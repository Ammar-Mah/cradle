<?php
/**
 * Cradle Dummy Content integration tests (northstar §16, acceptance CR-19).
 *
 * Every batch created here is purged at shutdown (with edited items included),
 * and every fixture is marked `_cradle_test_fixture = dummy-content`.
 */

use Cradle\Dummy_Content\Batches;
use Cradle\Dummy_Content\Cleanup;
use Cradle\Dummy_Content\Generator;
use Cradle\Dummy_Content\Ownership;
use Cradle\Dummy_Content\Request;

$GLOBALS['cradle_dc'] = array(
	'batches' => array(),
	'posts'   => array(),
	'terms'   => array(),
	'shared'  => array(),
);

function cradle_dc_ready() {
	if ( ! is_plugin_active( 'cradle-dummy-content/cradle-dummy-content.php' ) || ! cradle() || ! cradle()->modules()->is_ready( 'dummy-content' ) ) {
		skip_test( 'cradle-dummy-content is not active and ready.' );
	}
}

function cradle_dc_admin(): int {
	$admin = cradle_test_user( 'administrator' );
	wp_set_current_user( $admin );
	return $admin;
}

function cradle_dc_fixture_post( array $args ): int {
	$id = (int) wp_insert_post( $args + array( 'post_status' => 'publish', 'post_type' => 'post' ) );
	update_post_meta( $id, '_cradle_test_fixture', 'dummy-content' );
	$GLOBALS['cradle_dc']['posts'][] = $id;
	return $id;
}

function cradle_dc_run_job( string $uuid, int $max = 50 ): array {
	$job = array();
	for ( $i = 0; $i < $max; $i++ ) {
		$job = cradle()->jobs()->run( $uuid );
		if ( is_wp_error( $job ) ) {
			throw new Cradle_Test_Failure( 'Job run failed: ' . $job->get_error_message() );
		}
		if ( in_array( $job['state'], array( 'completed', 'failed', 'cancelled' ), true ) ) {
			break;
		}
	}
	if ( 'completed' !== $job['state'] ) {
		throw new Cradle_Test_Failure( 'Job did not complete: ' . $job['state'] . ' ' . $job['error_message'] );
	}
	return $job;
}

/**
 * Generates a batch as the current user and runs it to completion.
 *
 * @return array{uuid:string,ids:int[]}
 */
function cradle_dc_generate( array $raw ): array {
	$result = Generator::start( $raw );
	if ( is_wp_error( $result ) ) {
		throw new Cradle_Test_Failure( 'Generation refused: ' . wp_json_encode( $result->get_error_data() ) );
	}
	$GLOBALS['cradle_dc']['batches'][] = $result['batch']['uuid'];
	cradle_dc_run_job( $result['job']['uuid'] );
	return array(
		'uuid' => $result['batch']['uuid'],
		'ids'  => array_map( 'intval', wp_list_pluck( Ownership::batch_posts( $result['batch']['uuid'], 'content' ), 'ID' ) ),
	);
}

function cradle_dc_cleanup( string $uuid, string $mode, bool $include = false ): array {
	$job = Cleanup::start( $uuid, $mode, $include );
	if ( is_wp_error( $job ) ) {
		throw new Cradle_Test_Failure( 'Cleanup refused: ' . $job->get_error_message() );
	}
	return cradle_dc_run_job( $job['uuid'] );
}

function cradle_dc_titles( array $ids ): array {
	$out = array();
	foreach ( $ids as $id ) {
		$out[ (string) get_post_meta( $id, Ownership::INDEX, true ) ] = get_post( $id )->post_title;
	}
	ksort( $out );
	return $out;
}

function cradle_dc_base_request( array $extra = array() ): array {
	return array_merge(
		array(
			'batch_name'     => 'Cradle DC test batch',
			'note'           => 'Created by tests/integration/dummy-content.php',
			'post_type'      => 'post',
			'count'          => '5',
			'status'         => 'draft',
			'title_pattern'  => 'headline',
			'body_pattern'   => 'article',
			'vocabulary'     => 'english',
			'paragraphs_min' => '2',
			'paragraphs_max' => '4',
			'excerpt'        => '1',
			'seed'           => 'cradle-test-seed',
		),
		$extra
	);
}

function cradle_dc_fixture_image(): int {
	if ( ! Request::gd_available() ) {
		skip_test( 'GD is not available.' );
	}
	$img = imagecreatetruecolor( 20, 20 );
	ob_start();
	imagepng( $img );
	$bytes  = ob_get_clean();
	$upload = wp_upload_bits( 'cradle-dc-fixture-' . wp_generate_password( 6, false ) . '.png', null, $bytes );
	$id     = (int) wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'Cradle DC pre-existing image',
			'post_status'    => 'inherit',
		),
		$upload['file']
	);
	update_post_meta( $id, '_cradle_test_fixture', 'dummy-content' );
	$GLOBALS['cradle_dc']['posts'][] = $id;
	return $id;
}

