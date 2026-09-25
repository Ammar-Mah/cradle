<?php
/**
 * Custom table definitions and versioned, additive schema upgrades for core.
 *
 * Tables (documented in docs/architecture/data-model.md):
 * - {prefix}cradle_jobs      Durable bounded jobs with leases and fencing.
 * - {prefix}cradle_locks     Named cross-request locks with expiry and fencing.
 * - {prefix}cradle_activity  Redacted privileged-operation records with retention.
 *
 * Upgrades run on activation and on admin/CLI requests, never on ordinary frontend requests.
 *
 * @package Cradle
 */

namespace Cradle\Core;

defined( 'ABSPATH' ) || exit;

final class Schema {

	const VERSION = 3;
	const OPTION  = 'cradle_core_schema';

	public static function hooks() {
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 0 );
		}
	}

	public static function maybe_upgrade() {
		$from = (int) get_option( self::OPTION, 0 );
		if ( $from >= self::VERSION ) {
			return;
		}
		self::install();
		if ( $from < 3 ) {
			// v3: the Components & cleanup screen and the core settings section were removed.
			Capabilities::revoke_everywhere( array( 'cradle_manage_components' ) );
			delete_option( 'cradle_core_settings' );
		}
	}

	public static function tables(): array {
		global $wpdb;
		return array(
			'jobs'     => $wpdb->prefix . 'cradle_jobs',
			'locks'    => $wpdb->prefix . 'cradle_locks',
			'activity' => $wpdb->prefix . 'cradle_activity',
		);
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t       = self::tables();

		dbDelta(
			"CREATE TABLE {$t['jobs']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL,
			type varchar(64) NOT NULL,
			module varchar(41) NOT NULL,
			owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
			required_cap varchar(64) NOT NULL DEFAULT '',
			state varchar(20) NOT NULL DEFAULT 'created',
			phase varchar(64) NOT NULL DEFAULT '',
			cursor_data longtext NULL,
			completed_units longtext NULL,
			progress_done bigint(20) unsigned NOT NULL DEFAULT 0,
			progress_total bigint(20) unsigned NOT NULL DEFAULT 0,
			bytes_done bigint(20) unsigned NOT NULL DEFAULT 0,
			bytes_total bigint(20) unsigned NOT NULL DEFAULT 0,
			lease_owner char(36) NULL,
			lease_expires datetime NULL,
			fencing bigint(20) unsigned NOT NULL DEFAULT 0,
			retries int(10) unsigned NOT NULL DEFAULT 0,
			retries_total int(10) unsigned NOT NULL DEFAULT 0,
			max_retries int(10) unsigned NOT NULL DEFAULT 3,
			error_code varchar(64) NOT NULL DEFAULT '',
			error_message text NULL,
			payload longtext NULL,
			manifest_digest char(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY type_state (type,state),
			KEY module_state (module,state),
			KEY owner_id (owner_id)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$t['locks']} (
			name varchar(191) NOT NULL,
			owner char(36) NOT NULL,
			fencing bigint(20) unsigned NOT NULL DEFAULT 0,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (name)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$t['activity']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			module varchar(41) NOT NULL,
			action varchar(64) NOT NULL,
			message varchar(500) NOT NULL DEFAULT '',
			context text NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY module_action (module,action)
			) $charset;"
		);

		update_option( self::OPTION, self::VERSION, false );
	}

	/**
	 * Drops core tables. Only called by an explicit purge during uninstall.
	 */
	public static function drop() {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed internal table names.
		}
		delete_option( self::OPTION );
	}
}
