<?php
/**
 * Affected-record counts for previews, and the bounded batch job that moves stored content
 * when a key is renamed, a post type is converted, or field storage is renamed or removed.
 *
 * Job type `content/migrate` (core Jobs): payload `ops` is a list of operations processed in
 * order, each in batches of `migration_batch_size` records per step. Every step re-queries the
 * records still carrying the old value, so repeating a step after an interruption is safe.
 *
 * Operations:
 * - post_type   { from, to }                 posts.post_type from → to (set_post_type()).
 * - taxonomy    { from, to }                 term_taxonomy.taxonomy from → to (+ default term option).
 * - menus       { from, to }                 nav menu items' _menu_item_object from → to.
 * - meta_rename { object, from, to, subtypes } meta_key from → to for objects of the subtypes.
 * - meta_delete { object, key, subtypes }    delete_metadata_by_mid() for the key.
 *
 * @package Cradle\Content
 */

namespace Cradle\Content;

defined( 'ABSPATH' ) || exit;

final class Migrations {

	const JOB_TYPE  = 'content/migrate';
	const CRON_HOOK = 'cradle_content_run_job';
	const OPS       = array( 'post_type', 'taxonomy', 'menus', 'meta_rename', 'meta_delete' );

	public static function register( $core ) {
		$core->jobs()->register_type(
			self::JOB_TYPE,
			array(
				'capability'    => Module::CAP,
				'step'          => array( __CLASS__, 'step' ),
				'max_retries'   => 3,
				'lease_seconds' => 60,
				'time_budget'   => 8,
			)
		);
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_run' ) );
	}

	/* ---------------------------------------------------------------------
	 * Counts (admin-triggered previews only)
	 * ------------------------------------------------------------------- */

	/**
	 * @return array<string,int> Post counts by status (revisions and auto-drafts included for completeness).
	 */
	public static function post_counts( string $post_type ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin preview count.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_status, COUNT(*) AS c FROM {$wpdb->posts} WHERE post_type = %s GROUP BY post_status", $post_type ), ARRAY_A );
		$out  = array();
		foreach ( $rows ?: array() as $row ) {
			$out[ $row['post_status'] ] = (int) $row['c'];
		}
		return $out;
	}

	public static function post_total( string $post_type ): int {
		return array_sum( self::post_counts( $post_type ) );
	}

