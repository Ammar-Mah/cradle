<?php
/**
 * Hand-maintained dependency manifest (no build step). Versioned by file time.
 * `cradle-forms-fields` (shared editor data `cradleFormsEditor`) is registered by Module::boot().
 *
 * @package Cradle\Forms
 */

return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render', 'cradle-forms-fields' ),
	'version'      => (string) filemtime( __DIR__ . '/editor.js' ),
);
