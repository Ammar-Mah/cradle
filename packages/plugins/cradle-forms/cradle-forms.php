<?php
/**
 * Plugin Name:       Cradle Forms
 * Description:       Forms built from blocks: server-validated submissions without JavaScript, stored entries, private file uploads, consent records, notifications with routing and spam protection.
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Requires Plugins:  cradle
 * Author:            Cradle
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cradle-forms
 * Domain Path:       /languages
 * Update URI:        false
 * Cradle Module:     forms
 *
 * @package Cradle\Forms
 */

defined( 'ABSPATH' ) || exit;

define( 'CRADLE_FORMS_VERSION', '0.1.0' );
define( 'CRADLE_FORMS_FILE', __FILE__ );
define( 'CRADLE_FORMS_DIR', __DIR__ . '/' );

spl_autoload_register(
	static function ( $class_name ) {
		$map = array(
			'Cradle\\Forms\\Admin\\' => CRADLE_FORMS_DIR . 'admin/',
			'Cradle\\Forms\\'        => CRADLE_FORMS_DIR . 'src/',
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

register_activation_hook( __FILE__, array( 'Cradle\\Forms\\Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Cradle\\Forms\\Lifecycle', 'deactivate' ) );

add_action( 'cradle/register_modules', array( 'Cradle\\Forms\\Module', 'register' ) );

// Runtime guard: core can disappear (FTP delete, failed update, CLI). Forms pause; entries and files are untouched.
add_action(
	'plugins_loaded',
	static function () {
		if ( ! function_exists( 'cradle' ) || ! cradle() ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-warning"><p>' . esc_html__( 'Cradle Forms needs the Cradle core plugin. Forms are paused (embedded forms show nothing and submissions are not accepted); no entries or files were changed.', 'cradle-forms' ) . '</p></div>';
					}
				}
			);
		}
	},
	20
);
