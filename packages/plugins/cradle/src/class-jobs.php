<?php
/**
 * Durable, bounded background jobs with atomic leases and fencing.
 *
 * Handlers are registered in trusted code per job type. A runner claims a job
 * atomically, runs one bounded step, and commits progress only while its
 * lease and fencing generation are still current. Expired workers cannot
 * commit after another runner has taken over.
 *
 * @package Cradle
 */

namespace Cradle\Core;

defined( 'ABSPATH' ) || exit;

final class Jobs {

	const STATES          = array( 'created', 'preflight', 'running', 'paused', 'awaiting_cutover', 'completed', 'failed', 'cancelled', 'rolled_back' );
	const TERMINAL_STATES = array( 'completed', 'failed', 'cancelled', 'rolled_back' );
	const RUNNABLE_STATES = array( 'created', 'preflight', 'running' );

	/** @var array<string,array> Job type definitions keyed by type. */
	private $types = array();

	public function hooks() {
		add_action( 'wp_ajax_cradle_job_step', array( $this, 'ajax_step' ) );
		add_action( 'wp_ajax_cradle_job_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_cradle_job_cancel', array( $this, 'ajax_cancel' ) );
	}

	/**
	 * Registers a job type.
	 *
	 * @param string $type       Namespaced type such as `migration/export`.
	 * @param array  $definition module, capability, step (callable(array $job, callable $commit): array), max_retries, lease_seconds.
	 * @return true|\WP_Error
	 */
	public function register_type( string $type, array $definition ) {
		if ( ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $type ) ) {
			return new \WP_Error( 'cradle_job_type', 'Job type must be module/name.' );
		}
		if ( empty( $definition['step'] ) || ! is_callable( $definition['step'] ) || empty( $definition['capability'] ) ) {
			return new \WP_Error( 'cradle_job_definition', 'Job types need a step callable and a capability.' );
		}
		$this->types[ $type ] = wp_parse_args(
			$definition,
			array(
				'module'        => strtok( $type, '/' ),
				'max_retries'   => 3,
				'lease_seconds' => 60,
				'time_budget'   => 10,
			)
		);
		return true;
	}

	public function type( string $type ) {
		return $this->types[ $type ] ?? null;
	}

	/**
	 * Creates a job owned by the current user.
	 *
	 * @return array|\WP_Error Job row.
	 */
	public function create( string $type, array $payload = array(), array $args = array() ) {
		$def = $this->type( $type );
		if ( ! $def ) {
			return new \WP_Error( 'cradle_job_unknown', 'Unknown job type.' );
		}
		if ( empty( $args['system'] ) && ! current_user_can( $def['capability'] ) ) {
			return new \WP_Error( 'cradle_job_forbidden', __( 'You are not allowed to start this job.', 'cradle' ) );
		}
		global $wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );
		$uuid = wp_generate_uuid4();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			Schema::tables()['jobs'],
			array(
				'uuid'           => $uuid,
				'type'           => $type,
				'module'         => $def['module'],
				'owner_id'       => get_current_user_id(),
				'required_cap'   => $def['capability'],
				'state'          => 'created',
				'phase'          => (string) ( $args['phase'] ?? '' ),
				'cursor_data'    => wp_json_encode( $args['cursor'] ?? new \stdClass() ),
				'completed_units' => '[]',
				'progress_total' => (int) ( $args['total'] ?? 0 ),
				'bytes_total'    => (int) ( $args['bytes_total'] ?? 0 ),
				'max_retries'    => (int) $def['max_retries'],
				'payload'        => wp_json_encode( $payload ),
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		if ( ! $ok ) {
			return new \WP_Error( 'cradle_job_insert', 'Could not create the job.' );
		}
		return $this->get( $uuid );
	}

	/**
	 * @return array|null Decoded job row.
	 */
	public function get( string $uuid ) {
		global $wpdb;
		$table = Schema::tables()['jobs'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE uuid = %s", $uuid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::decode( $row ) : null;
	}

	private static function decode( array $row ): array {
		$row['cursor_data']     = json_decode( (string) $row['cursor_data'], true ) ?: array();
		$row['completed_units'] = json_decode( (string) $row['completed_units'], true ) ?: array();
		$row['payload']         = json_decode( (string) $row['payload'], true ) ?: array();
		foreach ( array( 'id', 'owner_id', 'progress_done', 'progress_total', 'bytes_done', 'bytes_total', 'fencing', 'retries', 'retries_total', 'max_retries' ) as $int ) {
			$row[ $int ] = (int) ( $row[ $int ] ?? 0 );
		}
		return $row;
	}

	/**
	 * Lists recent jobs for a module (bounded).
	 */
	public function recent( string $module, int $limit = 20 ): array {
		global $wpdb;
		$table = Schema::tables()['jobs'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE module = %s ORDER BY id DESC LIMIT %d", $module, min( 100, max( 1, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'decode' ), $rows ?: array() );
	}

	/**
	 * Atomically claims a runnable job. Returns the claimed row (with lease) or null.
	 */
	public function claim( string $uuid, int $lease_seconds = 60 ) {
		global $wpdb;
		$table   = Schema::tables()['jobs'];
		$owner   = wp_generate_uuid4();
		$now     = gmdate( 'Y-m-d H:i:s' );
		$expires = gmdate( 'Y-m-d H:i:s', time() + $lease_seconds );
		$states  = "'" . implode( "','", self::RUNNABLE_STATES ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET lease_owner = %s, lease_expires = %s, fencing = fencing + 1, updated_at = %s WHERE uuid = %s AND state IN ({$states}) AND (lease_owner IS NULL OR lease_expires < %s)", $owner, $expires, $now, $uuid, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== $updated ) {
			return null;
		}
		$job = $this->get( $uuid );
		return ( $job && $job['lease_owner'] === $owner ) ? $job : null;
	}

	/**
	 * Commits progress only when the caller still holds the lease generation.
	 *
	 * @param array $job     Claimed job (lease_owner and fencing are checked).
	 * @param array $changes Columns to update: state, phase, cursor_data, completed_units, progress_done, progress_total, bytes_done, bytes_total, payload, manifest_digest, error_code, error_message.
	 * @param bool  $release Whether to release the lease.
	 */
	public function commit( array $job, array $changes, bool $release = false ): bool {
		global $wpdb;
		$allowed = array( 'state', 'phase', 'cursor_data', 'completed_units', 'progress_done', 'progress_total', 'bytes_done', 'bytes_total', 'payload', 'manifest_digest', 'error_code', 'error_message', 'retries', 'retries_total' );
		$data    = array();
		foreach ( $changes as $column => $value ) {
			if ( ! in_array( $column, $allowed, true ) ) {
				continue;
			}
			if ( in_array( $column, array( 'cursor_data', 'completed_units', 'payload' ), true ) ) {
				$value = wp_json_encode( $value );
			}
			if ( 'state' === $column && ! in_array( $value, self::STATES, true ) ) {
				return false;
			}
			if ( 'error_message' === $column ) {
				$value = Activity::redact_string( (string) $value );
			}
			$data[ $column ] = $value;
		}
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		if ( $release ) {
			$data['lease_owner']   = null;
			$data['lease_expires'] = null;
		}
		$where = array(
			'uuid'        => $job['uuid'],
			'lease_owner' => $job['lease_owner'],
			'fencing'     => $job['fencing'],
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update( Schema::tables()['jobs'], $data, $where );
		if ( 1 === $updated ) {
			return true;
		}
		if ( 0 !== $updated || $release ) {
			return false;
		}
		// MySQL reports 0 affected rows when values are unchanged; confirm the lease is still ours.
		$table = Schema::tables()['jobs'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE uuid = %s AND lease_owner = %s AND fencing = %d", $where['uuid'], $where['lease_owner'], $where['fencing'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Runs bounded steps of a job until its time budget is used. Authorization
	 * must already have been checked by the caller (see ajax_step()).
	 *
	 * @return array|\WP_Error Updated job row.
	 */
	public function run( string $uuid ) {
		$job = $this->get( $uuid );
		if ( ! $job ) {
			return new \WP_Error( 'cradle_job_missing', 'Job not found.' );
		}
		$def = $this->type( $job['type'] );
		if ( ! $def ) {
			return new \WP_Error( 'cradle_job_unknown', 'Job type is not registered. Is its plugin active?' );
		}
		$claimed = $this->claim( $uuid, (int) $def['lease_seconds'] );
		if ( ! $claimed ) {
			return $this->get( $uuid );
		}
		$deadline = microtime( true ) + (float) $def['time_budget'];
		$job      = $claimed;
		do {
			try {
				$result = call_user_func( $def['step'], $job, $this );
			} catch ( \Throwable $e ) {
				$result = new \WP_Error( 'cradle_job_exception', $e->getMessage() );
			}
			if ( is_wp_error( $result ) ) {
				$retries  = $job['retries'] + 1;
				$terminal = $retries > $job['max_retries'] || 'fatal' === $result->get_error_data();
				$this->commit(
					$job,
					array(
						'state'         => $terminal ? 'failed' : $job['state'],
						'retries'       => $retries,
						'retries_total' => $job['retries_total'] + 1,
						'error_code'    => $result->get_error_code(),
						'error_message' => $result->get_error_message(),
					),
					true
				);
				return $this->get( $uuid );
			}
			// Step contract: return the changes to commit; include 'state' => 'completed' when done.
			$changes = (array) $result;
			if ( $job['retries'] > 0 && ! array_key_exists( 'retries', $changes ) ) {
				// A successful step ends a failure streak: max_retries bounds consecutive failures,
				// retries_total keeps the history, and the recovered error no longer describes the job.
				$changes['retries'] = 0;
				$changes           += array(
					'error_code'    => '',
					'error_message' => '',
				);
			}
			if ( ! $this->commit( $job, $changes ) ) {
				// Lease lost: another runner took over; stop without further writes.
				return $this->get( $uuid );
			}
			$job = array_merge( $job, $changes );
			if ( in_array( $job['state'], self::TERMINAL_STATES, true ) || in_array( $job['state'], array( 'paused', 'awaiting_cutover' ), true ) ) {
				break;
			}
		} while ( microtime( true ) < $deadline );

		$this->commit( $job, array(), true );
		return $this->get( $uuid );
	}

	/**
	 * Whether a job may be cancelled now. Job types can declare `cancellable` as a boolean or a
	 * callable( array $job ): bool, for example to refuse cancellation during a cutover.
	 */
	public function is_cancellable( array $job ): bool {
		$def = $this->type( $job['type'] );
		if ( in_array( $job['state'], self::TERMINAL_STATES, true ) ) {
			return false;
		}
		if ( ! $def || ! array_key_exists( 'cancellable', $def ) ) {
			return true;
		}
		return is_callable( $def['cancellable'] ) ? (bool) call_user_func( $def['cancellable'], $job ) : (bool) $def['cancellable'];
	}

	/**
	 * Moves a paused or awaiting_cutover job back to a runnable state (operator decision).
	 * Advances fencing so any stale worker cannot commit.
	 */
	public function resume( string $uuid, string $state = 'running' ): bool {
		global $wpdb;
		if ( ! in_array( $state, self::RUNNABLE_STATES, true ) ) {
			return false;
		}
		$table = Schema::tables()['jobs'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET state = %s, lease_owner = NULL, lease_expires = NULL, fencing = fencing + 1, updated_at = %s WHERE uuid = %s AND state IN ('paused','awaiting_cutover')", $state, gmdate( 'Y-m-d H:i:s' ), $uuid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Cancels a non-terminal job (the step handler is responsible for cleanup on next call).
	 * Returns false when the job type's cancellation policy refuses it.
	 */
	public function cancel( string $uuid ): bool {
		global $wpdb;
		$current = $this->get( $uuid );
		if ( ! $current || ! $this->is_cancellable( $current ) ) {
			return false;
		}
		$table    = Schema::tables()['jobs'];
		$terminal = "'" . implode( "','", self::TERMINAL_STATES ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$cancelled = 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET state = 'cancelled', lease_owner = NULL, lease_expires = NULL, fencing = fencing + 1, updated_at = %s WHERE uuid = %s AND state NOT IN ({$terminal})", gmdate( 'Y-m-d H:i:s' ), $uuid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $cancelled ) {
			$job = $this->get( $uuid );
			/**
			 * A job was cancelled. Handlers may persist reports or release resources; the
			 * fencing counter was advanced so any running step can no longer commit.
			 *
			 * @param array $job Job row after cancellation.
			 */
			do_action( 'cradle/job_cancelled', $job );
		}
		return $cancelled;
	}

	/**
	 * Object-level check: the job's required capability, plus ownership unless the user can manage options.
	 */
	public function user_can_access( array $job ): bool {
		if ( ! current_user_can( $job['required_cap'] ) ) {
			return false;
		}
		return (int) $job['owner_id'] === get_current_user_id() || current_user_can( 'manage_options' );
	}

	private function ajax_job() {
		check_ajax_referer( 'cradle_jobs', 'nonce' );
		$uuid = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '';
		$job  = wp_is_uuid( $uuid, 4 ) ? $this->get( $uuid ) : null;
		if ( ! $job || ! $this->user_can_access( $job ) ) {
			wp_send_json_error( array( 'message' => __( 'Job not found.', 'cradle' ) ), 404 );
		}
		return $job;
	}

	public function ajax_step() {
		$job    = $this->ajax_job();
		$result = $this->run( $job['uuid'] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
		}
		wp_send_json_success( self::public_view( $result ) );
	}

	public function ajax_status() {
		$job = $this->ajax_job();
		wp_send_json_success( self::public_view( $job ) );
	}

	public function ajax_cancel() {
		$job = $this->ajax_job();
		if ( ! $this->cancel( $job['uuid'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This job cannot be cancelled in its current phase.', 'cradle' ) ), 409 );
		}
		cradle_log_activity( $job['module'], 'job_cancelled', sprintf( 'Job %s cancelled.', $job['type'] ), array( 'job' => $job['uuid'] ) );
		wp_send_json_success( self::public_view( $this->get( $job['uuid'] ) ) );
	}

	/**
	 * Progress fields safe to return to the browser (no payload or paths).
	 */
	public static function public_view( array $job ): array {
		return array(
			'uuid'          => $job['uuid'],
			'type'          => $job['type'],
			'state'         => $job['state'],
			'phase'         => $job['phase'],
			'progressDone'  => $job['progress_done'],
			'progressTotal' => $job['progress_total'],
			'bytesDone'     => $job['bytes_done'],
			'bytesTotal'    => $job['bytes_total'],
			'retries'       => $job['retries'],
			'retriesTotal'  => (int) ( $job['retries_total'] ?? 0 ),
			'error'         => $job['error_message'] ? (string) $job['error_message'] : '',
			'updated'       => $job['updated_at'],
			'terminal'      => in_array( $job['state'], self::TERMINAL_STATES, true ),
		);
	}
}
