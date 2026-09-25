<?php
/**
 * Minimal semantic version range matching for module compatibility checks.
 *
 * Supported forms: "1.2.3" (exact), "^1.2" (>=1.2.0 <2.0.0; for 0.x: >=0.y <0.(y+1)),
 * "~1.2" (>=1.2.0 <1.3.0) and space-separated comparators such as ">=1.0 <2.0".
 *
 * @package Cradle
 */

namespace Cradle\Core;

defined( 'ABSPATH' ) || exit;

final class Version_Range {

	const VERSION_PATTERN = '/^\d+(\.\d+){0,2}(-[0-9A-Za-z.-]+)?$/';

	public static function is_version( $version ): bool {
		return is_string( $version ) && 1 === preg_match( self::VERSION_PATTERN, $version );
	}

	/**
	 * @return bool|null Null when the range is malformed.
	 */
	public static function satisfies( string $version, string $range ) {
		$range = trim( $range );
		if ( '' === $range || ! self::is_version( $version ) ) {
			return null;
		}
		$constraints = self::expand( $range );
		if ( null === $constraints ) {
			return null;
		}
		foreach ( $constraints as $constraint ) {
			list( $operator, $target ) = $constraint;
			if ( ! version_compare( self::pad( $version ), self::pad( $target ), $operator ) ) {
				return false;
			}
		}
		return true;
	}

	public static function is_valid_range( $range ): bool {
		return is_string( $range ) && null !== self::expand( trim( $range ) );
	}

	/**
	 * @return array<int,array{0:string,1:string}>|null
	 */
	private static function expand( string $range ) {
		if ( '' === $range ) {
			return null;
		}
		if ( '^' === $range[0] || '~' === $range[0] ) {
			$base = substr( $range, 1 );
			if ( ! self::is_version( $base ) ) {
				return null;
			}
			$parts = array_map( 'intval', explode( '.', self::pad( preg_replace( '/-.*/', '', $base ) ) ) );
			if ( '^' === $range[0] ) {
				$upper = $parts[0] > 0 ? ( $parts[0] + 1 ) . '.0.0' : '0.' . ( $parts[1] + 1 ) . '.0';
			} else {
				$upper = $parts[0] . '.' . ( $parts[1] + 1 ) . '.0';
			}
			return array( array( '>=', $base ), array( '<', $upper ) );
		}
		$out = array();
		foreach ( preg_split( '/\s+/', $range ) as $token ) {
			if ( ! preg_match( '/^(>=|<=|>|<|=|==)?(.+)$/', $token, $m ) || ! self::is_version( $m[2] ) ) {
				return null;
			}
			$operator = '' === $m[1] ? '==' : ( '=' === $m[1] ? '==' : $m[1] );
			$out[]    = array( $operator, $m[2] );
		}
		return $out;
	}

	private static function pad( string $version ): string {
		$core  = preg_replace( '/-.*/', '', $version );
		$parts = explode( '.', $core );
		while ( count( $parts ) < 3 ) {
			$parts[] = '0';
		}
		$suffix = substr( $version, strlen( $core ) );
		return implode( '.', $parts ) . $suffix;
	}
}
