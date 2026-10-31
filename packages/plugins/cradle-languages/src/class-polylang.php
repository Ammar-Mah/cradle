<?php
/**
 * Import from Polylang (read-only on Polylang's data; works while Polylang is inactive).
 *
 * Reads, with prepared SQL because Polylang's taxonomies are not registered when it is inactive:
 * - taxonomy `language` (term slug, name, term_group = order, description = serialized
 *   { locale, rtl, flag_code }), and post ↔ language relationships;
 * - taxonomy `term_language` (`pll_<slug>` terms) and term ↔ language relationships (object = term ID);
 * - taxonomies `post_translations` / `term_translations` (description = serialized { lang: ID });
 * - option `polylang` (default_lang, hide_default, force_lang, post_types, taxonomies,
 *   media_support, nav_menus[theme][location][lang]).
 * Serialized values are read with a bounded structural parser (never unserialize()).
 *
 * Types and taxonomies: Polylang translates post, page, category and post_tag, the custom types and
 * taxonomies listed in its option, and media when media support is on; everything else is untranslated,
 * i.e. shared. The import writes an explicit mode for every type and taxonomy Cradle Languages can
 * configure (translatable when Polylang translates it, otherwise shared; types that declare the
 * `cradle-languages` post type support and that Polylang does not list keep their declared default),
 * so Cradle's defaults (public types translatable) never apply to imported content.
 *
 * dry_run() reports counts, the type mapping and problems without writing. The import runs as the core job
 * `languages/polylang-import` (phases settings → posts → groups → terms → term_groups), each step
 * idempotent: language terms are set (never duplicated), translation groups get a UUID derived from
 * the Polylang group's ID, so running the import again gives the same result. Polylang data is never
 * changed or deleted.
 *
 * @package Cradle\Languages
 */

namespace Cradle\Languages;

defined( 'ABSPATH' ) || exit;

final class Polylang {

	const BATCH = 200;

	const MAX_DESCRIPTION = 65536;

