<?php
/**
 * Acceptance policy for the trusted-administrator raw CSS field.
 *
 * This is a guardrail, not a sanitizer: pattern checks cannot make arbitrary
 * CSS safe, which is why the field is limited to trusted administrators
 * (manage_options + unfiltered_html). The policy rejects content that could
 * leave the <style> element, load external or scripted resources, or hide
 * WordPress update/security notices. See docs/security.md.
 *
 * @package Cradle\Admin
 */

namespace Cradle\Admin;

defined( 'ABSPATH' ) || exit;

final class Css_Policy {

	/** Bumped when the policy changes; stored signatures of older versions are re-validated. */
	const VERSION = 1;

	const MAX_BYTES = 65536;

	const MAX_DEPTH = 8;

	const DATA_IMAGE = '/^data:image\/(png|gif|jpeg|jpg|webp|avif|svg\+xml)[;,]/';

	/**
	 * Validates CSS. Returns a list of human-readable problems (empty when accepted).
	 *
	 * @param mixed $css Candidate CSS.
	 * @return string[]
	 */
	public static function validate( $css ): array {
		if ( ! is_string( $css ) ) {
			return array( __( 'Custom CSS must be text.', 'cradle-admin' ) );
		}
		if ( strlen( $css ) > self::MAX_BYTES ) {
			/* translators: 1: submitted size in bytes, 2: limit in bytes */
			return array( sprintf( __( 'Custom CSS is limited to %2$s bytes; %1$s bytes were submitted.', 'cradle-admin' ), number_format_i18n( strlen( $css ) ), number_format_i18n( self::MAX_BYTES ) ) );
		}
		if ( ! mb_check_encoding( $css, 'UTF-8' ) ) {
			return array( __( 'Custom CSS must be valid UTF-8 text.', 'cradle-admin' ) );
		}
		$errors = array();
		if ( preg_match( '/[\x00-\x08\x0B\x0E-\x1F\x7F]/', $css ) ) {
			$errors[] = __( 'Custom CSS must not contain control characters.', 'cradle-admin' );
		}
		if ( false !== strpos( $css, '<' ) ) {
			$errors[] = __( 'Custom CSS must not contain “<”: it could close the style element (for example “</style>”) or start markup. Use “\\3c” inside CSS strings if you need the character.', 'cradle-admin' );
		}

		$scan = self::scan( $css );
		foreach ( $scan['errors'] as $error ) {
			$errors[] = $error;
		}
		if ( $scan['fatal'] ) {
			return $errors;
		}

		$decoded  = strtolower( self::decode_escapes( $scan['plain'] ) );
		$compact  = preg_replace( '/\s+/', '', $decoded );
		$patterns = array(
			'/@import/'                              => __( '@import is not allowed; it loads another stylesheet.', 'cradle-admin' ),
			'/expression\(/'                         => __( 'expression() is not allowed; it runs script in legacy browsers.', 'cradle-admin' ),
			'/(javascript|vbscript|livescript):/'    => __( 'Script URLs (javascript:, vbscript:) are not allowed.', 'cradle-admin' ),
			'/(?<![a-z0-9_-])behaviou?r:/'           => __( 'behavior: is not allowed; it attaches scripted components in legacy browsers.', 'cradle-admin' ),
			'/-moz-binding/'                         => __( '-moz-binding is not allowed; it attaches scripted bindings in legacy browsers.', 'cradle-admin' ),
			'/progid:/'                              => __( 'Legacy progid: filters are not allowed.', 'cradle-admin' ),
			'/(?<![a-z0-9_-])(-webkit-)?image-set\(/' => __( 'image-set() is not allowed because it accepts bare URL strings; use url() with a same-site address.', 'cradle-admin' ),
			'/(?<![a-z0-9_-])(src|image)\(/'         => __( 'src() and image() functions are not allowed because they accept bare URL strings; use url().', 'cradle-admin' ),
		);
		foreach ( $patterns as $pattern => $message ) {
			if ( preg_match( $pattern, $decoded ) || preg_match( $pattern, $compact ) ) {
				$errors[] = $message;
			}
		}

		foreach ( self::url_problems( $decoded ) as $problem ) {
			$errors[] = $problem;
		}

		foreach ( $scan['rules'] as $rule ) {
			if ( self::hides_protected_notice( $rule[0], $rule[1] ) ) {
				$errors[] = __( 'Custom CSS must not hide WordPress update or security notices, update counts or plugin update messages.', 'cradle-admin' );
				break;
			}
		}

		return array_values( array_unique( $errors ) );
	}

