<?php
/**
 * Command-line runner (bin/cradle-migrate.php and `wp cradle-migrate` when WP-CLI is present).
 *
 * Uses the same job engine as the browser. CLI access is host-level trust (whoever can
 * run PHP as the site owner), so jobs are created as "system" jobs owned by the chosen
 * administrator. Visitor-triggered WP-Cron is never needed.
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

use Cradle\Core\Jobs;

defined( 'ABSPATH' ) || exit;

final class Cli {

	/** @var bool */
	private static $json = false;

	/** @var array */
	private static $result = array();

	public static function out( string $line ) {
		if ( ! self::$json ) {
			fwrite( STDOUT, $line . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
	}

	private static function err( string $line ): int {
		fwrite( STDERR, 'Error: ' . $line . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		self::$result['error'] = $line;
		return 1;
	}

	public static function usage(): string {
		return <<<TXT
Usage: php bin/cradle-migrate.php --path=<wordpress root> <command> [options]

  export   [--database=0|1] [--uploads=0|1] [--themes=active|all|none] [--plugins=active|all|none]
           [--quiesce] [--part-size=<MB>] [--out=<dir>]
  import   --archive=<path/to/<id>.manifest.json> [--yes] [--no-database] [--no-uploads]
           [--no-themes] [--no-plugins] [--opaque=key1,key2*] [--max-disk-use=<bytes>]
  approve  --job=<uuid>    Approve a reviewed preflight report and stage.
  cutover  --job=<uuid>    Switch the site over (after staging) and finish.
  run      --job=<uuid>    Continue a job that is running (e.g. after an interruption).
  status   [--job=<uuid>]  Jobs, journal stage, table/file recovery picture.
  recover  --job=<uuid> [--rollback]    Finish (default) or roll back an interrupted restore.
  recover  --job=<uuid> --raw [--dir=<job dir>] --rollback|--complete-db
           Minimal bootstrap (no plugins) when the half-restored site cannot load.
  rollback --job=<uuid>    Same as recover --rollback.
  discard  --job=<uuid>    Drop staging of an import that never reached cutover.
  purge    --job=<uuid>    Delete backups (old tables and moved files) of a finished restore.
  release-safety           Release the post-restore safety hold (email, cron, outbound HTTP).
  archives                 List archives in private storage.

Global: --user=<admin login>  --json (machine-readable result on stdout)
TXT;
	}

	/**
	 * @return int Exit code.
	 */
	public static function dispatch( array $args, array $assoc ): int {
		self::$json   = ! empty( $assoc['json'] );
		self::$result = array();
		$command      = (string) ( $args[0] ?? '' );
		if ( '' === $command || 'help' === $command ) {
			self::out( self::usage() );
			return '' === $command ? 1 : 0;
		}
		if ( ! function_exists( 'cradle' ) || ! cradle() || ! cradle()->modules()->is_ready( 'migration' ) ) {
			return self::err( 'Cradle core and Cradle Migration must be active and ready on this site.' );
		}
		$user = self::set_user( (string) ( $assoc['user'] ?? '' ) );
		if ( is_wp_error( $user ) ) {
			return self::err( $user->get_error_message() );
		}
		self::faults( (string) ( $assoc['fault'] ?? '' ) );
		switch ( $command ) {
			case 'export':
				$code = self::export( $assoc );
				break;
			case 'import':
				$code = self::import( $assoc );
				break;
			case 'approve':
			case 'cutover':
			case 'run':
				$code = self::advance( $command, $assoc );
				break;
			case 'status':
				$code = self::status( $assoc );
				break;
			case 'recover':
				$code = self::recover( $assoc );
				break;
			case 'rollback':
				$code = self::recover( array( 'rollback' => true ) + $assoc );
				break;
			case 'discard':
				$code = self::discard( $assoc );
				break;
			case 'purge':
				$job = (string) ( $assoc['job'] ?? '' );
				$out = Importer::purge_backups( $job );
				$code = is_wp_error( $out ) ? self::err( $out->get_error_message() ) : 0;
				if ( 0 === $code ) {
					self::out( 'Backups purged: ' . $out['tablesDropped'] . ' table(s) dropped, backup files removed.' );
					self::$result = $out;
				}
				break;
			case 'release-safety':
				$ok   = Guard::release_hold();
				$code = 0;
				self::out( $ok ? 'Safety hold released.' : 'No safety hold was active.' );
				self::$result = array( 'released' => $ok );
				break;
			case 'archives':
				self::$result = array( 'archives' => Archives::all() );
				foreach ( self::$result['archives'] as $a ) {
					self::out( sprintf( '%s  %s  %d parts  %s', $a['id'], $a['createdAt'], count( $a['parts'] ), size_format( $a['bytes'] ) ) );
				}
				$code = 0;
				break;
			default:
				self::out( self::usage() );
				$code = 1;
		}
		if ( self::$json ) {
			fwrite( STDOUT, wp_json_encode( self::$result + array( 'exit' => $code ), JSON_UNESCAPED_SLASHES ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
		return $code;
	}

	/**
	 * @return int|\WP_Error User ID.
	 */
	private static function set_user( string $login ) {
		if ( '' !== $login ) {
			$user = get_user_by( 'login', $login );
			if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
				return new \WP_Error( 'cradle_migration_user', 'The --user account must exist and be an administrator.' );
			}
		} else {
			$users = get_users(
				array(
					'role'    => 'administrator',
					'number'  => 1,
					'orderby' => 'ID',
				)
			);
			$user  = $users[0] ?? null;
			if ( ! $user ) {
				return new \WP_Error( 'cradle_migration_user', 'No administrator account exists; pass --user.' );
			}
		}
		wp_set_current_user( $user->ID );
		return (int) $user->ID;
	}

	/**
	 * Operator/test fault injection (CLI only): simulate crashes and database failures.
	 */
	private static function faults( string $fault ) {
		if ( '' === $fault ) {
			return;
		}
		if ( 'after-db-cutover' === $fault ) {
			add_action(
				'cradle_migration/after_phase',
				static function ( $phase ) {
					if ( 'cutover_db' === $phase ) {
						fwrite( STDERR, "Simulated crash after the database cutover (before file replacement).\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
						exit( 70 );
					}
				}
			);
		} elseif ( preg_match( '/^after-file-op=([0-9]+)$/', $fault, $m ) ) {
			$n = (int) $m[1];
			add_action(
				'cradle_migration/after_file_op',
				static function ( $i ) use ( $n ) {
					if ( (int) $i === $n ) {
						fwrite( STDERR, "Simulated crash during file replacement.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
						exit( 70 );
					}
				}
			);
		} elseif ( in_array( $fault, array( 'db-load-once', 'db-load' ), true ) ) {
			$once = 'db-load-once' === $fault;
			$hit  = 0;
			add_action(
				'cradle_migration/before_load_chunk',
				static function () use ( $once, &$hit ) {
					if ( $once && $hit > 0 ) {
						return;
					}
					++$hit;
					global $wpdb;
					// A real database failure: this connection is killed server-side, so the next
					// statement of the chunk load fails with "server has gone away".
					$id = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
					$wpdb->suppress_errors( true );
					$wpdb->query( 'KILL ' . $id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->suppress_errors( false );
				}
			);
		}
	}

	/**
	 * Runs a job in bounded runner calls until it waits for a decision or ends.
	 *
	 * @return array|\WP_Error Final job row.
	 */
	public static function run_loop( string $uuid ) {
		$jobs  = cradle()->jobs();
		$waits = 0;
		$last  = '';
		while ( true ) {
			$job = $jobs->run( $uuid );
			if ( is_wp_error( $job ) ) {
				return $job;
			}
			$line = sprintf( '[%s] %s — %s  %d/%d%s', gmdate( 'H:i:s' ), $job['state'], $job['phase'], $job['progress_done'], $job['progress_total'], $job['error_message'] ? '  (' . $job['error_message'] . ')' : '' );
			if ( $line !== $last ) {
				self::out( $line );
				$last = $line;
			}
			if ( in_array( $job['state'], Jobs::TERMINAL_STATES, true ) || in_array( $job['state'], array( 'paused', 'awaiting_cutover' ), true ) ) {
				return $job;
			}
			if ( ! empty( $job['lease_owner'] ) && strtotime( (string) $job['lease_expires'] . ' UTC' ) > time() ) {
				if ( ++$waits > 150 ) {
					return new \WP_Error( 'cradle_migration_busy', 'Another runner holds this job. If that process crashed, run: recover --job=' . $uuid );
				}
				sleep( 2 );
				continue;
			}
			$waits = 0;
		}
	}

	private static function finish( $job ): int {
		if ( is_wp_error( $job ) ) {
			return self::err( $job->get_error_message() );
		}
		self::$result['job']   = Jobs::public_view( $job );
		self::$result['state'] = $job['state'];
		self::$result['phase'] = $job['phase'];
		return in_array( $job['state'], array( 'failed', 'cancelled' ), true ) ? 2 : 0;
	}

	private static function export( array $a ): int {
		$opts = array(
			'database' => '0' !== (string) ( $a['database'] ?? '1' ),
			'uploads'  => '0' !== (string) ( $a['uploads'] ?? '1' ),
			'themes'   => (string) ( $a['themes'] ?? 'active' ),
			'plugins'  => (string) ( $a['plugins'] ?? 'active' ),
			'quiesce'  => ! empty( $a['quiesce'] ),
			'partSize' => (int) ( (float) ( $a['part-size'] ?? 64 ) * 1048576 ),
		);
		$job  = Exporter::create( $opts, true );
		if ( is_wp_error( $job ) ) {
			return self::err( $job->get_error_message() );
		}
		self::out( 'Export job ' . $job['uuid'] );
		$job  = self::run_loop( $job['uuid'] );
		$code = self::finish( $job );
		if ( 0 !== $code || 'completed' !== $job['state'] ) {
			return $code ? $code : 2;
		}
		$archive                 = $job['payload']['archive'];
		self::$result['archive'] = $archive;
		$dir                     = Workspace::resolve_rel( $archive['location'] );
		self::out( sprintf( 'Archive %1$s: %2$d part(s), %3$s, consistency: %4$s, unsupported objects: %5$d', $archive['id'], $archive['parts'], size_format( $archive['bytes'] ), $archive['consistency'], $archive['unsupported'] ) );
		if ( ! empty( $a['out'] ) && ! is_wp_error( $dir ) ) {
			$out = rtrim( wp_normalize_path( (string) $a['out'] ), '/' );
			if ( ! is_dir( $out ) && ! wp_mkdir_p( $out ) ) {
				return self::err( 'Could not create ' . $out );
			}
			foreach ( (array) glob( $dir . '/' . $archive['id'] . '.*' ) as $file ) {
				copy( (string) $file, $out . '/' . basename( (string) $file ) );
			}
			self::$result['out'] = $out;
			self::out( 'Copied to ' . $out . ' — keep it private: it contains password hashes and secrets.' );
		}
		return 0;
	}

	private static function import( array $a ): int {
		Importer::sweep();
		$local = Upload::import_local( (string) ( $a['archive'] ?? '' ) );
		if ( is_wp_error( $local ) ) {
			return self::err( $local->get_error_message() );
		}
		$limits = array();
		foreach ( $a as $key => $value ) {
			if ( 0 === strpos( (string) $key, 'limit-' ) ) {
				$limits[ lcfirst( str_replace( ' ', '', ucwords( str_replace( '-', ' ', substr( (string) $key, 6 ) ) ) ) ) ] = $value;
			}
		}
		if ( isset( $a['max-disk-use'] ) ) {
			$limits['diskQuota'] = (int) $a['max-disk-use'];
		}
		$job = Importer::create(
			array(
				'location' => $local['location'],
				'manifest' => $local['manifest'],
				'options'  => array(
					'database' => empty( $a['no-database'] ),
					'uploads'  => empty( $a['no-uploads'] ),
					'themes'   => empty( $a['no-themes'] ),
					'plugins'  => empty( $a['no-plugins'] ),
					'opaque'   => array_filter( explode( ',', (string) ( $a['opaque'] ?? '' ) ) ),
					'limits'   => $limits,
				),
			),
			true
		);
		if ( is_wp_error( $job ) ) {
			return self::err( $job->get_error_message() );
		}
		self::out( 'Import job ' . $job['uuid'] );
		self::$result['uuid'] = $job['uuid'];
		return self::drive( $job['uuid'], ! empty( $a['yes'] ) );
	}

	/**
	 * Runs preflight/staging/cutover, stopping at decisions unless $yes.
	 */
	private static function drive( string $uuid, bool $yes ): int {
		$job = self::run_loop( $uuid );
		if ( is_wp_error( $job ) ) {
			return self::err( $job->get_error_message() );
		}
		self::print_report( $uuid );
		if ( 'paused' === $job['state'] && 'review' === $job['phase'] ) {
			if ( ! $yes ) {
				self::out( 'Preflight passed. Review the report above, then run: approve --job=' . $uuid );
				return self::finish( $job );
			}
			$ok = Importer::approve( $job );
			if ( is_wp_error( $ok ) ) {
				return self::err( $ok->get_error_message() );
			}
			$job = self::run_loop( $uuid );
			if ( is_wp_error( $job ) ) {
				return self::err( $job->get_error_message() );
			}
		}
		if ( 'awaiting_cutover' === $job['state'] ) {
			if ( ! $yes ) {
				self::out( 'Staged and validated. Switch the site over with: cutover --job=' . $uuid );
				return self::finish( $job );
			}
			$token = Importer::begin_cutover( $job );
			if ( is_wp_error( $token ) ) {
				return self::err( $token->get_error_message() );
			}
			$job = self::run_loop( $uuid );
		}
		$code = self::finish( $job );
		self::print_verify( $uuid );
		return $code;
	}

	private static function advance( string $command, array $a ): int {
		$uuid = (string) ( $a['job'] ?? '' );
		$job  = Job_Store::get( $uuid );
		if ( ! $job ) {
			return self::err( 'Job not found.' );
		}
		if ( 'approve' === $command ) {
			$ok = Importer::approve( $job );
			if ( is_wp_error( $ok ) ) {
				return self::err( $ok->get_error_message() );
			}
			return self::drive( $uuid, false );
		}
		if ( 'cutover' === $command ) {
			$ok = Importer::begin_cutover( $job );
			if ( is_wp_error( $ok ) ) {
				return self::err( $ok->get_error_message() );
			}
			$job  = self::run_loop( $uuid );
			$code = self::finish( $job );
			self::print_verify( $uuid );
			return $code;
		}
		$job = self::run_loop( $uuid );
		if ( Importer::TYPE === ( is_array( $job ) ? $job['type'] : '' ) ) {
			self::print_verify( $uuid );
		}
		return self::finish( $job );
	}

	private static function print_report( string $uuid ) {
		$dir = Workspace::job_dir( $uuid );
		if ( is_wp_error( $dir ) || ! is_file( $dir . '/report.json' ) ) {
			return;
		}
		$report                 = Workspace::read_json( $dir . '/report.json' );
		self::$result['report'] = is_array( $report ) ? array(
			'blocking' => $report['blocking'],
			'warnings' => $report['warnings'],
			'admin'    => $report['admin'],
		) : null;
		if ( ! is_array( $report ) ) {
			return;
		}
		self::out( '--- Preflight report ---' );
		foreach ( $report['checks'] as $c ) {
			self::out( sprintf( '  [%s] %s %s', strtoupper( $c['status'] ), $c['label'], '' !== $c['detail'] ? '— ' . $c['detail'] : '' ) );
		}
		foreach ( $report['blocking'] as $b ) {
			self::out( '  BLOCKING: ' . $b );
		}
		foreach ( array_slice( $report['warnings'], 0, 30 ) as $w ) {
			self::out( '  warning: ' . $w );
		}
		if ( ! empty( $report['admin']['summary'] ) ) {
			self::out( '  Admin login: ' . $report['admin']['summary'] );
		}
	}

	private static function print_verify( string $uuid ) {
		$dir = Workspace::job_dir( $uuid );
		if ( is_wp_error( $dir ) || ! is_file( $dir . '/verify.json' ) ) {
			return;
		}
		$verify                 = Workspace::read_json( $dir . '/verify.json' );
		self::$result['verify'] = $verify;
		if ( ! is_array( $verify ) ) {
			return;
		}
		self::out( '--- Post-restore verification ---' );
		foreach ( $verify['checks'] as $c ) {
			self::out( sprintf( '  [%s] %s — %s', strtoupper( $c['status'] ), $c['label'], $c['detail'] ) );
		}
		self::out( $verify['passed'] ? 'Verification passed.' : 'Verification FAILED; backups are kept. Roll back with: recover --job=' . $uuid . ' --rollback' );
	}

	private static function status( array $a ): int {
		$uuid = (string) ( $a['job'] ?? '' );
		if ( '' === $uuid ) {
			$rows = cradle()->jobs()->recent( 'migration', 20 );
			foreach ( $rows as $job ) {
				self::out( sprintf( '%s  %-16s %-16s %-16s %s', $job['uuid'], $job['type'], $job['state'], $job['phase'], $job['updated_at'] ) );
			}
			self::$result['jobs'] = array_map( array( Jobs::class, 'public_view' ), $rows );
			self::$result['hold'] = Guard::hold();
			self::out( 'Safety hold: ' . ( Guard::hold() ? 'ACTIVE' : 'inactive' ) );
			return 0;
		}
		$job = Job_Store::get( $uuid );
		if ( ! $job ) {
			return self::err( 'Job not found.' );
		}
		self::$result['job'] = Jobs::public_view( $job );
		self::out( sprintf( 'Job %s (%s): %s — %s %s', $uuid, $job['type'], $job['state'], $job['phase'], $job['error_message'] ? '(' . $job['error_message'] . ')' : '' ) );
		if ( Importer::TYPE === $job['type'] ) {
			$dir = Workspace::job_dir( $uuid );
			if ( ! is_wp_error( $dir ) ) {
				$status                   = Recovery::status( $dir );
				self::$result['recovery'] = $status;
				self::out( 'Journal stage: ' . $status['stage'] . sprintf( ' (file operations %d/%d)', $status['fileDone'], $status['fileOps'] ) );
				foreach ( $status['tables'] as $t ) {
					self::out( sprintf( '  %-40s live:%s staged:%s old:%s', $t['table'], $t['live'] ? 'y' : 'n', $t['staged'] ? 'y' : 'n', $t['old'] ? 'y' : 'n' ) );
				}
				$next = Recovery::forward_phase( $status['stage'] );
				if ( null !== $next && ! in_array( $job['state'], array( 'completed', 'rolled_back' ), true ) ) {
					self::out( 'Recovery: "recover --job=' . $uuid . '" finishes from phase ' . $next . '; add --rollback to undo instead.' );
				}
			}
		}
		return 0;
	}

	private static function recover( array $a ): int {
		$uuid = (string) ( $a['job'] ?? '' );
		$job  = Job_Store::get( $uuid );
		if ( ! $job || Importer::TYPE !== $job['type'] ) {
			return self::err( 'Import job not found.' );
		}
		$dir = Workspace::job_dir( $uuid );
		if ( is_wp_error( $dir ) ) {
			return self::err( $dir->get_error_message() );
		}
		if ( ! empty( $a['rollback'] ) ) {
			$lock = cradle()->locks()->acquire( 'migration:recover', 600 );
			if ( ! $lock ) {
				return self::err( 'Another recovery is running.' );
			}
			Job_Store::expire_lease( $uuid );
			$out = Recovery::rollback( $uuid, $dir );
			cradle()->locks()->release( 'migration:recover', $lock );
			if ( is_wp_error( $out ) ) {
				return self::err( $out->get_error_message() );
			}
			self::$result = $out;
			self::out( 'Rollback: ' . $out['note'] );
			return 0;
		}
		$phase = Recovery::prepare_forward( $uuid, $dir );
		if ( is_wp_error( $phase ) ) {
			return self::err( $phase->get_error_message() );
		}
		self::out( 'Resuming the restore at phase ' . $phase . '.' );
		$job  = self::run_loop( $uuid );
		$code = self::finish( $job );
		self::print_verify( $uuid );
		return $code;
	}

	private static function discard( array $a ): int {
		$uuid = (string) ( $a['job'] ?? '' );
		$job  = Job_Store::get( $uuid );
		if ( ! $job || Importer::TYPE !== $job['type'] ) {
			return self::err( 'Import job not found.' );
		}
		$dir = Workspace::job_dir( $uuid );
		if ( is_wp_error( $dir ) || 'not_started' !== ( new Journal( $dir ) )->stage() ) {
			return self::err( 'This import already changed the site; use recover --rollback.' );
		}
		if ( ! in_array( $job['state'], Jobs::TERMINAL_STATES, true ) ) {
			cradle()->jobs()->cancel( $uuid );
		}
		Importer::discard_staging( $uuid );
		self::out( 'Import discarded; the live site was not changed.' );
		return 0;
	}

	/**
	 * Minimal-bootstrap recovery (SHORTINIT): database renames and file moves from the journal only.
	 */
	public static function raw( array $a ): int {
		self::$json   = ! empty( $a['json'] );
		self::$result = array();
		$code         = self::raw_run( $a );
		if ( self::$json ) {
			fwrite( STDOUT, json_encode( self::$result + array( 'exit' => $code ), JSON_UNESCAPED_SLASHES ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $code;
	}

	private static function raw_run( array $a ): int {
		global $wpdb;
		$uuid = (string) ( $a['job'] ?? '' );
		if ( ! preg_match( '/^[0-9a-f-]{36}$/', $uuid ) ) {
			return self::err( '--job=<uuid> is required.' );
		}
		$dir = (string) ( $a['dir'] ?? '' );
		if ( '' === $dir ) {
			$candidates = array();
			if ( defined( 'CRADLE_PRIVATE_DIR' ) ) {
				$candidates[] = rtrim( (string) CRADLE_PRIVATE_DIR, '/\\' ) . '/migration/jobs/' . $uuid;
			}
			foreach ( (array) glob( WP_CONTENT_DIR . '/uploads/cradle-private-*/migration/jobs/' . $uuid ) as $c ) {
				$candidates[] = (string) $c;
			}
			foreach ( $candidates as $c ) {
				if ( is_file( $c . '/journal.jsonl' ) ) {
					$dir = $c;
					break;
				}
			}
		}
		$dir = wp_normalize_path( $dir );
		if ( '' === $dir || ! is_file( $dir . '/recovery.json' ) || basename( $dir ) !== $uuid ) {
			return self::err( 'Job directory not found; pass --dir=<.../migration/jobs/' . $uuid . '>.' );
		}
		$journal  = new Journal( $dir );
		$recovery = $journal->recovery();
		if ( ! is_array( $recovery ) || ( $recovery['destination']['prefix'] ?? '' ) !== $wpdb->prefix ) {
			return self::err( 'The recovery context does not belong to this site (table prefix mismatch).' );
		}
		self::out( 'Journal stage: ' . $journal->stage() );
		if ( ! empty( $a['rollback'] ) ) {
			$out = Recovery::rollback( $uuid, $dir, true );
			if ( is_wp_error( $out ) ) {
				return self::err( $out->get_error_message() );
			}
			self::$result = $out;
			self::out( 'Rollback: ' . $out['note'] );
			return 0;
		}
		if ( ! empty( $a['complete-db'] ) ) {
			$pairs = Recovery::forward_pairs( (array) $recovery['tables'] );
			if ( is_wp_error( $pairs ) ) {
				return self::err( $pairs->get_error_message() );
			}
			$ok = Db::rename( $pairs );
			if ( is_wp_error( $ok ) ) {
				return self::err( $ok->get_error_message() );
			}
			if ( ! $journal->has( 'db_cutover_done' ) ) {
				$journal->append( 'db_cutover_done', array( 'renamed' => count( $pairs ), 'raw' => true ) );
			}
			self::out( 'Database cutover completed (' . count( $pairs ) . ' renames). Now run the normal "recover --job=' . $uuid . '" to finish.' );
			return 0;
		}
		return self::err( 'Raw mode needs --rollback or --complete-db.' );
	}
}
