<?php
/**
 * Validation for translator input and catalog files.
 *
 * Validate first, normalize second: every check rejects instead of silently
 * rewriting. Translator input is untrusted data. It is never evaluated,
 * included or written as PHP, and it is escaped by the caller's output context
 * after translation (esc_html__(), esc_attr__() and friends).
 *
 * @package Cradle\Translations
 */

namespace Cradle\Translations;

defined( 'ABSPATH' ) || exit;

final class Validator {

	/**
	 * PHP sprintf() conversion specification: %[argnum$][flags][width][.precision]specifier.
	 * Groups: 1 argnum, 2 flags, 3 width, 4 precision, 5 specifier.
	 */
	const PLACEHOLDER_REGEX = '/%(?:(\d+)\$)?((?:[-+ 0#]|\'.)*)(\d+)?(?:\.(\d+))?([bcdeEfFgGosuxX%])/';

	/** Approved HTML subset: tag => allowed attributes. */
	const HTML_ALLOWED = array(
		'a'      => array( 'href', 'title', 'target', 'rel', 'hreflang' ),
		'abbr'   => array( 'title' ),
		'b'      => array(),
		'bdi'    => array( 'dir' ),
		'bdo'    => array( 'dir' ),
		'br'     => array(),
		'cite'   => array(),
		'code'   => array(),
		'del'    => array(),
		'em'     => array(),
		'i'      => array(),
		'ins'    => array(),
		'kbd'    => array(),
		'mark'   => array(),
		'p'      => array(),
		'q'      => array(),
		's'      => array(),
		'samp'   => array(),
		'small'  => array(),
		'span'   => array( 'class', 'dir', 'lang' ),
		'strong' => array(),
		'sub'    => array(),
		'sup'    => array(),
		'time'   => array( 'datetime' ),
		'u'      => array(),
		'var'    => array(),
	);

	/** Tags a translation may add although the source does not use them (line breaks, bidi isolation). */
	const HTML_FREE = array( 'br', 'bdi', 'bdo' );

