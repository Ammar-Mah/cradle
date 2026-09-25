<?php
/**
 * Runs cradle-admin's uninstall.php in a separate PHP process (WP_UNINSTALL_PLUGIN
 * must not leak into the test process). Used by tests/integration/admin.php only.
 *
 * Usage: php tests/fixtures/admin/run-uninstall.php
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_URI']    = '/cradle/wp/wp-admin/plugins.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
define( 'WP_ADMIN', true );
require dirname( __DIR__, 3 ) . '/wp/wp-load.php';

define( 'WP_UNINSTALL_PLUGIN', 'cradle-admin/cradle-admin.php' );
require WP_PLUGIN_DIR . '/cradle-admin/uninstall.php';
echo "uninstall-ran\n";
