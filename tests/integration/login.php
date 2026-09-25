<?php
/**
 * Cradle Login (northstar §10, CR-13): settings, permissions, health check, the native
 * login flow matrix over real HTTP with routing enabled, recovery (constant, filter,
 * deactivation) and the disabled-by-default state.
 *
 * Fixtures (users, posts, a privacy request) are created with unique names and removed
 * by the final test; a shutdown handler turns routing off even if the run aborts.
 * No email is sent: flows that would email (lost password for a real account, full
 * password reset, privacy confirmation notification) are exercised without triggering it.
 */

use Cradle\Login\Config;
use Cradle\Login\Health_Check;
use Cradle\Login\Module;
use Cradle\Login\Router;
use Cradle\Login\Slug_Policy;

$GLOBALS['cradle_login_fx'] = array(
	'slug'     => 'cl-route-' . strtolower( wp_generate_password( 8, false ) ),
	'slug2'    => 'cl-moved-' . strtolower( wp_generate_password( 8, false ) ),
	'users'    => array(),
	'posts'    => array(),
	'enabled'  => false,
	'password' => '',
);

register_shutdown_function(
	static function () {
		if ( ! empty( $GLOBALS['cradle_login_fx']['enabled'] ) && function_exists( 'cradle' ) && cradle() && cradle()->settings()->section( 'login' ) ) {
			cradle()->settings()->reset( 'login' );
			echo "NOTE  login: shutdown handler reset login routing to defaults.\n";
		}
	}
);

function cradle_login_t_refresh() {
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	foreach ( array( Config::OPTION, Config::HEALTH_OPTION, 'users_can_register', 'active_plugins' ) as $option ) {
		wp_cache_delete( $option, 'options' );
	}
	if ( class_exists( Router::class ) ) {
		Router::instance()->refresh();
	}
}

function cradle_login_t_admin(): int {
	return cradle_test_user( 'administrator' );
}

/**
 * Runs $fn as the test administrator (in-process).
 */
function cradle_login_t_as( int $user_id, callable $fn ) {
	$previous = get_current_user_id();
	wp_set_current_user( $user_id );
	try {
		return $fn();
	} finally {
		wp_set_current_user( $previous );
	}
}

/**
 * Submits the login settings form through options.php as $user_id.
 */
function cradle_login_t_options_post( int $user_id, array $session, array $fields ) {
	cradle_login_t_refresh();
	$nonce               = cradle_test_nonce( $user_id, $session, 'cradle_login-options' );
	$fields['_revision'] = cradle()->settings()->revision( 'login' );
	$response            = cradle_test_http(
		'POST',
		admin_url( 'options.php' ),
		array(
			'body' => array(
				'option_page'      => 'cradle_login',
				'action'           => 'update',
				'_wpnonce'         => $nonce,
				'_wp_http_referer' => '/cradle/wp/wp-admin/admin.php?page=cradle-login',
				Config::OPTION     => $fields,
			),
		),
		$user_id,
		$session
	);
	cradle_login_t_refresh();
	return $response;
}

function cradle_login_t_code( $response ): int {
	return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
}

function cradle_login_t_body( $response ): string {
	return is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
}

function cradle_login_t_location( $response ): string {
	$location = is_wp_error( $response ) ? '' : wp_remote_retrieve_header( $response, 'location' );
	return is_array( $location ) ? (string) end( $location ) : (string) $location;
}

/**
 * First cookie in a response whose name starts with $prefix (and is not a deletion).
 */
function cradle_login_t_cookie( $response, string $prefix ) {
	foreach ( is_wp_error( $response ) ? array() : wp_remote_retrieve_cookies( $response ) as $cookie ) {
		if ( 0 === strpos( $cookie->name, $prefix ) && '' !== trim( (string) $cookie->value ) && ( empty( $cookie->expires ) || $cookie->expires > time() ) ) {
			return $cookie;
		}
	}
	return null;
}

function cradle_login_t_anon( string $method, string $url, array $args = array() ) {
	return cradle_test_http( $method, $url, $args );
}

function cradle_login_t_route( string $query = '' ): string {
	return Config::route_url( $GLOBALS['cradle_login_fx']['slug'] ) . $query;
}

/**
 * WordPress' own wp-login.php URL (built without the site_url filter, which rewrites
 * it to the route in this process while routing is active).
 */
function cradle_login_t_native( string $query = '' ): string {
	return Config::site() . '/wp-login.php' . $query;
}

/**
 * Creates a disposable user with a random password (kept only in memory).
 */
function cradle_login_t_fixture_user( string $role, string $password = '' ): int {
	$login = 'cradle_login_fx_' . strtolower( wp_generate_password( 8, false ) );
	$id    = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => '' !== $password ? $password : wp_generate_password( 32 ),
			'user_email' => $login . '@example.test',
			'role'       => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new Cradle_Test_Failure( 'Could not create fixture user: ' . $id->get_error_message() );
	}
	$GLOBALS['cradle_login_fx']['users'][] = (int) $id;
	return (int) $id;
}

function cradle_login_t_require_enabled() {
	cradle_login_t_refresh();
	if ( 'active' !== Config::status() ) {
		throw new Cradle_Test_Failure( 'Routing is not active; the enabling test must pass first.' );
	}
}

/* ---------------------------------------------------------------------- */

cradle_test(
	'login: module ready, capability limited to administrators, defaults off',
	function () {
		cradle_login_t_refresh();
		assert_true( is_plugin_active( 'cradle-login/cradle-login.php' ), 'cradle-login must be active in the sandbox (php scripts/sandbox/activate.php plugin cradle-login).' );
		assert_same( 'ready', cradle()->modules()->state( 'login' ) );
		assert_true( null !== cradle()->settings()->section( 'login' ), 'Settings section "login" is registered.' );
		$defaults = cradle()->settings()->defaults( 'login' );
		assert_same( false, $defaults['enabled'], 'Routing is off by default.' );
		assert_same( '', $defaults['slug'] );
		assert_same( 'not_found', $defaults['blocked_response'], 'Generic 404 is the default safe response.' );
		assert_same( Config::defaults(), array_intersect_key( $defaults, Config::defaults() ) );

		assert_true( user_can( cradle_login_t_admin(), Module::CAP ), 'Administrators hold cradle_manage_login.' );
		foreach ( array( 'editor', 'author', 'subscriber' ) as $role ) {
			assert_false( user_can( cradle_test_user( $role ), Module::CAP ), "Role {$role} must not hold cradle_manage_login." );
		}
		$granted = get_option( 'cradle_granted_capabilities', array() );
		assert_true( isset( $granted[ Module::CAP ] ), 'The one-time grant is recorded.' );
	}
);

cradle_test(
	'login: disabled by default — native wp-login.php and /wp-admin/ behave normally',
	function () {
		cradle_login_t_as( cradle_login_t_admin(), fn() => cradle()->settings()->reset( 'login' ) );
		cradle_login_t_refresh();
		assert_same( 'disabled', Config::status() );
		assert_false( Router::instance()->is_active() );
		assert_same( Config::native_login_url(), wp_login_url(), 'wp_login_url() is native when disabled.' );

		$login = cradle_login_t_anon( 'GET', cradle_login_t_native( '' ) );
		assert_same( 200, cradle_login_t_code( $login ) );
		assert_contains( 'id="loginform"', cradle_login_t_body( $login ) );

		$admin = cradle_login_t_anon( 'GET', admin_url( '/' ) );
		assert_same( 302, cradle_login_t_code( $admin ) );
		assert_contains( 'wp-login.php', cradle_login_t_location( $admin ), 'Native auth_redirect to wp-login.php.' );

		$route = cradle_login_t_anon( 'GET', cradle_login_t_route() );
		assert_same( 404, cradle_login_t_code( $route ), 'An unconfigured slug is not routed.' );
		assert_not_contains( 'id="loginform"', cradle_login_t_body( $route ) );
	}
);

