<?php
/**
 * Hero slider integration tests (Cradle Blocks component `cradle/hero-slider`, northstar §17 as
 * changed by the owner: a normal Gutenberg component, not a plugin; posts are the slides; style and
 * behavior are block settings).
 *
 * Fixtures are ordinary posts in a fixture category (so "latest entries" is deterministic even when the
 * sandbox has real posts), pages and local images, all marked `_cradle_test_fixture = hero` and removed
 * by the last test and again at shutdown.
 */

use Cradle\Blocks\Hero_Slider;

$GLOBALS['cradle_hero_fixtures']   = array();
$GLOBALS['cradle_hero_log_offset'] = is_file( WP_CONTENT_DIR . '/debug.log' ) ? filesize( WP_CONTENT_DIR . '/debug.log' ) : 0;

function cradle_hero_ready() {
	if ( ! function_exists( 'cradle' ) || ! cradle() || ! cradle()->modules()->is_ready( 'blocks' ) || ! class_exists( 'Cradle\\Blocks\\Hero_Slider' ) ) {
		skip_test( 'cradle-blocks is not active and ready in the sandbox.' );
	}
}

function cradle_hero_track( int $id ): int {
	if ( $id > 0 ) {
		$GLOBALS['cradle_hero_fixtures'][] = $id;
		update_post_meta( $id, '_cradle_test_fixture', 'hero' );
	}
	return $id;
}

function cradle_hero_cleanup() {
	global $wpdb;
	$ids = array_map( 'intval', $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cradle_test_fixture' AND meta_value = 'hero'" ) ); // phpcs:ignore
	$ids = array_unique( array_merge( $ids, $GLOBALS['cradle_hero_fixtures'] ) );
	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			continue;
		}
		if ( 'attachment' === $post->post_type ) {
			wp_delete_attachment( $id, true );
		} else {
			wp_delete_post( $id, true );
		}
	}
	$terms = get_terms(
		array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
			'search'     => 'cradle-hero-fixture',
			'fields'     => 'ids',
		)
	);
	foreach ( is_array( $terms ) ? $terms : array() as $term_id ) {
		wp_delete_term( (int) $term_id, 'category' );
	}
	$GLOBALS['cradle_hero_fixtures'] = array();
}
register_shutdown_function( 'cradle_hero_cleanup' );

/**
 * Local PNG attachment with generated metadata (no remote media).
 */
function cradle_hero_image( int $width = 960, int $height = 540, string $alt = 'Fixture image' ): int {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		skip_test( 'GD is not available to create a local fixture image.' );
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$img = imagecreatetruecolor( $width, $height );
	imagefill( $img, 0, 0, imagecolorallocate( $img, 40, 80, 140 ) );
	ob_start();
	imagepng( $img );
	$bytes  = ob_get_clean();
	$upload = wp_upload_bits( 'cradle-hero-fixture-' . strtolower( wp_generate_password( 6, false ) ) . '.png', null, $bytes );
	if ( ! empty( $upload['error'] ) ) {
		throw new Cradle_Test_Failure( 'Could not write fixture image: ' . $upload['error'] );
	}
	$id = (int) wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'Cradle hero fixture image',
			'post_status'    => 'inherit',
		),
		$upload['file']
	);
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	return cradle_hero_track( $id );
}

/**
 * A fixture category; filtering by it keeps "latest entries" limited to this test's posts.
 */
function cradle_hero_term(): string {
	$slug = 'cradle-hero-fixture-' . strtolower( wp_generate_password( 6, false, false ) );
	wp_insert_term( 'Cradle hero fixture ' . $slug, 'category', array( 'slug' => $slug ) );
	return $slug;
}

/**
 * A post in the fixture category (published unless $extra says otherwise).
 */
function cradle_hero_post( string $title, string $term, array $extra = array(), int $image = 0 ): int {
	$cat = get_term_by( 'slug', $term, 'category' );
	$id  = cradle_hero_track(
		(int) wp_insert_post(
			array_merge(
				array(
					'post_type'     => 'post',
					'post_status'   => 'publish',
					'post_title'    => $title,
					'post_excerpt'  => $title . ' excerpt',
					'post_category' => $cat ? array( (int) $cat->term_id ) : array(),
				),
				$extra
			)
		)
	);
	if ( $image ) {
		set_post_thumbnail( $id, $image );
	}
	return $id;
}