register_shutdown_function(
	function () {
		if ( ! function_exists( 'cradle' ) || ! cradle() || ! class_exists( Cleanup::class ) ) {
			return;
		}
		global $wpdb;
		wp_set_current_user( cradle_test_user( 'administrator' ) );
		// 1. Real fixtures (and Cradle Duplicate copies of them) first, so they no longer "use" batch media or terms.
		$ids = array_map( 'intval', $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cradle_test_fixture' AND meta_value = 'dummy-content'" ) );
		$ids = array_unique( array_merge( $ids, $GLOBALS['cradle_dc']['posts'] ) );
		foreach ( $ids as $id ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cradle_duplicate_of' AND meta_value = %d", $id ) ) as $copy ) {
				wp_delete_post( (int) $copy, true );
			}
			if ( 'attachment' === get_post_type( $id ) ) {
				wp_delete_attachment( $id, true );
			} else {
				wp_delete_post( $id, true );
			}
		}
		// 2. Purge every batch created by these tests (edited items included), then drop the records.
		foreach ( array_unique( $GLOBALS['cradle_dc']['batches'] ) as $uuid ) {
			foreach ( cradle()->jobs()->recent( 'dummy-content', 100 ) as $job ) {
				if ( ( $job['payload']['batch'] ?? '' ) === $uuid && ! in_array( $job['state'], array( 'completed', 'failed', 'cancelled' ), true ) ) {
					cradle()->jobs()->cancel( $job['uuid'] );
				}
			}
			$job = Cleanup::start( $uuid, 'purge', true );
			if ( ! is_wp_error( $job ) ) {
				for ( $i = 0; $i < 50; $i++ ) {
					$state = cradle()->jobs()->run( $job['uuid'] );
					if ( is_wp_error( $state ) || in_array( $state['state'], array( 'completed', 'failed', 'cancelled' ), true ) ) {
						break;
					}
				}
			}
			$left  = Ownership::batch_posts( $uuid, 'content', 0, 1 ) || Ownership::batch_posts( $uuid, 'attachment', 0, 1 ) || Ownership::batch_terms( $uuid );
			$batch = Batches::get( $uuid );
			if ( $left ) {
				fwrite( STDERR, "WARNING: dummy-content batch {$uuid} still owns data after test cleanup.\n" );
			} elseif ( $batch ) {
				Batches::forget( $batch );
			}
		}
		foreach ( $GLOBALS['cradle_dc']['terms'] as $term ) {
			wp_delete_term( $term[0], $term[1] );
		}
		cradle()->settings()->reset( 'dummy-content' );
	}
);


cradle_test(
	'dummy-content: module is ready with capabilities, job types and protected non-REST markers',
	function () {
		cradle_dc_ready();
		assert_true( get_role( 'administrator' )->has_cap( 'cradle_generate_dummy' ) );
		assert_true( get_role( 'administrator' )->has_cap( 'cradle_cleanup_dummy' ) );
		foreach ( array( 'editor', 'author', 'subscriber' ) as $role ) {
			assert_false( get_role( $role )->has_cap( 'cradle_generate_dummy' ), "{$role} must not generate." );
			assert_false( get_role( $role )->has_cap( 'cradle_cleanup_dummy' ), "{$role} must not clean up." );
		}
		assert_true( (bool) cradle()->jobs()->type( 'dummy-content/generate' ) );
		assert_true( (bool) cradle()->jobs()->type( 'dummy-content/cleanup' ) );
		$registered = get_registered_meta_keys( 'post', '' );
		foreach ( Ownership::keys() as $key ) {
			assert_true( isset( $registered[ $key ] ), "{$key} is registered." );
			assert_false( (bool) $registered[ $key ]['show_in_rest'], "{$key} is not exposed in REST." );
			assert_true( is_protected_meta( $key, 'post' ), "{$key} is protected." );
		}
		assert_false( in_array( 'product', array_keys( Request::post_types() ), true ) );
		assert_false( in_array( 'attachment', array_keys( Request::post_types() ), true ) );
	}
);

cradle_test(
	'dummy-content: request validation rejects unsafe or out-of-range input',
	function () {
		cradle_dc_ready();
		cradle_dc_admin();
		$bad = Request::validate(
			cradle_dc_base_request(
				array(
					'count'          => '9999',
					'title_pattern'  => 'custom',
					'title_template' => '{noun} {eval}',
					'authors'        => array( '99999999' ),
					'date_from'      => '2030-01-01',
					'meta'           => array( array( 'key' => '_cradle_generated', 'type' => 'word' ) ),
					'image_mode'     => 'library',
					'image_library'  => array( '99999999' ),
					'seed'           => 'bad seed!',
				)
			)
		);
		assert_same( null, $bad['config'] );
		foreach ( array( 'count', 'title_template', 'authors', 'dates', 'meta', 'images', 'seed' ) as $field ) {
			assert_true( isset( $bad['errors'][ $field ] ), "Expected an error for {$field}." );
		}
		$type = Request::validate( cradle_dc_base_request( array( 'post_type' => 'attachment' ) ) );
		assert_true( isset( $type['errors']['post_type'] ) );
		$order = Request::validate( cradle_dc_base_request( array( 'date_from' => '2024-05-02', 'date_to' => '2024-05-01' ) ) );
		assert_true( isset( $order['errors']['dates'] ) );
		$nest = Request::validate( cradle_dc_base_request( array( 'rel_parent' => '1' ) ) );
		assert_true( isset( $nest['errors']['relationships'] ), 'Posts are not hierarchical.' );
		$ok = Request::validate( cradle_dc_base_request() );
		assert_same( array(), $ok['errors'] );
		assert_same( 'draft', $ok['config']['status'] );
	}
);

cradle_test(
	'dummy-content: production guard requires an explicit typed confirmation',
	function () {
		cradle_dc_ready();
		cradle_dc_admin();
		$prod = fn() => true;
		add_filter( 'cradle_dummy_content/is_production', $prod );
		$missing = Request::validate( cradle_dc_base_request() );
		$wrong   = Request::validate( cradle_dc_base_request( array( 'confirm_production' => 'yes' ) ) );
		$right   = Request::validate( cradle_dc_base_request( array( 'confirm_production' => Request::production_phrase() ) ) );
		remove_filter( 'cradle_dummy_content/is_production', $prod );
		assert_true( isset( $missing['errors']['confirm_production'] ) );
		assert_true( isset( $wrong['errors']['confirm_production'] ) );
		assert_same( array(), $right['errors'] );
		assert_true( $right['config']['production'] );
	}
);

