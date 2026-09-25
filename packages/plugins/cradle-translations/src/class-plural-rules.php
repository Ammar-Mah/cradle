<?php
/**
 * Structured plural rules per language.
 *
 * Plural selection is implemented as trusted PHP arithmetic per rule ID. A
 * `Plural-Forms` expression found in an imported catalog is never evaluated:
 * only its `nplurals` integer is read for comparison. The expression strings
 * below are trusted constants emitted as JavaScript (Jed) headers, where the
 * WordPress i18n runtime parses them with its own non-eval parser.
 *
 * @package Cradle\Translations
 */

namespace Cradle\Translations;

defined( 'ABSPATH' ) || exit;

final class Plural_Rules {

	/** Rule ID => gettext header parts. */
	const RULES = array(
		'one_other'   => array( 2, 'n != 1' ),
		'one_gt1'     => array( 2, 'n > 1' ),
		'none'        => array( 1, '0' ),
		'east_slavic' => array( 3, '(n % 10 == 1 && n % 100 != 11) ? 0 : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) ? 1 : 2)' ),
		'polish'      => array( 3, '(n == 1) ? 0 : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) ? 1 : 2)' ),
		'czech'       => array( 3, '(n == 1) ? 0 : ((n >= 2 && n <= 4) ? 1 : 2)' ),
		'arabic'      => array( 6, '(n == 0) ? 0 : ((n == 1) ? 1 : ((n == 2) ? 2 : ((n % 100 >= 3 && n % 100 <= 10) ? 3 : ((n % 100 >= 11 && n % 100 <= 99) ? 4 : 5))))' ),
		'lithuanian'  => array( 3, '(n % 10 == 1 && (n % 100 < 11 || n % 100 > 19)) ? 0 : ((n % 10 >= 2 && n % 10 <= 9 && (n % 100 < 11 || n % 100 > 19)) ? 1 : 2)' ),
		'latvian'     => array( 3, '(n % 10 == 1 && n % 100 != 11) ? 0 : ((n != 0) ? 1 : 2)' ),
		'romanian'    => array( 3, '(n == 1) ? 0 : ((n == 0 || (n % 100 > 0 && n % 100 < 20)) ? 1 : 2)' ),
		'slovenian'   => array( 4, '(n % 100 == 1) ? 0 : ((n % 100 == 2) ? 1 : ((n % 100 == 3 || n % 100 == 4) ? 2 : 3))' ),
		'irish'       => array( 5, '(n == 1) ? 0 : ((n == 2) ? 1 : ((n > 2 && n < 7) ? 2 : ((n > 6 && n < 11) ? 3 : 4)))' ),
		'welsh'       => array( 4, '(n == 1) ? 0 : ((n == 2) ? 1 : ((n != 8 && n != 11) ? 2 : 3))' ),
		'icelandic'   => array( 2, 'n % 10 != 1 || n % 100 == 11' ),
		'macedonian'  => array( 2, '(n % 10 == 1 && n % 100 != 11) ? 0 : 1' ),
	);

