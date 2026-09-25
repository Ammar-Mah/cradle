<?php
/**
 * Cradle Duplicate integration tests (northstar §13, acceptance CR-16).
 *
 * Fixtures are marked with `_cradle_test_fixture = duplicate` and removed at shutdown
 * together with every draft whose `_cradle_duplicate_of` points at a fixture.
 */

use Cradle\Duplicate\Duplicator;
use Cradle\Duplicate\Policy;

$GLOBALS['cradle_dup_fixtures'] = array(
	'posts' => array(),
	'terms' => array(),
);

function cradle_dup_ready() {
	if ( ! is_plugin_active( 'cradle-duplicate/cradle-duplicate.php' ) || ! cradle() || ! cradle()->modules()->is_ready( 'duplicate' ) ) {
		skip_test( 'cradle-duplicate is not active and ready.' );
	}
}

function cradle_dup_track_post( int $id ): int {
	$GLOBALS['cradle_dup_fixtures']['posts'][] = $id;
	update_post_meta( $id, '_cradle_test_fixture', 'duplicate' );
	return $id;
}

/**
 * Creates a small PNG attachment locally with GD (no remote media).
 */
function cradle_dup_fixture_image(): int {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		skip_test( 'GD is not available to create a local fixture image.' );
	}
	$img = imagecreatetruecolor( 40, 30 );
	imagefill( $img, 0, 0, imagecolorallocate( $img, 30, 90, 160 ) );
	ob_start();
	imagepng( $img );
	$bytes = ob_get_clean();
	$upload = wp_upload_bits( 'cradle-dup-fixture-' . wp_generate_password( 6, false ) . '.png', null, $bytes );
	if ( ! empty( $upload['error'] ) ) {
		throw new Cradle_Test_Failure( 'Could not write fixture image: ' . $upload['error'] );
	}
	$id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'Cradle duplicate fixture image',
			'post_status'    => 'inherit',
		),
		$upload['file']
	);
	return cradle_dup_track_post( (int) $id );
}

function cradle_dup_rest( string $method, int $post_id, int $user_id, bool $with_nonce = true ) {
	$session = cradle_test_cookies( $user_id );
	$headers = array();
	if ( $with_nonce ) {
		$headers['X-WP-Nonce'] = cradle_test_nonce( $user_id, $session, 'wp_rest' );
	}
	return cradle_test_http( $method, rest_url( 'cradle-duplicate/v1/posts/' . $post_id ), array( 'headers' => $headers ), $user_id, $session );
}

function cradle_dup_copies_of( int $source_id ): array {
	global $wpdb;
	// Direct query: copies created by HTTP requests are invisible to this process' query cache.
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cradle_duplicate_of' AND meta_value = %d", $source_id ) ) );
}

function cradle_dup_snapshot( int $post_id ): array {
	clean_post_cache( $post_id );
	wp_cache_delete( $post_id, 'post_meta' );
	$post = get_post( $post_id, ARRAY_A );
	$meta = get_post_meta( $post_id );
	ksort( $meta );
	$terms = wp_get_object_terms( $post_id, get_object_taxonomies( $post['post_type'] ), array( 'fields' => 'ids' ) );
	sort( $terms );
	return array(
		'post'  => $post,
		'meta'  => $meta,
		'terms' => $terms,
	);
}

/**
 * Builds the page fixture used by the acceptance tests.
 *
 * @return array{page:int,parent:int,pattern:int,image:int,content:string}
 */