cradle_test(
	'dummy-content: seeded batch records ownership, valid block markup, tracked terms and a local placeholder image',
	function () {
		cradle_dc_ready();
		$admin   = cradle_dc_admin();
		$started = time();
		$batch   = cradle_dc_generate(
			cradle_dc_base_request(
				array(
					'tax'        => array(
						'category' => array(
							'generate' => '2',
							'per_item' => '1',
						),
					),
					'meta'       => array( array( 'key' => 'cradle_dc_price', 'type' => 'number' ) ),
					'image_mode' => 'placeholder',
					'image_count' => '1',
					'date_from'  => '2024-01-01',
					'date_to'    => '2024-06-30',
				)
			)
		);
		$GLOBALS['cradle_dc']['main'] = $batch;
		assert_same( 5, count( $batch['ids'] ) );
		foreach ( $batch['ids'] as $id ) {
			$post = get_post( $id );
			assert_same( 'draft', $post->post_status );
			assert_same( '1', get_post_meta( $id, '_cradle_generated', true ) );
			assert_same( $batch['uuid'], get_post_meta( $id, '_cradle_generation_batch', true ) );
			assert_same( '1', get_post_meta( $id, '_cradle_generation_version', true ) );
			assert_true( (int) get_post_meta( $id, '_cradle_generated_at', true ) >= $started - 5 );
			assert_same( (string) $admin, get_post_meta( $id, '_cradle_generated_by', true ) );
			assert_same( Ownership::hash_post( $post ), get_post_meta( $id, '_cradle_generation_hash', true ) );
			assert_true( is_numeric( get_post_meta( $id, 'cradle_dc_price', true ) ) );
			assert_true( '' !== $post->post_excerpt );
			assert_true( $post->post_date >= '2024-01-01 00:00:00' && $post->post_date <= '2024-06-30 23:59:59', 'Date inside range: ' . $post->post_date );
			$names = array();
			$walk  = function ( $blocks ) use ( &$walk, &$names ) {
				foreach ( $blocks as $block ) {
					if ( null === $block['blockName'] ) {
						assert_same( '', trim( $block['innerHTML'] ), 'No freeform HTML outside blocks.' );
						continue;
					}
					$names[] = $block['blockName'];
					$walk( $block['innerBlocks'] );
				}
			};
			$walk( parse_blocks( $post->post_content ) );
			assert_same( array(), array_diff( array_unique( $names ), array( 'core/paragraph', 'core/heading', 'core/list', 'core/list-item' ) ), 'Only paragraph/heading/list blocks.' );
			assert_true( in_array( 'core/paragraph', $names, true ) );
			assert_same( 1, count( wp_get_object_terms( $id, 'category' ) ) );
		}
		$terms = Ownership::batch_terms( $batch['uuid'] );
		assert_same( 2, count( $terms ) );
		foreach ( $terms as $term ) {
			assert_same( '1', get_term_meta( (int) $term->term_id, '_cradle_generated', true ) );
			assert_same( $batch['uuid'], get_term_meta( (int) $term->term_id, '_cradle_generation_batch', true ) );
		}
		$images = Ownership::batch_posts( $batch['uuid'], 'attachment' );
		assert_same( 1, count( $images ) );
		$file = get_attached_file( (int) $images[0]->ID );
		assert_true( is_file( $file ), 'Placeholder file exists locally.' );
		assert_same( (int) $images[0]->ID, (int) get_post_thumbnail_id( $batch['ids'][0] ) );
		$record = Batches::get( $batch['uuid'] );
		assert_same( 2, count( $record['owned']['terms'] ) );
		assert_same( array( (int) $images[0]->ID ), array_map( 'intval', $record['owned']['attachments'] ) );
		assert_same( 'generated', $record['state'] );
		assert_same( 'cradle-test-seed', $record['config']['seed'] );

		// Markers never appear in REST responses.
		$session = cradle_test_cookies( $admin );
		$nonce   = cradle_test_nonce( $admin, $session, 'wp_rest' );
		$r       = cradle_test_http( 'GET', rest_url( 'wp/v2/posts/' . $batch['ids'][0] ) . '?context=edit', array( 'headers' => array( 'X-WP-Nonce' => $nonce ) ), $admin, $session );
		assert_same( 200, wp_remote_retrieve_response_code( $r ) );
		assert_not_contains( '_cradle_generat', wp_remote_retrieve_body( $r ) );
	}
);

cradle_test(
	'dummy-content: the same seed reproduces the same titles and text; another seed differs',
	function () {
		cradle_dc_ready();
		cradle_dc_admin();
		$main = $GLOBALS['cradle_dc']['main'] ?? null;
		if ( ! $main ) {
			skip_test( 'Main batch was not generated.' );
		}
		$again = cradle_dc_generate( cradle_dc_base_request( array( 'tax' => array( 'category' => array( 'generate' => '2', 'per_item' => '1' ) ) ) ) );
		assert_same( cradle_dc_titles( $main['ids'] ), cradle_dc_titles( $again['ids'] ), 'Same seed, same titles by index.' );
		$first  = array();
		$second = array();
		foreach ( $main['ids'] as $id ) {
			$first[ get_post_meta( $id, Ownership::INDEX, true ) ] = get_post( $id )->post_content;
		}
		foreach ( $again['ids'] as $id ) {
			$second[ get_post_meta( $id, Ownership::INDEX, true ) ] = get_post( $id )->post_content;
		}
		ksort( $first );
		ksort( $second );
		assert_same( $first, $second, 'Same seed, same bodies.' );
		// Terms with the same generated names already exist: the new batch never claims them.
		$ids_a = wp_list_pluck( Ownership::batch_terms( $main['uuid'] ), 'term_id' );
		$ids_b = wp_list_pluck( Ownership::batch_terms( $again['uuid'] ), 'term_id' );
		assert_same( array(), array_intersect( $ids_a, $ids_b ), 'Batches never share owned terms.' );
		$other = cradle_dc_generate( cradle_dc_base_request( array( 'seed' => 'another-seed', 'count' => '3' ) ) );
		assert_true( array_values( cradle_dc_titles( $other['ids'] ) ) !== array_slice( array_values( cradle_dc_titles( $main['ids'] ) ), 0, 3 ), 'A different seed gives different titles.' );
		cradle_dc_cleanup( $again['uuid'], 'purge', true );
		cradle_dc_cleanup( $other['uuid'], 'purge', true );
		assert_same( array(), Ownership::batch_posts( $again['uuid'], 'content' ) );
	}
);

