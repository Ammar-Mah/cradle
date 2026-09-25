<?php
/**
 * Runs Cradle integration tests against the sandbox WordPress.
 *
 * Usage: php tests/run.php [name-filter] [--suite=core,blocks,...]
 * Each file in tests/integration/*.php registers tests with cradle_test().
 * A missing tool or unavailable precondition is reported as SKIP, never PASS.
 */

$suite = null;
foreach ( $argv as $i => $arg ) {
	if ( str_starts_with( $arg, '--suite=' ) ) {
		$suite = explode( ',', substr( $arg, 8 ) );
		unset( $argv[ $i ] );
	}
}
$argv = array_values( $argv );

$GLOBALS['cradle_test_admin_context'] = true;
require __DIR__ . '/lib/bootstrap.php';

foreach ( glob( __DIR__ . '/integration/*.php' ) as $file ) {
	if ( $suite && ! in_array( basename( $file, '.php' ), $suite, true ) ) {
		continue;
	}
	require $file;
}

exit( cradle_run_tests() );
