<?php
/**
 * Background jobs (core Jobs service, capability cradle_manage_languages):
 *
 * - `languages/assign-default`: gives entries of translatable types and terms of translatable
 *   taxonomies that have no language the default language (batches of 100, resumable by ID cursor).
 *   Not required for correct output (untagged content already counts as the default language);
 *   it makes the stored data explicit, for example before adding languages or exporting.
 * - `languages/polylang-import`: see Polylang.
 *
 * @package Cradle\Languages
 */

namespace Cradle\Languages;

defined( 'ABSPATH' ) || exit;

final class Tasks {

	const ASSIGN = 'languages/assign-default';
	const IMPORT = 'languages/polylang-import';

	const BATCH = 100;

	/**
	 * @param \Cradle\Core\Plugin $core Core.
	 */
	public static function register( $core ) {
		$core->jobs()->register_type(
			self::ASSIGN,
			array(
				'capability'    => Module::CAP,
				'step'          => array( __CLASS__, 'assign_step' ),
				'max_retries'   => 3,
				'lease_seconds' => 60,
				'time_budget'   => 8,
			)
		);
		$core->jobs()->register_type(
			self::IMPORT,
			array(
				'capability'    => Module::CAP,
				'step'          => array( Polylang::class, 'step' ),
				'max_retries'   => 3,
				'lease_seconds' => 60,
				'time_budget'   => 8,
			)
		);
	}

	/** Translatable types with safe identifiers (for SQL IN lists). */
	private static function types(): array {
		return array_values( array_filter( Config::translatable_post_types(), static fn( $t ) => preg_match( '/^[a-z0-9_-]{1,20}$/', $t ) ) );
	}

	/**
	 * Entries without a language after $after (IDs only, bounded).
	 *
	 * @return int[]
	 */
	public static function untagged_posts( int $after = 0, int $limit = self::BATCH ): array {
		global $wpdb;
		$types = self::types();
		if ( ! $types ) {
			return array();
		}
		$in = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built from a fixed count.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p WHERE p.ID > %d AND p.post_type IN ( {$in} ) AND p.post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) AND NOT EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s AND tr.object_id = p.ID ) ORDER BY p.ID ASC LIMIT %d", array_merge( array( $after ), $types, array( Content::TAXONOMY, $limit ) ) ) );
		return array_map( 'intval', (array) $ids );
	}

	public static function untagged_post_count(): int {
		global $wpdb;
		$types = self::types();
		if ( ! $types ) {
			return 0;
		}
		$in = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built from a fixed count.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type IN ( {$in} ) AND p.post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) AND NOT EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s AND tr.object_id = p.ID )", array_merge( $types, array( Content::TAXONOMY ) ) ) );
	}

	/** @return int[] Terms of translatable taxonomies without `_cradle_lang`. */
	public static function untagged_terms( int $after = 0, int $limit = self::BATCH ): array {
		global $wpdb;
		$taxonomies = array_values( array_filter( Config::translatable_taxonomies(), static fn( $t ) => preg_match( '/^[a-z0-9_-]{1,32}$/', $t ) ) );
		if ( ! $taxonomies ) {
			return array();
		}
		$in = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built from a fixed count.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt WHERE tt.term_id > %d AND tt.taxonomy IN ( {$in} ) AND NOT EXISTS ( SELECT 1 FROM {$wpdb->termmeta} tm WHERE tm.term_id = tt.term_id AND tm.meta_key = %s ) ORDER BY tt.term_id ASC LIMIT %d", array_merge( array( $after ), $taxonomies, array( Content::TERM_LANG_META, $limit ) ) ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Creates the assignment job for the current user.
	 *
	 * @return array|\WP_Error Job row.
	 */
	public static function start_assign() {
		$core = cradle();
		$job  = $core->jobs()->create( self::ASSIGN, array( 'lang' => Languages::default_slug() ), array( 'total' => self::untagged_post_count() ) );
		if ( ! is_wp_error( $job ) ) {
			cradle_log_activity( Module::ID, 'assign_started', 'Default-language assignment started.', array( 'job' => $job['uuid'] ) );
		}
		return $job;
	}

	/**
	 * Job step: one batch of entries, then one batch of terms per call (idempotent).
	 */
	public static function assign_step( array $job ) {
		$cursor = (array) $job['cursor_data'];
		$phase  = '' !== (string) $job['phase'] ? (string) $job['phase'] : 'posts';
		$slug   = (string) ( $job['payload']['lang'] ?? '' );
		if ( ! Languages::exists( $slug ) ) {
			return new \WP_Error( 'cradle_languages_default', 'The default language changed; start the assignment again.', 'fatal' );
		}
		$after = (int) ( $cursor['after'] ?? 0 );
		if ( 'posts' === $phase ) {
			$ids = self::untagged_posts( $after );
			if ( ! $ids ) {
				return array(
					'state'       => 'running',
					'phase'       => 'terms',
					'cursor_data' => array( 'after' => 0 ),
				);
			}
			$term = Content::ensure_term( $slug );
			if ( is_wp_error( $term ) ) {
				return $term;
			}
			foreach ( $ids as $id ) {
				wp_set_object_terms( $id, array( (int) $term ), Content::TAXONOMY, false );
			}
			return array(
				'state'         => 'running',
				'phase'         => 'posts',
				'cursor_data'   => array( 'after' => end( $ids ) ),
				'progress_done' => (int) $job['progress_done'] + count( $ids ),
			);
		}
		$ids = self::untagged_terms( $after );
		if ( ! $ids ) {
			cradle_log_activity( Module::ID, 'assign_completed', 'Default-language assignment completed.', array( 'entries' => (int) $job['progress_done'] ) );
			return array(
				'state' => 'completed',
				'phase' => 'done',
			);
		}
		foreach ( $ids as $id ) {
			update_term_meta( $id, Content::TERM_LANG_META, $slug );
		}
		return array(
			'state'       => 'running',
			'phase'       => 'terms',
			'cursor_data' => array( 'after' => end( $ids ) ),
		);
	}
}
