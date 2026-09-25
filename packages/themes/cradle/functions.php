<?php
/**
 * Cradle parent theme bootstrap. Requires small single-purpose files only.
 *
 * This theme renders a valid minimal site with or without the Cradle plugins. Website child themes need
 * no PHP: inc/child-theme.php loads their styles, script and components by convention
 * (docs/child-themes.md). A child may still add a functions.php for site-specific code.
 *
 * @package Cradle
 */

defined( 'ABSPATH' ) || exit;

define( 'CRADLE_THEME_VERSION', '0.1.1' );

require_once get_template_directory() . '/inc/setup.php';
require_once get_template_directory() . '/inc/assets.php';
require_once get_template_directory() . '/inc/child-theme.php';
