<?php
/**
 * Harbor & Pine demo content (reference fixture: design-reference/harbor-pine in the child theme).
 * Returned to Provisioner::run() by bin/provision.php. Callables receive the provisioner so they can
 * reference IDs/URLs of records defined earlier in this file; order matters.
 *
 * Projects are the Cradle Content post type `project` (with the `project_type` taxonomy and the
 * "Project facts" field group, see content-types.json); field values use `_cradle_cf_<key>` meta.
 *
 * All page content is native block markup: core blocks plus Cradle components. Text, media,
 * links and repeated items stay editable; lists of projects/articles are live queries.
 *
 * @package Cradle Sandbox
 */

use Cradle\Sandbox\Provisioner;

defined( 'ABSPATH' ) || exit;

$cradle_site_ref = get_theme_root( 'cradle-site' ) . '/cradle-site/design-reference/harbor-pine/images/';

/**
 * Serialized feature card (delegates to Cradle Blocks so markup equals the editor's save()).
 */
$cradle_site_card = static function ( array $attrs ): string {
	if ( class_exists( 'Cradle\\Blocks\\Feature_Card' ) ) {
		return \Cradle\Blocks\Feature_Card::block_markup( $attrs );
	}
	return '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">' . esc_html( $attrs['title'] ) . '</h3><!-- /wp:heading -->';
};

