<?php
/**
 * Runtime translation overrides.
 *
 * - PHP: domain-scoped `gettext_{domain}`, `gettext_with_context_{domain}`, `ngettext_{domain}`
 *   and `ngettext_with_context_{domain}` filters, attached only for text domains that have a
 *   published override in some locale (read from a small autoloaded index). Lookups use a
 *   per-request map per locale: at most one runtime-file read per locale per request, then
 *   O(1) array lookups; no scans per string.
 * - JavaScript: merges overrides into the Jed JSON WordPress loads for a registered script
 *   handle (`load_script_translations`), or supplies it when no shipped file exists
 *   (`pre_load_script_translations` on the final fallback call).
 * - Fallback order: site override → shipped/native translation → original source text.
 * - Locale: determine_locale() (user locale in admin, site locale on the frontend, switched
 *   locale inside switch_to_locale()); cached per request and reset on `change_locale`
 *   and `set_current_user`, so switching locales never serves another locale's map.
 *
 * @package Cradle\Translations
 */

namespace Cradle\Translations;

defined( 'ABSPATH' ) || exit;

final class Runtime {

	/** @var Runtime|null */
	private static $instance = null;

	/** @var array<string,array> Loaded maps keyed by locale. */
	private $maps = array();

	/** @var array<string,int> Runtime file reads per locale in this request. */
	private $reads = array();

	/** @var array<string,true> Domains with attached filters. */
	private $attached = array();

	/** @var string|null */
	private $locale = null;

	/** @var array|null Cached index option for this request. */
	private $index = null;

	private $resolving = false;
	private $started   = false;

	private function index(): array {
		if ( null === $this->index ) {
			$this->index = Store::index();
		}
		return $this->index;
	}

