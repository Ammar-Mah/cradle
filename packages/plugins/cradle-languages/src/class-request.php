<?php
/**
 * Request language: detection from the URL prefix, prefix stripping around WordPress' own request
 * parsing, the frontend locale, the static front page / posts page per language and the redirect
 * of entries requested under another language's prefix.
 *
 * No rewrite rules are added. On `cradle/ready` (plugins_loaded) the raw REQUEST_URI is matched
 * against the configured prefixes; for a prefixed request `do_parse_request` swaps in the path
 * without the prefix, WordPress resolves it normally, and `parse_request` restores the original
 * (so redirect_canonical(), pagination and other code see the real URL). Unknown prefixes are not
 * stripped and end in WordPress' normal 404.
 *
 * Only frontend requests through index.php are routed: wp-admin, admin-ajax, admin-post, the REST
 * API, wp-login.php, XML-RPC, cron and CLI keep their native locale and URLs.
 *
 * @package Cradle\Languages
 */

namespace Cradle\Languages;

defined( 'ABSPATH' ) || exit;

final class Request {

	/** @var string Request language slug ('' outside routed frontend requests). */
	private static $slug = '';

	private static bool $frontend = false;

	/** Whether the URL carried a language prefix. */
	private static bool $prefixed = false;

	private static string $raw_uri = '';

	private static bool $parsing = false;

	/** @var array|null Saved $_SERVER values while WordPress parses the stripped path. */
	private static $saved = null;

	/** @var array<string,int> Front page / posts page translation memo. */
	private static $page_memo = array();

