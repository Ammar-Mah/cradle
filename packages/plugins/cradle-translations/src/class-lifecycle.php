<?php
/**
 * Activation and deactivation. Uninstall lives in uninstall.php (retain by default).
 *
 * @package Cradle\Translations
 */

namespace Cradle\Translations;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {

	/**
	 * @param bool $network_wide Whether network activation was requested.
	 */
	public static function activate( $network_wide = false ) {
		if ( class_exists( 'Cradle\\Core\\Lifecycle' ) ) {
			\Cradle\Core\Lifecycle::guard_multisite( $network_wide, 'Cradle Translations' );
		} elseif ( is_multisite() && $network_wide ) {
			wp_die(
				esc_html__( 'Cradle Translations does not support network activation. Activate it on individual sites.', 'cradle-translations' ),
				esc_html__( 'Network activation is not supported', 'cradle-translations' ),
				array( 'back_link' => true )
			);
		}

		// Grant once to administrators; a later removal by an administrator is respected.
		$granted = get_option( 'cradle_granted_capabilities', array() );
		$granted = is_array( $granted ) ? $granted : array();
		if ( ! isset( $granted[ Config::CAP ] ) ) {
			if ( class_exists( 'Cradle\\Core\\Capabilities' ) ) {
				// Core grants to the roles and records the grant with its owner.
				\Cradle\Core\Capabilities::grant( Config::CAP, array( 'administrator' ), Config::MODULE );
			} else {
				$role = get_role( 'administrator' );
				if ( $role && ! $role->has_cap( Config::CAP ) ) {
					$role->add_cap( Config::CAP );
				}
				$granted[ Config::CAP ] = Config::MODULE;
				update_option( 'cradle_granted_capabilities', $granted, false );
			}
		}
		update_option( 'cradle_translations_version', CRADLE_TRANSLATIONS_VERSION, false );
		// Built-in German and Arabic examples (core must be available for private storage and locks).
		if ( function_exists( 'cradle' ) && cradle() ) {
			Examples::maybe_install();
		}
	}

	/**
	 * Stops scheduled work and releases locks; catalogs, settings and history are kept.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Config::CRON_REFRESH );
		if ( function_exists( 'cradle' ) && cradle() ) {
			cradle()->locks()->release_prefix( Config::LOCK_PREFIX );
		}
	}
}