	public static function term_count( string $taxonomy ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy ) );
	}

	public static function relationship_count( string $taxonomy ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s", $taxonomy ) );
	}

	public static function menu_item_count( string $key ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_menu_item_object' AND meta_value = %s", $key ) );
	}

	/**
	 * Posts of $from whose slug already exists in $to (conversion may create duplicate URLs).
	 */
	public static function slug_collisions( string $from, string $to ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} a INNER JOIN {$wpdb->posts} b ON b.post_name = a.post_name AND b.post_type = %s WHERE a.post_type = %s AND a.post_name <> ''", $to, $from ) );
	}

	/**
	 * Number of stored meta rows for a field key on objects of the given subtypes.
	 *
	 * @param string   $object   post|term.
	 * @param string   $meta_key Meta key.
	 * @param string[] $subtypes Post types or taxonomies.
	 */
	public static function meta_count( string $object, string $meta_key, array $subtypes ): int {
		global $wpdb;
		$subtypes = self::safe_keys( $subtypes );
		if ( ! $subtypes ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $subtypes ), '%s' ) );
		if ( 'term' === $object ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in holds only placeholders.
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id WHERE tm.meta_key = %s AND tt.taxonomy IN ({$in})", $meta_key, ...$subtypes ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in holds only placeholders.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type IN ({$in})", $meta_key, ...$subtypes ) );
	}

	/**
	 * Keys safe to use as SQL values in IN() lists (already allowlisted by the validator; re-checked).
	 */
	private static function safe_keys( array $keys ): array {
		return array_values( array_filter( array_unique( $keys ), static fn( $k ) => is_string( $k ) && preg_match( '/^[a-z0-9_-]{1,32}$/', $k ) ) );
	}

	/**
	 * Definitions (database and code) that reference a post type or taxonomy key.
	 *
	 * @return array{db:string[],code:string[]}
	 */
	public static function references( string $kind, string $key ): array {
		$out  = array(
			'db'   => array(),
			'code' => array(),
		);
		$defs = Definitions::instance();
		foreach ( array( 'db', 'code' ) as $source ) {
			foreach ( Definitions::KINDS as $other_kind ) {
				$list = 'db' === $source ? $defs->db( $other_kind ) : $defs->code( $other_kind );
				foreach ( $list as $other_key => $def ) {
					$hit = false;
					if ( 'post_type' === $kind && 'taxonomy' === $other_kind ) {
						$hit = in_array( $key, (array) $def['object_types'], true );
					} elseif ( 'taxonomy' === $kind && 'post_type' === $other_kind ) {
						$hit = in_array( $key, (array) $def['taxonomies'], true );
					} elseif ( 'field_group' === $other_kind ) {
						$object = 'post_type' === $kind ? 'post' : 'term';
						$hit    = ( $def['object'] ?? 'post' ) === $object && in_array( $key, (array) $def['targets'], true );
						foreach ( (array) $def['fields'] as $field ) {
							$hit = $hit || ( ( 'post_type' === $kind ? 'post_ref' : 'term_ref' ) === $field['type'] && in_array( $key, (array) ( $field['targets'] ?? array() ), true ) );
						}
					}
					if ( $hit ) {
						$out[ $source ][] = Definitions::kind_label( $other_kind ) . ' ' . $other_key;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Impact of renaming a post type or taxonomy key (read-only).
	 */
	public static function rename_preview( string $kind, string $from, string $to ): array {
		if ( 'post_type' === $kind ) {
			$records  = self::post_counts( $from );
			$existing = self::post_total( $to );
		} else {
			$records  = array( 'terms' => self::term_count( $from ) );
			$existing = self::term_count( $to );
		}
		return array(
			'records'       => $records,
			'total'         => array_sum( $records ),
			'relationships' => 'taxonomy' === $kind ? self::relationship_count( $from ) : 0,
			'menu_items'    => self::menu_item_count( $from ),
			'existing'      => $existing,
			'references'    => self::references( $kind, $from ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Jobs
	 * ------------------------------------------------------------------- */

	/**
	 * Validates operations, creates the job, runs its first bounded steps and schedules a cron fallback.
	 *
	 * @param array  $ops   Operations.
	 * @param string $label Human label for the migrations list.
	 * @return array|\WP_Error Job row.
	 */
	public static function start( array $ops, string $label ) {
		$core = cradle();
		if ( ! $core ) {
			return new \WP_Error( 'cradle_content_core', __( 'Cradle core is not available.', 'cradle-content' ) );
		}
		$clean = array();
		$total = 0;
		$keys  = array();
		foreach ( $ops as $op ) {
			$op = self::validate_op( $op );
			if ( is_wp_error( $op ) ) {
				return $op;
			}
			$clean[] = $op;
			$total  += self::op_count( $op );
			$keys    = array_merge( $keys, self::op_keys( $op ) );
		}
		if ( ! $clean ) {
			return new \WP_Error( 'cradle_content_no_ops', __( 'Nothing to migrate.', 'cradle-content' ) );
		}
		foreach ( $core->jobs()->recent( Module::ID, 50 ) as $job ) {
			if ( self::JOB_TYPE !== $job['type'] || in_array( $job['state'], \Cradle\Core\Jobs::TERMINAL_STATES, true ) ) {
				continue;
			}
			$busy = array();
			foreach ( (array) ( $job['payload']['ops'] ?? array() ) as $running_op ) {
				$busy = array_merge( $busy, self::op_keys( (array) $running_op ) );
			}
			if ( array_intersect( $busy, $keys ) ) {
				return new \WP_Error( 'cradle_content_migration_running', __( 'Another migration of the same content is still running. Let it finish (Migrations tab) before starting a new one.', 'cradle-content' ) );
			}
		}
		$job = $core->jobs()->create(
			self::JOB_TYPE,
			array(
				'ops'   => $clean,
				'label' => sanitize_text_field( $label ),
			),
			array(
				'total'  => max( 1, $total ),
				'cursor' => array( 'op' => 0 ),
			)
		);
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		cradle_log_activity( Module::ID, 'migration_started', sprintf( 'Migration started: %s', $label ), array( 'job' => $job['uuid'] ) );
		$job = $core->jobs()->run( $job['uuid'] );
		if ( is_array( $job ) && ! in_array( $job['state'], \Cradle\Core\Jobs::TERMINAL_STATES, true ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK, array( $job['uuid'] ) );
		}
		return $job;
	}

	/**
	 * Cron fallback runner so a migration finishes even when nobody keeps the progress screen open.
	 *
	 * @param string $uuid Job UUID.
	 */
	public static function cron_run( $uuid ) {
		$core = function_exists( 'cradle' ) ? cradle() : null;
		if ( ! $core || ! is_string( $uuid ) || ! wp_is_uuid( $uuid, 4 ) ) {
			return;
		}
		$job = $core->jobs()->get( $uuid );
		if ( ! $job || self::JOB_TYPE !== $job['type'] ) {
			return;
		}
		$job = $core->jobs()->run( $uuid );
		if ( is_array( $job ) && ! in_array( $job['state'], \Cradle\Core\Jobs::TERMINAL_STATES, true ) && (int) $job['retries'] <= (int) $job['max_retries'] ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK, array( $uuid ) );
		}
	}

	/**
	 * @return array|\WP_Error
	 */
	private static function validate_op( array $op ) {
		$name = $op['op'] ?? '';
		if ( ! in_array( $name, self::OPS, true ) ) {
			return new \WP_Error( 'cradle_content_op', 'Unknown migration operation.' );
		}
		$key_pattern = '/^[a-z0-9_-]{1,32}$/';
		switch ( $name ) {
			case 'post_type':
			case 'taxonomy':
			case 'menus':
				if ( ! preg_match( $key_pattern, (string) ( $op['from'] ?? '' ) ) || ! preg_match( $key_pattern, (string) ( $op['to'] ?? '' ) ) || $op['from'] === $op['to'] ) {
					return new \WP_Error( 'cradle_content_op', 'Invalid migration keys.' );
				}
				return array(
					'op'   => $name,
					'from' => $op['from'],
					'to'   => $op['to'],
				);
			case 'meta_rename':
				if ( ! preg_match( '/^' . preg_quote( Field_Types::META_PREFIX, '/' ) . '[a-z][a-z0-9_]{0,39}$/', (string) ( $op['from'] ?? '' ) ) || ! preg_match( '/^' . preg_quote( Field_Types::META_PREFIX, '/' ) . '[a-z][a-z0-9_]{0,39}$/', (string) ( $op['to'] ?? '' ) ) ) {
					return new \WP_Error( 'cradle_content_op', 'Invalid meta keys.' );
				}
				return array(
					'op'       => $name,
					'object'   => 'term' === ( $op['object'] ?? '' ) ? 'term' : 'post',
					'from'     => $op['from'],
					'to'       => $op['to'],
					'subtypes' => self::safe_keys( (array) ( $op['subtypes'] ?? array() ) ),
				);
			case 'meta_delete':
				if ( ! preg_match( '/^' . preg_quote( Field_Types::META_PREFIX, '/' ) . '[a-z][a-z0-9_]{0,39}$/', (string) ( $op['key'] ?? '' ) ) ) {
					return new \WP_Error( 'cradle_content_op', 'Invalid meta key.' );
				}
				return array(
					'op'       => $name,
					'object'   => 'term' === ( $op['object'] ?? '' ) ? 'term' : 'post',
					'key'      => $op['key'],
					'subtypes' => self::safe_keys( (array) ( $op['subtypes'] ?? array() ) ),
				);
		}
		return new \WP_Error( 'cradle_content_op', 'Unknown migration operation.' );
	}

	private static function op_keys( array $op ): array {
		switch ( $op['op'] ?? '' ) {
			case 'post_type':
			case 'taxonomy':
			case 'menus':
				return array( 'k:' . ( $op['from'] ?? '' ), 'k:' . ( $op['to'] ?? '' ) );
			case 'meta_rename':
				return array( 'm:' . ( $op['from'] ?? '' ), 'm:' . ( $op['to'] ?? '' ) );
			case 'meta_delete':
				return array( 'm:' . ( $op['key'] ?? '' ) );
		}
		return array();
	}

	private static function op_count( array $op ): int {
		switch ( $op['op'] ) {
			case 'post_type':
				return self::post_total( $op['from'] );
			case 'taxonomy':
				return self::term_count( $op['from'] );
			case 'menus':
				return self::menu_item_count( $op['from'] );
			case 'meta_rename':
				return self::meta_count( $op['object'], $op['from'], $op['subtypes'] );
			case 'meta_delete':
				return self::meta_count( $op['object'], $op['key'], $op['subtypes'] );
		}
		return 0;
	}

	public static function op_label( array $op ): string {
		switch ( $op['op'] ?? '' ) {
			case 'post_type':
				/* translators: 1: old key, 2: new key */
				return sprintf( __( 'Moving posts from "%1$s" to "%2$s"', 'cradle-content' ), $op['from'], $op['to'] );
			case 'taxonomy':
				/* translators: 1: old key, 2: new key */
				return sprintf( __( 'Moving terms from "%1$s" to "%2$s"', 'cradle-content' ), $op['from'], $op['to'] );
			case 'menus':
				return __( 'Updating navigation menu items', 'cradle-content' );
			case 'meta_rename':
				/* translators: 1: old key, 2: new key */
				return sprintf( __( 'Renaming field storage "%1$s" to "%2$s"', 'cradle-content' ), $op['from'], $op['to'] );
			case 'meta_delete':
				/* translators: %s: meta key */
				return sprintf( __( 'Deleting stored values of "%s"', 'cradle-content' ), $op['key'] );
		}
		return '';
	}

	/**
	 * Job step: processes one batch of the current operation.
	 *
	 * @param array             $job  Claimed job.
	 * @param \Cradle\Core\Jobs $jobs Jobs service.
	 * @return array|\WP_Error Changes to commit.
	 */
	public static function step( array $job, $jobs ) {
		$ops   = (array) ( $job['payload']['ops'] ?? array() );
		$index = (int) ( $job['cursor_data']['op'] ?? 0 );
		if ( $index >= count( $ops ) ) {
			return self::complete( $job );
		}
		$op = self::validate_op( (array) $ops[ $index ] );
		if ( is_wp_error( $op ) ) {
			return new \WP_Error( $op->get_error_code(), $op->get_error_message(), 'fatal' );
		}
		$batch = (int) Settings_Section::get( 'migration_batch_size', 100 );
		$batch = max( 10, min( 500, $batch ) );
		$moved = self::run_op( $op, $batch );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}
		$next = $moved < $batch ? $index + 1 : $index;
		if ( $next !== $index ) {
			self::after_op( $op );
		}
		$changes = array(
			'progress_done' => min( (int) $job['progress_total'], (int) $job['progress_done'] + $moved ),
			'cursor_data'   => array( 'op' => $next ),
			'phase'         => $next < count( $ops ) ? self::op_label( (array) $ops[ $next ] ) : __( 'Finishing', 'cradle-content' ),
			'state'         => 'running',
		);
		if ( $next >= count( $ops ) ) {
			return array_merge( $changes, self::complete( $job ) );
		}
		return $changes;
	}

	private static function complete( array $job ): array {
		cradle_log_activity( Module::ID, 'migration_completed', sprintf( 'Migration completed: %s', (string) ( $job['payload']['label'] ?? '' ) ), array( 'job' => $job['uuid'] ) );
		return array(
			'state'         => 'completed',
			'phase'         => __( 'Completed', 'cradle-content' ),
			'progress_done' => (int) $job['progress_total'],
		);
	}

	/**
	 * @return int|\WP_Error Records processed.
	 */
	private static function run_op( array $op, int $batch ) {
		global $wpdb;
		switch ( $op['op'] ) {
			case 'post_type':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID LIMIT %d", $op['from'], $batch ) );
				foreach ( $ids as $id ) {
					set_post_type( (int) $id, $op['to'] );
				}
				return count( $ids );

			case 'taxonomy':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id, term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s ORDER BY term_taxonomy_id LIMIT %d", $op['from'], $batch ) );
				foreach ( $rows as $row ) {
					// There is no core API that changes a term's taxonomy; update the row and clear caches.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => $op['to'] ), array( 'term_taxonomy_id' => (int) $row->term_taxonomy_id ), array( '%s' ), array( '%d' ) );
					wp_cache_delete( (int) $row->term_id, 'terms' );
				}
				if ( $rows ) {
					clean_taxonomy_cache( $op['from'] );
					clean_taxonomy_cache( $op['to'] );
					wp_cache_set_terms_last_changed();
				}
				return count( $rows );

			case 'menus':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'nav_menu_item' WHERE pm.meta_key = '_menu_item_object' AND pm.meta_value = %s LIMIT %d", $op['from'], $batch ) );
				foreach ( $ids as $id ) {
					update_post_meta( (int) $id, '_menu_item_object', $op['to'] );
				}
				return count( $ids );

			case 'meta_rename':
			case 'meta_delete':
				$key = 'meta_rename' === $op['op'] ? $op['from'] : $op['key'];
				if ( ! $op['subtypes'] ) {
					return 0;
				}
				$in = implode( ',', array_fill( 0, count( $op['subtypes'] ), '%s' ) );
				if ( 'term' === $op['object'] ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in holds only placeholders.
					$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT tm.meta_id AS mid, tm.term_id AS oid FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id WHERE tm.meta_key = %s AND tt.taxonomy IN ({$in}) ORDER BY tm.meta_id LIMIT %d", $key, ...array_merge( $op['subtypes'], array( $batch ) ) ) );
					$table = $wpdb->termmeta;
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in holds only placeholders.
					$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_id AS mid, pm.post_id AS oid FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type IN ({$in}) ORDER BY pm.meta_id LIMIT %d", $key, ...array_merge( $op['subtypes'], array( $batch ) ) ) );
					$table = $wpdb->postmeta;
				}
				foreach ( $rows as $row ) {
					if ( 'meta_delete' === $op['op'] ) {
						delete_metadata_by_mid( $op['object'], (int) $row->mid );
						continue;
					}
					// Renaming a meta key has no dedicated core API; update the row by primary key and clear the object's meta cache.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update( $table, array( 'meta_key' => $op['to'] ), array( 'meta_id' => (int) $row->mid ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					wp_cache_delete( (int) $row->oid, $op['object'] . '_meta' );
				}
				return count( $rows );
		}
		return 0;
	}

	/**
	 * Finishing touches after an operation completed.
	 */
	private static function after_op( array $op ) {
		if ( 'taxonomy' === $op['op'] ) {
			$default = get_option( 'default_term_' . $op['from'], null );
			if ( null !== $default ) {
				update_option( 'default_term_' . $op['to'], (int) $default );
				delete_option( 'default_term_' . $op['from'] );
			}
		}
	}
}
