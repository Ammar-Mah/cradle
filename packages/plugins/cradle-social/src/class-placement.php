<?php
/**
 * Optional automatic placement of share links around single-entry content.
 *
 * Off by default (`placement` = none). When enabled it only acts on the main
 * query's singular entry inside the loop, for the configured post types,
 * never in feeds, REST responses, admin screens or excerpt generation, never
 * on password-protected content that is still locked, and never when the
 * entry already contains a Share links block.
 *
 * @package Cradle\Social
 */

namespace Cradle\Social;

defined( 'ABSPATH' ) || exit;

final class Placement {

	const PRIORITY = 20;

	public static function hooks() {
		add_filter( 'the_content', array( __CLASS__, 'filter' ), self::PRIORITY );
	}

	/**
	 * @param mixed $content Filtered content.
	 * @return mixed
	 */
	public static function filter( $content ) {
		if ( ! is_string( $content ) ) {
			return $content;
		}
		$mode = Config::get( 'placement' );
		if ( ! in_array( $mode, array( 'before', 'after', 'both' ), true ) ) {
			return $content;
		}
		if ( is_admin() || is_feed() || is_embed() || doing_filter( 'get_the_excerpt' ) || doing_filter( 'wp_trim_excerpt' ) ) {
			return $content;
		}
		if ( ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || (int) $post->ID !== (int) get_queried_object_id() ) {
			return $content;
		}
		if ( ! in_array( $post->post_type, Config::placement_post_types(), true ) || post_password_required( $post ) || has_block( 'cradle/share-links', $post->post_content ) ) {
			return $content;
		}
		$html = cradle_render_component(
			'cradle/share-links',
			array(),
			array(
				'adapter' => 'php',
				'post_id' => (int) $post->ID,
			)
		);
		if ( '' === $html ) {
			return $content;
		}
		$before = in_array( $mode, array( 'before', 'both' ), true ) ? '<div class="cradle-social-placement cradle-social-placement--before">' . $html . '</div>' : '';
		if ( 'both' === $mode ) {
			// A second instance needs its own element IDs.
			$html = cradle_render_component(
				'cradle/share-links',
				array(),
				array(
					'adapter' => 'php',
					'post_id' => (int) $post->ID,
				)
			);
		}
		$after = in_array( $mode, array( 'after', 'both' ), true ) ? '<div class="cradle-social-placement cradle-social-placement--after">' . $html . '</div>' : '';
		return $before . $content . $after;
	}
}
