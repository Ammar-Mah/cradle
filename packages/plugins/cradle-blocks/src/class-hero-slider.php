<?php
/**
 * Data access and render service for cradle/hero-slider (render contract hero-slider-v2).
 *
 * Slides are published entries: either the latest entries of one viewable post type
 * (optionally one term of a public taxonomy, bounded to 12) or entries chosen in the
 * block, in the chosen order. Each slide shows the entry's featured image, title,
 * optional excerpt and an optional link button to the entry. Every style and behavior
 * option is an instance property set by the page editor; there are no global settings.
 *
 * Builder-neutral: the Gutenberg block, `[cradle_component id="cradle/hero-slider"]`
 * and `cradle_render_component()` all end here; WP_Block is never required.
 *
 * Contract (child overrides must keep these classes and IDs; the view script depends on them):
 * - Root: <section class="wp-block-cradle-hero-slider cradle-hero is-carousel has-transition-{t} is-placement-{p}"
 *   aria-roledescription="carousel" aria-label="…" data-cradle-hero="{json}"> for 2+ slides;
 *   <div class="wp-block-cradle-hero-slider cradle-hero …"> without carousel semantics for one slide.
 * - <div class="cradle-hero__controls" hidden> first (revealed by the view script): .cradle-hero__rotation,
 *   .cradle-hero__prev, .cradle-hero__dots > .cradle-hero__dot, .cradle-hero__next.
 * - <div class="cradle-hero__viewport"><div class="cradle-hero__slides" id="{uid}-slides" aria-live="polite|off">
 *   with .cradle-hero__slide#{uid}-slide-{n} (role=group, aria-roledescription=slide, .is-align-*, .is-width-*);
 *   every slide except the first carries `hidden`.
 * - A slide: optional .cradle-hero__media > img.cradle-hero__image, optional .cradle-hero__overlay, then
 *   .cradle-hero__inner > .cradle-hero__content with .cradle-hero__heading, .cradle-hero__text and
 *   .cradle-hero__actions > a.cradle-hero__button.is-primary.
 *
 * Dynamic (fallbackPolicy "none"): outputs nothing while Cradle Blocks is inactive.
 *
 * @package Cradle\Blocks
 */

namespace Cradle\Blocks;

defined( 'ABSPATH' ) || exit;

final class Hero_Slider {

	const BASE          = 'wp-block-cradle-hero-slider';
	const MAX_SLIDES    = 12;
	const VIEW_HANDLE   = 'cradle-hero-slider-view';
	const TOKEN_PATTERN = '/^[a-z0-9][a-z0-9-]{0,59}$/';

	/** Upper bound per length unit. */
	const UNIT_MAX = array(
		'px'  => 2000,
		'rem' => 125,
		'vh'  => 100,
		'svh' => 100,
		'dvh' => 100,
	);

	/** @var bool True once the first hero of this request was rendered (it alone gets fetchpriority=high). */
	private static $first_rendered = false;

