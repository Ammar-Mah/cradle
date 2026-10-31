<?php
/**
 * Cradle Forms uninstall.
 *
 * Retain by default: forms, entries, consent records and uploaded files stay in place. Only when
 * an administrator selected "purge" for the `forms` module in the "When deleted" column of
 * Cradle → Overview are this module's own data owners removed: `cradle_form` posts (with their
 * `_cradle_form_*` meta and revisions), the three entry tables, the uploaded files in private
 * storage (`forms/` only), `cradle_forms_*` options and transients, the cron hook and the four
 * capabilities. Pages that embed a form block are user content and are never modified.
 *
 * Runs without the plugin or core loaded.
 *
 * @package Cradle\Forms
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cradle_forms_policies = get_option( 'cradle_uninstall_policy', array() );
if ( ! is_array( $cradle_forms_policies ) || 'purge' !== ( $cradle_forms_policies['forms'] ?? 'retain' ) ) {
	return;
}

global $wpdb;

// 1. Form posts (bounded batches; re-running is safe).
for ( $cradle_forms_round = 0; $cradle_forms_round < 50; $cradle_forms_round++ ) {
	$cradle_forms_ids = get_posts(
		array(
			'post_type'        => 'cradle_form',
			'post_status'      => 'any',
			'numberposts'      => 200,
			'fields'           => 'ids',
			'suppress_filters' => true,
		)
	);
	if ( ! $cradle_forms_ids ) {
		break;
	}
	foreach ( $cradle_forms_ids as $cradle_forms_id ) {
		wp_delete_post( (int) $cradle_forms_id, true );
	}
}
// Orphaned meta rows of this module (for example left by a partial earlier purge).
$wpdb->query( $wpdb->prepare( "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key LIKE %s AND ( p.ID IS NULL OR p.post_type = 'cradle_form' )", $wpdb->esc_like( '_cradle_form_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// 2. Uploaded files: only <private root>/forms, resolved the way core resolves its private root.
$cradle_forms_root = '';
if ( defined( 'CRADLE_PRIVATE_DIR' ) && is_string( CRADLE_PRIVATE_DIR ) && '' !== CRADLE_PRIVATE_DIR ) {
	$cradle_forms_root = (string) realpath( CRADLE_PRIVATE_DIR );
} else {
	$cradle_forms_suffix = get_option( 'cradle_private_dir_suffix' );
	$cradle_forms_upload = wp_upload_dir( null, false );
	if ( is_string( $cradle_forms_suffix ) && preg_match( '/^[a-z0-9]{20}$/', $cradle_forms_suffix ) && empty( $cradle_forms_upload['error'] ) ) {
		$cradle_forms_root = (string) realpath( $cradle_forms_upload['basedir'] . '/cradle-private-' . $cradle_forms_suffix );
	}
}
if ( '' !== $cradle_forms_root ) {
	$cradle_forms_root = rtrim( wp_normalize_path( $cradle_forms_root ), '/' );
	$cradle_forms_dir  = (string) realpath( $cradle_forms_root . '/forms' );
	$cradle_forms_dir  = '' !== $cradle_forms_dir ? rtrim( wp_normalize_path( $cradle_forms_dir ), '/' ) : '';
	if ( '' !== $cradle_forms_dir && 0 === strpos( strtolower( $cradle_forms_dir ), strtolower( $cradle_forms_root ) . '/' ) && is_dir( $cradle_forms_dir ) && ! is_link( $cradle_forms_dir ) ) {
		$cradle_forms_items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $cradle_forms_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $cradle_forms_items as $cradle_forms_item ) {
			if ( $cradle_forms_item->isLink() || $cradle_forms_item->isFile() ) {
				wp_delete_file( $cradle_forms_item->getPathname() );
			} elseif ( $cradle_forms_item->isDir() ) {
				rmdir( $cradle_forms_item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
		}
		rmdir( $cradle_forms_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

// 3. Tables (fixed internal names).
foreach ( array( 'cradle_form_files', 'cradle_form_values', 'cradle_form_entries' ) as $cradle_forms_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$cradle_forms_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// 4. Options and transients of this module only.
delete_option( 'cradle_forms_settings' );
delete_option( 'cradle_forms_installed_version' );
delete_option( 'cradle_forms_db_version' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_cradle_forms_' ) . '%', $wpdb->esc_like( '_transient_timeout_cradle_forms_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// 5. Cron and capabilities.
wp_clear_scheduled_hook( 'cradle_forms_retention' );
$cradle_forms_caps = array( 'cradle_manage_forms', 'cradle_view_form_entries', 'cradle_export_form_entries', 'cradle_delete_form_entries' );
foreach ( array_keys( wp_roles()->roles ) as $cradle_forms_role_name ) {
	$cradle_forms_role = get_role( $cradle_forms_role_name );
	if ( ! $cradle_forms_role ) {
		continue;
	}
	foreach ( $cradle_forms_caps as $cradle_forms_cap ) {
		if ( $cradle_forms_role->has_cap( $cradle_forms_cap ) ) {
			$cradle_forms_role->remove_cap( $cradle_forms_cap );
		}
	}
}
$cradle_forms_granted = get_option( 'cradle_granted_capabilities', array() );
if ( is_array( $cradle_forms_granted ) ) {
	foreach ( $cradle_forms_caps as $cradle_forms_cap ) {
		unset( $cradle_forms_granted[ $cradle_forms_cap ] );
	}
	update_option( 'cradle_granted_capabilities', $cradle_forms_granted, false );
}

unset( $cradle_forms_policies['forms'] );
update_option( 'cradle_uninstall_policy', $cradle_forms_policies, false );
