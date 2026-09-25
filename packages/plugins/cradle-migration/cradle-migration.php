<?php
/**
 * Plugin Name:       Cradle Migration
 * Description:       Full-site export, import, restore and move for single-site WordPress: open archive format, durable resumable jobs, journaled cutover and operator recovery.
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Requires Plugins:  cradle
 * Author:            Cradle
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cradle-migration
 * Domain Path:       /languages
 * Update URI:        false
 * Cradle Module:     migration
 *
 * @package Cradle\Migration
 */

defined( 'ABSPATH' ) || exit;

define( 'CRADLE_MIGRATION_VERSION', '0.1.0' );
define( 'CRADLE_MIGRATION_FILE', __FILE__ );
define( 'CRADLE_MIGRATION_DIR', __DIR__ . '/' );

spl_autoload_register(
	static function ( $class_name ) {
		$map = array(
			'Cradle\\Migration\\Admin\\' => CRADLE_MIGRATION_DIR . 'admin/',
			'Cradle\\Migration\\'        => CRADLE_MIGRATION_DIR . 'src/',
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

register_activation_hook( __FILE__, array( 'Cradle\\Migration\\Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Cradle\\Migration\\Lifecycle', 'deactivate' ) );

add_action( 'cradle/register_modules', array( 'Cradle\\Migration\\Module', 'register' ) );

// Runtime guard: core can disappear (FTP delete, failed update, CLI).
add_action(
	'plugins_loaded',
	static function () {
		if ( ! function_exists( 'cradle' ) || ! cradle() ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-warning"><p>' . esc_html__( 'Cradle Migration needs the Cradle core plugin. Export and import are paused; no data was changed. Host-level recovery remains available through bin/cradle-migrate.php.', 'cradle-migration' ) . '</p></div>';
					}
				}
			);
		}
	},
	20
);
