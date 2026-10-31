<?php
/**
 * Cradle Languages integration tests (multilingual content and routing).
 *
 * Runs against the shared sandbox: the suite uses its own users (`cradle_lang_t_<role>`), marks
 * every entry and term it creates (`_cradle_test_fixture = languages`), configures extra languages
 * only inside a test and always restores the English-only configuration (reset of the `languages`
 * section) at the end of each test and at shutdown, so other suites keep seeing the site unchanged.
 * Polylang data is inserted as Polylang-shaped rows (tests/fixtures/languages/polylang.php) and
 * removed; Polylang is never installed.
 */

use Cradle\Languages\Config;
use Cradle\Languages\Content;
use Cradle\Languages\Languages;
use Cradle\Languages\Lifecycle;
use Cradle\Languages\Links;
use Cradle\Languages\Menus;
use Cradle\Languages\Polylang;
use Cradle\Languages\Request;
use Cradle\Languages\Tasks;
use Cradle\Languages\Translator;

require_once CRADLE_TEST_ROOT . '/tests/fixtures/languages/polylang.php';

const CL_USER_PREFIX = 'cradle_lang_t_';

$GLOBALS['cl_fixtures'] = array(
	'posts' => array(),
	'terms' => array(),
	'users' => array(),
);

function cl_ready() {
	if ( ! is_plugin_active( 'cradle-languages/cradle-languages.php' ) || ! function_exists( 'cradle' ) || ! cradle() || ! cradle()->modules()->is_ready( 'languages' ) ) {
		skip_test( 'cradle-languages is not active and ready.' );
	}
}

/** Suite-owned user (deleted at shutdown), so sessions never race with other suites. */
function cl_user( string $role ): int {
	$login = CL_USER_PREFIX . $role;
	$user  = get_user_by( 'login', $login );
	if ( $user ) {
		$user->set_role( $role );
		$GLOBALS['cl_fixtures']['users'][ $login ] = (int) $user->ID;
		return (int) $user->ID;
	}
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 32 ),
			'user_email' => $login . '@example.test',
			'role'       => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new Cradle_Test_Failure( 'Could not create ' . $login . ': ' . $id->get_error_message() );
	}
	$GLOBALS['cl_fixtures']['users'][ $login ] = (int) $id;
	return (int) $id;
}

function cl_admin(): int {
	$id = cl_user( 'administrator' );
	wp_set_current_user( $id );
	return $id;
}

function cl_fresh() {
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( Config::OPTION, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	Config::flush();
	Content::flush_cache();
}

/**
 * English (default) + Arabic (rtl), optionally German; extra settings merged in.
 */
function cl_configure( array $extra = array(), bool $german = false ): array {
	cl_admin();
	cradle()->settings()->reset( Config::SECTION );
	$languages = array(
		array( 'slug' => 'en', 'locale' => 'en_US', 'name' => 'English', 'native' => 'English', 'dir' => 'ltr', 'default' => true ),
		array( 'slug' => 'ar', 'locale' => 'ar', 'name' => 'Arabic', 'native' => 'العربية', 'dir' => 'rtl', 'default' => false ),
	);
	if ( $german ) {
		$languages[] = array( 'slug' => 'de', 'locale' => 'de_DE', 'name' => 'German', 'native' => 'Deutsch', 'dir' => 'ltr', 'default' => false );
	}
	$result = cradle()->settings()->update( Config::SECTION, array_merge( array( 'languages' => $languages ), $extra ) );
	cl_fresh();
	if ( is_wp_error( $result ) || $result['errors'] ) {
		throw new Cradle_Test_Failure( 'Configuration failed: ' . wp_json_encode( is_wp_error( $result ) ? $result->get_error_message() : $result['errors'] ) );
	}
	return $result;
}

function cl_reset() {
	if ( function_exists( 'cradle' ) && cradle() && cradle()->settings()->section( Config::SECTION ) ) {
		cradle()->settings()->reset( Config::SECTION );
	}
	cl_fresh();
}

function cl_post( array $args, ?string $lang = null ): int {
	$id = wp_insert_post(
		array_merge(
			array(
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'post_title'   => 'Cradle languages fixture',
				'post_content' => '<!-- wp:paragraph --><p>Fixture.</p><!-- /wp:paragraph -->',
				'post_author'  => cl_user( 'administrator' ),
			),
			$args
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		throw new Cradle_Test_Failure( 'Could not create fixture: ' . $id->get_error_message() );
	}
	update_post_meta( $id, '_cradle_test_fixture', 'languages' );
	$GLOBALS['cl_fixtures']['posts'][] = (int) $id;
	if ( null !== $lang ) {
		$ok = Content::set_language( (int) $id, $lang );
		if ( is_wp_error( $ok ) ) {
			throw new Cradle_Test_Failure( 'set_language: ' . $ok->get_error_message() );
		}
	}
	return (int) $id;
}

function cl_term( string $name, string $taxonomy = 'category' ): int {
	$t = wp_insert_term( $name, $taxonomy, array( 'slug' => sanitize_title( $name ) . '-' . wp_generate_password( 4, false, false ) ) );
	if ( is_wp_error( $t ) ) {
		throw new Cradle_Test_Failure( 'Could not create term: ' . $t->get_error_message() );
	}
	$GLOBALS['cl_fixtures']['terms'][] = array( (int) $t['term_id'], $taxonomy );
	return (int) $t['term_id'];
}

/** Drops this process' caches of entries changed by an HTTP request. */
function cl_refresh( int ...$ids ) {
	foreach ( $ids as $id ) {
		clean_post_cache( $id );
	}
	Content::flush_cache();
}

function cl_track( int $post_id ): int {
	$GLOBALS['cl_fixtures']['posts'][] = $post_id;
	update_post_meta( $post_id, '_cradle_test_fixture', 'languages' );
	return $post_id;
}

/**
 * @return array{0:int,1:string,2:string} [status, body, location]
 */
function cl_get( string $path, int $user = 0, array $args = array() ): array {
	$r = cradle_test_http( 'GET', $path, $args, $user );
	if ( is_wp_error( $r ) ) {
		throw new Cradle_Test_Failure( 'HTTP ' . $path . ': ' . $r->get_error_message() );
	}
	return array( (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ), (string) wp_remote_retrieve_header( $r, 'location' ) );
}

/** @return array<string,string> hreflang => href */
function cl_hreflang( string $html ): array {
	preg_match_all( '/<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"/', $html, $m );
	return array_combine( $m[1], array_map( 'html_entity_decode', $m[2] ) );
}

function cl_html_tag( string $html ): string {
	return preg_match( '/<html[^>]*>/', $html, $m ) ? $m[0] : '';
}

/** Path of a URL relative to the sandbox home ("/ar/foo/"). */
function cl_path( string $url ): string {
	return substr( $url, strlen( untrailingslashit( (string) get_option( 'home' ) ) ) );
}

function cl_rest( string $method, string $route, array $body, int $user, bool $nonce = true ): array {
	$session = $user ? cradle_test_cookies( $user ) : null;
	$headers = array( 'Content-Type' => 'application/json' );
	if ( $user && $nonce ) {
		$headers['X-WP-Nonce'] = cradle_test_nonce( $user, $session, 'wp_rest' );
	}
	$args = array( 'headers' => $headers );
	if ( 'GET' !== $method ) {
		$args['body'] = wp_json_encode( $body );
	}
	$r = cradle_test_http( $method, rest_url( $route ), $args, $user, $session );
	if ( is_wp_error( $r ) ) {
		throw new Cradle_Test_Failure( 'REST ' . $route . ': ' . $r->get_error_message() );
	}
	return array( (int) wp_remote_retrieve_response_code( $r ), json_decode( (string) wp_remote_retrieve_body( $r ), true ) );
}

function cl_admin_post( string $action, array $body, int $user, string $nonce_action = '' ): array {
	$session = cradle_test_cookies( $user );
	$body    = array_merge(
		array(
			'action'   => $action,
			'_wpnonce' => cradle_test_nonce( $user, $session, '' !== $nonce_action ? $nonce_action : $action ),
		),
		$body
	);
	$r = cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => $body ), $user, $session );
	return array( (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ), (string) wp_remote_retrieve_header( $r, 'location' ) );
}

function cl_run_job( string $uuid ): array {
	for ( $i = 0; $i < 200; $i++ ) {
		$job = cradle()->jobs()->run( $uuid );
		if ( is_wp_error( $job ) ) {
			throw new Cradle_Test_Failure( 'Job: ' . $job->get_error_message() );
		}
		if ( in_array( $job['state'], array( 'completed', 'failed', 'cancelled' ), true ) ) {
			return $job;
		}
	}
	throw new Cradle_Test_Failure( 'Job did not finish.' );
}

/**
 * Removes this suite's fixtures, Polylang-shaped rows, settings and users. Runs as the suite's last
 * test (so later suites in a full run see a clean sandbox) and again at shutdown after a fatal error.
 */
