<?php
/**
 * Language prefixes in generated URLs.
 *
 * - `home_url` (routed frontend requests only): the current language's prefix, so archives,
 *   search, pagination, feeds and the home link stay in the language. Never for the REST API
 *   (`rest` scheme or the REST prefix), wp-admin/wp-login/wp-content/wp-includes, XML-RPC, cron,
 *   sitemaps, robots.txt or any *.php file.
 * - Entry and term links (everywhere, including wp-admin "View" links and REST `link` fields):
 *   the prefix of the object's own language; shared (untranslated) types use the current language.
 * - The front page and its translations link to their language's home URL.
 *
 * Conversion is idempotent: any existing language prefix is replaced, never stacked.
 *
 * @package Cradle\Languages
 */

namespace Cradle\Languages;

defined( 'ABSPATH' ) || exit;

final class Links {

	/** Paths (relative to home) that never get a language prefix. */
	const EXCLUDED_PATH = '#^/?(?:wp-|xmlrpc\.php|robots\.txt|favicon\.ico|sitemap(?:_index)?\.xml|[^?\#]*\.php(?:$|[?\#/]))#i';

	public static function hooks() {
		if ( Request::is_frontend() ) {
			add_filter( 'home_url', array( __CLASS__, 'home_url' ), 20, 3 );
		}
		add_filter( 'post_link', array( __CLASS__, 'post_link' ), 20, 2 );
		add_filter( 'post_type_link', array( __CLASS__, 'post_link' ), 20, 2 );
		add_filter( 'page_link', array( __CLASS__, 'page_link' ), 20, 2 );
		add_filter( 'attachment_link', array( __CLASS__, 'page_link' ), 20, 2 );
		add_filter( 'term_link', array( __CLASS__, 'term_link' ), 20, 2 );
		add_filter( 'url_to_postid', array( __CLASS__, 'url_to_postid' ), 20 );
	}

	/** Home URL from the option (respects WP_HOME), never through the home_url filter. */
	public static function home(): string {
		return untrailingslashit( (string) get_option( 'home' ) );
	}

	/** Path of the home URL without trailing slash ('' for a root install). */
	public static function home_path(): string {
		$path = wp_parse_url( self::home(), PHP_URL_PATH );
		return is_string( $path ) ? untrailingslashit( $path ) : '';
	}

	/** Plain and PATHINFO permalinks route through /index.php/<slug>/. */
	public static function uses_index_prefix(): bool {
		$structure = (string) get_option( 'permalink_structure' );
		return '' === $structure || str_starts_with( ltrim( $structure, '/' ), 'index.php' );
	}

	/**
	 * Request path relative to the home path ('' when the path is outside it).
	 */
	public static function path_after_home( string $path ): string {
		$base = self::home_path();
		if ( '' === $base ) {
			return $path;
		}
		if ( 0 !== strncasecmp( $path, $base, strlen( $base ) ) ) {
			return '';
		}
		return (string) substr( $path, strlen( $base ) );
	}

	/**
	 * Rewrites a URL on this site to carry $lang's prefix (or none). URLs elsewhere are returned unchanged.
	 *
	 * @param string     $url  Absolute URL.
	 * @param array|null $lang Language row; null keeps the URL unchanged.
	 */
	public static function localize( string $url, ?array $lang ): string {
		if ( null === $lang || '' === $url ) {
			return $url;
		}
		$home   = self::home();
		$scheme = '#^https?://#i';
		$bare   = static fn( string $u ): string => (string) preg_replace( $scheme, '', $u );
		if ( 0 !== strncasecmp( $bare( $url ), $bare( $home ), strlen( $bare( $home ) ) ) ) {
			return $url;
		}
		$offset = strlen( $url ) - strlen( $bare( $url ) ) + strlen( $bare( $home ) );
		$prefix = substr( $url, 0, $offset );
		$rest   = (string) substr( $url, $offset );
		if ( '' !== $rest && ! in_array( $rest[0], array( '/', '?', '#' ), true ) ) {
			return $url; // Another host or path sharing the same beginning (example.com vs example.com.au).
		}
		$suffix = '';
		$cut    = strcspn( $rest, '?#' );
		if ( $cut < strlen( $rest ) ) {
			$suffix = substr( $rest, $cut );
			$rest   = substr( $rest, 0, $cut );
		}
		if ( self::excluded( $rest ) ) {
			return $url;
		}
		$index = '';
		if ( preg_match( '#^/index\.php(?=/|$)#i', $rest ) ) {
			$index = substr( $rest, 0, 10 );
			$rest  = (string) substr( $rest, 10 );
		}
		// Drop an existing language prefix.
		if ( preg_match( '#^/([a-z]{2,3}(?:-[a-z]{2})?)(?=/|$)#', $rest, $m ) && Languages::exists( $m[1] ) ) {
			$rest = (string) substr( $rest, strlen( $m[0] ) );
		}
		if ( Languages::needs_prefix( $lang ) ) {
			if ( '' === $index && self::uses_index_prefix() ) {
				$index = '/index.php';
			}
			$rest = '/' . $lang['slug'] . ( '' === $rest ? '/' : $rest );
		} elseif ( '' !== $index && in_array( $rest, array( '', '/' ), true ) && '' === $suffix ) {
			$index = ''; // Plain home URL without a language: /index.php/ is not needed.
		}
		return $prefix . $index . $rest . $suffix;
	}