	/**
	 * Comment- and string-aware scan: removes comments, checks string termination,
	 * brace balance and nesting depth, and collects innermost (selector, declarations) pairs.
	 *
	 * @return array{plain:string,errors:string[],fatal:bool,rules:array<int,array{0:string,1:string}>}
	 */
	private static function scan( string $css ): array {
		$len    = strlen( $css );
		$plain  = '';
		$buffer = '';
		$stack  = array();
		$rules  = array();
		$errors = array();
		$fatal  = false;
		$nested = array();
		$i      = 0;
		while ( $i < $len ) {
			$c = $css[ $i ];
			if ( '/' === $c && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
				$end = strpos( $css, '*/', $i + 2 );
				if ( false === $end ) {
					$errors[] = __( 'A comment is not closed with “*/”.', 'cradle-admin' );
					$fatal    = true;
					break;
				}
				$plain  .= ' ';
				$buffer .= ' ';
				$i       = $end + 2;
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$j = $i + 1;
				while ( $j < $len && $css[ $j ] !== $c ) {
					if ( '\\' === $css[ $j ] ) {
						$j += 2;
						continue;
					}
					if ( "\n" === $css[ $j ] || "\r" === $css[ $j ] || "\f" === $css[ $j ] ) {
						break;
					}
					++$j;
				}
				if ( $j >= $len || $css[ $j ] !== $c ) {
					$errors[] = __( 'A quoted string is not closed on the same line.', 'cradle-admin' );
					$fatal    = true;
					break;
				}
				$string  = substr( $css, $i, $j - $i + 1 );
				$plain  .= $string;
				$buffer .= $string;
				$i       = $j + 1;
				continue;
			}
			if ( '\\' === $c ) {
				$pair    = substr( $css, $i, 2 );
				$plain  .= $pair;
				$buffer .= $pair;
				$i      += 2;
				continue;
			}
			if ( '{' === $c ) {
				$semi = strrpos( $buffer, ';' );
				$sel  = trim( false === $semi ? $buffer : substr( $buffer, $semi + 1 ) );
				if ( $stack ) {
					$nested[ count( $stack ) - 1 ] = true;
				}
				$stack[]                      = $sel;
				$nested[ count( $stack ) - 1 ] = false;
				$buffer                       = '';
				if ( count( $stack ) > self::MAX_DEPTH ) {
					$errors[] = __( 'Blocks are nested too deeply.', 'cradle-admin' );
					$fatal    = true;
					break;
				}
			} elseif ( '}' === $c ) {
				if ( ! $stack ) {
					$errors[] = __( 'There is a “}” without a matching “{”.', 'cradle-admin' );
					$fatal    = true;
					break;
				}
				$depth    = count( $stack ) - 1;
				$selector = array_pop( $stack );
				if ( empty( $nested[ $depth ] ) ) {
					$rules[] = array( $selector, $buffer );
				}
				unset( $nested[ $depth ] );
				$buffer = '';
			} elseif ( ';' === $c && ! $stack ) {
				$buffer = '';
			} else {
				$buffer .= $c;
			}
			$plain .= $c;
			++$i;
		}
		if ( ! $fatal && $stack ) {
			$errors[] = __( 'A “{” block is not closed with “}”.', 'cradle-admin' );
			$fatal    = true;
		}
		return array(
			'plain'  => $plain,
			'errors' => $errors,
			'fatal'  => $fatal,
			'rules'  => $rules,
		);
	}