function cl_cleanup() {
	if ( ! function_exists( 'cradle' ) ) {
		return;
	}
	foreach ( array_unique( $GLOBALS['cl_fixtures']['posts'] ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	foreach ( $GLOBALS['cl_fixtures']['terms'] as $row ) {
		wp_delete_term( $row[0], $row[1] );
	}
	if ( function_exists( 'cl_pll_cleanup' ) && ! empty( $GLOBALS['cl_pll_rows']['tt'] ) ) {
		cl_pll_cleanup();
	}
	cl_reset();
	if ( is_file( WP_CONTENT_DIR . '/mu-plugins/cradle-languages-test-ui-type.php' ) ) {
		unlink( WP_CONTENT_DIR . '/mu-plugins/cradle-languages-test-ui-type.php' );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $GLOBALS['cl_fixtures']['users'] as $id ) {
		wp_delete_user( (int) $id );
	}
	$GLOBALS['cl_fixtures'] = array(
		'posts' => array(),
		'terms' => array(),
		'users' => array(),
	);
}

register_shutdown_function( 'cl_cleanup' );

/* ------------------------------------------------------------------------- */
/* Module, lifecycle, settings                                               */
/* ------------------------------------------------------------------------- */

cradle_test(
	'languages: module ready with descriptor, capability, taxonomy, blocks and components',
	function () {
		cl_ready();
		$descriptor = cradle()->modules()->get( 'languages' );
		assert_same( 'cradle-languages', basename( dirname( $descriptor['pluginFile'] ) ) );
		assert_true( isset( $descriptor['dataOwners']['taxonomies'] ) && in_array( 'cradle_language', $descriptor['dataOwners']['taxonomies'], true ) );
		assert_true( isset( $descriptor['dependencies']['blocks'] ), 'Depends on the blocks module.' );
		assert_true( get_role( 'administrator' )->has_cap( 'cradle_manage_languages' ) );
		assert_false( get_role( 'editor' )->has_cap( 'cradle_manage_languages' ), 'Editors are not granted the capability.' );
		assert_true( taxonomy_exists( 'cradle_language' ) );
		$tax = get_taxonomy( 'cradle_language' );
		assert_false( $tax->public || $tax->show_ui || $tax->show_in_rest || $tax->query_var, 'The language taxonomy is hidden.' );
		foreach ( array( 'cradle/language-switcher', 'cradle/language-section' ) as $name ) {
			assert_true( WP_Block_Type_Registry::get_instance()->is_registered( $name ), $name );
			assert_true( (bool) cradle()->components()->get( $name ), $name . ' component' );
		}
		assert_true( (bool) cradle()->settings()->section( 'languages' ) );
		assert_same( 'cradle_manage_languages', cradle()->settings()->section( 'languages' )['capability'] );
	}
);

cradle_test(
	'languages: activation is idempotent and writes no settings or content; deactivation keeps data',
	function () {
		cl_ready();
		cl_reset();
		global $wpdb;
		$posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
		Lifecycle::activate( false );
		Lifecycle::activate( false );
		cl_fresh();
		assert_false( get_option( Config::OPTION ), 'Activation stores no settings row (defaults are derived).' );
		assert_same( CRADLE_LANGUAGES_VERSION, get_option( Lifecycle::VERSION_OPTION ) );
		assert_same( $posts, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ), 'No content is created.' );
		$default = Languages::site_language();
		assert_same( array( $default['slug'] ), Languages::slugs(), 'Without saved languages the site language is the only (default) language.' );
		assert_true( $default['default'] );
	}
);

cradle_test(
	'languages: settings validation (prefixes, locales, default, limits, reserved words, content collisions)',
	function () {
		cl_ready();
		cl_admin();
		$row = static fn( $slug, $locale, $default = false ) => array( 'slug' => $slug, 'locale' => $locale, 'name' => strtoupper( $slug ), 'default' => $default );
		$ok  = Config::validate_languages( array( $row( 'en', 'en_US', true ), $row( 'ar', 'ar' ), $row( 'pt-br', 'pt_BR' ) ) );
		assert_false( is_wp_error( $ok ), is_wp_error( $ok ) ? $ok->get_error_message() : '' );
		assert_same( 'rtl', $ok[1]['dir'], 'Direction defaults from the locale.' );
		assert_same( 'العربية', $ok[1]['native'], 'The native name is derived from the locale when empty.' );
		foreach (
			array(
				'bad prefix'         => array( $row( 'EN', 'en_US', true ) ),
				'long prefix'        => array( $row( 'english', 'en_US', true ) ),
				'bad locale'         => array( $row( 'en', 'english', true ) ),
				'duplicate prefix'   => array( $row( 'en', 'en_US', true ), $row( 'en', 'en_GB' ) ),
				'duplicate locale'   => array( $row( 'en', 'en_US', true ), $row( 'us', 'en_US' ) ),
				'two defaults'       => array( $row( 'en', 'en_US', true ), $row( 'ar', 'ar', true ) ),
				'reserved word'      => array( $row( 'en', 'en_US', true ), $row( 'tag', 'tg' ) ),
				'reserved query var' => array( $row( 'en', 'en_US', true ), $row( 'cat', 'ca' ) ),
				'rest prefix part'   => array( $row( 'en', 'en_US', true ), $row( 'wp', 'wo' ) ),
				'empty'              => array(),
			) as $case => $rows
		) {
			assert_wp_error( Config::validate_languages( $rows ), '', 'Must refuse: ' . $case );
		}
		$eleven = array();
		foreach ( array( 'aa', 'ab', 'ac', 'ad', 'ae', 'af', 'ag', 'ah', 'ai', 'aj', 'ak' ) as $i => $slug ) {
			$eleven[] = $row( $slug, $slug, 0 === $i );
		}
		assert_wp_error( Config::validate_languages( $eleven ), 'max', 'At most 10 languages.' );

		// A top-level entry whose slug equals a new prefix is refused.
		$page = cl_post( array( 'post_type' => 'page', 'post_name' => 'qx', 'post_title' => 'QX collision' ) );
		assert_same( 'qx', get_post_field( 'post_name', $page ) );
		$err = Config::validate_languages( array( $row( 'en', 'en_US', true ), $row( 'qx', 'qx' ) ) );
		assert_wp_error( $err, 'reserved' );
		assert_contains( 'QX collision', $err->get_error_message() );

		// Form shape: removing the default language is refused; removing another works.
		$form = array(
			'__form'    => '1',
			'__default' => '0',
			'rows'      => array(
				array( 'slug' => 'en', 'locale' => 'en_US', 'name' => 'English', 'remove' => '1' ),
				array( 'slug' => 'ar', 'locale' => 'ar', 'name' => 'Arabic' ),
			),
		);
		assert_wp_error( Config::validate_languages( $form ), 'default', 'The default language cannot be removed.' );
		$form['rows'][0]['remove'] = '';
		$form['rows'][1]['remove'] = '1';
		$form['rows'][2]           = array( 'slug' => '', 'locale' => '' ); // Empty "new" row.
		$kept                      = Config::validate_languages( $form );
		assert_same( array( 'en' ), array_column( $kept, 'slug' ) );

		// Invalid field keeps the previous value with an error; other fields save.
		cl_configure();
		$result = cradle()->settings()->update( 'languages', array( 'languages' => array( $row( 'EN', 'x' ) ), 'hreflang' => false ) );
		cl_fresh();
		assert_true( isset( $result['errors']['languages'] ) );
		assert_same( array( 'en', 'ar' ), Languages::slugs(), 'Languages kept.' );
		assert_false( Config::hreflang(), 'The valid field saved.' );
		assert_wp_error( Config::validate_modes( array( 'post' => 'sometimes' ) ) );
		assert_wp_error( Config::validate_menu_locations( array( 'ar' => array( 'primary' => 999999 ) ) ), 'menu' );
		cl_reset();
	}
);

cradle_test(
	'languages: settings form over HTTP — administrator saves, editor and bad nonce refused, stale revision refused',
	function () {
		cl_ready();
		cl_reset();
		$admin   = cl_admin();
		$session = cradle_test_cookies( $admin );
		$page    = cl_get( admin_url( 'admin.php?page=cradle-languages' ), 0 );
		assert_same( 302, $page[0], 'Anonymous users are sent to the login page.' );
		$r = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-languages' ), array(), $admin, $session );
		assert_same( 200, wp_remote_retrieve_response_code( $r ) );
		$body = wp_remote_retrieve_body( $r );
		assert_contains( 'cradle_languages_settings[languages][rows][0][slug]', $body );
		assert_contains( 'Cradle Translations catalog', $body );
		$post = static function ( int $user, array $session, string $nonce, int $rev ) {
			return cradle_test_http(
				'POST',
				admin_url( 'options.php' ),
				array(
					'body' => array(
						'option_page'               => 'cradle_languages',
						'action'                    => 'update',
						'_wpnonce'                  => $nonce,
						'_wp_http_referer'          => '/cradle/wp/wp-admin/admin.php?page=cradle-languages',
						'cradle_languages_settings' => array(
							'_revision' => $rev,
							'languages' => array(
								'__form'    => '1',
								'__default' => '0',
								'rows'      => array(
									array( 'slug' => 'en', 'locale' => 'en_US', 'name' => 'English', 'native' => 'English', 'dir' => 'ltr', 'order' => '1' ),
									array( 'slug' => 'ar', 'locale' => 'ar', 'name' => 'Arabic', 'native' => 'العربية', 'dir' => 'rtl', 'order' => '2' ),
									array( 'slug' => '', 'locale' => '' ),
								),
							),
						),
					),
				),
				$user,
				$session
			);
		};
		$nonce = cradle_test_nonce( $admin, $session, 'cradle_languages-options' );
		try {
			$post( $admin, $session, $nonce, cradle()->settings()->revision( 'languages' ) + 5 );
			cl_fresh();
			assert_same( 1, count( Languages::slugs() ), 'A stale revision saves nothing.' );

			$editor   = cl_user( 'editor' );
			$esession = cradle_test_cookies( $editor );
			$response = $post( $editor, $esession, cradle_test_nonce( $editor, $esession, 'cradle_languages-options' ), cradle()->settings()->revision( 'languages' ) );
			assert_same( 403, wp_remote_retrieve_response_code( $response ), 'Editors are refused.' );
			$response = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-languages' ), array(), $editor, $esession );
			assert_same( 403, wp_remote_retrieve_response_code( $response ), 'Editors cannot open the screen.' );

			$response = $post( $admin, $session, 'bad-nonce', cradle()->settings()->revision( 'languages' ) );
			assert_same( 403, wp_remote_retrieve_response_code( $response ), 'A bad nonce is refused.' );
			cl_fresh();
			assert_same( 1, count( Languages::slugs() ) );

			$response = $post( $admin, $session, $nonce, cradle()->settings()->revision( 'languages' ) );
			assert_same( 302, wp_remote_retrieve_response_code( $response ) );
			cl_fresh();
			assert_same( array( 'en', 'ar' ), Languages::slugs(), 'The administrator saves through options.php.' );
			assert_same( 'rtl', Languages::get( 'ar' )['dir'] );
			assert_true( (bool) get_term_by( 'slug', 'ar', 'cradle_language' ), 'Saving creates the language terms.' );
		} finally {
			cl_reset();
		}
	}
);

/* ------------------------------------------------------------------------- */
/* Routing, links, locale                                                    */
/* ------------------------------------------------------------------------- */

cradle_test(
	'languages: prefixed URLs resolve with the right <html lang dir>; wrong prefix 301; unknown prefix 404',
	function () {
		cl_ready();
		cl_configure();
		try {
			$en = cl_post( array( 'post_title' => 'Lang route English', 'post_name' => 'lang-route-en' ), 'en' );
			$ar = cl_post( array( 'post_title' => 'Lang route Arabic', 'post_name' => 'lang-route-ar' ), 'ar' );
			assert_same( '/ar/lang-route-ar/', cl_path( get_permalink( $ar ) ), 'Entry links carry their language prefix.' );
			assert_same( '/lang-route-en/', cl_path( get_permalink( $en ) ), 'The default language has no prefix.' );

			list( $code, $body ) = cl_get( '/ar/lang-route-ar/' );
			assert_same( 200, $code );
			assert_contains( 'Lang route Arabic', $body );
			$tag = cl_html_tag( $body );
			assert_contains( 'lang="ar"', $tag );
			assert_contains( 'dir="rtl"', $tag );

			list( $code, $body ) = cl_get( '/lang-route-en/' );
			assert_same( 200, $code );
			assert_contains( 'lang="en-US"', cl_html_tag( $body ) );
			assert_not_contains( 'dir="rtl"', cl_html_tag( $body ) );

			list( $code, , $location ) = cl_get( '/ar/lang-route-en/' );
			assert_same( 301, $code, 'An English entry under /ar/ redirects.' );
			assert_same( get_permalink( $en ), $location );
			list( $code, , $location ) = cl_get( '/lang-route-ar/' );
			assert_same( 301, $code, 'An Arabic entry without its prefix redirects.' );
			assert_same( get_permalink( $ar ), $location );

			list( $code ) = cl_get( '/zz/lang-route-missing-xyz/' );
			assert_same( 404, $code, 'An unknown prefix is a normal 404.' );
			list( $code ) = cl_get( '/ar/lang-route-missing-xyz/' );
			assert_same( 404, $code );

			list( $code, $body ) = cl_get( '/ar/' );
			assert_same( 200, $code, 'The Arabic home resolves.' );
			assert_contains( 'dir="rtl"', cl_html_tag( $body ) );
			list( $code, $body ) = cl_get( '/' );
			assert_same( 200, $code );
			assert_not_contains( 'dir="rtl"', cl_html_tag( $body ) );

			// wp-admin, REST and login are never prefixed or localized.
			list( $code, $body ) = cl_get( '/ar/' );
			assert_contains( 'href="' . esc_url( rest_url() ) . '"', $body, 'The REST discovery link is not prefixed.' );
			assert_not_contains( '/ar/wp-json', $body );
			assert_not_contains( '/ar/wp-content', $body );
			assert_not_contains( '/ar/wp-includes', $body );
			assert_same( 0, strpos( admin_url(), untrailingslashit( (string) get_option( 'siteurl' ) ) . '/wp-admin' ) );
		} finally {
			cl_reset();
		}
	}
);

cradle_test(
	'languages: links on a prefixed page keep the language; entry and term links use the object language',
	function () {
		cl_ready();
		cl_configure();
		try {
			$ar   = cl_post( array( 'post_title' => 'Lang links Arabic', 'post_name' => 'lang-links-ar' ), 'ar' );
			$cat  = cl_term( 'Lang links category' );
			$tag  = cl_term( 'Lang links tag ar', 'post_tag' );
			Content::set_term_language( $tag, 'ar' );
			wp_set_object_terms( $ar, array( $tag ), 'post_tag' );
			assert_same( '/ar/', cl_path( Links::language_home( Languages::get( 'ar' ) ) ) );
			assert_contains( '/ar/tag/', get_term_link( $tag ), 'Term links carry the term language.' );
			assert_not_contains( '/ar/', get_term_link( $cat ), 'Untagged terms count as the default language.' );
			assert_same( get_permalink( $ar ), Links::localize( get_permalink( $ar ), Languages::get( 'ar' ) ), 'Conversion is idempotent.' );
			assert_same( '/lang-links-ar/', cl_path( Links::localize( get_permalink( $ar ), Languages::get( 'en' ) ) ), 'An existing prefix is replaced, not stacked.' );
			assert_same( 'https://example.com/ar/x', Links::localize( 'https://example.com/ar/x', Languages::get( 'en' ) ), 'Other hosts are untouched.' );

			list( $code, $body ) = cl_get( '/ar/lang-links-ar/' );
			assert_same( 200, $code );
			$home = untrailingslashit( (string) get_option( 'home' ) );
			assert_contains( 'href="' . $home . '/ar/"', $body, 'Home links on Arabic pages point to the Arabic home.' );
			assert_not_contains( 'action="' . $home . '/"', $body, 'Search forms are not sent to the default language.' );
		} finally {
			cl_reset();
		}
	}
);

cradle_test(
	'languages: static front page and posts page per language',
	function () {
		cl_ready();
		cl_configure();
		$front = (int) get_option( 'page_on_front' );
		$posts = (int) get_option( 'page_for_posts' );
		if ( ! $front || ! $posts || 'page' !== get_option( 'show_on_front' ) ) {
			cl_reset();
			skip_test( 'The sandbox has no static front page and posts page.' );
		}
		try {
			$front_ar = Translator::create( $front, 'ar' );
			assert_false( is_wp_error( $front_ar ), is_wp_error( $front_ar ) ? $front_ar->get_error_message() : '' );
			cl_track( $front_ar['id'] );
			wp_update_post( array( 'ID' => $front_ar['id'], 'post_status' => 'publish', 'post_title' => 'الصفحة الرئيسية للاختبار', 'post_content' => '<!-- wp:paragraph --><p>محتوى الصفحة الرئيسية العربية</p><!-- /wp:paragraph -->' ) );
			$posts_ar = Translator::create( $posts, 'ar' );
			cl_track( $posts_ar['id'] );
			wp_update_post( array( 'ID' => $posts_ar['id'], 'post_status' => 'publish', 'post_title' => 'مدونة الاختبار' ) );
			$ar_post = cl_post( array( 'post_title' => 'Lang blog Arabic only', 'post_name' => 'lang-blog-ar' ), 'ar' );
			$en_post = cl_post( array( 'post_title' => 'Lang blog English only', 'post_name' => 'lang-blog-en' ), 'en' );
			Content::flush_cache();

			assert_same( '/ar/', cl_path( get_permalink( $front_ar['id'] ) ), 'The front page translation links to the language home.' );
			assert_same( '/', cl_path( get_permalink( $front ) ) );

			list( $code, $body ) = cl_get( '/ar/' );
			assert_same( 200, $code );
			assert_contains( 'محتوى الصفحة الرئيسية العربية', $body, '/ar/ shows the Arabic front page.' );
			list( , $body ) = cl_get( '/' );
			assert_not_contains( 'محتوى الصفحة الرئيسية العربية', $body, 'The default home keeps its front page.' );

			list( $code, $body ) = cl_get( cl_path( get_permalink( $posts_ar['id'] ) ) );
			assert_same( 200, $code );
			assert_contains( 'Lang blog Arabic only', $body, 'The Arabic posts page lists Arabic posts.' );
			assert_not_contains( 'Lang blog English only', $body, 'The Arabic posts page hides English posts.' );
			list( , $body ) = cl_get( cl_path( get_permalink( $posts ) ) );
			assert_contains( 'Lang blog English only', $body );
			assert_not_contains( 'Lang blog Arabic only', $body );
			unset( $ar_post, $en_post );
		} finally {
			cl_reset();
		}
	}
);

/* ------------------------------------------------------------------------- */
/* Query filtering, blocks                                                   */
/* ------------------------------------------------------------------------- */

cradle_test(
	'languages: post cards, Query Loop and search show only the current language; shared types show everywhere',
	function () {
		cl_ready();
		cl_configure( array( 'post_types' => array( 'project' => 'shared' ) ) );
		try {
			$word = 'zqlangword' . wp_generate_password( 5, false, false );
			cl_post( array( 'post_title' => 'Lang list English ' . $word ), 'en' );
			cl_post( array( 'post_title' => 'Lang list Arabic ' . $word ), 'ar' );
			cl_post( array( 'post_title' => 'Lang list Untagged ' . $word ) );
			wp_delete_object_term_relationships( end( $GLOBALS['cl_fixtures']['posts'] ), 'cradle_language' );
			$blocks  = '<!-- wp:cradle/post-cards {"postType":"post","count":24} /-->';
			$blocks .= '<!-- wp:query {"queryId":7,"query":{"perPage":50,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","search":"' . $word . '","inherit":false}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title {"className":"cl-loop-title"} /--><!-- /wp:post-template --></div><!-- /wp:query -->';
			$project = get_posts( array( 'post_type' => 'project', 'numberposts' => 1, 'cradle_lang' => 'all' ) );
			if ( $project ) {
				$blocks .= '<!-- wp:cradle/post-cards {"postType":"project","count":24} /-->';
			}
			$page_en = cl_post( array( 'post_type' => 'page', 'post_title' => 'Lang list page', 'post_name' => 'lang-list-page', 'post_content' => $blocks ), 'en' );
			$page_ar = Translator::create( $page_en, 'ar' );
			cl_track( $page_ar['id'] );
			wp_update_post( array( 'ID' => $page_ar['id'], 'post_status' => 'publish' ) );

			list( $code, $body ) = cl_get( cl_path( get_permalink( $page_ar['id'] ) ) );
			assert_same( 200, $code );
			assert_contains( 'Lang list Arabic ' . $word, $body );
			assert_not_contains( 'Lang list English ' . $word, $body, 'Post cards and Query Loop hide English entries on Arabic pages.' );
			assert_not_contains( 'Lang list Untagged ' . $word, $body, 'Entries without a language count as the default language.' );
			if ( $project ) {
				assert_contains( Links::localize( get_permalink( $project[0] ), Languages::get( 'ar' ) ), $body, 'Shared types list in every language (linked under the current prefix).' );
			}

			list( , $body ) = cl_get( '/lang-list-page/' );
			assert_contains( 'Lang list English ' . $word, $body );
			assert_contains( 'Lang list Untagged ' . $word, $body );
			assert_not_contains( 'Lang list Arabic ' . $word, $body );

			list( $code, $body ) = cl_get( '/ar/?s=' . $word );
			assert_same( 200, $code );
			assert_contains( 'Lang list Arabic ' . $word, $body, 'Search finds Arabic entries under /ar/.' );
			assert_not_contains( 'Lang list English ' . $word, $body, 'Search is limited to the current language.' );
			list( , $body ) = cl_get( '/?s=' . $word );
			assert_contains( 'Lang list English ' . $word, $body );
			assert_not_contains( 'Lang list Arabic ' . $word, $body );

			// Explicit cradle_lang query var.
			$all = get_posts( array( 's' => $word, 'cradle_lang' => 'all', 'numberposts' => 10, 'suppress_filters' => false ) );
			assert_same( 3, count( $all ), 'cradle_lang=all lists every language.' );
			$only = get_posts( array( 's' => $word, 'cradle_lang' => 'ar', 'numberposts' => 10 ) );
			assert_same( 1, count( $only ), 'cradle_lang=<slug> filters outside the frontend too.' );
			$en_only = get_posts( array( 's' => $word, 'cradle_lang' => 'en', 'numberposts' => 10 ) );
			assert_same( 2, count( $en_only ), 'The default language includes entries without a language.' );
		} finally {
			cl_reset();
		}
	}
);

cradle_test(
	'languages: switcher links to translations or language homes; hide missing; language section shows per language',
	function () {
		cl_ready();
		cl_configure();
		try {
			$content  = '<!-- wp:cradle/language-switcher {"display":"native"} /-->';
			$content .= '<!-- wp:cradle/language-switcher {"layout":"dropdown","hideMissing":true,"display":"slug","label":"Pick"} /-->';
			$content .= '<!-- wp:cradle/language-section {"languages":["en"]} --><!-- wp:paragraph --><p>CL-SECTION-EN</p><!-- /wp:paragraph --><!-- /wp:cradle/language-section -->';
			$content .= '<!-- wp:cradle/language-section {"languages":["ar"]} --><!-- wp:paragraph --><p>CL-SECTION-AR</p><!-- /wp:paragraph --><!-- /wp:cradle/language-section -->';
			$content .= '<!-- wp:cradle/language-section --><!-- wp:paragraph --><p>CL-SECTION-ALL</p><!-- /wp:paragraph --><!-- /wp:cradle/language-section -->';
			$en       = cl_post( array( 'post_type' => 'page', 'post_title' => 'Lang switch page', 'post_name' => 'lang-switch-page', 'post_content' => $content ), 'en' );
			$alone    = cl_post( array( 'post_type' => 'page', 'post_title' => 'Lang switch alone', 'post_name' => 'lang-switch-alone', 'post_content' => $content ), 'en' );
			$ar       = Translator::create( $en, 'ar' );
			cl_track( $ar['id'] );
			wp_update_post( array( 'ID' => $ar['id'], 'post_status' => 'publish' ) );
			$home = untrailingslashit( (string) get_option( 'home' ) );

			list( , $body ) = cl_get( '/lang-switch-page/' );
			assert_contains( 'class="cradle-language-switcher cradle-language-switcher--list', $body );
			assert_contains( 'href="' . esc_url( get_permalink( $ar['id'] ) ) . '" hreflang="ar" lang="ar" dir="rtl"', $body, 'Links to the Arabic translation.' );
			assert_contains( 'aria-current="page"', $body );
			assert_contains( '>العربية<', $body, 'Native names.' );
			assert_contains( 'CL-SECTION-EN', $body );
			assert_not_contains( 'CL-SECTION-AR', $body, 'The Arabic section is hidden in English.' );
			assert_contains( 'CL-SECTION-ALL', $body, 'A section without languages shows everywhere.' );

			list( , $body ) = cl_get( cl_path( get_permalink( $ar['id'] ) ) );
			assert_contains( 'CL-SECTION-AR', $body );
			assert_not_contains( 'CL-SECTION-EN', $body, 'The English section is hidden in Arabic.' );
			assert_contains( 'href="' . $home . '/lang-switch-page/" hreflang="en-US"', $body, 'Links back to the English entry.' );

			list( , $body ) = cl_get( '/lang-switch-alone/' );
			assert_contains( 'href="' . $home . '/ar/" hreflang="ar"', $body, 'Without a translation the switcher links to the language home.' );
			assert_contains( 'is-missing', $body );
			preg_match_all( '#<details class="cradle-language-switcher__details">.*?</details>#s', $body, $m );
			assert_same( 1, count( $m[0] ) );
			assert_not_contains( 'hreflang="ar"', $m[0][0], 'hideMissing leaves untranslated languages out.' );
			assert_contains( 'aria-label="Pick"', $body );

			// Items API and filter.
			$filter = static fn( $items ) => array_merge( $items, array( 'not-an-item' ) );
			add_filter( 'cradle_languages/switcher_items', $filter );
			$items = cradle_language_switcher_items();
			remove_filter( 'cradle_languages/switcher_items', $filter );
			assert_true( count( $items ) >= 2 && is_array( $items[0] ), 'Invalid filtered items are dropped.' );
		} finally {
			cl_reset();
		}
	}
);

cradle_test(
	'languages: hreflang alternates with x-default; sitemaps list every language and no drafts',
	function () {
		cl_ready();
		cl_configure();
		try {
			$en    = cl_post( array( 'post_title' => 'Lang seo English', 'post_name' => 'lang-seo-en' ), 'en' );
			$ar    = Translator::create( $en, 'ar' );
			$draft = Translator::create( cl_post( array( 'post_title' => 'Lang seo second', 'post_name' => 'lang-seo-second' ), 'en' ), 'ar' );
			cl_track( $ar['id'] );
			cl_track( $draft['id'] );
			wp_update_post( array( 'ID' => $ar['id'], 'post_status' => 'publish' ) );

			list( , $body ) = cl_get( '/lang-seo-en/' );
			$links          = cl_hreflang( $body );
			assert_same( get_permalink( $en ), $links['en-US'] ?? '' );
			assert_same( get_permalink( $ar['id'] ), $links['ar'] ?? '' );
			assert_same( get_permalink( $en ), $links['x-default'] ?? '' );
			assert_contains( '<link rel="canonical" href="' . get_permalink( $en ) . '"', $body );
			list( , $body ) = cl_get( cl_path( get_permalink( $ar['id'] ) ) );
			assert_contains( '<link rel="canonical" href="' . get_permalink( $ar['id'] ) . '"', $body, 'Canonical URL carries the prefix.' );

			list( , $body ) = cl_get( '/lang-seo-second/' );
			assert_same( array(), cl_hreflang( $body ), 'No alternates when the only translation is a draft.' );

			cradle()->settings()->update( 'languages', array( 'hreflang' => false ) );
			list( , $body ) = cl_get( '/lang-seo-en/' );
			assert_same( array(), cl_hreflang( $body ), 'hreflang can be switched off.' );

			if ( ! wp_sitemaps_get_server()->sitemaps_enabled() ) {
				return;
			}
			list( $code, $body ) = cl_get( '/wp-sitemap-posts-post-1.xml' );
			assert_same( 200, $code );
			assert_contains( '<loc>' . get_permalink( $en ) . '</loc>', $body );
			assert_contains( '<loc>' . get_permalink( $ar['id'] ) . '</loc>', $body, 'The sitemap lists the Arabic translation with its prefix.' );
			assert_not_contains( get_permalink( $draft['id'] ), $body, 'Drafts are never listed.' );
		} finally {
			cl_reset();
		}
	}
);

cradle_test(
	'languages: Cradle Translations catalogs apply per request language (Arabic and a third left-to-right language)',
	function () {
		cl_ready();
		if ( ! cradle()->modules()->is_ready( 'translations' ) || ! class_exists( '\\Cradle\\Translations\\Store' ) || ! \Cradle\Translations\Store::exists( 'ar' ) || ! \Cradle\Translations\Store::exists( 'de_DE' ) ) {
			skip_test( 'Cradle Translations with the ar and de_DE example catalogs is not available.' );
		}
		cl_configure( array(), true );
		try {
			$term    = cl_term( 'Lang empty category' );
			$slug    = get_term( $term )->slug;
			$content = '<!-- wp:cradle/post-cards {"postType":"post","taxonomy":"category","term":"' . $slug . '"} /-->';
			$en      = cl_post( array( 'post_type' => 'page', 'post_title' => 'Lang strings page', 'post_name' => 'lang-strings-page', 'post_content' => $content ), 'en' );
			foreach ( array( 'ar', 'de' ) as $lang ) {
				$t = Translator::create( $en, $lang );
				cl_track( $t['id'] );
				wp_update_post( array( 'ID' => $t['id'], 'post_status' => 'publish' ) );
			}
			$tr = Content::translations( $en );
			list( , $body ) = cl_get( '/lang-strings-page/' );
			assert_contains( 'Nothing to show yet.', $body );
			list( , $body ) = cl_get( cl_path( get_permalink( $tr['ar'] ) ) );
			assert_contains( 'لا يوجد شيء لعرضه بعد.', $body, 'A Cradle Blocks string renders from the Arabic catalog on /ar/.' );
			assert_contains( 'dir="rtl"', cl_html_tag( $body ) );
			list( $code, $body ) = cl_get( cl_path( get_permalink( $tr['de'] ) ) );
			assert_same( 200, $code );
			assert_contains( '/de/', get_permalink( $tr['de'] ) );
			assert_contains( 'Noch nichts anzuzeigen.', $body, 'The German catalog applies on /de/.' );
			$tag = cl_html_tag( $body );
			assert_contains( 'lang="de-DE"', $tag );
			assert_not_contains( 'dir="rtl"', $tag, 'German stays left to right.' );
		} finally {
			cl_reset();
		}
	}
);

/* ------------------------------------------------------------------------- */
/* Editor REST: translations                                                 */
/* ------------------------------------------------------------------------- */

cradle_test(
	'languages: REST "Add translation" — allowed for administrator, editor and own author; denied for others',
	function () {
		cl_ready();
		cl_configure();
		try {
			$admin  = cl_user( 'administrator' );
			$editor = cl_user( 'editor' );
			$author = cl_user( 'author' );
			$contri = cl_user( 'contributor' );
			$subs   = cl_user( 'subscriber' );
			$media  = get_posts( array( 'post_type' => 'attachment', 'numberposts' => 1, 'post_status' => 'inherit', 'cradle_lang' => 'all' ) );
			$cat    = cl_term( 'Lang copy cat en' );
			$cat_ar = cl_term( 'Lang copy cat ar' );
			$cat_x  = cl_term( 'Lang copy cat untranslated' );
			Content::link_term_translations( array( 'en' => $cat, 'ar' => $cat_ar ) );
			$source = cl_post(
				array(
					'post_title'   => 'Lang copy source',
					'post_name'    => 'lang-copy-source',
					'post_excerpt' => 'Lang copy excerpt',
					'post_content' => '<!-- wp:paragraph --><p>Lang copy body</p><!-- /wp:paragraph -->',
					'post_author'  => $admin,
				),
				'en'
			);
			wp_set_object_terms( $source, array( $cat, $cat_x ), 'category' );
			update_post_meta( $source, '_cradle_cf_subtitle', 'Copied field' );
			update_post_meta( $source, 'public_note', 'Copied public meta' );
			update_post_meta( $source, '_edit_lock', '123:1' );
			update_post_meta( $source, 'api_token', 'secret-value' );
			update_post_meta( $source, '_cradle_test_private', 'not copied' );
			if ( $media ) {
				set_post_thumbnail( $source, $media[0]->ID );
			}

			list( $code ) = cl_rest( 'POST', 'cradle-languages/v1/posts/' . $source . '/translations', array( 'lang' => 'ar' ), 0 );
			assert_same( 401, $code, 'Anonymous requests are refused.' );
			list( $code ) = cl_rest( 'POST', 'cradle-languages/v1/posts/' . $source . '/translations', array( 'lang' => 'ar' ), $admin, false );
			assert_same( 401, $code, 'A cookie without the REST nonce is refused.' );
			foreach ( array( $subs => 'subscriber', $contri => 'contributor', $author => 'author on another user’s post' ) as $user => $who ) {
				list( $code ) = cl_rest( 'POST', 'cradle-languages/v1/posts/' . $source . '/translations', array( 'lang' => 'ar' ), $user );
				assert_true( in_array( $code, array( 403, 404 ), true ), 'Refused for ' . $who . ' (got ' . $code . ').' );
			}
			assert_same( 0, Content::translation( $source, 'ar' ), 'Nothing was created by refused requests.' );
			list( $code ) = cl_rest( 'POST', 'cradle-languages/v1/posts/' . $source . '/translations', array( 'lang' => 'xx' ), $admin );
			assert_same( 400, $code, 'Unknown languages are refused.' );
			list( $code ) = cl_rest( 'GET', 'cradle-languages/v1/posts/' . $source . '/translations', array(), $admin );
			assert_same( 404, $code, 'GET never creates a translation.' );

			list( $code, $data ) = cl_rest( 'POST', 'cradle-languages/v1/posts/' . $source . '/translations', array( 'lang' => 'ar' ), $admin );
			assert_same( 201, $code, wp_json_encode( $data ) );
			$copy = (int) $data['id'];
			cl_track( $copy );
			cl_refresh( $source, $copy );
			$post = get_post( $copy );
			assert_same( 'draft', $post->post_status );
			assert_same( 'Lang copy source', $post->post_title, 'The title is copied without a suffix.' );
			assert_contains( 'Lang copy body', $post->post_content );
			assert_same( 'Lang copy excerpt', $post->post_excerpt );
			assert_same( 'ar', Content::post_language_slug( $copy ) );
			assert_same( array( 'en' => $source, 'ar' => $copy ), Content::translations( $source ), 'Linked to the group.' );
			assert_same( 'Copied field', get_post_meta( $copy, '_cradle_cf_subtitle', true ), 'Cradle Content fields are copied.' );
			assert_same( 'Copied public meta', get_post_meta( $copy, 'public_note', true ) );
			assert_same( '', get_post_meta( $copy, '_edit_lock', true ), 'Locks are not copied.' );
			assert_same( '', get_post_meta( $copy, 'api_token', true ), 'Secret-like keys are not copied.' );
			assert_same( '', get_post_meta( $copy, '_cradle_test_private', true ), 'Other protected keys are not copied.' );
			assert_same( array( $cat_ar ), wp_get_object_terms( $copy, 'category', array( 'fields' => 'ids' ) ), 'Terms map to their translation; untranslated terms are skipped.' );
			if ( $media ) {
				assert_same( $media[0]->ID, (int) get_post_thumbnail_id( $copy ) );
			}
			assert_contains( 'post.php?post=' . $copy, $data['editLink'] );

			list( $code ) = cl_rest( 'POST', 'cradle-languages/v1/posts/' . $source . '/translations', array( 'lang' => 'ar' ), $admin );
			assert_same( 409, $code, 'A second Arabic translation is refused.' );
			list( $code ) = cl_rest( 'POST', 'cradle-languages/v1/posts/' . $source . '/translations', array( 'lang' => 'en' ), $admin );
			assert_true( in_array( $code, array( 400, 409 ), true ), 'The source language is refused.' );

			// Editor may translate others' entries; an author may translate their own.
			$second        = cl_post( array( 'post_title' => 'Lang copy second', 'post_author' => $admin ), 'en' );
			list( $code, $data ) = cl_rest( 'POST', 'cradle-languages/v1/posts/' . $second . '/translations', array( 'lang' => 'ar' ), $editor );
			assert_same( 201, $code, 'Editors may translate.' );
			cl_track( (int) $data['id'] );
			$own           = cl_post( array( 'post_title' => 'Lang copy author own', 'post_author' => $author ), 'en' );
			list( $code, $data ) = cl_rest( 'POST', 'cradle-languages/v1/posts/' . $own . '/translations', array( 'lang' => 'ar' ), $author );
			assert_same( 201, $code, 'Authors may translate their own posts.' );
			cl_track( (int) $data['id'] );
			assert_same( $author, (int) get_post_field( 'post_author', (int) $data['id'] ) );

			// Panel data.
			list( $code, $data ) = cl_rest( 'GET', 'cradle-languages/v1/posts/' . $source, array(), $editor );
			assert_same( 200, $code );
			assert_same( 'en', $data['lang'] );
			assert_true( $data['languageLocked'] );
			assert_same( $copy, $data['languages'][1]['translation']['id'] );
			list( $code ) = cl_rest( 'GET', 'cradle-languages/v1/posts/' . $source, array(), $subs );
			assert_true( in_array( $code, array( 403, 404 ), true ), 'Subscribers cannot read panel data.' );
		} finally {
			cl_reset();
		}
	}
);

cradle_test(
	'languages: REST language and link endpoints enforce one entry per language per group',
	function () {
		cl_ready();
		cl_configure();
		try {
			$admin = cl_user( 'administrator' );
			$contr = cl_user( 'contributor' );
			$a     = cl_post( array( 'post_title' => 'Lang link A' ), 'en' );
			$b     = cl_post( array( 'post_title' => 'Lang link B' ), 'en' );
			$c     = cl_post( array( 'post_title' => 'Lang link C' ), 'ar' );

			list( $code ) = cl_rest( 'POST', 'cradle-languages/v1/posts/' . $b . '/language', array( 'lang' => 'ar' ), $contr );
			assert_true( in_array( $code, array( 403, 404 ), true ), 'Contributors cannot change others’ entries.' );
			list( $code, $data ) = cl_rest( 'POST', 'cradle-languages/v1/posts/' . $b . '/language', array( 'lang' => 'ar' ), $admin );
			assert_same( 200, $code );
			assert_same( 'ar', $data['lang'] );
			list( $code ) = cl_rest( 'POST', 'cradle-languages/v1/posts/' . $b . '/language', array( 'lang' => 'EN' ), $admin );
			assert_same( 400, $code, 'The argument schema refuses malformed slugs.' );

			list( $code, $data ) = cl_rest( 'POST', 'cradle-languages/v1/posts/' . $a . '/link', array( 'translations' => array( 'ar' => $b ) ), $admin );
			assert_same( 200, $code, wp_json_encode( $data ) );
			cl_refresh( $a, $b, $c );
			assert_same( array( 'en' => $a, 'ar' => $b ), Content::translations( $a ) );

			$taken = Content::set_language( $a, 'ar' );
			assert_wp_error( $taken, 'cradle_languages_taken', 'A second Arabic entry in the group is refused.' );
			// Linking C as Arabic replaces B in the group (B keeps its language, leaves the group).
			list( $code ) = cl_rest( 'POST', 'cradle-languages/v1/posts/' . $a . '/link', array( 'translations' => array( 'ar' => $c ) ), $admin );
			assert_same( 200, $code );
			cl_refresh( $a, $b, $c );
			assert_same( array( 'en' => $a, 'ar' => $c ), Content::translations( $a ) );
			assert_same( array( 'ar' => $b ), Content::translations( $b ) );
			assert_wp_error( Content::link_translations( array( 'en' => $a, 'ar' => $a ) ), '', 'One entry cannot have two languages.' );
			$page = cl_post( array( 'post_type' => 'page', 'post_title' => 'Lang link page' ), 'ar' );
			assert_wp_error( Content::link_translations( array( 'en' => $a, 'ar' => $page ) ), 'cradle_languages_type' );
			list( $code ) = cl_rest( 'POST', 'cradle-languages/v1/posts/' . $a . '/link', array( 'translations' => array( 'ar' => $page ) ), $contr );
			assert_true( in_array( $code, array( 403, 404 ), true ) );
		} finally {
			cl_reset();
		}
	}
);

cradle_test(
	'languages: REST cradle_lang collection parameter (editor requests list every language)',
	function () {
		cl_ready();
		cl_configure();
		try {
			$admin = cl_user( 'administrator' );
			$word  = 'zqrest' . wp_generate_password( 5, false, false );
			$en    = cl_post( array( 'post_title' => 'Lang rest ' . $word . ' en' ), 'en' );
			$ar    = cl_post( array( 'post_title' => 'Lang rest ' . $word . ' ar' ), 'ar' );
			list( $code, $data ) = cl_rest( 'GET', 'wp/v2/posts?search=' . $word, array(), $admin );
			assert_same( 200, $code );
			assert_same( 2, count( $data ), 'Without cradle_lang every language is listed.' );
			list( , $data ) = cl_rest( 'GET', 'wp/v2/posts?search=' . $word . '&cradle_lang=ar', array(), $admin );
			assert_same( array( $ar ), array_column( $data, 'id' ) );
			assert_same( 'ar', $data[0]['cradle_lang'] );
			list( , $data ) = cl_rest( 'GET', 'wp/v2/posts?search=' . $word . '&cradle_lang=en', array(), $admin );
			assert_same( array( $en ), array_column( $data, 'id' ) );
			list( $code ) = cl_rest( 'GET', 'wp/v2/posts?cradle_lang=xx', array(), $admin );
			assert_same( 400, $code, 'Unknown languages are refused by the schema.' );
			list( , $data ) = cl_rest( 'GET', 'wp/v2/posts/' . $ar, array(), 0 );
			assert_contains( '/ar/', $data['link'], 'REST entry links carry the prefix.' );
		} finally {
			cl_reset();
		}
	}
);

cradle_test(
	'languages: admin list language column and filter; Add New preselects a language',
	function () {
		cl_ready();
		cl_configure();
		try {
			$admin   = cl_admin();
			$word    = 'zqlist' . wp_generate_password( 5, false, false );
			$en      = cl_post( array( 'post_title' => 'Lang admin ' . $word . ' en' ), 'en' );
			$ar      = cl_post( array( 'post_title' => 'Lang admin ' . $word . ' ar' ), 'ar' );
			Content::link_translations( array( 'en' => $en, 'ar' => $ar ) );
			list( $code, $body ) = cl_get( admin_url( 'edit.php?s=' . $word ), $admin );
			assert_same( 200, $code );
			assert_contains( 'column-cradle_languages', $body );
			assert_contains( 'name="cradle_lang"', $body );
			assert_contains( 'Lang admin ' . $word . ' en', $body );
			assert_contains( 'Lang admin ' . $word . ' ar', $body, 'The list shows every language by default.' );
			assert_contains( 'post.php?post=' . $ar . '&amp;action=edit', $body, 'The column links to the translation.' );
			list( , $body ) = cl_get( admin_url( 'edit.php?s=' . $word . '&cradle_lang=ar' ), $admin );
			assert_contains( 'Lang admin ' . $word . ' ar', $body );
			assert_not_contains( 'Lang admin ' . $word . ' en</a>', $body, 'The language filter limits the list.' );

			// Add New with ?cradle_lang=ar gives the new auto-draft that language.
			$GLOBALS['pagenow']   = 'post-new.php';
			$_GET['cradle_lang'] = 'ar';
			$draft               = wp_insert_post( array( 'post_title' => 'Lang auto draft', 'post_status' => 'auto-draft', 'post_type' => 'post' ) );
			unset( $_GET['cradle_lang'] );
			$GLOBALS['pagenow'] = 'index.php';
			cl_track( (int) $draft );
			assert_same( 'ar', Content::post_language_term( (int) $draft ) );
			$plain = cl_post( array( 'post_title' => 'Lang default on insert' ) );
			assert_same( 'en', Content::post_language_term( $plain ), 'New entries get the default language.' );
		} finally {
			cl_reset();
		}
	}
);

cradle_test(
	'languages: term languages, term translations (form handler with nonce and capability) and term query filtering',
	function () {
		cl_ready();
		cl_configure();
		try {
			$admin = cl_admin();
			$en    = cl_term( 'Lang term news' );
			$ar    = cl_term( 'Lang term akhbar' );
			assert_same( 'en', get_term_meta( $en, '_cradle_lang', true ), 'New terms get the default language.' );

			// The form handler: valid nonce + capability.
			$_POST['cradle_languages_term_nonce'] = wp_create_nonce( 'cradle_languages_term' );
			$_POST['cradle_languages_term']       = array( 'lang' => 'ar' );
			wp_update_term( $ar, 'category', array( 'description' => 'x' ) );
			assert_same( 'ar', get_term_meta( $ar, '_cradle_lang', true ) );
			$_POST['cradle_languages_term'] = array( 'lang' => 'en', 'translations' => array( 'ar' => $ar ) );
			wp_update_term( $en, 'category', array( 'description' => 'y' ) );
			assert_same( array( 'en' => $en, 'ar' => $ar ), Content::term_translations( $en ), 'Terms are linked.' );
			assert_same( $ar, cradle_get_term_translation( $en, 'ar' ), 'Public API: term translation.' );
			assert_same( $en, cradle_get_term_translation( $ar, 'en' ) );
			assert_same( 0, cradle_get_term_translation( $en, 'xx' ), 'Unknown language: 0.' );
			assert_same( 'ar', cradle_language_of( get_term( $ar ) )['slug'] ?? '', 'Public API: term language.' );

			// Bad nonce and a user without edit_term change nothing.
			$_POST['cradle_languages_term_nonce'] = 'bad';
			$_POST['cradle_languages_term']       = array( 'lang' => 'en' );
			wp_update_term( $ar, 'category', array( 'description' => 'z' ) );
			assert_same( 'ar', get_term_meta( $ar, '_cradle_lang', true ), 'A bad nonce is ignored.' );
			wp_set_current_user( cl_user( 'subscriber' ) );
			$_POST['cradle_languages_term_nonce'] = wp_create_nonce( 'cradle_languages_term' );
			\Cradle\Languages\Admin\Lists::save_term( $ar, 0, 'category' );
			assert_same( 'ar', get_term_meta( $ar, '_cradle_lang', true ), 'Users without edit_term are ignored.' );
			unset( $_POST['cradle_languages_term_nonce'], $_POST['cradle_languages_term'] );
			wp_set_current_user( $admin );

			$ids = static fn( $lang ) => get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'ids', 'cradle_lang' => $lang, 'include' => array() ) );
			assert_true( in_array( $ar, $ids( 'ar' ), true ) && ! in_array( $en, $ids( 'ar' ), true ), 'Term queries filter by language.' );
			assert_true( in_array( $en, $ids( 'en' ), true ) && ! in_array( $ar, $ids( 'en' ), true ) );
			assert_true( in_array( $ar, get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'ids' ) ), true ), 'Outside the frontend terms are unfiltered.' );

			// Edit screen shows the fields.
			list( $code, $body ) = cl_get( admin_url( 'term.php?taxonomy=category&tag_ID=' . $en ), $admin );
			assert_same( 200, $code );
			assert_contains( 'name="cradle_languages_term[lang]"', $body );
			assert_contains( 'name="cradle_languages_term[translations][ar]"', $body );
		} finally {
			unset( $_POST['cradle_languages_term_nonce'], $_POST['cradle_languages_term'] );
			cl_reset();
		}
	}
);

