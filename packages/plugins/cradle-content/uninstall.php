<?php
/**
 * Cradle Content uninstall.
 *
 * Runs without the plugin or core loaded. Policy (docs/lifecycle.md):
 * - Retain by default: nothing is removed unless an administrator selected "purge"
 *   for the `content` module in the “When deleted” column of Cradle → Overview.
 * - Purge removes only what this module owns: its options, the field values it
 *   stored (meta keys starting with _cradle_cf_), the capabilities it granted,
 *   its scheduled events and per-user transients.
 * - Posts and terms of Cradle-defined types are site content and are never deleted
 *   here. Convert or delete them through WordPress before uninstalling if unwanted.
 *
 * @package Cradle\Content
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cradle_content_policies = get_option( 'cradle_uninstall_policy', array() );
if ( ! is_array( $cradle_content_policies ) || 'purge' !== ( $cradle_content_policies['content'] ?? 'retain' ) ) {
	return;
}

global $wpdb;

// 1. Field values stored by this module (WordPress deletion API per key).
foreach ( array( 'post' => $wpdb->postmeta, 'term' => $wpdb->termmeta ) as $cradle_content_type => $cradle_content_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table name.
	$cradle_content_keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$cradle_content_table} WHERE meta_key LIKE %s", $wpdb->esc_like( '_cradle_cf_' ) . '%' ) );
	foreach ( $cradle_content_keys as $cradle_content_key ) {
		if ( preg_match( '/^_cradle_cf_[a-z][a-z0-9_]{0,39}$/', $cradle_content_key ) ) {
			delete_metadata( $cradle_content_type, 0, $cradle_content_key, '', true );
		}
	}
}

// 2. Capabilities this module granted: the module capability plus recorded custom content capabilities.
$cradle_content_caps    = array( 'cradle_manage_content_types' );
$cradle_content_records = get_option( 'cradle_content_caps', array() );
$cradle_content_grants  = array();
if ( is_array( $cradle_content_records ) ) {
	foreach ( $cradle_content_records as $cradle_content_record ) {
		if ( is_array( $cradle_content_record ) && isset( $cradle_content_record['caps'], $cradle_content_record['roles'] ) ) {
			foreach ( (array) $cradle_content_record['roles'] as $cradle_content_role ) {
				foreach ( (array) $cradle_content_record['caps'] as $cradle_content_cap ) {
					$cradle_content_grants[] = array( (string) $cradle_content_role, (string) $cradle_content_cap );
				}
			}
		}
	}
}
foreach ( array_keys( wp_roles()->roles ) as $cradle_content_role_name ) {
	$cradle_content_role = get_role( $cradle_content_role_name );
	if ( $cradle_content_role ) {
		foreach ( $cradle_content_caps as $cradle_content_cap ) {
			$cradle_content_role->remove_cap( $cradle_content_cap );
		}
	}
}
foreach ( $cradle_content_grants as $cradle_content_grant ) {
	$cradle_content_role = get_role( $cradle_content_grant[0] );
	if ( $cradle_content_role ) {
		$cradle_content_role->remove_cap( $cradle_content_grant[1] );
	}
}
$cradle_content_granted = get_option( 'cradle_granted_capabilities', array() );
if ( is_array( $cradle_content_granted ) ) {
	unset( $cradle_content_granted['cradle_manage_content_types'] );
	update_option( 'cradle_granted_capabilities', $cradle_content_granted, false );
}

// 3. Scheduled events and per-user transients.
wp_unschedule_hook( 'cradle_content_run_job' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$cradle_content_transients = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_cradle_content_' ) . '%' ) );
foreach ( $cradle_content_transients as $cradle_content_transient ) {
	delete_transient( substr( $cradle_content_transient, strlen( '_transient_' ) ) );
}

// 4. Options.
foreach ( array( 'cradle_content_definitions', 'cradle_content_settings', 'cradle_content_caps', 'cradle_content_rewrite' ) as $cradle_content_option ) {
	delete_option( $cradle_content_option );
}
delete_option( 'rewrite_rules' ); // Regenerated on the next request without the removed routes.

unset( $cradle_content_policies['content'] );
update_option( 'cradle_uninstall_policy', $cradle_content_policies, false );
