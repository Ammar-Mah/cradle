<?php
/**
 * Plugin Name:       Cradle Content
 * Description:       Custom post types, taxonomies and bounded field schemas managed through a first-party Cradle UI, with export/import, key-rename migrations and safe disable.
 * Version:           0.1.1
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Requires Plugins:  cradle
 * Author:            Cradle
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cradle-content
 * Domain Path:       /languages
 * Update URI:        false
 * Cradle Module:     content
 *
 * @package Cradle\Content
 */

defined( 'ABSPATH' ) || exit;

define( 'CRADLE_CONTENT_VERSION', '0.1.1' );
define( 'CRADLE_CONTENT_FILE', __FILE__ );
define( 'CRADLE_CONTENT_DIR', __DIR__ . '/' );

if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Cradle Content requires PHP 8.3 or newer. Its features are paused; no data was changed.', 'cradle-content' ) . '</p></div>';
			}
		}
	);
	return;
}

/*
 * Explicit namespace map: Cradle\Content\Admin\* → admin/, Cradle\Content\* → src/.
 * Only these two known directories are consulted; class names are strictly validated.
 */
spl_autoload_register(
	static function ( $class_name ) {
		$map = array(
			'Cradle\\Content\\Admin\\' => CRADLE_CONTENT_DIR . 'admin/',
			'Cradle\\Content\\'        => CRADLE_CONTENT_DIR . 'src/',
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

register_activation_hook( __FILE__, array( 'Cradle\\Content\\Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Cradle\\Content\\Lifecycle', 'deactivate' ) );

add_action( 'cradle/register_modules', array( 'Cradle\\Content\\Module', 'register' ) );

// Runtime guard: core can disappear (FTP delete, failed update, CLI). Content stays in the database.
add_action(
	'plugins_loaded',
	static function () {
		if ( ! function_exists( 'cradle' ) || ! cradle() ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-warning"><p>' . esc_html__( 'Cradle Content needs the Cradle core plugin. Custom post types, taxonomies and fields it manages are not registered right now, but all posts, terms and field values remain in the database. Reactivate Cradle to restore access.', 'cradle-content' ) . '</p></div>';
					}
				}
			);
		}
	},
	20
);
