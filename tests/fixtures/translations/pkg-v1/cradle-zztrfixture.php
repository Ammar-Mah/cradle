<?php
/**
 * Cradle Translations test fixture package, version 1 (copied into the plugins directory by the
 * translations test suite, then removed). Deliberately has no "Plugin Name" header, so WordPress
 * never lists or loads it as a plugin.
 *
 * Version: 1.0.0
 */

defined( 'ABSPATH' ) || exit;

function cradle_zztrfixture_strings( $label, $n ) {
	return array(
		__( 'Fixture kept', 'cradle-zztrfixture' ),
		__( 'Fixture removed', 'cradle-zztrfixture' ),
		_x( 'Fixture word', 'first meaning', 'cradle-zztrfixture' ),
		_x( 'Fixture word', 'second meaning', 'cradle-zztrfixture' ),
		/* translators: %d: number of apples */
		sprintf( _n( '%d fixture apple', '%d fixture apples', $n, 'cradle-zztrfixture' ), $n ),
		__( $label, 'cradle-zztrfixture' ),
	);
}
