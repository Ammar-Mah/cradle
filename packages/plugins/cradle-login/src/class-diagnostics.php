<?php
/**
 * Compatibility diagnostics for the Cradle → Login screen. Admin-only, read-only.
 *
 * @package Cradle\Login
 */

namespace Cradle\Login;

defined( 'ABSPATH' ) || exit;

final class Diagnostics {

	/** Plugins that also change or conceal the login URL. */
	const KNOWN_CONFLICTS = array(
		'wps-hide-login/wps-hide-login.php'                     => 'WPS Hide Login',
		'rename-wp-login/rename-wp-login.php'                   => 'Rename wp-login.php',
		'all-in-one-wp-security-and-firewall/wp-security.php'   => 'All-In-One Security',
		'better-wp-security/better-wp-security.php'             => 'Solid Security',
		'wp-hide-security-enhancer/wp-hide.php'                 => 'WP Hide & Security Enhancer',
		'perfmatters/perfmatters.php'                           => 'Perfmatters',
		'defender-security/wp-defender.php'                     => 'Defender',
		'custom-login-url/custom-login-url.php'                 => 'Custom Login URL',
		'change-wp-admin-login/change-wp-admin-login.php'       => 'Change wp-admin login',
	);

	/**
	 * Rows of [label, level (ok|warn|bad|info), text].
	 *
	 * @return array<int,array{0:string,1:string,2:string}>
	 */
	public static function rows(): array {
		$rows   = array();
		$values = Config::values();

		$structure = (string) get_option( 'permalink_structure' );
		if ( '' === $structure ) {
			$rows[] = array( __( 'Permalinks', 'cradle-login' ), 'warn', __( 'Plain permalinks: the route is served at /index.php/<slug>/ and depends on the server passing PATH_INFO to PHP. The health check verifies it before enabling.', 'cradle-login' ) );
		} elseif ( Config::uses_index_prefix() ) {
			$rows[] = array( __( 'Permalinks', 'cradle-login' ), 'info', __( 'PATHINFO permalinks: the route is served at /index.php/<slug>/.', 'cradle-login' ) );
		} else {
			$rows[] = array( __( 'Permalinks', 'cradle-login' ), 'ok', __( 'Pretty permalinks: the route is served at /<slug>/ through the front controller. No rewrite rules are added or flushed.', 'cradle-login' ) );
		}

		$home_path = Config::home_path();
		$rows[]    = array(
			__( 'Install location', 'cradle-login' ),
			'info',
			'' === $home_path
				? __( 'Site root.', 'cradle-login' )
				/* translators: %s: URL path */
				: sprintf( __( 'Subdirectory install (%s); the route is relative to the home URL.', 'cradle-login' ), $home_path )
		);
		if ( Config::home() !== Config::site() ) {
			$rows[] = array( __( 'Home and WordPress address', 'cradle-login' ), 'info', __( 'The home URL differs from the WordPress address; the route lives under the home URL while wp-login.php stays under the WordPress address.', 'cradle-login' ) );
		}

		$https = 'https' === wp_parse_url( Config::home(), PHP_URL_SCHEME ) || force_ssl_admin();
		$rows[] = array(
			__( 'HTTPS', 'cradle-login' ),
			$https ? 'ok' : ( 'production' === wp_get_environment_type() ? 'bad' : 'warn' ),
			$https ? __( 'Login pages use HTTPS.', 'cradle-login' ) : __( 'Login pages are served over plain HTTP, which exposes credentials in transit. HTTPS is provided by hosting, not by Cradle Login.', 'cradle-login' )
		);

		$rows[] = array(
			__( 'Registration', 'cradle-login' ),
			'info',
			get_option( 'users_can_register' ) ? __( 'Open: the register action is served at the route.', 'cradle-login' ) : __( 'Closed: register requests at the route answer "registration disabled", as WordPress does.', 'cradle-login' )
		);

		$rows[] = array(
			__( 'WooCommerce', 'cradle-login' ),
			'info',
			class_exists( 'WooCommerce' )
				? __( 'Active. My Account login, registration and lost-password forms are handled by WooCommerce on its own pages and are not affected; its account and checkout endpoints are reserved slugs.', 'cradle-login' )
				: __( 'Not active.', 'cradle-login' )
		);

		if ( is_multisite() ) {
			$rows[] = array( __( 'Multisite', 'cradle-login' ), 'bad', __( 'Multisite networks are not supported; routing stays off.', 'cradle-login' ) );
		}

		if ( Config::disabled_by_constant() ) {
			/* translators: %s: constant name */
			$rows[] = array( __( 'Recovery switch', 'cradle-login' ), 'warn', sprintf( __( '%s is set: routing is off. Remove it from the server configuration once the route is fixed.', 'cradle-login' ), Config::CONSTANT ) );
		}
		if ( Config::disabled_by_filter() ) {
			$rows[] = array( __( 'Code switch', 'cradle-login' ), 'warn', __( 'The cradle_login_disable_routing filter returns true: routing is off.', 'cradle-login' ) );
		}

		$active_plugins = (array) get_option( 'active_plugins', array() );
		$conflicts      = array();
		foreach ( self::KNOWN_CONFLICTS as $basename => $name ) {
			if ( in_array( $basename, $active_plugins, true ) ) {
				$conflicts[] = $name;
			}
		}
		if ( $conflicts ) {
			/* translators: %s: plugin names */
			$rows[] = array( __( 'Other login-URL plugins', 'cradle-login' ), 'bad', sprintf( __( 'Active: %s. Use only one plugin that changes the login URL.', 'cradle-login' ), implode( ', ', $conflicts ) ) );
		}
		$foreign = array_merge( self::foreign_callbacks( 'login_url' ), self::foreign_callbacks( 'site_url' ) );
		$rows[]  = array(
			__( 'Other login URL filters', 'cradle-login' ),
			$foreign ? 'warn' : 'ok',
			$foreign
				/* translators: %s: callback names */
				? sprintf( __( 'Other code also filters login or site URLs: %s. Check that it does not rewrite wp-login.php links differently.', 'cradle-login' ), implode( ', ', array_unique( $foreign ) ) )
				: __( 'No other code filters login_url or site_url.', 'cradle-login' )
		);

		if ( defined( 'WP_CACHE' ) && WP_CACHE && is_file( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
			$rows[] = array( __( 'Page cache', 'cradle-login' ), 'warn', __( 'A full-page cache drop-in is active. Exclude the login route from caching (WordPress already sends no-cache headers on login pages).', 'cradle-login' ) );
		}

		if ( '' !== $values['slug'] ) {
			$check = Slug_Policy::check( $values['slug'] );
			$rows[] = array(
				__( 'Saved slug', 'cradle-login' ),
				is_wp_error( $check ) ? 'bad' : 'ok',
				is_wp_error( $check )
					/* translators: %s: collision description */
					? sprintf( __( 'Now collides: %s While the route is active, that address shows the login page.', 'cradle-login' ), $check->get_error_message() )
					: __( 'No collisions with current content, routes or files.', 'cradle-login' )
			);
		}

		$rows[] = array(
			__( 'Unaffected requests', 'cradle-login' ),
			'ok',
			__( 'admin-ajax.php, admin-post.php, REST API (including application passwords), XML-RPC, wp-cron.php and WP-CLI are never blocked or redirected.', 'cradle-login' )
		);
		return $rows;
	}

	/**
	 * Human names of callbacks on a hook that are not Cradle Login's.
	 *
	 * @return string[]
	 */
	public static function foreign_callbacks( string $hook ): array {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
			return array();
		}
		$out = array();
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'] ?? null;
				if ( is_array( $fn ) && isset( $fn[0] ) && $fn[0] instanceof Router ) {
					continue;
				}
				$out[] = self::describe_callable( $fn );
			}
		}
		return $out;
	}

	/**
	 * @param mixed $fn Callable.
	 */
	private static function describe_callable( $fn ): string {
		if ( is_string( $fn ) ) {
			return $fn;
		}
		if ( is_array( $fn ) && isset( $fn[0], $fn[1] ) && is_string( $fn[1] ) ) {
			return ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) . '::' . $fn[1];
		}
		if ( $fn instanceof \Closure ) {
			$ref = new \ReflectionFunction( $fn );
			return 'closure in ' . wp_basename( (string) $ref->getFileName() );
		}
		return is_object( $fn ) ? get_class( $fn ) : 'callback';
	}
}