cradle_test(
	'login: slug validation rejects collisions and invalid formats',
	function () {
		$page = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Cradle login fixture page',
				'post_name'   => 'cl-page-' . strtolower( wp_generate_password( 6, false ) ),
			)
		);
		assert_true( is_int( $page ) && $page > 0 );
		$GLOBALS['cradle_login_fx']['posts'][] = $page;
		$page_slug                             = get_post_field( 'post_name', $page );

		$reserved = array( 'admin', 'login', 'dashboard', 'wp-admin', 'wp-json', 'wp-login', 'wp-content', 'feed', 'rss2', 'atom', 'sitemap', 'wp-sitemap', 'search', 'author', 'embed', 'category', 'page', 'preview', 'register', 'logout', 'xmlrpc', 'robots', 'favicon', 'comments' );
		foreach ( $reserved as $slug ) {
			assert_wp_error( Slug_Policy::check( $slug ), '', "Reserved slug {$slug} must be rejected." );
		}
		assert_wp_error( Slug_Policy::check( $page_slug ), 'cradle_login_slug_content', 'Existing page slug must be rejected.' );
		assert_wp_error( Slug_Policy::check( 'hello-world' === get_post_field( 'post_name', 1 ) ? 'hello-world' : $page_slug ), '', 'Existing post slug must be rejected.' );

		foreach ( array( 'Bad Slug', 'ab', '-lead', 'trail-', 'under_score', 'dot.php', 'UPPER', str_repeat( 'a', 65 ), '' ) as $slug ) {
			assert_wp_error( Slug_Policy::check( $slug ), 'cradle_login_slug_format', 'Invalid format: ' . $slug );
		}

		// REST prefix follows the site's configured prefix.
		$prefix = static fn() => 'cl-api-gateway';
		add_filter( 'rest_url_prefix', $prefix );
		$rest = Slug_Policy::check( 'cl-api-gateway' );
		remove_filter( 'rest_url_prefix', $prefix );
		assert_wp_error( $rest, 'cradle_login_slug_reserved', 'The REST prefix must be rejected.' );

		// Rewrite-rule prefixes (post type / taxonomy bases, endpoints) and extension filter.
		assert_true( in_array( 'wp-json', Slug_Policy::rewrite_prefixes( $GLOBALS['wp_rewrite'] ), true ), 'Rewrite prefixes are detected.' );
		$extra = static fn( $list ) => $list + array( 'cl-shop-account' => 'a test integration' );
		add_filter( 'cradle_login_reserved_slugs', $extra );
		$extended = Slug_Policy::check( 'cl-shop-account' );
		remove_filter( 'cradle_login_reserved_slugs', $extra );
		assert_wp_error( $extended, 'cradle_login_slug_reserved' );

		assert_true( Slug_Policy::path_exists( 'wp-content' ), 'Real directories in the site root are detected.' );
		assert_true( true === Slug_Policy::check( $GLOBALS['cradle_login_fx']['slug'] ), 'A fresh unique slug is accepted.' );

		// Through the Settings service: the colliding slug is refused, nothing else changes.
		$result = cradle_login_t_as( cradle_login_t_admin(), fn() => cradle()->settings()->update( 'login', array( 'slug' => $page_slug ) ) );
		assert_true( isset( $result['errors']['slug'] ), 'Settings update reports the slug collision.' );
		cradle_login_t_refresh();
		assert_same( '', Config::values()['slug'] );

		// Through options.php as an administrator.
		$admin    = cradle_login_t_admin();
		$session  = cradle_test_cookies( $admin );
		$response = cradle_login_t_options_post( $admin, $session, array( 'enabled' => '0', 'slug' => 'wp-admin', 'blocked_response' => 'not_found', 'recovery_note' => '' ) );
		assert_same( 302, cradle_login_t_code( $response ) );
		assert_same( '', Config::values()['slug'], 'options.php refuses a reserved slug.' );

		if ( class_exists( 'WooCommerce' ) ) {
			$endpoints = Slug_Policy::woocommerce_endpoints();
			assert_true( in_array( 'orders', $endpoints, true ) || count( $endpoints ) > 0, 'WooCommerce endpoints are reserved.' );
		}
	}
);

cradle_test(
	'login: editors and subscribers cannot change routing, open the screen or run the health check',
	function () {
		$editor = cradle_test_user( 'editor' );
		$result = cradle_login_t_as( $editor, fn() => cradle()->settings()->update( 'login', array( 'enabled' => true, 'slug' => $GLOBALS['cradle_login_fx']['slug'] ) ) );
		assert_wp_error( $result, 'cradle_forbidden' );

		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			$user     = cradle_test_user( $role );
			$session  = cradle_test_cookies( $user );
			$response = cradle_login_t_options_post( $user, $session, array( 'enabled' => '1', 'slug' => $GLOBALS['cradle_login_fx']['slug'], 'blocked_response' => 'redirect_home' ) );
			assert_same( 403, cradle_login_t_code( $response ), "options.php must refuse the {$role}." );
			assert_same( false, Config::values()['enabled'] );
			assert_same( 'not_found', Config::values()['blocked_response'] );

			$page = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-login' ), array(), $user, $session );
			assert_same( 403, cradle_login_t_code( $page ), "The Login screen must refuse the {$role}." );

			$nonce = cradle_test_nonce( $user, $session, 'cradle_login_health_check' );
			$check = cradle_test_http(
				'POST',
				admin_url( 'admin-post.php' ),
				array(
					'body' => array(
						'action'   => 'cradle_login_health_check',
						'_wpnonce' => $nonce,
						'slug'     => $GLOBALS['cradle_login_fx']['slug'],
					),
				),
				$user,
				$session
			);
			assert_same( 403, cradle_login_t_code( $check ), "The health check must refuse the {$role}." );
		}

		// Administrator without a nonce is refused; with a nonce the test runs and nothing is saved.
		$admin   = cradle_login_t_admin();
		$session = cradle_test_cookies( $admin );
		$check   = cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array( 'action' => 'cradle_login_health_check', 'slug' => $GLOBALS['cradle_login_fx']['slug'] ) ), $admin, $session );
		assert_same( 403, cradle_login_t_code( $check ), 'Missing nonce must be refused.' );
		$nonce = cradle_test_nonce( $admin, $session, 'cradle_login_health_check' );
		$check = cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array( 'action' => 'cradle_login_health_check', '_wpnonce' => $nonce, 'slug' => $GLOBALS['cradle_login_fx']['slug'] ) ), $admin, $session );
		assert_same( 302, cradle_login_t_code( $check ) );
		wp_cache_delete( '_transient_cradle_login_check_' . $admin, 'options' );
		wp_cache_delete( '_transient_timeout_cradle_login_check_' . $admin, 'options' );
		$stored = get_transient( 'cradle_login_check_' . $admin );
		assert_true( is_array( $stored ) && true === $stored['ok'], 'Manual health check of a fresh slug passes: ' . wp_json_encode( $stored ) );
		cradle_login_t_refresh();
		assert_same( 'disabled', Config::status(), 'A manual test saves nothing.' );
		delete_transient( 'cradle_login_check_' . $admin );
	}
);

