<?php
/**
 * Test fixture: a post type and taxonomy registered in code by "other code" (not Cradle Content).
 *
 * tests/integration/content.php copies this file into the sandbox as a must-use plugin for one
 * test (so real admin-post requests see the keys) and removes it afterwards. Never packaged.
 *
 * Plugin Name: Cradle test fixture — foreign content types
 *
 * @package Cradle\Tests
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function () {
		register_taxonomy(
			'cctest_foreign_tax',
			array(),
			array(
				'public'  => true,
				'rewrite' => array( 'slug' => 'cctest-foreign-tax' ),
			)
		);
		register_post_type(
			'cctest_foreign',
			array(
				'public'      => true,
				'label'       => 'Foreign fixture',
				'has_archive' => false,
				'rewrite'     => array( 'slug' => 'cctest-foreign-items' ),
			)
		);
	},
	1
);
