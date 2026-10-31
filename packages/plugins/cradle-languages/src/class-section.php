<?php
/**
 * Render service of `cradle/language-section` (render contract language-section-v1): the inner
 * blocks only when the current language is one of the selected languages. No wrapper element is
 * added, so the section is invisible in the page structure.
 *
 * Saved markup is the inner blocks themselves: without this plugin every language's section shows.
 *
 * @package Cradle\Languages
 */

namespace Cradle\Languages;

defined( 'ABSPATH' ) || exit;

final class Section {

	/**
	 * @param array $p         Normalized properties (`languages`: slugs; empty = every language).
	 * @param array $context   Render context with `inner_html`.
	 * @param array $component Descriptor.
	 */
	public static function render( array $p, array $context = array(), array $component = array() ): string {
		unset( $component );
		return self::visible( (array) ( $p['languages'] ?? array() ) ) ? (string) ( $context['inner_html'] ?? '' ) : '';
	}

	/**
	 * @param string[] $languages Selected language slugs.
	 */
	public static function visible( array $languages ): bool {
		$languages = array_values( array_filter( $languages, array( Languages::class, 'exists' ) ) );
		return ! $languages || in_array( Languages::current_slug(), $languages, true );
	}
}
