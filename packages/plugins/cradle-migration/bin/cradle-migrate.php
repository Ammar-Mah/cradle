<?php
/**
 * Cradle Migration command-line runner (no WP-CLI required).
 *
 *   php wp-content/plugins/cradle-migration/bin/cradle-migrate.php --path=/var/www/site <command> [options]
 *   php .../cradle-migrate.php --path=... help
 *
 * Host-level tool: it runs with the rights of whoever can execute PHP as the site
 * owner. It refuses to run from a web server. `--raw` bootstraps WordPress with
 * SHORTINIT (no plugins, no theme) for journal-driven recovery of a site that cannot
 * load normally; see docs/recovery-guide.md.
 *
 * @package Cradle\Migration
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 1 );
}

$cradle_args   = array();
$cradle_assoc  = array();
foreach ( array_slice( $argv, 1 ) as $cradle_arg ) {
	if ( preg_match( '/^--([a-z0-9-]+)(?:=(.*))?$/s', $cradle_arg, $cradle_m ) ) {
		$cradle_assoc[ $cradle_m[1] ] = array_key_exists( 2, $cradle_m ) ? $cradle_m[2] : true;
	} else {
		$cradle_args[] = $cradle_arg;
	}
}

$cradle_path = isset( $cradle_assoc['path'] ) ? rtrim( str_replace( '\\', '/', (string) $cradle_assoc['path'] ), '/' ) : dirname( __DIR__, 4 );
if ( ! is_file( $cradle_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "Error: --path must point at the WordPress root (wp-load.php not found).\n" );
	exit( 1 );
}

$cradle_url = isset( $cradle_assoc['url'] ) ? (string) $cradle_assoc['url'] : 'http://localhost/';
$cradle_u   = parse_url( $cradle_url );

$_SERVER['HTTP_HOST']       = ( $cradle_u['host'] ?? 'localhost' ) . ( isset( $cradle_u['port'] ) ? ':' . $cradle_u['port'] : '' );
$_SERVER['SERVER_NAME']     = $cradle_u['host'] ?? 'localhost';
$_SERVER['SERVER_PORT']     = $cradle_u['port'] ?? 80;
$_SERVER['REQUEST_URI']     = ( $cradle_u['path'] ?? '/' );
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

// Never let this process fire visitor-style WP-Cron spawns.
define( 'DISABLE_WP_CRON', true );

if ( ! empty( $cradle_assoc['raw'] ) ) {
	define( 'SHORTINIT', true );
	require $cradle_path . '/wp-load.php';
	foreach ( array( 'serialized', 'db', 'journal', 'guard', 'recovery', 'job-store', 'cli' ) as $cradle_class ) {
		require_once dirname( __DIR__ ) . '/src/class-' . $cradle_class . '.php';
	}
	exit( Cradle\Migration\Cli::raw( $cradle_assoc ) );
}

define( 'WP_ADMIN', true );
require $cradle_path . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';

if ( ! class_exists( 'Cradle\\Migration\\Cli' ) ) {
	fwrite( STDERR, "Error: Cradle Migration is not active on this site. Use --raw for journal-based recovery.\n" );
	exit( 1 );
}
exit( Cradle\Migration\Cli::dispatch( $cradle_args, $cradle_assoc ) );
