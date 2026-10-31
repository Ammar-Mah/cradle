<?php
/**
 * Language filtering of queries.
 *
 * Posts (`WP_Query`, including get_posts(), Query Loop, Cradle Blocks post cards and the hero
 * slider): on routed frontend requests every non-singular query for translatable types is limited
 * to the current language unless it sets `cradle_lang` (`'all'` or a language slug). Outside the
 * frontend (wp-admin, REST, cron, CLI) queries are unfiltered unless they set a slug.
 * - All queried types translatable: a `cradle_language` clause is appended to the parsed tax query
 *   on `parse_tax_query` (the queried object of term archives is unchanged).
 * - Mixed translatable and shared types (search, `post_type = any`): a WHERE clause limited to the
 *   translatable types (needs `suppress_filters` false, as for main and block queries).
 * - The default language also matches entries without a language.
 * - Queries with an explicit `post__in` are not filtered; each chosen entry is replaced by its
 *   published translation in the current language when one exists.
 * Terms (`WP_Term_Query`) of translatable taxonomies are limited the same way on the frontend,
 * except lookups by slug, name, ID or object.
 *
 * @package Cradle\Languages
 */

namespace Cradle\Languages;

defined( 'ABSPATH' ) || exit;

final class Query {

	const VAR = 'cradle_lang';

	/** @var \WeakMap<\WP_Query,array>|null Queries filtered through posts_where. */
	private static $mixed = null;

	public static function hooks() {
		self::$mixed = new \WeakMap();
		add_action( 'pre_get_posts', array( __CLASS__, 'pre_get_posts' ), 20 );
		add_action( 'parse_tax_query', array( __CLASS__, 'parse_tax_query' ), 20 );
		add_filter( 'posts_where', array( __CLASS__, 'posts_where' ), 20, 2 );
		add_action( 'pre_get_terms', array( __CLASS__, 'pre_get_terms' ), 20 );
	}

	/**
	 * Language a query is limited to, or null for no filtering.
	 *
	 * @param mixed $requested The query's `cradle_lang` value.
	 */
	public static function language_for( $requested ): ?string {
		if ( 'all' === $requested ) {
			return null;
		}
		if ( is_string( $requested ) && Languages::exists( $requested ) ) {
			return $requested;
		}
		return Request::is_frontend() && Languages::is_multilingual() ? Languages::current_slug() : null;
	}

	/**
	 * Replaces explicitly chosen entries (`post__in`) by their translation in the current language.
	 */
	public static function pre_get_posts( \WP_Query $query ) {
		$ids = $query->get( 'post__in' );
		if ( ! is_array( $ids ) || ! $ids || '' !== (string) $query->get( self::VAR ) || ! Request::is_frontend() || ! Languages::is_multilingual() ) {
			return;
		}
		$slug   = Languages::current_slug();
		$mapped = array();
		foreach ( $ids as $id ) {
			$id          = (int) $id;
			$translation = $id > 0 ? Content::translation( $id, $slug, true ) : 0;
			$mapped[]    = $translation ? $translation : $id;
		}
		$query->set( 'post__in', array_values( array_unique( $mapped ) ) );
	}

	public static function parse_tax_query( \WP_Query $query ) {
		if ( $query->is_singular || ( $query->get( 'post__in' ) && '' === (string) $query->get( self::VAR ) ) ) {
			return;
		}
		$slug = self::language_for( $query->get( self::VAR ) );
		if ( null === $slug ) {
			return;
		}
		$types        = self::queried_types( $query );
		$translatable = array_values( array_intersect( $types, Config::translatable_post_types() ) );
		if ( ! $translatable ) {
			return;
		}
		if ( count( $translatable ) < count( $types ) ) {
			self::$mixed[ $query ] = array(
				'slug'  => $slug,
				'types' => $translatable,
			);
			return;
		}
		$clause = self::tax_clause( $slug );
		$extra  = new \WP_Tax_Query( array( $clause ) );
		$tq     = $query->tax_query;
		if ( ! $tq instanceof \WP_Tax_Query ) {
			return;
		}
		$mine = $extra->queries[0] ?? null;
		if ( ! $mine ) {
			return;
		}
		if ( empty( $tq->queries ) ) {
			$tq->queries = array(
				'relation' => 'AND',
				$mine,
			);
		} else {
			$existing             = $tq->queries;
			$existing['relation'] = $existing['relation'] ?? $tq->relation;
			$tq->queries          = array(
				'relation' => 'AND',
				$existing,
				$mine,
			);
		}
		$tq->relation = 'AND';
	}

	/**
	 * Tax query clause matching entries of a language (and untagged ones for the default).
	 */
	public static function tax_clause( string $slug ): array {
		$tt    = Content::term_taxonomy_id( $slug );
		$match = array(
			'taxonomy'         => Content::TAXONOMY,
			'field'            => 'term_taxonomy_id',
			'terms'            => array( $tt ),
			'include_children' => false,
		);
		if ( Languages::default_slug() !== $slug ) {
			return array(
				'relation' => 'AND',
				$match,
			);
		}
		$untagged = array(
			'taxonomy' => Content::TAXONOMY,
			'operator' => 'NOT EXISTS',
		);
		return $tt ? array( 'relation' => 'OR', $match, $untagged ) : array( 'relation' => 'AND', $untagged );
	}