function cradle_dup_page_fixture(): array {
	static $fixture = null;
	if ( $fixture ) {
		return $fixture;
	}
	$admin = cradle_test_user( 'administrator' );
	wp_set_current_user( $admin );
	$pattern = cradle_dup_track_post(
		(int) wp_insert_post(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Cradle dup synced pattern',
				'post_content' => "<!-- wp:paragraph -->\n<p>Shared call to action.</p>\n<!-- /wp:paragraph -->",
			)
		)
	);
	$parent  = cradle_dup_track_post(
		(int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Cradle dup parent page',
				'post_name'   => 'cradle-dup-parent',
			)
		)
	);
	$image   = cradle_dup_fixture_image();
	$content = '<!-- wp:group {"layout":{"type":"constrained"}} -->' . "\n"
		. '<div class="wp-block-group"><!-- wp:heading -->' . "\n"
		. '<h2 class="wp-block-heading">Nested heading</h2>' . "\n"
		. '<!-- /wp:heading -->' . "\n\n"
		. '<!-- wp:columns -->' . "\n"
		. '<div class="wp-block-columns"><!-- wp:column -->' . "\n"
		. '<div class="wp-block-column"><!-- wp:paragraph -->' . "\n"
		. '<p>Left column with <strong>bold</strong> text &amp; an entity.</p>' . "\n"
		. '<!-- /wp:paragraph --></div>' . "\n"
		. '<!-- /wp:column -->' . "\n\n"
		. '<!-- wp:column -->' . "\n"
		. '<div class="wp-block-column"><!-- wp:image {"id":' . $image . ',"sizeSlug":"full"} -->' . "\n"
		. '<figure class="wp-block-image size-full"><img src="' . esc_url( wp_get_attachment_url( $image ) ) . '" alt="" class="wp-image-' . $image . '"/></figure>' . "\n"
		. '<!-- /wp:image --></div>' . "\n"
		. '<!-- /wp:column --></div>' . "\n"
		. '<!-- /wp:columns -->' . "\n\n"
		. '<!-- wp:block {"ref":' . $pattern . '} /--></div>' . "\n"
		. '<!-- /wp:group -->';
	$page    = cradle_dup_track_post(
		(int) wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Cradle dup source page',
					'post_name'    => 'cradle-dup-source',
					'post_content' => $content,
					'post_excerpt' => 'Source excerpt.',
					'post_parent'  => $parent,
					'menu_order'   => 7,
				)
			)
		)
	);
	update_post_meta( $page, '_wp_page_template', 'page-no-title' );
	set_post_thumbnail( $page, $image );
	// Allowed public meta, an allowlisted private key, and meta that must never be copied.
	add_post_meta( $page, 'cradle_dup_subtitle', wp_slash( 'Public subtitle with \backslash' ) );
	add_post_meta( $page, 'cradle_dup_list', array( 'a' => 1, 'b' => array( 'c' ) ) );
	add_post_meta( $page, '_cradle_dup_fixture_layout', 'wide' );
	add_post_meta( $page, '_cradle_dup_unknown_state', 'plugin-private' );
	add_post_meta( $page, '_cradle_generated', '1' );
	add_post_meta( $page, '_cradle_generation_batch', 'fake-batch' );
	add_post_meta( $page, '_cradle_generation_hash', 'abc' );
	add_post_meta( $page, '_edit_lock', time() . ':1' );
	add_post_meta( $page, '_edit_last', '1' );
	add_post_meta( $page, '_wp_old_slug', 'older-slug' );
	add_post_meta( $page, 'api_token', 'secret-value' );
	add_post_meta( $page, '_order_total', '99.00' );
	add_post_meta( $page, 'payment_reference', 'pay_123' );
	add_post_meta( $page, '_cradle_source_key', 'cradle-dup-test:source-page' );
	$fixture = compact( 'page', 'parent', 'pattern', 'image', 'content' );
	return $fixture;
}

