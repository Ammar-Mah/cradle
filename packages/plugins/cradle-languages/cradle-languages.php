<?php
/**
 * Plugin Name:       Cradle Languages
 * Description:       Multilingual content and routing: languages with /xx/ URL prefixes, linked translations of posts, pages and terms, a language switcher, per-language sections, hreflang and menus per language. Interface strings stay with Cradle Translations.
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Requires Plugins:  cradle
 * Author:            Cradle
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cradle-languages
 * Domain Path:       /languages
 * Update URI:        false
 * Cradle Module:     languages
 *
 * @package Cradle\Languages
 */

defined( 'ABSPATH' ) || exit;

define( 'CRADLE_LANGUAGES_VERSION', '0.1.0' );
define( 'CRADLE_LANGUAGES_FILE', __FILE__ );
define( 'CRADLE_LANGUAGES_DIR', __DIR__ . '/' );

spl_autoload_register(
	static function ( $class_name ) {
		$map = array(
			'Cradle\\Languages\\Admin\\' => CRADLE_LANGUAGES_DIR . 'admin/',
			'Cradle\\Languages\\'        => CRADLE_LANGUAGES_DIR . 'src/',
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

// Public procedural API (each function returns a safe empty value while the module is not ready).
require_once CRADLE_LANGUAGES_DIR . 'src/functions.php';

register_activation_hook( __FILE__, array( 'Cradle\\Languages\\Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Cradle\\Languages\\Lifecycle', 'deactivate' ) );

add_action( 'cradle/register_modules', array( 'Cradle\\Languages\\Module', 'register' ) );

// Request routing (language prefix, locale) must exist before `init`: attached on `cradle/ready`
// (plugins_loaded priority 5) once core reports the module ready. See docs/contract.md.
add_action( 'cradle/ready', array( 'Cradle\\Languages\\Module', 'ready' ), 10, 2 );

// Runtime guard: core can disappear (FTP delete, failed update, CLI). Without core nothing is
// routed or filtered, so every URL is the plain WordPress URL again; no data is changed.
add_action(
	'plugins_loaded',
	static function () {
		if ( ! function_exists( 'cradle' ) || ! cradle() ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-warning"><p>' . esc_html__( 'Cradle Languages needs the Cradle core plugin. Language routing is paused: every page is served at its plain address in the site language; no content or settings were changed.', 'cradle-languages' ) . '</p></div>';
					}
				}
			);
		}
	},
	20
);
