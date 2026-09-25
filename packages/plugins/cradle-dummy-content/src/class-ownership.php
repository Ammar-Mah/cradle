<?php
/**
 * Protected ownership markers for generated posts, attachments and terms.
 *
 * Ownership is proven only by these underscore-prefixed (protected) meta keys,
 * never by titles, slugs or a category name. The content hash includes the post
 * ID, so a copy made by any duplicator that blindly copies metadata no longer
 * matches and is treated as "changed since generation" (kept by default).
 *
 * @package Cradle\Dummy_Content
 */

namespace Cradle\Dummy_Content;

defined( 'ABSPATH' ) || exit;

final class Ownership {

	const GENERATED  = '_cradle_generated';
	const BATCH      = '_cradle_generation_batch';
	const VERSION    = '_cradle_generation_version';
	const CREATED_AT = '_cradle_generated_at';
	const CREATED_BY = '_cradle_generated_by';
	const HASH       = '_cradle_generation_hash';
	const INDEX      = '_cradle_generation_index';

	/** Data format version written to VERSION. */
	const DATA_VERSION = 1;

	public static function keys(): array {
		return array( self::GENERATED, self::BATCH, self::VERSION, self::CREATED_AT, self::CREATED_BY, self::HASH, self::INDEX );
	}

	/**
	 * Registers the markers as protected, non-REST, non-editable meta for all post types and taxonomies.
	 */
	public static function register_meta() {
		$types = array(
			self::GENERATED  => 'integer',
			self::BATCH      => 'string',
			self::VERSION    => 'integer',
			self::CREATED_AT => 'integer',
			self::CREATED_BY => 'integer',
			self::HASH       => 'string',
			self::INDEX      => 'string',
		);
		foreach ( $types as $key => $type ) {
			$args = array(
				'type'          => $type,
				'single'        => true,
				'show_in_rest'  => false,
				'description'   => 'Cradle Dummy Content ownership marker.',
				'auth_callback' => '__return_false',
			);
			register_post_meta( '', $key, $args );
			if ( self::HASH !== $key ) {
				register_term_meta( '', $key, $args );
			}
		}
	}

	/**
	 * Marker values for a new record (hash is added after insert).
	 */
	public static function markers( string $batch, string $index, int $user_id ): array {
		return array(
			self::GENERATED  => 1,
			self::BATCH      => $batch,
			self::VERSION    => self::DATA_VERSION,
			self::CREATED_AT => time(),
			self::CREATED_BY => $user_id,
			self::INDEX      => $index,
		);
	}

	/**
	 * Original-content hash: post ID + title + content + excerpt as stored.
	 */
	public static function hash_post( \WP_Post $post ): string {
		return hash( 'sha256', wp_json_encode( array( (int) $post->ID, (string) $post->post_title, (string) $post->post_content, (string) $post->post_excerpt ) ) );
	}

	public static function store_hash( int $post_id ) {
		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		if ( $post ) {
			update_post_meta( $post_id, self::HASH, self::hash_post( $post ) );
		}
	}

	/**
	 * True when the stored hash is missing or differs (edited, or copied into another record).
	 *
	 * @param \WP_Post|object $post Row with ID, post_title, post_content, post_excerpt.
	 */
	public static function is_modified( $post, ?string $stored = null ): bool {
		$stored = $stored ?? (string) get_post_meta( (int) $post->ID, self::HASH, true );
		if ( '' === $stored ) {
			return true;
		}
		$current = hash( 'sha256', wp_json_encode( array( (int) $post->ID, (string) $post->post_title, (string) $post->post_content, (string) $post->post_excerpt ) ) );
		return ! hash_equals( $stored, $current );
	}