register_shutdown_function(
	function () {
		if ( ! function_exists( 'wp_delete_post' ) ) {
			return;
		}
		wp_set_current_user( 0 );
		global $wpdb;
		$ids = array_map( 'intval', $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cradle_test_fixture' AND meta_value = 'duplicate'" ) );
		$ids   = array_unique( array_merge( $ids, $GLOBALS['cradle_dup_fixtures']['posts'] ) );
		$extra = array();
		foreach ( $ids as $id ) {
			$extra = array_merge( $extra, cradle_dup_copies_of( (int) $id ) );
		}
		foreach ( array_unique( array_merge( $extra, $ids ) ) as $id ) {
			if ( 'attachment' === get_post_type( $id ) ) {
				wp_delete_attachment( (int) $id, true );
			} else {
				wp_delete_post( (int) $id, true );
			}
		}
		foreach ( $GLOBALS['cradle_dup_fixtures']['terms'] as $term ) {
			wp_delete_term( $term[0], $term[1] );
		}
		if ( function_exists( 'cradle' ) && cradle() && cradle()->settings()->section( 'duplicate' ) ) {
			cradle()->settings()->reset( 'duplicate' );
		}
	}
);

cradle_test(
	'duplicate: module is ready with REST route, settings section and capability',
	function () {
		cradle_dup_ready();
		assert_same( 'ready', cradle()->modules()->state( 'duplicate' ) );
		$routes = rest_get_server()->get_routes( 'cradle-duplicate/v1' );
		assert_true( isset( $routes['/cradle-duplicate/v1/posts/(?P<id>\d+)'] ), 'REST route registered.' );
		assert_true( (bool) cradle()->settings()->section( 'duplicate' ), 'Settings section registered.' );
		assert_true( get_role( 'administrator' )->has_cap( 'cradle_manage_duplicate' ), 'Administrators hold the settings capability.' );
		assert_false( get_role( 'editor' )->has_cap( 'cradle_manage_duplicate' ), 'Editors do not.' );
		assert_same( array( 'post', 'page' ), Policy::supported_post_types() );
	}
);

cradle_test(
	'duplicate: settings are capability-protected and reject unsupported types',
	function () {
		cradle_dup_ready();
		wp_set_current_user( cradle_test_user( 'editor' ) );
		assert_wp_error( cradle()->settings()->update( 'duplicate', array( 'title_suffix' => 'x' ) ), 'cradle_forbidden' );
		wp_set_current_user( cradle_test_user( 'administrator' ) );
		cradle()->settings()->reset( 'duplicate' );
		$out = cradle()->settings()->update(
			'duplicate',
			array(
				'post_types'      => array( 'post', 'page', 'product' ),
				'extra_meta_keys' => "_cradle_dup_fixture_layout\nnot a key!",
			)
		);
		assert_true( isset( $out['errors']['post_types'] ), 'product is not an allowed choice.' );
		assert_true( isset( $out['errors']['extra_meta_keys'] ), 'Invalid meta key rejected.' );
		assert_same( array( 'post', 'page' ), cradle_get_setting( 'duplicate', 'post_types' ) );
		$out = cradle()->settings()->update( 'duplicate', array( 'extra_meta_keys' => "_cradle_dup_fixture_layout\n" ) );
		assert_same( array(), $out['errors'] );
		assert_same( array( '_cradle_dup_fixture_layout' ), cradle_get_setting( 'duplicate', 'extra_meta_keys' ) );
		$r = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-duplicate' ), array(), cradle_test_user( 'editor' ) );
		assert_true( 200 !== wp_remote_retrieve_response_code( $r ) || false !== strpos( wp_remote_retrieve_body( $r ), 'not allowed' ), 'Editor cannot open the settings page.' );
		$r = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-duplicate' ), array(), cradle_test_user( 'administrator' ) );
		assert_same( 200, wp_remote_retrieve_response_code( $r ) );
		assert_contains( 'cradle_duplicate_settings[post_types][]', wp_remote_retrieve_body( $r ) );
	}
);

cradle_test(
	'duplicate: page with nested blocks, synced pattern, media, template and metadata becomes a correct draft (REST, administrator)',
	function () {
		cradle_dup_ready();
		$admin = cradle_test_user( 'administrator' );
		wp_set_current_user( $admin );
		cradle()->settings()->update( 'duplicate', array( 'extra_meta_keys' => array( '_cradle_dup_fixture_layout' ) ) );
		$f           = cradle_dup_page_fixture();
		$before      = cradle_dup_snapshot( $f['page'] );
		$attachments = (int) wp_count_posts( 'attachment' )->inherit;
		$patterns    = (int) array_sum( (array) wp_count_posts( 'wp_block' ) );
		$started     = time();

		$r    = cradle_dup_rest( 'POST', $f['page'], $admin );
		$body = json_decode( wp_remote_retrieve_body( $r ), true );
		assert_same( 201, wp_remote_retrieve_response_code( $r ), 'REST duplicate should succeed: ' . wp_remote_retrieve_body( $r ) );
		$new = (int) $body['id'];
		assert_false( metadata_exists( 'post', $new, '_cradle_test_fixture' ), 'Unknown private meta is not copied.' );
		cradle_dup_track_post( $new );
		assert_contains( 'post=' . $new, $body['editLink'] );
		assert_contains( 'action=edit', $body['editLink'] );
		assert_same( 1, $body['syncedPatterns'] );

		clean_post_cache( $new );
		$copy = get_post( $new );
		assert_same( 'draft', $copy->post_status );
		assert_same( 'page', $copy->post_type );
		assert_same( $f['content'], $copy->post_content, 'Block serialization must be unchanged.' );
		assert_same( 'Cradle dup source page (Copy)', $copy->post_title );
		assert_same( 'Source excerpt.', $copy->post_excerpt );
		assert_same( $admin, (int) $copy->post_author );
		assert_same( $f['parent'], (int) $copy->post_parent );
		assert_same( 7, (int) $copy->menu_order );
		assert_true( '' !== $copy->post_name && 'cradle-dup-source' !== $copy->post_name, 'Slug must be unique: ' . $copy->post_name );
		assert_true( strtotime( $copy->post_modified ) >= $started - 120 && strtotime( $copy->post_date ) >= $started - 120, 'Timestamps are current.' );
		assert_same( 'page-no-title', get_post_meta( $new, '_wp_page_template', true ) );
		assert_same( $f['image'], (int) get_post_thumbnail_id( $new ), 'Featured image reference reused.' );
		assert_same( $attachments, (int) wp_count_posts( 'attachment' )->inherit, 'No attachment was cloned.' );
		assert_same( $patterns, (int) array_sum( (array) wp_count_posts( 'wp_block' ) ), 'Synced pattern stays shared (no new wp_block).' );
		assert_contains( '"ref":' . $f['pattern'], $copy->post_content );

		// Metadata policy.
		assert_same( 'Public subtitle with \\backslash', get_post_meta( $new, 'cradle_dup_subtitle', true ), 'Public meta copied byte-exact.' );
		assert_same( array( 'a' => 1, 'b' => array( 'c' ) ), get_post_meta( $new, 'cradle_dup_list', true ) );
		assert_same( 'wide', get_post_meta( $new, '_cradle_dup_fixture_layout', true ), 'Allowlisted private meta copied.' );
		foreach ( array( '_cradle_dup_unknown_state', '_cradle_generated', '_cradle_generation_batch', '_cradle_generation_hash', '_edit_lock', '_edit_last', '_wp_old_slug', 'api_token', '_order_total', 'payment_reference', '_cradle_source_key' ) as $key ) {
			assert_false( metadata_exists( 'post', $new, $key ), "Meta {$key} must not be copied." );
		}
		assert_same( $f['page'], (int) get_post_meta( $new, '_cradle_duplicate_of', true ) );

		// Source unchanged.
		$after = cradle_dup_snapshot( $f['page'] );
		assert_same( $before['post'], $after['post'], 'Source post row unchanged.' );
		assert_same( $before['meta'], $after['meta'], 'Source metadata unchanged.' );

		// Second duplicate gets yet another unique slug.
		$r2 = cradle_dup_rest( 'POST', $f['page'], $admin );
		assert_same( 201, wp_remote_retrieve_response_code( $r2 ) );
		$new2 = (int) json_decode( wp_remote_retrieve_body( $r2 ), true )['id'];
		cradle_dup_track_post( $new2 );
		assert_true( get_post( $new2 )->post_name !== $copy->post_name, 'Each copy gets a distinct slug.' );

		// Activity was logged.
		$logged = false;
		foreach ( cradle()->activity()->recent( 20, 'duplicate' ) as $row ) {
			$logged = $logged || ( 'post_duplicated' === $row['action'] && false !== strpos( $row['message'], '#' . $new ) );
		}
		assert_true( $logged, 'Duplication is recorded in the activity log.' );
	}
);

cradle_test(
	'duplicate: supported taxonomy terms are copied (post over REST, page in-process)',
	function () {
		cradle_dup_ready();
		$admin = cradle_test_user( 'administrator' );
		wp_set_current_user( $admin );
		$cat = wp_insert_term( 'Cradle Dup Test Category', 'category' );
		$tag = wp_insert_term( 'cradle-dup-test-tag', 'post_tag' );
		assert_true( is_array( $cat ) && is_array( $tag ) );
		$GLOBALS['cradle_dup_fixtures']['terms'][] = array( (int) $cat['term_id'], 'category' );
		$GLOBALS['cradle_dup_fixtures']['terms'][] = array( (int) $tag['term_id'], 'post_tag' );
		$post = cradle_dup_track_post(
			(int) wp_insert_post(
				array(
					'post_type'     => 'post',
					'post_status'   => 'publish',
					'post_title'    => 'Cradle dup source post',
					'post_content'  => "<!-- wp:paragraph -->\n<p>Post body.</p>\n<!-- /wp:paragraph -->",
					'post_category' => array( (int) $cat['term_id'] ),
					'tags_input'    => array( 'cradle-dup-test-tag' ),
				)
			)
		);
		$r = cradle_dup_rest( 'POST', $post, $admin );
		assert_same( 201, wp_remote_retrieve_response_code( $r ), wp_remote_retrieve_body( $r ) );
		$new = (int) json_decode( wp_remote_retrieve_body( $r ), true )['id'];
		cradle_dup_track_post( $new );
		assert_same( array( (int) $cat['term_id'] ), wp_get_object_terms( $new, 'category', array( 'fields' => 'ids' ) ) );
		assert_same( array( (int) $tag['term_id'] ), wp_get_object_terms( $new, 'post_tag', array( 'fields' => 'ids' ) ) );

		// Page with a taxonomy attached in this process, duplicated through the PHP API.
		register_taxonomy_for_object_type( 'post_tag', 'page' );
		$f = cradle_dup_page_fixture();
		wp_set_object_terms( $f['page'], array( (int) $tag['term_id'] ), 'post_tag' );
		$result = Duplicator::duplicate( $f['page'] );
		assert_true( is_array( $result ), 'Direct duplicate should succeed.' );
		cradle_dup_track_post( $result['id'] );
		assert_same( array( (int) $tag['term_id'] ), wp_get_object_terms( $result['id'], 'post_tag', array( 'fields' => 'ids' ) ) );
		wp_set_object_terms( $f['page'], array(), 'post_tag' );
		unregister_taxonomy_for_object_type( 'post_tag', 'page' );
	}
);

cradle_test(
	'duplicate: subscriber, contributor and an author on someone else\'s item are denied; author on own and editor on others are allowed',
	function () {
		cradle_dup_ready();
		$f      = cradle_dup_page_fixture();
		$admin  = cradle_test_user( 'administrator' );
		$author = cradle_test_user( 'author' );
		wp_set_current_user( $admin );
		$admin_post = cradle_dup_track_post(
			(int) wp_insert_post(
				array(
					'post_type'   => 'post',
					'post_status' => 'publish',
					'post_title'  => 'Cradle dup admin post',
					'post_author' => $admin,
				)
			)
		);
		$own_post   = cradle_dup_track_post(
			(int) wp_insert_post(
				array(
					'post_type'   => 'post',
					'post_status' => 'publish',
					'post_title'  => 'Cradle dup author post',
					'post_author' => $author,
				)
			)
		);
		$count_page = count( cradle_dup_copies_of( $f['page'] ) );
		$count_post = count( cradle_dup_copies_of( $admin_post ) );

		foreach ( array( 'subscriber', 'contributor' ) as $role ) {
			$r = cradle_dup_rest( 'POST', $f['page'], cradle_test_user( $role ) );
			assert_true( in_array( wp_remote_retrieve_response_code( $r ), array( 403, 404 ), true ), "{$role} must be refused on the page (got " . wp_remote_retrieve_response_code( $r ) . ').' );
			$r = cradle_dup_rest( 'POST', $admin_post, cradle_test_user( $role ) );
			assert_true( in_array( wp_remote_retrieve_response_code( $r ), array( 403, 404 ), true ), "{$role} must be refused on the post." );
		}
		$r = cradle_dup_rest( 'POST', $admin_post, $author );
		assert_same( 403, wp_remote_retrieve_response_code( $r ), 'Author cannot duplicate someone else\'s post.' );
		$r = cradle_dup_rest( 'POST', $f['page'], $author );
		assert_true( in_array( wp_remote_retrieve_response_code( $r ), array( 403, 404 ), true ), 'Author cannot duplicate pages.' );
		assert_same( $count_page, count( cradle_dup_copies_of( $f['page'] ) ), 'No page copy was created by denied users.' );
		assert_same( $count_post, count( cradle_dup_copies_of( $admin_post ) ), 'No post copy was created by denied users.' );

		// Denied through the no-JS admin-post path as well, even with the user's own valid nonce.
		$sub     = cradle_test_user( 'subscriber' );
		$session = cradle_test_cookies( $sub );
		$nonce   = cradle_test_nonce( $sub, $session, 'cradle_duplicate_post_' . $f['page'] );
		$r       = cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array( 'action' => 'cradle_duplicate', 'post' => $f['page'], '_wpnonce' => $nonce ) ), $sub, $session );
		assert_true( in_array( wp_remote_retrieve_response_code( $r ), array( 403, 404 ), true ), 'Subscriber refused on admin-post (got ' . wp_remote_retrieve_response_code( $r ) . ').' );
		assert_same( $count_page, count( cradle_dup_copies_of( $f['page'] ) ) );

		// Allowed: author on own post (becomes author of the copy), editor on an administrator's page.
		$r = cradle_dup_rest( 'POST', $own_post, $author );
		assert_same( 201, wp_remote_retrieve_response_code( $r ), 'Author may duplicate own post: ' . wp_remote_retrieve_body( $r ) );
		$copy = (int) json_decode( wp_remote_retrieve_body( $r ), true )['id'];
		cradle_dup_track_post( $copy );
		assert_same( $author, (int) get_post( $copy )->post_author );
		$editor = cradle_test_user( 'editor' );
		$r      = cradle_dup_rest( 'POST', $f['page'], $editor );
		assert_same( 201, wp_remote_retrieve_response_code( $r ), 'Editor may duplicate others\' pages.' );
		$copy = (int) json_decode( wp_remote_retrieve_body( $r ), true )['id'];
		cradle_dup_track_post( $copy );
		assert_same( $editor, (int) get_post( $copy )->post_author, 'Default author policy: the person duplicating.' );

		// PHP API performs the same checks.
		wp_set_current_user( cradle_test_user( 'contributor' ) );
		assert_wp_error( Duplicator::duplicate( $admin_post ) );
		wp_set_current_user( 0 );
		assert_wp_error( Duplicator::duplicate( $admin_post ), 'cradle_duplicate_login' );
	}
);

