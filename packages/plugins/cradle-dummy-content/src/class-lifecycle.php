<?php
/**
 * Activation and deactivation. Uninstall lives in uninstall.php.
 *
 * Activation never generates content. Deactivation keeps batch records,
 * generated content and its ownership markers so cleanup stays possible
 * after reactivation; it only releases this module's locks.
 *
 * @package Cradle\Dummy_Content
 */

namespace Cradle\Dummy_Content;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {

	const CAP_GENERATE = 'cradle_generate_dummy';
	const CAP_CLEANUP  = 'cradle_cleanup_dummy';

	/**
	 * @param bool $network_wide Whether network activation was requested.
	 */
	public static function activate( $network_wide = false ) {
		if ( class_exists( '\\Cradle\\Core\\Lifecycle' ) ) {
			\Cradle\Core\Lifecycle::guard_multisite( $network_wide, 'Cradle Dummy Content' );
		} elseif ( is_multisite() && $network_wide ) {
			wp_die(
				esc_html__( 'Cradle Dummy Content does not support network activation yet. Activate it on individual sites.', 'cradle-dummy-content' ),
				esc_html__( 'Network activation is not supported', 'cradle-dummy-content' ),
				array( 'back_link' => true )
			);
		}

		// Grant both capabilities to administrators once and remember the grant (core policy).
		$granted = get_option( 'cradle_granted_capabilities', array() );
		$granted = is_array( $granted ) ? $granted : array();
		$changed = false;
		foreach ( array( self::CAP_GENERATE, self::CAP_CLEANUP ) as $cap ) {
			if ( isset( $granted[ $cap ] ) ) {
				continue;
			}
			if ( class_exists( '\\Cradle\\Core\\Capabilities' ) ) {
				\Cradle\Core\Capabilities::grant( $cap, array( 'administrator' ), 'dummy-content' ); // Grants and records.
				continue;
			}
			$role = get_role( 'administrator' );
			if ( $role && ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
			$granted[ $cap ] = 'dummy-content';
			$changed         = true;
		}
		if ( $changed ) {
			update_option( 'cradle_granted_capabilities', $granted, false );
		}
		update_option( 'cradle_dummy_content_installed_version', CRADLE_DUMMY_CONTENT_VERSION, false );
	}

	public static function deactivate() {
		if ( function_exists( 'cradle' ) && cradle() ) {
			cradle()->locks()->release_prefix( 'dummy-content:' );
		}
	}
}
