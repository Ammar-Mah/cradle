<?php
/**
 * Block Bindings source `cradle-content/field`: lets core blocks (paragraph, heading,
 * image, button) display a Cradle field of the current post without theme code.
 *
 * Example: <!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"cradle-content/field","args":{"key":"isbn"}}}}} -->
 *
 * Only fields with "public" exposure are output, only for posts the viewer may read
 * (same rule as the core post-meta source), and values are formatted as plain text
 * (limited rich text passes through the rich text allowlist).
 *
 * @package Cradle\Content
 */

namespace Cradle\Content;

defined( 'ABSPATH' ) || exit;

final class Bindings {

	public static function register() {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}
		register_block_bindings_source(
			'cradle-content/field',
			array(
				'label'              => __( 'Cradle field', 'cradle-content' ),
				'get_value_callback' => array( __CLASS__, 'value' ),
				'uses_context'       => array( 'postId', 'postType' ),
			)
		);
	}

	/**
	 * @param array     $args           Source args: key (field key).
	 * @param \WP_Block $block_instance Block.
	 * @param string    $attribute      Bound attribute.
	 * @return string|null
	 */
	public static function value( array $args, $block_instance, $attribute = '' ) {
		$key     = is_string( $args['key'] ?? null ) ? $args['key'] : '';
		$post_id = (int) ( $block_instance->context['postId'] ?? 0 );
		if ( '' === $key || ! $post_id ) {
			return null;
		}
		$post = get_post( $post_id );
		if ( ! $post || ( ! is_post_publicly_viewable( $post ) && ! current_user_can( 'read_post', $post_id ) ) || post_password_required( $post ) ) {
			return null;
		}
		$field = Meta::instance()->field( 'post', $post->post_type, $key );
		if ( ! $field || 'public' !== $field['exposure'] ) {
			return null;
		}
		$value = get_post_meta( $post_id, $field['meta_key'], ! Field_Types::is_multi( $field ) );
		if ( 'attachment' === $field['type'] && in_array( $attribute, array( 'url', 'href' ), true ) ) {
			$url = wp_get_attachment_url( (int) $value );
			return $url ? $url : null;
		}
		if ( 'url' === $field['type'] ) {
			if ( ! is_string( $value ) || '' === $value ) {
				return null;
			}
			// Attribute values are escaped by the HTML API; text content is escaped here.
			return in_array( $attribute, array( 'url', 'href' ), true ) ? esc_url_raw( $value, array( 'http', 'https' ) ) : esc_html( $value );
		}
		if ( 'rich_text' === $field['type'] ) {
			return is_string( $value ) ? wp_kses( $value, Field_Types::RICH_TAGS ) : null;
		}
		if ( 'post_ref' === $field['type'] && in_array( $attribute, array( 'url', 'href' ), true ) ) {
			$id = (int) ( is_array( $value ) ? reset( $value ) : $value );
			return $id && ( is_post_publicly_viewable( $id ) || current_user_can( 'read_post', $id ) ) ? get_permalink( $id ) : null;
		}
		return esc_html( Field_Types::display( $field, $value ) );
	}
}
