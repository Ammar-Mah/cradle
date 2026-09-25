<?php
/**
 * Plugin Name:       Cradle Blocks
 * Description:       Generic Cradle components and the Gutenberg editor adapter (plus a shortcode fallback) built on the Cradle component contract.
 * Version:           0.1.1
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Requires Plugins:  cradle
 * Author:            Cradle
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cradle-blocks
 * Domain Path:       /languages
 * Update URI:        false
 * Cradle Module:     blocks
 *
 * @package Cradle\Blocks
 */

defined( 'ABSPATH' ) || exit;

define( 'CRADLE_BLOCKS_VERSION', '0.1.1' );
define( 'CRADLE_BLOCKS_FILE', __FILE__ );
define( 'CRADLE_BLOCKS_DIR', __DIR__ . '/' );

spl_autoload_register(
	static function ( $class_name ) {
		$map = array(
			'Cradle\\Blocks\\Admin\\' => CRADLE_BLOCKS_DIR . 'admin/',
			'Cradle\\Blocks\\'        => CRADLE_BLOCKS_DIR . 'src/',
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

register_activation_hook( __FILE__, array( 'Cradle\\Blocks\\Module', 'activate' ) );

add_action( 'cradle/register_modules', array( 'Cradle\\Blocks\\Module', 'register' ) );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! function_exists( 'cradle' ) || ! cradle() ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-warning"><p>' . esc_html__( 'Cradle Blocks needs the Cradle core plugin. Blocks render their saved fallback markup until core is active; no content was changed.', 'cradle-blocks' ) . '</p></div>';
					}
				}
			);
		}
	},
	20
);
