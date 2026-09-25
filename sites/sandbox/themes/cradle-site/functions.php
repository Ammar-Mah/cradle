<?php
/**
 * Cradle Sandbox child theme: site-specific PHP only. Styles, scripts and components in this folder are
 * loaded by the Cradle parent theme by convention (parent docs/child-themes.md).
 *
 * @package Cradle Sandbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'cradle_site_entity_id' ) ) {
	/**
	 * ID of a record created by the demo provisioning (for example `navigation:primary`), or 0.
	 * Verifies the record still carries the matching source key. Used by the navigation patterns.
	 *
	 * @param string $key Stable source key.
	 */
	function cradle_site_entity_id( $key ) {
		$registry = get_option( 'cradle_site_provisioning', array() );
		$id       = is_array( $registry ) ? (int) ( $registry[ $key ]['id'] ?? 0 ) : 0;
		if ( ! $id ) {
			return 0;
		}
		if ( 'term' === ( $registry[ $key ]['type'] ?? '' ) ) {
			return get_term_meta( $id, '_cradle_source_key', true ) === $key ? $id : 0;
		}
		return get_post_meta( $id, '_cradle_source_key', true ) === $key && 'trash' !== get_post_status( $id ) ? $id : 0;
	}
}
