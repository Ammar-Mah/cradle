<?php
/**
 * Cradle Blocks uninstall. Stored block markup in posts is user content and is
 * never removed. With the "Remove its data" choice (Cradle → Overview) only the
 * package's own option and any capability left by older versions are removed.
 *
 * @package Cradle\Blocks
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cradle_blocks_policies = get_option( 'cradle_uninstall_policy', array() );
if ( ! is_array( $cradle_blocks_policies ) || 'purge' !== ( $cradle_blocks_policies['blocks'] ?? 'retain' ) ) {
	return;
}

delete_option( 'cradle_blocks_schema' );

// Capability used by the conversion screen of version 0.1.0.
foreach ( array_keys( wp_roles()->roles ) as $cradle_blocks_role ) {
	$cradle_blocks_role_object = get_role( $cradle_blocks_role );
	if ( $cradle_blocks_role_object ) {
		$cradle_blocks_role_object->remove_cap( 'cradle_convert_components' );
	}
}
$cradle_blocks_granted = get_option( 'cradle_granted_capabilities', array() );
if ( is_array( $cradle_blocks_granted ) ) {
	unset( $cradle_blocks_granted['cradle_convert_components'] );
	update_option( 'cradle_granted_capabilities', $cradle_blocks_granted, false );
}
