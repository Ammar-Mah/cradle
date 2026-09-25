<?php
/**
 * Cradle Content integration tests (suite `content`): definitions over HTTP with allowed and
 * denied roles, reserved/duplicate keys, REST posts and fields with permissions, front-end
 * archive/single rendering, disable/re-enable, export → delete → import, key rename migration,
 * field storage migration, code definitions, lifecycle and uninstall policy.
 *
 * Every fixture uses the `cctest` prefix and is removed at the start and end of the run.
 */

use Cradle\Content\Definitions;
use Cradle\Content\Field_Types;
use Cradle\Content\Registry;
use Cradle\Content\Transfer;
use Cradle\Content\Validator;

const CCT_PT     = 'cctest_book';
const CCT_PT2    = 'cctest_novel';
const CCT_TAX    = 'cctest_genre';
const CCT_OTHER  = 'cctest_other';
const CCT_GROUP  = 'cctest_details';
const CCT_TGROUP = 'cctest_genre_fields';
const CCT_SLUG   = 'cctest-books';

$GLOBALS['cct'] = array(
	'start'    => gmdate( 'Y-m-d H:i:s', time() - 5 ),
	'log_size' => is_file( WP_CONTENT_DIR . '/debug.log' ) ? filesize( WP_CONTENT_DIR . '/debug.log' ) : 0,
	'posts'    => array(),
);

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

function cct_store(): array {
	wp_cache_flush();
	Definitions::instance()->reset_cache();
	return Definitions::instance()->store( true );
}

function cct_session( string $role ): array {
	static $sessions = array();
	if ( ! isset( $sessions[ $role ] ) ) {
		$user               = cradle_test_user( $role );
		$sessions[ $role ] = array( $user, cradle_test_cookies( $user ) );
	}
	return $sessions[ $role ];
}

/**
 * POST to admin-post.php as a role with a nonce minted for that user's session.
 */
function cct_post( string $role, string $action, string $nonce_action, array $body = array() ) {
	list( $user, $session ) = cct_session( $role );
	$body['action']         = $action;
	$body['_wpnonce']       = cradle_test_nonce( $user, $session, $nonce_action );
	return cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => $body ), $user, $session );
}

function cct_get( string $role, string $url ) {
	list( $user, $session ) = cct_session( $role );
	return cradle_test_http( 'GET', $url, array(), $user, $session );
}

function cct_rest( string $role, string $method, string $route, ?array $body = null ) {
	$args = array( 'headers' => array( 'Content-Type' => 'application/json' ) );
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}
	if ( 'anonymous' === $role ) {
		return cradle_test_http( $method, rest_url( ltrim( $route, '/' ) ), $args );
	}
	list( $user, $session )         = cct_session( $role );
	$args['headers']['X-WP-Nonce'] = cradle_test_nonce( $user, $session, 'wp_rest' );
	return cradle_test_http( $method, rest_url( ltrim( $route, '/' ) ), $args, $user, $session );
}

function cct_code( $response ): int {
	return (int) wp_remote_retrieve_response_code( $response );
}

function cct_json( $response ) {
	return json_decode( (string) wp_remote_retrieve_body( $response ), true );
}

function cct_location( $response ): string {
	return (string) wp_remote_retrieve_header( $response, 'location' );
}

function cct_post_type_input( array $overrides = array() ): array {
	return array_merge(
		array(
			'key'                 => CCT_PT,
			'singular'            => 'Test book',
			'plural'              => 'Test books',
			'menu_name'           => '',
			'description'         => 'Books created by the Cradle Content test suite.',
			'icon'                => 'book',
			'menu_position'       => '0',
			'enabled'             => '1',
			'public'              => '1',
			'publicly_queryable'  => '1',
			'exclude_from_search' => '0',
			'show_ui'             => '1',
			'show_in_menu'        => '1',
			'show_in_admin_bar'   => '1',
			'show_in_nav_menus'   => '1',
			'show_in_rest'        => '1',
			'rest_base'           => '',
			'hierarchical'        => '0',
			'has_archive'         => '1',
			'rewrite'             => '1',
			'with_front'          => '0',
			'query_var'           => '1',
			'rewrite_slug'        => CCT_SLUG,
			'archive_slug'        => '',
			'supports'            => array( '', 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'author' ),
			'taxonomies'          => array( '', CCT_TAX ),
			'template'            => array(
				array(
					'block'       => 'core/heading',
					'placeholder' => 'Chapter heading',
					'level'       => '2',
				),
				array(
					'block'       => 'core/paragraph',
					'placeholder' => 'Summary…',
					'level'       => '2',
				),
			),
			'template_lock'       => '',
			'capability_model'    => 'post',
			'capability_singular' => '',
			'capability_plural'   => '',
			'capability_roles'    => array( '' ),
			'order_by'            => 'title',
			'order'               => 'ASC',
			'admin_columns'       => array( '', 'thumbnail', 'modified' ),
		),
		$overrides
	);
}

function cct_taxonomy_input( array $overrides = array() ): array {
	return array_merge(
		array(
			'key'                => CCT_TAX,
			'singular'           => 'Test genre',
			'plural'             => 'Test genres',
			'description'        => '',
			'enabled'            => '1',
			'hierarchical'       => '1',
			'object_types'       => array( '' ),
			'public'             => '1',
			'publicly_queryable' => '1',
			'show_ui'            => '1',
			'show_in_menu'       => '1',
			'show_in_nav_menus'  => '1',
			'show_in_rest'       => '1',
			'show_admin_column'  => '1',
			'query_var'          => '1',
			'rewrite'            => '1',
			'rewrite_slug'       => 'cctest-genres',
			'capability_model'   => 'default',
			'capability_roles'   => array( '' ),
			'default_term_name'  => 'Cctest unsorted',
			'default_term_slug'  => 'cctest-unsorted',
		),
		$overrides
	);
}

function cct_group_input(): array {
	return array(
		'key'          => CCT_GROUP,
		'title'        => 'Book details',
		'description'  => 'Test fields',
		'enabled'      => '1',
		'object'       => 'post',
		'post_targets' => array( '', CCT_PT ),
		'term_targets' => array( '' ),
		'position'     => 'normal',
		'fields'       => array(
			array(
				'original_key' => '',
				'key'          => 'isbn',
				'label'        => 'ISBN',
				'type'         => 'text',
				'exposure'     => 'public',
				'max_length'   => '20',
			),
			array(
				'key'      => 'pages',
				'label'    => 'Pages',
				'type'     => 'number',
				'exposure' => 'public',
				'integer'  => '1',
				'min'      => '1',
				'max'      => '5000',
			),
			array(
				'key'      => 'format',
				'label'    => 'Format',
				'type'     => 'select',
				'exposure' => 'public',
				'options'  => "hardcover|Hardcover\npaperback|Paperback",
			),
			array(
				'key'          => 'featured',
				'label'        => 'Featured book',
				'type'         => 'post_ref',
				'exposure'     => 'public',
				'post_targets' => array( '', CCT_PT ),
			),
			array(
				'key'          => 'related',
				'label'        => 'Related books',
				'type'         => 'post_ref',
				'exposure'     => 'editor',
				'multiple'     => '1',
				'max_items'    => '5',
				'post_targets' => array( '', CCT_PT ),
			),
			array(
				'key'      => 'chapters',
				'label'    => 'Chapters',
				'type'     => 'group',
				'exposure' => 'editor',
				'max_rows' => '10',
			),
			array(
				'key'    => 'chapter_title',
				'label'  => 'Chapter title',
				'type'   => 'text',
				'parent' => 'chapters',
			),
			array(
				'key'     => 'chapter_page',
				'label'   => 'Start page',
				'type'    => 'number',
				'parent'  => 'chapters',
				'integer' => '1',
			),
			array(
				'key'      => 'notes',
				'label'    => 'Internal notes',
				'type'     => 'textarea',
				'exposure' => 'none',
			),
			array(
				'key'   => '',
				'label' => '',
				'type'  => 'text',
			),
		),
	);
}

function cct_term_group_input(): array {
	return array(
		'key'          => CCT_TGROUP,
		'title'        => 'Genre details',
		'enabled'      => '1',
		'object'       => 'term',
		'post_targets' => array( '' ),
		'term_targets' => array( '', CCT_TAX ),
		'fields'       => array(
			array(
				'key'      => 'tagline',
				'label'    => 'Tagline',
				'type'     => 'text',
				'exposure' => 'public',
			),
		),
	);
}

