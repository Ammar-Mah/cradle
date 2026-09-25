<?php
/**
 * Activation and deactivation. Uninstall lives in uninstall.php.
 *
 * Activation never creates content or settings rows (defaults are computed
 * on read). Deactivation keeps every setting; saved blocks then render
 * nothing because both components are dynamic (see docs/social.md).
 *
 * @package Cradle\Social
 */

namespace Cradle\Social;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {

	const CAPABILITY = 'cradle_manage_social';

	/**
	 * @param bool $network_wide Whether network activation was requested.
	 */
	public static function activate( $network_wide = false ) {
		if ( class_exists( '\\Cradle\\Core\\Lifecycle' ) ) {
			\Cradle\Core\Lifecycle::guard_multisite( $network_wide, 'Cradle Social' );
		} elseif ( is_multisite() && $network_wide ) {
			wp_die(
				esc_html__( 'Cradle Social does not support network activation yet. Activate it on individual sites.', 'cradle-social' ),
				esc_html__( 'Network activation is not supported', 'cradle-social' ),
				array( 'back_link' => true )
			);
		}

		// Grant the settings capability once (administrators only) and remember the grant,
		// so an administrator's later removal is respected by core's grant policy.
		$granted = get_option( 'cradle_granted_capabilities', array() );
		$granted = is_array( $granted ) ? $granted : array();
		if ( isset( $granted[ self::CAPABILITY ] ) ) {
			// Already granted once: respect an administrator's later removal.
		} elseif ( class_exists( '\\Cradle\\Core\\Capabilities' ) ) {
			\Cradle\Core\Capabilities::grant( self::CAPABILITY, array( 'administrator' ), Module::ID ); // Grants and records.
		} else {
			$role = get_role( 'administrator' );
			if ( $role && ! $role->has_cap( self::CAPABILITY ) ) {
				$role->add_cap( self::CAPABILITY );
			}
			$granted[ self::CAPABILITY ] = Module::ID;
			update_option( 'cradle_granted_capabilities', $granted, false );
		}
		update_option( 'cradle_social_installed_version', CRADLE_SOCIAL_VERSION, false );
	}

	/**
	 * Nothing is scheduled, locked or routed; settings and saved blocks are kept.
	 */
	public static function deactivate() {
		if ( class_exists( __NAMESPACE__ . '\\Providers' ) ) {
			Providers::flush();
		}
	}
}
