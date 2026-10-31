<?php
/**
 * Entry storage: `{prefix}cradle_form_entries`, `…_values` and `…_files` (docs/data-model.md).
 *
 * All SQL is prepared; table names come from Schema; identifiers are never taken from input.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Entries {

	const STATUSES = array( 'new', 'read', 'spam' );

	/**
	 * @param array $entry  form_id, form_title, language, locale, ip_hash, ua_hash, submitter_email, consent (list).
	 * @param array $values List of array( field_name, label, type, value ).
	 * @return int|\WP_Error Entry ID.
	 */
	public static function insert( array $entry, array $values ) {
		global $wpdb;
		$row = array(
			'form_id'         => (int) $entry['form_id'],
			'form_title'      => mb_substr( (string) $entry['form_title'], 0, 255 ),
			'language'        => mb_substr( (string) $entry['language'], 0, 20 ),
			'locale'          => mb_substr( (string) $entry['locale'], 0, 20 ),
			'status'          => 'new',
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			'ip_hash'         => (string) $entry['ip_hash'],
			'ua_hash'         => (string) $entry['ua_hash'],
			'submitter_email' => mb_substr( strtolower( (string) $entry['submitter_email'] ), 0, 191 ),
			'consent'         => $entry['consent'] ? (string) wp_json_encode( $entry['consent'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null,
		);
		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
		if ( Schema::has_source() ) {
			$row['source_id']    = max( 0, (int) ( $entry['source_id'] ?? 0 ) );
			$row['source_title'] = mb_substr( (string) ( $entry['source_title'] ?? '' ), 0, 255 );
			$formats[]           = '%d';
			$formats[]           = '%s';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $wpdb->insert( Schema::table( 'entries' ), $row, $formats ) ) {
			return new \WP_Error( 'cradle_forms_store', __( 'Your submission could not be saved. Please try again later.', 'cradle-forms' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		foreach ( $values as $value ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok = $wpdb->insert(
				Schema::table( 'values' ),
				array(
					'entry_id'   => $id,
					'field_name' => (string) $value['field_name'],
					'label'      => mb_substr( (string) $value['label'], 0, 255 ),
					'type'       => (string) $value['type'],
					'value'      => (string) $value['value'],
				),
				array( '%d', '%s', '%s', '%s', '%s' )
			);
			if ( ! $ok ) {
				self::delete( array( $id ) );
				return new \WP_Error( 'cradle_forms_store', __( 'Your submission could not be saved. Please try again later.', 'cradle-forms' ), array( 'status' => 500 ) );
			}
		}
		return $id;
	}

	/**
	 * Adds a value row after files were stored (file fields list their original names).
	 */
	public static function add_value( int $entry_id, array $value ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Schema::table( 'values' ),
			array(
				'entry_id'   => $entry_id,
				'field_name' => (string) $value['field_name'],
				'label'      => mb_substr( (string) $value['label'], 0, 255 ),
				'type'       => (string) $value['type'],
				'value'      => (string) $value['value'],
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( 'entries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $id > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ) : null;
		if ( ! $row ) {
			return null;
		}
		$row['id']      = (int) $row['id'];
		$row['form_id'] = (int) $row['form_id'];
		$row['source_id']    = (int) ( $row['source_id'] ?? 0 );
		$row['source_title'] = (string) ( $row['source_title'] ?? '' );
		$consent        = json_decode( (string) $row['consent'], true );
		$row['consent'] = is_array( $consent ) ? $consent : array();
		return $row;
	}

	public static function values( int $entry_id ): array {
		global $wpdb;
		$table = Schema::table( 'values' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT field_name, label, type, value FROM {$table} WHERE entry_id = %d ORDER BY id ASC", $entry_id ), ARRAY_A );
	}

	/**
	 * Values of several entries, grouped by entry ID.
	 *
	 * @param int[] $ids Entry IDs.
	 */
	public static function values_for( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$table = Schema::table( 'values' );
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT entry_id, field_name, label, type, value FROM {$table} WHERE entry_id IN ({$in}) ORDER BY id ASC", $ids ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['entry_id'] ][] = $row;
		}
		return $out;
	}

	public static function files( int $entry_id ): array {
		global $wpdb;
		$table = Schema::table( 'files' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE entry_id = %d ORDER BY id ASC", $entry_id ), ARRAY_A );
	}

	public static function file( int $file_id ): ?array {
		global $wpdb;
		$table = Schema::table( 'files' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $file_id > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $file_id ), ARRAY_A ) : null;
		return $row ? $row : null;
	}

	/**
	 * Filtered list of one form's entries.
	 *
	 * @param array $args form_id (required), status, from, to (Y-m-d, site time), page, per_page, order.
	 * @return array{rows:array,total:int}
	 */
	public static function query( array $args ): array {
		global $wpdb;
		$table  = Schema::table( 'entries' );
		$where  = array( 'form_id = %d' );
		$params = array( (int) ( $args['form_id'] ?? 0 ) );
		if ( in_array( $args['status'] ?? '', self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		} elseif ( 'all' !== ( $args['status'] ?? '' ) ) {
			$where[] = "status <> 'spam'"; // Default view hides spam.
		}
		foreach ( array(
			'from' => '>=',
			'to'   => '<',
		) as $key => $op ) {
			$date = (string) ( $args[ $key ] ?? '' );
			if ( Field_Types::is_date( $date ) ) {
				$local    = new \DateTimeImmutable( $date . ' 00:00:00', wp_timezone() );
				$local    = 'to' === $key ? $local->modify( '+1 day' ) : $local;
				$where[]  = 'created_at ' . $op . ' %s';
				$params[] = $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			}
		}
		$sql_where = implode( ' AND ', $where );
		$per_page  = max( 1, min( 500, (int) ( $args['per_page'] ?? 20 ) ) );
		$page      = max( 1, (int) ( $args['page'] ?? 1 ) );
		$order     = 'asc' === ( $args['order'] ?? '' ) ? 'ASC' : 'DESC';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table, allowlisted order.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}", $params ) );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY created_at {$order}, id {$order} LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * @return array<int,array{total:int,new:int}> Counts keyed by form ID (spam excluded from totals).
	 */
	public static function counts_by_form(): array {
		global $wpdb;
		if ( ! Schema::exists() ) {
			return array();
		}
		$table = Schema::table( 'entries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT form_id, SUM(status <> 'spam') AS total, SUM(status = 'new') AS fresh FROM {$table} GROUP BY form_id", ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['form_id'] ] = array(
				'total' => (int) $row['total'],
				'new'   => (int) $row['fresh'],
			);
		}
		return $out;
	}

	/**
	 * @param int[] $ids     Entry IDs.
	 * @param int   $form_id Limit to this form (0: any).
	 * @return int[] IDs that exist (and belong to the form).
	 */
	public static function existing( array $ids, int $form_id = 0 ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$table  = Schema::table( 'entries' );
		$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params = $ids;
		$extra  = '';
		if ( $form_id ) {
			$extra    = ' AND form_id = %d';
			$params[] = $form_id;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE id IN ({$in}){$extra}", $params ) ) );
	}

	public static function set_status( array $ids, string $status ): int {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return 0;
		}
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$table = Schema::table( 'entries' );
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE id IN ({$in})", array_merge( array( $status ), $ids ) ) );
	}

	/**
	 * Deletes entries with their values, file rows and stored files.
	 *
	 * @param int[] $ids Entry IDs.
	 * @return int Entries deleted.
	 */
	public static function delete( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$files = Schema::table( 'files' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stored = (array) $wpdb->get_col( $wpdb->prepare( "SELECT stored_name FROM {$files} WHERE entry_id IN ({$in})", $ids ) );
		foreach ( $stored as $name ) {
			Uploads::delete_stored( (string) $name );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$files} WHERE entry_id IN ({$in})", $ids ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'values' ) . " WHERE entry_id IN ({$in})", $ids ) );
		$count = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'entries' ) . " WHERE id IN ({$in})", $ids ) );
		// phpcs:enable
		return $count;
	}

	public static function ids_older_than( int $form_id, string $cutoff, int $limit ): array {
		global $wpdb;
		$table = Schema::table( 'entries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE form_id = %d AND created_at < %s ORDER BY id ASC LIMIT %d", $form_id, $cutoff, $limit ) ) );
	}

	/**
	 * Entries whose "submitter's own email" field holds this address (privacy tools).
	 */
	public static function ids_for_email( string $email, int $limit = 0, int $offset = 0 ): array {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return array();
		}
		$table = Schema::table( 'entries' );
		$sql   = "SELECT id FROM {$table} WHERE submitter_email = %s ORDER BY id ASC";
		$args  = array( $email );
		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d OFFSET %d';
			$args[] = $limit;
			$args[] = $offset;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
	}

	/**
	 * Escaped admin URL of an entry.
	 */
	public static function admin_url( int $entry_id ): string {
		return admin_url( 'admin.php?page=cradle-forms&view=entry&entry=' . $entry_id );
	}
}
