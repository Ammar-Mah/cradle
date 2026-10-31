<?php
/**
 * Test-only must-use plugin for tests/integration/languages.php. One test copies it to
 * wp-content/mu-plugins/ and removes it again (cl_cleanup() removes it too).
 *
 * Registers a custom post type that is not public but has admin screens (as Cradle Content
 * registers non-public types shown through theme lists) and, on frontend requests with
 * `?cl_ui_list=1`, answers with the titles of its published entries as JSON (an ordinary
 * get_posts() query, as a theme list would run it).
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function () {
		register_post_type(
			'cl_ui_type',
			array(
				'public'   => false,
				'show_ui'  => true,
				'label'    => 'CL UI entries',
				'supports' => array( 'title' ),
			)
		);
	},
	5
);

add_action(
	'template_redirect',
	static function () {
		if ( ! isset( $_GET['cl_ui_list'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only test listing.
			return;
		}
		$titles = wp_list_pluck(
			get_posts(
				array(
					'post_type'   => 'cl_ui_type',
					'post_status' => 'publish',
					'numberposts' => 50,
					'orderby'     => 'title',
					'order'       => 'ASC',
				)
			),
			'post_title'
		);
		wp_send_json( array_values( $titles ) );
	},
	0
);