cradle_test(
	'duplicate: GET requests and requests without a nonce never duplicate',
	function () {
		cradle_dup_ready();
		$f      = cradle_dup_page_fixture();
		$admin  = cradle_test_user( 'administrator' );
		$before = count( cradle_dup_copies_of( $f['page'] ) );

		// GET on the admin-post action redirects to the read-only confirmation screen.
		$r = cradle_test_http( 'GET', admin_url( 'admin-post.php?action=cradle_duplicate&post=' . $f['page'] ), array(), $admin );
		assert_same( 302, wp_remote_retrieve_response_code( $r ) );
		assert_contains( 'page=cradle-duplicate-confirm', (string) wp_remote_retrieve_header( $r, 'location' ) );
		// GET with a (valid) nonce in the URL still does not mutate.
		$session = cradle_test_cookies( $admin );
		$nonce   = cradle_test_nonce( $admin, $session, 'cradle_duplicate_post_' . $f['page'] );
		$r       = cradle_test_http( 'GET', admin_url( 'admin-post.php?action=cradle_duplicate&post=' . $f['page'] . '&_wpnonce=' . $nonce ), array(), $admin, $session );
		assert_same( 302, wp_remote_retrieve_response_code( $r ) );
		// Confirmation screen renders a POST form with an item-specific nonce and the synced-pattern explanation.
		$r    = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-duplicate-confirm&post=' . $f['page'] ), array(), $admin );
		$html = wp_remote_retrieve_body( $r );
		assert_same( 200, wp_remote_retrieve_response_code( $r ) );
		assert_contains( 'method="post"', $html );
		assert_contains( 'name="_wpnonce"', $html );
		assert_contains( 'synced pattern', $html );
		// POST without nonce is refused.
		$r = cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array( 'action' => 'cradle_duplicate', 'post' => $f['page'] ) ), $admin );
		assert_same( 403, wp_remote_retrieve_response_code( $r ) );
		// POST with a nonce for a different item is refused.
		$other = cradle_test_nonce( $admin, $session, 'cradle_duplicate_post_' . $f['parent'] );
		$r     = cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array( 'action' => 'cradle_duplicate', 'post' => $f['page'], '_wpnonce' => $other ) ), $admin, $session );
		assert_same( 403, wp_remote_retrieve_response_code( $r ) );
		// REST with cookies but no X-WP-Nonce is treated as anonymous.
		$r = cradle_dup_rest( 'POST', $f['page'], $admin, false );
		assert_same( 401, wp_remote_retrieve_response_code( $r ) );
		// GET on the REST route does not exist.
		$r = cradle_dup_rest( 'GET', $f['page'], $admin );
		assert_same( 404, wp_remote_retrieve_response_code( $r ) );
		// Anonymous confirmation screen access is refused (login redirect).
		$r = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-duplicate-confirm&post=' . $f['page'] ) );
		assert_true( 200 !== wp_remote_retrieve_response_code( $r ) );
		assert_same( $before, count( cradle_dup_copies_of( $f['page'] ) ), 'Nothing was duplicated.' );

		// The protected POST path works and redirects to the new draft.
		$r        = cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array( 'action' => 'cradle_duplicate', 'post' => $f['page'], '_wpnonce' => $nonce ) ), $admin, $session );
		$location = (string) wp_remote_retrieve_header( $r, 'location' );
		assert_same( 302, wp_remote_retrieve_response_code( $r ) );
		$copies = cradle_dup_copies_of( $f['page'] );
		assert_same( $before + 1, count( $copies ) );
		$new = (int) max( $copies );
		cradle_dup_track_post( $new );
		assert_contains( 'post=' . $new, $location );
		assert_contains( 'cradle_duplicated=1', $location );
		assert_same( 'draft', get_post_status( $new ) );
	}
);

