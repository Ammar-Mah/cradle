<?php
/**
 * Title: Primary navigation
 * Slug: cradle-site/primary-navigation
 * Inserter: no
 *
 * Resolves the site's primary navigation entity (provisioned with the stable source key
 * `navigation:primary`). Falls back to the core page list only until that entity exists.
 *
 * @package Cradle Sandbox
 */

defined( 'ABSPATH' ) || exit;

$cradle_site_nav = function_exists( 'cradle_site_entity_id' ) ? cradle_site_entity_id( 'navigation:primary' ) : 0;
$cradle_site_attrs = array(
	'overlayMenu' => 'mobile',
	'className'   => 'hp-primary-nav',
	'layout'      => array(
		'type'           => 'flex',
		'justifyContent' => 'right',
	),
	'style'       => array( 'spacing' => array( 'blockGap' => '2rem' ) ),
);
if ( $cradle_site_nav ) {
	$cradle_site_attrs['ref'] = $cradle_site_nav;
}
?>
<!-- wp:navigation <?php echo wp_json_encode( $cradle_site_attrs ); ?> /-->
