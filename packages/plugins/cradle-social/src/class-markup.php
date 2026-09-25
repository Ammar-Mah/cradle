<?php
/**
 * Shared, escaped markup for both components (render contracts
 * social-links-v1 and share-links-v1).
 *
 * Contract (child overrides must keep these classes and the accessibility
 * behavior):
 *
 *   <nav|div class="cradle-social cradle-social--{profiles|share} is-display-{display}
 *        is-size-{size} is-spacing-{spacing} is-shape-{shape} is-justify-{justify}[ has-background]"
 *        [style="--cradle-social-color:…;--cradle-social-background:…"]
 *        aria-labelledby|aria-label [data-cradle-social …]>
 *     [<p class="cradle-social__label" id="…">Label</p>]
 *     <ul class="cradle-social__list" role="list">
 *       <li class="cradle-social__item cradle-social__item--{id}" [hidden]>
 *         <a|button class="cradle-social__link" …>{svg}<span class="cradle-social__text">…</span><span class="cradle-social__sr">…</span></a|button>
 *       </li>
 *     </ul>
 *     [<p class="cradle-social__status" role="status" aria-live="polite" aria-atomic="true"></p>]
 *   </nav|div>
 *
 * Every link's accessible name is the full action ("Share on X",
 * "Follow us on LinkedIn"), plus "(opens in a new tab)" when target=_blank.
 *
 * @package Cradle\Social
 */

namespace Cradle\Social;

defined( 'ABSPATH' ) || exit;

final class Markup {

	/**
	 * @param string $kind    `profiles` or `share`.
	 * @param array  $items   Items: id, icon, name (accessible), visible, and either href (+ new_tab) or action (+ data).
	 * @param array  $o       Options from Style::options().
	 * @param array  $context Render context (instance_id).
	 * @param array  $root    Extra root data attributes (already plain strings).
	 */
	public static function render( string $kind, array $items, array $o, array $context, array $root = array() ): string {
		if ( ! $items ) {
			return '';
		}
		$instance = sanitize_html_class( (string) ( $context['instance_id'] ?? '' ) );
		$instance = '' !== $instance ? $instance : 'cradle-social-' . wp_rand( 1000, 999999 );
		$label_id = $instance . '-label';

		$classes = array(
			'cradle-social',
			'cradle-social--' . $kind,
			'is-display-' . $o['display'],
			'is-size-' . $o['size'],
			'is-spacing-' . $o['spacing'],
			'is-shape-' . $o['shape'],
			'is-justify-' . $o['justify'],
		);
		$style = array();
		if ( '' !== $o['color'] ) {
			$style[] = '--cradle-social-color:' . $o['color'];
		}
		if ( '' !== $o['background'] && 'none' !== $o['shape'] ) {
			$style[]   = '--cradle-social-background:' . $o['background'];
			$classes[] = 'has-background';
		}

		$tag   = 'profiles' === $kind ? 'nav' : 'div';
		$attrs = ' class="' . esc_attr( implode( ' ', array_map( 'sanitize_html_class', $classes ) ) ) . '"';
		if ( $style ) {
			$attrs .= ' style="' . esc_attr( implode( ';', $style ) ) . '"';
		}
		if ( 'div' === $tag ) {
			$attrs .= ' role="group"';
		}
		$attrs .= $o['show_label'] ? ' aria-labelledby="' . esc_attr( $label_id ) . '"' : ' aria-label="' . esc_attr( $o['label'] ) . '"';
		foreach ( $root as $name => $value ) {
			if ( preg_match( '/^data-cradle-social(?:-[a-z]+)*$/', (string) $name ) ) {
				$attrs .= ' ' . $name . '="' . esc_attr( (string) $value ) . '"';
			}
		}

		$html = '<' . $tag . $attrs . '>';
		if ( $o['show_label'] ) {
			$html .= '<p class="cradle-social__label" id="' . esc_attr( $label_id ) . '">' . esc_html( $o['label'] ) . '</p>';
		}
		$html      .= '<ul class="cradle-social__list" role="list">';
		$has_action = false;
		foreach ( $items as $item ) {
			$html .= self::item( $item, $o );
			if ( isset( $item['action'] ) ) {
				$has_action = true;
			}
		}
		$html .= '</ul>';
		if ( $has_action ) {
			$html .= '<p class="cradle-social__status" role="status" aria-live="polite" aria-atomic="true"></p>';
		}
		return $html . '</' . $tag . '>';
	}

	private static function item( array $item, array $o ): string {
		$new_tab = ! empty( $item['new_tab'] ) && isset( $item['href'] ) && ! str_starts_with( (string) $item['href'], 'mailto:' );
		$name    = (string) $item['name'];
		$visible = 'action' === $o['label_style'] ? $name : (string) $item['visible'];
		$note    = $new_tab ? ' ' . __( '(opens in a new tab)', 'cradle-social' ) : '';

		$inner = '';
		if ( 'text' !== $o['display'] ) {
			$inner .= Icons::svg( (string) $item['icon'] );
		}
		if ( 'icon' === $o['display'] ) {
			$inner .= '<span class="cradle-social__sr">' . esc_html( $name . $note ) . '</span>';
		} elseif ( $visible === $name ) {
			$inner .= '<span class="cradle-social__text">' . esc_html( $visible ) . '</span>';
			$inner .= '' !== $note ? '<span class="cradle-social__sr">' . esc_html( $note ) . '</span>' : '';
		} else {
			// Visible short label (contained in the accessible name, WCAG 2.5.3) + full name for assistive tech.
			$inner .= '<span class="cradle-social__text" aria-hidden="true">' . esc_html( $visible ) . '</span>';
			$inner .= '<span class="cradle-social__sr">' . esc_html( $name . $note ) . '</span>';
		}

		$li = '<li class="cradle-social__item cradle-social__item--' . esc_attr( sanitize_html_class( (string) $item['id'] ) ) . '"' . ( isset( $item['action'] ) ? ' hidden' : '' ) . '>';
		if ( isset( $item['action'] ) ) {
			$data = '';
			foreach ( (array) ( $item['data'] ?? array() ) as $key => $value ) {
				if ( preg_match( '/^[a-z]+$/', (string) $key ) ) {
					$data .= ' data-cradle-social-' . $key . '="' . esc_attr( (string) $value ) . '"';
				}
			}
			return $li . '<button type="button" class="cradle-social__link cradle-social__action" data-cradle-social-action="' . esc_attr( (string) $item['action'] ) . '"' . $data . '>' . $inner . '</button></li>';
		}
		$protocols = array( 'https', 'mailto' );
		$href      = esc_url( (string) $item['href'], $protocols );
		if ( '' === $href ) {
			return '';
		}
		$rel    = trim( ( 'me' === ( $item['rel'] ?? '' ) ? 'me' : '' ) . ( $new_tab ? ' noopener noreferrer' : '' ) );
		$target = ( $new_tab ? ' target="_blank"' : '' ) . ( '' !== $rel ? ' rel="' . esc_attr( $rel ) . '"' : '' );
		return $li . '<a class="cradle-social__link" href="' . $href . '"' . $target . '>' . $inner . '</a></li>';
	}
}
