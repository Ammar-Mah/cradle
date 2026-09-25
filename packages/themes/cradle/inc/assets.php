<?php
/**
 * Parent presentation assets. Load order (documented in docs/extension-points.md):
 * 1. cradle-base (parent defaults)
 * 2. cradle-child-design (child assets/css/design.css, loaded by inc/child-theme.php)
 * 3. component styles (enqueued on demand by the component render service)
 * 4. cradle-child-site (child assets/css/site.css, after the design style)
 *
 * @package Cradle
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'cradle_theme_enqueue_assets', 5 );
add_action( 'wp_head', 'cradle_theme_js_class', 1 );

/**
 * Enqueues the minimal parent stylesheet.
 */
function cradle_theme_enqueue_assets() {
	$file = get_template_directory() . '/assets/css/base.css';
	wp_enqueue_style(
		'cradle-base',
		get_template_directory_uri() . '/assets/css/base.css',
		array(),
		CRADLE_THEME_VERSION . ( is_file( $file ) ? '.' . filemtime( $file ) : '' )
	);
}

/**
 * Marks the document as JavaScript-capable so components can progressively enhance.
 */
function cradle_theme_js_class() {
	wp_print_inline_script_tag( "document.documentElement.classList.add('cradle-js');" );
}
