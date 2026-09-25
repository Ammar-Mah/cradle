<?php
/**
 * Branding runtime: footer text, admin title, admin bar brand menu, dashboard
 * welcome panel and login page presentation.
 *
 * White labeling changes presentation only. It never removes the WordPress
 * version, update/security notices, the updates admin bar item or license
 * information, and the brand menu keeps an "About WordPress" link.
 *
 * @package Cradle\Admin
 */

namespace Cradle\Admin;

defined( 'ABSPATH' ) || exit;

final class Branding {

	const LOGIN_HANDLE = 'cradle-admin-login';

	public static function hooks() {
		add_filter( 'admin_footer_text', array( self::class, 'footer_text' ), 20 );
		add_filter( 'admin_title', array( self::class, 'admin_title' ), 20, 2 );
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 11 );
		add_action( 'wp_dashboard_setup', array( self::class, 'dashboard_setup' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'admin_bar_style' ), 30 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'admin_bar_style' ), 30 );
		add_action( 'login_enqueue_scripts', array( self::class, 'login_enqueue' ) );
		add_filter( 'login_body_class', array( self::class, 'login_body_class' ) );
		add_filter( 'login_headerurl', array( self::class, 'login_headerurl' ), 20 );
		add_filter( 'login_headertext', array( self::class, 'login_headertext' ), 20 );
		add_filter( 'login_title', array( self::class, 'login_title' ), 20, 2 );
	}

	private static function values(): array {
		return Module::values( Module::SECTION_BRANDING );
	}

	public static function brand_name(): string {
		$name = (string) ( self::values()['brand_name'] ?? '' );
		return trim( $name );
	}

	/**
	 * URL of a logo image, or '' when unset/missing/not an image.
	 *
	 * @param string $which 'admin' or 'login' (login falls back to admin).
	 */
	public static function logo_url( string $which = 'admin' ): string {
		$values = self::values();
		$id     = (int) ( 'login' === $which ? ( $values['login_logo'] ?? 0 ) : ( $values['admin_logo'] ?? 0 ) );
		if ( 'login' === $which && ! $id ) {
			$id = (int) ( $values['admin_logo'] ?? 0 );
		}
		if ( $id <= 0 || ! wp_attachment_is_image( $id ) ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $id, 'medium' );
		if ( ! $url ) {
			$url = wp_get_attachment_url( $id );
		}
		$url = is_string( $url ) ? esc_url_raw( $url, array( 'http', 'https' ) ) : '';
		return $url;
	}

	/*
	 * ---------------------------- Admin ----------------------------
	 */

	/**
	 * @param string $text Footer HTML.
	 */
	public static function footer_text( $text ) {
		if ( Module::safe_mode() ) {
			return $text;
		}
		$custom = (string) ( self::values()['footer_text'] ?? '' );
		return '' === trim( $custom ) ? $text : '<span class="cradle-admin-footer">' . esc_html( $custom ) . '</span>';
	}

	/**
	 * Replaces the trailing "WordPress" of the admin document title with the brand name.
	 *
	 * @param string $admin_title Full title (escaped later by WordPress).
	 * @param string $title       Screen title.
	 */
	public static function admin_title( $admin_title, $title = '' ) {
		unset( $title );
		return self::brand_title( (string) $admin_title );
	}

	/**
	 * @param string $login_title Full login title.
	 * @param string $title       Screen title.
	 */
	public static function login_title( $login_title, $title = '' ) {
		unset( $title );
		if ( empty( self::values()['login_branding'] ) ) {
			return $login_title;
		}
		return self::brand_title( (string) $login_title );
	}

	private static function brand_title( string $full ): string {
		$brand = self::brand_name();
		if ( '' === $brand || Module::safe_mode() ) {
			return $full;
		}
		$pos = strrpos( $full, 'WordPress' );
		if ( false === $pos ) {
			return $full;
		}
		return substr_replace( $full, $brand, $pos, strlen( 'WordPress' ) );
	}

	/**
	 * Replaces the WordPress logo menu with the brand menu (priority 11, right after it was added).
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public static function admin_bar( $bar ) {
		$values = self::values();
		if ( Module::safe_mode() || empty( $values['admin_bar_branding'] ) || ! $bar instanceof \WP_Admin_Bar ) {
			return;
		}
		$name   = self::brand_name();
		$name   = '' === $name ? (string) get_bloginfo( 'name' ) : $name;
		$visual = Module::values( Module::SECTION_VISUAL );
		$logo   = in_array( $visual['logo_placement'] ?? 'menu', array( 'adminbar', 'both' ), true ) ? self::logo_url( 'admin' ) : '';
		if ( '' !== $logo ) {
			$title = '<img class="cradle-admin-bar-logo" src="' . esc_url( $logo ) . '" alt="" /><span class="screen-reader-text">' . esc_html( $name ) . '</span>';
		} else {
			$title = '<span class="ab-label">' . esc_html( $name ) . '</span>';
		}
		$href = (string) ( $values['brand_url'] ?? '' );
		$bar->remove_node( 'wp-logo' );
		$bar->add_node(
			array(
				'id'    => 'cradle-brand',
				'title' => $title,
				'href'  => '' !== $href ? $href : admin_url(),
				'meta'  => array(
					'class' => 'cradle-admin-brand',
				),
			)
		);
		$links = is_array( $values['support_links'] ?? null ) ? $values['support_links'] : array();
		foreach ( array_values( $links ) as $index => $link ) {
			if ( empty( $link['label'] ) || empty( $link['url'] ) ) {
				continue;
			}
			$bar->add_node(
				array(
					'parent' => 'cradle-brand',
					'id'     => 'cradle-brand-link-' . $index,
					'title'  => esc_html( (string) $link['label'] ),
					'href'   => (string) $link['url'],
				)
			);
		}
		$bar->add_node(
			array(
				'parent' => 'cradle-brand',
				'id'     => 'cradle-brand-about-wordpress',
				'title'  => esc_html__( 'About WordPress', 'cradle-admin' ),
				'href'   => self_admin_url( 'about.php' ),
			)
		);
	}

	/**
	 * Small admin bar rule for the brand logo (admin and front end).
	 */
	public static function admin_bar_style() {
		if ( Module::safe_mode() || ! is_admin_bar_showing() || empty( self::values()['admin_bar_branding'] ) ) {
			return;
		}
		wp_add_inline_style( 'admin-bar', '#wpadminbar #wp-admin-bar-cradle-brand img.cradle-admin-bar-logo{display:inline-block;max-height:20px;max-width:120px;width:auto;height:auto;vertical-align:middle;margin-block:-4px 0}' );
	}

	public static function dashboard_setup() {
		$values = self::values();
		if ( Module::safe_mode() || empty( $values['welcome_enabled'] ) ) {
			return;
		}
		$content = (string) ( $values['welcome_content'] ?? '' );
		$links   = is_array( $values['support_links'] ?? null ) ? $values['support_links'] : array();
		if ( '' === trim( $content ) && ! $links ) {
			return;
		}
		$title = trim( (string) ( $values['welcome_title'] ?? '' ) );
		if ( '' === $title ) {
			$brand = self::brand_name();
			/* translators: %s: brand or site name */
			$title = sprintf( __( 'Welcome to %s', 'cradle-admin' ), '' !== $brand ? $brand : get_bloginfo( 'name' ) );
		}
		wp_add_dashboard_widget( 'cradle_admin_welcome', esc_html( $title ), array( self::class, 'render_welcome' ), null, null, 'normal', 'high' );
	}

	public static function render_welcome() {
		$values  = self::values();
		$content = Sections::kses_rich_text( (string) ( $values['welcome_content'] ?? '' ) );
		$links   = is_array( $values['support_links'] ?? null ) ? $values['support_links'] : array();
		echo '<div class="cradle-admin-welcome">';
		if ( '' !== trim( $content ) ) {
			echo wp_kses( wpautop( $content ), Sections::rich_text_tags(), array( 'https', 'http', 'mailto' ) );
		}
		if ( $links ) {
			echo '<h3>' . esc_html__( 'Help and support', 'cradle-admin' ) . '</h3><ul>';
			foreach ( $links as $link ) {
				if ( ! empty( $link['label'] ) && ! empty( $link['url'] ) ) {
					echo '<li><a href="' . esc_url( (string) $link['url'] ) . '">' . esc_html( (string) $link['label'] ) . '</a></li>';
				}
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/*
	 * ---------------------------- Login ----------------------------
	 */

	private static function login_active(): bool {
		return ! Module::safe_mode() && ! empty( self::values()['login_branding'] );
	}

	public static function login_enqueue() {
		if ( ! self::login_active() ) {
			return;
		}
		$values = self::values();
		\Cradle\Core\Assets::register_style( self::LOGIN_HANDLE, CRADLE_ADMIN_FILE, 'assets/css/login.css', array( 'login' ), CRADLE_ADMIN_VERSION );
		wp_enqueue_style( self::LOGIN_HANDLE );
		$accent   = Skin::hex( $values['login_accent'] ?? '', '#3858e9' );
		$darker10 = Color::darken( $accent, 10 );
		$darker20 = Color::darken( $accent, 20 );
		$vars     = array(
			'--wp-admin-theme-color'                => $accent,
			'--wp-admin-theme-color--rgb'           => Color::rgb_list( $accent ),
			'--wp-admin-theme-color-darker-10'      => $darker10,
			'--wp-admin-theme-color-darker-10--rgb' => Color::rgb_list( $darker10 ),
			'--wp-admin-theme-color-darker-20'      => $darker20,
			'--wp-admin-theme-color-darker-20--rgb' => Color::rgb_list( $darker20 ),
			'--cradle-login-bg'                     => Skin::hex( $values['login_background'] ?? '', '#f0f0f1' ),
			'--cradle-login-text'                   => Skin::hex( $values['login_text'] ?? '', '#3c434a' ),
			'--cradle-login-accent'                 => $accent,
			'--cradle-login-logo-width'             => Skin::px( $values['login_logo_width'] ?? null, 40, 320, 84 ),
			'--cradle-login-logo-height'            => Skin::px( $values['login_logo_height'] ?? null, 40, 200, 84 ),
		);
		$logo = self::logo_url( 'login' );
		if ( '' !== $logo ) {
			$vars['--cradle-login-logo'] = 'url("' . Skin::css_url( $logo ) . '")';
		}
		$css = '';
		foreach ( $vars as $name => $value ) {
			$css .= $name . ':' . $value . ';';
		}
		wp_add_inline_style( self::LOGIN_HANDLE, 'body.login.cradle-admin-login{' . $css . '}' );
	}

	/**
	 * @param string[] $classes Login body classes.
	 */
	public static function login_body_class( $classes ) {
		if ( ! self::login_active() ) {
			return $classes;
		}
		$classes   = (array) $classes;
		$classes[] = 'cradle-admin-login';
		$classes[] = '' !== self::logo_url( 'login' ) ? 'cradle-admin-login--logo' : 'cradle-admin-login--text';
		return $classes;
	}

	/**
	 * @param string $url Header logo link.
	 */
	public static function login_headerurl( $url ) {
		if ( ! self::login_active() ) {
			return $url;
		}
		$brand_url = (string) ( self::values()['brand_url'] ?? '' );
		return '' !== $brand_url ? $brand_url : home_url( '/' );
	}

	/**
	 * @param string $text Header logo text.
	 */
	public static function login_headertext( $text ) {
		if ( ! self::login_active() ) {
			return $text;
		}
		// wp-login.php prints this value without escaping; escape here.
		$brand = self::brand_name();
		return esc_html( '' !== $brand ? $brand : (string) get_bloginfo( 'name' ) );
	}
}
