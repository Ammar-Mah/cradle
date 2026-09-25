<?php
/**
 * Registers authoritative post types and taxonomies on every request.
 *
 * Cost per request: one autoloaded option read (already in memory), the code
 * definitions filter, and the register_* calls themselves. Rewrite rules are
 * flushed only when the rewrite-relevant signature of what was registered
 * changes (never on ordinary requests).
 *
 * A key already registered by other code is never overwritten: the Cradle
 * definition is skipped and the conflict is reported in admin.
 *
 * @package Cradle\Content
 */

namespace Cradle\Content;

defined( 'ABSPATH' ) || exit;

final class Registry {

	const SIGNATURE_OPTION = 'cradle_content_rewrite';

	/** @var Registry|null */
	private static $instance = null;

	/** @var array<string,array<string,object>> Registered WP objects, kind => key => object. */
	private $registered = array(
		'post_type' => array(),
		'taxonomy'  => array(),
	);

	/** @var array<string,array<string,array>> Definitions registered this request. */
	private $definitions = array(
		'post_type' => array(),
		'taxonomy'  => array(),
	);

	/** @var array<string,string> Conflict messages keyed "kind:key". */
	private $conflicts = array();

	private $booted = false;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Runs inside the module boot (init priority 0).
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;
		Meta::instance()->prepare( Definitions::instance()->effective( 'field_group' ) );
		$this->register_taxonomies();
		$this->register_post_types();
		Meta::instance()->register();