	public static function instance(): Runtime {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Attaches runtime hooks. Called on `cradle/ready` when the module is ready, before `init`,
	 * so strings core translates at `init` priority 0 are covered too.
	 */
	public function start() {
		if ( $this->started ) {
			return;
		}
		$this->started = true;
		// switch_to_locale() unloads text domains and rebuilds WP_Locale before `change_locale`
		// fires; resetting on `unload_textdomain` too keeps those early strings in the new locale.
		add_action( 'unload_textdomain', array( $this, 'reset_locale' ) );
		add_action( 'change_locale', array( $this, 'reset_locale' ) );
		add_action( 'set_current_user', array( $this, 'reset_locale' ) );
		add_filter( 'get_available_languages', array( $this, 'available_languages' ), 10, 2 );
		add_filter( 'cradle/translate_label', array( $this, 'translate_label' ), 10, 3 );
		if ( Config::get( 'runtime_enabled' ) ) {
			$this->attach_domains();
			if ( Config::get( 'js_enabled' ) ) {
				add_filter( 'load_script_translations', array( $this, 'merge_script_translations' ), 20, 4 );
				add_filter( 'pre_load_script_translations', array( $this, 'provide_script_translations' ), 20, 4 );
			}
		}
	}

	/**
	 * Attaches gettext filters for every domain with published overrides (idempotent).
	 */
	public function attach_domains() {
		if ( ! $this->started || ! Config::get( 'runtime_enabled' ) ) {
			return;
		}
		foreach ( array_keys( $this->index()['domains'] ) as $domain ) {
			$domain = (string) $domain;
			if ( isset( $this->attached[ $domain ] ) || ! Config::valid_domain( $domain ) ) {
				continue;
			}
			$this->attached[ $domain ] = true;
			add_filter( "gettext_{$domain}", array( $this, 'gettext' ), 10, 3 );
			add_filter( "gettext_with_context_{$domain}", array( $this, 'gettext_with_context' ), 10, 4 );
			add_filter( "ngettext_{$domain}", array( $this, 'ngettext' ), 10, 5 );
			add_filter( "ngettext_with_context_{$domain}", array( $this, 'ngettext_with_context' ), 10, 6 );
		}
	}

	public function reset_locale() {
		$this->locale = null;
	}

	/**
	 * Locale translations are currently resolved for.
	 */
	public function current_locale(): string {
		if ( null === $this->locale ) {
			$this->resolving = true;
			$locale          = determine_locale();
			$this->resolving = false;
			$this->locale    = Config::valid_locale( $locale ) ? $locale : 'en_US';
		}
		return $this->locale;
	}

	/**
	 * Drops the cached map of one locale (after publishing) and attaches new domain filters.
	 */
	public function invalidate( string $locale ) {
		unset( $this->maps[ $locale ] );
		$this->index = null;
		$this->attach_domains();
	}

	/**
	 * Per-request lookup map for a locale; loaded at most once per request.
	 */
	public function map( string $locale ): array {
		if ( isset( $this->maps[ $locale ] ) ) {
			return $this->maps[ $locale ];
		}
		$row = $this->index()['locales'][ $locale ] ?? null;
		if ( ! $row ) {
			$this->maps[ $locale ] = array( 'domains' => array() );
			return $this->maps[ $locale ];
		}
		$cached = wp_cache_get( 'map:' . $locale, Config::CACHE_GROUP );
		if ( is_array( $cached ) && ( $cached['hash'] ?? '' ) === ( $row['hash'] ?? null ) ) {
			$this->maps[ $locale ] = $cached;
			return $cached;
		}
		$this->reads[ $locale ] = ( $this->reads[ $locale ] ?? 0 ) + 1;
		$map                    = Store::read_runtime( $locale );
		if ( ! $map || $map['hash'] !== ( $row['hash'] ?? null ) ) {
			$map = array( 'domains' => array() );
		} else {
			wp_cache_set( 'map:' . $locale, $map, Config::CACHE_GROUP, HOUR_IN_SECONDS );
		}
		$this->maps[ $locale ] = $map;
		return $map;
	}

	/**
	 * @return array{reads:array<string,int>,loaded:string[],attached:string[]}
	 */
	public function stats(): array {
		return array(
			'reads'    => $this->reads,
			'loaded'   => array_keys( $this->maps ),
			'attached' => array_keys( $this->attached ),
		);
	}

	public function reset_stats() {
		$this->reads = array();
	}

	/**
	 * @return array|null Domain maps for the current locale, or null when the domain has no overrides.
	 */
	private function domain_map( string $domain ) {
		if ( $this->resolving ) {
			return null; // Re-entrant call while determining the locale: use the native translation.
		}
		$locale = $this->current_locale();
		$row    = $this->index()['locales'][ $locale ] ?? null;
		if ( ! $row || empty( $row['domains'][ $domain ] ) ) {
			return null;
		}
		return $this->map( $locale )['domains'][ $domain ] ?? null;
	}

	public function gettext( $translation, $text, $domain ) {
		if ( ! is_string( $text ) || ! is_string( $domain ) ) {
			return $translation;
		}
		$map = $this->domain_map( $domain );
		return isset( $map['s'][ $text ] ) ? $map['s'][ $text ] : $translation;
	}

	public function gettext_with_context( $translation, $text, $context, $domain ) {
		if ( ! is_string( $text ) || ! is_string( $domain ) ) {
			return $translation;
		}
		$map = $this->domain_map( $domain );
		$key = Catalog::lookup_key( $text, is_string( $context ) ? $context : null );
		return isset( $map['s'][ $key ] ) ? $map['s'][ $key ] : $translation;
	}

	public function ngettext( $translation, $single, $plural, $number, $domain ) {
		return $this->plural( $translation, $single, $plural, $number, null, $domain );
	}

	public function ngettext_with_context( $translation, $single, $plural, $number, $context, $domain ) {
		return $this->plural( $translation, $single, $plural, $number, is_string( $context ) ? $context : null, $domain );
	}

	private function plural( $translation, $single, $plural, $number, ?string $context, $domain ) {
		if ( ! is_string( $single ) || ! is_string( $plural ) || ! is_string( $domain ) ) {
			return $translation;
		}
		$map = $this->domain_map( $domain );
		$key = Catalog::lookup_key( $single, $context ) . "\x00" . $plural;
		if ( ! isset( $map['p'][ $key ] ) || ! is_array( $map['p'][ $key ] ) ) {
			return $translation;
		}
		$index = Plural_Rules::index( $this->current_locale(), $number );
		$form  = $map['p'][ $key ][ $index ] ?? '';
		return is_string( $form ) && '' !== $form ? $form : $translation;
	}

	/**
	 * Jed entries for a domain in the current locale.
	 *
	 * @return array<string,string[]>
	 */
	private function script_entries( string $domain ): array {
		$locale = $this->current_locale();
		$row    = $this->index()['locales'][ $locale ] ?? null;
		if ( ! $row || empty( $row['js'][ $domain ] ) ) {
			return array();
		}
		$entries = $this->map( $locale )['domains'][ $domain ]['j'] ?? array();
		return is_array( $entries ) ? $entries : array();
	}

	private static function json( array $data ): string {
		// Hex-escape <, >, & and quotes so the JSON is inert inside the inline <script> WordPress prints.
		return (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	}

	/**
	 * Supplies Jed JSON when no shipped translation file was found (final fallback call has $file === false).
	 *
	 * @param string|false|null $translations Pre-filtered value.
	 * @param string|false      $file         Candidate file.
	 */
	public function provide_script_translations( $translations, $file, $handle, $domain ) {
		if ( null !== $translations || false !== $file || ! is_string( $domain ) ) {
			return $translations;
		}
		$entries = $this->script_entries( $domain );
		if ( ! $entries ) {
			return $translations;
		}
		$locale   = $this->current_locale();
		$messages = array(
			'' => array(
				'domain'       => 'messages',
				'lang'         => $locale,
				'plural-forms' => Plural_Rules::header( $locale ),
			),
		);
		foreach ( $entries as $key => $forms ) {
			$messages[ (string) $key ] = array_values( $forms );
		}
		return self::json(
			array(
				'translation-revision-date' => gmdate( 'Y-m-d H:i:sO' ),
				'generator'                 => 'cradle-translations/' . CRADLE_TRANSLATIONS_VERSION,
				'domain'                    => 'messages',
				'locale_data'               => array( 'messages' => $messages ),
			)
		);
	}

	/**
	 * Merges overrides into a shipped Jed JSON file's contents.
	 *
	 * @param string|false $translations JSON read from the file.
	 */
	public function merge_script_translations( $translations, $file, $handle, $domain ) {
		if ( ! is_string( $translations ) || '' === $translations || ! is_string( $domain ) ) {
			return $translations;
		}
		$entries = $this->script_entries( $domain );
		if ( ! $entries ) {
			return $translations;
		}
		$data = json_decode( $translations, true, 16 );
		if ( ! is_array( $data ) || ! isset( $data['locale_data'] ) || ! is_array( $data['locale_data'] ) ) {
			return $translations;
		}
		$bucket = isset( $data['locale_data'][ $domain ] ) ? $domain : 'messages';
		if ( ! isset( $data['locale_data'][ $bucket ] ) || ! is_array( $data['locale_data'][ $bucket ] ) ) {
			$data['locale_data'][ $bucket ] = array(
				'' => array(
					'domain'       => $bucket,
					'lang'         => $this->current_locale(),
					'plural-forms' => Plural_Rules::header( $this->current_locale() ),
				),
			);
		}
		$header         = (string) ( $data['locale_data'][ $bucket ]['']['plural-forms'] ?? $data['locale_data'][ $bucket ]['']['Plural-Forms'] ?? '' );
		$shipped_forms  = Plural_Rules::header_nplurals( $header );
		$compatible_pls = null === $shipped_forms || Plural_Rules::nplurals( $this->current_locale() ) === $shipped_forms;
		foreach ( $entries as $key => $forms ) {
			if ( count( $forms ) > 1 && ! $compatible_pls ) {
				continue; // Shipped plural rule differs from ours; keep the shipped plural forms.
			}
			$data['locale_data'][ $bucket ][ (string) $key ] = array_values( $forms );
		}
		return self::json( $data );
	}

	/**
	 * Offers locales with published overrides as available languages, so users and the site can
	 * select them and switch_to_locale() accepts them even without a WordPress language pack.
	 *
	 * @param string[]    $languages Installed languages.
	 * @param string|null $dir       Directory scanned.
	 */
	public function available_languages( $languages, $dir = null ) {
		if ( ! is_array( $languages ) || ( null !== $dir && wp_normalize_path( untrailingslashit( (string) $dir ) ) !== wp_normalize_path( untrailingslashit( WP_LANG_DIR ) ) ) ) {
			return $languages;
		}
		if ( ! Config::get( 'expose_locales' ) || ! Config::get( 'runtime_enabled' ) ) {
			return $languages;
		}
		foreach ( array_keys( $this->index()['locales'] ) as $locale ) {
			if ( 'en_US' !== $locale && Config::valid_locale( (string) $locale ) && ! in_array( $locale, $languages, true ) ) {
				$languages[] = (string) $locale;
			}
		}
		return $languages;
	}

	/**
	 * `cradle/translate_label` filter: Cradle-registered labels (module names, capability labels,
	 * configuration labels) resolved through the same override → native → original chain.
	 */
	public function translate_label( $text, $context = '', $domain = 'cradle' ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return $text;
		}
		$domain = is_string( $domain ) && Config::valid_domain( $domain ) ? $domain : 'cradle';
		// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralContext, WordPress.WP.I18n.NonSingularStringLiteralDomain -- runtime lookup of registered labels.
		$native = is_string( $context ) && '' !== $context ? translate_with_gettext_context( $text, $context, $domain ) : translate( $text, $domain );
		// phpcs:enable
		return is_string( $native ) ? $native : $text;
	}
}