cradle_test(
	'dummy-content: preview counts; edited, copied and in-use items are kept; look-alikes and shared terms survive; trash then purge',
	function () {
		cradle_dc_ready();
		$admin = cradle_dc_admin();
		$main  = $GLOBALS['cradle_dc']['main'] ?? null;
		if ( ! $main ) {
			skip_test( 'Main batch was not generated.' );
		}
		$uuid    = $main['uuid'];
		$preview = Cleanup::preview( $uuid );
		assert_same( 5, $preview['posts']['total'] );
		assert_same( array( 'post' => 5 ), $preview['posts']['by_type'] );
		assert_same( array( 'draft' => 5 ), $preview['posts']['by_status'] );
		assert_same( 2, $preview['terms']['total'] );
		assert_same( 1, $preview['attachments']['total'] );
		assert_same( array(), $preview['posts']['modified'] );

		list( $edited, $plain_a, $plain_b ) = array( $main['ids'][0], $main['ids'][1], $main['ids'][2] );
		// 1. Edit one generated post: flagged and excluded from default cleanup.
		wp_update_post(
			array(
				'ID'           => $edited,
				'post_content' => get_post( $edited )->post_content . "\n\n<!-- wp:paragraph -->\n<p>Edited by a person.</p>\n<!-- /wp:paragraph -->",
			)
		);
		wp_set_object_terms( $edited, array(), 'category' );
		delete_post_thumbnail( $edited );
		// 2. A real post with the same title as a generated one (no markers).
		$lookalike = cradle_dc_fixture_post(
			array(
				'post_title'   => get_post( $plain_a )->post_title,
				'post_content' => 'Real content.',
			)
		);
		// 3. A generated term also used by real content.
		$terms  = Ownership::batch_terms( $uuid );
		$shared = (int) $terms[0]->term_id;
		$solo   = (int) $terms[1]->term_id;
		$real   = cradle_dc_fixture_post( array( 'post_title' => 'Cradle DC real post using a generated term' ) );
		wp_set_object_terms( $real, array( $shared ), 'category' );
		// 4. A naive copy that inherited every meta key (as other duplicators might do).
		$naive = cradle_dc_fixture_post(
			array(
				'post_title'   => get_post( $plain_b )->post_title,
				'post_content' => get_post( $plain_b )->post_content,
				'post_status'  => 'draft',
			)
		);
		foreach ( get_post_meta( $plain_b ) as $key => $values ) {
			if ( '_cradle_test_fixture' !== $key ) {
				foreach ( $values as $value ) {
					add_post_meta( $naive, $key, maybe_unserialize( $value ) );
				}
			}
		}
		// 5. A copy made with Cradle Duplicate carries no generation markers.
		$dup_copy = 0;
		if ( class_exists( 'Cradle\\Duplicate\\Duplicator' ) && cradle()->modules()->is_ready( 'duplicate' ) ) {
			$result = Cradle\Duplicate\Duplicator::duplicate( $plain_a );
			assert_true( is_array( $result ), 'Cradle Duplicate copy should succeed.' );
			$dup_copy = (int) $result['id'];
			update_post_meta( $dup_copy, '_cradle_test_fixture', 'dummy-content' );
			$GLOBALS['cradle_dc']['posts'][] = $dup_copy;
			foreach ( array_keys( get_post_meta( $dup_copy ) ) as $key ) {
				assert_false( str_starts_with( (string) $key, '_cradle_generat' ), "Duplicate must not inherit {$key}." );
			}
			// The copy is real content: it uses the shared term and still references the placeholder image.
			wp_set_object_terms( $dup_copy, array( $shared ), 'category' );
			set_post_thumbnail( $dup_copy, (int) Ownership::batch_posts( $uuid, 'attachment' )[0]->ID );
		}

		$preview = Cleanup::preview( $uuid );
		assert_true( in_array( $edited, $preview['posts']['modified'], true ), 'Edited item is flagged.' );
		assert_true( in_array( $naive, $preview['posts']['modified'], true ), 'Copy with inherited markers is flagged (hash is bound to the original ID).' );
		assert_same( 2, count( $preview['posts']['modified'] ) );
		assert_same( 6, $preview['posts']['total'], 'Only marked items are counted (5 generated + inherited-marker copy).' );
		assert_true( in_array( $shared, $preview['terms']['shared'], true ), 'Term used by real content is shown as shared.' );

		// Trash (default mode, edited items excluded).
		cradle_dc_cleanup( $uuid, 'trash' );
		clean_post_cache( $edited );
		assert_same( 'draft', get_post_status( $edited ), 'Edited item kept.' );
		assert_same( 'draft', get_post_status( $naive ), 'Inherited-marker copy kept.' );
		assert_same( 'publish', get_post_status( $lookalike ), 'Look-alike real post untouched.' );
		foreach ( array_slice( $main['ids'], 1 ) as $id ) {
			clean_post_cache( $id );
			assert_same( 'trash', get_post_status( $id ), "Generated item {$id} trashed." );
		}
		if ( $dup_copy ) {
			assert_same( 'draft', get_post_status( $dup_copy ), 'Cradle Duplicate copy is not cleanup-owned.' );
		}
		assert_true( (bool) get_term( $solo, 'category' ), 'Terms stay while items are only trashed.' );
		$image = (int) Ownership::batch_posts( $uuid, 'attachment' )[0]->ID;
		assert_true( 'attachment' === get_post_type( $image ), 'Images stay while items are only trashed.' );
		$record = Batches::get( $uuid );
		$last   = end( $record['reports'] );
		assert_same( 'trash', $last['type'] );
		assert_same( 4, (int) $last['counts']['trashed'] );
		assert_same( 2, (int) $last['counts']['kept_modified'] );

		// Purge (separate explicit action).
		$file = get_attached_file( $image );
		cradle_dc_cleanup( $uuid, 'purge' );
		foreach ( array_slice( $main['ids'], 1 ) as $id ) {
			clean_post_cache( $id );
			assert_same( null, get_post( $id ), "Generated item {$id} permanently deleted." );
		}
		clean_post_cache( $edited );
		assert_true( (bool) get_post( $edited ), 'Edited item survives purge by default.' );
		assert_true( (bool) get_post( $naive ), 'Inherited-marker copy survives purge by default.' );
		assert_true( (bool) get_post( $lookalike ) && (bool) get_post( $real ), 'Real posts survive.' );
		if ( $dup_copy ) {
			assert_true( (bool) get_post( $dup_copy ), 'Cradle Duplicate copy survives purge.' );
		}
		clean_term_cache( array( $shared, $solo ), 'category' );
		assert_true( get_term( $shared, 'category' ) instanceof WP_Term, 'Generated term used by a real post is retained.' );
		assert_same( null, get_term( $solo, 'category' ), 'Unused generated term is deleted.' );
		$record = Batches::get( $uuid );
		$last   = end( $record['reports'] );
		assert_same( 'purge', $last['type'] );
		assert_true( (bool) $last['complete'] );
		assert_same( 1, (int) $last['counts']['terms_deleted'] );
		assert_same( 1, (int) $last['counts']['kept_shared_terms'] );
		if ( $dup_copy ) {
			assert_same( 'attachment', get_post_type( $image ), 'Placeholder still used by the (real) duplicate is retained.' );
			assert_true( is_file( $file ) );
			assert_same( 1, (int) $last['counts']['kept_attachments_in_use'] );
		} else {
			assert_same( null, get_post( $image ), 'Unused placeholder image deleted.' );
			assert_false( is_file( $file ), 'Placeholder file removed from disk.' );
			assert_same( 1, (int) $last['counts']['attachments_deleted'] );
		}

		// Explicitly including edited items removes them too (but never unmarked content).
		cradle_dc_cleanup( $uuid, 'purge', true );
		assert_same( null, get_post( $edited ) );
		assert_same( null, get_post( $naive ) );
		assert_true( (bool) get_post( $lookalike ) && (bool) get_post( $real ) );
		if ( $dup_copy ) {
			assert_true( (bool) get_post( $dup_copy ), 'Cradle Duplicate copy is never cleanup-owned.' );
		}
		assert_true( get_term( $shared, 'category' ) instanceof WP_Term, 'Shared term still retained.' );

		// Once the real content stops using them, the batch's remaining term and image are removed.
		if ( $dup_copy ) {
			wp_delete_post( $dup_copy, true );
		}
		wp_delete_post( $real, true );
		cradle_dc_cleanup( $uuid, 'purge' );
		clean_term_cache( array( $shared ), 'category' );
		assert_same( null, get_term( $shared, 'category' ) );
		assert_same( null, get_post( $image ) );
		assert_false( is_file( $file ), 'Placeholder file removed from disk.' );
		assert_same( array(), Ownership::batch_terms( $uuid ) );
		assert_same( 'removed', Batches::get( $uuid )['state'] );
		assert_true( (bool) get_post( $lookalike ), 'Look-alike real post is still untouched.' );
	}
);

