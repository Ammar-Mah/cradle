<?php
/**
 * Safe, bounded parser for gettext .po/.pot source catalogs (UTF-8 only).
 *
 * A pure text state machine: no include, no eval. The Plural-Forms header is never evaluated;
 * only its `nplurals` integer is read for comparison with Cradle's structured plural rules.
 * Obsolete (#~) entries are skipped.
 *
 * @package Cradle\Translations
 */

namespace Cradle\Translations;

defined( 'ABSPATH' ) || exit;

final class Po_Parser {

	/**
	 * @return array{headers:array<string,string>,entries:array}|\WP_Error
	 */
	public static function parse( string $raw, int $max_bytes ) {
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
		$lines   = preg_split( '/\r\n|\n|\r/', $raw );
		$entries = array();
		$headers = array();
		$current = self::blank();
		$field   = null;
		$index   = 0;
		foreach ( $lines as $number => $line ) {
			$line = trim( $line );
			$pos  = $number + 1;
			if ( '' === $line ) {
				continue;
			}
			if ( str_starts_with( $line, '#~' ) ) {
				continue; // Obsolete entries consist only of #~ lines; they are ignored.
			}
			if ( '#' === $line[0] ) {
				if ( null !== $field && self::started( $current ) ) {
					$done = self::finish( $current, $entries, $headers );
					if ( is_wp_error( $done ) ) {
						return self::at( $done, $pos );
					}
					$current = self::blank();
					$field   = null;
				}
				if ( str_starts_with( $line, '#:' ) ) {
					foreach ( preg_split( '/\s+/', trim( substr( $line, 2 ) ) ) as $ref ) {
						if ( '' !== $ref ) {
							$current['references'][] = $ref;
						}
					}
				} elseif ( str_starts_with( $line, '#,' ) ) {
					foreach ( array_map( 'trim', explode( ',', substr( $line, 2 ) ) ) as $flag ) {
						if ( 'fuzzy' === $flag ) {
							$current['fuzzy'] = true;
						}
					}
				} elseif ( str_starts_with( $line, '#.' ) ) {
					$current['comments'][] = trim( substr( $line, 2 ) );
				}
				continue;
			}
			if ( '"' === $line[0] ) {
				if ( null === $field ) {
					return self::at( new \WP_Error( 'cradle_translations_po', __( 'A continuation string has no keyword.', 'cradle-translations' ) ), $pos );
				}
				$value = self::unquote( $line );
				if ( null === $value ) {
					return self::at( new \WP_Error( 'cradle_translations_po', __( 'Malformed quoted string.', 'cradle-translations' ) ), $pos );
				}
				self::append( $current, $field, $index, $value );
				continue;
			}
			if ( ! preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d{1,2})\])?)\s+(".*")$/', $line, $m ) ) {
				return self::at( new \WP_Error( 'cradle_translations_po', __( 'Unrecognized line.', 'cradle-translations' ) ), $pos );
			}
			$keyword = str_starts_with( $m[1], 'msgstr' ) ? 'msgstr' : $m[1];
			if ( ( 'msgctxt' === $keyword || 'msgid' === $keyword ) && null !== $current['msgstr'] ) {
				$done = self::finish( $current, $entries, $headers );
				if ( is_wp_error( $done ) ) {
					return self::at( $done, $pos );
				}
				$current = self::blank();
			}
			$value = self::unquote( $m[3] );
			if ( null === $value ) {
				return self::at( new \WP_Error( 'cradle_translations_po', __( 'Malformed quoted string.', 'cradle-translations' ) ), $pos );
			}
			$field = $keyword;
			$index = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
			if ( 'msgstr' === $keyword && $index >= Config::MAX_PLURAL_FORMS ) {
				return self::at( new \WP_Error( 'cradle_translations_po', __( 'Too many plural forms.', 'cradle-translations' ) ), $pos );
			}
			if ( 'msgstr' === $keyword ) {
				$current['msgstr']           = $current['msgstr'] ?? array();
				$current['msgstr'][ $index ] = '';
			} elseif ( 'msgctxt' === $keyword ) {
				$current['context'] = '';
			} elseif ( 'msgid_plural' === $keyword ) {
				$current['plural'] = '';
			} else {
				$current['msgid'] = '';
			}
			self::append( $current, $field, $index, $value );
			if ( count( $entries ) > Config::MAX_ENTRIES ) {
				return new \WP_Error( 'cradle_translations_po', __( 'The catalog has too many entries.', 'cradle-translations' ) );
			}
		}
		if ( self::started( $current ) ) {
			$done = self::finish( $current, $entries, $headers );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}
		if ( count( $entries ) > Config::MAX_ENTRIES ) {
			return new \WP_Error( 'cradle_translations_po', __( 'The catalog has too many entries.', 'cradle-translations' ) );
		}
		if ( isset( $headers['content-type'] ) && preg_match( '/charset=([A-Za-z0-9_-]+)/i', $headers['content-type'], $cm ) && ! in_array( strtolower( $cm[1] ), array( 'utf-8', 'utf8', 'charset' ), true ) ) {
			return new \WP_Error( 'cradle_translations_charset', __( 'Only UTF-8 catalogs are supported.', 'cradle-translations' ) );
		}
		return array(
			'headers' => $headers,
			'entries' => $entries,
		);
	}

	private static function blank(): array {
		return array(
			'msgid'      => null,
			'context'    => null,
			'plural'     => null,
			'msgstr'     => null,
			'references' => array(),
			'comments'   => array(),
			'fuzzy'      => false,
			'obsolete'   => false,
		);
	}

	private static function started( array $entry ): bool {
		return null !== $entry['msgid'];
	}

	private static function append( array &$entry, string $field, int $index, string $value ) {
		if ( 'msgstr' === $field ) {
			$entry['msgstr'][ $index ] .= $value;
		} elseif ( 'msgctxt' === $field ) {
			$entry['context'] .= $value;
		} elseif ( 'msgid_plural' === $field ) {
			$entry['plural'] .= $value;
		} else {
			$entry['msgid'] .= $value;
		}
	}

	private static function at( \WP_Error $error, int $line ): \WP_Error {
		/* translators: 1: line number, 2: problem */
		return new \WP_Error( $error->get_error_code(), sprintf( __( 'Line %1$d: %2$s', 'cradle-translations' ), $line, $error->get_error_message() ) );
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function finish( array $entry, array &$entries, array &$headers ) {
		if ( $entry['obsolete'] ) {
			return true;
		}
		if ( null === $entry['msgstr'] ) {
			return new \WP_Error( 'cradle_translations_po', __( 'An entry has no msgstr.', 'cradle-translations' ) );
		}
		if ( '' === $entry['msgid'] && null === $entry['context'] ) {
			foreach ( explode( "\n", (string) ( $entry['msgstr'][0] ?? '' ) ) as $row ) {
				if ( preg_match( '/^([A-Za-z0-9-]+):\s*(.*)$/', $row, $m ) ) {
					$headers[ strtolower( $m[1] ) ] = mb_substr( $m[2], 0, 300 );
				}
			}
			return true;
		}
		ksort( $entry['msgstr'] );
		if ( array_keys( $entry['msgstr'] ) !== range( 0, count( $entry['msgstr'] ) - 1 ) ) {
			return new \WP_Error( 'cradle_translations_po', __( 'Plural msgstr indexes are not contiguous.', 'cradle-translations' ) );
		}
		$entries[] = array(
			'msgid'        => $entry['msgid'],
			'context'      => '' === $entry['context'] ? null : $entry['context'],
			'msgidPlural'  => '' === $entry['plural'] ? null : $entry['plural'],
			'translations' => array_values( $entry['msgstr'] ),
			'references'   => Validator::clean_references( $entry['references'] ),
			'comments'     => mb_substr( implode( ' ', $entry['comments'] ), 0, Config::MAX_COMMENT_CHARS ),
			'fuzzy'        => $entry['fuzzy'],
		);
		return true;
	}

	/**
	 * Decodes a C-style quoted PO string.
	 */
	private static function unquote( string $quoted ): ?string {
		if ( ! preg_match( '/^"((?:[^"\\\\]|\\\\.)*)"$/s', $quoted, $m ) ) {
			return null;
		}
		$out = preg_replace_callback(
			'/\\\\(?:([ntrabfv\\\\"\'?])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2}))/',
			static function ( $e ) {
				if ( isset( $e[1] ) && '' !== $e[1] ) {
					$map = array(
						'n'  => "\n",
						't'  => "\t",
						'r'  => "\r",
						'a'  => "\x07",
						'b'  => "\x08",
						'f'  => "\f",
						'v'  => "\v",
						'\\' => '\\',
						'"'  => '"',
						"'"  => "'",
						'?'  => '?',
					);
					return $map[ $e[1] ];
				}
				if ( isset( $e[2] ) && '' !== $e[2] ) {
					return chr( octdec( $e[2] ) & 255 );
				}
				return chr( hexdec( $e[3] ) );
			},
			$m[1]
		);
		return is_string( $out ) ? $out : null;
	}
}
