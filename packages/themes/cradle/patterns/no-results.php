<?php
/**
 * Title: No results
 * Slug: cradle/no-results
 * Inserter: no
 *
 * @package Cradle
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:paragraph -->
<p><?php esc_html_e( 'Nothing was found. Try a different search.', 'cradle' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:search {"label":"<?php echo esc_attr_x( 'Search', 'search form label', 'cradle' ); ?>","buttonText":"<?php echo esc_attr_x( 'Search', 'search button text', 'cradle' ); ?>"} /-->