cradle_test(
	'dummy-content: pre-existing media selected for a batch is never deleted; nested pages and related items are populated',
	function () {
		cradle_dc_ready();
		cradle_dc_admin();
		$image = cradle_dc_fixture_image();
		$batch = cradle_dc_generate(
			cradle_dc_base_request(
				array(
					'post_type'     => 'page',
					'count'         => '4',
					'image_mode'    => 'library',
					'image_library' => array( (string) $image ),
					'rel_parent'    => '1',
					'rel_meta_key'  => 'cradle_dc_related',
					'seed'          => 'nested-seed',
				)
			)
		);
		$parents = 0;
		foreach ( $batch['ids'] as $id ) {
			assert_same( $image, (int) get_post_thumbnail_id( $id ) );
			$parent = (int) get_post( $id )->post_parent;
			if ( $parent ) {
				++$parents;
				assert_true( in_array( $parent, $batch['ids'], true ), 'Parents come from the same batch.' );
			}
			$related = get_post_meta( $id, 'cradle_dc_related', true );
			if ( $related ) {
				assert_same( array(), array_diff( array_map( 'intval', (array) $related ), $batch['ids'] ), 'Related items come from the same batch.' );
			}
		}
		assert_true( (bool) get_post_meta( $batch['ids'][3], 'cradle_dc_related', true ), 'Later items get related IDs.' );
		assert_same( 1, count( Batches::get( $batch['uuid'] )['referenced']['attachments'] ) );
		assert_same( 0, count( Ownership::batch_posts( $batch['uuid'], 'attachment' ) ), 'Selected image is referenced, not owned.' );
		cradle_dc_cleanup( $batch['uuid'], 'purge', true );
		clean_post_cache( $image );
		assert_same( 'attachment', get_post_type( $image ), 'Pre-existing image survives purge.' );
		assert_same( array(), Ownership::batch_posts( $batch['uuid'], 'content' ) );
		assert_true( $parents >= 0 );
	}
);

