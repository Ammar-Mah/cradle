<?php
/**
 * Settings section `social` (option `cradle_social_settings`), field
 * validators/controls and typed readers.
 *
 * Structured fields (profiles, share services, placement post types) are core
 * `object` fields with trusted validate + render callbacks. Validators accept
 * both the admin form shape and the list shape produced by the core JSON
 * export, reject invalid input before normalizing it, and never store a
 * profile URL that the provider policy refuses.
 *
 * @package Cradle\Social
 */

namespace Cradle\Social;

defined( 'ABSPATH' ) || exit;

final class Config {

	const SECTION = 'social';
	const OPTION  = 'cradle_social_settings';

	/** Supported placeholders for the default share title/text. */
	const PLACEHOLDERS = array( '{title}', '{url}', '{site}' );

	const PLACEHOLDER_PATTERN = '/^(?:[^{}]|\{(?:title|url|site)\})*$/u';

	const COLOR_PATTERN = '/^(?:#[0-9a-fA-F]{3}|#[0-9a-fA-F]{6}|preset:[a-z0-9-]{1,60})$/';

	const DEFAULT_SHARE = array( 'facebook', 'x', 'linkedin', 'bluesky', 'email', 'copy', 'native' );

	/**
	 * @param \Cradle\Core\Plugin $core Core.
	 */
	public static function register( $core ) {
		$placeholder_help = __( 'Supported placeholders: {title} (entry title), {url} (entry address), {site} (site title). Other {placeholders} and HTML are refused.', 'cradle-social' );
		$core->settings()->register_section(
			array(
				'id'            => self::SECTION,
				'owner'         => Module::ID,
				'title'         => __( 'Social links and sharing', 'cradle-social' ),
				'description'   => __( 'Profile links point to the organization’s own accounts; share links let visitors share the current page. Links are plain HTML with local icons: no remote scripts, trackers, iframes or share counts are loaded. Place them with the “Social profile links” and “Share links” blocks, or enable the automatic placement below.', 'cradle-social' ),
				'capability'    => Lifecycle::CAPABILITY,
				'schemaVersion' => 1,
				'page'          => true,
				'menu_title'    => __( 'Social', 'cradle-social' ),
				'autoload'      => true,
				'lifecycle'     => array( 'uninstall' => 'retain' ),
				'fields'        => array(
					'profiles'             => array(
						'type'     => 'object',
						'default'  => array(),
						'label'    => __( 'Profile links', 'cradle-social' ),
						'help'     => __( 'Tick the accounts to show and set their order (lowest first). Addresses must use https:// on the service’s own domain (Mastodon: your instance with /@name; Email: an address such as hello@example.com). Invalid addresses are not saved.', 'cradle-social' ),
						'validate' => array( __CLASS__, 'validate_profiles' ),
						'render'   => array( __CLASS__, 'render_profiles' ),
					),
					'profiles_label'       => array(
						'type'        => 'string',
						'default'     => '',
						'maxLength'   => 120,
						'label'       => __( 'Profile links label', 'cradle-social' ),
						'placeholder' => __( 'Follow us', 'cradle-social' ),
						'help'        => __( 'Names the group of profile links for assistive technology (and is shown when enabled below). Empty uses “Follow us”.', 'cradle-social' ),
					),
					'profiles_show_label'  => array(
						'type'           => 'boolean',
						'default'        => false,
						'label'          => __( 'Show the profile label', 'cradle-social' ),
						'checkbox_label' => __( 'Show the label above profile links', 'cradle-social' ),
					),
					'profiles_new_tab'     => array(
						'type'           => 'boolean',
						'default'        => false,
						'label'          => __( 'Profile links in a new tab', 'cradle-social' ),
						'checkbox_label' => __( 'Open profile links in a new tab', 'cradle-social' ),
						'help'           => __( 'Adds target="_blank" with rel="noopener noreferrer" and announces “opens in a new tab” to screen readers. Off keeps normal link behavior.', 'cradle-social' ),
					),
					'share_services'       => array(
						'type'     => 'object',
						'default'  => self::DEFAULT_SHARE,
						'label'    => __( 'Share services', 'cradle-social' ),
						'help'     => __( '“Copy link” copies the page address with the clipboard. “More sharing options” uses the device share sheet and only appears in browsers that support it. Mastodon, Instagram and YouTube offer profile links only.', 'cradle-social' ),
						'validate' => array( __CLASS__, 'validate_share_services' ),
						'render'   => array( __CLASS__, 'render_share_services' ),
					),
					'share_title'          => array(
						'type'            => 'string',
						'default'         => '{title}',
						'maxLength'       => 200,
						'pattern'         => self::PLACEHOLDER_PATTERN,
						'pattern_message' => __( 'Only the placeholders {title}, {url} and {site} are supported.', 'cradle-social' ),
						'label'           => __( 'Default share title', 'cradle-social' ),
						'help'            => __( 'Used as the email subject, the Reddit title and the device share sheet title.', 'cradle-social' ) . ' ' . $placeholder_help,
					),
					'share_text'           => array(
						'type'            => 'string',
						'default'         => '{title}',
						'maxLength'       => 280,
						'control'         => 'textarea',
						'rows'            => 2,
						'pattern'         => self::PLACEHOLDER_PATTERN,
						'pattern_message' => __( 'Only the placeholders {title}, {url} and {site} are supported.', 'cradle-social' ),
						'label'           => __( 'Default share text', 'cradle-social' ),
						'help'            => __( 'Pre-filled message for X, Bluesky, WhatsApp, Telegram, Pinterest and the email body. The page address is added once automatically where the service needs it.', 'cradle-social' ) . ' ' . $placeholder_help,
					),
					'share_label'          => array(
						'type'        => 'string',
						'default'     => '',
						'maxLength'   => 120,
						'label'       => __( 'Share links label', 'cradle-social' ),
						'placeholder' => __( 'Share this page', 'cradle-social' ),
						'help'        => __( 'Names the group of share links. Empty uses “Share this page”.', 'cradle-social' ),
					),
					'share_show_label'     => array(
						'type'           => 'boolean',
						'default'        => true,
						'label'          => __( 'Show the share label', 'cradle-social' ),
						'checkbox_label' => __( 'Show the label above share links', 'cradle-social' ),
					),
					'share_new_tab'        => array(
						'type'           => 'boolean',
						'default'        => false,
						'label'          => __( 'Share links in a new tab', 'cradle-social' ),
						'checkbox_label' => __( 'Open share links in a new tab', 'cradle-social' ),
						'help'           => __( 'Email links always open the visitor’s mail program.', 'cradle-social' ),
					),
					'display'              => array(
						'type'    => 'enum',
						'enum'    => array(
							'icon_text' => __( 'Icon and text', 'cradle-social' ),
							'icon'      => __( 'Icon only (text remains available to screen readers)', 'cradle-social' ),
							'text'      => __( 'Text only', 'cradle-social' ),
						),
						'default' => 'icon_text',
						'control' => 'radio',
						'label'   => __( 'Display', 'cradle-social' ),
					),
					'label_style'          => array(
						'type'    => 'enum',
						'enum'    => array(
							'service' => __( 'Service name (“LinkedIn”)', 'cradle-social' ),
							'action'  => __( 'Action (“Follow us on LinkedIn”, “Share on X”)', 'cradle-social' ),
						),
						'default' => 'service',
						'control' => 'radio',
						'label'   => __( 'Visible text', 'cradle-social' ),
						'help'    => __( 'Screen readers always hear the full action, for example “Share on X”.', 'cradle-social' ),
					),
					'size'                 => array(
						'type'    => 'enum',
						'enum'    => array(
							'small'  => __( 'Small', 'cradle-social' ),
							'medium' => __( 'Medium', 'cradle-social' ),
							'large'  => __( 'Large', 'cradle-social' ),
						),
						'default' => 'medium',
						'label'   => __( 'Size', 'cradle-social' ),
					),
					'spacing'              => array(
						'type'    => 'enum',
						'enum'    => array(
							'compact' => __( 'Compact', 'cradle-social' ),
							'normal'  => __( 'Normal', 'cradle-social' ),
							'relaxed' => __( 'Relaxed', 'cradle-social' ),
						),
						'default' => 'normal',
						'label'   => __( 'Spacing', 'cradle-social' ),
					),
					'shape'                => array(
						'type'    => 'enum',
						'enum'    => array(
							'none'    => __( 'Plain links', 'cradle-social' ),
							'square'  => __( 'Square buttons', 'cradle-social' ),
							'rounded' => __( 'Rounded buttons', 'cradle-social' ),
							'circle'  => __( 'Round / pill buttons', 'cradle-social' ),
						),
						'default' => 'rounded',
						'label'   => __( 'Shape', 'cradle-social' ),
					),
					'color_text'           => array(
						'type'            => 'string',
						'default'         => '',
						'maxLength'       => 70,
						'pattern'         => self::COLOR_PATTERN,
						'pattern_message' => __( 'Use a theme color such as preset:accent or a hex color such as #1a2b3c.', 'cradle-social' ),
						'label'           => __( 'Icon and text color', 'cradle-social' ),
						'help'            => __( 'A theme color token (preset:slug) or a hex color. Empty inherits the surrounding text color. Check the contrast against the background.', 'cradle-social' ),
						'render'          => array( __CLASS__, 'render_color' ),
					),
					'color_background'     => array(
						'type'            => 'string',
						'default'         => '',
						'maxLength'       => 70,
						'pattern'         => self::COLOR_PATTERN,
						'pattern_message' => __( 'Use a theme color such as preset:accent or a hex color such as #1a2b3c.', 'cradle-social' ),
						'label'           => __( 'Button background color', 'cradle-social' ),
						'help'            => __( 'A theme color token (preset:slug) or a hex color. Empty shows an outlined button (ignored for plain links).', 'cradle-social' ),
						'render'          => array( __CLASS__, 'render_color' ),
					),
					'placement'            => array(
						'type'    => 'enum',
						'enum'    => array(
							'none'   => __( 'Off — only where a block is placed (default)', 'cradle-social' ),
							'after'  => __( 'After the content', 'cradle-social' ),
							'before' => __( 'Before the content', 'cradle-social' ),
							'both'   => __( 'Before and after the content', 'cradle-social' ),
						),
						'default' => 'none',
						'control' => 'radio',
						'label'   => __( 'Automatic share links', 'cradle-social' ),
						'help'    => __( 'Adds share links to single entries of the types below. Entries that already contain a Share links block are skipped.', 'cradle-social' ),
					),
					'placement_post_types' => array(
						'type'     => 'object',
						'default'  => array( 'post' ),
						'label'    => __( 'Automatic placement on', 'cradle-social' ),
						'validate' => array( __CLASS__, 'validate_post_types' ),
						'render'   => array( __CLASS__, 'render_post_types' ),
					),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Typed readers (never write).
	 * ------------------------------------------------------------------- */

	public static function values(): array {
		$core = function_exists( 'cradle' ) ? cradle() : null;
		return $core ? $core->settings()->values( self::SECTION ) : array();
	}

	public static function get( string $key ) {
		return self::values()[ $key ] ?? null;
	}

	/**
	 * Enabled profile rows in display order. Stored rows are re-validated on read:
	 * a stored value is not trusted merely because it came from the database.
	 *
	 * @return array<int,array{service:string,url:string}>
	 */
	public static function enabled_profiles(): array {
		$out = array();
		foreach ( (array) self::get( 'profiles' ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['enabled'] ) || ! is_string( $row['service'] ?? null ) ) {
				continue;
			}
			$url = Providers::validate_profile_url( $row['service'], $row['url'] ?? '' );
			if ( is_wp_error( $url ) ) {
				continue;
			}
			$out[ $row['service'] ] = array(
				'service' => $row['service'],
				'url'     => $url,
			);
		}
		return array_values( $out );
	}

	/** @return string[] Enabled share service IDs in display order (unknown IDs dropped). */
	public static function enabled_share_services(): array {
		$allowed = Providers::share_ids();
		return array_values( array_unique( array_filter( (array) self::get( 'share_services' ), static fn( $id ) => is_string( $id ) && in_array( $id, $allowed, true ) ) ) );
	}

	/** @return string[] */
	public static function placement_post_types(): array {
		return array_values( array_filter( (array) self::get( 'placement_post_types' ), 'is_string' ) );
	}

	/* ---------------------------------------------------------------------
	 * Validators (trusted callbacks for core `object` fields).
	 * ------------------------------------------------------------------- */

	/**
	 * @param mixed $value Form shape { service: {enabled, order, url} } or list shape [ {service, url, enabled} ].
	 * @return array|\WP_Error
	 */
	public static function validate_profiles( $value ) {
		if ( '' === $value || null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'type', __( 'Must be a list of profile links.', 'cradle-social' ) );
		}
		$allowed = Providers::profile_ids();
		$rows    = array();
		$index   = 0;
		$is_list = array_is_list( $value );
		foreach ( $value as $key => $row ) {
			if ( '__form' === $key ) {
				continue;
			}
			++$index;
			if ( ! is_array( $row ) ) {
				return new \WP_Error( 'type', __( 'Must be a list of profile links.', 'cradle-social' ) );
			}
			$service = $is_list ? ( $row['service'] ?? null ) : $key;
			if ( ! is_string( $service ) || ! in_array( $service, $allowed, true ) ) {
				return new \WP_Error( 'enum', __( 'Contains a service that is not supported for profile links.', 'cradle-social' ) );
			}
			$enabled = $row['enabled'] ?? ( $is_list ? true : false );
			if ( ! in_array( $enabled, array( true, false, 1, 0, '1', '0', 'on', '' ), true ) ) {
				return new \WP_Error( 'type', __( 'The “show” choice must be on or off.', 'cradle-social' ) );
			}
			$enabled = in_array( $enabled, array( true, 1, '1', 'on' ), true );
			$order   = $is_list ? $index : ( $row['order'] ?? $index );
			if ( is_string( $order ) ) {
				$order = '' === trim( $order ) ? $index : trim( $order );
			}
			if ( ! is_numeric( $order ) || (int) $order != $order || (int) $order < 0 || (int) $order > 999 ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- numeric string vs int.
				/* translators: %s: service name */
				return new \WP_Error( 'range', sprintf( __( '%s: the order must be a whole number from 0 to 999.', 'cradle-social' ), Providers::label( $service ) ) );
			}
			$url = $row['url'] ?? '';
			if ( ! is_string( $url ) ) {
				return new \WP_Error( 'type', __( 'Profile addresses must be text.', 'cradle-social' ) );
			}
			if ( '' === trim( $url ) ) {
				if ( $enabled ) {
					/* translators: %s: service name */
					return new \WP_Error( 'required', sprintf( __( '%s: enter the profile address to show this link.', 'cradle-social' ), Providers::label( $service ) ) );
				}
				continue;
			}
			$normalized = Providers::validate_profile_url( $service, $url );
			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}
			if ( isset( $rows[ $service ] ) ) {
				return new \WP_Error( 'duplicate', __( 'Each service can be listed once.', 'cradle-social' ) );
			}
			$rows[ $service ] = array(
				'service' => $service,
				'url'     => $normalized,
				'enabled' => $enabled,
				'_order'  => (int) $order,
				'_index'  => $index,
			);
		}
		uasort(
			$rows,
			static fn( $a, $b ) => array( $a['_order'], $a['_index'] ) <=> array( $b['_order'], $b['_index'] )
		);
		return array_values(
			array_map(
				static fn( $row ) => array(
					'service' => $row['service'],
					'url'     => $row['url'],
					'enabled' => $row['enabled'],
				),
				$rows
			)
		);
	}

	/**
	 * @param mixed $value Form shape { id: {enabled, order} } or list of IDs.
	 * @return array|\WP_Error Ordered list of enabled IDs.
	 */
	public static function validate_share_services( $value ) {
		if ( '' === $value || null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'type', __( 'Must be a list of share services.', 'cradle-social' ) );
		}
		$allowed = Providers::share_ids();
		if ( array_is_list( $value ) ) {
			$out = array();
			foreach ( $value as $id ) {
				if ( ! is_string( $id ) || ! in_array( $id, $allowed, true ) ) {
					return new \WP_Error( 'enum', __( 'Contains a share service that is not supported.', 'cradle-social' ) );
				}
				$out[] = $id;
			}
			return array_values( array_unique( $out ) );
		}
		$rows  = array();
		$index = 0;
		foreach ( $value as $id => $row ) {
			if ( '__form' === $id ) {
				continue;
			}
			++$index;
			if ( ! is_string( $id ) || ! in_array( $id, $allowed, true ) || ! is_array( $row ) ) {
				return new \WP_Error( 'enum', __( 'Contains a share service that is not supported.', 'cradle-social' ) );
			}
			$enabled = $row['enabled'] ?? '';
			if ( ! in_array( $enabled, array( true, false, 1, 0, '1', '0', 'on', '' ), true ) ) {
				return new \WP_Error( 'type', __( 'The “show” choice must be on or off.', 'cradle-social' ) );
			}
			$order = $row['order'] ?? $index;
			$order = is_string( $order ) && '' === trim( $order ) ? $index : ( is_string( $order ) ? trim( $order ) : $order );
			if ( ! is_numeric( $order ) || (int) $order != $order || (int) $order < 0 || (int) $order > 999 ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- numeric string vs int.
				/* translators: %s: service name */
				return new \WP_Error( 'range', sprintf( __( '%s: the order must be a whole number from 0 to 999.', 'cradle-social' ), Providers::label( $id ) ) );
			}
			if ( in_array( $enabled, array( true, 1, '1', 'on' ), true ) ) {
				$rows[] = array( $id, (int) $order, $index );
			}
		}
		usort( $rows, static fn( $a, $b ) => array( $a[1], $a[2] ) <=> array( $b[1], $b[2] ) );
		return array_values( array_map( static fn( $row ) => $row[0], $rows ) );
	}

