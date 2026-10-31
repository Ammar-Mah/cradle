<?php
/**
 * Activation, deactivation and versioned updates. Uninstall lives in uninstall.php.
 *
 * Activation never creates content or settings rows: the language list is derived from the
 * site language until an administrator saves the Languages screen. No rewrite rules are added
 * (prefixes are stripped before WordPress parses the request), so nothing is flushed.
 * Deactivation keeps every setting, language assignment and translation group; all URLs are the
 * plain WordPress URLs again while the plugin is inactive.
 *
 * @package Cradle\Languages
 */

namespace Cradle\Languages;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {

	const VERSION_OPTION = 'cradle_languages_installed_version';

	/**
	 * @param bool $network_wide Whether network activation was requested.
	 */
	public static function activate( $network_wide = false ) {
		if ( class_exists( '\\Cradle\\Core\\Lifecycle' ) ) {
			\Cradle\Core\Lifecycle::guard_multisite( $network_wide, 'Cradle Languages' );
		} elseif ( is_multisite() && $network_wide ) {
			wp_die(
				esc_html__( 'Cradle Languages does not support network activation. Activate it on individual sites.', 'cradle-languages' ),
				esc_html__( 'Network activation is not supported', 'cradle-languages' ),
				array( 'back_link' => true )
			);
		}

		// Grant the capability once (administrators only) and remember the grant, so an
		// administrator's later removal is respected by core's one-time grant policy.
		$granted = get_option( 'cradle_granted_capabilities', array() );
		$granted = is_array( $granted ) ? $granted : array();
		if ( isset( $granted[ Module::CAP ] ) ) {
			// Granted before: respect a later removal.
		} elseif ( class_exists( '\\Cradle\\Core\\Capabilities' ) ) {
			\Cradle\Core\Capabilities::grant( Module::CAP, array( 'administrator' ), Module::ID );
		} else {
			$role = get_role( 'administrator' );
			if ( $role && ! $role->has_cap( Module::CAP ) ) {
				$role->add_cap( Module::CAP );
			}
			$granted[ Module::CAP ] = Module::ID;
			update_option( 'cradle_granted_capabilities', $granted, false );
		}
		self::maybe_update( true );
	}

	/**
	 * Releases this module's locks; keeps all data. No cron, no rewrite rules to remove.
	 */
	public static function deactivate() {
		$core = function_exists( 'cradle' ) ? cradle() : null;
		if ( $core ) {
			// Locks of this module are short-lived (translation creation); drop any left by a crashed request.
			$core->locks()->release_prefix( 'languages:' );
		}
		Languages::flush();
	}

	/**
	 * Versioned, idempotent updates. Runs on activation and on admin/CLI requests only.
	 *
	 * @param bool $force Run even on a frontend request (activation).
	 */
	public static function maybe_update( bool $force = false ) {
		if ( ! $force && ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$installed = (string) get_option( self::VERSION_OPTION, '' );
		if ( CRADLE_LANGUAGES_VERSION === $installed ) {
			return;
		}
		// 0.1.0 is the first schema: nothing to migrate yet. Future migrations go here, keyed by
		// version_compare( $installed, 'x.y.z', '<' ), each safe to run twice.
		update_option( self::VERSION_OPTION, CRADLE_LANGUAGES_VERSION, false );
	}
}
