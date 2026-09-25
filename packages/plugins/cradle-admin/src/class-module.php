<?php
/**
 * Module descriptor, boot and shared helpers of Cradle Admin.
 *
 * @package Cradle\Admin
 */

namespace Cradle\Admin;

defined( 'ABSPATH' ) || exit;

final class Module {

	const ID               = 'admin';
	const CAP              = 'cradle_manage_branding';
	const PAGE             = 'cradle-admin';
	const SECTION_BRANDING = 'admin-branding';
	const SECTION_VISUAL   = 'admin-visual';
	const SECTION_LABELS   = 'admin-labels';
	const USER_META        = 'cradle_admin_native_appearance';

	/** @var \Cradle\Core\Plugin|null */
	private static $core = null;

	/**
	 * Registers the trusted module descriptor (cradle/register_modules).
	 *
	 * @param \Cradle\Core\Module_Registry $registry Registry.
	 */
	public static function register( $registry ) {
		$registry->register(
			array(
				'id'               => self::ID,
				'name'             => 'Cradle Admin',
				'description'      => 'White-label branding, scoped admin appearance, label overrides and trusted custom CSS.',
				'version'          => CRADLE_ADMIN_VERSION,
				'coreApiRange'     => '^1.0',
				'pluginFile'       => CRADLE_ADMIN_FILE,
				'settingsSections' => self::sections(),
				'capabilities'     => array(
					self::CAP => array(
						'label'       => 'Manage admin branding',
						'description' => 'Change Cradle admin branding, appearance and labels. Raw CSS additionally requires manage_options and unfiltered_html.',
						'roles'       => array( 'administrator' ),
					),
				),
				'schemaVersion'    => 1,
				'dataOwners'       => array(
					'options'    => array(
						'cradle_admin_branding_settings',
						'cradle_admin_visual_settings',
						'cradle_admin_labels_settings',
						Custom_Css::OPTION,
						Custom_Css::REVISIONS_OPTION,
						'cradle_admin_version',
					),
					'userMeta'   => array( self::USER_META ),
					'transients' => array( 'cradle_admin_css_draft_*' ),
				),
				'boot'             => array( self::class, 'boot' ),
				'export'           => array( self::class, 'export' ),
				'import'           => array( self::class, 'import' ),
				'adminPage'        => self::PAGE,
			)
		);
	}

	/**
	 * Boots the module inside `init` priority 0 (core ready, translations available).
	 *
	 * @param \Cradle\Core\Plugin $core       Core instance.
	 * @param array               $descriptor Normalized descriptor.
	 */
	public static function boot( $core, array $descriptor ) {
		unset( $descriptor );
		if ( ! version_compare( $core->api_version(), '1.0.0', '>=' ) ) {
			return;
		}
		self::$core = $core;
		load_plugin_textdomain( 'cradle-admin', false, dirname( plugin_basename( CRADLE_ADMIN_FILE ) ) . '/languages' );

		Sections::register( $core->settings() );

		Skin::hooks();
		Branding::hooks();
		Labels::hooks();

		if ( is_admin() ) {
			Admin\Page::hooks();
			Admin\Profile::hooks();
		}
	}

	public static function core() {
		return self::$core;
	}

	/**
	 * @return string[] Settings section IDs owned by this module.
	 */
	public static function sections(): array {
		return array( self::SECTION_BRANDING, self::SECTION_VISUAL, self::SECTION_LABELS );
	}

	/**
	 * Safe mode disables every Cradle Admin styling and labeling change.
	 *
	 * Server-side switch: define( 'CRADLE_ADMIN_SAFE_MODE', true ) in wp-config.php.
	 * The `cradle_admin/safe_mode` filter can only switch safe mode on (used by tests
	 * and must-use recovery code); it cannot switch off a defined constant.
	 */
	public static function safe_mode(): bool {
		if ( defined( 'CRADLE_ADMIN_SAFE_MODE' ) && CRADLE_ADMIN_SAFE_MODE ) {
			return true;
		}
		/**
		 * Filters whether Cradle Admin safe mode is on for this request.
		 *
		 * @param bool $safe_mode False unless something switched it on.
		 */
		return true === apply_filters( 'cradle_admin/safe_mode', false );
	}

	public static function safe_mode_by_constant(): bool {
		return defined( 'CRADLE_ADMIN_SAFE_MODE' ) && CRADLE_ADMIN_SAFE_MODE;
	}

	/**
	 * Current values of one of this module's sections (never writes).
	 */
	public static function values( string $section ): array {
		if ( self::$core ) {
			return self::$core->settings()->values( $section );
		}
		return function_exists( 'cradle' ) && cradle() ? cradle()->settings()->values( $section ) : array();
	}

	/**
	 * Module export handler: non-sensitive section values plus the active custom CSS.
	 */
	public static function export(): array {
		$settings = cradle()->settings();
		$out      = array(
			'format'        => 'cradle-admin',
			'formatVersion' => 1,
			'sections'      => array(),
			'customCss'     => Custom_Css::current()['css'],
		);
		foreach ( self::sections() as $section ) {
			$out['sections'][ $section ] = $settings->export( $section );
		}
		return $out;
	}

	/**
	 * Module import handler. Every value is validated again; raw CSS is imported only
	 * for trusted administrators on environments that allow it.
	 *
	 * @param array $data Data produced by export().
	 * @return true|\WP_Error
	 */
	public static function import( array $data ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'cradle_admin_forbidden', __( 'You are not allowed to import admin branding.', 'cradle-admin' ) );
		}
		if ( 'cradle-admin' !== ( $data['format'] ?? '' ) ) {
			return new \WP_Error( 'cradle_admin_format', __( 'This is not a Cradle Admin export.', 'cradle-admin' ) );
		}
		$errors   = array();
		$settings = cradle()->settings();
		foreach ( self::sections() as $section ) {
			if ( isset( $data['sections'][ $section ] ) && is_array( $data['sections'][ $section ] ) ) {
				$result = $settings->update( $section, $data['sections'][ $section ] );
				if ( is_wp_error( $result ) ) {
					$errors[ $section ] = $result->get_error_message();
				} elseif ( $result['errors'] ) {
					$errors[ $section ] = $result['errors'];
				}
			}
		}
		if ( isset( $data['customCss'] ) && is_string( $data['customCss'] ) && '' !== $data['customCss'] ) {
			if ( ! Custom_Css::user_can_edit() ) {
				$errors['customCss'] = __( 'Custom CSS was not imported: it requires a trusted administrator on an environment that allows unfiltered HTML.', 'cradle-admin' );
			} else {
				$saved = Custom_Css::save( $data['customCss'], Custom_Css::current()['revision'], get_current_user_id() );
				if ( is_wp_error( $saved ) ) {
					$errors['customCss'] = $saved->get_error_messages();
				}
			}
		}
		if ( $errors ) {
			return new \WP_Error( 'cradle_admin_import_partial', __( 'Some imported values were invalid and kept their previous values.', 'cradle-admin' ), $errors );
		}
		return true;
	}
}