	/**
	 * Drops values that must not reach the renderer. Runs after core normalization.
	 *
	 * @param array $p       Normalized properties.
	 * @param array $context Render context.
	 */
	public static function prepare( array $p, array $context = array() ): array {
		if ( ! self::viewable_type( $p['postType'] ) ) {
			$p['postType'] = '';
		}
		if ( '' !== $p['taxonomy'] ) {
			$tax = get_taxonomy( $p['taxonomy'] );
			if ( ! $tax || ! $tax->public || '' === $p['postType'] || ! is_object_in_taxonomy( $p['postType'], $p['taxonomy'] ) ) {
				$p['taxonomy'] = '';
				$p['term']     = '';
			}
		}
		// Any entry (even one core reset to 0 because its post is gone) means entries were chosen;
		// such a slider never falls back to showing the latest entries.
		$p['chosen'] = count( (array) $p['postIds'] ) > 0;
		$ids         = array();
		foreach ( (array) $p['postIds'] as $id ) {
			$id = (int) $id;
			if ( $id > 0 && '' !== $p['postType'] && get_post_type( $id ) === $p['postType'] && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		$p['postIds']         = array_slice( $ids, 0, self::MAX_SLIDES );
		$p['minHeight']       = self::parse_length( (string) $p['minHeight'] );
		$p['mobileMinHeight'] = self::parse_length( (string) $p['mobileMinHeight'] );
		foreach ( array( 'overlayColor', 'textColor', 'backgroundColor', 'controlsColor' ) as $key ) {
			if ( '' !== $p[ $key ] && ! preg_match( self::TOKEN_PATTERN, $p[ $key ] ) ) {
				$p[ $key ] = '';
			}
		}
		return $p;
	}

	public static function viewable_type( string $type ): bool {
		return '' !== $type && 'attachment' !== $type && post_type_exists( $type ) && is_post_type_viewable( $type );
	}

	/**
	 * `<number><px|rem|vh|svh|dvh>` within the unit's bound, or '' (use the default).
	 */
	public static function parse_length( string $raw ): string {
		$raw = strtolower( trim( $raw ) );
		if ( ! preg_match( '/^(\d{1,4}(?:\.\d{1,2})?)(px|rem|vh|svh|dvh)$/', $raw, $m ) || (float) $m[1] > self::UNIT_MAX[ $m[2] ] ) {
			return '';
		}
		return rtrim( rtrim( number_format( (float) $m[1], 2, '.', '' ), '0' ), '.' ) . $m[2];
	}

	/**
	 * Published, not password-protected entries for the slider.
	 *
	 * @return \WP_Post[]
	 */
	public static function query( array $p ): array {
		if ( '' === $p['postType'] || ( ! empty( $p['chosen'] ) && ! $p['postIds'] ) ) {
			return array();
		}
		$args = array(
			'post_type'              => $p['postType'],
			'post_status'            => 'publish',
			'has_password'           => false,
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
		);
		if ( $p['postIds'] ) {
			$args['post__in']       = $p['postIds'];
			$args['orderby']        = 'post__in';
			$args['posts_per_page'] = count( $p['postIds'] );
		} else {
			$args['orderby']        = $p['orderBy'];
			$args['order']          = 'asc' === $p['order'] ? 'ASC' : 'DESC';
			$args['posts_per_page'] = max( 1, min( self::MAX_SLIDES, (int) $p['count'] ) );
			if ( '' !== $p['taxonomy'] && '' !== $p['term'] ) {
				$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => $p['taxonomy'],
						'field'    => 'slug',
						'terms'    => $p['term'],
					),
				);
			}
		}
		$posts = ( new \WP_Query( $args ) )->posts;
		if ( $posts ) {
			$query        = new \WP_Query();
			$query->posts = $posts;
			update_post_thumbnail_cache( $query );
		}
		return $posts;
	}

