<?php
/**
 * Activation and deactivation. Uninstall lives in uninstall.php (retain by default).
 *
 * @package Cradle\Admin
 */

namespace Cradle\Admin;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {

	/**
	 * Refuses network activation, grants the branding capability to administrators once,
	 * and creates the non-autoloaded custom CSS options idempotently. Never generates content
	 * and never switches any white-label feature on.
	 *
	 * @param bool $network_wide Whether network activation was requested.
	 */
	public static function activate( $network_wide = false ) {
		if ( class_exists( 'Cradle\\Core\\Lifecycle' ) ) {
			\Cradle\Core\Lifecycle::guard_multisite( $network_wide, 'Cradle Admin' );
		} elseif ( is_multisite() && $network_wide ) {
			wp_die(
				esc_html__( 'Cradle Admin does not support network activation yet. Activate it on individual sites.', 'cradle-admin' ),
				esc_html__( 'Network activation is not supported', 'cradle-admin' ),
				array( 'back_link' => true )
			);
		}

		// Grant once and remember the grant, exactly like core's policy, so a later removal is respected.
		$granted = get_option( 'cradle_granted_capabilities', array() );
		$granted = is_array( $granted ) ? $granted : array();
		if ( ! isset( $granted[ Module::CAP ] ) ) {
			$role = get_role( 'administrator' );
			if ( $role && ! $role->has_cap( Module::CAP ) ) {
				$role->add_cap( Module::CAP );
			}
			$granted[ Module::CAP ] = Module::ID;
			update_option( 'cradle_granted_capabilities', $granted, false );
		}

		add_option( Custom_Css::OPTION, Custom_Css::empty_state(), '', false );
		add_option( Custom_Css::REVISIONS_OPTION, array(), '', false );
		update_option( 'cradle_admin_version', CRADLE_ADMIN_VERSION, false );
	}

	/**
	 * Nothing is scheduled, locked or routed by this plugin; all settings are kept.
	 * Deactivating immediately restores the native administration because every
	 * change is applied at runtime through hooks.
	 */
	public static function deactivate() {
		// Intentionally keeps data. Drafts expire on their own (10 minute transients).
	}
}
