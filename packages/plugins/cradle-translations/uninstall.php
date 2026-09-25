<?php
/**
 * Cradle Translations uninstall. Runs without the plugin or core loaded.
 *
 * Policy: retain by default. Catalogs, history, settings and the capability are removed only
 * when an administrator set the uninstall policy of `translations` to "purge" in
 * the “When deleted” column of Cradle → Overview. Paths are re-derived and re-validated here; only files with
 * the names this package writes are deleted, and symlinks are never followed.
 *
 * @package Cradle\Translations
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cradle_translations_policies = get_option( 'cradle_uninstall_policy', array() );
if ( ! is_array( $cradle_translations_policies ) || 'purge' !== ( $cradle_translations_policies['translations'] ?? 'retain' ) ) {
	return;
}

wp_clear_scheduled_hook( 'cradle_translations_refresh' );

// Private storage root, derived exactly as Cradle core does.
$cradle_translations_root = '';
if ( defined( 'CRADLE_PRIVATE_DIR' ) && is_string( CRADLE_PRIVATE_DIR ) && '' !== CRADLE_PRIVATE_DIR ) {
	$cradle_translations_root = (string) realpath( CRADLE_PRIVATE_DIR );
} else {
	$cradle_translations_suffix  = get_option( 'cradle_private_dir_suffix' );
	$cradle_translations_uploads = wp_upload_dir( null, false );
	if ( is_string( $cradle_translations_suffix ) && preg_match( '/^[a-z0-9]{20}$/', $cradle_translations_suffix ) && empty( $cradle_translations_uploads['error'] ) ) {
		$cradle_translations_root = (string) realpath( $cradle_translations_uploads['basedir'] . '/cradle-private-' . $cradle_translations_suffix );
	}
}

if ( '' !== $cradle_translations_root && is_dir( $cradle_translations_root . '/translations' ) && ! is_link( $cradle_translations_root . '/translations' ) ) {
	$cradle_translations_base  = wp_normalize_path( (string) realpath( $cradle_translations_root . '/translations' ) );
	$cradle_translations_rootn = wp_normalize_path( $cradle_translations_root );
	if ( 0 === strpos( $cradle_translations_base, $cradle_translations_rootn . '/' ) ) {
		$cradle_translations_patterns = array(
			'catalogs' => '/^[A-Za-z0-9_]+\.json$|^\.[A-Za-z0-9_]+\.[a-z0-9]+\.tmp$/',
			'history'  => '/^[A-Za-z0-9_]+\.r\d+\.json$/',
			'runtime'  => '/^[A-Za-z0-9_]+\.json$|^\.[A-Za-z0-9_]+\.[a-z0-9]+\.tmp$/',
			'manifest' => '/^manifest\.json$|^\.manifest\.[a-z0-9]+\.tmp$/',
		);
		foreach ( $cradle_translations_patterns as $cradle_translations_sub => $cradle_translations_pattern ) {
			$cradle_translations_dir = $cradle_translations_base . '/' . $cradle_translations_sub;
			if ( ! is_dir( $cradle_translations_dir ) || is_link( $cradle_translations_dir ) ) {
				continue;
			}
			foreach ( (array) scandir( $cradle_translations_dir ) as $cradle_translations_file ) {
				$cradle_translations_path = $cradle_translations_dir . '/' . $cradle_translations_file;
				if ( ! is_string( $cradle_translations_file ) || is_link( $cradle_translations_path ) || ! is_file( $cradle_translations_path ) ) {
					continue;
				}
				if ( preg_match( $cradle_translations_pattern, $cradle_translations_file ) || in_array( $cradle_translations_file, array( '.htaccess', 'web.config', 'index.php' ), true ) ) {
					wp_delete_file( $cradle_translations_path );
				}
			}
			@rmdir( $cradle_translations_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- only succeeds when empty.
		}
		foreach ( array( '.htaccess', 'web.config', 'index.php' ) as $cradle_translations_file ) {
			if ( is_file( $cradle_translations_base . '/' . $cradle_translations_file ) && ! is_link( $cradle_translations_base . '/' . $cradle_translations_file ) ) {
				wp_delete_file( $cradle_translations_base . '/' . $cradle_translations_file );
			}
		}
		@rmdir( $cradle_translations_base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- only succeeds when empty.
	}
}

foreach ( array( 'cradle_translations_settings', 'cradle_translations_index', 'cradle_translations_state', 'cradle_translations_version', 'cradle_translations_examples' ) as $cradle_translations_option ) {
	delete_option( $cradle_translations_option );
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_cradle_translations_notice_' ) . '%', $wpdb->esc_like( '_transient_timeout_cradle_translations_notice_' ) . '%' ) );

foreach ( array_keys( wp_roles()->roles ) as $cradle_translations_role_name ) {
	$cradle_translations_role = get_role( $cradle_translations_role_name );
	if ( $cradle_translations_role && $cradle_translations_role->has_cap( 'cradle_manage_translations' ) ) {
		$cradle_translations_role->remove_cap( 'cradle_manage_translations' );
	}
}
$cradle_translations_granted = get_option( 'cradle_granted_capabilities', array() );
if ( is_array( $cradle_translations_granted ) ) {
	unset( $cradle_translations_granted['cradle_manage_translations'] );
	update_option( 'cradle_granted_capabilities', $cradle_translations_granted, false );
}

unset( $cradle_translations_policies['translations'] );
update_option( 'cradle_uninstall_policy', $cradle_translations_policies, false );