/**
 * Three published posts with explicit dates.
 *
 * @return array{0:string,1:int[]} Term slug and post IDs, newest first.
 */
function cradle_hero_three( string $prefix, array $images = array() ): array {
	$term = cradle_hero_term();
	$ids  = array();
	foreach ( array( 'one', 'two', 'three' ) as $i => $word ) {
		$ids[] = cradle_hero_post( $prefix . ' ' . $word, $term, array( 'post_date' => gmdate( 'Y-m-d H:i:s', time() - 3600 * ( $i + 1 ) ) ), $images[ $i ] ?? 0 );
	}
	return array( $term, $ids );
}

function cradle_hero_props( string $term, array $extra = array() ): array {
	return array_merge(
		array(
			'postType' => 'post',
			'taxonomy' => 'category',
			'term'     => $term,
		),
		$extra
	);
}

function cradle_hero_page( string $content, string $title = 'Hero fixture page' ): int {
	return cradle_hero_track(
		(int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => wp_slash( $content ),
			)
		)
	);
}

/**
 * Splits rendered HTML into top-level slider roots (by data-cradle-hero sections).
 */
function cradle_hero_sections( string $html ): array {
	preg_match_all( '#<section[^>]*data-cradle-hero=.*?</section>#s', $html, $m );
	return $m[0];
}

cradle_hero_cleanup(); // Leftovers from an interrupted earlier run.

cradle_test(
	'hero: the slider is a Cradle Blocks component and block; no hero plugin, post type, settings or capability remain',
	function () {
		cradle_hero_ready();
		$component = cradle()->components()->get( 'cradle/hero-slider' );
		assert_true( (bool) $component, 'Component registered.' );
		assert_same( 'cradle-blocks', $component['owner'] );
		assert_same( 'hero-slider-v2', $component['renderContract'] );
		assert_same( array( 'cradle-hero-slider-view' ), $component['assets']['viewScriptHandles'] );
		assert_true( wp_script_is( 'cradle-hero-slider-view', 'registered' ), 'View script registered (not enqueued) at boot.' );
		assert_false( wp_script_is( 'cradle-hero-slider-view', 'enqueued' ), 'View script is not enqueued before a slider renders.' );
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'cradle/hero-slider' );
		assert_true( (bool) $type, 'Block registered through the Gutenberg adapter.' );
		assert_same( 3, $type->api_version );
		$defaults = array(
			'postType'       => 'post',
			'postIds'        => array(),
			'count'          => 3,
			'autoplay'       => false,
			'overlayOpacity' => 40,
			'transition'     => 'fade',
			'minHeight'      => '70vh',
		);
		foreach ( $defaults as $name => $default ) {
			assert_same( $default, $type->attributes[ $name ]['default'], "Block attribute {$name} default." );
		}
		assert_true( in_array( 'cradle/hero-slider', cradle()->modules()->get( 'blocks' )['dataOwners']['blocks'], true ) );
		assert_false( (bool) cradle()->modules()->get( 'hero' ), 'No hero module.' );
		assert_false( post_type_exists( 'cradle_hero_item' ), 'No hero post type.' );
		assert_false( (bool) cradle()->settings()->section( 'hero' ), 'No global hero settings.' );
		assert_false( is_dir( WP_PLUGIN_DIR . '/cradle-hero' ), 'The retired Cradle Hero plugin is not installed.' );
		assert_false( get_role( 'editor' )->has_cap( 'cradle_manage_hero' ), 'The retired capability is gone.' );
	}
);