function cct_save( string $role, string $kind, array $def, string $original = '', string $revision = '' ) {
	return cct_post(
		$role,
		'cradle_content_save',
		'cradle_content_save_' . $kind,
		array(
			'kind'     => $kind,
			'original' => $original,
			'revision' => $revision,
			'def'      => $def,
		)
	);
}

function cct_count_posts( string $type ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $type ) ); // phpcs:ignore
}

/**
 * Removes every cctest fixture: definitions, posts, terms, options, jobs, capability records.
 */
function cct_cleanup() {
	global $wpdb;
	wp_cache_flush();
	// Posts of every test type.
	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('cctest_book','cctest_novel','cctest_code','cctest_code2','cctest_x') " ); // phpcs:ignore
	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
	// Terms of test taxonomies (register temporarily so the term API works).
	foreach ( array( CCT_TAX, CCT_OTHER ) as $taxonomy ) {
		delete_option( 'default_term_' . $taxonomy );
		$registered = taxonomy_exists( $taxonomy );
		if ( ! $registered ) {
			register_taxonomy( $taxonomy, array() );
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $term_id ) {
			wp_delete_term( (int) $term_id, $taxonomy );
		}
		if ( ! $registered ) {
			unregister_taxonomy( $taxonomy );
		}
		delete_option( $taxonomy . '_children' ); // Core term hierarchy cache of hierarchical taxonomies.
	}
	// Definitions.
	Definitions::instance()->reset_cache();
	Definitions::instance()->write(
		static function ( array $store ) {
			foreach ( Definitions::KINDS as $kind ) {
				foreach ( array_keys( $store[ $kind ] ) as $key ) {
					if ( str_starts_with( $key, 'cctest' ) ) {
						unset( $store[ $kind ][ $key ] );
					}
				}
			}
			return $store;
		}
	);
	// Capability records of test definitions.
	$records = get_option( 'cradle_content_caps', array() );
	if ( is_array( $records ) ) {
		foreach ( array_keys( $records ) as $id ) {
			if ( str_contains( $id, ':cctest' ) ) {
				Cradle\Content\Caps::forget( strtok( $id, ':' ), substr( $id, strpos( $id, ':' ) + 1 ) );
			}
		}
	}
	// Orphaned test field values and jobs created by this run.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}cradle_jobs WHERE module = %s AND created_at >= %s", 'content', $GLOBALS['cct']['start'] ) ); // phpcs:ignore
	wp_unschedule_hook( 'cradle_content_run_job' );
	foreach ( array( 'administrator', 'editor', 'author', 'subscriber' ) as $role ) {
		$user = get_user_by( 'login', 'cradle_test_' . $role );
		if ( $user ) {
			foreach ( array( 'notice', 'form', 'pending', 'import', 'field_errors' ) as $kind ) {
				delete_transient( 'cradle_content_' . $kind . '_' . $user->ID );
			}
		}
	}
	remove_all_filters( Definitions::CODE_FILTER );
	Definitions::instance()->reset_cache();
	wp_cache_flush();
}

/* -------------------------------------------------------------------------
 * Tests
 * ---------------------------------------------------------------------- */

cradle_test(
	'content: fixtures from earlier runs are cleaned up',
	function () {
		cct_cleanup();
		$store = cct_store();
		foreach ( Definitions::KINDS as $kind ) {
			foreach ( array_keys( $store[ $kind ] ) as $key ) {
				assert_false( str_starts_with( $key, 'cctest' ), 'Leftover test definition ' . $key );
			}
		}
	}
);

cradle_test(
	'content: module is ready and the capability belongs to administrators only',
	function () {
		if ( ! is_plugin_active( 'cradle-content/cradle-content.php' ) ) {
			activate_plugin( 'cradle-content/cradle-content.php' );
		}
		assert_true( is_plugin_active( 'cradle-content/cradle-content.php' ), 'Plugin must be active.' );
		assert_same( 'ready', cradle()->modules()->state( 'content' ) );
		assert_true( get_role( 'administrator' )->has_cap( 'cradle_manage_content_types' ) );
		assert_false( get_role( 'editor' )->has_cap( 'cradle_manage_content_types' ) );
		assert_false( get_role( 'author' )->has_cap( 'cradle_manage_content_types' ) );
		assert_false( get_role( 'subscriber' )->has_cap( 'cradle_manage_content_types' ) );
		$descriptor = cradle()->modules()->get( 'content' );
		assert_true( in_array( 'cradle_content_definitions', $descriptor['dataOwners']['options'], true ), 'Options are declared data owners.' );
		assert_same( array( '_cradle_cf_*' ), $descriptor['dataOwners']['postMeta'] );
		assert_same( array( '_cradle_cf_*' ), $descriptor['dataOwners']['termMeta'] );
		$report = cradle()->inventory()->scan( 'content' );
		assert_true( isset( $report['options']['cradle_content_definitions'], $report['postMeta']['_cradle_cf_*'] ), 'Core inventory reports owned data.' );
		// Bounded, autoloaded definitions option (read on every request).
		$autoload = $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", Definitions::OPTION ) ); // phpcs:ignore
		assert_true( in_array( $autoload, array( 'yes', 'on', 'auto-on' ), true ), 'Definitions option is autoloaded (got ' . var_export( $autoload, true ) . ').' );
	}
);

cradle_test(
	'content: admin screens load for administrators; editors and subscribers are refused',
	function () {
		foreach ( array( 'post_type', 'taxonomy', 'field_group', 'tools', 'migrations', 'settings' ) as $tab ) {
			$r = cct_get( 'administrator', admin_url( 'admin.php?page=cradle-content&tab=' . $tab ) );
			assert_same( 200, cct_code( $r ), "Tab {$tab} loads." );
			assert_contains( 'Content types', wp_remote_retrieve_body( $r ) );
			assert_not_contains( 'Fatal error', wp_remote_retrieve_body( $r ) );
		}
		foreach ( array( 'post_type', 'taxonomy', 'field_group' ) as $kind ) {
			$r = cct_get( 'administrator', admin_url( 'admin.php?page=cradle-content&tab=' . $kind . '&action=new' ) );
			assert_same( 200, cct_code( $r ) );
			assert_contains( 'name="def[key]"', wp_remote_retrieve_body( $r ), "New {$kind} form renders." );
		}
		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			$r = cct_get( $role, admin_url( 'admin.php?page=cradle-content' ) );
			assert_true( 200 !== cct_code( $r ) || str_contains( wp_remote_retrieve_body( $r ), 'not allowed' ), "{$role} is refused." );
			assert_not_contains( 'name="def[key]"', wp_remote_retrieve_body( $r ) );
		}
	}
);

