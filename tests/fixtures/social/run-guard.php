<?php
/**
 * Test helper for tests/integration/social.php: loads the sandbox WordPress in a
 * separate process with one plugin hidden from the active list FOR THIS PROCESS
 * ONLY (a pre-initialized `option_active_plugins` filter; nothing is written to
 * the database), then reports how Cradle Social behaves.
 *
 * Usage: php run-guard.php no-core|no-blocks
 * Prints one JSON object.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$cradle_social_mode   = $argv[1] ?? '';
$cradle_social_hidden = array(
	'no-core'   => 'cradle/cradle.php',
	'no-blocks' => 'cradle-blocks/cradle-blocks.php',
)[ $cradle_social_mode ] ?? null;
if ( null === $cradle_social_hidden ) {
	fwrite( STDERR, "usage: run-guard.php no-core|no-blocks\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['SERVER_NAME']     = 'localhost';
$_SERVER['SERVER_PORT']     = 80;
$_SERVER['REQUEST_URI']     = '/cradle/wp/wp-admin/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
define( 'WP_ADMIN', true );

// WordPress converts a pre-set $wp_filter array into hooks when plugin.php loads.
$GLOBALS['wp_filter'] = array(
	'option_active_plugins' => array(
		1 => array(
			'cradle_social_hide' => array(
				'function'      => static function ( $plugins ) use ( $cradle_social_hidden ) {
					return array_values( array_diff( (array) $plugins, array( $cradle_social_hidden ) ) );
				},
				'accepted_args' => 1,
			),
		),
	),
);

$cradle_social_errors = array();
set_error_handler(
	static function ( $errno, $message, $file ) use ( &$cradle_social_errors ) {
		if ( false !== stripos( str_replace( '\\', '/', $file ), 'cradle-social' ) ) {
			$cradle_social_errors[] = $message . ' @ ' . basename( $file );
		}
		return false;
	}
);

require dirname( __DIR__, 3 ) . '/wp/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
set_current_screen( 'plugins' ); // Admin notices are printed on the Plugins screen.

$cradle_social_admin = get_user_by( 'login', 'cradle_test_administrator' );
if ( $cradle_social_admin ) {
	wp_set_current_user( $cradle_social_admin->ID );
}

ob_start();
do_action( 'admin_notices' );
$cradle_social_notices = (string) ob_get_clean();

$cradle_social_registry = WP_Block_Type_Registry::get_instance();
$cradle_social_core     = function_exists( 'cradle' ) ? cradle() : null;

echo wp_json_encode(
	array(
		'mode'             => $cradle_social_mode,
		'socialLoaded'     => defined( 'CRADLE_SOCIAL_VERSION' ),
		'coreLoaded'       => (bool) $cradle_social_core,
		'state'            => $cradle_social_core ? $cradle_social_core->modules()->state( 'social' ) : null,
		'problems'         => $cradle_social_core ? ( $cradle_social_core->modules()->report()['social']['problems'] ?? array() ) : array(),
		'section'          => $cradle_social_core ? (bool) $cradle_social_core->settings()->section( 'social' ) : false,
		'blockRegistered'  => $cradle_social_registry->is_registered( 'cradle/share-links' ),
		'contentFilter'    => (bool) has_filter( 'the_content', array( 'Cradle\\Social\\Placement', 'filter' ) ),
		'notice'           => str_contains( $cradle_social_notices, 'Cradle Social needs the Cradle core plugin' ),
		'coreNotice'       => str_contains( $cradle_social_notices, 'Cradle Social' ),
		'renderedBlock'    => trim( do_blocks( '<!-- wp:cradle/share-links /-->' ) ),
		'optionUnchanged'  => false === get_option( 'cradle_social_settings', false ) || is_array( get_option( 'cradle_social_settings' ) ),
		'errors'           => $cradle_social_errors,
	)
);
exit( 0 );