		add_action( 'init', array( $this, 'late_attach' ), 99 );
		add_action( 'wp_loaded', array( $this, 'maybe_flush' ) );
		add_action( 'pre_get_posts', array( $this, 'default_order' ) );
		if ( is_admin() ) {
			$this->admin_columns();
		}
	}

	/**
	 * Registers taxonomies before post types so post types can attach them.
	 */
	private function register_taxonomies() {
		foreach ( Definitions::instance()->effective( 'taxonomy' ) as $key => $def ) {
			if ( empty( $def['enabled'] ) ) {
				continue;
			}
			if ( taxonomy_exists( $key ) ) {
				$this->conflicts[ 'taxonomy:' . $key ] = __( 'Not registered: a taxonomy with this key was already registered by other code.', 'cradle-content' );
				continue;
			}
			$result = register_taxonomy( $key, $def['object_types'], self::taxonomy_args( $def ) );
			if ( is_wp_error( $result ) ) {
				$this->conflicts[ 'taxonomy:' . $key ] = $result->get_error_message();
				continue;
			}
			$this->registered['taxonomy'][ $key ]  = get_taxonomy( $key );
			$this->definitions['taxonomy'][ $key ] = $def;
		}
	}

	/**
	 * Registers enabled post types; REST-exposed fields add custom-fields support.
	 */
	private function register_post_types() {
		foreach ( Definitions::instance()->effective( 'post_type' ) as $key => $def ) {
			if ( empty( $def['enabled'] ) ) {
				continue;
			}
			if ( post_type_exists( $key ) ) {
				$this->conflicts[ 'post_type:' . $key ] = __( 'Not registered: a post type with this key was already registered by other code.', 'cradle-content' );
				continue;
			}
			$needs_custom_fields = Meta::instance()->has_rest_fields( 'post', $key );
			$result              = register_post_type( $key, self::post_type_args( $def, $needs_custom_fields ) );
			if ( is_wp_error( $result ) ) {
				$this->conflicts[ 'post_type:' . $key ] = $result->get_error_message();
				continue;
			}
			$this->registered['post_type'][ $key ]  = $result;
			$this->definitions['post_type'][ $key ] = $def;
		}
	}

	/**
	 * Maps a validated post type definition to register_post_type() arguments.
	 */
	public static function post_type_args( array $def, bool $needs_custom_fields = false ): array {
		$supports = array_values( (array) $def['supports'] );
		if ( $needs_custom_fields && ! in_array( 'custom-fields', $supports, true ) ) {
			$supports[] = 'custom-fields';
		}
		$args = array(
			'labels'              => Labels::post_type( $def ),
			'description'         => $def['description'],
			'public'              => (bool) $def['public'],
			'publicly_queryable'  => (bool) $def['publicly_queryable'],
			'exclude_from_search' => (bool) $def['exclude_from_search'],
			'show_ui'             => (bool) $def['show_ui'],
			'show_in_menu'        => (bool) $def['show_ui'] && (bool) $def['show_in_menu'],
			'show_in_nav_menus'   => (bool) $def['show_in_nav_menus'],
			'show_in_admin_bar'   => (bool) $def['show_in_admin_bar'],
			'show_in_rest'        => (bool) $def['show_in_rest'],
			'menu_icon'           => 'dashicons-' . $def['icon'],
			'hierarchical'        => (bool) $def['hierarchical'],
			'has_archive'         => $def['has_archive'] ? ( '' !== $def['archive_slug'] ? $def['archive_slug'] : true ) : false,
			'rewrite'             => $def['rewrite'] ? array(
				'slug'       => '' !== $def['rewrite_slug'] ? $def['rewrite_slug'] : $def['key'],
				'with_front' => (bool) $def['with_front'],
				'feeds'      => (bool) $def['has_archive'],
				'pages'      => true,
			) : false,
			'query_var'           => (bool) $def['query_var'],
			'supports'            => $supports ? $supports : false,
			'taxonomies'          => array_values( (array) $def['taxonomies'] ),
			'map_meta_cap'        => true,
			'can_export'          => true,
			'delete_with_user'    => false,
		);
		if ( '' !== $def['rest_base'] ) {
			$args['rest_base'] = $def['rest_base'];
		}
		if ( $def['menu_position'] > 0 ) {
			$args['menu_position'] = (int) $def['menu_position'];
		}
		if ( 'custom' === $def['capability_model'] ) {
			$args['capability_type'] = array( $def['capability_singular'], $def['capability_plural'] );
		} else {
			$args['capability_type'] = 'page' === $def['capability_model'] ? 'page' : 'post';
		}
		if ( $def['template'] ) {
			$args['template'] = self::template( $def['template'] );
			if ( '' !== $def['template_lock'] ) {
				$args['template_lock'] = $def['template_lock'];
			}
		}
		return $args;
	}

	/**
	 * Converts template rows into the block template array format.
	 */
	public static function template( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$attrs = '' !== ( $row['placeholder'] ?? '' ) ? array( 'placeholder' => $row['placeholder'] ) : array();
			switch ( $row['block'] ) {
				case 'core/heading':
					$out[] = array( 'core/heading', $attrs + array( 'level' => max( 1, min( 6, (int) ( $row['level'] ?? 2 ) ) ) ) );
					break;
				case 'core/list':
					$out[] = array( 'core/list', array(), array( array( 'core/list-item', $attrs ) ) );
					break;
				case 'core/quote':
					$out[] = array( 'core/quote', array(), array( array( 'core/paragraph', $attrs ) ) );
					break;
				case 'core/buttons':
					$out[] = array( 'core/buttons', array(), array( array( 'core/button', $attrs ) ) );
					break;
				case 'core/paragraph':
					$out[] = array( 'core/paragraph', $attrs );
					break;
				default:
					$out[] = array( $row['block'], array() );
			}
		}
		return $out;
	}

	public static function taxonomy_args( array $def ): array {
		$args = array(
			'labels'             => Labels::taxonomy( $def ),
			'description'        => $def['description'],
			'public'             => (bool) $def['public'],
			'publicly_queryable' => (bool) $def['publicly_queryable'],
			'hierarchical'       => (bool) $def['hierarchical'],
			'show_ui'            => (bool) $def['show_ui'],
			'show_in_menu'       => (bool) $def['show_ui'] && (bool) $def['show_in_menu'],
			'show_in_nav_menus'  => (bool) $def['show_in_nav_menus'],
			'show_in_rest'       => (bool) $def['show_in_rest'],
			'show_tagcloud'      => (bool) $def['show_tagcloud'],
			'show_in_quick_edit' => (bool) $def['show_in_quick_edit'],
			'show_admin_column'  => (bool) $def['show_admin_column'],
			'query_var'          => (bool) $def['query_var'],
			'rewrite'            => $def['rewrite'] ? array(
				'slug'         => '' !== $def['rewrite_slug'] ? $def['rewrite_slug'] : $def['key'],
				'with_front'   => (bool) $def['with_front'],
				'hierarchical' => (bool) $def['rewrite_hierarchical'],
			) : false,
			'sort'               => (bool) $def['sort'],
		);
		if ( '' !== $def['rest_base'] ) {
			$args['rest_base'] = $def['rest_base'];
		}
		if ( 'custom' === $def['capability_model'] && '' !== $def['capability_base'] ) {
			$b                    = $def['capability_base'];
			$args['capabilities'] = array(
				'manage_terms' => "manage_{$b}",
				'edit_terms'   => "edit_{$b}",
				'delete_terms' => "delete_{$b}",
				'assign_terms' => "assign_{$b}",
			);
		}
		if ( '' !== $def['default_term_name'] ) {
			$args['default_term'] = array(
				'name'        => $def['default_term_name'],
				'slug'        => $def['default_term_slug'],
				'description' => $def['default_term_description'],
			);
		}
		return $args;
	}

	/**
	 * Attaches taxonomies registered after init 0 by other code, and detects overwritten registrations.
	 */
	public function late_attach() {
		foreach ( $this->definitions['post_type'] as $key => $def ) {
			foreach ( (array) $def['taxonomies'] as $taxonomy ) {
				if ( taxonomy_exists( $taxonomy ) && ! is_object_in_taxonomy( $key, $taxonomy ) ) {
					register_taxonomy_for_object_type( $taxonomy, $key );
				}
			}
			if ( get_post_type_object( $key ) !== $this->registered['post_type'][ $key ] ) {
				$this->conflicts[ 'post_type:' . $key ] = __( 'Overwritten: other code registered a post type with this key after Cradle.', 'cradle-content' );
			}
		}
		foreach ( $this->definitions['taxonomy'] as $key => $def ) {
			foreach ( (array) $def['object_types'] as $type ) {
				if ( post_type_exists( $type ) && ! is_object_in_taxonomy( $type, $key ) ) {
					register_taxonomy_for_object_type( $key, $type );
				}
			}
			if ( get_taxonomy( $key ) !== $this->registered['taxonomy'][ $key ] ) {
				$this->conflicts[ 'taxonomy:' . $key ] = __( 'Overwritten: other code registered a taxonomy with this key after Cradle.', 'cradle-content' );
			}
		}
	}

	/**
	 * Rewrite-relevant arguments of everything registered this request.
	 */
	public function rewrite_signature(): array {
		$sig = array();
		foreach ( $this->definitions['post_type'] as $key => $def ) {
			$sig[ 'p:' . $key ] = array( $def['public'], $def['publicly_queryable'], $def['rewrite'], $def['rewrite_slug'], $def['with_front'], $def['has_archive'], $def['archive_slug'], $def['hierarchical'], $def['query_var'] );
		}
		foreach ( $this->definitions['taxonomy'] as $key => $def ) {
			$sig[ 't:' . $key ] = array( $def['public'], $def['publicly_queryable'], $def['rewrite'], $def['rewrite_slug'], $def['with_front'], $def['rewrite_hierarchical'], $def['hierarchical'], $def['query_var'] );
		}
		ksort( $sig );
		return $sig;
	}

	/**
	 * Flushes rewrite rules once after a meaningful change (wp_loaded, after every plugin registered its routes).
	 */
	public function maybe_flush() {
		$signature = md5( (string) wp_json_encode( $this->rewrite_signature() ) );
		if ( get_option( self::SIGNATURE_OPTION ) === $signature && $this->routes_present() ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::SIGNATURE_OPTION, $signature, true );
	}

	/**
	 * Whether the stored rewrite rules still contain a route for every registered type with a URL.
	 * Other code can regenerate the rules while this module is paused (for example while Cradle core is
	 * missing); without this check those routes would stay missing until a definition changes.
	 * Cost: one scan of the autoloaded rules array.
	 */
	private function routes_present(): bool {
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) || ! $rules ) {
			return true; // Plain permalinks, or WordPress regenerates the rules itself with our types registered.
		}
		$slugs = array();
		foreach ( array( 'post_type', 'taxonomy' ) as $kind ) {
			foreach ( $this->definitions[ $kind ] as $key => $def ) {
				if ( ! empty( $def['public'] ) && ! empty( $def['rewrite'] ) ) {
					$slugs[] = trim( '' !== (string) $def['rewrite_slug'] ? (string) $def['rewrite_slug'] : $key, '/' ) . '/';
				}
			}
		}
		if ( ! $slugs ) {
			return true;
		}
		$keys = "\n" . implode( "\n", array_keys( $rules ) );
		foreach ( $slugs as $slug ) {
			if ( false === strpos( $keys, $slug ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Applies the definition's default ordering to the main query of its admin list and archive.
	 */
	public function default_order( $query ) {
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() ) {
			return;
		}
		$type = $query->get( 'post_type' );
		if ( ! is_string( $type ) || ! isset( $this->definitions['post_type'][ $type ] ) ) {
			return;
		}
		$def = $this->definitions['post_type'][ $type ];
		if ( '' === $def['order_by'] || $query->get( 'orderby' ) ) {
			return;
		}
		if ( ! is_admin() && ! $query->is_post_type_archive( $type ) ) {
			return;
		}
		$query->set( 'orderby', $def['order_by'] );
		$query->set( 'order', $def['order'] );
	}

	private function admin_columns() {
		foreach ( $this->definitions['post_type'] as $key => $def ) {
			if ( ! $def['admin_columns'] ) {
				continue;
			}
			add_filter(
				"manage_{$key}_posts_columns",
				function ( $columns ) use ( $key ) {
					return $this->columns( $key, (array) $columns );
				}
			);
			add_action(
				"manage_{$key}_posts_custom_column",
				function ( $column, $post_id ) use ( $key ) {
					$this->column_value( $key, (string) $column, (int) $post_id );
				},
				10,
				2
			);
			add_filter(
				"manage_edit-{$key}_sortable_columns",
				function ( $sortable ) use ( $def ) {
					if ( in_array( 'modified', $def['admin_columns'], true ) ) {
						$sortable['cradle_modified'] = array( 'modified', true );
					}
					if ( in_array( 'menu_order', $def['admin_columns'], true ) ) {
						$sortable['cradle_menu_order'] = array( 'menu_order', false );
					}
					return $sortable;
				}
			);
		}
	}

	private function columns( string $type, array $columns ): array {
		$def   = $this->definitions['post_type'][ $type ];
		$extra = array();
		foreach ( $def['admin_columns'] as $column ) {
			switch ( $column ) {
				case 'thumbnail':
					$extra['cradle_thumbnail'] = __( 'Image', 'cradle-content' );
					break;
				case 'author':
					if ( ! isset( $columns['author'] ) ) {
						$extra['author'] = __( 'Author', 'cradle-content' );
					}
					break;
				case 'modified':
					$extra['cradle_modified'] = __( 'Last modified', 'cradle-content' );
					break;
				case 'menu_order':
					$extra['cradle_menu_order'] = __( 'Order', 'cradle-content' );
					break;
				case 'excerpt':
					$extra['cradle_excerpt'] = __( 'Excerpt', 'cradle-content' );
					break;
				default:
					$field = Meta::instance()->field( 'post', $type, substr( $column, 6 ) );
					if ( $field ) {
						$extra[ 'cradle_field_' . $field['key'] ] = $field['label'];
					}
			}
		}
		// Insert before the date column when present.
		$out = array();
		foreach ( $columns as $name => $label ) {
			if ( 'date' === $name ) {
				$out += $extra;
			}
			$out[ $name ] = $label;
		}
		return $out + $extra;
	}

	private function column_value( string $type, string $column, int $post_id ) {
		switch ( $column ) {
			case 'cradle_thumbnail':
				echo get_the_post_thumbnail( $post_id, array( 48, 48 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-generated image markup.
				return;
			case 'cradle_modified':
				echo esc_html( get_the_modified_date( '', $post_id ) );
				return;
			case 'cradle_menu_order':
				echo esc_html( (string) get_post_field( 'menu_order', $post_id ) );
				return;
			case 'cradle_excerpt':
				echo esc_html( wp_trim_words( get_the_excerpt( $post_id ), 15 ) );
				return;
		}
		if ( str_starts_with( $column, 'cradle_field_' ) ) {
			$field = Meta::instance()->field( 'post', $type, substr( $column, 13 ) );
			if ( $field ) {
				$value = get_post_meta( $post_id, $field['meta_key'], ! Field_Types::is_multi( $field ) );
				echo esc_html( wp_html_excerpt( Field_Types::display( $field, $value ), 80, '…' ) );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Introspection
	 * ------------------------------------------------------------------- */

	public function is_registered( string $kind, string $key ): bool {
		return isset( $this->registered[ $kind ][ $key ] );
	}

	public function registered_keys( string $kind ): array {
		return array_keys( $this->registered[ $kind ] ?? array() );
	}

	public function conflicts(): array {
		return $this->conflicts;
	}

	/**
	 * Keys registered by other code (not Cradle Content).
	 */
	public function foreign_keys( string $kind ): array {
		$all = 'post_type' === $kind ? get_post_types() : get_taxonomies();
		return array_values( array_diff( array_values( $all ), array_keys( $this->registered[ $kind ] ) ) );
	}

	/**
	 * Rewrite and archive slugs claimed by post types and taxonomies registered by other code.
	 */
	public function foreign_slugs(): array {
		$slugs = array();
		foreach ( get_post_types( array(), 'objects' ) as $name => $object ) {
			if ( isset( $this->registered['post_type'][ $name ] ) ) {
				continue;
			}
			if ( is_array( $object->rewrite ) && ! empty( $object->rewrite['slug'] ) ) {
				$slugs[] = trim( $object->rewrite['slug'], '/' );
			}
			if ( is_string( $object->has_archive ) && '' !== $object->has_archive ) {
				$slugs[] = trim( $object->has_archive, '/' );
			}
		}
		foreach ( get_taxonomies( array(), 'objects' ) as $name => $object ) {
			if ( ! isset( $this->registered['taxonomy'][ $name ] ) && is_array( $object->rewrite ) && ! empty( $object->rewrite['slug'] ) ) {
				$slugs[] = trim( $object->rewrite['slug'], '/' );
			}
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Unregisters everything this module registered (deactivation), so a following flush drops the routes.
	 */
	public function unregister_all() {
		foreach ( array_keys( $this->registered['post_type'] ) as $key ) {
			if ( get_post_type_object( $key ) === $this->registered['post_type'][ $key ] ) {
				unregister_post_type( $key );
			}
		}
		foreach ( array_keys( $this->registered['taxonomy'] ) as $key ) {
			if ( get_taxonomy( $key ) === $this->registered['taxonomy'][ $key ] ) {
				unregister_taxonomy( $key );
			}
		}
		$this->registered  = array(
			'post_type' => array(),
			'taxonomy'  => array(),
		);
		$this->definitions = $this->registered;
		$this->booted      = false; // A later boot() in the same request (reactivation) registers everything again.
	}
}
