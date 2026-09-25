<?php
/**
 * Small color math helpers (WCAG contrast, mixing, Sass-compatible darken).
 * Inputs are validated hex colors; invalid input returns null / 0.
 *
 * @package Cradle\Admin
 */

namespace Cradle\Admin;

defined( 'ABSPATH' ) || exit;

final class Color {

	/**
	 * @return int[]|null [r, g, b] 0–255, or null for anything but #rgb / #rrggbb.
	 */
	public static function rgb( $hex ): ?array {
		if ( ! is_string( $hex ) || ! preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $hex, $m ) ) {
			return null;
		}
		$h = $m[1];
		if ( 3 === strlen( $h ) ) {
			$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
		}
		return array( hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ) );
	}

	public static function hex( array $rgb ): string {
		return sprintf( '#%02x%02x%02x', self::clamp( $rgb[0] ), self::clamp( $rgb[1] ), self::clamp( $rgb[2] ) );
	}

	/**
	 * "r,g,b" for rgba(var(--x--rgb), a) consumers such as --wp-admin-theme-color--rgb.
	 */
	public static function rgb_list( string $hex ): string {
		$rgb = self::rgb( $hex );
		return $rgb ? implode( ',', $rgb ) : '0,0,0';
	}

	/**
	 * WCAG 2.x relative luminance.
	 */
	public static function luminance( string $hex ): float {
		$rgb = self::rgb( $hex );
		if ( ! $rgb ) {
			return 0.0;
		}
		$channel = static function ( $v ) {
			$v = $v / 255;
			return $v <= 0.04045 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
		};
		return 0.2126 * $channel( $rgb[0] ) + 0.7152 * $channel( $rgb[1] ) + 0.0722 * $channel( $rgb[2] );
	}

	/**
	 * WCAG 2.x contrast ratio (1–21).
	 */
	public static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * Linear mix: $weight of $a plus (1 - $weight) of $b.
	 */
	public static function mix( string $a, string $b, float $weight ): string {
		$ra = self::rgb( $a ) ?? array( 0, 0, 0 );
		$rb = self::rgb( $b ) ?? array( 0, 0, 0 );
		$w  = max( 0.0, min( 1.0, $weight ) );
		return self::hex(
			array(
				(int) round( $ra[0] * $w + $rb[0] * ( 1 - $w ) ),
				(int) round( $ra[1] * $w + $rb[1] * ( 1 - $w ) ),
				(int) round( $ra[2] * $w + $rb[2] * ( 1 - $w ) ),
			)
		);
	}

	/**
	 * Sass darken(): lowers HSL lightness by $points percentage points (0–100).
	 */
	public static function darken( string $hex, float $points ): string {
		$rgb = self::rgb( $hex );
		if ( ! $rgb ) {
			return $hex;
		}
		list( $h, $s, $l ) = self::to_hsl( $rgb );
		$l                 = max( 0.0, $l - $points / 100 );
		return self::hex( self::from_hsl( $h, $s, $l ) );
	}

	/**
	 * Picks #ffffff or #1e1e1e, whichever contrasts more with $background.
	 */
	public static function readable_on( string $background ): string {
		return self::contrast( '#ffffff', $background ) >= self::contrast( '#1e1e1e', $background ) ? '#ffffff' : '#1e1e1e';
	}

	private static function to_hsl( array $rgb ): array {
		list( $r, $g, $b ) = array( $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255 );
		$max               = max( $r, $g, $b );
		$min               = min( $r, $g, $b );
		$l                 = ( $max + $min ) / 2;
		if ( $max === $min ) {
			return array( 0.0, 0.0, $l );
		}
		$d = $max - $min;
		$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
		if ( $max === $r ) {
			$h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 );
		} elseif ( $max === $g ) {
			$h = ( $b - $r ) / $d + 2;
		} else {
			$h = ( $r - $g ) / $d + 4;
		}
		return array( $h / 6, $s, $l );
	}

	private static function from_hsl( float $h, float $s, float $l ): array {
		if ( 0.0 === $s ) {
			$v = (int) round( $l * 255 );
			return array( $v, $v, $v );
		}
		$q   = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
		$p   = 2 * $l - $q;
		$hue = static function ( $t ) use ( $p, $q ) {
			if ( $t < 0 ) {
				++$t;
			}
			if ( $t > 1 ) {
				--$t;
			}
			if ( $t < 1 / 6 ) {
				return $p + ( $q - $p ) * 6 * $t;
			}
			if ( $t < 1 / 2 ) {
				return $q;
			}
			if ( $t < 2 / 3 ) {
				return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;
			}
			return $p;
		};
		return array(
			(int) round( $hue( $h + 1 / 3 ) * 255 ),
			(int) round( $hue( $h ) * 255 ),
			(int) round( $hue( $h - 1 / 3 ) * 255 ),
		);
	}

	private static function clamp( $v ): int {
		return max( 0, min( 255, (int) $v ) );
	}
}