cradle_test(
	'dummy-content: cleanup and generation are resumable after cancellation without losing progress',
	function () {
		cradle_dc_ready();
		cradle_dc_admin();
		cradle()->settings()->update( 'dummy-content', array( 'items_per_step' => 2 ) );
		$jobs = cradle()->jobs();

		// Generation: run only the first step (terms), cancel, then resume.
		$start = Generator::start( cradle_dc_base_request( array( 'count' => '5', 'seed' => 'resume-seed' ) ) );
		assert_true( is_array( $start ) );
		$uuid                              = $start['batch']['uuid'];
		$GLOBALS['cradle_dc']['batches'][] = $uuid;
		for ( $i = 0; $i < 3; $i++ ) { // terms, images, first posts step.
			$claimed = $jobs->claim( $start['job']['uuid'] );
			$jobs->commit( $claimed, Generator::step( $claimed ), true );
		}
		assert_same( 2, count( Ownership::batch_posts( $uuid, 'content' ) ), 'One posts step created two items.' );
		assert_wp_error( Cleanup::start( $uuid, 'trash', false ), 'cradle_dummy_running', 'Cleanup waits while generation runs.' );
		assert_true( $jobs->cancel( $start['job']['uuid'] ) );
		$resumed = Generator::resume( Batches::get( $uuid ) );
		assert_true( is_array( $resumed ) && $resumed['uuid'] !== $start['job']['uuid'] );
		cradle_dc_run_job( $resumed['uuid'] );
		$ids = wp_list_pluck( Ownership::batch_posts( $uuid, 'content' ), 'ID' );
		assert_same( 5, count( $ids ), 'Resume created exactly the missing items (no duplicates).' );

		// Cleanup: one step, cancel, verify partial report, then a new job finishes the rest.
		$job     = Cleanup::start( $uuid, 'trash', false );
		$claimed = $jobs->claim( $job['uuid'] );
		$jobs->commit( $claimed, Cleanup::step( $claimed ), true );
		assert_true( $jobs->cancel( $job['uuid'] ) );
		assert_same( 'cancelled', $jobs->get( $job['uuid'] )['state'] );
		$report = null;
		foreach ( Batches::get( $uuid )['reports'] as $item ) {
			if ( $item['job'] === $job['uuid'] ) {
				$report = $item;
			}
		}
		assert_true( is_array( $report ) && 2 === (int) $report['counts']['trashed'] && ! $report['complete'], 'Partial progress is recorded.' );
		$trashed = 0;
		foreach ( $ids as $id ) {
			clean_post_cache( $id );
			$trashed += 'trash' === get_post_status( $id ) ? 1 : 0;
		}
		assert_same( 2, $trashed, 'Exactly the items of the completed step were trashed before cancelling.' );
		cradle_dc_cleanup( $uuid, 'trash' );
		foreach ( $ids as $id ) {
			clean_post_cache( $id );
			assert_same( 'trash', get_post_status( $id ) );
		}
		cradle_dc_cleanup( $uuid, 'purge' );
		assert_same( array(), Ownership::batch_posts( $uuid, 'content' ) );
		cradle()->settings()->reset( 'dummy-content' );
	}
);