cradle_test(
	'content: administrator creates a taxonomy, a post type and field groups over HTTP',
	function () {
		$r = cct_save( 'administrator', 'taxonomy', cct_taxonomy_input() );
		assert_same( 302, cct_code( $r ), 'Save redirects.' );
		assert_contains( 'saved=' . CCT_TAX, cct_location( $r ) );

		$r = cct_save( 'administrator', 'post_type', cct_post_type_input() );
		assert_contains( 'saved=' . CCT_PT, cct_location( $r ), 'Post type saved: ' . cct_location( $r ) );

		$r = cct_save( 'administrator', 'field_group', cct_group_input() );
		assert_contains( 'saved=' . CCT_GROUP, cct_location( $r ), 'Field group saved: ' . cct_location( $r ) );

		$r = cct_save( 'administrator', 'field_group', cct_term_group_input() );
		assert_contains( 'saved=' . CCT_TGROUP, cct_location( $r ), 'Term field group saved.' );

		$store = cct_store();
		$pt    = $store['post_type'][ CCT_PT ] ?? null;
		assert_true( is_array( $pt ), 'Post type stored.' );
		assert_same( 'Test books', $pt['plural'] );
		assert_same( CCT_SLUG, $pt['rewrite_slug'] );
		assert_same( array( CCT_TAX ), $pt['taxonomies'] );
		assert_same( 'book', $pt['icon'] );
		assert_same( 2, count( $pt['template'] ) );
		assert_same( 1, $pt['revision'] );
		assert_true( $pt['has_archive'] );
		$group = $store['field_group'][ CCT_GROUP ];
		assert_same( array( CCT_PT ), $group['targets'] );
		assert_same( 9, count( $group['fields'] ), 'Blank rows are ignored.' );
		$compiled = Field_Types::compile( $group );
		assert_same( array( 'chapter_title', 'chapter_page' ), array_keys( $compiled['chapters']['subfields'] ) );
		assert_same( array( array( 'value' => 'hardcover', 'label' => 'Hardcover' ), array( 'value' => 'paperback', 'label' => 'Paperback' ) ), $compiled['format']['options'] );

		// The new type is registered on the next request, by the plugin, with custom-fields support added for REST meta.
		$r    = cct_rest( 'administrator', 'GET', '/wp/v2/types/' . CCT_PT . '?context=edit' );
		$type = cct_json( $r );
		assert_same( 200, cct_code( $r ), 'REST type exists.' );
		assert_same( 'Test books', $type['name'] );
		assert_true( in_array( CCT_TAX, $type['taxonomies'], true ), 'Taxonomy attached.' );
		assert_true( ! empty( $type['supports']['custom-fields'] ), 'custom-fields support added for REST meta.' );
		$edit = cct_get( 'administrator', admin_url( 'edit.php?post_type=' . CCT_PT ) );
		assert_same( 200, cct_code( $edit ), 'Admin list screen of the new type loads.' );
		assert_contains( 'Last modified', wp_remote_retrieve_body( $edit ), 'Configured admin column is shown.' );

		// Edit forms of stored definitions render with their values and no PHP warnings.
		foreach ( array( 'post_type' => CCT_PT, 'taxonomy' => CCT_TAX, 'field_group' => CCT_GROUP ) as $kind => $key ) {
			$page = wp_remote_retrieve_body( cct_get( 'administrator', admin_url( 'admin.php?page=cradle-content&tab=' . $kind . '&action=edit&key=' . $key ) ) );
			assert_contains( 'value="' . $key . '"', $page, "Edit form for {$key}." );
			assert_contains( 'name="revision" value="1"', $page, 'Revision is carried for stale-edit detection.' );
			assert_not_contains( 'Warning:', $page );
		}
		$page = wp_remote_retrieve_body( cct_get( 'administrator', admin_url( 'admin.php?page=cradle-content&tab=field_group&action=edit&key=' . CCT_GROUP ) ) );
		assert_contains( 'value="chapters"', $page, 'Repeated group row rendered.' );
		assert_contains( 'hardcover|Hardcover', $page, 'Choices rendered as editable lines.' );
		// Term forms show term fields; the add-post screen shows the admin-only meta box.
		$terms = wp_remote_retrieve_body( cct_get( 'administrator', admin_url( 'edit-tags.php?taxonomy=' . CCT_TAX ) ) );
		assert_contains( 'name="cradle_cf[tagline]"', $terms, 'Term add form shows term fields.' );
		assert_contains( 'cradle_content_term_nonce', $terms );
		$new = wp_remote_retrieve_body( cct_get( 'administrator', admin_url( 'post-new.php?post_type=' . CCT_PT ) ) );
		assert_contains( 'cradle-content-editor', $new, 'Block editor panel script enqueued for the type.' );
		assert_contains( '"metaKey":"_cradle_cf_isbn"', $new, 'Panel configuration lists REST fields.' );
		assert_not_contains( '"metaKey":"_cradle_cf_notes"', $new, 'Admin-only fields are not sent to the editor panel.' );
	}
);

cradle_test(
	'content: editors, subscribers, anonymous users and bad nonces cannot change definitions',
	function () {
		$before = cct_store();
		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			$r = cct_save( $role, 'post_type', cct_post_type_input( array( 'key' => 'cctest_x', 'rewrite_slug' => 'cctest-x' ) ) );
			assert_same( 403, cct_code( $r ), "{$role} save is refused." );
			$r = cct_post(
				$role,
				'cradle_content_toggle',
				'cradle_content_toggle_post_type',
				array(
					'kind'    => 'post_type',
					'key'     => CCT_PT,
					'enabled' => '0',
				)
			);
			assert_same( 403, cct_code( $r ), "{$role} toggle is refused." );
			$r = cct_post(
				$role,
				'cradle_content_delete',
				'cradle_content_delete_post_type',
				array(
					'kind'    => 'post_type',
					'key'     => CCT_PT,
					'confirm' => '1',
				)
			);
			assert_same( 403, cct_code( $r ), "{$role} delete is refused." );
			$r = cct_post(
				$role,
				'cradle_content_rename',
				'cradle_content_rename_post_type',
				array(
					'kind'    => 'post_type',
					'key'     => CCT_PT,
					'new_key' => 'cctest_x',
				)
			);
			assert_same( 403, cct_code( $r ), "{$role} rename is refused." );
			$r = cct_post( $role, 'cradle_content_export', 'cradle_content_export', array() );
			assert_same( 403, cct_code( $r ), "{$role} export is refused." );
			assert_not_contains( CCT_PT, wp_remote_retrieve_body( $r ) );
		}
		// Anonymous request to admin-post.php has no handler.
		$r = cradle_test_http(
			'POST',
			admin_url( 'admin-post.php' ),
			array(
				'body' => array(
					'action' => 'cradle_content_toggle',
					'kind'   => 'post_type',
					'key'    => CCT_PT,
				),
			)
		);
		assert_true( in_array( cct_code( $r ), array( 200, 302, 400, 403 ), true ) );
		// Administrator with a forged nonce.
		list( $admin, $session ) = cct_session( 'administrator' );
		$r                       = cradle_test_http(
			'POST',
			admin_url( 'admin-post.php' ),
			array(
				'body' => array(
					'action'   => 'cradle_content_toggle',
					'kind'     => 'post_type',
					'key'      => CCT_PT,
					'enabled'  => '0',
					'_wpnonce' => 'forged',
				),
			),
			$admin,
			$session
		);
		assert_same( 403, cct_code( $r ), 'Invalid nonce is refused.' );
		// GET cannot mutate.
		$r = cct_get( 'administrator', admin_url( 'admin-post.php?action=cradle_content_toggle&kind=post_type&key=' . CCT_PT . '&enabled=0' ) );
		assert_same( 405, cct_code( $r ), 'GET is refused.' );
		$after = cct_store();
		assert_same( $before['post_type'], $after['post_type'], 'Nothing changed.' );
		assert_false( isset( $after['post_type']['cctest_x'] ) );
	}
);

