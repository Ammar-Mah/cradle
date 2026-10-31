<?php
/**
 * Activation, deactivation and versioned updates. Uninstall lives in uninstall.php.
 *
 * Activation creates the tables, grants the four capabilities once (administrators) and schedules
 * the daily retention run. It never creates forms, pages or entries. Deactivation unschedules the
 * cron hook and releases locks; forms, entries, files and settings are kept.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {

	const VERSION_OPTION = 'cradle_forms_installed_version';

	/**
	 * @param bool $network_wide Whether network activation was requested.
	 */
	public static function activate( $network_wide = false ) {
		if ( class_exists( '\\Cradle\\Core\\Lifecycle' ) ) {
			\Cradle\Core\Lifecycle::guard_multisite( $network_wide, 'Cradle Forms' );
		} elseif ( is_multisite() && $network_wide ) {
			wp_die(
				esc_html__( 'Cradle Forms does not support network activation yet. Activate it on individual sites.', 'cradle-forms' ),
				esc_html__( 'Network activation is not supported', 'cradle-forms' ),
				array( 'back_link' => true )
			);
		}
		Schema::install();
		self::grant_capabilities();
		Retention::schedule();
		update_option( self::VERSION_OPTION, CRADLE_FORMS_VERSION, false );
	}

	public static function deactivate() {
		Retention::unschedule();
		if ( function_exists( 'cradle' ) && cradle() ) {
			cradle()->locks()->release_prefix( 'forms:' );
		}
	}

	/**
	 * Grants each capability once and records the grant, so a later removal by an administrator stands.
	 */
	public static function grant_capabilities() {
		foreach ( Caps::definitions() as $cap => $definition ) {
			if ( class_exists( '\\Cradle\\Core\\Capabilities' ) ) {
				\Cradle\Core\Capabilities::grant( $cap, $definition['roles'], Module::ID );
				continue;
			}
			$granted = get_option( 'cradle_granted_capabilities', array() );
			$granted = is_array( $granted ) ? $granted : array();
			if ( isset( $granted[ $cap ] ) ) {
				continue;
			}
			foreach ( $definition['roles'] as $role_name ) {
				$role = get_role( $role_name );
				if ( $role && ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
			$granted[ $cap ] = Module::ID;
			update_option( 'cradle_granted_capabilities', $granted, false );
		}
	}

	/**
	 * Admin/CLI requests only: applies table upgrades and restores the cron schedule after an update.
	 */
	public static function maybe_upgrade() {
		Schema::maybe_upgrade();
		if ( get_option( self::VERSION_OPTION ) !== CRADLE_FORMS_VERSION ) {
			update_option( self::VERSION_OPTION, CRADLE_FORMS_VERSION, false );
		}
		Retention::schedule();
	}
}
