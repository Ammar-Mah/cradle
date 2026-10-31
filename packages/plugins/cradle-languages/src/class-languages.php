<?php
/**
 * The configured languages (ordered) and the language of the current request.
 *
 * Read from the stored option directly and re-validated, because routing starts at
 * `cradle/ready` before settings sections are registered. Never writes. Without a saved list
 * there is exactly one language: the site language, which is the default.
 *
 * A language row: slug (URL prefix and term slug), locale, name, native, dir (ltr|rtl), default.
 *
 * @package Cradle\Languages
 */

namespace Cradle\Languages;

defined( 'ABSPATH' ) || exit;

final class Languages {

	const MAX = 10;

	const SLUG_PATTERN = '/^[a-z]{2,3}(?:-[a-z]{2})?$/';

	const LOCALE_PATTERN = '/^[a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z0-9]{2,12})?$/';

	/** Languages written right to left (ISO 639 codes). */
	const RTL = array( 'ar', 'arc', 'ckb', 'dv', 'fa', 'ha', 'he', 'khw', 'ks', 'ps', 'sd', 'ug', 'ur', 'yi' );

	/**
	 * Common languages: code => [English name, native name]. Used for defaults and suggestions
	 * only; administrators may configure any locale.
	 */
	const KNOWN = array(
		'ar' => array( 'Arabic', 'العربية' ),
		'bg' => array( 'Bulgarian', 'Български' ),
		'bn' => array( 'Bengali', 'বাংলা' ),
		'ca' => array( 'Catalan', 'Català' ),
		'cs' => array( 'Czech', 'Čeština' ),
		'da' => array( 'Danish', 'Dansk' ),
		'de' => array( 'German', 'Deutsch' ),
		'el' => array( 'Greek', 'Ελληνικά' ),
		'en' => array( 'English', 'English' ),
		'es' => array( 'Spanish', 'Español' ),
		'et' => array( 'Estonian', 'Eesti' ),
		'fa' => array( 'Persian', 'فارسی' ),
		'fi' => array( 'Finnish', 'Suomi' ),
		'fr' => array( 'French', 'Français' ),
		'he' => array( 'Hebrew', 'עברית' ),
		'hi' => array( 'Hindi', 'हिन्दी' ),
		'hr' => array( 'Croatian', 'Hrvatski' ),
		'hu' => array( 'Hungarian', 'Magyar' ),
		'id' => array( 'Indonesian', 'Bahasa Indonesia' ),
		'it' => array( 'Italian', 'Italiano' ),
		'ja' => array( 'Japanese', '日本語' ),
		'ko' => array( 'Korean', '한국어' ),
		'ku' => array( 'Kurdish', 'Kurdî' ),
		'lt' => array( 'Lithuanian', 'Lietuvių' ),
		'lv' => array( 'Latvian', 'Latviešu' ),
		'nb' => array( 'Norwegian', 'Norsk bokmål' ),
		'nl' => array( 'Dutch', 'Nederlands' ),
		'pl' => array( 'Polish', 'Polski' ),
		'pt' => array( 'Portuguese', 'Português' ),
		'ro' => array( 'Romanian', 'Română' ),
		'ru' => array( 'Russian', 'Русский' ),
		'sk' => array( 'Slovak', 'Slovenčina' ),
		'sl' => array( 'Slovenian', 'Slovenščina' ),
		'sq' => array( 'Albanian', 'Shqip' ),
		'sr' => array( 'Serbian', 'Српски' ),
		'sv' => array( 'Swedish', 'Svenska' ),
		'sw' => array( 'Swahili', 'Kiswahili' ),
		'th' => array( 'Thai', 'ไทย' ),
		'tr' => array( 'Turkish', 'Türkçe' ),
		'uk' => array( 'Ukrainian', 'Українська' ),
		'ur' => array( 'Urdu', 'اردو' ),
		'vi' => array( 'Vietnamese', 'Tiếng Việt' ),
		'zh' => array( 'Chinese', '中文' ),
	);

	/** @var array<string,array>|null */
	private static $all = null;

	/** @var string[] Stack of switched language slugs. */
	private static $stack = array();

	/**
	 * Configured languages in display order, keyed by slug.
	 *
	 * @return array<string,array{slug:string,locale:string,name:string,native:string,dir:string,default:bool}>
	 */
	public static function all(): array {
		if ( null === self::$all ) {
			$stored    = get_option( Config::OPTION, array() );
			$rows      = is_array( $stored ) && is_array( $stored['languages'] ?? null ) ? $stored['languages'] : array();
			self::$all = self::normalize_rows( $rows );
			if ( ! self::$all ) {
				$fallback                      = self::site_language();
				self::$all[ $fallback['slug'] ] = $fallback;
			}
		}
		return self::$all;
	}