/* ------------------------------------------------------------------------- */
/* Menus                                                                     */
/* ------------------------------------------------------------------------- */

cradle_test(
	'languages: menus per language (Navigation translation, links to translations, classic locations)',
	function () {
		cl_ready();
		$navigation = get_posts( array( 'post_type' => 'wp_navigation', 'numberposts' => 1, 'title' => 'Primary', 'cradle_lang' => 'all' ) );
		$studio     = get_page_by_path( 'studio' );
		if ( ! $navigation || ! $studio ) {
			skip_test( 'The sandbox has no Primary navigation or Studio page.' );
		}
		cl_configure( array( 'post_types' => array( 'wp_navigation' => 'translatable' ) ) );
		try {
			$studio_ar = Translator::create( $studio->ID, 'ar' );
			cl_track( $studio_ar['id'] );
			wp_update_post( array( 'ID' => $studio_ar['id'], 'post_status' => 'publish' ) );
			list( , $body ) = cl_get( '/ar/' );
			assert_contains( 'href="' . get_permalink( $studio_ar['id'] ) . '"', $body, 'Navigation links point to the published translation.' );

			$nav_ar = Translator::create( $navigation[0]->ID, 'ar' );
			assert_false( is_wp_error( $nav_ar ), is_wp_error( $nav_ar ) ? $nav_ar->get_error_message() : '' );
			cl_track( $nav_ar['id'] );
			wp_update_post( array( 'ID' => $nav_ar['id'], 'post_status' => 'publish', 'post_content' => '<!-- wp:navigation-link {"label":"CL-ARABIC-NAV","url":"' . esc_url_raw( home_url( '/ar/' ) ) . '","kind":"custom"} /-->' ) );
			list( , $body ) = cl_get( '/ar/' );
			assert_contains( 'CL-ARABIC-NAV', $body, 'The Navigation block shows its Arabic translation on /ar/.' );
			list( , $body ) = cl_get( '/' );
			assert_not_contains( 'CL-ARABIC-NAV', $body, 'English pages keep the original navigation.' );

			// Classic locations: the language's assignment wins over the default one.
			$menu = wp_create_nav_menu( 'Lang test menu ' . wp_generate_password( 4, false, false ) );
			try {
				cradle()->settings()->update( 'languages', array( 'menu_locations' => array( 'ar' => array( 'primary' => $menu ) ) ) );
				cl_fresh();
				Languages::switch_to( 'ar' );
				assert_same( array( 'primary' => $menu, 'footer' => 5 ), Menus::locations( array( 'primary' => 4, 'footer' => 5 ) ) );
				Languages::restore();
				Languages::switch_to( 'en' );
				assert_same( array( 'primary' => 4 ), Menus::locations( array( 'primary' => 4 ) ), 'The default language keeps its menus.' );
				Languages::restore();
			} finally {
				wp_delete_nav_menu( $menu );
			}
		} finally {
			cl_reset();
		}
	}
);

