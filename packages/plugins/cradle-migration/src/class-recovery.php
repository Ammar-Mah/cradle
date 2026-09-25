<?php
/**
 * Journal-driven recovery: finish (roll forward) or roll back an interrupted restore.
 *
 * Everything here decides from the journal plus the actual state of tables and files,
 * so every operation is idempotent and can be repeated after another crash. It needs
 * only $wpdb and the job directory, so the CLI can also run it in a minimal
 * bootstrap (SHORTINIT) when a half-restored site cannot load normally.
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

defined( 'ABSPATH' ) || exit;

final class Recovery {

	/**
	 * Rename pairs that complete the DB cutover from the current state.
	 *
	 * @return array<int,array{0:string,1:string}>|\WP_Error
	 */
	public static function forward_pairs( array $tables ) {
		$pairs = array();
		foreach ( $tables as $t ) {
			$live   = Db::table_exists( $t['live'] );
			$staged = Db::table_exists( $t['staged'] );
			$old    = Db::table_exists( $t['old'] );
			if ( ! $staged ) {
				if ( $live ) {
					continue; // Already swapped.
				}
				return new \WP_Error( 'cradle_migration_recovery', sprintf( 'Table %s is missing in every form; restore it from the old table or a backup manually.', $t['live'] ) );
			}
			if ( $live ) {
				if ( $old ) {
					return new \WP_Error( 'cradle_migration_recovery', sprintf( 'Table %s exists as live, staged and old copies; resolve manually (see the recovery guide).', $t['live'] ) );
				}
				$pairs[] = array( $t['live'], $t['old'] );
			}
			$pairs[] = array( $t['staged'], $t['live'] );
		}
		return $pairs;
	}

	/**
	 * Rename pairs that put the destination's original tables back.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function rollback_pairs( array $tables ): array {
		$pairs = array();
		foreach ( $tables as $t ) {
			$live   = Db::table_exists( $t['live'] );
			$staged = Db::table_exists( $t['staged'] );
			$old    = Db::table_exists( $t['old'] );
			if ( $staged ) {
				if ( ! $live && $old ) {
					$pairs[] = array( $t['old'], $t['live'] );
				}
				continue;
			}
			if ( $live ) {
				$pairs[] = array( $t['live'], $t['staged'] );
			}
			if ( $old ) {
				$pairs[] = array( $t['old'], $t['live'] );
			}
		}
		return $pairs;
	}

	/**
	 * Idempotent move of a file or directory. An unexpected occupant of the target is
	 * moved to $displaced instead of being overwritten.
	 *
	 * @return true|\WP_Error
	 */
	public static function move( string $from, string $to, string $displaced ) {
		$from_exists = file_exists( $from ) || is_link( $from );
		if ( ! $from_exists ) {
			return true; // Already moved (or nothing to move aside).
		}
		if ( is_link( $from ) ) {
			return new \WP_Error( 'cradle_migration_symlink', 'Refusing to move a symbolic link: ' . basename( $from ) );
		}
		if ( file_exists( $to ) || is_link( $to ) ) {
			if ( ! is_dir( dirname( $displaced ) ) && ! wp_mkdir_p( dirname( $displaced ) ) ) {
				return new \WP_Error( 'cradle_migration_move', 'Could not prepare a displaced-files directory.' );
			}
			if ( ! self::rename_any( $to, $displaced . '-' . basename( $to ) ) ) {
				return new \WP_Error( 'cradle_migration_move', 'Could not move an unexpected file out of the way: ' . basename( $to ) );
			}
		}
		if ( ! is_dir( dirname( $to ) ) && ! wp_mkdir_p( dirname( $to ) ) ) {
			return new \WP_Error( 'cradle_migration_move', 'Could not create ' . basename( dirname( $to ) ) . ' (disk full or permissions).' );
		}
		if ( ! self::rename_any( $from, $to ) ) {
			return new \WP_Error( 'cradle_migration_move', 'Could not move ' . basename( $from ) . ' into place (disk full or permissions).' );
		}
		return true;
	}

	/**
	 * rename(), falling back to copy + delete across volumes.
	 */
	private static function rename_any( string $from, string $to ): bool {
		if ( @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.rename_rename
			return true;
		}
		if ( is_file( $from ) ) {
			if ( ! @copy( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return false;
			}
			return @unlink( $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		if ( ! is_dir( $from ) || ( ! is_dir( $to ) && ! @mkdir( $to, 0755, true ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $from, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $items as $item ) {
			$dest = $to . '/' . substr( wp_normalize_path( $item->getPathname() ), strlen( wp_normalize_path( $from ) ) + 1 );
			if ( $item->isLink() ) {
				return false;
			}
			if ( $item->isDir() ) {
				if ( ! is_dir( $dest ) && ! @mkdir( $dest, 0755, true ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					return false;
				}
			} elseif ( ! @copy( $item->getPathname(), $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return false;
			}
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $from, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() ); // phpcs:ignore
		}
		return @rmdir( $from ); // phpcs:ignore
	}

	/**
	 * Current recovery picture for an import job directory.
	 */
	public static function status( string $dir ): array {
		$journal  = new Journal( $dir );
		$recovery = $journal->recovery();
		$tables   = array();
		foreach ( (array) ( $recovery['tables'] ?? array() ) as $t ) {
			$tables[] = array(
				'table'  => $t['live'],
				'live'   => Db::table_exists( $t['live'] ),
				'staged' => Db::table_exists( $t['staged'] ),
				'old'    => Db::table_exists( $t['old'] ),
			);
		}
		$plan = is_file( $dir . '/file-plan.json' ) ? json_decode( (string) file_get_contents( $dir . '/file-plan.json' ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return array(
			'stage'    => $journal->stage(),
			'tables'   => $tables,
			'fileOps'  => count( (array) ( $plan['ops'] ?? array() ) ),
			'fileDone' => count( $journal->done_file_ops() ),
			'recovery' => is_array( $recovery ),
		);
	}

	/**
	 * Which phase a forward recovery resumes at, or null when nothing can be finished.
	 */
	public static function forward_phase( string $stage ) {
		$map = array(
			'db_cutover_in_progress' => 'cutover_db',
			'db_swapped'             => 'replace_files',
			'files_in_progress'      => 'replace_files',
			'files_replaced'         => 'finalize',
			'finalized'              => 'verify',
			'verified'               => 'verify',
		);
		return $map[ $stage ] ?? null;
	}

	/**
	 * Puts a job back into the runner at the right phase (operator decision; takes over a dead worker's lease).
	 *
	 * @return string|\WP_Error Phase.
	 */
	public static function prepare_forward( string $uuid, string $dir ) {
		$journal = new Journal( $dir );
		$stage   = $journal->stage();
		$phase   = self::forward_phase( $stage );
		if ( null === $phase ) {
			return new \WP_Error( 'cradle_migration_recovery', 'Nothing to finish: journal stage is "' . $stage . '".' . ( 'not_started' === $stage ? ' The cutover never started, so the live site is unchanged.' : '' ) );
		}
		Job_Store::expire_lease( $uuid );
		if ( ! Job_Store::resume( $uuid, array( 'running', 'failed', 'paused', 'awaiting_cutover' ), $phase ) ) {
			return new \WP_Error( 'cradle_migration_recovery', 'The job row could not be moved back to running.' );
		}
		$journal->append( 'recovery_forward', array( 'phase' => $phase ) );
		return $phase;
	}

	/**
	 * Rolls a restore back: files first (reverse journal order), then tables.
	 *
	 * @param bool $raw Minimal bootstrap: no Cradle core services available.
	 * @return array|\WP_Error Summary.
	 */
	public static function rollback( string $uuid, string $dir, bool $raw = false ) {
		global $wpdb;
		$journal  = new Journal( $dir );
		$recovery = $journal->recovery();
		$stage    = $journal->stage();
		if ( 'rolled_back' === $stage ) {
			return array( 'stage' => 'rolled_back', 'note' => 'Already rolled back.' );
		}
		if ( 'not_started' === $stage ) {
			if ( ! $raw ) {
				Importer::discard_staging( $uuid );
				Job_Store::set_state( $uuid, 'cancelled', 'discarded', 'Cutover never started; staging discarded. The live site was not changed.' );
			}
			return array( 'stage' => 'not_started', 'note' => 'Cutover never started; nothing on the live site changed. Staging was discarded.' );
		}
		if ( ! is_array( $recovery ) ) {
			return new \WP_Error( 'cradle_migration_recovery', 'The recovery context (recovery.json) is missing; follow the manual steps in the recovery guide.' );
		}
		// Keep writes out while switching back.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", Guard::QUIESCE_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'on')", Guard::QUIESCE_OPTION, serialize( array( 'job' => $uuid, 'mode' => 'import', 'since' => time(), 'until' => time() + 900 ) ) ) ); // phpcs:ignore
		$journal->append( 'rollback_begin', array( 'from' => $stage ) );

		// Files: undo every begun operation, newest first.
		$plan  = is_file( $dir . '/file-plan.json' ) ? json_decode( (string) file_get_contents( $dir . '/file-plan.json' ), true ) : array( 'ops' => array() ); // phpcs:ignore
		$begun = array();
		foreach ( $journal->events() as $event ) {
			if ( 'file_op_begin' === $event['op'] ) {
				$begun[ (int) $event['i'] ] = true;
			}
		}
		$ops = array_reverse( (array) ( $plan['ops'] ?? array() ) );
		$undone = 0;
		foreach ( $ops as $op ) {
			if ( ! isset( $begun[ (int) $op['i'] ] ) ) {
				continue;
			}
			if ( 'place' === $op['kind'] ) {
				// Imported copy is live at 'to': move it aside (kept for inspection).
				$r = self::move( $op['to'], $dir . '/rolledback/' . $op['i'] . '-' . basename( $op['to'] ), $dir . '/rolledback/x' . $op['i'] );
			} else {
				// Original destination copy sits in the backup: put it back.
				$r = self::move( $op['to'], $op['from'], $dir . '/rolledback/occupant-' . $op['i'] );
			}
			if ( is_wp_error( $r ) ) {
				$journal->append( 'rollback_failed', array( 'i' => $op['i'], 'error' => $r->get_error_message() ) );
				return $r;
			}
			$journal->append( 'file_op_undone', array( 'i' => $op['i'] ) );
			++$undone;
		}

		// Tables.
		$pairs = self::rollback_pairs( (array) $recovery['tables'] );
		$ok    = Db::rename( $pairs );
		if ( is_wp_error( $ok ) ) {
			$journal->append( 'rollback_failed', array( 'error' => $ok->get_error_message() ) );
			return $ok;
		}
		// The restored (original) options table still carries the pre-swap window flag.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", Guard::QUIESCE_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		$journal->append( 'rolled_back', array( 'tables' => count( $pairs ), 'files' => $undone ) );
		if ( $raw ) {
			$jobs = $wpdb->prefix . 'cradle_jobs';
			$wpdb->query( $wpdb->prepare( "UPDATE {$jobs} SET state = 'rolled_back', phase = 'rolled_back', lease_owner = NULL, lease_expires = NULL, fencing = fencing + 1, updated_at = %s WHERE uuid = %s", gmdate( 'Y-m-d H:i:s' ), $uuid ) ); // phpcs:ignore
		} else {
			Job_Store::set_state( $uuid, 'rolled_back', 'rolled_back' );
			cradle_log_activity( 'migration', 'import_rolled_back', 'Site restore rolled back.', array( 'job' => $uuid ) );
		}
		return array(
			'stage'       => 'rolled_back',
			'tables'      => count( $pairs ),
			'fileOpsUndone' => $undone,
			'note'        => 'The destination tables and files were restored. The imported copies are kept as cmig_* tables and in the job directory until backups are purged.',
		);
	}
}