	/**
	 * Public, viewable post types (attachments excluded), validated when saving.
	 *
	 * @param mixed $value List of post type names (form or import).
	 * @return array|\WP_Error
	 */
	public static function validate_post_types( $value ) {
		if ( '' === $value || null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'type', __( 'Must be a list of content types.', 'cradle-social' ) );
		}
		$eligible = array_keys( self::eligible_post_types() );
		$out      = array();
		foreach ( $value as $type ) {
			if ( '' === $type ) {
				continue;
			}
			if ( ! is_string( $type ) || ! in_array( $type, $eligible, true ) ) {
				return new \WP_Error( 'enum', __( 'Contains a content type that cannot show share links.', 'cradle-social' ) );
			}
			$out[] = $type;
		}
		return array_values( array_unique( $out ) );
	}

	/** @return array<string,\WP_Post_Type> */
	public static function eligible_post_types(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $object ) {
			if ( 'attachment' !== $name && is_post_type_viewable( $object ) ) {
				$out[ $name ] = $object;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Controls (render callbacks). All output escaped; no JavaScript required.
	 * ------------------------------------------------------------------- */

	public static function render_profiles( string $name, string $id, $value, array $field, string $describedby ) {
		$stored = array();
		foreach ( is_array( $value ) ? $value : array() as $row ) {
			if ( is_array( $row ) && isset( $row['service'] ) && is_string( $row['service'] ) ) {
				$stored[ $row['service'] ] = $row;
			}
		}
		$ids = array_merge( array_keys( $stored ), array_diff( Providers::profile_ids(), array_keys( $stored ) ) );
		$ids = array_values( array_intersect( $ids, Providers::profile_ids() ) );
		self::open_table( $name, $id, $field, $describedby, array( __( 'Service', 'cradle-social' ), __( 'Show', 'cradle-social' ), __( 'Order', 'cradle-social' ), __( 'Profile address', 'cradle-social' ) ) );
		foreach ( $ids as $position => $service ) {
			$row      = $stored[ $service ] ?? array(
				'url'     => '',
				'enabled' => false,
			);
			$label    = Providers::label( $service );
			$base     = $id . '-' . $service;
			$provider = Providers::get( $service );
			$hint     = 'mailto' === $provider['profile']['mode'] ? 'hello@example.com' : ( 'federated' === $provider['profile']['mode'] ? 'https://mastodon.example/@name' : 'https://' . $provider['profile']['hosts'][0] . '/…' );
			echo '<tr>';
			echo '<th scope="row" id="' . esc_attr( $base . '-name' ) . '">' . Icons::svg( $provider['icon'] ) . ' ' . esc_html( $label ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static reviewed SVG.
			printf(
				'<td><input type="checkbox" id="%1$s-enabled" name="%2$s[%3$s][enabled]" value="1" %4$s /> <label for="%1$s-enabled">%5$s<span class="screen-reader-text"> %6$s</span></label></td>',
				esc_attr( $base ),
				esc_attr( $name ),
				esc_attr( $service ),
				checked( ! empty( $row['enabled'] ), true, false ),
				esc_html__( 'Show', 'cradle-social' ),
				esc_html( $label )
			);
			printf(
				'<td><label class="screen-reader-text" for="%1$s-order">%2$s</label><input type="number" id="%1$s-order" name="%3$s[%4$s][order]" value="%5$d" min="0" max="999" step="1" class="small-text" /></td>',
				esc_attr( $base ),
				/* translators: %s: service name */
				esc_html( sprintf( __( 'Order of %s', 'cradle-social' ), $label ) ),
				esc_attr( $name ),
				esc_attr( $service ),
				(int) $position + 1
			);
			printf(
				'<td><label class="screen-reader-text" for="%1$s-url">%2$s</label><input type="%6$s" id="%1$s-url" name="%3$s[%4$s][url]" value="%5$s" class="regular-text" maxlength="%7$d" placeholder="%8$s" spellcheck="false" autocomplete="off" /></td>',
				esc_attr( $base ),
				/* translators: %s: service name */
				esc_html( sprintf( __( '%s profile address', 'cradle-social' ), $label ) ),
				esc_attr( $name ),
				esc_attr( $service ),
				esc_attr( 'mailto' === $provider['profile']['mode'] ? preg_replace( '/^mailto:/', '', (string) $row['url'] ) : (string) $row['url'] ),
				'mailto' === $provider['profile']['mode'] ? 'text' : 'url',
				(int) Providers::MAX_URL_LENGTH,
				esc_attr( $hint )
			);
			echo '</tr>';
		}
		self::close_table();
	}

	public static function render_share_services( string $name, string $id, $value, array $field, string $describedby ) {
		$enabled = array_values( array_intersect( is_array( $value ) ? $value : array(), Providers::share_ids() ) );
		$ids     = array_merge( $enabled, array_diff( Providers::share_ids(), $enabled ) );
		self::open_table( $name, $id, $field, $describedby, array( __( 'Service', 'cradle-social' ), __( 'Show', 'cradle-social' ), __( 'Order', 'cradle-social' ) ) );
		foreach ( $ids as $position => $service ) {
			$label = Providers::label( $service );
			$base  = $id . '-' . $service;
			$icon  = 'copy' === $service ? 'link' : ( 'native' === $service ? 'share' : Providers::get( $service )['icon'] );
			echo '<tr>';
			echo '<th scope="row">' . Icons::svg( $icon ) . ' ' . esc_html( $label ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static reviewed SVG.
			printf(
				'<td><input type="checkbox" id="%1$s-enabled" name="%2$s[%3$s][enabled]" value="1" %4$s /> <label for="%1$s-enabled">%5$s<span class="screen-reader-text"> %6$s</span></label></td>',
				esc_attr( $base ),
				esc_attr( $name ),
				esc_attr( $service ),
				checked( in_array( $service, $enabled, true ), true, false ),
				esc_html__( 'Show', 'cradle-social' ),
				esc_html( $label )
			);
			printf(
				'<td><label class="screen-reader-text" for="%1$s-order">%2$s</label><input type="number" id="%1$s-order" name="%3$s[%4$s][order]" value="%5$d" min="0" max="999" step="1" class="small-text" /></td>',
				esc_attr( $base ),
				/* translators: %s: service name */
				esc_html( sprintf( __( 'Order of %s', 'cradle-social' ), $label ) ),
				esc_attr( $name ),
				esc_attr( $service ),
				(int) $position + 1
			);
			echo '</tr>';
		}
		self::close_table();
	}

	private static function open_table( string $name, string $id, array $field, string $describedby, array $columns ) {
		echo '<fieldset class="cradle-social-services"' . ( $describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : '' ) . '>';
		echo '<legend class="screen-reader-text">' . esc_html( $field['label'] ) . '</legend>';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '[__form]" value="1" />';
		echo '<table class="widefat striped" style="max-inline-size:52rem"><thead><tr>';
		foreach ( $columns as $column ) {
			echo '<th scope="col">' . esc_html( $column ) . '</th>';
		}
		echo '</tr></thead><tbody>';
	}

	private static function close_table() {
		echo '</tbody></table></fieldset>';
		echo '<style>.cradle-social-services .cradle-social__icon{inline-size:1.1em;block-size:1.1em;vertical-align:-.2em}</style>';
	}

	public static function render_post_types( string $name, string $id, $value, array $field, string $describedby ) {
		$value = is_array( $value ) ? $value : array();
		echo '<fieldset' . ( $describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : '' ) . '>';
		echo '<legend class="screen-reader-text">' . esc_html( $field['label'] ) . '</legend>';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '[]" value="" />';
		foreach ( self::eligible_post_types() as $type => $object ) {
			printf(
				'<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s <code>%2$s</code></label><br />',
				esc_attr( $name ),
				esc_attr( $type ),
				checked( in_array( $type, $value, true ), true, false ),
				esc_html( $object->labels->name )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Text input with a list of theme color tokens (works without JavaScript).
	 */
	public static function render_color( string $name, string $id, $value, array $field, string $describedby ) {
		$list = $id . '-tokens';
		printf(
			'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text code" list="%4$s" maxlength="70" spellcheck="false" autocomplete="off" placeholder="preset:accent"%5$s />',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( (string) $value ),
			esc_attr( $list ),
			$describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : ''
		);
		echo '<datalist id="' . esc_attr( $list ) . '">';
		foreach ( Style::palette() as $slug => $label ) {
			echo '<option value="' . esc_attr( 'preset:' . $slug ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</datalist>';
		$css = Style::color_css( (string) $value, '' );
		if ( '' !== $css ) {
			echo ' <span aria-hidden="true" style="display:inline-block;inline-size:1.5em;block-size:1.5em;vertical-align:middle;border:1px solid #8c8f94;border-radius:3px;background:' . esc_attr( $css ) . '"></span>';
		}
	}
}
