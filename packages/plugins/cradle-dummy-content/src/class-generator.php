<?php
/**
 * Generation: batch creation and the bounded, idempotent `dummy-content/generate` job step.
 *
 * Phases: terms → images → posts → finalize. Every created record carries
 * ownership markers written in the same wp_insert_* call (meta_input), and a
 * step that is retried after a crash finds already-created records by their
 * batch + index markers instead of creating duplicates.
 *
 * @package Cradle\Dummy_Content
 */

namespace Cradle\Dummy_Content;

defined( 'ABSPATH' ) || exit;

final class Generator {

	const JOB_TYPE = 'dummy-content/generate';

	/**
	 * Validates a request, creates the batch record and its generation job.
	 *
	 * @param array $raw Unslashed request fields.
	 * @return array|\WP_Error { batch, job } or WP_Error with field errors in data['errors'].
	 */
	public static function start( array $raw ) {
		if ( ! current_user_can( Lifecycle::CAP_GENERATE ) ) {
			return new \WP_Error( 'cradle_dummy_forbidden', __( 'You are not allowed to generate dummy content.', 'cradle-dummy-content' ), array( 'status' => 403 ) );
		}
		$result = Request::validate( $raw );
		if ( ! $result['config'] ) {
			return new \WP_Error(
				'cradle_dummy_invalid',
				__( 'The generation settings need attention.', 'cradle-dummy-content' ),
				array(
					'status' => 400,
					'errors' => $result['errors'],
				)
			);
		}
		$config = $result['config'];
		$batch  = Batches::create( $config );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}
		$job = cradle()->jobs()->create(
			self::JOB_TYPE,
			array( 'batch' => $batch['uuid'] ),
			array(
				'total'  => $config['count'],
				'phase'  => 'terms',
				'cursor' => array(
					'phase' => 'terms',
					'index' => 0,
					'ids'   => array(),
				),
			)
		);
		if ( is_wp_error( $job ) ) {
			Batches::forget( $batch );
			return $job;
		}
		Batches::set_job( $batch, $job['uuid'] );
		Batches::set_state( $batch, 'generating' );
		cradle_log_activity(
			Module::ID,
			'batch_created',
			sprintf( 'Dummy content batch "%1$s" queued: %2$d %3$s item(s), status %4$s.', $config['batch_name'], $config['count'], $config['post_type'], $config['status'] ),
			array(
				'batch'      => $batch['uuid'],
				'job'        => $job['uuid'],
				'production' => $config['production'],
			)
		);
		return array(
			'batch' => Batches::get( $batch['uuid'] ),
			'job'   => $job,
		);
	}

	/**
	 * Starts a new generation job for an existing batch (resume after cancel/failure).
	 * Idempotent: items already created are found by their markers and skipped.
	 *
	 * @return array|\WP_Error Job.
	 */
	public static function resume( array $batch ) {
		if ( ! current_user_can( Lifecycle::CAP_GENERATE ) || empty( $batch['config']['post_type'] ) ) {
			return new \WP_Error( 'cradle_dummy_forbidden', __( 'This batch cannot be resumed.', 'cradle-dummy-content' ), array( 'status' => 403 ) );
		}
		$current = Batches::active_job( $batch['uuid'] );
		if ( $current ) {
			if ( self::JOB_TYPE === $current['type'] ) {
				return $current;
			}
			return new \WP_Error( 'cradle_dummy_running', __( 'A cleanup of this batch is running. Let it finish or cancel it first.', 'cradle-dummy-content' ), array( 'status' => 409 ) );
		}
		$job = cradle()->jobs()->create(
			self::JOB_TYPE,
			array( 'batch' => $batch['uuid'] ),
			array(
				'total'  => (int) $batch['config']['count'],
				'phase'  => 'terms',
				'cursor' => array(
					'phase' => 'terms',
					'index' => 0,
					'ids'   => array(),
				),
			)
		);
		if ( ! is_wp_error( $job ) ) {
			Batches::set_job( $batch, $job['uuid'] );
			Batches::set_state( $batch, 'generating' );
		}
		return $job;
	}

	/**
	 * Job step callback (core Jobs contract).
	 *
	 * @param array $job Claimed job.
	 * @return array|\WP_Error Columns to commit.
	 */
	public static function step( array $job ) {
		if ( get_current_user_id() && ! current_user_can( Lifecycle::CAP_GENERATE ) ) {
			return new \WP_Error( 'cradle_dummy_forbidden', 'Not allowed.', 'fatal' );
		}
		$batch = Batches::get( (string) ( $job['payload']['batch'] ?? '' ) );
		if ( ! $batch || empty( $batch['config']['post_type'] ) ) {
			return new \WP_Error( 'cradle_dummy_batch_missing', 'The batch record is missing.', 'fatal' );
		}
		$config = $batch['config'];
		if ( ! post_type_exists( $config['post_type'] ) ) {
			return new \WP_Error( 'cradle_dummy_type_missing', 'The post type is no longer registered.', 'fatal' );
		}
		$cursor = (array) $job['cursor_data'] + array(
			'phase' => 'terms',
			'index' => 0,
			'ids'   => array(),
		);
		$builder = new Content_Builder( $config );

		switch ( $cursor['phase'] ) {
			case 'terms':
				self::create_terms( $batch, $builder );
				$cursor['phase'] = 'images';
				$cursor['index'] = 0;
				break;

			case 'images':
				$wanted = 'placeholder' === ( $config['images']['mode'] ?? 'none' ) ? (int) $config['images']['count'] : 0;
				if ( $cursor['index'] < $wanted ) {
					$k        = (int) $cursor['index'];
					$existing = Ownership::find_post( $batch['uuid'], 'image:' . $k );
					$id       = $existing ? $existing : Images::create( $batch, $k, (int) $batch['owner'] );
					if ( is_wp_error( $id ) ) {
						return $id;
					}
					Batches::add_owned( $batch, 'attachments', (int) $id );
					++$cursor['index'];
				}
				if ( $cursor['index'] >= $wanted ) {
					$cursor['phase'] = 'posts';
					$cursor['index'] = 0;
				}
				break;

			case 'posts':
				$per_step = max( 1, (int) Config::get( 'items_per_step' ) );
				$end      = min( (int) $config['count'], (int) $cursor['index'] + $per_step );
				wp_defer_term_counting( true );
				try {
					for ( $i = (int) $cursor['index']; $i < $end; $i++ ) {
						$id = self::create_item( $batch, $builder, $i, $cursor['ids'] );
						if ( is_wp_error( $id ) ) {
							return $id;
						}
						$cursor['ids'][ (string) $i ] = $id;
						$cursor['index']              = $i + 1;
					}
				} finally {
					wp_defer_term_counting( false );
				}
				if ( $cursor['index'] >= (int) $config['count'] ) {
					$cursor['phase'] = 'finalize';
				}
				break;

			case 'finalize':
				$report = array(
					'job'      => $job['uuid'],
					'type'     => 'generate',
					'user'     => get_current_user_id() ? get_current_user_id() : (int) $job['owner_id'],
					'finished' => gmdate( 'c' ),
					'complete' => true,
					'counts'   => array(
						'posts'       => count( $cursor['ids'] ),
						'terms'       => count( Ownership::batch_terms( $batch['uuid'] ) ),
						'attachments' => count( Ownership::batch_posts( $batch['uuid'], 'attachment' ) ),
					),
				);
				Batches::put_report( $batch, $report );
				Batches::set_state( $batch, 'generated' );
				cradle_log_activity( Module::ID, 'batch_generated', sprintf( 'Dummy content batch "%1$s" generated %2$d item(s).', $batch['name'], $report['counts']['posts'] ), array( 'batch' => $batch['uuid'] ) );
				return array(
					'state'         => 'completed',
					'phase'         => 'done',
					'cursor_data'   => $cursor,
					'progress_done' => (int) $config['count'],
				);
		}

		return array(
			'state'         => 'running',
			'phase'         => (string) $cursor['phase'],
			'cursor_data'   => $cursor,
			'progress_done' => 'posts' === $cursor['phase'] || 'finalize' === $cursor['phase'] ? (int) ( 'finalize' === $cursor['phase'] ? $config['count'] : $cursor['index'] ) : 0,
		);
	}

	/**
	 * Creates the batch's own terms (bounded, idempotent). Names never claim an existing term.
	 */
	private static function create_terms( array $batch, Content_Builder $builder ) {
		foreach ( (array) ( $batch['config']['taxonomies'] ?? array() ) as $taxonomy => $rules ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			for ( $k = 0; $k < (int) $rules['generate']; $k++ ) {
				$index = 'term:' . $taxonomy . ':' . $k;
				$found = Ownership::find_term( $batch['uuid'], $index );
				if ( $found ) {
					Batches::add_owned( $batch, 'terms', array( 'id' => $found, 'taxonomy' => $taxonomy ) );
					continue;
				}
				for ( $attempt = 0; $attempt < 5; $attempt++ ) {
					$name = $builder->term_name( $taxonomy, $k, $attempt );
					if ( term_exists( $name, $taxonomy ) ) {
						continue; // Pre-existing (or another batch's) term: never claim it.
					}
					$term = wp_insert_term( $name, $taxonomy );
					if ( is_wp_error( $term ) ) {
						continue;
					}
					foreach ( Ownership::markers( $batch['uuid'], $index, (int) $batch['owner'] ) as $key => $value ) {
						add_term_meta( (int) $term['term_id'], $key, $value, true );
					}
					Batches::add_owned(
						$batch,
						'terms',
						array(
							'id'       => (int) $term['term_id'],
							'taxonomy' => $taxonomy,
						)
					);
					break;
				}
			}
		}
	}

	/**
	 * Creates one item (or returns the one already created for this index).
	 *
	 * @param array $ids Map of index => post ID created so far.
	 * @return int|\WP_Error
	 */
	private static function create_item( array $batch, Content_Builder $builder, int $i, array $ids ) {
		$config   = $batch['config'];
		$existing = Ownership::find_post( $batch['uuid'], 'post:' . $i );
		if ( $existing ) {
			return $existing;
		}
		$lookup = static function ( int $index ) use ( $batch, $ids ) {
			return (int) ( $ids[ (string) $index ] ?? Ownership::find_post( $batch['uuid'], 'post:' . $index ) );
		};

		$meta = Ownership::markers( $batch['uuid'], 'post:' . $i, (int) $batch['owner'] );
		foreach ( (array) ( $config['meta'] ?? array() ) as $key => $type ) {
			$meta[ $key ] = $builder->meta_value( $i, (string) $key, (string) $type );
		}
		$postarr = array(
			'post_type'      => $config['post_type'],
			'post_status'    => $config['status'],
			'post_title'     => $builder->title( $i ),
			'post_content'   => $builder->body( $i ),
			'post_excerpt'   => $builder->excerpt( $i ),
			'post_author'    => $builder->author( $i ),
			'comment_status' => get_default_comment_status( $config['post_type'] ),
			'ping_status'    => 'closed',
			'meta_input'     => $meta,
		);
		$date = $builder->date( $i );
		if ( '' !== $date ) {
			$postarr['post_date']     = $date;
			$postarr['post_date_gmt'] = get_gmt_from_date( $date );
		}
		if ( ! empty( $config['relationships']['parent'] ) && is_post_type_hierarchical( $config['post_type'] ) ) {
			$parent_index = $builder->parent_index( $i );
			if ( $parent_index >= 0 ) {
				$postarr['post_parent'] = $lookup( $parent_index );
			}
		}

		// No pingbacks, enclosures or update-service pings as a side effect of publishing samples.
		$had_hook = has_action( 'publish_post', '_publish_post_hook' );
		if ( false !== $had_hook ) {
			remove_action( 'publish_post', '_publish_post_hook', $had_hook );
		}
		try {
			$id = wp_insert_post( wp_slash( $postarr ), true );
		} finally {
			if ( false !== $had_hook ) {
				add_action( 'publish_post', '_publish_post_hook', $had_hook, 1 );
			}
		}
		if ( is_wp_error( $id ) ) {
			return new \WP_Error( 'cradle_dummy_insert', 'An item could not be created: ' . $id->get_error_message() );
		}
		$id = (int) $id;

		// Terms: batch-generated terms plus referenced existing terms.
		foreach ( (array) ( $config['taxonomies'] ?? array() ) as $taxonomy => $rules ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$pool = array_map( 'intval', (array) $rules['existing'] );
			foreach ( Ownership::batch_terms( $batch['uuid'] ) as $term ) {
				if ( $term->taxonomy === $taxonomy ) {
					$pool[] = (int) $term->term_id;
				}
			}
			$chosen = $builder->terms( $i, $taxonomy, array_values( array_unique( $pool ) ), (int) $rules['per_item'] );
			if ( $chosen ) {
				wp_set_object_terms( $id, $chosen, $taxonomy );
			}
		}

		// Featured image from the approved local source.
		$images = $config['images'] ?? array( 'mode' => 'none' );
		$pool   = array();
		if ( 'library' === $images['mode'] ) {
			$pool = (array) $images['library'];
		} elseif ( 'placeholder' === $images['mode'] ) {
			$pool = wp_list_pluck( Ownership::batch_posts( $batch['uuid'], 'attachment' ), 'ID' );
		}
		$image = $builder->pick_image( $i, array_map( 'intval', $pool ) );
		if ( $image && 'attachment' === get_post_type( $image ) ) {
			set_post_thumbnail( $id, $image );
		}

		// Related items (earlier items of the same batch).
		if ( ! empty( $config['relationships']['meta_key'] ) ) {
			$related = array_values( array_filter( array_map( $lookup, $builder->related_indexes( $i ) ) ) );
			if ( $related ) {
				update_post_meta( $id, $config['relationships']['meta_key'], $related );
			}
		}

		delete_post_meta( $id, '_pingme' );
		delete_post_meta( $id, '_encloseme' );
		Ownership::store_hash( $id );
		return $id;
	}
}
