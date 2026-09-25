<?php
/**
 * Plugin Name:       Cradle Login
 * Description:       Optional custom login route that serves the native WordPress login flows (login, logout, lost/reset password, registration, post passwords, reauthentication, recovery mode) at a configurable path, with a pre-enable health check and server-side recovery.
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Requires Plugins:  cradle
 * Author:            Cradle
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cradle-login
 * Domain Path:       /languages
 * Update URI:        false
 * Cradle Module:     login
 *
 * @package Cradle\Login
 */

defined( 'ABSPATH' ) || exit;

define( 'CRADLE_LOGIN_VERSION', '0.1.0' );
define( 'CRADLE_LOGIN_FILE', __FILE__ );
define( 'CRADLE_LOGIN_DIR', __DIR__ . '/' );

spl_autoload_register(
	static function ( $class_name ) {
		$map = array(
			'Cradle\\Login\\Admin\\' => CRADLE_LOGIN_DIR . 'admin/',
			'Cradle\\Login\\'        => CRADLE_LOGIN_DIR . 'src/',
		);
		foreach ( $map as $prefix => $dir ) {
			if ( 0 !== strpos( $class_name, $prefix ) ) {
				continue;
			}
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
);

register_activation_hook( __FILE__, array( 'Cradle\\Login\\Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Cradle\\Login\\Lifecycle', 'deactivate' ) );

add_action( 'cradle/register_modules', array( 'Cradle\\Login\\Module', 'register' ) );

// Routing is attached as soon as core reports the module ready (plugins_loaded priority 5),
// so $pagenow/is_login() are correct for other plugins during init. See docs/login.md.
add_action( 'cradle/ready', array( 'Cradle\\Login\\Module', 'ready' ), 10, 2 );

// Runtime guard: core can disappear (FTP delete, failed update, CLI). Without core no
// routing runs, so WordPress login behaves natively; no data is changed.
add_action(
	'plugins_loaded',
	static function () {
		if ( ! function_exists( 'cradle' ) || ! cradle() ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-warning"><p>' . esc_html__( 'Cradle Login needs the Cradle core plugin. Custom login routing is paused and the normal WordPress login page is in use; no data was changed.', 'cradle-login' ) . '</p></div>';
					}
				}
			);
		}
	},
	20
);
