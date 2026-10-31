<?php
/**
 * Fixed-window rate limit per form and client network (N submissions per hour).
 *
 * Backed by transients `cradle_forms_rl_<hash>` (the bucket key is an HMAC of form, IP hash and
 * hour; no raw IP is stored). Transient increments are not atomic: a burst of simultaneous
 * requests from one client can exceed the limit by a few submissions. Attempts that fail
 * validation (honest mistakes) are given back; rejected spam keeps its slot.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Rate_Limit {

	const WINDOW = HOUR_IN_SECONDS;

	private static function key( int $form_id, string $client ): string {
		$window = (int) floor( time() / self::WINDOW );
		return 'cradle_forms_rl_' . substr( Security::hmac( $form_id . '|' . $client . '|' . $window, 'rate' ), 0, 40 );
	}

	/**
	 * Reserves one attempt. Returns false when the limit is already reached (nothing reserved).
	 */
	public static function consume( int $form_id, string $client, int $limit ): bool {
		if ( $limit <= 0 ) {
			return true; // Rate limiting is off for this form.
		}
		$key   = self::key( $form_id, $client );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, self::WINDOW );
		return true;
	}

	public static function release( int $form_id, string $client, int $limit ) {
		if ( $limit <= 0 ) {
			return;
		}
		$key   = self::key( $form_id, $client );
		$count = (int) get_transient( $key );
		if ( $count > 0 ) {
			set_transient( $key, $count - 1, self::WINDOW );
		}
	}

	public static function clear( int $form_id, string $client ) {
		delete_transient( self::key( $form_id, $client ) );
	}
}