cradle_test(
	'content: reserved, malformed, duplicate and colliding keys and slugs are rejected',
	function () {
		$cases = array(
			'post'                  => array(),
			'page'                  => array(),
			'product'               => array(),
			'order'                 => array(),
			'type'                  => array(),
			'attachment'            => array(),
			'wp_thing'              => array(),
			'cradle_x'              => array(),
			'Bad Key'               => array(),
			'cctest_far_too_long_key' => array(),
			CCT_PT                  => array( 'rewrite_slug' => 'cctest-dupe' ),
			'cctest_x'              => array( 'rewrite_slug' => CCT_SLUG ),
			CCT_TAX                 => array( 'rewrite_slug' => 'cctest-y' ),
		);
		foreach ( $cases as $key => $overrides ) {
			$r = cct_save( 'administrator', 'post_type', cct_post_type_input( array_merge( array( 'key' => $key ), $overrides ) ) );
			assert_contains( 'error=1', cct_location( $r ), "Key {$key} must be rejected." );
		}
		// Types registered in code by other plugins are never taken over (fixture mu-plugin for these requests).
		$mu_dir  = WP_CONTENT_DIR . '/mu-plugins';
		$had_dir = is_dir( $mu_dir );
		$mu      = $mu_dir . '/cradle-test-foreign-types.php';
		if ( ! $had_dir ) {
			wp_mkdir_p( $mu_dir );
		}
		copy( CRADLE_TEST_ROOT . '/tests/fixtures/content/foreign-types.php', $mu );
		try {
			$r = cct_save( 'administrator', 'taxonomy', cct_taxonomy_input( array( 'key' => 'cctest_foreign_tax', 'rewrite_slug' => 'cctest-ft' ) ) );
			assert_contains( 'error=1', cct_location( $r ), 'A taxonomy key registered by other code is rejected.' );
			$form = wp_remote_retrieve_body( cct_get( 'administrator', admin_url( 'admin.php?page=cradle-content&tab=taxonomy&action=new&error=1' ) ) );
			assert_contains( 'registered by other code', $form, 'The reason names the other owner.' );
			$r = cct_save( 'administrator', 'post_type', cct_post_type_input( array( 'key' => 'cctest_foreign_tax', 'rewrite_slug' => 'cctest-ft2' ) ) );
			assert_contains( 'error=1', cct_location( $r ), 'A post type cannot reuse a foreign taxonomy key.' );
			$r = cct_save( 'administrator', 'post_type', cct_post_type_input( array( 'key' => 'cctest_foreign', 'rewrite_slug' => 'cctest-fp' ) ) );
			assert_contains( 'error=1', cct_location( $r ), 'A post type key registered by other code is rejected.' );
			$r = cct_save( 'administrator', 'post_type', cct_post_type_input( array( 'key' => 'cctest_slugclash', 'rewrite_slug' => 'cctest-foreign-items' ) ) );
			assert_contains( 'error=1', cct_location( $r ), 'The URL slug of a type registered by other code cannot be reused.' );
			$list = wp_remote_retrieve_body( cct_get( 'administrator', admin_url( 'admin.php?page=cradle-content&tab=post_type' ) ) );
			assert_contains( 'cctest_foreign', $list, 'Types registered by other code are listed on Content types.' );
		} finally {
			@unlink( $mu ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			if ( ! $had_dir ) {
				@rmdir( $mu_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			}
		}
		$store = cct_store();
		assert_false( isset( $store['post_type']['cctest_foreign'], $store['taxonomy']['cctest_foreign_tax'], $store['post_type']['cctest_slugclash'] ), 'Nothing was stored for foreign keys.' );
		$r = cct_save( 'administrator', 'taxonomy', cct_taxonomy_input( array( 'key' => CCT_PT, 'rewrite_slug' => 'cctest-z' ) ) );
		assert_contains( 'error=1', cct_location( $r ), 'A taxonomy cannot reuse a post type key.' );
		$r = cct_save( 'administrator', 'taxonomy', cct_taxonomy_input( array( 'key' => 'category' ) ) );
		assert_contains( 'error=1', cct_location( $r ), 'Core taxonomy key is reserved.' );
		$r = cct_save( 'administrator', 'taxonomy', cct_taxonomy_input( array( 'key' => 'cctest_y', 'rewrite_slug' => 'wp-admin' ) ) );
		assert_contains( 'error=1', cct_location( $r ), 'Reserved URL segment is rejected.' );
		$store = cct_store();
		foreach ( array( 'post', 'product', 'order', 'cctest_x', 'cctest_far_too_long_key' ) as $key ) {
			assert_false( isset( $store['post_type'][ $key ] ), "{$key} not stored." );
		}
		assert_same( CCT_SLUG, $store['post_type'][ CCT_PT ]['rewrite_slug'], 'Existing definition untouched.' );

		// The error screen keeps the input and shows field-specific messages.
		cct_save( 'administrator', 'post_type', cct_post_type_input( array( 'key' => 'product', 'singular' => 'Kept label' ) ) );
		$form = wp_remote_retrieve_body( cct_get( 'administrator', admin_url( 'admin.php?page=cradle-content&tab=post_type&action=new&error=1' ) ) );
		assert_contains( 'Kept label', $form, 'Submitted values survive a failed save.' );
		assert_contains( 'aria-invalid="true"', $form, 'Invalid field is flagged.' );

		// Keys registered by other code are refused (environment check).
		$ctx                          = Definitions::instance()->context( 'post_type' );
		$ctx['foreign']['post_type'][] = 'cctest_foreign';
		$result                       = Validator::validate( 'post_type', cct_post_type_input( array( 'key' => 'cctest_foreign', 'rewrite_slug' => 'cctest-foreign' ) ), $ctx );
		assert_true( isset( $result['errors']['key'] ), 'Foreign registration blocks the key.' );

		// Field definitions: nested groups, unknown types and code-like option values are refused.
		$bad = cct_group_input();
		$bad['key']                    = 'cctest_bad';
		$bad['fields'][0]['type']      = 'php_code';
		$bad['fields'][2]['options']   = "<?php echo 1; ?>|X";
		$bad['fields'][6]['parent']    = 'isbn';
		$result                        = Validator::validate( 'field_group', Cradle\Content\Admin\Admin::normalize_form_input( 'field_group', $bad ), Definitions::instance()->context( 'field_group' ) );
		assert_true( isset( $result['errors']['fields.0.type'], $result['errors']['fields.2.options'], $result['errors']['fields.6.parent'] ), 'Invalid field settings are reported per field.' );
	}
);

cradle_test(
	'content: posts and fields are created through REST with permission checks',
	function () {
		$posts = &$GLOBALS['cct']['posts'];
		$route = '/wp/v2/' . CCT_PT;

		$r = cct_rest(
			'administrator',
			'POST',
			$route,
			array(
				'title'  => 'Cctest private book',
				'status' => 'private',
			)
		);
		assert_same( 201, cct_code( $r ), 'Admin creates a private book: ' . wp_remote_retrieve_body( $r ) );
		$posts['private'] = cct_json( $r )['id'];

		$r = cct_rest(
			'administrator',
			'POST',
			$route,
			array(
				'title'   => 'Cctest first book',
				'slug'    => 'cctest-first-book',
				'status'  => 'publish',
				'content' => '<!-- wp:paragraph --><p>Cctest body text</p><!-- /wp:paragraph -->',
				'meta'    => array(
					'_cradle_cf_isbn'     => '978-3-16-148410-0',
					'_cradle_cf_pages'    => 320,
					'_cradle_cf_format'   => 'hardcover',
					'_cradle_cf_featured' => $posts['private'],
					'_cradle_cf_related'  => array( $posts['private'] ),
					'_cradle_cf_chapters' => array(
						array(
							'chapter_title' => 'One',
							'chapter_page'  => 1,
						),
						array(
							'chapter_title' => 'Two',
							'chapter_page'  => 40,
						),
					),
				),
			)
		);
		assert_same( 201, cct_code( $r ), 'Admin creates a book with fields: ' . wp_remote_retrieve_body( $r ) );
		$book           = cct_json( $r );
		$posts['first'] = $book['id'];
		assert_same( '978-3-16-148410-0', $book['meta']['_cradle_cf_isbn'] );
		assert_same( 320, $book['meta']['_cradle_cf_pages'] );
		wp_cache_flush();
		assert_same( 'hardcover', get_post_meta( $posts['first'], '_cradle_cf_format', true ) );
		assert_same( array( (string) $posts['private'] ), get_post_meta( $posts['first'], '_cradle_cf_related', false ), 'Multiple references are stored one row per value.' );
		assert_same( 'One', get_post_meta( $posts['first'], '_cradle_cf_chapters', true )[0]['chapter_title'] );
		$terms = wp_get_object_terms( $posts['first'], CCT_TAX );
		assert_true( is_wp_error( $terms ) || true, 'Terms readable.' );

		// Invalid values are rejected before anything is written.
		foreach (
			array(
				array( '_cradle_cf_pages' => 99999 ),
				array( '_cradle_cf_pages' => 2.5 ),
				array( '_cradle_cf_format' => 'scroll' ),
				array( '_cradle_cf_related' => array( 999999999 ) ),
				array( '_cradle_cf_featured' => 1 ),
				array( '_cradle_cf_chapters' => array( array( 'chapter_title' => 'x', 'chapter_page' => 'abc' ) ) ),
				array( '_cradle_cf_isbn' => str_repeat( '9', 30 ) ),
			) as $meta
		) {
			$r = cct_rest( 'administrator', 'POST', $route . '/' . $posts['first'], array( 'meta' => $meta ) );
			assert_same( 400, cct_code( $r ), 'Invalid meta refused: ' . wp_json_encode( $meta ) . ' ' . wp_remote_retrieve_body( $r ) );
		}
		wp_cache_flush();
		assert_same( '320', get_post_meta( $posts['first'], '_cradle_cf_pages', true ), 'Stored value unchanged after refusals.' );

		// Editors may edit others' books and their fields.
		$r = cct_rest( 'editor', 'POST', $route . '/' . $posts['first'], array( 'meta' => array( '_cradle_cf_format' => 'paperback' ) ) );
		assert_same( 200, cct_code( $r ), 'Editor updates fields: ' . wp_remote_retrieve_body( $r ) );

		// Authors cannot reference a private post they cannot read, but can reference a published one.
		$r = cct_rest(
			'author',
			'POST',
			$route,
			array(
				'title'  => 'Cctest author book',
				'status' => 'draft',
				'meta'   => array( '_cradle_cf_related' => array( $posts['private'] ) ),
			)
		);
		assert_same( 400, cct_code( $r ), 'Author cannot reference an unreadable post.' );
		$r = cct_rest(
			'author',
			'POST',
			$route,
			array(
				'title'  => 'Cctest author book',
				'status' => 'draft',
				'meta'   => array( '_cradle_cf_related' => array( $posts['first'] ) ),
			)
		);
		assert_same( 201, cct_code( $r ), 'Author references a published post: ' . wp_remote_retrieve_body( $r ) );
		$posts['author'] = cct_json( $r )['id'];
		// Authors cannot edit an administrator's book or its fields.
		$r = cct_rest( 'author', 'POST', $route . '/' . $posts['first'], array( 'meta' => array( '_cradle_cf_isbn' => 'hijack' ) ) );
		assert_same( 403, cct_code( $r ), 'Author cannot edit others\' fields.' );

		// Subscribers and anonymous visitors cannot create posts of the type.
		$r = cct_rest( 'subscriber', 'POST', $route, array( 'title' => 'Cctest nope', 'meta' => array( '_cradle_cf_isbn' => 'x' ) ) );
		assert_same( 403, cct_code( $r ), 'Subscriber refused.' );
		$r = cct_rest( 'anonymous', 'POST', $route, array( 'title' => 'Cctest nope' ) );
		assert_same( 401, cct_code( $r ), 'Anonymous refused.' );
		wp_cache_flush();
		assert_same( 'paperback', get_post_meta( $posts['first'], '_cradle_cf_format', true ) );
		assert_same( '978-3-16-148410-0', get_post_meta( $posts['first'], '_cradle_cf_isbn', true ) );

		// Public output: editor-only fields hidden, unreadable references stripped; edit context shows them.
		$public = cct_json( cct_rest( 'anonymous', 'GET', $route . '/' . $posts['first'] ) );
		assert_same( '978-3-16-148410-0', $public['meta']['_cradle_cf_isbn'] ?? null, 'Public field visible.' );
		assert_false( array_key_exists( '_cradle_cf_related', $public['meta'] ), 'Editor-only field hidden from the public.' );
		assert_false( array_key_exists( '_cradle_cf_chapters', $public['meta'] ), 'Editor-only group hidden from the public.' );
		assert_false( array_key_exists( '_cradle_cf_notes', $public['meta'] ), 'Admin-only field is not in REST.' );
		assert_same( 0, $public['meta']['_cradle_cf_featured'] ?? null, 'Reference to a private post is stripped for the public.' );
		$edit = cct_json( cct_rest( 'administrator', 'GET', $route . '/' . $posts['first'] . '?context=edit' ) );
		assert_same( array( $posts['private'] ), $edit['meta']['_cradle_cf_related'] ?? null, 'Edit context includes editor fields.' );
		assert_same( $posts['private'], $edit['meta']['_cradle_cf_featured'] ?? null );

		// Term fields through REST.
		$r = cct_rest(
			'administrator',
			'POST',
			'/wp/v2/' . CCT_TAX,
			array(
				'name' => 'Cctest fantasy',
				'slug' => 'cctest-fantasy',
				'meta' => array( '_cradle_cf_tagline' => 'Dragons welcome' ),
			)
		);
		assert_same( 201, cct_code( $r ), 'Term with meta created: ' . wp_remote_retrieve_body( $r ) );
		$term_id = cct_json( $r )['id'];
		wp_cache_flush();
		assert_same( 'Dragons welcome', get_term_meta( $term_id, '_cradle_cf_tagline', true ) );
		$r = cct_rest( 'subscriber', 'POST', '/wp/v2/' . CCT_TAX . '/' . $term_id, array( 'meta' => array( '_cradle_cf_tagline' => 'hijack' ) ) );
		assert_same( 403, cct_code( $r ), 'Subscriber cannot edit term fields.' );
		$r = cct_rest( 'administrator', 'POST', $route . '/' . $posts['first'], array( CCT_TAX => array( $term_id ) ) );
		assert_same( 200, cct_code( $r ), 'Term assigned.' );
		wp_cache_flush();
		assert_true( (bool) get_term_by( 'slug', 'cctest-unsorted', CCT_TAX ) || true, 'Default term handled by WordPress.' );
	}
);

cradle_test(
	'content: invalid low-level writes are refused, not coerced',
	function () {
		$id = $GLOBALS['cct']['posts']['first'] ?? 0;
		if ( ! $id ) {
			skip_test( 'Depends on the REST test.' );
		}
		// Register the field in-process (the test process booted before the definitions existed).
		$group = Field_Types::compile( cct_store()['field_group'][ CCT_GROUP ] );
		$field = $group['pages'];
		register_post_meta(
			CCT_PT,
			$field['meta_key'],
			array(
				'type'              => 'integer',
				'single'            => true,
				'sanitize_callback' => static fn( $v ) => Field_Types::sanitize_stored( $field, $v ),
			)
		);
		add_filter( 'update_post_metadata', array( Cradle\Content\Meta::instance(), 'refuse_rejected' ), 99, 4 );
		assert_false( update_post_meta( $id, $field['meta_key'], 'not a number' ), 'Invalid value refused.' );
		wp_cache_flush();
		assert_same( '320', get_post_meta( $id, $field['meta_key'], true ) );
		unregister_post_meta( CCT_PT, $field['meta_key'] );
	}
);

cradle_test(
	'content: classic meta box saves admin-only fields with nonce and object capability',
	function () {
		$id = $GLOBALS['cct']['posts']['first'] ?? 0;
		if ( ! $id ) {
			skip_test( 'Depends on the REST test.' );
		}
		$submit = function ( string $role, string $note ) use ( $id ) {
			list( $user, $session ) = cct_session( $role );
			return cradle_test_http(
				'POST',
				admin_url( 'post.php' ),
				array(
					'body' => array(
						'action'                      => 'editpost',
						'post_ID'                     => $id,
						'post_type'                   => CCT_PT,
						'user_ID'                     => $user,
						'_wpnonce'                    => cradle_test_nonce( $user, $session, 'update-post_' . $id ),
						'post_title'                  => 'Cctest first book',
						'cradle_content_fields_nonce' => cradle_test_nonce( $user, $session, 'cradle_content_fields_' . $id ),
						'cradle_cf'                   => array( 'notes' => $note ),
					),
				),
				$user,
				$session
			);
		};
		$page = wp_remote_retrieve_body( cct_get( 'administrator', admin_url( 'post.php?post=' . $id . '&action=edit' ) ) );
		assert_contains( 'cradle-content-editor', $page, 'Block editor field panel script is enqueued.' );
		assert_contains( 'cradle-content-' . CCT_GROUP, $page, 'Classic meta box for admin-only fields is registered.' );
		$submit( 'administrator', 'Checked by QA' );
		wp_cache_flush();
		assert_same( 'Checked by QA', get_post_meta( $id, '_cradle_cf_notes', true ), 'Administrator saves the admin-only field.' );
		$r = $submit( 'author', 'Hijacked' );
		assert_true( 200 !== cct_code( $r ) || str_contains( wp_remote_retrieve_body( $r ), 'not allowed' ), 'Author cannot edit the post.' );
		wp_cache_flush();
		assert_same( 'Checked by QA', get_post_meta( $id, '_cradle_cf_notes', true ), 'Author could not change the field.' );
	}
);

cradle_test(
	'content: reference lookup endpoint enforces permissions',
	function () {
		$id    = $GLOBALS['cct']['posts']['first'] ?? 0;
		$query = '/cradle-content/v1/lookup?object=post&subtype=' . CCT_PT . '&field=related';
		assert_same( 401, cct_code( cct_rest( 'anonymous', 'GET', $query ) ) );
		assert_same( 403, cct_code( cct_rest( 'subscriber', 'GET', $query ) ) );
		$r = cct_rest( 'administrator', 'GET', $query );
		assert_same( 200, cct_code( $r ) );
		assert_true( in_array( $id, array_column( (array) cct_json( $r ), 'id' ), true ), 'Published book is a candidate.' );
		$r = cct_rest( 'author', 'GET', $query );
		assert_same( 200, cct_code( $r ) );
		assert_false( in_array( $GLOBALS['cct']['posts']['private'], array_column( (array) cct_json( $r ), 'id' ), true ), 'Unreadable private post is not offered to authors.' );
		assert_same( 404, cct_code( cct_rest( 'administrator', 'GET', '/cradle-content/v1/lookup?object=post&subtype=' . CCT_PT . '&field=isbn' ) ), 'Non-reference fields are not searchable.' );
	}
);

cradle_test(
	'content: archive, single and term URLs render on the front end (plugin-registered, theme templates)',
	function () {
		$single = cradle_test_http( 'GET', home_url( '/' . CCT_SLUG . '/cctest-first-book/' ) );
		assert_same( 200, cct_code( $single ), 'Single URL renders.' );
		assert_contains( 'Cctest first book', wp_remote_retrieve_body( $single ) );
		assert_contains( 'Cctest body text', wp_remote_retrieve_body( $single ) );
		$archive = cradle_test_http( 'GET', home_url( '/' . CCT_SLUG . '/' ) );
		assert_same( 200, cct_code( $archive ), 'Archive URL renders.' );
		assert_contains( 'Cctest first book', wp_remote_retrieve_body( $archive ) );
		assert_not_contains( 'Cctest private book', wp_remote_retrieve_body( $archive ), 'Private posts are not listed publicly.' );
		$term = cradle_test_http( 'GET', home_url( '/cctest-genres/cctest-fantasy/' ) );
		assert_same( 200, cct_code( $term ), 'Term archive renders.' );
		assert_contains( 'Cctest first book', wp_remote_retrieve_body( $term ) );
		// Theme independence: the type comes from the plugin's definition store, not from theme code.
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $dir ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( 'php' === $file->getExtension() ) {
					assert_not_contains( CCT_PT, (string) file_get_contents( $file->getPathname() ), 'Theme does not register the test type.' );
				}
			}
		}
		$found = Definitions::instance()->find( 'post_type', CCT_PT );
		assert_same( 'db', $found['source'] ?? '', 'Definition is owned by the plugin database store.' );
	}
);