cradle_test(
	'duplicate: WooCommerce product and order types are excluded even when a filter adds them',
	function () {
		cradle_dup_ready();
		$admin = cradle_test_user( 'administrator' );
		wp_set_current_user( $admin );
		$registered = false;
		if ( ! post_type_exists( 'product' ) ) {
			register_post_type( 'product', array( 'public' => true, 'show_ui' => true, 'label' => 'Products' ) );
			$registered = true;
		}
		$add = function ( $types ) {
			$types[] = 'product';
			$types[] = 'shop_order';
			return $types;
		};
		add_filter( 'cradle_duplicate/post_types', $add );
		$product = cradle_dup_track_post(
			(int) wp_insert_post(
				array(
					'post_type'   => 'product',
					'post_status' => 'publish',
					'post_title'  => 'Cradle dup product',
				)
			)
		);
		assert_false( in_array( 'product', Policy::supported_post_types(), true ) );
		assert_false( isset( Policy::eligible_post_types()['product'] ), 'Product is not offered in settings.' );
		assert_wp_error( Policy::check( $product ), 'cradle_duplicate_unsupported' );
		assert_wp_error( Duplicator::duplicate( $product ), 'cradle_duplicate_unsupported' );
		assert_same( array(), cradle_dup_copies_of( $product ) );
		remove_filter( 'cradle_duplicate/post_types', $add );
		if ( $registered ) {
			wp_delete_post( $product, true );
			unregister_post_type( 'product' );
		}
	}
);