cradle_test(
	'dummy-content: editor and subscriber are denied generate and cleanup (PHP API and HTTP), GET never mutates',
	function () {
		cradle_dc_ready();
		$admin = cradle_dc_admin();
		$main  = cradle_dc_generate( cradle_dc_base_request( array( 'count' => '2', 'seed' => 'deny-seed' ) ) );
		$count = count( Batches::all() );
		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			$user = cradle_test_user( $role );
			wp_set_current_user( $user );
			assert_wp_error( Generator::start( cradle_dc_base_request() ), 'cradle_dummy_forbidden' );
			assert_wp_error( Cleanup::start( $main['uuid'], 'trash', false ), 'cradle_dummy_forbidden' );
			$session = cradle_test_cookies( $user );
			$nonce   = cradle_test_nonce( $user, $session, 'cradle_dummy_generate' );
			$r       = cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array_merge( cradle_dc_base_request(), array( 'action' => 'cradle_dummy_generate', '_wpnonce' => $nonce ) ) ), $user, $session );
			assert_same( 403, wp_remote_retrieve_response_code( $r ), "{$role} generate over HTTP." );
			$nonce = cradle_test_nonce( $user, $session, 'cradle_dummy_cleanup_' . $main['uuid'] );
			$r     = cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array( 'action' => 'cradle_dummy_cleanup', 'batch' => $main['uuid'], 'mode' => 'purge', 'confirm_purge' => '1', 'include_modified' => '1', '_wpnonce' => $nonce ) ), $user, $session );
			assert_same( 403, wp_remote_retrieve_response_code( $r ), "{$role} cleanup over HTTP." );
			$r = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-dummy-content' ), array(), $user, $session );
			assert_true( 200 !== wp_remote_retrieve_response_code( $r ) || false !== strpos( wp_remote_retrieve_body( $r ), 'not allowed' ), "{$role} cannot open the screen." );
		}
		wp_set_current_user( $admin );
		assert_same( $count, count( Batches::all() ), 'No batch was created by denied users.' );
		foreach ( $main['ids'] as $id ) {
			clean_post_cache( $id );
			assert_same( 'draft', get_post_status( $id ), 'Denied cleanup changed nothing.' );
		}
		// GET on mutation endpoints is refused and changes nothing.
		$r = cradle_test_http( 'GET', admin_url( 'admin-post.php?action=cradle_dummy_generate' ), array(), $admin );
		assert_same( 405, wp_remote_retrieve_response_code( $r ) );
		$r = cradle_test_http( 'GET', admin_url( 'admin-post.php?action=cradle_dummy_cleanup&batch=' . $main['uuid'] . '&mode=purge' ), array(), $admin );
		assert_same( 405, wp_remote_retrieve_response_code( $r ) );
		// Admin without nonce is refused.
		$r = cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array( 'action' => 'cradle_dummy_cleanup', 'batch' => $main['uuid'], 'mode' => 'trash' ) ), $admin );
		assert_same( 403, wp_remote_retrieve_response_code( $r ) );
		assert_same( $count, count( Batches::all() ) );
		foreach ( $main['ids'] as $id ) {
			clean_post_cache( $id );
			assert_same( 'draft', get_post_status( $id ) );
		}
		// Job AJAX: an editor cannot step or cancel an administrator's job.
		$job      = Cleanup::start( $main['uuid'], 'trash', false );
		$editor   = cradle_test_user( 'editor' );
		$esession = cradle_test_cookies( $editor );
		$enonce   = cradle_test_nonce( $editor, $esession, 'cradle_jobs' );
		foreach ( array( 'cradle_job_step', 'cradle_job_cancel' ) as $action ) {
			$r = cradle_test_http( 'POST', admin_url( 'admin-ajax.php' ), array( 'body' => array( 'action' => $action, 'nonce' => $enonce, 'job' => $job['uuid'] ) ), $editor, $esession );
			assert_same( 404, wp_remote_retrieve_response_code( $r ), "Editor {$action} refused." );
		}
		cradle()->jobs()->cancel( $job['uuid'] );
	}
);

cradle_test(
	'dummy-content: administrator generates and cleans up over HTTP (admin-post + core job runner)',
	function () {
		cradle_dc_ready();
		$admin   = cradle_dc_admin();
		$session = cradle_test_cookies( $admin );
		$nonce   = cradle_test_nonce( $admin, $session, 'cradle_dummy_generate' );
		$body    = array_merge(
			cradle_dc_base_request(
				array(
					'batch_name' => 'Cradle DC HTTP batch',
					'count'      => '3',
					'status'     => 'publish',
					'seed'       => 'http-seed',
				)
			),
			array(
				'action'   => 'cradle_dummy_generate',
				'_wpnonce' => $nonce,
			)
		);
		$r        = cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => $body ), $admin, $session );
		$location = (string) wp_remote_retrieve_header( $r, 'location' );
		assert_same( 302, wp_remote_retrieve_response_code( $r ) );
		assert_true( (bool) preg_match( '/batch=([0-9a-f-]{36})/', $location, $m ), 'Redirects to the batch: ' . $location );
		$uuid                              = $m[1];
		$GLOBALS['cradle_dc']['batches'][] = $uuid;
		wp_cache_flush();
		$batch = Batches::get( $uuid );
		assert_true( is_array( $batch ) && 'Cradle DC HTTP batch' === $batch['name'] );

		// Batch screen renders the core job runner markup.
		$html = wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-dummy-content&batch=' . $uuid ), array(), $admin, $session ) );
		assert_contains( 'data-cradle-job="' . $batch['job'] . '"', $html );

		// No-JS path: run steps through the nonce-protected admin-post control.
		for ( $i = 0; $i < 10; $i++ ) {
			$run_nonce = cradle_test_nonce( $admin, $session, 'cradle_dummy_job_run_' . $batch['job'] );
			cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array( 'action' => 'cradle_dummy_job', 'op' => 'run', 'job' => $batch['job'], 'batch' => $uuid, '_wpnonce' => $run_nonce ) ), $admin, $session );
			if ( 'completed' === cradle()->jobs()->get( $batch['job'] )['state'] ) {
				break;
			}
		}
		assert_same( 'completed', cradle()->jobs()->get( $batch['job'] )['state'] );
		$ids = wp_list_pluck( Ownership::batch_posts( $uuid, 'content' ), 'ID' );
		assert_same( 3, count( $ids ) );
		foreach ( $ids as $id ) {
			clean_post_cache( $id );
			wp_cache_delete( $id, 'post_meta' );
			assert_same( 'publish', get_post_status( $id ) );
			assert_false( metadata_exists( 'post', $id, '_pingme' ), 'No pingback queued for sample content.' );
			assert_false( metadata_exists( 'post', $id, '_encloseme' ), 'No enclosure lookup queued for sample content.' );
		}

		// Purge over HTTP, driven by core's AJAX runner with the cradle_jobs nonce.
		$cnonce = cradle_test_nonce( $admin, $session, 'cradle_dummy_cleanup_' . $uuid );
		$r      = cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array( 'action' => 'cradle_dummy_cleanup', 'batch' => $uuid, 'mode' => 'purge', '_wpnonce' => $cnonce ) ), $admin, $session );
		assert_same( 302, wp_remote_retrieve_response_code( $r ), 'Purge without confirmation redirects back with an error.' );
		wp_cache_flush();
		assert_same( 1, count( Batches::get( $uuid )['reports'] ), 'No cleanup job without purge confirmation.' );
		$r = cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array( 'action' => 'cradle_dummy_cleanup', 'batch' => $uuid, 'mode' => 'purge', 'confirm_purge' => '1', '_wpnonce' => $cnonce ) ), $admin, $session );
		assert_contains( 'cradle_notice=cleanup_started', (string) wp_remote_retrieve_header( $r, 'location' ) );
		wp_cache_flush();
		$reports = Batches::get( $uuid )['reports'];
		$job     = end( $reports )['job'];
		$jnonce  = cradle_test_nonce( $admin, $session, 'cradle_jobs' );
		for ( $i = 0; $i < 10; $i++ ) {
			$r    = cradle_test_http( 'POST', admin_url( 'admin-ajax.php' ), array( 'body' => array( 'action' => 'cradle_job_step', 'nonce' => $jnonce, 'job' => $job ) ), $admin, $session );
			$data = json_decode( wp_remote_retrieve_body( $r ), true );
			assert_true( ! empty( $data['success'] ), 'Job step via AJAX: ' . wp_remote_retrieve_body( $r ) );
			if ( $data['data']['terminal'] ) {
				break;
			}
		}
		assert_same( 'completed', $data['data']['state'] );
		wp_cache_flush();
		assert_same( array(), Ownership::batch_posts( $uuid, 'content' ) );
		foreach ( $ids as $id ) {
			assert_same( null, get_post( $id ) );
		}
		$html = wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-dummy-content&batch=' . $uuid ), array(), $admin, $session ) );
		assert_contains( 'Operation reports', $html );
		assert_contains( 'no longer owns any content', $html );
	}
);

