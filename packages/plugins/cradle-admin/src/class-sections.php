<?php
/**
 * Settings section schemas (core Settings API) and their validators.
 *
 * Every default is neutral: white-label features stay off until configured,
 * and default colors equal the WordPress 7.x "Default" admin color scheme.
 *
 * @package Cradle\Admin
 */

namespace Cradle\Admin;

use Cradle\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Sections {

	/** Minimum WCAG contrast for text. */
	const TEXT_CONTRAST = 4.5;

	/** Minimum WCAG contrast for focus indicators and other non-text UI. */
	const UI_CONTRAST = 3.0;

	const DEFAULTS = array(
		'accent'     => '#3858e9',
		'background' => '#f0f0f0',
		'text'       => '#1e1e1e',
		'border'     => '#dcdcde',
		'focus'      => '#3858e9',
		'menu_bg'    => '#1e1e1e',
		'menu_text'  => '#ffffff',
	);

	public static function register( $settings ) {
		$settings->register_section( self::branding() );
		$settings->register_section( self::visual() );
		$settings->register_section( self::labels() );
	}

	private static function px( float $value ): array {
		return array(
			'value' => $value,
			'unit'  => 'px',
		);
	}

	public static function branding(): array {
		return array(
			'id'            => Module::SECTION_BRANDING,
			'owner'         => Module::ID,
			'title'         => __( 'Branding', 'cradle-admin' ),
			'description'   => __( 'Brand name, logos, footer, support links, dashboard welcome panel, admin bar and login page. Every item stays off or empty until you set it; WordPress update and security notices are never hidden.', 'cradle-admin' ),
			'capability'    => Module::CAP,
			'schemaVersion' => 1,
			'page'          => false,
			'autoload'      => true,
			'lifecycle'     => array( 'uninstall' => 'retain' ),
			'fields'        => array(
				'brand_name'         => array(
					'type'      => 'string',
					'default'   => '',
					'maxLength' => 80,
					'label'     => __( 'Brand name', 'cradle-admin' ),
					'help'      => __( 'Shown in the admin bar brand menu, the browser title of admin screens and the login page. Leave empty to keep WordPress naming.', 'cradle-admin' ),
				),
				'brand_url'          => array(
					'type'      => 'url',
					'default'   => '',
					'protocols' => array( 'https', 'http' ),
					'label'     => __( 'Brand link', 'cradle-admin' ),
					'help'      => __( 'Where the logo links on the login page and in the admin bar. Empty uses the site home page (login) or the dashboard (admin bar).', 'cradle-admin' ),
				),
				'admin_logo'         => array(
					'type'    => 'attachment',
					'default' => 0,
					'mime'    => array( 'image/' ),
					'label'   => __( 'Admin logo', 'cradle-admin' ),
					'help'    => __( 'Image from the media library. Placement and size are set under Appearance.', 'cradle-admin' ),
				),
				'footer_text'        => array(
					'type'      => 'string',
					'default'   => '',
					'maxLength' => 200,
					'label'     => __( 'Footer text', 'cradle-admin' ),
					'help'      => __( 'Plain text replacing “Thank you for creating with WordPress”. The WordPress version shown on the right is kept.', 'cradle-admin' ),
				),
				'support_links'      => array(
					'type'     => 'object',
					'default'  => array(),
					'control'  => 'radio', // Renders a group label (fieldset) for this multi-input control.
					'label'    => __( 'Support and help links', 'cradle-admin' ),
					'help'     => __( 'Up to five https/http links shown in the admin bar brand menu and the dashboard welcome panel.', 'cradle-admin' ),
					'validate' => array( self::class, 'validate_links' ),
					'render'   => array( Admin\Fields::class, 'links' ),
				),
				'admin_bar_branding' => array(
					'type'           => 'boolean',
					'default'        => false,
					'label'          => __( 'Admin bar branding', 'cradle-admin' ),
					'checkbox_label' => __( 'Replace the WordPress logo menu with the brand menu', 'cradle-admin' ),
					'help'           => __( 'The brand menu keeps an “About WordPress” link. Update, comment and account items are never changed.', 'cradle-admin' ),
				),
				'welcome_enabled'    => array(
					'type'           => 'boolean',
					'default'        => false,
					'label'          => __( 'Dashboard welcome panel', 'cradle-admin' ),
					'checkbox_label' => __( 'Show the welcome panel on the dashboard', 'cradle-admin' ),
				),
				'welcome_title'      => array(
					'type'      => 'string',
					'default'   => '',
					'maxLength' => 120,
					'label'     => __( 'Welcome panel title', 'cradle-admin' ),
				),
				'welcome_content'    => array(
					'type'     => 'object',
					'default'  => '',
					'label'    => __( 'Welcome panel content', 'cradle-admin' ),
					'help'     => __( 'Limited rich text: paragraphs, line breaks, bold, italic, lists, level 3–4 headings, code and https/http/mailto links. Anything else is removed.', 'cradle-admin' ),
					'validate' => array( self::class, 'validate_rich_text' ),
					'render'   => array( Admin\Fields::class, 'rich_text' ),
				),
				'login_branding'     => array(
					'type'           => 'boolean',
					'default'        => false,
					'label'          => __( 'Login page branding', 'cradle-admin' ),
					'checkbox_label' => __( 'Apply the brand name, logo and colors to the login page', 'cradle-admin' ),
				),
				'login_logo'         => array(
					'type'    => 'attachment',
					'default' => 0,
					'mime'    => array( 'image/' ),
					'label'   => __( 'Login logo', 'cradle-admin' ),
					'help'    => __( 'Optional. Falls back to the admin logo, then to the brand name as text.', 'cradle-admin' ),
				),
				'login_logo_width'   => array(
					'type'     => 'dimension',
					'default'  => self::px( 84 ),
					'units'    => array( 'px' ),
					'minimum'  => 40,
					'maximum'  => 320,
					'step'     => 1,
					'label'    => __( 'Login logo width', 'cradle-admin' ),
				),
				'login_logo_height'  => array(
					'type'    => 'dimension',
					'default' => self::px( 84 ),
					'units'   => array( 'px' ),
					'minimum' => 40,
					'maximum' => 200,
					'step'    => 1,
					'label'   => __( 'Login logo height', 'cradle-admin' ),
				),
				'login_background'   => array(
					'type'    => 'color',
					'default' => '#f0f0f1',
					'label'   => __( 'Login background color', 'cradle-admin' ),
				),
				'login_text'         => array(
					'type'    => 'color',
					'default' => '#3c434a',
					'label'   => __( 'Login text and link color', 'cradle-admin' ),
					'help'    => __( 'Needs at least 4.5:1 contrast with the login background.', 'cradle-admin' ),
				),
				'login_accent'       => array(
					'type'    => 'color',
					'default' => '#3858e9',
					'label'   => __( 'Login button color', 'cradle-admin' ),
					'help'    => __( 'Button text is white, so this color needs at least 4.5:1 contrast with white.', 'cradle-admin' ),
				),
			),
			'validate'      => array( self::class, 'validate_branding' ),
		);
	}

	public static function visual(): array {
		$fonts = self::font_stacks();
		$fenum = array();
		foreach ( $fonts as $key => $font ) {
			$fenum[ $key ] = $font['label'];
		}
		return array(
			'id'            => Module::SECTION_VISUAL,
			'owner'         => Module::ID,
			'title'         => __( 'Admin appearance', 'cradle-admin' ),
			'description'   => __( 'Structured visual controls, output as CSS custom properties scoped to a body class on approved screens only. This settings page itself always uses the native appearance so it stays usable.', 'cradle-admin' ),
			'capability'    => Module::CAP,
			'schemaVersion' => 1,
			'page'          => false,
			'autoload'      => true,
			'lifecycle'     => array( 'uninstall' => 'retain' ),
			'fields'        => array(
				'enabled'           => array(
					'type'           => 'boolean',
					'default'        => false,
					'label'          => __( 'Cradle admin appearance', 'cradle-admin' ),
					'checkbox_label' => __( 'Apply these colors, layout and typography as the default admin appearance', 'cradle-admin' ),
				),
				'allow_user_choice' => array(
					'type'           => 'boolean',
					'default'        => true,
					'label'          => __( 'Per-user choice', 'cradle-admin' ),
					'checkbox_label' => __( 'Let each user switch back to the default WordPress appearance on their profile', 'cradle-admin' ),
				),
				'screens'           => array(
					'type'    => 'multi_enum',
					'enum'    => Screens::labels(),
					'default' => Screens::default_groups(),
					'label'   => __( 'Approved screens', 'cradle-admin' ),
					'help'    => __( 'Styling, the menu logo and custom CSS apply only on these screens.', 'cradle-admin' ),
				),
				'accent_color'      => array(
					'type'    => 'color',
					'default' => self::DEFAULTS['accent'],
					'label'   => __( 'Accent color', 'cradle-admin' ),
					'help'    => __( 'Buttons, links, current menu item. Needs 4.5:1 contrast with white and with the background.', 'cradle-admin' ),
				),
				'background_color'  => array(
					'type'    => 'color',
					'default' => self::DEFAULTS['background'],
					'label'   => __( 'Background color', 'cradle-admin' ),
				),
				'text_color'        => array(
					'type'    => 'color',
					'default' => self::DEFAULTS['text'],
					'label'   => __( 'Text color', 'cradle-admin' ),
					'help'    => __( 'Content panels stay white, so text needs 4.5:1 contrast with both the background and white.', 'cradle-admin' ),
				),
				'border_color'      => array(
					'type'    => 'color',
					'default' => self::DEFAULTS['border'],
					'label'   => __( 'Border color', 'cradle-admin' ),
					'help'    => __( 'Panel and table borders (decorative). Form field borders keep WordPress defaults.', 'cradle-admin' ),
				),
				'focus_color'       => array(
					'type'    => 'color',
					'default' => self::DEFAULTS['focus'],
					'label'   => __( 'Focus indicator color', 'cradle-admin' ),
					'help'    => __( 'Keyboard focus outline. Needs 3:1 contrast with the background and with white.', 'cradle-admin' ),
				),
				'menu_background'   => array(
					'type'    => 'color',
					'default' => self::DEFAULTS['menu_bg'],
					'label'   => __( 'Menu and admin bar background', 'cradle-admin' ),
				),
				'menu_text'         => array(
					'type'    => 'color',
					'default' => self::DEFAULTS['menu_text'],
					'label'   => __( 'Menu and admin bar text', 'cradle-admin' ),
					'help'    => __( 'Needs 4.5:1 contrast with the menu background.', 'cradle-admin' ),
				),
				'sidebar_width'     => array(
					'type'    => 'dimension',
					'default' => self::px( 160 ),
					'units'   => array( 'px' ),
					'minimum' => 140,
					'maximum' => 320,
					'step'    => 1,
					'label'   => __( 'Admin menu width', 'cradle-admin' ),
					'help'    => __( 'Applies on wide screens only; folded, auto-folded (narrow) and mobile menus keep WordPress behavior. Never applied in the block editor.', 'cradle-admin' ),
				),
				'content_spacing'   => array(
					'type'    => 'dimension',
					'default' => self::px( 20 ),
					'units'   => array( 'px' ),
					'minimum' => 8,
					'maximum' => 48,
					'step'    => 1,
					'label'   => __( 'Content spacing', 'cradle-admin' ),
					'help'    => __( 'Space between the menu and screen content on wide screens.', 'cradle-admin' ),
				),
				'density'           => array(
					'type'    => 'enum',
					'enum'    => array(
						'default'     => __( 'Default', 'cradle-admin' ),
						'compact'     => __( 'Compact', 'cradle-admin' ),
						'comfortable' => __( 'Comfortable', 'cradle-admin' ),
					),
					'default' => 'default',
					'control' => 'radio',
					'label'   => __( 'Content density', 'cradle-admin' ),
					'help'    => __( 'Cell spacing in list tables, settings forms and panels.', 'cradle-admin' ),
				),
				'control_size'      => array(
					'type'    => 'enum',
					'enum'    => array(
						'default' => __( 'Default (40px)', 'cradle-admin' ),
						'compact' => __( 'Compact (32px, still above the 24px minimum target size)', 'cradle-admin' ),
					),
					'default' => 'default',
					'control' => 'radio',
					'label'   => __( 'Control size', 'cradle-admin' ),
				),
				'border_radius'     => array(
					'type'    => 'dimension',
					'default' => self::px( 2 ),
					'units'   => array( 'px' ),
					'minimum' => 0,
					'maximum' => 16,
					'step'    => 1,
					'label'   => __( 'Corner radius', 'cradle-admin' ),
					'help'    => __( 'Buttons, form fields, panels and notices.', 'cradle-admin' ),
				),
				'font_family'       => array(
					'type'    => 'enum',
					'enum'    => $fenum,
					'default' => 'system',
					'label'   => __( 'Admin typeface', 'cradle-admin' ),
					'help'    => __( 'Only the permitted local font stacks are offered; no web fonts are downloaded. Never applied in the block editor.', 'cradle-admin' ),
				),
				'font_size'         => array(
					'type'    => 'dimension',
					'default' => self::px( 13 ),
					'units'   => array( 'px' ),
					'minimum' => 12,
					'maximum' => 16,
					'step'    => 1,
					'label'   => __( 'Base font size', 'cradle-admin' ),
				),
				'logo_placement'    => array(
					'type'    => 'enum',
					'enum'    => array(
						'menu'     => __( 'Top of the admin menu', 'cradle-admin' ),
						'adminbar' => __( 'Admin bar brand menu (requires admin bar branding)', 'cradle-admin' ),
						'both'     => __( 'Both', 'cradle-admin' ),
						'none'     => __( 'Do not show the admin logo', 'cradle-admin' ),
					),
					'default' => 'menu',
					'control' => 'radio',
					'label'   => __( 'Logo placement', 'cradle-admin' ),
				),
				'logo_width'        => array(
					'type'    => 'dimension',
					'default' => self::px( 120 ),
					'units'   => array( 'px' ),
					'minimum' => 24,
					'maximum' => 280,
					'step'    => 1,
					'label'   => __( 'Menu logo maximum width', 'cradle-admin' ),
				),
				'logo_height'       => array(
					'type'    => 'dimension',
					'default' => self::px( 40 ),
					'units'   => array( 'px' ),
					'minimum' => 16,
					'maximum' => 120,
					'step'    => 1,
					'label'   => __( 'Menu logo height', 'cradle-admin' ),
				),
			),
			'validate'      => array( self::class, 'validate_visual' ),
		);
	}

	public static function labels(): array {
		return array(
			'id'            => Module::SECTION_LABELS,
			'owner'         => Module::ID,
			'title'         => __( 'Admin labels', 'cradle-admin' ),
			'description'   => __( 'Targeted label overrides by entity (post types, taxonomies), admin menu item, or supported text domain and context. Overrides change display text only; they never change permissions or hide anything.', 'cradle-admin' ),
			'capability'    => Module::CAP,
			'schemaVersion' => 1,
			'page'          => false,
			'autoload'      => true,
			'lifecycle'     => array( 'uninstall' => 'retain' ),
			'fields'        => array(
				'post_type_labels' => array(
					'type'     => 'object',
					'default'  => array(),
					'control'  => 'radio',
					'label'    => __( 'Post type labels', 'cradle-admin' ),
					'help'     => __( 'Leave a field empty to keep the registered label.', 'cradle-admin' ),
					'validate' => array( self::class, 'validate_post_type_labels' ),
					'render'   => array( Admin\Fields::class, 'post_type_labels' ),
				),
				'taxonomy_labels'  => array(
					'type'     => 'object',
					'default'  => array(),
					'control'  => 'radio',
					'label'    => __( 'Taxonomy labels', 'cradle-admin' ),
					'help'     => __( 'Leave a field empty to keep the registered label.', 'cradle-admin' ),
					'validate' => array( self::class, 'validate_taxonomy_labels' ),
					'render'   => array( Admin\Fields::class, 'taxonomy_labels' ),
				),
				'menu_labels'      => array(
					'type'     => 'object',
					'default'  => array(),
					'control'  => 'radio',
					'label'    => __( 'Admin menu labels', 'cradle-admin' ),
					'help'     => __( 'Renames selected menu items. Update and comment count bubbles are kept. Menu access is unchanged.', 'cradle-admin' ),
					'validate' => array( self::class, 'validate_menu_labels' ),
					'render'   => array( Admin\Fields::class, 'menu_labels' ),
				),
				'text_overrides'   => array(
					'type'     => 'object',
					'default'  => array(),
					'control'  => 'radio',
					'label'    => __( 'Text overrides', 'cradle-admin' ),
					'help'     => __( 'Exact admin strings from a supported text domain (optionally a translation context and a screen ID). Placeholders such as %s must be kept exactly. Applies to PHP-rendered admin text only, not to block editor JavaScript strings.', 'cradle-admin' ),
					'validate' => array( self::class, 'validate_text_overrides' ),
					'render'   => array( Admin\Fields::class, 'text_overrides' ),
				),
			),
		);
	}

	/**
	 * Permitted local font stacks. Sites may add stacks with `cradle_admin/font_stacks`;
	 * added entries are validated (key, label, and a restricted font-family character set).
	 *
	 * @return array<string,array{label:string,stack:string}>
	 */
	public static function font_stacks(): array {
		$stacks = array(
			'system'    => array(
				'label' => __( 'WordPress default', 'cradle-admin' ),
				'stack' => '',
			),
			'system-ui' => array(
				'label' => __( 'Operating system interface font', 'cradle-admin' ),
				'stack' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
			),
			'humanist'  => array(
				'label' => __( 'Humanist sans-serif', 'cradle-admin' ),
				'stack' => 'Seravek, "Gill Sans Nova", Ubuntu, Calibri, "DejaVu Sans", sans-serif',
			),
			'geometric' => array(
				'label' => __( 'Geometric sans-serif', 'cradle-admin' ),
				'stack' => 'Avenir, Montserrat, Corbel, "URW Gothic", sans-serif',
			),
			'serif'     => array(
				'label' => __( 'Serif', 'cradle-admin' ),
				'stack' => 'Charter, "Bitstream Charter", "Sitka Text", Cambria, serif',
			),
		);
		/**
		 * Filters the permitted admin font stacks (local fonts only).
		 *
		 * @param array $stacks key => array( 'label' => string, 'stack' => string ).
		 */
		$filtered = apply_filters( 'cradle_admin/font_stacks', $stacks );
		$out      = array( 'system' => $stacks['system'] );
		foreach ( is_array( $filtered ) ? $filtered : array() as $key => $font ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z0-9-]{1,40}$/', $key ) || ! is_array( $font ) || 'system' === $key ) {
				continue;
			}
			$label = isset( $font['label'] ) && is_string( $font['label'] ) ? sanitize_text_field( $font['label'] ) : '';
			$stack = isset( $font['stack'] ) && is_string( $font['stack'] ) ? trim( $font['stack'] ) : '';
			if ( '' === $label || '' === $stack || strlen( $stack ) > 300 || ! preg_match( '/^[A-Za-z0-9 ,"\'-]+$/', $stack ) ) {
				continue;
			}
			$out[ $key ] = array(
				'label' => $label,
				'stack' => $stack,
			);
		}
		return $out;
	}

	/*
	 * ------------------------------------------------------------------
	 * Validators. Each rejects invalid input first, then normalizes.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Plain single-line text without markup or control characters.
	 *
	 * @return string|\WP_Error
	 */
	public static function plain_text( $value, int $max ) {
		if ( ! is_string( $value ) ) {
			return new \WP_Error( 'type', __( 'Must be text.', 'cradle-admin' ) );
		}
		$value = trim( $value );
		if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
			return new \WP_Error( 'utf8', __( 'Contains invalid characters.', 'cradle-admin' ) );
		}
		if ( mb_strlen( $value ) > $max ) {
			/* translators: %d: maximum number of characters */
			return new \WP_Error( 'length', sprintf( __( 'Must be %d characters or fewer.', 'cradle-admin' ), $max ) );
		}
		if ( preg_match( '/[<>\x00-\x1F\x7F]/', $value ) ) {
			return new \WP_Error( 'markup', __( 'Must not contain HTML, angle brackets or control characters.', 'cradle-admin' ) );
		}
		return sanitize_text_field( $value );
	}

	/**
	 * @return array|\WP_Error
	 */
	public static function validate_links( $value ) {
		if ( '' === $value || null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'type', __( 'Must be a list of links.', 'cradle-admin' ) );
		}
		if ( count( $value ) > 20 ) {
			return new \WP_Error( 'count', __( 'Too many rows were submitted.', 'cradle-admin' ) );
		}
		$out = array();
		$row = 0;
		foreach ( $value as $item ) {
			++$row;
			if ( ! is_array( $item ) ) {
				return new \WP_Error( 'type', __( 'Each link must have a label and a URL.', 'cradle-admin' ) );
			}
			$label = $item['label'] ?? '';
			$url   = $item['url'] ?? '';
			if ( ! is_string( $label ) || ! is_string( $url ) ) {
				return new \WP_Error( 'type', __( 'Each link must have a label and a URL.', 'cradle-admin' ) );
			}
			if ( '' === trim( $label ) && '' === trim( $url ) ) {
				continue;
			}
			if ( '' === trim( $label ) || '' === trim( $url ) ) {
				/* translators: %d: row number */
				return new \WP_Error( 'incomplete', sprintf( __( 'Link %d needs both a label and a URL.', 'cradle-admin' ), $row ) );
			}
			$clean_label = self::plain_text( $label, 60 );
			if ( is_wp_error( $clean_label ) ) {
				/* translators: 1: row number, 2: error */
				return new \WP_Error( 'label', sprintf( __( 'Link %1$d label: %2$s', 'cradle-admin' ), $row, $clean_label->get_error_message() ) );
			}
			$clean_url = Settings::validate_field(
				array(
					'type'      => 'url',
					'protocols' => array( 'https', 'http' ),
				),
				$url
			);
			if ( is_wp_error( $clean_url ) ) {
				/* translators: 1: row number, 2: error */
				return new \WP_Error( 'url', sprintf( __( 'Link %1$d URL: %2$s', 'cradle-admin' ), $row, $clean_url->get_error_message() ) );
			}
			$out[] = array(
				'label' => $clean_label,
				'url'   => $clean_url,
			);
		}
		if ( count( $out ) > 5 ) {
			return new \WP_Error( 'count', __( 'At most five links are allowed.', 'cradle-admin' ) );
		}
		return $out;
	}

	/**
	 * Allowed markup for the welcome panel.
	 */
	public static function rich_text_tags(): array {
		return array(
			'p'      => array(),
			'br'     => array(),
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'h3'     => array(),
			'h4'     => array(),
			'code'   => array(),
			'a'      => array(
				'href'  => true,
				'title' => true,
			),
		);
	}

	public static function kses_rich_text( string $html ): string {
		return wp_kses( $html, self::rich_text_tags(), array( 'https', 'http', 'mailto' ) );
	}

	/**
	 * @return string|\WP_Error
	 */
	public static function validate_rich_text( $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( ! is_string( $value ) ) {
			return new \WP_Error( 'type', __( 'Must be text.', 'cradle-admin' ) );
		}
		if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
			return new \WP_Error( 'utf8', __( 'Contains invalid characters.', 'cradle-admin' ) );
		}
		if ( strlen( $value ) > 10000 ) {
			return new \WP_Error( 'length', __( 'Must be 10,000 bytes or fewer.', 'cradle-admin' ) );
		}
		return trim( self::kses_rich_text( $value ) );
	}

	public static function validate_branding( array $values, array $current ): array {
		unset( $current );
		$errors = array();
		if ( Color::contrast( $values['login_text'], $values['login_background'] ) < self::TEXT_CONTRAST ) {
			$message                    = self::contrast_message( __( 'Login text', 'cradle-admin' ), __( 'login background', 'cradle-admin' ), Color::contrast( $values['login_text'], $values['login_background'] ), self::TEXT_CONTRAST );
			$errors['login_text']       = $message;
			$errors['login_background'] = $message;
		}
		if ( Color::contrast( $values['login_accent'], '#ffffff' ) < self::TEXT_CONTRAST ) {
			$errors['login_accent'] = self::contrast_message( __( 'Login button color', 'cradle-admin' ), __( 'its white text', 'cradle-admin' ), Color::contrast( $values['login_accent'], '#ffffff' ), self::TEXT_CONTRAST );
		}
		return $errors;
	}

	/**
	 * Cross-field accessibility checks. Every field of a failing pair is reported,
	 * so both keep their previous (valid) values.
	 */
	public static function validate_visual( array $values, array $current ): array {
		unset( $current );
		$errors = array();
		$pairs  = array(
			array( 'text_color', 'background_color', self::TEXT_CONTRAST, __( 'Text', 'cradle-admin' ), __( 'the background', 'cradle-admin' ) ),
			array( 'text_color', '#ffffff', self::TEXT_CONTRAST, __( 'Text', 'cradle-admin' ), __( 'white panels', 'cradle-admin' ) ),
			array( 'accent_color', 'background_color', self::TEXT_CONTRAST, __( 'Accent', 'cradle-admin' ), __( 'the background', 'cradle-admin' ) ),
			array( 'accent_color', '#ffffff', self::TEXT_CONTRAST, __( 'Accent', 'cradle-admin' ), __( 'white', 'cradle-admin' ) ),
			array( 'focus_color', 'background_color', self::UI_CONTRAST, __( 'Focus indicator', 'cradle-admin' ), __( 'the background', 'cradle-admin' ) ),
			array( 'focus_color', '#ffffff', self::UI_CONTRAST, __( 'Focus indicator', 'cradle-admin' ), __( 'white', 'cradle-admin' ) ),
			array( 'menu_text', 'menu_background', self::TEXT_CONTRAST, __( 'Menu text', 'cradle-admin' ), __( 'the menu background', 'cradle-admin' ) ),
		);
		foreach ( $pairs as $pair ) {
			list( $a, $b, $min, $label_a, $label_b ) = $pair;
			$color_a                                = (string) $values[ $a ];
			$color_b                                = '#' === $b[0] ? $b : (string) $values[ $b ];
			$ratio                                  = Color::contrast( $color_a, $color_b );
			if ( $ratio < $min ) {
				$message      = self::contrast_message( $label_a, $label_b, $ratio, $min );
				$errors[ $a ] = isset( $errors[ $a ] ) ? $errors[ $a ] . ' ' . $message : $message;
				if ( '#' !== $b[0] ) {
					$errors[ $b ] = isset( $errors[ $b ] ) ? $errors[ $b ] . ' ' . $message : $message;
				}
			}
		}
		return $errors;
	}

	public static function contrast_message( string $a, string $b, float $ratio, float $min ): string {
		/* translators: 1: first color role, 2: second color role, 3: measured ratio, 4: required ratio */
		return sprintf( __( '%1$s on %2$s has a contrast of %3$s:1; at least %4$s:1 is required.', 'cradle-admin' ), $a, $b, number_format_i18n( $ratio, 2 ), number_format_i18n( $min, 1 ) );
	}

	/**
	 * Allowed label keys per entity kind.
	 */
	public static function entity_label_keys( string $kind ): array {
		if ( 'taxonomy' === $kind ) {
			return array(
				'name'          => __( 'Plural name', 'cradle-admin' ),
				'singular_name' => __( 'Singular name', 'cradle-admin' ),
				'menu_name'     => __( 'Menu name', 'cradle-admin' ),
				'all_items'     => __( 'All items', 'cradle-admin' ),
				'add_new_item'  => __( 'Add new item', 'cradle-admin' ),
				'edit_item'     => __( 'Edit item', 'cradle-admin' ),
				'search_items'  => __( 'Search items', 'cradle-admin' ),
			);
		}
		return array(
			'name'           => __( 'Plural name', 'cradle-admin' ),
			'singular_name'  => __( 'Singular name', 'cradle-admin' ),
			'menu_name'      => __( 'Menu name', 'cradle-admin' ),
			'all_items'      => __( 'All items', 'cradle-admin' ),
			'add_new'        => __( 'Add new (button)', 'cradle-admin' ),
			'add_new_item'   => __( 'Add new item (screen title)', 'cradle-admin' ),
			'edit_item'      => __( 'Edit item', 'cradle-admin' ),
			'search_items'   => __( 'Search items', 'cradle-admin' ),
			'name_admin_bar' => __( 'Admin bar “New” item', 'cradle-admin' ),
		);
	}

	/**
	 * @return array|\WP_Error
	 */
	public static function validate_post_type_labels( $value ) {
		return self::validate_entity_labels( $value, 'post_type' );
	}

	/**
	 * @return array|\WP_Error
	 */
	public static function validate_taxonomy_labels( $value ) {
		return self::validate_entity_labels( $value, 'taxonomy' );
	}

	/**
	 * Entities that are not registered right now (for example a content plugin that is
	 * temporarily inactive) keep their stored overrides instead of being dropped.
	 *
	 * @return array|\WP_Error
	 */
	private static function validate_entity_labels( $value, string $kind ) {
		if ( '' === $value || null === $value ) {
			$value = array();
		}
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'type', __( 'Must be a list of labels.', 'cradle-admin' ) );
		}
		if ( count( $value ) > 100 ) {
			return new \WP_Error( 'count', __( 'Too many entities were submitted.', 'cradle-admin' ) );
		}
		$keys   = self::entity_label_keys( $kind );
		$exists = 'taxonomy' === $kind ? 'taxonomy_exists' : 'post_type_exists';
		$out    = array();
		foreach ( $value as $entity => $labels ) {
			if ( ! is_string( $entity ) || ! preg_match( '/^[a-z0-9_-]{1,32}$/', $entity ) ) {
				return new \WP_Error( 'entity', __( 'Contains an invalid entity key.', 'cradle-admin' ) );
			}
			if ( ! $exists( $entity ) ) {
				/* translators: %s: post type or taxonomy key */
				return new \WP_Error( 'entity', sprintf( __( '“%s” is not registered on this site.', 'cradle-admin' ), $entity ) );
			}
			if ( ! is_array( $labels ) ) {
				return new \WP_Error( 'type', __( 'Must be a list of labels.', 'cradle-admin' ) );
			}
			foreach ( $labels as $key => $label ) {
				if ( ! is_string( $key ) || ! isset( $keys[ $key ] ) ) {
					return new \WP_Error( 'key', __( 'Contains a label that cannot be overridden.', 'cradle-admin' ) );
				}
				$clean = self::plain_text( $label, 80 );
				if ( is_wp_error( $clean ) ) {
					/* translators: 1: entity key, 2: label key, 3: error */
					return new \WP_Error( 'label', sprintf( __( '%1$s → %2$s: %3$s', 'cradle-admin' ), $entity, $key, $clean->get_error_message() ) );
				}
				if ( '' !== $clean ) {
					$out[ $entity ][ $key ] = $clean;
				}
			}
		}
		$stored = Module::values( Module::SECTION_LABELS )[ 'taxonomy' === $kind ? 'taxonomy_labels' : 'post_type_labels' ] ?? array();
		foreach ( is_array( $stored ) ? $stored : array() as $entity => $labels ) {
			if ( is_string( $entity ) && ! $exists( $entity ) && ! isset( $out[ $entity ] ) && is_array( $labels ) ) {
				$out[ $entity ] = $labels;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Menu override keys: a top-level menu slug, or "parent|child" for a submenu item.
	 *
	 * @param mixed $key Candidate key.
	 */
	public static function menu_key_valid( $key ): bool {
		return is_string( $key ) && strlen( $key ) <= 200 && (bool) preg_match( '/^[A-Za-z0-9_\-.\/?=&%:]+(\|[A-Za-z0-9_\-.\/?=&%:]+)?$/', $key );
	}

	/**
	 * @return array|\WP_Error
	 */
	public static function validate_menu_labels( $value ) {
		if ( '' === $value || null === $value ) {
			$value = array();
		}
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'type', __( 'Must be a list of menu labels.', 'cradle-admin' ) );
		}
		if ( count( $value ) > 400 ) {
			return new \WP_Error( 'count', __( 'Too many menu items were submitted.', 'cradle-admin' ) );
		}
		$out = array();
		foreach ( $value as $key => $label ) {
			if ( is_string( $label ) && '' === trim( $label ) ) {
				continue; // Empty = no override for this item.
			}
			if ( ! self::menu_key_valid( $key ) ) {
				return new \WP_Error( 'key', __( 'Contains an invalid menu item.', 'cradle-admin' ) );
			}
			$clean = self::plain_text( $label, 60 );
			if ( is_wp_error( $clean ) ) {
				/* translators: 1: menu item, 2: error */
				return new \WP_Error( 'label', sprintf( __( '%1$s: %2$s', 'cradle-admin' ), $key, $clean->get_error_message() ) );
			}
			if ( '' !== $clean ) {
				$out[ $key ] = $clean;
			}
		}
		if ( count( $out ) > 200 ) {
			return new \WP_Error( 'count', __( 'At most 200 menu labels can be overridden.', 'cradle-admin' ) );
		}
		// Keep overrides for menu items that are not present right now (for example a plugin's menu while it is inactive).
		$stored  = Module::values( Module::SECTION_LABELS )['menu_labels'] ?? array();
		$present = Labels::menu_originals();
		if ( $present && is_array( $stored ) ) {
			foreach ( $stored as $key => $label ) {
				if ( ! isset( $out[ $key ] ) && ! array_key_exists( $key, $value ) && ! Labels::menu_key_present( (string) $key ) ) {
					$out[ $key ] = $label;
				}
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Text domains whose admin strings may be overridden.
	 */
	public static function text_domains(): array {
		/**
		 * Filters the text domains supported by admin text overrides.
		 *
		 * @param string[] $domains Text domains; 'default' is WordPress core.
		 */
		$domains = apply_filters( 'cradle_admin/label_text_domains', array( 'default' ) );
		$out     = array();
		foreach ( is_array( $domains ) ? $domains : array() as $domain ) {
			if ( is_string( $domain ) && preg_match( '/^[a-z0-9_-]{1,64}$/', $domain ) ) {
				$out[] = $domain;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * sprintf-style placeholders of a string, sorted, for exact comparison.
	 */
	public static function placeholders( string $text ): array {
		preg_match_all( '/%(?:\d+\$)?[-+ 0\'#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/', str_replace( '%%', '', $text ), $m );
		$list = $m[0];
		sort( $list );
		return $list;
	}

	/**
	 * @return array|\WP_Error
	 */
	public static function validate_text_overrides( $value ) {
		if ( '' === $value || null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'type', __( 'Must be a list of text overrides.', 'cradle-admin' ) );
		}
		if ( count( $value ) > 100 ) {
			return new \WP_Error( 'count', __( 'Too many rows were submitted.', 'cradle-admin' ) );
		}
		$domains = self::text_domains();
		$out     = array();
		$row     = 0;
		foreach ( $value as $item ) {
			++$row;
			if ( ! is_array( $item ) ) {
				return new \WP_Error( 'type', __( 'Each text override must be a row.', 'cradle-admin' ) );
			}
			$fields = array();
			foreach ( array( 'domain', 'context', 'original', 'replacement', 'screen' ) as $key ) {
				$fields[ $key ] = $item[ $key ] ?? '';
				if ( ! is_string( $fields[ $key ] ) ) {
					return new \WP_Error( 'type', __( 'Each text override must be a row of text fields.', 'cradle-admin' ) );
				}
			}
			if ( '' === trim( $fields['original'] ) && '' === trim( $fields['replacement'] ) ) {
				continue;
			}
			/* translators: %d: row number */
			$prefix = sprintf( __( 'Row %d: ', 'cradle-admin' ), $row );
			if ( ! in_array( $fields['domain'], $domains, true ) ) {
				return new \WP_Error( 'domain', $prefix . __( 'The text domain is not supported.', 'cradle-admin' ) );
			}
			$original = $fields['original'];
			if ( '' === $original || strlen( $original ) > 400 || ! mb_check_encoding( $original, 'UTF-8' ) || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $original ) ) {
				return new \WP_Error( 'original', $prefix . __( 'The original text is required, at most 400 bytes, without control characters.', 'cradle-admin' ) );
			}
			$replacement = self::plain_text( $fields['replacement'], 200 );
			if ( is_wp_error( $replacement ) ) {
				return new \WP_Error( 'replacement', $prefix . $replacement->get_error_message() );
			}
			if ( '' === $replacement ) {
				return new \WP_Error( 'replacement', $prefix . __( 'The replacement text is required.', 'cradle-admin' ) );
			}
			if ( self::placeholders( $original ) !== self::placeholders( $replacement ) ) {
				return new \WP_Error( 'placeholders', $prefix . __( 'The replacement must contain exactly the same placeholders (such as %s or %1$s) as the original.', 'cradle-admin' ) );
			}
			$context = self::plain_text( $fields['context'], 100 );
			if ( is_wp_error( $context ) ) {
				return new \WP_Error( 'context', $prefix . $context->get_error_message() );
			}
			$screen = trim( $fields['screen'] );
			if ( '' !== $screen && ! preg_match( '/^[a-z0-9_-]{1,100}$/', $screen ) ) {
				return new \WP_Error( 'screen', $prefix . __( 'The screen ID may contain lowercase letters, digits, hyphens and underscores only.', 'cradle-admin' ) );
			}
			$out[] = array(
				'domain'      => $fields['domain'],
				'context'     => $context,
				'original'    => $original,
				'replacement' => $replacement,
				'screen'      => $screen,
			);
		}
		if ( count( $out ) > 50 ) {
			return new \WP_Error( 'count', __( 'At most 50 text overrides are allowed.', 'cradle-admin' ) );
		}
		return $out;
	}
}
