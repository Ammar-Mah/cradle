<?php
/**
 * Uninstall: retain by default. Archives are the operator's backups and restore
 * backups may be the only way back, so nothing is deleted unless an administrator
 * chose "purge" for the migration module in the “When deleted” column of Cradle → Overview.
 *
 * Runs without core or this plugin loaded.
 *
 * @package Cradle\Migration
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cradle_policy = get_option( 'cradle_uninstall_policy' );
if ( ! is_array( $cradle_policy ) || 'purge' !== ( $cradle_policy['migration'] ?? '' ) ) {
	// Retain: only runtime state that must not outlive the plugin.
	delete_option( 'cradle_migration_quiesce' );
	return;
}

global $wpdb;

foreach ( array( 'cradle_migration_quiesce', 'cradle_migration_safety_hold', 'cradle_migration_last_restore' ) as $cradle_option ) {
	delete_option( $cradle_option );
}

// Restore backup tables: cmig_<6 hex>_<table> / cold_<6 hex>_<table>.
foreach ( array( 'cmig', 'cold' ) as $cradle_kind ) {
	$cradle_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $cradle_kind . '_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( (array) $cradle_tables as $cradle_table ) {
		if ( preg_match( '/^c(mig|old)_[0-9a-f]{6}_[A-Za-z0-9_]{1,54}$/', (string) $cradle_table ) ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $cradle_table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- validated identifier.
		}
	}
}

// Private files: <private root>/migration only.
$cradle_roots = array();
if ( defined( 'CRADLE_PRIVATE_DIR' ) && is_string( CRADLE_PRIVATE_DIR ) && '' !== CRADLE_PRIVATE_DIR ) {
	$cradle_roots[] = rtrim( wp_normalize_path( CRADLE_PRIVATE_DIR ), '/' );
}
$cradle_suffix = get_option( 'cradle_private_dir_suffix' );
if ( is_string( $cradle_suffix ) && preg_match( '/^[a-z0-9]{20}$/', $cradle_suffix ) ) {
	$cradle_uploads = wp_upload_dir( null, false );
	$cradle_roots[] = rtrim( wp_normalize_path( $cradle_uploads['basedir'] ), '/' ) . '/cradle-private-' . $cradle_suffix;
}
foreach ( $cradle_roots as $cradle_root ) {
	$cradle_dir = $cradle_root . '/migration';
	if ( ! is_dir( $cradle_dir ) || is_link( $cradle_dir ) ) {
		continue;
	}
	$cradle_items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $cradle_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $cradle_items as $cradle_item ) {
		if ( $cradle_item->isDir() && ! $cradle_item->isLink() ) {
			rmdir( $cradle_item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		} else {
			wp_delete_file( $cradle_item->getPathname() );
		}
	}
	rmdir( $cradle_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

// Capabilities.
foreach ( array_keys( wp_roles()->roles ) as $cradle_role_name ) {
	$cradle_role = get_role( $cradle_role_name );
	if ( $cradle_role ) {
		$cradle_role->remove_cap( 'cradle_export_site' );
		$cradle_role->remove_cap( 'cradle_import_site' );
	}
}
$cradle_granted = get_option( 'cradle_granted_capabilities' );
if ( is_array( $cradle_granted ) ) {
	unset( $cradle_granted['cradle_export_site'], $cradle_granted['cradle_import_site'] );
	update_option( 'cradle_granted_capabilities', $cradle_granted, false );
}
