<?php
/**
 * Disposable migration TARGET: a second, independent WordPress at wp-target/
 * (URL http://localhost/cradle/wp-target, database cradle_wp_target, prefix ct_).
 * Deliberately different URL, path and table prefix from the source sandbox (wp/, cw_).
 *
 * Usage (CLI only; prints one JSON object on the last line):
 *   php tests/fixtures/migration/target.php install [--fresh]   Install/reset the target from the verified 7.1.2 zip.
 *   php tests/fixtures/migration/target.php sync                Mirror cradle core, cradle-migration and the cradle theme.
 *   php tests/fixtures/migration/target.php info                URLs, prefix, active plugins/theme, tables.
 *   php tests/fixtures/migration/target.php cookies <login>     Mint auth cookies + nonces for a target user (no password).
 *   php tests/fixtures/migration/target.php user <role>         Create/reuse a disposable target user; prints id + cookies.
 *   php tests/fixtures/migration/target.php option <name>       Raw option_value from the target database.
 *   php tests/fixtures/migration/target.php post <title>        Content/GUID/ID/permalink of a post by exact title.
 *   php tests/fixtures/migration/target.php marker [set|get]    Target-only marker option (proves replacement/rollback).
 *   php tests/fixtures/migration/target.php private-url <job>   Public URL a job-directory file would have (must be denied).
 *   php tests/fixtures/migration/target.php lease-test <manifest path>   Expired-lease / stale-commit test.
 *   php tests/fixtures/migration/target.php quiesce <start|finish> [job]      Export write-quiescence window test.
 *   php tests/fixtures/migration/target.php view <create|drop>  A database view (unsupported object) in the target.
 *
 * Never touches the source sandbox (wp/, cradle_wp).
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

const CRADLE_T_ROOT   = __DIR__ . '/../../..';
const CRADLE_T_DIR    = CRADLE_T_ROOT . '/wp-target';
const CRADLE_T_URL    = 'http://localhost/cradle/wp-target';
const CRADLE_T_DB     = 'cradle_wp_target';
const CRADLE_T_PREFIX = 'ct_';
const CRADLE_T_ZIP_SHA1 = 'fab6ce3a905a1f4085ffff7fd9920bc693f5dd94';

$cmd  = $argv[1] ?? 'info';
$rest = array_slice( $argv, 2 );

function t_out( array $data, int $code = 0 ) {
	echo json_encode( $data, JSON_UNESCAPED_SLASHES ) . "\n";
	exit( $code );
}

function t_zip_path(): string {
	$env = getenv( 'CRADLE_WP_ZIP' );
	if ( $env && is_file( $env ) ) {
		return $env;
	}
	return 'C:/Users/Guest/AppData/Local/Temp/claude/C--wamp64-www-cradle/b1903608-7b2a-4cfc-826c-91420db0f9fb/scratchpad/wordpress-7.1.2.zip';
}

function t_rmdir( string $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $item ) {
		if ( $item->isLink() || $item->isFile() ) {
			@chmod( $item->getPathname(), 0666 );
			unlink( $item->getPathname() );
		} else {
			rmdir( $item->getPathname() );
		}
	}
	rmdir( $dir );
}

function t_copy( string $from, string $to ) {
	$skip = '/^(\.git|\.svn|node_modules|\.DS_Store|Thumbs\.db|.*\.log|\.idea|\.vscode)$/i';
	if ( ! is_dir( $to ) ) {
		mkdir( $to, 0775, true );
	}
	foreach ( scandir( $from ) as $name ) {
		if ( '.' === $name || '..' === $name || preg_match( $skip, $name ) ) {
			continue;
		}
		if ( is_dir( "$from/$name" ) ) {
			t_copy( "$from/$name", "$to/$name" );
		} else {
			copy( "$from/$name", "$to/$name" );
		}
	}
}

function t_mysqli(): mysqli {
	mysqli_report( MYSQLI_REPORT_OFF );
	$m = new mysqli( '127.0.0.1', 'root', '', '', 3306 );
	if ( $m->connect_errno ) {
		t_out( array( 'error' => 'mysql: ' . $m->connect_error ), 1 );
	}
	return $m;
}

function t_php( array $args ): array {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ );
	foreach ( $args as $a ) {
		$cmd .= ' ' . escapeshellarg( $a );
	}
	exec( $cmd . ' 2>&1', $lines, $code );
	$last = json_decode( (string) end( $lines ), true );
	return array( 'code' => $code, 'json' => is_array( $last ) ? $last : null, 'output' => implode( "\n", $lines ) );
}

/** Mirrors Cradle core, Cradle Migration and the parent theme from packages/ into the target. */
function t_sync() {
	$map = array(
		'packages/plugins/cradle'           => 'wp-content/plugins/cradle',
		'packages/plugins/cradle-migration' => 'wp-content/plugins/cradle-migration',
		'packages/themes/cradle'            => 'wp-content/themes/cradle',
	);
	foreach ( $map as $src => $dst ) {
		t_rmdir( CRADLE_T_DIR . '/' . $dst );
		t_copy( CRADLE_T_ROOT . '/' . $src, CRADLE_T_DIR . '/' . $dst );
	}
	if ( function_exists( 'opcache_reset' ) ) {
		@opcache_reset();
	}
}

