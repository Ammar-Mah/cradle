<?php
/**
 * Import/restore job (`migration/import`).
 *
 * Preflight (state `preflight`, nothing on the destination changes):
 *   [unpack →] verify_manifest → hash_parts → inspect_parts → scan_db → report → paused/review
 * `unpack` runs for a `.cradle` container and extracts its manifest and parts into the
 * job's location. A one-step import (payload `auto`) continues from a report without
 * blocking problems straight into staging; blocking problems still fail the job.
 * Staging (state `running`, live site untouched; imported PHP is never loaded):
 *   stage_files → load_db → validate_staged → safety → checkpoint → awaiting_cutover
 * Critical phase (write-quiescence window, journaled):
 *   cutover_db → replace_files → finalize → verify → completed | failed(verify_failed)
 *
 * Staged tables are named cmig_<token>_<table>; replaced live tables are kept as
 * cold_<token>_<table>; replaced files are moved (not deleted) into the job's backup
 * directory. The journal and recovery context live in private file storage, and the
 * destination's own Cradle job/lock/activity tables are never replaced, so recovery
 * does not depend on anything the restore swaps out.
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

use Cradle\Core\Jobs;
use Cradle\Core\Storage;

defined( 'ABSPATH' ) || exit;

final class Importer {

	const TYPE           = 'migration/import';
	const STEP_SECONDS   = 6.0;
	const ENGINE_SLUGS   = array( 'cradle', 'cradle-migration' );
	const PRESERVED_OPTS = array( 'cradle_private_dir_suffix', 'cradle_storage_probe', 'upload_path', 'upload_url_path' );
	const TOKEN_TTL      = 7200;

	/* ------------------------------------------------------------------ */
	/* Creation and operator decisions                                    */
	/* ------------------------------------------------------------------ */

	public static function default_options(): array {
		return array(
			'database'  => true,
			'uploads'   => true,
			'themes'    => true,
			'plugins'   => true,
			'languages' => true,
			'root'      => false,
			'opaque'    => array(),
			'limits'   => array(),
		);
	}

	public static function destination(): array {
		global $wpdb, $wp_version;
		$uploads = wp_upload_dir( null, false );
		$dest    = array(
			'db'         => (string) $wpdb->dbname,
			'prefix'     => (string) $wpdb->prefix,
			'abspath'    => wp_normalize_path( ABSPATH ),
			'home'       => untrailingslashit( (string) get_option( 'home' ) ),
			'siteurl'    => untrailingslashit( (string) get_option( 'siteurl' ) ),
			'contentDir' => untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ),
			'uploadsDir' => untrailingslashit( wp_normalize_path( $uploads['basedir'] ) ),
			'uploadsUrl' => untrailingslashit( (string) $uploads['baseurl'] ),
			'pluginsDir' => untrailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ),
			'themesDir'  => untrailingslashit( wp_normalize_path( get_theme_root() ) ),
			'languagesDir'    => untrailingslashit( wp_normalize_path( WP_LANG_DIR ) ),
			'translationsDir' => self::translations_dir(),
			'wordpress'  => (string) $wp_version,
			'dbVersion'  => (int) get_option( 'db_version' ),
		);
		$dest['fingerprint'] = hash( 'sha256', $dest['db'] . '|' . $dest['prefix'] . '|' . $dest['abspath'] );
		return $dest;
	}

	/**
	 * Cradle Translations data folder in this site's private storage ('' when storage is unavailable).
	 */
	public static function translations_dir(): string {
		$private = function_exists( 'cradle' ) && cradle() ? cradle()->storage()->private_root() : null;
		return is_array( $private ) ? untrailingslashit( wp_normalize_path( (string) $private['path'] ) ) . '/translations' : '';
	}

	/**
	 * Resolves a container reference `archives|incoming/<dir>/<file>.cradle` inside private storage.
	 *
	 * @return string|\WP_Error Absolute path.
	 */
	public static function container_path( string $rel ) {
		if ( ! preg_match( '#^((?:archives|incoming)/[A-Za-z0-9][A-Za-z0-9-]{3,79})/([A-Za-z0-9][A-Za-z0-9-]{0,79}\.cradle)$#', $rel, $m ) ) {
			return new \WP_Error( 'cradle_migration_container', 'Invalid .cradle file location.' );
		}
		$dir = Workspace::resolve_rel( $m[1] );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$path = $dir . '/' . $m[2];
		if ( ! is_file( $path ) || is_link( $path ) ) {
			return new \WP_Error( 'cradle_migration_container', 'The .cradle file was not found.' );
		}
		return $path;
	}

	/**
	 * @param array $args location (incoming/<id> | archives/<id>) and manifest (file name) of an unpacked
	 *                    archive, or container (see container_path(); location then defaults to a new
	 *                    incoming folder); options; auto (one-step import without the review stop).
	 * @return array|\WP_Error
	 */
	public static function create( array $args, bool $system = false ) {
		if ( is_multisite() ) {
			return new \WP_Error( 'cradle_migration_multisite', 'Multisite restore is not supported.' );
		}
		if ( ! $system && ! current_user_can( 'cradle_import_site' ) ) {
			return new \WP_Error( 'cradle_migration_forbidden', __( 'You are not allowed to import into this site.', 'cradle-migration' ) );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'cradle_migration_zip', 'The PHP zip extension (ZipArchive) is required.' );
		}
		$protected = Workspace::ensure_protected();
		if ( is_wp_error( $protected ) ) {
			return $protected;
		}
		$container = (string) ( $args['container'] ?? '' );
		$location  = (string) ( $args['location'] ?? '' );
		$manifest  = '';
		$new_dir   = '';
		if ( '' !== $container ) {
			$path = self::container_path( $container );
			if ( is_wp_error( $path ) ) {
				return $path;
			}
			if ( '' === $location ) {
				// The unpack folder is created only once the job can actually start (below).
				$location = 'incoming/unpack-' . strtolower( wp_generate_password( 20, false, false ) );
				$new_dir  = $location;
			} else {
				$dir = Workspace::resolve_rel( $location );
				if ( is_wp_error( $dir ) || ! is_dir( $dir ) || 0 !== strpos( $location, 'incoming/' ) ) {
					return new \WP_Error( 'cradle_migration_location', 'Invalid archive location.' );
				}
			}
		} else {
			$dir = Workspace::resolve_rel( $location );
			if ( is_wp_error( $dir ) ) {
				return $dir;
			}
			$manifest = (string) ( $args['manifest'] ?? '' );
			if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9-]{7,63}\.manifest\.json$/', $manifest ) || ! is_file( $dir . '/' . $manifest ) ) {
				return new \WP_Error( 'cradle_migration_manifest', 'The archive manifest was not found.' );
			}
		}
		$options = wp_parse_args( (array) ( $args['options'] ?? array() ), self::default_options() );
		foreach ( array( 'database', 'uploads', 'themes', 'plugins', 'languages', 'root' ) as $k ) {
			$options[ $k ] = ! empty( $options[ $k ] );
		}
		$options['opaque'] = array_values( array_filter( array_map( 'strval', (array) $options['opaque'] ), static fn( $p ) => (bool) preg_match( '/^[A-Za-z0-9_\-]{1,120}\*?$/', $p ) ) );
		$options['limits'] = array_filter( (array) $options['limits'], 'is_numeric' );
		$conflict          = self::conflicts( '' );
		if ( $conflict ) {
			return new \WP_Error( 'cradle_migration_conflict', $conflict );
		}
		$locks = cradle()->locks();
		$lock  = $locks->acquire( 'migration:create', 30 );
		if ( ! $lock ) {
			return new \WP_Error( 'cradle_migration_busy', 'Another migration request is being created. Try again.' );
		}
		$active = Job_Store::active();
		if ( $active ) {
			$locks->release( 'migration:create', $lock );
			return new \WP_Error( 'cradle_migration_conflict', sprintf( 'Another migration job (%1$s, %2$s) is not finished. Finish, cancel or recover it first.', $active[0]['type'], $active[0]['state'] ) );
		}
		if ( '' !== $new_dir ) {
			$made = Workspace::resolve_rel( $new_dir );
			if ( is_wp_error( $made ) || ! wp_mkdir_p( $made ) ) {
				$locks->release( 'migration:create', $lock );
				return new \WP_Error( 'cradle_migration_mkdir', 'Could not create a private working directory.' );
			}
		}
		$job = cradle()->jobs()->create(
			self::TYPE,
			array(
				'location'    => $location,
				'manifest'    => $manifest,
				'container'   => $container,
				'auto'        => ! empty( $args['auto'] ),
				'options'     => $options,
				'destination' => self::destination(),
			),
			array(
				'phase'  => '' !== $container ? 'unpack' : 'verify_manifest',
				'system' => $system,
			)
		);
		$locks->release( 'migration:create', $lock );
		if ( is_wp_error( $job ) ) {
			if ( '' !== $new_dir && isset( $made ) && is_string( $made ) ) {
				Workspace::remove_tree( $made );
			}
			return $job;
		}
		cradle_log_activity( 'migration', 'import_started', empty( $args['auto'] ) ? 'Site import preflight started.' : 'One-step site import started.', array( 'job' => $job['uuid'] ) );
		return $job;
	}

	/**
	 * One-step import of an archive stored on this site: its `.cradle` file, or the manifest and
	 * parts of an archive exported before 0.2.0.
	 *
	 * @return array|\WP_Error
	 */
	public static function create_from_archive( string $id ) {
		if ( ! preg_match( Manifest::ID_PATTERN, $id ) ) {
			return new \WP_Error( 'cradle_migration_missing', __( 'Choose an archive.', 'cradle-migration' ) );
		}
		$dir = Workspace::subdir( 'archives', $id, false );
		if ( is_wp_error( $dir ) || ! is_dir( $dir ) ) {
			return new \WP_Error( 'cradle_migration_missing', 'Archive not found.' );
		}
		if ( is_file( $dir . '/' . Container::file_name( $id ) ) ) {
			return self::create(
				array(
					'container' => 'archives/' . $id . '/' . Container::file_name( $id ),
					'auto'      => true,
				)
			);
		}
		return self::create(
			array(
				'location' => 'archives/' . $id,
				'manifest' => Manifest::manifest_name( $id ),
				'auto'     => true,
			)
		);
	}

	/**
	 * Destination-side conflicts: running migration jobs, core updates, maintenance mode.
	 */
	public static function conflicts( string $exclude ): string {
		$active = Job_Store::active( $exclude );
		if ( $active ) {
			return sprintf( 'Another migration job (%1$s, %2$s) is not finished.', $active[0]['type'], $active[0]['state'] );
		}
		if ( get_option( 'core_updater.lock' ) ) {
			return 'A WordPress core update is in progress.';
		}
		if ( file_exists( ABSPATH . '.maintenance' ) ) {
			return 'WordPress maintenance mode (.maintenance) is active; an update may be running.';
		}
		return '';
	}

	/**
	 * Operator approval after reviewing the preflight report.
	 *
	 * @return true|\WP_Error
	 */
	public static function approve( array $job ) {
		if ( 'paused' !== $job['state'] || 'review' !== $job['phase'] ) {
			return new \WP_Error( 'cradle_migration_state', 'This import is not waiting for review.' );
		}
		$dir    = Workspace::job_dir( $job['uuid'] );
		$report = is_wp_error( $dir ) ? $dir : Workspace::read_json( $dir . '/report.json' );
		if ( is_wp_error( $report ) || ! empty( $report['blocking'] ) ) {
			return new \WP_Error( 'cradle_migration_blocked', 'The preflight report has blocking problems.' );
		}
		$payload                = $job['payload'];
		$payload['approvedBy']  = get_current_user_id();
		$payload['approvedAt']  = time();
		if ( ! Job_Store::resume( $job['uuid'], array( 'paused' ), 'stage_files', $payload, array( 'file' => 0, 'seg' => 0 ) ) ) {
			return new \WP_Error( 'cradle_migration_state', 'The job could not be resumed (it may be running elsewhere).' );
		}
		cradle_log_activity( 'migration', 'import_approved', 'Import preflight approved; staging started.', array( 'job' => $job['uuid'] ) );
		return true;
	}

	/**
	 * Operator confirmation of the cutover. Returns the one-time recovery token.
	 *
	 * @return array{token:string,expires:int}|\WP_Error
	 */
	public static function begin_cutover( array $job ) {
		if ( 'awaiting_cutover' !== $job['state'] ) {
			return new \WP_Error( 'cradle_migration_state', 'This import is not ready for cutover.' );
		}
		$conflict = self::conflicts( $job['uuid'] );
		if ( $conflict ) {
			return new \WP_Error( 'cradle_migration_conflict', $conflict );
		}
		$dir = Workspace::job_dir( $job['uuid'] );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$journal  = new Journal( $dir );
		$recovery = $journal->recovery();
		if ( ! is_array( $recovery ) ) {
			return new \WP_Error( 'cradle_migration_recovery', 'The recovery context is missing; run the checkpoint again.' );
		}
		$token                     = bin2hex( random_bytes( 32 ) );
		$recovery['tokenHash']     = hash( 'sha256', $token );
		$recovery['expires']       = time() + self::TOKEN_TTL;
		$recovery['failures']      = 0;
		$recovery['confirmedBy']   = get_current_user_id();
		$recovery['confirmedAt']   = time();
		$ok                        = $journal->write_recovery( $recovery );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( ! Job_Store::resume( $job['uuid'], array( 'awaiting_cutover' ), 'cutover_db' ) ) {
			return new \WP_Error( 'cradle_migration_state', 'The job could not be resumed.' );
		}
		$journal->append( 'cutover_confirmed', array( 'user' => get_current_user_id() ) );
		cradle_log_activity( 'migration', 'import_cutover', 'Import cutover confirmed.', array( 'job' => $job['uuid'] ) );
		return array(
			'token'   => $token,
			'expires' => $recovery['expires'],
		);
	}

	/**
	 * Checks a job-scoped recovery token (used after cutover, when the browser session is gone).
	 */
	public static function check_token( string $uuid, string $token ): bool {
		$job = Job_Store::get( $uuid );
		if ( ! $job || self::TYPE !== $job['type'] || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return false;
		}
		$dir = Workspace::job_dir( $uuid );
		if ( is_wp_error( $dir ) ) {
			return false;
		}
		$journal  = new Journal( $dir );
		$recovery = $journal->recovery();
		if ( ! is_array( $recovery ) || empty( $recovery['tokenHash'] ) || (int) ( $recovery['expires'] ?? 0 ) < time() || (int) ( $recovery['failures'] ?? 0 ) >= 20 ) {
			return false;
		}
		if ( ! hash_equals( (string) $recovery['tokenHash'], hash( 'sha256', $token ) ) ) {
			$recovery['failures'] = (int) ( $recovery['failures'] ?? 0 ) + 1;
			$journal->write_recovery( $recovery );
			return false;
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Step dispatcher                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array|\WP_Error
	 */
	public static function step( array $job, Jobs $jobs ) {
		$dir = Workspace::job_dir( $job['uuid'] );
		if ( is_wp_error( $dir ) ) {
			return new \WP_Error( $dir->get_error_code(), $dir->get_error_message(), 'fatal' );
		}
		$dest = $job['payload']['destination'] ?? array();
		$now  = self::destination();
		if ( ( $dest['fingerprint'] ?? '' ) !== $now['fingerprint'] ) {
			return new \WP_Error( 'cradle_migration_identity', 'The destination identity (database, table prefix or path) changed since this import started. Refusing to continue.', 'fatal' );
		}
		$phase = (string) $job['phase'];
		if ( in_array( $phase, array( 'cutover_db', 'replace_files', 'finalize' ), true ) ) {
			Guard::start_quiesce( $job['uuid'], 'import', 1800 );
		}
		switch ( $phase ) {
			case 'unpack':
				return self::unpack( $job, $dir );
			case 'verify_manifest':
				return self::verify_manifest( $job, $dir );
			case 'hash_parts':
				return self::hash_parts( $job, $dir );
			case 'inspect_parts':
				return self::inspect_parts( $job, $dir );
			case 'scan_db':
				return self::scan_db( $job, $dir );
			case 'report':
				return self::report( $job, $dir );
			case 'stage_files':
				return self::stage_files( $job, $dir );
			case 'load_db':
				return self::load_db( $job, $dir );
			case 'validate_staged':
				return self::validate_staged( $job, $dir );
			case 'safety':
				return self::safety( $job, $dir );
			case 'checkpoint':
				return self::checkpoint( $job, $dir );
			case 'cutover_db':
				return self::cutover_db( $job, $dir );
			case 'replace_files':
				return self::replace_files( $job, $dir );
			case 'finalize':
				return self::finalize( $job, $dir );
			case 'verify':
				return self::verify( $job, $dir );
		}
		return new \WP_Error( 'cradle_migration_phase', 'Unknown import phase.', 'fatal' );
	}

	/**
	 * Seconds of work per step (filter `cradle_migration_step_seconds`; lower it on hosts with short
	 * execution limits). Each step always completes at least one unit, so progress never stalls.
	 */
	public static function budget(): float {
		return max( 0.0, min( 30.0, (float) apply_filters( 'cradle_migration_step_seconds', self::STEP_SECONDS ) ) );
	}

	private static function within( float $deadline, int &$units ): bool {
		return 0 === $units++ || microtime( true ) < $deadline;
	}

	public static function token_for( string $uuid ): string {
		return substr( str_replace( '-', '', $uuid ), 0, 6 );
	}

	public static function staged_name( string $uuid, string $table ): string {
		return 'cmig_' . self::token_for( $uuid ) . '_' . $table;
	}

	public static function old_name( string $uuid, string $table ): string {
		return 'cold_' . self::token_for( $uuid ) . '_' . $table;
	}

	private static function archive_dir( array $job ) {
		return Workspace::resolve_rel( (string) $job['payload']['location'] );
	}

	private static function limits( array $job ): array {
		return Manifest::limits( (array) ( $job['payload']['options']['limits'] ?? array() ) );
	}

	/**
	 * @return array|\WP_Error Validated manifest data saved by verify_manifest.
	 */
	public static function validated( string $dir ) {
		return Workspace::read_json( $dir . '/validated.json', 536870912 );
	}

	private static function preflight_state( string $dir ): array {
		$state = is_file( $dir . '/preflight.json' ) ? Workspace::read_json( $dir . '/preflight.json' ) : array();
		return is_array( $state ) ? $state + array(
			'errors'   => array(),
			'warnings' => array(),
		) : array(
			'errors'   => array(),
			'warnings' => array(),
		);
	}

	private static function save_preflight( string $dir, array $state ) {
		$state['errors']   = array_slice( array_values( array_unique( $state['errors'] ) ), 0, 300 );
		$state['warnings'] = array_slice( array_values( array_unique( $state['warnings'] ) ), 0, 300 );
		return Workspace::write_json( $dir . '/preflight.json', $state );
	}

	/* ------------------------------------------------------------------ */
	/* Preflight                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Extracts the manifest and parts of a `.cradle` container into the job's location, whole
	 * entries per step. An uploaded container is deleted afterwards; one in Archives is kept.
	 */
	private static function unpack( array $job, string $dir ) {
		$protected = Workspace::ensure_protected();
		if ( is_wp_error( $protected ) ) {
			return new \WP_Error( $protected->get_error_code(), $protected->get_error_message(), 'fatal' );
		}
		$target = self::archive_dir( $job );
		if ( is_wp_error( $target ) || ! is_dir( $target ) ) {
			return new \WP_Error( 'cradle_migration_location', 'The import working folder is missing.', 'fatal' );
		}
		$c    = (array) $job['cursor_data'];
		$path = self::container_path( (string) ( $job['payload']['container'] ?? '' ) );
		if ( is_wp_error( $path ) ) {
			return self::unpack_failed( $job, $dir, $path->get_error_message() );
		}
		$limits = self::limits( $job );
		if ( empty( $c['entries'] ) ) {
			$info = Container::inspect( $path, $limits );
			if ( is_wp_error( $info ) ) {
				return self::unpack_failed( $job, $dir, $info->get_error_message() );
			}
			$free  = Workspace::free_space( $target );
			$quota = (int) $limits['diskQuota'];
			if ( ( null !== $free && $info['bytes'] > $free * 0.9 ) || ( $quota > 0 && $info['bytes'] > $quota ) ) {
				return self::unpack_failed( $job, $dir, 'Insufficient disk space to unpack the .cradle file.' );
			}
			$c = array(
				'entries'  => $info['entries'],
				'manifest' => $info['manifest'],
				'next'     => 0,
			);
		}
		$deadline = microtime( true ) + self::budget();
		$units    = 0;
		while ( $c['next'] < count( $c['entries'] ) && self::within( $deadline, $units ) ) {
			$entry = $c['entries'][ $c['next'] ];
			$ok    = Container::extract( $path, $entry, $target . '/' . $entry['name'] );
			if ( is_wp_error( $ok ) ) {
				return 'cradle_migration_container' === $ok->get_error_code() ? self::unpack_failed( $job, $dir, $ok->get_error_message() ) : $ok;
			}
			++$c['next'];
		}
		if ( $c['next'] < count( $c['entries'] ) ) {
			return array(
				'state'          => 'preflight',
				'cursor_data'    => $c,
				'progress_done'  => $c['next'],
				'progress_total' => count( $c['entries'] ),
			);
		}
		// The uploaded container is deleted by verify_manifest, i.e. only after this step was committed.
		$payload             = $job['payload'];
		$payload['manifest'] = (string) $c['manifest'];
		return array(
			'state'       => 'preflight',
			'phase'       => 'verify_manifest',
			'payload'     => $payload,
			'cursor_data' => array(),
		);
	}

	/**
	 * Deletes an uploaded `.cradle` file once it was unpacked (a container in Archives is kept).
	 * Idempotent: repeated attempts find nothing to delete.
	 */
	private static function drop_uploaded_container( array $job ) {
		$rel = (string) ( $job['payload']['container'] ?? '' );
		if ( '' === $rel || 0 !== strpos( $rel, (string) $job['payload']['location'] . '/' ) ) {
			return;
		}
		$path = self::container_path( $rel );
		if ( ! is_wp_error( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Ends an import whose container cannot be used, with a report that names the problem.
	 */
	private static function unpack_failed( array $job, string $dir, string $message ): array {
		Workspace::write_json(
			$dir . '/report.json',
			array(
				'checks'   => array(),
				'blocking' => array( $message ),
				'warnings' => array(),
				'admin'    => array(),
			)
		);
		cradle_log_activity( 'migration', 'import_preflight_failed', 'Import preflight failed.', array( 'job' => $job['uuid'] ) );
		return array(
			'state'         => 'failed',
			'phase'         => 'preflight_failed',
			'error_code'    => 'preflight_failed',
			'error_message' => 'Preflight failed: ' . $message,
		);
	}

	private static function verify_manifest( array $job, string $dir ) {
		$protected = Workspace::ensure_protected();
		if ( is_wp_error( $protected ) ) {
			return new \WP_Error( $protected->get_error_code(), $protected->get_error_message(), 'fatal' );
		}
		$archive_dir = self::archive_dir( $job );
		if ( is_wp_error( $archive_dir ) ) {
			return new \WP_Error( 'cradle_migration_location', $archive_dir->get_error_message(), 'fatal' );
		}
		self::drop_uploaded_container( $job );
		$limits   = self::limits( $job );
		$name     = (string) $job['payload']['manifest'];
		$expected = substr( $name, 0, -strlen( '.manifest.json' ) );
		$state    = array(
			'errors'   => array(),
			'warnings' => array(),
		);
		foreach ( array( 'validated.json', 'preflight.json', 'scan.json', 'scan-issues.jsonl', 'report.json' ) as $f ) {
			if ( is_file( $dir . '/' . $f ) ) {
				wp_delete_file( $dir . '/' . $f );
			}
		}
		$raw = Workspace::read_json( $archive_dir . '/' . $name, (int) $limits['maxManifestBytes'] );
		if ( is_wp_error( $raw ) ) {
			$state['errors'][] = $raw->get_error_message();
			self::save_preflight( $dir, $state );
			return array(
				'state' => 'preflight',
				'phase' => 'report',
			);
		}
		$v                 = Manifest::validate( $raw, $limits, $expected );
		$state['errors']   = $v['errors'];
		$state['warnings'] = $v['warnings'];
		unset( $raw );
		if ( ! $v['errors'] ) {
			$m    = $v['manifest'];
			$dest = $job['payload']['destination'];
			if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
				$state['errors'][] = 'PHP 8.3 or newer is required on the destination.';
			}
			foreach ( array( 'zip', 'mysqli', 'json', 'hash', 'mbstring' ) as $ext ) {
				if ( ! extension_loaded( $ext ) ) {
					$state['errors'][] = 'The PHP extension ' . $ext . ' is required.';
				}
			}
			if ( version_compare( $m['source']['wordpress'], $dest['wordpress'], '>' ) ) {
				$state['errors'][] = sprintf( 'The archive comes from WordPress %1$s, newer than this site (%2$s). Update WordPress here first; databases are never downgraded.', $m['source']['wordpress'], $dest['wordpress'] );
			} elseif ( version_compare( $m['source']['wordpress'], $dest['wordpress'], '<' ) ) {
				$state['warnings'][] = sprintf( 'The archive comes from WordPress %1$s; this site runs %2$s. WordPress will ask to update the database after restore.', $m['source']['wordpress'], $dest['wordpress'] );
			}
			if ( $m['source']['dbVersion'] > $dest['dbVersion'] ) {
				$state['errors'][] = 'The archive database schema is newer than this WordPress installation supports.';
			}
			foreach ( $m['tables'] as $t ) {
				if ( strlen( self::staged_name( $job['uuid'], $t['name'] ) ) > 64 || strlen( $dest['prefix'] . $t['name'] ) > 64 ) {
					$state['errors'][] = 'Table ' . $t['name'] . ' would exceed the 64-character name limit with this table prefix.';
				}
			}
			$has = array();
			foreach ( $m['tables'] as $t ) {
				$has[ $t['name'] ] = true;
			}
			if ( $m['scope']['database'] && ( ! isset( $has['options'] ) || ! isset( $has['users'] ) || ! isset( $has['usermeta'] ) ) ) {
				$state['errors'][] = 'The archive database lacks the WordPress options/users/usermeta tables.';
			}
			$ok = Workspace::write_json(
				$dir . '/validated.json',
				array(
					'manifest' => $m,
					'entries'  => $v['entries'],
				)
			);
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		self::save_preflight( $dir, $state );
		if ( $state['errors'] ) {
			return array(
				'state' => 'preflight',
				'phase' => 'report',
			);
		}
		$m = $v['manifest'];
		return array(
			'state'          => 'preflight',
			'phase'          => 'hash_parts',
			'progress_total' => count( $m['parts'] ) * 2 + count( $m['tables'] ),
			'bytes_total'    => (int) array_sum( array_column( $m['parts'], 'size' ) ),
			'cursor_data'    => array( 'part' => 1 ),
		);
	}

	private static function hash_parts( array $job, string $dir ) {
		$v = self::validated( $dir );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$archive_dir = self::archive_dir( $job );
		$state       = self::preflight_state( $dir );
		$c           = $job['cursor_data'];
		$parts       = $v['manifest']['parts'];
		$deadline    = microtime( true ) + self::budget();
		$units       = 0;
		$bytes       = (int) $job['bytes_done'];
		while ( $c['part'] <= count( $parts ) && self::within( $deadline, $units ) ) {
			$p    = $parts[ $c['part'] - 1 ];
			$path = $archive_dir . '/' . $p['name'];
			if ( ! is_file( $path ) ) {
				$state['errors'][] = sprintf( 'Archive part %d (%s) is missing.', $p['index'], $p['name'] );
			} elseif ( filesize( $path ) !== $p['size'] ) {
				$state['errors'][] = sprintf( 'Archive part %d has the wrong size (truncated or incomplete upload).', $p['index'] );
			} elseif ( ! hash_equals( $p['sha256'], (string) hash_file( 'sha256', $path ) ) ) {
				$state['errors'][] = sprintf( 'Archive part %d failed its SHA-256 checksum (corrupted).', $p['index'] );
			}
			$bytes += $p['size'];
			++$c['part'];
		}
		self::save_preflight( $dir, $state );
		if ( $c['part'] <= count( $parts ) ) {
			return array(
				'cursor_data'   => $c,
				'bytes_done'    => $bytes,
				'progress_done' => $c['part'] - 1,
			);
		}
		if ( $state['errors'] ) {
			return array( 'phase' => 'report' );
		}
		return array(
			'phase'         => 'inspect_parts',
			'bytes_done'    => $bytes,
			'progress_done' => count( $parts ),
			'cursor_data'   => array(
				'part'  => 1,
				'seen'  => 0,
				'bytes' => 0,
			),
		);
	}

	private static function inspect_parts( array $job, string $dir ) {
		$v = self::validated( $dir );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$limits      = self::limits( $job );
		$archive_dir = self::archive_dir( $job );
		$state       = self::preflight_state( $dir );
		$c           = $job['cursor_data'];
		$parts       = $v['manifest']['parts'];
		$entries     = $v['entries'];
		$deadline    = microtime( true ) + self::budget();
		$units       = 0;
		$seen_path   = $dir . '/seen.json';
		$seen        = 1 === (int) $c['part'] || ! is_file( $seen_path ) ? array() : Workspace::read_json( $seen_path );
		$seen        = is_array( $seen ) ? $seen : array();
		$special     = array( 0xA000 => 'symbolic link', 0x2000 => 'character device', 0x6000 => 'block device', 0x1000 => 'FIFO', 0xC000 => 'socket' );
		while ( $c['part'] <= count( $parts ) && self::within( $deadline, $units ) ) {
			$p   = $parts[ $c['part'] - 1 ];
			$zip = new \ZipArchive();
			$rc  = $zip->open( $archive_dir . '/' . $p['name'], \ZipArchive::RDONLY );
			if ( true !== $rc ) {
				$state['errors'][] = sprintf( 'Archive part %d is not a readable ZIP file (%s).', $p['index'], (string) $rc );
				++$c['part'];
				continue;
			}
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$stat = $zip->statIndex( $i, \ZipArchive::FL_UNCHANGED );
				if ( ! is_array( $stat ) ) {
					$state['errors'][] = sprintf( 'Archive part %d has an unreadable entry.', $p['index'] );
					continue;
				}
				$name = (string) $stat['name'];
				if ( '.cradle-empty' === $name && 0 === (int) $stat['size'] ) {
					continue;
				}
				$label = Path_Guard::printable( $name );
				if ( isset( $seen[ $name ] ) ) {
					$state['errors'][] = 'Duplicate archive entry ' . $label . '.';
					continue;
				}
				$seen[ $name ] = 1;
				if ( ! isset( $entries[ $name ] ) || (int) $entries[ $name ]['part'] !== (int) $p['index'] ) {
					$state['errors'][] = 'Unexpected archive entry ' . $label . ' (not listed in the manifest for this part).';
					continue;
				}
				if ( ! empty( $stat['encryption_method'] ) ) {
					$state['errors'][] = 'Encrypted archive entry ' . $label . ' is not supported.';
				}
				$opsys = 0;
				$attr  = 0;
				if ( $zip->getExternalAttributesIndex( $i, $opsys, $attr ) && \ZipArchive::OPSYS_UNIX === $opsys ) {
					$mode = ( $attr >> 16 ) & 0xF000;
					if ( isset( $special[ $mode ] ) ) {
						$state['errors'][] = sprintf( 'Archive entry %1$s is a %2$s; only regular files are accepted.', $label, $special[ $mode ] );
					} elseif ( 0x4000 === $mode ) {
						$state['errors'][] = 'Archive entry ' . $label . ' is a directory where a file was expected.';
					}
				}
				$size = (int) $stat['size'];
				$comp = (int) $stat['comp_size'];
				if ( $size !== (int) $entries[ $name ]['size'] ) {
					$state['errors'][] = 'Archive entry ' . $label . ' has a different size than the manifest states.';
				}
				if ( $size >= $limits['ratioMinBytes'] && ( $comp <= 0 || $size / max( 1, $comp ) > $limits['maxRatio'] ) ) {
					$state['errors'][] = sprintf( 'Archive entry %1$s expands %2$d:1, above the %3$d:1 limit (possible decompression bomb).', $label, (int) ( $size / max( 1, $comp ) ), $limits['maxRatio'] );
				}
				$c['bytes'] += $size;
				if ( count( $seen ) > $limits['maxEntries'] ) {
					$state['errors'][] = 'The archive has more entries than allowed.';
					break;
				}
			}
			$zip->close();
			++$c['part'];
		}
		if ( $c['bytes'] > $limits['maxTotalBytes'] ) {
			$state['errors'][] = 'The archive expands beyond the total size limit.';
		}
		if ( $c['part'] <= count( $parts ) ) {
			Workspace::write_json( $seen_path, $seen );
			self::save_preflight( $dir, $state );
			return array(
				'cursor_data'   => $c,
				'progress_done' => count( $parts ) + $c['part'] - 1,
			);
		}
		foreach ( $entries as $name => $info ) {
			if ( ! isset( $seen[ $name ] ) ) {
				$state['errors'][] = 'Archive entry ' . Path_Guard::printable( $name ) . ' listed in the manifest is missing from part ' . (int) $info['part'] . '.';
			}
		}
		if ( is_file( $seen_path ) ) {
			wp_delete_file( $seen_path );
		}
		self::save_preflight( $dir, $state );
		if ( $state['errors'] ) {
			return array( 'phase' => 'report' );
		}
		return array(
			'phase'         => 'scan_db',
			'progress_done' => count( $parts ) * 2,
			'cursor_data'   => array(
				't'         => 0,
				'c'         => 0,
				'issuesLen' => 0,
				'issues'    => 0,
			),
		);
	}

	/**
	 * Reads a DB chunk entry fully (bounded) and verifies its checksum.
	 *
	 * @return string|\WP_Error
	 */
	private static function read_chunk( string $archive_dir, array $manifest, array $chunk, array &$zips ) {
		$part = (int) $chunk['part'];
		if ( ! isset( $zips[ $part ] ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $archive_dir . '/' . Manifest::part_name( $manifest['archiveId'], $part ), \ZipArchive::RDONLY ) ) {
				return new \WP_Error( 'cradle_migration_zip', 'Could not open archive part ' . $part . '.', 'fatal' );
			}
			$zips[ $part ] = $zip;
		}
		$stream = $zips[ $part ]->getStream( $chunk['entry'] );
		if ( ! $stream ) {
			return new \WP_Error( 'cradle_migration_zip', 'Could not read ' . $chunk['entry'] . '.', 'fatal' );
		}
		$data = stream_get_contents( $stream, (int) $chunk['bytes'] + 1 );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( ! is_string( $data ) || strlen( $data ) !== (int) $chunk['bytes'] || ! hash_equals( $chunk['sha256'], hash( 'sha256', $data ) ) ) {
			return new \WP_Error( 'cradle_migration_checksum', 'Database chunk ' . $chunk['entry'] . ' failed its checksum.', 'fatal' );
		}
		return $data;
	}

	private static function transformer( array $job, array $manifest ): Transformer {
		$opaque = apply_filters( 'cradle_migration_opaque_keys', (array) ( $job['payload']['options']['opaque'] ?? array() ) );
		return new Transformer( $manifest['source'], $job['payload']['destination'], (array) $opaque );
	}

	private static function scan_db( array $job, string $dir ) {
		$v = self::validated( $dir );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$m           = $v['manifest'];
		$archive_dir = self::archive_dir( $job );
		$state       = self::preflight_state( $dir );
		$c           = $job['cursor_data'];
		$scan        = 0 === (int) $c['t'] && 0 === (int) $c['c'] ? array() : Workspace::read_json( $dir . '/scan.json' );
		$scan        = is_array( $scan ) ? $scan : array();
		$scan       += array(
			'admins'      => array(),
			'logins'      => array(),
			'sessions'    => 0,
			'appPasswords' => 0,
			'cronEvents'  => 0,
			'loginRoute'  => null,
			'activePlugins' => array(),
			'template'    => '',
			'stylesheet'  => '',
			'rewritten'   => 0,
			'rows'        => 0,
		);
		$tf          = self::transformer( $job, $m );
		$src_prefix  = $m['source']['tablePrefix'];
		$issues      = array();
		$zips        = array();
		$deadline    = microtime( true ) + self::budget();
		$units       = 0;
		while ( $c['t'] < count( $m['tables'] ) && self::within( $deadline, $units ) ) {
			$t = $m['tables'][ $c['t'] ];
			if ( $c['c'] >= count( $t['chunks'] ) ) {
				++$c['t'];
				$c['c'] = 0;
				continue;
			}
			$chunk = $t['chunks'][ $c['c'] ];
			$data  = self::read_chunk( $archive_dir, $m, $chunk, $zips );
			if ( is_wp_error( $data ) ) {
				$state['errors'][] = $data->get_error_message();
				++$c['c'];
				continue;
			}
			$lines = explode( "\n", rtrim( $data, "\n" ) );
			if ( count( $lines ) !== (int) $chunk['rows'] ) {
				$state['errors'][] = sprintf( 'Chunk %s has %d rows; the manifest says %d.', $chunk['entry'], count( $lines ), $chunk['rows'] );
			}
			foreach ( $lines as $line ) {
				$row = Transformer::decode_line( $line, $t['columns'] );
				if ( null === $row ) {
					$state['errors'][] = 'Chunk ' . $chunk['entry'] . ' contains a malformed row.';
					break;
				}
				++$scan['rows'];
				$out = $tf->row( $t['name'], $t['columns'], $row );
				if ( $out['changed'] ) {
					++$scan['rewritten'];
				}
				foreach ( $out['issues'] as $issue ) {
					if ( empty( $issue['warning'] ) ) {
						$issues[] = $issue;
					} elseif ( count( $state['warnings'] ) < 250 ) {
						$state['warnings'][] = sprintf( '%1$s.%2$s (%3$s): %4$s', $issue['table'], $issue['column'], $issue['key'], $issue['detail'] );
					}
				}
				self::collect( $scan, $t, $row, $src_prefix );
			}
			++$c['c'];
		}
		foreach ( $zips as $zip ) {
			$zip->close();
		}
		if ( $issues ) {
			$len = Workspace::append_lines( $dir . '/scan-issues.jsonl', array_slice( $issues, 0, max( 0, 1000 - (int) $c['issues'] ) ), (int) $c['issuesLen'] );
			if ( is_wp_error( $len ) ) {
				return $len;
			}
			$c['issuesLen'] = $len;
			$c['issues']   += count( $issues );
		}
		Workspace::write_json( $dir . '/scan.json', $scan );
		self::save_preflight( $dir, $state );
		if ( $c['t'] < count( $m['tables'] ) ) {
			return array(
				'cursor_data'   => $c,
				'progress_done' => count( $m['parts'] ) * 2 + (int) $c['t'],
			);
		}
		return array(
			'phase'         => 'report',
			'progress_done' => count( $m['parts'] ) * 2 + count( $m['tables'] ),
			'cursor_data'   => array( 'issues' => (int) $c['issues'] ),
		);
	}

	/**
	 * Gathers facts for the preflight report (admins, sessions, login route, cron, plugins).
	 */
	private static function collect( array &$scan, array $t, array $row, string $prefix ) {
		$col = array();
		foreach ( $t['columns'] as $i => $c ) {
			$col[ $c['name'] ] = $i;
		}
		if ( 'usermeta' === $t['name'] && isset( $col['meta_key'], $col['user_id'], $col['meta_value'] ) ) {
			$key = (string) $row[ $col['meta_key'] ];
			if ( $prefix . 'capabilities' === $key && false !== strpos( (string) $row[ $col['meta_value'] ], '"administrator"' ) && count( $scan['admins'] ) < 50 ) {
				$scan['admins'][] = (int) $row[ $col['user_id'] ];
			} elseif ( 'session_tokens' === $key ) {
				++$scan['sessions'];
			} elseif ( '_application_passwords' === $key ) {
				++$scan['appPasswords'];
			}
		} elseif ( 'users' === $t['name'] && isset( $col['ID'], $col['user_login'] ) && count( $scan['logins'] ) < 5000 ) {
			$scan['logins'][ (string) $row[ $col['ID'] ] ] = mb_substr( (string) $row[ $col['user_login'] ], 0, 60 );
		} elseif ( 'options' === $t['name'] && isset( $col['option_name'], $col['option_value'] ) ) {
			$name  = (string) $row[ $col['option_name'] ];
			$value = (string) $row[ $col['option_value'] ];
			if ( 'cron' === $name ) {
				$scan['cronEvents'] = max( 0, substr_count( $value, 's:8:"schedule";' ) );
			} elseif ( 'cradle_login_settings' === $name ) {
				$node               = Serialized::parse( $value );
				$scan['loginRoute'] = self::serialized_flag( $node, 'enabled' );
			} elseif ( 'active_plugins' === $name ) {
				$scan['activePlugins'] = self::serialized_strings( $value );
			} elseif ( 'template' === $name || 'stylesheet' === $name ) {
				$scan[ $name ] = mb_substr( $value, 0, 100 );
			}
		}
	}

	/**
	 * Reads a boolean-ish top-level flag from a parsed serialized array (null when absent).
	 */
	private static function serialized_flag( $node, string $key ) {
		if ( ! is_array( $node ) || 'a' !== $node['t'] ) {
			return null;
		}
		foreach ( $node['items'] as $pair ) {
			if ( 's' === $pair[0]['t'] && $key === $pair[0]['v'] ) {
				$v = $pair[1];
				if ( 'raw' === $v['t'] ) {
					return ! in_array( $v['v'], array( 'b:0;', 'i:0;', 'N;' ), true );
				}
				return 's' === $v['t'] ? ! in_array( $v['v'], array( '', '0' ), true ) : true;
			}
		}
		return null;
	}

	/**
	 * String values of a flat serialized array (e.g. active_plugins), parsed without unserialize().
	 *
	 * @return string[]
	 */
	public static function serialized_strings( string $value ): array {
		$node = Serialized::parse( $value );
		$out  = array();
		if ( is_array( $node ) && 'a' === $node['t'] ) {
			foreach ( $node['items'] as $pair ) {
				if ( 's' === $pair[1]['t'] ) {
					$out[] = $pair[1]['v'];
				}
			}
		}
		return $out;
	}

	private static function report( array $job, string $dir ) {
		$state  = self::preflight_state( $dir );
		$v      = is_file( $dir . '/validated.json' ) ? self::validated( $dir ) : null;
		$m      = is_array( $v ) ? $v['manifest'] : null;
		$scan   = is_file( $dir . '/scan.json' ) ? Workspace::read_json( $dir . '/scan.json' ) : array();
		$scan   = is_array( $scan ) ? $scan : array();
		$opts   = $job['payload']['options'];
		$dest   = $job['payload']['destination'];
		$limits = self::limits( $job );
		$issues = Workspace::read_lines( $dir . '/scan-issues.jsonl', 200 );
		foreach ( $issues as $issue ) {
			$state['errors'][] = sprintf( 'Unsupported data in %1$s.%2$s (key %3$s): %4$s', $issue['table'], $issue['column'], '' === $issue['key'] ? '?' : $issue['key'], $issue['detail'] );
		}
		$report = array(
			'job'         => $job['uuid'],
			'generated'   => gmdate( 'c' ),
			'blocking'    => array(),
			'warnings'    => array(),
			'checks'      => array(),
			'tables'      => array(),
			'files'       => array(),
			'urlMapping'  => array(),
			'admin'       => array(),
			'external'    => array(),
			'exclusions'  => array(),
			'unsupported' => array(),
			'archive'     => array(),
		);
		$check  = static function ( string $label, bool $ok, string $detail = '' ) use ( &$report ) {
			$report['checks'][] = array(
				'label'  => $label,
				'status' => $ok ? 'pass' : 'fail',
				'detail' => $detail,
			);
		};
		$check( 'Permission and request integrity', true, 'Import capability checked when the job was created; browser actions use nonces.' );
		$protected = Workspace::ensure_protected();
		$check( 'Protected private storage for staging and backups', ! is_wp_error( $protected ), is_wp_error( $protected ) ? $protected->get_error_message() : 'Direct HTTP access to private storage is denied.' );
		if ( is_wp_error( $protected ) ) {
			$state['errors'][] = $protected->get_error_message();
		}
		$conflict = self::conflicts( $job['uuid'] );
		$check( 'No running import, export or update', '' === $conflict, $conflict );
		if ( '' !== $conflict ) {
			$state['errors'][] = $conflict;
		}
		$check( 'Destination identity', true, sprintf( 'Database %1$s, table prefix %2$s.', $dest['db'], $dest['prefix'] ) );
		if ( $m ) {
			$needed = (int) $m['totalBytes'];
			foreach ( $m['tables'] as $t ) {
				$needed += (int) ( array_sum( array_column( $t['chunks'], 'bytes' ) ) * 1.5 );
			}
			$free      = Workspace::free_space( $dir );
			$available = null === $free ? null : $free;
			if ( ! empty( $limits['diskQuota'] ) ) {
				$available = null === $available ? (float) $limits['diskQuota'] : min( $available, (float) $limits['diskQuota'] );
			}
			$ok = null === $available || $needed <= $available * 0.9;
			$check( 'Available disk space', $ok, null === $available ? 'Free space could not be measured on this host.' : sprintf( 'Needs about %1$s; %2$s available%3$s.', size_format( $needed ), size_format( $available ), ! empty( $limits['diskQuota'] ) ? ' (operator quota)' : '' ) );
			if ( ! $ok ) {
				$state['errors'][] = 'Insufficient disk space for staging and backups.';
			}
			$check( 'Archive format and inventory', true, sprintf( 'Format %1$d, archive %2$s, %3$d parts, %4$d files, %5$d tables.', $m['formatVersion'], $m['archiveId'], count( $m['parts'] ), count( $m['files'] ), count( $m['tables'] ) ) );
			$check( 'Per-part and per-chunk SHA-256 checksums', true, 'Verified. Per-file checksums are verified while staging.' );
			$check( 'Paths, special files, sizes and compression ratios', true, 'No traversal, absolute, device, symlink, duplicate or oversized entries.' );
			$report['archive'] = array(
				'id'          => $m['archiveId'],
				'created'     => $m['createdAt'],
				'mode'        => $m['mode'],
				'source'      => $m['source']['homeUrl'],
				'wordpress'   => $m['source']['wordpress'],
				'consistency' => $m['consistency'],
				'generator'   => $m['generator'],
			);
			$tables_live = array_map( 'strtolower', Db::tables_with_prefix( $dest['prefix'] ) );
			$in_archive  = array();
			foreach ( $m['tables'] as $t ) {
				$live                           = $dest['prefix'] . $t['name'];
				$in_archive[ strtolower( $live ) ] = true;
				if ( in_array( $t['name'], Db::RUNTIME_TABLES, true ) || 'schema-only' === $t['rowsMode'] ) {
					$action = 'kept (destination runtime table)';
				} elseif ( ! $opts['database'] ) {
					$action = 'skipped (database not selected)';
				} else {
					$action = in_array( strtolower( $live ), $tables_live, true ) ? 'replaced (old table kept as backup)' : 'created';
				}
				$report['tables'][] = array(
					'table'  => $live,
					'rows'   => $t['rows'],
					'action' => $action,
				);
			}
			foreach ( $tables_live as $live ) {
				if ( ! isset( $in_archive[ $live ] ) ) {
					$report['tables'][] = array(
						'table'  => $live,
						'rows'   => null,
						'action' => 'destination-only, left untouched',
					);
				}
			}
			$counts = array();
			foreach ( $m['files'] as $f ) {
				$key = $f['category'] . ( in_array( $f['category'], array( 'uploads', 'root', 'languages', 'translations' ), true ) ? '' : ':' . strtok( $f['path'], '/' ) );
				if ( ! isset( $counts[ $key ] ) ) {
					$counts[ $key ] = array( 0, 0, 0 );
				}
				++$counts[ $key ][0];
				$counts[ $key ][1] += $f['size'];
				if ( $f['executable'] ) {
					++$counts[ $key ][2];
				}
			}
			foreach ( $counts as $key => $n ) {
				list( $cat, $slug ) = array_pad( explode( ':', $key, 2 ), 2, '' );
				$enabled             = ! empty( $opts[ 'translations' === $cat ? 'database' : $cat ] );
				if ( 'plugins' === $cat && in_array( $slug, self::ENGINE_SLUGS, true ) ) {
					$action = 'preserved: the running migration engine/core on this site is kept';
				} elseif ( ! $enabled ) {
					$action = 'translations' === $cat ? 'skipped (restored only together with the database)' : 'skipped (not selected)';
				} elseif ( 'uploads' === $cat ) {
					$action = 'destination uploads replaced (Cradle private storage kept); old files moved to backup';
				} elseif ( 'languages' === $cat ) {
					$action = 'language files installed (files and plugins/, themes/ folders of the same name moved to backup); keeps the site language and its text direction';
				} elseif ( 'translations' === $cat ) {
					$action = 'Cradle Translations catalogs replaced (old ones moved to backup)';
				} else {
					$live   = 'themes' === $cat ? $dest['themesDir'] . '/' . $slug : ( 'plugins' === $cat ? $dest['pluginsDir'] . '/' . $slug : $dest['abspath'] );
					$action = file_exists( $live ) ? 'replaced (old copy moved to backup) — privileged code deployment' : 'installed — privileged code deployment';
				}
				$report['files'][] = array(
					'category' => $cat,
					'package'  => $slug,
					'files'    => $n[0],
					'bytes'    => $n[1],
					'action'   => $action,
				);
				if ( $n[2] ) {
					$report['warnings'][] = sprintf( '%d executable file(s) in uploads will be skipped.', $n[2] );
				}
			}
			$report['urlMapping'] = array(
				array( $m['source']['homeUrl'], $dest['home'] ),
				array( $m['source']['siteUrl'], $dest['siteurl'] ),
				array( $m['source']['abspath'], $dest['abspath'] ),
				array( 'table prefix ' . $m['source']['tablePrefix'], $dest['prefix'] ),
			);
			$logins = array();
			foreach ( (array) ( $scan['admins'] ?? array() ) as $uid ) {
				if ( isset( $scan['logins'][ (string) $uid ] ) ) {
					$logins[] = $scan['logins'][ (string) $uid ];
				}
			}
			if ( $opts['database'] && ! $logins ) {
				$state['errors'][] = 'The archive contains no administrator account; nobody could sign in after restore.';
			}
			$report['admin'] = array(
				'logins'  => $logins,
				'summary' => $opts['database']
					? sprintf( 'After restore, sign in with an administrator account from the source site (%s) using that account\'s source password. The destination\'s current accounts, sessions and your current login stop working at cutover; all %d imported session(s) and %d application password set(s) are cleared. Keep this page open (it holds a one-time recovery token) or use the CLI recovery command.', implode( ', ', $logins ), (int) ( $scan['sessions'] ?? 0 ), (int) ( $scan['appPasswords'] ?? 0 ) )
					: 'The database is not replaced; existing accounts keep working.',
			);
			$report['external'] = array(
				sprintf( 'Imported WP-Cron events (%d) do not run until an administrator releases the safety hold.', (int) ( $scan['cronEvents'] ?? 0 ) ),
				'Outgoing email and outbound HTTP to other hosts (webhooks, payment and API calls made through WordPress) are paused by the safety hold.',
				null === ( $scan['loginRoute'] ?? null ) ? 'No Cradle custom login route configuration in the archive.' : 'The imported Cradle custom login route will be disabled (standard wp-login.php) until an administrator re-enables it.',
				sprintf( 'Imported active plugins (%d) are activated after file replacement; during cutover only the Cradle engine runs.', count( (array) ( $scan['activePlugins'] ?? array() ) ) ),
				'Destination siteurl/home, table prefix, database credentials (wp-config.php) and private storage location are preserved.',
			);
			$report['exclusions']  = array_slice( $m['exclusions'], 0, 300 );
			$report['unsupported'] = $m['unsupported'];
			foreach ( $m['unsupported'] as $u ) {
				$report['warnings'][] = 'Not in archive (unsupported at source): ' . ( $u['kind'] ?? '' ) . ' ' . ( $u['name'] ?? '' ) . ' — ' . ( $u['detail'] ?? '' );
			}
			if ( 'none' === ( $m['consistency']['strategy'] ?? 'none' ) ) {
				$report['warnings'][] = 'The source export was not taken in a write-quiescence window: ' . $m['consistency']['notes'];
			}
			$report['warnings'][] = sprintf( '%d stored value(s) will be rewritten for the new URL/path/prefix (post GUIDs are not rewritten).', (int) ( $scan['rewritten'] ?? 0 ) );
		}
		$report['warnings'] = array_values( array_unique( array_merge( $state['warnings'], $report['warnings'] ) ) );
		$report['blocking'] = array_values( array_unique( $state['errors'] ) );
		Workspace::write_json( $dir . '/report.json', $report );
		if ( $report['blocking'] ) {
			cradle_log_activity( 'migration', 'import_preflight_failed', 'Import preflight failed.', array( 'job' => $job['uuid'] ) );
			return array(
				'state'         => 'failed',
				'phase'         => 'preflight_failed',
				'error_code'    => 'preflight_failed',
				'error_message' => 'Preflight failed: ' . implode( ' | ', array_slice( $report['blocking'], 0, 5 ) ),
			);
		}
		if ( ! empty( $job['payload']['auto'] ) ) {
			// One-step import: the operator confirmed the whole import when starting it. The report
			// stays available on the job; staging still leaves the live site untouched.
			$payload                 = $job['payload'];
			$payload['approvedBy']   = (int) $job['owner_id'];
			$payload['approvedAt']   = time();
			$payload['autoApproved'] = true;
			cradle_log_activity( 'migration', 'import_approved', 'Import preflight passed; staging started (one-step import).', array( 'job' => $job['uuid'] ) );
			return array(
				'state'       => 'running',
				'phase'       => 'stage_files',
				'payload'     => $payload,
				'cursor_data' => array(
					'file' => 0,
					'seg'  => 0,
				),
			);
		}
		return array(
			'state' => 'paused',
			'phase' => 'review',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Staging                                                            */
	/* ------------------------------------------------------------------ */

	private static function file_selected( array $opts, array $f ): bool {
		if ( $f['executable'] ) {
			return false;
		}
		if ( 'plugins' === $f['category'] && in_array( strtok( $f['path'], '/' ), self::ENGINE_SLUGS, true ) ) {
			return false;
		}
		// Cradle Translations data is indexed by options in the database: restore both or neither.
		if ( 'translations' === $f['category'] ) {
			return ! empty( $opts['database'] );
		}
		return ! empty( $opts[ $f['category'] ] );
	}

	private static function stage_files( array $job, string $dir ) {
		$v = self::validated( $dir );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$m           = $v['manifest'];
		$opts        = $job['payload']['options'];
		$archive_dir = self::archive_dir( $job );
		$tree        = $dir . '/tree';
		if ( ! is_dir( $tree ) && ! wp_mkdir_p( $tree ) ) {
			return new \WP_Error( 'cradle_migration_write', 'Could not create the staging directory.' );
		}
		$c        = $job['cursor_data'];
		$zips     = array();
		$deadline = microtime( true ) + self::budget();
		$units       = 0;
		$bytes    = (int) $job['bytes_done'];
		while ( $c['file'] < count( $m['files'] ) && self::within( $deadline, $units ) ) {
			$f = $m['files'][ $c['file'] ];
			if ( ! self::file_selected( $opts, $f ) ) {
				++$c['file'];
				$c['seg'] = 0;
				continue;
			}
			$target = Path_Guard::join( $tree, $f['category'] . '/' . $f['path'] );
			if ( is_wp_error( $target ) ) {
				return new \WP_Error( $target->get_error_code(), $target->get_error_message(), 'fatal' );
			}
			$partial = $target . '.cradle-partial';
			if ( ! is_dir( dirname( $target ) ) && ! wp_mkdir_p( dirname( $target ) ) ) {
				return new \WP_Error( 'cradle_migration_write', 'Could not create a staging directory (disk full or permissions).' );
			}
			if ( ! Storage::path_within( $partial, $tree ) ) {
				return new \WP_Error( 'cradle_migration_escape', 'A staging path escapes the staging directory.', 'fatal' );
			}
			// Resume check: the partial file must hold exactly the segments already written.
			$expected_len = 0;
			for ( $s = 0; $s < $c['seg']; $s++ ) {
				$expected_len += $f['segments'][ $s ]['size'];
			}
			clearstatcache( true, $partial );
			if ( $c['seg'] > 0 && ( ! is_file( $partial ) || filesize( $partial ) !== $expected_len ) ) {
				$c['seg'] = 0;
			}
			while ( $c['seg'] < count( $f['segments'] ) ) {
				$seg  = $f['segments'][ $c['seg'] ];
				$part = (int) $seg['part'];
				if ( ! isset( $zips[ $part ] ) ) {
					$zip = new \ZipArchive();
					if ( true !== $zip->open( $archive_dir . '/' . Manifest::part_name( $m['archiveId'], $part ), \ZipArchive::RDONLY ) ) {
						return new \WP_Error( 'cradle_migration_zip', 'Could not open archive part ' . $part . '.' );
					}
					$zips[ $part ] = $zip;
				}
				$in  = $zips[ $part ]->getStream( $seg['entry'] );
				$out = fopen( $partial, 0 === (int) $c['seg'] ? 'wb' : 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				if ( ! $in || ! $out ) {
					return new \WP_Error( 'cradle_migration_stage', 'Could not stage ' . Path_Guard::printable( $f['path'] ) . '.' );
				}
				$copied = stream_copy_to_stream( $in, $out, (int) $seg['size'] + 1 );
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				if ( $copied !== (int) $seg['size'] ) {
					return new \WP_Error( 'cradle_migration_stage', 'Archive entry ' . Path_Guard::printable( $seg['entry'] ) . ' did not expand to its declared size (disk full or corrupt archive).', false === $copied ? null : 'fatal' );
				}
				$bytes += $copied;
				++$c['seg'];
			}
			if ( ! hash_equals( $f['sha256'], (string) hash_file( 'sha256', $partial ) ) ) {
				wp_delete_file( $partial );
				return new \WP_Error( 'cradle_migration_checksum', 'File ' . Path_Guard::printable( $f['category'] . '/' . $f['path'] ) . ' failed its SHA-256 checksum.', 'fatal' );
			}
			if ( ! rename( $partial, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				return new \WP_Error( 'cradle_migration_stage', 'Could not finish staging a file.' );
			}
			++$c['file'];
			$c['seg'] = 0;
		}
		foreach ( $zips as $zip ) {
			$zip->close();
		}
		if ( $c['file'] < count( $m['files'] ) ) {
			return array(
				'cursor_data' => $c,
				'bytes_done'  => $bytes,
			);
		}
		return array(
			'phase'          => 'load_db',
			'bytes_done'     => $bytes,
			'progress_done'  => 0,
			'progress_total' => max( 1, array_sum( array_map( static fn( $t ) => max( 1, count( $t['chunks'] ) ), $m['tables'] ) ) ),
			'cursor_data'    => array(
				't'       => 0,
				'c'       => 0,
				'created' => array(),
				'done'    => 0,
			),
		);
	}

	private static function load_db( array $job, string $dir ) {
		$v = self::validated( $dir );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$m           = $v['manifest'];
		$opts        = $job['payload']['options'];
		$archive_dir = self::archive_dir( $job );
		$c           = $job['cursor_data'];
		$tf          = self::transformer( $job, $m );
		$zips        = array();
		$deadline    = microtime( true ) + self::budget();
		$units       = 0;
		$load        = ! empty( $opts['database'] ) && ! empty( $m['scope']['database'] );
		while ( $load && $c['t'] < count( $m['tables'] ) && self::within( $deadline, $units ) ) {
			$t = $m['tables'][ $c['t'] ];
			if ( in_array( $t['name'], Db::RUNTIME_TABLES, true ) || 'schema-only' === $t['rowsMode'] ) {
				++$c['t'];
				$c['c'] = 0;
				continue;
			}
			$staged = self::staged_name( $job['uuid'], $t['name'] );
			if ( ! in_array( $t['name'], $c['created'], true ) ) {
				$drop = Db::drop( $staged );
				if ( is_wp_error( $drop ) ) {
					return $drop;
				}
				$made = Db::exec( Db::create_sql( $staged, $t ) );
				if ( is_wp_error( $made ) ) {
					return new \WP_Error( 'cradle_migration_create', 'Could not create staging table for ' . $t['name'] . ': ' . $made->get_error_message() );
				}
				$c['created'][] = $t['name'];
				$c['c']         = 0;
				continue; // Creating a table is its own unit; rows load in the next unit.
			}
			if ( $c['c'] >= count( $t['chunks'] ) ) {
				++$c['t'];
				$c['c'] = 0;
				continue;
			}
			$chunk = $t['chunks'][ $c['c'] ];
			do_action( 'cradle_migration/before_load_chunk', $t['name'], (int) $chunk['index'], $job );
			$keyed = (bool) $t['primaryKey'];
			foreach ( $t['indexes'] as $ix ) {
				$keyed = $keyed || $ix['unique'];
			}
			if ( ! $keyed ) {
				// Without a key a retried chunk could duplicate rows: verify the row count first.
				$before = 0;
				for ( $i = 0; $i < $c['c']; $i++ ) {
					$before += $t['chunks'][ $i ]['rows'];
				}
				if ( Db::count( $staged ) !== $before ) {
					Db::exec( 'TRUNCATE TABLE ' . Db::ident( $staged ) );
					$c['c'] = 0;
					continue;
				}
			}
			$data = self::read_chunk( $archive_dir, $m, $chunk, $zips );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$rows = array();
			foreach ( explode( "\n", rtrim( $data, "\n" ) ) as $line ) {
				$row = Transformer::decode_line( $line, $t['columns'] );
				if ( null === $row ) {
					return new \WP_Error( 'cradle_migration_row', 'Malformed row in ' . $chunk['entry'] . '.', 'fatal' );
				}
				$out    = $tf->row( $t['name'], $t['columns'], $row );
				$rows[] = $out['row'];
			}
			unset( $data );
			$ok = Db::insert_rows( $staged, $t, $rows, $keyed );
			if ( is_wp_error( $ok ) ) {
				return new \WP_Error( 'cradle_migration_load', 'Loading ' . $t['name'] . ' chunk ' . $chunk['index'] . ' failed: ' . $ok->get_error_message() );
			}
			++$c['c'];
			++$c['done'];
		}
		foreach ( $zips as $zip ) {
			$zip->close();
		}
		if ( $load && $c['t'] < count( $m['tables'] ) ) {
			return array(
				'cursor_data'   => $c,
				'progress_done' => (int) $c['done'],
			);
		}
		return array(
			'phase'         => 'validate_staged',
			'progress_done' => (int) $job['progress_total'],
			'cursor_data'   => array(),
		);
	}

	private static function validate_staged( array $job, string $dir ) {
		$v = self::validated( $dir );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$m    = $v['manifest'];
		$opts = $job['payload']['options'];
		$dest = $job['payload']['destination'];
		if ( empty( $opts['database'] ) || empty( $m['scope']['database'] ) ) {
			return array( 'phase' => 'safety' );
		}
		$problems = array();
		foreach ( $m['tables'] as $t ) {
			if ( in_array( $t['name'], Db::RUNTIME_TABLES, true ) || 'schema-only' === $t['rowsMode'] ) {
				continue;
			}
			$staged = self::staged_name( $job['uuid'], $t['name'] );
			$count  = Db::table_exists( $staged ) ? Db::count( $staged ) : -1;
			if ( $count !== (int) $t['rows'] ) {
				$problems[] = sprintf( 'Staged table %1$s has %2$d rows; the archive has %3$d.', $t['name'], $count, $t['rows'] );
			}
		}
		$options = self::staged_name( $job['uuid'], 'options' );
		$usermeta = self::staged_name( $job['uuid'], 'usermeta' );
		foreach ( array( 'siteurl', 'home', $dest['prefix'] . 'user_roles' ) as $name ) {
			if ( null === self::staged_option( $options, $name ) ) {
				$problems[] = sprintf( 'Staged options lack %s (URL or table-prefix transformation failed).', $name );
			}
		}
		$admins = Db::rows( 'SELECT COUNT(*) AS c FROM ' . Db::ident( $usermeta ) . ' WHERE meta_key = ' . Db::quote( $dest['prefix'] . 'capabilities' ) . ' AND meta_value LIKE ' . Db::quote( '%"administrator"%' ) );
		if ( is_wp_error( $admins ) || (int) ( $admins[0]['c'] ?? 0 ) < 1 ) {
			$problems[] = 'No administrator remains in the staged user data after the prefix change.';
		}
		$plugins = self::staged_option( $options, 'active_plugins' );
		if ( null !== $plugins && null === Serialized::parse( $plugins ) ) {
			$problems[] = 'The staged active_plugins option is not valid serialized data.';
		}
		if ( $problems ) {
			self::discard_staging( $job['uuid'], $m );
			return array(
				'state'         => 'failed',
				'phase'         => 'staging_failed',
				'error_code'    => 'staging_invalid',
				'error_message' => implode( ' ', array_slice( $problems, 0, 5 ) ) . ' Nothing on the live site was changed.',
			);
		}
		return array( 'phase' => 'safety' );
	}

	public static function staged_option( string $table, string $name ) {
		$rows = Db::rows( 'SELECT option_value FROM ' . Db::ident( $table ) . ' WHERE option_name = ' . Db::quote( $name ) . ' LIMIT 1' );
		return ( is_wp_error( $rows ) || ! $rows ) ? null : (string) $rows[0]['option_value'];
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function set_staged_option( string $table, string $name, ?string $value, string $autoload = 'on' ) {
		if ( null === $value ) {
			$r = Db::exec( 'DELETE FROM ' . Db::ident( $table ) . ' WHERE option_name = ' . Db::quote( $name ) );
			return is_wp_error( $r ) ? $r : true;
		}
		if ( null !== self::staged_option( $table, $name ) ) {
			$r = Db::exec( 'UPDATE ' . Db::ident( $table ) . ' SET option_value = ' . Db::quote( $value ) . ' WHERE option_name = ' . Db::quote( $name ) );
		} else {
			$r = Db::exec( 'INSERT INTO ' . Db::ident( $table ) . ' (option_name, option_value, autoload) VALUES (' . Db::quote( $name ) . ', ' . Db::quote( $value ) . ', ' . Db::quote( $autoload ) . ')' );
		}
		return is_wp_error( $r ) ? $r : true;
	}

	/**
	 * Destination safety profile applied to the staged tables before cutover.
	 */
	private static function safety( array $job, string $dir ) {
		$v = self::validated( $dir );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$m       = $v['manifest'];
		$opts    = $job['payload']['options'];
		$dest    = $job['payload']['destination'];
		$profile = array(
			'importedActivePlugins' => array(),
			'sessionsCleared'       => 0,
			'appPasswordsCleared'   => 0,
			'loginRoute'            => 'not present',
			'admins'                => array(),
			'database'              => false,
		);
		if ( ! empty( $opts['database'] ) && ! empty( $m['scope']['database'] ) ) {
			global $wpdb;
			$profile['database'] = true;
			$options             = self::staged_name( $job['uuid'], 'options' );
			$usermeta            = self::staged_name( $job['uuid'], 'usermeta' );
			$users               = self::staged_name( $job['uuid'], 'users' );
			$sets                = array(
				'siteurl' => $dest['siteurl'],
				'home'    => $dest['home'],
			);
			foreach ( self::PRESERVED_OPTS as $name ) {
				$live          = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$sets[ $name ] = null === $live ? null : (string) $live;
			}
			$imported                         = self::staged_option( $options, 'active_plugins' );
			$profile['importedActivePlugins'] = null === $imported ? array() : self::serialized_strings( $imported );
			$sets['active_plugins']           = serialize( self::engine_basenames() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- own trusted value.
			$login                            = self::staged_option( $options, 'cradle_login_settings' );
			if ( null !== $login ) {
				$node = Serialized::parse( $login );
				$done = false;
				if ( is_array( $node ) && 'a' === $node['t'] ) {
					foreach ( $node['items'] as $i => $pair ) {
						if ( 's' === $pair[0]['t'] && 'enabled' === $pair[0]['v'] ) {
							$node['items'][ $i ][1] = array(
								't' => 'raw',
								'v' => 'b:0;',
							);
							$done                   = true;
						}
					}
				}
				if ( $done ) {
					$sets['cradle_login_settings'] = Serialized::write( $node );
					$profile['loginRoute']         = 'disabled';
				} else {
					$profile['loginRoute'] = 'present without an enabled flag; left unchanged';
				}
			}
			$sets['rewrite_rules']      = null;
			$sets[ Guard::QUIESCE_OPTION ] = serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				array(
					'job'   => $job['uuid'],
					'mode'  => 'import',
					'since' => time(),
					'until' => time() + 1800,
				)
			);
			$sets[ Guard::HOLD_OPTION ]    = serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				array(
					'active' => true,
					'job'    => $job['uuid'],
					'since'  => time(),
					'source' => $m['source']['homeUrl'],
				)
			);
			foreach ( $sets as $name => $value ) {
				$ok = self::set_staged_option( $options, $name, $value );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
			}
			$count = Db::rows( 'SELECT meta_key, COUNT(*) AS c FROM ' . Db::ident( $usermeta ) . " WHERE meta_key IN ('session_tokens','_application_passwords') GROUP BY meta_key" );
			foreach ( is_wp_error( $count ) ? array() : $count as $row ) {
				$profile[ 'session_tokens' === $row['meta_key'] ? 'sessionsCleared' : 'appPasswordsCleared' ] = (int) $row['c'];
			}
			$ok = Db::exec( 'DELETE FROM ' . Db::ident( $usermeta ) . " WHERE meta_key IN ('session_tokens','_application_passwords')" );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$admins = Db::rows( 'SELECT u.user_login FROM ' . Db::ident( $users ) . ' u JOIN ' . Db::ident( $usermeta ) . ' m ON m.user_id = u.ID WHERE m.meta_key = ' . Db::quote( $dest['prefix'] . 'capabilities' ) . ' AND m.meta_value LIKE ' . Db::quote( '%"administrator"%' ) . ' ORDER BY u.ID LIMIT 20' );
			$profile['admins'] = is_wp_error( $admins ) ? array() : array_column( $admins, 'user_login' );
		}
		$ok = Workspace::write_json( $dir . '/profile.json', $profile );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return array( 'phase' => 'checkpoint' );
	}

	public static function engine_basenames(): array {
		$out = array( plugin_basename( CRADLE_MIGRATION_FILE ) );
		if ( defined( 'CRADLE_FILE' ) ) {
			array_unshift( $out, plugin_basename( CRADLE_FILE ) );
		}
		return $out;
	}

	/**
	 * Builds and verifies the recovery checkpoint and the file-operation plan.
	 */
	private static function checkpoint( array $job, string $dir ) {
		$v = self::validated( $dir );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$m    = $v['manifest'];
		$dest = $job['payload']['destination'];
		$opts = $job['payload']['options'];
		$tok  = self::token_for( $job['uuid'] );

		// 1. Can this database user create, rename and drop tables? (needed for cutover and rollback)
		$probe_a = 'cmig_' . $tok . '_zprobe';
		$probe_b = 'cold_' . $tok . '_zprobe';
		Db::drop( $probe_a );
		Db::drop( $probe_b );
		$ok = Db::exec( 'CREATE TABLE ' . Db::ident( $probe_a ) . ' (id int NOT NULL) ENGINE=InnoDB' );
		$ok = is_wp_error( $ok ) ? $ok : Db::rename( array( array( $probe_a, $probe_b ) ) );
		$ok = is_wp_error( $ok ) ? $ok : Db::drop( $probe_b );
		if ( is_wp_error( $ok ) ) {
			return new \WP_Error( 'cradle_migration_privileges', 'The database user cannot create/rename/drop tables, so a recoverable cutover is impossible: ' . $ok->get_error_message(), 'fatal' );
		}

		// 2. Table plan.
		$tables = array();
		if ( ! empty( $opts['database'] ) && ! empty( $m['scope']['database'] ) ) {
			foreach ( $m['tables'] as $t ) {
				if ( in_array( $t['name'], Db::RUNTIME_TABLES, true ) || 'schema-only' === $t['rowsMode'] ) {
					continue;
				}
				$live     = $dest['prefix'] . $t['name'];
				$tables[] = array(
					'name'        => $t['name'],
					'live'        => $live,
					'staged'      => self::staged_name( $job['uuid'], $t['name'] ),
					'old'         => self::old_name( $job['uuid'], $t['name'] ),
					'liveExisted' => Db::table_exists( $live ),
				);
			}
		}

		// 3. File plan: move destination copies aside (backup), then move staged copies in.
		$tree   = $dir . '/tree';
		$backup = $dir . '/backup';
		$ops    = array();
		$roots  = array(
			'uploads'      => $dest['uploadsDir'],
			'themes'       => $dest['themesDir'],
			'plugins'      => $dest['pluginsDir'],
			'root'         => untrailingslashit( $dest['abspath'] ),
			// Jobs created before 0.3.0 did not record these two destination folders.
			'languages'    => (string) ( $dest['languagesDir'] ?? untrailingslashit( wp_normalize_path( WP_LANG_DIR ) ) ),
			'translations' => (string) ( $dest['translationsDir'] ?? self::translations_dir() ),
		);
		// An unknown folder is never an allowed root (the containment check below relies on this).
		$roots   = array_filter( $roots, static fn( $r ) => '' !== (string) $r );
		$private = function_exists( 'cradle' ) && cradle() ? cradle()->storage()->private_root() : null;
		foreach ( $roots as $cat => $live_root ) {
			if ( ! is_dir( $tree . '/' . $cat ) ) {
				continue;
			}
			// Language files and Cradle Translations data replace entry by entry (a language file, or a
			// whole plugins/, themes/, catalogs/… folder); their folder may not exist yet on this site.
			if ( in_array( $cat, array( 'languages', 'translations' ), true ) ) {
				$made = '' !== $live_root && ( 'translations' === $cat ? ! is_wp_error( cradle()->storage()->module_dir( 'translations' ) ) : wp_mkdir_p( $live_root ) );
				if ( ! $made || ! is_dir( $live_root ) || is_link( $live_root ) ) {
					return new \WP_Error( 'cradle_migration_write', 'Could not prepare the destination folder for ' . $cat . '.', 'fatal' );
				}
			}
			if ( 'uploads' === $cat ) {
				foreach ( (array) scandir( $live_root ) as $name ) {
					if ( '.' === $name || '..' === $name || 0 === stripos( (string) $name, 'cradle-private-' ) ) {
						continue;
					}
					if ( is_array( $private ) && Storage::path_within( (string) $private['path'], $live_root . '/' . $name ) ) {
						continue;
					}
					$ops[] = array(
						'kind' => 'backup',
						'from' => $live_root . '/' . $name,
						'to'   => $backup . '/uploads/' . $name,
					);
				}
			}
			foreach ( (array) scandir( $tree . '/' . $cat ) as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				if ( 'uploads' !== $cat && file_exists( $live_root . '/' . $name ) ) {
					$ops[] = array(
						'kind' => 'backup',
						'from' => $live_root . '/' . $name,
						'to'   => $backup . '/' . $cat . '/' . $name,
					);
				}
				$ops[] = array(
					'kind' => 'place',
					'from' => $tree . '/' . $cat . '/' . $name,
					'to'   => $live_root . '/' . $name,
				);
			}
		}
		foreach ( $ops as $i => $op ) {
			$ops[ $i ]['i'] = $i;
			foreach ( array( 'from', 'to' ) as $end ) {
				$path   = $op[ $end ];
				$inside = Storage::path_within( $path, $dir );
				foreach ( $roots as $r ) {
					$inside = $inside || Storage::path_within( $path, $r );
				}
				if ( ! $inside || is_link( $path ) ) {
					return new \WP_Error( 'cradle_migration_escape', 'A file operation left the allowed roots.', 'fatal' );
				}
			}
		}
		$same_volume = true;
		foreach ( $roots as $r ) {
			$a = @stat( $r ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$b = @stat( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $a && $b && $a['dev'] !== $b['dev'] ) {
				$same_volume = false;
			}
		}
		if ( ! $same_volume ) {
			$need = 0;
			foreach ( $ops as $op ) {
				$need += is_dir( $op['from'] ) ? Workspace::dir_size( $op['from'] ) : ( is_file( $op['from'] ) ? (int) filesize( $op['from'] ) : 0 );
			}
			$free = Workspace::free_space( $dir );
			if ( null !== $free && $need > $free * 0.9 ) {
				return new \WP_Error( 'cradle_migration_space', 'Not enough free space to keep backups of the replaced files.', 'fatal' );
			}
		}
		$ok = Workspace::write_json(
			$dir . '/file-plan.json',
			array(
				'ops'        => $ops,
				'sameVolume' => $same_volume,
			)
		);
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		// 4. Recovery context, independent of the tables being replaced.
		$journal = new Journal( $dir );
		$ok      = $journal->write_recovery(
			array(
				'job'         => $job['uuid'],
				'created'     => time(),
				'expires'     => 0,
				'tokenHash'   => '',
				'destination' => $dest,
				'tables'      => $tables,
				'token'       => $tok,
				'jobDir'      => $dir,
				'treeDir'     => $tree,
				'backupDir'   => $backup,
				'engine'      => self::engine_basenames(),
				'cli'         => sprintf( 'php %s --path=%s recover --job=%s', wp_normalize_path( CRADLE_MIGRATION_DIR . 'bin/cradle-migrate.php' ), untrailingslashit( $dest['abspath'] ), $job['uuid'] ),
			)
		);
		if ( is_wp_error( $ok ) ) {
			return new \WP_Error( $ok->get_error_code(), $ok->get_error_message(), 'fatal' );
		}
		$journal->append(
			'checkpoint',
			array(
				'tables' => count( $tables ),
				'ops'    => count( $ops ),
			)
		);
		return array(
			'state' => 'awaiting_cutover',
			'phase' => 'cutover_ready',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Critical phase                                                     */
	/* ------------------------------------------------------------------ */

	private static function cutover_db( array $job, string $dir ) {
		$journal  = new Journal( $dir );
		$recovery = $journal->recovery();
		if ( ! is_array( $recovery ) ) {
			return new \WP_Error( 'cradle_migration_recovery', 'The recovery context is missing.', 'fatal' );
		}
		if ( ! $journal->has( 'db_cutover_done' ) ) {
			$tables = $recovery['tables'];
			if ( ! $journal->has( 'db_cutover_begin' ) ) {
				Guard::start_quiesce( $job['uuid'], 'import', 1800 );
				$ok = $journal->append( 'db_cutover_begin', array( 'tables' => $tables ) );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
			}
			$pairs = Recovery::forward_pairs( $tables );
			if ( is_wp_error( $pairs ) ) {
				return new \WP_Error( $pairs->get_error_code(), $pairs->get_error_message(), 'fatal' );
			}
			$ok = Db::rename( $pairs );
			if ( is_wp_error( $ok ) ) {
				return new \WP_Error( 'cradle_migration_cutover', 'Database cutover failed; run the recovery command. ' . $ok->get_error_message(), 'fatal' );
			}
			$journal->append( 'db_cutover_done', array( 'renamed' => count( $pairs ) ) );
		}
		wp_cache_flush();
		do_action( 'cradle_migration/after_phase', 'cutover_db', $job );
		return array(
			'phase'       => 'replace_files',
			'cursor_data' => array( 'op' => 0 ),
		);
	}

	private static function replace_files( array $job, string $dir ) {
		$plan = Workspace::read_json( $dir . '/file-plan.json', 536870912 );
		if ( is_wp_error( $plan ) ) {
			return new \WP_Error( $plan->get_error_code(), $plan->get_error_message(), 'fatal' );
		}
		$journal  = new Journal( $dir );
		$done     = $journal->done_file_ops();
		$deadline = microtime( true ) + self::budget();
		$units       = 0;
		foreach ( $plan['ops'] as $op ) {
			if ( isset( $done[ $op['i'] ] ) ) {
				continue;
			}
			if ( ! self::within( $deadline, $units ) ) {
				return array( 'cursor_data' => array( 'op' => (int) $op['i'] ) );
			}
			$journal->append(
				'file_op_begin',
				array(
					'i'    => $op['i'],
					'kind' => $op['kind'],
				)
			);
			$ok = Recovery::move( $op['from'], $op['to'], $dir . '/displaced/' . $op['i'] );
			if ( is_wp_error( $ok ) ) {
				return new \WP_Error( $ok->get_error_code(), $ok->get_error_message() . ' Run the recovery command to finish or roll back.', 'fatal' );
			}
			$journal->append( 'file_op_done', array( 'i' => $op['i'] ) );
			do_action( 'cradle_migration/after_file_op', (int) $op['i'], $job );
		}
		if ( ! $journal->has( 'files_done' ) ) {
			$journal->append( 'files_done' );
		}
		return array(
			'phase'       => 'finalize',
			'cursor_data' => array(),
		);
	}

	private static function finalize( array $job, string $dir ) {
		$journal = new Journal( $dir );
		$profile = Workspace::read_json( $dir . '/profile.json' );
		$profile = is_wp_error( $profile ) ? array() : $profile;
		wp_cache_flush();
		if ( ! empty( $profile['database'] ) ) {
			$active = array();
			foreach ( (array) ( $profile['importedActivePlugins'] ?? array() ) as $basename ) {
				if ( is_string( $basename ) && 0 === validate_file( $basename ) && str_ends_with( $basename, '.php' ) && is_file( WP_PLUGIN_DIR . '/' . $basename ) ) {
					$active[] = $basename;
				}
			}
			foreach ( self::engine_basenames() as $basename ) {
				if ( ! in_array( $basename, $active, true ) ) {
					$active[] = $basename;
				}
			}
			update_option( 'active_plugins', array_values( array_unique( $active ) ) );
			if ( ! Guard::hold() ) {
				Guard::set_hold(
					array(
						'job'   => $job['uuid'],
						'since' => time(),
					)
				);
			}
		}
		delete_option( 'rewrite_rules' );
		update_option(
			'cradle_migration_last_restore',
			array(
				'job'      => $job['uuid'],
				'time'     => time(),
				'admins'   => (array) ( $profile['admins'] ?? array() ),
				'sessions' => (int) ( $profile['sessionsCleared'] ?? 0 ),
			),
			false
		);
		Guard::end_quiesce( $job['uuid'] );
		wp_cache_flush();
		if ( ! $journal->has( 'finalized' ) ) {
			$journal->append( 'finalized' );
		}
		return array( 'phase' => 'verify' );
	}

	private static function verify( array $job, string $dir ) {
		$v      = self::validated( $dir );
		$m      = is_wp_error( $v ) ? array() : $v['manifest'];
		$result = Verifier::run( $job, $m, $dir );
		Workspace::write_json( $dir . '/verify.json', $result );
		$journal = new Journal( $dir );
		$journal->append(
			'verified',
			array(
				'passed' => $result['passed'],
			)
		);
		$recovery = $journal->recovery();
		if ( is_array( $recovery ) ) {
			// The browser continuation token is no longer needed once verification ran.
			$recovery['tokenHash'] = '';
			$recovery['expires']   = 0;
			$journal->write_recovery( $recovery );
		}
		cradle_log_activity( 'migration', $result['passed'] ? 'import_completed' : 'import_verify_failed', $result['passed'] ? 'Site restore completed and verified.' : 'Site restore finished but verification failed; backups are kept for rollback.', array( 'job' => $job['uuid'] ) );
		if ( ! $result['passed'] ) {
			return array(
				'state'         => 'failed',
				'phase'         => 'verify_failed',
				'error_code'    => 'verify_failed',
				'error_message' => 'Post-restore verification failed: ' . implode( ' | ', array_slice( $result['failures'], 0, 5 ) ) . ' Backups are kept; roll back from Cradle → Migration or the CLI.',
			);
		}
		return array(
			'state'         => 'completed',
			'phase'         => 'done',
			'progress_done' => (int) $job['progress_total'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* Cleanup                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Drops staged tables of a job that never reached cutover.
	 */
	public static function discard_staging( string $uuid, ?array $m = null ) {
		foreach ( Db::tables_with_prefix( 'cmig_' . self::token_for( $uuid ) . '_' ) as $table ) {
			Db::drop( $table );
		}
		$dir = Workspace::job_dir( $uuid );
		if ( ! is_wp_error( $dir ) && is_dir( $dir . '/tree' ) ) {
			Workspace::remove_tree( $dir . '/tree' );
		}
	}

	/**
	 * Removes staging of terminal import jobs that never reached cutover. Never touches backups.
	 */
	public static function sweep(): int {
		if ( ! function_exists( 'cradle' ) || ! cradle() ) {
			return 0;
		}
		$count = 0;
		foreach ( cradle()->jobs()->recent( 'migration', 50 ) as $job ) {
			if ( self::TYPE !== $job['type'] || ! in_array( $job['state'], Jobs::TERMINAL_STATES, true ) ) {
				continue;
			}
			$dir = Workspace::job_dir( $job['uuid'] );
			if ( is_wp_error( $dir ) ) {
				continue;
			}
			// The uploaded/copied archive is no longer needed once the job ended (backups live in the job dir).
			$location = (string) ( $job['payload']['location'] ?? '' );
			if ( 0 === strpos( $location, 'incoming/' ) ) {
				$incoming = Workspace::resolve_rel( $location );
				if ( ! is_wp_error( $incoming ) && is_dir( $incoming ) ) {
					Workspace::remove_tree( $incoming );
				}
			}
			$journal = new Journal( $dir );
			if ( in_array( $job['state'], array( 'failed', 'cancelled' ), true ) && 'not_started' === $journal->stage() && ( is_dir( $dir . '/tree' ) || Db::tables_with_prefix( 'cmig_' . self::token_for( $job['uuid'] ) . '_' ) ) ) {
				self::discard_staging( $job['uuid'] );
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Deletes a completed restore's backups (old tables and moved-aside files) — explicit operator action.
	 *
	 * @return array|\WP_Error Summary.
	 */
	public static function purge_backups( string $uuid ) {
		$job = Job_Store::get( $uuid );
		if ( ! $job || self::TYPE !== $job['type'] || ! in_array( $job['state'], array( 'completed', 'rolled_back' ), true ) ) {
			return new \WP_Error( 'cradle_migration_state', 'Backups can only be purged after a verified restore or a completed rollback.' );
		}
		$dropped = 0;
		foreach ( array( 'cold_', 'cmig_' ) as $kind ) {
			foreach ( Db::tables_with_prefix( $kind . self::token_for( $uuid ) . '_' ) as $table ) {
				Db::drop( $table );
				++$dropped;
			}
		}
		$dir = Workspace::job_dir( $uuid );
		if ( ! is_wp_error( $dir ) ) {
			foreach ( array( 'backup', 'tree', 'displaced', 'rolledback' ) as $sub ) {
				if ( is_dir( $dir . '/' . $sub ) ) {
					Workspace::remove_tree( $dir . '/' . $sub );
				}
			}
			$journal = new Journal( $dir );
			$journal->append( 'backups_purged', array( 'tables' => $dropped ) );
		}
		cradle_log_activity( 'migration', 'backups_purged', 'Restore backups purged.', array( 'job' => $uuid ) );
		return array( 'tablesDropped' => $dropped );
	}
}
