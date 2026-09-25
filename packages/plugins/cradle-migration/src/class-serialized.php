<?php
/**
 * Bounded structural parser and writer for PHP's serialize() grammar.
 *
 * Archive data is never passed to unserialize(). This parser builds a small node
 * tree without instantiating classes, so string leaves can be transformed and
 * lengths recomputed. Supported and rewritable: N, b, i, d, s and arrays. Objects
 * (O, C, E) and references (r, R) are recognised structurally and kept as opaque
 * byte ranges; callers must refuse to change them (see Replacer).
 *
 * Node shapes:
 *   array( 't' => 'raw', 'v' => 'i:5;' )           scalars other than strings (byte-exact)
 *   array( 't' => 's', 'v' => '<bytes>' )          string
 *   array( 't' => 'a', 'items' => array( array( $key_node, $value_node ), ... ) )
 *   array( 't' => 'opaque', 'kind' => 'O'|'C'|'E'|'r'|'R', 'v' => '<raw bytes>' )
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

defined( 'ABSPATH' ) || exit;

final class Serialized {

	const MAX_DEPTH = 64;

	/** @var int Remaining node budget for the current parse (bounds CPU/memory). */
	private static $budget = 0;

	/** @var bool Whether the current parse met an object or reference. */
	private static $has_opaque = false;

	/**
	 * Cheap shape test before a full parse.
	 */
	public static function looks_serialized( string $s ): bool {
		$len = strlen( $s );
		if ( 'N;' === $s ) {
			return true;
		}
		if ( $len < 4 || ':' !== $s[1] ) {
			return false;
		}
		$last = $s[ $len - 1 ];
		return false !== strpos( 'aOCEsidb', $s[0] ) && ( ';' === $last || '}' === $last );
	}

	/**
	 * Parses one complete serialized value.
	 *
	 * @return array|null Node tree, or null when the bytes are not valid serialized data
	 *                    within the bounds. Extra trailing bytes make the value invalid.
	 */
	public static function parse( string $s, int $max_depth = self::MAX_DEPTH, int $max_nodes = 1000000 ) {
		$pos               = 0;
		self::$budget      = $max_nodes;
		self::$has_opaque  = false;
		try {
			$node = self::value( $s, $pos, 0, $max_depth );
		} catch ( \UnexpectedValueException $e ) {
			return null;
		}
		if ( strlen( $s ) !== $pos ) {
			return null;
		}
		$node['opaque_inside'] = self::$has_opaque;
		return $node;
	}

	/**
	 * Whether the last successful parse() met an object or a reference anywhere.
	 */
	public static function had_opaque(): bool {
		return self::$has_opaque;
	}

	private static function fail(): void {
		throw new \UnexpectedValueException( 'invalid' );
	}

	private static function value( string $s, int &$pos, int $depth, int $max_depth ): array {
		if ( $depth > $max_depth ) {
			throw new \LengthException( 'depth' );
		}
		if ( --self::$budget < 0 ) {
			throw new \LengthException( 'size' );
		}
		$len = strlen( $s );
		if ( $pos >= $len ) {
			self::fail();
		}
		$type = $s[ $pos ];
		switch ( $type ) {
			case 'N':
				if ( 'N;' !== substr( $s, $pos, 2 ) ) {
					self::fail();
				}
				$pos += 2;
				return array( 't' => 'raw', 'v' => 'N;' );

			case 'b':
			case 'i':
			case 'd':
				$patterns = array(
					'b' => '/\Gb:[01];/',
					'i' => '/\Gi:[+-]?[0-9]{1,20};/',
					'd' => '/\Gd:(?:-?INF|NAN|[+-]?(?:[0-9]+\.?[0-9]*|\.[0-9]+)(?:[eE][+-]?[0-9]+)?);/',
				);
				if ( ! preg_match( $patterns[ $type ], $s, $m, 0, $pos ) ) {
					self::fail();
				}
				$pos += strlen( $m[0] );
				return array( 't' => 'raw', 'v' => $m[0] );

			case 's':
				if ( ! preg_match( '/\Gs:([0-9]{1,10}):"/', $s, $m, 0, $pos ) ) {
					self::fail();
				}
				$n     = (int) $m[1];
				$start = $pos + strlen( $m[0] );
				if ( $start + $n + 2 > $len || '";' !== substr( $s, $start + $n, 2 ) ) {
					self::fail();
				}
				$pos = $start + $n + 2;
				return array( 't' => 's', 'v' => (string) substr( $s, $start, $n ) );

			case 'a':
				if ( ! preg_match( '/\Ga:([0-9]{1,9}):\{/', $s, $m, 0, $pos ) ) {
					self::fail();
				}
				$count = (int) $m[1];
				// Each element needs at least 4 bytes for its key and 2 for its value.
				if ( $count * 6 > $len - $pos ) {
					self::fail();
				}
				$pos  += strlen( $m[0] );
				$items = array();
				for ( $i = 0; $i < $count; $i++ ) {
					$key = self::value( $s, $pos, $depth + 1, $max_depth );
					if ( 's' !== $key['t'] && ( 'raw' !== $key['t'] || 'i' !== $key['v'][0] ) ) {
						self::fail();
					}
					$items[] = array( $key, self::value( $s, $pos, $depth + 1, $max_depth ) );
				}
				if ( $pos >= $len || '}' !== $s[ $pos ] ) {
					self::fail();
				}
				++$pos;
				return array(
					't'     => 'a',
					'items' => $items,
				);

			case 'O':
				$start = $pos;
				if ( ! preg_match( '/\GO:([0-9]{1,5}):"/', $s, $m, 0, $pos ) ) {
					self::fail();
				}
				$n    = (int) $m[1];
				$pos += strlen( $m[0] );
				if ( $pos + $n > $len || ! preg_match( '/\G[A-Za-z0-9_\\\\\x80-\xff]+\z/', substr( $s, $pos, $n ) ) ) {
					self::fail();
				}
				$pos += $n;
				if ( ! preg_match( '/\G":([0-9]{1,9}):\{/', $s, $m, 0, $pos ) ) {
					self::fail();
				}
				$count = (int) $m[1];
				if ( $count * 6 > $len - $pos ) {
					self::fail();
				}
				$pos += strlen( $m[0] );
				for ( $i = 0; $i < $count; $i++ ) {
					$key = self::value( $s, $pos, $depth + 1, $max_depth );
					if ( 's' !== $key['t'] && ( 'raw' !== $key['t'] || 'i' !== $key['v'][0] ) ) {
						self::fail();
					}
					self::value( $s, $pos, $depth + 1, $max_depth );
				}
				if ( $pos >= $len || '}' !== $s[ $pos ] ) {
					self::fail();
				}
				++$pos;
				self::$has_opaque = true;
				return array(
					't'    => 'opaque',
					'kind' => 'O',
					'v'    => substr( $s, $start, $pos - $start ),
				);

			case 'C':
				$start = $pos;
				if ( ! preg_match( '/\GC:([0-9]{1,5}):"/', $s, $m, 0, $pos ) ) {
					self::fail();
				}
				$pos += strlen( $m[0] ) + (int) $m[1];
				if ( ! preg_match( '/\G":([0-9]{1,10}):\{/', $s, $m, 0, $pos ) ) {
					self::fail();
				}
				$pos += strlen( $m[0] ) + (int) $m[1];
				if ( $pos >= $len || '}' !== $s[ $pos ] ) {
					self::fail();
				}
				++$pos;
				self::$has_opaque = true;
				return array(
					't'    => 'opaque',
					'kind' => 'C',
					'v'    => substr( $s, $start, $pos - $start ),
				);

			case 'E':
				$start = $pos;
				if ( ! preg_match( '/\GE:([0-9]{1,5}):"/', $s, $m, 0, $pos ) ) {
					self::fail();
				}
				$pos += strlen( $m[0] ) + (int) $m[1];
				if ( '";' !== substr( $s, $pos, 2 ) ) {
					self::fail();
				}
				$pos += 2;
				self::$has_opaque = true;
				return array(
					't'    => 'opaque',
					'kind' => 'E',
					'v'    => substr( $s, $start, $pos - $start ),
				);

			case 'r':
			case 'R':
				if ( ! preg_match( '/\G[rR]:[0-9]{1,10};/', $s, $m, 0, $pos ) ) {
					self::fail();
				}
				$pos             += strlen( $m[0] );
				self::$has_opaque = true;
				return array(
					't'    => 'opaque',
					'kind' => $type,
					'v'    => $m[0],
				);
		}
		self::fail();
		return array();
	}

	/**
	 * Writes a node tree back to serialized bytes, recomputing string lengths and counts.
	 */
	public static function write( array $node ): string {
		switch ( $node['t'] ) {
			case 'raw':
			case 'opaque':
				return $node['v'];
			case 's':
				return 's:' . strlen( $node['v'] ) . ':"' . $node['v'] . '";';
			case 'a':
				$out = 'a:' . count( $node['items'] ) . ':{';
				foreach ( $node['items'] as $pair ) {
					$out .= self::write( $pair[0] ) . self::write( $pair[1] );
				}
				return $out . '}';
		}
		return '';
	}

	/**
	 * Parses a value with LengthException (depth/size) surfaced as a distinct result.
	 *
	 * @return array{status:string,node:?array} status: ok | invalid | too_deep
	 */
	public static function inspect( string $s, int $max_depth = self::MAX_DEPTH ): array {
		try {
			$node = self::parse( $s, $max_depth );
		} catch ( \LengthException $e ) {
			return array(
				'status' => 'too_deep',
				'node'   => null,
			);
		}
		return array(
			'status' => null === $node ? 'invalid' : 'ok',
			'node'   => $node,
		);
	}
}
