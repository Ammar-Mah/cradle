<?php
/**
 * Activation and deactivation of the core plugin. Uninstall lives in uninstall.php.
 *
 * @package Cradle
 */

namespace Cradle\Core;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {

	/**
	 * @param bool $network_wide Whether network activation was requested.
	 */
	public static function activate( $network_wide = false ) {
		self::guard_multisite( $network_wide, 'Cradle' );
		Schema::install();
		foreach ( array( 'cradle_manage_settings', 'cradle_view_diagnostics' ) as $cap ) {
			Capabilities::grant( $cap, array( 'administrator' ), 'core' );
		}
		if ( ! wp_next_scheduled( Activity::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Activity::CRON_HOOK );
		}
		update_option( 'cradle_installed_version', CRADLE_VERSION, false );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( Activity::CRON_HOOK );
		if ( function_exists( 'cradle' ) && cradle() ) {
			cradle()->locks()->release_prefix( 'core:' );
		}
	}

	/**
	 * Refuses network-wide activation until multisite is an explicit, tested milestone.
	 * Shared by add-ons that load core.
	 */
	public static function guard_multisite( $network_wide, string $name ) {
		if ( is_multisite() && $network_wide ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: plugin name */
						__( '%s does not support network activation yet. Multisite is a separate compatibility milestone; activate it on individual single sites only after that support is released.', 'cradle' ),
						$name
					)
				),
				esc_html__( 'Network activation is not supported', 'cradle' ),
				array( 'back_link' => true )
			);
		}
	}
}
