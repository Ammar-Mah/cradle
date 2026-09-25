<?php
/**
 * Cradle Social uninstall.
 *
 * Retain by default. Only when an administrator selected "purge" for the
 * `social` module in the “When deleted” column of Cradle → Overview are this module's own
 * options and capability removed. Saved blocks inside posts, templates and
 * patterns are user content and are never modified here (they render nothing
 * once the plugin is gone; see docs/social.md).
 *
 * Runs without the plugin or core loaded.
 *
 * @package Cradle\Social
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cradle_social_policies = get_option( 'cradle_uninstall_policy', array() );
if ( ! is_array( $cradle_social_policies ) || 'purge' !== ( $cradle_social_policies['social'] ?? 'retain' ) ) {
	return;
}

delete_option( 'cradle_social_settings' );
delete_option( 'cradle_social_installed_version' );

foreach ( array_keys( wp_roles()->roles ) as $cradle_social_role_name ) {
	$cradle_social_role = get_role( $cradle_social_role_name );
	if ( $cradle_social_role && $cradle_social_role->has_cap( 'cradle_manage_social' ) ) {
		$cradle_social_role->remove_cap( 'cradle_manage_social' );
	}
}
$cradle_social_granted = get_option( 'cradle_granted_capabilities', array() );
if ( is_array( $cradle_social_granted ) ) {
	unset( $cradle_social_granted['cradle_manage_social'] );
	update_option( 'cradle_granted_capabilities', $cradle_social_granted, false );
}

unset( $cradle_social_policies['social'] );
update_option( 'cradle_uninstall_policy', $cradle_social_policies, false );