/* ------------------------------------------------------------------------- */
/* Options and edge cases                                                    */
/* ------------------------------------------------------------------------- */

cradle_test(
	'languages: with hide_default_prefix off the default language gets /en/ and unprefixed URLs redirect',
	function () {
		cl_ready();
		cl_configure( array( 'hide_default_prefix' => false ) );
		try {
			$en = cl_post( array( 'post_title' => 'Lang prefix English', 'post_name' => 'lang-prefix-en' ), 'en' );
			assert_same( '/en/lang-prefix-en/', cl_path( get_permalink( $en ) ) );
			list( $code, $body ) = cl_get( '/en/lang-prefix-en/' );
			assert_same( 200, $code );
			assert_contains( 'Lang prefix English', $body );
			list( $code, , $location ) = cl_get( '/lang-prefix-en/' );
			assert_same( 301, $code );
			assert_same( get_permalink( $en ), $location );
			list( $code, , $location ) = cl_get( '/' );
			assert_same( 301, $code, 'The unprefixed home redirects to /en/.' );
			assert_contains( '/en/', $location );
		} finally {
			cl_reset();
		}
	}
);

cradle_test(
	'languages: a top-level entry cannot take a language prefix as its slug',
	function () {
		cl_ready();
		cl_configure();
		try {
			$page = cl_post( array( 'post_type' => 'page', 'post_title' => 'Arabic', 'post_name' => 'ar' ) );
			assert_true( 'ar' !== get_post_field( 'post_name', $page ), 'The slug was made unique.' );
			$child = cl_post( array( 'post_type' => 'page', 'post_title' => 'Child ar', 'post_name' => 'ar', 'post_parent' => $page ) );
			assert_same( 'ar', get_post_field( 'post_name', $child ), 'Child pages may use it.' );
		} finally {
			cl_reset();
		}
	}
);

