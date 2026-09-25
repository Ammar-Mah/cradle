<?php
/**
 * Activation and deactivation. Uninstall lives in uninstall.php (retain by default).
 *
 * @package Cradle\Content
 */

namespace Cradle\Content;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {

	/**
	 * Refuses network activation, grants the module capability to administrators,
	 * creates the empty definitions store. Never creates content.
	 *
	 * @param bool $network_wide Whether network activation was requested.
	 */
	public static function activate( $network_wide = false ) {
		if ( class_exists( '\\Cradle\\Core\\Lifecycle' ) ) {
			\Cradle\Core\Lifecycle::guard_multisite( $network_wide, 'Cradle Content' );
		} elseif ( is_multisite() && $network_wide ) {
			wp_die( esc_html__( 'Cradle Content does not support network activation. Activate it on individual sites.', 'cradle-content' ), '', array( 'back_link' => true ) );
		}

		if ( class_exists( '\\Cradle\\Core\\Capabilities' ) ) {
			// Grant once; core records the grant so an administrator's later removal is respected.
			$granted = get_option( \Cradle\Core\Capabilities::GRANTED_OPTION, array() );
			if ( ! is_array( $granted ) || ! isset( $granted[ Module::CAP ] ) ) {
				\Cradle\Core\Capabilities::grant( Module::CAP, array( 'administrator' ), Module::ID );
			}
		}

		if ( false === get_option( Definitions::OPTION, false ) ) {
			add_option( Definitions::OPTION, Definitions::empty_store(), '', true );
		}
		// Forces one rewrite flush on the next request, after the definitions register again.
		delete_option( Registry::SIGNATURE_OPTION );
	}

	/**
	 * Stops scheduled work, releases locks and removes the routes; keeps every definition and all content.
	 */
	public static function deactivate() {
		wp_unschedule_hook( Migrations::CRON_HOOK ); // Events carry job UUID args; unschedule all of them.
		if ( function_exists( 'cradle' ) && cradle() ) {
			cradle()->locks()->release_prefix( 'content:' );
		}
		Registry::instance()->unregister_all();
		delete_option( Registry::SIGNATURE_OPTION );
		flush_rewrite_rules( false );
	}
}
