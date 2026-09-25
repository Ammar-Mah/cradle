<?php
/**
 * Theme supports and editor setup.
 *
 * @package Cradle
 */

defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', 'cradle_theme_setup' );

/**
 * Registers theme supports. Block themes receive most supports automatically.
 */
function cradle_theme_setup() {
	add_theme_support( 'responsive-embeds' );
	add_editor_style( 'assets/css/base.css' );

	/**
	 * Lets child themes declare additional supports
	 * (for example `woocommerce` on a commerce site) at the right time.
	 */
	do_action( 'cradle_theme/setup' );
}