cradle_test(
	'languages: default-language assignment job — administrator runs it, editor refused',
	function () {
		cl_ready();
		cl_configure();
		try {
			$id = cl_post( array( 'post_title' => 'Lang untagged' ) );
			wp_delete_object_term_relationships( $id, 'cradle_language' );
			clean_object_term_cache( $id, 'post' );
			assert_true( Tasks::untagged_post_count() >= 1 );
			list( $code ) = cl_admin_post( 'cradle_languages_assign', array(), cl_user( 'editor' ) );
			assert_same( 403, $code, 'Editors cannot start the job.' );
			list( $code, , $location ) = cl_admin_post( 'cradle_languages_assign', array( '_wpnonce' => 'bad' ), cl_user( 'administrator' ) );
			assert_same( 403, $code, 'A bad nonce is refused.' );
			list( $code, , $location ) = cl_admin_post( 'cradle_languages_assign', array(), cl_user( 'administrator' ) );
			assert_same( 302, $code );
			parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
			assert_true( wp_is_uuid( $query['job'] ?? '', 4 ), 'Redirects to the progress view.' );
			cl_admin();
			$job = cl_run_job( $query['job'] );
			assert_same( 'completed', $job['state'], (string) $job['error_message'] );
			clean_object_term_cache( $id, 'post' );
			assert_same( 'en', Content::post_language_term( $id ) );
			assert_same( 0, Tasks::untagged_post_count() );
		} finally {
			cl_reset();
		}
	}
);

/* ------------------------------------------------------------------------- */
/* Polylang import                                                           */
/* ------------------------------------------------------------------------- */

