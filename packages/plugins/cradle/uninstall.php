<?php
/**
 * Cradle core uninstall.
 *
 * Policy: retain by default. Owned data is purged only when an administrator
 * explicitly chose "Remove its data" for `core` in the “When deleted” column of Cradle → Overview.
 * Other modules' data and their private storage are never touched here.
 *
 * @package Cradle
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cradle_policies = get_option( 'cradle_uninstall_policy', array() );
if ( ! is_array( $cradle_policies ) || 'purge' !== ( $cradle_policies['core'] ?? 'retain' ) ) {
	return;
}

global $wpdb;

foreach ( array( 'cradle_jobs', 'cradle_locks', 'cradle_activity' ) as $cradle_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . $cradle_table ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
}

wp_clear_scheduled_hook( 'cradle_activity_prune' );

$cradle_granted = get_option( 'cradle_granted_capabilities', array() );
$cradle_core_caps = array( 'cradle_manage_settings', 'cradle_view_diagnostics', 'cradle_manage_components' ); // The last one is retired (schema 3); removed here for old installs.
foreach ( array_keys( wp_roles()->roles ) as $cradle_role_name ) {
	$cradle_role = get_role( $cradle_role_name );
	if ( $cradle_role ) {
		foreach ( $cradle_core_caps as $cradle_cap ) {
			$cradle_role->remove_cap( $cradle_cap );
		}
	}
}
if ( is_array( $cradle_granted ) ) {
	foreach ( $cradle_core_caps as $cradle_cap ) {
		unset( $cradle_granted[ $cradle_cap ] );
	}
	update_option( 'cradle_granted_capabilities', $cradle_granted, false );
}

foreach ( array( 'cradle_core_settings', 'cradle_core_schema', 'cradle_storage_probe', 'cradle_installed_version' ) as $cradle_option ) {
	delete_option( $cradle_option );
}

// Keep the uninstall policy map and private-dir suffix: other retained modules may still rely on them.
unset( $cradle_policies['core'] );
update_option( 'cradle_uninstall_policy', $cradle_policies, false );
