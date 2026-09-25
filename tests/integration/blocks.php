<?php
/**
 * Cradle Blocks: component contract, builder boundary, overrides, query safety, usage and conversion.
 */

use Cradle\Blocks\Feature_Card;
use Cradle\Blocks\Module as Blocks_Module;

function cradle_blocks_test_ready() {
	if ( ! is_plugin_active( 'cradle-blocks/cradle-blocks.php' ) || ! cradle()->modules()->is_ready( 'blocks' ) ) {
		skip_test( 'cradle-blocks is not active/ready in the sandbox.' );
	}
}

cradle_test(
	'blocks: components and block types are registered',
	function () {
		cradle_blocks_test_ready();
		assert_true( (bool) cradle()->components()->get( 'cradle/feature-card' ) );
		assert_true( (bool) cradle()->components()->get( 'cradle/post-cards' ) );
		$registry = WP_Block_Type_Registry::get_instance();
		assert_true( $registry->is_registered( 'cradle/feature-card' ) );
		assert_true( $registry->is_registered( 'cradle/post-cards' ) );
		assert_same( 3, $registry->get_registered( 'cradle/feature-card' )->api_version );
	}
);

cradle_test(
	'blocks: same render service through PHP, shortcode and Gutenberg (builder boundary)',
	function () {
		cradle_blocks_test_ready();
		$props  = array(
			'title'    => 'Boundary & proof',
			'body'     => 'Body <em>text</em>',
			'link'     => 'https://example.test/x',
			'linkText' => 'Read',
			'variant'  => 'outlined',
		);
		$php    = cradle_render_component( 'cradle/feature-card', $props );
		$short  = do_shortcode( '[cradle_component id="cradle/feature-card" title="Boundary &amp; proof" body="Body <em>text</em>" link="https://example.test/x" link-text="Read" variant="outlined"]' );
		$block  = do_blocks( Feature_Card::block_markup( $props ) );
		$strip  = static function ( $html ) {
			return preg_replace( '/\s+/', ' ', trim( preg_replace( '/ class="[^"]*"/', '', $html ) ) );
		};
		assert_contains( 'Boundary &amp; proof', $php );
		assert_same( $strip( $php ), $strip( $short ), 'Shortcode output differs from PHP render.' );
		assert_same( $strip( $php ), $strip( $block ), 'Block output differs from PHP render.' );
		assert_contains( 'is-variant-outlined', $block );
		assert_true( wp_style_is( 'cradle-feature-card-style', 'enqueued' ), 'Component style handle is enqueued on render.' );
	}
);

cradle_test(
	'blocks: block_markup round-trips attributes through the block parser',
	function () {
		cradle_blocks_test_ready();
		$attrs  = array(
			'title'        => 'Quote "and" <tag>',
			'body'         => '<strong>Bold</strong> text',
			'link'         => 'https://example.test/?a=1&b=2',
			'linkText'     => 'Go',
			'headingLevel' => 2,
			'variant'      => 'elevated',
		);
		$parsed = parse_blocks( Feature_Card::block_markup( $attrs ) );
		$block  = $parsed[0];
		assert_same( 'cradle/feature-card', $block['blockName'] );
		foreach ( $attrs as $key => $value ) {
			assert_same( $value, $block['attrs'][ $key ], "Attribute {$key} did not round-trip." );
		}
		assert_contains( '<h2 class="wp-block-cradle-feature-card__title">', $block['innerHTML'] );
	}
);

cradle_test(
	'blocks: post-cards only lists published, public, non-password content',
	function () {
		cradle_blocks_test_ready();
		$admin = cradle_test_user( 'administrator' );
		wp_set_current_user( $admin );
		$ids = array(
			'public'   => wp_insert_post( array( 'post_title' => 'PC public item', 'post_status' => 'publish', 'post_type' => 'page', 'menu_order' => 1 ) ),
			'private'  => wp_insert_post( array( 'post_title' => 'PC private item', 'post_status' => 'private', 'post_type' => 'page', 'menu_order' => 2 ) ),
			'password' => wp_insert_post( array( 'post_title' => 'PC password item', 'post_status' => 'publish', 'post_type' => 'page', 'post_password' => 'x', 'menu_order' => 3 ) ),
			'draft'    => wp_insert_post( array( 'post_title' => 'PC draft item', 'post_status' => 'draft', 'post_type' => 'page', 'menu_order' => 4 ) ),
		);
		try {
			$html = cradle_render_component( 'cradle/post-cards', array( 'postType' => 'page', 'count' => 24, 'orderBy' => 'title', 'order' => 'asc' ) );
			assert_contains( 'PC public item', $html );
			assert_not_contains( 'PC private item', $html, 'Private posts leak even for an administrator render.' );
			assert_not_contains( 'PC password item', $html );
			assert_not_contains( 'PC draft item', $html );
			$bad = cradle_render_component( 'cradle/post-cards', array( 'postType' => 'wp_template' ) );
			assert_contains( 'is-empty', $bad, 'Non-viewable post types are refused.' );
			$bad = cradle_render_component( 'cradle/post-cards', array( 'postType' => 'page', 'taxonomy' => 'nav_menu', 'term' => 'x' ) );
			assert_contains( 'PC public item', $bad, 'Non-public taxonomy filters are dropped, not applied.' );
			$bounded = cradle()->components()->normalize( 'cradle/post-cards', array( 'count' => 5000 ) );
			assert_same( 3, $bounded['count'], 'Unbounded counts fall back to the default.' );
		} finally {
			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
			}
		}
	}
);