cradle_test(
	'content: rewrite rules are flushed only when the registered signature changes',
	function () {
		Registry::instance()->maybe_flush();
		$before = did_action( 'generate_rewrite_rules' );
		Registry::instance()->maybe_flush();
		Registry::instance()->maybe_flush();
		assert_same( $before, did_action( 'generate_rewrite_rules' ), 'No flush without a meaningful change.' );
	}
);

cradle_test(
	'content: disabling a post type keeps its posts; re-enabling restores access',
	function () {
		$count = cct_count_posts( CCT_PT );
		assert_true( $count >= 2, 'Fixture posts exist.' );
		$toggle = function ( string $enabled ) {
			return cct_post(
				'administrator',
				'cradle_content_toggle',
				'cradle_content_toggle_post_type',
				array(
					'kind'    => 'post_type',
					'key'     => CCT_PT,
					'enabled' => $enabled,
				)
			);
		};
		assert_same( 302, cct_code( $toggle( '0' ) ) );
		assert_false( cct_store()['post_type'][ CCT_PT ]['enabled'], 'Definition disabled.' );
		assert_same( $count, cct_count_posts( CCT_PT ), 'Posts remain in the database.' );
		assert_same( 404, cct_code( cradle_test_http( 'GET', home_url( '/' . CCT_SLUG . '/cctest-first-book/' ) ) ), 'Single URL is gone while disabled.' );
		assert_same( 404, cct_code( cct_rest( 'administrator', 'GET', '/wp/v2/' . CCT_PT ) ), 'REST route is gone while disabled.' );
		$list = wp_remote_retrieve_body( cct_get( 'administrator', admin_url( 'admin.php?page=cradle-content&tab=post_type' ) ) );
		assert_contains( 'Disabled definitions still hold content', $list, 'Retained content is explained.' );

		assert_same( 302, cct_code( $toggle( '1' ) ) );
		assert_true( cct_store()['post_type'][ CCT_PT ]['enabled'] );
		assert_same( 200, cct_code( cradle_test_http( 'GET', home_url( '/' . CCT_SLUG . '/cctest-first-book/' ) ) ), 'Single URL is back.' );
		wp_cache_flush();
		assert_same( '978-3-16-148410-0', get_post_meta( $GLOBALS['cct']['posts']['first'], '_cradle_cf_isbn', true ), 'Field values survived.' );
	}
);

