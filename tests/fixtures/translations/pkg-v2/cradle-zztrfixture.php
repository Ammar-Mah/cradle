<?php
/**
 * Cradle Translations test fixture package, version 2: "Fixture removed" vanished and
 * "Fixture added" appeared, simulating a package update.
 *
 * Version: 2.0.0
 */

defined( 'ABSPATH' ) || exit;

function cradle_zztrfixture_strings( $label, $n ) {
	return array(
		__( 'Fixture kept', 'cradle-zztrfixture' ),
		__( 'Fixture added', 'cradle-zztrfixture' ),
		_x( 'Fixture word', 'first meaning', 'cradle-zztrfixture' ),
		_x( 'Fixture word', 'second meaning', 'cradle-zztrfixture' ),
		/* translators: %d: number of apples */
		sprintf( _n( '%d fixture apple', '%d fixture apples', $n, 'cradle-zztrfixture' ), $n ),
		__( $label, 'cradle-zztrfixture' ),
	);
}
