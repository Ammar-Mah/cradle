<?php
/**
 * Boots the sandbox in a separate PHP process with the Cradle core plugin filtered
 * out of the active plugin list (for this process only; nothing is written), to
 * verify the cradle-admin runtime guard: no fatal error, nothing applied, and an
 * admin notice for users who can activate plugins.
 *
 * Usage: php tests/fixtures/admin/core-missing-probe.php
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['REQUEST_URI']    = '/cradle/wp/wp-admin/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
define( 'WP_ADMIN', true );

// Pre-initialized hook (supported by WP_Hook::build_preinitialized_hooks) that hides core for this process.
$GLOBALS['wp_filter'] = array(
	'option_active_plugins' => array(
		1 => array(
			array(
				'function'      => static function ( $plugins ) {
					return array_values( array_diff( (array) $plugins, array( 'cradle/cradle.php' ) ) );
				},
				'accepted_args' => 1,
			),
		),
	),
);

require dirname( __DIR__, 3 ) . '/wp/wp-load.php';

$admin = get_user_by( 'login', 'cradle_test_administrator' );
wp_set_current_user( $admin ? $admin->ID : 0 );

ob_start();
do_action( 'admin_notices' );
$notices = ob_get_clean();

echo wp_json_encode(
	array(
		'core_loaded'         => function_exists( 'cradle' ),
		'admin_plugin_loaded' => defined( 'CRADLE_ADMIN_VERSION' ),
		'module_class_loaded' => class_exists( 'Cradle\\Admin\\Module', false ),
		'body_class_filtered' => (bool) has_filter( 'admin_body_class', array( 'Cradle\\Admin\\Skin', 'body_class' ) ),
		'notice'              => false !== strpos( $notices, 'Cradle Admin needs the Cradle core plugin' ),
	)
);
