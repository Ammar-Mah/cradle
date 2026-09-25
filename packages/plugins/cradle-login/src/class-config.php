<?php
/**
 * Read-only access to the login routing configuration and route URL helpers.
 *
 * The option is read directly (not through the core Settings service) because the
 * router starts at `cradle/ready` (plugins_loaded), before settings sections are
 * registered in `boot`. Stored values are re-checked here; anything unexpected
 * falls back to the safe default (routing off).
 *
 * @package Cradle\Login
 */

namespace Cradle\Login;

defined( 'ABSPATH' ) || exit;

final class Config {

	const OPTION = 'cradle_login_settings';

	const HEALTH_OPTION = 'cradle_login_health';

	const CONSTANT = 'CRADLE_DISABLE_CUSTOM_LOGIN';

	/** 3–64 characters: lowercase letters, digits and inner hyphens. */
	const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{1,62}[a-z0-9]$/';

	const RESPONSES = array( 'not_found', 'plain_not_found', 'redirect_home' );

	public static function defaults(): array {
		return array(
			'enabled'          => false,
			'slug'             => '',
			'blocked_response' => 'not_found',
			'recovery_note'    => '',
		);
	}

	/**
	 * Stored values merged over defaults, each re-validated by type. Never writes.
	 */
	public static function values(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = self::defaults();

		$out['enabled'] = isset( $stored['enabled'] ) && true === $stored['enabled'];
		if ( isset( $stored['slug'] ) && is_string( $stored['slug'] ) && self::is_valid_slug_format( $stored['slug'] ) ) {
			$out['slug'] = $stored['slug'];
		}
		if ( isset( $stored['blocked_response'] ) && in_array( $stored['blocked_response'], self::RESPONSES, true ) ) {
			$out['blocked_response'] = $stored['blocked_response'];
		}
		if ( isset( $stored['recovery_note'] ) && is_string( $stored['recovery_note'] ) ) {
			$out['recovery_note'] = $stored['recovery_note'];
		}
		return $out;
	}

	public static function is_valid_slug_format( string $slug ): bool {
		return 1 === preg_match( self::SLUG_PATTERN, $slug );
	}

	/**
	 * Server-side emergency switch, read from trusted configuration (wp-config.php or an
	 * mu-plugin). Any truthy value disables routing entirely.
	 */
	public static function disabled_by_constant(): bool {
		return defined( self::CONSTANT ) && (bool) constant( self::CONSTANT );
	}

	/**
	 * Code-level switch for hosts and mu-plugins (documented in docs/recovery.md).
	 */
	public static function disabled_by_filter(): bool {
		/**
		 * Filters whether custom login routing is disabled regardless of the saved settings.
		 *
		 * @param bool $disabled Default false.
		 */
		return (bool) apply_filters( 'cradle_login_disable_routing', false );
	}

	/**
	 * Effective routing state.
	 *
	 * @return string One of active, disabled, no_slug, constant, filter, multisite.
	 */
	public static function status(): string {
		if ( self::disabled_by_constant() ) {
			return 'constant';
		}
		if ( is_multisite() ) {
			return 'multisite';
		}
		if ( self::disabled_by_filter() ) {
			return 'filter';
		}
		$values = self::values();
		if ( ! $values['enabled'] ) {
			return 'disabled';
		}
		if ( '' === $values['slug'] ) {
			return 'no_slug';
		}
		return 'active';
	}

	/**
	 * Whether a health probe may run in this environment (routing is not blocked by a switch).
	 */
	public static function probe_allowed(): bool {
		return in_array( self::status(), array( 'active', 'disabled', 'no_slug' ), true );
	}

	/**
	 * Home URL without trailing slash, from the option (respects WP_HOME) and never
	 * through the home_url filter, so generation and matching use the same base.
	 */
	public static function home(): string {
		return untrailingslashit( (string) get_option( 'home' ) );
	}

	/**
	 * Path of the home URL without trailing slash ('' for a root install, '/cradle/wp' for a subdirectory).
	 */
	public static function home_path(): string {
		$path = wp_parse_url( self::home(), PHP_URL_PATH );
		return is_string( $path ) ? untrailingslashit( $path ) : '';
	}

	/**
	 * Site (WordPress core) URL without trailing slash.
	 */
	public static function site(): string {
		return untrailingslashit( (string) get_option( 'siteurl' ) );
	}

	/**
	 * Plain permalinks and PATHINFO permalinks route through /index.php/<slug>/.
	 */
	public static function uses_index_prefix(): bool {
		$structure = (string) get_option( 'permalink_structure' );
		return '' === $structure || str_starts_with( ltrim( $structure, '/' ), 'index.php' );
	}

	/**
	 * Absolute URL of the login route for a slug.
	 *
	 * @param string $slug   Route slug.
	 * @param string $scheme Optional scheme passed to set_url_scheme() (http, https, login, login_post, relative).
	 */
	public static function route_url( string $slug, string $scheme = '' ): string {
		$url = self::home() . ( self::uses_index_prefix() ? '/index.php' : '' ) . '/' . $slug . '/';
		return '' === $scheme ? $url : set_url_scheme( $url, $scheme );
	}

	/**
	 * Root-relative path of the login route for a slug.
	 */
	public static function route_path( string $slug ): string {
		return self::home_path() . ( self::uses_index_prefix() ? '/index.php' : '' ) . '/' . $slug . '/';
	}

	/**
	 * URL of WordPress' own wp-login.php, computed without the site_url filter.
	 */
	public static function native_login_url( string $scheme = 'login' ): string {
		return set_url_scheme( self::site() . '/wp-login.php', $scheme );
	}

	/**
	 * Whether a raw REQUEST_URI addresses the route for $slug under the home path.
	 * Accepts /<slug>, /<slug>/ and /index.php/<slug>/ (any query string).
	 */
	public static function request_matches( string $request_uri, string $slug ): bool {
		if ( '' === $slug || '' === $request_uri ) {
			return false;
		}
		$path = explode( '#', explode( '?', $request_uri, 2 )[0], 2 )[0];
		$path = rawurldecode( $path );
		$path = (string) preg_replace( '#/+#', '/', '/' . $path );
		$base = self::home_path();
		if ( '' !== $base ) {
			if ( 0 !== stripos( $path, $base . '/' ) ) {
				return false;
			}
			$path = substr( $path, strlen( $base ) );
		}
		$path = trim( $path, '/' );
		if ( 0 === strpos( $path, 'index.php/' ) ) {
			$path = trim( substr( $path, 10 ), '/' );
		}
		return $path === $slug;
	}
}
