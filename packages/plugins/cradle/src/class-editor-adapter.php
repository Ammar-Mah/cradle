<?php
/**
 * Contract for editor/page-builder adapters (Gutenberg is the first implementation).
 *
 * Adapters map their saved data and editing context to the builder-neutral
 * component properties consumed by the shared PHP render services.
 *
 * @package Cradle
 */

namespace Cradle\Core;

defined( 'ABSPATH' ) || exit;

interface Editor_Adapter {

	/** Stable adapter ID, for example `gutenberg`. */
	public function id(): string;

	public function is_available(): bool;

	/**
	 * Registers the editor representation of a validated component.
	 *
	 * @param array $component Normalized component descriptor.
	 */
	public function register_component( array $component );

	/**
	 * Maps adapter-specific saved data (for example block attributes) to raw component properties.
	 */
	public function map_saved_data_to_properties( array $component, array $saved_data ): array;

	/**
	 * Maps an adapter-specific context object to a plain render context array.
	 *
	 * @param mixed $editor_context Adapter context (for example a WP_Block instance).
	 */
	public function map_context( $editor_context ): array;

	/**
	 * Describes the editing controls offered for a component, for inventories and docs.
	 */
	public function describe_edit_controls( array $component ): array;

	/**
	 * Finds stored usages. Must be bounded and is only called from admin-triggered scans.
	 *
	 * @return array<int,array{object_type:string,object_id:int,title:string,status:string,count:int}>
	 */
	public function find_component_usages( string $component_id ): array;

	/**
	 * Exports or converts one stored instance, for example to static semantic HTML.
	 *
	 * @param array $instance Adapter-specific instance description.
	 * @return array{html:string,lost:string[]}|\WP_Error
	 */
	public function export_or_convert_instance( array $instance );
}
