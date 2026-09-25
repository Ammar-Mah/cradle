<?php
/**
 * Classifies admin screens into approval groups for scoped styling.
 *
 * Cradle Admin's own settings page and full-screen app screens (site editor,
 * customizer) are always excluded, so a broken visual setting can never make
 * its own recovery screen unusable.
 *
 * @package Cradle\Admin
 */

namespace Cradle\Admin;

defined( 'ABSPATH' ) || exit;

final class Screens {

	const EXCLUDED = 'excluded';

	const GROUPS = array( 'dashboard', 'content', 'block-editor', 'media', 'comments', 'users', 'appearance', 'plugins', 'tools', 'settings', 'cradle', 'other' );

	/**
	 * Groups approved by default: every group except unknown third-party screens.
	 */
	public static function default_groups(): array {
		return array_values( array_diff( self::GROUPS, array( 'other' ) ) );
	}

	/**
	 * Translated labels (call after init).
	 */
	public static function labels(): array {
		return array(
			'dashboard'    => __( 'Dashboard', 'cradle-admin' ),
			'content'      => __( 'Content lists, classic editor and terms (includes WooCommerce product lists)', 'cradle-admin' ),
			'block-editor' => __( 'Block editor (accent color only; layout is never changed there)', 'cradle-admin' ),
			'media'        => __( 'Media library', 'cradle-admin' ),
			'comments'     => __( 'Comments', 'cradle-admin' ),
			'users'        => __( 'Users and profiles', 'cradle-admin' ),
			'appearance'   => __( 'Themes, menus and widgets', 'cradle-admin' ),
			'plugins'      => __( 'Plugins', 'cradle-admin' ),
			'tools'        => __( 'Tools, updates and site health', 'cradle-admin' ),
			'settings'     => __( 'Settings screens', 'cradle-admin' ),
			'cradle'       => __( 'Other Cradle screens', 'cradle-admin' ),
			'other'        => __( 'Other plugin screens (for example WooCommerce app pages)', 'cradle-admin' ),
		);
	}

	public static function is_own_page( $screen ): bool {
		return $screen instanceof \WP_Screen && (bool) preg_match( '/_page_' . preg_quote( Module::PAGE, '/' ) . '$/', (string) $screen->id );
	}

	/**
	 * @param \WP_Screen|null $screen Screen to classify.
	 * @return string One of GROUPS or 'excluded'.
	 */
	public static function group( $screen ): string {
		if ( ! $screen instanceof \WP_Screen ) {
			return self::EXCLUDED;
		}
		$group = self::classify( $screen );
		if ( self::EXCLUDED !== $group ) {
			/**
			 * Filters the approval group of an admin screen. Return one of the documented groups.
			 * Cradle Admin's own page and the site editor cannot be re-included.
			 *
			 * @param string     $group  Group ID.
			 * @param \WP_Screen $screen Current screen.
			 */
			$filtered = apply_filters( 'cradle_admin/screen_group', $group, $screen );
			$group    = is_string( $filtered ) && ( in_array( $filtered, self::GROUPS, true ) || self::EXCLUDED === $filtered ) ? $filtered : $group;
		}
		return $group;
	}

	private static function classify( \WP_Screen $screen ): string {
		$id   = (string) $screen->id;
		$base = (string) $screen->base;
		if ( self::is_own_page( $screen ) || in_array( $base, array( 'site-editor', 'customize' ), true ) ) {
			return self::EXCLUDED;
		}
		if ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return 'block-editor';
		}
		if ( 'toplevel_page_cradle' === $id || preg_match( '/_page_cradle(-[a-z0-9-]+)?$/', $id ) ) {
			return 'cradle';
		}
		switch ( $base ) {
			case 'dashboard':
				return 'dashboard';
			case 'edit':
			case 'post':
			case 'edit-tags':
			case 'term':
				return 'content';
			case 'upload':
			case 'media':
			case 'async-upload':
				return 'media';
			case 'edit-comments':
			case 'comment':
				return 'comments';
			case 'users':
			case 'user-edit':
			case 'profile':
			case 'user':
				return 'users';
			case 'themes':
			case 'theme-install':
			case 'theme-editor':
			case 'nav-menus':
			case 'widgets':
				return 'appearance';
			case 'plugins':
			case 'plugin-install':
			case 'plugin-editor':
				return 'plugins';
			case 'tools':
			case 'import':
			case 'export':
			case 'site-health':
			case 'update-core':
			case 'export-personal-data':
			case 'erase-personal-data':
				return 'tools';
		}
		if ( 0 === strpos( $base, 'options' ) || 'privacy' === $base ) {
			return 'settings';
		}
		return 'other';
	}

	/**
	 * Whether a screen belongs to one of the approved groups.
	 */
	public static function is_approved( $screen, array $approved ): bool {
		$group = self::group( $screen );
		return self::EXCLUDED !== $group && in_array( $group, $approved, true );
	}
}
