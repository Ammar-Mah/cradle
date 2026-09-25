<?php
/**
 * Module descriptor and boot.
 *
 * @package Cradle\Duplicate
 */

namespace Cradle\Duplicate;

defined( 'ABSPATH' ) || exit;

final class Module {

	const ID = 'duplicate';

	/**
	 * Registers the trusted descriptor (plain strings only: translations are not loaded yet).
	 *
	 * @param \Cradle\Core\Module_Registry $registry Registry.
	 */
	public static function register( $registry ) {
		$registry->register(
			array(
				'id'               => self::ID,
				'name'             => 'Cradle Duplicate',
				'description'      => 'Duplicate posts and pages as editable drafts through a protected, object-authorized action.',
				'version'          => CRADLE_DUPLICATE_VERSION,
				'coreApiRange'     => '^1.0',
				'pluginFile'       => CRADLE_DUPLICATE_FILE,
				'settingsSections' => array( 'duplicate' ),
				'capabilities'     => array(
					Lifecycle::CAPABILITY => array(
						'label'       => 'Manage Cradle Duplicate settings',
						'description' => 'Choose supported post types, the title suffix, the author policy and extra allowed private metadata.',
						'roles'       => array( 'administrator' ),
					),
				),
				'schemaVersion'    => 1,
				'dataOwners'       => array(
					'options'  => array( 'cradle_duplicate_settings', 'cradle_duplicate_installed_version' ),
					'postMeta' => array( Duplicator::SOURCE_META ),
				),
				'boot'             => array( __CLASS__, 'boot' ),
				'adminPage'        => 'cradle-duplicate',
			)
		);
	}

	/**
	 * Attaches feature hooks. Runs on `init` priority 0 only when the module is ready.
	 *
	 * @param \Cradle\Core\Plugin $core       Core.
	 * @param array               $descriptor Normalized descriptor.
	 */
	public static function boot( $core, array $descriptor ) {
		if ( version_compare( $core->api_version(), '1.0.0', '<' ) ) {
			return;
		}
		Config::register( $core );

		register_post_meta(
			'',
			Duplicator::SOURCE_META,
			array(
				'type'          => 'integer',
				'single'        => true,
				'show_in_rest'  => false,
				'description'   => 'ID of the post this draft was duplicated from (Cradle Duplicate provenance).',
				'auth_callback' => '__return_false',
			)
		);

		( new Rest() )->hooks();
		if ( is_admin() ) {
			( new Admin\Admin() )->hooks();
		}
	}
}