cradle_test(
	'duplicate: trash items cannot be duplicated and filters can only restrict',
	function () {
		cradle_dup_ready();
		wp_set_current_user( cradle_test_user( 'administrator' ) );
		$post = cradle_dup_track_post(
			(int) wp_insert_post(
				array(
					'post_type'   => 'post',
					'post_status' => 'publish',
					'post_title'  => 'Cradle dup trashed',
				)
			)
		);
		wp_trash_post( $post );
		assert_wp_error( Duplicator::duplicate( $post ), 'cradle_duplicate_status' );
		wp_untrash_post( $post );
		wp_update_post( array( 'ID' => $post, 'post_status' => 'publish' ) );
		$deny = fn() => false;
		add_filter( 'cradle_duplicate/can_duplicate', $deny );
		assert_wp_error( Duplicator::duplicate( $post ), 'cradle_duplicate_forbidden' );
		remove_filter( 'cradle_duplicate/can_duplicate', $deny );
		wp_set_current_user( cradle_test_user( 'subscriber' ) );
		$grant = fn() => true;
		add_filter( 'cradle_duplicate/can_duplicate', $grant );
		assert_wp_error( Duplicator::duplicate( $post ), 'cradle_duplicate_forbidden', 'A filter cannot grant access.' );
		remove_filter( 'cradle_duplicate/can_duplicate', $grant );
	}
);