	/**
	 * @param array $p         Normalized, prepared properties.
	 * @param array $context   Plain render context (instance_id is unique per request).
	 * @param array $component Component descriptor.
	 */
	public static function render( array $p, array $context = array(), array $component = array() ): string {
		$posts = self::query( $p );
		if ( ! $posts ) {
			return self::editor_notice( '' === $p['postType'] ? 'type' : ( ! empty( $p['chosen'] ) ? 'chosen' : 'none' ) );
		}

		$slides = array();
		foreach ( $posts as $post ) {
			$excerpt  = $p['showExcerpt'] ? wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post ) ), 30 ) : '';
			$slides[] = array(
				'heading' => wp_strip_all_tags( get_the_title( $post ) ),
				'text'    => $excerpt,
				'url'     => (string) get_permalink( $post ),
				'imageId' => (int) get_post_thumbnail_id( $post ),
			);
		}

		$uid      = self::instance_id( $context );
		$count    = count( $slides );
		$carousel = $count > 1;
		$autoplay = $carousel && $p['autoplay'];
		$priority = ! self::$first_rendered;

		self::$first_rendered = true;

		$label   = '' !== trim( $p['label'] ) ? $p['label'] : __( 'Featured content', 'cradle-blocks' );
		$classes = array( self::BASE, 'cradle-hero', 'has-transition-' . $p['transition'], 'is-placement-' . $p['textPlacement'] );
		if ( $carousel ) {
			$classes[] = 'is-carousel';
		}
		$style = sprintf(
			'--cradle-hero-min-height:%1$s;--cradle-hero-min-height-mobile:%2$s;--cradle-hero-duration:%3$dms;--cradle-hero-bg:%4$s;--cradle-hero-controls:%5$s',
			'' !== $p['minHeight'] ? $p['minHeight'] : '70vh',
			'' !== $p['mobileMinHeight'] ? $p['mobileMinHeight'] : '60svh',
			(int) $p['duration'],
			self::color_var( $p['backgroundColor'], '#111111' ),
			self::color_var( $p['controlsColor'], '#ffffff' )
		);

		$slides_html = '';
		foreach ( $slides as $index => $slide ) {
			$slides_html .= self::slide( $slide, $index, $count, $uid, $p, $carousel, $priority );
		}

		$slides_attrs = array(
			'class' => 'cradle-hero__slides',
			'id'    => $uid . '-slides',
		);
		if ( $carousel ) {
			$slides_attrs['aria-live'] = $autoplay ? 'off' : 'polite';
		}
		$body = '<div class="cradle-hero__viewport"><div' . self::attrs( $slides_attrs ) . '>' . $slides_html . '</div></div>';

		if ( ! $carousel ) {
			return '<div' . self::attrs(
				array(
					'class' => implode( ' ', $classes ),
					'style' => $style,
				)
			) . '>' . $body . '</div>';
		}

		$config = array(
			'autoplay'     => $autoplay,
			'interval'     => (int) $p['interval'],
			'duration'     => (int) $p['duration'],
			'transition'   => $p['transition'],
			'loop'         => (bool) $p['loop'],
			'swipe'        => (bool) $p['swipe'],
			'pauseOnHover' => (bool) $p['pauseOnHover'],
		);
		return '<section' . self::attrs(
			array(
				'class'                => implode( ' ', $classes ),
				'style'                => $style,
				'aria-roledescription' => __( 'carousel', 'cradle-blocks' ),
				'aria-label'           => $label,
				'data-cradle-hero'     => (string) wp_json_encode( $config ),
			)
		) . '>' . self::controls( $uid, $slides, $p, $autoplay ) . $body . '</section>';
	}

	private static function slide( array $s, int $index, int $count, string $uid, array $p, bool $carousel, bool $priority ): string {
		$classes = array( 'cradle-hero__slide', 'is-align-' . $p['contentAlign'], 'is-width-' . $p['contentWidth'] );
		if ( 0 === $index ) {
			$classes[] = 'is-active';
		}
		$attrs = array(
			'class' => implode( ' ', $classes ),
			'id'    => $uid . '-slide-' . ( $index + 1 ),
		);
		if ( $carousel ) {
			$attrs['role']                 = 'group';
			$attrs['aria-roledescription'] = __( 'slide', 'cradle-blocks' );
			/* translators: 1: slide number, 2: number of slides */
			$attrs['aria-label'] = sprintf( __( '%1$d of %2$d', 'cradle-blocks' ), $index + 1, $count );
		}
		$html  = '<div' . self::attrs( $attrs ) . ( $index > 0 ? ' hidden' : '' ) . '>';
		$media = self::media( $s['imageId'], $index, $priority, $p );
		$html .= $media;
		if ( '' !== $media && $p['overlayOpacity'] > 0 && '' !== $p['overlayColor'] ) {
			$html .= sprintf(
				'<div class="cradle-hero__overlay" aria-hidden="true" style="%s"></div>',
				esc_attr( 'background-color:' . self::color_var( $p['overlayColor'], '#000000' ) . ';opacity:' . ( (int) $p['overlayOpacity'] / 100 ) )
			);
		}

		$content = '';
		if ( '' !== $s['heading'] ) {
			$content .= sprintf( '<h%1$d class="cradle-hero__heading">%2$s</h%1$d>', (int) $p['headingLevel'], esc_html( $s['heading'] ) );
		}
		if ( '' !== $s['text'] ) {
			$content .= '<p class="cradle-hero__text">' . esc_html( $s['text'] ) . '</p>';
		}
		if ( $p['showButton'] && '' !== $s['url'] ) {
			$text     = '' !== trim( $p['buttonText'] ) ? $p['buttonText'] : __( 'Read more', 'cradle-blocks' );
			$content .= '<div class="cradle-hero__actions"><a class="cradle-hero__button is-primary wp-element-button" href="' . esc_url( $s['url'] ) . '">'
				. esc_html( $text ) . ( '' !== $s['heading'] ? '<span class="cradle-hero__sr">: ' . esc_html( $s['heading'] ) . '</span>' : '' ) . '</a></div>';
		}
		if ( '' !== $content ) {
			$html .= '<div class="cradle-hero__inner"><div class="cradle-hero__content" style="' . esc_attr( '--cradle-hero-text:' . self::color_var( $p['textColor'], '#ffffff' ) ) . '">' . $content . '</div></div>';
		}
		return $html . '</div>';
	}

	/**
	 * Featured image with dimensions, the media-library alternative text (empty when none: the
	 * slide heading carries the meaning), the block's focal point, and loading priorities: the
	 * first slide is never lazy, only the very first hero of the request gets fetchpriority=high.
	 */
	private static function media( int $image_id, int $index, bool $priority, array $p ): string {
		if ( ! $image_id ) {
			return '';
		}
		$attr = array(
			'class'    => 'cradle-hero__image',
			'alt'      => trim( wp_strip_all_tags( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ) ),
			'sizes'    => '100vw',
			'style'    => sprintf( 'object-position:%d%% %d%%', (int) $p['focalX'], (int) $p['focalY'] ),
			'decoding' => 'async',
		);
		if ( 0 === $index ) {
			$attr['loading']       = 'eager';
			$attr['fetchpriority'] = $priority ? 'high' : 'auto';
		} else {
			$attr['loading'] = 'lazy';
		}
		$img = wp_get_attachment_image( $image_id, 'full', false, $attr );
		return '' === $img ? '' : '<div class="cradle-hero__media">' . $img . '</div>';
	}

	private static function controls( string $uid, array $slides, array $p, bool $autoplay ): string {
		$slides_id  = $uid . '-slides';
		$count      = count( $slides );
		$arrows     = $p['arrows'] || ! $p['indicators']; // Keyboard users always need a way to change slides.
		$html       = '';
		if ( $autoplay ) {
			$pause = __( 'Pause slideshow', 'cradle-blocks' );
			$play  = __( 'Play slideshow', 'cradle-blocks' );
			$html .= sprintf(
				'<button type="button" class="cradle-hero__control cradle-hero__rotation" aria-controls="%1$s" data-label-pause="%2$s" data-label-play="%3$s">%4$s<span class="cradle-hero__sr">%2$s</span></button>',
				esc_attr( $slides_id ),
				esc_attr( $pause ),
				esc_attr( $play ),
				self::icon( 'pause' ) . self::icon( 'play' )
			);
		}
		if ( $arrows ) {
			$html .= sprintf(
				'<button type="button" class="cradle-hero__control cradle-hero__prev" aria-controls="%1$s">%2$s<span class="cradle-hero__sr">%3$s</span></button>',
				esc_attr( $slides_id ),
				self::icon( 'prev' ),
				esc_html__( 'Previous slide', 'cradle-blocks' )
			);
		}
		if ( $p['indicators'] ) {
			$dots = '';
			foreach ( $slides as $index => $slide ) {
				$text = '' !== $slide['heading']
					/* translators: 1: slide number, 2: number of slides, 3: slide heading */
					? sprintf( __( 'Show slide %1$d of %2$d: %3$s', 'cradle-blocks' ), $index + 1, $count, $slide['heading'] )
					/* translators: 1: slide number, 2: number of slides */
					: sprintf( __( 'Show slide %1$d of %2$d', 'cradle-blocks' ), $index + 1, $count );
				$dots .= sprintf(
					'<button type="button" class="cradle-hero__dot" aria-controls="%1$s" data-slide="%2$d"%3$s><span class="cradle-hero__sr">%4$s</span></button>',
					esc_attr( $uid . '-slide-' . ( $index + 1 ) ),
					(int) $index,
					0 === $index ? ' aria-current="true"' : '',
					esc_html( $text )
				);
			}
			$html .= '<div class="cradle-hero__dots" role="group" aria-label="' . esc_attr__( 'Choose a slide', 'cradle-blocks' ) . '">' . $dots . '</div>';
		}
		if ( $arrows ) {
			$html .= sprintf(
				'<button type="button" class="cradle-hero__control cradle-hero__next" aria-controls="%1$s">%2$s<span class="cradle-hero__sr">%3$s</span></button>',
				esc_attr( $slides_id ),
				self::icon( 'next' ),
				esc_html__( 'Next slide', 'cradle-blocks' )
			);
		}
		return '<div class="cradle-hero__controls" hidden>' . $html . '</div>';
	}

	private static function icon( string $name ): string {
		$paths = array(
			'prev'  => '<path d="M15 5l-7 7 7 7"/>',
			'next'  => '<path d="M9 5l7 7-7 7"/>',
			'pause' => '<path d="M9 5v14M15 5v14"/>',
			'play'  => '<path d="M8 5l11 7-11 7z"/>',
		);
		return '<svg class="cradle-hero__icon cradle-hero__icon--' . esc_attr( $name ) . '" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' . $paths[ $name ] . '</svg>';
	}

	/**
	 * Placeholder shown only inside the editor preview (REST block renderer) to content editors.
	 */
	private static function editor_notice( string $reason ): string {
		if ( ! function_exists( 'wp_is_serving_rest_request' ) || ! wp_is_serving_rest_request() || ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		$messages = array(
			'type'   => __( 'Choose a content type for the slides.', 'cradle-blocks' ),
			'none'   => __( 'There are no published entries to show yet. Publish entries of this content type (a featured image makes the best slide).', 'cradle-blocks' ),
			'chosen' => __( 'None of the chosen entries is published, so visitors see nothing here.', 'cradle-blocks' ),
		);
		return '<div class="cradle-hero-notice"><p>' . esc_html( $messages[ $reason ] ?? $messages['none'] ) . '</p></div>';
	}

	/**
	 * Unique per request (from the core component service); randomized inside
	 * REST previews so several editor previews never share IDs.
	 */
	private static function instance_id( array $context ): string {
		static $fallback = 0;
		$id = str_replace( 'cradle-cradle-', 'cradle-', (string) ( $context['instance_id'] ?? '' ) );
		if ( '' === $id ) {
			$id = 'cradle-hero-slider-x' . ( ++$fallback );
		}
		if ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) {
			$id .= '-' . strtolower( wp_generate_password( 4, false, false ) );
		}
		return sanitize_html_class( $id );
	}

	private static function color_var( string $token, string $fallback ): string {
		return preg_match( self::TOKEN_PATTERN, $token ) ? 'var(--wp--preset--color--' . $token . ', ' . $fallback . ')' : $fallback;
	}

	private static function attrs( array $attrs ): string {
		$out = '';
		foreach ( $attrs as $name => $value ) {
			$out .= ' ' . $name . '="' . esc_attr( (string) $value ) . '"';
		}
		return $out;
	}

	/**
	 * Serialized block markup for programmatic provisioning (dynamic block: self-closing, no saved HTML).
	 * Attributes equal to their defaults are omitted, like the editor does.
	 *
	 * @param array $attrs Block attributes.
	 */
	public static function block_markup( array $attrs ): string {
		$type     = \WP_Block_Type_Registry::get_instance()->get_registered( 'cradle/hero-slider' );
		$defaults = array();
		if ( $type ) {
			foreach ( $type->attributes as $name => $schema ) {
				if ( array_key_exists( 'default', $schema ) ) {
					$defaults[ $name ] = $schema['default'];
				}
			}
		}
		$out = array();
		foreach ( $attrs as $name => $value ) {
			if ( ! array_key_exists( $name, $defaults ) || $defaults[ $name ] !== $value ) {
				$out[ $name ] = $value;
			}
		}
		return '<!-- wp:cradle/hero-slider ' . ( $out ? serialize_block_attributes( $out ) . ' ' : '' ) . '/-->';
	}
}
