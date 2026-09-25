<?php
/**
 * Activation and deactivation. Data (archives, job records, backups) is retained.
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {

	const CAPS = array( 'cradle_export_site', 'cradle_import_site' );

	/**
	 * @param bool $network_wide Whether network activation was requested.
	 */
	public static function activate( $network_wide = false ) {
		if ( class_exists( 'Cradle\\Core\\Lifecycle' ) ) {
			\Cradle\Core\Lifecycle::guard_multisite( $network_wide, 'Cradle Migration' );
		} elseif ( is_multisite() && $network_wide ) {
			wp_die( esc_html__( 'Cradle Migration does not support network activation.', 'cradle-migration' ) );
		}
		if ( class_exists( 'Cradle\\Core\\Capabilities' ) ) {
			foreach ( self::CAPS as $cap ) {
				// Core records the grant so a later removal by an administrator is respected.
				\Cradle\Core\Capabilities::grant( $cap, array( 'administrator' ), 'migration' );
			}
		}
	}

	public static function deactivate() {
		if ( function_exists( 'cradle' ) && cradle() ) {
			cradle()->locks()->release_prefix( 'migration:' );
		}
		// A write-quiescence window must never outlive the plugin that enforces and clears it.
		delete_option( Guard::QUIESCE_OPTION );
	}
}
