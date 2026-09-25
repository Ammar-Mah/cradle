<?php
/**
 * Redacted activity records for privileged Cradle operations, with bounded retention.
 *
 * @package Cradle
 */

namespace Cradle\Core;

defined( 'ABSPATH' ) || exit;

final class Activity {

	const CRON_HOOK      = 'cradle_activity_prune';
	const SENSITIVE_KEYS = '/(pass|secret|token|key|salt|cookie|auth|nonce|credential|session|email|phone)/i';

	public function hooks() {
		add_action( self::CRON_HOOK, array( $this, 'prune' ) );
	}

	public function record( string $module, string $action, string $message, array $context = array() ) {
		global $wpdb;
		$context = self::redact( $context );
		$encoded = wp_json_encode( $context );
		if ( strlen( (string) $encoded ) > 4000 ) {
			$encoded = wp_json_encode( array( 'truncated' => true ) );
		}
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Schema::tables()['activity'],
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'user_id'    => get_current_user_id(),
				'module'     => substr( sanitize_key( $module ), 0, 41 ),
				'action'     => substr( sanitize_key( $action ), 0, 64 ),
				'message'    => mb_substr( self::redact_string( $message ), 0, 500 ),
				'context'    => $encoded,
			)
		);
		$wpdb->suppress_errors( $suppress );
	}

	public static function redact( array $data, int $depth = 0 ): array {
		$out = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && preg_match( self::SENSITIVE_KEYS, $key ) ) {
				$out[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$out[ $key ] = $depth < 4 ? self::redact( $value, $depth + 1 ) : '[truncated]';
			} elseif ( is_scalar( $value ) || null === $value ) {
				$out[ $key ] = is_string( $value ) ? self::redact_string( $value ) : $value;
			} else {
				$out[ $key ] = '[object]';
			}
		}
		return $out;
	}

	/**
	 * Removes values that look like credentials, emails, or absolute server paths.
	 */
	public static function redact_string( string $value ): string {
		$value = preg_replace( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $value );
		$value = preg_replace( '/\b(password|pwd|secret|token|key)=\S+/i', '$1=[redacted]', $value );
		$roots = array_filter( array( wp_normalize_path( ABSPATH ), wp_normalize_path( WP_CONTENT_DIR ) ) );
		foreach ( $roots as $root ) {
			$value = str_ireplace( array( $root, str_replace( '/', '\\', $root ) ), '[site]/', $value );
		}
		return (string) $value;
	}

	/**
	 * @return array<int,array>
	 */
	public function recent( int $limit = 50, string $module = '' ): array {
		global $wpdb;
		$table = Schema::tables()['activity'];
		$limit = min( 200, max( 1, $limit ) );
		if ( '' !== $module ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE module = %s ORDER BY id DESC LIMIT %d", $module, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $rows ?: array();
	}

	public function prune() {
		global $wpdb;
		$table = Schema::tables()['activity'];
		/**
		 * Days to keep redacted activity records (minimum 7). No admin setting: code-level policy.
		 *
		 * @param int $days Default 90.
		 */
		$days = (int) apply_filters( 'cradle/activity_retention_days', 90 );
		/**
		 * Maximum number of activity records kept (minimum 500).
		 *
		 * @param int $rows Default 5000.
		 */
		$max = (int) apply_filters( 'cradle/activity_max_rows', 5000 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - max( 7, $days ) * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$cutoff = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", max( 500, $max ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $cutoff > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
