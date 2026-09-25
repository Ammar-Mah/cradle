<?php
/**
 * Validation and normalization of content definitions (post types, taxonomies, field groups).
 *
 * Every input source — admin forms, JSON imports, code definitions supplied by
 * another plugin — passes through the same validator. Invalid values are rejected
 * with field-specific messages before anything is normalized or stored.
 *
 * Structural checks (types, lengths, allowlists) always run. Environment checks
 * (reserved names, keys or slugs registered by other code, duplicates) run when
 * `$ctx['strict']` is true: on admin saves and imports, not on every request.
 *
 * @package Cradle\Content
 */

namespace Cradle\Content;

defined( 'ABSPATH' ) || exit;

final class Validator {

	const POST_TYPE_PATTERN = '/^[a-z][a-z0-9_-]{0,19}$/';
	const TAXONOMY_PATTERN  = '/^[a-z][a-z0-9_-]{0,31}$/';
	const GROUP_PATTERN     = '/^[a-z][a-z0-9_]{0,39}$/';
	const FIELD_PATTERN     = '/^[a-z][a-z0-9_]{0,39}$/';
	const SLUG_PATTERN      = '#^[a-z][a-z0-9_-]{0,59}(/[a-z0-9][a-z0-9_-]{0,59}){0,2}$#';
	const REST_BASE_PATTERN = '/^[a-z][a-z0-9_-]{0,59}$/';

	const MAX_FIELDS_PER_GROUP = 50;
	const MAX_FIELDS_TOTAL     = 300;
	const MAX_TEMPLATE_BLOCKS  = 20;

	/** WordPress core post types. */
	const CORE_POST_TYPES = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' );

