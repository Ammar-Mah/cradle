<?php
/**
 * CSV export of a form's entries (streamed in batches).
 *
 * Every cell that starts with = + - @ (or a tab/carriage return) is prefixed with an apostrophe,
 * so spreadsheet programs never evaluate submitted text as a formula (CSV injection).
 *
 * @package Cradle\Forms\Admin
 */

namespace Cradle\Forms\Admin;

use Cradle\Forms\Entries;
use Cradle\Forms\Field_Types;
use Cradle\Forms\Form_Schema;
use Cradle\Forms\Schema;

defined( 'ABSPATH' ) || exit;

final class Csv {

	const BATCH = 500;

	public static function cell( $value ): string {
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/**
	 * Columns: fixed entry columns, the current schema fields, then field names found only in
	 * older entries (label snapshot).
	 *
	 * @return array<string,string> field name => header label.
	 */
	public static function columns( int $form_id ): array {
		global $wpdb;
		$columns = array();
		foreach ( Form_Schema::fields( $form_id ) as $field ) {
			$columns[ $field['name'] ] = 'consent' === $field['type'] ? (string) $field['label_text'] : $field['label'];
		}
		$values  = Schema::table( 'values' );
		$entries = Schema::table( 'entries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$old = (array) $wpdb->get_results( $wpdb->prepare( "SELECT v.field_name, MAX(v.label) AS label FROM {$values} v INNER JOIN {$entries} e ON e.id = v.entry_id WHERE e.form_id = %d GROUP BY v.field_name ORDER BY MIN(v.id) ASC", $form_id ), ARRAY_A );
		foreach ( $old as $row ) {
			if ( ! isset( $columns[ $row['field_name'] ] ) ) {
				$columns[ $row['field_name'] ] = (string) $row['label'];
			}
		}
		return $columns;
	}

	/**
	 * Builds the rows for one batch (also used by tests).
	 *
	 * @return array<int,string[]>
	 */
	public static function rows( int $form_id, array $entries, array $columns ): array {
		$fields = Form_Schema::fields( $form_id );
		$values = Entries::values_for( wp_list_pluck( $entries, 'id' ) );
		$out    = array();
		foreach ( $entries as $entry ) {
			$by_name = array();
			foreach ( $values[ (int) $entry['id'] ] ?? array() as $row ) {
				$field                         = Form_Schema::field( $fields, $row['field_name'] );
				$by_name[ $row['field_name'] ] = Field_Types::display( $row['type'], $row['value'], $field && $field['type'] === $row['type'] ? $field : null );
			}
			$consent = array();
			foreach ( (array) json_decode( (string) $entry['consent'], true ) as $record ) {
				if ( is_array( $record ) ) {
					$consent[] = sprintf( '%s [%s] %s %s', (string) ( $record['plain'] ?? '' ), (string) ( $record['given_at'] ?? '' ), (string) ( $record['language'] ?? '' ), substr( (string) ( $record['hash'] ?? '' ), 0, 16 ) );
				}
			}
			$line = array(
				(string) $entry['id'],
				get_date_from_gmt( (string) $entry['created_at'], 'Y-m-d H:i:s' ),
				(string) $entry['status'],
				(string) $entry['language'],
				(string) (int) ( $entry['source_id'] ?? 0 ),
				(string) ( $entry['source_title'] ?? '' ),
			);
			foreach ( array_keys( $columns ) as $name ) {
				$line[] = $by_name[ $name ] ?? '';
			}
			$line[] = implode( ' | ', $consent );
			$out[]  = array_map( array( __CLASS__, 'cell' ), $line );
		}
		return $out;
	}

	public static function header( array $columns ): array {
		return array_map(
			array( __CLASS__, 'cell' ),
			array_merge(
				array( __( 'Entry', 'cradle-forms' ), __( 'Submitted', 'cradle-forms' ), __( 'Status', 'cradle-forms' ), __( 'Language', 'cradle-forms' ), __( 'Source ID', 'cradle-forms' ), __( 'Sent from', 'cradle-forms' ) ),
				array_values( $columns ),
				array( __( 'Consent records', 'cradle-forms' ) )
			)
		);
	}

	/**
	 * Streams the CSV and exits. Callers MUST check capability and nonce first.
	 */
	public static function stream( \WP_Post $form, array $filters ) {
		$columns = self::columns( (int) $form->ID );
		$name    = sanitize_file_name( 'entries-' . ( sanitize_title( $form->post_title ) ?: 'form-' . $form->ID ) . '-' . gmdate( 'Y-m-d' ) . '.csv' );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for spreadsheet programs.
		fputcsv( $out, self::header( $columns ), ',', '"', '' );
		$page = 1;
		do {
			$result = Entries::query(
				array_merge(
					$filters,
					array(
						'form_id'  => (int) $form->ID,
						'page'     => $page,
						'per_page' => self::BATCH,
						'order'    => 'asc',
					)
				)
			);
			foreach ( self::rows( (int) $form->ID, $result['rows'], $columns ) as $line ) {
				fputcsv( $out, $line, ',', '"', '' );
			}
			++$page;
		} while ( count( $result['rows'] ) === self::BATCH );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
