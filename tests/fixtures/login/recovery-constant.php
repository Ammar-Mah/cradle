<?php
/**
 * Separate-process fixture for the CRADLE_DISABLE_CUSTOM_LOGIN recovery constant.
 *
 * A PHP constant cannot be defined for an already-running process or for Apache
 * requests from a test, so tests/integration/login.php runs this script with
 * PHP_BINARY. It optionally defines the real constant before WordPress loads (exactly
 * like a line in wp-config.php would) and prints the resulting routing state as JSON.
 *
 * Usage: php recovery-constant.php <with|without> <slug>
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$cradle_login_mode = $argv[1] ?? 'with';
$cradle_login_slug = $argv[2] ?? '';

if ( 'with' === $cradle_login_mode ) {
	define( 'CRADLE_DISABLE_CUSTOM_LOGIN', true );
}

$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['SERVER_NAME']     = 'localhost';
$_SERVER['SERVER_PORT']     = 80;
$_SERVER['REQUEST_URI']     = '/cradle/wp/wp-login.php';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

require dirname( __DIR__, 3 ) . '/wp/wp-load.php';

$cradle_login_out = array(
	'loaded' => class_exists( 'Cradle\\Login\\Router' ),
);
if ( $cradle_login_out['loaded'] ) {
	$cradle_login_router = Cradle\Login\Router::instance();
	$cradle_login_out   += array(
		'constant'       => defined( 'CRADLE_DISABLE_CUSTOM_LOGIN' ) && CRADLE_DISABLE_CUSTOM_LOGIN,
		'status'         => Cradle\Login\Config::status(),
		'active'         => $cradle_login_router->is_active(),
		'login_url'      => wp_login_url(),
		'native_url'     => Cradle\Login\Config::native_login_url(),
		'decision_login' => $cradle_login_router->decide( 'login', false, 'GET', array() ),
		'decision_admin' => $cradle_login_router->decide( 'admin', false, 'GET', array() ),
	);
	if ( 'with' === $cradle_login_mode && '' !== $cradle_login_slug ) {
		// Enabling (or re-enabling) must be refused without running a probe while the constant is set.
		$cradle_login_out['enable_errors'] = Cradle\Login\Module::validate_section(
			array(
				'enabled'          => true,
				'slug'             => $cradle_login_slug,
				'blocked_response' => 'not_found',
				'recovery_note'    => '',
			),
			array(
				'enabled'          => false,
				'slug'             => $cradle_login_slug,
				'blocked_response' => 'not_found',
				'recovery_note'    => '',
			)
		);
	}
}
echo 'CRADLE_LOGIN_JSON:' . wp_json_encode( $cradle_login_out ) . "\n";
