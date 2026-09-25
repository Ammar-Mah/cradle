<?php
/**
 * Presentation options: merges site settings with per-component overrides and
 * formats the only CSS values that reach inline styles (validated colors).
 *
 * Stored settings are re-checked against allowlists here because stored data
 * is not trusted merely because it came from the database.
 *
 * @package Cradle\Social
 */

namespace Cradle\Social;

defined( 'ABSPATH' ) || exit;

final class Style {

	const DISPLAY     = array( 'icon_text', 'icon', 'text' );
	const LABEL_STYLE = array( 'service', 'action' );
	const SIZE        = array( 'small', 'medium', 'large' );
	const SPACING     = array( 'compact', 'normal', 'relaxed' );
	const SHAPE       = array( 'none', 'square', 'rounded', 'circle' );
	const JUSTIFY     = array( 'start', 'center', 'end', 'space-between' );

	/**
	 * Effective options for one rendered instance.
	 *
	 * @param array  $p     Normalized component properties.
	 * @param string $group `profiles` or `share`.
	 */
	public static function options( array $p, string $group ): array {
		$s = Config::values();

		$pick = static function ( string $prop, string $setting, array $allowed, string $fallback ) use ( $p, $s ) {
			$value = (string) ( $p[ $prop ] ?? '' );
			if ( '' === $value || ! in_array( $value, $allowed, true ) ) {
				$value = (string) ( $s[ $setting ] ?? '' );
			}
			return in_array( $value, $allowed, true ) ? $value : $fallback;
		};
		$tristate = static function ( string $prop, string $on, string $off, $setting ) use ( $p ) {
			$value = (string) ( $p[ $prop ] ?? '' );
			if ( $on === $value ) {
				return true;
			}
			if ( $off === $value ) {
				return false;
			}
			return true === $setting;
		};

		$default_label = 'share' === $group ? __( 'Share this page', 'cradle-social' ) : __( 'Follow us', 'cradle-social' );
		$label         = Providers::plain( (string) ( $p['label'] ?? '' ), 120 );
		if ( '' === $label ) {
			$label = Providers::plain( (string) ( $s[ $group . '_label' ] ?? '' ), 120 );
		}

		return array(
			'display'     => $pick( 'display', 'display', self::DISPLAY, 'icon_text' ),
			'label_style' => $pick( 'labelStyle', 'label_style', self::LABEL_STYLE, 'service' ),
			'size'        => $pick( 'size', 'size', self::SIZE, 'medium' ),
			'spacing'     => $pick( 'spacing', 'spacing', self::SPACING, 'normal' ),
			'shape'       => $pick( 'shape', 'shape', self::SHAPE, 'rounded' ),
			'justify'     => in_array( $p['justify'] ?? '', self::JUSTIFY, true ) ? $p['justify'] : 'start',
			'new_tab'     => $tristate( 'newTab', 'on', 'off', $s[ $group . '_new_tab' ] ?? false ),
			'show_label'  => $tristate( 'showLabel', 'show', 'hide', $s[ $group . '_show_label' ] ?? false ),
			'label'       => '' !== $label ? $label : $default_label,
			'color'       => self::color_css( (string) ( $s['color_text'] ?? '' ), '' ),
			'background'  => self::color_css( (string) ( $s['color_background'] ?? '' ), '' ),
		);
	}

	/**
	 * CSS for a validated color setting: a lowercase hex color or a theme
	 * preset custom property. Anything else yields ''.
	 *
	 * @param string $value    Stored value (`#hex`, `preset:slug` or empty).
	 * @param string $fallback Optional CSS fallback inside var().
	 */
	public static function color_css( string $value, string $fallback = '' ): string {
		if ( preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ) {
			return strtolower( $value );
		}
		if ( preg_match( '/^preset:([a-z0-9-]{1,60})$/', $value, $m ) ) {
			$fallback = preg_match( '/^(?:#[0-9a-f]{3,6}|currentColor|transparent)$/', $fallback ) ? $fallback : '';
			return 'var(--wp--preset--color--' . $m[1] . ( '' !== $fallback ? ', ' . $fallback : '' ) . ')';
		}
		return '';
	}

	/**
	 * Theme and user palette colors as slug => name, for the settings control.
	 *
	 * @return array<string,string>
	 */
	public static function palette(): array {
		$out = array();
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return $out;
		}
		$palette = wp_get_global_settings( array( 'color', 'palette' ) );
		foreach ( array( 'theme', 'custom', 'default' ) as $origin ) {
			foreach ( (array) ( $palette[ $origin ] ?? array() ) as $color ) {
				$slug = (string) ( $color['slug'] ?? '' );
				if ( preg_match( '/^[a-z0-9-]{1,60}$/', $slug ) && ! isset( $out[ $slug ] ) ) {
					$out[ $slug ] = (string) ( $color['name'] ?? $slug );
				}
			}
		}
		return $out;
	}
}