	/** @return array<string,array> Polylang languages keyed by slug, in Polylang's order. */
	public static function languages(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, t.name, t.slug, t.term_group, tt.term_taxonomy_id, tt.description, tt.count FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s ORDER BY t.term_group ASC, t.term_id ASC LIMIT 100", 'language' ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$meta                 = self::decode( (string) $row->description );
			$out[ (string) $row->slug ] = array(
				'slug'   => (string) $row->slug,
				'name'   => (string) $row->name,
				'locale' => is_string( $meta['locale'] ?? null ) ? $meta['locale'] : '',
				'rtl'    => ! empty( $meta['rtl'] ),
				'tt_id'  => (int) $row->term_taxonomy_id,
				'order'  => (int) $row->term_group,
			);
		}
		return $out;
	}

	public static function options(): array {
		$options = get_option( 'polylang', array() );
		return is_array( $options ) ? $options : array();
	}

	public static function has_data(): bool {
		return (bool) self::languages();
	}

	/**
	 * Decodes a Polylang serialized description without unserialize(): a bounded structural parser
	 * for PHP-serialized arrays of strings, integers, booleans, floats and null (nested arrays are
	 * parsed to depth 3; objects and references are refused). Returns the top-level scalar entries.
	 */
	public static function decode( string $value ): array {
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > self::MAX_DESCRIPTION || 'a:' !== substr( $value, 0, 2 ) ) {
			return array();
		}
		$pos  = 0;
		$data = self::parse_value( $value, $pos, 0 );
		if ( ! is_array( $data ) || ! is_array( $data[0] ) || strlen( $value ) !== $pos ) {
			return array();
		}
		return array_filter( $data[0], static fn( $v ) => is_scalar( $v ) );
	}

	/**
	 * @return array{0:mixed}|null [value], or null when malformed.
	 */
	private static function parse_value( string $s, int &$pos, int $depth ): ?array {
		if ( preg_match( '/\Gs:(\d{1,6}):"/', $s, $m, 0, $pos ) ) {
			$len   = (int) $m[1];
			$start = $pos + strlen( $m[0] );
			if ( '";' !== substr( $s, $start + $len, 2 ) ) {
				return null;
			}
			$pos = $start + $len + 2;
			return array( substr( $s, $start, $len ) );
		}
		if ( preg_match( '/\Gi:(-?\d{1,18});/', $s, $m, 0, $pos ) ) {
			$pos += strlen( $m[0] );
			return array( (int) $m[1] );
		}
		if ( preg_match( '/\Gb:([01]);/', $s, $m, 0, $pos ) ) {
			$pos += strlen( $m[0] );
			return array( '1' === $m[1] );
		}
		if ( preg_match( '/\Gd:(-?\d{1,20}(?:\.\d{1,20})?(?:E[+-]?\d{1,3})?);/', $s, $m, 0, $pos ) ) {
			$pos += strlen( $m[0] );
			return array( (float) $m[1] );
		}
		if ( 'N;' === substr( $s, $pos, 2 ) ) {
			$pos += 2;
			return array( null );
		}
		if ( $depth < 3 && preg_match( '/\Ga:(\d{1,4}):\{/', $s, $m, 0, $pos ) ) {
			$count = (int) $m[1];
			$pos  += strlen( $m[0] );
			$out   = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$key = self::parse_value( $s, $pos, 3 );
				if ( null === $key || ( ! is_int( $key[0] ) && ! is_string( $key[0] ) ) ) {
					return null;
				}
				$item = self::parse_value( $s, $pos, $depth + 1 );
				if ( null === $item ) {
					return null;
				}
				$out[ $key[0] ] = $item[0];
			}
			if ( '}' !== ( $s[ $pos ] ?? '' ) ) {
				return null;
			}
			++$pos;
			return array( $out );
		}
		return null;
	}

	/**
	 * Cradle Languages rows built from Polylang's languages.
	 *
	 * @return array<int,array>
	 */
	public static function language_rows( array $languages, string $default ): array {
		$rows = array();
		foreach ( $languages as $slug => $lang ) {
			$rows[] = array(
				'slug'    => $slug,
				'locale'  => $lang['locale'],
				'name'    => $lang['name'],
				'native'  => $lang['name'],
				'dir'     => $lang['rtl'] ? 'rtl' : 'ltr',
				'default' => $slug === $default,
			);
		}
		return $rows;
	}

	/**
	 * Read-only report: what the import would do and what prevents it.
	 */
	public static function dry_run(): array {
		global $wpdb;
		$languages = self::languages();
		$options   = self::options();
		$report    = array(
			'found'       => (bool) $languages,
			'languages'   => array(),
			'default'     => '',
			'hideDefault' => true,
			'postTypes'   => array(),
			'taxonomies'  => array(),
			'counts'      => array(
				'posts'      => array(),
				'postGroups' => 0,
				'terms'      => array(),
				'termGroups' => 0,
				'menus'      => 0,
			),
			'problems'    => array(),
			'warnings'    => array(),
			'fingerprint' => '',
		);
		if ( ! $languages ) {
			$report['problems'][] = __( 'No Polylang languages were found in the database.', 'cradle-languages' );
			return $report;
		}
		$default = is_string( $options['default_lang'] ?? null ) && isset( $languages[ $options['default_lang'] ] ) ? $options['default_lang'] : (string) array_key_first( $languages );
		$report['default']     = $default;
		$report['hideDefault'] = ! array_key_exists( 'hide_default', $options ) || ! empty( $options['hide_default'] );
		$report['languages']   = self::language_rows( $languages, $default );
		$report['postTypes']   = self::post_type_modes( $options );
		$report['taxonomies']  = self::taxonomy_modes( $options );
		foreach ( self::unconfigurable( $options ) as $kind => $names ) {
			foreach ( $names as $name ) {
				$report['warnings'][] = 'post_type' === $kind
					/* translators: %s: post type name */
					? sprintf( __( 'Polylang translates the content type “%s”, which Cradle Languages cannot configure here (it is not registered, or it is neither public nor shown in the admin). Its entries get no language; if it is registered by a plugin or theme that is not active, activate it and run the import again.', 'cradle-languages' ), $name )
					/* translators: %s: taxonomy name */
					: sprintf( __( 'Polylang translates the taxonomy “%s”, which Cradle Languages cannot configure here (it is not registered or not public). Its terms get no language; if it is registered by a plugin or theme that is not active, activate it and run the import again.', 'cradle-languages' ), $name );
			}
		}
		if ( count( $languages ) > Languages::MAX ) {
			/* translators: %d: maximum number of languages */
			$report['problems'][] = sprintf( __( 'Polylang has more than %d languages; Cradle Languages supports at most that many.', 'cradle-languages' ), Languages::MAX );
		}
		$existing = Languages::slugs();
		foreach ( $languages as $slug => $lang ) {
			if ( ! preg_match( Languages::SLUG_PATTERN, $slug ) ) {
				/* translators: %s: language slug */
				$report['problems'][] = sprintf( __( 'The Polylang language code “%s” cannot be used as an address prefix (2–3 lowercase letters, optionally -xx).', 'cradle-languages' ), $slug );
			} elseif ( ! in_array( $slug, $existing, true ) ) {
				$problem = Config::slug_problem( $slug );
				if ( '' !== $problem ) {
					$report['problems'][] = $problem;
				}
			}
			if ( '' === $lang['locale'] || ! Config::valid_locale( $lang['locale'] ) ) {
				/* translators: %s: language slug */
				$report['problems'][] = sprintf( __( 'The Polylang language “%s” has no valid locale.', 'cradle-languages' ), $slug );
			}
		}
		if ( isset( $options['force_lang'] ) && 1 !== (int) $options['force_lang'] ) {
			$report['warnings'][] = __( 'Polylang did not use directory prefixes (it used the content, subdomains or domains). Cradle Languages serves languages under /<prefix>/; addresses of other languages change.', 'cradle-languages' );
		}
		if ( empty( $options['rewrite'] ) && isset( $options['rewrite'] ) ) {
			$report['warnings'][] = __( 'Polylang added /language/ before the prefix; Cradle Languages serves /<prefix>/ directly.', 'cradle-languages' );
		}

		// Entries per language.
		$tt_ids = array_column( $languages, 'tt_id', 'slug' );
		foreach ( $tt_ids as $slug => $tt ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$report['counts']['posts'][ $slug ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tr.term_taxonomy_id = %d AND p.post_status NOT IN ('trash','auto-draft','inherit')", $tt ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$orphans = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tt.taxonomy = %s AND p.ID IS NULL", 'language' ) );
		if ( $orphans ) {
			/* translators: %d: number of relationships */
			$report['warnings'][] = sprintf( _n( '%d language assignment points to an entry that no longer exists; it is skipped.', '%d language assignments point to entries that no longer exist; they are skipped.', $orphans, 'cradle-languages' ), $orphans );
		}

		// Translation groups.
		$bad = 0;
		foreach ( self::groups( 'post_translations', 0, 10000 ) as $group ) {
			$valid = self::valid_post_group( $group['map'], $languages );
			if ( count( $valid ) >= 2 ) {
				++$report['counts']['postGroups'];
			}
			if ( count( $valid ) !== count( $group['map'] ) ) {
				++$bad;
			}
		}
		if ( $bad ) {
			/* translators: %d: number of translation groups */
			$report['warnings'][] = sprintf( _n( '%d translation group refers to missing entries or entries in another language; those links are skipped.', '%d translation groups refer to missing entries or entries in another language; those links are skipped.', $bad, 'cradle-languages' ), $bad );
		}

		// Terms.
		foreach ( self::term_language_terms() as $slug => $tt ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$report['counts']['terms'][ $slug ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $tt ) );
		}
		foreach ( self::groups( 'term_translations', 0, 10000 ) as $group ) {
			if ( count( array_filter( $group['map'], static fn( $id ) => get_term( (int) $id ) instanceof \WP_Term ) ) >= 2 ) {
				++$report['counts']['termGroups'];
			}
		}
		$report['counts']['menus'] = array_sum( array_map( 'count', self::menu_locations( $options, $default ) ) );
		$report['fingerprint']     = md5( (string) wp_json_encode( array( $report['languages'], $report['counts'], $report['postTypes'], $report['taxonomies'] ) ) );
		return $report;
	}

	/** Post types Polylang translated (post and page always, media when enabled). */
	public static function post_types( array $options ): array {
		$types = array( 'post', 'page' );
		foreach ( (array) ( $options['post_types'] ?? array() ) as $type ) {
			if ( is_string( $type ) && preg_match( '/^[a-z0-9_-]{1,20}$/', $type ) ) {
				$types[] = $type;
			}
		}
		if ( ! empty( $options['media_support'] ) ) {
			$types[] = 'attachment';
		}
		return array_values( array_unique( $types ) );
	}

	public static function taxonomies( array $options ): array {
		$out = array( 'category', 'post_tag' );
		foreach ( (array) ( $options['taxonomies'] ?? array() ) as $taxonomy ) {
			if ( is_string( $taxonomy ) && preg_match( '/^[a-z0-9_-]{1,32}$/', $taxonomy ) ) {
				$out[] = $taxonomy;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Mode the import writes for every post type Cradle Languages can configure: translatable when
	 * Polylang translates it, otherwise shared. A type that declares the `cradle-languages` post type
	 * support and is not listed by Polylang (Polylang never managed it, for example Cradle Forms'
	 * forms) keeps its declared default.
	 *
	 * @return array<string,string> Type => translatable|shared, sorted by name.
	 */
	public static function post_type_modes( array $options ): array {
		$translated = self::post_types( $options );
		$out        = array();
		foreach ( array_keys( Config::eligible_post_types() ) as $type ) {
			if ( in_array( $type, $translated, true ) ) {
				$out[ $type ] = 'translatable';
			} elseif ( post_type_supports( $type, Config::SUPPORT ) ) {
				$out[ $type ] = Config::default_post_type_mode( $type );
			} else {
				$out[ $type ] = 'shared';
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Mode the import writes for every taxonomy Cradle Languages can configure: translatable when
	 * Polylang translates it, otherwise shared.
	 *
	 * @return array<string,string> Taxonomy => translatable|shared, sorted by name.
	 */
	public static function taxonomy_modes( array $options ): array {
		$translated = self::taxonomies( $options );
		$out        = array();
		foreach ( array_keys( Config::eligible_taxonomies() ) as $taxonomy ) {
			$out[ $taxonomy ] = in_array( $taxonomy, $translated, true ) ? 'translatable' : 'shared';
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Types and taxonomies Polylang translates that Cradle Languages cannot configure (not
	 * registered right now, or not eligible). Reported as warnings by the dry run.
	 *
	 * @return array{post_type:string[],taxonomy:string[]}
	 */
	public static function unconfigurable( array $options ): array {
		return array(
			'post_type' => array_values( array_diff( self::post_types( $options ), array_keys( Config::eligible_post_types() ) ) ),
			'taxonomy'  => array_values( array_diff( self::taxonomies( $options ), array_keys( Config::eligible_taxonomies() ) ) ),
		);
	}

	/** @return array<string,array<string,int>> Non-default language => location => menu ID. */
	public static function menu_locations( array $options, string $default ): array {
		$theme = (array) ( $options['nav_menus'][ get_stylesheet() ] ?? array() );
		$out   = array();
		foreach ( $theme as $location => $by_lang ) {
			if ( ! is_string( $location ) || ! preg_match( '/^[a-z0-9_-]{1,60}$/i', $location ) || ! is_array( $by_lang ) ) {
				continue;
			}
			foreach ( $by_lang as $lang => $menu ) {
				if ( is_string( $lang ) && $lang !== $default && preg_match( Languages::SLUG_PATTERN, $lang ) && (int) $menu > 0 && is_nav_menu( (int) $menu ) ) {
					$out[ $lang ][ $location ] = (int) $menu;
				}
			}
		}
		return $out;
	}

	/** @return array<string,int> Language slug => term_taxonomy_id of its `term_language` term. */
	public static function term_language_terms(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.slug, tt.term_taxonomy_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s LIMIT 100", 'term_language' ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$slug = preg_replace( '/^pll_/', '', (string) $row->slug );
			$out[ $slug ] = (int) $row->term_taxonomy_id;
		}
		return $out;
	}

	/**
	 * Translation groups of a Polylang taxonomy after a term_taxonomy_id cursor.
	 *
	 * @return array<int,array{id:int,map:array<string,int>}>
	 */
	public static function groups( string $taxonomy, int $after, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id, description FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND term_taxonomy_id > %d ORDER BY term_taxonomy_id ASC LIMIT %d", $taxonomy, $after, $limit ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$map = array();
			foreach ( self::decode( (string) $row->description ) as $lang => $id ) {
				if ( is_string( $lang ) && (int) $id > 0 ) {
					$map[ $lang ] = (int) $id;
				}
			}
			$out[] = array(
				'id'  => (int) $row->term_taxonomy_id,
				'map' => $map,
			);
		}
		return $out;
	}

	/**
	 * Members of a Polylang post group whose entry exists and carries that language in Polylang.
	 *
	 * @return array<string,int>
	 */
	private static function valid_post_group( array $map, array $languages ): array {
		global $wpdb;
		$out = array();
		foreach ( $map as $slug => $id ) {
			if ( ! isset( $languages[ $slug ] ) || ! get_post( $id ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$has = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id = %d AND term_taxonomy_id = %d", $id, $languages[ $slug ]['tt_id'] ) );
			if ( $has ) {
				$out[ $slug ] = $id;
			}
		}
		return $out;
	}

	/** Deterministic UUID v4-format identifier for a Polylang group (idempotent re-imports). */
	public static function group_uuid( string $kind, int $id ): string {
		$h     = md5( 'cradle-languages:polylang:' . $kind . ':' . $id );
		$h[12] = '4';
		$h[16] = dechex( 8 | ( hexdec( $h[16] ) & 3 ) );
		return substr( $h, 0, 8 ) . '-' . substr( $h, 8, 4 ) . '-' . substr( $h, 12, 4 ) . '-' . substr( $h, 16, 4 ) . '-' . substr( $h, 20, 12 );
	}

	/**
	 * Starts the import job after a dry run with the same fingerprint and no blocking problem.
	 *
	 * @return array|\WP_Error Job row.
	 */
	public static function start( string $fingerprint ) {
		$report = self::dry_run();
		if ( $report['problems'] ) {
			return new \WP_Error( 'cradle_languages_polylang_problems', implode( ' ', $report['problems'] ) );
		}
		if ( '' === $fingerprint || ! hash_equals( $report['fingerprint'], $fingerprint ) ) {
			return new \WP_Error( 'cradle_languages_polylang_stale', __( 'The Polylang data changed since the dry run. Run the dry run again and review it before importing.', 'cradle-languages' ) );
		}
		$job = cradle()->jobs()->create( Tasks::IMPORT, array( 'fingerprint' => $fingerprint ), array( 'phase' => 'settings' ) );
		if ( ! is_wp_error( $job ) ) {
			cradle_log_activity( Module::ID, 'polylang_import_started', 'Polylang import started.', array( 'job' => $job['uuid'] ) );
		}
		return $job;
	}

	/**
	 * Job step (idempotent per phase).
	 */
	public static function step( array $job ) {
		$phase  = '' !== (string) $job['phase'] ? (string) $job['phase'] : 'settings';
		$after  = (int) ( ( (array) $job['cursor_data'] )['after'] ?? 0 );
		$counts = (array) ( $job['payload']['counts'] ?? array() );
		$next   = static function ( string $phase, array $counts ) use ( $job ): array {
			return array(
				'state'       => 'running',
				'phase'       => $phase,
				'cursor_data' => array( 'after' => 0 ),
				'payload'     => array_merge( (array) $job['payload'], array( 'counts' => $counts ) ),
			);
		};
		switch ( $phase ) {
			case 'settings':
				$result = self::import_settings();
				if ( is_wp_error( $result ) ) {
					return new \WP_Error( $result->get_error_code(), $result->get_error_message(), 'fatal' );
				}
				return $next( 'posts', $counts );

			case 'posts':
				$languages = self::languages();
				$by_tt     = array_flip( array_column( $languages, 'tt_id', 'slug' ) );
				if ( ! $by_tt ) {
					return $next( 'groups', $counts );
				}
				global $wpdb;
				$in = implode( ', ', array_map( 'intval', array_keys( $by_tt ) ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT object_id, term_taxonomy_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ( {$in} ) AND object_id > %d ORDER BY object_id ASC LIMIT %d", $after, self::BATCH ) );
				if ( ! $rows ) {
					return $next( 'groups', $counts );
				}
				$last = $after;
				foreach ( $rows as $row ) {
					$last = max( $last, (int) $row->object_id );
					$slug = $by_tt[ (int) $row->term_taxonomy_id ] ?? '';
					$post = get_post( (int) $row->object_id );
					if ( ! $post || ! Content::is_translatable_post_type( $post->post_type ) || ! Languages::exists( $slug ) ) {
						continue;
					}
					if ( Content::post_language_term( $post->ID ) !== $slug ) {
						$term = Content::ensure_term( $slug );
						if ( ! is_wp_error( $term ) ) {
							wp_set_object_terms( $post->ID, array( (int) $term ), Content::TAXONOMY, false );
						}
					}
					$counts['posts'] = (int) ( $counts['posts'] ?? 0 ) + 1;
				}
				return array(
					'state'         => 'running',
					'phase'         => 'posts',
					'cursor_data'   => array( 'after' => $last ),
					'progress_done' => (int) $job['progress_done'] + count( $rows ),
					'payload'       => array_merge( (array) $job['payload'], array( 'counts' => $counts ) ),
				);

			case 'groups':
				$groups = self::groups( 'post_translations', $after, 50 );
				if ( ! $groups ) {
					return $next( 'terms', $counts );
				}
				foreach ( $groups as $group ) {
					$valid = array();
					foreach ( $group['map'] as $slug => $id ) {
						if ( Languages::exists( $slug ) && get_post( $id ) && Content::post_language_slug( $id ) === $slug ) {
							$valid[ $slug ] = $id;
						}
					}
					if ( count( $valid ) < 2 ) {
						continue;
					}
					$uuid = self::group_uuid( 'post', $group['id'] );
					foreach ( $valid as $id ) {
						update_post_meta( $id, Content::GROUP_META, $uuid );
					}
					$counts['groups'] = (int) ( $counts['groups'] ?? 0 ) + 1;
				}
				Content::flush_cache();
				return array(
					'state'       => 'running',
					'phase'       => 'groups',
					'cursor_data' => array( 'after' => (int) end( $groups )['id'] ),
					'payload'     => array_merge( (array) $job['payload'], array( 'counts' => $counts ) ),
				);

			case 'terms':
				$tts = self::term_language_terms();
				if ( ! $tts ) {
					return $next( 'term_groups', $counts );
				}
				global $wpdb;
				$by_tt = array_flip( $tts );
				$in    = implode( ', ', array_map( 'intval', array_keys( $by_tt ) ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT object_id, term_taxonomy_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ( {$in} ) AND object_id > %d ORDER BY object_id ASC LIMIT %d", $after, self::BATCH ) );
				if ( ! $rows ) {
					return $next( 'term_groups', $counts );
				}
				$last = $after;
				foreach ( $rows as $row ) {
					$last = max( $last, (int) $row->object_id );
					$slug = $by_tt[ (int) $row->term_taxonomy_id ] ?? '';
					$term = get_term( (int) $row->object_id );
					if ( $term instanceof \WP_Term && Languages::exists( $slug ) && Content::is_translatable_taxonomy( $term->taxonomy ) ) {
						update_term_meta( $term->term_id, Content::TERM_LANG_META, $slug );
						$counts['terms'] = (int) ( $counts['terms'] ?? 0 ) + 1;
					}
				}
				return array(
					'state'       => 'running',
					'phase'       => 'terms',
					'cursor_data' => array( 'after' => $last ),
					'payload'     => array_merge( (array) $job['payload'], array( 'counts' => $counts ) ),
				);

			case 'term_groups':
				$groups = self::groups( 'term_translations', $after, 50 );
				if ( ! $groups ) {
					cradle_log_activity( Module::ID, 'polylang_import_completed', 'Polylang import completed.', array( 'counts' => $counts ) );
					return array(
						'state'   => 'completed',
						'phase'   => 'done',
						'payload' => array_merge( (array) $job['payload'], array( 'counts' => $counts ) ),
					);
				}
				foreach ( $groups as $group ) {
					$valid = array();
					foreach ( $group['map'] as $slug => $id ) {
						$term = get_term( $id );
						if ( $term instanceof \WP_Term && Languages::exists( $slug ) && Content::is_translatable_taxonomy( $term->taxonomy ) ) {
							$valid[ $slug ] = $id;
						}
					}
					if ( count( $valid ) < 2 ) {
						continue;
					}
					$uuid = self::group_uuid( 'term', $group['id'] );
					foreach ( $valid as $slug => $id ) {
						update_term_meta( $id, Content::TERM_LANG_META, $slug );
						update_term_meta( $id, Content::TERM_GROUP_META, $uuid );
					}
					$counts['termGroups'] = (int) ( $counts['termGroups'] ?? 0 ) + 1;
				}
				return array(
					'state'       => 'running',
					'phase'       => 'term_groups',
					'cursor_data' => array( 'after' => (int) end( $groups )['id'] ),
					'payload'     => array_merge( (array) $job['payload'], array( 'counts' => $counts ) ),
				);
		}
		return new \WP_Error( 'cradle_languages_phase', 'Unknown import phase.', 'fatal' );
	}

	/**
	 * Writes the languages, prefix choice, type and taxonomy modes and menus to the settings.
	 * Every configurable type and taxonomy gets an explicit mode (see post_type_modes()); types and
	 * taxonomies Polylang translates that are not registered now are stored as translatable too, so
	 * they behave as in Polylang once their plugin or theme is active again (run the import again
	 * then to give their entries a language).
	 *
	 * @return true|\WP_Error
	 */
	public static function import_settings() {
		$languages = self::languages();
		if ( ! $languages ) {
			return new \WP_Error( 'cradle_languages_polylang_none', __( 'No Polylang languages were found in the database.', 'cradle-languages' ) );
		}
		$options = self::options();
		$default = is_string( $options['default_lang'] ?? null ) && isset( $languages[ $options['default_lang'] ] ) ? $options['default_lang'] : (string) array_key_first( $languages );
		$raw     = Config::raw();
		$types   = is_array( $raw['post_types'] ?? null ) ? $raw['post_types'] : array();
		foreach ( self::post_types( $options ) as $type ) {
			$types[ $type ] = 'translatable';
		}
		$types      = array_merge( $types, self::post_type_modes( $options ) );
		$taxonomies = is_array( $raw['taxonomies'] ?? null ) ? $raw['taxonomies'] : array();
		foreach ( self::taxonomies( $options ) as $taxonomy ) {
			$taxonomies[ $taxonomy ] = 'translatable';
		}
		$taxonomies = array_merge( $taxonomies, self::taxonomy_modes( $options ) );
		$menus = is_array( $raw['menu_locations'] ?? null ) ? $raw['menu_locations'] : array();
		foreach ( self::menu_locations( $options, $default ) as $lang => $rows ) {
			$menus[ $lang ] = array_merge( (array) ( $menus[ $lang ] ?? array() ), $rows );
		}
		$result = cradle()->settings()->update(
			Config::SECTION,
			array(
				'languages'           => self::language_rows( $languages, $default ),
				'hide_default_prefix' => ! array_key_exists( 'hide_default', $options ) || ! empty( $options['hide_default'] ),
				'post_types'          => $types,
				'taxonomies'          => $taxonomies,
				'menu_locations'      => $menus,
			),
			false
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $result['errors'] ) ) {
			return new \WP_Error( 'cradle_languages_polylang_settings', implode( ' ', $result['errors'] ) );
		}
		Config::flush();
		Content::attach_types();
		return true;
	}
}
