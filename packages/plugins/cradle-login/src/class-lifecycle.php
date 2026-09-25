<?php
/**
 * Activation and deactivation. Uninstall lives in uninstall.php.
 *
 * Activation: refuses network activation, grants the capability once to
 * administrators (remembered, like core), creates the default option (routing off).
 * Deactivation: nothing is scheduled, locked or rewritten, so there is nothing to
 * undo; settings are kept. While the plugin is inactive WordPress serves
 * wp-login.php natively — this is the documented recovery path.
 *
 * @package Cradle\Login
 */

namespace Cradle\Login;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {

	const RESUMED_NOTICE = 'cradle_login_resumed_notice';

	/**
	 * @param bool $network_wide Whether network activation was requested.
	 */
	public static function activate( $network_wide = false ) {
		if ( class_exists( '\\Cradle\\Core\\Lifecycle' ) ) {
			\Cradle\Core\Lifecycle::guard_multisite( (bool) $network_wide, 'Cradle Login' );
		} elseif ( is_multisite() && $network_wide ) {
			wp_die(
				esc_html__( 'Cradle Login does not support network activation.', 'cradle-login' ),
				esc_html__( 'Network activation is not supported', 'cradle-login' ),
				array( 'back_link' => true )
			);
		}

		self::grant_capability();

		$stored = get_option( Config::OPTION, false );
		if ( false === $stored ) {
			add_option( Config::OPTION, Config::defaults() + array( '_revision' => 0, '_schema' => 1 ), '', true );
		} elseif ( is_array( $stored ) && true === ( $stored['enabled'] ?? false ) ) {
			// Routing was enabled before deactivation and resumes now; tell administrators.
			set_transient( self::RESUMED_NOTICE, 1, DAY_IN_SECONDS );
		}

		if ( function_exists( 'cradle_log_activity' ) ) {
			cradle_log_activity( 'login', 'activated', 'Cradle Login activated.' );
		}
	}

	public static function deactivate() {
		delete_transient( self::RESUMED_NOTICE );
		if ( function_exists( 'cradle_log_activity' ) ) {
			cradle_log_activity( 'login', 'deactivated', 'Cradle Login deactivated; wp-login.php is served natively. Settings were kept.' );
		}
	}

	/**
	 * Grants cradle_manage_login to administrators once and records the grant so a later
	 * removal by an administrator is respected (core capability policy).
	 */
	private static function grant_capability() {
		$granted = get_option( 'cradle_granted_capabilities', array() );
		$granted = is_array( $granted ) ? $granted : array();
		if ( isset( $granted[ Module::CAP ] ) ) {
			return;
		}
		if ( class_exists( '\\Cradle\\Core\\Capabilities' ) ) {
			\Cradle\Core\Capabilities::grant( Module::CAP, array( 'administrator' ) );
		} else {
			$role = get_role( 'administrator' );
			if ( $role && ! $role->has_cap( Module::CAP ) ) {
				$role->add_cap( Module::CAP );
			}
		}
		$granted[ Module::CAP ] = Module::ID;
		update_option( 'cradle_granted_capabilities', $granted, false );
	}
}