cradle_test(
	'blocks: child override with matching contract is used; stale contract is ignored and reported',
	function () {
		cradle_blocks_test_ready();
		$dir  = get_stylesheet_directory() . '/cradle/components/feature-card';
		$file = $dir . '/render.php';
		if ( file_exists( $file ) ) {
			skip_test( 'The active child already ships a feature-card override.' );
		}
		wp_mkdir_p( $dir );
		try {
			file_put_contents( $file, "<?php\n/**\n * Render contract: feature-card-v1\n */\necho '<div class=\"child-override\">' . esc_html( \$props['title'] ) . '</div>';\n" );
			$html = cradle_render_component( 'cradle/feature-card', array( 'title' => 'Via child' ) );
			assert_same( '<div class="child-override">Via child</div>', $html );
			file_put_contents( $file, "<?php\n/**\n * Render contract: feature-card-v0\n */\necho 'stale';\n" );
			$html = cradle_render_component( 'cradle/feature-card', array( 'title' => 'Default again' ) );
			assert_contains( 'wp-block-cradle-feature-card', $html );
			assert_true( isset( cradle()->components()->override_problems()['cradle/feature-card'] ) );
		} finally {
			wp_delete_file( $file );
			@rmdir( $dir ); // phpcs:ignore
			@rmdir( dirname( $dir ) ); // phpcs:ignore
		}
	}
);

cradle_test(
	'blocks: saved fallback survives when the block type is unavailable',
	function () {
		cradle_blocks_test_ready();
		$markup   = Feature_Card::block_markup( array( 'title' => 'Fallback title', 'body' => 'Fallback body' ) );
		$registry = WP_Block_Type_Registry::get_instance();
		$type     = $registry->get_registered( 'cradle/feature-card' );
		$registry->unregister( 'cradle/feature-card' );
		try {
			$html = do_blocks( $markup );
			assert_contains( 'Fallback title', $html, 'Saved semantic markup renders without the plugin.' );
			assert_contains( '<article', $html );
		} finally {
			$registry->register( $type );
		}
	}
);

cradle_test(
	'blocks: usage scan finds nested instances; static export is an API only (no admin conversion screen)',
	function () {
		cradle_blocks_test_ready();
		$admin   = cradle_test_user( 'administrator' );
		$content = '<!-- wp:group --><div class="wp-block-group">' . Feature_Card::block_markup( array( 'title' => 'Export me' ) ) . '</div><!-- /wp:group -->';
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Export fixture',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => $admin,
				'post_content' => wp_slash( $content ),
			)
		);
		try {
			$usages = cradle()->components()->find_usages( 'cradle/feature-card' );
			$found  = array_filter( $usages['gutenberg'], fn( $u ) => $u['object_id'] === $post_id );
			assert_true( (bool) $found, 'Nested usage is found.' );

			$blocks = parse_blocks( $content );
			$export = cradle()->components()->adapter( 'gutenberg' )->export_or_convert_instance(
				array(
					'block'   => $blocks[0]['innerBlocks'][0],
					'post_id' => $post_id,
				)
			);
			assert_contains( 'Export me', $export['html'], 'The export API returns static HTML for one instance.' );
			assert_true( is_array( $export['lost'] ) );

			// The removed Block conversion screen left no handler, page or capability behind.
			$session = cradle_test_cookies( $admin );
			$r       = cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array( 'action' => 'cradle_blocks_convert', 'post' => $post_id, 'component' => 'cradle/feature-card', '_wpnonce' => cradle_test_nonce( $admin, $session, 'cradle_blocks_convert_' . $post_id ) ) ), $admin, $session );
			assert_true( 302 !== wp_remote_retrieve_response_code( $r ), 'No conversion handler is registered.' );
			clean_post_cache( $post_id );
			assert_contains( 'wp:cradle/feature-card', get_post( $post_id )->post_content, 'Stored content is unchanged.' );
			$menu = wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle' ), array(), $admin, $session ) );
			assert_contains( 'Cradle modules', $menu );
			assert_not_contains( 'Block conversion', $menu, 'The Cradle menu no longer offers Block conversion.' );
			foreach ( wp_roles()->roles as $name => $role ) {
				assert_false( ! empty( $role['capabilities']['cradle_convert_components'] ), "{$name} must not keep the retired conversion capability." );
			}
			assert_same( 2, (int) get_option( 'cradle_blocks_schema' ), 'Blocks data schema upgraded.' );
		} finally {
			wp_delete_post( $post_id, true );
		}
	}
);

cradle_test(
	'blocks: frontend renders a page containing both blocks without errors',
	function () {
		cradle_blocks_test_ready();
		$content = Feature_Card::block_markup( array( 'title' => 'Frontend card', 'body' => 'Hello', 'variant' => 'outlined' ) ) . "\n\n" . '<!-- wp:cradle/post-cards {"count":2,"showImage":false} /-->';
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Blocks frontend fixture',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => wp_slash( $content ),
			)
		);
		try {
			$r    = cradle_test_http( 'GET', get_permalink( $post_id ) );
			$body = wp_remote_retrieve_body( $r );
			assert_same( 200, wp_remote_retrieve_response_code( $r ) );
			assert_contains( 'Frontend card', $body );
			assert_contains( 'wp-block-cradle-post-cards', $body );
			assert_true( (bool) preg_match( '/id="cradle-feature-card-style-(inline-)?css"/', $body ), 'Block style is loaded (linked or inlined) on the frontend.' );
			assert_not_contains( 'Fatal error', $body );
		} finally {
			wp_delete_post( $post_id, true );
		}
	}
);
