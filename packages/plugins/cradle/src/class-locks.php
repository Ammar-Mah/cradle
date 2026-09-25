<?php
/**
 * Named cross-request locks with expiry and fencing tokens.
 *
 * Acquisition is a single atomic INSERT or a conditional UPDATE of an expired
 * row; the affected-row count decides ownership. A read-then-write transient
 * is not used.
 *
 * @package Cradle
 */

namespace Cradle\Core;

defined( 'ABSPATH' ) || exit;

final class Locks {

	/**
	 * @return array{owner:string,fencing:int}|null Lock handle or null when held elsewhere.
	 */
	public function acquire( string $name, int $ttl_seconds = 60 ) {
		global $wpdb;
		$table   = Schema::tables()['locks'];
		$owner   = wp_generate_uuid4();
		$name    = substr( $name, 0, 191 );
		$now     = gmdate( 'Y-m-d H:i:s' );
		$expires = gmdate( 'Y-m-d H:i:s', time() + max( 1, $ttl_seconds ) );

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (name, owner, fencing, expires_at) VALUES (%s, %s, 1, %s)", $name, $owner, $expires ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->suppress_errors( $suppress );
		if ( 1 === $inserted ) {
			return array(
				'owner'   => $owner,
				'fencing' => 1,
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET owner = %s, fencing = fencing + 1, expires_at = %s WHERE name = %s AND expires_at < %s", $owner, $expires, $name, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== $updated ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$fencing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT fencing FROM {$table} WHERE name = %s AND owner = %s", $name, $owner ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'owner'   => $owner,
			'fencing' => $fencing,
		);
	}

	/**
	 * Extends a held lock. Returns false when ownership was lost.
	 */
	public function renew( string $name, array $handle, int $ttl_seconds = 60 ): bool {
		global $wpdb;
		$table = Schema::tables()['locks'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET expires_at = %s WHERE name = %s AND owner = %s AND fencing = %d", gmdate( 'Y-m-d H:i:s', time() + max( 1, $ttl_seconds ) ), $name, $handle['owner'], $handle['fencing'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 1 === $updated || ( 0 === $updated && $this->holds( $name, $handle ) );
	}

	public function holds( string $name, array $handle ): bool {
		global $wpdb;
		$table = Schema::tables()['locks'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE name = %s AND owner = %s AND fencing = %d AND expires_at >= %s", $name, $handle['owner'], $handle['fencing'], gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function release( string $name, array $handle ): bool {
		global $wpdb;
		$table = Schema::tables()['locks'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return 1 === $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE name = %s AND owner = %s AND fencing = %d", $name, $handle['owner'], $handle['fencing'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Releases every lock whose name starts with a module prefix; used on deactivation.
	 */
	public function release_prefix( string $prefix ): int {
		global $wpdb;
		$table = Schema::tables()['locks'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