	/** WordPress core taxonomies. */
	const CORE_TAXONOMIES = array( 'category', 'post_tag', 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' );

	/** Keys owned by WooCommerce and other well-known plugins; never overwritten even when those plugins are inactive. */
	const THIRD_PARTY_KEYS = array( 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon', 'shop_order_placehold', 'shop_webhook', 'product_cat', 'product_tag', 'product_type', 'product_visibility', 'product_shipping_class', 'scheduled-action', 'acf-field', 'acf-field-group', 'acf-post-type', 'acf-taxonomy', 'elementor_library' );

	/** WordPress reserved terms (query variables and internal names) that break queries when used as type keys. */
	const RESERVED_TERMS = array( 'action', 'attachment', 'attachment_id', 'author', 'author_name', 'calendar', 'cat', 'category', 'category__and', 'category__in', 'category__not_in', 'category_name', 'comments_per_page', 'comments_popup', 'custom', 'customize_messenger_channel', 'customized', 'cpage', 'day', 'debug', 'embed', 'error', 'exact', 'feed', 'fields', 'hour', 'link_category', 'm', 'minute', 'monthnum', 'more', 'name', 'nav_menu', 'nonce', 'nopaging', 'offset', 'order', 'orderby', 'p', 'page', 'page_id', 'paged', 'pagename', 'pb', 'perm', 'post', 'post__in', 'post__not_in', 'post_format', 'post_mime_type', 'post_status', 'post_tag', 'post_type', 'posts', 'posts_per_archive_page', 'posts_per_page', 'preview', 'robots', 's', 'search', 'second', 'sentence', 'showposts', 'static', 'status', 'subpost', 'subpost_id', 'tag', 'tag__and', 'tag__in', 'tag__not_in', 'tag_id', 'tag_slug__and', 'tag_slug__in', 'taxonomy', 'tb', 'term', 'terms', 'theme', 'title', 'type', 'types', 'w', 'withcomments', 'withoutcomments', 'year', 'rest_route', 'sitemap', 'sitemap-subtype', 'sitemap-stylesheet' );

	/** First URL segments WordPress or the server already uses. */
	const RESERVED_SLUGS = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login', 'wp-sitemap', 'feed', 'page', 'comments', 'search', 'author', 'category', 'tag', 'type', 'embed', 'trackback', 'attachment', 'xmlrpc', 'robots', 'favicon' );

	const SUPPORTS = array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'comments', 'custom-fields', 'page-attributes' );

	const TEMPLATE_BLOCKS = array( 'core/paragraph', 'core/heading', 'core/image', 'core/list', 'core/quote', 'core/gallery', 'core/separator', 'core/buttons', 'core/table' );

	const TEMPLATE_LOCKS = array( '', 'all', 'insert', 'contentOnly' );

	const ORDER_BY = array( '', 'date', 'title', 'modified', 'menu_order' );

	const ADMIN_COLUMNS = array( 'thumbnail', 'author', 'modified', 'menu_order', 'excerpt' );

	const DASHICONS = array( 'admin-post', 'admin-page', 'admin-media', 'admin-links', 'admin-comments', 'admin-appearance', 'admin-plugins', 'admin-users', 'admin-tools', 'admin-settings', 'admin-network', 'admin-home', 'admin-generic', 'admin-site', 'airplane', 'album', 'analytics', 'archive', 'art', 'awards', 'bank', 'beer', 'bell', 'book', 'book-alt', 'building', 'businessman', 'businessperson', 'businesswoman', 'calculator', 'calendar', 'calendar-alt', 'camera', 'car', 'carrot', 'cart', 'category', 'chart-area', 'chart-bar', 'chart-line', 'chart-pie', 'clipboard', 'clock', 'cloud', 'coffee', 'database', 'desktop', 'download', 'edit', 'email', 'email-alt', 'excerpt-view', 'feedback', 'flag', 'food', 'format-aside', 'format-audio', 'format-chat', 'format-gallery', 'format-image', 'format-quote', 'format-status', 'format-video', 'games', 'grid-view', 'groups', 'hammer', 'heart', 'id', 'id-alt', 'images-alt', 'images-alt2', 'info', 'laptop', 'lightbulb', 'list-view', 'location', 'location-alt', 'lock', 'media-document', 'media-spreadsheet', 'media-text', 'megaphone', 'microphone', 'money', 'money-alt', 'nametag', 'networking', 'open-folder', 'palmtree', 'paperclip', 'pets', 'portfolio', 'pressthis', 'printer', 'products', 'schedule', 'shield', 'smartphone', 'star-filled', 'sticky', 'store', 'tag', 'testimonial', 'text-page', 'tickets', 'tickets-alt', 'translation', 'trophy', 'universal-access', 'video-alt3', 'welcome-learn-more', 'welcome-write-blog' );

	const MIME_PREFIXES = array( 'image/', 'video/', 'audio/', 'application/pdf', 'text/' );

	/** Post types a field group or taxonomy may never target. */
	const INTERNAL_POST_TYPES = array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' );

	/** Taxonomies that may not be attached or targeted. */
	const INTERNAL_TAXONOMIES = array( 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' );

	/* ---------------------------------------------------------------------
	 * Defaults
	 * ------------------------------------------------------------------- */

	public static function defaults( string $kind ): array {
		switch ( $kind ) {
			case 'post_type':
				return array(
					'key'                 => '',
					'enabled'             => true,
					'singular'            => '',
					'plural'              => '',
					'menu_name'           => '',
					'description'         => '',
					'icon'                => 'admin-post',
					'menu_position'       => 0,
					'public'              => true,
					'publicly_queryable'  => true,
					'show_ui'             => true,
					'show_in_menu'        => true,
					'show_in_nav_menus'   => true,
					'show_in_admin_bar'   => true,
					'show_in_rest'        => true,
					'rest_base'           => '',
					'exclude_from_search' => false,
					'hierarchical'        => false,
					'has_archive'         => false,
					'archive_slug'        => '',
					'rewrite'             => true,
					'rewrite_slug'        => '',
					'with_front'          => false,
					'query_var'           => true,
					'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ),
					'taxonomies'          => array(),
					'capability_model'    => 'post',
					'capability_singular' => '',
					'capability_plural'   => '',
					'capability_roles'    => array(),
					'template'            => array(),
					'template_lock'       => '',
					'order_by'            => '',
					'order'               => 'DESC',
					'admin_columns'       => array(),
				);
			case 'taxonomy':
				return array(
					'key'                      => '',
					'enabled'                  => true,
					'singular'                 => '',
					'plural'                   => '',
					'menu_name'                => '',
					'description'              => '',
					'hierarchical'             => false,
					'object_types'             => array(),
					'public'                   => true,
					'publicly_queryable'       => true,
					'show_ui'                  => true,
					'show_in_menu'             => true,
					'show_in_nav_menus'        => true,
					'show_in_rest'             => true,
					'rest_base'                => '',
					'show_tagcloud'            => true,
					'show_in_quick_edit'       => true,
					'show_admin_column'        => true,
					'query_var'                => true,
					'rewrite'                  => true,
					'rewrite_slug'             => '',
					'with_front'               => false,
					'rewrite_hierarchical'     => false,
					'capability_model'         => 'default',
					'capability_base'          => '',
					'capability_roles'         => array(),
					'default_term_name'        => '',
					'default_term_slug'        => '',
					'default_term_description' => '',
					'sort'                     => false,
				);
			case 'field_group':
				return array(
					'key'         => '',
					'enabled'     => true,
					'title'       => '',
					'description' => '',
					'object'      => 'post',
					'targets'     => array(),
					'position'    => 'normal',
					'fields'      => array(),
				);
		}
		return array();
	}

	/* ---------------------------------------------------------------------
	 * Entry point
	 * ------------------------------------------------------------------- */

	/**
	 * Validates one definition.
	 *
	 * @param string $kind Definition kind.
	 * @param array  $in   Raw input.
	 * @param array  $ctx  Context: strict (bool), original (string key being edited or ''),
	 *                     definitions (kind => key => def, every other known definition),
	 *                     foreign (kind => key[] registered by other code), foreign_slugs (string[]).
	 * @return array{definition:array,errors:array<string,string>,warnings:string[]}
	 */
	public static function validate( string $kind, array $in, array $ctx = array() ): array {
		$ctx += array(
			'strict'        => false,
			'original'      => '',
			'definitions'   => array(),
			'foreign'       => array(),
			'foreign_slugs' => array(),
		);
		switch ( $kind ) {
			case 'post_type':
				return self::post_type( $in, $ctx );
			case 'taxonomy':
				return self::taxonomy( $in, $ctx );
			case 'field_group':
				return self::field_group( $in, $ctx );
		}
		return array(
			'definition' => array(),
			'errors'     => array( 'kind' => __( 'Unknown definition kind.', 'cradle-content' ) ),
			'warnings'   => array(),
		);
	}

	/* ---------------------------------------------------------------------
	 * Primitive readers: reject wrong types, then normalize.
	 * ------------------------------------------------------------------- */

	/**
	 * @return bool|\WP_Error
	 */
	public static function read_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( in_array( $value, array( 1, '1', 'true', 'on', 'yes' ), true ) ) {
			return true;
		}
		if ( in_array( $value, array( 0, '0', 'false', 'off', 'no', '', null ), true ) ) {
			return false;
		}
		return new \WP_Error( 'type', __( 'Must be on or off.', 'cradle-content' ) );
	}

	/**
	 * @return string|\WP_Error
	 */
	public static function read_text( $value, int $max, bool $multiline = false ) {
		if ( null === $value ) {
			return '';
		}
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			return new \WP_Error( 'type', __( 'Must be text.', 'cradle-content' ) );
		}
		$value = (string) $value;
		if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
			return new \WP_Error( 'utf8', __( 'Contains invalid characters.', 'cradle-content' ) );
		}
		if ( mb_strlen( $value ) > $max ) {
			/* translators: %d: maximum number of characters */
			return new \WP_Error( 'length', sprintf( __( 'Must be %d characters or fewer.', 'cradle-content' ), $max ) );
		}
		return $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
	}

	/**
	 * Reads a list of strings from an array (or a comma/newline separated string for convenience).
	 *
	 * @return string[]|\WP_Error
	 */
	public static function read_list( $value, int $max_items = 100 ) {
		if ( null === $value || '' === $value ) {
			return array();
		}
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
		}
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'type', __( 'Must be a list.', 'cradle-content' ) );
		}
		$out = array();
		foreach ( $value as $item ) {
			if ( '' === $item || null === $item ) {
				continue;
			}
			if ( ! is_string( $item ) || strlen( $item ) > 100 ) {
				return new \WP_Error( 'type', __( 'Contains an invalid entry.', 'cradle-content' ) );
			}
			$out[] = $item;
		}
		$out = array_values( array_unique( $out ) );
		if ( count( $out ) > $max_items ) {
			/* translators: %d: maximum number of entries */
			return new \WP_Error( 'length', sprintf( __( 'Choose %d entries or fewer.', 'cradle-content' ), $max_items ) );
		}
		return $out;
	}

	/**
	 * @return int|\WP_Error
	 */
	public static function read_int( $value, int $min, int $max ) {
		if ( null === $value || '' === $value ) {
			return $min;
		}
		if ( is_string( $value ) ) {
			$value = trim( $value );
		}
		if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ) ) {
			return new \WP_Error( 'type', __( 'Must be a whole number.', 'cradle-content' ) );
		}
		$value = (int) $value;
		if ( $value < $min || $value > $max ) {
			/* translators: 1: minimum, 2: maximum */
			return new \WP_Error( 'range', sprintf( __( 'Must be between %1$d and %2$d.', 'cradle-content' ), $min, $max ) );
		}
		return $value;
	}

	/**
	 * @return float|null|\WP_Error Null when empty.
	 */
	public static function read_number( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( is_string( $value ) ) {
			$value = trim( $value );
		}
		if ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && is_numeric( $value ) ) ) {
			return new \WP_Error( 'type', __( 'Must be a number.', 'cradle-content' ) );
		}
		$value = (float) $value;
		if ( ! is_finite( $value ) || abs( $value ) > 1e15 ) {
			return new \WP_Error( 'range', __( 'Is out of range.', 'cradle-content' ) );
		}
		return $value;
	}

	/**
	 * @return string|\WP_Error
	 */
	public static function read_enum( $value, array $allowed, string $default = '' ) {
		if ( null === $value ) {
			return $default;
		}
		if ( ! is_scalar( $value ) || ! in_array( (string) $value, $allowed, true ) ) {
			return new \WP_Error( 'enum', __( 'Is not an allowed choice.', 'cradle-content' ) );
		}
		return (string) $value;
	}

	/* ---------------------------------------------------------------------
	 * Shared helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Applies readers to scalar fields; collects errors; unknown keys are dropped.
	 *
	 * @param array $spec key => callable( $raw ) returning value|WP_Error.
	 */
	private static function apply( array $spec, array $in, array $defaults, array &$errors ): array {
		$out = array();
		foreach ( $spec as $key => $reader ) {
			if ( ! array_key_exists( $key, $in ) ) {
				$out[ $key ] = $defaults[ $key ];
				continue;
			}
			$value = $reader( $in[ $key ] );
			if ( is_wp_error( $value ) ) {
				$errors[ $key ] = $value->get_error_message();
				$out[ $key ]    = $defaults[ $key ];
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	private static function bool_reader(): callable {
		return static fn( $v ) => self::read_bool( $v );
	}

	private static function text_reader( int $max, bool $multiline = false ): callable {
		return static fn( $v ) => self::read_text( $v, $max, $multiline );
	}

	/**
	 * Keys a post type or taxonomy key must not collide with, for reporting.
	 *
	 * @return string|null Error message or null.
	 */
	private static function key_conflict( string $kind, string $key, array $ctx ): ?string {
		$reserved = array_merge( self::CORE_POST_TYPES, self::CORE_TAXONOMIES, self::RESERVED_TERMS );
		if ( in_array( $key, $reserved, true ) || str_starts_with( $key, 'wp_' ) || str_starts_with( $key, 'wp-' ) ) {
			return __( 'This key is reserved by WordPress. Choose another key.', 'cradle-content' );
		}
		if ( in_array( $key, self::THIRD_PARTY_KEYS, true ) || ( 'taxonomy' === $kind && str_starts_with( $key, 'pa_' ) ) ) {
			return __( 'This key belongs to another plugin (for example WooCommerce). Cradle never overwrites it.', 'cradle-content' );
		}
		if ( str_starts_with( $key, 'cradle_' ) || str_starts_with( $key, 'cradle-' ) ) {
			return __( 'Keys starting with "cradle" are reserved for Cradle modules.', 'cradle-content' );
		}
		if ( ! $ctx['strict'] ) {
			return null;
		}
		// Ownership by other code is reported first: it is the most useful explanation.
		foreach ( array( 'post_type', 'taxonomy' ) as $other ) {
			if ( in_array( $key, $ctx['foreign'][ $other ] ?? array(), true ) ) {
				return 'post_type' === $other
					? __( 'A post type with this key is already registered by other code (a plugin or theme). Cradle never overwrites it.', 'cradle-content' )
					: __( 'A taxonomy with this key is already registered by other code (a plugin or theme). Cradle never overwrites it.', 'cradle-content' );
			}
		}
		global $wp;
		if ( $wp instanceof \WP && ( in_array( $key, (array) $wp->public_query_vars, true ) || in_array( $key, (array) $wp->private_query_vars, true ) ) ) {
			return __( 'This key is already used as a WordPress query variable.', 'cradle-content' );
		}
		if ( isset( $ctx['definitions'][ $kind ][ $key ] ) ) {
			return __( 'A definition with this key already exists.', 'cradle-content' );
		}
		$other_kind = 'post_type' === $kind ? 'taxonomy' : 'post_type';
		if ( isset( $ctx['definitions'][ $other_kind ][ $key ] ) ) {
			return 'post_type' === $kind
				? __( 'A Cradle taxonomy already uses this key; post types and taxonomies share URL query variables.', 'cradle-content' )
				: __( 'A Cradle post type already uses this key; post types and taxonomies share URL query variables.', 'cradle-content' );
		}
		return null;
	}

	/**
	 * Validates a rewrite/archive slug and checks it is unique.
	 *
	 * @param string $slug    Slug (already a string).
	 * @param string $own     Owner id "kind:key" used to skip self.
	 * @param array  $ctx     Context.
	 * @param array  $warnings Collected warnings.
	 * @return string|\WP_Error
	 */
	private static function check_slug( string $slug, string $own, array $ctx, array &$warnings ) {
		$slug = strtolower( trim( $slug, " \t\n\r\0\x0B/" ) );
		if ( '' === $slug ) {
			return '';
		}
		if ( ! preg_match( self::SLUG_PATTERN, $slug ) ) {
			return new \WP_Error( 'slug', __( 'Use lowercase letters, numbers, dashes and underscores, starting with a letter (up to three segments separated by "/").', 'cradle-content' ) );
		}
		$first = explode( '/', $slug )[0];
		if ( in_array( $first, self::RESERVED_SLUGS, true ) || ( function_exists( 'rest_get_url_prefix' ) && rest_get_url_prefix() === $first ) ) {
			return new \WP_Error( 'slug', __( 'This URL segment is reserved by WordPress.', 'cradle-content' ) );
		}
		if ( ! $ctx['strict'] ) {
			return $slug;
		}
		foreach ( self::slug_owners( $ctx ) as $owner => $slugs ) {
			if ( $owner !== $own && in_array( $slug, $slugs, true ) ) {
				/* translators: %s: definition identifier such as post_type:book */
				return new \WP_Error( 'slug_duplicate', sprintf( __( 'This URL slug is already used by %s.', 'cradle-content' ), $owner ) );
			}
		}
		if ( in_array( $slug, $ctx['foreign_slugs'], true ) ) {
			return new \WP_Error( 'slug_duplicate', __( 'This URL slug is already used by a post type or taxonomy registered by other code.', 'cradle-content' ) );
		}
		if ( function_exists( 'get_page_by_path' ) && get_page_by_path( $slug, OBJECT, 'page' ) ) {
			/* translators: %s: slug */
			$warnings[] = sprintf( __( 'A page with the path "%s" exists; the content type URL will take precedence over it.', 'cradle-content' ), $slug );
		}
		return $slug;
	}

	/**
	 * Slugs claimed by known Cradle definitions, keyed by "kind:key".
	 */
	private static function slug_owners( array $ctx ): array {
		$out = array();
		foreach ( $ctx['definitions']['post_type'] ?? array() as $key => $def ) {
			$slugs = array();
			if ( ! empty( $def['rewrite'] ) ) {
				$slugs[] = '' !== ( $def['rewrite_slug'] ?? '' ) ? $def['rewrite_slug'] : $key;
			}
			if ( ! empty( $def['has_archive'] ) && '' !== ( $def['archive_slug'] ?? '' ) ) {
				$slugs[] = $def['archive_slug'];
			}
			$out[ 'post_type:' . $key ] = $slugs;
		}
		foreach ( $ctx['definitions']['taxonomy'] ?? array() as $key => $def ) {
			$out[ 'taxonomy:' . $key ] = ! empty( $def['rewrite'] ) ? array( '' !== ( $def['rewrite_slug'] ?? '' ) ? $def['rewrite_slug'] : $key ) : array();
		}
		return $out;
	}

	/**
	 * Roles that may receive custom content capabilities (editorial roles other than administrator).
	 */
	public static function editorial_roles(): array {
		$out = array();
		foreach ( wp_roles()->roles as $name => $role ) {
			if ( 'administrator' !== $name && ! empty( $role['capabilities']['edit_posts'] ) ) {
				$out[ $name ] = translate_user_role( $role['name'] );
			}
		}
		return $out;
	}

	/**
	 * Post types that definitions may reference (attach, target): Cradle definitions plus registered, non-internal types.
	 */
	public static function referenceable_post_types( array $ctx ): array {
		$out = array_keys( $ctx['definitions']['post_type'] ?? array() );
		foreach ( get_post_types( array(), 'names' ) as $name ) {
			if ( ! in_array( $name, self::INTERNAL_POST_TYPES, true ) ) {
				$out[] = $name;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function referenceable_taxonomies( array $ctx ): array {
		$out = array_keys( $ctx['definitions']['taxonomy'] ?? array() );
		foreach ( get_taxonomies( array(), 'names' ) as $name ) {
			if ( ! in_array( $name, self::INTERNAL_TAXONOMIES, true ) ) {
				$out[] = $name;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @return string[]|\WP_Error
	 */
	private static function read_refs( $value, array $allowed, bool $strict, string $message ) {
		$list = self::read_list( $value, 50 );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		foreach ( $list as $item ) {
			if ( ! preg_match( '/^[a-z0-9_-]{1,32}$/', $item ) ) {
				return new \WP_Error( 'ref', __( 'Contains an invalid key.', 'cradle-content' ) );
			}
			if ( $strict && ! in_array( $item, $allowed, true ) ) {
				return new \WP_Error( 'ref', sprintf( $message, $item ) );
			}
		}
		return $list;
	}

	/* ---------------------------------------------------------------------
	 * Post types
	 * ------------------------------------------------------------------- */

	private static function post_type( array $in, array $ctx ): array {
		$d        = self::defaults( 'post_type' );
		$errors   = array();
		$warnings = array();

		$key = is_string( $in['key'] ?? null ) ? strtolower( trim( $in['key'] ) ) : '';
		if ( '' === $key ) {
			$errors['key'] = __( 'A key is required.', 'cradle-content' );
		} elseif ( ! preg_match( self::POST_TYPE_PATTERN, $key ) ) {
			$errors['key'] = __( 'Use 1–20 lowercase letters, numbers, dashes or underscores, starting with a letter.', 'cradle-content' );
		} elseif ( $key !== $ctx['original'] || ! $ctx['strict'] ) {
			$conflict = self::key_conflict( 'post_type', $key, $ctx );
			if ( $conflict ) {
				$errors['key'] = $conflict;
			}
		}

		$out = self::apply(
			array(
				'enabled'             => self::bool_reader(),
				'singular'            => self::text_reader( 60 ),
				'plural'              => self::text_reader( 60 ),
				'menu_name'           => self::text_reader( 60 ),
				'description'         => self::text_reader( 500, true ),
				'icon'                => static fn( $v ) => self::read_enum( is_string( $v ) ? preg_replace( '/^dashicons-/', '', $v ) : $v, self::DASHICONS, 'admin-post' ),
				'menu_position'       => static fn( $v ) => self::read_int( $v, 0, 200 ),
				'public'              => self::bool_reader(),
				'publicly_queryable'  => self::bool_reader(),
				'show_ui'             => self::bool_reader(),
				'show_in_menu'        => self::bool_reader(),
				'show_in_nav_menus'   => self::bool_reader(),
				'show_in_admin_bar'   => self::bool_reader(),
				'show_in_rest'        => self::bool_reader(),
				'rest_base'           => static function ( $v ) {
					$v = self::read_text( $v, 60 );
					return ( is_wp_error( $v ) || '' === $v || preg_match( self::REST_BASE_PATTERN, $v ) ) ? $v : new \WP_Error( 'rest_base', __( 'Use lowercase letters, numbers, dashes and underscores.', 'cradle-content' ) );
				},
				'exclude_from_search' => self::bool_reader(),
				'hierarchical'        => self::bool_reader(),
				'has_archive'         => self::bool_reader(),
				'archive_slug'        => self::text_reader( 190 ),
				'rewrite'             => self::bool_reader(),
				'rewrite_slug'        => self::text_reader( 190 ),
				'with_front'          => self::bool_reader(),
				'query_var'           => self::bool_reader(),
				'supports'            => static function ( $v ) {
					$list = self::read_list( $v, 20 );
					if ( is_wp_error( $list ) ) {
						return $list;
					}
					return array_diff( $list, self::SUPPORTS ) ? new \WP_Error( 'supports', __( 'Contains an unsupported feature.', 'cradle-content' ) ) : $list;
				},
				'capability_model'    => static fn( $v ) => self::read_enum( $v, array( 'post', 'page', 'custom' ), 'post' ),
				'capability_singular' => self::text_reader( 40 ),
				'capability_plural'   => self::text_reader( 40 ),
				'template_lock'       => static fn( $v ) => self::read_enum( false === $v ? '' : $v, self::TEMPLATE_LOCKS, '' ),
				'order_by'            => static fn( $v ) => self::read_enum( $v, self::ORDER_BY, '' ),
				'order'               => static fn( $v ) => self::read_enum( is_string( $v ) ? strtoupper( $v ) : $v, array( 'ASC', 'DESC' ), 'DESC' ),
			),
			$in,
			$d,
			$errors
		);
		$out = array( 'key' => $key ) + $out;

		if ( '' === $out['singular'] && ! isset( $errors['singular'] ) ) {
			$errors['singular'] = __( 'A singular label is required.', 'cradle-content' );
		}
		if ( '' === $out['plural'] && ! isset( $errors['plural'] ) ) {
			$errors['plural'] = __( 'A plural label is required.', 'cradle-content' );
		}

		foreach ( array( 'rewrite_slug', 'archive_slug' ) as $slug_key ) {
			if ( isset( $errors[ $slug_key ] ) ) {
				continue;
			}
			$slug = self::check_slug( $out[ $slug_key ], 'post_type:' . ( $ctx['original'] ?: $key ), $ctx, $warnings );
			if ( is_wp_error( $slug ) ) {
				$errors[ $slug_key ] = $slug->get_error_message();
				$out[ $slug_key ]    = '';
			} else {
				$out[ $slug_key ] = $slug;
			}
		}
		if ( $ctx['strict'] && $out['rewrite'] && '' === $out['rewrite_slug'] && ! isset( $errors['key'] ) && '' !== $key ) {
			$implicit = self::check_slug( $key, 'post_type:' . ( $ctx['original'] ?: $key ), $ctx, $warnings );
			if ( is_wp_error( $implicit ) ) {
				$errors['rewrite_slug'] = $implicit->get_error_message();
			}
		}
		if ( '' !== $out['archive_slug'] && $out['archive_slug'] === ( $out['rewrite_slug'] ?: $key ) ) {
			$out['archive_slug'] = '';
		}
		if ( '' !== $out['rest_base'] && $ctx['strict'] ) {
			foreach ( $ctx['definitions']['post_type'] ?? array() as $other_key => $other ) {
				if ( $other_key !== $ctx['original'] && ( $other['rest_base'] ?: $other_key ) === $out['rest_base'] ) {
					$errors['rest_base'] = __( 'Another post type already uses this REST base.', 'cradle-content' );
				}
			}
		}

		// Attached taxonomies.
		if ( array_key_exists( 'taxonomies', $in ) ) {
			$allowed = array_diff( self::referenceable_taxonomies( $ctx ), self::INTERNAL_TAXONOMIES );
			/* translators: %s: taxonomy key */
			$tax = self::read_refs( $in['taxonomies'], $allowed, $ctx['strict'], __( 'Taxonomy "%s" does not exist.', 'cradle-content' ) );
			if ( is_wp_error( $tax ) ) {
				$errors['taxonomies'] = $tax->get_error_message();
				$tax                  = array();
			}
			$out['taxonomies'] = array_values( array_diff( $tax, self::INTERNAL_TAXONOMIES ) );
		} else {
			$out['taxonomies'] = array();
		}

		// Capability model.
		if ( 'custom' === $out['capability_model'] ) {
			foreach ( array( 'capability_singular', 'capability_plural' ) as $cap_key ) {
				if ( '' === $out[ $cap_key ] ) {
					$out[ $cap_key ] = 'capability_singular' === $cap_key ? str_replace( '-', '_', $key ) : str_replace( '-', '_', $key ) . 's';
				}
				if ( ! preg_match( '/^[a-z][a-z0-9_]{1,39}$/', $out[ $cap_key ] ) ) {
					$errors[ $cap_key ] = __( 'Use lowercase letters, numbers and underscores (2–40 characters).', 'cradle-content' );
				}
			}
			if ( $out['capability_singular'] === $out['capability_plural'] ) {
				$errors['capability_plural'] = __( 'Singular and plural capability names must differ.', 'cradle-content' );
			}
			if ( in_array( $out['capability_plural'], array( 'posts', 'pages' ), true ) || in_array( $out['capability_singular'], array( 'post', 'page' ), true ) ) {
				$errors['capability_singular'] = __( 'Use the "post" or "page" model instead of reusing core capability names.', 'cradle-content' );
			}
		} else {
			$out['capability_singular'] = '';
			$out['capability_plural']   = '';
		}
		$roles = self::read_list( $in['capability_roles'] ?? array(), 20 );
		if ( is_wp_error( $roles ) ) {
			$errors['capability_roles'] = $roles->get_error_message();
			$roles                      = array();
		} elseif ( $ctx['strict'] && array_diff( $roles, array_keys( self::editorial_roles() ) ) ) {
			$errors['capability_roles'] = __( 'Only editorial roles (roles that can edit posts) can receive content capabilities.', 'cradle-content' );
			$roles                      = array();
		}
		$out['capability_roles'] = 'custom' === $out['capability_model'] ? $roles : array();

		// Block template.
		$out['template'] = array();
		if ( ! empty( $in['template'] ) ) {
			if ( ! is_array( $in['template'] ) ) {
				$errors['template'] = __( 'The template must be a list of blocks.', 'cradle-content' );
			} else {
				foreach ( array_values( $in['template'] ) as $i => $row ) {
					if ( ! is_array( $row ) || '' === ( $row['block'] ?? '' ) ) {
						continue;
					}
					if ( count( $out['template'] ) >= self::MAX_TEMPLATE_BLOCKS ) {
						/* translators: %d: maximum blocks */
						$errors['template'] = sprintf( __( 'A template can hold at most %d blocks.', 'cradle-content' ), self::MAX_TEMPLATE_BLOCKS );
						break;
					}
					$block       = self::read_enum( $row['block'], self::TEMPLATE_BLOCKS );
					$placeholder = self::read_text( $row['placeholder'] ?? '', 200 );
					// Only headings carry a level; stored non-heading rows hold 0, which must re-validate (imports).
					$level = 'core/heading' === $block ? self::read_int( ( $row['level'] ?? 2 ) ?: 2, 1, 6 ) : 0;
					if ( is_wp_error( $block ) || is_wp_error( $placeholder ) || is_wp_error( $level ) ) {
						/* translators: %d: row number */
						$errors['template'] = sprintf( __( 'Template row %d is invalid.', 'cradle-content' ), $i + 1 );
						continue;
					}
					$out['template'][] = array(
						'block'       => $block,
						'placeholder' => $placeholder,
						'level'       => 'core/heading' === $block ? $level : 0,
					);
				}
			}
		}
		if ( ! $out['template'] ) {
			$out['template_lock'] = '';
		}

		// Admin list columns.
		$columns = self::read_list( $in['admin_columns'] ?? array(), 20 );
		if ( is_wp_error( $columns ) ) {
			$errors['admin_columns'] = $columns->get_error_message();
			$columns                 = array();
		}
		foreach ( $columns as $column ) {
			if ( ! in_array( $column, self::ADMIN_COLUMNS, true ) && ! preg_match( '/^field:[a-z][a-z0-9_]{0,39}$/', $column ) ) {
				$errors['admin_columns'] = __( 'Contains an unknown column.', 'cradle-content' );
				$columns                 = array();
				break;
			}
		}
		$out['admin_columns'] = $columns;

		// Coherence.
		if ( ! $out['show_ui'] ) {
			$out['show_in_menu']      = false;
			$out['show_in_admin_bar'] = false;
		}
		if ( 'menu_order' === $out['order_by'] && ! in_array( 'page-attributes', $out['supports'], true ) ) {
			$warnings[] = __( 'Ordering by menu order is most useful with the "Page attributes" feature enabled.', 'cradle-content' );
		}
		if ( $out['hierarchical'] && ! in_array( 'page-attributes', $out['supports'], true ) ) {
			$warnings[] = __( 'Hierarchical types need "Page attributes" support to choose a parent in the editor.', 'cradle-content' );
		}

		return array(
			'definition' => $out,
			'errors'     => $errors,
			'warnings'   => $warnings,
		);
	}

	/* ---------------------------------------------------------------------
	 * Taxonomies
	 * ------------------------------------------------------------------- */

	private static function taxonomy( array $in, array $ctx ): array {
		$d        = self::defaults( 'taxonomy' );
		$errors   = array();
		$warnings = array();

		$key = is_string( $in['key'] ?? null ) ? strtolower( trim( $in['key'] ) ) : '';
		if ( '' === $key ) {
			$errors['key'] = __( 'A key is required.', 'cradle-content' );
		} elseif ( ! preg_match( self::TAXONOMY_PATTERN, $key ) ) {
			$errors['key'] = __( 'Use 1–32 lowercase letters, numbers, dashes or underscores, starting with a letter.', 'cradle-content' );
		} elseif ( $key !== $ctx['original'] || ! $ctx['strict'] ) {
			$conflict = self::key_conflict( 'taxonomy', $key, $ctx );
			if ( $conflict ) {
				$errors['key'] = $conflict;
			}
		}

		$out = self::apply(
			array(
				'enabled'                  => self::bool_reader(),
				'singular'                 => self::text_reader( 60 ),
				'plural'                   => self::text_reader( 60 ),
				'menu_name'                => self::text_reader( 60 ),
				'description'              => self::text_reader( 500, true ),
				'hierarchical'             => self::bool_reader(),
				'public'                   => self::bool_reader(),
				'publicly_queryable'       => self::bool_reader(),
				'show_ui'                  => self::bool_reader(),
				'show_in_menu'             => self::bool_reader(),
				'show_in_nav_menus'        => self::bool_reader(),
				'show_in_rest'             => self::bool_reader(),
				'rest_base'                => static function ( $v ) {
					$v = self::read_text( $v, 60 );
					return ( is_wp_error( $v ) || '' === $v || preg_match( self::REST_BASE_PATTERN, $v ) ) ? $v : new \WP_Error( 'rest_base', __( 'Use lowercase letters, numbers, dashes and underscores.', 'cradle-content' ) );
				},
				'show_tagcloud'            => self::bool_reader(),
				'show_in_quick_edit'       => self::bool_reader(),
				'show_admin_column'        => self::bool_reader(),
				'query_var'                => self::bool_reader(),
				'rewrite'                  => self::bool_reader(),
				'rewrite_slug'             => self::text_reader( 190 ),
				'with_front'               => self::bool_reader(),
				'rewrite_hierarchical'     => self::bool_reader(),
				'capability_model'         => static fn( $v ) => self::read_enum( $v, array( 'default', 'custom' ), 'default' ),
				'capability_base'          => self::text_reader( 40 ),
				'default_term_name'        => self::text_reader( 100 ),
				'default_term_slug'        => self::text_reader( 100 ),
				'default_term_description' => self::text_reader( 500, true ),
				'sort'                     => self::bool_reader(),
			),
			$in,
			$d,
			$errors
		);
		$out = array( 'key' => $key ) + $out;

		if ( '' === $out['singular'] && ! isset( $errors['singular'] ) ) {
			$errors['singular'] = __( 'A singular label is required.', 'cradle-content' );
		}
		if ( '' === $out['plural'] && ! isset( $errors['plural'] ) ) {
			$errors['plural'] = __( 'A plural label is required.', 'cradle-content' );
		}

		if ( ! isset( $errors['rewrite_slug'] ) ) {
			$slug = self::check_slug( $out['rewrite_slug'], 'taxonomy:' . ( $ctx['original'] ?: $key ), $ctx, $warnings );
			if ( is_wp_error( $slug ) ) {
				$errors['rewrite_slug'] = $slug->get_error_message();
				$out['rewrite_slug']    = '';
			} else {
				$out['rewrite_slug'] = $slug;
			}
		}
		if ( $ctx['strict'] && $out['rewrite'] && '' === $out['rewrite_slug'] && ! isset( $errors['key'] ) && '' !== $key ) {
			$implicit = self::check_slug( $key, 'taxonomy:' . ( $ctx['original'] ?: $key ), $ctx, $warnings );
			if ( is_wp_error( $implicit ) ) {
				$errors['rewrite_slug'] = $implicit->get_error_message();
			}
		}

		$allowed = array_diff( self::referenceable_post_types( $ctx ), self::INTERNAL_POST_TYPES );
		/* translators: %s: post type key */
		$types = self::read_refs( $in['object_types'] ?? array(), $allowed, $ctx['strict'], __( 'Post type "%s" does not exist.', 'cradle-content' ) );
		if ( is_wp_error( $types ) ) {
			$errors['object_types'] = $types->get_error_message();
			$types                  = array();
		}
		$out['object_types'] = array_values( array_diff( $types, self::INTERNAL_POST_TYPES ) );

		if ( 'custom' === $out['capability_model'] ) {
			if ( '' === $out['capability_base'] ) {
				$out['capability_base'] = str_replace( '-', '_', $key ) . '_terms';
			}
			if ( ! preg_match( '/^[a-z][a-z0-9_]{1,39}$/', $out['capability_base'] ) || in_array( $out['capability_base'], array( 'categories', 'post_tags', 'terms' ), true ) ) {
				$errors['capability_base'] = __( 'Use lowercase letters, numbers and underscores (2–40 characters), not a core capability name.', 'cradle-content' );
			}
		} else {
			$out['capability_base'] = '';
		}
		$roles = self::read_list( $in['capability_roles'] ?? array(), 20 );
		if ( is_wp_error( $roles ) ) {
			$errors['capability_roles'] = $roles->get_error_message();
			$roles                      = array();
		} elseif ( $ctx['strict'] && array_diff( $roles, array_keys( self::editorial_roles() ) ) ) {
			$errors['capability_roles'] = __( 'Only editorial roles (roles that can edit posts) can receive content capabilities.', 'cradle-content' );
			$roles                      = array();
		}
		$out['capability_roles'] = 'custom' === $out['capability_model'] ? $roles : array();

		if ( '' !== $out['default_term_slug'] ) {
			$out['default_term_slug'] = sanitize_title( $out['default_term_slug'] );
		}
		if ( '' === $out['default_term_name'] ) {
			$out['default_term_slug']        = '';
			$out['default_term_description'] = '';
		}
		if ( ! $out['show_ui'] ) {
			$out['show_in_menu']       = false;
			$out['show_in_quick_edit'] = false;
			$out['show_admin_column']  = false;
		}
		if ( ! $out['hierarchical'] ) {
			$out['rewrite_hierarchical'] = false;
		}

		return array(
			'definition' => $out,
			'errors'     => $errors,
			'warnings'   => $warnings,
		);
	}

	/* ---------------------------------------------------------------------
	 * Field groups
	 * ------------------------------------------------------------------- */

	private static function field_group( array $in, array $ctx ): array {
		$d        = self::defaults( 'field_group' );
		$errors   = array();
		$warnings = array();

		$key = is_string( $in['key'] ?? null ) ? strtolower( trim( $in['key'] ) ) : '';
		if ( '' === $key ) {
			$errors['key'] = __( 'A key is required.', 'cradle-content' );
		} elseif ( ! preg_match( self::GROUP_PATTERN, $key ) ) {
			$errors['key'] = __( 'Use 1–40 lowercase letters, numbers or underscores, starting with a letter.', 'cradle-content' );
		} elseif ( $ctx['strict'] && $key !== $ctx['original'] && isset( $ctx['definitions']['field_group'][ $key ] ) ) {
			$errors['key'] = __( 'A field group with this key already exists.', 'cradle-content' );
		}

		$out = self::apply(
			array(
				'enabled'     => self::bool_reader(),
				'title'       => self::text_reader( 100 ),
				'description' => self::text_reader( 500, true ),
				'object'      => static fn( $v ) => self::read_enum( $v, array( 'post', 'term' ), 'post' ),
				'position'    => static fn( $v ) => self::read_enum( $v, array( 'normal', 'side' ), 'normal' ),
			),
			$in,
			$d,
			$errors
		);
		$out = array( 'key' => $key ) + $out;
		if ( '' === $out['title'] && ! isset( $errors['title'] ) ) {
			$errors['title'] = __( 'A title is required.', 'cradle-content' );
		}

		$allowed = 'post' === $out['object'] ? array_diff( self::referenceable_post_types( $ctx ), self::INTERNAL_POST_TYPES ) : array_diff( self::referenceable_taxonomies( $ctx ), self::INTERNAL_TAXONOMIES );
		/* translators: %s: key */
		$targets = self::read_refs( $in['targets'] ?? array(), $allowed, $ctx['strict'], __( '"%s" does not exist.', 'cradle-content' ) );
		if ( is_wp_error( $targets ) ) {
			$errors['targets'] = $targets->get_error_message();
			$targets           = array();
		}
		if ( ! $targets && ! isset( $errors['targets'] ) ) {
			$errors['targets'] = __( 'Choose at least one target.', 'cradle-content' );
		}
		$out['targets'] = $targets;

		// Fields.
		$out['fields'] = array();
		$raw_fields    = $in['fields'] ?? array();
		if ( ! is_array( $raw_fields ) ) {
			$errors['fields'] = __( 'Fields must be a list.', 'cradle-content' );
			$raw_fields       = array();
		}
		$seen = array();
		foreach ( array_values( $raw_fields ) as $i => $raw ) {
			if ( ! is_array( $raw ) || ( '' === trim( (string) ( $raw['key'] ?? '' ) ) && '' === trim( (string) ( $raw['label'] ?? '' ) ) ) ) {
				continue; // Blank form rows are ignored.
			}
			if ( count( $out['fields'] ) >= self::MAX_FIELDS_PER_GROUP ) {
				/* translators: %d: maximum */
				$errors['fields'] = sprintf( __( 'A field group can hold at most %d fields.', 'cradle-content' ), self::MAX_FIELDS_PER_GROUP );
				break;
			}
			$result = Field_Types::validate_definition( $raw, $ctx );
			foreach ( $result['errors'] as $prop => $message ) {
				$errors[ 'fields.' . $i . '.' . $prop ] = $message;
			}
			$field = $result['field'];
			if ( '' !== $field['key'] ) {
				if ( isset( $seen[ $field['key'] ] ) ) {
					$errors[ 'fields.' . $i . '.key' ] = __( 'Field keys must be unique within a group.', 'cradle-content' );
				}
				$seen[ $field['key'] ] = $field;
			}
			$field['_row']   = $i;
			$out['fields'][] = $field;
		}

		// Parents: sub-fields belong to a "group" field in the same definition, one level deep.
		foreach ( $out['fields'] as $field ) {
			if ( '' === $field['parent'] ) {
				continue;
			}
			$parent = $seen[ $field['parent'] ] ?? null;
			if ( ! $parent || 'group' !== $parent['type'] || '' !== $parent['parent'] ) {
				$errors[ 'fields.' . $field['_row'] . '.parent' ] = __( 'The parent must be a repeated group field of this field group.', 'cradle-content' );
			} elseif ( ! in_array( $field['type'], Field_Types::SUB_TYPES, true ) || ! empty( $field['multiple'] ) ) {
				$errors[ 'fields.' . $field['_row'] . '.type' ] = __( 'This field type cannot be used inside a repeated group.', 'cradle-content' );
			}
		}
		foreach ( $out['fields'] as $field ) {
			if ( 'group' === $field['type'] && '' === $field['parent'] ) {
				$has_children = false;
				foreach ( $out['fields'] as $child ) {
					$has_children = $has_children || $child['parent'] === $field['key'];
				}
				if ( ! $has_children ) {
					$errors[ 'fields.' . $field['_row'] . '.type' ] = __( 'A repeated group needs at least one sub-field (set another field\'s parent to this group).', 'cradle-content' );
				}
			}
		}
		foreach ( $out['fields'] as &$field ) {
			unset( $field['_row'] );
		}
		unset( $field );

		// Field keys must be unique per target across enabled groups (they share the meta key namespace).
		if ( $ctx['strict'] ) {
			foreach ( $ctx['definitions']['field_group'] ?? array() as $other_key => $other ) {
				if ( $other_key === $ctx['original'] || ( $other['object'] ?? 'post' ) !== $out['object'] || ! array_intersect( $other['targets'] ?? array(), $out['targets'] ) ) {
					continue;
				}
				foreach ( $other['fields'] ?? array() as $other_field ) {
					if ( '' === ( $other_field['parent'] ?? '' ) && isset( $seen[ $other_field['key'] ] ) && '' === $seen[ $other_field['key'] ]['parent'] ) {
						/* translators: 1: field key, 2: field group key */
						$errors['fields'] = sprintf( __( 'Field "%1$s" is already defined by field group "%2$s" for the same target.', 'cradle-content' ), $other_field['key'], $other_key );
					}
				}
			}
		}
		$top_level = 0;
		foreach ( $ctx['definitions']['field_group'] ?? array() as $other_key => $other ) {
			if ( $other_key !== $ctx['original'] ) {
				$top_level += count( $other['fields'] ?? array() );
			}
		}
		if ( $ctx['strict'] && $top_level + count( $out['fields'] ) > self::MAX_FIELDS_TOTAL ) {
			/* translators: %d: maximum */
			$errors['fields'] = sprintf( __( 'A site can define at most %d fields in total.', 'cradle-content' ), self::MAX_FIELDS_TOTAL );
		}

		return array(
			'definition' => $out,
			'errors'     => $errors,
			'warnings'   => $warnings,
		);
	}
}
