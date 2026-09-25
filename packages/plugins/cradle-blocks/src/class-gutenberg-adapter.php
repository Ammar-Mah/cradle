<?php
/**
 * Gutenberg adapter: registers block types for components that declare
 * `adapters.gutenberg.blockName` and ship a block.json directory, and maps
 * block attributes/context to the builder-neutral component render service.
 *
 * @package Cradle\Blocks
 */

namespace Cradle\Blocks;

use Cradle\Core\Components;
use Cradle\Core\Editor_Adapter;

defined( 'ABSPATH' ) || exit;

final class Gutenberg_Adapter implements Editor_Adapter {

	/** Content-bearing post types scanned for usages. */
	const SCAN_POST_TYPES = array( 'post', 'page', 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation', 'revision' );
	const SCAN_LIMIT      = 500;

	private Components $components;

	/** @var array<string,string> blockName => componentId */
	private $blocks = array();

	public function __construct( Components $components ) {
		$this->components = $components;
	}

	public function id(): string {
		return 'gutenberg';
	}

	public function is_available(): bool {
		return function_exists( 'register_block_type' );
	}

	public function register_component( array $component ) {
		$block_name = (string) ( $component['adapters']['gutenberg']['blockName'] ?? '' );
		$block_dir  = (string) ( $this->components->handlers( $component['id'] )['blockDir'] ?? '' );
		if ( '' === $block_name || '' === $block_dir || ! is_file( $block_dir . '/block.json' ) ) {
			return;
		}
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( $block_name ) ) {
			return;
		}
		$component_id = $component['id'];
		$result       = register_block_type(
			$block_dir,
			array(
				'render_callback' => function ( $attributes, $content, $block ) use ( $component_id ) {
					return $this->render_block( $component_id, (array) $attributes, (string) $content, $block );
				},
			)
		);
		if ( $result ) {
			$this->blocks[ $block_name ] = $component_id;
		}
	}

	/**
	 * Block render callback. The component service is authoritative while active;
	 * the saved markup ($content) is what WordPress shows if this plugin is inactive.
	 */
	public function render_block( string $component_id, array $attributes, string $content, $block ): string {
		$component = $this->components->get( $component_id );
		if ( ! $component ) {
			return $content;
		}
		$context               = $this->map_context( $block );
		$context['inner_html'] = $this->inner_html( $block, $content );
		$props                 = $this->map_saved_data_to_properties( $component, $attributes );
		$html                  = $this->components->render( $component_id, $props, $context );
		if ( '' === $html ) {
			return '';
		}
		return $this->apply_wrapper_attributes( $html, $block );
	}

	/**
	 * Rendered inner blocks for components with slots (InnerBlocks).
	 */
	private function inner_html( $block, string $content ): string {
		if ( ! $block instanceof \WP_Block || empty( $block->inner_blocks ) ) {
			return '';
		}
		$html = '';
		foreach ( $block->inner_blocks as $inner ) {
			$html .= $inner->render();
		}
		return $html;
	}

