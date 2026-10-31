<?php
/**
 * Content language and translation groups.
 *
 * - Language of an entry: one term of the hidden taxonomy `cradle_language` (term slug =
 *   language slug). An entry of a translatable type without a term counts as the default language.
 * - Translation group: post meta `_cradle_lang_group` (UUID v4) shared by all translations; at most
 *   one entry per language per group (enforced by set_language() and link_translations()).
 * - Terms of translatable taxonomies: term meta `_cradle_lang` (slug) and `_cradle_lang_group`.
 *
 * @package Cradle\Languages
 */

namespace Cradle\Languages;

defined( 'ABSPATH' ) || exit;

final class Content {

	const TAXONOMY = 'cradle_language';

	const GROUP_META = '_cradle_lang_group';

	const TERM_LANG_META = '_cradle_lang';

	const TERM_GROUP_META = '_cradle_lang_group';

	/** Statuses that never take part in a translation group. */
	const IGNORED_STATUSES = array( 'trash', 'auto-draft', 'inherit' );

	/** @var string|null Language for the next inserted entry (set by Translator). */
	private static $pending = null;

	/** @var array<string,array<string,int>> Group UUID => lang => post ID. */
	private static $groups = array();

	public static function hooks() {
		register_taxonomy(
			self::TAXONOMY,
			array(),
			array(
				'labels'             => array(
					'name'          => __( 'Languages', 'cradle-languages' ),
					'singular_name' => __( 'Language', 'cradle-languages' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'show_tagcloud'      => false,
				'show_admin_column'  => false,
				'hierarchical'       => false,
				'query_var'          => false,
				'rewrite'            => false,
				'capabilities'       => array(
					'manage_terms' => Module::CAP,
					'edit_terms'   => Module::CAP,
					'delete_terms' => Module::CAP,
					'assign_terms' => 'edit_posts',
				),
			)
		);
		// After every type is registered (Cradle Content and themes register on init).
		add_action( 'init', array( __CLASS__, 'attach_types' ), 99 );
		add_action( 'wp_insert_post', array( __CLASS__, 'on_insert' ), 10, 3 );
		add_filter( 'wp_unique_post_slug', array( __CLASS__, 'unique_slug' ), 10, 6 );
		add_action( 'created_term', array( __CLASS__, 'on_created_term' ), 10, 3 );
		add_action( 'add_option_' . Config::OPTION, array( __CLASS__, 'settings_added' ), 10, 2 );
		add_action( 'update_option_' . Config::OPTION, array( __CLASS__, 'settings_changed' ), 10, 2 );
		add_action( 'delete_option_' . Config::OPTION, array( Config::class, 'flush' ) );
	}

	public static function attach_types() {
		foreach ( Config::translatable_post_types() as $type ) {
			register_taxonomy_for_object_type( self::TAXONOMY, $type );
		}
	}

	public static function is_translatable_post_type( $type ): bool {
		return is_string( $type ) && in_array( $type, Config::translatable_post_types(), true );
	}

	public static function is_translatable_taxonomy( $taxonomy ): bool {
		return is_string( $taxonomy ) && in_array( $taxonomy, Config::translatable_taxonomies(), true );
	}

	/* ------------------------------------------------------------------ */
	/* Entry language                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Language of an entry of a translatable type: its term, else the default language.
	 * Null for shared types, missing entries and entries tagged with a language that is no longer configured.
	 *
	 * @param \WP_Post|int $post Post.
	 */
	public static function post_language( $post ): ?array {
		$post = get_post( $post );
		if ( ! $post || ! self::is_translatable_post_type( $post->post_type ) ) {
			return null;
		}
		$slug = self::post_language_term( $post->ID );
		if ( '' === $slug ) {
			return Languages::default_language();
		}
		return Languages::get( $slug );
	}

	/** Slug of the entry's language term ('' when untagged). */
	public static function post_language_term( int $post_id ): string {
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return '';
		}
		$terms = get_the_terms( $post_id, self::TAXONOMY );
		return ( is_array( $terms ) && $terms ) ? (string) $terms[0]->slug : '';
	}

	public static function post_language_slug( $post ): string {
		$lang = self::post_language( $post );
		return $lang ? $lang['slug'] : '';
	}

	/**
	 * Assigns a language to an entry. Refused when another entry of its translation group already
	 * has that language. Does not check capabilities (callers do).
	 *
	 * @return true|\WP_Error
	 */
	public static function set_language( int $post_id, string $slug ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'cradle_languages_missing', __( 'The entry does not exist.', 'cradle-languages' ), array( 'status' => 404 ) );
		}
		if ( ! self::is_translatable_post_type( $post->post_type ) ) {
			return new \WP_Error( 'cradle_languages_shared', __( 'Entries of this type are shared by all languages.', 'cradle-languages' ), array( 'status' => 400 ) );
		}
		if ( ! Languages::exists( $slug ) ) {
			return new \WP_Error( 'cradle_languages_unknown', __( 'This language is not configured.', 'cradle-languages' ), array( 'status' => 400 ) );
		}
		$group = self::group( $post_id );
		if ( '' !== $group ) {
			$members = self::group_members( $group );
			if ( isset( $members[ $slug ] ) && $members[ $slug ] !== $post_id ) {
				return new \WP_Error( 'cradle_languages_taken', __( 'Another entry of this translation group already has this language.', 'cradle-languages' ), array( 'status' => 409 ) );
			}
		}
		$term = self::ensure_term( $slug );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		$result = wp_set_object_terms( $post_id, array( (int) $term ), self::TAXONOMY, false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::flush_group( $group );
		return true;
	}

