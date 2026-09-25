<?php
/**
 * Subprocess used by the translations suite: loads the sandbox with ONLY cradle-translations
 * active (core missing, a bundled default theme for this process only) and reports the runtime
 * guard behavior as JSON. Nothing is persisted: the plugin list and theme are overridden with
 * pre-initialized in-memory filters.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['REQUEST_URI']    = '/cradle/wp/wp-admin/';
$_SERVER['REQUEST_METHOD'] = 'GET';
define( 'WP_ADMIN', true );

$only    = static fn() => array( 'cradle-translations/cradle-translations.php' );
$theme   = static fn() => 'twentytwentyfive';
$wp_filter = array(
	'option_active_plugins' => array( 10 => array( 'ct_only' => array( 'function' => $only, 'accepted_args' => 1 ) ) ),
	'pre_option_template'   => array( 10 => array( 'ct_theme' => array( 'function' => $theme, 'accepted_args' => 1 ) ) ),
	'pre_option_stylesheet' => array( 10 => array( 'ct_theme' => array( 'function' => $theme, 'accepted_args' => 1 ) ) ),
);

require dirname( __DIR__, 3 ) . '/wp/wp-load.php';

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
ob_start();
do_action( 'admin_notices' );
$notices = (string) ob_get_clean();

echo wp_json_encode(
	array(
		'core'        => function_exists( 'cradle' ),
		'plugin'      => defined( 'CRADLE_TRANSLATIONS_VERSION' ),
		'runtime'     => class_exists( 'Cradle\Translations\Runtime', false ),
		'gettext'     => (bool) has_filter( 'gettext_cradle' ),
		'notice'      => $notices,
		'translation' => __( 'Cradle modules', 'cradle' ),
	)
);
