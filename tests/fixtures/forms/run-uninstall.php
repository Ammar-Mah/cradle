<?php
/**
 * Test helper for tests/integration/forms.php: runs the sandbox copy of
 * cradle-forms/uninstall.php the way WordPress does (WordPress loaded, WP_UNINSTALL_PLUGIN
 * defined) in a separate process, so the constant never leaks into the test process.
 * CLI only; touches only what uninstall.php decides under the current `cradle_uninstall_policy`.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['SERVER_NAME']     = 'localhost';
$_SERVER['SERVER_PORT']     = 80;
$_SERVER['REQUEST_URI']     = '/cradle/wp/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

define( 'WP_UNINSTALL_PLUGIN', 'cradle-forms/cradle-forms.php' );
require dirname( __DIR__, 3 ) . '/wp/wp-load.php';

$cradle_forms_uninstall = WP_PLUGIN_DIR . '/cradle-forms/uninstall.php';
if ( ! is_file( $cradle_forms_uninstall ) ) {
	fwrite( STDERR, "uninstall.php not found in the sandbox\n" );
	exit( 2 );
}
include $cradle_forms_uninstall;
echo "ok\n";
exit( 0 );