	/** Term ID of a language's `cradle_language` term, created when missing. */
	public static function ensure_term( string $slug ) {
		$lang = Languages::get( $slug );
		if ( ! $lang ) {
			return new \WP_Error( 'cradle_languages_unknown', __( 'This language is not configured.', 'cradle-languages' ), array( 'status' => 400 ) );
		}
		$term = get_term_by( 'slug', $slug, self::TAXONOMY );
		if ( $term instanceof \WP_Term ) {
			return (int) $term->term_id;
		}
		$created = wp_insert_term( $lang['name'], self::TAXONOMY, array( 'slug' => $slug ) );
		if ( is_wp_error( $created ) ) {
			$existing = (int) ( $created->get_error_data( 'term_exists' ) ?? 0 );
			return $existing > 0 ? $existing : $created;
		}
		return (int) $created['term_id'];
	}

	/** Term taxonomy ID of a language term (0 when it does not exist yet). */
	public static function term_taxonomy_id( string $slug ): int {
		$term = taxonomy_exists( self::TAXONOMY ) ? get_term_by( 'slug', $slug, self::TAXONOMY ) : false;
		return $term instanceof \WP_Term ? (int) $term->term_taxonomy_id : 0;
	}

	/* ------------------------------------------------------------------ */
	/* Translation groups (entries)                                        */
	/* ------------------------------------------------------------------ */

	public static function group( int $post_id ): string {
		$group = (string) get_post_meta( $post_id, self::GROUP_META, true );
		return wp_is_uuid( $group, 4 ) ? $group : '';
	}

