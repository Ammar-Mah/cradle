<?php
/**
 * Request-level security helpers: keyed hashes, client identification, signed form tokens and
 * same-origin checks.
 *
 * Adapted from the Complaint Management System by WND (GPL-2.0-or-later), with its review
 * findings fixed: IPv4-mapped IPv6 addresses (`::ffff:a.b.c.d`) are unwrapped to the IPv4
 * address before hashing (they used to collapse into one /64 bucket shared by every IPv4
 * visitor), and the HMAC key comes from `wp_salt()` (wp-config.php), not from the database.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Security {

	/** Oldest form render accepted (cached pages may be served for a long time). */
	const TOKEN_MAX_AGE = 30 * DAY_IN_SECONDS;

	/**
	 * Domain-separated HMAC-SHA256 (hex). Rotating the WordPress salts invalidates open forms
	 * and resets rate-limit buckets; stored IP hashes are then no longer comparable.
	 */
	public static function hmac( string $data, string $context ): string {
		return hash_hmac( 'sha256', 'cradle-forms|' . $context . '|' . $data, wp_salt( 'secure_auth' ) );
	}

	/**
	 * Client IP: REMOTE_ADDR, adjustable for trusted reverse proxies with `cradle_forms/client_ip`.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the client IP used for rate limiting and the stored IP hash. Only read a header
		 * here (for example X-Forwarded-For) when a trusted proxy sets it.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'cradle_forms/client_ip', $ip );
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Network key: IPv4 address, or the /64 prefix of an IPv6 address (one customer allocation).
	 * IPv4-mapped (::ffff:a.b.c.d) and IPv4-compatible forms are unwrapped to the IPv4 address.
	 */
	public static function network_key( string $ip ): string {
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return 'invalid';
		}
		if ( 16 === strlen( $packed ) ) {
			$prefix = substr( $packed, 0, 12 );
			if ( str_repeat( "\0", 10 ) . "\xff\xff" === $prefix ) {
				return 'v4:' . inet_ntop( substr( $packed, 12 ) );
			}
			return 'v6:' . bin2hex( substr( $packed, 0, 8 ) );
		}
		return 'v4:' . inet_ntop( $packed );
	}

	/**
	 * Keyed hash of the client network; raw IP addresses are never stored.
	 */
	public static function client_hash(): string {
		return self::hmac( self::network_key( self::client_ip() ), 'ip' );
	}

	public static function user_agent_hash(): string {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 0, 500 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only hashed.
		return '' === $ua ? '' : self::hmac( $ua, 'ua' );
	}

	/**
	 * Signed render token: time, form, instance, source (the singular post the form was rendered
	 * on, 0 elsewhere), language and locale of the page. Format 2 (7 parts, HMAC context
	 * `form-token-2`); format 1 tokens (6 parts, no source) are
	 * still accepted with source 0 (pages cached before the source page was recorded).
	 */
	public static function form_token( int $form_id, int $instance, string $language, string $locale, int $source = 0 ): string {
		$payload = implode( '.', array( (string) time(), (string) $form_id, (string) $instance, (string) max( 0, $source ), self::token_part( $language ), self::token_part( $locale ) ) );
		return $payload . '.' . substr( self::hmac( $payload, 'form-token-2' ), 0, 40 );
	}

	private static function token_part( string $value ): string {
		return preg_match( '/^[A-Za-z0-9_-]{0,20}$/', $value ) ? $value : '';
	}

	/**
	 * @return array{time:int,form:int,instance:int,source:int,language:string,locale:string}|null
	 */
	public static function read_token( string $token ): ?array {
		$parts = explode( '.', $token );
		$count = count( $parts );
		if ( ( 6 !== $count && 7 !== $count ) || ! ctype_digit( $parts[0] ) || ! ctype_digit( $parts[1] ) || ! ctype_digit( $parts[2] ) ) {
			return null;
		}
		$payload = implode( '.', array_slice( $parts, 0, $count - 1 ) );
		$context = 7 === $count ? 'form-token-2' : 'form-token';
		if ( ! hash_equals( substr( self::hmac( $payload, $context ), 0, 40 ), (string) $parts[ $count - 1 ] ) ) {
			return null;
		}
		if ( 7 === $count ) {
			if ( ! ctype_digit( $parts[3] ) ) {
				return null;
			}
			$source = (int) $parts[3];
			$tail   = array( $parts[4], $parts[5] );
		} else {
			$source = 0; // Format 1: rendered before the source page was recorded.
			$tail   = array( $parts[3], $parts[4] );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{0,20}$/', $tail[0] ) || ! preg_match( '/^[A-Za-z0-9_-]{0,20}$/', $tail[1] ) ) {
			return null;
		}
		return array(
			'time'     => (int) $parts[0],
			'form'     => (int) $parts[1],
			'instance' => max( 1, (int) $parts[2] ),
			'source'   => $source,
			'language' => $tail[0],
			'locale'   => $tail[1],
		);
	}

	private static function same_site( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}
		$allowed = array( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ), strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ) );
		return in_array( $host, $allowed, true );
	}

	/**
	 * Browser evidence about where the request came from.
	 *
	 * @return string `same` (proven same origin), `cross` (proven foreign), `unknown` (no evidence).
	 */
	public static function request_origin(): string {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared with fixed values / parsed for the host only.
		$fetch_site = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) : '';
		$origin     = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : '';
		$referer    = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
		// phpcs:enable
		if ( '' !== $fetch_site ) {
			if ( 'same-origin' !== $fetch_site && 'none' !== $fetch_site ) {
				return 'cross';
			}
			if ( '' !== $origin && 'null' !== $origin && ! self::same_site( $origin ) ) {
				return 'cross';
			}
			return 'same';
		}
		if ( '' !== $origin && 'null' !== $origin ) {
			return self::same_site( $origin ) ? 'same' : 'cross';
		}
		if ( '' !== $referer ) {
			return self::same_site( $referer ) ? 'same' : 'cross';
		}
		return 'unknown';
	}
}
