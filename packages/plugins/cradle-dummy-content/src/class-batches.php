<?php
/**
 * Batch records: one private `cradle_dummy_batch` post per generation batch.
 *
 * The record keeps the validated generation config (including the seed), the
 * IDs of terms and attachments the batch created, pre-existing items it only
 * referenced, the latest job IDs and a bounded list of operation reports.
 * Structured values are stored as JSON strings (no PHP serialization).
 *
 * @package Cradle\Dummy_Content
 */

namespace Cradle\Dummy_Content;

defined( 'ABSPATH' ) || exit;

final class Batches {

	const POST_TYPE = 'cradle_dummy_batch';

	const META_CONFIG     = '_cradle_dummy_config';
	const META_CREATED    = '_cradle_dummy_created';
	const META_REFERENCED = '_cradle_dummy_referenced';
	const META_REPORTS    = '_cradle_dummy_reports';
	const META_JOB        = '_cradle_dummy_job';
	const META_STATE      = '_cradle_dummy_state';
	const META_NOTE       = '_cradle_dummy_note';

	public static function register_post_type() {
		$caps = array(
			'create_posts'           => 'do_not_allow',
			'edit_posts'             => Lifecycle::CAP_GENERATE,
			'edit_others_posts'      => Lifecycle::CAP_GENERATE,
			'edit_private_posts'     => Lifecycle::CAP_GENERATE,
			'edit_published_posts'   => Lifecycle::CAP_GENERATE,
			'publish_posts'          => Lifecycle::CAP_GENERATE,
			'read_private_posts'     => Lifecycle::CAP_GENERATE,
			'read'                   => Lifecycle::CAP_GENERATE,
			'delete_posts'           => Lifecycle::CAP_CLEANUP,
			'delete_others_posts'    => Lifecycle::CAP_CLEANUP,
			'delete_private_posts'   => Lifecycle::CAP_CLEANUP,
			'delete_published_posts' => Lifecycle::CAP_CLEANUP,
		);
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Dummy content batches', 'cradle-dummy-content' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'delete_with_user'    => false,
				'supports'            => array( 'title', 'author' ),
				'capability_type'     => array( 'cradle_dummy_batch', 'cradle_dummy_batches' ),
				'capabilities'        => $caps,
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Creates the record for a validated config.
	 *
	 * @return array|\WP_Error Batch array.
	 */
	public static function create( array $config ) {
		$uuid = wp_generate_uuid4();
		$id   = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => self::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => $config['batch_name'],
					'post_name'   => $uuid,
					'post_author' => get_current_user_id(),
					'meta_input'  => array(
						self::META_CONFIG     => wp_json_encode( $config ),
						self::META_CREATED    => wp_json_encode(
							array(
								'terms'       => array(),
								'attachments' => array(),
							)
						),
						self::META_REFERENCED => wp_json_encode(
							array(
								'attachments' => array_values( $config['images']['library'] ?? array() ),
								'terms'       => self::referenced_terms( $config ),
							)
						),
						self::META_REPORTS    => '[]',
						self::META_STATE      => 'created',
						self::META_NOTE       => $config['note'],
					),
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return new \WP_Error( 'cradle_dummy_batch_record', __( 'The batch record could not be created.', 'cradle-dummy-content' ) );
		}
		return self::get( $uuid );
	}

	private static function referenced_terms( array $config ): array {
		$out = array();
		foreach ( (array) ( $config['taxonomies'] ?? array() ) as $taxonomy => $rules ) {
			foreach ( (array) ( $rules['existing'] ?? array() ) as $term_id ) {
				$out[] = array(
					'id'       => (int) $term_id,
					'taxonomy' => (string) $taxonomy,
				);
			}
		}
		return $out;
	}

