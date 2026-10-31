<?php
/**
 * Custom tables for entries, their values and their uploaded files (see docs/data-model.md).
 *
 * Created on activation and upgraded on admin/CLI requests (never on front-end requests).
 * Additive, idempotent dbDelta definitions; `cradle_forms_db_version` records the applied version.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Schema {

	const VERSION = 2; // 2: entries.source_id, entries.source_title.
	const OPTION  = 'cradle_forms_db_version';

	/** Table names without the WordPress prefix (as listed in dataOwners). */
	const TABLES = array(
		'entries' => 'cradle_form_entries',
		'values'  => 'cradle_form_values',
		'files'   => 'cradle_form_files',
	);

	public static function table( string $key ): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLES[ $key ];
	}

	public static function maybe_upgrade() {
		if ( (int) get_option( self::OPTION, 0 ) >= self::VERSION ) {
			return;
		}
		if ( ! ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) || wp_doing_ajax() ) {
			return; // Never on a front-end request.
		}
		self::install();
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$entries = self::table( 'entries' );
		$values  = self::table( 'values' );
		$files   = self::table( 'files' );

		dbDelta(
			"CREATE TABLE {$entries} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			form_title varchar(255) NOT NULL DEFAULT '',
			language varchar(20) NOT NULL DEFAULT '',
			locale varchar(20) NOT NULL DEFAULT '',
			status varchar(10) NOT NULL DEFAULT 'new',
			created_at datetime NOT NULL,
			ip_hash char(64) NOT NULL DEFAULT '',
			ua_hash char(64) NOT NULL DEFAULT '',
			submitter_email varchar(191) NOT NULL DEFAULT '',
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_title varchar(255) NOT NULL DEFAULT '',
			consent longtext NULL,
			PRIMARY KEY  (id),
			KEY form_status (form_id,status),
			KEY form_created (form_id,created_at),
			KEY submitter_email (submitter_email),
			KEY form_source (form_id,source_id),
			KEY created_at (created_at)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$values} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entry_id bigint(20) unsigned NOT NULL,
			field_name varchar(41) NOT NULL,
			label varchar(255) NOT NULL DEFAULT '',
			type varchar(20) NOT NULL DEFAULT '',
			value longtext NULL,
			PRIMARY KEY  (id),
			KEY entry_id (entry_id),
			KEY entry_field (entry_id,field_name)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$files} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entry_id bigint(20) unsigned NOT NULL,
			field_name varchar(41) NOT NULL,
			stored_name char(32) NOT NULL,
			original_name varchar(255) NOT NULL DEFAULT '',
			mime varchar(100) NOT NULL DEFAULT '',
			size bigint(20) unsigned NOT NULL DEFAULT 0,
			metadata_stripped tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stored_name (stored_name),
			KEY entry_id (entry_id)
			) $charset;"
		);

		update_option( self::OPTION, self::VERSION, false );
	}

	/**
	 * Whether the entries table already has the version 2 columns (source page). Front-end
	 * requests never upgrade the schema, so inserts must work on a version 1 table until an
	 * admin or CLI request has run the upgrade.
	 */
	public static function has_source(): bool {
		return (int) get_option( self::OPTION, 0 ) >= 2;
	}

	public static function exists(): bool {
		global $wpdb;
		$table = self::table( 'entries' );
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