cradle_test(
	'duplicate: list row action and block editor integration are shown only to allowed users',
	function () {
		cradle_dup_ready();
		$f      = cradle_dup_page_fixture();
		$admin  = cradle_test_user( 'administrator' );
		$author = cradle_test_user( 'author' );
		wp_set_current_user( $admin );
		$admin_post = cradle_dup_track_post( (int) wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Cradle dup row action admin', 'post_author' => $admin ) ) );
		$own_post   = cradle_dup_track_post( (int) wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Cradle dup row action author', 'post_author' => $author ) ) );

		$html = wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'edit.php?post_type=page' ), array(), $admin ) );
		assert_contains( 'data-cradle-duplicate="' . $f['page'] . '"', $html );
		assert_contains( 'duplicate-list.js', $html );

		$html = wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'edit.php?s=Cradle+dup+row+action' ), array(), $author ) );
		assert_contains( 'data-cradle-duplicate="' . $own_post . '"', $html );
		assert_not_contains( 'data-cradle-duplicate="' . $admin_post . '"', $html );

		$html = wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'post.php?post=' . $f['page'] . '&action=edit' ), array(), $admin ) );
		assert_contains( 'duplicate-editor.js', $html );
		assert_contains( 'cradleDuplicateEditor', $html );
	}
);

