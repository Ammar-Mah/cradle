<?php
/**
 * Title: Footer columns
 * Slug: cradle-site/footer-columns
 * Inserter: no
 *
 * @package Cradle Sandbox
 */

defined( 'ABSPATH' ) || exit;

$cradle_site_footer_nav = function_exists( 'cradle_site_entity_id' ) ? cradle_site_entity_id( 'navigation:footer' ) : 0;
$cradle_site_nav_attrs  = array(
	'overlayMenu' => 'never',
	'className'   => 'hp-footer-nav',
	'layout'      => array(
		'type'        => 'flex',
		'orientation' => 'vertical',
	),
	'style'       => array( 'spacing' => array( 'blockGap' => '0.35rem' ) ),
);
if ( $cradle_site_footer_nav ) {
	$cradle_site_nav_attrs['ref'] = $cradle_site_footer_nav;
}
?>
<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"left":"2.5rem"}}}} -->
<div class="wp-block-columns alignwide"><!-- wp:column {"width":"50%"} -->
<div class="wp-block-column" style="flex-basis:50%"><!-- wp:site-title {"level":0,"className":"hp-footer-brand"} /-->

<!-- wp:paragraph {"className":"hp-footer-address"} -->
<p class="hp-footer-address"><?php echo wp_kses( __( '12 Quay Street<br>Harborton', 'cradle-site' ), array( 'br' => array() ) ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column {"width":"25%"} -->
<div class="wp-block-column" style="flex-basis:25%"><!-- wp:heading {"level":2,"className":"hp-footer-heading"} -->
<h2 class="wp-block-heading hp-footer-heading"><?php esc_html_e( 'Studio', 'cradle-site' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:navigation <?php echo wp_json_encode( $cradle_site_nav_attrs ); ?> /--></div>
<!-- /wp:column -->

<!-- wp:column {"width":"25%"} -->
<div class="wp-block-column" style="flex-basis:25%"><!-- wp:heading {"level":2,"className":"hp-footer-heading"} -->
<h2 class="wp-block-heading hp-footer-heading"><?php esc_html_e( 'Follow', 'cradle-site' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:social-links {"showLabels":true,"className":"is-style-logos-only hp-footer-social","layout":{"type":"flex","orientation":"vertical"}} -->
<ul class="wp-block-social-links has-visible-labels is-style-logos-only hp-footer-social"><!-- wp:social-link {"url":"https://www.instagram.com/","service":"instagram","label":"Instagram"} /-->

<!-- wp:social-link {"url":"https://www.linkedin.com/","service":"linkedin","label":"LinkedIn"} /--></ul>
<!-- /wp:social-links --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:group {"align":"wide","className":"hp-footer-bottom","style":{"border":{"top":{"color":"#ffffff26","width":"1px"}},"spacing":{"margin":{"top":"2.5rem"},"padding":{"top":"1.25rem"}}},"layout":{"type":"default"}} -->
<div class="wp-block-group alignwide hp-footer-bottom" style="border-top-color:#ffffff26;border-top-width:1px;margin-top:2.5rem;padding-top:1.25rem"><!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size"><?php echo esc_html( sprintf( /* translators: %s: year */ __( '© %s Harbor & Pine Studio', 'cradle-site' ), gmdate( 'Y' ) ) ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
