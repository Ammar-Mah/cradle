<?php
/**
 * Plugin Name:       Cradle Translations
 * Description:       One authoritative JSON translation catalog per locale with a Loco-like editor: PHP, contextual, plural and JavaScript overrides for Cradle, WordPress and WooCommerce interface strings.
 * Version:           0.1.1
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Requires Plugins:  cradle
 * Author:            Cradle
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cradle-translations
 * Domain Path:       /languages
 * Update URI:        false
 * Cradle Module:     translations
 *
 * @package Cradle\Translations
 */

defined( 'ABSPATH' ) || exit;

define( 'CRADLE_TRANSLATIONS_VERSION', '0.1.1' );
define( 'CRADLE_TRANSLATIONS_FILE', __FILE__ );
define( 'CRADLE_TRANSLATIONS_DIR', __DIR__ . '/' );

spl_autoload_register(
	static function ( $class_name ) {
		$map = array(
			'Cradle\\Translations\\Admin\\' => CRADLE_TRANSLATIONS_DIR . 'admin/',
			'Cradle\\Translations\\'        => CRADLE_TRANSLATIONS_DIR . 'src/',
		);
		foreach ( $map as $prefix => $dir ) {
			if ( 0 === strpos( $class_name, $prefix ) ) {
				$rel = substr( $class_name, strlen( $prefix ) );
				if ( preg_match( '/^[A-Za-z0-9_]+$/', $rel ) ) {
					$file = $dir . 'class-' . strtolower( str_replace( '_', '-', $rel ) ) . '.php';
					if ( is_file( $file ) ) {
						require_once $file;
					}
				}
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, array( 'Cradle\\Translations\\Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Cradle\\Translations\\Lifecycle', 'deactivate' ) );

add_action( 'cradle/register_modules', array( 'Cradle\\Translations\\Module', 'register' ) );
add_action( 'cradle/ready', array( 'Cradle\\Translations\\Module', 'ready' ), 10, 2 );

// Runtime guard: core can disappear (FTP delete, failed update, CLI). Nothing runs and no data changes.
add_action(
	'plugins_loaded',
	static function () {
		if ( ! function_exists( 'cradle' ) || ! cradle() ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-warning"><p>' . esc_html__( 'Cradle Translations needs the Cradle core plugin. Translation overrides are paused and catalogs are untouched; WordPress shows its shipped translations until core is back.', 'cradle-translations' ) . '</p></div>';
					}
				}
			);
		}
	},
	20
);