cradle_test(
	'hero: latest published entries become slides (featured image, title, excerpt, link) in the chosen order and number',
	function () {
		cradle_hero_ready();
		list( $term, $ids ) = cradle_hero_three( 'Latest' );
		cradle_hero_post( 'Latest draft', $term, array( 'post_status' => 'draft' ) );
		cradle_hero_post( 'Latest locked', $term, array( 'post_password' => 'secret', 'post_date' => gmdate( 'Y-m-d H:i:s', time() - 30 ) ) );

		$html = cradle_render_component( 'cradle/hero-slider', cradle_hero_props( $term ) );
		assert_true( strpos( $html, 'Latest one' ) < strpos( $html, 'Latest two' ) && strpos( $html, 'Latest two' ) < strpos( $html, 'Latest three' ), 'Newest first.' );
		assert_not_contains( 'Latest draft', $html, 'Drafts are never slides.' );
		assert_not_contains( 'Latest locked', $html, 'Password-protected entries are never slides.' );
		assert_contains( '<h2 class="cradle-hero__heading">Latest one</h2>', $html );
		assert_contains( '<p class="cradle-hero__text">Latest one excerpt</p>', $html );
		assert_contains( 'href="' . esc_url( get_permalink( $ids[0] ) ) . '">Read more<span class="cradle-hero__sr">: Latest one</span></a>', $html, 'Button links to the entry; screen readers hear its title.' );
		assert_contains( 'aria-label="3 of 3"', $html );

		$asc = cradle_render_component( 'cradle/hero-slider', cradle_hero_props( $term, array( 'order' => 'asc', 'count' => 2 ) ) );
		assert_true( strpos( $asc, 'Latest three' ) < strpos( $asc, 'Latest two' ), 'Ascending order.' );
		assert_not_contains( 'Latest one', $asc, 'Count limits the slides.' );

		$plain = cradle_render_component( 'cradle/hero-slider', cradle_hero_props( $term, array( 'showExcerpt' => false, 'showButton' => false, 'headingLevel' => 3 ) ) );
		assert_not_contains( 'cradle-hero__text', $plain );
		assert_not_contains( 'cradle-hero__button', $plain );
		assert_contains( '<h3 class="cradle-hero__heading">Latest one</h3>', $plain );
		$custom = cradle_render_component( 'cradle/hero-slider', cradle_hero_props( $term, array( 'buttonText' => 'Open story' ) ) );
		assert_contains( '>Open story<span class="cradle-hero__sr">: Latest one</span>', $custom );

		assert_same( '', cradle_render_component( 'cradle/hero-slider', array( 'postType' => 'attachment' ) ), 'Attachments are not a slide source.' );
		assert_same( '', cradle_render_component( 'cradle/hero-slider', array( 'postType' => 'no_such_type' ) ) );
		assert_same( '', cradle_render_component( 'cradle/hero-slider', cradle_hero_props( cradle_hero_term() ) ), 'No entries: nothing for visitors.' );
	}
);

