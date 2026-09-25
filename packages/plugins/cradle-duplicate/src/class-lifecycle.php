<?php
/**
 * Activation and deactivation. Uninstall lives in uninstall.php.
 *
 * Activation never duplicates or creates content. Deactivation keeps every
 * setting and every draft that was created; it only releases runtime locks.
 *
 * @package Cradle\Duplicate
 */

namespace Cradle\Duplicate;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {

	const CAPABILITY = 'cradle_manage_duplicate';

	/**
	 * @param bool $network_wide Whether network activation was requested.
	 */
	public static function activate( $network_wide = false ) {
		if ( class_exists( '\\Cradle\\Core\\Lifecycle' ) ) {
			\Cradle\Core\Lifecycle::guard_multisite( $network_wide, 'Cradle Duplicate' );
		} elseif ( is_multisite() && $network_wide ) {
			wp_die(
				esc_html__( 'Cradle Duplicate does not support network activation yet. Activate it on individual sites.', 'cradle-duplicate' ),
				esc_html__( 'Network activation is not supported', 'cradle-duplicate' ),
				array( 'back_link' => true )
			);
		}

		// Grant the settings capability once (administrators only) and remember the grant,
		// so an administrator's later removal is respected by core's grant policy.
		$granted = get_option( 'cradle_granted_capabilities', array() );
		$granted = is_array( $granted ) ? $granted : array();
		if ( ! isset( $granted[ self::CAPABILITY ] ) ) {
			if ( class_exists( '\\Cradle\\Core\\Capabilities' ) ) {
				\Cradle\Core\Capabilities::grant( self::CAPABILITY, array( 'administrator' ), 'duplicate' ); // Grants and records.
			} else {
				$role = get_role( 'administrator' );
				if ( $role && ! $role->has_cap( self::CAPABILITY ) ) {
					$role->add_cap( self::CAPABILITY );
				}
				$granted[ self::CAPABILITY ] = 'duplicate';
				update_option( 'cradle_granted_capabilities', $granted, false );
			}
		}
		update_option( 'cradle_duplicate_installed_version', CRADLE_DUPLICATE_VERSION, false );
	}

	public static function deactivate() {
		if ( function_exists( 'cradle' ) && cradle() ) {
			cradle()->locks()->release_prefix( 'duplicate:' );
		}
	}
}