$cradle_site_image = static function ( Provisioner $p, string $key, string $ratio = '4/3' ): string {
	$id  = $p->id( $key );
	$src = $id ? wp_get_attachment_image_src( $id, 'large' ) : false;
	$alt = $id ? (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) : '';
	if ( ! $src ) {
		return '';
	}
	return '<!-- wp:image {"id":' . $id . ',"aspectRatio":"' . $ratio . '","scale":"cover","sizeSlug":"large","linkDestination":"none"} -->' . "\n"
		. '<figure class="wp-block-image size-large"><img src="' . esc_url( $src[0] ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $id . '" style="aspect-ratio:' . $ratio . ';object-fit:cover"/></figure>' . "\n"
		. '<!-- /wp:image -->';
};

$cradle_site_paragraphs = static function ( array $paragraphs ): string {
	$out = '';
	foreach ( $paragraphs as $text ) {
		$out .= '<!-- wp:paragraph -->' . "\n" . '<p>' . esc_html( $text ) . '</p>' . "\n" . '<!-- /wp:paragraph -->' . "\n\n";
	}
	return trim( $out );
};

$cradle_site_projects = array(
	'cedar-point'  => array( 'Cedar Point House', 'A family home stepping down to the water.', 'type:residential', 'Maren & Jo Lindqvist', 'Cedar Point', '2024', 'media:project-1' ),
	'old-mill'     => array( 'Old Mill Studios', 'Twelve maker studios in a restored mill.', 'type:restoration', 'Old Mill Cooperative', 'Harborton', '2023', 'media:project-2' ),
	'harbor-lib'   => array( 'Harbor Library', 'A reading room open to the tides.', 'type:workplace', 'Harborton Council', 'Harborton', '2025', 'media:project-3' ),
	'fern-cottage' => array( 'Fern Cottage', 'A quiet extension to a nineteenth-century cottage.', 'type:restoration', 'Private client', 'Upper Vale', '2022', 'media:project-4' ),
	'quay-offices' => array( 'Quay Street Offices', 'A calm workplace for a design team of forty.', 'type:workplace', 'Northfold Design', 'Harborton', '2024', 'media:project-5' ),
	'dune-house'   => array( 'Dune House', 'Low timber rooms sheltered from the sea wind.', 'type:residential', 'Private client', 'Sandreach', '2021', 'media:project-6' ),
);

$definitions = array(
	'identity'   => array(
		'name'    => 'Harbor & Pine',
		'tagline' => 'Architecture & interiors',
	),
	'media'      => array(
		'media:hero'   => array( 'file' => $cradle_site_ref . 'hero.jpg', 'title' => 'Timber house above the harbor', 'alt' => 'Timber house above a harbor at dusk' ),
		'media:studio' => array( 'file' => $cradle_site_ref . 'studio.jpg', 'title' => 'The studio', 'alt' => 'Drawing tables in a daylit studio' ),
	),
	'terms'      => array(
		'type:residential' => array( 'taxonomy' => 'project_type', 'name' => 'Residential', 'slug' => 'residential' ),
		'type:workplace'   => array( 'taxonomy' => 'project_type', 'name' => 'Workplace', 'slug' => 'workplace' ),
		'type:restoration' => array( 'taxonomy' => 'project_type', 'name' => 'Restoration', 'slug' => 'restoration' ),
		'cat:journal'      => array( 'taxonomy' => 'category', 'name' => 'Journal', 'slug' => 'journal' ),
	),
	'pages'      => array(),
	'projects'   => array(),
	'posts'      => array(),
	'navigation' => array(),
	'reading'    => array(
		'front' => 'page:home',
		'posts' => 'page:journal',
	),
);

for ( $i = 1; $i <= 6; $i++ ) {
	$definitions['media'][ 'media:project-' . $i ] = array(
		'file'  => $cradle_site_ref . 'project-' . $i . '.jpg',
		'title' => 'Project image ' . $i,
		'alt'   => '',
	);
}
foreach ( $cradle_site_projects as $cradle_site_slug => $cradle_site_project ) {
	$definitions['media'][ $cradle_site_project[6] ]['alt'] = $cradle_site_project[0] . ' — exterior view';
}

$definitions['pages']['page:contact'] = array(
	'post_type' => 'page',
	'title'     => 'Contact',
	'slug'      => 'contact',
	'content'   => $cradle_site_paragraphs(
		array(
			'Tell us about the place and the people. We reply within two working days.',
			'Studio: 12 Quay Street, Harborton.',
		)
	) . "\n\n" . '<!-- wp:paragraph -->' . "\n" . '<p><a href="mailto:studio@example.test">studio@example.test</a></p>' . "\n" . '<!-- /wp:paragraph -->',
);

$definitions['pages']['page:journal'] = array(
	'post_type' => 'page',
	'title'     => 'Journal',
	'slug'      => 'journal',
	'content'   => '',
);

$definitions['pages']['page:studio'] = static function ( Provisioner $p ) use ( $cradle_site_image, $cradle_site_paragraphs ) {
	// Hero slider (Cradle Blocks component placed in content): the three latest projects as slides.
	$slider = class_exists( 'Cradle\\Blocks\\Hero_Slider' ) ? \Cradle\Blocks\Hero_Slider::block_markup(
		array(
			'postType'     => 'project',
			'count'        => 3,
			'label'        => 'Selected projects',
			'buttonText'   => 'View project',
			'contentAlign' => 'start',
			'minHeight'    => '60vh',
		)
	) . "\n\n" : '';
	return array(
		'post_type' => 'page',
		'title'     => 'Studio',
		'slug'      => 'studio',
		'content'   => $slider . $cradle_site_image( $p, 'media:studio', '16/9' ) . "\n\n" . $cradle_site_paragraphs(
			array(
				'Harbor & Pine is a small practice of architects and interior designers working from a former sail loft on Quay Street.',
				'We take on a handful of projects each year so that every building gets the attention of the people who drew it.',
			)
		),
	);
};

$cradle_site_services = static function () use ( $cradle_site_card ) {
	return $cradle_site_card( array( 'title' => 'Residential', 'body' => 'New homes and extensions designed around daylight, storage and the rhythms of a household.', 'link' => home_url( '/project-type/residential/' ), 'linkText' => 'Explore residential', 'variant' => 'outlined' ) ) . "\n\n"
		. $cradle_site_card( array( 'title' => 'Workplace', 'body' => 'Studios and offices that support focus and conversation, built with healthy materials.', 'link' => home_url( '/project-type/workplace/' ), 'linkText' => 'Explore workplace', 'variant' => 'outlined' ) ) . "\n\n"
		. $cradle_site_card( array( 'title' => 'Restoration', 'body' => 'Careful repair and adaptation of older buildings, keeping their character and cutting their energy use.', 'link' => home_url( '/project-type/restoration/' ), 'linkText' => 'Explore restoration', 'variant' => 'outlined' ) );
};

$definitions['pages']['page:services'] = static function ( Provisioner $p ) use ( $cradle_site_services ) {
	return array(
		'post_type' => 'page',
		'title'     => 'Services',
		'slug'      => 'services',
		'content'   => '<!-- wp:paragraph {"textColor":"muted","fontSize":"lede"} -->' . "\n" . '<p class="has-muted-color has-text-color has-lede-font-size">From first sketch to the last fitted shelf, we lead the design and stay with the build.</p>' . "\n" . '<!-- /wp:paragraph -->' . "\n\n"
			. '<!-- wp:group {"align":"wide","className":"hp-services","style":{"spacing":{"blockGap":"1.75rem"}},"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"16rem"}} -->' . "\n" . '<div class="wp-block-group alignwide hp-services">' . $cradle_site_services() . '</div>' . "\n" . '<!-- /wp:group -->',
	);
};

$definitions['pages']['page:home'] = static function ( Provisioner $p ) use ( $cradle_site_image, $cradle_site_services ) {
	$projects = get_post_type_archive_link( 'project' ) ?: home_url( '/projects/' );
	$contact  = $p->url( 'page:contact' );
	$journal  = $p->url( 'page:journal' );
	$content  = <<<HTML
<!-- wp:group {"align":"full","className":"hp-hero","style":{"spacing":{"padding":{"top":"4.5rem","bottom":"5.5rem"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull hp-hero" style="padding-top:4.5rem;padding-bottom:5.5rem"><!-- wp:columns {"verticalAlignment":"center","align":"wide","style":{"spacing":{"blockGap":{"left":"3.5rem"}}}} -->
<div class="wp-block-columns alignwide are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"51.2%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:51.2%"><!-- wp:paragraph {"className":"hp-eyebrow"} -->
<p class="hp-eyebrow">Architecture &amp; interiors</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Architecture that listens to its place.</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"muted","fontSize":"lede"} -->
<p class="has-muted-color has-text-color has-lede-font-size">We design calm, durable buildings for families, workplaces and communities — shaped by light, landscape and the way people actually live.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"style":{"spacing":{"margin":{"top":"2rem"}}}} -->
<div class="wp-block-buttons" style="margin-top:2rem"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="{$projects}">See our work</a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="{$contact}">Start a project</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"center","width":"48.8%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:48.8%">{$cradle_site_image( $p, 'media:hero' )}</div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","className":"hp-services","style":{"spacing":{"padding":{"top":"6rem","bottom":"6rem"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull hp-services" style="padding-top:6rem;padding-bottom:6rem"><!-- wp:heading {"align":"wide"} -->
<h2 class="wp-block-heading alignwide">What we do</h2>
<!-- /wp:heading -->

<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"1.75rem"}},"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"16rem"}} -->
<div class="wp-block-group alignwide">{$cradle_site_services()}</div>
<!-- /wp:group --></div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","className":"hp-projects","style":{"spacing":{"padding":{"top":"6rem","bottom":"6rem"}}},"backgroundColor":"subtle","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull hp-projects has-subtle-background-color has-background" style="padding-top:6rem;padding-bottom:6rem"><!-- wp:group {"align":"wide","className":"hp-section-head","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"bottom"}} -->
<div class="wp-block-group alignwide hp-section-head"><!-- wp:heading -->
<h2 class="wp-block-heading">Selected work</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"hp-more"} -->
<p class="hp-more"><a href="{$projects}">All projects →</a></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:cradle/post-cards {"postType":"project","count":3,"align":"wide"} /--></div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","className":"hp-quote-band","style":{"spacing":{"padding":{"top":"6rem","bottom":"6rem"}}},"layout":{"type":"constrained","contentSize":"772px"}} -->
<div class="wp-block-group alignfull hp-quote-band" style="padding-top:6rem;padding-bottom:6rem"><!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p>“They listened to how we live before drawing a single line. The house feels like it has always been here.”</p>
<!-- /wp:paragraph --><cite>Maren &amp; Jo, Cedar Point House</cite></blockquote>
<!-- /wp:quote --></div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","className":"hp-journal","style":{"spacing":{"padding":{"top":"0","bottom":"6rem"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull hp-journal" style="padding-top:0;padding-bottom:6rem"><!-- wp:group {"align":"wide","className":"hp-section-head","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"bottom"}} -->
<div class="wp-block-group alignwide hp-section-head"><!-- wp:heading -->
<h2 class="wp-block-heading">From the journal</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"hp-more"} -->
<p class="hp-more"><a href="{$journal}">All articles →</a></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:cradle/post-cards {"count":3,"showImage":false,"align":"wide"} /--></div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","className":"hp-cta-band","style":{"spacing":{"padding":{"top":"5.5rem","bottom":"5.5rem"}}},"backgroundColor":"pine","textColor":"white","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull hp-cta-band has-white-color has-pine-background-color has-text-color has-background" style="padding-top:5.5rem;padding-bottom:5.5rem"><!-- wp:heading {"textColor":"white"} -->
<h2 class="wp-block-heading has-white-color has-text-color">Have a site in mind?</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Tell us about the place and the people. We reply within two working days.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"base","textColor":"contrast"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-contrast-color has-base-background-color has-text-color has-background wp-element-button" href="{$contact}">Start a conversation</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->
HTML;
	return array(
		'post_type' => 'page',
		'title'     => 'Home',
		'slug'      => 'home',
		'template'  => 'page-no-title',
		'content'   => $content,
	);
};

