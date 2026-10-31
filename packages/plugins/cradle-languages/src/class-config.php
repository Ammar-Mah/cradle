<?php
/**
 * Settings section `languages` (option `cradle_languages_settings`): languages, prefix of the
 * default language, translatable/shared content types and taxonomies, classic menu locations per
 * language and hreflang output. Typed readers re-validate stored values and never write.
 *
 * Structured fields are core `object` fields with trusted validate + render callbacks; validators
 * accept the admin form shape and the list/map shape of the core JSON export.
 *
 * @package Cradle\Languages
 */

namespace Cradle\Languages;

defined( 'ABSPATH' ) || exit;

final class Config {

	const SECTION = 'languages';
	const OPTION  = 'cradle_languages_settings';

	const MODES = array( 'translatable', 'shared' );

	/** Non-public post types whose entries can be translated (navigation menus, synced patterns). */
	const EXTRA_TYPES = array( 'wp_navigation', 'wp_block' );

	/**
	 * Post type support that makes any other type configurable (for example a plugin's non-public
	 * type): `add_post_type_support( $type, 'cradle-languages' )`, optionally with
	 * `array( 'default' => 'translatable' )` to make translatable its default mode.
	 */
	const SUPPORT = 'cradle-languages';

	/** Words WordPress uses at the site root or as query variables (2–6 characters). */
	const RESERVED = array( 'wp', 'rss', 'rdf', 'atom', 'tag', 'day', 'cat', 'tb', 'year', 'page', 'feed', 'embed', 'type', 'author', 'search', 'cpage', 'paged', 'name', 'order', 'term', 'terms', 'title', 'static', 'status', 'theme', 'admin', 'login', 'files', 'api', 'rest', 'cgi', 'www', 'ftp', 'mail', 'json', 'xml' );

	/** @var array|null */
	private static $modes = null;

