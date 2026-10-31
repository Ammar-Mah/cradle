<?php
/**
 * hreflang alternates and language-aware core sitemaps.
 *
 * - `wp_head`: `<link rel="alternate" hreflang>` for every known language version of the page
 *   (published translations of an entry or term; the front page, archives and shared entries in
 *   every language) plus `x-default` (the default language), only when at least two exist.
 * - Core sitemaps list entries and terms of every language (each URL with its own prefix); core
 *   sitemaps never list drafts. Canonical URLs come from get_permalink(), which carries the prefix.
 *
 * @package Cradle\Languages
 */

namespace Cradle\Languages;

defined( 'ABSPATH' ) || exit;

final class Seo {

	public static function hooks() {
		add_action( 'wp_head', array( __CLASS__, 'hreflang' ), 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'all_languages' ) );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( __CLASS__, 'all_languages' ) );
	}

	/**
	 * @param array $args Query args.
	 */
	public static function all_languages( $args ) {
		if ( is_array( $args ) ) {
			$args[ Query::VAR ] = 'all';
		}
		return $args;
	}

	/** @return array<string,string> hreflang => URL. */
	public static function links(): array {
		if ( ! Config::hreflang() || ! Request::is_frontend() || ! Languages::is_multilingual() || is_404() || is_search() || is_feed() ) {
			return array();
		}
		$alternates = Switcher::alternates();
		if ( count( $alternates ) < 2 ) {
			return array();
		}
		$out = array();
		foreach ( $alternates as $slug => $url ) {
			$lang = Languages::get( $slug );
			if ( $lang ) {
				$out[ Languages::bcp47( $lang ) ] = $url;
			}
		}
		$default = Languages::default_slug();
		if ( isset( $alternates[ $default ] ) ) {
			$out['x-default'] = $alternates[ $default ];
		}
		return $out;
	}

	public static function hreflang() {
		foreach ( self::links() as $hreflang => $url ) {
			printf( '<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n", esc_attr( $hreflang ), esc_url( $url ) );
		}
	}
}
