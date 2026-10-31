<?php
/**
 * Module descriptor and boot.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Module {

	const ID = 'forms';

	const STYLE_HANDLE        = 'cradle-forms';
	const VIEW_SCRIPT         = 'cradle-forms-view';
	const FIELDS_SCRIPT       = 'cradle-forms-fields';
	const EDITOR_STYLE        = 'cradle-forms-editor';
	const FORM_EDITOR_SCRIPT  = 'cradle-forms-form-editor';
	const ADMIN_STYLE         = 'cradle-forms-admin';

	const TEXT_DOMAIN = 'cradle-forms';

	/** Descriptor name and description (plain English; translated through `cradle/translate_label`). */
	const NAME        = 'Cradle Forms';
	const DESCRIPTION = 'Forms built from blocks with server-side validation, stored entries, private file uploads, consent records, notifications and spam protection.';

	/**
	 * Registers the trusted descriptor (plain strings only: translations are not loaded yet).
	 *
	 * @param \Cradle\Core\Module_Registry $registry Registry.
	 */
	public static function register( $registry ) {
		$registry->register(
			array(
				'id'               => self::ID,
				'name'             => self::NAME,
				'description'      => self::DESCRIPTION,
				'version'          => CRADLE_FORMS_VERSION,
				'coreApiRange'     => '^1.0',
				'pluginFile'       => CRADLE_FORMS_FILE,
				'dependencies'     => array( 'blocks' => '^0.1' ),
				'settingsSections' => array( Settings_Section::ID ),
				'capabilities'     => Caps::definitions(),
				'schemaVersion'    => Schema::VERSION,
				'dataOwners'       => array(
					'options'    => array( Settings_Section::OPTION, Lifecycle::VERSION_OPTION, Schema::OPTION ),
					'postTypes'  => array( Post_Type::NAME ),
					'postMeta'   => array( '_cradle_form_*' ),
					'tables'     => array_values( Schema::TABLES ),
					'cron'       => array( Retention::HOOK ),
					'uploads'    => array( 'cradle-private/forms/uploads' ),
					'files'      => array( 'cradle-private/forms/uploads/*' ),
					'transients' => array( 'cradle_forms_*' ),
					'blocks'     => array_merge( array( 'cradle/form' ), Field_Types::block_names() ),
				),
				'boot'             => array( __CLASS__, 'boot' ),
				'adminPage'        => 'cradle-forms',
			)
		);
	}

	/**
	 * Attaches feature hooks. Runs on `init` priority 0 only when the module (and `blocks`) is ready.
	 *
	 * @param \Cradle\Core\Plugin $core       Core.
	 * @param array               $descriptor Normalized descriptor.
	 */
	public static function boot( $core, array $descriptor = array() ) {
		if ( version_compare( $core->api_version(), '1.0.0', '<' ) ) {
			return;
		}

		// Shipped translations: languages/cradle-forms-<locale>.l10n.php (just-in-time loading).
		load_plugin_textdomain( self::TEXT_DOMAIN, false, dirname( plugin_basename( CRADLE_FORMS_FILE ) ) . '/languages' );
		add_filter( 'cradle/translate_label', array( __CLASS__, 'translate_label' ), 20, 3 );
		add_action( 'init', array( __CLASS__, 'script_translations' ), 100 );

		Settings_Section::register( $core );
		Editor::hooks();
		Post_Type::register();
		Field_Types::register_blocks();

		\Cradle\Core\Assets::register_style( self::STYLE_HANDLE, CRADLE_FORMS_FILE, 'assets/css/forms.css', array(), CRADLE_FORMS_VERSION );
		\Cradle\Core\Assets::register_script( self::VIEW_SCRIPT, CRADLE_FORMS_FILE, 'assets/js/view.js', array(), CRADLE_FORMS_VERSION );

		$core->components()->register_from_file(
			CRADLE_FORMS_DIR . 'components/form/component.json',
			array(
				'render'   => array( Renderer::class, 'render' ),
				'blockDir' => CRADLE_FORMS_DIR . 'blocks/form',
			)
		);
		add_shortcode( 'cradle_form', array( Renderer::class, 'shortcode' ) );

		Handler::hooks();
		Rest::hooks();
		Privacy::hooks();
		Retention::hooks();

		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_action( 'admin_init', array( Lifecycle::class, 'maybe_upgrade' ), 5 );
		}
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->hooks();
		}
	}

	/**
	 * Configuration labels this module registers in the `cradle` domain (descriptor name and
	 * description, capability labels), by translation context.
	 *
	 * @return array<string,string[]>
	 */
	public static function labels(): array {
		return array(
			'module name'        => array( self::NAME ),
			'module description' => array( self::DESCRIPTION ),
			'capability label'   => array_values( array_map( static fn( $cap ) => (string) $cap['label'], Caps::definitions() ) ),
		);
	}

	/**
	 * `cradle/translate_label` (priority 20, after Cradle Translations): a label of this module in
	 * the `cradle` domain that is still the English original gets the translation shipped in this
	 * plugin's own domain. A translation applied earlier (a Cradle Translations override) is kept.
	 *
	 * @param mixed $text    Label, possibly translated by an earlier callback.
	 * @param mixed $context Translation context.
	 * @param mixed $domain  Text domain the label belongs to.
	 * @return mixed
	 */
	public static function translate_label( $text, $context = '', $domain = 'cradle' ) {
		if ( 'cradle' !== $domain || ! is_string( $text ) || ! is_string( $context ) || ! in_array( $text, self::labels()[ $context ] ?? array(), true ) ) {
			return $text;
		}
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralContext -- descriptor labels; translations ship in languages/cradle-forms.json.
		$translated = translate_with_gettext_context( $text, $context, self::TEXT_DOMAIN );
		return is_string( $translated ) && '' !== $translated ? $translated : $text;
	}

	/**
	 * Points the editor script of `cradle/form` (registered from its block.json) at the shipped
	 * Jed files in languages/. The other editor scripts are registered with that path directly.
	 */
	public static function script_translations() {
		$block = \WP_Block_Type_Registry::get_instance()->get_registered( 'cradle/form' );
		foreach ( $block ? (array) $block->editor_script_handles : array() as $handle ) {
			if ( is_string( $handle ) && wp_script_is( $handle, 'registered' ) ) {
				wp_set_script_translations( $handle, self::TEXT_DOMAIN, CRADLE_FORMS_DIR . 'languages' );
			}
		}
	}
}
