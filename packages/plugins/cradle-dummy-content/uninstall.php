<?php
/**
 * Cradle Dummy Content uninstall.
 *
 * Retain by default. Only when an administrator selected "purge" for the
 * `dummy-content` module in the “When deleted” column of Cradle → Overview does this remove the
 * module's own configuration: batch records (and their reports), settings,
 * the installed-version option and the two capabilities.
 *
 * Generated posts, terms and images, and their `_cradle_generat*` ownership
 * markers, are deliberately NOT deleted here: removing content needs the
 * previewed, resumable cleanup on Cradle → Dummy content. Run that cleanup
 * before uninstalling if the sample content should go. Keeping the markers
 * means a later reinstall can still identify and clean up the content.
 *
 * Runs without the plugin or core loaded.
 *
 * @package Cradle\Dummy_Content
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cradle_dummy_policies = get_option( 'cradle_uninstall_policy', array() );
if ( ! is_array( $cradle_dummy_policies ) || 'purge' !== ( $cradle_dummy_policies['dummy-content'] ?? 'retain' ) ) {
	return;
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$cradle_dummy_records = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'cradle_dummy_batch' ) );
foreach ( $cradle_dummy_records as $cradle_dummy_record ) {
	wp_delete_post( (int) $cradle_dummy_record, true );
}

delete_option( 'cradle_dummy_content_settings' );
delete_option( 'cradle_dummy_content_installed_version' );

$cradle_dummy_caps = array( 'cradle_generate_dummy', 'cradle_cleanup_dummy' );
foreach ( array_keys( wp_roles()->roles ) as $cradle_dummy_role_name ) {
	$cradle_dummy_role = get_role( $cradle_dummy_role_name );
	if ( ! $cradle_dummy_role ) {
		continue;
	}
	foreach ( $cradle_dummy_caps as $cradle_dummy_cap ) {
		if ( $cradle_dummy_role->has_cap( $cradle_dummy_cap ) ) {
			$cradle_dummy_role->remove_cap( $cradle_dummy_cap );
		}
	}
}
$cradle_dummy_granted = get_option( 'cradle_granted_capabilities', array() );
if ( is_array( $cradle_dummy_granted ) ) {
	foreach ( $cradle_dummy_caps as $cradle_dummy_cap ) {
		unset( $cradle_dummy_granted[ $cradle_dummy_cap ] );
	}
	update_option( 'cradle_granted_capabilities', $cradle_dummy_granted, false );
}

unset( $cradle_dummy_policies['dummy-content'] );
update_option( 'cradle_uninstall_policy', $cradle_dummy_policies, false );
