<?php
/**
 * Serialization-aware URL/path replacement with boundary checks.
 *
 * - No blind SQL REPLACE(): every value is transformed in PHP.
 * - Needles are expanded into plain, JSON-escaped (\/), backslash, JSON-escaped
 *   backslash, block-attribute (\) and URL-encoded forms.
 * - A match only counts when the next byte is not [A-Za-z0-9_.~-], so
 *   ".../wp" never rewrites ".../wpx" or ".../wp-other".
 * - PHP serialized values are parsed structurally (Serialized) and re-written with
 *   correct lengths; nested serialized strings are handled recursively. Objects and
 *   references are preserved byte-for-byte when untouched and reported as blocking
 *   issues when a change would be needed inside them.
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

defined( 'ABSPATH' ) || exit;

final class Replacer {

	/** @var array<string,string> Needle => replacement, longest needle first. */
	private $map = array();

	/** @var int Maximum nested decoding depth (serialized inside serialized …). */
	private $max_depth;

	/** @var array<int,array> Issues found by the last transform() call. */
	private $issues = array();

	/**
	 * @param array<int,array{0:string,1:string}> $url_pairs  Source URL => destination URL (no trailing slash).
	 * @param array<int,array{0:string,1:string}> $path_pairs Source path => destination path (forward slashes, no trailing slash).
	 */
	public function __construct( array $url_pairs, array $path_pairs, int $max_depth = Serialized::MAX_DEPTH ) {
		$this->max_depth = $max_depth;
		$map             = array();
		foreach ( $url_pairs as $pair ) {
			list( $from, $to ) = $pair;
			$from              = untrailingslashit( (string) $from );
			$to                = untrailingslashit( (string) $to );
			if ( '' === $from || $from === $to ) {
				continue;
			}
			$variants = array( array( $from, $to ) );
			if ( preg_match( '#^https?:(//.+)$#i', $from, $mf ) && preg_match( '#^https?:(//.+)$#i', $to, $mt ) ) {
				$variants[] = array( $mf[1], $mt[1] );
			}
			foreach ( $variants as $v ) {
				$map[ $v[0] ]                                   = $v[1];
				$map[ str_replace( '/', '\\/', $v[0] ) ]        = str_replace( '/', '\\/', $v[1] );
				$map[ rawurlencode( $v[0] ) ]                   = rawurlencode( $v[1] );
			}
		}
		foreach ( $path_pairs as $pair ) {
			list( $from, $to ) = $pair;
			$from              = rtrim( str_replace( '\\', '/', (string) $from ), '/' );
			$to                = rtrim( str_replace( '\\', '/', (string) $to ), '/' );
			if ( strlen( $from ) < 3 || $from === $to ) {
				continue;
			}
			$map[ $from ]                                  = $to;
			$map[ str_replace( '/', '\\/', $from ) ]       = str_replace( '/', '\\/', $to );
			if ( preg_match( '#^[A-Za-z]:/#', $from ) || preg_match( '#^[A-Za-z]:/#', $to ) ) {
				$map[ str_replace( '/', '\\', $from ) ]      = str_replace( '/', '\\', $to );
				$map[ str_replace( '/', '\\\\', $from ) ]    = str_replace( '/', '\\\\', $to );
				$map[ str_replace( '/', '\\u005c', $from ) ] = str_replace( '/', '\\u005c', $to );
			}
		}
		foreach ( array_keys( $map ) as $needle ) {
			if ( $needle === $map[ $needle ] || strlen( $needle ) < 3 ) {
				unset( $map[ $needle ] );
			}
		}
		uksort(
			$map,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a ) ?: strcmp( $a, $b );
			}
		);
		$this->map = $map;
	}

	public function map(): array {
		return $this->map;
	}

	public function is_empty(): bool {
		return ! $this->map;
	}

	/**
	 * @return array<int,array{code:string,detail:string}>
	 */
	public function issues(): array {
		return $this->issues;
	}

	public function contains_needle( string $s ): bool {
		foreach ( $this->map as $needle => $unused ) {
			if ( false !== strpos( $s, (string) $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Transforms one stored value.
	 *
	 * @return string The new value (identical when nothing changed or a blocking issue was found).
	 */
	public function transform( string $value ): string {
		$this->issues = array();
		if ( ! $this->map || ! $this->contains_needle( $value ) ) {
			return $value;
		}
		$out = $this->transform_string( $value, 0 );
		return null === $out ? $value : $out;
	}

	/**
	 * @return string|null Null when a blocking issue prevents a safe change.
	 */
	private function transform_string( string $value, int $depth ) {
		if ( ! $this->contains_needle( $value ) ) {
			return $value;
		}
		if ( $depth < $this->max_depth && Serialized::looks_serialized( $value ) ) {
			$inspect = Serialized::inspect( $value, $this->max_depth - $depth );
			if ( 'too_deep' === $inspect['status'] ) {
				$this->issues[] = array(
					'code'   => 'serialized_too_deep',
					'detail' => 'A serialized value is nested deeper than the supported limit and contains a URL/path that would need changing.',
				);
				return null;
			}
			if ( 'ok' === $inspect['status'] ) {
				// Objects/references that need no change are kept byte-for-byte; value
				// numbering is unaffected by string length changes elsewhere.
				$blocked = false;
				$new     = $this->walk( $inspect['node'], $depth, $blocked );
				if ( $blocked ) {
					return null;
				}
				return Serialized::write( $new );
			}
			$this->issues[] = array(
				'code'    => 'serialized_malformed',
				'detail'  => 'A value looks serialized but does not parse; it was treated as plain text (it was already unreadable to PHP).',
				'warning' => true,
			);
		}
		return $this->replace_text( $value );
	}

	private function walk( array $node, int $depth, bool &$blocked ): array {
		switch ( $node['t'] ) {
			case 's':
				$new = $this->transform_string( $node['v'], $depth + 1 );
				if ( null === $new ) {
					$blocked = true;
					return $node;
				}
				$node['v'] = $new;
				return $node;
			case 'a':
				foreach ( $node['items'] as $i => $pair ) {
					$node['items'][ $i ][1] = $this->walk( $pair[1], $depth + 1, $blocked );
					if ( $blocked ) {
						return $node;
					}
				}
				return $node;
			case 'opaque':
				if ( $this->contains_needle( $node['v'] ) ) {
					$blocked        = true;
					$this->issues[] = array(
						'code'   => 'serialized_object',
						'detail' => sprintf( 'A serialized %s entry contains a URL/path that would need changing; objects are never rewritten.', 'O' === $node['kind'] || 'C' === $node['kind'] || 'E' === $node['kind'] ? 'object' : 'reference' ),
					);
				}
				return $node;
		}
		return $node;
	}

	/**
	 * Boundary-aware multi-needle replacement in plain text (single left-to-right pass,
	 * longest needle wins at equal positions). No regular expressions, so no PCRE limits.
	 */
	public function replace_text( string $s ): string {
		$len  = strlen( $s );
		$next = array();
		foreach ( $this->map as $needle => $unused ) {
			$p = strpos( $s, (string) $needle );
			if ( false !== $p ) {
				$next[ $needle ] = $p;
			}
		}
		if ( ! $next ) {
			return $s;
		}
		$out    = '';
		$offset = 0;
		while ( $next ) {
			$best     = null;
			$best_pos = PHP_INT_MAX;
			foreach ( $next as $needle => $p ) {
				if ( $p < $best_pos || ( $p === $best_pos && strlen( (string) $needle ) > strlen( (string) $best ) ) ) {
					$best     = (string) $needle;
					$best_pos = $p;
				}
			}
			$end = $best_pos + strlen( $best );
			if ( $end < $len && self::is_word_byte( $s[ $end ] ) ) {
				// Not at a boundary: look for the next occurrence of this needle.
				$p = strpos( $s, $best, $best_pos + 1 );
				if ( false === $p ) {
					unset( $next[ $best ] );
				} else {
					$next[ $best ] = $p;
				}
				continue;
			}
			$out   .= substr( $s, $offset, $best_pos - $offset ) . $this->map[ $best ];
			$offset = $end;
			foreach ( $next as $needle => $p ) {
				if ( $p < $offset ) {
					$np = strpos( $s, (string) $needle, $offset );
					if ( false === $np ) {
						unset( $next[ $needle ] );
					} else {
						$next[ $needle ] = $np;
					}
				}
			}
		}
		return $out . substr( $s, $offset );
	}

	private static function is_word_byte( string $c ): bool {
		return ctype_alnum( $c ) || '_' === $c || '.' === $c || '~' === $c || '-' === $c;
	}
}