	/**
	 * Post types a non-singular query will return (mirrors WP_Query::get_posts()).
	 *
	 * @return string[]
	 */
	public static function queried_types( \WP_Query $query ): array {
		$type = $query->get( 'post_type' );
		if ( 'any' === $type || ( is_array( $type ) && in_array( 'any', $type, true ) ) ) {
			return array_values( get_post_types( array( 'exclude_from_search' => false ) ) );
		}
		if ( ! empty( $type ) ) {
			return array_values( array_filter( (array) $type, 'is_string' ) );
		}
		if ( $query->is_attachment ) {
			return array( 'attachment' );
		}
		if ( $query->is_tax || $query->is_category || $query->is_tag ) {
			$taxonomies = $query->tax_query instanceof \WP_Tax_Query ? array_keys( $query->tax_query->queried_terms ) : array();
			$out        = array();
			foreach ( get_post_types( array( 'exclude_from_search' => false ) ) as $pt ) {
				$object_taxonomies = 'attachment' === $pt ? get_taxonomies_for_attachments() : get_object_taxonomies( $pt );
				if ( array_intersect( $taxonomies, $object_taxonomies ) ) {
					$out[] = $pt;
				}
			}
			return $out ? $out : array_values( get_post_types( array( 'exclude_from_search' => false ) ) );
		}
		if ( $query->is_search ) {
			return array_values( get_post_types( array( 'exclude_from_search' => false ) ) );
		}
		return array( 'post' );
	}

	/**
	 * `posts_where` for queries mixing translatable and shared types.
	 */
	public static function posts_where( $where, $query ) {
		if ( ! $query instanceof \WP_Query || ! isset( self::$mixed[ $query ] ) ) {
			return $where;
		}
		global $wpdb;
		$spec = self::$mixed[ $query ];
		unset( self::$mixed[ $query ] );
		$types = array_values( array_filter( $spec['types'], static fn( $t ) => preg_match( '/^[a-z0-9_-]{1,20}$/', $t ) ) );
		if ( ! $types ) {
			return $where;
		}
		$in   = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$tt   = Content::term_taxonomy_id( $spec['slug'] );
		$has  = "{$wpdb->posts}.ID IN ( SELECT tr.object_id FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id = %d )";
		$none = "NOT EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} tr2 INNER JOIN {$wpdb->term_taxonomy} tt2 ON tt2.term_taxonomy_id = tr2.term_taxonomy_id WHERE tt2.taxonomy = %s AND tr2.object_id = {$wpdb->posts}.ID )";
		if ( Languages::default_slug() === $spec['slug'] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built from a fixed count; table names from $wpdb.
			$sql = $wpdb->prepare( " AND ( {$wpdb->posts}.post_type NOT IN ( {$in} ) OR {$has} OR {$none} )", array_merge( $types, array( $tt, Content::TAXONOMY ) ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built from a fixed count; table names from $wpdb.
			$sql = $wpdb->prepare( " AND ( {$wpdb->posts}.post_type NOT IN ( {$in} ) OR {$has} )", array_merge( $types, array( $tt ) ) );
		}
		return $where . $sql;
	}

	/**
	 * `pre_get_terms`: terms of translatable taxonomies in the current language (frontend), or in
	 * the language named by a `cradle_lang` query var.
	 */
	public static function pre_get_terms( \WP_Term_Query $query ) {
		$vars = $query->query_vars;
		$slug = self::language_for( $vars[ self::VAR ] ?? null );
		if ( null === $slug ) {
			return;
		}
		foreach ( array( 'object_ids', 'slug', 'name', 'include', 'term_taxonomy_id' ) as $lookup ) {
			if ( ! empty( $vars[ $lookup ] ) ) {
				return;
			}
		}
		$taxonomies = array_filter( (array) ( $vars['taxonomy'] ?? array() ), 'is_string' );
		if ( ! $taxonomies || array_diff( $taxonomies, Config::translatable_taxonomies() ) ) {
			return;
		}
		$mine = Languages::default_slug() === $slug
			? array(
				'relation' => 'OR',
				array(
					'key'   => Content::TERM_LANG_META,
					'value' => $slug,
				),
				array(
					'key'     => Content::TERM_LANG_META,
					'compare' => 'NOT EXISTS',
				),
			)
			: array(
				'key'   => Content::TERM_LANG_META,
				'value' => $slug,
			);
		$existing                         = $vars['meta_query'] ?? array();
		$query->query_vars['meta_query'] = $existing ? array( 'relation' => 'AND', $existing, $mine ) : array( $mine ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}
}
