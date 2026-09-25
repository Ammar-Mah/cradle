<?php
/**
 * Title: Footer credit
 * Slug: cradle/footer-credit
 * Inserter: no
 *
 * @package Cradle
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size"><?php echo esc_html( sprintf( /* translators: %s: current year */ __( '© %s', 'cradle' ), gmdate( 'Y' ) ) ); ?></p>
<!-- /wp:paragraph -->