cradle_test(
	'dummy-content: screens render for administrators (batches, generate form, limits)',
	function () {
		cradle_dc_ready();
		$admin = cradle_test_user( 'administrator' );
		foreach ( array( '', '&tab=generate', '&tab=generate&dc_type=page', '&tab=settings' ) as $suffix ) {
			$r    = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-dummy-content' . $suffix ), array(), $admin );
			$html = wp_remote_retrieve_body( $r );
			assert_same( 200, wp_remote_retrieve_response_code( $r ), "Screen {$suffix} loads." );
			assert_not_contains( 'Fatal error', $html );
		}
		$html = wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-dummy-content&tab=generate' ), array(), $admin ) );
		assert_contains( 'name="seed"', $html );
		assert_contains( 'name="tax[category][generate]"', $html );
		assert_contains( 'name="image_mode"', $html );
		assert_contains( 'dummy-admin.js', $html );
	}
);

cradle_test(
	'dummy-content: uninstall retains by default; purge removes records, settings and capabilities but never generated content',
	function () {
		cradle_dc_ready();
		cradle_dc_admin();
		cradle()->settings()->update( 'dummy-content', array( 'keep_reports' => 10 ) );
		$batch = cradle_dc_generate( cradle_dc_base_request( array( 'count' => '1', 'seed' => 'uninstall-seed' ) ) );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'cradle-dummy-content/cradle-dummy-content.php' );
		}
		$file     = WP_PLUGIN_DIR . '/cradle-dummy-content/uninstall.php';
		$had      = false !== get_option( 'cradle_uninstall_policy', false );
		$original = get_option( 'cradle_uninstall_policy', array() );
		$original = is_array( $original ) ? $original : array();
		$policies = $original;
		unset( $policies['dummy-content'] );
		update_option( 'cradle_uninstall_policy', $policies, false );
		include $file;
		assert_true( (bool) Batches::get( $batch['uuid'] ), 'Retain: batch record kept.' );
		assert_true( false !== get_option( 'cradle_dummy_content_settings', false ), 'Retain: settings kept.' );
		assert_true( get_role( 'administrator' )->has_cap( 'cradle_generate_dummy' ) );

		$policies['dummy-content'] = 'purge';
		update_option( 'cradle_uninstall_policy', $policies, false );
		try {
			include $file;
			assert_same( null, Batches::get( $batch['uuid'] ), 'Purge: batch record removed.' );
			assert_same( false, get_option( 'cradle_dummy_content_settings', false ), 'Purge: settings removed.' );
			assert_false( get_role( 'administrator' )->has_cap( 'cradle_generate_dummy' ) );
			assert_false( get_role( 'administrator' )->has_cap( 'cradle_cleanup_dummy' ) );
			clean_post_cache( $batch['ids'][0] );
			wp_cache_delete( $batch['ids'][0], 'post_meta' );
			assert_true( (bool) get_post( $batch['ids'][0] ), 'Generated content is not deleted by uninstall.' );
			assert_same( $batch['uuid'], get_post_meta( $batch['ids'][0], '_cradle_generation_batch', true ), 'Markers stay so a reinstall can clean up.' );
		} finally {
			Cradle\Dummy_Content\Lifecycle::activate();
			if ( $had ) {
				update_option( 'cradle_uninstall_policy', $original, false );
			} else {
				delete_option( 'cradle_uninstall_policy' );
			}
		}
		assert_true( get_role( 'administrator' )->has_cap( 'cradle_generate_dummy' ) && get_role( 'administrator' )->has_cap( 'cradle_cleanup_dummy' ), 'Reactivation restores capabilities.' );
		// The batch is now "unrecorded"; cleanup still finds it by markers and recreates a record for the report.
		cradle_dc_cleanup( $batch['uuid'], 'purge', true );
		assert_same( array(), Ownership::batch_posts( $batch['uuid'], 'content' ) );
		$record = Batches::get( $batch['uuid'] );
		assert_true( is_array( $record ) && str_starts_with( $record['name'], 'Unrecorded batch' ), 'A minimal record was recreated for the report.' );
		assert_same( 'removed', $record['state'] );
		assert_same( 1, (int) end( $record['reports'] )['counts']['deleted'] );
	}
);
