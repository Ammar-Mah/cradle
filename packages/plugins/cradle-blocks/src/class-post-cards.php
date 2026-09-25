<?php
/**
 * Data access and render service for cradle/post-cards (render contract post-cards-v1).
 *
 * Data layer: bounded WP_Query over published, non-password-protected entries
 * of a viewable post type, optionally filtered by one term of a public taxonomy
 * attached to that type. Thumbnails are primed in one batch.
 * Presentation: a list whose items are rendered through cradle/feature-card.
 *
 * This component is dynamic (fallbackPolicy "none"): if Cradle Blocks is
 * deactivated the block outputs nothing. Convert instances first when removing.
 *
 * @package Cradle\Blocks
 */

namespace Cradle\Blocks;

defined( 'ABSPATH' ) || exit;

final class Post_Cards {

	/**
	 * Drops query parameters that point at non-public data. Runs before render.
	 */
	public static function prepare( array $p, array $context = array() ): array {
		if ( ! post_type_exists( $p['postType'] ) || ! is_post_type_viewable( $p['postType'] ) || 'attachment' === $p['postType'] ) {
			$p['postType'] = '';
		}
		if ( '' !== $p['taxonomy'] ) {
			$tax = get_taxonomy( $p['taxonomy'] );
			if ( ! $tax || ! $tax->public || '' === $p['postType'] || ! is_object_in_taxonomy( $p['postType'], $p['taxonomy'] ) ) {
				$p['taxonomy'] = '';
				$p['term']     = '';
			}
		}
		return $p;
	}

	/**
	 * @return \WP_Post[]
	 */
	public static function query( array $p ): array {
		if ( '' === $p['postType'] ) {
			return array();
		}
		$args = array(
			'post_type'              => $p['postType'],
			'post_status'            => 'publish',
			'posts_per_page'         => max( 1, min( 24, (int) $p['count'] ) ),
			'orderby'                => $p['orderBy'],
			'order'                  => 'asc' === $p['order'] ? 'ASC' : 'DESC',
			'has_password'           => false,
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
		);
		if ( '' !== $p['taxonomy'] && '' !== $p['term'] ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => $p['taxonomy'],
					'field'    => 'slug',
					'terms'    => $p['term'],
				),
			);
		}
		$posts = ( new \WP_Query( $args ) )->posts;
		if ( $p['showImage'] && $posts ) {
			update_post_thumbnail_cache( self::thumbnail_query( $posts ) );
		}
		return $posts;
	}

	private static function thumbnail_query( array $posts ): \WP_Query {
		$query        = new \WP_Query();
		$query->posts = $posts;
		return $query;
	}

	public static function render( array $p, array $context = array(), array $component = array() ): string {
		$posts   = self::query( $p );
		$columns = max( 1, min( 4, (int) $p['columns'] ) );
		$classes = 'wp-block-cradle-post-cards has-' . $columns . '-columns';
		if ( ! $posts ) {
			$message = '' !== $p['emptyMessage'] ? $p['emptyMessage'] : __( 'Nothing to show yet.', 'cradle-blocks' );
			return '<div class="' . esc_attr( $classes . ' is-empty' ) . '"><p class="wp-block-cradle-post-cards__empty">' . esc_html( $message ) . '</p></div>';
		}
		$items = '';
		foreach ( $posts as $post ) {
			$card   = cradle_render_component(
				'cradle/feature-card',
				array(
					'title'        => wp_strip_all_tags( get_the_title( $post ) ),
					'body'         => $p['showExcerpt'] ? '<p>' . esc_html( wp_strip_all_tags( get_the_excerpt( $post ) ) ) . '</p>' : '',
					'imageId'      => $p['showImage'] ? (int) get_post_thumbnail_id( $post ) : 0,
					'link'         => (string) get_permalink( $post ),
					'titleLink'    => true,
					'headingLevel' => (int) $p['headingLevel'],
					'variant'      => $p['variant'],
				),
				array(
					'adapter'    => $context['adapter'] ?? 'php',
					'post_id'    => (int) $post->ID,
					'entity_key' => 'post:' . $post->ID,
				)
			);
			$items .= '<li class="wp-block-cradle-post-cards__item">' . $card . '</li>';
		}
		return '<div class="' . esc_attr( $classes ) . '"><ul class="wp-block-cradle-post-cards__list" role="list">' . $items . '</ul></div>';
	}
}
