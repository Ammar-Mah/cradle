<?php
/**
 * Module descriptor and boot.
 *
 * @package Cradle\Social
 */

namespace Cradle\Social;

defined( 'ABSPATH' ) || exit;

final class Module {

	const ID = 'social';

	const STYLE_HANDLE = 'cradle-social';

	/**
	 * Registers the trusted descriptor (plain strings only: translations are not loaded yet).
	 *
	 * @param \Cradle\Core\Module_Registry $registry Registry.
	 */
	public static function register( $registry ) {
		$registry->register(
			array(
				'id'               => self::ID,
				'name'             => 'Cradle Social',
				'description'      => 'Social profile links and share links from an allowlisted provider registry, without remote SDKs or share counts.',
				'version'          => CRADLE_SOCIAL_VERSION,
				'coreApiRange'     => '^1.0',
				'pluginFile'       => CRADLE_SOCIAL_FILE,
				'dependencies'     => array( 'blocks' => '^0.1' ),
				'settingsSections' => array( Config::SECTION ),
				'capabilities'     => array(
					Lifecycle::CAPABILITY => array(
						'label'       => 'Manage Cradle Social settings',
						'description' => 'Choose profile links and their URLs, share services, share text, appearance and automatic placement.',
						'roles'       => array( 'administrator' ),
					),
				),
				'schemaVersion'    => 1,
				'dataOwners'       => array(
					'options' => array( Config::OPTION, 'cradle_social_installed_version' ),
					'blocks'  => array( 'cradle/social-links', 'cradle/share-links' ),
				),
				'boot'             => array( __CLASS__, 'boot' ),
				'adminPage'        => 'cradle-' . Config::SECTION,
			)
		);
	}

	/**
	 * Attaches feature hooks. Runs on `init` priority 0 only when the module
	 * (and its `blocks` dependency) is ready.
	 *
	 * @param \Cradle\Core\Plugin $core       Core.
	 * @param array               $descriptor Normalized descriptor.
	 */
	public static function boot( $core, array $descriptor = array() ) {
		if ( version_compare( $core->api_version(), '1.0.0', '<' ) ) {
			return;
		}
		Config::register( $core );

		// Frontend assets are registered here and enqueued only by the render services.
		\Cradle\Core\Assets::register_style( self::STYLE_HANDLE, CRADLE_SOCIAL_FILE, 'assets/css/social.css', array(), CRADLE_SOCIAL_VERSION );
		\Cradle\Core\Assets::register_script( Share_Links::VIEW_SCRIPT, CRADLE_SOCIAL_FILE, 'assets/js/social.js', array(), CRADLE_SOCIAL_VERSION );
		Editor::register_assets();

		$core->components()->register_from_file(
			CRADLE_SOCIAL_DIR . 'components/social-links/component.json',
			array(
				'render'   => array( Social_Links::class, 'render' ),
				'blockDir' => CRADLE_SOCIAL_DIR . 'blocks/social-links',
			)
		);
		$core->components()->register_from_file(
			CRADLE_SOCIAL_DIR . 'components/share-links/component.json',
			array(
				'render'   => array( Share_Links::class, 'render' ),
				'blockDir' => CRADLE_SOCIAL_DIR . 'blocks/share-links',
			)
		);

		add_action( 'enqueue_block_editor_assets', array( Editor::class, 'enqueue' ) );
		Placement::hooks();
	}
}
