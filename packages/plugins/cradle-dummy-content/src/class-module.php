<?php
/**
 * Module descriptor and boot.
 *
 * @package Cradle\Dummy_Content
 */

namespace Cradle\Dummy_Content;

defined( 'ABSPATH' ) || exit;

final class Module {

	const ID = 'dummy-content';

	/**
	 * @param \Cradle\Core\Module_Registry $registry Registry.
	 */
	public static function register( $registry ) {
		$registry->register(
			array(
				'id'               => self::ID,
				'name'             => 'Cradle Dummy Content',
				'description'      => 'Generate reproducible development content in tracked batches and remove exactly what was generated.',
				'version'          => CRADLE_DUMMY_CONTENT_VERSION,
				'coreApiRange'     => '^1.0',
				'pluginFile'       => CRADLE_DUMMY_CONTENT_FILE,
				'settingsSections' => array( Config::SECTION ),
				'capabilities'     => array(
					Lifecycle::CAP_GENERATE => array(
						'label'       => 'Generate dummy content',
						'description' => 'Create tracked batches of sample posts, terms and placeholder images.',
						'roles'       => array( 'administrator' ),
					),
					Lifecycle::CAP_CLEANUP  => array(
						'label'       => 'Clean up dummy content',
						'description' => 'Preview, trash and permanently remove generated batches.',
						'roles'       => array( 'administrator' ),
					),
				),
				'schemaVersion'    => 1,
				'dataOwners'       => array(
					'options'   => array( 'cradle_dummy_content_settings', 'cradle_dummy_content_installed_version' ),
					'postTypes' => array( Batches::POST_TYPE ),
					'postMeta'  => array( '_cradle_generat*', '_cradle_dummy_*' ),
					'termMeta'  => array( '_cradle_generat*' ),
				),
				'boot'             => array( __CLASS__, 'boot' ),
				'adminPage'        => 'cradle-dummy-content',
			)
		);
	}

	/**
	 * @param \Cradle\Core\Plugin $core       Core.
	 * @param array               $descriptor Normalized descriptor.
	 */
	public static function boot( $core, array $descriptor ) {
		if ( version_compare( $core->api_version(), '1.0.0', '<' ) ) {
			return;
		}
		Config::register( $core );
		Batches::register_post_type();
		Ownership::register_meta();

		$core->jobs()->register_type(
			Generator::JOB_TYPE,
			array(
				'capability'    => Lifecycle::CAP_GENERATE,
				'step'          => array( Generator::class, 'step' ),
				'max_retries'   => 3,
				'lease_seconds' => 60,
				'time_budget'   => 8,
			)
		);
		$core->jobs()->register_type(
			Cleanup::JOB_TYPE,
			array(
				'capability'    => Lifecycle::CAP_CLEANUP,
				'step'          => array( Cleanup::class, 'step' ),
				'max_retries'   => 3,
				'lease_seconds' => 60,
				'time_budget'   => 8,
			)
		);

		if ( is_admin() ) {
			( new Admin\Admin() )->hooks();
		}
	}
}