	/**
	 * `home_url` on routed frontend requests: the current language's prefix.
	 */
	public static function home_url( $url, $path = '', $orig_scheme = null ) {
		if ( ! is_string( $url ) || Request::is_parsing() || 'rest' === $orig_scheme || ! Languages::prefixed_slugs() ) {
			return $url;
		}
		$path = is_string( $path ) ? $path : '';
		$rest = trim( (string) rest_get_url_prefix(), '/' );
		$p    = ltrim( $path, '/' );
		if ( ( '' !== $rest && ( $p === $rest || str_starts_with( $p, $rest . '/' ) ) ) || str_contains( $p, 'rest_route=' ) || self::excluded( $p ) ) {
			return $url;
		}
		return self::localize( $url, Languages::current() );
	}

	/**
	 * Language whose prefix an entry's links carry: its own (translatable types) or the current one.
	 *
	 * @param \WP_Post|int $post Post.
	 */
	public static function language_for_post( $post ): array {
		$post = get_post( $post );
		if ( $post && Content::is_translatable_post_type( $post->post_type ) ) {
			$lang = Content::post_language( $post );
			if ( $lang ) {
				return $lang;
			}
		}
		return Languages::current();
	}

	/**
	 * `post_link` / `post_type_link`.
	 *
	 * @param string       $link URL.
	 * @param \WP_Post|int $post Post.
	 */
	public static function post_link( $link, $post ) {
		if ( ! is_string( $link ) || ! self::active() ) {
			return $link;
		}
		return self::localize( $link, self::language_for_post( $post ) );
	}

	/**
	 * `page_link` / `attachment_link` (receive an ID). The static front page and its
	 * translations link to their language's home URL.
	 *
	 * @param string $link    URL.
	 * @param int    $post_id Post ID.
	 */
	public static function page_link( $link, $post_id ) {
		if ( ! is_string( $link ) || ! self::active() ) {
			return $link;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $link;
		}
		$lang = self::language_for_post( $post );
		if ( 'page' === $post->post_type && 'page' === get_option( 'show_on_front' ) && Content::in_group_of( $post->ID, self::raw_option( 'page_on_front' ) ) ) {
			return self::language_home( $lang );
		}
		return self::localize( $link, $lang );
	}

	/**
	 * `term_link`.
	 *
	 * @param string   $link URL.
	 * @param \WP_Term $term Term.
	 */
	public static function term_link( $link, $term ) {
		if ( ! is_string( $link ) || ! self::active() || ! $term instanceof \WP_Term ) {
			return $link;
		}
		$lang = Content::is_translatable_taxonomy( $term->taxonomy ) ? Content::term_language( $term ) : null;
		return self::localize( $link, $lang ? $lang : Languages::current() );
	}

	/**
	 * `url_to_postid`: accept URLs of any language (WordPress compares against the current home URL).
	 *
	 * @param string $url URL.
	 */
	public static function url_to_postid( $url ) {
		if ( ! is_string( $url ) || ! self::active() ) {
			return $url;
		}
		return self::localize( $url, Languages::current() );
	}

	/** Whether a path relative to home must never carry a language prefix. */
	public static function excluded( string $path ): bool {
		$path = ltrim( $path, '/' );
		return 1 === preg_match( self::EXCLUDED_PATH, $path ) && 1 !== preg_match( '#^index\.php(?:/|$)#i', $path );
	}

	/** Home URL of a language (with a trailing slash). */
	public static function language_home( array $lang ): string {
		return self::localize( self::home() . '/', $lang );
	}

	/** A raw option value, bypassing this plugin's per-language option filters. */
	public static function raw_option( string $option ): int {
		$filter   = array( Request::class, 'front_page_option' );
		$priority = has_filter( 'option_' . $option, $filter );
		if ( false !== $priority ) {
			remove_filter( 'option_' . $option, $filter, $priority );
		}
		$value = (int) get_option( $option );
		if ( false !== $priority ) {
			add_filter( 'option_' . $option, $filter, $priority );
		}
		return $value;
	}

	private static function active(): bool {
		return Module::started() && taxonomy_exists( Content::TAXONOMY ) && ( Languages::is_multilingual() || Languages::prefixed_slugs() );
	}
}
