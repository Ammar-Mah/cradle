<?php
/**
 * Cradle Login uninstall. Runs without the plugin or core loaded.
 *
 * Retain by default: settings stay so a reinstall resumes the saved configuration.
 * Purge only when cradle_uninstall_policy['login'] === 'purge' (Cradle → Components &
 * cleanup): delete the owned options and transients and revoke cradle_manage_login.
 * Nothing else exists to remove: no tables, posts, cron events, files or rewrite rules.
 *
 * @package Cradle\Login
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cradle_login_policies = get_option( 'cradle_uninstall_policy', array() );
if ( ! is_array( $cradle_login_policies ) || 'purge' !== ( $cradle_login_policies['login'] ?? 'retain' ) ) {
	return;
}

delete_option( 'cradle_login_settings' );
delete_option( 'cradle_login_health' );

global $wpdb;
// Transients (probe tokens, per-user test results, resumed notice) are prefix-owned.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_cradle_login_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_cradle_login_' ) . '%'
	)
);
wp_cache_delete( 'alloptions', 'options' );

foreach ( array_keys( wp_roles()->roles ) as $cradle_login_role_name ) {
	$cradle_login_role = get_role( $cradle_login_role_name );
	if ( $cradle_login_role && $cradle_login_role->has_cap( 'cradle_manage_login' ) ) {
		$cradle_login_role->remove_cap( 'cradle_manage_login' );
	}
}
$cradle_login_granted = get_option( 'cradle_granted_capabilities', array() );
if ( is_array( $cradle_login_granted ) && isset( $cradle_login_granted['cradle_manage_login'] ) ) {
	unset( $cradle_login_granted['cradle_manage_login'] );
	update_option( 'cradle_granted_capabilities', $cradle_login_granted, false );
}
