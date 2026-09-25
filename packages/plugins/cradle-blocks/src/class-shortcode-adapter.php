<?php
/**
 * First-party shortcode adapter: `[cradle_component id="cradle/feature-card" title="…"]`.
 *
 * A fallback/integration surface that proves the render service works without
 * Gutenberg. Only components whose descriptor declares `adapters.shortcode`
 * are exposed. Attribute names are matched case-insensitively (WordPress
 * lowercases shortcode attributes) and `image-id` style names map to `imageId`.
 *
 * @package Cradle\Blocks
 */

namespace Cradle\Blocks;

use Cradle\Core\Components;
use Cradle\Core\Editor_Adapter;

defined( 'ABSPATH' ) || exit;

final class Shortcode_Adapter implements Editor_Adapter {

	const TAG = 'cradle_component';

	private Components $components;

	public function __construct( Components $components ) {
		$this->components = $components;
		add_shortcode( self::TAG, array( $this, 'shortcode' ) );
	}

	public function id(): string {
		return 'shortcode';
	}

	public function is_available(): bool {
		return true;
	}

	public function register_component( array $component ) {
		// Shortcodes are resolved at render time; nothing to register per component.
	}

	/**
	 * @param array|string $atts    Shortcode attributes.
	 * @param string|null  $content Enclosed content (used as the first slot).
	 */
	public function shortcode( $atts, $content = null ): string {
		$atts = is_array( $atts ) ? $atts : array();
		$id   = isset( $atts['id'] ) ? (string) $atts['id'] : '';
		$comp = $this->components->get( $id );
		if ( ! $comp || empty( $comp['adapters']['shortcode'] ) ) {
			return '';
		}
		unset( $atts['id'] );
		$context = $this->map_context( null );
		if ( null !== $content && '' !== $content ) {
			$context['inner_html'] = wp_kses_post( do_shortcode( $content ) );
		}
		return $this->components->render( $id, $this->map_saved_data_to_properties( $comp, $atts ), $context );
	}

	public function map_saved_data_to_properties( array $component, array $saved_data ): array {
		$lookup = array();
		foreach ( array_keys( $component['properties'] ) as $name ) {
			$lookup[ strtolower( $name ) ] = $name;
		}
		$props = array();
		foreach ( $saved_data as $key => $value ) {
			$normalized = strtolower( str_replace( array( '-', '_' ), '', (string) $key ) );
			if ( ! isset( $lookup[ $normalized ] ) ) {
				continue;
			}
			$name = $lookup[ $normalized ];
			$prop = $component['properties'][ $name ];
			// Lists of scalars are written comma-separated: items="12,14,13".
			if ( is_string( $value ) && 'array' === ( $prop['type'] ?? '' ) && 'object' !== ( $prop['items']['type'] ?? '' ) ) {
				$value = '' === trim( $value ) ? array() : array_map( 'trim', explode( ',', $value ) );
			}
			$props[ $name ] = $value;
		}
		return $props;
	}

	public function map_context( $editor_context ): array {
		return array(
			'adapter' => 'shortcode',
			'post_id' => (int) get_the_ID(),
		);
	}

	public function describe_edit_controls( array $component ): array {
		return array(
			'shortcode'  => self::TAG,
			'attributes' => array_keys( $component['properties'] ),
		);
	}

	public function find_component_usages( string $component_id ): array {
		global $wpdb;
		$needle = '[' . self::TAG;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_status, post_title, post_content FROM {$wpdb->posts} WHERE post_type IN ('post','page','wp_block') AND post_content LIKE %s LIMIT 500", '%' . $wpdb->esc_like( $needle ) . '%' ) );
		$out  = array();
		foreach ( $rows ?: array() as $row ) {
			$count = preg_match_all( '/\[' . self::TAG . '\s[^\]]*id=["\']?' . preg_quote( $component_id, '/' ) . '["\'\s\]]/', $row->post_content );
			if ( $count ) {
				$out[] = array(
					'object_type' => $row->post_type,
					'object_id'   => (int) $row->ID,
					'title'       => $row->post_title,
					'status'      => $row->post_status,
					'count'       => (int) $count,
				);
			}
		}
		return $out;
	}

	public function export_or_convert_instance( array $instance ) {
		$atts = (array) ( $instance['atts'] ?? array() );
		return array(
			'html' => $this->shortcode( $atts, $instance['content'] ?? null ),
			'lost' => array( __( 'Shortcode attributes', 'cradle-blocks' ) ),
		);
	}
}
