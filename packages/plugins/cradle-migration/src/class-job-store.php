<?php
/**
 * Small, atomic job-row transitions that core Jobs does not expose (core API gap).
 *
 * Core Jobs can claim runnable jobs and commit under a lease, but has no way to move
 * a job out of `paused`/`awaiting_cutover` after an operator decision, or to record
 * an operator rollback on a terminal job. These helpers use single conditional
 * UPDATE statements on the core jobs table (affected-row count decides), and bump
 * the fencing generation so any stale worker can no longer commit.
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

use Cradle\Core\Schema;

defined( 'ABSPATH' ) || exit;

final class Job_Store {

	const TYPES = array( 'migration/export', 'migration/import' );

	/**
	 * Moves a waiting job back to running with a new phase (and optional payload/cursor).
	 */
	public static function resume( string $uuid, array $from_states, string $phase, ?array $payload = null, ?array $cursor = null ): bool {
		global $wpdb;
		$table  = Schema::tables()['jobs'];
		$states = array_values( array_intersect( $from_states, array( 'paused', 'awaiting_cutover', 'running', 'failed' ) ) );
		if ( ! $states ) {
			return false;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$set = array( "state = 'running'", 'phase = ' . Db::quote( $phase ), 'fencing = fencing + 1', 'lease_owner = NULL', 'lease_expires = NULL', "error_code = ''", 'error_message = NULL', 'retries = 0', 'updated_at = ' . Db::quote( $now ) );
		if ( null !== $payload ) {
			$set[] = 'payload = ' . Db::quote( (string) wp_json_encode( $payload ) );
		}
		if ( null !== $cursor ) {
			$set[] = 'cursor_data = ' . Db::quote( (string) wp_json_encode( $cursor ) );
		}
		$in  = "'" . implode( "','", $states ) . "'";
		$sql = 'UPDATE ' . Db::ident( $table ) . ' SET ' . implode( ', ', $set ) . ' WHERE uuid = ' . Db::quote( $uuid ) . " AND state IN ({$in}) AND module = 'migration' AND (lease_owner IS NULL OR lease_expires < " . Db::quote( $now ) . ')';
		$ok  = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers validated, values quoted.
		return 1 === $ok;
	}

	/**
	 * Records a terminal outcome decided outside a runner (operator rollback, recovery).
	 */
	public static function set_state( string $uuid, string $state, string $phase, string $error = '' ): bool {
		global $wpdb;
		if ( ! in_array( $state, array( 'failed', 'rolled_back', 'completed', 'cancelled', 'running' ), true ) ) {
			return false;
		}
		$table = Schema::tables()['jobs'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->update(
			$table,
			array(
				'state'         => $state,
				'phase'         => $phase,
				'error_message' => '' === $error ? null : \Cradle\Core\Activity::redact_string( $error ),
				'lease_owner'   => null,
				'lease_expires' => null,
				'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'uuid'   => $uuid,
				'module' => 'migration',
			)
		);
		if ( $ok ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::ident( $table ) . ' SET fencing = fencing + 1 WHERE uuid = %s', $uuid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return false !== $ok;
	}

	/**
	 * Non-terminal migration jobs (optionally excluding one).
	 *
	 * @return array<int,array>
	 */
	public static function active( string $exclude = '' ): array {
		global $wpdb;
		$table = Schema::tables()['jobs'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT uuid, type, state, phase, owner_id, updated_at FROM ' . Db::ident( $table ) . " WHERE module = 'migration' AND state NOT IN ('completed','failed','cancelled','rolled_back') AND uuid <> %s ORDER BY id DESC LIMIT 20", $exclude ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $rows ?: array();
	}

	/**
	 * Forces a lease to look expired (fault-injection tests and operator recovery after a crashed worker).
	 */
	public static function expire_lease( string $uuid ): bool {
		global $wpdb;
		$table = Schema::tables()['jobs'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::ident( $table ) . ' SET lease_expires = %s WHERE uuid = %s AND lease_owner IS NOT NULL', gmdate( 'Y-m-d H:i:s', time() - 5 ), $uuid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function get( string $uuid ) {
		if ( ! wp_is_uuid( $uuid, 4 ) || ! function_exists( 'cradle' ) || ! cradle() ) {
			return null;
		}
		$job = cradle()->jobs()->get( $uuid );
		return ( $job && in_array( $job['type'], self::TYPES, true ) ) ? $job : null;
	}
}
