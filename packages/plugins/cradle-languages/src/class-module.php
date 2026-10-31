<?php
/**
 * Module descriptor, request-level start (`cradle/ready`) and boot (`init` priority 0).
 *
 * @package Cradle\Languages
 */

namespace Cradle\Languages;

defined( 'ABSPATH' ) || exit;

final class Module {

	const ID = 'languages';

	const CAP = 'cradle_manage_languages';

	const MIN_API = '1.0.0';

	const STYLE_HANDLE = 'cradle-languages';

	const EDITOR_HANDLE = 'cradle-languages-editor';

	const TEXT_DOMAIN = 'cradle-languages';

	/** Descriptor labels (plain English; translated through `cradle/translate_label`). */
	const NAME        = 'Cradle Languages';
	const DESCRIPTION = 'Multilingual content and routing: languages with URL prefixes, linked translations, language switcher, hreflang and menus per language.';
	const CAP_LABEL   = 'Manage languages';

	/** @var bool */
	private static $started = false;

	/**
	 * `cradle/register_modules`: trusted descriptor (plain strings only, translations are not loaded yet).
	 *
	 * @param \Cradle\Core\Module_Registry $registry Registry.
	 */
	public static function register( $registry ) {
		$registry->register(
			array(
				'id'               => self::ID,
				'name'             => self::NAME,
				'description'      => self::DESCRIPTION,
				'version'          => CRADLE_LANGUAGES_VERSION,
				'coreApiRange'     => '^1.0',
				'pluginFile'       => CRADLE_LANGUAGES_FILE,
				'dependencies'     => array( 'blocks' => '^0.1' ),
				'settingsSections' => array( Config::SECTION ),
				'capabilities'     => array(
					self::CAP => array(
						'label'       => self::CAP_LABEL,
						'description' => 'Configure languages, URL prefixes, translatable content types, menus per language, and run the default-language assignment and the Polylang import.',
						'roles'       => array( 'administrator' ),
					),
				),
				'schemaVersion'    => 1,
				'dataOwners'       => array(
					'options'    => array( Config::OPTION, Lifecycle::VERSION_OPTION ),
					'transients' => array( 'cradle_languages_*' ),
					'taxonomies' => array( Content::TAXONOMY ),
					'postMeta'   => array( '_cradle_lang_*' ),
					'termMeta'   => array( '_cradle_lang*' ),
					'blocks'     => array( 'cradle/language-switcher', 'cradle/language-section' ),
				),
				'boot'             => array( __CLASS__, 'boot' ),
				'adminPage'        => 'cradle-languages',
			)
		);
	}

	/**
	 * `cradle/ready` (plugins_loaded priority 5): detects the request language and attaches the
	 * routing and locale filters. No translation calls here.
	 *
	 * @param \Cradle\Core\Plugin $core        Core.
	 * @param string              $api_version Core API version.
	 */
	public static function ready( $core, $api_version = '' ) {
		if ( self::$started || ! is_object( $core ) || ! is_string( $api_version ) || ! version_compare( $api_version, self::MIN_API, '>=' ) ) {
			return;
		}
		if ( ! $core->modules()->is_ready( self::ID ) ) {
			return;
		}
		self::$started = true;
		Request::start();
		Links::hooks();
	}

	public static function started(): bool {
		return self::$started;
	}

	/**
	 * Module boot (init priority 0, module and its `blocks` dependency ready).
	 *
	 * @param \Cradle\Core\Plugin $core       Core.
	 * @param array               $descriptor Normalized descriptor.
	 */
	public static function boot( $core, array $descriptor = array() ) {
		unset( $descriptor );
		if ( ! version_compare( $core->api_version(), self::MIN_API, '>=' ) ) {
			return;
		}
		// Shipped translations: languages/cradle-languages-<locale>.l10n.php (just-in-time loading).
		load_plugin_textdomain( self::TEXT_DOMAIN, false, dirname( plugin_basename( CRADLE_LANGUAGES_FILE ) ) . '/languages' );
		add_filter( 'cradle/translate_label', array( __CLASS__, 'translate_label' ), 20, 3 );
		add_action( 'init', array( __CLASS__, 'script_translations' ), 100 );
		Lifecycle::maybe_update();
		Config::register( $core );
		Content::hooks();
		Query::hooks();
		Rest::hooks();
		Seo::hooks();
		Menus::hooks();
		Tasks::register( $core );

		\Cradle\Core\Assets::register_style( self::STYLE_HANDLE, CRADLE_LANGUAGES_FILE, 'assets/css/languages.css', array(), CRADLE_LANGUAGES_VERSION );
		Editor::hooks();

		$core->components()->register_from_file(
			CRADLE_LANGUAGES_DIR . 'components/language-switcher/component.json',
			array(
				'render'   => array( Switcher::class, 'render' ),
				'blockDir' => CRADLE_LANGUAGES_DIR . 'blocks/language-switcher',
			)
		);
		$core->components()->register_from_file(
			CRADLE_LANGUAGES_DIR . 'components/language-section/component.json',
			array(
				'render'   => array( Section::class, 'render' ),
				'blockDir' => CRADLE_LANGUAGES_DIR . 'blocks/language-section',
			)
		);

		if ( is_admin() ) {
			( new Admin\Admin_Page( $core ) )->hooks();
			Admin\Lists::hooks();
		}
	}

	/**
	 * Configuration labels this module registers in the `cradle` domain (descriptor name and
	 * description, capability label), by translation context.
	 *
	 * @return array<string,string[]>
	 */
	public static function labels(): array {
		return array(
			'module name'        => array( self::NAME ),
			'module description' => array( self::DESCRIPTION ),
			'capability label'   => array( self::CAP_LABEL ),
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
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralContext -- descriptor labels; translations ship in languages/cradle-languages.json.
		$translated = translate_with_gettext_context( $text, $context, self::TEXT_DOMAIN );
		return is_string( $translated ) && '' !== $translated ? $translated : $text;
	}

	/**
	 * Points the editor scripts of the two blocks (registered from their block.json) at the shipped
	 * Jed files in languages/. The Language panel script is registered with that path directly.
	 */
	public static function script_translations() {
		foreach ( array( 'cradle/language-switcher', 'cradle/language-section' ) as $name ) {
			$block = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
			foreach ( $block ? (array) $block->editor_script_handles : array() as $handle ) {
				if ( is_string( $handle ) && wp_script_is( $handle, 'registered' ) ) {
					wp_set_script_translations( $handle, self::TEXT_DOMAIN, CRADLE_LANGUAGES_DIR . 'languages' );
				}
			}
		}
	}
}