	public static function is_generated_post( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, self::GENERATED, true ) && '' !== (string) get_post_meta( $post_id, self::BATCH, true );
	}

	/**
	 * Finds the record created for a batch index (idempotent job steps).
	 */
	public static function find_post( string $batch, string $index ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT b.post_id FROM {$wpdb->postmeta} b INNER JOIN {$wpdb->postmeta} i ON i.post_id = b.post_id AND i.meta_key = %s AND i.meta_value = %s WHERE b.meta_key = %s AND b.meta_value = %s ORDER BY b.post_id ASC LIMIT 1",
				self::INDEX,
				$index,
				self::BATCH,
				$batch
			)
		);
	}

	public static function find_term( string $batch, string $index ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT b.term_id FROM {$wpdb->termmeta} b INNER JOIN {$wpdb->termmeta} i ON i.term_id = b.term_id AND i.meta_key = %s AND i.meta_value = %s WHERE b.meta_key = %s AND b.meta_value = %s ORDER BY b.term_id ASC LIMIT 1",
				self::INDEX,
				$index,
				self::BATCH,
				$batch
			)
		);
	}

	/**
	 * Generated posts (optionally including attachments) of a batch after a cursor ID.
	 *
	 * @param string      $batch       Batch UUID.
	 * @param string      $kind        'content' (non-attachments) or 'attachment'.
	 * @param int         $after_id    Cursor.
	 * @param int         $limit       Page size.
	 * @param string|null $exclude_status Status to skip (for example 'trash').
	 * @return array<int,object> Rows: ID, post_type, post_status, post_title, post_content, post_excerpt, hash.
	 */
	public static function batch_posts( string $batch, string $kind, int $after_id = 0, int $limit = 500, ?string $exclude_status = null ): array {
		global $wpdb;
		$type_sql   = 'attachment' === $kind ? "p.post_type = 'attachment'" : "p.post_type <> 'attachment'";
		$status_sql = $exclude_status ? $wpdb->prepare( ' AND p.post_status <> %s', $exclude_status ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_type, p.post_status, p.post_title, p.post_content, p.post_excerpt, p.post_parent, h.meta_value AS hash
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} g ON g.post_id = p.ID AND g.meta_key = %s AND g.meta_value = '1'
				INNER JOIN {$wpdb->postmeta} b ON b.post_id = p.ID AND b.meta_key = %s AND b.meta_value = %s
				LEFT JOIN {$wpdb->postmeta} h ON h.post_id = p.ID AND h.meta_key = %s
				WHERE {$type_sql}{$status_sql} AND p.ID > %d
				ORDER BY p.ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed fragments.
				self::GENERATED,
				self::BATCH,
				$batch,
				self::HASH,
				$after_id,
				max( 1, $limit )
			)
		);
	}

	/**
	 * Generated terms of a batch.
	 *
	 * @return array<int,object> Rows: term_id, taxonomy, term_taxonomy_id, name, count.
	 */
	public static function batch_terms( string $batch ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.name, tt.taxonomy, tt.term_taxonomy_id, tt.count
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				INNER JOIN {$wpdb->termmeta} g ON g.term_id = t.term_id AND g.meta_key = %s AND g.meta_value = '1'
				INNER JOIN {$wpdb->termmeta} b ON b.term_id = t.term_id AND b.meta_key = %s AND b.meta_value = %s
				ORDER BY t.term_id ASC",
				self::GENERATED,
				self::BATCH,
				$batch
			)
		);
	}

	/**
	 * Batch IDs present in ownership markers (posts and terms), with counts.
	 *
	 * @return array<string,array{posts:int,terms:int}>
	 */
	public static function batches_in_use(): array {
		global $wpdb;
		$out = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS batch, COUNT(*) AS c FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value LIMIT 500", self::BATCH ) );
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->batch ] = array(
				'posts' => (int) $row->c,
				'terms' => 0,
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS batch, COUNT(*) AS c FROM {$wpdb->termmeta} WHERE meta_key = %s GROUP BY meta_value LIMIT 500", self::BATCH ) );
		foreach ( (array) $rows as $row ) {
			$key                  = (string) $row->batch;
			$out[ $key ]          = $out[ $key ] ?? array(
				'posts' => 0,
				'terms' => 0,
			);
			$out[ $key ]['terms'] = (int) $row->c;
		}
		return $out;
	}
}
