<?php
/**
 * Hand-maintained dependency manifest (no build step). Versioned by file time.
 *
 * @package Cradle\Blocks
 */

return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-html-entities' ),
	'version'      => (string) filemtime( __DIR__ . '/editor.js' ),
);
