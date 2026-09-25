<?php
/**
 * Export job (`migration/export`): bounded, idempotent steps that produce a Cradle archive.
 *
 * Phases: prepare → scan_files → dump_db → pack → finalize.
 * Working files live in jobs/<uuid>/; every JSON-lines working file is truncated to the
 * committed length at the start of a step, so a retried or taken-over step never
 * duplicates records. ZIP parts are written with ZipArchive (libzip writes a temporary
 * file and renames it on close, so a failed close keeps the previous part intact).
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

use Cradle\Core\Jobs;

defined( 'ABSPATH' ) || exit;

final class Exporter {

	const TYPE          = 'migration/export';
	const CHUNK_ROWS    = 2000;
	const CHUNK_BYTES   = 4194304;
	const READ_BATCH    = 500;
	const SCAN_BATCH    = 4000;
	const PACK_BYTES    = 67108864;
	const STEP_SECONDS  = 6.0;
	const STORE_EXT     = '/\.(jpe?g|png|gif|webp|avif|heic|mp4|m4v|mov|webm|mp3|m4a|ogg|oga|zip|gz|tgz|bz2|xz|7z|rar|woff2?|pdf)$/i';
	const GLOBAL_SKIP   = '/^(\.git|\.svn|\.hg|\.bzr|node_modules|\.ds_store|thumbs\.db|desktop\.ini|\.idea|\.vscode|error_log|\.env(\..*)?|.*\.log|.*\.swp|.*\.tmp|.*~)$/i';
	const UPLOADS_TOP   = '/^(cradle-private-.*|cache|wc-logs|backwpup-.*|backupbuddy_backups|updraft|wp-migrate-db|wpvivid.*|ai1wm-backups|wp-staging.*|cradle-migration-.*)$/i';
	const SERVER_FILES  = '/^(\.htaccess|web\.config|\.user\.ini|php\.ini|\.htpasswd)$/i';

	public static function default_options(): array {
		return array(
			'database'  => true,
			'uploads'   => true,
			'themes'    => 'active',
			'plugins'   => 'active',
			'rootFiles' => array(),
			'quiesce'   => false,
			'partSize'  => 67108864,
			'label'     => '',
		);
	}

	/**
	 * @return array|\WP_Error
	 */
	public static function normalize_options( array $in ) {
		$o                = self::default_options();
		// Missing keys keep their defaults; present keys are read as booleans.
		$o['database']    = array_key_exists( 'database', $in ) ? ! empty( $in['database'] ) : $o['database'];
		$o['uploads']     = array_key_exists( 'uploads', $in ) ? ! empty( $in['uploads'] ) : $o['uploads'];
		$o['themes']      = in_array( $in['themes'] ?? 'active', array( 'active', 'all', 'none' ), true ) ? $in['themes'] ?? 'active' : 'active';
		$o['plugins']     = in_array( $in['plugins'] ?? 'active', array( 'active', 'all', 'none' ), true ) ? $in['plugins'] ?? 'active' : 'active';
		$o['quiesce']     = ! empty( $in['quiesce'] );
		$o['partSize']    = max( 262144, min( 2147483648, (int) ( $in['partSize'] ?? $o['partSize'] ) ) );
		$o['label']       = mb_substr( sanitize_text_field( (string) ( $in['label'] ?? '' ) ), 0, 100 );
		$o['rootFiles']   = array();
		foreach ( (array) ( $in['rootFiles'] ?? array() ) as $name ) {
			$name = (string) $name;
			if ( '' === $name ) {
				continue;
			}
			if ( ! preg_match( Manifest::ROOT_FILE, $name ) || ! is_file( ABSPATH . $name ) || is_link( ABSPATH . $name ) ) {
				return new \WP_Error( 'cradle_migration_root_file', sprintf( 'Root file "%s" is not allowed or does not exist.', Path_Guard::printable( $name ) ) );
			}
			$o['rootFiles'][] = $name;
		}
		if ( ! $o['database'] && ! $o['uploads'] && 'none' === $o['themes'] && 'none' === $o['plugins'] && ! $o['rootFiles'] ) {
			return new \WP_Error( 'cradle_migration_empty', 'Select at least one thing to export.' );
		}
		return $o;
	}

	/**
	 * Creates an export job for the current user (capability checked by core unless $system).
	 *
	 * @return array|\WP_Error
	 */
	public static function create( array $options, bool $system = false ) {
		if ( is_multisite() ) {
			return new \WP_Error( 'cradle_migration_multisite', 'Multisite export is not supported.' );
		}
		if ( ! $system && ! current_user_can( 'cradle_export_site' ) ) {
			return new \WP_Error( 'cradle_migration_forbidden', __( 'You are not allowed to export this site.', 'cradle-migration' ) );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'cradle_migration_zip', 'The PHP zip extension (ZipArchive) is required.' );
		}
		$options = self::normalize_options( $options );
		if ( is_wp_error( $options ) ) {
			return $options;
		}
		$protected = Workspace::ensure_protected();
		if ( is_wp_error( $protected ) ) {
			return $protected;
		}
		$locks = cradle()->locks();
		$lock  = $locks->acquire( 'migration:create', 30 );
		if ( ! $lock ) {
			return new \WP_Error( 'cradle_migration_busy', 'Another migration request is being created. Try again.' );
		}
		$active = Job_Store::active();
		if ( $active ) {
			$locks->release( 'migration:create', $lock );
			return new \WP_Error( 'cradle_migration_conflict', sprintf( 'Another migration job (%1$s, %2$s) is not finished. Finish or cancel it first.', $active[0]['type'], $active[0]['state'] ) );
		}
		$archive_id = 'cradle-' . gmdate( 'Ymd\THis\Z' ) . '-' . strtolower( wp_generate_password( 8, false, false ) );
		$job        = cradle()->jobs()->create(
			self::TYPE,
			array(
				'options'   => $options,
				'archiveId' => $archive_id,
			),
			array(
				'phase'  => 'prepare',
				'system' => $system,
			)
		);
		$locks->release( 'migration:create', $lock );
		if ( ! is_wp_error( $job ) ) {
			cradle_log_activity( 'migration', 'export_started', 'Site export started.', array( 'job' => $job['uuid'], 'quiesce' => $options['quiesce'] ) );
		}
		return $job;
	}

	/**
	 * Job step dispatcher.
	 *
	 * @return array|\WP_Error
	 */
	public static function step( array $job, Jobs $jobs ) {
		$dir = Workspace::job_dir( $job['uuid'] );
		if ( is_wp_error( $dir ) ) {
			return new \WP_Error( $dir->get_error_code(), $dir->get_error_message(), 'fatal' );
		}
		$opts = $job['payload']['options'] ?? self::default_options();
		if ( ! empty( $opts['quiesce'] ) && 'prepare' !== $job['phase'] ) {
			Guard::start_quiesce( $job['uuid'], 'export', 900 );
		}
		switch ( $job['phase'] ) {
			case 'prepare':
				return self::prepare( $job, $dir, $opts );
			case 'scan_files':
				return self::scan_files( $job, $dir );
			case 'dump_db':
				return self::dump_db( $job, $dir );
			case 'pack':
				return self::pack( $job, $dir, $opts );
			case 'finalize':
				return self::finalize( $job, $dir, $opts );
		}
		return new \WP_Error( 'cradle_migration_phase', 'Unknown export phase.', 'fatal' );
	}

	/* ------------------------------ prepare ------------------------------- */

	public static function source_info(): array {
		global $wpdb, $wp_version;
		$uploads = wp_upload_dir( null, false );
		return array(
			'siteUrl'       => (string) get_option( 'siteurl' ),
			'homeUrl'       => (string) get_option( 'home' ),
			'abspath'       => wp_normalize_path( ABSPATH ),
			'contentDir'    => untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ),
			'uploadsDir'    => untrailingslashit( wp_normalize_path( $uploads['basedir'] ) ),
			'uploadsUrl'    => (string) $uploads['baseurl'],
			'pluginsDir'    => untrailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ),
			'themesDir'     => untrailingslashit( wp_normalize_path( get_theme_root() ) ),
			'tablePrefix'   => $wpdb->prefix,
			'multisite'     => is_multisite(),
			'wordpress'     => (string) $wp_version,
			'dbVersion'     => (int) get_option( 'db_version' ),
			'php'           => PHP_VERSION,
			'dbServer'      => (string) $wpdb->db_server_info(),
			'dbCharset'     => (string) $wpdb->charset,
			'locale'        => get_locale(),
			'template'      => get_template(),
			'stylesheet'    => get_stylesheet(),
			'activePlugins' => array_values( array_filter( (array) get_option( 'active_plugins', array() ), 'is_string' ) ),
		);
	}

	/**
	 * Package inventory with the export selection applied.
	 */
	public static function packages( array $opts ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active_themes = array_unique( array( get_stylesheet(), get_template() ) );
		$themes        = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$is_active = in_array( $slug, $active_themes, true );
			$themes[]  = array(
				'slug'     => (string) $slug,
				'name'     => (string) $theme->get( 'Name' ),
				'version'  => (string) $theme->get( 'Version' ),
				'parent'   => $theme->parent() ? (string) $theme->get_template() : null,
				'active'   => $is_active,
				'included' => 'all' === $opts['themes'] || ( 'active' === $opts['themes'] && $is_active ),
				'dir'      => wp_normalize_path( $theme->get_stylesheet_directory() ),
			);
		}
		$active  = (array) get_option( 'active_plugins', array() );
		$plugins = array();
		foreach ( get_plugins() as $basename => $data ) {
			$is_active = in_array( $basename, $active, true );
			$slug      = false === strpos( $basename, '/' ) ? $basename : dirname( $basename );
			$plugins[] = array(
				'basename' => (string) $basename,
				'slug'     => (string) $slug,
				'name'     => (string) $data['Name'],
				'version'  => (string) $data['Version'],
				'active'   => $is_active,
				'included' => 'all' === $opts['plugins'] || ( 'active' === $opts['plugins'] && $is_active ),
			);
		}
		return array(
			'themes'     => $themes,
			'plugins'    => $plugins,
			'muPlugins'  => array_values( array_map( 'strval', array_keys( get_mu_plugins() ) ) ),
			'dropins'    => array_values( array_map( 'strval', array_keys( get_dropins() ) ) ),
			'core'       => array(
				'version' => (string) $GLOBALS['wp_version'],
				'restore' => sprintf( 'Install a trusted WordPress %s package from wordpress.org; Cradle does not restore core files from archives.', $GLOBALS['wp_version'] ),
			),
		);
	}

	private static function prepare( array $job, string $dir, array $opts ) {
		$protected = Workspace::ensure_protected();
		if ( is_wp_error( $protected ) ) {
			return new \WP_Error( $protected->get_error_code(), $protected->get_error_message(), 'fatal' );
		}
		global $wpdb;
		if ( $opts['quiesce'] ) {
			Guard::start_quiesce( $job['uuid'], 'export', 900 );
		}
		$packages    = self::packages( $opts );
		$tables      = array();
		$unsupported = array();
		if ( $opts['database'] ) {
			$found       = Db::discover( $wpdb->prefix );
			$unsupported = $found['unsupported'];
			foreach ( $found['tables'] as $t ) {
				$meta = Db::describe( $t['physical'], $t['logical'] );
				if ( $meta['problems'] ) {
					$unsupported[] = array(
						'kind'   => 'table',
						'name'   => $t['physical'],
						'detail' => 'Not exported: ' . implode( ' ', array_unique( $meta['problems'] ) ),
					);
					continue;
				}
				unset( $meta['problems'] );
				$meta['physical'] = $t['physical'];
				$meta['rowsMode'] = in_array( $t['logical'], Db::RUNTIME_TABLES, true ) ? 'schema-only' : 'full';
				$tables[]         = $meta;
			}
		}
		$roots = array();
		if ( $opts['uploads'] ) {
			$uploads = wp_upload_dir( null, false );
			$roots[] = array(
				'category' => 'uploads',
				'dir'      => untrailingslashit( wp_normalize_path( $uploads['basedir'] ) ),
				'prefix'   => '',
			);
		}
		$themes_root = untrailingslashit( wp_normalize_path( get_theme_root() ) );
		foreach ( $packages['themes'] as $theme ) {
			if ( $theme['included'] && is_dir( $theme['dir'] ) && ! is_link( $theme['dir'] ) ) {
				$roots[] = array(
					'category' => 'themes',
					'dir'      => $theme['dir'],
					'prefix'   => basename( $theme['dir'] ),
				);
			}
		}
		$plugins_root = untrailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		foreach ( $packages['plugins'] as $plugin ) {
			if ( ! $plugin['included'] ) {
				continue;
			}
			$path = $plugins_root . '/' . $plugin['slug'];
			if ( is_link( $path ) ) {
				continue;
			}
			if ( is_dir( $path ) ) {
				$roots[] = array(
					'category' => 'plugins',
					'dir'      => $path,
					'prefix'   => $plugin['slug'],
				);
			} elseif ( is_file( $path ) ) {
				$roots[] = array(
					'category' => 'plugins',
					'file'     => $path,
					'prefix'   => $plugin['slug'],
				);
			}
		}
		foreach ( $opts['rootFiles'] as $name ) {
			$roots[] = array(
				'category' => 'root',
				'file'     => wp_normalize_path( ABSPATH . $name ),
				'prefix'   => $name,
			);
		}
		foreach ( $packages['themes'] as $i => $theme ) {
			unset( $packages['themes'][ $i ]['dir'] );
		}
		$plan = array(
			'source'      => self::source_info(),
			'packages'    => $packages,
			'tables'      => $tables,
			'unsupported' => $unsupported,
			'roots'       => $roots,
			'startedAt'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'themesRoot'  => $themes_root,
		);
		foreach ( array( 'files.jsonl', 'exclusions.jsonl', 'chunks.jsonl', 'entries.jsonl', 'files-meta.jsonl', 'warnings.jsonl' ) as $f ) {
			if ( is_file( $dir . '/' . $f ) ) {
				wp_delete_file( $dir . '/' . $f );
			}
		}
		$ok = Workspace::write_json( $dir . '/plan.json', $plan );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return array(
			'state'          => 'running',
			'phase'          => 'scan_files',
			'progress_done'  => 0,
			'progress_total' => count( $tables ) + count( $roots ),
			'cursor_data'    => array(
				'root'     => 0,
				'stack'    => array( '' ),
				'filesLen' => 0,
				'exclLen'  => 0,
				'files'    => 0,
				'bytes'    => 0,
			),
		);
	}

	/* ----------------------------- scan files ----------------------------- */

	private static function scan_files( array $job, string $dir ) {
		$plan = Workspace::read_json( $dir . '/plan.json' );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$c        = $job['cursor_data'];
		$roots    = $plan['roots'];
		$files    = array();
		$excluded = array();
		$budget   = self::SCAN_BATCH;
		$deadline = microtime( true ) + self::STEP_SECONDS;
		while ( $budget > 0 && $c['root'] < count( $roots ) && microtime( true ) < $deadline ) {
			$root = $roots[ $c['root'] ];
			if ( isset( $root['file'] ) ) {
				$abs = $root['file'];
				if ( is_file( $abs ) && ! is_link( $abs ) && is_readable( $abs ) ) {
					$files[] = array(
						'c' => $root['category'],
						'p' => $root['prefix'],
						's' => (int) filesize( $abs ),
						'r' => $c['root'],
						'x' => '',
					);
				} else {
					$excluded[] = array(
						'category' => $root['category'],
						'path'     => $root['prefix'],
						'reason'   => 'missing, unreadable or a symbolic link',
					);
				}
				++$c['root'];
				$c['stack'] = array( '' );
				--$budget;
				continue;
			}
			if ( ! $c['stack'] ) {
				++$c['root'];
				$c['stack'] = array( '' );
				continue;
			}
			$rel   = (string) array_pop( $c['stack'] );
			$abs   = $root['dir'] . ( '' === $rel ? '' : '/' . $rel );
			$names = is_dir( $abs ) && ! is_link( $abs ) ? @scandir( $abs ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $names ) {
				$excluded[] = array(
					'category' => $root['category'],
					'path'     => self::join( $root['prefix'], $rel ),
					'reason'   => 'directory could not be read',
				);
				continue;
			}
			$subdirs = array();
			foreach ( $names as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				--$budget;
				$child_rel = '' === $rel ? $name : $rel . '/' . $name;
				$child     = $abs . '/' . $name;
				$logical   = self::join( $root['prefix'], $child_rel );
				$reason    = self::exclusion_reason( $root['category'], $rel, $name, $child );
				if ( null !== $reason ) {
					$excluded[] = array(
						'category' => $root['category'],
						'path'     => $logical,
						'reason'   => $reason,
					);
					continue;
				}
				if ( is_dir( $child ) ) {
					$subdirs[] = $child_rel;
					continue;
				}
				$valid = Path_Guard::validate_relative( $logical );
				if ( is_wp_error( $valid ) ) {
					$excluded[] = array(
						'category' => $root['category'],
						'path'     => Path_Guard::printable( $logical ),
						'reason'   => 'file name is not portable: ' . $valid->get_error_message(),
					);
					continue;
				}
				$files[] = array(
					'c' => $root['category'],
					'p' => $logical,
					's' => (int) filesize( $child ),
					'r' => $c['root'],
					'x' => $child_rel,
				);
			}
			// Depth-first, deterministic order.
			rsort( $subdirs );
			foreach ( $subdirs as $sd ) {
				$c['stack'][] = $sd;
			}
		}
		$len = Workspace::append_lines( $dir . '/files.jsonl', $files, (int) $c['filesLen'] );
		if ( is_wp_error( $len ) ) {
			return $len;
		}
		$elen = Workspace::append_lines( $dir . '/exclusions.jsonl', $excluded, (int) $c['exclLen'] );
		if ( is_wp_error( $elen ) ) {
			return $elen;
		}
		$c['filesLen'] = $len;
		$c['exclLen']  = $elen;
		$c['files']   += count( $files );
		$c['bytes']   += array_sum( array_column( $files, 's' ) );
		if ( $c['root'] >= count( $roots ) ) {
			return array(
				'phase'          => 'dump_db',
				'progress_done'  => count( $roots ),
				'bytes_total'    => (int) $c['bytes'],
				'cursor_data'    => array(
					'table'     => 0,
					'chunk'     => 1,
					'page'      => array(),
					'chunksLen' => 0,
					'rows'      => 0,
					'files'     => $c['files'],
					'fileBytes' => $c['bytes'],
				),
			);
		}
		return array(
			'progress_done' => (int) $c['root'],
			'cursor_data'   => $c,
		);
	}

	private static function join( string $prefix, string $rel ): string {
		if ( '' === $prefix ) {
			return $rel;
		}
		return '' === $rel ? $prefix : $prefix . '/' . $rel;
	}

	/**
	 * Why a directory entry is excluded, or null to include it.
	 */
	public static function exclusion_reason( string $category, string $parent_rel, string $name, string $abs ) {
		if ( is_link( $abs ) ) {
			return 'symbolic link (never followed or archived)';
		}
		if ( ! is_dir( $abs ) && ! is_file( $abs ) ) {
			return 'special file (device, FIFO, socket, or a link/junction PHP cannot resolve)';
		}
		if ( preg_match( self::GLOBAL_SKIP, $name ) ) {
			return 'development, log, cache or secret file';
		}
		if ( 'uploads' === $category ) {
			if ( '' === $parent_rel && preg_match( self::UPLOADS_TOP, $name ) ) {
				return 0 === stripos( $name, 'cradle-private-' ) ? 'Cradle private storage (archives, jobs, secrets)' : 'cache, log or backup directory';
			}
			if ( is_file( $abs ) && preg_match( self::SERVER_FILES, $name ) ) {
				return 'server-control file (not transplanted)';
			}
			if ( is_file( $abs ) && Path_Guard::is_executable_name( $name ) ) {
				return 'executable file in uploads (not exported)';
			}
		}
		if ( is_file( $abs ) && ! is_readable( $abs ) ) {
			return 'unreadable file';
		}
		return null;
	}

	/* ------------------------------- dump DB ------------------------------ */

	private static function dump_db( array $job, string $dir ) {
		$plan = Workspace::read_json( $dir . '/plan.json' );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$c        = $job['cursor_data'];
		$tables   = $plan['tables'];
		$records  = array();
		$deadline = microtime( true ) + self::STEP_SECONDS;
		while ( $c['table'] < count( $tables ) && microtime( true ) < $deadline ) {
			$meta = $tables[ $c['table'] ];
			if ( 'full' !== $meta['rowsMode'] ) {
				++$c['table'];
				$c['chunk'] = 1;
				$c['page']  = array();
				continue;
			}
			$chunk = self::build_chunk( $meta, $c['page'] );
			if ( is_wp_error( $chunk ) ) {
				return $chunk;
			}
			if ( $chunk['rows'] > 0 ) {
				$tdir = $dir . '/db/' . $meta['name'];
				if ( ! is_dir( $tdir ) && ! wp_mkdir_p( $tdir ) ) {
					return new \WP_Error( 'cradle_migration_write', 'Could not create a working directory.' );
				}
				$file = sprintf( '%s/%06d.jsonl', $tdir, $c['chunk'] );
				if ( false === file_put_contents( $file, $chunk['data'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					return new \WP_Error( 'cradle_migration_write', 'Could not write a database chunk (disk full?).' );
				}
				$records[] = array(
					'table'  => $meta['name'],
					'index'  => $c['chunk'],
					'rows'   => $chunk['rows'],
					'bytes'  => strlen( $chunk['data'] ),
					'sha256' => hash( 'sha256', $chunk['data'] ),
				);
				$c['rows'] += $chunk['rows'];
				++$c['chunk'];
			}
			$c['page'] = $chunk['page'];
			if ( $chunk['done'] ) {
				++$c['table'];
				$c['chunk'] = 1;
				$c['page']  = array();
			}
		}
		$len = Workspace::append_lines( $dir . '/chunks.jsonl', $records, (int) $c['chunksLen'] );
		if ( is_wp_error( $len ) ) {
			return $len;
		}
		$c['chunksLen'] = $len;
		$roots          = count( $plan['roots'] );
		if ( $c['table'] >= count( $tables ) ) {
			return array(
				'phase'         => 'pack',
				'progress_done' => $roots + count( $tables ),
				'cursor_data'   => array(
					'src'        => 'chunks',
					'offset'     => 0,
					'part'       => 1,
					'partBytes'  => 0,
					'entriesLen' => 0,
					'metaLen'    => 0,
					'warnLen'    => 0,
					'seg'        => 0,
					'segs'       => array(),
					'hash'       => '',
					'rows'       => $c['rows'],
					'files'      => $c['files'],
					'fileBytes'  => $c['fileBytes'],
					'packed'     => 0,
				),
			);
		}
		return array(
			'progress_done' => $roots + (int) $c['table'],
			'cursor_data'   => $c,
		);
	}

	/**
	 * Reads rows until a chunk is full or the table ends.
	 *
	 * @return array{data:string,rows:int,page:array,done:bool}|\WP_Error
	 */
	private static function build_chunk( array $meta, array $page ) {
		$data      = '';
		$rows      = 0;
		$done      = false;
		$key       = Db::keyset_column( $meta );
		$key_index = null;
		$name_col  = null;
		foreach ( $meta['columns'] as $i => $col ) {
			if ( $col['name'] === $key ) {
				$key_index = $i;
			}
			if ( 'options' === $meta['name'] && 'option_name' === $col['name'] ) {
				$name_col = $i;
			}
		}
		while ( true ) {
			$batch = Db::read_batch( $meta['physical'], $meta, $page, self::READ_BATCH );
			if ( is_wp_error( $batch ) ) {
				return $batch;
			}
			foreach ( $batch['rows'] as $row ) {
				if ( null !== $key_index ) {
					$page['last'] = (string) $row[ $key_index ];
				} else {
					$page['offset'] = (int) ( $page['offset'] ?? 0 ) + 1;
				}
				if ( null !== $name_col && self::skip_option( (string) $row[ $name_col ] ) ) {
					continue;
				}
				$line = array();
				foreach ( $meta['columns'] as $i => $col ) {
					$v = $row[ $i ];
					if ( null === $v ) {
						$line[] = null;
					} elseif ( ! empty( $col['binary'] ) || ! mb_check_encoding( (string) $v, 'UTF-8' ) ) {
						$line[] = array( 'b64' => base64_encode( (string) $v ) );
					} else {
						$line[] = (string) $v;
					}
				}
				$encoded = json_encode( $line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( false === $encoded ) {
					return new \WP_Error( 'cradle_migration_encode', 'A row of ' . $meta['name'] . ' could not be encoded.' );
				}
				$data .= $encoded . "\n";
				++$rows;
				if ( $rows >= self::CHUNK_ROWS || strlen( $data ) >= self::CHUNK_BYTES ) {
					break 2;
				}
			}
			if ( $batch['exhausted'] ) {
				$done = true;
				break;
			}
		}
		return array(
			'data' => $data,
			'rows' => $rows,
			'page' => $page,
			'done' => $done,
		);
	}

	/**
	 * Options that are caches or runtime state of this plugin and never exported.
	 */
	public static function skip_option( string $name ): bool {
		return 0 === strpos( $name, '_transient_' ) || 0 === strpos( $name, '_site_transient_' ) || Guard::QUIESCE_OPTION === $name;
	}

	/* -------------------------------- pack -------------------------------- */

	private static function pack( array $job, string $dir, array $opts ) {
		$plan = Workspace::read_json( $dir . '/plan.json' );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$c         = $job['cursor_data'];
		$archive   = $job['payload']['archiveId'];
		$part_size = (int) $opts['partSize'];
		$seg_size  = min( Manifest::SEGMENT_BYTES, $part_size );
		$parts_dir = $dir . '/parts';
		if ( ! is_dir( $parts_dir ) && ! wp_mkdir_p( $parts_dir ) ) {
			return new \WP_Error( 'cradle_migration_write', 'Could not create the parts directory.' );
		}
		$zip       = null;
		$open_part = function ( int $index, bool $fresh ) use ( $parts_dir, $archive, &$zip ) {
			$path = $parts_dir . '/' . Manifest::part_name( $archive, $index );
			if ( $fresh && is_file( $path ) ) {
				wp_delete_file( $path );
			}
			$zip = new \ZipArchive();
			$ok  = $zip->open( $path, \ZipArchive::CREATE );
			return true === $ok ? true : new \WP_Error( 'cradle_migration_zip', 'Could not open archive part ' . $index . ' (' . $ok . ').' );
		};
		$opened = $open_part( (int) $c['part'], false );
		if ( is_wp_error( $opened ) ) {
			return $opened;
		}
		$entries  = array();
		$metas    = array();
		$warnings = array();
		$checks   = array();
		$added    = 0;
		$deadline = microtime( true ) + self::STEP_SECONDS;
		$finished = false;
		$rotate   = function () use ( &$c, &$zip, $open_part ) {
			if ( ! $zip->close() ) {
				return new \WP_Error( 'cradle_migration_zip', 'Could not write archive part ' . $c['part'] . ' (disk full?).' );
			}
			++$c['part'];
			$c['partBytes'] = 0;
			return $open_part( (int) $c['part'], true );
		};
		while ( $added < self::PACK_BYTES && microtime( true ) < $deadline ) {
			$src  = 'chunks' === $c['src'] ? $dir . '/chunks.jsonl' : $dir . '/files.jsonl';
			$item = self::read_line_at( $src, (int) $c['offset'] );
			if ( null === $item ) {
				if ( 'chunks' === $c['src'] ) {
					$c['src']    = 'files';
					$c['offset'] = 0;
					continue;
				}
				$finished = true;
				break;
			}
			$record = $item['record'];
			if ( 'chunks' === $c['src'] ) {
				$file  = sprintf( '%s/db/%s/%06d.jsonl', $dir, $record['table'], $record['index'] );
				$entry = Manifest::chunk_entry( $record['table'], (int) $record['index'] );
				if ( $c['partBytes'] > 0 && $c['partBytes'] + $record['bytes'] > $part_size ) {
					$r = $rotate();
					if ( is_wp_error( $r ) ) {
						return $r;
					}
				}
				if ( ! $zip->addFile( $file, $entry ) ) {
					return new \WP_Error( 'cradle_migration_zip', 'Could not add a database chunk to the archive.' );
				}
				$zip->setCompressionName( $entry, \ZipArchive::CM_DEFLATE );
				$entries[]       = array(
					'kind'  => 'chunk',
					'table' => $record['table'],
					'index' => (int) $record['index'],
					'part'  => (int) $c['part'],
					'entry' => $entry,
				);
				$c['partBytes'] += $record['bytes'];
				$added          += $record['bytes'];
				$c['offset']     = $item['next'];
				continue;
			}
			// A file (possibly segmented, possibly resumed mid-file).
			$root = $plan['roots'][ $record['r'] ];
			$abs  = isset( $root['file'] ) ? $root['file'] : $root['dir'] . '/' . $record['x'];
			if ( ! is_file( $abs ) || is_link( $abs ) || ! is_readable( $abs ) ) {
				$warnings[]  = array( 'w' => 'Skipped ' . $record['c'] . '/' . $record['p'] . ': it disappeared or became unreadable during export.' );
				$c['offset'] = $item['next'];
				$c['seg']    = 0;
				$c['segs']   = array();
				continue;
			}
			clearstatcache( true, $abs );
			$size  = (int) filesize( $abs );
			$mtime = (int) filemtime( $abs );
			if ( 0 === (int) $c['seg'] ) {
				$c['hash'] = hash_file( 'sha256', $abs );
				$c['stat'] = array( $size, $mtime );
			}
			$nseg  = max( 1, (int) ceil( $size / $seg_size ) );
			$store = (bool) preg_match( self::STORE_EXT, $abs );
			while ( $c['seg'] < $nseg ) {
				$start = (int) $c['seg'] * $seg_size;
				$len   = 1 === $nseg ? $size : min( $seg_size, $size - $start );
				if ( $c['partBytes'] > 0 && $c['partBytes'] + $len > $part_size ) {
					$r = $rotate();
					if ( is_wp_error( $r ) ) {
						return $r;
					}
				}
				$entry = Manifest::file_entry( $record['c'], $record['p'], (int) $c['seg'] + 1, $nseg );
				$ok    = 1 === $nseg ? $zip->addFile( $abs, $entry ) : $zip->addFile( $abs, $entry, $start, $len );
				if ( ! $ok ) {
					return new \WP_Error( 'cradle_migration_zip', 'Could not add a file to the archive.' );
				}
				$zip->setCompressionName( $entry, $store ? \ZipArchive::CM_STORE : \ZipArchive::CM_DEFLATE );
				$c['segs'][]     = array(
					'part'  => (int) $c['part'],
					'entry' => $entry,
					'size'  => $len,
				);
				$entries[]       = array(
					'kind'  => 'file',
					'part'  => (int) $c['part'],
					'entry' => $entry,
				);
				$c['partBytes'] += $len;
				$added          += $len;
				++$c['seg'];
				if ( $added >= self::PACK_BYTES && $c['seg'] < $nseg ) {
					break 2;
				}
			}
			$checks[]    = array( $abs, $c['stat'], count( $metas ) );
			$metas[]     = array(
				'category' => $record['c'],
				'path'     => $record['p'],
				'size'     => $size,
				'sha256'   => $c['hash'],
				'mtime'    => $mtime,
				'segments' => $c['segs'],
			);
			$c['offset'] = $item['next'];
			$c['seg']    = 0;
			$c['segs']   = array();
			$c['hash']   = '';
			++$c['packed'];
		}
		if ( ! $zip->close() ) {
			return new \WP_Error( 'cradle_migration_zip', 'Could not write archive part ' . $c['part'] . ' (disk full?).' );
		}
		// Files that changed while being archived: re-hash what was actually stored.
		foreach ( $checks as $check ) {
			list( $abs, $stat, $mi ) = $check;
			clearstatcache( true, $abs );
			if ( ! is_file( $abs ) || (int) filesize( $abs ) !== (int) $stat[0] || (int) filemtime( $abs ) !== (int) $stat[1] || hash_file( 'sha256', $abs ) !== $metas[ $mi ]['sha256'] ) {
				$rehash = self::hash_entries( $parts_dir, $archive, $metas[ $mi ]['segments'] );
				if ( is_wp_error( $rehash ) ) {
					return $rehash;
				}
				$metas[ $mi ]['sha256'] = $rehash['sha256'];
				$metas[ $mi ]['size']   = $rehash['size'];
				foreach ( $metas[ $mi ]['segments'] as $si => $seg ) {
					$metas[ $mi ]['segments'][ $si ]['size'] = $rehash['sizes'][ $si ];
				}
				$warnings[] = array( 'w' => 'File ' . $metas[ $mi ]['category'] . '/' . $metas[ $mi ]['path'] . ' changed while it was being exported; the archived copy is what was read.' );
			}
		}
		$len = Workspace::append_lines( $dir . '/entries.jsonl', $entries, (int) $c['entriesLen'] );
		if ( is_wp_error( $len ) ) {
			return $len;
		}
		$c['entriesLen'] = $len;
		$len             = Workspace::append_lines( $dir . '/files-meta.jsonl', $metas, (int) $c['metaLen'] );
		if ( is_wp_error( $len ) ) {
			return $len;
		}
		$c['metaLen'] = $len;
		$len          = Workspace::append_lines( $dir . '/warnings.jsonl', $warnings, (int) $c['warnLen'] );
		if ( is_wp_error( $len ) ) {
			return $len;
		}
		$c['warnLen'] = $len;
		$changes      = array(
			'bytes_done'  => (int) $job['bytes_done'] + $added,
			'cursor_data' => $c,
		);
		if ( $finished ) {
			$changes['phase']       = 'finalize';
			$changes['cursor_data'] = array(
				'parts'  => (int) $c['part'],
				'hashed' => array(),
				'rows'   => $c['rows'],
			);
		}
		return $changes;
	}

	/**
	 * Reads one JSON line at a byte offset.
	 *
	 * @return array{record:array,next:int}|null
	 */
	private static function read_line_at( string $path, int $offset ) {
		if ( ! is_file( $path ) ) {
			return null;
		}
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fseek( $fh, $offset );
		$line = fgets( $fh );
		$next = ftell( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( false === $line || '' === trim( $line ) ) {
			return null;
		}
		$record = json_decode( $line, true );
		return is_array( $record ) ? array(
			'record' => $record,
			'next'   => (int) $next,
		) : null;
	}

	/**
	 * Hashes stored segments back out of the parts (used when a source file changed mid-export).
	 *
	 * @return array{sha256:string,size:int,sizes:int[]}|\WP_Error
	 */
	private static function hash_entries( string $parts_dir, string $archive, array $segments ) {
		$ctx   = hash_init( 'sha256' );
		$total = 0;
		$sizes = array();
		foreach ( $segments as $seg ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $parts_dir . '/' . Manifest::part_name( $archive, (int) $seg['part'] ), \ZipArchive::RDONLY ) ) {
				return new \WP_Error( 'cradle_migration_zip', 'Could not reopen an archive part.' );
			}
			$stream = $zip->getStream( $seg['entry'] );
			$n      = 0;
			while ( $stream && ! feof( $stream ) ) {
				$buf = fread( $stream, 1048576 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $buf ) {
					break;
				}
				hash_update( $ctx, $buf );
				$n += strlen( $buf );
			}
			if ( $stream ) {
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			$zip->close();
			$sizes[] = $n;
			$total  += $n;
		}
		return array(
			'sha256' => hash_final( $ctx ),
			'size'   => $total,
			'sizes'  => $sizes,
		);
	}

	/* ------------------------------ finalize ------------------------------ */

	private static function finalize( array $job, string $dir, array $opts ) {
		$c         = $job['cursor_data'];
		$archive   = $job['payload']['archiveId'];
		$parts_dir = $dir . '/parts';
		$deadline  = microtime( true ) + self::STEP_SECONDS;

		// 1. Per part: drop stale entries left by an interrupted attempt, then hash.
		$expected = array();
		foreach ( Workspace::read_lines( $dir . '/entries.jsonl' ) as $e ) {
			$expected[ (int) $e['part'] ][ $e['entry'] ] = true;
		}
		for ( $p = 1; $p <= (int) $c['parts']; $p++ ) {
			if ( isset( $c['hashed'][ (string) $p ] ) ) {
				continue;
			}
			if ( microtime( true ) > $deadline ) {
				return array( 'cursor_data' => $c );
			}
			$path = $parts_dir . '/' . Manifest::part_name( $archive, $p );
			$zip  = new \ZipArchive();
			if ( true !== $zip->open( $path, \ZipArchive::CREATE ) ) {
				return new \WP_Error( 'cradle_migration_zip', 'Could not open archive part ' . $p . '.' );
			}
			$stale = array();
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = (string) $zip->getNameIndex( $i );
				if ( ! isset( $expected[ $p ][ $name ] ) ) {
					$stale[] = $name;
				}
			}
			foreach ( $stale as $name ) {
				$zip->deleteName( $name );
			}
			if ( 0 === $zip->numFiles && ! $stale ) {
				// libzip does not write an archive without entries; format 1 uses a zero-byte marker.
				$zip->addFromString( '.cradle-empty', '' );
				$expected[ $p ]['.cradle-empty'] = true;
			}
			if ( ! $zip->close() ) {
				return new \WP_Error( 'cradle_migration_zip', 'Could not finalize archive part ' . $p . '.' );
			}
			clearstatcache( true, $path );
			$c['hashed'][ (string) $p ] = array(
				'sha256' => hash_file( 'sha256', $path ),
				'size'   => (int) filesize( $path ),
			);
		}

		// 2. Manifest.
		$plan = Workspace::read_json( $dir . '/plan.json' );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$chunk_part = array();
		foreach ( Workspace::read_lines( $dir . '/entries.jsonl' ) as $e ) {
			if ( 'chunk' === $e['kind'] ) {
				$chunk_part[ $e['table'] . '#' . $e['index'] ] = (int) $e['part'];
			}
		}
		$chunks = array();
		foreach ( Workspace::read_lines( $dir . '/chunks.jsonl' ) as $ch ) {
			$chunks[ $ch['table'] ][] = array(
				'index'  => (int) $ch['index'],
				'part'   => $chunk_part[ $ch['table'] . '#' . $ch['index'] ] ?? 0,
				'entry'  => Manifest::chunk_entry( $ch['table'], (int) $ch['index'] ),
				'rows'   => (int) $ch['rows'],
				'bytes'  => (int) $ch['bytes'],
				'sha256' => $ch['sha256'],
			);
		}
		$tables = array();
		$rows   = 0;
		foreach ( $plan['tables'] as $t ) {
			unset( $t['physical'] );
			$t['chunks'] = $chunks[ $t['name'] ] ?? array();
			$t['rows']   = array_sum( array_column( $t['chunks'], 'rows' ) );
			$rows       += $t['rows'];
			foreach ( $t['columns'] as $i => $col ) {
				unset( $t['columns'][ $i ]['binary'] );
				$t['columns'][ $i ]['binary'] = ! empty( $col['binary'] );
			}
			$tables[] = $t;
		}
		$files      = Workspace::read_lines( $dir . '/files-meta.jsonl' );
		$exclusions = Workspace::read_lines( $dir . '/exclusions.jsonl', 5000 );
		$warnings   = array_column( Workspace::read_lines( $dir . '/warnings.jsonl', 2000 ), 'w' );
		$exclusions = array_merge(
			array(
				array(
					'category' => 'root',
					'path'     => 'wp-config.php',
					'reason'   => 'destination configuration and database credentials are never exported',
				),
				array(
					'category' => 'database',
					'path'     => 'options: _transient_*, _site_transient_*',
					'reason'   => 'caches',
				),
				array(
					'category' => 'database',
					'path'     => 'cradle_jobs, cradle_locks, cradle_activity rows',
					'reason'   => 'runtime state of this installation (schema only)',
				),
			),
			$exclusions
		);
		$parts = array();
		for ( $p = 1; $p <= (int) $c['parts']; $p++ ) {
			$parts[] = array(
				'name'   => Manifest::part_name( $archive, $p ),
				'index'  => $p,
				'size'   => $c['hashed'][ (string) $p ]['size'],
				'sha256' => $c['hashed'][ (string) $p ]['sha256'],
			);
		}
		$quiesced    = ! empty( $opts['quiesce'] );
		$full        = $opts['database'] && $opts['uploads'] && 'none' !== $opts['themes'] && 'none' !== $opts['plugins'];
		$packages    = $plan['packages'];
		$scope_theme = array();
		$scope_plug  = array();
		foreach ( $packages['themes'] as $t ) {
			if ( $t['included'] ) {
				$scope_theme[] = $t['slug'];
			}
		}
		foreach ( $packages['plugins'] as $pl ) {
			if ( $pl['included'] ) {
				$scope_plug[] = $pl['slug'];
			}
		}
		$manifest = array(
			'format'        => Manifest::FORMAT,
			'formatVersion' => Manifest::FORMAT_VERSION,
			'archiveId'     => $archive,
			'createdAt'     => $plan['startedAt'],
			'completedAt'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'generator'     => array(
				'name'        => 'cradle-migration',
				'version'     => CRADLE_MIGRATION_VERSION,
				'coreVersion' => defined( 'CRADLE_VERSION' ) ? CRADLE_VERSION : '',
				'coreApi'     => defined( 'CRADLE_API_VERSION' ) ? CRADLE_API_VERSION : '',
			),
			'mode'          => $full ? 'full' : 'selective',
			'scope'         => array(
				'database'  => (bool) $opts['database'],
				'uploads'   => (bool) $opts['uploads'],
				'themes'    => $scope_theme,
				'plugins'   => $scope_plug,
				'rootFiles' => $opts['rootFiles'],
			),
			'redacted'      => false,
			'consistency'   => array(
				'strategy'    => $quiesced ? 'write-quiescence' : 'none',
				'windowStart' => $quiesced ? $plan['startedAt'] : null,
				'windowEnd'   => $quiesced ? gmdate( 'Y-m-d\TH:i:s\Z' ) : null,
				'notes'       => $quiesced
					? 'WordPress-level writes (POST requests, comments, REST/AJAX writes, XML-RPC, WP-Cron) were refused during the export window. Direct database writers, system cron jobs and other processes outside WordPress were not stopped.'
					: 'No snapshot: tables and files were read in bounded batches while the site stayed writable. Content written during the export may be partially included. Use the write-quiescence option for a consistent copy.',
			),
			'source'        => $plan['source'],
			'packages'      => $packages,
			'database'      => array(
				'encoding' => 'jsonl-v1',
				'charset'  => $plan['source']['dbCharset'],
				'tables'   => $tables,
			),
			'files'         => $files,
			'exclusions'    => $exclusions,
			'unsupported'   => $plan['unsupported'],
			'notMigrated'   => Manifest::NOT_MIGRATED,
			'warnings'      => $warnings,
			'parts'         => $parts,
			'totals'        => array(
				'files'     => count( $files ),
				'fileBytes' => array_sum( array_column( $files, 'size' ) ),
				'tables'    => count( $tables ),
				'rows'      => $rows,
				'chunks'    => array_sum( array_map( 'count', $chunks ) ),
				'parts'     => count( $parts ),
				'partBytes' => array_sum( array_column( $parts, 'size' ) ),
			),
		);
		$json     = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		if ( false === $json ) {
			return new \WP_Error( 'cradle_migration_json', 'Could not encode the manifest.' );
		}
		$target = Workspace::subdir( 'archives', $archive );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		foreach ( $parts as $part ) {
			$from = $parts_dir . '/' . $part['name'];
			if ( is_file( $from ) && ! rename( $from, $target . '/' . $part['name'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				return new \WP_Error( 'cradle_migration_write', 'Could not move an archive part into place.' );
			}
		}
		if ( false === file_put_contents( $target . '/' . Manifest::manifest_name( $archive ), $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new \WP_Error( 'cradle_migration_write', 'Could not write the manifest.' );
		}
		$digest = hash( 'sha256', $json );
		Guard::end_quiesce( $job['uuid'] );
		Workspace::remove_tree( $dir );
		cradle_log_activity(
			'migration',
			'export_completed',
			sprintf( 'Site export %1$s completed (%2$d parts, %3$d files, %4$d rows).', $archive, count( $parts ), count( $files ), $rows ),
			array(
				'job'     => $job['uuid'],
				'archive' => $archive,
			)
		);
		$payload            = $job['payload'];
		$payload['archive'] = array(
			'id'          => $archive,
			'location'    => 'archives/' . $archive,
			'parts'       => count( $parts ),
			'bytes'       => array_sum( array_column( $parts, 'size' ) ),
			'unsupported' => count( $plan['unsupported'] ),
			'warnings'    => count( $warnings ),
			'consistency' => $manifest['consistency']['strategy'],
		);
		return array(
			'state'           => 'completed',
			'phase'           => 'done',
			'manifest_digest' => $digest,
			'payload'         => $payload,
			'progress_done'   => (int) $job['progress_total'],
		);
	}
}