$cradle_site_offset = 0;
foreach ( $cradle_site_projects as $cradle_site_slug => $cradle_site_project ) {
	$definitions['projects'][ 'project:' . $cradle_site_slug ] = array(
		'post_type' => 'project',
		'title'     => $cradle_site_project[0],
		'slug'      => $cradle_site_slug,
		'excerpt'   => $cradle_site_project[1],
		'date'      => gmdate( 'Y-m-d H:i:s', strtotime( '2026-06-01 10:00:00' ) - ( $cradle_site_offset++ * DAY_IN_SECONDS * 20 ) ),
		'content'   => $cradle_site_paragraphs(
			array(
				$cradle_site_project[1] . ' The brief asked for rooms that change with the light and the seasons.',
				'We worked with local makers and kept materials simple: timber, lime and stone that will weather well.',
			)
		),
		'terms'     => array( 'project_type' => array( $cradle_site_project[2] ) ),
		'featured'  => $cradle_site_project[6],
		'meta'      => array(
			'_cradle_cf_client'   => $cradle_site_project[3],
			'_cradle_cf_location' => $cradle_site_project[4],
			'_cradle_cf_year'     => $cradle_site_project[5],
		),
	);
}

$cradle_site_posts = array(
	'low-winter-sun'   => array( 'Designing for low winter sun', 'How orientation and eaves do more than any gadget.', '2026-09-10 09:00:00' ),
	'timber-carbon'    => array( 'Timber, carbon and time', 'What a century-long view changes about material choices.', '2026-08-22 09:00:00' ),
	'restoring-mill'   => array( 'Restoring the Old Mill', 'Notes from two years on site.', '2026-08-01 09:00:00' ),
);
foreach ( $cradle_site_posts as $cradle_site_slug => $cradle_site_post ) {
	$definitions['posts'][ 'post:' . $cradle_site_slug ] = array(
		'post_type' => 'post',
		'title'     => $cradle_site_post[0],
		'slug'      => $cradle_site_slug,
		'excerpt'   => $cradle_site_post[1],
		'date'      => $cradle_site_post[2],
		'content'   => $cradle_site_paragraphs( array( $cradle_site_post[1], 'This article is part of the demonstration content for the Cradle sandbox.' ) ),
		'terms'     => array( 'category' => array( 'cat:journal' ) ),
	);
}

