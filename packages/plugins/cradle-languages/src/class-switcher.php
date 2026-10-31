<?php
/**
 * Language switcher: alternates of the current page per language and the render service of the
 * `cradle/language-switcher` component (render contract language-switcher-v1).
 *
 * Each item links to the published translation of what is shown (entry, posts page, term) or,
 * when there is none, to that language's home page (or is left out with `hideMissing`).
 * Front page, archives, search and shared entries exist in every language under its prefix.
 *
 * @package Cradle\Languages
 */

namespace Cradle\Languages;

defined( 'ABSPATH' ) || exit;

final class Switcher {

	/** @var array|null Per-request memo of alternates. */
	private static $alternates = null;

	public static function flush() {
		self::$alternates = null;
	}

	/**
	 * URL of the current page in each language where it is known: lang slug => URL.
	 *
	 * @return array<string,string>
	 */
	public static function alternates(): array {
		if ( null !== self::$alternates ) {
			return self::$alternates;
		}
		$out   = array();
		$all   = Languages::all();
		$query = $GLOBALS['wp_query'] ?? null;
		if ( ! $query instanceof \WP_Query || ! did_action( 'wp' ) ) {
			return array();
		}
		$object = get_queried_object();
		if ( is_front_page() ) {
			foreach ( $all as $slug => $lang ) {
				$out[ $slug ] = Links::language_home( $lang );
			}
		} elseif ( $object instanceof \WP_Post && ( is_singular() || $query->is_posts_page ) ) {
			if ( Content::is_translatable_post_type( $object->post_type ) ) {
				foreach ( Content::translations( $object->ID, true ) as $slug => $id ) {
					$out[ $slug ] = (string) get_permalink( $id );
				}
				if ( 'publish' !== $object->post_status && isset( $all[ Content::post_language_slug( $object ) ] ) ) {
					$out[ Content::post_language_slug( $object ) ] = (string) get_permalink( $object );
				}
			} else {
				$link = (string) get_permalink( $object );
				foreach ( $all as $slug => $lang ) {
					$out[ $slug ] = Links::localize( $link, $lang );
				}
			}
		} elseif ( $object instanceof \WP_Term && ( is_category() || is_tag() || is_tax() ) ) {
			if ( Content::is_translatable_taxonomy( $object->taxonomy ) ) {
				foreach ( Content::term_translations( $object->term_id ) as $slug => $id ) {
					$link = get_term_link( $id );
					if ( is_string( $link ) ) {
						$out[ $slug ] = $link;
					}
				}
			} else {
				$link = get_term_link( $object );
				foreach ( is_string( $link ) ? $all : array() as $slug => $lang ) {
					$out[ $slug ] = Links::localize( $link, $lang );
				}
			}
		} elseif ( is_search() ) {
			$link = get_search_link( get_search_query( false ) );
			foreach ( $all as $slug => $lang ) {
				$out[ $slug ] = Links::localize( $link, $lang );
			}
		} elseif ( is_home() || is_archive() ) {
			$current = Request::current_url();
			foreach ( $all as $slug => $lang ) {
				$out[ $slug ] = Links::localize( $current, $lang );
			}
		}
		self::$alternates = $out;
		return $out;
	}

	/**
	 * Switcher items for the current request.
	 *
	 * @param array $context { hideMissing?: bool, hideCurrent?: bool } plus render context.
	 * @return array<int,array{slug:string,name:string,native:string,locale:string,dir:string,hreflang:string,url:string,current:bool,missing:bool}>
	 */
	public static function items( array $context = array() ): array {
		$alternates = self::alternates();
		$current    = Languages::current_slug();
		$items      = array();
		foreach ( Languages::all() as $slug => $lang ) {
			$is_current = $slug === $current;
			$missing    = ! isset( $alternates[ $slug ] );
			if ( ( $missing && ! $is_current && ! empty( $context['hideMissing'] ) ) || ( $is_current && ! empty( $context['hideCurrent'] ) ) ) {
				continue;
			}
			$url = $missing ? Links::language_home( $lang ) : $alternates[ $slug ];
			if ( $is_current && $missing ) {
				$url = Request::is_frontend() ? Request::current_url() : $url;
			}
			$items[] = array(
				'slug'     => $slug,
				'name'     => $lang['name'],
				'native'   => $lang['native'],
				'locale'   => $lang['locale'],
				'dir'      => $lang['dir'],
				'hreflang' => Languages::bcp47( $lang ),
				'url'      => $url,
				'current'  => $is_current,
				'missing'  => $missing && ! $is_current,
			);
		}
		/**
		 * Filters the language switcher items.
		 *
		 * @param array $items   Items (slug, name, native, locale, dir, hreflang, url, current, missing).
		 * @param array $context Switcher options and render context.
		 */
		$filtered = apply_filters( 'cradle_languages/switcher_items', $items, $context );
		return is_array( $filtered ) ? array_values( array_filter( $filtered, static fn( $item ) => is_array( $item ) && isset( $item['url'], $item['slug'] ) ) ) : $items;
	}

