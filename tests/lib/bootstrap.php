<?php
/**
 * Loads the sandbox WordPress for CLI integration tests and provides a tiny
 * assertion harness plus authenticated HTTP helpers.
 *
 * Tests run against the disposable sandbox in ../../wp only.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'CRADLE_TEST_ROOT', dirname( __DIR__, 2 ) );
$cradle_wp = CRADLE_TEST_ROOT . '/wp';

$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['SERVER_NAME']     = 'localhost';
$_SERVER['SERVER_PORT']     = 80;
$_SERVER['REQUEST_URI']     = '/cradle/wp/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

if ( ! empty( $GLOBALS['cradle_test_admin_context'] ) ) {
	define( 'WP_ADMIN', true );
}

require $cradle_wp . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';

$GLOBALS['cradle_tests']   = array();
$GLOBALS['cradle_results'] = array(
	'pass' => 0,
	'fail' => 0,
	'skip' => 0,
);

function cradle_test( string $name, callable $fn ) {
	$GLOBALS['cradle_tests'][] = array( $name, $fn );
}

class Cradle_Test_Failure extends Exception {}
class Cradle_Test_Skip extends Exception {}

function assert_true( $condition, string $message = 'Expected true' ) {
	if ( true !== (bool) $condition ) {
		throw new Cradle_Test_Failure( $message );
	}
}

function assert_false( $condition, string $message = 'Expected false' ) {
	assert_true( ! $condition, $message );
}

function assert_same( $expected, $actual, string $message = '' ) {
	if ( $expected !== $actual ) {
		throw new Cradle_Test_Failure( trim( $message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ) );
	}
}

function assert_contains( string $needle, string $haystack, string $message = '' ) {
	if ( false === strpos( $haystack, $needle ) ) {
		throw new Cradle_Test_Failure( trim( $message . ' Missing: ' . $needle ) );
	}
}

function assert_not_contains( string $needle, string $haystack, string $message = '' ) {
	if ( false !== strpos( $haystack, $needle ) ) {
		throw new Cradle_Test_Failure( trim( $message . ' Unexpected: ' . $needle ) );
	}
}

function assert_wp_error( $value, string $code = '', string $message = 'Expected WP_Error' ) {
	if ( ! is_wp_error( $value ) || ( '' !== $code && $value->get_error_code() !== $code ) ) {
		throw new Cradle_Test_Failure( $message . ( is_wp_error( $value ) ? ' (got ' . $value->get_error_code() . ')' : '' ) );
	}
}

function skip_test( string $reason ) {
	throw new Cradle_Test_Skip( $reason );
}

/**
 * Creates (or reuses) a disposable test user for a role.
 */
function cradle_test_user( string $role ): int {
	$login = 'cradle_test_' . $role;
	$user  = get_user_by( 'login', $login );
	if ( $user ) {
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
		// Another test process may have created it concurrently.
		clean_user_cache( 0 );
		wp_cache_delete( $login, 'userlogins' );
		$user = get_user_by( 'login', $login );
		if ( ! $user ) {
			throw new Cradle_Test_Failure( 'Could not create test user ' . $login . ': ' . $id->get_error_message() );
		}
		return (int) $user->ID;
	}
	return (int) $id;
}

/**
 * Mints auth cookies for a user (no password involved) and returns them for wp_remote_* calls.
 *
 * @return array{cookies:WP_Http_Cookie[],token:string,logged_in:string}
 */
function cradle_test_cookies( int $user_id ): array {
	$expiration = time() + HOUR_IN_SECONDS;
	$manager    = WP_Session_Tokens::get_instance( $user_id );
	$token      = $manager->create( $expiration );
	$auth       = wp_generate_auth_cookie( $user_id, $expiration, 'auth', $token );
	$logged_in  = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token );
	return array(
		'cookies'   => array(
			new WP_Http_Cookie( array( 'name' => AUTH_COOKIE, 'value' => $auth ) ),
			new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => $logged_in ) ),
		),
		'token'     => $token,
		'logged_in' => $logged_in,
	);
}

/**
 * Creates a nonce exactly as the browser session for $user_id would receive it.
 */
function cradle_test_nonce( int $user_id, array $session, string $action ): string {
	$previous                     = get_current_user_id();
	$_COOKIE[ LOGGED_IN_COOKIE ] = $session['logged_in'];
	wp_set_current_user( $user_id );
	$nonce = wp_create_nonce( $action );
	unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	wp_set_current_user( $previous );
	return $nonce;
}

/**
 * HTTP request against the sandbox as a given user (0 = anonymous).
 */
function cradle_test_http( string $method, string $path, array $args = array(), int $user_id = 0, ?array $session = null ) {
	$url = str_starts_with( $path, 'http' ) ? $path : home_url( $path );
	if ( $user_id && ! $session ) {
		$session = cradle_test_cookies( $user_id );
	}
	$args = array_merge(
		array(
			'method'      => $method,
			'timeout'     => 60,
			'redirection' => 0,
			'cookies'     => $session ? $session['cookies'] : array(),
		),
		$args
	);
	return wp_remote_request( $url, $args );
}

function cradle_run_tests(): int {
	$filter = $GLOBALS['argv'][1] ?? '';
	foreach ( $GLOBALS['cradle_tests'] as $test ) {
		list( $name, $fn ) = $test;
		if ( '' !== $filter && false === stripos( $name, $filter ) ) {
			continue;
		}
		wp_set_current_user( 0 );
		try {
			$fn();
			$GLOBALS['cradle_results']['pass']++;
			echo "PASS  {$name}\n";
		} catch ( Cradle_Test_Skip $e ) {
			$GLOBALS['cradle_results']['skip']++;
			echo "SKIP  {$name}: {$e->getMessage()}\n";
		} catch ( Throwable $e ) {
			$GLOBALS['cradle_results']['fail']++;
			echo "FAIL  {$name}: {$e->getMessage()}\n";
			if ( ! $e instanceof Cradle_Test_Failure ) {
				echo '      at ' . $e->getFile() . ':' . $e->getLine() . "\n";
			}
		}
	}
	$r = $GLOBALS['cradle_results'];
	echo "\n{$r['pass']} passed, {$r['fail']} failed, {$r['skip']} skipped\n";
	return $r['fail'] > 0 ? 1 : 0;
}