cradle_test(
	'login: enabling is refused when the pre-enable health check fails',
	function () {
		$admin = cradle_login_t_admin();
		$slug  = $GLOBALS['cradle_login_fx']['slug'];
		$fakes = array(
			'transport error'        => new WP_Error( 'http_request_failed', 'simulated loopback failure' ),
			'404'                    => array( 'headers' => array(), 'body' => 'nope', 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'cookies' => array(), 'filename' => null ),
			'200 without login form' => array( 'headers' => array( Health_Check::HEADER => 'ok' ), 'body' => '<html>cached page</html>', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null ),
			'not served by plugin'   => array( 'headers' => array(), 'body' => '<form id="loginform"></form>', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null ),
		);
		foreach ( $fakes as $label => $fake ) {
			$filter = static function ( $pre, $args, $url ) use ( $slug, $fake ) {
				return false !== strpos( (string) $url, '/' . $slug . '/' ) ? $fake : $pre;
			};
			add_filter( 'pre_http_request', $filter, 10, 3 );
			try {
				$result = cradle_login_t_as( $admin, fn() => cradle()->settings()->update( 'login', array( 'enabled' => true, 'slug' => $slug ) ) );
			} finally {
				remove_filter( 'pre_http_request', $filter, 10 );
			}
			cradle_login_t_refresh();
			assert_true( isset( $result['errors']['enabled'] ), "Enabling must be refused ({$label})." );
			assert_same( 'disabled', Config::status(), "Routing stays off ({$label})." );
			assert_same( Config::native_login_url(), wp_login_url(), "Login URL stays native ({$label})." );
			assert_same( false, Health_Check::last()['ok'] ?? null, "Failure is recorded ({$label})." );
		}
		assert_same( $slug, Config::values()['slug'], 'The valid slug itself was saved.' );

		// Enabling without any slug is refused.
		cradle_login_t_as( $admin, fn() => cradle()->settings()->reset( 'login' ) );
		$result = cradle_login_t_as( $admin, fn() => cradle()->settings()->update( 'login', array( 'enabled' => true ) ) );
		assert_true( isset( $result['errors']['enabled'] ), 'Enabling without a slug is refused.' );

		// A form submission with an invalid new slug never enables the previously saved slug.
		cradle_login_t_as( $admin, fn() => cradle()->settings()->update( 'login', array( 'slug' => $slug ) ) );
		$session  = cradle_test_cookies( $admin );
		$response = cradle_login_t_options_post( $admin, $session, array( 'enabled' => '1', 'slug' => 'Not A Slug', 'blocked_response' => 'not_found', 'recovery_note' => '' ) );
		assert_same( 302, cradle_login_t_code( $response ) );
		assert_same( 'disabled', Config::status(), 'Invalid submitted slug must not enable the old slug.' );
		assert_same( $slug, Config::values()['slug'] );
	}
);

cradle_test(
	'login: administrator enables the route via options.php; health check passes; session is preserved',
	function () {
		$admin   = cradle_login_t_admin();
		$session = cradle_test_cookies( $admin );
		$slug    = $GLOBALS['cradle_login_fx']['slug'];

		$GLOBALS['cradle_login_fx']['enabled'] = true;
		$response                              = cradle_login_t_options_post( $admin, $session, array( 'enabled' => '1', 'slug' => $slug, 'blocked_response' => 'not_found', 'recovery_note' => 'Route recorded in the test runbook.' ) );
		assert_same( 302, cradle_login_t_code( $response ), 'options.php redirects back after saving.' );
		assert_same( 'active', Config::status(), 'Routing is active after a passing health check.' );
		assert_same( $slug, Config::values()['slug'] );
		$health = Health_Check::last();
		assert_true( true === ( $health['ok'] ?? null ) && 'enable' === ( $health['context'] ?? '' ), 'The enabling health check passed: ' . wp_json_encode( $health ) );
		assert_same( Config::route_url( $slug ), wp_login_url(), 'wp_login_url() now points at the route.' );

		$dashboard = cradle_test_http( 'GET', admin_url( '/' ), array(), $admin, $session );
		assert_same( 200, cradle_login_t_code( $dashboard ), 'The same administrator session still opens /wp-admin/.' );

		$screen = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-login' ), array(), $admin, $session );
		$body   = cradle_login_t_body( $screen );
		assert_same( 200, cradle_login_t_code( $screen ) );
		assert_contains( 'Effective login URL', $body );
		assert_contains( esc_html( Config::route_url( $slug ) ), $body, 'The screen shows the effective login URL.' );
		assert_contains( Config::CONSTANT, $body, 'The screen documents the recovery constant.' );
		assert_contains( 'Compatibility diagnostics', $body );
		assert_contains( 'Route recorded in the test runbook.', $body, 'The recovery note is shown to the administrator.' );
	}
);

cradle_test(
	'login: generated URLs point at the route; other URLs are untouched',
	function () {
		cradle_login_t_require_enabled();
		$route = cradle_login_t_route();
		assert_same( $route, wp_login_url() );
		assert_same( $route . '?redirect_to=' . rawurlencode( admin_url() ), wp_login_url( admin_url() ) );
		assert_same( 0, strpos( wp_logout_url(), $route . '?action=logout' ) );
		assert_same( $route . '?action=lostpassword', wp_lostpassword_url() );
		assert_same( $route . '?action=register', wp_registration_url() );
		assert_same( $route . '?action=postpass', site_url( 'wp-login.php?action=postpass', 'login_post' ) );
		assert_same( $route . '?action=rp&key=k&login=u', network_site_url( 'wp-login.php?action=rp&key=k&login=u', 'login' ) );
		assert_same( Config::route_path( $GLOBALS['cradle_login_fx']['slug'] ), site_url( 'wp-login.php', 'relative' ) );
		assert_same( $route . '?checkemail=confirm', apply_filters( 'wp_redirect', 'wp-login.php?checkemail=confirm', 302 ), 'Relative core redirects are rewritten.' );
		assert_same( $route . '?action=lostpassword&error=invalidkey', apply_filters( 'wp_redirect', cradle_login_t_native( '?action=lostpassword&error=invalidkey' ), 302 ) );
		assert_same( $route, Router::instance()->rewrite( $route ), 'Rewriting is idempotent.' );
		assert_same( 'https://example.org/wp-login.php?x=1', apply_filters( 'wp_redirect', 'https://example.org/wp-login.php?x=1', 302 ), 'Foreign hosts are untouched.' );
		assert_same( admin_url( 'profile.php' ), apply_filters( 'wp_redirect', admin_url( 'profile.php' ), 302 ) );
		assert_same( home_url( '/' ), home_url( '/' ) );

		// Subdirectory install + permalink modes (plain permalinks are simulated in-process only;
		// switching the shared sandbox's permalinks would disturb concurrent work).
		$slug = $GLOBALS['cradle_login_fx']['slug'];
		assert_same( Config::home_path() . '/' . $slug . '/', Config::route_path( $slug ) );
		assert_true( Config::request_matches( Config::home_path() . '/' . $slug . '/?action=lostpassword', $slug ) );
		assert_true( Config::request_matches( Config::home_path() . '/index.php/' . $slug . '/', $slug ) );
		assert_true( Config::request_matches( Config::home_path() . '//' . $slug, $slug ) );
		assert_false( Config::request_matches( '/' . $slug . '/', $slug ) || '' === Config::home_path(), 'Paths outside the home path are not routed.' );
		assert_false( Config::request_matches( Config::home_path() . '/' . $slug . '/extra/', $slug ) );
		assert_false( Config::request_matches( Config::home_path() . '/' . strtoupper( $slug ) . '/', $slug ) );
		$plain = static fn() => '';
		add_filter( 'pre_option_permalink_structure', $plain );
		try {
			assert_same( Config::home() . '/index.php/' . $slug . '/', Config::route_url( $slug ), 'Plain permalinks use /index.php/<slug>/.' );
			assert_same( Config::home() . '/index.php/' . $slug . '/', wp_login_url() );
		} finally {
			remove_filter( 'pre_option_permalink_structure', $plain );
		}

		$begin = apply_filters( 'recovery_mode_begin_url', add_query_arg( array( 'action' => 'enter_recovery_mode', 'rm_token' => 't', 'rm_key' => 'k' ), wp_login_url() ), 't', 'k' );
		assert_same( 0, strpos( $begin, Config::native_login_url() . '?' ), 'Recovery-mode begin links address wp-login.php (validated before plugins load).' );
	}
);