cradle_test(
	'languages: Polylang import — dry run, permissions, stale fingerprint and idempotent import of fixture data',
	function () {
		cl_ready();
		cl_reset();
		if ( Polylang::has_data() ) {
			skip_test( 'The sandbox already holds Polylang data; the fixture would mix with it.' );
		}
		try {
			$en1 = cl_post( array( 'post_title' => 'Pll en one', 'post_type' => 'page' ) );
			$ar1 = cl_post( array( 'post_title' => 'Pll ar one', 'post_type' => 'page' ) );
			$en2 = cl_post( array( 'post_title' => 'Pll en two' ) );
			$ar2 = cl_post( array( 'post_title' => 'Pll ar two' ) );
			$cen = cl_term( 'Pll news' );
			$car = cl_term( 'Pll akhbar' );
			cl_pll_install(
				array(
					'en'     => array( $en1, $en2 ),
					'ar'     => array( $ar1, $ar2 ),
					'groups' => array( array( 'en' => $en1, 'ar' => $ar1 ), array( 'en' => $en2, 'ar' => $ar2 ) ),
				),
				array(
					'en'     => array( $cen ),
					'ar'     => array( $car ),
					'groups' => array( array( 'en' => $cen, 'ar' => $car ) ),
				)
			);
			$report = Polylang::dry_run();
			assert_true( $report['found'] );
			assert_same( array(), $report['problems'], wp_json_encode( $report['problems'] ) );
			assert_same( array( 'en', 'ar' ), array_column( $report['languages'], 'slug' ) );
			assert_same( 'rtl', $report['languages'][1]['dir'] );
			assert_same( 2, $report['counts']['postGroups'] );
			assert_same( 1, $report['counts']['termGroups'] );
			assert_true( $report['counts']['posts']['ar'] >= 2 );
			cl_fresh();
			assert_same( array( 'en' ), Languages::slugs(), 'The dry run changes nothing.' );

			// Admin screen actions: editor refused, administrator gets the report.
			list( $code ) = cl_admin_post( 'cradle_languages_polylang_check', array(), cl_user( 'editor' ) );
			assert_same( 403, $code );
			list( $code, , $location ) = cl_admin_post( 'cradle_languages_polylang_check', array(), cl_user( 'administrator' ) );
			assert_same( 302, $code );
			assert_contains( 'cradle_languages_notice=checked', $location );
			list( $code, , $location ) = cl_admin_post( 'cradle_languages_polylang_import', array( 'fingerprint' => 'stale' ), cl_user( 'administrator' ) );
			assert_same( 302, $code );
			assert_contains( 'cradle_languages_notice=stale', $location, 'A changed fingerprint is refused.' );
			list( $code ) = cl_admin_post( 'cradle_languages_polylang_import', array( 'fingerprint' => $report['fingerprint'] ), cl_user( 'editor' ) );
			assert_same( 403, $code, 'Editors cannot import.' );
			cl_fresh();
			assert_same( array( 'en' ), Languages::slugs() );

			cl_admin();
			$job = Polylang::start( $report['fingerprint'] );
			assert_false( is_wp_error( $job ), is_wp_error( $job ) ? $job->get_error_message() : '' );
			$job = cl_run_job( $job['uuid'] );
			assert_same( 'completed', $job['state'], (string) $job['error_message'] );
			cl_fresh();
			clean_object_term_cache( array( $en1, $ar1, $en2, $ar2 ), 'post' );
			assert_same( array( 'en', 'ar' ), Languages::slugs(), 'Languages imported.' );
			assert_same( 'rtl', Languages::get( 'ar' )['dir'] );
			assert_true( Languages::hide_default_prefix() );
			assert_same( 'ar', Content::post_language_slug( $ar1 ) );
			assert_same( array( 'en' => $en1, 'ar' => $ar1 ), Content::translations( $en1 ) );
			assert_same( array( 'en' => $en2, 'ar' => $ar2 ), Content::translations( $ar2 ) );
			assert_same( 'ar', get_term_meta( $car, '_cradle_lang', true ) );
			assert_same( array( 'en' => $cen, 'ar' => $car ), Content::term_translations( $cen ) );
			$group = Content::group( $en1 );

			// Second run: same result (deterministic groups), Polylang data untouched.
			$again = Polylang::dry_run();
			$job   = cl_run_job( Polylang::start( $again['fingerprint'] )['uuid'] );
			assert_same( 'completed', $job['state'] );
			Content::flush_cache();
			assert_same( $group, Content::group( $en1 ), 'Re-import keeps the same group.' );
			assert_same( array( 'en' => $en1, 'ar' => $ar1 ), Content::translations( $en1 ) );
			assert_true( Polylang::has_data(), 'Polylang data is never deleted.' );

			// The imported Arabic page is served under /ar/.
			wp_update_post( array( 'ID' => $ar1, 'post_name' => 'pll-ar-one' ) );
			list( $code, $body ) = cl_get( '/ar/pll-ar-one/' );
			assert_same( 200, $code );
			assert_contains( 'Pll ar one', $body );
		} finally {
			cl_pll_cleanup();
			cl_reset();
		}
	}
);

cradle_test(
	'languages: Polylang descriptions are read by a bounded parser (objects, references and malformed data refused)',
	function () {
		cl_ready();
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- building fixtures in Polylang's format.
		assert_same( array( 'locale' => 'ar', 'rtl' => 1 ), Polylang::decode( serialize( array( 'locale' => 'ar', 'rtl' => 1, 'fallbacks' => array( 'en' ) ) ) ), 'Nested arrays are skipped, scalars kept.' );
		assert_same( array( 'en' => 12, 'ar' => 34 ), Polylang::decode( serialize( array( 'en' => 12, 'ar' => 34 ) ) ) );
		assert_same( array( 'name' => 'العربية' ), Polylang::decode( serialize( array( 'name' => 'العربية' ) ) ), 'Lengths are bytes (UTF-8).' );
		// phpcs:enable
		foreach (
			array(
				'O:8:"stdClass":0:{}',
				'a:1:{s:1:"a";O:8:"stdClass":0:{}}',
				'a:1:{s:1:"a";C:11:"ArrayObject":21:{x:i:0;a:0:{};m:a:0:{}}}',
				'a:1:{s:1:"a";R:1;}',
				'a:1:{s:9:"a";i:1;}',
				'a:2:{s:1:"a";i:1;}',
				'a:1:{s:1:"a";i:1;}x',
				'a:1:{s:1:"a";a:1:{i:0;a:1:{i:0;a:1:{i:0;i:1;}}}}',
				str_repeat( 'a', 70000 ),
				'',
			) as $hostile
		) {
			assert_same( array(), Polylang::decode( $hostile ), 'Refused: ' . substr( $hostile, 0, 40 ) );
		}
		assert_true( wp_is_uuid( Polylang::group_uuid( 'post', 7 ), 4 ), 'Deterministic group IDs are valid UUID v4 strings.' );
		assert_same( Polylang::group_uuid( 'post', 7 ), Polylang::group_uuid( 'post', 7 ) );
		assert_false( Polylang::group_uuid( 'post', 7 ) === Polylang::group_uuid( 'term', 7 ) );
	}
);

cradle_test(
	'languages: Polylang CLI script refuses web requests and runs a read-only dry run',
	function () {
		cl_ready();
		$script = WP_PLUGIN_DIR . '/cradle-languages/bin/import-polylang.php';
		if ( ! is_file( $script ) ) {
			skip_test( 'CLI script missing in the sandbox.' );
		}
		list( $code, $body ) = cl_get( content_url( 'plugins/cradle-languages/bin/import-polylang.php?run=1' ) );
		assert_same( '', trim( $body ), 'A web request gets no output (exit before anything runs).' );
		$php = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
		exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $script ) . ' --json --wp=' . escapeshellarg( ABSPATH ) . ' 2>&1', $output, $exit ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$json = json_decode( implode( "\n", $output ), true );
		if ( ! is_array( $json ) ) {
			$json = json_decode( (string) implode( "\n", array_slice( $output, 0, -1 ) ), true );
		}
		assert_true( is_array( $json ) && array_key_exists( 'found', $json ), 'Prints the dry-run report: ' . substr( implode( "\n", $output ), 0, 300 ) );
		assert_true( in_array( $exit, array( 0, 3 ), true ), 'Exit 0 (no problems) or 3 (problems listed).' );
		unset( $code );
	}
);

/* ------------------------------------------------------------------------- */
/* Lifecycle                                                                 */
/* ------------------------------------------------------------------------- */

cradle_test(
	'languages: deactivation restores the original URLs; reactivation keeps languages and translations',
	function () {
		cl_ready();
		cl_configure();
		$file = 'cradle-languages/cradle-languages.php';
		try {
			$en = cl_post( array( 'post_title' => 'Lang deact English', 'post_name' => 'lang-deact-en' ), 'en' );
			$ar = cl_post( array( 'post_title' => 'Lang deact Arabic', 'post_name' => 'lang-deact-ar' ), 'ar' );
			Content::link_translations( array( 'en' => $en, 'ar' => $ar ) );
			list( $code ) = cl_get( '/ar/lang-deact-ar/' );
			assert_same( 200, $code );

			deactivate_plugins( $file );
			list( $code, , $location ) = cl_get( '/ar/lang-deact-ar/' );
			assert_true( 404 === $code || ( 301 === $code && false === strpos( $location, '/ar/' ) ), 'Without the plugin /ar/ is not routed (WordPress answers 404 or guesses the plain address).' );
			list( $code, $body ) = cl_get( '/lang-deact-ar/' );
			assert_same( 200, $code, 'Entries are served at their plain WordPress address.' );
			assert_not_contains( 'hreflang', $body );
			assert_not_contains( 'dir="rtl"', cl_html_tag( $body ) );
			assert_true( (bool) get_option( Config::OPTION ), 'Settings are kept.' );
		} finally {
			if ( ! is_plugin_active( $file ) ) {
				activate_plugin( $file );
			}
		}
		try {
			list( $code ) = cl_get( '/ar/lang-deact-ar/' );
			assert_same( 200, $code, 'Reactivated: routing is back.' );
			assert_same( array( 'en' => $en, 'ar' => $ar ), Content::translations( $en ), 'Translation groups were kept.' );
		} finally {
			cl_reset();
		}
	}
);

cradle_test(
	'languages: uninstall retains everything by default and purges only its own data under the purge policy',
	function () {
		cl_ready();
		$runner = CRADLE_TEST_ROOT . '/tests/fixtures/languages/run-uninstall.php';
		$php    = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
		cl_configure();
		$en  = cl_post( array( 'post_title' => 'Lang purge English' ), 'en' );
		$ar  = cl_post( array( 'post_title' => 'Lang purge Arabic' ), 'ar' );
		$cat = cl_term( 'Lang purge term' );
		Content::link_translations( array( 'en' => $en, 'ar' => $ar ) );
		$sentinel = get_option( 'blogname' );
		try {
			exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $runner ) . ' 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			assert_same( 0, $code, implode( "\n", $output ) );
			wp_cache_flush();
			assert_true( (bool) get_option( Config::OPTION ), 'Retain keeps the settings.' );
			assert_same( 'ar', Content::post_language_term( $ar ) );

			$policies              = get_option( 'cradle_uninstall_policy', array() );
			$policies              = is_array( $policies ) ? $policies : array();
			$policies['languages'] = 'purge';
			update_option( 'cradle_uninstall_policy', $policies, false );
			$output = array();
			exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $runner ) . ' 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			assert_same( 0, $code, implode( "\n", $output ) );
			wp_cache_flush();
			wp_roles()->for_site( get_current_blog_id() );
			global $wpdb;
			assert_false( get_option( Config::OPTION ), 'Purge removes the settings.' );
			assert_false( get_option( Lifecycle::VERSION_OPTION ) );
			assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'cradle_language' ) ), 'The language taxonomy is gone.' );
			assert_same( '', get_post_meta( $en, '_cradle_lang_group', true ), 'Group meta is gone.' );
			assert_same( '', get_term_meta( $cat, '_cradle_lang', true ), 'Term meta is gone.' );
			assert_true( (bool) get_post( $en ) && (bool) get_post( $ar ) && (bool) get_term( $cat ), 'Content itself is never deleted.' );
			assert_false( get_role( 'administrator' )->has_cap( 'cradle_manage_languages' ), 'The capability is revoked.' );
			assert_same( $sentinel, get_option( 'blogname' ) );
			$after = get_option( 'cradle_uninstall_policy', array() );
			assert_false( isset( $after['languages'] ), 'The consumed purge policy is cleared.' );
		} finally {
			wp_cache_flush();
			wp_roles()->for_site( get_current_blog_id() );
			$after = get_option( 'cradle_uninstall_policy', array() );
			if ( is_array( $after ) && isset( $after['languages'] ) ) {
				unset( $after['languages'] );
				update_option( 'cradle_uninstall_policy', $after, false );
			}
			get_role( 'administrator' )->add_cap( 'cradle_manage_languages' );
			$granted                            = get_option( 'cradle_granted_capabilities', array() );
			$granted                            = is_array( $granted ) ? $granted : array();
			$granted['cradle_manage_languages'] = 'languages';
			update_option( 'cradle_granted_capabilities', $granted, false );
			update_option( Lifecycle::VERSION_OPTION, CRADLE_LANGUAGES_VERSION, false );
			cl_reset();
		}
	}
);

cradle_test(
	'languages: core-missing guard and missing blocks dependency degrade safely (isolated processes)',
	function () {
		cl_ready();
		$runner = CRADLE_TEST_ROOT . '/tests/fixtures/languages/run-guard.php';
		$php    = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
		$run    = static function ( string $mode ) use ( $php, $runner ) {
			$output = array();
			exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $runner ) . ' ' . escapeshellarg( $mode ) . ' 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			$data = json_decode( (string) end( $output ), true );
			if ( 0 !== $code || ! is_array( $data ) ) {
				throw new Cradle_Test_Failure( "Guard runner ({$mode}) failed: " . substr( implode( "\n", $output ), 0, 500 ) );
			}
			return $data;
		};
		$no_core = $run( 'no-core' );
		assert_true( $no_core['loaded'] && ! $no_core['coreLoaded'], 'The plugin loads without core.' );
		assert_true( $no_core['notice'], 'Administrators see the core-missing notice.' );
		assert_false( $no_core['ready'] || $no_core['taxonomy'] || $no_core['blockRegistered'], 'Nothing runs without core.' );
		assert_true( $no_core['apiEmpty'], 'The public API returns empty values.' );
		assert_same( array(), $no_core['errors'] );

		$no_blocks = $run( 'no-blocks' );
		assert_same( 'failed', $no_blocks['state'], 'Not ready without the blocks module.' );
		assert_contains( 'blocks', implode( ' ', $no_blocks['problems'] ) );
		assert_false( $no_blocks['ready'] || $no_blocks['taxonomy'] );
		assert_same( array(), $no_blocks['errors'] );
	}
);

