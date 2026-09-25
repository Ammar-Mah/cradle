<?php
/**
 * Module-wide operational settings, registered with the core settings schema
 * (option `cradle_content_settings`, Settings API form, revision, export/import, reset).
 *
 * @package Cradle\Content
 */

namespace Cradle\Content;

defined( 'ABSPATH' ) || exit;

final class Settings_Section {

	const ID = 'content';

	public static function register( $core ) {
		$core->settings()->register_section(
			array(
				'id'            => self::ID,
				'owner'         => Module::ID,
				'title'         => __( 'Content types settings', 'cradle-content' ),
				'description'   => __( 'Operational limits for migrations, reference pickers and REST output of Cradle content fields.', 'cradle-content' ),
				'capability'    => Module::CAP,
				'schemaVersion' => 1,
				'page'          => false,
				'autoload'      => true,
				'lifecycle'     => array( 'uninstall' => 'retain' ),
				'fields'        => array(
					'migration_batch_size'        => array(
						'type'    => 'integer',
						'default' => 100,
						'minimum' => 10,
						'maximum' => 500,
						'step'    => 10,
						'label'   => __( 'Migration batch size', 'cradle-content' ),
						'help'    => __( 'Records moved per step of a key-rename, conversion or field-data migration. Smaller batches are gentler on slow hosts.', 'cradle-content' ),
					),
					'lookup_limit'                => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 5,
						'maximum' => 50,
						'label'   => __( 'Reference search results', 'cradle-content' ),
						'help'    => __( 'Maximum results per search in the block editor reference picker.', 'cradle-content' ),
					),
					'select_limit'                => array(
						'type'    => 'integer',
						'default' => 200,
						'minimum' => 20,
						'maximum' => 500,
						'step'    => 10,
						'label'   => __( 'Reference choices in classic forms', 'cradle-content' ),
						'help'    => __( 'Maximum options listed in reference selects on classic meta boxes and term forms.', 'cradle-content' ),
					),
					'rest_filter_unreadable_refs' => array(
						'type'           => 'boolean',
						'default'        => true,
						'label'          => __( 'Hide unreadable references', 'cradle-content' ),
						'checkbox_label' => __( 'Remove references to posts the viewer cannot read from public REST responses', 'cradle-content' ),
					),
					'show_retained_notice'        => array(
						'type'           => 'boolean',
						'default'        => true,
						'label'          => __( 'Retained content notice', 'cradle-content' ),
						'checkbox_label' => __( 'Explain disabled definitions that still hold content on the Content types screen', 'cradle-content' ),
					),
				),
			)
		);
	}

	/**
	 * Reads a setting with a fallback when core or the section is unavailable. Never writes.
	 *
	 * @param mixed $fallback Fallback value.
	 */
	public static function get( string $field, $fallback = null ) {
		if ( ! function_exists( 'cradle_get_setting' ) ) {
			return $fallback;
		}
		$value = cradle_get_setting( self::ID, $field );
		return null === $value ? $fallback : $value;
	}
}
