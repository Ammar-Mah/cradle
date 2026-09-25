<?php
/**
 * Plugin Name:       Cradle Social
 * Description:       Social profile links and share links for the current page, built from an allowlisted provider registry with local icons and no remote SDKs, trackers or share counts.
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Requires Plugins:  cradle
 * Author:            Cradle
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cradle-social
 * Domain Path:       /languages
 * Update URI:        false
 * Cradle Module:     social
 *
 * @package Cradle\Social
 */

defined( 'ABSPATH' ) || exit;

define( 'CRADLE_SOCIAL_VERSION', '0.1.0' );
define( 'CRADLE_SOCIAL_FILE', __FILE__ );
define( 'CRADLE_SOCIAL_DIR', __DIR__ . '/' );

spl_autoload_register(
	static function ( $class_name ) {
		$map = array(
			'Cradle\\Social\\Admin\\' => CRADLE_SOCIAL_DIR . 'admin/',
			'Cradle\\Social\\'        => CRADLE_SOCIAL_DIR . 'src/',
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

register_activation_hook( __FILE__, array( 'Cradle\\Social\\Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Cradle\\Social\\Lifecycle', 'deactivate' ) );

add_action( 'cradle/register_modules', array( 'Cradle\\Social\\Module', 'register' ) );

// Runtime guard: core can disappear (FTP delete, failed update, CLI). Features pause; data is untouched.
add_action(
	'plugins_loaded',
	static function () {
		if ( ! function_exists( 'cradle' ) || ! cradle() ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-warning"><p>' . esc_html__( 'Cradle Social needs the Cradle core plugin. Social and share links are paused (the blocks output nothing); no settings were changed.', 'cradle-social' ) . '</p></div>';
					}
				}
			);
		}
	},
	20
);