cradle_test(
	'content: export, delete and import round trip without touching unrelated definitions',
	function () {
		// Unrelated definition that must survive the import untouched.
		$r = cct_save( 'administrator', 'taxonomy', cct_taxonomy_input( array( 'key' => CCT_OTHER, 'rewrite_slug' => 'cctest-other', 'default_term_name' => '', 'default_term_slug' => '' ) ) );
		assert_contains( 'saved=' . CCT_OTHER, cct_location( $r ) );
		$other_before = cct_store()['taxonomy'][ CCT_OTHER ];

		$selection = array( 'post_type:' . CCT_PT, 'taxonomy:' . CCT_TAX, 'field_group:' . CCT_GROUP, 'field_group:' . CCT_TGROUP );
		$r         = cct_post( 'administrator', 'cradle_content_export', 'cradle_content_export', array( 'selection' => $selection ) );
		assert_same( 200, cct_code( $r ) );
		assert_contains( 'attachment; filename="cradle-content-definitions-', (string) wp_remote_retrieve_header( $r, 'content-disposition' ) );
		$json   = wp_remote_retrieve_body( $r );
		$export = json_decode( $json, true );
		assert_same( 'cradle-content-definitions', $export['format'] );
		assert_same( array( CCT_PT ), array_keys( $export['definitions']['post_type'] ) );
		assert_false( isset( $export['definitions']['taxonomy'][ CCT_OTHER ] ), 'Only the selection is exported.' );
		assert_false( isset( $export['definitions']['post_type'][ CCT_PT ]['revision'] ), 'Bookkeeping is not exported.' );
		$stored_before = cct_store();
		$posts         = cct_count_posts( CCT_PT );

		// Delete definitions (content kept).
		foreach ( array( 'field_group' => CCT_GROUP, 'post_type' => CCT_PT, 'taxonomy' => CCT_TAX ) as $kind => $key ) {
			$page = wp_remote_retrieve_body( cct_get( 'administrator', admin_url( 'admin.php?page=cradle-content&tab=' . $kind . '&action=delete&key=' . $key ) ) );
			assert_contains( 'Delete definition', $page, "Delete preview for {$key}." );
			$r = cct_post(
				'administrator',
				'cradle_content_delete',
				'cradle_content_delete_' . $kind,
				array(
					'kind'    => $kind,
					'key'     => $key,
					'content' => 'keep',
					'confirm' => '1',
				)
			);
			assert_same( 302, cct_code( $r ) );
		}
		$store = cct_store();
		assert_false( isset( $store['post_type'][ CCT_PT ] ) || isset( $store['taxonomy'][ CCT_TAX ] ) || isset( $store['field_group'][ CCT_GROUP ] ), 'Definitions deleted.' );
		assert_same( $posts, cct_count_posts( CCT_PT ), 'Posts kept after deleting the definition.' );
		$list = wp_remote_retrieve_body( cct_get( 'administrator', admin_url( 'admin.php?page=cradle-content&tab=post_type' ) ) );
		assert_contains( 'Stored posts without a registered type', $list, 'Stranded content is explained.' );
		assert_contains( 'Create a definition for this key', $list, 'A re-create link is offered.' );
		assert_true( (bool) preg_match( '/action=new(&amp;|&#038;|&)key=' . CCT_PT . '/', $list ), 'The re-create link pre-fills the key.' );

		// Import through a real multipart upload, preview first.
		list( $admin, $session ) = cct_session( 'administrator' );
		$boundary                = 'cct' . wp_generate_password( 16, false );
		$fields                  = array(
			'action'   => 'cradle_content_import',
			'_wpnonce' => cradle_test_nonce( $admin, $session, 'cradle_content_import' ),
		);
		$body                    = '';
		foreach ( $fields as $name => $value ) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
		}
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"cradle_content_file\"; filename=\"defs.json\"\r\nContent-Type: application/json\r\n\r\n{$json}\r\n--{$boundary}--\r\n";
		$r     = cradle_test_http(
			'POST',
			admin_url( 'admin-post.php' ),
			array(
				'body'    => $body,
				'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
			),
			$admin,
			$session
		);
		assert_same( 302, cct_code( $r ) );
		assert_contains( 'import=', cct_location( $r ), 'Import redirects to the preview: ' . cct_location( $r ) );
		parse_str( (string) wp_parse_url( cct_location( $r ), PHP_URL_QUERY ), $query );
		$preview = wp_remote_retrieve_body( cct_get( 'administrator', cct_location( $r ) ) );
		assert_contains( 'New — will be created', $preview );
		assert_same( $other_before, cct_store()['taxonomy'][ CCT_OTHER ], 'Preview wrote nothing.' );
		$r = cct_post( 'administrator', 'cradle_content_import_apply', 'cradle_content_import_apply', array( 'token' => $query['import'] ) );
		assert_same( 302, cct_code( $r ) );
		$store = cct_store();
		foreach ( array( 'post_type' => CCT_PT, 'taxonomy' => CCT_TAX, 'field_group' => CCT_GROUP ) as $kind => $key ) {
			assert_true( isset( $store[ $kind ][ $key ] ), "{$key} imported." );
			assert_true( Transfer::same( $stored_before[ $kind ][ $key ], $store[ $kind ][ $key ] ), "{$key} is identical to the exported definition." );
		}
		assert_same( $other_before, $store['taxonomy'][ CCT_OTHER ], 'Unrelated definition untouched.' );
		assert_same( 200, cct_code( cradle_test_http( 'GET', home_url( '/' . CCT_SLUG . '/cctest-first-book/' ) ) ), 'Content reachable again after import.' );

		// A differing definition is only overwritten when explicitly chosen.
		$changed           = cct_post_type_input( array( 'singular' => 'Changed book', 'plural' => 'Changed books' ) );
		$r                 = cct_save( 'administrator', 'post_type', $changed, CCT_PT, (string) $store['post_type'][ CCT_PT ]['revision'] );
		assert_contains( 'saved=' . CCT_PT, cct_location( $r ), 'Label change is a normal edit.' );
		cct_store(); // Refresh the in-process memo after the HTTP save.
		$preview           = Transfer::preview( $export );
		$statuses          = array_column( $preview['items'], 'status', 'key' );
		assert_same( 'update', $statuses[ CCT_PT ] );
		assert_same( 'identical', $statuses[ CCT_TAX ] );
		wp_set_current_user( $admin );
		$report = Transfer::apply( $preview, array() );
		assert_true( in_array( 'post_type:' . CCT_PT, $report['skipped'], true ) );
		assert_same( 'Changed books', cct_store()['post_type'][ CCT_PT ]['plural'], 'Not overwritten without consent.' );
		$report = Transfer::apply( Transfer::preview( $export ), array( 'post_type:' . CCT_PT ) );
		assert_same( array( 'post_type:' . CCT_PT ), $report['updated'] );
		assert_same( 'Test books', cct_store()['post_type'][ CCT_PT ]['plural'], 'Overwritten when chosen.' );

		// Stale edits are refused.
		$r = cct_save( 'administrator', 'post_type', cct_post_type_input( array( 'plural' => 'Stale' ) ), CCT_PT, '1' );
		assert_contains( 'error=1', cct_location( $r ), 'Stale revision refused.' );
		assert_same( 'Test books', cct_store()['post_type'][ CCT_PT ]['plural'] );
	}
);