	/** Locale or language code => [rule ID, autonym]. Exact locale entries win over the language. */
	const LANGUAGES = array(
		'en'    => array( 'one_other', 'English' ),
		'de'    => array( 'one_other', 'Deutsch' ),
		'nl'    => array( 'one_other', 'Nederlands' ),
		'sv'    => array( 'one_other', 'Svenska' ),
		'da'    => array( 'one_other', 'Dansk' ),
		'nb'    => array( 'one_other', 'Norsk bokmål' ),
		'nn'    => array( 'one_other', 'Norsk nynorsk' ),
		'fi'    => array( 'one_other', 'Suomi' ),
		'et'    => array( 'one_other', 'Eesti' ),
		'it'    => array( 'one_other', 'Italiano' ),
		'es'    => array( 'one_other', 'Español' ),
		'pt'    => array( 'one_other', 'Português' ),
		'el'    => array( 'one_other', 'Ελληνικά' ),
		'hu'    => array( 'one_other', 'Magyar' ),
		'bg'    => array( 'one_other', 'Български' ),
		'ca'    => array( 'one_other', 'Català' ),
		'eu'    => array( 'one_other', 'Euskara' ),
		'gl'    => array( 'one_other', 'Galego' ),
		'he'    => array( 'one_other', 'עִבְרִית' ),
		'hi'    => array( 'one_other', 'हिन्दी' ),
		'bn'    => array( 'one_other', 'বাংলা' ),
		'ur'    => array( 'one_other', 'اردو' ),
		'sq'    => array( 'one_other', 'Shqip' ),
		'af'    => array( 'one_other', 'Afrikaans' ),
		'az'    => array( 'one_other', 'Azərbaycan dili' ),
		'eo'    => array( 'one_other', 'Esperanto' ),
		'ka'    => array( 'one_other', 'ქართული' ),
		'kk'    => array( 'one_other', 'Қазақ тілі' ),
		'ml'    => array( 'one_other', 'മലയാളം' ),
		'mn'    => array( 'one_other', 'Монгол' ),
		'mr'    => array( 'one_other', 'मराठी' ),
		'ne'    => array( 'one_other', 'नेपाली' ),
		'ps'    => array( 'one_other', 'پښتو' ),
		'pa'    => array( 'one_other', 'ਪੰਜਾਬੀ' ),
		'ta'    => array( 'one_other', 'தமிழ்' ),
		'te'    => array( 'one_other', 'తెలుగు' ),
		'sw'    => array( 'one_other', 'Kiswahili' ),
		'gu'    => array( 'one_other', 'ગુજરાતી' ),
		'kn'    => array( 'one_other', 'ಕನ್ನಡ' ),
		'si'    => array( 'one_other', 'සිංහල' ),
		'fr'    => array( 'one_gt1', 'Français' ),
		'pt_BR' => array( 'one_gt1', 'Português do Brasil' ),
		'tr'    => array( 'one_gt1', 'Türkçe' ),
		'fa'    => array( 'one_gt1', 'فارسی' ),
		'oc'    => array( 'one_gt1', 'Occitan' ),
		'fil'   => array( 'one_gt1', 'Filipino' ),
		'ja'    => array( 'none', '日本語' ),
		'zh'    => array( 'none', '中文' ),
		'ko'    => array( 'none', '한국어' ),
		'vi'    => array( 'none', 'Tiếng Việt' ),
		'th'    => array( 'none', 'ไทย' ),
		'id'    => array( 'none', 'Bahasa Indonesia' ),
		'ms'    => array( 'none', 'Bahasa Melayu' ),
		'lo'    => array( 'none', 'ພາສາລາວ' ),
		'my'    => array( 'none', 'ဗမာစာ' ),
		'km'    => array( 'none', 'ភាសាខ្មែរ' ),
		'ru'    => array( 'east_slavic', 'Русский' ),
		'uk'    => array( 'east_slavic', 'Українська' ),
		'be'    => array( 'east_slavic', 'Беларуская' ),
		'sr'    => array( 'east_slavic', 'Српски' ),
		'hr'    => array( 'east_slavic', 'Hrvatski' ),
		'bs'    => array( 'east_slavic', 'Bosanski' ),
		'pl'    => array( 'polish', 'Polski' ),
		'cs'    => array( 'czech', 'Čeština' ),
		'sk'    => array( 'czech', 'Slovenčina' ),
		'ar'    => array( 'arabic', 'العربية' ),
		'ary'   => array( 'arabic', 'العربية المغربية' ),
		'lt'    => array( 'lithuanian', 'Lietuvių' ),
		'lv'    => array( 'latvian', 'Latviešu' ),
		'ro'    => array( 'romanian', 'Română' ),
		'sl'    => array( 'slovenian', 'Slovenščina' ),
		'ga'    => array( 'irish', 'Gaelige' ),
		'cy'    => array( 'welsh', 'Cymraeg' ),
		'is'    => array( 'icelandic', 'Íslenska' ),
		'mk'    => array( 'macedonian', 'Македонски' ),
	);

	const RTL_LANGUAGES = array( 'ar', 'ary', 'he', 'fa', 'ur', 'ps', 'ug', 'dv', 'ckb', 'sd', 'yi', 'haz', 'azb' );

	private static function lookup( string $locale ): ?array {
		if ( isset( self::LANGUAGES[ $locale ] ) ) {
			return self::LANGUAGES[ $locale ];
		}
		$parts = explode( '_', $locale );
		if ( isset( $parts[1] ) && isset( self::LANGUAGES[ $parts[0] . '_' . $parts[1] ] ) ) {
			return self::LANGUAGES[ $parts[0] . '_' . $parts[1] ];
		}
		return self::LANGUAGES[ $parts[0] ] ?? null;
	}

	/**
	 * Whether the plural rule of a locale is known (unknown locales use the two-form default).
	 */
	public static function is_known( string $locale ): bool {
		return null !== self::lookup( $locale );
	}

	public static function rule_id( string $locale ): string {
		$row = self::lookup( $locale );
		return $row ? $row[0] : 'one_other';
	}

	public static function autonym( string $locale ): string {
		$row = self::lookup( $locale );
		return $row ? $row[1] : '';
	}

	public static function is_rtl( string $locale ): bool {
		return in_array( explode( '_', $locale )[0], self::RTL_LANGUAGES, true );
	}

