<?php
/**
 * Scoped admin appearance: body classes on approved screens, the skin
 * stylesheet, CSS custom properties from validated settings, the menu logo
 * and the trusted custom CSS. Nothing rewrites the admin DOM.
 *
 * @package Cradle\Admin
 */

namespace Cradle\Admin;

use Cradle\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Skin {

	const HANDLE        = 'cradle-admin-skin';
	const CUSTOM_HANDLE = 'cradle-admin-custom-css';

	public static function hooks() {
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'admin_body_class', array( self::class, 'body_class' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ), 20 );
	}

	/**
	 * Whether a user chose the default WordPress appearance (and may choose).
	 */
	public static function user_prefers_native( int $user_id, ?array $visual = null ): bool {
		$visual = $visual ?? Module::values( Module::SECTION_VISUAL );
		if ( ! $user_id || empty( $visual['allow_user_choice'] ) ) {
			return false;
		}
		return '1' === (string) get_user_meta( $user_id, Module::USER_META, true );
	}

	/**
	 * Styling state for a screen and user.
	 *
	 * @param \WP_Screen|null $screen  Screen (defaults to current).
	 * @param int|null        $user_id User (defaults to current).
	 * @return array{skin:bool,layout:bool,logo:string,custom_css:string,classes:string[]}
	 */
	public static function state( $screen = null, ?int $user_id = null ): array {
		$off = array(
			'skin'       => false,
			'layout'     => false,
			'logo'       => '',
			'custom_css' => '',
			'classes'    => array(),
		);
		if ( Module::safe_mode() ) {
			return $off;
		}
		$screen = $screen ?? ( function_exists( 'get_current_screen' ) ? get_current_screen() : null );
		if ( ! $screen instanceof \WP_Screen ) {
			return $off;
		}
		$visual = Module::values( Module::SECTION_VISUAL );
		if ( ! Screens::is_approved( $screen, (array) ( $visual['screens'] ?? array() ) ) ) {
			return $off;
		}
		if ( self::user_prefers_native( null === $user_id ? get_current_user_id() : $user_id, $visual ) ) {
			return $off;
		}
		$state            = $off;
		$state['skin']    = ! empty( $visual['enabled'] );
		$block_editor     = 'block-editor' === Screens::group( $screen );
		$state['layout']  = $state['skin'] && ! $block_editor;
		$placement        = (string) ( $visual['logo_placement'] ?? 'menu' );
		$state['logo']    = in_array( $placement, array( 'menu', 'both' ), true ) ? Branding::logo_url( 'admin' ) : '';
		$state['custom_css'] = Custom_Css::printable();

		if ( $state['skin'] ) {
			$state['classes'][] = 'cradle-admin-skin';
		}
		if ( $state['layout'] ) {
			$state['classes'][] = 'cradle-admin-layout';
			if ( 'default' !== ( $visual['density'] ?? 'default' ) ) {
				$state['classes'][] = 'cradle-admin-density-' . ( 'compact' === $visual['density'] ? 'compact' : 'comfortable' );
			}
			if ( 'compact' === ( $visual['control_size'] ?? 'default' ) ) {
				$state['classes'][] = 'cradle-admin-controls-compact';
			}
			$fonts = Sections::font_stacks();
			$font  = (string) ( $visual['font_family'] ?? 'system' );
			if ( 'system' !== $font && isset( $fonts[ $font ] ) ) {
				$state['classes'][] = 'cradle-admin-font-family';
			}
			if ( 13.0 !== (float) ( $visual['font_size']['value'] ?? 13 ) ) {
				$state['classes'][] = 'cradle-admin-font-size';
			}
		}
		if ( '' !== $state['logo'] ) {
			$state['classes'][] = 'cradle-admin-logo';
		}
		if ( '' !== $state['custom_css'] ) {
			$state['classes'][] = 'cradle-admin-custom-css';
		}
		return $state;
	}

	/**
	 * @param string $classes Space-separated admin body classes.
	 */
	public static function body_class( $classes ) {
		$state = self::state();
		if ( ! $state['classes'] ) {
			return $classes;
		}
		return rtrim( (string) $classes ) . ' ' . implode( ' ', array_map( 'sanitize_html_class', $state['classes'] ) ) . ' ';
	}

	public static function enqueue() {
		$state = self::state();
		if ( $state['skin'] || '' !== $state['logo'] ) {
			\Cradle\Core\Assets::register_style( self::HANDLE, CRADLE_ADMIN_FILE, 'assets/css/skin.css', array( 'colors' ), CRADLE_ADMIN_VERSION );
			wp_enqueue_style( self::HANDLE );
			wp_add_inline_style( self::HANDLE, self::variables_css( $state ) );
		}
		if ( '' !== $state['custom_css'] ) {
			wp_register_style( self::CUSTOM_HANDLE, false, wp_style_is( self::HANDLE, 'enqueued' ) ? array( self::HANDLE ) : array( 'colors' ), CRADLE_ADMIN_VERSION );
			wp_enqueue_style( self::CUSTOM_HANDLE );
			wp_add_inline_style( self::CUSTOM_HANDLE, $state['custom_css'] );
		}
	}

	/**
	 * CSS custom properties generated only from validated settings. Colors are
	 * re-checked as hex, dimensions are formatted from numbers with allowlisted units,
	 * enums map to fixed strings; nothing user-typed reaches this output verbatim.
	 */
	public static function variables_css( array $state ): string {
		$visual = Module::values( Module::SECTION_VISUAL );
		$lines  = array();
		if ( $state['skin'] ) {
			$accent     = self::hex( $visual['accent_color'] ?? '', Sections::DEFAULTS['accent'] );
			$menu_bg    = self::hex( $visual['menu_background'] ?? '', Sections::DEFAULTS['menu_bg'] );
			$menu_text  = self::hex( $visual['menu_text'] ?? '', Sections::DEFAULTS['menu_text'] );
			$submenu_bg = Color::luminance( $menu_bg ) < 0.5 ? Color::mix( $menu_bg, '#000000', 0.65 ) : Color::mix( $menu_bg, '#000000', 0.94 );
			if ( Color::contrast( $menu_text, $submenu_bg ) < Sections::TEXT_CONTRAST ) {
				$submenu_bg = $menu_bg;
			}
			$darker10 = Color::darken( $accent, 10 );
			$darker20 = Color::darken( $accent, 20 );
			$vars     = array(
				'--wp-admin-theme-color'                => $accent,
				'--wp-admin-theme-color--rgb'           => Color::rgb_list( $accent ),
				'--wp-admin-theme-color-darker-10'      => $darker10,
				'--wp-admin-theme-color-darker-10--rgb' => Color::rgb_list( $darker10 ),
				'--wp-admin-theme-color-darker-20'      => $darker20,
				'--wp-admin-theme-color-darker-20--rgb' => Color::rgb_list( $darker20 ),
				'--cradle-admin-accent'                 => $accent,
				'--cradle-admin-accent-hover'           => $darker20,
				'--cradle-admin-on-accent'              => Color::readable_on( $accent ),
				'--cradle-admin-bg'                     => self::hex( $visual['background_color'] ?? '', Sections::DEFAULTS['background'] ),
				'--cradle-admin-text'                   => self::hex( $visual['text_color'] ?? '', Sections::DEFAULTS['text'] ),
				'--cradle-admin-border'                 => self::hex( $visual['border_color'] ?? '', Sections::DEFAULTS['border'] ),
				'--cradle-admin-focus'                  => self::hex( $visual['focus_color'] ?? '', Sections::DEFAULTS['focus'] ),
				'--cradle-admin-menu-bg'                => $menu_bg,
				'--cradle-admin-menu-text'              => $menu_text,
				'--cradle-admin-submenu-bg'             => $submenu_bg,
				'--cradle-admin-sidebar-width'          => self::px( $visual['sidebar_width'] ?? null, 140, 320, 160 ),
				'--cradle-admin-spacing'                => self::px( $visual['content_spacing'] ?? null, 8, 48, 20 ),
				'--cradle-admin-radius'                 => self::px( $visual['border_radius'] ?? null, 0, 16, 2 ),
				'--cradle-admin-font-size'              => self::px( $visual['font_size'] ?? null, 12, 16, 13 ),
			);
			$fonts    = Sections::font_stacks();
			$font     = (string) ( $visual['font_family'] ?? 'system' );
			if ( 'system' !== $font && isset( $fonts[ $font ] ) ) {
				$vars['--cradle-admin-font-family'] = $fonts[ $font ]['stack'];
			}
			$lines[] = 'body.wp-admin.cradle-admin-skin{' . self::declarations( $vars ) . '}';
		}
		if ( '' !== $state['logo'] ) {
			$lines[] = 'body.wp-admin.cradle-admin-logo{' . self::declarations(
				array(
					'--cradle-admin-logo'        => 'url("' . self::css_url( $state['logo'] ) . '")',
					'--cradle-admin-logo-width'  => self::px( $visual['logo_width'] ?? null, 24, 280, 120 ),
					'--cradle-admin-logo-height' => self::px( $visual['logo_height'] ?? null, 16, 120, 40 ),
				)
			) . '}';
		}
		return implode( "\n", $lines );
	}

	private static function declarations( array $vars ): string {
		$out = '';
		foreach ( $vars as $name => $value ) {
			$out .= $name . ':' . $value . ';';
		}
		return $out;
	}

	public static function hex( $value, string $fallback ): string {
		$hex = is_string( $value ) ? sanitize_hex_color( $value ) : null;
		return $hex ? strtolower( $hex ) : $fallback;
	}

	/**
	 * Pixel value from a stored dimension, clamped again at output.
	 */
	public static function px( $value, float $min, float $max, float $fallback ): string {
		$num = is_array( $value ) && isset( $value['value'] ) && is_numeric( $value['value'] ) && 'px' === ( $value['unit'] ?? '' ) ? (float) $value['value'] : $fallback;
		$num = max( $min, min( $max, $num ) );
		return Settings::css_dimension(
			array(
				'value' => $num,
				'unit'  => 'px',
			)
		);
	}

	/**
	 * Makes an http(s) URL safe inside url("...") by percent-encoding CSS/HTML-significant characters.
	 */
	public static function css_url( string $url ): string {
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		return strtr(
			$url,
			array(
				'"'  => '%22',
				"'"  => '%27',
				'('  => '%28',
				')'  => '%29',
				'\\' => '%5C',
				' '  => '%20',
				'<'  => '%3C',
				'>'  => '%3E',
				"\n" => '',
				"\r" => '',
			)
		);
	}
}
