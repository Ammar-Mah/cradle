<?php
/**
 * Cradle Languages uninstall.
 *
 * Retain by default. Only when an administrator chose "purge" for the `languages` module in the
 * "When deleted" column of Cradle → Overview is this module's own data removed:
 * its options and transients, the hidden `cradle_language` taxonomy (terms and their
 * relationships), `_cradle_lang_*` post meta, `_cradle_lang*` term meta and the capability.
 * Posts, pages and terms themselves are site content and are never deleted; saved
 * `cradle/language-*` blocks stay in content (the switcher then renders nothing, a language
 * section shows its inner blocks for every language).
 *
 * Runs without the plugin or core loaded.
 *
 * @package Cradle\Languages
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cradle_languages_policies = get_option( 'cradle_uninstall_policy', array() );
if ( ! is_array( $cradle_languages_policies ) || 'purge' !== ( $cradle_languages_policies['languages'] ?? 'retain' ) ) {
	return;
}

global $wpdb;

delete_option( 'cradle_languages_settings' );
delete_option( 'cradle_languages_installed_version' );
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_cradle_languages_' ) . '%', $wpdb->esc_like( '_transient_timeout_cradle_languages_' ) . '%' ) );

// The taxonomy is not registered while the plugin is inactive: remove its rows directly.
$cradle_languages_tt = $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id, term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'cradle_language' ) );
foreach ( (array) $cradle_languages_tt as $cradle_languages_row ) {
	$wpdb->delete( $wpdb->term_relationships, array( 'term_taxonomy_id' => (int) $cradle_languages_row->term_taxonomy_id ) );
	$wpdb->delete( $wpdb->term_taxonomy, array( 'term_taxonomy_id' => (int) $cradle_languages_row->term_taxonomy_id ) );
	$cradle_languages_shared = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d", (int) $cradle_languages_row->term_id ) );
	if ( 0 === $cradle_languages_shared ) {
		$wpdb->delete( $wpdb->termmeta, array( 'term_id' => (int) $cradle_languages_row->term_id ) );
		$wpdb->delete( $wpdb->terms, array( 'term_id' => (int) $cradle_languages_row->term_id ) );
	}
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_cradle_lang_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_cradle_lang' ) . '%' ) );
// phpcs:enable

foreach ( array_keys( wp_roles()->roles ) as $cradle_languages_role_name ) {
	$cradle_languages_role = get_role( $cradle_languages_role_name );
	if ( $cradle_languages_role && $cradle_languages_role->has_cap( 'cradle_manage_languages' ) ) {
		$cradle_languages_role->remove_cap( 'cradle_manage_languages' );
	}
}
$cradle_languages_granted = get_option( 'cradle_granted_capabilities', array() );
if ( is_array( $cradle_languages_granted ) ) {
	unset( $cradle_languages_granted['cradle_manage_languages'] );
	update_option( 'cradle_granted_capabilities', $cradle_languages_granted, false );
}

wp_cache_flush();

unset( $cradle_languages_policies['languages'] );
update_option( 'cradle_uninstall_policy', $cradle_languages_policies, false );
