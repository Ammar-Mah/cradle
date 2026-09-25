<?php
/**
 * Minimal stand-in for WP_Screen used only while a signed-out /wp-admin/ request is
 * answered with the theme's 404 page. is_admin() consults $GLOBALS['current_screen']
 * before the WP_ADMIN constant, so installing this object lets the theme render in
 * frontend context (no admin bar, frontend assets) instead of half-loaded admin context.
 * The request ends right after the 404 page; admin code never runs with it.
 *
 * @package Cradle\Login
 */

namespace Cradle\Login;

defined( 'ABSPATH' ) || exit;

final class Frontend_Screen {

	/** @var string */
	public $id = 'front';

	/** @var string */
	public $base = 'front';

	/** @var string */
	public $action = '';

	/** @var string */
	public $post_type = '';

	/** @var string */
	public $taxonomy = '';

	/** @var bool */
	public $is_network = false;

	/** @var bool */
	public $is_user = false;

	/**
	 * Mirrors WP_Screen::in_admin(): this request is not in any admin.
	 *
	 * @param string|null $admin Admin type to check.
	 */
	public function in_admin( $admin = null ): bool {
		unset( $admin );
		return false;
	}

	/**
	 * @param bool|null $set Ignored.
	 */
	public function is_block_editor( $set = null ): bool {
		unset( $set );
		return false;
	}

	/**
	 * Unknown WP_Screen properties read by third-party code resolve to null.
	 *
	 * @param string $name Property.
	 */
	public function __get( $name ) {
		unset( $name );
		return null;
	}

	/**
	 * Unknown WP_Screen methods are no-ops.
	 *
	 * @param string $name Method.
	 * @param array  $args Arguments.
	 */
	public function __call( $name, $args ) {
		unset( $name, $args );
		return null;
	}
}