function t_boot( bool $admin = true ) {
	$_SERVER['HTTP_HOST']       = 'localhost';
	$_SERVER['SERVER_NAME']     = 'localhost';
	$_SERVER['SERVER_PORT']     = 80;
	$_SERVER['REQUEST_URI']     = '/cradle/wp-target/';
	$_SERVER['REQUEST_METHOD']  = 'GET';
	$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
	$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
	if ( ! defined( 'DISABLE_WP_CRON' ) ) {
		define( 'DISABLE_WP_CRON', true );
	}
	if ( $admin && ! defined( 'WP_ADMIN' ) ) {
		define( 'WP_ADMIN', true );
	}
	require CRADLE_T_DIR . '/wp-load.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/theme.php';
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) );
	if ( $admins ) {
		wp_set_current_user( $admins[0]->ID );
	}
}

function t_cookies( int $user_id ): array {
	$expiration = time() + 2 * HOUR_IN_SECONDS;
	$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
	$auth       = wp_generate_auth_cookie( $user_id, $expiration, 'auth', $token );
	$logged_in  = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token );
	$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in;
	wp_set_current_user( $user_id );
	$nonces = array();
	foreach ( array( 'cradle_jobs', 'cradle_migration', 'cradle_migration_admin', 'cradle_migration_download' ) as $action ) {
		$nonces[ $action ] = wp_create_nonce( $action );
	}
	return array(
		'user'    => $user_id,
		'cookies' => array(
			array( 'name' => AUTH_COOKIE, 'value' => $auth ),
			array( 'name' => LOGGED_IN_COOKIE, 'value' => $logged_in ),
		),
		'nonces'  => $nonces,
	);
}