	/** Never accepted in a translation, even when present in the source. */
	const HTML_FORBIDDEN = array( 'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'textarea', 'select', 'option', 'link', 'meta', 'base', 'svg', 'math', 'template', 'noscript', 'xmp', 'plaintext', 'param', 'source', 'video', 'audio', 'img', 'picture', 'dialog', 'marquee', 'html', 'head', 'body', 'title' );

	const TAG_REGEX  = '/<(\/?)([A-Za-z][A-Za-z0-9-]*)((?:\s+[^\s>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?)*)\s*(\/?)>/';
	const ATTR_REGEX = '/([^\s>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/';

	const ENTRY_KEYS   = array( 'id', 'msgid', 'context', 'msgidPlural', 'translations', 'draft', 'draftFuzzy', 'status', 'fuzzy', 'obsolete', 'source', 'references', 'comments', 'js', 'changedRevision', 'updated' );
	const CATALOG_KEYS = array( 'schemaVersion', 'locale', 'revision', 'publishedRevision', 'updated', 'generator', 'exported', 'pluralRule', 'domains', 'log' );
	const STATUSES     = array( 'missing', 'translated', 'fuzzy', 'obsolete' );
	const SOURCES      = array( 'extracted', 'imported', 'manual', 'registered' );

	/**
	 * Checks one string: type, UTF-8, length, control characters.
	 *
	 * @return true|\WP_Error
	 */
	public static function check_text( $value, string $what, bool $allow_empty = true ) {
		if ( ! is_string( $value ) ) {
			/* translators: %s: field name */
			return new \WP_Error( 'cradle_translations_type', sprintf( __( '%s must be a string.', 'cradle-translations' ), $what ) );
		}
		if ( ! $allow_empty && '' === $value ) {
			/* translators: %s: field name */
			return new \WP_Error( 'cradle_translations_empty', sprintf( __( '%s must not be empty.', 'cradle-translations' ), $what ) );
		}
		if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
			/* translators: %s: field name */
			return new \WP_Error( 'cradle_translations_encoding', sprintf( __( '%s is not valid UTF-8.', 'cradle-translations' ), $what ) );
		}
		if ( mb_strlen( $value, 'UTF-8' ) > Config::MAX_STRING_CHARS ) {
			/* translators: 1: field name, 2: maximum number of characters */
			return new \WP_Error( 'cradle_translations_length', sprintf( __( '%1$s is longer than %2$d characters.', 'cradle-translations' ), $what, Config::MAX_STRING_CHARS ) );
		}
		if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ) {
			/* translators: %s: field name */
			return new \WP_Error( 'cradle_translations_control', sprintf( __( '%s contains control characters.', 'cradle-translations' ), $what ) );
		}
		return true;
	}

	private static function type_class( string $specifier ): string {
		switch ( $specifier ) {
			case 'd':
			case 'u':
				return 'd';
			case 'e':
			case 'E':
			case 'f':
			case 'F':
			case 'g':
			case 'G':
				return 'f';
			default:
				return $specifier;
		}
	}

	/**
	 * Parses printf placeholders.
	 *
	 * @param bool $strict_only Only count bare placeholders (%s, %2$d) without flags/width: used when the
	 *                          source is not a printf format, so "50 % de réduction" is plain text.
	 * @return array{args:array<int,string>,tokens:array<int,string>,numbered:bool,unnumbered:bool,literal:int,stray:bool,invalid:bool}
	 */
	public static function placeholders( string $text, bool $strict_only = false ): array {
		$out = array(
			'args'       => array(),
			'tokens'     => array(),
			'numbered'   => false,
			'unnumbered' => false,
			'literal'    => 0,
			'stray'      => false,
			'invalid'    => false,
		);
		if ( false === strpos( $text, '%' ) ) {
			return $out;
		}
		preg_match_all( self::PLACEHOLDER_REGEX, $text, $matches, PREG_SET_ORDER );
		$next = 1;
		foreach ( $matches as $m ) {
			$specifier = $m[5];
			$modifiers = '' !== $m[2] || '' !== $m[3] || '' !== $m[4];
			if ( '%' === $specifier ) {
				++$out['literal'];
				continue;
			}
			if ( $strict_only && $modifiers ) {
				continue;
			}
			$type = self::type_class( $specifier );
			if ( '' !== $m[1] ) {
				$out['numbered'] = true;
				$position        = (int) $m[1];
				if ( $position < 1 || $position > 99 ) {
					$out['invalid'] = true;
					continue;
				}
			} else {
				$out['unnumbered'] = true;
				$position          = $next++;
			}
			if ( isset( $out['args'][ $position ] ) && $out['args'][ $position ] !== $type ) {
				$out['invalid'] = true;
			}
			$out['args'][ $position ] = $type;
			$out['tokens'][]          = $m[0];
		}
		$out['stray'] = false !== strpos( (string) preg_replace( self::PLACEHOLDER_REGEX, '', $text ), '%' );
		ksort( $out['args'] );
		return $out;
	}

	/**
	 * Placeholder tokens of a source entry, for editor hints.
	 *
	 * @return string[]
	 */
	public static function source_tokens( array $entry ): array {
		$tokens = array();
		foreach ( array( $entry['msgid'], $entry['msgidPlural'] ?? null ) as $text ) {
			if ( is_string( $text ) ) {
				$tokens = array_merge( $tokens, self::placeholders( $text )['tokens'] );
			}
		}
		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Placeholder compatibility between the source and translated forms.
	 *
	 * Rules: a non-plural translation uses exactly the source arguments (numbered reordering is fine);
	 * plural forms may omit arguments but never add one or change its type; numbered and unnumbered
	 * placeholders cannot be mixed; a printf source needs %% for a literal percent sign; a non-printf
	 * source must not gain placeholders.
	 *
	 * @param string[] $forms Non-empty translated forms.
	 * @return true|\WP_Error
	 */
	public static function check_placeholders( array $entry, array $forms ) {
		$is_plural = null !== ( $entry['msgidPlural'] ?? null );
		$printf    = false;
		$source    = array();
		foreach ( array( $entry['msgid'], $entry['msgidPlural'] ?? null ) as $text ) {
			if ( ! is_string( $text ) ) {
				continue;
			}
			$parsed = self::placeholders( $text );
			if ( $parsed['args'] || $parsed['literal'] ) {
				$printf = true;
			}
			foreach ( $parsed['args'] as $position => $type ) {
				$source[ $position ] = $type;
			}
		}
		foreach ( $forms as $i => $form ) {
			/* translators: %d: plural form number */
			$where  = $is_plural ? sprintf( __( 'Plural form %d', 'cradle-translations' ), $i + 1 ) : __( 'Translation', 'cradle-translations' );
			$parsed = self::placeholders( $form, ! $printf );
			if ( ! $printf ) {
				if ( $parsed['args'] ) {
					/* translators: 1: where, 2: placeholder such as %s */
					return new \WP_Error( 'cradle_translations_placeholder', sprintf( __( '%1$s adds the placeholder %2$s, but the source text has no placeholders.', 'cradle-translations' ), $where, $parsed['tokens'][0] ) );
				}
				continue;
			}
			if ( $parsed['invalid'] ) {
				/* translators: %s: where */
				return new \WP_Error( 'cradle_translations_placeholder', sprintf( __( '%s contains an invalid or conflicting placeholder.', 'cradle-translations' ), $where ) );
			}
			if ( $parsed['numbered'] && $parsed['unnumbered'] ) {
				/* translators: %s: where */
				return new \WP_Error( 'cradle_translations_placeholder', sprintf( __( '%s mixes numbered (%%1$s) and unnumbered (%%s) placeholders.', 'cradle-translations' ), $where ) );
			}
			if ( $parsed['stray'] ) {
				/* translators: %s: where */
				return new \WP_Error( 'cradle_translations_placeholder', sprintf( __( '%s contains a percent sign that is not a placeholder. Write %%%% for a literal percent sign.', 'cradle-translations' ), $where ) );
			}
			foreach ( $parsed['args'] as $position => $type ) {
				if ( ! isset( $source[ $position ] ) ) {
					/* translators: 1: where, 2: argument number */
					return new \WP_Error( 'cradle_translations_placeholder', sprintf( __( '%1$s uses placeholder argument %2$d, which the source text does not have.', 'cradle-translations' ), $where, $position ) );
				}
				if ( $source[ $position ] !== $type ) {
					/* translators: 1: where, 2: argument number, 3: expected type such as %s */
					return new \WP_Error( 'cradle_translations_placeholder', sprintf( __( '%1$s changes the type of placeholder argument %2$d; the source uses %3$s.', 'cradle-translations' ), $where, $position, '%' . $source[ $position ] ) );
				}
			}
			if ( ! $is_plural ) {
				foreach ( array_keys( $source ) as $position ) {
					if ( ! isset( $parsed['args'][ $position ] ) ) {
						/* translators: 1: where, 2: argument number */
						return new \WP_Error( 'cradle_translations_placeholder', sprintf( __( '%1$s is missing placeholder argument %2$d from the source text.', 'cradle-translations' ), $where, $position ) );
					}
				}
			}
		}
		return true;
	}

	/**
	 * Parses tags of a string.
	 *
	 * @return array<int,array{name:string,closing:bool,attrs:array<string,string|null>}>
	 */
	private static function tags( string $html ): array {
		$out = array();
		if ( false === strpos( $html, '<' ) ) {
			return $out;
		}
		preg_match_all( self::TAG_REGEX, $html, $matches, PREG_SET_ORDER );
		foreach ( $matches as $m ) {
			$attrs = array();
			if ( '' !== trim( $m[3] ) ) {
				preg_match_all( self::ATTR_REGEX, $m[3], $am, PREG_SET_ORDER );
				foreach ( $am as $a ) {
					$value                        = $a[2] ?? '';
					$value                        = '' !== $value ? $value : ( $a[3] ?? '' );
					$value                        = '' !== $value ? $value : ( $a[4] ?? '' );
					$attrs[ strtolower( $a[1] ) ] = isset( $a[2] ) ? $value : null;
				}
			}
			$out[] = array(
				'name'    => strtolower( $m[2] ),
				'closing' => '/' === $m[1],
				'attrs'   => $attrs,
			);
		}
		return $out;
	}

	/**
	 * Approved-HTML check: only allowlisted tags that the source already uses (plus line breaks and
	 * bidi isolation), only allowlisted attributes, link targets identical to the source.
	 *
	 * @return true|\WP_Error
	 */
	public static function check_html( array $entry, string $form ) {
		if ( false === strpos( $form, '<' ) ) {
			return true;
		}
		if ( preg_match( '/<\s*[!?]/', $form ) ) {
			return new \WP_Error( 'cradle_translations_html', __( 'Comments, processing instructions and declarations are not allowed in translations.', 'cradle-translations' ) );
		}
		$leftover = (string) preg_replace( self::TAG_REGEX, '', $form );
		if ( preg_match( '/<\/?[A-Za-z]/', $leftover ) ) {
			return new \WP_Error( 'cradle_translations_html', __( 'The translation contains malformed HTML. Write &lt; for a literal less-than sign.', 'cradle-translations' ) );
		}
		$source_text  = $entry['msgid'] . ' ' . ( $entry['msgidPlural'] ?? '' );
		$source_names = array();
		$source_pairs = array();
		$source_hrefs = array();
		foreach ( self::tags( $source_text ) as $tag ) {
			$source_names[ $tag['name'] ] = true;
			foreach ( $tag['attrs'] as $name => $value ) {
				$source_pairs[ $tag['name'] . '|' . $name . '=' . (string) $value ] = true;
				if ( 'a' === $tag['name'] && 'href' === $name ) {
					$source_hrefs[ (string) $value ] = true;
				}
			}
		}
		foreach ( self::tags( $form ) as $tag ) {
			$name = $tag['name'];
			if ( in_array( $name, self::HTML_FORBIDDEN, true ) ) {
				/* translators: %s: HTML tag name */
				return new \WP_Error( 'cradle_translations_html', sprintf( __( 'The <%s> tag is not allowed in translations.', 'cradle-translations' ), $name ) );
			}
			$known = isset( self::HTML_ALLOWED[ $name ] );
			if ( ! isset( $source_names[ $name ] ) && ! in_array( $name, self::HTML_FREE, true ) ) {
				/* translators: %s: HTML tag name */
				return new \WP_Error( 'cradle_translations_html', sprintf( __( 'The <%s> tag does not appear in the source text, so the translation cannot add it.', 'cradle-translations' ), $name ) );
			}
			if ( $tag['closing'] ) {
				continue;
			}
			foreach ( $tag['attrs'] as $attr => $value ) {
				$value = (string) $value;
				if ( isset( $source_pairs[ $name . '|' . $attr . '=' . $value ] ) && 0 !== strpos( $attr, 'on' ) ) {
					continue;
				}
				if ( ! $known || ! in_array( $attr, self::HTML_ALLOWED[ $name ], true ) ) {
					/* translators: 1: attribute name, 2: tag name */
					return new \WP_Error( 'cradle_translations_html', sprintf( __( 'The %1$s attribute is not allowed on <%2$s> in translations.', 'cradle-translations' ), $attr, $name ) );
				}
				$ok = true;
				switch ( $attr ) {
					case 'href':
						$ok = isset( $source_hrefs[ $value ] );
						if ( ! $ok ) {
							return new \WP_Error( 'cradle_translations_html', __( 'A link in the translation must point to the same address (or placeholder) as the source text.', 'cradle-translations' ) );
						}
						break;
					case 'target':
						$ok = '_blank' === $value;
						break;
					case 'rel':
						$ok = (bool) preg_match( '/^[a-z ]{1,60}$/', $value );
						break;
					case 'dir':
						$ok = in_array( $value, array( 'ltr', 'rtl', 'auto' ), true );
						break;
					case 'lang':
					case 'hreflang':
						$ok = (bool) preg_match( '/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/', $value );
						break;
					case 'class':
						$ok = (bool) preg_match( '/^[A-Za-z0-9_ -]{0,100}$/', $value );
						break;
					case 'datetime':
						$ok = (bool) preg_match( '/^[0-9T:+\-Z. ]{1,40}$/', $value );
						break;
					case 'title':
						$ok = false === strpbrk( $value, '<>' ) && mb_strlen( $value, 'UTF-8' ) <= 300;
						break;
				}
				if ( ! $ok ) {
					/* translators: 1: attribute name, 2: tag name */
					return new \WP_Error( 'cradle_translations_html', sprintf( __( 'The value of the %1$s attribute on <%2$s> is not allowed.', 'cradle-translations' ), $attr, $name ) );
				}
			}
		}
		return true;
	}

	/**
	 * Validates and normalizes translated forms for an entry in a locale.
	 *
	 * @param mixed $forms Submitted forms (list of strings).
	 * @return string[]|\WP_Error Normalized forms; an empty array clears the translation.
	 */
	public static function check_translation( array $entry, $forms, string $locale ) {
		if ( ! is_array( $forms ) || ! array_is_list( $forms ) ) {
			return new \WP_Error( 'cradle_translations_forms', __( 'Translations must be a list of strings.', 'cradle-translations' ) );
		}
		$is_plural = null !== ( $entry['msgidPlural'] ?? null );
		$expected  = $is_plural ? Plural_Rules::nplurals( $locale ) : 1;
		if ( count( $forms ) > max( $expected, 1 ) ) {
			/* translators: %d: number of forms */
			return new \WP_Error( 'cradle_translations_forms', sprintf( _n( 'This entry takes %d translated form in this locale.', 'This entry takes %d translated forms in this locale.', $expected, 'cradle-translations' ), $expected ) );
		}
		$normalized = array();
		foreach ( $forms as $form ) {
			$ok = self::check_text( $form, __( 'Translation', 'cradle-translations' ) );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$normalized[] = str_replace( array( "\r\n", "\r" ), "\n", $form );
		}
		$filled = count( array_filter( $normalized, static fn( $f ) => '' !== $f ) );
		if ( 0 === $filled ) {
			return array();
		}
		if ( $filled < $expected || count( $normalized ) < $expected ) {
			return new \WP_Error( 'cradle_translations_forms', __( 'Fill in every plural form, or leave all of them empty.', 'cradle-translations' ) );
		}
		foreach ( $normalized as $form ) {
			$html = self::check_html( $entry, $form );
			if ( is_wp_error( $html ) ) {
				return $html;
			}
		}
		$placeholders = self::check_placeholders( $entry, $normalized );
		if ( is_wp_error( $placeholders ) ) {
			return $placeholders;
		}
		return $normalized;
	}

	/**
	 * Bounded JSON decoding for untrusted catalog bytes.
	 *
	 * @return array|\WP_Error
	 */
	public static function decode_json( string $raw, int $max_bytes ) {
		if ( strlen( $raw ) > $max_bytes ) {
			/* translators: %s: size such as 4 MB */
			return new \WP_Error( 'cradle_translations_size', sprintf( __( 'The catalog is larger than the %s limit.', 'cradle-translations' ), size_format( $max_bytes ) ) );
		}
		if ( str_starts_with( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		}
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			return new \WP_Error( 'cradle_translations_encoding', __( 'The catalog is not valid UTF-8.', 'cradle-translations' ) );
		}
		$data = json_decode( $raw, true, Config::MAX_JSON_DEPTH );
		if ( JSON_ERROR_DEPTH === json_last_error() ) {
			return new \WP_Error( 'cradle_translations_depth', __( 'The catalog is nested too deeply.', 'cradle-translations' ) );
		}
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new \WP_Error( 'cradle_translations_json', __( 'The catalog is not valid JSON.', 'cradle-translations' ) );
		}
		return $data;
	}

	private static function schema_error( string $detail ): \WP_Error {
		/* translators: %s: description of the schema problem */
		return new \WP_Error( 'cradle_translations_schema', sprintf( __( 'The catalog does not match the Cradle catalog schema: %s', 'cradle-translations' ), $detail ) );
	}

	/**
	 * Keeps only display-safe relative source references ("plugins/cradle/src/x.php:12").
	 */
	public static function clean_references( $refs ): array {
		$out = array();
		foreach ( is_array( $refs ) ? $refs : array() as $ref ) {
			if ( ! is_string( $ref ) || strlen( $ref ) > Config::MAX_REFERENCE_LEN || str_starts_with( $ref, '/' ) || false !== strpos( $ref, '..' ) ) {
				continue;
			}
			if ( preg_match( '/^[A-Za-z0-9_@.\/ +-]+(?::\d{1,7})?$/', $ref ) ) {
				$out[ $ref ] = true;
			}
			if ( count( $out ) >= Config::MAX_REFERENCES ) {
				break;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Structural schema validation of a whole catalog (import, restore, stored read).
	 *
	 * @param mixed $data Decoded JSON.
	 * @param array $opts `locale` (expected locale or ''), `semantic` (bool: validate translations too).
	 * @return array|\WP_Error Normalized catalog.
	 */
	public static function catalog( $data, array $opts = array() ) {
		$opts = wp_parse_args(
			$opts,
			array(
				'locale'   => '',
				'semantic' => false,
			)
		);
		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			return self::schema_error( __( 'the top level must be an object.', 'cradle-translations' ) );
		}
		$unknown = array_diff( array_keys( $data ), self::CATALOG_KEYS );
		if ( $unknown ) {
			/* translators: %s: list of keys */
			return self::schema_error( sprintf( __( 'unknown keys %s.', 'cradle-translations' ), implode( ', ', array_map( 'strval', array_slice( $unknown, 0, 5 ) ) ) ) );
		}
		if ( Config::SCHEMA_VERSION !== ( $data['schemaVersion'] ?? null ) ) {
			return self::schema_error( __( 'schemaVersion must be 1.', 'cradle-translations' ) );
		}
		$locale = $data['locale'] ?? null;
		if ( ! Config::valid_locale( $locale ) ) {
			return self::schema_error( __( 'locale is not a valid locale code.', 'cradle-translations' ) );
		}
		if ( '' !== $opts['locale'] && $locale !== $opts['locale'] ) {
			/* translators: 1: locale in the file, 2: expected locale */
			return new \WP_Error( 'cradle_translations_locale_mismatch', sprintf( __( 'The catalog is for %1$s, not %2$s.', 'cradle-translations' ), $locale, $opts['locale'] ) );
		}
		foreach ( array( 'revision', 'publishedRevision' ) as $key ) {
			if ( isset( $data[ $key ] ) && ( ! is_int( $data[ $key ] ) || $data[ $key ] < 0 ) ) {
				/* translators: %s: key */
				return self::schema_error( sprintf( __( '%s must be a non-negative integer.', 'cradle-translations' ), $key ) );
			}
		}
		foreach ( array( 'updated', 'generator', 'exported', 'pluralRule' ) as $key ) {
			if ( isset( $data[ $key ] ) && ( ! is_string( $data[ $key ] ) || strlen( $data[ $key ] ) > 100 ) ) {
				/* translators: %s: key */
				return self::schema_error( sprintf( __( '%s must be a short string.', 'cradle-translations' ), $key ) );
			}
		}
		$domains = $data['domains'] ?? null;
		if ( ! is_array( $domains ) || ( $domains && array_is_list( $domains ) ) ) {
			return self::schema_error( __( 'domains must be an object keyed by text domain.', 'cradle-translations' ) );
		}
		if ( count( $domains ) > Config::MAX_DOMAINS ) {
			return self::schema_error( __( 'too many text domains.', 'cradle-translations' ) );
		}
		$out   = array(
			'schemaVersion'     => Config::SCHEMA_VERSION,
			'locale'            => $locale,
			'revision'          => (int) ( $data['revision'] ?? 0 ),
			'publishedRevision' => (int) ( $data['publishedRevision'] ?? 0 ),
			'updated'           => (string) ( $data['updated'] ?? '' ),
			'pluralRule'        => Plural_Rules::rule_id( $locale ),
			'domains'           => array(),
			'log'               => array(),
		);
		$total = 0;
		$seen  = array();
		foreach ( $domains as $domain => $entries ) {
			$domain = (string) $domain;
			if ( ! Config::valid_domain( $domain ) ) {
				return self::schema_error( __( 'a text domain name is invalid.', 'cradle-translations' ) );
			}
			if ( ! is_array( $entries ) || ( $entries && ! array_is_list( $entries ) ) ) {
				/* translators: %s: text domain */
				return self::schema_error( sprintf( __( 'domain %s must be a list of entries.', 'cradle-translations' ), $domain ) );
			}
			$total += count( $entries );
			if ( $total > Config::MAX_ENTRIES ) {
				/* translators: %d: maximum entries */
				return self::schema_error( sprintf( __( 'more than %d entries.', 'cradle-translations' ), Config::MAX_ENTRIES ) );
			}
			foreach ( $entries as $position => $entry ) {
				$normalized = self::entry( $entry, $domain, $locale, (bool) $opts['semantic'] );
				if ( is_wp_error( $normalized ) ) {
					/* translators: 1: text domain, 2: entry position, 3: problem */
					return self::schema_error( sprintf( __( '%1$s entry #%2$d: %3$s', 'cradle-translations' ), $domain, $position + 1, $normalized->get_error_message() ) );
				}
				$key = Catalog::identity( $domain, $normalized['msgid'], $normalized['context'], $normalized['msgidPlural'] );
				if ( isset( $seen[ $key ] ) ) {
					/* translators: 1: text domain, 2: entry position */
					return self::schema_error( sprintf( __( '%1$s entry #%2$d duplicates another entry (same text, context and plural).', 'cradle-translations' ), $domain, $position + 1 ) );
				}
				$seen[ $key ]                = true;
				$out['domains'][ $domain ][] = $normalized;
			}
		}
		if ( isset( $data['log'] ) ) {
			if ( ! is_array( $data['log'] ) || ! array_is_list( $data['log'] ) || count( $data['log'] ) > Config::MAX_LOG * 2 ) {
				return self::schema_error( __( 'log must be a short list.', 'cradle-translations' ) );
			}
			foreach ( $data['log'] as $row ) {
				if ( is_array( $row ) && isset( $row['revision'] ) && is_int( $row['revision'] ) ) {
					$out['log'][] = array(
						'revision' => $row['revision'],
						'time'     => is_string( $row['time'] ?? null ) ? substr( $row['time'], 0, 40 ) : '',
						'user'     => is_int( $row['user'] ?? null ) ? $row['user'] : 0,
						'action'   => is_string( $row['action'] ?? null ) ? sanitize_key( $row['action'] ) : '',
						'summary'  => is_string( $row['summary'] ?? null ) && mb_check_encoding( $row['summary'], 'UTF-8' ) ? mb_substr( $row['summary'], 0, 300 ) : '',
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Validates one catalog entry.
	 *
	 * @param mixed $entry Raw entry.
	 * @return array|\WP_Error
	 */
	public static function entry( $entry, string $domain, string $locale, bool $semantic ) {
		if ( ! is_array( $entry ) || array_is_list( $entry ) ) {
			return new \WP_Error( 'cradle_translations_schema', __( 'an entry must be an object.', 'cradle-translations' ) );
		}
		$unknown = array_diff( array_keys( $entry ), self::ENTRY_KEYS );
		if ( $unknown ) {
			/* translators: %s: list of keys */
			return new \WP_Error( 'cradle_translations_schema', sprintf( __( 'unknown keys %s.', 'cradle-translations' ), implode( ', ', array_map( 'strval', array_slice( $unknown, 0, 5 ) ) ) ) );
		}
		$ok = self::check_text( $entry['msgid'] ?? null, 'msgid', false );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		foreach ( array( 'context', 'msgidPlural' ) as $key ) {
			if ( isset( $entry[ $key ] ) ) {
				$ok = self::check_text( $entry[ $key ], $key );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
			}
		}
		$context = isset( $entry['context'] ) && '' !== $entry['context'] ? $entry['context'] : null;
		$plural  = isset( $entry['msgidPlural'] ) && '' !== $entry['msgidPlural'] ? $entry['msgidPlural'] : null;
		$max     = null === $plural ? 1 : Config::MAX_PLURAL_FORMS;
		$lists   = array();
		foreach ( array( 'translations', 'draft' ) as $key ) {
			if ( ! isset( $entry[ $key ] ) ) {
				$lists[ $key ] = 'draft' === $key ? null : array();
				continue;
			}
			if ( ! is_array( $entry[ $key ] ) || ! array_is_list( $entry[ $key ] ) || count( $entry[ $key ] ) > $max ) {
				/* translators: %s: key */
				return new \WP_Error( 'cradle_translations_schema', sprintf( __( '%s must be a list with at most one string per plural form.', 'cradle-translations' ), $key ) );
			}
			foreach ( $entry[ $key ] as $form ) {
				$ok = self::check_text( $form, $key );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
			}
			$lists[ $key ] = array_values( $entry[ $key ] );
			if ( ! array_filter( $lists[ $key ], static fn( $f ) => '' !== $f ) ) {
				// An all-empty draft is a pending removal of the published translation.
				$lists[ $key ] = array();
			}
		}
		foreach ( array( 'fuzzy', 'obsolete', 'js' ) as $key ) {
			if ( isset( $entry[ $key ] ) && ! is_bool( $entry[ $key ] ) ) {
				/* translators: %s: key */
				return new \WP_Error( 'cradle_translations_schema', sprintf( __( '%s must be true or false.', 'cradle-translations' ), $key ) );
			}
		}
		if ( isset( $entry['status'] ) && ! in_array( $entry['status'], self::STATUSES, true ) ) {
			return new \WP_Error( 'cradle_translations_schema', __( 'status must be missing, translated, fuzzy or obsolete.', 'cradle-translations' ) );
		}
		if ( isset( $entry['source'] ) && ! in_array( $entry['source'], self::SOURCES, true ) ) {
			return new \WP_Error( 'cradle_translations_schema', __( 'source must be extracted, imported, manual or registered.', 'cradle-translations' ) );
		}
		if ( isset( $entry['references'] ) && ( ! is_array( $entry['references'] ) || ! array_is_list( $entry['references'] ) || count( $entry['references'] ) > 1000 ) ) {
			return new \WP_Error( 'cradle_translations_schema', __( 'references must be a list of strings.', 'cradle-translations' ) );
		}
		if ( isset( $entry['comments'] ) && ( ! is_string( $entry['comments'] ) || ! mb_check_encoding( $entry['comments'], 'UTF-8' ) || mb_strlen( $entry['comments'], 'UTF-8' ) > Config::MAX_COMMENT_CHARS ) ) {
			return new \WP_Error( 'cradle_translations_schema', __( 'comments must be a short string.', 'cradle-translations' ) );
		}
		foreach ( array( 'changedRevision' ) as $key ) {
			if ( isset( $entry[ $key ] ) && ( ! is_int( $entry[ $key ] ) || $entry[ $key ] < 0 ) ) {
				/* translators: %s: key */
				return new \WP_Error( 'cradle_translations_schema', sprintf( __( '%s must be a non-negative integer.', 'cradle-translations' ), $key ) );
			}
		}
		foreach ( array( 'id', 'updated' ) as $key ) {
			if ( isset( $entry[ $key ] ) && ( ! is_string( $entry[ $key ] ) || strlen( $entry[ $key ] ) > 100 ) ) {
				/* translators: %s: key */
				return new \WP_Error( 'cradle_translations_schema', sprintf( __( '%s must be a short string.', 'cradle-translations' ), $key ) );
			}
		}
		if ( isset( $entry['draftFuzzy'] ) && ! is_bool( $entry['draftFuzzy'] ) ) {
			return new \WP_Error( 'cradle_translations_schema', __( 'draftFuzzy must be true, false or null.', 'cradle-translations' ) );
		}
		$fuzzy = ! empty( $entry['fuzzy'] ) || ( 'fuzzy' === ( $entry['status'] ?? '' ) );
		$out   = array(
			'id'              => Catalog::entry_id( $domain, $entry['msgid'], $context, $plural ),
			'msgid'           => $entry['msgid'],
			'context'         => $context,
			'msgidPlural'     => $plural,
			'translations'    => $lists['translations'],
			'draft'           => $lists['draft'],
			'draftFuzzy'      => null === $lists['draft'] ? null : ( isset( $entry['draftFuzzy'] ) ? (bool) $entry['draftFuzzy'] : $fuzzy ),
			'fuzzy'           => $fuzzy,
			'obsolete'        => ! empty( $entry['obsolete'] ) || 'obsolete' === ( $entry['status'] ?? '' ),
			'source'          => $entry['source'] ?? 'imported',
			'references'      => self::clean_references( $entry['references'] ?? array() ),
			'comments'        => (string) ( $entry['comments'] ?? '' ),
			'js'              => ! empty( $entry['js'] ),
			'changedRevision' => (int) ( $entry['changedRevision'] ?? 0 ),
			'status'          => 'missing',
		);
		if ( $semantic ) {
			foreach ( array( 'translations', 'draft' ) as $key ) {
				if ( empty( $out[ $key ] ) ) {
					continue;
				}
				$checked = self::check_translation( $out, $out[ $key ], $locale );
				if ( is_wp_error( $checked ) ) {
					return $checked;
				}
				$out[ $key ] = $checked;
			}
		}
		$out['status'] = Catalog::status( $out, $locale );
		return $out;
	}
}
