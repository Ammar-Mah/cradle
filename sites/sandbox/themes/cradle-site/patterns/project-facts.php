<?php
/**
 * Title: Project facts
 * Slug: cradle-site/project-facts
 * Inserter: no
 *
 * Client, location and completion year: Cradle Content fields of the "Project facts" group, shown
 * through the `cradle-content/field` block binding source.
 *
 * @package Cradle Sandbox
 */

defined( 'ABSPATH' ) || exit;

$cradle_site_facts = array(
	'client'   => __( 'Client', 'cradle-site' ),
	'location' => __( 'Location', 'cradle-site' ),
	'year'     => __( 'Completed', 'cradle-site' ),
);
?>
<!-- wp:group {"align":"wide","className":"hp-project-facts","layout":{"type":"grid","columnCount":3,"minimumColumnWidth":null}} -->
<div class="wp-block-group alignwide hp-project-facts">
<?php foreach ( $cradle_site_facts as $cradle_site_key => $cradle_site_label ) : ?>
<!-- wp:group {"layout":{"type":"default"}} -->
<div class="wp-block-group"><!-- wp:paragraph {"className":"hp-fact-label","fontSize":"small","textColor":"muted"} -->
<p class="hp-fact-label has-muted-color has-text-color has-small-font-size"><?php echo esc_html( $cradle_site_label ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"cradle-content/field","args":{"key":"<?php echo esc_attr( $cradle_site_key ); ?>"}}}}} -->
<p></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
<?php endforeach; ?>
</div>
<!-- /wp:group -->

<!-- wp:heading {"align":"wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|50"}}}} -->
<h2 class="wp-block-heading alignwide" style="margin-top:var(--wp--preset--spacing--50)"><?php esc_html_e( 'More projects', 'cradle-site' ); ?></h2>
<!-- /wp:heading -->