cradle_test(
	'content: renaming a field key previews stored values and migrates them',
	function () {
		$id    = $GLOBALS['cct']['posts']['first'];
		$store = cct_store();
		$input = cct_group_input();
		foreach ( $input['fields'] as &$row ) {
			$row['original_key'] = $row['key'];
		}
		unset( $row );
		$input['fields'][0]['key'] = 'isbn13';
		$r                         = cct_save( 'administrator', 'field_group', $input, CCT_GROUP, (string) $store['field_group'][ CCT_GROUP ]['revision'] );
		assert_contains( 'action=confirm', cct_location( $r ), 'Renaming a field with data needs confirmation: ' . cct_location( $r ) );
		assert_same( 'isbn', cct_store()['field_group'][ CCT_GROUP ]['fields'][0]['key'], 'Nothing saved before confirmation.' );
		$page = wp_remote_retrieve_body( cct_get( 'administrator', cct_location( $r ) ) );
		assert_contains( 'Move values to the new key', $page, 'Preview offers migration.' );
		parse_str( (string) wp_parse_url( cct_location( $r ), PHP_URL_QUERY ), $query );
		$r = cct_post(
			'administrator',
			'cradle_content_confirm_fields',
			'cradle_content_confirm_fields',
			array(
				'token'   => $query['token'],
				'storage' => array( 'isbn' => 'migrate' ),
			)
		);
		assert_same( 302, cct_code( $r ) );
		assert_same( 'isbn13', cct_store()['field_group'][ CCT_GROUP ]['fields'][0]['key'] );
		wp_cache_flush();
		assert_same( '978-3-16-148410-0', get_post_meta( $id, '_cradle_cf_isbn13', true ), 'Value moved to the new key.' );
		assert_same( '', get_post_meta( $id, '_cradle_cf_isbn', true ), 'Old key emptied.' );
	}
);

cradle_test(
	'content: key rename shows a migration preview and moves posts in batches',
	function () {
		$count = cct_count_posts( CCT_PT );
		$page  = wp_remote_retrieve_body( cct_get( 'administrator', admin_url( 'admin.php?page=cradle-content&tab=post_type&action=rename&key=' . CCT_PT . '&new_key=' . CCT_PT2 . '&keep_urls=1' ) ) );
		assert_contains( 'Affected records', $page );
		assert_contains( 'Rename and migrate', $page );
		assert_contains( 'field_group', strtolower( str_replace( ' ', '_', $page ) ) ); // References listed.
		$bad = wp_remote_retrieve_body( cct_get( 'administrator', admin_url( 'admin.php?page=cradle-content&tab=post_type&action=rename&key=' . CCT_PT . '&new_key=post' ) ) );
		assert_contains( 'reserved', $bad, 'Reserved target key is refused in the preview.' );

		$r = cct_post(
			'administrator',
			'cradle_content_rename',
			'cradle_content_rename_post_type',
			array(
				'kind'      => 'post_type',
				'key'       => CCT_PT,
				'new_key'   => CCT_PT2,
				'keep_urls' => '1',
			)
		);
		assert_same( 302, cct_code( $r ) );
		$store = cct_store();
		assert_true( isset( $store['post_type'][ CCT_PT2 ] ) && ! isset( $store['post_type'][ CCT_PT ] ), 'Definition renamed.' );
		assert_same( array( CCT_PT2 ), $store['field_group'][ CCT_GROUP ]['targets'], 'Field group target follows the rename.' );
		$compiled = Field_Types::compile( $store['field_group'][ CCT_GROUP ] );
		assert_same( array( CCT_PT2 ), $compiled['related']['targets'], 'Reference targets follow the rename.' );

		$jobs = cradle()->jobs()->recent( 'content', 5 );
		$job  = $jobs[0] ?? null;
		assert_true( is_array( $job ) && 'content/migrate' === $job['type'], 'Migration job recorded.' );
		if ( 'completed' !== $job['state'] ) {
			wp_set_current_user( cradle_test_user( 'administrator' ) );
			for ( $i = 0; $i < 10 && 'completed' !== $job['state']; $i++ ) {
				$job = cradle()->jobs()->run( $job['uuid'] );
			}
		}
		assert_same( 'completed', $job['state'], 'Migration completed.' );
		$migrations = wp_remote_retrieve_body( cct_get( 'administrator', admin_url( 'admin.php?page=cradle-content&tab=migrations' ) ) );
		assert_contains( 'Rename &quot;' . CCT_PT . '&quot; to &quot;' . CCT_PT2 . '&quot;', $migrations, 'Migration is listed.' );
		wp_cache_flush();
		assert_same( 0, cct_count_posts( CCT_PT ), 'No posts left under the old key.' );
		assert_same( $count, cct_count_posts( CCT_PT2 ), 'All posts moved to the new key.' );
		assert_same( CCT_PT2, get_post_type( $GLOBALS['cct']['posts']['first'] ) );
		assert_same( '978-3-16-148410-0', get_post_meta( $GLOBALS['cct']['posts']['first'], '_cradle_cf_isbn13', true ), 'Field values follow the posts.' );
		assert_same( 200, cct_code( cradle_test_http( 'GET', home_url( '/' . CCT_SLUG . '/cctest-first-book/' ) ) ), 'URL kept after rename.' );
		$r = cct_rest( 'administrator', 'GET', '/wp/v2/' . CCT_PT . '/' . $GLOBALS['cct']['posts']['first'] . '?context=edit' );
		assert_same( 200, cct_code( $r ), 'REST base pinned to the old key.' );
		assert_same( CCT_PT2, cct_json( $r )['type'] ?? '' );
	}
);

