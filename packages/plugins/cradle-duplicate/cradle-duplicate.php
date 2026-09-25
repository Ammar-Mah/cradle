<?php
/**
 * Plugin Name:       Cradle Duplicate
 * Description:       Duplicate posts and pages as editable drafts through a protected, object-authorized action.
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Requires Plugins:  cradle
 * Author:            Cradle
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cradle-duplicate
 * Domain Path:       /languages
 * Update URI:        false
 * Cradle Module:     duplicate
 *
 * @package Cradle\Duplicate
 */

defined( 'ABSPATH' ) || exit;

define( 'CRADLE_DUPLICATE_VERSION', '0.1.0' );
define( 'CRADLE_DUPLICATE_FILE', __FILE__ );
define( 'CRADLE_DUPLICATE_DIR', __DIR__ . '/' );

spl_autoload_register(
	static function ( $class_name ) {
		$map = array(
			'Cradle\\Duplicate\\Admin\\' => CRADLE_DUPLICATE_DIR . 'admin/',
			'Cradle\\Duplicate\\'        => CRADLE_DUPLICATE_DIR . 'src/',
		);
		foreach ( $map as $prefix => $dir ) {
			if ( 0 === strpos( $class_name, $prefix ) ) {
				$relative = substr( $class_name, strlen( $prefix ) );
				if ( preg_match( '/^[A-Za-z0-9_]+$/', $relative ) ) {
					$file = $dir . 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
					if ( is_file( $file ) ) {
						require_once $file;
					}
				}
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, array( 'Cradle\\Duplicate\\Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Cradle\\Duplicate\\Lifecycle', 'deactivate' ) );

add_action( 'cradle/register_modules', array( 'Cradle\\Duplicate\\Module', 'register' ) );

// Runtime guard: core can disappear (FTP delete, failed update, CLI). Features pause; data is untouched.
add_action(
	'plugins_loaded',
	static function () {
		if ( ! function_exists( 'cradle' ) || ! cradle() ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-warning"><p>' . esc_html__( 'Cradle Duplicate needs the Cradle core plugin. Its features are paused; no data was changed.', 'cradle-duplicate' ) . '</p></div>';
					}
				}
			);
		}
	},
	20
);