	/**
	 * Creates a minimal record for markers whose batch has no record (for example after a
	 * database import), so cleanup can store its report. Never touches generated content.
	 *
	 * @return array|\WP_Error
	 */
	public static function ensure( string $uuid ) {
		$batch = self::get( $uuid );
		if ( $batch ) {
			return $batch;
		}
		if ( ! wp_is_uuid( $uuid, 4 ) ) {
			return new \WP_Error( 'cradle_dummy_batch_id', __( 'Invalid batch ID.', 'cradle-dummy-content' ) );
		}
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				/* translators: %s: first characters of the batch ID */
				'post_title'  => sprintf( __( 'Unrecorded batch %s', 'cradle-dummy-content' ), substr( $uuid, 0, 8 ) ),
				'post_name'   => $uuid,
				'post_author' => get_current_user_id(),
				'meta_input'  => array(
					self::META_CONFIG     => '{}',
					self::META_CREATED    => '{"terms":[],"attachments":[]}',
					self::META_REFERENCED => '{"terms":[],"attachments":[]}',
					self::META_REPORTS    => '[]',
					self::META_STATE      => 'unrecorded',
				),
			),
			true
		);
		return is_wp_error( $id ) ? $id : self::get( $uuid );
	}

	/**
	 * @return array|null
	 */
	public static function get( string $uuid ) {
		if ( ! wp_is_uuid( $uuid, 4 ) ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s LIMIT 1", self::POST_TYPE, $uuid ) );
		return $id ? self::from_post( $id ) : null;
	}

	private static function json_meta( int $id, string $key ): array {
		$value = json_decode( (string) get_post_meta( $id, $key, true ), true );
		return is_array( $value ) ? $value : array();
	}

	private static function from_post( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		return array(
			'record'     => (int) $post->ID,
			'uuid'       => (string) $post->post_name,
			'name'       => (string) $post->post_title,
			'note'       => (string) get_post_meta( $id, self::META_NOTE, true ),
			'owner'      => (int) $post->post_author,
			'created'    => (string) $post->post_date_gmt,
			'config'     => self::json_meta( $id, self::META_CONFIG ),
			'owned'      => self::json_meta( $id, self::META_CREATED ) + array(
				'terms'       => array(),
				'attachments' => array(),
			),
			'referenced' => self::json_meta( $id, self::META_REFERENCED ) + array(
				'terms'       => array(),
				'attachments' => array(),
			),
			'reports'    => self::json_meta( $id, self::META_REPORTS ),
			'job'        => (string) get_post_meta( $id, self::META_JOB, true ),
			'state'      => (string) get_post_meta( $id, self::META_STATE, true ),
		);
	}

	/**
	 * All records, newest first (bounded).
	 */
	public static function all( int $limit = 100 ): array {
		$ids = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => min( 200, max( 1, $limit ) ),
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => true,
				'cache_results'    => false,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$batch = self::from_post( (int) $id );
			if ( $batch ) {
				$out[ $batch['uuid'] ] = $batch;
			}
		}
		return $out;
	}

	public static function set_state( array $batch, string $state ) {
		update_post_meta( $batch['record'], self::META_STATE, $state );
	}

	public static function set_job( array $batch, string $job_uuid ) {
		update_post_meta( $batch['record'], self::META_JOB, $job_uuid );
	}

	/**
	 * Adds created term/attachment IDs to the record (idempotent).
	 *
	 * @param string $kind 'terms' or 'attachments'.
	 * @param mixed  $item Term array {id,taxonomy} or attachment ID.
	 */
	public static function add_owned( array $batch, string $kind, $item ) {
		$owned = self::json_meta( $batch['record'], self::META_CREATED ) + array(
			'terms'       => array(),
			'attachments' => array(),
		);
		if ( ! in_array( $item, $owned[ $kind ], true ) ) {
			$owned[ $kind ][] = $item;
		}
		update_post_meta( $batch['record'], self::META_CREATED, wp_slash( wp_json_encode( $owned ) ) );
	}

	/**
	 * Inserts or replaces the report of one operation (keyed by job UUID); keeps the newest N.
	 */
	public static function put_report( array $batch, array $report ) {
		$reports = self::json_meta( $batch['record'], self::META_REPORTS );
		$found   = false;
		foreach ( $reports as $i => $existing ) {
			if ( ( $existing['job'] ?? '' ) === ( $report['job'] ?? '' ) ) {
				$reports[ $i ] = $report;
				$found         = true;
			}
		}
		if ( ! $found ) {
			$reports[] = $report;
		}
		$keep    = max( 1, (int) Config::get( 'keep_reports' ) );
		$reports = array_slice( $reports, -$keep );
		update_post_meta( $batch['record'], self::META_REPORTS, wp_slash( wp_json_encode( array_values( $reports ) ) ) );
	}

	/**
	 * The non-terminal generation or cleanup job of a batch, if any (at most one may run).
	 *
	 * @return array|null Job row.
	 */
	public static function active_job( string $uuid ) {
		foreach ( cradle()->jobs()->recent( Module::ID, 100 ) as $job ) {
			if ( ( $job['payload']['batch'] ?? '' ) === $uuid && ! in_array( $job['state'], \Cradle\Core\Jobs::TERMINAL_STATES, true ) ) {
				return $job;
			}
		}
		return null;
	}

	/**
	 * Deletes a record. Callers must ensure the batch owns nothing any more.
	 */
	public static function forget( array $batch ): bool {
		return (bool) wp_delete_post( $batch['record'], true );
	}
}
