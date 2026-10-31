<?php
/**
 * Menus per language (frontend only).
 *
 * - Classic menu locations: `theme_mod_nav_menu_locations` returns the menus configured for the
 *   current language over the default assignment (Cradle → Languages → Menus per language).
 * - Block themes: a Navigation block (`core/navigation` with `ref`) or synced pattern (`core/block`
 *   with `ref`) shows the published translation of its referenced entity in the current language
 *   when Navigation menus / patterns are translatable and a translation exists. Navigation links to
 *   entries (`kind` post-type / taxonomy with `id`) point to the entry's translation when one exists.
 *
 * @package Cradle\Languages
 */

namespace Cradle\Languages;

defined( 'ABSPATH' ) || exit;

final class Menus {

	public static function hooks() {
		if ( ! Request::is_frontend() ) {
			return;
		}
		add_filter( 'theme_mod_nav_menu_locations', array( __CLASS__, 'locations' ), 20 );
		add_filter( 'render_block_data', array( __CLASS__, 'block_data' ), 20 );
		// Links inside a Navigation block are rendered by the navigation block itself (no render_block_data).
		add_filter( 'render_block_core/navigation-link', array( __CLASS__, 'link_html' ), 20, 2 );
		add_filter( 'render_block_core/navigation-submenu', array( __CLASS__, 'link_html' ), 20, 2 );
	}

	/**
	 * @param mixed $locations Location => menu ID.
	 */
	public static function locations( $locations ) {
		$current = Languages::current();
		if ( $current['default'] || ! is_array( $locations ) ) {
			return $locations;
		}
		foreach ( Config::menu_locations( $current['slug'] ) as $location => $menu ) {
			$locations[ $location ] = $menu;
		}
		return $locations;
	}

	/**
	 * @param array $block Parsed block.
	 */
	public static function block_data( $block ) {
		if ( ! is_array( $block ) || ! Languages::is_multilingual() ) {
			return $block;
		}
		$name = $block['blockName'] ?? '';
		$slug = Languages::current_slug();
		if ( in_array( $name, array( 'core/navigation', 'core/block' ), true ) && ! empty( $block['attrs']['ref'] ) ) {
			$translation = Content::translation( (int) $block['attrs']['ref'], $slug, true );
			if ( $translation ) {
				$block['attrs']['ref'] = $translation;
			}
		}
		return $block;
	}

	/**
	 * Target of a navigation link to an entry or term in the current language ('' = keep).
	 *
	 * @param array $attrs Block attributes.
	 */
	public static function translated_url( array $attrs ): string {
		$id   = (int) ( $attrs['id'] ?? 0 );
		$kind = (string) ( $attrs['kind'] ?? '' );
		$slug = Languages::current_slug();
		if ( $id <= 0 ) {
			return '';
		}
		if ( 'post-type' === $kind ) {
			$translation = Content::translation( $id, $slug, true );
			return $translation && $translation !== $id ? (string) get_permalink( $translation ) : '';
		}
		if ( 'taxonomy' === $kind ) {
			$translation = Content::term_translation( $id, $slug );
			$link        = $translation && $translation !== $id ? get_term_link( $translation ) : '';
			return is_string( $link ) ? $link : '';
		}
		return '';
	}

	/**
	 * `render_block_core/navigation-link` / `-submenu`: point the link at the entry's translation.
	 *
	 * @param string $html  Rendered link.
	 * @param array  $block Parsed block.
	 */
	public static function link_html( $html, $block ) {
		if ( ! is_string( $html ) || '' === $html || ! Languages::is_multilingual() || ! is_array( $block ) ) {
			return $html;
		}
		$url = self::translated_url( (array) ( $block['attrs'] ?? array() ) );
		if ( '' === $url ) {
			return $html;
		}
		$p = new \WP_HTML_Tag_Processor( $html );
		if ( $p->next_tag( array( 'tag_name' => 'a' ) ) ) {
			$p->set_attribute( 'href', esc_url_raw( $url ) ); // The tag processor encodes the attribute value.
		}
		return $p->get_updated_html();
	}
}
