<?php
/**
 * Boots the sandbox WordPress in a separate PHP process, optionally with the real
 * CRADLE_ADMIN_SAFE_MODE constant defined before plugins load (exactly as a
 * wp-config.php definition would), and prints what Cradle Admin applies.
 * Used by tests/integration/admin.php only; it changes nothing.
 *
 * Usage: php tests/fixtures/admin/safe-mode-probe.php on|off
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

if ( 'on' === ( $argv[1] ?? 'off' ) ) {
	define( 'CRADLE_ADMIN_SAFE_MODE', true );
}

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['REQUEST_URI']    = '/cradle/wp/wp-admin/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
define( 'WP_ADMIN', true );
require dirname( __DIR__, 3 ) . '/wp/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

$admin = get_user_by( 'login', 'cradle_test_administrator' );
wp_set_current_user( $admin ? $admin->ID : 0 );

$bar = new WP_Admin_Bar();
$bar->add_node(
	array(
		'id'    => 'wp-logo',
		'title' => 'WP',
	)
);
Cradle\Admin\Branding::admin_bar( $bar );

echo wp_json_encode(
	array(
		'safe_mode'  => Cradle\Admin\Module::safe_mode(),
		'classes'    => Cradle\Admin\Skin::state( WP_Screen::get( 'dashboard' ) )['classes'],
		'post_label' => get_post_type_object( 'post' )->labels->name,
		'footer'     => Cradle\Admin\Branding::footer_text( 'native footer' ),
		'css'        => Cradle\Admin\Custom_Css::printable(),
		'text'       => __( 'Cradle safe mode probe %s' ), // phpcs:ignore WordPress.WP.I18n
		'login'      => Cradle\Admin\Branding::login_body_class( array() ),
		'brand_node' => null !== $bar->get_node( 'cradle-brand' ),
	)
);
