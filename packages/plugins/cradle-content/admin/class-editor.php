<?php
/**
 * Block editor integration: a document settings panel per field group, built with
 * wp.plugins / wp.editor / wp.components / wp.data (plain JavaScript, no build step).
 * Values are read and written through the post entity's registered REST meta.
 *
 * @package Cradle\Content\Admin
 */

namespace Cradle\Content\Admin;

use Cradle\Content\Meta;
use Cradle\Content\Rest;
use Cradle\Content\Settings_Section;

defined( 'ABSPATH' ) || exit;

final class Editor {

	public static function hooks() {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Client configuration for one field (no server-only data).
	 */
	private static function field_config( array $field ): array {
		$out = array(
			'key'       => $field['key'],
			'metaKey'   => $field['meta_key'] ?? '',
			'label'     => $field['label'],
			'type'      => $field['type'],
			'help'      => $field['help'],
			'required'  => ! empty( $field['required'] ),
			'options'   => array_values( (array) ( $field['options'] ?? array() ) ),
			'min'       => $field['min'] ?? null,
			'max'       => $field['max'] ?? null,
			'step'      => $field['step'] ?? null,
			'integer'   => ! empty( $field['integer'] ),
			'maxLength' => (int) ( $field['max_length'] ?? 0 ),
			'multiple'  => ! empty( $field['multiple'] ) || 'multiselect' === $field['type'],
			'maxItems'  => (int) ( $field['max_items'] ?? 0 ),
			'mime'      => array_values( (array) ( $field['mime'] ?? array() ) ),
			'maxRows'   => (int) ( $field['max_rows'] ?? 0 ),
			'subfields' => array(),
		);
		foreach ( (array) ( $field['subfields'] ?? array() ) as $sub ) {
			$out['subfields'][] = self::field_config( $sub );
		}
		return $out;
	}

	public static function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! $screen->post_type ) {
			return;
		}
		$post_type = $screen->post_type;
		$groups    = array();
		foreach ( Meta::instance()->groups_for( 'post', $post_type ) as $key => $group ) {
			$fields = array();
			foreach ( $group['fields'] as $field ) {
				if ( 'none' !== $field['exposure'] ) {
					$fields[] = self::field_config( $field );
				}
			}
			if ( $fields ) {
				$groups[] = array(
					'key'         => $key,
					'title'       => $group['definition']['title'],
					'description' => $group['definition']['description'] ?? '',
					'fields'      => $fields,
				);
			}
		}
		if ( ! $groups ) {
			return;
		}
		wp_enqueue_script(
			'cradle-content-editor',
			plugins_url( 'assets/js/editor.js', CRADLE_CONTENT_FILE ),
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-core-data', 'wp-i18n', 'wp-api-fetch', 'wp-block-editor', 'wp-url' ),
			Admin::asset_version( 'assets/js/editor.js' ),
			true
		);
		wp_add_inline_script(
			'cradle-content-editor',
			'window.cradleContentEditor = ' . wp_json_encode(
				array(
					'postType'   => $post_type,
					'groups'     => $groups,
					'lookupPath' => '/' . Rest::NAMESPACE . '/lookup',
					'searchMin'  => 2,
					'limit'      => (int) Settings_Section::get( 'lookup_limit', 20 ),
				),
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
			) . ';',
			'before'
		);
		wp_set_script_translations( 'cradle-content-editor', 'cradle-content', CRADLE_CONTENT_DIR . 'languages' );
		wp_enqueue_style( 'cradle-content-editor', plugins_url( 'assets/css/editor.css', CRADLE_CONTENT_FILE ), array(), Admin::asset_version( 'assets/css/editor.css' ) );
	}
}