	public static function start() {
		self::$frontend = self::detect_frontend();
		if ( ! self::$frontend ) {
			return;
		}
		// Read during plugins_loaded, before wp_magic_quotes() slashes $_SERVER.
		self::$raw_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- matched against configured slugs only.
		list( $prefix ) = self::split( self::$raw_uri );
		$detected       = null !== $prefix ? $prefix : Languages::default_slug();

		/**
		 * Filters the language of a routed frontend request (before WordPress parses the URL).
		 * Must return a configured language slug; anything else is ignored. Attach it before
		 * `plugins_loaded` priority 5 (a must-use plugin), since the language is decided there.
		 *
		 * @param string $slug        Language slug detected from the URL prefix (default language when unprefixed).
		 * @param string $request_uri Raw request URI.
		 */
		$filtered       = apply_filters( 'cradle_languages/request_language', $detected, self::$raw_uri );
		self::$slug     = Languages::exists( $filtered ) ? $filtered : $detected;
		self::$prefixed = null !== $prefix;

		// Priority 5: WP_Locale_Switcher's own `locale` filter (10) still wins inside switch_to_locale().
		add_filter( 'locale', array( __CLASS__, 'filter_locale' ), 5 );
		add_filter( 'gettext_with_context_default', array( __CLASS__, 'text_direction' ), 20, 4 );
		add_filter( 'do_parse_request', array( __CLASS__, 'before_parse' ), PHP_INT_MAX );
		add_action( 'parse_request', array( __CLASS__, 'after_parse' ), PHP_INT_MIN );
		add_action( 'wp', array( __CLASS__, 'after_parse' ), PHP_INT_MIN );
		add_filter( 'option_page_on_front', array( __CLASS__, 'front_page_option' ), 20 );
		add_filter( 'option_page_for_posts', array( __CLASS__, 'front_page_option' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect' ), 1 );
	}

	public static function is_frontend(): bool {
		return self::$frontend;
	}

	public static function language_slug(): string {
		return self::$slug;
	}

	public static function is_prefixed(): bool {
		return self::$prefixed;
	}

	public static function is_parsing(): bool {
		return self::$parsing;
	}

	public static function raw_uri(): string {
		return self::$raw_uri;
	}

	private static function detect_frontend(): bool {
		if ( 'cli' === PHP_SAPI || ( defined( 'WP_CLI' ) && WP_CLI ) || is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only.
			return false;
		}
		$script = '';
		if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && is_string( $_SERVER['SCRIPT_FILENAME'] ) ) {
			$script = strtolower( wp_basename( str_replace( '\\', '/', $_SERVER['SCRIPT_FILENAME'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- basename compared only.
		}
		if ( '' !== $script && 'index.php' !== $script ) {
			return false;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
		$path = trim( Links::path_after_home( (string) explode( '?', $uri, 2 )[0] ), '/' );
		$rest = trim( (string) rest_get_url_prefix(), '/' );
		if ( '' !== $rest && ( $path === $rest || str_starts_with( $path, $rest . '/' ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Splits a request URI into its language prefix and the URI without it.
	 *
	 * @return array{0:?string,1:string} [slug or null, URI without the prefix]
	 */
	public static function split( string $uri ): array {
		$parts = explode( '?', $uri, 2 );
		$path  = $parts[0];
		$query = isset( $parts[1] ) ? '?' . $parts[1] : '';
		$base  = Links::home_path();
		if ( '' !== $base ) {
			if ( 0 !== strncasecmp( $path, $base, strlen( $base ) ) || ( strlen( $path ) > strlen( $base ) && '/' !== $path[ strlen( $base ) ] ) ) {
				return array( null, $uri );
			}
		}
		$head = substr( $path, 0, strlen( $base ) );
		$rest = (string) substr( $path, strlen( $base ) );
		$idx  = '';
		if ( preg_match( '#^/index\.php(?=/|$)#i', $rest ) ) {
			$idx  = substr( $rest, 0, 10 );
			$rest = (string) substr( $rest, 10 );
		}
		if ( ! preg_match( '#^/([a-z]{2,3}(?:-[a-z]{2})?)(/.*)?$#', $rest, $m ) || ! in_array( $m[1], Languages::prefixed_slugs(), true ) ) {
			return array( null, $uri );
		}
		$tail = isset( $m[2] ) && '' !== $m[2] ? $m[2] : '/';
		return array( $m[1], $head . $idx . $tail . $query );
	}

	/**
	 * `do_parse_request`: hand WordPress the path without the language prefix.
	 *
	 * @param bool $parse Whether WordPress parses the request.
	 */
	public static function before_parse( $parse ) {
		if ( ! $parse ) {
			return $parse;
		}
		// WordPress strips the home path it reads from home_url(): keep that URL unprefixed while it parses.
		self::$parsing = true;
		if ( ! self::$prefixed || null !== self::$saved ) {
			return $parse;
		}
		self::$saved = array(
			'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- restored verbatim.
			'PATH_INFO'   => $_SERVER['PATH_INFO'] ?? null, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- restored verbatim.
		);
		if ( is_string( self::$saved['REQUEST_URI'] ) ) {
			$_SERVER['REQUEST_URI'] = self::split( self::$saved['REQUEST_URI'] )[1];
		}
		if ( is_string( self::$saved['PATH_INFO'] ) && preg_match( '#^/' . preg_quote( self::$slug, '#' ) . '(/.*)?$#', self::$saved['PATH_INFO'], $m ) ) {
			$tail = $m[1] ?? '';
			if ( '' === $tail || '/' === $tail ) {
				unset( $_SERVER['PATH_INFO'] );
			} else {
				$_SERVER['PATH_INFO'] = $tail;
			}
		}
		return $parse;
	}

	/**
	 * `parse_request` (first callback) and `wp`: restore the original request URI.
	 */
	public static function after_parse() {
		self::$parsing = false;
		if ( null === self::$saved ) {
			return;
		}
		foreach ( self::$saved as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		self::$saved = null;
	}

	/**
	 * `locale` on routed frontend requests: the request language's locale.
	 *
	 * @param string $locale Site locale.
	 */
	public static function filter_locale( $locale ) {
		$lang = '' !== self::$slug ? Languages::get( self::$slug ) : null;
		return $lang ? $lang['locale'] : $locale;
	}

	/**
	 * Text direction follows the configured direction of the request language (WordPress reads it
	 * from the translation of `ltr` with context `text direction` when it builds WP_Locale).
	 */
	public static function text_direction( $translation, $text, $context, $domain ) {
		unset( $domain );
		if ( 'ltr' !== $text || 'text direction' !== $context || ( isset( $GLOBALS['wp_locale_switcher'] ) && is_locale_switched() ) ) {
			return $translation;
		}
		$lang = '' !== self::$slug ? Languages::get( self::$slug ) : null;
		return $lang ? $lang['dir'] : $translation;
	}

	/**
	 * `option_page_on_front` / `option_page_for_posts`: the translation in the request language.
	 *
	 * @param mixed $value Page ID.
	 */
	public static function front_page_option( $value ) {
		$id = (int) $value;
		if ( $id <= 0 || ! Languages::is_multilingual() || ! taxonomy_exists( Content::TAXONOMY ) ) {
			return $value;
		}
		$slug = Languages::current_slug();
		$key  = $id . ':' . $slug;
		if ( ! isset( self::$page_memo[ $key ] ) ) {
			$translation              = Content::translation( $id, $slug, true );
			self::$page_memo[ $key ] = $translation ? $translation : $id;
		}
		return (string) self::$page_memo[ $key ];
	}

	public static function flush_memo() {
		self::$page_memo = array();
	}

	/**
	 * `template_redirect` priority 1: 301 for an entry or term requested under another language's
	 * prefix, and for unprefixed requests when the default language shows its prefix.
	 */
	public static function redirect() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || is_preview() || is_feed() || is_robots() || is_404() || is_customize_preview() ) {
			return;
		}
		$target = '';
		$query  = $GLOBALS['wp_query'] ?? null;
		$object = get_queried_object();
		$slug   = Languages::current_slug();
		if ( ! is_front_page() && $object instanceof \WP_Post && ( is_singular() || ( $query instanceof \WP_Query && $query->is_posts_page ) ) ) {
			$lang = Content::post_language( $object );
			if ( $lang && $lang['slug'] !== $slug && Content::is_translatable_post_type( $object->post_type ) ) {
				$target = (string) get_permalink( $object );
			}
		} elseif ( $object instanceof \WP_Term && ( is_category() || is_tag() || is_tax() ) && Content::is_translatable_taxonomy( $object->taxonomy ) ) {
			$lang = Content::term_language( $object );
			if ( $lang && $lang['slug'] !== $slug ) {
				$link   = get_term_link( $object );
				$target = is_string( $link ) ? $link : '';
			}
		}
		if ( '' === $target && ! self::$prefixed && Languages::needs_prefix( Languages::current() ) ) {
			$target = Links::localize( self::current_url(), Languages::current() );
		}
		if ( '' === $target || self::same_url( $target, self::current_url() ) ) {
			return;
		}
		if ( wp_safe_redirect( $target, 301, 'Cradle Languages' ) ) {
			exit;
		}
	}

	/** Absolute URL of the original request. */
	public static function current_url(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return set_url_scheme( 'http://' . $host . self::$raw_uri );
	}

	private static function same_url( string $a, string $b ): bool {
		$norm = static function ( string $url ): string {
			$parts = wp_parse_url( $url );
			$path  = untrailingslashit( rawurldecode( (string) ( $parts['path'] ?? '' ) ) );
			return strtolower( (string) ( $parts['host'] ?? '' ) ) . $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		};
		return $norm( $a ) === $norm( $b );
	}
}
