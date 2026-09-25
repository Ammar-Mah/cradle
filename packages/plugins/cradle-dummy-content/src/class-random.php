<?php
/**
 * Deterministic pseudo-random stream derived from a seed and a stream name.
 *
 * HMAC-SHA256 in counter mode: identical (seed, stream) pairs produce identical
 * sequences on every PHP version and platform, independent of mt_rand state,
 * other plugins, or how a batch was split into job steps. Not for security.
 *
 * @package Cradle\Dummy_Content
 */

namespace Cradle\Dummy_Content;

defined( 'ABSPATH' ) || exit;

final class Random {

	private string $key;
	private int $counter = 0;
	private string $buffer = '';

	public function __construct( string $seed, string $stream ) {
		$this->key = hash( 'sha256', 'cradle-dummy|' . $seed . '|' . $stream, true );
	}

	private function uint32(): int {
		if ( strlen( $this->buffer ) < 4 ) {
			$this->buffer .= hash_hmac( 'sha256', (string) $this->counter, $this->key, true );
			++$this->counter;
		}
		$chunk        = substr( $this->buffer, 0, 4 );
		$this->buffer = substr( $this->buffer, 4 );
		return unpack( 'N', $chunk )[1];
	}

	/**
	 * Integer in [min, max] (inclusive).
	 */
	public function int( int $min, int $max ): int {
		if ( $max <= $min ) {
			return $min;
		}
		return $min + ( $this->uint32() % ( $max - $min + 1 ) );
	}

	public function chance( int $percent ): bool {
		return $this->int( 1, 100 ) <= $percent;
	}

	/**
	 * @param array $list Non-empty list.
	 * @return mixed
	 */
	public function pick( array $list ) {
		$list = array_values( $list );
		return $list ? $list[ $this->int( 0, count( $list ) - 1 ) ] : null;
	}

	/**
	 * Up to $count distinct items, order determined by the stream.
	 */
	public function sample( array $list, int $count ): array {
		$list = array_values( $list );
		$n    = count( $list );
		$take = min( max( 0, $count ), $n );
		for ( $i = 0; $i < $take; $i++ ) {
			$j          = $this->int( $i, $n - 1 );
			$tmp        = $list[ $i ];
			$list[ $i ] = $list[ $j ];
			$list[ $j ] = $tmp;
		}
		return array_slice( $list, 0, $take );
	}
}
