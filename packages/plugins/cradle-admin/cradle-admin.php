<?php
/**
 * Plugin Name:       Cradle Admin
 * Description:       White-label branding, scoped admin appearance, label overrides and trusted custom CSS for the WordPress administration. Everything is off until configured.
 * Version:           0.1.1
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Requires Plugins:  cradle
 * Author:            Cradle
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cradle-admin
 * Domain Path:       /languages
 * Update URI:        false
 * Cradle Module:     admin
 *
 * @package Cradle\Admin
 */

defined( 'ABSPATH' ) || exit;

define( 'CRADLE_ADMIN_VERSION', '0.1.1' );
define( 'CRADLE_ADMIN_FILE', __FILE__ );
define( 'CRADLE_ADMIN_DIR', __DIR__ . '/' );

/*
 * Explicit namespace map: Cradle\Admin\Admin\* → admin/, Cradle\Admin\* → src/.
 * Only these two directories are consulted and class names are allowlisted.
 */
spl_autoload_register(
	static function ( $class_name ) {
		$map = array(
			'Cradle\\Admin\\Admin\\' => CRADLE_ADMIN_DIR . 'admin/',
			'Cradle\\Admin\\'        => CRADLE_ADMIN_DIR . 'src/',
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

register_activation_hook( __FILE__, array( 'Cradle\\Admin\\Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Cradle\\Admin\\Lifecycle', 'deactivate' ) );

add_action( 'cradle/register_modules', array( 'Cradle\\Admin\\Module', 'register' ) );

// Runtime guard: core can disappear (FTP delete, failed update, CLI). Nothing is styled or relabelled then.
add_action(
	'plugins_loaded',
	static function () {
		if ( ! function_exists( 'cradle' ) || ! cradle() ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-warning"><p>' . esc_html__( 'Cradle Admin needs the Cradle core plugin. Branding, appearance and label changes are paused and the native WordPress administration is shown; no data was changed.', 'cradle-admin' ) . '</p></div>';
					}
				}
			);
		}
	},
	20
);