	public static function nplurals( string $locale ): int {
		return self::RULES[ self::rule_id( $locale ) ][0];
	}

	/**
	 * Gettext/Jed header, for example `nplurals=2; plural=(n != 1);`. Trusted constant.
	 */
	public static function header( string $locale ): string {
		$rule = self::RULES[ self::rule_id( $locale ) ];
		return sprintf( 'nplurals=%d; plural=(%s);', $rule[0], $rule[1] );
	}

	/**
	 * Selects the plural form index for a number. Pure arithmetic, no expression evaluation.
	 *
	 * @param int|float|string $number Count passed to _n()/_nx().
	 */
	public static function index( string $locale, $number ): int {
		$n = is_numeric( $number ) ? (int) min( PHP_INT_MAX, abs( (float) $number ) ) : 1;
		return self::index_for_rule( self::rule_id( $locale ), $n );
	}

	public static function index_for_rule( string $rule, int $n ): int {
		$n10  = $n % 10;
		$n100 = $n % 100;
		switch ( $rule ) {
			case 'none':
				return 0;
			case 'one_gt1':
				return $n > 1 ? 1 : 0;
			case 'east_slavic':
				if ( 1 === $n10 && 11 !== $n100 ) {
					return 0;
				}
				return ( $n10 >= 2 && $n10 <= 4 && ( $n100 < 12 || $n100 > 14 ) ) ? 1 : 2;
			case 'polish':
				if ( 1 === $n ) {
					return 0;
				}
				return ( $n10 >= 2 && $n10 <= 4 && ( $n100 < 12 || $n100 > 14 ) ) ? 1 : 2;
			case 'czech':
				if ( 1 === $n ) {
					return 0;
				}
				return ( $n >= 2 && $n <= 4 ) ? 1 : 2;
			case 'arabic':
				if ( 0 === $n ) {
					return 0;
				}
				if ( 1 === $n ) {
					return 1;
				}
				if ( 2 === $n ) {
					return 2;
				}
				if ( $n100 >= 3 && $n100 <= 10 ) {
					return 3;
				}
				return ( $n100 >= 11 && $n100 <= 99 ) ? 4 : 5;
			case 'lithuanian':
				if ( 1 === $n10 && ( $n100 < 11 || $n100 > 19 ) ) {
					return 0;
				}
				return ( $n10 >= 2 && $n10 <= 9 && ( $n100 < 11 || $n100 > 19 ) ) ? 1 : 2;
			case 'latvian':
				if ( 1 === $n10 && 11 !== $n100 ) {
					return 0;
				}
				return 0 !== $n ? 1 : 2;
			case 'romanian':
				if ( 1 === $n ) {
					return 0;
				}
				return ( 0 === $n || ( $n100 > 0 && $n100 < 20 ) ) ? 1 : 2;
			case 'slovenian':
				if ( 1 === $n100 ) {
					return 0;
				}
				if ( 2 === $n100 ) {
					return 1;
				}
				return ( 3 === $n100 || 4 === $n100 ) ? 2 : 3;
			case 'irish':
				if ( 1 === $n ) {
					return 0;
				}
				if ( 2 === $n ) {
					return 1;
				}
				if ( $n > 2 && $n < 7 ) {
					return 2;
				}
				return ( $n > 6 && $n < 11 ) ? 3 : 4;
			case 'welsh':
				if ( 1 === $n ) {
					return 0;
				}
				if ( 2 === $n ) {
					return 1;
				}
				return ( 8 !== $n && 11 !== $n ) ? 2 : 3;
			case 'icelandic':
				return ( 1 !== $n10 || 11 === $n100 ) ? 1 : 0;
			case 'macedonian':
				return ( 1 === $n10 && 11 !== $n100 ) ? 0 : 1;
			case 'one_other':
			default:
				return 1 === $n ? 0 : 1;
		}
	}

	/**
	 * Example numbers for each plural form, for editor labels.
	 *
	 * @return array<int,int[]>
	 */
	public static function samples( string $locale, int $per_form = 4 ): array {
		$rule    = self::rule_id( $locale );
		$count   = self::RULES[ $rule ][0];
		$samples = array_fill( 0, $count, array() );
		for ( $n = 0; $n <= 1000; $n++ ) {
			$i = self::index_for_rule( $rule, $n );
			if ( count( $samples[ $i ] ) < $per_form ) {
				$samples[ $i ][] = $n;
			}
		}
		return $samples;
	}

	/**
	 * Reads only the integer `nplurals` from an untrusted Plural-Forms header.
	 */
	public static function header_nplurals( string $header ): ?int {
		return preg_match( '/nplurals\s*=\s*(\d{1,2})\s*;/', $header, $m ) ? (int) $m[1] : null;
	}
}
