<?php
/**
 * Serves WordPress' own wp-login.php at the custom route and conceals the native
 * entry points from signed-out visitors. Authentication, cookies, nonces, reset
 * keys and redirects remain entirely WordPress' own code: the router only
 * (1) includes the unmodified wp-login.php for requests to the route,
 * (2) rewrites generated wp-login.php URLs to the route, and
 * (3) answers signed-out direct requests to wp-login.php, /wp-admin/ and the
 *     single-site signup scripts with the configured safe response.
 *
 * No rewrite rules are added: the route is matched against REQUEST_URI on the
 * front controller (index.php) during plugins_loaded, so nothing is ever flushed.
 *
 * Never affected: admin-ajax.php, admin-post.php, REST, XML-RPC, cron, CLI.
 *
 * @package Cradle\Login
 */

namespace Cradle\Login;

defined( 'ABSPATH' ) || exit;

final class Router {

	/** @var Router|null */
	private static $instance = null;

	private bool $started = false;

	/** Configured routing is active (enabled, valid slug, not switched off). */
	private bool $active = false;

	/** Slug that URL filters rewrite to for this request ('' = leave URLs alone). */
	private string $slug = '';

	/** This request addresses the route and will be dispatched to wp-login.php. */
	private bool $route_request = false;

	/** This request is an authenticated-by-token health probe of a candidate slug. */
	private bool $probe = false;

	private bool $dispatching = false;

	private bool $filters_added = false;