	/**
	 * @param \Cradle\Core\Plugin $core Core.
	 */
	public static function register( $core ) {
		$core->settings()->register_section(
			array(
				'id'            => self::SECTION,
				'owner'         => Module::ID,
				'title'         => __( 'Languages', 'cradle-languages' ),
				'description'   => __( 'Each additional language is served under its own address prefix (for example /ar/). Entries of translatable content types belong to one language and are linked to their translations; shared types appear in every language. Interface texts (buttons, labels) are translated with Cradle Translations for each language’s locale.', 'cradle-languages' ),
				'capability'    => Module::CAP,
				'schemaVersion' => 1,
				'page'          => false,
				'autoload'      => true,
				'lifecycle'     => array( 'uninstall' => 'retain' ),
				'fields'        => array(
					'languages'           => array(
						'type'     => 'object',
						'default'  => array(),
						'label'    => __( 'Languages', 'cradle-languages' ),
						'help'     => __( 'Up to 10 languages. Locale: a WordPress locale such as en_US, de_DE or ar. Address prefix: 2–3 lowercase letters, optionally followed by a hyphen and 2 letters (pt-br). Fill an empty row to add a language; tick Remove to remove one (its entries are kept but hidden on the site until it is added again).', 'cradle-languages' ),
						'validate' => array( __CLASS__, 'validate_languages' ),
						'render'   => array( __CLASS__, 'render_languages' ),
					),
					'hide_default_prefix' => array(
						'type'           => 'boolean',
						'default'        => true,
						'label'          => __( 'Default language address', 'cradle-languages' ),
						'checkbox_label' => __( 'Serve the default language without an address prefix', 'cradle-languages' ),
						'help'           => __( 'On (recommended): existing addresses stay the same. Off: the default language also gets its prefix and unprefixed addresses redirect to it.', 'cradle-languages' ),
					),
					'post_types'          => array(
						'type'     => 'object',
						'default'  => array(),
						'label'    => __( 'Content types', 'cradle-languages' ),
						'help'     => __( 'Translatable: each entry belongs to one language and lists only in that language. Shared: one entry for all languages. Changing a mode never deletes content; entries without a language count as the default language.', 'cradle-languages' ),
						'validate' => array( __CLASS__, 'validate_modes' ),
						'render'   => array( __CLASS__, 'render_post_types' ),
					),
					'taxonomies'          => array(
						'type'     => 'object',
						'default'  => array(),
						'label'    => __( 'Taxonomies', 'cradle-languages' ),
						'help'     => __( 'Translatable terms belong to one language and can be linked to their translations; shared terms are used by every language.', 'cradle-languages' ),
						'validate' => array( __CLASS__, 'validate_modes' ),
						'render'   => array( __CLASS__, 'render_taxonomies' ),
					),
					'menu_locations'      => array(
						'type'     => 'object',
						'default'  => array(),
						'label'    => __( 'Menus per language', 'cradle-languages' ),
						'help'     => __( 'Classic menu locations of the active theme. The default language uses the menus assigned under Appearance → Menus; choose another menu for a language here, or leave “Same as default language”.', 'cradle-languages' ),
						'validate' => array( __CLASS__, 'validate_menu_locations' ),
						'render'   => array( __CLASS__, 'render_menu_locations' ),
					),
					'hreflang'            => array(
						'type'           => 'boolean',
						'default'        => true,
						'label'          => __( 'Alternate language links', 'cradle-languages' ),
						'checkbox_label' => __( 'Add hreflang links for published translations to the page head', 'cradle-languages' ),
					),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Typed readers (never write).
	 * ------------------------------------------------------------------- */

	public static function raw(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	public static function flush() {
		self::$modes = null;
		Languages::flush();
	}

	/**
	 * Post types that can be configured, with their labels: public viewable types, attachments,
	 * Navigation menus and synced patterns, every custom (not built-in) type with admin screens
	 * (`show_ui`, for example non-public Cradle Content types shown through theme lists; their
	 * default mode is shared), and any type with the `cradle-languages` post type support.
	 *
	 * @return array<string,string>
	 */
	public static function eligible_post_types(): array {
		$out = array();
		foreach ( get_post_types( array(), 'objects' ) as $name => $object ) {
			if ( ( $object->public && is_post_type_viewable( $object ) ) || 'attachment' === $name || in_array( $name, self::EXTRA_TYPES, true ) || ( ! $object->_builtin && $object->show_ui ) || post_type_supports( $name, self::SUPPORT ) ) {
				$out[ $name ] = (string) $object->labels->name;
			}
		}
		return $out;
	}

	/** @return array<string,string> */
	public static function eligible_taxonomies(): array {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $name => $object ) {
			if ( ! in_array( $name, array( 'post_format', 'nav_menu', 'link_category', 'wp_pattern_category', Content::TAXONOMY ), true ) ) {
				$out[ $name ] = (string) $object->labels->name;
			}
		}
		return $out;
	}

	public static function default_post_type_mode( string $type ): string {
		if ( in_array( $type, array( 'attachment', 'wp_navigation', 'wp_block' ), true ) ) {
			return 'shared';
		}
		$support = get_all_post_type_supports( $type )[ self::SUPPORT ] ?? null;
		if ( is_array( $support ) && in_array( 'translatable', array_column( array_filter( $support, 'is_array' ), 'default' ), true ) ) {
			return 'translatable';
		}
		$object = get_post_type_object( $type );
		return ( $object && $object->public ) ? 'translatable' : 'shared';
	}

	public static function default_taxonomy_mode( string $taxonomy ): string {
		$object = get_taxonomy( $taxonomy );
		if ( ! $object || ! $object->public ) {
			return 'shared';
		}
		foreach ( (array) $object->object_type as $type ) {
			if ( 'translatable' === self::post_type_mode( $type ) ) {
				return 'translatable';
			}
		}
		return 'shared';
	}

	public static function post_type_mode( string $type ): string {
		$stored = self::raw()['post_types'] ?? array();
		if ( is_array( $stored ) && isset( $stored[ $type ] ) && in_array( $stored[ $type ], self::MODES, true ) ) {
			return $stored[ $type ];
		}
		return self::default_post_type_mode( $type );
	}

	/** @return string[] Registered post types whose entries have a language. */
	public static function translatable_post_types(): array {
		if ( ! did_action( 'init' ) ) {
			return array();
		}
		if ( null === self::$modes || ! isset( self::$modes['post_types'] ) ) {
			$out = array();
			foreach ( array_keys( self::eligible_post_types() ) as $type ) {
				if ( 'translatable' === self::post_type_mode( $type ) ) {
					$out[] = $type;
				}
			}
			// Types can still be registered while init runs: remember the list only afterwards.
			if ( doing_action( 'init' ) ) {
				return $out;
			}
			self::$modes               = (array) self::$modes;
			self::$modes['post_types'] = $out;
		}
		return self::$modes['post_types'];
	}

	/** @return string[] Registered taxonomies whose terms have a language. */
	public static function translatable_taxonomies(): array {
		if ( ! did_action( 'init' ) ) {
			return array();
		}
		if ( null === self::$modes || ! isset( self::$modes['taxonomies'] ) ) {
			$stored = self::raw()['taxonomies'] ?? array();
			$stored = is_array( $stored ) ? $stored : array();
			$out    = array();
			foreach ( array_keys( self::eligible_taxonomies() ) as $taxonomy ) {
				$mode = isset( $stored[ $taxonomy ] ) && in_array( $stored[ $taxonomy ], self::MODES, true ) ? $stored[ $taxonomy ] : self::default_taxonomy_mode( $taxonomy );
				if ( 'translatable' === $mode ) {
					$out[] = $taxonomy;
				}
			}
			if ( doing_action( 'init' ) ) {
				return $out;
			}
			self::$modes               = (array) self::$modes;
			self::$modes['taxonomies'] = $out;
		}
		return self::$modes['taxonomies'];
	}

	/**
	 * Menu assignments for a language: location => menu term ID (only valid, configured rows).
	 *
	 * @return array<string,int>
	 */
	public static function menu_locations( string $slug ): array {
		$stored = self::raw()['menu_locations'] ?? array();
		$rows   = is_array( $stored ) && is_array( $stored[ $slug ] ?? null ) ? $stored[ $slug ] : array();
		$out    = array();
		foreach ( $rows as $location => $menu ) {
			if ( is_string( $location ) && preg_match( '/^[a-z0-9_-]{1,60}$/i', $location ) && (int) $menu > 0 ) {
				$out[ $location ] = (int) $menu;
			}
		}
		return $out;
	}

	public static function hreflang(): bool {
		return false !== ( self::raw()['hreflang'] ?? true );
	}

	/* ---------------------------------------------------------------------
	 * Validators.
	 * ------------------------------------------------------------------- */

	/**
	 * @param mixed $value Form shape { __form, __default, rows: [ {slug, locale, name, native, dir, order, remove} ] }
	 *                     or list shape [ {slug, locale, name, native, dir, default} ].
	 * @return array|\WP_Error Ordered list of language rows.
	 */
	public static function validate_languages( $value ) {
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'type', __( 'Must be a list of languages.', 'cradle-languages' ) );
		}
		$is_form = isset( $value['__form'] );
		$rows    = $is_form ? ( $value['rows'] ?? array() ) : $value;
		if ( ! is_array( $rows ) ) {
			return new \WP_Error( 'type', __( 'Must be a list of languages.', 'cradle-languages' ) );
		}
		$default_index = $is_form && isset( $value['__default'] ) && is_scalar( $value['__default'] ) ? (string) $value['__default'] : null;
		$current       = array_keys( Languages::normalize_rows( (array) ( self::raw()['languages'] ?? array() ) ) );
		$out           = array();
		$slugs         = array();
		$locales       = array();
		$default_slug  = null;
		$default_gone  = false;
		$position      = 0;
		foreach ( $rows as $index => $row ) {
			++$position;
			if ( ! is_array( $row ) ) {
				return new \WP_Error( 'type', __( 'Must be a list of languages.', 'cradle-languages' ) );
			}
			$row    = array_map( static fn( $v ) => is_string( $v ) ? trim( $v ) : $v, $row );
			$slug   = $row['slug'] ?? '';
			$locale = $row['locale'] ?? '';
			if ( ! is_string( $slug ) || ! is_string( $locale ) ) {
				return new \WP_Error( 'type', __( 'Locale and address prefix must be text.', 'cradle-languages' ) );
			}
			$removed = $is_form && in_array( $row['remove'] ?? '', array( '1', 1, true, 'on' ), true );
			if ( ( '' === $slug && '' === $locale ) || $removed ) {
				if ( $removed && null !== $default_index && (string) $index === $default_index ) {
					$default_gone = true;
				}
				continue;
			}
			/* translators: %d: row number in the languages table */
			$where = sprintf( __( 'Row %d', 'cradle-languages' ), $position );
			if ( ! preg_match( Languages::SLUG_PATTERN, $slug ) ) {
				/* translators: %s: row label such as "Row 2" */
				return new \WP_Error( 'slug', sprintf( __( '%s: the address prefix must be 2–3 lowercase letters, optionally with a hyphen and 2 letters (en, ar, pt-br).', 'cradle-languages' ), $where ) );
			}
			if ( ! self::valid_locale( $locale ) ) {
				/* translators: %s: row label such as "Row 2" */
				return new \WP_Error( 'locale', sprintf( __( '%s: enter a WordPress locale such as en_US, de_DE or ar.', 'cradle-languages' ), $where ) );
			}
			if ( isset( $slugs[ $slug ] ) || isset( $locales[ $locale ] ) ) {
				/* translators: %s: row label such as "Row 2" */
				return new \WP_Error( 'duplicate', sprintf( __( '%s: each locale and each address prefix can be used by one language only.', 'cradle-languages' ), $where ) );
			}
			if ( ! in_array( $slug, $current, true ) ) {
				$problem = self::slug_problem( $slug );
				if ( '' !== $problem ) {
					return new \WP_Error( 'reserved', $where . ': ' . $problem );
				}
			}
			foreach ( array( 'name', 'native' ) as $text ) {
				if ( isset( $row[ $text ] ) && ( ! is_string( $row[ $text ] ) || mb_strlen( $row[ $text ] ) > 60 || ! mb_check_encoding( $row[ $text ], 'UTF-8' ) ) ) {
					/* translators: %s: row label such as "Row 2" */
					return new \WP_Error( 'name', sprintf( __( '%s: names must be text of at most 60 characters.', 'cradle-languages' ), $where ) );
				}
			}
			$dir = $row['dir'] ?? '';
			if ( '' === $dir ) {
				$dir = Languages::default_dir( $locale );
			}
			if ( ! in_array( $dir, array( 'ltr', 'rtl' ), true ) ) {
				/* translators: %s: row label such as "Row 2" */
				return new \WP_Error( 'dir', sprintf( __( '%s: the direction must be left to right or right to left.', 'cradle-languages' ), $where ) );
			}
			$order = $row['order'] ?? $position;
			$order = ( is_string( $order ) && '' === $order ) ? $position : $order;
			if ( ! is_numeric( $order ) || (int) $order < 0 || (int) $order > 999 ) {
				/* translators: %s: row label such as "Row 2" */
				return new \WP_Error( 'order', sprintf( __( '%s: the order must be a whole number from 0 to 999.', 'cradle-languages' ), $where ) );
			}
			$known  = Languages::KNOWN[ Languages::language_code( $locale ) ] ?? array( $locale, $locale );
			$name   = sanitize_text_field( is_string( $row['name'] ?? null ) && '' !== $row['name'] ? $row['name'] : $known[0] );
			$native = sanitize_text_field( is_string( $row['native'] ?? null ) && '' !== $row['native'] ? $row['native'] : ( $known[1] ?? $name ) );
			$is_default = $is_form ? ( null !== $default_index && (string) $index === $default_index ) : in_array( $row['default'] ?? false, array( true, 1, '1' ), true );
			if ( $is_default ) {
				if ( null !== $default_slug ) {
					return new \WP_Error( 'default', __( 'Choose exactly one default language.', 'cradle-languages' ) );
				}
				$default_slug = $slug;
			}
			$slugs[ $slug ]     = true;
			$locales[ $locale ] = true;
			$out[]              = array(
				'slug'    => $slug,
				'locale'  => $locale,
				'name'    => '' !== $name ? $name : $locale,
				'native'  => '' !== $native ? $native : $name,
				'dir'     => $dir,
				'default' => $is_default,
				'_order'  => (int) $order,
				'_index'  => $position,
			);
		}
		if ( $default_gone ) {
			return new \WP_Error( 'default', __( 'The default language cannot be removed. Choose another default language first.', 'cradle-languages' ) );
		}
		if ( ! $out ) {
			return new \WP_Error( 'empty', __( 'Configure at least one language.', 'cradle-languages' ) );
		}
		if ( count( $out ) > Languages::MAX ) {
			/* translators: %d: maximum number of languages */
			return new \WP_Error( 'max', sprintf( __( 'At most %d languages can be configured.', 'cradle-languages' ), Languages::MAX ) );
		}
		if ( null === $default_slug ) {
			if ( $is_form ) {
				return new \WP_Error( 'default', __( 'Choose exactly one default language.', 'cradle-languages' ) );
			}
			$out[0]['default'] = true;
		}
		usort( $out, static fn( $a, $b ) => array( $a['_order'], $a['_index'] ) <=> array( $b['_order'], $b['_index'] ) );
		return array_map(
			static function ( $row ) {
				unset( $row['_order'], $row['_index'] );
				return $row;
			},
			$out
		);
	}

	/**
	 * Locale accepted for a language: an installed language, a cached entry of WordPress'
	 * translation list (never fetched remotely here), en_US, or the usual locale shape.
	 */
	public static function valid_locale( string $locale ): bool {
		if ( 'en_US' === $locale || in_array( $locale, get_available_languages(), true ) ) {
			return true;
		}
		$cached = get_site_transient( 'available_translations' );
		if ( is_array( $cached ) && isset( $cached[ $locale ] ) ) {
			return true;
		}
		return 1 === preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2})?$/', $locale );
	}

