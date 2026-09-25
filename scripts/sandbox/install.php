<?php
/**
 * Installs the local sandbox WordPress in ../../wp (CLI only, development only).
 *
 * Usage: php scripts/sandbox/install.php [--url=http://localhost/cradle/wp] [--title="Cradle Sandbox"]
 *
 * Prints the generated administrator password once. It is never written to disk
 * by this script; keep it out of source and agent notes.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$opts  = getopt( '', array( 'url::', 'title::', 'user::', 'email::' ) );
$url   = rtrim( $opts['url'] ?? 'http://localhost/cradle/wp', '/' );
$title = $opts['title'] ?? 'Cradle Sandbox';
$user  = $opts['user'] ?? 'cradle_admin';
$email = $opts['email'] ?? 'admin@example.test';

$root = dirname( __DIR__, 2 ) . '/wp';
if ( ! is_file( $root . '/wp-config.php' ) ) {
	fwrite( STDERR, "wp/wp-config.php missing\n" );
	exit( 1 );
}

$parts                   = parse_url( $url );
$_SERVER['HTTP_HOST']    = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
$_SERVER['REQUEST_URI']  = ( $parts['path'] ?? '' ) . '/wp-admin/install.php';
$_SERVER['SERVER_NAME']  = $parts['host'];
$_SERVER['SERVER_PORT']  = $parts['port'] ?? 80;
$_SERVER['REQUEST_METHOD'] = 'GET';

define( 'WP_INSTALLING', true );
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

if ( is_blog_installed() ) {
	echo "Already installed.\n";
	exit( 0 );
}

$password = wp_generate_password( 24, true, false );
$result   = wp_install( $title, $user, $email, true, '', $password );

update_option( 'siteurl', $url );
update_option( 'home', $url );
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'blogdescription', '' );

echo "Installed WordPress {$GLOBALS['wp_version']} at {$url}\n";
echo "Admin user: {$user}\n";
echo "Admin password (shown once): {$password}\n";