	/**
	 * Resolves CSS escapes (\75 rl, \@import, line continuations) so checks see
	 * what the browser sees.
	 */
	public static function decode_escapes( string $css ): string {
		$out = preg_replace_callback(
			'/\\\\(?:([0-9a-fA-F]{1,6})(?:\r\n|[ \t\r\n\f])?|(\r\n|[\n\r\f])|(.))/su',
			static function ( $m ) {
				if ( isset( $m[1] ) && '' !== $m[1] ) {
					$cp = hexdec( $m[1] );
					if ( 0 === $cp || $cp > 0x10FFFF || ( $cp >= 0xD800 && $cp <= 0xDFFF ) ) {
						return "\u{FFFD}";
					}
					return mb_chr( $cp, 'UTF-8' );
				}
				if ( isset( $m[2] ) && '' !== $m[2] ) {
					return '';
				}
				return $m[3] ?? '';
			},
			$css
		);
		return is_string( $out ) ? $out : $css;
	}

	/**
	 * Checks every url(...) reference. Allowed: relative and same-site URLs,
	 * #fragments, and data: images (png, gif, jpeg, webp, avif, svg+xml).
	 *
	 * @param string $decoded Lowercased CSS with comments removed and escapes resolved.
	 * @return string[]
	 */
	private static function url_problems( string $decoded ): array {
		$problems = array();
		$count    = preg_match_all( '/(?<![a-z0-9_-])url\(/', $decoded );
		$parsed   = preg_match_all( '/(?<![a-z0-9_-])url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)"\'\s]*))\s*\)/', $decoded, $matches, PREG_SET_ORDER );
		if ( $count !== $parsed ) {
			$problems[] = __( 'A url() reference could not be read; write it as url("path") with a single same-site address.', 'cradle-admin' );
		}
		$hosts = self::allowed_hosts();
		foreach ( $matches as $m ) {
			$raw = $m[1] ?? '';
			if ( '' === $raw && isset( $m[2] ) && '' !== $m[2] ) {
				$raw = $m[2];
			}
			if ( '' === $raw && isset( $m[3] ) ) {
				$raw = $m[3];
			}
			// Browsers strip tabs/newlines and treat "\" as "/" in URLs; normalize the same way.
			$url = str_replace( '\\', '/', preg_replace( '/[\t\n\r\f]/', '', trim( $raw ) ) );
			if ( '' === $url || '#' === $url[0] ) {
				continue;
			}
			if ( 0 === strpos( $url, 'data:' ) ) {
				if ( ! preg_match( self::DATA_IMAGE, $url ) ) {
					$problems[] = __( 'Only data: images (PNG, GIF, JPEG, WebP, AVIF, SVG) are allowed in url().', 'cradle-admin' );
				}
				continue;
			}
			$absolute = 0 === strpos( $url, '//' ) ? 'https:' . $url : $url;
			if ( preg_match( '/^([a-z][a-z0-9+.-]*):/', $absolute, $scheme ) ) {
				$host = strtolower( (string) wp_parse_url( $absolute, PHP_URL_HOST ) );
				if ( ! in_array( $scheme[1], array( 'http', 'https' ), true ) || '' === $host || ! in_array( $host, $hosts, true ) ) {
					/* translators: %s: rejected URL */
					$problems[] = sprintf( __( 'url(%s) points outside this site; only relative, same-site and data: image URLs are allowed.', 'cradle-admin' ), $url );
				}
			}
		}
		return array_values( array_unique( $problems ) );
	}

	/**
	 * Hosts of this WordPress installation.
	 */
	public static function allowed_hosts(): array {
		$hosts = array();
		foreach ( array( home_url(), site_url(), admin_url(), content_url(), includes_url() ) as $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' !== $host ) {
				$hosts[] = $host;
			}
		}
		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Heuristic guardrail: a rule targeting update/security notice selectors must not hide them.
	 */
	private static function hides_protected_notice( string $selector, string $declarations ): bool {
		$selector = strtolower( self::decode_escapes( $selector ) );
		if ( ! preg_match( '/update-nag|update-message|update-plugins|plugin-update|wp-admin-bar-updates|core-updates|notice-error|notice-warning|site-health|recovery-mode/', $selector ) ) {
			return false;
		}
		$decl = preg_replace( '/\s+/', '', strtolower( self::decode_escapes( $declarations ) ) );
		return (bool) preg_match( '/(^|[;{])(display:none|visibility:(hidden|collapse)|opacity:0(\.0*)?(;|!|$)|content-visibility:hidden|clip-path:|font-size:0(px|em|rem)?(;|!|$)|(max-)?height:0(px)?(;|!|$))/', $decl );
	}
}