	/**
	 * Re-validates stored rows; invalid rows are skipped, duplicates dropped, exactly one default.
	 *
	 * @param array $rows Stored list.
	 * @return array<string,array>
	 */
	public static function normalize_rows( array $rows ): array {
		$out     = array();
		$locales = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || count( $out ) >= self::MAX ) {
				continue;
			}
			$slug   = is_string( $row['slug'] ?? null ) ? $row['slug'] : '';
			$locale = is_string( $row['locale'] ?? null ) ? $row['locale'] : '';
			if ( ! preg_match( self::SLUG_PATTERN, $slug ) || ! preg_match( self::LOCALE_PATTERN, $locale ) || isset( $out[ $slug ] ) || isset( $locales[ $locale ] ) ) {
				continue;
			}
			$known         = self::KNOWN[ self::language_code( $locale ) ] ?? array( $locale, $locale );
			$name          = is_string( $row['name'] ?? null ) && '' !== trim( $row['name'] ) ? trim( $row['name'] ) : $known[0];
			$native        = is_string( $row['native'] ?? null ) && '' !== trim( $row['native'] ) ? trim( $row['native'] ) : $name;
			$out[ $slug ]  = array(
				'slug'    => $slug,
				'locale'  => $locale,
				'name'    => mb_substr( $name, 0, 60 ),
				'native'  => mb_substr( $native, 0, 60 ),
				'dir'     => 'rtl' === ( $row['dir'] ?? '' ) ? 'rtl' : 'ltr',
				'default' => true === ( $row['default'] ?? false ),
			);
			$locales[ $locale ] = true;
		}
		$defaults = array_keys( array_filter( $out, static fn( $row ) => $row['default'] ) );
		if ( $out && 1 !== count( $defaults ) ) {
			$first = $defaults[0] ?? array_key_first( $out );
			foreach ( $out as $slug => $row ) {
				$out[ $slug ]['default'] = ( $slug === $first );
			}
		}
		return $out;
	}

	/**
	 * The site language as a single default language (used until languages are saved).
	 */
	public static function site_language(): array {
		$locale = (string) get_option( 'WPLANG', '' );
		$locale = preg_match( self::LOCALE_PATTERN, $locale ) ? $locale : 'en_US';
		$code   = self::language_code( $locale );
		$known  = self::KNOWN[ $code ] ?? array( $locale, $locale );
		return array(
			'slug'    => preg_match( self::SLUG_PATTERN, $code ) ? $code : 'en',
			'locale'  => $locale,
			'name'    => $known[0],
			'native'  => $known[1],
			'dir'     => self::default_dir( $locale ),
			'default' => true,
		);
	}

	/** ISO 639 part of a locale (`ar` for ar, `pt` for pt_BR). */
	public static function language_code( string $locale ): string {
		return strtolower( (string) strtok( $locale, '_' ) );
	}

	public static function default_dir( string $locale ): string {
		return in_array( self::language_code( $locale ), self::RTL, true ) ? 'rtl' : 'ltr';
	}

	/** BCP 47 tag for hreflang / lang attributes (`en-US`, `ar`). */
	public static function bcp47( array $lang ): string {
		$parts = explode( '_', $lang['locale'] );
		$tag   = strtolower( $parts[0] );
		if ( isset( $parts[1] ) && preg_match( '/^[A-Z]{2}$/', $parts[1] ) ) {
			$tag .= '-' . $parts[1];
		}
		return $tag;
	}

	public static function flush() {
		self::$all = null;
	}

	/** @return string[] */
	public static function slugs(): array {
		return array_keys( self::all() );
	}

	public static function get( string $slug ): ?array {
		return self::all()[ $slug ] ?? null;
	}

	public static function exists( $slug ): bool {
		return is_string( $slug ) && isset( self::all()[ $slug ] );
	}

	public static function default_language(): array {
		foreach ( self::all() as $row ) {
			if ( $row['default'] ) {
				return $row;
			}
		}
		$all = self::all();
		return reset( $all );
	}

	public static function default_slug(): string {
		return self::default_language()['slug'];
	}

	public static function is_multilingual(): bool {
		return count( self::all() ) > 1;
	}

	public static function hide_default_prefix(): bool {
		$stored = get_option( Config::OPTION, array() );
		return ! ( is_array( $stored ) && array_key_exists( 'hide_default_prefix', $stored ) && false === $stored['hide_default_prefix'] );
	}

	/** Whether URLs of this language carry a /slug/ prefix. */
	public static function needs_prefix( array $lang ): bool {
		return ! $lang['default'] || ! self::hide_default_prefix();
	}

	/**
	 * Slugs that can appear as the first path segment of a URL.
	 *
	 * @return string[]
	 */
	public static function prefixed_slugs(): array {
		return array_values( array_map( static fn( $row ) => $row['slug'], array_filter( self::all(), array( __CLASS__, 'needs_prefix' ) ) ) );
	}

	/**
	 * Language of the current request on the frontend; elsewhere the default language
	 * (or the language switched to with switch_to()).
	 */
	public static function current(): array {
		if ( self::$stack ) {
			$slug = end( self::$stack );
			if ( isset( self::all()[ $slug ] ) ) {
				return self::all()[ $slug ];
			}
		}
		$slug = Request::language_slug();
		return ( '' !== $slug && isset( self::all()[ $slug ] ) ) ? self::all()[ $slug ] : self::default_language();
	}

	public static function current_slug(): string {
		return self::current()['slug'];
	}

	/**
	 * Temporarily treats another language as current (links, switcher items, rendering in tests
	 * and sitemaps). Does not change the WordPress locale. Pair with restore().
	 */
	public static function switch_to( string $slug ): bool {
		if ( ! self::exists( $slug ) ) {
			return false;
		}
		self::$stack[] = $slug;
		return true;
	}

	public static function restore() {
		array_pop( self::$stack );
	}
}