	/**
	 * Members of a group: lang slug => post ID (trash, auto-drafts and revisions excluded;
	 * the lowest ID wins if data was edited by hand into a conflict).
	 *
	 * @return array<string,int>
	 */
	public static function group_members( string $group ): array {
		if ( '' === $group ) {
			return array();
		}
		if ( isset( self::$groups[ $group ] ) ) {
			return self::$groups[ $group ];
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_status NOT IN ('trash','auto-draft','inherit') ORDER BY pm.post_id ASC LIMIT 50", self::GROUP_META, $group ) );
		$ids = array_map( 'intval', (array) $ids );
		if ( $ids ) {
			_prime_post_caches( $ids, true, false );
		}
		$out = array();
		foreach ( $ids as $id ) {
			$slug = self::post_language_slug( $id );
			if ( '' !== $slug && ! isset( $out[ $slug ] ) ) {
				$out[ $slug ] = $id;
			}
		}
		self::$groups[ $group ] = $out;
		return $out;
	}

	private static function flush_group( string $group ) {
		if ( '' !== $group ) {
			unset( self::$groups[ $group ] );
		}
		Request::flush_memo();
	}

	public static function flush_cache() {
		self::$groups = array();
		Request::flush_memo();
	}

	/**
	 * Translations of an entry including itself: lang slug => post ID.
	 *
	 * @param int  $post_id   Entry.
	 * @param bool $published Only published entries (for public output).
	 * @return array<string,int>
	 */
	public static function translations( int $post_id, bool $published = false ): array {
		$post = get_post( $post_id );
		if ( ! $post || ! self::is_translatable_post_type( $post->post_type ) ) {
			return array();
		}
		$group   = self::group( $post_id );
		$members = '' !== $group ? self::group_members( $group ) : array();
		$own     = self::post_language_slug( $post );
		if ( '' !== $own && ! in_array( $post_id, $members, true ) && ! in_array( $post->post_status, self::IGNORED_STATUSES, true ) ) {
			$members[ $own ] = $post_id;
		}
		if ( $published ) {
			$members = array_filter( $members, static fn( $id ) => 'publish' === get_post_status( $id ) );
		}
		// Configured languages only, in their order.
		$out = array();
		foreach ( Languages::slugs() as $slug ) {
			if ( isset( $members[ $slug ] ) ) {
				$out[ $slug ] = (int) $members[ $slug ];
			}
		}
		return $out;
	}

	public static function translation( int $post_id, string $slug, bool $published = false ): int {
		return (int) ( self::translations( $post_id, $published )[ $slug ] ?? 0 );
	}

	/** Whether $post_id is $target or one of its translations. */
	public static function in_group_of( int $post_id, int $target ): bool {
		if ( $target <= 0 ) {
			return false;
		}
		if ( $post_id === $target ) {
			return true;
		}
		$group = self::group( $target );
		return '' !== $group && self::group( $post_id ) === $group;
	}

	/**
	 * Links entries as translations of each other: lang slug => post ID. Each entry gets the
	 * language of its key; entries leave their previous groups. All entries must be of one type.
	 *
	 * @param array<string,int> $map Language => entry.
	 * @return string|\WP_Error Group UUID.
	 */
	public static function link_translations( array $map ) {
		$map   = array_filter( array_map( 'intval', $map ) );
		$type  = null;
		$group = '';
		if ( count( array_unique( $map ) ) !== count( $map ) ) {
			return new \WP_Error( 'cradle_languages_duplicate', __( 'An entry can have only one language.', 'cradle-languages' ), array( 'status' => 400 ) );
		}
		foreach ( $map as $slug => $id ) {
			$post = get_post( $id );
			if ( ! Languages::exists( (string) $slug ) || ! $post || in_array( $post->post_status, self::IGNORED_STATUSES, true ) ) {
				return new \WP_Error( 'cradle_languages_invalid', __( 'Every translation must be an existing entry in a configured language.', 'cradle-languages' ), array( 'status' => 400 ) );
			}
			if ( null !== $type && $post->post_type !== $type ) {
				return new \WP_Error( 'cradle_languages_type', __( 'Translations must be entries of the same content type.', 'cradle-languages' ), array( 'status' => 400 ) );
			}
			$type = $post->post_type;
			if ( '' === $group ) {
				$group = self::group( $id );
			}
		}
		if ( ! $map ) {
			return new \WP_Error( 'cradle_languages_empty', __( 'Nothing to link.', 'cradle-languages' ), array( 'status' => 400 ) );
		}
		if ( ! self::is_translatable_post_type( $type ) ) {
			return new \WP_Error( 'cradle_languages_shared', __( 'Entries of this type are shared by all languages.', 'cradle-languages' ), array( 'status' => 400 ) );
		}
		if ( '' === $group ) {
			$group = wp_generate_uuid4();
		}
		// Members of the target group that are not part of the new map and collide by language leave it.
		foreach ( self::group_members( $group ) as $slug => $id ) {
			if ( isset( $map[ $slug ] ) && $map[ $slug ] !== $id ) {
				delete_post_meta( $id, self::GROUP_META );
			}
		}
		foreach ( $map as $slug => $id ) {
			$old = self::group( $id );
			$term = self::ensure_term( (string) $slug );
			if ( is_wp_error( $term ) ) {
				return $term;
			}
			wp_set_object_terms( $id, array( (int) $term ), self::TAXONOMY, false );
			update_post_meta( $id, self::GROUP_META, $group );
			self::flush_group( $old );
		}
		self::flush_group( $group );
		return $group;
	}

	/** Ensures an entry has a group UUID (created on first translation). */
	public static function ensure_group( int $post_id ): string {
		$group = self::group( $post_id );
		if ( '' === $group ) {
			$group = wp_generate_uuid4();
			update_post_meta( $post_id, self::GROUP_META, $group );
		}
		return $group;
	}

	/** Removes an entry from its group (its language stays). */
	public static function unlink( int $post_id ) {
		$group = self::group( $post_id );
		delete_post_meta( $post_id, self::GROUP_META );
		self::flush_group( $group );
	}

	/**
	 * Language the next wp_insert_post() of a translatable entry receives (Translator, imports).
	 */
	public static function set_pending_language( ?string $slug ) {
		self::$pending = $slug;
	}

	/**
	 * `wp_insert_post`: entries of translatable types without a language get one — the pending
	 * language, a valid `cradle_lang` on wp-admin's "Add New" screen, or the default language.
	 */
	public static function on_insert( $post_id, $post, $update ) {
		unset( $update );
		if ( ! $post instanceof \WP_Post || 'revision' === $post->post_type || ! self::is_translatable_post_type( $post->post_type ) ) {
			return;
		}
		if ( '' !== self::post_language_term( (int) $post_id ) ) {
			return;
		}
		$slug = self::$pending;
		if ( null === $slug && is_admin() && isset( $GLOBALS['pagenow'] ) && 'post-new.php' === $GLOBALS['pagenow'] && isset( $_GET['cradle_lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- preselects the language of the user's own new draft; validated below.
			$slug = sanitize_key( wp_unslash( $_GET['cradle_lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( null === $slug || ! Languages::exists( $slug ) ) {
			$slug = Languages::default_slug();
		}
		$term = self::ensure_term( $slug );
		if ( ! is_wp_error( $term ) ) {
			wp_set_object_terms( (int) $post_id, array( (int) $term ), self::TAXONOMY, false );
		}
	}

	/**
	 * `wp_unique_post_slug`: a top-level entry may not take a language prefix as its slug
	 * (its address would be read as the language's home).
	 */
	public static function unique_slug( $slug, $post_id, $status, $type, $parent, $original ) {
		unset( $original );
		if ( ! is_string( $slug ) || (int) $parent > 0 || ! in_array( $slug, Languages::slugs(), true ) || ! self::top_level_type( (string) $type ) ) {
			return $slug;
		}
		return wp_unique_post_slug( $slug . '-2', (int) $post_id, (string) $status, (string) $type, 0 );
	}

	/** Types whose entries are addressed directly below the home URL. */
	public static function top_level_type( string $type ): bool {
		if ( in_array( $type, array( 'post', 'page', 'attachment' ), true ) ) {
			return true;
		}
		$object = get_post_type_object( $type );
		return $object && $object->public && ( empty( $object->rewrite ) || '' === trim( (string) ( $object->rewrite['slug'] ?? '' ), '/' ) );
	}

	/** An existing top-level entry whose address equals $slug, if any. */
	public static function top_level_collision( string $slug ): ?\WP_Post {
		global $wpdb;
		$types = array_values( array_filter( array_keys( get_post_types() ), array( __CLASS__, 'top_level_type' ) ) );
		$types = array_values( array_filter( $types, static fn( $t ) => preg_match( '/^[a-z0-9_-]{1,20}$/', $t ) ) );
		if ( ! $types ) {
			return null;
		}
		$in = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from a fixed count.
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_parent = 0 AND post_type IN ( {$in} ) AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) LIMIT 1", array_merge( array( $slug ), $types ) ) );
		return $id ? get_post( $id ) : null;
	}

	/* ------------------------------------------------------------------ */
	/* Terms                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Language of a term of a translatable taxonomy (default when unset); null for shared taxonomies.
	 *
	 * @param \WP_Term|int $term Term.
	 */
	public static function term_language( $term ): ?array {
		$term = get_term( $term );
		if ( ! $term instanceof \WP_Term || ! self::is_translatable_taxonomy( $term->taxonomy ) ) {
			return null;
		}
		$slug = (string) get_term_meta( $term->term_id, self::TERM_LANG_META, true );
		return '' === $slug ? Languages::default_language() : Languages::get( $slug );
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_term_language( int $term_id, string $slug ) {
		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term || ! self::is_translatable_taxonomy( $term->taxonomy ) ) {
			return new \WP_Error( 'cradle_languages_shared', __( 'Terms of this taxonomy are shared by all languages.', 'cradle-languages' ), array( 'status' => 400 ) );
		}
		if ( ! Languages::exists( $slug ) ) {
			return new \WP_Error( 'cradle_languages_unknown', __( 'This language is not configured.', 'cradle-languages' ), array( 'status' => 400 ) );
		}
		$group = (string) get_term_meta( $term_id, self::TERM_GROUP_META, true );
		if ( '' !== $group ) {
			$members = self::term_group_members( $group );
			if ( isset( $members[ $slug ] ) && $members[ $slug ] !== $term_id ) {
				return new \WP_Error( 'cradle_languages_taken', __( 'Another term of this translation group already has this language.', 'cradle-languages' ), array( 'status' => 409 ) );
			}
		}
		update_term_meta( $term_id, self::TERM_LANG_META, $slug );
		return true;
	}

	/** @return array<string,int> lang => term ID. */
	public static function term_group_members( string $group ): array {
		if ( ! wp_is_uuid( $group, 4 ) ) {
			return array();
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY term_id ASC LIMIT 50", self::TERM_GROUP_META, $group ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$lang = self::term_language( $id );
			if ( $lang && ! isset( $out[ $lang['slug'] ] ) ) {
				$out[ $lang['slug'] ] = $id;
			}
		}
		return $out;
	}

	/** @return array<string,int> Translations of a term including itself. */
	public static function term_translations( int $term_id ): array {
		$lang = self::term_language( $term_id );
		if ( ! $lang ) {
			return array();
		}
		$group   = (string) get_term_meta( $term_id, self::TERM_GROUP_META, true );
		$members = self::term_group_members( $group );
		if ( ! in_array( $term_id, $members, true ) ) {
			$members[ $lang['slug'] ] = $term_id;
		}
		$out = array();
		foreach ( Languages::slugs() as $slug ) {
			if ( isset( $members[ $slug ] ) ) {
				$out[ $slug ] = $members[ $slug ];
			}
		}
		return $out;
	}

	public static function term_translation( int $term_id, string $slug ): int {
		return (int) ( self::term_translations( $term_id )[ $slug ] ?? 0 );
	}

	/**
	 * Links terms of one taxonomy as translations: lang => term ID.
	 *
	 * @return string|\WP_Error Group UUID.
	 */
	public static function link_term_translations( array $map ) {
		$map      = array_filter( array_map( 'intval', $map ) );
		$taxonomy = null;
		$group    = '';
		if ( ! $map || count( array_unique( $map ) ) !== count( $map ) ) {
			return new \WP_Error( 'cradle_languages_invalid', __( 'Every translation must be a different term.', 'cradle-languages' ), array( 'status' => 400 ) );
		}
		foreach ( $map as $slug => $id ) {
			$term = get_term( $id );
			if ( ! Languages::exists( (string) $slug ) || ! $term instanceof \WP_Term || ( null !== $taxonomy && $term->taxonomy !== $taxonomy ) || ! self::is_translatable_taxonomy( $term->taxonomy ) ) {
				return new \WP_Error( 'cradle_languages_invalid', __( 'Translations must be terms of the same translatable taxonomy in configured languages.', 'cradle-languages' ), array( 'status' => 400 ) );
			}
			$taxonomy = $term->taxonomy;
			if ( '' === $group ) {
				$existing = (string) get_term_meta( $id, self::TERM_GROUP_META, true );
				$group    = wp_is_uuid( $existing, 4 ) ? $existing : '';
			}
		}
		if ( '' === $group ) {
			$group = wp_generate_uuid4();
		}
		foreach ( self::term_group_members( $group ) as $slug => $id ) {
			if ( isset( $map[ $slug ] ) && $map[ $slug ] !== $id ) {
				delete_term_meta( $id, self::TERM_GROUP_META );
			}
		}
		foreach ( $map as $slug => $id ) {
			update_term_meta( $id, self::TERM_LANG_META, (string) $slug );
			update_term_meta( $id, self::TERM_GROUP_META, $group );
		}
		return $group;
	}

	/**
	 * `created_term`: new terms of translatable taxonomies get the pending or default language.
	 */
	public static function on_created_term( $term_id, $tt_id, $taxonomy ) {
		unset( $tt_id );
		if ( ! self::is_translatable_taxonomy( $taxonomy ) || '' !== (string) get_term_meta( (int) $term_id, self::TERM_LANG_META, true ) ) {
			return;
		}
		$slug = null !== self::$pending && Languages::exists( self::$pending ) ? self::$pending : Languages::default_slug();
		update_term_meta( (int) $term_id, self::TERM_LANG_META, $slug );
	}

	/* ------------------------------------------------------------------ */
	/* Settings changes                                                    */
	/* ------------------------------------------------------------------ */

	public static function settings_added( $option, $value ) {
		unset( $option );
		self::settings_changed( array(), $value );
	}

	/**
	 * Keeps `cradle_language` terms in step with the language list: creates missing terms, renames
	 * a language's term (and term meta) when its locale stays but its prefix changed, updates names.
	 * Never deletes terms of removed languages (their entries keep their language).
	 */
	public static function settings_changed( $old, $value ) {
		Config::flush();
		self::flush_cache();
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}
		$old_rows = Languages::normalize_rows( is_array( $old ) && is_array( $old['languages'] ?? null ) ? $old['languages'] : array() );
		$new_rows = Languages::all();
		$by_locale = array();
		foreach ( $old_rows as $row ) {
			$by_locale[ $row['locale'] ] = $row['slug'];
		}
		global $wpdb;
		foreach ( $new_rows as $slug => $row ) {
			$previous = $by_locale[ $row['locale'] ] ?? $slug;
			if ( $previous !== $slug && ! isset( $new_rows[ $previous ] ) ) {
				$term = get_term_by( 'slug', $previous, self::TAXONOMY );
				if ( $term instanceof \WP_Term && ! get_term_by( 'slug', $slug, self::TAXONOMY ) ) {
					wp_update_term( $term->term_id, self::TAXONOMY, array( 'slug' => $slug ) );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->update( $wpdb->termmeta, array( 'meta_value' => $slug ), array( 'meta_key' => self::TERM_LANG_META, 'meta_value' => $previous ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
					wp_cache_flush_group( 'term_meta' );
					cradle_log_activity( Module::ID, 'language_renamed', sprintf( 'Language prefix %1$s renamed to %2$s.', $previous, $slug ) );
				}
			}
			$term = get_term_by( 'slug', $slug, self::TAXONOMY );
			if ( $term instanceof \WP_Term ) {
				if ( $term->name !== $row['name'] ) {
					wp_update_term( $term->term_id, self::TAXONOMY, array( 'name' => $row['name'] ) );
				}
			} else {
				self::ensure_term( $slug );
			}
		}
		Request::flush_memo();
	}
}
