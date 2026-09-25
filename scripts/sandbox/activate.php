<?php
/**
 * Activates sandbox plugins/theme through the normal WordPress activation APIs
 * (running each package's activation hook), as an administrator would.
 *
 * Usage:
 *   php scripts/sandbox/activate.php plugin cradle cradle-blocks ...
 *   php scripts/sandbox/activate.php deactivate cradle-social
 *   php scripts/sandbox/activate.php theme cradle-site
 *   php scripts/sandbox/activate.php status
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_URI']    = '/cradle/wp/wp-admin/plugins.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
define( 'WP_ADMIN', true );
require dirname( __DIR__, 2 ) . '/wp/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';

$admin = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) ( $admin[0] ?? 0 ) );

$cmd   = $argv[1] ?? 'status';
$names = array_slice( $argv, 2 );

$basename = static function ( string $slug ) {
	foreach ( array_keys( get_plugins() ) as $file ) {
		if ( dirname( $file ) === $slug || $file === $slug ) {
			return $file;
		}
	}
	return null;
};

switch ( $cmd ) {
	case 'plugin':
		foreach ( $names as $slug ) {
			$file = $basename( $slug );
			if ( ! $file ) {
				echo "missing plugin {$slug}\n";
				continue;
			}
			$result = activate_plugin( $file );
			echo is_wp_error( $result ) ? "error {$slug}: " . $result->get_error_message() . "\n" : "activated {$file}\n";
		}
		break;
	case 'deactivate':
		foreach ( $names as $slug ) {
			$file = $basename( $slug );
			if ( $file ) {
				deactivate_plugins( $file );
				echo "deactivated {$file}\n";
			}
		}
		break;
	case 'theme':
		$theme = wp_get_theme( $names[0] ?? '' );
		if ( ! $theme->exists() || $theme->errors() ) {
			echo 'theme error: ' . ( $theme->errors() ? $theme->errors()->get_error_message() : 'missing' ) . "\n";
			exit( 1 );
		}
		switch_theme( $theme->get_stylesheet() );
		echo 'active theme ' . get_stylesheet() . ' (template ' . get_template() . ")\n";
		break;
	default:
		echo 'theme: ' . get_stylesheet() . ' (template ' . get_template() . ")\n";
		foreach ( get_plugins() as $file => $data ) {
			echo ( is_plugin_active( $file ) ? '[x] ' : '[ ] ' ) . $file . ' ' . $data['Version'] . "\n";
		}
}
