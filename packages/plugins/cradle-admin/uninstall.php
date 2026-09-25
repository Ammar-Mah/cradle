<?php
/**
 * Cradle Admin uninstall.
 *
 * Runs without the plugin or core loaded. Retain by default: nothing is removed
 * unless an administrator selected "purge" for the `admin` module in
 * the “When deleted” column of Cradle → Overview. Purge removes only data declared in the
 * module's dataOwners: its options, the per-user appearance meta, draft
 * transients and the cradle_manage_branding capability.
 *
 * @package Cradle\Admin
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cradle_admin_policies = get_option( 'cradle_uninstall_policy', array() );
if ( ! is_array( $cradle_admin_policies ) || 'purge' !== ( $cradle_admin_policies['admin'] ?? 'retain' ) ) {
	return;
}

foreach ( array(
	'cradle_admin_branding_settings',
	'cradle_admin_visual_settings',
	'cradle_admin_labels_settings',
	'cradle_admin_custom_css',
	'cradle_admin_custom_css_revisions',
	'cradle_admin_version',
) as $cradle_admin_option ) {
	delete_option( $cradle_admin_option );
}

delete_metadata( 'user', 0, 'cradle_admin_native_appearance', '', true );

global $wpdb;
$cradle_admin_like = $wpdb->esc_like( '_transient_cradle_admin_css_draft_' ) . '%';
$cradle_admin_rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $cradle_admin_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( (array) $cradle_admin_rows as $cradle_admin_row ) {
	delete_transient( substr( (string) $cradle_admin_row, strlen( '_transient_' ) ) );
}

foreach ( array_keys( wp_roles()->roles ) as $cradle_admin_role_name ) {
	$cradle_admin_role = get_role( $cradle_admin_role_name );
	if ( $cradle_admin_role && $cradle_admin_role->has_cap( 'cradle_manage_branding' ) ) {
		$cradle_admin_role->remove_cap( 'cradle_manage_branding' );
	}
}
$cradle_admin_granted = get_option( 'cradle_granted_capabilities', array() );
if ( is_array( $cradle_admin_granted ) && isset( $cradle_admin_granted['cradle_manage_branding'] ) ) {
	unset( $cradle_admin_granted['cradle_manage_branding'] );
	update_option( 'cradle_granted_capabilities', $cradle_admin_granted, false );
}

unset( $cradle_admin_policies['admin'] );
update_option( 'cradle_uninstall_policy', $cradle_admin_policies, false );