cradle_test(
	'duplicate: uninstall retains by default and purges only owned configuration when the policy says so',
	function () {
		cradle_dup_ready();
		$admin = cradle_test_user( 'administrator' );
		wp_set_current_user( $admin );
		cradle()->settings()->update( 'duplicate', array( 'title_suffix' => '(Clone)' ) );
		$post = cradle_dup_track_post( (int) wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Cradle dup uninstall fixture' ) ) );
		update_post_meta( $post, '_cradle_duplicate_of', 999999 );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'cradle-duplicate/cradle-duplicate.php' );
		}
		$file     = WP_PLUGIN_DIR . '/cradle-duplicate/uninstall.php';
		$had      = false !== get_option( 'cradle_uninstall_policy', false );
		$original = get_option( 'cradle_uninstall_policy', array() );
		$original = is_array( $original ) ? $original : array();
		$policies = $original;
		unset( $policies['duplicate'] );
		update_option( 'cradle_uninstall_policy', $policies, false );
		include $file;
		assert_true( false !== get_option( 'cradle_duplicate_settings', false ), 'Retain: settings kept.' );
		assert_true( get_role( 'administrator' )->has_cap( 'cradle_manage_duplicate' ), 'Retain: capability kept.' );
		assert_same( '999999', get_post_meta( $post, '_cradle_duplicate_of', true ) );

		$policies['duplicate'] = 'purge';
		update_option( 'cradle_uninstall_policy', $policies, false );
		try {
			include $file;
			wp_cache_delete( $post, 'post_meta' );
			assert_same( false, get_option( 'cradle_duplicate_settings', false ), 'Purge: settings removed.' );
			assert_false( get_role( 'administrator' )->has_cap( 'cradle_manage_duplicate' ), 'Purge: capability removed.' );
			assert_false( metadata_exists( 'post', $post, '_cradle_duplicate_of' ), 'Purge: provenance meta removed.' );
			assert_true( (bool) get_post( $post ), 'Purge never deletes posts.' );
			$after = get_option( 'cradle_uninstall_policy', array() );
			assert_false( isset( $after['duplicate'] ), 'Policy entry cleared.' );
		} finally {
			Cradle\Duplicate\Lifecycle::activate();
			if ( $had ) {
				update_option( 'cradle_uninstall_policy', $original, false );
			} else {
				delete_option( 'cradle_uninstall_policy' );
			}
		}
		assert_true( get_role( 'administrator' )->has_cap( 'cradle_manage_duplicate' ), 'Reactivation restores the capability.' );
	}
);

cradle_test(
	'duplicate: settings form saves through options.php (custom post type and meta key fields)',
	function () {
		cradle_dup_ready();
		$admin = cradle_test_user( 'administrator' );
		wp_set_current_user( $admin );
		cradle()->settings()->reset( 'duplicate' );
		$session = cradle_test_cookies( $admin );
		$nonce   = cradle_test_nonce( $admin, $session, 'cradle_duplicate-options' );
		$r       = cradle_test_http(
			'POST',
			admin_url( 'options.php' ),
			array(
				'body' => array(
					'option_page'               => 'cradle_duplicate',
					'action'                    => 'update',
					'_wpnonce'                  => $nonce,
					'_wp_http_referer'          => '/cradle/wp/wp-admin/admin.php?page=cradle-duplicate',
					'cradle_duplicate_settings' => array(
						'_revision'       => cradle()->settings()->revision( 'duplicate' ),
						'post_types'      => array( 'page' ),
						'title_suffix'    => '— copy',
						'author_policy'   => 'original',
						'extra_meta_keys' => "_layout_one\n_theme_block_*\n",
					),
				),
			),
			$admin,
			$session
		);
		assert_same( 302, wp_remote_retrieve_response_code( $r ), 'options.php redirects after saving.' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'cradle_duplicate_settings', 'options' );
		assert_same( array( 'page' ), cradle_get_setting( 'duplicate', 'post_types' ) );
		assert_same( '— copy', cradle_get_setting( 'duplicate', 'title_suffix' ) );
		assert_same( 'original', cradle_get_setting( 'duplicate', 'author_policy' ) );
		assert_same( array( '_layout_one', '_theme_block_*' ), cradle_get_setting( 'duplicate', 'extra_meta_keys' ) );
		assert_same( array( 'page' ), Policy::supported_post_types() );
		cradle()->settings()->reset( 'duplicate' );
	}
);

cradle_test(
	'duplicate: fixed exclusions (ownership markers, provisioning identity, locks) win over an allowlist',
	function () {
		cradle_dup_ready();
		wp_set_current_user( cradle_test_user( 'administrator' ) );
		$f     = cradle_dup_page_fixture();
		$allow = function ( $list ) {
			$list[] = '_cradle_*';
			$list[] = '_edit_*';
			return $list;
		};
		add_filter( 'cradle_duplicate/meta_allowlist', $allow );
		$result = Duplicator::duplicate( $f['page'] );
		remove_filter( 'cradle_duplicate/meta_allowlist', $allow );
		assert_true( is_array( $result ) );
		$new = (int) $result['id'];
		assert_same( 'plugin-private', get_post_meta( $new, '_cradle_dup_unknown_state', true ), 'Allowlisted private meta is copied.' );
		foreach ( array( '_cradle_source_key', '_cradle_generated', '_cradle_generation_batch', '_cradle_generation_hash', '_edit_lock', '_edit_last' ) as $key ) {
			assert_false( metadata_exists( 'post', $new, $key ), "{$key} is never copied, even when allowlisted." );
		}
		cradle_dup_track_post( $new );
	}
);
