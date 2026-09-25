<?php
/**
 * Hand-maintained dependency manifest (no build step). Versioned by file time.
 * `cradle-social-editor` (shared controls) is registered by Module::boot().
 *
 * @package Cradle\Social
 */

return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'cradle-social-editor' ),
	'version'      => (string) filemtime( __DIR__ . '/editor.js' ),
);