	public static function instance(): Router {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Starts routing for this request. Called on `cradle/ready` when the module is ready.
	 */
	public function start() {
		if ( $this->started ) {
			return;
		}
		$this->started = true;
		$this->load_state();
		$this->detect_request();

		if ( '' !== $this->slug ) {
			$this->add_url_filters();
		}
		if ( $this->route_request ) {
			// Mirror a direct wp-login.php request for code that inspects the current screen.
			$GLOBALS['pagenow']      = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$_SERVER['SCRIPT_NAME'] = Config::route_path( $this->slug ); // Makes is_login() true on the route.
			add_action( 'wp_loaded', array( $this, 'dispatch' ), PHP_INT_MAX );
			return;
		}
		if ( $this->active && ! self::is_cli() ) {
			add_action( 'wp_loaded', array( $this, 'guard' ), PHP_INT_MAX );
			add_action( 'template_redirect', array( $this, 'suppress_admin_location_redirects' ), 999 );
		}
		add_action( 'update_option_' . Config::OPTION, array( $this, 'refresh' ), 20 );
		add_action( 'add_option_' . Config::OPTION, array( $this, 'refresh' ), 20 );
		add_action( 'delete_option_' . Config::OPTION, array( $this, 'refresh_after_delete' ), 20 );
	}

	/**
	 * Re-reads the configuration after it changed during this request, so URLs generated
	 * later in the same request (and in CLI processes) match the saved state.
	 */
	public function refresh() {
		if ( $this->route_request ) {
			return;
		}
		$this->load_state();
		if ( '' !== $this->slug ) {
			$this->add_url_filters();
		}
	}

	public function refresh_after_delete() {
		add_action( 'deleted_option', array( $this, 'refresh' ), 20 );
	}

	private function load_state() {
		$this->active = 'active' === Config::status();
		$this->slug   = $this->active ? Config::values()['slug'] : '';
	}

	public function is_active(): bool {
		return $this->active;
	}

	/**
	 * Slug currently used for generated URLs ('' when routing is inactive).
	 */
	public function slug(): string {
		return $this->slug;
	}

	public function is_route_request(): bool {
		return $this->route_request;
	}

	public function is_probe(): bool {
		return $this->probe;
	}

	public static function is_cli(): bool {
		return 'cli' === PHP_SAPI || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	private function detect_request() {
		if ( self::is_cli() || is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
			return;
		}
		$script = self::script_basename();
		if ( '' !== $script && 'index.php' !== $script ) {
			return;
		}
		// Read during plugins_loaded, before wp_magic_quotes() slashes $_SERVER.
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared, never output.

		$token = isset( $_SERVER[ Health_Check::SERVER_KEY ] ) && is_string( $_SERVER[ Health_Check::SERVER_KEY ] ) ? $_SERVER[ Health_Check::SERVER_KEY ] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- format-checked in pending_slug().
		if ( '' !== $token && Config::probe_allowed() ) {
			$candidate = Health_Check::pending_slug( $token );
			if ( '' !== $candidate && Config::request_matches( $uri, $candidate ) ) {
				$this->slug          = $candidate;
				$this->route_request = true;
				$this->probe         = true;
				return;
			}
		}
		if ( $this->active && Config::request_matches( $uri, $this->slug ) ) {
			$this->route_request = true;
		}
	}

	/**
	 * Lower-case basename of the executing script. Works before (plugins_loaded) and after
	 * (wp_loaded) wp_magic_quotes(): doubled Windows backslashes normalize to slashes; the
	 * path is deliberately not unslashed, which would destroy single Windows backslashes.
	 */
	private static function script_basename(): string {
		$script = '';
		if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && is_string( $_SERVER['SCRIPT_FILENAME'] ) ) {
			$script = $_SERVER['SCRIPT_FILENAME']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- basename compared only.
		} elseif ( isset( $_SERVER['PHP_SELF'] ) && is_string( $_SERVER['PHP_SELF'] ) ) {
			$script = $_SERVER['PHP_SELF']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- basename compared only.
		}
		return strtolower( wp_basename( str_replace( '\\', '/', $script ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Generated URLs                                                      */
	/* ------------------------------------------------------------------ */

	private function add_url_filters() {
		if ( $this->filters_added ) {
			return;
		}
		$this->filters_added = true;
		add_filter( 'site_url', array( $this, 'filter_url' ), 20 );
		add_filter( 'network_site_url', array( $this, 'filter_url' ), 20 );
		add_filter( 'login_url', array( $this, 'filter_url' ), 20 );
		add_filter( 'logout_url', array( $this, 'filter_url' ), 20 );
		add_filter( 'lostpassword_url', array( $this, 'filter_url' ), 20 );
		add_filter( 'register_url', array( $this, 'filter_url' ), 20 );
		add_filter( 'wp_redirect', array( $this, 'filter_url' ), 20 );
		add_filter( 'recovery_mode_begin_url', array( $this, 'native_url' ), 20 );
	}

	/**
	 * Filter callback: rewrites this site's wp-login.php URLs to the route.
	 *
	 * @param mixed $url URL or redirect location.
	 * @return mixed
	 */
	public function filter_url( $url ) {
		return is_string( $url ) ? $this->rewrite( $url ) : $url;
	}

	/**
	 * Maps a URL addressing this site's wp-login.php (absolute, root-relative or the bare
	 * relative "wp-login.php?…" used by core redirects) to the route, keeping query and
	 * fragment. Every other URL is returned unchanged; the transformation is idempotent.
	 */
	public function rewrite( string $url ): string {
		if ( '' === $this->slug || false === stripos( $url, 'wp-login.php' ) ) {
			return $url;
		}
		$original = $url;
		$fragment = '';
		$hash_pos = strpos( $url, '#' );
		if ( false !== $hash_pos ) {
			$fragment = substr( $url, $hash_pos );
			$url      = substr( $url, 0, $hash_pos );
		}
		$query     = '';
		$query_pos = strpos( $url, '?' );
		if ( false !== $query_pos ) {
			$query = substr( $url, $query_pos );
			$base  = substr( $url, 0, $query_pos );
		} else {
			$base = $url;
		}

		$site_path  = (string) wp_parse_url( Config::site(), PHP_URL_PATH );
		$login_path = untrailingslashit( $site_path ) . '/wp-login.php';

		if ( 'wp-login.php' === $base ) {
			return Config::route_url( $this->slug ) . $query . $fragment;
		}
		if ( 0 === strpos( $base, '/' ) && 0 !== strpos( $base, '//' ) ) {
			return $base === $login_path ? Config::route_path( $this->slug ) . $query . $fragment : $original;
		}
		$parts = wp_parse_url( $base );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $original;
		}
		$site_host = (string) wp_parse_url( Config::site(), PHP_URL_HOST );
		$site_port = wp_parse_url( Config::site(), PHP_URL_PORT );
		$same_host = 0 === strcasecmp( $parts['host'], $site_host ) && ( $parts['port'] ?? null ) === $site_port;
		if ( ! $same_host || ( $parts['path'] ?? '' ) !== $login_path ) {
			return $original;
		}
		$scheme = isset( $parts['scheme'] ) && in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ? strtolower( $parts['scheme'] ) : '';
		return Config::route_url( $this->slug, $scheme ) . $query . $fragment;
	}

	/**
	 * Recovery-mode links must address wp-login.php itself: WordPress validates them
	 * before plugins load (WP_Recovery_Mode_Link_Service::handle_begin_link()).
	 *
	 * @param mixed $url Begin URL built from wp_login_url().
	 * @return mixed
	 */
	public function native_url( $url ) {
		if ( ! is_string( $url ) || '' === $this->slug ) {
			return $url;
		}
		$query_pos = strpos( $url, '?' );
		$base      = false === $query_pos ? $url : substr( $url, 0, $query_pos );
		$query     = false === $query_pos ? '' : substr( $url, $query_pos );
		if ( set_url_scheme( $base, 'http' ) === set_url_scheme( Config::route_url( $this->slug ), 'http' ) ) {
			return Config::native_login_url() . $query;
		}
		return $url;
	}

	/* ------------------------------------------------------------------ */
	/* The route                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Runs the unmodified wp-login.php for a route request (wp_loaded, last priority —
	 * the same point at which a direct wp-login.php request starts executing).
	 */
	public function dispatch() {
		if ( $this->dispatching ) {
			return;
		}
		$this->dispatching = true;
		if ( $this->probe && ! headers_sent() ) {
			header( Health_Check::HEADER . ': ok' );
		}
		// wp-login.php re-requires wp-load.php, which lowers error_reporting(); restore it
		// as early as wp-login.php allows so debug logging behaves as on a direct request.
		$level = error_reporting();
		add_action(
			'login_init',
			static function () use ( $level ) {
				error_reporting( $level ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting
			},
			PHP_INT_MIN
		);
		self::load_login_screen();
		exit;
	}

	/**
	 * Includes wp-login.php so that its top-level variables are the same globals they are
	 * on a direct request (functions in wp-login.php and plugins read them via `global`).
	 */
	private static function load_login_screen() {
		foreach ( self::login_globals() as $__cradle_login_var ) {
			${$__cradle_login_var} = &$GLOBALS[ $__cradle_login_var ];
		}
		unset( $__cradle_login_var );
		require ABSPATH . 'wp-login.php';
	}

	/**
	 * Names of variables assigned or read at the top level of wp-login.php (outside function
	 * bodies), found with the PHP tokenizer so future WordPress versions are covered.
	 *
	 * @return string[]
	 */
	public static function login_globals(): array {
		$known = array( 'action', 'errors', 'error', 'interim_login', 'user_login', 'redirect_to', 'requested_redirect_to', 'http_post', 'login_link_separator', 'secure', 'secure_cookie', 'default_actions', 'rp_cookie', 'rp_path', 'rp_key', 'rp_login', 'user', 'reauth', 'customize_login', 'rememberme', 'aria_describedby', 'has_errors', 'login_script', 'url', 'value' );
		$code  = function_exists( 'token_get_all' ) ? file_get_contents( ABSPATH . 'wp-login.php' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $code ) ) {
			return $known;
		}
		$skip       = array( 'this', 'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV', 'http_response_header', 'argc', 'argv' );
		$names      = array();
		$depth      = 0;
		$skip_depth = null;
		$await_body = false;
		foreach ( token_get_all( $code ) as $token ) {
			if ( is_array( $token ) ) {
				if ( T_FUNCTION === $token[0] && null === $skip_depth ) {
					$await_body = true;
				} elseif ( T_CURLY_OPEN === $token[0] || T_DOLLAR_OPEN_CURLY_BRACES === $token[0] ) {
					++$depth;
				} elseif ( T_VARIABLE === $token[0] && null === $skip_depth && ! $await_body ) {
					$names[ substr( $token[1], 1 ) ] = true;
				}
				continue;
			}
			if ( '{' === $token ) {
				++$depth;
				if ( $await_body ) {
					$skip_depth = $depth;
					$await_body = false;
				}
			} elseif ( '}' === $token ) {
				if ( null !== $skip_depth && $skip_depth === $depth ) {
					$skip_depth = null;
				}
				--$depth;
			} elseif ( ';' === $token && $await_body ) {
				$await_body = false; // Abstract/interface declaration without a body.
			}
		}
		foreach ( $known as $name ) {
			$names[ $name ] = true;
		}
		$out = array();
		foreach ( array_keys( $names ) as $name ) {
			if ( ! in_array( $name, $skip, true ) && 0 !== strpos( $name, '__cradle' ) && preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $name ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Native entry points                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Kind of native entry point addressed by this request: login, admin, signup or ''.
	 */
	public static function entry_kind(): string {
		$script = self::script_basename();
		if ( 'wp-login.php' === $script || ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) ) {
			return 'login';
		}
		if ( in_array( $script, array( 'wp-signup.php', 'wp-activate.php' ), true ) && ! is_multisite() ) {
			return 'signup';
		}
		if ( is_admin() && ! in_array( $script, array( 'admin-ajax.php', 'admin-post.php' ), true ) ) {
			return 'admin';
		}
		return '';
	}

	/**
	 * Decides how a request to a native entry point is answered.
	 *
	 * @param string $kind          login|admin|signup|'' (see entry_kind()).
	 * @param bool   $authenticated Whether the visitor has a valid session.
	 * @param string $method        HTTP method.
	 * @param array  $query         Query arguments ($_GET, slashed).
	 * @return string pass (normal WordPress), block (safe response) or handoff (valid token → route).
	 */
	public function decide( string $kind, bool $authenticated, string $method, array $query ): string {
		if ( ! $this->active || '' === $kind || $authenticated ) {
			return 'pass';
		}
		if ( 'login' !== $kind ) {
			return 'block';
		}
		$action = isset( $query['action'] ) && is_string( $query['action'] ) ? $query['action'] : 'login';

		// Post password forms cached before the route existed keep working; this sets only
		// the post-password cookie and redirects back to the post.
		if ( 'postpass' === $action && 'POST' === $method ) {
			return 'pass';
		}
		// Emailed links that carry a valid secret are handed to the route with the same query.
		if ( isset( $query['key'], $query['login'] ) && is_string( $query['key'] ) && is_string( $query['login'] ) ) {
			$user = check_password_reset_key( wp_unslash( $query['key'] ), wp_unslash( $query['login'] ) );
			return $user instanceof \WP_User ? 'handoff' : 'block';
		}
		if ( 'confirmaction' === $action && isset( $query['request_id'], $query['confirm_key'] ) && is_scalar( $query['request_id'] ) && is_string( $query['confirm_key'] ) ) {
			$request_id = absint( $query['request_id'] );
			// wp_validate_user_request_key() reads the request before checking it exists.
			if ( ! $request_id || ! wp_get_user_request( $request_id ) instanceof \WP_User_Request ) {
				return 'block';
			}
			$valid = wp_validate_user_request_key( $request_id, sanitize_text_field( wp_unslash( $query['confirm_key'] ) ) );
			return true === $valid ? 'handoff' : 'block';
		}
		if ( 'entered_recovery_mode' === $action && class_exists( 'WP_Recovery_Mode_Cookie_Service' ) ) {
			$cookies = new \WP_Recovery_Mode_Cookie_Service();
			return $cookies->is_cookie_set() && true === $cookies->validate_cookie() ? 'handoff' : 'block';
		}
		return 'block';
	}

	/**
	 * wp_loaded: answers signed-out requests to native entry points.
	 */
	public function guard() {
		if ( $this->dispatching || ! $this->active || self::is_cli() || wp_doing_ajax() || wp_doing_cron() || wp_installing()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return;
		}
		$kind = self::entry_kind();
		if ( '' === $kind ) {
			return;
		}
		$method   = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
		$decision = $this->decide( $kind, self::has_session(), $method, $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision; secrets are validated by WordPress.
		if ( 'handoff' === $decision ) {
			$this->handoff();
		} elseif ( 'block' === $decision ) {
			$this->respond_blocked( $kind );
		}
	}

	/**
	 * Valid session: an auth cookie for this context or a valid logged-in cookie.
	 */
	public static function has_session(): bool {
		if ( is_user_logged_in() ) {
			return true;
		}
		if ( ! defined( 'LOGGED_IN_COOKIE' ) || empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			return false;
		}
		return false !== wp_validate_auth_cookie( '', 'logged_in' );
	}

	private function handoff() {
		// $_SERVER is slashed by wp_magic_quotes() at this point.
		$query = isset( $_SERVER['QUERY_STRING'] ) && is_string( $_SERVER['QUERY_STRING'] ) ? wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- appended to our own URL and sanitized by wp_safe_redirect().
		nocache_headers();
		wp_safe_redirect( Config::route_url( $this->slug ) . ( '' !== $query ? '?' . $query : '' ), 302, 'Cradle Login' );
		exit;
	}

	/**
	 * Configured safe response. Reveals nothing about the route.
	 *
	 * @param string $kind Entry kind (login|admin|signup).
	 */
	private function respond_blocked( string $kind ) {
		$mode = Config::values()['blocked_response'];
		if ( 'redirect_home' === $mode ) {
			nocache_headers();
			wp_safe_redirect( home_url( '/' ), 302 );
			exit;
		}
		if ( 'plain_not_found' === $mode ) {
			self::plain_not_found();
		}
		self::theme_not_found( 'admin' === $kind );
	}

	private static function plain_not_found() {
		status_header( 404 );
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
		}
		printf(
			'<!DOCTYPE html><html lang="%1$s"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>%2$s</title></head><body><h1>%3$s</h1><p>%4$s</p></body></html>',
			esc_attr( get_bloginfo( 'language' ) ),
			esc_html__( '404 Not Found', 'cradle-login' ),
			esc_html__( 'Not Found', 'cradle-login' ),
			esc_html__( 'The requested URL was not found on this server.', 'cradle-login' )
		);
		exit;
	}

	/**
	 * Renders the theme's ordinary 404 template with a 404 status, the same page a
	 * visitor sees for any missing URL. Rendering is buffered: if the theme or a plugin
	 * throws, the minimal 404 is sent instead of a partial page.
	 *
	 * @param bool $admin_context The request came in through /wp-admin/.
	 */
	private static function theme_not_found( bool $admin_context ) {
		if ( $admin_context ) {
			// Signed-out admin request: render in frontend context (see Frontend_Screen).
			$GLOBALS['current_screen'] = new Frontend_Screen(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		$GLOBALS['pagenow'] = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( ! defined( 'WP_USE_THEMES' ) ) {
			define( 'WP_USE_THEMES', true );
		}
		add_filter( 'request', array( __CLASS__, 'force_not_found_query' ), PHP_INT_MAX );
		add_filter( 'do_parse_request', '__return_true', PHP_INT_MAX );
		remove_action( 'template_redirect', 'redirect_canonical' );
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
		remove_action( 'template_redirect', 'wp_old_slug_redirect' );

		$level = ob_get_level();
		ob_start();
		try {
			nocache_headers();
			wp();
			status_header( 404 );
			require ABSPATH . WPINC . '/template-loader.php';
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			self::plain_not_found();
		}
		while ( ob_get_level() > $level ) {
			ob_end_flush();
		}
		exit;
	}

	/**
	 * `request` filter used only while rendering the safe 404 response.
	 */
	public static function force_not_found_query(): array {
		return array( 'error' => '404' );
	}

	/**
	 * Core redirects /login and /wp-login to wp_login_url() on 404s; for signed-out visitors
	 * that would reveal the route, so the redirect is skipped and the request stays a 404.
	 */
	public function suppress_admin_location_redirects() {
		if ( $this->active && ! self::has_session() ) {
			remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
		}
	}
}
