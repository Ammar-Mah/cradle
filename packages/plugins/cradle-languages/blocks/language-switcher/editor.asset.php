<?php
/**
 * Hand-maintained dependency manifest (no build step). Versioned by file time.
 * `cradle-languages-editor` (configured languages for the editor) is registered by Editor::register().
 *
 * @package Cradle\Languages
 */

return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render', 'cradle-languages-editor' ),
	'version'      => (string) filemtime( __DIR__ . '/editor.js' ),
);
