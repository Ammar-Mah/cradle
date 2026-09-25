<?php
/**
 * Plugin Name:       Cradle
 * Description:       Central Cradle services: module registry, settings schemas, capabilities, components, jobs, storage, diagnostics.
 * Version:           0.1.1
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Author:            Cradle
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cradle
 * Domain Path:       /languages
 * Update URI:        false
 *
 * @package Cradle
 */

defined( 'ABSPATH' ) || exit;

define( 'CRADLE_VERSION', '0.1.1' );
define( 'CRADLE_API_VERSION', '1.0.0' );
define( 'CRADLE_FILE', __FILE__ );
define( 'CRADLE_DIR', __DIR__ . '/' );
define( 'CRADLE_MIN_PHP', '8.3' );

if ( version_compare( PHP_VERSION, CRADLE_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Cradle requires PHP 8.3 or newer. Cradle services are not running.', 'cradle' ) . '</p></div>';
			}
		}
	);
	return;
}

require_once CRADLE_DIR . 'src/functions.php';

spl_autoload_register( 'cradle_core_autoload' );

register_activation_hook( __FILE__, array( 'Cradle\\Core\\Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Cradle\\Core\\Lifecycle', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Cradle\\Core\\Plugin', 'boot' ), Cradle\Core\Plugin::BOOT_PRIORITY );
