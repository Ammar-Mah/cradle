<?php
/**
 * Settings section `dummy-content` (option `cradle_dummy_content_settings`).
 *
 * These are site-policy limits, so they need `manage_options` in addition to
 * being shown only on the Dummy Content screen.
 *
 * @package Cradle\Dummy_Content
 */

namespace Cradle\Dummy_Content;

defined( 'ABSPATH' ) || exit;

final class Config {

	const SECTION = 'dummy-content';

	const DEFAULTS = array(
		'max_items_per_batch'    => 50,
		'items_per_step'         => 5,
		'default_status'         => 'draft',
		'max_generated_terms'    => 10,
		'max_placeholder_images' => 5,
		'keep_reports'           => 20,
	);

	/** Hard ceiling regardless of settings. */
	const ABSOLUTE_MAX_ITEMS = 500;

	/**
	 * @param \Cradle\Core\Plugin $core Core.
	 */
	public static function register( $core ) {
		$core->settings()->register_section(
			array(
				'id'            => self::SECTION,
				'owner'         => Module::ID,
				'title'         => __( 'Dummy content limits', 'cradle-dummy-content' ),
				'description'   => __( 'Upper bounds that keep generation and cleanup jobs small and predictable.', 'cradle-dummy-content' ),
				'capability'    => 'manage_options',
				'schemaVersion' => 1,
				'page'          => false,
				'autoload'      => false,
				'lifecycle'     => array( 'uninstall' => 'retain' ),
				'fields'        => array(
					'max_items_per_batch'    => array(
						'type'    => 'integer',
						'default' => self::DEFAULTS['max_items_per_batch'],
						'minimum' => 1,
						'maximum' => self::ABSOLUTE_MAX_ITEMS,
						'label'   => __( 'Maximum items per batch', 'cradle-dummy-content' ),
						'help'    => __( 'The generation form refuses larger counts.', 'cradle-dummy-content' ),
					),
					'items_per_step'         => array(
						'type'    => 'integer',
						'default' => self::DEFAULTS['items_per_step'],
						'minimum' => 1,
						'maximum' => 25,
						'label'   => __( 'Items per job step', 'cradle-dummy-content' ),
						'help'    => __( 'How many items one bounded, resumable job step creates or removes.', 'cradle-dummy-content' ),
					),
					'default_status'         => array(
						'type'    => 'enum',
						'enum'    => array(
							'draft'   => __( 'Draft', 'cradle-dummy-content' ),
							'pending' => __( 'Pending review', 'cradle-dummy-content' ),
							'private' => __( 'Private', 'cradle-dummy-content' ),
							'publish' => __( 'Published', 'cradle-dummy-content' ),
						),
						'default' => self::DEFAULTS['default_status'],
						'label'   => __( 'Default status', 'cradle-dummy-content' ),
						'help'    => __( 'Drafts are recommended: they never appear on the public site.', 'cradle-dummy-content' ),
					),
					'max_generated_terms'    => array(
						'type'    => 'integer',
						'default' => self::DEFAULTS['max_generated_terms'],
						'minimum' => 0,
						'maximum' => 20,
						'label'   => __( 'Maximum new terms per taxonomy', 'cradle-dummy-content' ),
					),
					'max_placeholder_images' => array(
						'type'    => 'integer',
						'default' => self::DEFAULTS['max_placeholder_images'],
						'minimum' => 0,
						'maximum' => 20,
						'label'   => __( 'Maximum placeholder images per batch', 'cradle-dummy-content' ),
						'help'    => __( 'Placeholder images are drawn locally with PHP GD. Nothing is downloaded.', 'cradle-dummy-content' ),
					),
					'keep_reports'           => array(
						'type'    => 'integer',
						'default' => self::DEFAULTS['keep_reports'],
						'minimum' => 1,
						'maximum' => 50,
						'label'   => __( 'Operation reports kept per batch', 'cradle-dummy-content' ),
					),
				),
			)
		);
	}

	public static function values(): array {
		if ( ! function_exists( 'cradle' ) || ! cradle() || ! cradle()->settings()->section( self::SECTION ) ) {
			return self::DEFAULTS;
		}
		return array_merge( self::DEFAULTS, cradle()->settings()->values( self::SECTION ) );
	}

	public static function get( string $key ) {
		return self::values()[ $key ] ?? null;
	}
}