cradle_test(
	'login: route serves the native forms; native entry points answer 404 without revealing the route',
	function () {
		cradle_login_t_require_enabled();
		$slug  = $GLOBALS['cradle_login_fx']['slug'];
		$route = cradle_login_t_route();

		$form = cradle_login_t_anon( 'GET', $route );
		$body = cradle_login_t_body( $form );
		assert_same( 200, cradle_login_t_code( $form ) );
		assert_contains( 'id="loginform"', $body );
		assert_contains( 'action="' . esc_url( $route ) . '"', $body, 'The login form posts to the route.' );
		assert_contains( esc_url( $route . '?action=lostpassword' ), $body, 'Lost-password link uses the route.' );
		assert_not_contains( 'wp-login.php', $body, 'No link to wp-login.php on the route.' );

		foreach ( array( Config::home() . '/' . $slug, Config::home() . '/index.php/' . $slug . '/' ) as $variant ) {
			$response = cradle_login_t_anon( 'GET', $variant );
			assert_same( 200, cradle_login_t_code( $response ), 'Route variant ' . $variant );
			assert_contains( 'id="loginform"', cradle_login_t_body( $response ) );
		}

		$concealed = array(
			cradle_login_t_native( '' ),
			cradle_login_t_native( '?action=lostpassword' ),
			cradle_login_t_native( '?action=register' ),
			cradle_login_t_native( '?action=rp&key=invalid&login=nobody' ),
			cradle_login_t_native( '?action=confirmaction&request_id=1&confirm_key=invalid' ),
			cradle_login_t_native( '?action=entered_recovery_mode' ),
			cradle_login_t_native( '?interim-login=1' ),
			admin_url( '/' ),
			admin_url( 'profile.php' ),
			admin_url( 'options-general.php' ),
			admin_url( 'authorize-application.php?app_name=x' ),
			home_url( '/login' ),
			home_url( '/admin' ),
			home_url( '/dashboard' ),
			home_url( '/wp-login' ),
			site_url( 'wp-signup.php' ),
		);
		foreach ( $concealed as $url ) {
			$response = cradle_login_t_anon( 'GET', $url );
			assert_same( 404, cradle_login_t_code( $response ), 'Signed-out ' . $url );
			assert_not_contains( $slug, cradle_login_t_body( $response ) . cradle_login_t_location( $response ), 'Response must not reveal the route: ' . $url );
			assert_not_contains( 'id="loginform"', cradle_login_t_body( $response ) );
		}
		// wp-activate.php loads no plugins (WP_INSTALLING); it redirects to the native, concealed wp-login.php.
		$activate = cradle_login_t_anon( 'GET', site_url( 'wp-activate.php' ) );
		assert_not_contains( $slug, cradle_login_t_body( $activate ) . cradle_login_t_location( $activate ), 'wp-activate.php must not reveal the route.' );
		if ( 302 === cradle_login_t_code( $activate ) ) {
			assert_same( 404, cradle_login_t_code( cradle_login_t_anon( 'GET', cradle_login_t_location( $activate ) ) ) );
		}

		$not_found = cradle_login_t_body( cradle_login_t_anon( 'GET', admin_url( '/' ) ) );
		assert_contains( 'error404', $not_found, 'The theme 404 template is used for /wp-admin/.' );
		assert_not_contains( 'wpadminbar', $not_found );

		// A probe header with an unknown token routes nothing.
		$probe = cradle_login_t_anon( 'GET', Config::route_url( $GLOBALS['cradle_login_fx']['slug2'] ), array( 'headers' => array( Health_Check::HEADER => wp_generate_password( 40, false ) ) ) );
		assert_same( 404, cradle_login_t_code( $probe ), 'Unknown probe tokens are ignored.' );
	}
);

cradle_test(
	'login: admin-ajax, admin-post, REST (anonymous and cookie auth), XML-RPC and cron stay reachable',
	function () {
		cradle_login_t_require_enabled();
		$ajax = cradle_login_t_anon( 'GET', admin_url( 'admin-ajax.php?action=cradle_login_no_such_action' ) );
		assert_same( 400, cradle_login_t_code( $ajax ), 'admin-ajax.php answers natively (unknown action).' );
		assert_not_contains( $GLOBALS['cradle_login_fx']['slug'], cradle_login_t_body( $ajax ) );

		$heartbeat = cradle_login_t_anon( 'POST', admin_url( 'admin-ajax.php' ), array( 'body' => array( 'action' => 'heartbeat', 'data' => array( 'wp-auth-check' => true ) ) ) );
		assert_same( 200, cradle_login_t_code( $heartbeat ), 'Anonymous heartbeat (auth check) works.' );

		$post = cradle_login_t_anon( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array( 'action' => 'cradle_login_no_such_action' ) ) );
		assert_same( 400, cradle_login_t_code( $post ), 'admin-post.php answers natively.' );

		$index = cradle_login_t_anon( 'GET', rest_url( '/' ) );
		assert_same( 200, cradle_login_t_code( $index ) );
		assert_contains( '"namespaces"', cradle_login_t_body( $index ) );
		assert_same( 401, cradle_login_t_code( cradle_login_t_anon( 'GET', rest_url( 'wp/v2/users/me' ) ) ), 'REST authentication errors are native.' );

		$admin   = cradle_login_t_admin();
		$session = cradle_test_cookies( $admin );
		$me      = cradle_test_http( 'GET', rest_url( 'wp/v2/users/me' ), array( 'headers' => array( 'X-WP-Nonce' => cradle_test_nonce( $admin, $session, 'wp_rest' ) ) ), $admin, $session );
		assert_same( 200, cradle_login_t_code( $me ), 'Cookie + nonce REST authentication works.' );
		assert_same( $admin, (int) ( json_decode( cradle_login_t_body( $me ), true )['id'] ?? 0 ) );

		assert_same( 200, cradle_login_t_code( cradle_login_t_anon( 'GET', site_url( 'wp-cron.php' ) ) ), 'wp-cron.php is reachable.' );
		assert_same( 405, cradle_login_t_code( cradle_login_t_anon( 'GET', site_url( 'xmlrpc.php' ) ) ), 'xmlrpc.php answers natively.' );
	}
);

