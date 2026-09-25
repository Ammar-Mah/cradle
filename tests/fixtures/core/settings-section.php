<?php
/**
 * Test fixture: a settings section owned by nobody but the core test suite.
 *
 * tests/integration/core.php copies this file into the sandbox as a
 * must-use plugin for the duration of one test (so real options.php requests
 * see the section) and removes it, with its option, afterwards. It is never
 * packaged and never installed on a real site.
 *
 * Plugin Name: Cradle test fixture — settings section
 *
 * @package Cradle\Tests
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'cradle_test_fixture_settings_section' ) ) {
	function cradle_test_fixture_settings_section() {
		if ( ! function_exists( 'cradle' ) || ! cradle() || cradle()->settings()->section( 'fixture' ) ) {
			return;
		}
		cradle()->settings()->register_section(
			array(
				'id'            => 'fixture',
				'owner'         => 'core',
				'title'         => 'Cradle test fixture settings',
				'description'   => 'Registered only while the core test suite runs.',
				'capability'    => 'cradle_manage_settings',
				'schemaVersion' => 1,
				'page'          => true,
				'menu_title'    => 'Test fixture',
				'autoload'      => false,
				'fields'        => array(
					'retention_days' => array(
						'type'    => 'integer',
						'default' => 90,
						'minimum' => 1,
						'maximum' => 365,
						'label'   => 'Retention (days)',
					),
					'max_rows'       => array(
						'type'    => 'integer',
						'default' => 5000,
						'minimum' => 100,
						'maximum' => 50000,
						'label'   => 'Maximum rows',
					),
				),
			)
		);
	}

	if ( did_action( 'cradle/booted' ) ) {
		cradle_test_fixture_settings_section();
	} else {
		add_action( 'cradle/booted', 'cradle_test_fixture_settings_section' );
	}
}
