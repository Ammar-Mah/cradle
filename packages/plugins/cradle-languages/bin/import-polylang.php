<?php
/**
 * Polylang import from the command line (CLI only; exits without output over the web).
 *
 * Usage (from anywhere):
 *   php wp-content/plugins/cradle-languages/bin/import-polylang.php            dry run (default, changes nothing)
 *   php wp-content/plugins/cradle-languages/bin/import-polylang.php --run      dry run, then import when it lists no problems
 *   options: --user=<login>  administrator to act as (default: the first user with cradle_manage_languages)
 *            --wp=<path>     WordPress root (default: four folders above this file)
 *            --json          print the dry-run report as JSON
 *
 * Exit codes: 0 ok, 1 web request / usage, 2 WordPress or Cradle Languages unavailable, 3 problems, 4 import failed.
 *
 * @package Cradle\Languages
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$cradle_languages_args = array(
	'run'  => false,
	'json' => false,
	'user' => '',
	'wp'   => dirname( __DIR__, 4 ),
);
foreach ( array_slice( $argv, 1 ) as $cradle_languages_arg ) {
	if ( '--run' === $cradle_languages_arg ) {
		$cradle_languages_args['run'] = true;
	} elseif ( '--json' === $cradle_languages_arg ) {
		$cradle_languages_args['json'] = true;
	} elseif ( preg_match( '/^--user=([A-Za-z0-9._@-]{1,60})$/', $cradle_languages_arg, $cradle_languages_m ) ) {
		$cradle_languages_args['user'] = $cradle_languages_m[1];
	} elseif ( str_starts_with( $cradle_languages_arg, '--wp=' ) ) {
		$cradle_languages_args['wp'] = substr( $cradle_languages_arg, 5 );
	} else {
		fwrite( STDERR, "Unknown argument. Usage: import-polylang.php [--run] [--json] [--user=<login>] [--wp=<path>]\n" );
		exit( 1 );
	}
}

$cradle_languages_load = rtrim( str_replace( '\\', '/', $cradle_languages_args['wp'] ), '/' ) . '/wp-load.php';
if ( ! is_file( $cradle_languages_load ) ) {
	fwrite( STDERR, "wp-load.php not found; pass --wp=<WordPress root>.\n" );
	exit( 2 );
}
$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
define( 'WP_ADMIN', true );
require $cradle_languages_load;

if ( ! function_exists( 'cradle_languages_ready' ) || ! cradle_languages_ready() || ! class_exists( '\\Cradle\\Languages\\Polylang' ) ) {
	fwrite( STDERR, "Cradle Languages is not active and ready on this site.\n" );
	exit( 2 );
}

$cradle_languages_user = '' !== $cradle_languages_args['user'] ? get_user_by( 'login', $cradle_languages_args['user'] ) : null;
if ( ! $cradle_languages_user ) {
	foreach ( get_users( array( 'role' => 'administrator', 'number' => 20 ) ) as $cradle_languages_candidate ) {
		if ( user_can( $cradle_languages_candidate, 'cradle_manage_languages' ) ) {
			$cradle_languages_user = $cradle_languages_candidate;
			break;
		}
	}
}
if ( ! $cradle_languages_user || ! user_can( $cradle_languages_user, 'cradle_manage_languages' ) ) {
	fwrite( STDERR, "No user with the cradle_manage_languages capability was found (use --user=<login>).\n" );
	exit( 2 );
}
wp_set_current_user( $cradle_languages_user->ID );

$cradle_languages_report = \Cradle\Languages\Polylang::dry_run();
if ( $cradle_languages_args['json'] ) {
	echo wp_json_encode( $cradle_languages_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
} else {
	echo "Polylang dry run\n";
	foreach ( $cradle_languages_report['languages'] as $cradle_languages_row ) {
		printf( "  %-6s %-8s %-20s entries %d, terms %d%s\n", $cradle_languages_row['slug'], $cradle_languages_row['locale'], $cradle_languages_row['name'], (int) ( $cradle_languages_report['counts']['posts'][ $cradle_languages_row['slug'] ] ?? 0 ), (int) ( $cradle_languages_report['counts']['terms'][ $cradle_languages_row['slug'] ] ?? 0 ), $cradle_languages_row['default'] ? ' (default)' : '' );
	}
	printf( "  entry groups %d, term groups %d, menu locations %d\n", $cradle_languages_report['counts']['postGroups'], $cradle_languages_report['counts']['termGroups'], $cradle_languages_report['counts']['menus'] );
	foreach ( array( 'postTypes' => 'type', 'taxonomies' => 'taxonomy' ) as $cradle_languages_key => $cradle_languages_word ) {
		foreach ( (array) $cradle_languages_report[ $cradle_languages_key ] as $cradle_languages_name => $cradle_languages_mode ) {
			printf( "  %-8s %-32s %s\n", $cradle_languages_word, $cradle_languages_name, $cradle_languages_mode );
		}
	}
	foreach ( $cradle_languages_report['warnings'] as $cradle_languages_line ) {
		echo '  warning: ' . $cradle_languages_line . "\n";
	}
	foreach ( $cradle_languages_report['problems'] as $cradle_languages_line ) {
		echo '  problem: ' . $cradle_languages_line . "\n";
	}
}
if ( $cradle_languages_report['problems'] ) {
	exit( 3 );
}
if ( ! $cradle_languages_args['run'] ) {
	echo "Dry run only: nothing was changed. Add --run to import.\n";
	exit( 0 );
}

$cradle_languages_job = \Cradle\Languages\Polylang::start( $cradle_languages_report['fingerprint'] );
if ( is_wp_error( $cradle_languages_job ) ) {
	fwrite( STDERR, 'Import not started: ' . $cradle_languages_job->get_error_message() . "\n" );
	exit( 4 );
}
for ( $cradle_languages_i = 0; $cradle_languages_i < 10000; $cradle_languages_i++ ) {
	$cradle_languages_job = cradle()->jobs()->run( $cradle_languages_job['uuid'] );
	if ( is_wp_error( $cradle_languages_job ) ) {
		fwrite( STDERR, 'Import failed: ' . $cradle_languages_job->get_error_message() . "\n" );
		exit( 4 );
	}
	if ( in_array( $cradle_languages_job['state'], array( 'completed', 'failed', 'cancelled' ), true ) ) {
		break;
	}
}
if ( 'completed' !== $cradle_languages_job['state'] ) {
	fwrite( STDERR, 'Import ended in state ' . $cradle_languages_job['state'] . ': ' . $cradle_languages_job['error_message'] . "\n" );
	exit( 4 );
}
echo 'Imported: ' . wp_json_encode( $cradle_languages_job['payload']['counts'] ?? array() ) . "\n";
exit( 0 );