cradle_test(
	'login: signed-in users keep /wp-admin/ and wp-login.php; logout works through the route',
	function () {
		cradle_login_t_require_enabled();
		$admin   = cradle_login_t_admin();
		$session = cradle_test_cookies( $admin );
		assert_same( 200, cradle_login_t_code( cradle_test_http( 'GET', admin_url( 'options-general.php' ), array(), $admin, $session ) ) );
		// Like a browser: only the logged-in cookie is sent outside /wp-admin/.
		$native = cradle_login_t_anon( 'GET', cradle_login_t_native( '' ), array( 'cookies' => array( $session['cookies'][1] ) ) );
		assert_same( 200, cradle_login_t_code( $native ), 'Signed-in users may still open wp-login.php.' );
		assert_contains( 'id="loginform"', cradle_login_t_body( $native ) );
		// With the wp-admin auth cookie as well, WordPress itself sends a signed-in user on to the dashboard.
		$both = cradle_test_http( 'GET', cradle_login_t_native( '' ), array(), $admin, $session );
		assert_same( 302, cradle_login_t_code( $both ) );
		$location = cradle_login_t_location( $both );
		assert_true( admin_url() === $location || 0 === strpos( $location, cradle_login_t_route( '?' ) ), 'Native signed-in redirect: ' . $location );

		// Admin email confirmation screen (shown after login when the address is due for review).
		$confirm = cradle_login_t_anon( 'GET', cradle_login_t_route( '?action=confirm_admin_email' ), array( 'cookies' => array( $session['cookies'][1] ) ) );
		assert_same( 200, cradle_login_t_code( $confirm ) );
		assert_contains( 'action="' . esc_url( cradle_login_t_route( '?action=confirm_admin_email' ) ) . '"', cradle_login_t_body( $confirm ), 'Admin email confirmation posts to the route.' );

		$user     = cradle_login_t_fixture_user( 'subscriber' );
		$usession = cradle_test_cookies( $user );
		assert_same( 200, cradle_login_t_code( cradle_test_http( 'GET', admin_url( 'profile.php' ), array(), $user, $usession ) ), 'Subscriber reaches profile.php.' );

		$nonce  = cradle_test_nonce( $user, $usession, 'log-out' );
		$logout = cradle_test_http( 'GET', cradle_login_t_route( '?action=logout&_wpnonce=' . $nonce ), array(), $user, $usession );
		assert_same( 302, cradle_login_t_code( $logout ), 'Logout redirects.' );
		assert_same( 0, strpos( cradle_login_t_location( $logout ), cradle_login_t_route( '?loggedout=true' ) ), 'Logout returns to the route: ' . cradle_login_t_location( $logout ) );
		wp_cache_delete( $user, 'user_meta' );
		assert_false( WP_Session_Tokens::get_instance( $user )->verify( $usession['token'] ), 'The session was destroyed.' );
		$after = cradle_test_http( 'GET', admin_url( 'profile.php' ), array(), $user, $usession );
		assert_same( 404, cradle_login_t_code( $after ), 'Logged-out cookies no longer open /wp-admin/.' );

		$loggedout = cradle_login_t_anon( 'GET', cradle_login_t_location( $logout ) );
		assert_same( 200, cradle_login_t_code( $loggedout ) );
		assert_contains( 'login-message', cradle_login_t_body( $loggedout ), '"You are now logged out" message.' );

		$bad = cradle_test_http( 'GET', cradle_login_t_route( '?action=logout&_wpnonce=invalid' ), array(), $admin, $session );
		assert_same( 403, cradle_login_t_code( $bad ), 'A logout without a valid nonce asks for confirmation (native wp_nonce_ays).' );
		assert_true( WP_Session_Tokens::get_instance( $admin )->verify( $session['token'] ), 'Invalid nonce does not end the session.' );
	}
);

cradle_test(
	'login: login with valid and invalid credentials, reauth and interim login through the route',
	function () {
		cradle_login_t_require_enabled();
		$password = wp_generate_password( 24, true, false );
		$user     = cradle_login_t_fixture_user( 'subscriber', $password );
		$login    = get_userdata( $user )->user_login;

		$bad = cradle_login_t_anon( 'POST', cradle_login_t_route(), array( 'body' => array( 'log' => $login, 'pwd' => $password . 'x', 'redirect_to' => admin_url( 'profile.php' ) ) ) );
		assert_same( 200, cradle_login_t_code( $bad ) );
		assert_contains( 'id="login_error"', cradle_login_t_body( $bad ), 'Invalid credentials show the native error.' );
		assert_true( null === cradle_login_t_cookie( $bad, LOGGED_IN_COOKIE ), 'No session cookie on failure.' );

		$good = cradle_login_t_anon( 'POST', cradle_login_t_route(), array( 'body' => array( 'log' => $login, 'pwd' => $password, 'redirect_to' => admin_url( 'profile.php' ) ) ) );
		assert_same( 302, cradle_login_t_code( $good ), 'Valid credentials redirect.' );
		assert_same( admin_url( 'profile.php' ), cradle_login_t_location( $good ) );
		$cookies = array_filter( wp_remote_retrieve_cookies( $good ), static fn( $c ) => '' !== trim( (string) $c->value ) && ( empty( $c->expires ) || $c->expires > time() ) );
		assert_true( null !== cradle_login_t_cookie( $good, LOGGED_IN_COOKIE ), 'WordPress set the logged-in cookie.' );
		$profile = cradle_login_t_anon( 'GET', admin_url( 'profile.php' ), array( 'cookies' => array_values( $cookies ) ) );
		assert_same( 200, cradle_login_t_code( $profile ), 'The new session opens /wp-admin/profile.php.' );

		$reauth = cradle_login_t_anon( 'GET', wp_login_url( admin_url(), true ) );
		assert_same( 200, cradle_login_t_code( $reauth ) );
		assert_contains( 'id="loginform"', cradle_login_t_body( $reauth ), 'Reauthentication form.' );

		$interim = cradle_login_t_anon( 'GET', cradle_login_t_route( '?interim-login=1' ) );
		assert_same( 200, cradle_login_t_code( $interim ) );
		assert_contains( 'name="interim-login"', cradle_login_t_body( $interim ), 'Interim (modal) login form.' );
		assert_contains( 'Your session has expired', cradle_login_t_body( $interim ) );
	}
);

