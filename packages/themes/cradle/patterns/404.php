<?php
/**
 * Title: Page not found
 * Slug: cradle/404
 * Inserter: no
 *
 * @package Cradle
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:heading {"level":1,"fontSize":"xx-large"} -->
<h1 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'Page not found', 'cradle' ); ?></h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'The page you requested could not be found. It may have moved, or the address may be mistyped.', 'cradle' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:search {"label":"<?php echo esc_attr_x( 'Search', 'search form label', 'cradle' ); ?>","buttonText":"<?php echo esc_attr_x( 'Search', 'search button text', 'cradle' ); ?>"} /-->