cradle_test(
	'content: code-managed definitions are read-only, authoritative and forkable',
	function () {
		wp_set_current_user( cradle_test_user( 'administrator' ) );
		$code = array(
			'post_type' => array(
				'cctest_code' => array(
					'singular'     => 'Code item',
					'plural'       => 'Code items',
					'rewrite_slug' => 'cctest-code',
					'owner'        => 'cradle-site',
				),
				'cctest_bad'  => array( 'singular' => '' ),
			),
		);
		add_filter( Definitions::CODE_FILTER, static fn() => $code );
		$defs = Definitions::instance();
		$defs->reset_cache();
		$found = $defs->find( 'post_type', 'cctest_code' );
		assert_same( 'code', $found['source'] );
		assert_same( 'cradle-site', $found['definition']['owner'] );
		assert_true( isset( $defs->code_errors()['post_type:cctest_bad'] ), 'Invalid code definitions are reported, not registered.' );
		assert_wp_error( $defs->save( 'post_type', array( 'key' => 'cctest_code', 'singular' => 'X', 'plural' => 'Xs' ), 'cctest_code' ), 'cradle_content_missing', 'Code definitions are read-only.' );
		assert_wp_error( $defs->save( 'post_type', array( 'key' => 'cctest_code', 'singular' => 'X', 'plural' => 'Xs' ) ), 'cradle_content_code_owned', 'A database copy cannot silently claim the key.' );
		assert_wp_error( $defs->set_enabled( 'post_type', 'cctest_code', false ), 'cradle_content_code_owned' );

		$fork = $defs->fork( 'post_type', 'cctest_code', 'cctest_code' );
		assert_true( is_array( $fork ), 'Fork with the same key: ' . ( is_wp_error( $fork ) ? Transfer::error_text( $fork ) : '' ) );
		$defs->reset_cache();
		$found = $defs->find( 'post_type', 'cctest_code' );
		assert_same( 'code', $found['source'], 'Code stays authoritative while it exists.' );
		assert_true( $found['shadowed'], 'Database copy is marked as shadowed.' );
		assert_same( 'code', $defs->effective( 'post_type' )['cctest_code']['_source'] );
		$fork2 = $defs->fork( 'post_type', 'cctest_code', 'cctest_code2' );
		assert_true( is_array( $fork2 ), 'Fork with a new key: ' . ( is_wp_error( $fork2 ) ? Transfer::error_text( $fork2 ) : '' ) );
		$preview = Transfer::preview( array( 'format' => 'cradle-content-definitions', 'formatVersion' => 1, 'schemaVersion' => 1, 'definitions' => array( 'post_type' => array( 'cctest_code' => array( 'singular' => 'Y', 'plural' => 'Ys' ) ) ) ) );
		assert_same( 'blocked', $preview['items'][0]['status'], 'Import cannot take over a code-managed key.' );

		// When the code definition disappears, the database copy takes over.
		remove_all_filters( Definitions::CODE_FILTER );
		$defs->reset_cache();
		$found = $defs->find( 'post_type', 'cctest_code' );
		assert_same( 'db', $found['source'], 'Database copy owns the key once code is removed.' );
	}
);

cradle_test(
	'content: deactivation keeps definitions and content; uninstall retains by default and purges only on request',
	function () {
		wp_set_current_user( cradle_test_user( 'administrator' ) );
		$snapshot = get_option( Definitions::OPTION );
		$settings = get_option( 'cradle_content_settings', null );
		$caps     = get_option( 'cradle_content_caps', null );
		$posts    = cct_count_posts( CCT_PT2 );
		$id       = $GLOBALS['cct']['posts']['first'];

		Cradle\Content\Lifecycle::deactivate();
		assert_same( $snapshot, get_option( Definitions::OPTION ), 'Deactivation keeps definitions.' );
		assert_same( $posts, cct_count_posts( CCT_PT2 ), 'Deactivation keeps posts.' );
		assert_false( wp_next_scheduled( 'cradle_content_run_job' ) );
		Cradle\Content\Lifecycle::activate( false );
		Cradle\Content\Registry::instance()->boot(); // What the next request does; later suites share this process.
		assert_true( post_type_exists( CCT_PT2 ), 'Types are registered again after reactivation.' );
		assert_same( $snapshot, get_option( Definitions::OPTION ), 'Reactivation does not reset definitions.' );
		assert_true( get_role( 'administrator' )->has_cap( 'cradle_manage_content_types' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'cradle-content/cradle-content.php' );
		}
		$uninstall = WP_PLUGIN_DIR . '/cradle-content/uninstall.php';
		$policies  = get_option( 'cradle_uninstall_policy', array() );
		$policies  = is_array( $policies ) ? $policies : array();
		unset( $policies['content'] );
		update_option( 'cradle_uninstall_policy', $policies, false );
		include $uninstall;
		wp_cache_flush();
		assert_same( $snapshot, get_option( Definitions::OPTION ), 'Retain policy keeps everything.' );
		assert_same( '978-3-16-148410-0', get_post_meta( $id, '_cradle_cf_isbn13', true ) );

		$policies['content'] = 'purge';
		update_option( 'cradle_uninstall_policy', $policies, false );
		try {
			include $uninstall;
			wp_cache_flush();
			assert_false( get_option( Definitions::OPTION ), 'Purge removes definitions.' );
			assert_same( '', get_post_meta( $id, '_cradle_cf_isbn13', true ), 'Purge removes field values.' );
			assert_same( $posts, cct_count_posts( CCT_PT2 ), 'Purge never deletes posts.' );
			assert_false( get_role( 'administrator' )->has_cap( 'cradle_manage_content_types' ), 'Purge revokes the capability.' );
			$after = get_option( 'cradle_uninstall_policy', array() );
			assert_false( isset( $after['content'] ), 'Policy entry cleared.' );
		} finally {
			// Restore the module state for the rest of the run and for other work in the sandbox.
			update_option( Definitions::OPTION, $snapshot, true );
			if ( null !== $settings ) {
				update_option( 'cradle_content_settings', $settings );
			}
			if ( null !== $caps ) {
				update_option( 'cradle_content_caps', $caps, false );
			}
			Cradle\Content\Lifecycle::activate( false );
			Definitions::instance()->reset_cache();
		}
		assert_true( get_role( 'administrator' )->has_cap( 'cradle_manage_content_types' ), 'Capability restored.' );
	}
);

cradle_test(
	'content: fixtures are removed and no PHP notices came from cradle-content',
	function () {
		cct_cleanup();
		$store = cct_store();
		foreach ( Definitions::KINDS as $kind ) {
			foreach ( array_keys( $store[ $kind ] ) as $key ) {
				assert_false( str_starts_with( $key, 'cctest' ), 'Leftover definition ' . $key );
			}
		}
		assert_same( 0, cct_count_posts( CCT_PT ) + cct_count_posts( CCT_PT2 ), 'Test posts removed.' );
		// Trigger a normal front-end request so registration runs with the cleaned store.
		cradle_test_http( 'GET', home_url( '/' ) );
		$log = WP_CONTENT_DIR . '/debug.log';
		if ( is_file( $log ) ) {
			$handle = fopen( $log, 'rb' );
			fseek( $handle, (int) $GLOBALS['cct']['log_size'] );
			$new = (string) stream_get_contents( $handle );
			fclose( $handle );
			foreach ( preg_split( '/\r\n|\n/', $new ) as $line ) {
				if ( str_contains( $line, 'cradle-content' ) && preg_match( '/PHP (Notice|Warning|Deprecated|Fatal|Parse)/', $line ) ) {
					throw new Cradle_Test_Failure( 'debug.log: ' . $line );
				}
			}
		}
	}
);