	/**
	 * Why a slug cannot be a language prefix, or '' when it can.
	 */
	public static function slug_problem( string $slug ): string {
		$reserved = self::RESERVED;
		$rest     = trim( (string) rest_get_url_prefix(), '/' );
		global $wp, $wp_rewrite;
		if ( $wp instanceof \WP ) {
			$reserved = array_merge( $reserved, (array) apply_filters( 'query_vars', $wp->public_query_vars ), (array) $wp->private_query_vars ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		}
		if ( $wp_rewrite instanceof \WP_Rewrite ) {
			$reserved = array_merge( $reserved, (array) $wp_rewrite->feeds );
			foreach ( array_keys( (array) $wp_rewrite->wp_rewrite_rules() ) as $regex ) {
				if ( preg_match( '/^\^?([a-z0-9_-]+)(?=$|[\/\\\\$?(.])/i', (string) $regex, $m ) ) {
					$reserved[] = strtolower( $m[1] );
				}
			}
		}
		foreach ( array( 'category_base', 'tag_base' ) as $option ) {
			$base = trim( (string) get_option( $option ), '/' );
			if ( '' !== $base ) {
				$reserved[] = strtok( $base, '/' );
			}
		}
		$reserved[] = $rest;
		if ( in_array( $slug, array_map( 'strval', $reserved ), true ) ) {
			/* translators: %s: address prefix */
			return sprintf( __( '“%s” is already used by WordPress (a route, feed or query variable).', 'cradle-languages' ), $slug );
		}
		$post = Content::top_level_collision( $slug );
		if ( $post ) {
			/* translators: 1: address prefix, 2: entry title, 3: entry ID */
			return sprintf( __( '“%1$s” is the address of an existing entry (“%2$s”, #%3$d). Change that entry’s slug first.', 'cradle-languages' ), $slug, wp_strip_all_tags( get_the_title( $post ) ), (int) $post->ID );
		}
		if ( file_exists( trailingslashit( ABSPATH ) . $slug ) ) {
			/* translators: %s: address prefix */
			return sprintf( __( 'A file or folder named “%s” exists in the site folder; the web server would serve it instead of WordPress.', 'cradle-languages' ), $slug );
		}
		return '';
	}

	/**
	 * Post type / taxonomy modes: { name: translatable|shared } (form adds `__form`).
	 * Names of types that are not registered right now are kept (a deactivated plugin may return).
	 *
	 * @param mixed $value Map.
	 * @return array|\WP_Error
	 */
	public static function validate_modes( $value ) {
		if ( '' === $value || null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'type', __( 'Must be a list of modes.', 'cradle-languages' ) );
		}
		$out = array();
		foreach ( $value as $name => $mode ) {
			if ( '__form' === $name ) {
				continue;
			}
			if ( ! is_string( $name ) || ! preg_match( '/^[a-z0-9_-]{1,32}$/', $name ) || ! is_string( $mode ) || ! in_array( $mode, self::MODES, true ) ) {
				return new \WP_Error( 'enum', __( 'Each type must be translatable or shared.', 'cradle-languages' ) );
			}
			$out[ $name ] = $mode;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @param mixed $value { lang: { location: menu_id } }.
	 * @return array|\WP_Error
	 */
	public static function validate_menu_locations( $value ) {
		if ( '' === $value || null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'type', __( 'Must be a list of menu assignments.', 'cradle-languages' ) );
		}
		$out = array();
		foreach ( $value as $slug => $rows ) {
			if ( '__form' === $slug ) {
				continue;
			}
			if ( ! is_string( $slug ) || ! preg_match( Languages::SLUG_PATTERN, $slug ) || ! is_array( $rows ) ) {
				return new \WP_Error( 'type', __( 'Must be a list of menu assignments.', 'cradle-languages' ) );
			}
			foreach ( $rows as $location => $menu ) {
				if ( ! is_string( $location ) || ! preg_match( '/^[a-z0-9_-]{1,60}$/i', $location ) || ! is_numeric( $menu ) || (int) $menu < 0 ) {
					return new \WP_Error( 'type', __( 'Must be a list of menu assignments.', 'cradle-languages' ) );
				}
				if ( 0 === (int) $menu ) {
					continue;
				}
				if ( ! is_nav_menu( (int) $menu ) ) {
					return new \WP_Error( 'menu', __( 'A selected menu does not exist.', 'cradle-languages' ) );
				}
				$out[ $slug ][ $location ] = (int) $menu;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Controls (render callbacks). Escaped; no JavaScript required.
	 * ------------------------------------------------------------------- */

	public static function render_languages( string $name, string $id, $value, array $field, string $describedby ) {
		unset( $value );
		$rows    = array_values( Languages::all() );
		$blank   = max( 1, min( 2, Languages::MAX - count( $rows ) ) );
		$list_id = $id . '-locales';
		echo '<fieldset class="cradle-languages-table"' . ( $describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : '' ) . '>';
		echo '<legend class="screen-reader-text">' . esc_html( $field['label'] ) . '</legend>';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '[__form]" value="1" />';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Default', 'cradle-languages' ), __( 'Locale', 'cradle-languages' ), __( 'Address prefix', 'cradle-languages' ), __( 'Name', 'cradle-languages' ), __( 'Native name', 'cradle-languages' ), __( 'Direction', 'cradle-languages' ), __( 'Order', 'cradle-languages' ), __( 'Remove', 'cradle-languages' ) ) as $column ) {
			echo '<th scope="col">' . esc_html( $column ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$total = count( $rows ) + $blank;
		for ( $i = 0; $i < $total; $i++ ) {
			$row  = $rows[ $i ] ?? array(
				'slug'    => '',
				'locale'  => '',
				'name'    => '',
				'native'  => '',
				'dir'     => '',
				'default' => false,
			);
			$base = $name . '[rows][' . $i . ']';
			$rid  = $id . '-' . $i;
			/* translators: %d: row number */
			$label = '' !== $row['slug'] ? $row['name'] : sprintf( __( 'New language %d', 'cradle-languages' ), $i - count( $rows ) + 1 );
			echo '<tr>';
			printf( '<td><input type="radio" id="%1$s-default" name="%2$s[__default]" value="%3$d" %4$s /><label class="screen-reader-text" for="%1$s-default">%5$s</label></td>', esc_attr( $rid ), esc_attr( $name ), (int) $i, checked( $row['default'], true, false ), esc_html( sprintf( /* translators: %s: language name */ __( 'Default language: %s', 'cradle-languages' ), $label ) ) );
			printf( '<td><label class="screen-reader-text" for="%1$s-locale">%2$s</label><input type="text" id="%1$s-locale" name="%3$s[locale]" value="%4$s" class="small-text code" style="inline-size:7em" maxlength="20" list="%5$s" spellcheck="false" autocomplete="off" placeholder="de_DE" /></td>', esc_attr( $rid ), esc_html( sprintf( /* translators: %s: language name */ __( 'Locale of %s', 'cradle-languages' ), $label ) ), esc_attr( $base ), esc_attr( $row['locale'] ), esc_attr( $list_id ) );
			printf( '<td><label class="screen-reader-text" for="%1$s-slug">%2$s</label><input type="text" id="%1$s-slug" name="%3$s[slug]" value="%4$s" class="small-text code" maxlength="6" spellcheck="false" autocomplete="off" placeholder="de" /></td>', esc_attr( $rid ), esc_html( sprintf( /* translators: %s: language name */ __( 'Address prefix of %s', 'cradle-languages' ), $label ) ), esc_attr( $base ), esc_attr( $row['slug'] ) );
			printf( '<td><label class="screen-reader-text" for="%1$s-name">%2$s</label><input type="text" id="%1$s-name" name="%3$s[name]" value="%4$s" class="regular-text" style="inline-size:10em" maxlength="60" placeholder="German" /></td>', esc_attr( $rid ), esc_html( sprintf( /* translators: %s: language name */ __( 'Name of %s', 'cradle-languages' ), $label ) ), esc_attr( $base ), esc_attr( $row['name'] ) );
			printf( '<td><label class="screen-reader-text" for="%1$s-native">%2$s</label><input type="text" id="%1$s-native" name="%3$s[native]" value="%4$s" class="regular-text" style="inline-size:10em" maxlength="60" placeholder="Deutsch" /></td>', esc_attr( $rid ), esc_html( sprintf( /* translators: %s: language name */ __( 'Native name of %s', 'cradle-languages' ), $label ) ), esc_attr( $base ), esc_attr( $row['native'] ) );
			printf( '<td><label class="screen-reader-text" for="%1$s-dir">%2$s</label><select id="%1$s-dir" name="%3$s[dir]">', esc_attr( $rid ), esc_html( sprintf( /* translators: %s: language name */ __( 'Text direction of %s', 'cradle-languages' ), $label ) ), esc_attr( $base ) );
			echo '<option value="">' . esc_html__( 'From the locale', 'cradle-languages' ) . '</option>';
			echo '<option value="ltr"' . selected( $row['dir'], 'ltr', false ) . '>' . esc_html__( 'Left to right', 'cradle-languages' ) . '</option>';
			echo '<option value="rtl"' . selected( $row['dir'], 'rtl', false ) . '>' . esc_html__( 'Right to left', 'cradle-languages' ) . '</option>';
			echo '</select></td>';
			printf( '<td><label class="screen-reader-text" for="%1$s-order">%2$s</label><input type="number" id="%1$s-order" name="%3$s[order]" value="%4$d" min="0" max="999" class="small-text" /></td>', esc_attr( $rid ), esc_html( sprintf( /* translators: %s: language name */ __( 'Order of %s', 'cradle-languages' ), $label ) ), esc_attr( $base ), (int) $i + 1 );
			if ( '' !== $row['slug'] ) {
				printf( '<td><input type="checkbox" id="%1$s-remove" name="%2$s[remove]" value="1" /><label class="screen-reader-text" for="%1$s-remove">%3$s</label></td>', esc_attr( $rid ), esc_attr( $base ), esc_html( sprintf( /* translators: %s: language name */ __( 'Remove %s', 'cradle-languages' ), $label ) ) );
			} else {
				echo '<td></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></fieldset>';
		echo '<datalist id="' . esc_attr( $list_id ) . '">';
		$suggest = array_unique( array_merge( array( 'en_US' ), get_available_languages() ) );
		foreach ( $suggest as $locale ) {
			echo '<option value="' . esc_attr( $locale ) . '"></option>';
		}
		foreach ( Languages::KNOWN as $code => $names ) {
			if ( ! in_array( $code, $suggest, true ) ) {
				echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $names[0] ) . '</option>';
			}
		}
		echo '</datalist>';
	}

	public static function render_post_types( string $name, string $id, $value, array $field, string $describedby ) {
		unset( $value );
		$rows = array();
		foreach ( self::eligible_post_types() as $type => $label ) {
			$rows[ $type ] = array( $label, self::post_type_mode( $type ) );
		}
		self::render_mode_table( $name, $id, $field, $describedby, $rows, __( 'Content type', 'cradle-languages' ) );
	}

	public static function render_taxonomies( string $name, string $id, $value, array $field, string $describedby ) {
		$stored = is_array( $value ) ? $value : array();
		$rows   = array();
		foreach ( self::eligible_taxonomies() as $taxonomy => $label ) {
			$rows[ $taxonomy ] = array( $label, isset( $stored[ $taxonomy ] ) && in_array( $stored[ $taxonomy ], self::MODES, true ) ? $stored[ $taxonomy ] : self::default_taxonomy_mode( $taxonomy ) );
		}
		self::render_mode_table( $name, $id, $field, $describedby, $rows, __( 'Taxonomy', 'cradle-languages' ) );
	}

	private static function render_mode_table( string $name, string $id, array $field, string $describedby, array $rows, string $heading ) {
		echo '<fieldset' . ( $describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : '' ) . '>';
		echo '<legend class="screen-reader-text">' . esc_html( $field['label'] ) . '</legend>';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '[__form]" value="1" />';
		echo '<table class="widefat striped" style="max-inline-size:40rem"><thead><tr><th scope="col">' . esc_html( $heading ) . '</th><th scope="col">' . esc_html__( 'Translatable', 'cradle-languages' ) . '</th><th scope="col">' . esc_html__( 'Shared', 'cradle-languages' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $key => $row ) {
			$rid = $id . '-' . $key;
			echo '<tr><th scope="row" id="' . esc_attr( $rid ) . '-label">' . esc_html( $row[0] ) . ' <code>' . esc_html( $key ) . '</code></th>';
			foreach ( self::MODES as $mode ) {
				printf(
					'<td><input type="radio" id="%1$s-%2$s" name="%3$s[%4$s]" value="%2$s" %5$s aria-labelledby="%1$s-label %1$s-%2$s-text" /> <label id="%1$s-%2$s-text" for="%1$s-%2$s">%6$s</label></td>',
					esc_attr( $rid ),
					esc_attr( $mode ),
					esc_attr( $name ),
					esc_attr( $key ),
					checked( $row[1], $mode, false ),
					'translatable' === $mode ? esc_html__( 'Translatable', 'cradle-languages' ) : esc_html__( 'Shared', 'cradle-languages' )
				);
			}
			echo '</tr>';
		}
		echo '</tbody></table></fieldset>';
	}

	public static function render_menu_locations( string $name, string $id, $value, array $field, string $describedby ) {
		unset( $value );
		$locations = get_registered_nav_menus();
		$others    = array_filter( Languages::all(), static fn( $lang ) => ! $lang['default'] );
		echo '<input type="hidden" name="' . esc_attr( $name ) . '[__form]" value="1" />';
		if ( ! $locations ) {
			echo '<p>' . esc_html__( 'The active theme has no classic menu locations. With a block theme, make Navigation menus translatable above and add a translation of each menu, or place a Language section block around language-specific content.', 'cradle-languages' ) . '</p>';
			return;
		}
		if ( ! $others ) {
			echo '<p>' . esc_html__( 'Add a second language to assign menus per language.', 'cradle-languages' ) . '</p>';
			return;
		}
		$menus = wp_get_nav_menus();
		echo '<fieldset' . ( $describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : '' ) . '><legend class="screen-reader-text">' . esc_html( $field['label'] ) . '</legend>';
		echo '<table class="widefat striped" style="max-inline-size:52rem"><thead><tr><th scope="col">' . esc_html__( 'Language', 'cradle-languages' ) . '</th>';
		foreach ( $locations as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $others as $slug => $lang ) {
			$assigned = self::menu_locations( $slug );
			echo '<tr><th scope="row">' . esc_html( $lang['name'] ) . '</th>';
			foreach ( $locations as $location => $label ) {
				$fid = $id . '-' . $slug . '-' . $location;
				printf( '<td><label class="screen-reader-text" for="%1$s">%2$s</label><select id="%1$s" name="%3$s[%4$s][%5$s]">', esc_attr( $fid ), esc_html( $lang['name'] . ': ' . $label ), esc_attr( $name ), esc_attr( $slug ), esc_attr( $location ) );
				echo '<option value="0">' . esc_html__( 'Same as default language', 'cradle-languages' ) . '</option>';
				foreach ( $menus as $menu ) {
					echo '<option value="' . (int) $menu->term_id . '"' . selected( (int) ( $assigned[ $location ] ?? 0 ), (int) $menu->term_id, false ) . '>' . esc_html( $menu->name ) . '</option>';
				}
				echo '</select></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></fieldset>';
	}
}
