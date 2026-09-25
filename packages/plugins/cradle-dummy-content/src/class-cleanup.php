<?php
/**
 * Cleanup preview and the bounded, resumable `dummy-content/cleanup` job step.
 *
 * Scope is proven only by ownership markers. Default mode moves generated
 * content to the trash; "purge" permanently deletes it with WordPress
 * deletion APIs (revisions, meta, relationships and files included).
 * Items edited since generation (content hash mismatch, including copies
 * that inherited markers) and items still in use (menus, front page) are kept
 * unless explicitly included. Owned terms and attachments are deleted only on
 * purge and only when nothing outside the deleted items still uses them.
 * Pre-existing media or terms that were merely selected carry no markers and
 * are never touched.
 *
 * @package Cradle\Dummy_Content
 */

namespace Cradle\Dummy_Content;

defined( 'ABSPATH' ) || exit;

final class Cleanup {

	const JOB_TYPE = 'dummy-content/cleanup';
	const MODES    = array( 'trash', 'purge' );

	/**
	 * Counts by entity type for one batch, with edited/in-use flags. Read-only.
	 */
	public static function preview( string $batch ): array {
		$posts = array(
			'total'     => 0,
			'by_type'   => array(),
			'by_status' => array(),
			'modified'  => array(),
			'in_use'    => array(),
		);
		$after = 0;
		do {
			$rows = Ownership::batch_posts( $batch, 'content', $after, 200 );
			foreach ( $rows as $row ) {
				$after = (int) $row->ID;
				++$posts['total'];
				$posts['by_type'][ $row->post_type ]     = ( $posts['by_type'][ $row->post_type ] ?? 0 ) + 1;
				$posts['by_status'][ $row->post_status ] = ( $posts['by_status'][ $row->post_status ] ?? 0 ) + 1;
				if ( Ownership::is_modified( $row, (string) $row->hash ) ) {
					$posts['modified'][] = (int) $row->ID;
				} elseif ( self::post_in_use( (int) $row->ID ) ) {
					$posts['in_use'][] = (int) $row->ID;
				}
			}
		} while ( count( $rows ) === 200 );

		$terms = array(
			'total'  => 0,
			'shared' => array(),
		);
		foreach ( Ownership::batch_terms( $batch ) as $term ) {
			++$terms['total'];
			if ( self::term_usage( $term, $batch ) > 0 ) {
				$terms['shared'][] = (int) $term->term_id;
			}
		}

		$attachments = array(
			'total'  => 0,
			'in_use' => array(),
		);
		foreach ( Ownership::batch_posts( $batch, 'attachment' ) as $row ) {
			++$attachments['total'];
			if ( self::attachment_usage( (int) $row->ID, $batch ) > 0 ) {
				$attachments['in_use'][] = (int) $row->ID;
			}
		}

		$record = Batches::get( $batch );
		return array(
			'posts'       => $posts,
			'terms'       => $terms,
			'attachments' => $attachments,
			'referenced'  => array(
				'attachments' => $record ? count( (array) $record['referenced']['attachments'] ) : 0,
				'terms'       => $record ? count( (array) $record['referenced']['terms'] ) : 0,
			),
		);
	}