cradle_test(
	'login: lost password, reset key flow and registration follow WordPress rules at the route',
	function () {
		cradle_login_t_require_enabled();
		$route = cradle_login_t_route();

		$lost = cradle_login_t_anon( 'GET', cradle_login_t_route( '?action=lostpassword' ) );
		assert_same( 200, cradle_login_t_code( $lost ) );
		assert_contains( 'id="lostpasswordform"', cradle_login_t_body( $lost ) );
		assert_contains( 'action="' . esc_url( $route . '?action=lostpassword' ) . '"', cradle_login_t_body( $lost ) );
		// Posting an unknown account exercises the handler without sending email.
		$posted = cradle_login_t_anon( 'POST', cradle_login_t_route( '?action=lostpassword' ), array( 'body' => array( 'user_login' => 'cradle_login_nobody_' . wp_generate_password( 6, false ) ) ) );
		assert_same( 200, cradle_login_t_code( $posted ) );
		assert_contains( 'id="login_error"', cradle_login_t_body( $posted ), 'Lost-password POST is processed by WordPress.' );
		$check = cradle_login_t_anon( 'GET', cradle_login_t_route( '?checkemail=confirm' ) );
		assert_same( 200, cradle_login_t_code( $check ) );
		assert_contains( esc_url( $route ), cradle_login_t_body( $check ), '"Check your email" links back to the route.' );

		// Real reset key for a disposable user.
		$user  = cradle_login_t_fixture_user( 'subscriber' );
		$login = get_userdata( $user )->user_login;
		$key   = get_password_reset_key( get_userdata( $user ) );
		assert_true( is_string( $key ) && '' !== $key );

		$handoff = cradle_login_t_anon( 'GET', cradle_login_t_native( '?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $login ) ) );
		assert_same( 302, cradle_login_t_code( $handoff ), 'An emailed wp-login.php reset link with a valid key is forwarded.' );
		assert_same( 0, strpos( cradle_login_t_location( $handoff ), $route . '?action=rp&key=' ) );

		$step1 = cradle_login_t_anon( 'GET', cradle_login_t_route( '?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $login ) ) );
		assert_same( 302, cradle_login_t_code( $step1 ), 'WordPress moves the key into the reset cookie.' );
		assert_same( Config::route_path( $GLOBALS['cradle_login_fx']['slug'] ) . '?action=rp', cradle_login_t_location( $step1 ), 'Root-relative redirect to the route (WordPress uses REQUEST_URI).' );
		$rp_cookie = cradle_login_t_cookie( $step1, 'wp-resetpass-' . COOKIEHASH );
		assert_true( null !== $rp_cookie, 'Reset cookie set.' );
		assert_same( Config::route_path( $GLOBALS['cradle_login_fx']['slug'] ), $rp_cookie->path, 'Reset cookie is scoped to the route path.' );

		$form = cradle_login_t_anon( 'GET', cradle_login_t_route( '?action=rp' ), array( 'cookies' => array( $rp_cookie ) ) );
		assert_same( 200, cradle_login_t_code( $form ) );
		assert_contains( 'id="resetpassform"', cradle_login_t_body( $form ) );
		assert_contains( 'action="' . esc_url( $route . '?action=resetpass' ) . '"', cradle_login_t_body( $form ) );
		assert_contains( 'name="rp_key" value="' . esc_attr( $key ) . '"', cradle_login_t_body( $form ) );

		// Submit with mismatched passwords: reaches the validated-key reset handler without
		// changing the password (a completed reset would email the site administrator).
		$submit = cradle_login_t_anon(
			'POST',
			cradle_login_t_route( '?action=resetpass' ),
			array(
				'cookies' => array( $rp_cookie ),
				'body'    => array(
					'pass1'  => 'Aa1!' . wp_generate_password( 20, false ),
					'pass2'  => 'Bb2!' . wp_generate_password( 20, false ),
					'rp_key' => $key,
				),
			)
		);
		assert_same( 200, cradle_login_t_code( $submit ) );
		assert_contains( 'The passwords do not match', cradle_login_t_body( $submit ), 'The reset POST reached WordPress with a valid key and cookie.' );
		assert_true( check_password_reset_key( $key, $login ) instanceof WP_User, 'The key remains valid after the rejected submission.' );

		$invalid = cradle_login_t_anon( 'GET', cradle_login_t_route( '?action=rp' ), array( 'cookies' => array( new WP_Http_Cookie( array( 'name' => 'wp-resetpass-' . COOKIEHASH, 'value' => $login . ':invalidkey' ) ) ) ) );
		assert_same( 302, cradle_login_t_code( $invalid ) );
		assert_same( cradle_login_t_route( '?action=lostpassword&error=invalidkey' ), cradle_login_t_location( $invalid ), 'Invalid keys go to the route\'s lost-password form.' );

		// Registration only when WordPress allows it.
		$closed = cradle_login_t_anon( 'GET', cradle_login_t_route( '?action=register' ) );
		assert_same( 302, cradle_login_t_code( $closed ) );
		assert_same( cradle_login_t_route( '?registration=disabled' ), cradle_login_t_location( $closed ) );
		$original = get_option( 'users_can_register' );
		update_option( 'users_can_register', 1 );
		try {
			$open = cradle_login_t_anon( 'GET', cradle_login_t_route( '?action=register' ) );
		} finally {
			update_option( 'users_can_register', $original );
		}
		assert_same( 200, cradle_login_t_code( $open ) );
		assert_contains( 'id="registerform"', cradle_login_t_body( $open ) );
		assert_contains( 'action="' . esc_url( $route . '?action=register' ) . '"', cradle_login_t_body( $open ) );
	}
);

cradle_test(
	'login: post password form, privacy confirmation and recovery-mode links work with the route',
	function () {
		cradle_login_t_require_enabled();
		$route    = cradle_login_t_route();
		$password = 'pw-' . wp_generate_password( 10, false );
		$secret   = 'CRADLE-LOGIN-SECRET-' . wp_generate_password( 8, false );
		$post_id  = wp_insert_post(
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_title'    => 'Cradle login protected fixture',
				'post_content'  => '<!-- wp:paragraph --><p>' . $secret . '</p><!-- /wp:paragraph -->',
				'post_password' => $password,
			)
		);
		assert_true( is_int( $post_id ) && $post_id > 0 );
		$GLOBALS['cradle_login_fx']['posts'][] = $post_id;
		$permalink                             = get_permalink( $post_id );

		$locked = cradle_login_t_anon( 'GET', $permalink );
		assert_same( 200, cradle_login_t_code( $locked ) );
		assert_not_contains( $secret, cradle_login_t_body( $locked ) );
		assert_contains( 'action="' . esc_url( $route . '?action=postpass' ) . '"', cradle_login_t_body( $locked ), 'The password form posts to the route.' );

		$wrong = cradle_login_t_anon( 'POST', cradle_login_t_route( '?action=postpass' ), array( 'body' => array( 'post_password' => 'wrong', 'redirect_to' => $permalink ) ) );
		assert_same( 302, cradle_login_t_code( $wrong ) );
		$unlock = cradle_login_t_anon( 'POST', cradle_login_t_route( '?action=postpass' ), array( 'body' => array( 'post_password' => $password, 'redirect_to' => $permalink ) ) );
		assert_same( 302, cradle_login_t_code( $unlock ) );
		assert_same( $permalink, cradle_login_t_location( $unlock ) );
		$pass_cookie = cradle_login_t_cookie( $unlock, 'wp-postpass_' . COOKIEHASH );
		assert_true( null !== $pass_cookie, 'Post password cookie set.' );
		$open = cradle_login_t_anon( 'GET', $permalink, array( 'cookies' => array( $pass_cookie ) ) );
		assert_contains( $secret, cradle_login_t_body( $open ), 'The protected content is shown with the cookie.' );
		$legacy = cradle_login_t_anon( 'POST', cradle_login_t_native( '?action=postpass' ), array( 'body' => array( 'post_password' => $password, 'redirect_to' => $permalink ) ) );
		assert_same( 302, cradle_login_t_code( $legacy ), 'Cached forms posting to wp-login.php?action=postpass keep working.' );
		assert_same( $permalink, cradle_login_t_location( $legacy ) );

		// Privacy request confirmation (confirmaction). Marked as already notified so no email is sent.
		$request_id = wp_create_user_request( 'cradle-login-fx-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test', 'export_personal_data', array(), 'pending' );
		assert_true( is_int( $request_id ) && $request_id > 0, 'Privacy request fixture created.' );
		$GLOBALS['cradle_login_fx']['posts'][] = $request_id;
		update_post_meta( $request_id, '_wp_admin_notified', true );
		$confirm_key = wp_generate_user_request_key( $request_id );
		$query       = '?action=confirmaction&request_id=' . $request_id . '&confirm_key=' . rawurlencode( $confirm_key );
		$handoff     = cradle_login_t_anon( 'GET', cradle_login_t_native( '' . $query ) );
		assert_same( 302, cradle_login_t_code( $handoff ), 'An emailed wp-login.php confirmation link with a valid key is forwarded.' );
		assert_same( $route . $query, cradle_login_t_location( $handoff ) );
		$confirmed = cradle_login_t_anon( 'GET', $route . $query );
		assert_same( 200, cradle_login_t_code( $confirmed ) );
		assert_contains( 'Thanks for confirming your export request', cradle_login_t_body( $confirmed ) );
		clean_post_cache( $request_id );
		assert_same( 'request-confirmed', get_post_status( $request_id ), 'WordPress recorded the confirmation.' );

		// Recovery mode: a real begin link (generated like the fatal-error email) end to end.
		$links = new WP_Recovery_Mode_Link_Service( new WP_Recovery_Mode_Cookie_Service(), new WP_Recovery_Mode_Key_Service() );
		$begin = $links->generate_url();
		assert_same( 0, strpos( $begin, Config::native_login_url() . '?action=enter_recovery_mode' ), 'Begin link addresses wp-login.php.' );
		$enter = cradle_login_t_anon( 'GET', $begin );
		assert_same( 302, cradle_login_t_code( $enter ), 'WordPress validated the recovery key.' );
		$rec_cookie = cradle_login_t_cookie( $enter, RECOVERY_MODE_COOKIE );
		assert_true( null !== $rec_cookie, 'Recovery cookie set.' );
		$entered = cradle_login_t_anon( 'GET', cradle_login_t_location( $enter ), array( 'cookies' => array( $rec_cookie ) ) );
		assert_same( 302, cradle_login_t_code( $entered ), 'A valid recovery cookie is forwarded from wp-login.php to the route.' );
		assert_same( $route . '?action=entered_recovery_mode', cradle_login_t_location( $entered ) );
		$message = cradle_login_t_anon( 'GET', cradle_login_t_location( $entered ), array( 'cookies' => array( $rec_cookie ) ) );
		assert_same( 200, cradle_login_t_code( $message ) );
		assert_contains( 'Recovery Mode Initialized', cradle_login_t_body( $message ) );
		$no_cookie = cradle_login_t_anon( 'GET', cradle_login_t_location( $enter ) );
		assert_same( 404, cradle_login_t_code( $no_cookie ), 'Without the recovery cookie the native URL stays concealed.' );
	}
);

cradle_test(
	'login: safe response modes and moving the route keep the administrator signed in',
	function () {
		cradle_login_t_require_enabled();
		$admin = cradle_login_t_admin();
		$slug  = $GLOBALS['cradle_login_fx']['slug'];
		$slug2 = $GLOBALS['cradle_login_fx']['slug2'];

		cradle_login_t_as( $admin, fn() => cradle()->settings()->update( 'login', array( 'blocked_response' => 'plain_not_found' ) ) );
		$plain = cradle_login_t_anon( 'GET', cradle_login_t_native( '' ) );
		assert_same( 404, cradle_login_t_code( $plain ) );
		assert_contains( '<h1>Not Found</h1>', cradle_login_t_body( $plain ) );
		assert_not_contains( $slug, cradle_login_t_body( $plain ) );

		cradle_login_t_as( $admin, fn() => cradle()->settings()->update( 'login', array( 'blocked_response' => 'redirect_home' ) ) );
		$home = cradle_login_t_anon( 'GET', admin_url( '/' ) );
		assert_same( 302, cradle_login_t_code( $home ) );
		assert_same( home_url( '/' ), cradle_login_t_location( $home ) );

		cradle_login_t_as( $admin, fn() => cradle()->settings()->update( 'login', array( 'blocked_response' => 'not_found' ) ) );
		assert_same( 'active', Config::status(), 'Changing the response did not touch routing.' );

		// Low-privilege users cannot switch routing off either.
		$editor   = cradle_test_user( 'editor' );
		$esession = cradle_test_cookies( $editor );
		assert_same( 403, cradle_login_t_code( cradle_login_t_options_post( $editor, $esession, array( 'enabled' => '0' ) ) ) );
		assert_same( 'active', Config::status() );

		$session  = cradle_test_cookies( $admin );
		$response = cradle_login_t_options_post( $admin, $session, array( 'enabled' => '1', 'slug' => $slug2, 'blocked_response' => 'not_found', 'recovery_note' => '' ) );
		assert_same( 302, cradle_login_t_code( $response ) );
		assert_same( $slug2, Config::values()['slug'], 'The route moved after a passing health check.' );
		assert_same( 'change', Health_Check::last()['context'] ?? '' );
		assert_same( 200, cradle_login_t_code( cradle_test_http( 'GET', admin_url( '/' ), array(), $admin, $session ) ), 'The administrator stays signed in after the move.' );
		assert_same( 404, cradle_login_t_code( cradle_login_t_anon( 'GET', Config::route_url( $slug ) ) ), 'The old route is gone.' );
		$new = cradle_login_t_anon( 'GET', Config::route_url( $slug2 ) );
		assert_same( 200, cradle_login_t_code( $new ) );
		assert_contains( 'action="' . esc_url( Config::route_url( $slug2 ) ) . '"', cradle_login_t_body( $new ) );

		// A failing health check keeps the current route.
		$fail = static fn( $pre, $args, $url ) => false !== strpos( (string) $url, '/' . $slug . '/' ) ? new WP_Error( 'http_request_failed', 'simulated' ) : $pre;
		add_filter( 'pre_http_request', $fail, 10, 3 );
		try {
			$result = cradle_login_t_as( $admin, fn() => cradle()->settings()->update( 'login', array( 'slug' => $slug ) ) );
		} finally {
			remove_filter( 'pre_http_request', $fail, 10 );
		}
		cradle_login_t_refresh();
		assert_true( isset( $result['errors']['slug'] ), 'A failed move is reported on the slug field.' );
		assert_same( $slug2, Config::values()['slug'] );
		assert_same( 'active', Config::status() );

		// Back to the primary slug for the remaining tests.
		$response = cradle_login_t_options_post( $admin, $session, array( 'enabled' => '1', 'slug' => $slug, 'blocked_response' => 'not_found', 'recovery_note' => '' ) );
		assert_same( $slug, Config::values()['slug'] );
	}
);

cradle_test(
	'login: CRADLE_DISABLE_CUSTOM_LOGIN (separate process) and the code filter switch routing off',
	function () {
		cradle_login_t_require_enabled();
		$script = CRADLE_TEST_ROOT . '/tests/fixtures/login/recovery-constant.php';
		$run    = static function ( string $mode ) use ( $script ) {
			$output = array();
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $mode ) . ' ' . escapeshellarg( $GLOBALS['cradle_login_fx']['slug'] ) . ' 2>&1', $output );
			foreach ( $output as $line ) {
				if ( 0 === strpos( $line, 'CRADLE_LOGIN_JSON:' ) ) {
					return json_decode( substr( $line, 18 ), true );
				}
			}
			throw new Cradle_Test_Failure( 'Fixture produced no result: ' . implode( ' | ', array_slice( $output, 0, 5 ) ) );
		};

		$without = $run( 'without' );
		assert_same( true, $without['loaded'] );
		assert_same( 'active', $without['status'], 'Control run: routing active without the constant.' );
		assert_same( cradle_login_t_route(), $without['login_url'] );
		assert_same( 'block', $without['decision_login'] );
		assert_same( 'block', $without['decision_admin'] );

		$with = $run( 'with' );
		assert_same( true, $with['constant'] );
		assert_same( 'constant', $with['status'], 'The constant switches routing off with routing still enabled in the database.' );
		assert_same( false, $with['active'] );
		assert_same( $with['native_url'], $with['login_url'], 'wp_login_url() is native under the constant.' );
		assert_same( 'pass', $with['decision_login'], 'wp-login.php is served normally under the constant.' );
		assert_same( 'pass', $with['decision_admin'], '/wp-admin/ behaves normally under the constant.' );
		assert_true( isset( $with['enable_errors']['enabled'] ) && false !== strpos( $with['enable_errors']['enabled'], Config::CONSTANT ), 'Enabling is refused while the constant is set.' );

		$filter = '__return_true';
		add_filter( 'cradle_login_disable_routing', $filter );
		Router::instance()->refresh();
		try {
			assert_same( 'filter', Config::status() );
			assert_same( Config::native_login_url(), wp_login_url() );
			assert_same( 'pass', Router::instance()->decide( 'login', false, 'GET', array() ) );
		} finally {
			remove_filter( 'cradle_login_disable_routing', $filter );
			Router::instance()->refresh();
		}
		assert_same( cradle_login_t_route(), wp_login_url() );
	}
);

cradle_test(
	'login: deactivating the plugin restores native login; reactivating resumes the saved route',
	function () {
		cradle_login_t_require_enabled();
		$file  = 'cradle-login/cradle-login.php';
		$admin = cradle_login_t_admin();
		cradle_login_t_as( $admin, fn() => deactivate_plugins( $file ) );
		try {
			cradle_login_t_refresh();
			assert_false( is_plugin_active( $file ) );
			$login = cradle_login_t_anon( 'GET', cradle_login_t_native( '' ) );
			assert_same( 200, cradle_login_t_code( $login ), 'wp-login.php works once the add-on is inactive.' );
			assert_contains( 'id="loginform"', cradle_login_t_body( $login ) );
			assert_contains( 'action="' . esc_url( Config::native_login_url( 'login_post' ) ) . '"', cradle_login_t_body( $login ) );
			$admin_redirect = cradle_login_t_anon( 'GET', admin_url( '/' ) );
			assert_same( 302, cradle_login_t_code( $admin_redirect ) );
			assert_contains( 'wp-login.php', cradle_login_t_location( $admin_redirect ) );
			assert_same( 404, cradle_login_t_code( cradle_login_t_anon( 'GET', cradle_login_t_route() ) ), 'The route is inert while inactive.' );
			assert_same( true, get_option( Config::OPTION )['enabled'] ?? null, 'Settings are kept on deactivation.' );
		} finally {
			$activated = cradle_login_t_as( $admin, fn() => activate_plugin( $file ) );
			cradle_login_t_refresh();
		}
		assert_true( null === $activated, 'Reactivation succeeded.' );
		assert_true( is_plugin_active( $file ), 'Plugin is active again.' );
		assert_same( 404, cradle_login_t_code( cradle_login_t_anon( 'GET', cradle_login_t_native( '' ) ) ), 'Reactivation resumes the saved route.' );
		assert_same( 200, cradle_login_t_code( cradle_login_t_anon( 'GET', cradle_login_t_route() ) ) );
		assert_true( (bool) get_transient( 'cradle_login_resumed_notice' ), 'Administrators are told routing resumed.' );
		delete_transient( 'cradle_login_resumed_notice' );
	}
);

cradle_test(
	'login: teardown — routing disabled, fixtures removed, native login restored',
	function () {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$admin = cradle_login_t_admin();
		if ( ! is_plugin_active( 'cradle-login/cradle-login.php' ) ) {
			cradle_login_t_as( $admin, fn() => activate_plugin( 'cradle-login/cradle-login.php' ) );
		}
		$result = cradle_login_t_as( $admin, fn() => cradle()->settings()->update( 'login', array( 'enabled' => false ) ) );
		assert_true( is_array( $result ) && ! isset( $result['errors']['enabled'] ) );
		cradle_login_t_as( $admin, fn() => cradle()->settings()->reset( 'login' ) );
		// Leave the stored defaults in place (as activation creates them): routing off, no slug.
		cradle_login_t_as( $admin, fn() => cradle()->settings()->update( 'login', array( 'enabled' => false ) ) );
		$GLOBALS['cradle_login_fx']['enabled'] = false;
		cradle_login_t_refresh();
		assert_same( 'disabled', Config::status() );
		assert_same( Config::defaults(), array_intersect_key( (array) get_option( Config::OPTION ), Config::defaults() ) );
		assert_same( Config::native_login_url(), wp_login_url() );

		foreach ( $GLOBALS['cradle_login_fx']['users'] as $user_id ) {
			wp_delete_user( $user_id );
		}
		foreach ( $GLOBALS['cradle_login_fx']['posts'] as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$GLOBALS['cradle_login_fx']['users'] = array();
		$GLOBALS['cradle_login_fx']['posts'] = array();

		$login = cradle_login_t_anon( 'GET', cradle_login_t_native( '' ) );
		assert_same( 200, cradle_login_t_code( $login ) );
		assert_contains( 'id="loginform"', cradle_login_t_body( $login ) );
		assert_same( 302, cradle_login_t_code( cradle_login_t_anon( 'GET', admin_url( '/' ) ) ) );
		assert_true( is_plugin_active( 'cradle-login/cradle-login.php' ), 'The plugin is left active with routing disabled.' );
	}
);

cradle_test(
	'login: uninstall retains data by default and purges only owned data on the purge policy (separate process)',
	function () {
		$script = CRADLE_TEST_ROOT . '/tests/fixtures/login/uninstall.php';
		$run    = static function ( string $mode ) use ( $script ) {
			$output = array();
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $mode ) . ' 2>&1', $output );
			foreach ( $output as $line ) {
				if ( 0 === strpos( $line, 'CRADLE_LOGIN_JSON:' ) ) {
					return json_decode( substr( $line, 18 ), true );
				}
			}
			throw new Cradle_Test_Failure( 'Fixture produced no result: ' . implode( ' | ', array_slice( $output, 0, 5 ) ) );
		};
		$admin = cradle_login_t_admin();
		try {
			$retain = $run( 'retain' );
			assert_same( 1, $retain['before']['settings'], 'Settings exist before uninstall.' );
			assert_same( 1, $retain['settings_after'], 'Retain policy keeps the settings.' );
			assert_true( $retain['transients_after'] >= 1, 'Retain policy keeps transients.' );
			assert_false( $retain['roles_write_attempted'], 'Retain policy leaves roles alone.' );
			assert_false( $retain['granted_write_attempted'] );

			$purge = $run( 'purge' );
			assert_same( 0, $purge['settings_after'], 'Purge deletes cradle_login_settings.' );
			assert_same( 0, $purge['health_after'], 'Purge deletes cradle_login_health.' );
			assert_same( 0, $purge['transients_after'], 'Purge deletes cradle_login_* transients.' );
			assert_true( $purge['roles_write_attempted'] && false === $purge['admin_cap_in_attempt'], 'Purge revokes cradle_manage_login from administrators.' );
			assert_true( $purge['granted_write_attempted'] && false === $purge['granted_entry_in_attempt'], 'Purge forgets the one-time grant.' );
		} finally {
			// Restore the defaults activation would create; shared role data was never written.
			cradle_login_t_refresh();
			cradle_login_t_as( $admin, fn() => cradle()->settings()->update( 'login', array( 'enabled' => false ) ) );
			cradle_login_t_refresh();
		}
		wp_cache_delete( $GLOBALS['wpdb']->prefix . 'user_roles', 'options' );
		wp_roles()->for_site();
		assert_true( get_role( 'administrator' )->has_cap( Module::CAP ), 'The real administrator role still holds the capability.' );
		assert_same( 1, (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", Config::OPTION ) ), 'Default settings restored.' );
		assert_same( 'disabled', Config::status() );
		assert_true( is_plugin_active( 'cradle-login/cradle-login.php' ), 'Cradle Login is left active with routing disabled.' );
	}
);
