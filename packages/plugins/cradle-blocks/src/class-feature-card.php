<?php
/**
 * Render service for cradle/feature-card (render contract feature-card-v1).
 *
 * Contract: root <article class="wp-block-cradle-feature-card is-variant-{variant} has-media-{mediaPosition}">
 * containing, in order and only when present: <figure class="…__media">, <h{level} class="…__title">,
 * <div class="…__body">, <a class="…__link">. Child overrides must keep these classes.
 *
 * @package Cradle\Blocks
 */

namespace Cradle\Blocks;

defined( 'ABSPATH' ) || exit;

final class Feature_Card {

	/**
	 * @param array $p         Normalized properties.
	 * @param array $context   Plain render context.
	 * @param array $component Component descriptor.
	 */
	public static function render( array $p, array $context = array(), array $component = array() ): string {
		$base    = 'wp-block-cradle-feature-card';
		$level   = max( 2, min( 6, (int) $p['headingLevel'] ) );
		$classes = array( $base, 'is-variant-' . sanitize_html_class( $p['variant'] ), 'has-media-' . sanitize_html_class( $p['mediaPosition'] ) );
		$parts   = array();

		if ( $p['imageId'] ) {
			$alt   = '' !== $p['imageAlt'] ? $p['imageAlt'] : (string) get_post_meta( $p['imageId'], '_wp_attachment_image_alt', true );
			$image = wp_get_attachment_image(
				$p['imageId'],
				'large',
				false,
				array(
					'alt'   => $alt,
					'class' => $base . '__image',
					'sizes' => '(max-width: 600px) 100vw, 600px',
				)
			);
			if ( $image ) {
				$parts[] = '<figure class="' . esc_attr( $base . '__media' ) . '">' . $image . '</figure>';
			}
		}

		$has_link = '' !== $p['link'];
		if ( '' !== $p['title'] ) {
			$title   = esc_html( $p['title'] );
			$title   = ( $p['titleLink'] && $has_link ) ? '<a href="' . esc_url( $p['link'] ) . '">' . $title . '</a>' : $title;
			$parts[] = sprintf( '<h%1$d class="%2$s">%3$s</h%1$d>', $level, esc_attr( $base . '__title' ), $title );
		}
		if ( '' !== $p['body'] ) {
			$parts[] = '<div class="' . esc_attr( $base . '__body' ) . '">' . wp_kses( $p['body'], \Cradle\Core\Components::limited_rich_text_tags() ) . '</div>';
		}
		if ( $has_link && '' !== $p['linkText'] && ! $p['titleLink'] ) {
			$parts[] = '<a class="' . esc_attr( $base . '__link' ) . '" href="' . esc_url( $p['link'] ) . '">' . esc_html( $p['linkText'] ) . '</a>';
		}

		if ( ! $parts ) {
			return '';
		}
		return '<article class="' . esc_attr( implode( ' ', $classes ) ) . '">' . implode( '', $parts ) . '</article>';
	}

	/**
	 * Serialized block markup equivalent to the editor's save() output, for
	 * programmatic content provisioning (see docs/provisioning.md).
	 *
	 * @param array $attrs Block attributes.
	 */
	public static function block_markup( array $attrs ): string {
		$defaults = array(
			'title'         => '',
			'body'          => '',
			'imageId'       => 0,
			'imageUrl'      => '',
			'imageAlt'      => '',
			'link'          => '',
			'linkText'      => '',
			'titleLink'     => false,
			'headingLevel'  => 3,
			'variant'       => 'plain',
			'mediaPosition' => 'top',
		);
		$a        = array_merge( $defaults, array_intersect_key( $attrs, $defaults ) );
		if ( $a['imageId'] && '' === $a['imageUrl'] ) {
			$src           = wp_get_attachment_image_src( (int) $a['imageId'], 'large' );
			$a['imageUrl'] = $src ? $src[0] : '';
		}
		$base  = 'wp-block-cradle-feature-card';
		$inner = '';
		if ( '' !== $a['imageUrl'] ) {
			$inner .= '<figure class="' . $base . '__media"><img src="' . esc_attr( $a['imageUrl'] ) . '" alt="' . esc_attr( $a['imageAlt'] ) . '"/></figure>';
		}
		if ( '' !== $a['title'] ) {
			$level  = max( 2, min( 6, (int) $a['headingLevel'] ) );
			$title  = esc_html( $a['title'] );
			$title  = ( $a['titleLink'] && '' !== $a['link'] ) ? '<a href="' . esc_attr( $a['link'] ) . '">' . $title . '</a>' : $title;
			$inner .= '<h' . $level . ' class="' . $base . '__title">' . $title . '</h' . $level . '>';
		}
		if ( '' !== $a['body'] ) {
			$inner .= '<div class="' . $base . '__body">' . $a['body'] . '</div>';
		}
		if ( '' !== $a['link'] && '' !== $a['linkText'] && ! $a['titleLink'] ) {
			$inner .= '<a class="' . $base . '__link" href="' . esc_attr( $a['link'] ) . '">' . esc_html( $a['linkText'] ) . '</a>';
		}
		$comment_attrs = array();
		foreach ( $a as $key => $value ) {
			if ( $value !== $defaults[ $key ] ) {
				$comment_attrs[ $key ] = $value;
			}
		}
		$json = $comment_attrs ? ' ' . serialize_block_attributes( $comment_attrs ) : '';
		return '<!-- wp:cradle/feature-card' . $json . ' -->' . "\n"
			. '<article class="' . $base . ' is-variant-' . esc_attr( $a['variant'] ) . ' has-media-' . esc_attr( $a['mediaPosition'] ) . '">' . $inner . '</article>' . "\n"
			. '<!-- /wp:cradle/feature-card -->';
	}
}
