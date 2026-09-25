<?php
/**
 * Pre-enable health check: a loopback request to a candidate route that must return
 * the native login form served by this plugin.
 *
 * Mechanism: the administrator's request stores a short-lived pending probe
 * (candidate slug + SHA-256 of a random one-time token) in a transient and sends an
 * anonymous loopback GET to the candidate URL with the token in the
 * X-Cradle-Login-Probe header. The router in that loopback request serves the login
 * screen at the candidate slug only when the token hash matches, so the check
 * exercises the real web-server → WordPress → router → wp-login.php path before the
 * route is saved. The probe only renders the public login form; it grants nothing,
 * is not a bypass and expires after two minutes (and is deleted immediately after use).
 *
 * @package Cradle\Login
 */

namespace Cradle\Login;

defined( 'ABSPATH' ) || exit;

final class Health_Check {

	const HEADER = 'X-Cradle-Login-Probe';

	const SERVER_KEY = 'HTTP_X_CRADLE_LOGIN_PROBE';

	const TRANSIENT_PREFIX = 'cradle_login_probe_';

	const TTL = 120;

	/**
	 * Probes a candidate slug over HTTP.
	 *
	 * @return array{ok:bool,code:int,message:string,time:int,url:string}
	 */
	public static function probe( string $slug ): array {
		$result = array(
			'ok'      => false,
			'code'    => 0,
			'message' => '',
			'time'    => time(),
			'url'     => '',
		);
		if ( ! Config::is_valid_slug_format( $slug ) ) {
			$result['message'] = __( 'The slug format is invalid.', 'cradle-login' );
			return $result;
		}
		if ( ! Config::probe_allowed() ) {
			$result['message'] = __( 'Routing is switched off by the recovery constant, a filter, or multisite; the route cannot be tested.', 'cradle-login' );
			return $result;
		}

		$token = wp_generate_password( 40, false, false );
		$hash  = hash( 'sha256', $token );
		$key   = self::transient_key_for_hash( $hash );
		set_transient(
			$key,
			array(
				'slug' => $slug,
				'hash' => $hash,
			),
			self::TTL
		);

		$url           = Config::route_url( $slug );
		$result['url'] = $url;
		$headers       = array(
			self::HEADER    => $token,
			'Cache-Control' => 'no-cache',
		);
		// Like Site Health's loopback test: forward HTTP basic credentials of this same-site request.
		if ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) && is_string( $_SERVER['PHP_AUTH_USER'] ) && is_string( $_SERVER['PHP_AUTH_PW'] ) ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) . ':' . wp_unslash( $_SERVER['PHP_AUTH_PW'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$args = array(
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => $headers,
			'cookies'     => array(),
			/** This filter is documented in wp-includes/class-wp-http-streams.php */
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
		);

		$response = wp_remote_get( $url, $args );
		delete_transient( $key );

		if ( is_wp_error( $response ) ) {
			/* translators: %s: transport error */
			$result['message'] = sprintf( __( 'The site could not reach the new route (loopback request failed: %s).', 'cradle-login' ), $response->get_error_message() );
			return $result;
		}
		$code           = (int) wp_remote_retrieve_response_code( $response );
		$body           = (string) wp_remote_retrieve_body( $response );
		$result['code'] = $code;
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			$result['message'] = sprintf( __( 'The new route answered with HTTP %d instead of the login form. A server rule, cache, file or directory may be intercepting it.', 'cradle-login' ), $code );
			return $result;
		}
		if ( 'ok' !== wp_remote_retrieve_header( $response, self::HEADER ) ) {
			$result['message'] = __( 'The new route answered, but not through Cradle Login (a page cache or another component served it). Exclude the route from caching and try again.', 'cradle-login' );
			return $result;
		}
		if ( ! str_contains( $body, 'id="loginform"' ) ) {
			$result['message'] = __( 'The new route did not contain the WordPress login form.', 'cradle-login' );
			return $result;
		}
		if ( ! str_contains( $body, esc_url( Config::route_url( $slug, 'login_post' ) ) ) ) {
			$result['message'] = __( 'The login form at the new route does not post back to the route; another component rewrites login URLs.', 'cradle-login' );
			return $result;
		}
		$result['ok']      = true;
		$result['message'] = __( 'The route served the native login form.', 'cradle-login' );
		return $result;
	}

	/**
	 * Candidate slug of a pending probe matching a raw token, or ''.
	 */
	public static function pending_slug( string $token ): string {
		if ( ! preg_match( '/^[A-Za-z0-9]{40}$/', $token ) ) {
			return '';
		}
		$hash    = hash( 'sha256', $token );
		$pending = get_transient( self::transient_key_for_hash( $hash ) );
		if ( ! is_array( $pending ) || ! isset( $pending['hash'], $pending['slug'] ) || ! is_string( $pending['hash'] ) || ! is_string( $pending['slug'] ) ) {
			return '';
		}
		if ( ! hash_equals( $pending['hash'], $hash ) || ! Config::is_valid_slug_format( $pending['slug'] ) ) {
			return '';
		}
		return $pending['slug'];
	}

	private static function transient_key_for_hash( string $hash ): string {
		return self::TRANSIENT_PREFIX . substr( $hash, 0, 32 );
	}

	/**
	 * Stores the latest result (no token, no credentials) for the admin screen.
	 *
	 * @param array  $result  Probe result.
	 * @param string $context enable|change|manual.
	 */
	public static function record( array $result, string $context ) {
		update_option(
			Config::HEALTH_OPTION,
			array(
				'ok'      => ! empty( $result['ok'] ),
				'code'    => (int) ( $result['code'] ?? 0 ),
				'message' => (string) ( $result['message'] ?? '' ),
				'time'    => (int) ( $result['time'] ?? time() ),
				'context' => sanitize_key( $context ),
			),
			false
		);
		if ( function_exists( 'cradle_log_activity' ) ) {
			cradle_log_activity(
				'login',
				'health_check',
				! empty( $result['ok'] ) ? 'Login route health check passed.' : 'Login route health check failed.',
				array(
					'context' => sanitize_key( $context ),
					'code'    => (int) ( $result['code'] ?? 0 ),
				)
			);
		}
	}

	/**
	 * Latest stored result or an empty array.
	 */
	public static function last(): array {
		$last = get_option( Config::HEALTH_OPTION, array() );
		return is_array( $last ) ? $last : array();
	}
}