cradle_test(
	'languages: non-public types opt in with the cradle-languages post type support (default shared, or translatable on request)',
	function () {
		cl_ready();
		cl_configure();
		register_post_type( 'cl_private', array( 'public' => false, 'supports' => array( 'title', 'editor', 'cradle-languages' ) ) );
		register_post_type( 'cl_private_tr', array( 'public' => false, 'supports' => array( 'title' ) ) );
		add_post_type_support( 'cl_private_tr', 'cradle-languages', array( 'default' => 'translatable' ) );
		register_post_type( 'cl_private_no', array( 'public' => false ) );
		try {
			Config::flush();
			$eligible = Config::eligible_post_types();
			assert_true( isset( $eligible['cl_private'], $eligible['cl_private_tr'] ), 'Types with the support are configurable.' );
			assert_false( isset( $eligible['cl_private_no'] ), 'Other non-public types are not.' );
			assert_same( 'shared', Config::post_type_mode( 'cl_private' ), 'Default mode of a non-public type: shared.' );
			assert_same( 'translatable', Config::post_type_mode( 'cl_private_tr' ), 'The support can ask for translatable.' );
			assert_contains( 'cl_private_tr', implode( ',', Config::translatable_post_types() ) );

			cl_configure( array( 'post_types' => array( 'cl_private' => 'translatable' ) ) );
			assert_true( Content::is_translatable_post_type( 'cl_private' ), 'The site can switch it to translatable.' );
			$en = cl_post( array( 'post_type' => 'cl_private', 'post_title' => 'Private en' ), 'en' );
			$ar = cl_post( array( 'post_type' => 'cl_private', 'post_title' => 'Private ar' ), 'ar' );
			assert_false( is_wp_error( cradle_link_translations( array( 'en' => $en, 'ar' => $ar ) ) ), 'Entries of the type can be linked.' );
			assert_same( $ar, cradle_get_translation( $en, 'ar' ) );
		} finally {
			foreach ( array( 'cl_private', 'cl_private_tr', 'cl_private_no' ) as $type ) {
				unregister_post_type( $type );
			}
			cl_reset();
		}
	}
);

/* ------------------------------------------------------------------------- */
/* Shipped translations (languages/)                                         */
/* ------------------------------------------------------------------------- */

const CL_UI_PLUGIN = 'cradle-languages-test-ui-type.php';

/**
 * The shipped translation source: locale => lookup key ("context\4msgid") => entry.
 */
function cl_shipped_source(): array {
	$data = json_decode( (string) file_get_contents( WP_PLUGIN_DIR . '/cradle-languages/languages/cradle-languages.json' ), true );
	$out  = array();
	foreach ( array( 'de_DE', 'ar' ) as $locale ) {
		foreach ( $data[ $locale ]['entries'] as $row ) {
			$out[ $locale ][ ( isset( $row['context'] ) ? $row['context'] . "\4" : '' ) . $row['msgid'] ] = $row;
		}
	}
	return $out;
}

/**
 * Runs $fn with Cradle Translations' runtime overrides switched off (when it is active), so only the
 * plugin's own languages/ files can translate; the settings are restored afterwards.
 *
 * @return mixed
 */
function cl_without_overrides( callable $fn ) {
	$active = function_exists( 'cradle' ) && cradle() && cradle()->modules()->is_ready( 'translations' ) && cradle()->settings()->section( 'translations' );
	if ( ! $active ) {
		return $fn();
	}
	cl_admin();
	$saved  = get_option( 'cradle_translations_settings', null );
	$values = cradle()->settings()->values( 'translations' );
	cradle()->settings()->update( 'translations', array_merge( $values, array( 'runtime_enabled' => false ) ), false );
	try {
		return $fn();
	} finally {
		if ( null === $saved ) {
			delete_option( 'cradle_translations_settings' );
		} else {
			cradle()->settings()->update( 'translations', $values, false );
		}
		wp_cache_delete( 'cradle_translations_settings', 'options' );
	}
}

cradle_test(
	'languages: shipped translations cover every string the Cradle Translations extractor finds, valid in de_DE and ar',
	function () {
		cl_ready();
		if ( ! cradle()->modules()->is_ready( 'translations' ) || ! class_exists( '\\Cradle\\Translations\\Extractor' ) ) {
			skip_test( 'Cradle Translations is not active (its extractor and validator are needed).' );
		}
		$manifest = \Cradle\Translations\Extractor::build_manifest();
		$entries  = array_values( array_filter( $manifest['entries'], static fn( $e ) => 'cradle-languages' === $e['domain'] ) );
		assert_true( count( $entries ) > 150, 'Strings found: ' . count( $entries ) );
		$dir = WP_PLUGIN_DIR . '/cradle-languages/languages/';
		foreach ( array( 'de_DE', 'ar' ) as $locale ) {
			$file = include $dir . 'cradle-languages-' . $locale . '.l10n.php';
			assert_true( is_array( $file ) && is_array( $file['messages'] ?? null ), "{$locale} .l10n.php returns a translation array." );
			assert_same( $locale, $file['language'] );
			assert_contains( 'ar' === $locale ? 'nplurals=6;' : 'nplurals=2;', (string) $file['plural-forms'] );
			$missing = array();
			$invalid = array();
			foreach ( $entries as $e ) {
				$key = ( null !== $e['context'] ? $e['context'] . "\4" : '' ) . $e['msgid'];
				if ( ! isset( $file['messages'][ $key ] ) ) {
					$missing[] = $e['msgid'];
					continue;
				}
				$forms   = null !== $e['msgidPlural'] ? explode( "\0", $file['messages'][ $key ] ) : array( $file['messages'][ $key ] );
				$checked = \Cradle\Translations\Validator::check_translation( $e, $forms, $locale );
				if ( is_wp_error( $checked ) || ! $checked ) {
					$invalid[] = $e['msgid'] . ( is_wp_error( $checked ) ? ' — ' . $checked->get_error_message() : '' );
				}
				if ( $e['js'] ) {
					// Every script that uses the string ships it in its Jed file.
					foreach ( $e['references'] as $ref ) {
						if ( preg_match( '#^plugins/cradle-languages/(.+\.js):\d+$#', $ref, $m ) ) {
							$jed = json_decode( (string) file_get_contents( $dir . 'cradle-languages-' . $locale . '-' . md5( $m[1] ) . '.json' ), true );
							if ( ( $jed['locale_data']['messages'][ $key ] ?? null ) !== $forms ) {
								$missing[] = $e['msgid'] . ' (' . $m[1] . ')';
							}
						}
					}
				}
			}
			assert_same( array(), array_slice( $missing, 0, 20 ), "{$locale}: every string is shipped." );
			assert_same( array(), array_slice( $invalid, 0, 20 ), "{$locale}: every shipped translation is valid." );
		}
	}
);

cradle_test(
	'languages: generated translation files match languages/cradle-languages.json (generator rerun into a temporary folder; CLI only)',
	function () {
		cl_ready();
		$script = WP_PLUGIN_DIR . '/cradle-languages/bin/build-languages.php';
		$dir    = WP_PLUGIN_DIR . '/cradle-languages/languages';
		list( , $body ) = cl_get( content_url( 'plugins/cradle-languages/bin/build-languages.php' ) );
		assert_same( '', trim( $body ), 'A web request gets no output.' );
		$tmp = get_temp_dir() . 'cradle-languages-build-' . wp_generate_password( 8, false, false );
		wp_mkdir_p( $tmp );
		try {
			$php = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
			exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $script ) . ' --quiet --out=' . escapeshellarg( $tmp ) . ' 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			assert_same( 0, $code, 'Generator: ' . implode( "\n", $output ) );
			$built   = array_map( 'basename', (array) glob( $tmp . '/*' ) );
			$shipped = array_values( array_diff( array_map( 'basename', (array) glob( $dir . '/*' ) ), array( 'cradle-languages.json' ) ) );
			sort( $built );
			sort( $shipped );
			assert_same( $shipped, $built, 'The same files are generated.' );
			assert_true( count( $built ) >= 8, 'Two .l10n.php files plus Jed files for three scripts in two locales.' );
			foreach ( array( 'assets/js/panel.js', 'blocks/language-switcher/editor.js', 'blocks/language-section/editor.js' ) as $script_path ) {
				assert_true( in_array( 'cradle-languages-ar-' . md5( $script_path ) . '.json', $built, true ), "Jed file for {$script_path}." );
			}
			foreach ( $built as $name ) {
				assert_same( str_replace( "\r\n", "\n", (string) file_get_contents( $dir . '/' . $name ) ), (string) file_get_contents( $tmp . '/' . $name ), "{$name} matches the source." );
			}
			$output = array();
			exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $script ) . ' --check 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			assert_same( 0, $code, '--check: ' . implode( "\n", $output ) );
		} finally {
			foreach ( (array) glob( $tmp . '/*' ) as $file ) {
				unlink( $file );
			}
			rmdir( $tmp );
		}
	}
);

cradle_test(
	'languages: shipped translations load in switch_to_locale() (ar, de_DE), for editor scripts, for block metadata and for module labels',
	function () {
		cl_ready();
		$source = cl_shipped_source();
		foreach ( array( 'ar', 'de_DE' ) as $locale ) {
			if ( ! switch_to_locale( $locale ) ) {
				skip_test( "switch_to_locale( '{$locale}' ) is refused: no WordPress language pack and no Cradle Translations catalog offers it." );
			}
			try {
				$file  = get_translations_for_domain( 'cradle-languages' );
				$wrong = array();
				foreach ( $source[ $locale ] as $row ) {
					$context  = (string) ( $row['context'] ?? '' );
					$plural   = isset( $row['msgidPlural'] );
					$expected = $row['forms'][ $plural ? ( 'ar' === $locale ? 2 : 1 ) : 0 ];
					$native   = $plural ? $file->translate_plural( $row['msgid'], $row['msgidPlural'], 2, $context ) : $file->translate( $row['msgid'], $context );
					if ( $expected !== $native ) {
						$wrong[] = $row['msgid'];
					}
				}
				assert_same( array(), array_slice( $wrong, 0, 10 ), "{$locale}: the .l10n.php file is loaded and gives every shipped translation." );
				assert_same( $source[ $locale ]['Languages']['forms'][0], __( 'Languages', 'cradle-languages' ) );
				assert_same( $source[ $locale ][ "block title\4Language switcher" ]['forms'][0], translate_with_gettext_context( 'Language switcher', 'block title', 'cradle-languages' ), 'Block title context.' );
				foreach ( array( Cradle\Languages\Editor::PANEL_HANDLE, 'cradle-language-switcher-editor-script', 'cradle-language-section-editor-script' ) as $handle ) {
					$registered = wp_scripts()->registered[ $handle ] ?? null;
					assert_true( $registered && 'cradle-languages' === $registered->textdomain, "{$handle} has script translations." );
					assert_same( wp_normalize_path( WP_PLUGIN_DIR . '/cradle-languages/languages' ), wp_normalize_path( (string) $registered->translations_path ), "{$handle} points at languages/." );
					$jed = json_decode( (string) load_script_textdomain( $handle, 'cradle-languages', $registered->translations_path ), true );
					assert_same( $locale, $jed['locale_data']['messages']['']['lang'] ?? null, "{$handle}: Jed file of the locale loads." );
				}
				$jed = json_decode( (string) load_script_textdomain( 'cradle-language-switcher-editor-script', 'cradle-languages', wp_scripts()->registered['cradle-language-switcher-editor-script']->translations_path ), true );
				assert_same( array( $source[ $locale ]['Language switcher']['forms'][0] ), $jed['locale_data']['messages']['Language switcher'] ?? null );
				$name = $source[ $locale ][ "module name\4" . Cradle\Languages\Module::NAME ]['forms'][0];
				assert_same( $name, apply_filters( 'cradle/translate_label', Cradle\Languages\Module::NAME, 'module name', 'cradle' ), 'Module name label.' );
				if ( 'ar' === $locale ) {
					assert_false( Cradle\Languages\Module::NAME === $name, 'The Arabic module name is translated.' );
				}
				assert_same( 'Manage admin branding', Cradle\Languages\Module::translate_label( 'Manage admin branding', 'capability label', 'cradle' ), 'Labels of other modules are not touched by this plugin.' );
				assert_same( Cradle\Languages\Module::NAME, Cradle\Languages\Module::translate_label( Cradle\Languages\Module::NAME, 'module name', 'cradle-languages' ), 'Only the cradle domain is handled.' );
				assert_same( 'Already translated', Cradle\Languages\Module::translate_label( 'Already translated', 'module name', 'cradle' ), 'A label translated earlier (no longer the original) is kept.' );
			} finally {
				restore_previous_locale();
			}
		}
		assert_same( 'Languages', __( 'Languages', 'cradle-languages' ), 'Back to English after restore_previous_locale().' );
	}
);

cradle_test(
	'languages: without Cradle Translations the shipped files alone translate strings and labels, also inside switch_to_locale() (isolated process)',
	function () {
		cl_ready();
		$runner = CRADLE_TEST_ROOT . '/tests/fixtures/languages/run-guard.php';
		$php    = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
		$output = array();
		exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $runner ) . ' no-translations 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$data = json_decode( (string) end( $output ), true );
		assert_true( 0 === $code && is_array( $data ), 'Runner failed: ' . substr( implode( "\n", $output ), 0, 500 ) );
		$source = cl_shipped_source();
		assert_false( $data['translationsLoaded'], 'Cradle Translations is not loaded in the isolated process.' );
		assert_true( $data['ready'] );
		assert_same( 'ar', $data['locale'] );
		assert_true( $data['ar']['checked'] > 150 );
		assert_same( array(), $data['ar']['wrong'], 'Arabic: every shipped string.' );
		assert_same( $source['ar'][ "module name\4" . Cradle\Languages\Module::NAME ]['forms'][0], $data['arName'] );
		assert_same( $source['ar'][ "capability label\4" . Cradle\Languages\Module::CAP_LABEL ]['forms'][0], $data['arCapability'] );
		assert_same( 'Manage admin branding', $data['otherLabel'], 'Labels of other modules stay as they are.' );
		assert_true( $data['switched'], 'switch_to_locale( de_DE ) with the locale available.' );
		assert_same( array(), $data['de']['wrong'], 'German inside switch_to_locale(): every shipped string.' );
		assert_same( $source['de_DE'][ "capability label\4" . Cradle\Languages\Module::CAP_LABEL ]['forms'][0], $data['deCapability'] );
		assert_same( 'ar', $data['restored'] );
		assert_same( array(), $data['errors'] );
	}
);