$cradle_site_nav_link = static function ( Provisioner $p, string $label, string $key, string $class = '' ): string {
	$attrs = array(
		'label' => $label,
		'type'  => 'page',
		'id'    => $p->id( $key ),
		'url'   => $p->url( $key ),
		'kind'  => 'post-type',
	);
	if ( $class ) {
		$attrs['className'] = $class;
	}
	return '<!-- wp:navigation-link ' . serialize_block_attributes( $attrs ) . ' /-->';
};

$definitions['navigation']['navigation:primary'] = static function ( Provisioner $p ) use ( $cradle_site_nav_link ) {
	$work = '<!-- wp:navigation-link ' . serialize_block_attributes(
		array(
			'label' => 'Work',
			'url'   => get_post_type_archive_link( 'project' ) ?: home_url( '/projects/' ),
			'kind'  => 'custom',
		)
	) . ' /-->';
	return array(
		'post_type' => 'wp_navigation',
		'title'     => 'Primary',
		'content'   => implode(
			"\n\n",
			array(
				$work,
				$cradle_site_nav_link( $p, 'Services', 'page:services' ),
				$cradle_site_nav_link( $p, 'Studio', 'page:studio' ),
				$cradle_site_nav_link( $p, 'Journal', 'page:journal' ),
				$cradle_site_nav_link( $p, 'Contact', 'page:contact', 'hp-nav-cta' ),
			)
		),
	);
};

$definitions['navigation']['navigation:footer'] = static function ( Provisioner $p ) use ( $cradle_site_nav_link ) {
	return array(
		'post_type' => 'wp_navigation',
		'title'     => 'Footer',
		'content'   => implode(
			"\n\n",
			array(
				$cradle_site_nav_link( $p, 'About', 'page:studio' ),
				$cradle_site_nav_link( $p, 'Services', 'page:services' ),
				$cradle_site_nav_link( $p, 'Journal', 'page:journal' ),
				$cradle_site_nav_link( $p, 'Contact', 'page:contact' ),
			)
		),
	);
};

return $definitions;