	/**
	 * Adds block-supports classes/styles (alignment, colors, spacing) to the root element.
	 */
	private function apply_wrapper_attributes( string $html, $block ): string {
		if ( ! $block instanceof \WP_Block || ! function_exists( 'get_block_wrapper_attributes' ) ) {
			return $html;
		}
		\WP_Block_Supports::$block_to_render = $block->parsed_block;
		$wrapper                             = get_block_wrapper_attributes();
		if ( '' === $wrapper || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}
		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag() ) {
			return $html;
		}
		$source = new \WP_HTML_Tag_Processor( '<div ' . $wrapper . '></div>' );
		$source->next_tag();
		foreach ( (array) $source->get_attribute_names_with_prefix( '' ) as $name ) {
			$value = $source->get_attribute( $name );
			if ( 'class' === $name ) {
				foreach ( preg_split( '/\s+/', (string) $value ) as $class ) {
					if ( '' !== $class ) {
						$processor->add_class( $class );
					}
				}
			} elseif ( 'style' === $name ) {
				$existing = (string) $processor->get_attribute( 'style' );
				$processor->set_attribute( 'style', trim( $existing . ( $existing && ! str_ends_with( $existing, ';' ) ? ';' : '' ) . $value ) );
			} elseif ( null === $processor->get_attribute( $name ) ) {
				$processor->set_attribute( $name, $value );
			}
		}
		return $processor->get_updated_html();
	}

	public function map_saved_data_to_properties( array $component, array $saved_data ): array {
		$props = array();
		foreach ( array_keys( $component['properties'] ) as $name ) {
			if ( array_key_exists( $name, $saved_data ) ) {
				$props[ $name ] = $saved_data[ $name ];
			}
		}
		return $props;
	}

	public function map_context( $editor_context ): array {
		$post_id = 0;
		if ( $editor_context instanceof \WP_Block && isset( $editor_context->context['postId'] ) ) {
			$post_id = (int) $editor_context->context['postId'];
		}
		return array(
			'adapter' => 'gutenberg',
			'post_id' => $post_id ?: (int) get_the_ID(),
		);
	}

	public function describe_edit_controls( array $component ): array {
		$block_name = (string) ( $component['adapters']['gutenberg']['blockName'] ?? '' );
		$type       = \WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
		if ( ! $type ) {
			return array();
		}
		return array(
			'blockName'  => $block_name,
			'attributes' => array_keys( (array) $type->attributes ),
			'supports'   => array_keys( (array) $type->supports ),
		);
	}

	public function block_name_for( string $component_id ): string {
		$component = $this->components->get( $component_id );
		return $component ? (string) ( $component['adapters']['gutenberg']['blockName'] ?? '' ) : '';
	}

	public function find_component_usages( string $component_id ): array {
		global $wpdb;
		$block_name = $this->block_name_for( $component_id );
		if ( '' === $block_name ) {
			return array();
		}
		$types = "'" . implode( "','", array_map( 'esc_sql', self::SCAN_POST_TYPES ) ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_status, post_title, post_parent, post_content FROM {$wpdb->posts} WHERE post_type IN ({$types}) AND post_content LIKE %s ORDER BY ID ASC LIMIT %d", '%' . $wpdb->esc_like( '<!-- wp:' . $block_name ) . '%', self::SCAN_LIMIT ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows ?: array() as $row ) {
			$count = self::count_blocks( parse_blocks( $row->post_content ), $block_name );
			if ( ! $count ) {
				continue;
			}
			$out[] = array(
				'object_type' => $row->post_type,
				'object_id'   => (int) $row->ID,
				'title'       => '' !== $row->post_title ? $row->post_title : '#' . $row->ID,
				'status'      => 'revision' === $row->post_type ? 'revision of #' . (int) $row->post_parent : $row->post_status,
				'count'       => $count,
			);
		}
		return $out;
	}

	public static function count_blocks( array $blocks, string $block_name ): int {
		$count = 0;
		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] ?? '' ) === $block_name ) {
				$count++;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$count += self::count_blocks( $block['innerBlocks'], $block_name );
			}
		}
		return $count;
	}

	/**
	 * Renders one stored block instance to static semantic HTML.
	 *
	 * @param array $instance array( 'block' => parsed block array, 'post_id' => int ).
	 */
	public function export_or_convert_instance( array $instance ) {
		$parsed = $instance['block'] ?? null;
		if ( ! is_array( $parsed ) || empty( $parsed['blockName'] ) || ! isset( $this->blocks[ $parsed['blockName'] ] ) ) {
			return new \WP_Error( 'cradle_blocks_not_component', 'Not a Cradle component block.' );
		}
		$component_id = $this->blocks[ $parsed['blockName'] ];
		$component    = $this->components->get( $component_id );
		$previous     = $GLOBALS['post'] ?? null;
		if ( ! empty( $instance['post_id'] ) ) {
			$GLOBALS['post'] = get_post( (int) $instance['post_id'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		$html            = trim( render_block( $parsed ) );
		$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$lost            = array( __( 'Block settings in the editor sidebar', 'cradle-blocks' ) );
		if ( 'none' === $component['fallbackPolicy'] ) {
			$lost[] = __( 'Live data: the converted HTML is a snapshot and will not update when content changes', 'cradle-blocks' );
		}
		return array(
			'html' => $html,
			'lost' => $lost,
		);
	}

	public function registered_blocks(): array {
		return $this->blocks;
	}
}