switch ( $cmd ) {
	case 'install':
		$fresh = in_array( '--fresh', $rest, true );
		$zip   = t_zip_path();
		if ( ! is_file( $zip ) || sha1_file( $zip ) !== CRADLE_T_ZIP_SHA1 ) {
			t_out( array( 'error' => 'WordPress 7.1.2 zip missing or checksum mismatch' ), 1 );
		}
		$m = t_mysqli();
		if ( $fresh ) {
			$m->query( 'DROP DATABASE IF EXISTS `' . CRADLE_T_DB . '`' );
		}
		$m->query( 'CREATE DATABASE IF NOT EXISTS `' . CRADLE_T_DB . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' );
		$core_ok = is_file( CRADLE_T_DIR . '/wp-includes/version.php' ) && false !== strpos( (string) file_get_contents( CRADLE_T_DIR . '/wp-includes/version.php' ), "'7.1.2'" );
		$z       = new ZipArchive();
		if ( true !== $z->open( $zip, ZipArchive::RDONLY ) ) {
			t_out( array( 'error' => 'zip open failed' ), 1 );
		}
		$need_content = $fresh || ! is_dir( CRADLE_T_DIR . '/wp-content' );
		if ( $need_content ) {
			t_rmdir( CRADLE_T_DIR . '/wp-content' );
		}
		if ( ! is_dir( CRADLE_T_DIR ) ) {
			mkdir( CRADLE_T_DIR, 0775, true );
		}
		for ( $i = 0; $i < $z->numFiles; $i++ ) {
			$name = $z->getNameIndex( $i );
			if ( 0 !== strpos( $name, 'wordpress/' ) || str_ends_with( $name, '/' ) ) {
				continue;
			}
			$rel        = substr( $name, 10 );
			$is_content = 0 === strpos( $rel, 'wp-content/' );
			if ( ( $is_content && ! $need_content ) || ( ! $is_content && $core_ok ) ) {
				continue;
			}
			$dest = CRADLE_T_DIR . '/' . $rel;
			if ( ! is_dir( dirname( $dest ) ) ) {
				mkdir( dirname( $dest ), 0775, true );
			}
			file_put_contents( $dest, $z->getFromIndex( $i ) );
		}
		$z->close();
		$salts = '';
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $k ) {
			$salts .= "define( '{$k}', '" . bin2hex( random_bytes( 32 ) ) . "' );\n";
		}
		if ( $fresh || ! is_file( CRADLE_T_DIR . '/wp-config.php' ) ) {
			file_put_contents(
				CRADLE_T_DIR . '/wp-config.php',
				"<?php\n/**\n * Cradle migration TARGET (disposable test fixture; recreate with tests/fixtures/migration/target.php install --fresh).\n */\n" .
				"define( 'DB_NAME', '" . CRADLE_T_DB . "' );\ndefine( 'DB_USER', 'root' );\ndefine( 'DB_PASSWORD', '' );\ndefine( 'DB_HOST', '127.0.0.1:3306' );\ndefine( 'DB_CHARSET', 'utf8mb4' );\ndefine( 'DB_COLLATE', '' );\n\n" .
				$salts . "\n\$table_prefix = '" . CRADLE_T_PREFIX . "';\n\n" .
				"// No external HTTP from this fixture (update checks, cron); loopback to localhost stays allowed.\n" .
				"define( 'WP_HTTP_BLOCK_EXTERNAL', true );\ndefine( 'AUTOMATIC_UPDATER_DISABLED', true );\n" .
				"define( 'WP_ENVIRONMENT_TYPE', 'staging' );\ndefine( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_LOG', true );\ndefine( 'WP_DEBUG_DISPLAY', false );\ndefine( 'DISALLOW_FILE_EDIT', true );\n\n" .
				"if ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\nrequire_once ABSPATH . 'wp-settings.php';\n"
			);
		}
		file_put_contents( CRADLE_T_DIR . '/.htaccess', "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\nRewriteBase /cradle/wp-target/\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /cradle/wp-target/index.php [L]\n</IfModule>\n# END WordPress\n" );
		t_sync();
		$r = t_php( array( '_wp_install' ) );
		if ( 0 !== $r['code'] ) {
			t_out( array( 'error' => 'install failed', 'output' => $r['output'] ), 1 );
		}
		$a = t_php( array( '_activate' ) );
		if ( 0 !== $a['code'] ) {
			t_out( array( 'error' => 'activation failed', 'output' => $a['output'] ), 1 );
		}
		$p = t_php( array( '_prepare' ) );
		if ( 0 !== $p['code'] ) {
			t_out( array( 'error' => 'prepare failed', 'output' => $p['output'] ), 1 );
		}
		t_out( array( 'installed' => true, 'fresh' => $fresh, 'install' => $r['json'], 'activate' => $a['json'], 'prepare' => $p['json'] ) );
		break;

	case '_wp_install':
		$_SERVER['HTTP_HOST']      = 'localhost';
		$_SERVER['REQUEST_URI']    = '/cradle/wp-target/wp-admin/install.php';
		$_SERVER['SERVER_NAME']    = 'localhost';
		$_SERVER['SERVER_PORT']    = 80;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		define( 'WP_INSTALLING', true );
		require CRADLE_T_DIR . '/wp-load.php';
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( ! is_blog_installed() ) {
			// Random password, never printed: tests mint cookies instead.
			wp_install( 'Cradle Migration Target', 'target_admin', 'target-admin@example.test', false, '', wp_generate_password( 32 ) );
		}
		update_option( 'siteurl', CRADLE_T_URL );
		update_option( 'home', CRADLE_T_URL );
		update_option( 'permalink_structure', '/%postname%/' );
		update_option( 'blogdescription', '' );
		t_out( array( 'wp' => $GLOBALS['wp_version'] ) );
		break;

	case '_activate':
		t_boot();
		$out = array();
		foreach ( array( 'cradle/cradle.php', 'cradle-migration/cradle-migration.php' ) as $p ) {
			$r       = activate_plugin( $p );
			$out[ $p ] = is_wp_error( $r ) ? $r->get_error_message() : 'active';
		}
		switch_theme( 'cradle' );
		t_out( array( 'plugins' => $out ) );
		break;

	case '_prepare':
		// Separate process so the plugins activated above are loaded and booted.
		t_boot();
		flush_rewrite_rules( true );
		$probe = cradle()->storage()->verify_http_denial( true );
		// Target-only content that a restore must replace (and a rollback must bring back).
		$page = get_page_by_path( 'target-only-page' );
		if ( ! $page ) {
			wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Target Only Page', 'post_name' => 'target-only-page', 'post_content' => 'Present only on the target before restore.' ) );
		}
		update_option( 'cradle_target_marker', 'original-target' );
		$up = wp_upload_dir();
		wp_mkdir_p( $up['basedir'] . '/target-only' );
		file_put_contents( $up['basedir'] . '/target-only/marker.txt', 'target file' );
		t_out( array( 'storage' => $probe['status'], 'caps' => current_user_can( 'cradle_import_site' ) ) );
		break;

	case 'sync':
		t_sync();
		t_out( array( 'synced' => true ) );
		break;

	case 'info':
		t_boot();
		global $wpdb;
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		t_out(
			array(
				'home'          => get_option( 'home' ),
				'siteurl'       => get_option( 'siteurl' ),
				'prefix'        => $wpdb->prefix,
				'template'      => get_option( 'template' ),
				'stylesheet'    => get_option( 'stylesheet' ),
				'themeExists'   => wp_get_theme()->exists() && ! wp_get_theme()->errors(),
				'activePlugins' => (array) get_option( 'active_plugins' ),
				'missingPlugins' => array_values( array_filter( (array) get_option( 'active_plugins' ), static fn( $p ) => ! is_file( WP_PLUGIN_DIR . '/' . $p ) ) ),
				'tables'        => $tables,
				'hold'          => get_option( 'cradle_migration_safety_hold' ),
				'quiesce'       => get_option( 'cradle_migration_quiesce' ),
				'marker'        => get_option( 'cradle_target_marker' ),
				'login'         => get_option( 'cradle_login_settings' ),
				'admins'        => array_map( static fn( $u ) => $u->user_login, get_users( array( 'role' => 'administrator' ) ) ),
				'userRolesOpt'  => is_array( get_option( $wpdb->prefix . 'user_roles' ) ),
				'oldPrefixKeys' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE 'cw\\_%'" ),
				'sessions'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'session_tokens'" ),
				'uploadsTargetOnly' => is_file( wp_upload_dir()['basedir'] . '/target-only/marker.txt' ),
				'wpVersion'     => $GLOBALS['wp_version'],
			)
		);
		break;

	case 'cookies':
		t_boot();
		$user = get_user_by( 'login', (string) ( $rest[0] ?? '' ) );
		if ( ! $user ) {
			t_out( array( 'error' => 'no such user' ), 1 );
		}
		t_out( t_cookies( (int) $user->ID ) );
		break;

	case 'user':
		t_boot();
		$role  = (string) ( $rest[0] ?? 'editor' );
		$login = 'mig_target_' . $role;
		$user  = get_user_by( 'login', $login );
		if ( ! $user ) {
			if ( 'migrator' === $role && ! get_role( 'cradle_test_migrator' ) ) {
				add_role( 'cradle_test_migrator', 'Cradle test migrator', array( 'read' => true, 'cradle_export_site' => true, 'cradle_import_site' => true ) );
			}
			$id   = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 32 ), 'user_email' => $login . '@example.test', 'role' => 'migrator' === $role ? 'cradle_test_migrator' : $role ) );
			$user = get_user_by( 'id', $id );
		}
		t_out( t_cookies( (int) $user->ID ) );
		break;

	case 'option':
		t_boot();
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", (string) ( $rest[0] ?? '' ) ) );
		t_out( array( 'value' => $v ) );
		break;

	case 'post':
		t_boot();
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_content, guid, post_type FROM {$wpdb->posts} WHERE post_title = %s ORDER BY ID DESC LIMIT 1", (string) ( $rest[0] ?? '' ) ), ARRAY_A );
		if ( $row ) {
			$row['permalink'] = get_permalink( (int) $row['ID'] );
			$row['meta']      = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $row['ID'] ), ARRAY_A );
		}
		t_out( array( 'post' => $row ) );
		break;

	case 'marker':
		t_boot();
		if ( 'set' === ( $rest[0] ?? 'get' ) ) {
			update_option( 'cradle_target_marker', (string) ( $rest[1] ?? 'set' ) );
		}
		t_out( array( 'marker' => get_option( 'cradle_target_marker' ) ) );
		break;

	case 'private-url':
		t_boot();
		$root = cradle()->storage()->private_root();
		$dir  = Cradle\Migration\Workspace::job_dir( (string) ( $rest[0] ?? '' ) );
		$rel  = ltrim( substr( wp_normalize_path( (string) $dir ), strlen( $root['path'] ) ), '/' );
		t_out( array( 'mode' => $root['mode'], 'url' => ( $root['url'] ?? '' ) . '/' . $rel, 'dir' => $dir, 'files' => array_map( 'basename', (array) glob( $dir . '/*' ) ) ) );
		break;

	case 'lease-test':
		// Expired lease + duplicate work: a worker claims the job, does a load step, then "dies"
		// before committing; a new runner takes over (higher fencing) and repeats the same chunk
		// idempotently; the dead worker's late commit is refused.
		t_boot();
		$jobs  = cradle()->jobs();
		$local = Cradle\Migration\Upload::import_local( (string) ( $rest[0] ?? '' ) );
		if ( is_wp_error( $local ) ) {
			t_out( array( 'error' => $local->get_error_message() ), 1 );
		}
		$job = Cradle\Migration\Importer::create( $local, true );
		if ( is_wp_error( $job ) ) {
			t_out( array( 'error' => $job->get_error_message() ), 1 );
		}
		$uuid = $job['uuid'];
		do {
			$job = $jobs->run( $uuid );
		} while ( ! in_array( $job['state'], array( 'paused', 'failed' ), true ) );
		if ( 'paused' !== $job['state'] ) {
			t_out( array( 'error' => 'preflight failed', 'job' => $job['error_message'] ), 1 );
		}
		Cradle\Migration\Importer::approve( $job );
		// One unit of work per step, driven manually, until the next unit loads rows into an
		// already-created staged table.
		add_filter( 'cradle_migration_step_seconds', static fn() => 0.0 );
		$v    = Cradle\Migration\Importer::validated( Cradle\Migration\Workspace::job_dir( $uuid ) );
		$next = null;
		for ( $i = 0; $i < 5000; $i++ ) {
			$job = $jobs->get( $uuid );
			if ( 'load_db' === $job['phase'] ) {
				$c = $job['cursor_data'];
				$t = $v['manifest']['tables'][ $c['t'] ] ?? null;
				if ( $t && in_array( $t['name'], (array) $c['created'], true ) && $c['c'] < count( $t['chunks'] ) ) {
					$next = $t['name'] . ' chunk ' . ( $c['c'] + 1 );
					break;
				}
			}
			$claim = $jobs->claim( $uuid, 60 );
			$res   = Cradle\Migration\Importer::step( $claim, $jobs );
			$jobs->commit( $claim, is_array( $res ) ? $res : array(), true );
		}
		if ( null === $next ) {
			t_out( array( 'error' => 'did not reach a chunk load' ), 1 );
		}
		$stale = $jobs->claim( $uuid, 1 );
		$work  = Cradle\Migration\Importer::step( $stale, $jobs ); // Rows inserted; the worker "dies" before committing.
		sleep( 3 );
		$taken = $jobs->claim( $uuid, 60 ); // Lease expired: taken over with a higher fencing generation.
		$took  = is_array( $taken ) && $taken['fencing'] > $stale['fencing'];
		$again = Cradle\Migration\Importer::step( $taken, $jobs ); // Same chunk again (duplicate work).
		$jobs->commit( $taken, is_array( $again ) ? $again : array(), true );
		$late  = $jobs->commit( $stale, is_array( $work ) ? $work : array() );
		// Finish loading, then count before the safety profile (which clears sessions) runs.
		for ( $i = 0; $i < 5000; $i++ ) {
			$job = $jobs->get( $uuid );
			if ( 'load_db' !== $job['phase'] || 'running' !== $job['state'] ) {
				break;
			}
			$claim = $jobs->claim( $uuid, 60 );
			$res   = Cradle\Migration\Importer::step( $claim, $jobs );
			$jobs->commit( $claim, is_array( $res ) ? $res : array(), true );
		}
		$counts = array();
		foreach ( $v['manifest']['tables'] as $t ) {
			if ( 'full' === $t['rowsMode'] && ! in_array( $t['name'], Cradle\Migration\Db::RUNTIME_TABLES, true ) ) {
				$counts[ $t['name'] ] = array( (int) $t['rows'], Cradle\Migration\Db::count( Cradle\Migration\Importer::staged_name( $uuid, $t['name'] ) ) );
			}
		}
		$mismatch = array_filter( $counts, static fn( $c ) => $c[0] !== $c[1] );
		do {
			$job = $jobs->run( $uuid );
		} while ( 'running' === $job['state'] );
		$state = $job['state'];
		Cradle\Migration\Importer::discard_staging( $uuid );
		$jobs->cancel( $uuid );
		t_out( array( 'uuid' => $uuid, 'repeated' => $next, 'takeover' => $took, 'lateCommitRefused' => false === $late, 'state' => $state, 'mismatch' => $mismatch, 'tables' => count( $counts ) ) );
		break;

	case 'quiesce':
		t_boot();
		$jobs = cradle()->jobs();
		if ( 'start' === ( $rest[0] ?? '' ) ) {
			$job = Cradle\Migration\Exporter::create( array( 'database' => true, 'uploads' => false, 'themes' => 'none', 'plugins' => 'none', 'quiesce' => true ), true );
			if ( is_wp_error( $job ) ) {
				t_out( array( 'error' => $job->get_error_message() ), 1 );
			}
			$claim  = $jobs->claim( $job['uuid'], 60 );
			$result = Cradle\Migration\Exporter::step( $claim, $jobs );
			$jobs->commit( $claim, is_array( $result ) ? $result : array(), true );
			t_out( array( 'job' => $job['uuid'], 'window' => get_option( 'cradle_migration_quiesce' ) ) );
		}
		$uuid = (string) ( $rest[1] ?? '' );
		do {
			$job = $jobs->run( $uuid );
		} while ( ! in_array( $job['state'], Cradle\Core\Jobs::TERMINAL_STATES, true ) );
		$archive = $job['payload']['archive'] ?? array();
		$m       = null;
		if ( $archive ) {
			$dir = Cradle\Migration\Workspace::resolve_rel( $archive['location'] );
			$m   = json_decode( (string) file_get_contents( $dir . '/' . $archive['id'] . '.manifest.json' ), true );
			Cradle\Migration\Workspace::remove_tree( $dir );
		}
		t_out( array( 'state' => $job['state'], 'window' => get_option( 'cradle_migration_quiesce' ), 'consistency' => $m['consistency'] ?? null, 'unsupported' => $m['unsupported'] ?? null ) );
		break;

	case 'upload-marker':
		t_boot();
		$file = wp_upload_dir( null, false )['basedir'] . '/rollback-marker.txt';
		if ( 'set' === ( $rest[0] ?? 'get' ) ) {
			file_put_contents( $file, (string) ( $rest[1] ?? 'marker' ) );
		}
		t_out( array( 'exists' => is_file( $file ), 'content' => is_file( $file ) ? file_get_contents( $file ) : null ) );
		break;

	case 'view':
		t_boot();
		global $wpdb;
		if ( 'create' === ( $rest[0] ?? '' ) ) {
			$wpdb->query( "CREATE OR REPLACE VIEW {$wpdb->prefix}mig_view AS SELECT ID, post_title FROM {$wpdb->posts}" );
		} else {
			$wpdb->query( "DROP VIEW IF EXISTS {$wpdb->prefix}mig_view" );
		}
		t_out( array( 'ok' => '' === $wpdb->last_error, 'error' => $wpdb->last_error ) );
		break;

	default:
		t_out( array( 'error' => 'unknown command' ), 1 );
}
