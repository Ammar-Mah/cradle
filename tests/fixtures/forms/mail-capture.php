<?php
/**
 * Test-only mu-plugin for tests/integration/forms.php. The suite copies it into
 * wp/wp-content/mu-plugins/ and removes it in its shutdown cleanup.
 *
 * Records (instead of sending) emails whose subject contains "CF Test" in the option
 * `cradle_forms_test_mails`, so notification routing can be checked over real HTTP.
 * Every other email is left untouched.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, $atts ) {
		$subject = (string) ( $atts['subject'] ?? '' );
		if ( false === strpos( $subject, 'CF Test' ) ) {
			return $short_circuit;
		}
		$log   = get_option( 'cradle_forms_test_mails', array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'to'          => (array) ( $atts['to'] ?? array() ),
			'subject'     => $subject,
			'message'     => (string) ( $atts['message'] ?? '' ),
			'headers'     => is_array( $atts['headers'] ?? null ) ? $atts['headers'] : explode( "\n", (string) ( $atts['headers'] ?? '' ) ),
			'attachments' => (array) ( $atts['attachments'] ?? array() ),
		);
		update_option( 'cradle_forms_test_mails', $log, false );
		return true;
	},
	10,
	2
);