cradle_test(
	'languages: an /ar/ page renders the switcher strings from the shipped Arabic file over HTTP (Cradle Translations overrides off); the editor prints the Arabic script translations',
	function () {
		cl_ready();
		cl_configure();
		$source = cl_shipped_source();
		try {
			$page = cl_post(
				array(
					'post_type'    => 'page',
					'post_title'   => 'Lang shipped strings ar',
					'post_name'    => 'lang-shipped-strings-ar',
					'post_content' => '<!-- wp:cradle/language-switcher /-->',
				),
				'ar'
			);
			$path = cl_path( get_permalink( $page ) );
			assert_contains( '/ar/', $path );
			cl_without_overrides(
				static function () use ( $path, $source ) {
					if ( cradle()->modules()->is_ready( 'translations' ) ) {
						assert_false( (bool) ( get_option( 'cradle_translations_settings' )['runtime_enabled'] ?? true ), 'Cradle Translations overrides are off for this request.' );
					}
					list( $code, $body ) = cl_get( $path );
					assert_same( 200, $code );
					assert_contains( 'dir="rtl"', cl_html_tag( $body ) );
					assert_contains( 'aria-label="' . esc_attr( $source['ar']['Languages']['forms'][0] ) . '"', $body, 'Switcher label in Arabic.' );
					assert_contains( esc_html( $source['ar']['(home page, this page is not translated)']['forms'][0] ), $body, 'Missing-translation note in Arabic.' );
				}
			);
			list( , $body ) = cl_get( $path );
			assert_contains( 'aria-label="' . esc_attr( $source['ar']['Languages']['forms'][0] ) . '"', $body, 'Same with Cradle Translations overrides on.' );

			// Block editor in Arabic: WordPress prints the shipped Jed data of the switcher block script.
			$admin = cl_admin();
			update_user_meta( $admin, 'locale', 'ar' );
			try {
				$expected = $source['ar']['Hide languages without a translation']['forms'][0];
				cl_without_overrides(
					static function () use ( $admin, $expected ) {
						list( $code, $editor ) = cl_get( admin_url( 'post-new.php?post_type=page' ), $admin );
						assert_same( 200, $code );
						assert_contains( 'cradle-language-switcher-editor-script', $editor, 'The switcher block script is printed in the editor.' );
						assert_contains( $expected, $editor, 'Its Arabic translations are printed with it.' );
					}
				);
			} finally {
				delete_user_meta( $admin, 'locale' );
			}
		} finally {
			cl_reset();
		}
	}
);

/* ------------------------------------------------------------------------- */
/* Polylang type mapping; non-public types with admin screens                */
/* ------------------------------------------------------------------------- */

cradle_test(
	'languages: Polylang import writes an explicit mode for every type and taxonomy (Polylang-translated = translatable, others shared) and warns about ones it cannot configure',
	function () {
		cl_ready();
		cl_reset();
		if ( Polylang::has_data() ) {
			skip_test( 'The sandbox already holds Polylang data; the fixture would mix with it.' );
		}
		register_post_type( 'cl_pll_pub', array( 'public' => true, 'label' => 'CL Pll public' ) );
		register_post_type( 'cl_pll_listed', array( 'public' => true, 'label' => 'CL Pll listed' ) );
		register_post_type( 'cl_pll_ui', array( 'public' => false, 'show_ui' => true, 'label' => 'CL Pll admin only' ) );
		register_taxonomy( 'cl_pll_tax', 'cl_pll_pub', array( 'public' => true, 'label' => 'CL Pll tax' ) );
		register_taxonomy( 'cl_pll_tax_listed', 'cl_pll_listed', array( 'public' => true, 'label' => 'CL Pll tax listed' ) );
		try {
			$en  = cl_post( array( 'post_title' => 'Pll map en' ) );
			$ar  = cl_post( array( 'post_title' => 'Pll map ar' ) );
			$pub = cl_post( array( 'post_title' => 'Pll map public', 'post_type' => 'cl_pll_pub' ) );
			cl_pll_install(
				array(
					'en'     => array( $en ),
					'ar'     => array( $ar ),
					'groups' => array( array( 'en' => $en, 'ar' => $ar ) ),
				),
				array(
					'en'     => array(),
					'ar'     => array(),
					'groups' => array(),
				)
			);
			$options               = get_option( 'polylang' );
			$options['post_types'] = array( 'cl_pll_listed', 'cl_pll_ui', 'cl_pll_gone' );
			$options['taxonomies'] = array( 'cl_pll_tax_listed', 'cl_pll_tax_gone' );
			update_option( 'polylang', $options, false );
			cl_fresh();

			$report = Polylang::dry_run();
			assert_same( array(), $report['problems'], wp_json_encode( $report['problems'] ) );
			$types = $report['postTypes'];
			assert_same( array_keys( Config::eligible_post_types() ), array_values( array_intersect( array_keys( Config::eligible_post_types() ), array_keys( $types ) ) ), 'Every configurable type is mapped.' );
			assert_same( array( 'translatable', 'translatable', 'translatable', 'translatable', 'shared', 'shared', 'shared', 'shared' ), array( $types['post'], $types['page'], $types['cl_pll_listed'], $types['cl_pll_ui'], $types['cl_pll_pub'], $types['attachment'], $types['wp_navigation'], $types['wp_block'] ) );
			if ( isset( $types['cradle_form'] ) ) {
				assert_same( 'translatable', $types['cradle_form'], 'A type declaring the cradle-languages support keeps its declared default.' );
			}
			assert_false( isset( $types['cl_pll_gone'] ), 'Unregistered types are not mapped.' );
			$taxonomies = $report['taxonomies'];
			assert_same( array( 'translatable', 'translatable', 'translatable', 'shared' ), array( $taxonomies['category'], $taxonomies['post_tag'], $taxonomies['cl_pll_tax_listed'], $taxonomies['cl_pll_tax'] ) );
			$warnings = implode( "\n", $report['warnings'] );
			assert_contains( '“cl_pll_gone”', $warnings, 'Warning for a translated type Cradle Languages cannot configure.' );
			assert_contains( '“cl_pll_tax_gone”', $warnings, 'Warning for a translated taxonomy it cannot configure.' );
			assert_not_contains( 'cl_pll_ui', $warnings );
			ob_start();
			Cradle\Languages\Admin\Admin_Page::render_report( $report );
			$html = (string) ob_get_clean();
			assert_contains( 'CL Pll public (cl_pll_pub)', $html, 'The dry run lists the shared types.' );
			assert_contains( 'CL Pll listed (cl_pll_listed)', $html );
			assert_contains( 'CL Pll tax (cl_pll_tax)', $html );

			cl_admin();
			$job = cl_run_job( Polylang::start( $report['fingerprint'] )['uuid'] );
			assert_same( 'completed', $job['state'], (string) $job['error_message'] );
			cl_fresh();
			$raw = Config::raw();
			foreach ( array_keys( Config::eligible_post_types() ) as $type ) {
				assert_true( isset( $raw['post_types'][ $type ] ), "Explicit mode stored for {$type}." );
			}
			assert_same( 'shared', Config::post_type_mode( 'cl_pll_pub' ), 'A public type Polylang did not translate is shared.' );
			assert_same( 'translatable', Config::post_type_mode( 'cl_pll_listed' ) );
			assert_same( 'translatable', Config::post_type_mode( 'cl_pll_ui' ) );
			assert_same( 'translatable', Config::post_type_mode( 'post' ) );
			assert_same( 'translatable', $raw['post_types']['cl_pll_gone'] ?? null, 'Polylang-translated types that are not registered are kept for later.' );
			assert_same( 'shared', $raw['taxonomies']['cl_pll_tax'] ?? null );
			assert_same( 'translatable', $raw['taxonomies']['cl_pll_tax_listed'] ?? null );
			assert_false( Content::is_translatable_taxonomy( 'cl_pll_tax' ) );
			assert_false( Content::is_translatable_post_type( 'cl_pll_pub' ) );
			assert_same( null, cradle_language_of( $pub ), 'Entries of shared types get no language.' );
			assert_same( 'ar', Content::post_language_slug( $ar ) );
		} finally {
			cl_pll_cleanup();
			unregister_taxonomy( 'cl_pll_tax' );
			unregister_taxonomy( 'cl_pll_tax_listed' );
			foreach ( array( 'cl_pll_pub', 'cl_pll_listed', 'cl_pll_ui' ) as $type ) {
				unregister_post_type( $type );
			}
			cl_reset();
		}
	}
);

cradle_test(
	'languages: custom types that are not public but have admin screens are configurable (default shared) and filtered on /ar/ lists once translatable',
	function () {
		cl_ready();
		register_post_type( 'cl_ui_type', array( 'public' => false, 'show_ui' => true, 'label' => 'CL UI entries', 'supports' => array( 'title' ) ) );
		register_post_type( 'cl_ui_hidden', array( 'public' => false, 'show_ui' => false ) );
		$plugin = WP_CONTENT_DIR . '/mu-plugins/' . CL_UI_PLUGIN;
		try {
			cl_configure();
			$eligible = Config::eligible_post_types();
			assert_true( isset( $eligible['cl_ui_type'] ), 'A non-public type with admin screens is configurable.' );
			assert_false( isset( $eligible['cl_ui_hidden'] ), 'A non-public type without admin screens is not.' );
			assert_false( isset( $eligible['wp_template'] ) || isset( $eligible['wp_global_styles'] ) || isset( $eligible['customize_changeset'] ), 'Built-in internal types stay excluded.' );
			assert_same( 'shared', Config::post_type_mode( 'cl_ui_type' ), 'Its default mode is shared.' );
			assert_same( 'shared', Config::post_type_mode( 'wp_navigation' ) );

			wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
			copy( CRADLE_TEST_ROOT . '/tests/fixtures/languages/ui-type.php', $plugin );
			$en       = cl_post( array( 'post_type' => 'cl_ui_type', 'post_title' => 'CL UI English' ) );
			$ar       = cl_post( array( 'post_type' => 'cl_ui_type', 'post_title' => 'CL UI Arabic' ) );
			$untagged = cl_post( array( 'post_type' => 'cl_ui_type', 'post_title' => 'CL UI untagged' ) );
			list( , $body ) = cl_get( '/ar/?cl_ui_list=1' );
			$all = array( 'CL UI Arabic', 'CL UI English', 'CL UI untagged' );
			assert_same( $all, array_values( array_intersect( (array) json_decode( $body, true ), $all ) ), 'Shared: every entry in every language.' );

			cl_configure( array( 'post_types' => array( 'cl_ui_type' => 'translatable' ) ) );
			assert_true( Content::is_translatable_post_type( 'cl_ui_type' ), 'The site can set it translatable.' );
			cl_ok_lang( Content::set_language( $en, 'en' ) );
			cl_ok_lang( Content::set_language( $ar, 'ar' ) );
			wp_delete_object_term_relationships( $untagged, Content::TAXONOMY );
			cl_refresh( $en, $ar, $untagged );
			list( , $body ) = cl_get( '/ar/?cl_ui_list=1' );
			assert_same( array( 'CL UI Arabic' ), array_values( array_intersect( (array) json_decode( $body, true ), $all ) ), 'Translatable: /ar/ lists only the Arabic entry.' );
			list( , $body ) = cl_get( '/?cl_ui_list=1' );
			assert_same( array( 'CL UI English', 'CL UI untagged' ), array_values( array_intersect( (array) json_decode( $body, true ), $all ) ), 'The default language lists its entries and untagged ones.' );
		} finally {
			if ( is_file( $plugin ) ) {
				unlink( $plugin );
			}
			unregister_post_type( 'cl_ui_type' );
			unregister_post_type( 'cl_ui_hidden' );
			cl_reset();
		}
	}
);

/** Fails the test when a Content call returned a WP_Error. */
function cl_ok_lang( $result ) {
	if ( is_wp_error( $result ) ) {
		throw new Cradle_Test_Failure( $result->get_error_message() );
	}
	return $result;
}

cradle_test(
	'languages: cleanup — fixtures, Polylang-shaped rows, test users and settings removed (runs last)',
	function () {
		global $wpdb;
		cl_cleanup();
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", '_cradle_test_fixture', 'languages' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		assert_same( 0, $left, 'No fixture entries are left.' );
		$users = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE %s", $wpdb->esc_like( CL_USER_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		assert_same( 0, $users, 'No test users are left.' );
		assert_false( Languages::is_multilingual(), 'Settings are back to the site language only.' );
	}
);