	/**
	 * Render service (component `cradle/language-switcher`).
	 *
	 * @param array $p         Normalized properties.
	 * @param array $context   Render context.
	 * @param array $component Descriptor.
	 */
	public static function render( array $p, array $context = array(), array $component = array() ): string {
		unset( $component );
		if ( ! Languages::is_multilingual() ) {
			return '';
		}
		$items = self::items(
			$context + array(
				'hideMissing' => ! empty( $p['hideMissing'] ),
				'hideCurrent' => ! empty( $p['hideCurrent'] ),
			)
		);
		if ( ! $items ) {
			return '';
		}
		wp_enqueue_style( Module::STYLE_HANDLE );
		$layout  = 'dropdown' === ( $p['layout'] ?? '' ) ? 'dropdown' : 'list';
		$display = in_array( $p['display'] ?? '', array( 'native', 'name', 'slug', 'native_slug' ), true ) ? $p['display'] : 'native';
		$label   = '' !== trim( (string) ( $p['label'] ?? '' ) ) ? trim( (string) $p['label'] ) : __( 'Languages', 'cradle-languages' );
		$list    = '<ul class="cradle-language-switcher__list">';
		$active  = null;
		foreach ( $items as $item ) {
			$text = self::text( $item, $display );
			if ( $item['current'] ) {
				$active = $item;
			}
			$classes = 'cradle-language-switcher__item' . ( $item['current'] ? ' is-current' : '' ) . ( ! empty( $item['missing'] ) ? ' is-missing' : '' );
			$list   .= sprintf(
				'<li class="%1$s"><a class="cradle-language-switcher__link" href="%2$s" hreflang="%3$s" lang="%3$s" dir="%4$s"%5$s>%6$s%7$s</a></li>',
				esc_attr( $classes ),
				esc_url( $item['url'] ),
				esc_attr( $item['hreflang'] ),
				esc_attr( 'rtl' === $item['dir'] ? 'rtl' : 'ltr' ),
				$item['current'] ? ' aria-current="page"' : '',
				esc_html( $text ),
				! empty( $item['missing'] ) ? '<span class="cradle-language-switcher__sr"> ' . esc_html__( '(home page, this page is not translated)', 'cradle-languages' ) . '</span>' : ''
			);
		}
		$list .= '</ul>';
		if ( 'dropdown' === $layout ) {
			$summary = $active ? self::text( $active, $display ) : $label;
			$inner   = '<details class="cradle-language-switcher__details"><summary class="cradle-language-switcher__summary"><span class="cradle-language-switcher__sr">' . esc_html( $label ) . ': </span>' . esc_html( $summary ) . '</summary>' . $list . '</details>';
		} else {
			$inner = $list;
		}
		return '<nav class="' . esc_attr( 'cradle-language-switcher cradle-language-switcher--' . $layout ) . '" aria-label="' . esc_attr( $label ) . '">' . $inner . '</nav>';
	}

	private static function text( array $item, string $display ): string {
		switch ( $display ) {
			case 'name':
				return (string) $item['name'];
			case 'slug':
				return strtoupper( (string) $item['slug'] );
			case 'native_slug':
				return $item['native'] . ' (' . strtoupper( (string) $item['slug'] ) . ')';
		}
		return (string) $item['native'];
	}
}