	/**
	 * A generated post is "in use" when a menu item points at it or it is the front/posts page.
	 */
	public static function post_in_use( int $post_id ): bool {
		global $wpdb;
		if ( (int) get_option( 'page_on_front' ) === $post_id || (int) get_option( 'page_for_posts' ) === $post_id ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->postmeta} o INNER JOIN {$wpdb->postmeta} t ON t.post_id = o.post_id AND t.meta_key = '_menu_item_type' AND t.meta_value = 'post_type' WHERE o.meta_key = '_menu_item_object_id' AND o.meta_value = %s LIMIT 1",
				(string) $post_id
			)
		);
	}

	/**
	 * Uses of a term outside this batch's generated posts: relationships with other
	 * objects, child terms not owned by the batch, and menu items.
	 */
	public static function term_usage( object $term, string $batch, bool $exclude_batch_posts = true ): int {
		global $wpdb;
		// While previewing, the batch's own posts do not count; during purge the remaining batch posts
		// are the kept (edited or in-use) ones, so every remaining relationship counts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$objects = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
				WHERE tr.term_taxonomy_id = %d
				AND tr.object_id NOT IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s )",
				(int) $term->term_taxonomy_id,
				Ownership::BATCH,
				$exclude_batch_posts ? $batch : ''
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$children = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} tt
				WHERE tt.parent = %d AND tt.taxonomy = %s
				AND tt.term_id NOT IN ( SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s )",
				(int) $term->term_id,
				(string) $term->taxonomy,
				Ownership::BATCH,
				$batch
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$menus = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} o INNER JOIN {$wpdb->postmeta} t ON t.post_id = o.post_id AND t.meta_key = '_menu_item_type' AND t.meta_value = 'taxonomy' WHERE o.meta_key = '_menu_item_object_id' AND o.meta_value = %s",
				(string) $term->term_id
			)
		);
		return $objects + $children + $menus;
	}

	/**
	 * Uses of an attachment outside this batch: featured image of other posts, block/HTML
	 * references in other posts' content, site logo/icon.
	 */
	public static function attachment_usage( int $attachment_id, string $batch, bool $exclude_batch_posts = true ): int {
		global $wpdb;
		$batch   = $exclude_batch_posts ? $batch : '';
		$exclude = "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$thumbs = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s AND post_id NOT IN ( {$exclude} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) $attachment_id,
				Ownership::BATCH,
				$batch
			)
		);
		$file  = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$like1 = '%' . $wpdb->esc_like( 'wp-image-' . $attachment_id . '"' ) . '%';
		$like2 = '%' . $wpdb->esc_like( '"id":' . $attachment_id . ',' ) . '%';
		$like3 = '' !== $file ? '%' . $wpdb->esc_like( pathinfo( $file, PATHINFO_FILENAME ) ) . '%' : '%cradle-no-file-match%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$content = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s ) AND ID NOT IN ( {$exclude} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$like1,
				$like2,
				$like3,
				Ownership::BATCH,
				$batch
			)
		);
		$options = 0;
		foreach ( array( 'site_icon', 'site_logo' ) as $option ) {
			$options += (int) get_option( $option ) === $attachment_id ? 1 : 0;
		}
		return $thumbs + $content + $options;
	}

	/**
	 * Starts a cleanup job for a batch.
	 *
	 * @return array|\WP_Error Job.
	 */
	public static function start( string $batch_uuid, string $mode, bool $include_modified ) {
		if ( ! current_user_can( Lifecycle::CAP_CLEANUP ) ) {
			return new \WP_Error( 'cradle_dummy_forbidden', __( 'You are not allowed to clean up dummy content.', 'cradle-dummy-content' ), array( 'status' => 403 ) );
		}
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return new \WP_Error( 'cradle_dummy_mode', __( 'Unknown cleanup mode.', 'cradle-dummy-content' ), array( 'status' => 400 ) );
		}
		$in_use = Ownership::batches_in_use();
		if ( ! Batches::get( $batch_uuid ) && ! isset( $in_use[ $batch_uuid ] ) ) {
			return new \WP_Error( 'cradle_dummy_batch_missing', __( 'Unknown batch.', 'cradle-dummy-content' ), array( 'status' => 404 ) );
		}
		$batch = Batches::ensure( $batch_uuid );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}
		$lock = cradle()->locks()->acquire( 'dummy-content:cleanup-start:' . $batch_uuid, 15 );
		if ( ! $lock ) {
			return new \WP_Error( 'cradle_dummy_busy', __( 'A cleanup for this batch is starting. Reload in a moment.', 'cradle-dummy-content' ), array( 'status' => 409 ) );
		}
		try {
			$running = Batches::active_job( $batch_uuid );
			if ( $running ) {
				return new \WP_Error(
					'cradle_dummy_running',
					Generator::JOB_TYPE === $running['type']
						? __( 'Generation of this batch is still running. Let it finish or cancel it first.', 'cradle-dummy-content' )
						: __( 'A cleanup for this batch is already running. Let it finish or cancel it first.', 'cradle-dummy-content' ),
					array( 'status' => 409 )
				);
			}
			$preview = self::preview( $batch_uuid );
			$job     = cradle()->jobs()->create(
				self::JOB_TYPE,
				array(
					'batch'            => $batch_uuid,
					'mode'             => $mode,
					'include_modified' => $include_modified,
				),
				array(
					'total'  => $preview['posts']['total'] + ( 'purge' === $mode ? $preview['terms']['total'] + $preview['attachments']['total'] : 0 ),
					'phase'  => 'posts',
					'cursor' => array(
						'phase'  => 'posts',
						'after'  => 0,
						'counts' => array(),
						'kept'   => array(),
					),
				)
			);
		} finally {
			cradle()->locks()->release( 'dummy-content:cleanup-start:' . $batch_uuid, $lock );
		}
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		Batches::put_report(
			$batch,
			array(
				'job'              => $job['uuid'],
				'type'             => $mode,
				'include_modified' => $include_modified,
				'user'             => get_current_user_id(),
				'started'          => gmdate( 'c' ),
				'complete'         => false,
				'preview'          => array(
					'posts'          => $preview['posts']['total'],
					'modified'       => count( $preview['posts']['modified'] ),
					'in_use'         => count( $preview['posts']['in_use'] ),
					'terms'          => $preview['terms']['total'],
					'terms_shared'   => count( $preview['terms']['shared'] ),
					'attachments'    => $preview['attachments']['total'],
					'attachments_in' => count( $preview['attachments']['in_use'] ),
				),
				'counts'           => array(),
			)
		);
		cradle_log_activity( Module::ID, 'cleanup_started', sprintf( 'Dummy content cleanup (%1$s) started for batch "%2$s".', $mode, $batch['name'] ), array( 'batch' => $batch_uuid, 'job' => $job['uuid'] ) );
		return $job;
	}

	private static function bump( array &$cursor, string $key, int $id = 0 ) {
		$cursor['counts'][ $key ] = ( $cursor['counts'][ $key ] ?? 0 ) + 1;
		if ( $id && 0 === strpos( $key, 'kept' ) && count( $cursor['kept'] ) < 200 ) {
			$cursor['kept'][] = array( $key, $id );
		}
	}

	/**
	 * Job step callback (core Jobs contract). Idempotent: every phase re-queries markers.
	 *
	 * @return array|\WP_Error
	 */
	public static function step( array $job ) {
		if ( get_current_user_id() && ! current_user_can( Lifecycle::CAP_CLEANUP ) ) {
			return new \WP_Error( 'cradle_dummy_forbidden', 'Not allowed.', 'fatal' );
		}
		$payload = (array) $job['payload'];
		$uuid    = (string) ( $payload['batch'] ?? '' );
		$mode    = in_array( $payload['mode'] ?? '', self::MODES, true ) ? $payload['mode'] : 'trash';
		$include = ! empty( $payload['include_modified'] );
		$batch   = Batches::get( $uuid );
		if ( ! $batch ) {
			return new \WP_Error( 'cradle_dummy_batch_missing', 'The batch record is missing.', 'fatal' );
		}
		$cursor   = (array) $job['cursor_data'] + array(
			'phase'  => 'posts',
			'after'  => 0,
			'counts' => array(),
			'kept'   => array(),
		);
		$per_step = max( 1, (int) Config::get( 'items_per_step' ) );
		$done     = (int) $job['progress_done'];

		switch ( $cursor['phase'] ) {
			case 'posts':
				$rows = Ownership::batch_posts( $uuid, 'content', (int) $cursor['after'], $per_step, 'trash' === $mode ? 'trash' : null );
				foreach ( $rows as $row ) {
					$cursor['after'] = (int) $row->ID;
					++$done;
					if ( ! $include && Ownership::is_modified( $row, (string) $row->hash ) ) {
						self::bump( $cursor, 'kept_modified', (int) $row->ID );
						continue;
					}
					if ( ! $include && self::post_in_use( (int) $row->ID ) ) {
						self::bump( $cursor, 'kept_in_use', (int) $row->ID );
						continue;
					}
					if ( 'trash' === $mode ) {
						if ( ! EMPTY_TRASH_DAYS ) {
							// wp_trash_post() would delete permanently; never do that in trash mode.
							self::bump( $cursor, 'kept_trash_disabled', (int) $row->ID );
							continue;
						}
						if ( wp_trash_post( (int) $row->ID ) ) {
							self::bump( $cursor, 'trashed' );
						} else {
							self::bump( $cursor, 'kept_failed', (int) $row->ID );
						}
					} elseif ( wp_delete_post( (int) $row->ID, true ) ) {
						self::bump( $cursor, 'deleted' );
					} else {
						self::bump( $cursor, 'kept_failed', (int) $row->ID );
					}
				}
				if ( count( $rows ) < $per_step ) {
					$cursor['phase'] = 'purge' === $mode ? 'terms' : 'finalize';
					$cursor['after'] = 0;
				}
				break;

			case 'terms':
				$handled = 0;
				foreach ( Ownership::batch_terms( $uuid ) as $term ) {
					if ( (int) $term->term_id <= (int) $cursor['after'] ) {
						continue;
					}
					if ( $handled >= $per_step ) {
						break;
					}
					++$handled;
					++$done;
					$cursor['after'] = (int) $term->term_id;
					if ( self::term_usage( $term, $uuid, false ) > 0 ) {
						self::bump( $cursor, 'kept_shared_terms', (int) $term->term_id );
						continue;
					}
					$result = wp_delete_term( (int) $term->term_id, $term->taxonomy );
					if ( true === $result ) {
						self::bump( $cursor, 'terms_deleted' );
					} else {
						// Default categories cannot be deleted; keep and report.
						self::bump( $cursor, 'kept_failed_terms', (int) $term->term_id );
					}
				}
				if ( $handled < $per_step ) {
					$cursor['phase'] = 'attachments';
					$cursor['after'] = 0;
				}
				break;

			case 'attachments':
				$rows = Ownership::batch_posts( $uuid, 'attachment', (int) $cursor['after'], $per_step );
				foreach ( $rows as $row ) {
					$cursor['after'] = (int) $row->ID;
					++$done;
					if ( self::attachment_usage( (int) $row->ID, $uuid, false ) > 0 ) {
						self::bump( $cursor, 'kept_attachments_in_use', (int) $row->ID );
						continue;
					}
					if ( wp_delete_attachment( (int) $row->ID, true ) ) {
						self::bump( $cursor, 'attachments_deleted' );
					} else {
						self::bump( $cursor, 'kept_failed_attachments', (int) $row->ID );
					}
				}
				if ( count( $rows ) < $per_step ) {
					$cursor['phase'] = 'finalize';
				}
				break;

			case 'finalize':
				self::write_report( $batch, $job, $cursor, true );
				$left = Ownership::batch_posts( $uuid, 'content', 0, 1 ) || Ownership::batch_terms( $uuid ) || Ownership::batch_posts( $uuid, 'attachment', 0, 1 );
				Batches::set_state( $batch, $left ? ( 'trash' === $mode ? 'trashed' : 'partially_removed' ) : 'removed' );
				cradle_log_activity(
					Module::ID,
					'cleanup_completed',
					sprintf( 'Dummy content cleanup (%1$s) finished for batch "%2$s".', $mode, $batch['name'] ),
					array(
						'batch'  => $uuid,
						'counts' => $cursor['counts'],
					)
				);
				return array(
					'state'         => 'completed',
					'phase'         => 'done',
					'cursor_data'   => $cursor,
					'progress_done' => max( $done, (int) $job['progress_total'] ),
				);
		}

		// Persist the running report after every step so cancellation never loses what was done.
		self::write_report( $batch, $job, $cursor, false );
		return array(
			'state'         => 'running',
			'phase'         => (string) $cursor['phase'],
			'cursor_data'   => $cursor,
			'progress_done' => $done,
		);
	}

	private static function write_report( array $batch, array $job, array $cursor, bool $complete ) {
		$existing = array();
		foreach ( Batches::get( $batch['uuid'] )['reports'] ?? array() as $report ) {
			if ( ( $report['job'] ?? '' ) === $job['uuid'] ) {
				$existing = $report;
			}
		}
		$payload = (array) $job['payload'];
		Batches::put_report(
			$batch,
			array_merge(
				$existing,
				array(
					'job'              => $job['uuid'],
					'type'             => (string) ( $payload['mode'] ?? 'trash' ),
					'include_modified' => ! empty( $payload['include_modified'] ),
					'user'             => $existing['user'] ?? (int) $job['owner_id'],
					'complete'         => $complete,
					'updated'          => gmdate( 'c' ),
					'counts'           => $cursor['counts'],
					'kept'             => array_slice( $cursor['kept'], 0, 200 ),
				)
			)
		);
	}
}
