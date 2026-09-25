<?php
/**
 * Marker returned by field sanitize callbacks for invalid values.
 *
 * WordPress sanitize callbacks cannot fail, so an invalid low-level write
 * (update_post_meta() from other code) returns this marker; the
 * add/update metadata short-circuit filters in Meta then refuse the write
 * instead of silently storing a coerced value.
 *
 * @package Cradle\Content
 */

namespace Cradle\Content;

defined( 'ABSPATH' ) || exit;

final class Rejected_Value {

	public string $reason;

	public function __construct( string $reason ) {
		$this->reason = $reason;
	}
}
