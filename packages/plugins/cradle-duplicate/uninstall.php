<?php
/**
 * Cradle Duplicate uninstall.
 *
 * Retain by default. Only when an administrator selected "purge" for the
 * `duplicate` module in the “When deleted” column of Cradle → Overview are the module's own
 * settings, provenance meta and capability removed. Drafts created by
 * duplication are ordinary user content and are never deleted here.
 *
 * Runs without the plugin or core loaded.
 *
 * @package Cradle\Duplicate
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cradle_duplicate_policies = get_option( 'cradle_uninstall_policy', array() );
if ( ! is_array( $cradle_duplicate_policies ) || 'purge' !== ( $cradle_duplicate_policies['duplicate'] ?? 'retain' ) ) {
	return;
}

delete_option( 'cradle_duplicate_settings' );
delete_option( 'cradle_duplicate_installed_version' );

// Provenance marker only; the posts themselves stay.
delete_post_meta_by_key( '_cradle_duplicate_of' );

foreach ( array_keys( wp_roles()->roles ) as $cradle_duplicate_role_name ) {
	$cradle_duplicate_role = get_role( $cradle_duplicate_role_name );
	if ( $cradle_duplicate_role && $cradle_duplicate_role->has_cap( 'cradle_manage_duplicate' ) ) {
		$cradle_duplicate_role->remove_cap( 'cradle_manage_duplicate' );
	}
}
$cradle_duplicate_granted = get_option( 'cradle_granted_capabilities', array() );
if ( is_array( $cradle_duplicate_granted ) ) {
	unset( $cradle_duplicate_granted['cradle_manage_duplicate'] );
	update_option( 'cradle_granted_capabilities', $cradle_duplicate_granted, false );
}

unset( $cradle_duplicate_policies['duplicate'] );
update_option( 'cradle_uninstall_policy', $cradle_duplicate_policies, false );
