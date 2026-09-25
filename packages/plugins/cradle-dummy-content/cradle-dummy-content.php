<?php
/**
 * Plugin Name:       Cradle Dummy Content
 * Description:       Generate reproducible development content in tracked batches and remove exactly what was generated.
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Requires Plugins:  cradle
 * Author:            Cradle
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cradle-dummy-content
 * Domain Path:       /languages
 * Update URI:        false
 * Cradle Module:     dummy-content
 *
 * @package Cradle\Dummy_Content
 */

defined( 'ABSPATH' ) || exit;

define( 'CRADLE_DUMMY_CONTENT_VERSION', '0.1.0' );
define( 'CRADLE_DUMMY_CONTENT_FILE', __FILE__ );
define( 'CRADLE_DUMMY_CONTENT_DIR', __DIR__ . '/' );

spl_autoload_register(
	static function ( $class_name ) {
		$map = array(
			'Cradle\\Dummy_Content\\Admin\\' => CRADLE_DUMMY_CONTENT_DIR . 'admin/',
			'Cradle\\Dummy_Content\\'        => CRADLE_DUMMY_CONTENT_DIR . 'src/',
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

register_activation_hook( __FILE__, array( 'Cradle\\Dummy_Content\\Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Cradle\\Dummy_Content\\Lifecycle', 'deactivate' ) );

add_action( 'cradle/register_modules', array( 'Cradle\\Dummy_Content\\Module', 'register' ) );

// Runtime guard: core can disappear (FTP delete, failed update, CLI). Features pause; data is untouched.
add_action(
	'plugins_loaded',
	static function () {
		if ( ! function_exists( 'cradle' ) || ! cradle() ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-warning"><p>' . esc_html__( 'Cradle Dummy Content needs the Cradle core plugin. Generation and cleanup are paused; no data was changed.', 'cradle-dummy-content' ) . '</p></div>';
					}
				}
			);
		}
	},
	20
);