cradle_test(
	'hero: chosen entries render in the chosen order; unavailable choices render nothing and never fall back',
	function () {
		cradle_hero_ready();
		list( $term, $ids ) = cradle_hero_three( 'Chosen' );
		$html               = cradle_render_component( 'cradle/hero-slider', array( 'postType' => 'post', 'postIds' => array( $ids[2], $ids[0] ) ) );
		assert_true( strpos( $html, 'Chosen three' ) < strpos( $html, 'Chosen one' ), 'Chosen order wins over dates.' );
		assert_not_contains( 'Chosen two', $html );
		assert_contains( 'aria-label="2 of 2"', $html );

		$page  = cradle_hero_page( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
		$mixed = cradle_render_component( 'cradle/hero-slider', array( 'postType' => 'post', 'postIds' => array( $page, $ids[1] ) ) );
		assert_contains( 'Chosen two', $mixed );
		assert_not_contains( 'Hero fixture page', $mixed, 'Entries of another content type are dropped.' );

		$draft = cradle_hero_post( 'Chosen draft', $term, array( 'post_status' => 'draft' ) );
		assert_same( '', cradle_render_component( 'cradle/hero-slider', array( 'postType' => 'post', 'postIds' => array( $draft ) ) ), 'An unpublished choice renders nothing.' );
		assert_same( '', cradle_render_component( 'cradle/hero-slider', array( 'postType' => 'post', 'postIds' => array( $page ) ) ), 'Only wrong-type choices: nothing, not the latest posts.' );
		$gone = cradle_hero_post( 'Chosen gone', $term );
		wp_delete_post( $gone, true );
		assert_same( '', cradle_render_component( 'cradle/hero-slider', array( 'postType' => 'post', 'postIds' => array( $gone ) ) ), 'Deleted choices do not fall back to the latest entries.' );
	}
);

cradle_test(
	'hero: style attributes set on the block are validated and applied (heights, colors, overlay, alignment, focus)',
	function () {
		cradle_hero_ready();
		$img          = cradle_hero_image( 960, 540, 'Harbor at dusk' );
		list( $term ) = cradle_hero_three( 'Style', array( $img, $img, $img ) );
		$html         = cradle_render_component(
			'cradle/hero-slider',
			cradle_hero_props(
				$term,
				array(
					'minHeight'       => '640px',
					'mobileMinHeight' => '55.5svh',
					'overlayColor'    => 'accent',
					'overlayOpacity'  => 60,
					'textColor'       => 'contrast',
					'backgroundColor' => 'base',
					'controlsColor'   => 'accent',
					'contentAlign'    => 'end',
					'contentWidth'    => 'wide',
					'textPlacement'   => 'bottom',
					'focalX'          => 20,
					'focalY'          => 80,
					'label'           => 'Studio highlights',
				)
			)
		);
		assert_contains( '--cradle-hero-min-height:640px', $html );
		assert_contains( '--cradle-hero-min-height-mobile:55.5svh', $html );
		assert_contains( '--cradle-hero-bg:var(--wp--preset--color--base, #111111)', $html );
		assert_contains( '--cradle-hero-controls:var(--wp--preset--color--accent, #ffffff)', $html );
		assert_contains( 'background-color:var(--wp--preset--color--accent, #000000);opacity:0.6', $html );
		assert_contains( '--cradle-hero-text:var(--wp--preset--color--contrast, #ffffff)', $html );
		assert_contains( 'cradle-hero__slide is-align-end is-width-wide is-active', $html );
		assert_contains( 'is-placement-bottom', $html );
		assert_contains( 'object-position:20% 80%', $html );
		assert_contains( 'aria-label="Studio highlights"', $html );
		assert_contains( 'alt="Harbor at dusk"', $html, 'Media-library alternative text.' );

		$bad = cradle_render_component( 'cradle/hero-slider', cradle_hero_props( $term, array( 'minHeight' => '50vh;background:red', 'mobileMinHeight' => '150vh', 'backgroundColor' => 'red;x:y', 'controlsColor' => 'x);}', 'overlayColor' => '', 'overlayOpacity' => 95 ) ) );
		assert_not_contains( 'background:red', $bad );
		assert_not_contains( 'x);}', $bad );
		assert_contains( '--cradle-hero-min-height:70vh', $bad, 'Invalid height falls back to the default.' );
		assert_contains( '--cradle-hero-min-height-mobile:60svh', $bad, 'Viewport units above 100 are refused.' );
		assert_contains( '--cradle-hero-bg:#111111', $bad, 'Invalid token falls back to a plain color.' );
		assert_not_contains( 'cradle-hero__overlay', $bad, 'No overlay color: no overlay.' );
		assert_same( '480px', Hero_Slider::parse_length( '480px' ) );
		assert_same( '', Hero_Slider::parse_length( 'calc(100vh - 1px)' ) );
		assert_same( '', Hero_Slider::parse_length( '10em;color:red' ) );
		assert_same( '', Hero_Slider::parse_length( '2001px' ) );
	}
);

cradle_test(
	'hero: behavior settings (autoplay, interval, transition, loop, controls) reach the markup and the view script',
	function () {
		cradle_hero_ready();
		list( $term ) = cradle_hero_three( 'Behave' );
		$html         = cradle_render_component( 'cradle/hero-slider', cradle_hero_props( $term ) );
		assert_not_contains( 'cradle-hero__rotation', $html, 'Autoplay is off by default: no pause/play control.' );
		assert_contains( 'aria-live="polite"', $html );
		assert_contains( '&quot;autoplay&quot;:false', $html );
		assert_contains( 'has-transition-fade', $html );

		$on = cradle_render_component( 'cradle/hero-slider', cradle_hero_props( $term, array( 'autoplay' => true, 'interval' => 5000, 'transition' => 'slide', 'loop' => false, 'pauseOnHover' => false ) ) );
		assert_contains( 'cradle-hero__rotation', $on );
		assert_contains( 'data-label-pause="Pause slideshow"', $on );
		assert_contains( 'aria-live="off"', $on );
		assert_contains( '&quot;autoplay&quot;:true', $on );
		assert_contains( '&quot;interval&quot;:5000', $on );
		assert_contains( '&quot;loop&quot;:false', $on );
		assert_contains( '&quot;pauseOnHover&quot;:false', $on );
		assert_contains( 'has-transition-slide', $on );
		assert_true( strpos( $on, 'cradle-hero__rotation' ) < strpos( $on, 'cradle-hero__prev' ), 'Rotation control comes first in tab order.' );
		$fast = cradle_render_component( 'cradle/hero-slider', cradle_hero_props( $term, array( 'autoplay' => true, 'interval' => 100 ) ) );
		assert_contains( '&quot;interval&quot;:7000', $fast, 'An out-of-range interval uses the default.' );

		$none = cradle_render_component( 'cradle/hero-slider', cradle_hero_props( $term, array( 'arrows' => false, 'indicators' => false ) ) );
		assert_contains( 'cradle-hero__prev', $none, 'Without dots, previous/next stay so everyone can change slides.' );
		$dots = cradle_render_component( 'cradle/hero-slider', cradle_hero_props( $term, array( 'arrows' => false ) ) );
		assert_not_contains( 'cradle-hero__prev', $dots );
		assert_contains( 'cradle-hero__dot', $dots );

		$single = cradle_render_component( 'cradle/hero-slider', cradle_hero_props( $term, array( 'count' => 1 ) ) );
		assert_contains( 'Behave one', $single );
		assert_not_contains( 'data-cradle-hero', $single, 'One slide: plain hero, no carousel semantics or script config.' );
		assert_not_contains( 'aria-roledescription', $single );
	}
);

cradle_test(
	'hero: without JavaScript the first slide is visible and the rest are hidden; two sliders have distinct IDs and their own controls',
	function () {
		cradle_hero_ready();
		list( $term ) = cradle_hero_three( 'Twin' );
		$html         = do_blocks( Hero_Slider::block_markup( cradle_hero_props( $term ) ) . "\n\n" . Hero_Slider::block_markup( cradle_hero_props( $term, array( 'transition' => 'none' ) ) ) );
		$sections     = cradle_hero_sections( $html );
		assert_same( 2, count( $sections ), 'Two carousels.' );
		preg_match_all( '/<div class="cradle-hero__slide(?: [^"]*)?"[^>]*>/', $sections[0], $slides );
		assert_same( 3, count( $slides[0] ) );
		assert_not_contains( ' hidden', $slides[0][0], 'First slide is not hidden.' );
		assert_contains( ' hidden', $slides[0][1] );
		assert_contains( ' hidden', $slides[0][2] );
		assert_contains( '<div class="cradle-hero__controls" hidden>', $sections[0], 'Controls stay hidden until the view script runs.' );
		preg_match_all( '/\sid="([^"]+)"/', $html, $ids );
		assert_true( count( $ids[1] ) >= 8 );
		assert_same( count( $ids[1] ), count( array_unique( $ids[1] ) ), 'No duplicate IDs across instances.' );
		foreach ( $sections as $section ) {
			preg_match_all( '/aria-controls="([^"]+)"/', $section, $controls );
			assert_true( count( $controls[1] ) >= 5, 'Arrows and dots present.' );
			foreach ( $controls[1] as $target ) {
				assert_true( str_contains( $section, 'id="' . $target . '"' ), "Control target {$target} lives in the same instance." );
			}
			assert_contains( 'aria-roledescription="carousel"', $section );
			assert_contains( '<span class="cradle-hero__sr">Show slide 2 of 3: Twin two</span>', $section );
		}
		assert_contains( 'has-transition-none', $sections[1] );
		assert_true( wp_script_is( 'cradle-hero-slider-view', 'enqueued' ), 'Rendering a slider enqueues the view script.' );
	}
);

cradle_test(
	'hero: same render service through PHP, shortcode and block; the dynamic block outputs nothing when its type is unavailable',
	function () {
		cradle_hero_ready();
		list( $term, $ids ) = cradle_hero_three( 'Boundary' );
		$strip              = static fn( $h ) => preg_replace( '/cradle-hero-slider-\d+/', 'X', preg_replace( '/\s+/', ' ', trim( $h ) ) );
		$php                = cradle_render_component( 'cradle/hero-slider', array( 'postType' => 'post', 'postIds' => array( $ids[1], $ids[0] ) ) );
		$short              = do_shortcode( '[cradle_component id="cradle/hero-slider" post-type="post" post-ids="' . $ids[1] . ', ' . $ids[0] . '"]' );
		assert_same( $strip( $php ), $strip( $short ), 'Shortcode (comma-separated post IDs) output matches PHP render.' );
		assert_true( strpos( $short, 'Boundary two' ) < strpos( $short, 'Boundary one' ) );
		$markup = Hero_Slider::block_markup( cradle_hero_props( $term, array( 'count' => 3, 'autoplay' => false ) ) );
		assert_not_contains( '"count"', $markup, 'Default attributes are omitted from provisioned markup.' );
		assert_contains( 'Boundary one', do_blocks( $markup ) );
		$registry = WP_Block_Type_Registry::get_instance();
		$type     = $registry->get_registered( 'cradle/hero-slider' );
		$registry->unregister( 'cradle/hero-slider' );
		try {
			assert_same( '', trim( do_blocks( $markup ) ), 'Without Cradle Blocks the dynamic block renders nothing (documented).' );
		} finally {
			$registry->register( $type );
		}
	}
);

cradle_test(
	'hero: the editor preview explains empty sliders to content editors only',
	function () {
		cradle_hero_ready();
		$term    = cradle_hero_term();
		$editor  = cradle_test_user( 'editor' );
		$session = cradle_test_cookies( $editor );
		$url     = add_query_arg(
			array(
				'context'    => 'edit',
				'attributes' => cradle_hero_props( $term ),
			),
			rest_url( 'wp/v2/block-renderer/cradle/hero-slider' )
		);
		$r       = cradle_test_http( 'GET', $url, array( 'headers' => array( 'X-WP-Nonce' => cradle_test_nonce( $editor, $session, 'wp_rest' ) ) ), $editor, $session );
		$data    = json_decode( wp_remote_retrieve_body( $r ), true );
		assert_same( 200, wp_remote_retrieve_response_code( $r ), 'Block renderer answers editors: ' . wp_remote_retrieve_body( $r ) );
		assert_contains( 'There are no published entries to show yet', (string) ( $data['rendered'] ?? '' ) );
		$r = cradle_test_http( 'GET', $url, array(), 0 );
		assert_true( 200 !== wp_remote_retrieve_response_code( $r ), 'Visitors cannot use the block renderer.' );
		assert_same( '', cradle_render_component( 'cradle/hero-slider', cradle_hero_props( $term ) ), 'Visitors never see the notice.' );
	}
);

cradle_test(
	'hero: reduced motion is honored by the view script and stylesheet (static check)',
	function () {
		cradle_hero_ready();
		$js  = (string) file_get_contents( CRADLE_BLOCKS_DIR . 'blocks/hero-slider/view.js' );
		$css = (string) file_get_contents( CRADLE_BLOCKS_DIR . 'blocks/hero-slider/style.css' );
		assert_contains( "matchMedia( '(prefers-reduced-motion: reduce)' )", $js );
		assert_contains( 'rotating: cfg.autoplay && ! prefersReducedMotion()', $js, 'Autoplay never starts under reduced motion.' );
		assert_contains( "track.setAttribute( 'aria-live', state.rotating ? 'off' : 'polite' )", $js, 'Live region only while not rotating.' );
		assert_contains( "root.hasAttribute( 'data-cradle-hero-ready' )", $js, 'Each instance initializes once.' );
		assert_contains( 'pointerdown', $js );
		assert_not_contains( 'jQuery', $js );
		assert_not_contains( 'innerHTML', $js, 'No HTML string injection in the view script.' );
		assert_true( (bool) preg_match( '/@media \(prefers-reduced-motion: reduce\)\s*\{[^}]*\{\s*transition: none !important;/s', $css ), 'Reduced motion disables transitions.' );
		assert_true( (bool) preg_match( '/\.cradle-hero__slide\[hidden\]\s*\{\s*display: none !important;/', $css ), 'No-JS hidden slides stay hidden.' );
	}
);

cradle_test(
	'hero: frontend page returns 200 without PHP notices; view script only where a slider renders; image loading priorities',
	function () {
		cradle_hero_ready();
		$img1               = cradle_hero_image( 960, 540, 'Mountain at dawn' );
		$img2               = cradle_hero_image( 960, 540, '' );
		list( $term, $ids ) = cradle_hero_three( 'Front', array( $img1, $img2, $img2 ) );
		$with               = cradle_hero_page( Hero_Slider::block_markup( cradle_hero_props( $term ) ) . "\n\n<!-- wp:paragraph --><p>Between sliders</p><!-- /wp:paragraph -->\n\n" . Hero_Slider::block_markup( cradle_hero_props( $term, array( 'autoplay' => true ) ) ), 'Hero fixture page with sliders' );
		$without            = cradle_hero_page( '<!-- wp:paragraph --><p>No slider here</p><!-- /wp:paragraph -->', 'Hero fixture page without slider' );

		$r    = cradle_test_http( 'GET', get_permalink( $with ) );
		$body = wp_remote_retrieve_body( $r );
		assert_same( 200, wp_remote_retrieve_response_code( $r ) );
		assert_same( 1, substr_count( $body, 'id="cradle-hero-slider-view-js"' ), 'View script printed once for two sliders.' );
		assert_true( (bool) preg_match( '/id="cradle-hero-slider-style(-inline)?-css"/', $body ), 'Slider stylesheet loaded.' );
		foreach ( array( 'Fatal error', 'Warning:', 'Notice:', 'Deprecated:' ) as $needle ) {
			assert_not_contains( $needle, $body );
		}
		$sections = cradle_hero_sections( $body );
		assert_same( 2, count( $sections ), 'Both sliders rendered on the frontend.' );
		assert_contains( 'alignfull', $sections[0], 'Block supports (align full) applied to the root.' );

		preg_match_all( '/<img [^>]*class="cradle-hero__image[^"]*"[^>]*>/', $sections[0], $first );
		preg_match_all( '/<img [^>]*class="cradle-hero__image[^"]*"[^>]*>/', $sections[1], $second );
		assert_same( 3, count( $first[0] ) );
		foreach ( array_merge( $first[0], $second[0] ) as $tag ) {
			assert_true( (bool) preg_match( '/ width="\d+"/', $tag ) && (bool) preg_match( '/ height="\d+"/', $tag ), 'Image dimensions reserved: ' . $tag );
		}
		assert_contains( 'fetchpriority="high"', $first[0][0], 'First slide of the first slider is high priority.' );
		assert_not_contains( 'loading="lazy"', $first[0][0] );
		assert_contains( 'alt="Mountain at dawn"', $first[0][0] );
		assert_contains( 'loading="lazy"', $first[0][1], 'Later slides are lazy.' );
		assert_contains( 'alt=""', $first[0][1], 'No library text: empty alt (the title carries the meaning).' );
		assert_not_contains( 'fetchpriority="high"', $second[0][0], 'Only the very first slider gets fetchpriority=high.' );
		assert_not_contains( 'loading="lazy"', $second[0][0], 'The first slide of every slider is not lazy-loaded.' );
		assert_true( (bool) preg_match_all( '/\sid="([^"]+)"/', $body, $all_ids ) );
		assert_same( count( $all_ids[1] ), count( array_unique( $all_ids[1] ) ), 'No duplicate IDs on the full page.' );

		$r    = cradle_test_http( 'GET', get_permalink( $without ) );
		$body = wp_remote_retrieve_body( $r );
		assert_same( 200, wp_remote_retrieve_response_code( $r ) );
		assert_not_contains( 'cradle-hero-slider-view-js', $body, 'View script is not loaded on pages without a slider.' );

		// Deleted featured image: the slide still renders its text without an image.
		wp_delete_attachment( $img1, true );
		clean_post_cache( $ids[0] );
		$html = cradle_render_component( 'cradle/hero-slider', cradle_hero_props( $term ) );
		assert_contains( 'Front one', $html );
		assert_true( strpos( $html, 'cradle-hero__image' ) > strpos( $html, 'Front one' ), 'Missing image is skipped gracefully.' );
	}
);

cradle_test(
	'hero: no PHP notices from the slider in debug.log; fixtures removed',
	function () {
		cradle_hero_ready();
		cradle_hero_cleanup();
		global $wpdb;
		$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_cradle_test_fixture' AND meta_value = 'hero'" ); // phpcs:ignore
		assert_same( 0, $left, 'All hero fixtures removed.' );
		$file = WP_CONTENT_DIR . '/debug.log';
		if ( ! is_file( $file ) ) {
			return;
		}
		clearstatcache();
		$handle = fopen( $file, 'rb' ); // phpcs:ignore
		fseek( $handle, min( $GLOBALS['cradle_hero_log_offset'], filesize( $file ) ) );
		$new = (string) stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore
		$lines = array_filter( explode( "\n", $new ), static fn( $l ) => str_contains( $l, 'hero-slider' ) || str_contains( $l, 'Hero_Slider' ) );
		assert_same( array(), array_values( $lines ), 'The hero slider wrote to debug.log.' );
	}
);
