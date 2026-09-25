<?php
/**
 * The website child-theme contract (docs/child-themes.md).
 *
 * A Cradle website's child theme carries site data only: brief and records, theme.json tokens, the
 * templates/parts/patterns it overrides, CSS/JS files and site components. It needs no PHP: this file
 * loads what the child provides by convention, so parent updates improve that behavior without editing
 * the child. Every convention is optional; only files that exist are loaded, only while a child is active.
 *
 * - assets/css/design.css → style `cradle-child-design` (after `cradle-base`), also an editor style
 * - assets/css/site.css   → style `cradle-child-site` (after the design style), also an editor style
 * - assets/js/site.js     → script `cradle-child-site` (front end, deferred, in the footer)
 * - components/<slug>/component.json + render.php (+ blocks/<slug>/block.json) → Cradle component (+ block),
 *   registered while the Cradle core plugin is active
 *
 * @package Cradle
 */

defined( 'ABSPATH' ) || exit;

/** Child stylesheets in load order: handle => path inside the child theme. */
const CRADLE_THEME_CHILD_STYLES = array(
	'cradle-child-design' => 'assets/css/design.css',
	'cradle-child-site'   => 'assets/css/site.css',
);

add_action( 'after_setup_theme', 'cradle_theme_child_editor_styles', 20 );
add_action( 'wp_enqueue_scripts', 'cradle_theme_child_assets', 20 );
add_action( 'cradle/booted', 'cradle_theme_register_child_components' );

/**
 * Directory of the active child theme, or '' when the parent itself is active.
 */
function cradle_theme_child_dir(): string {
	return is_child_theme() ? get_stylesheet_directory() : '';
}

/**
 * Adds the child's stylesheets to the block editor (relative paths resolve to the child first).
 */
function cradle_theme_child_editor_styles() {
	$dir = cradle_theme_child_dir();
	if ( '' === $dir ) {
		return;
	}
	foreach ( CRADLE_THEME_CHILD_STYLES as $file ) {
		if ( is_file( $dir . '/' . $file ) ) {
			add_editor_style( $file );
		}
	}
}

/**
 * Enqueues the child's stylesheets and script on the front end.
 */
function cradle_theme_child_assets() {
	$dir = cradle_theme_child_dir();
	if ( '' === $dir ) {
		return;
	}
	$uri  = get_stylesheet_directory_uri();
	$deps = array( 'cradle-base' );
	foreach ( CRADLE_THEME_CHILD_STYLES as $handle => $file ) {
		if ( is_file( $dir . '/' . $file ) ) {
			wp_enqueue_style( $handle, $uri . '/' . $file, $deps, (string) filemtime( $dir . '/' . $file ) );
			$deps = array( $handle );
		}
	}
	if ( is_file( $dir . '/assets/js/site.js' ) ) {
		wp_enqueue_script(
			'cradle-child-site',
			$uri . '/assets/js/site.js',
			array(),
			(string) filemtime( $dir . '/assets/js/site.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}
}

/**
 * Registers the child's own Cradle components: every components/<slug>/ with a component.json and a
 * render.php, plus blocks/<slug>/ as its Gutenberg block when that folder exists. render.php receives
 * $props (normalized), $context and $component and must escape its output.
 *
 * @param \Cradle\Core\Plugin $core Cradle core (passed by `cradle/booted`).
 */
function cradle_theme_register_child_components( $core ) {
	$dir = cradle_theme_child_dir();
	if ( '' === $dir || ! is_object( $core ) || ! method_exists( $core, 'components' ) ) {
		return;
	}
	foreach ( glob( $dir . '/components/*/component.json' ) ?: array() as $descriptor ) {
		$slug   = basename( dirname( $descriptor ) );
		$render = dirname( $descriptor ) . '/render.php';
		if ( ! preg_match( '/^[a-z0-9-]+$/', $slug ) || ! is_file( $render ) ) {
			continue;
		}
		$handlers = array(
			'render' => static function ( array $props, array $context = array(), array $component = array() ) use ( $render ) {
				ob_start();
				include $render;
				return (string) ob_get_clean();
			},
		);
		if ( is_file( $dir . '/blocks/' . $slug . '/block.json' ) ) {
			$handlers['blockDir'] = $dir . '/blocks/' . $slug;
		}
		$core->components()->register_from_file( $descriptor, $handlers );
	}
}
