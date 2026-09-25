<?php
/**
 * Block editor integration data for the two blocks.
 *
 * The shared editor controls script (`cradle-social-editor`) is a dependency
 * of both blocks' editor scripts. It receives only non-sensitive choice lists:
 * provider IDs/labels, which profile services are configured (not their
 * URLs) and the site's default share services.
 *
 * @package Cradle\Social
 */

namespace Cradle\Social;

defined( 'ABSPATH' ) || exit;

final class Editor {

	const HANDLE = 'cradle-social-editor';

	public static function register_assets() {
		// Loaded like the block editor scripts that depend on it (no defer strategy).
		wp_register_script(
			self::HANDLE,
			plugins_url( 'assets/js/editor-controls.js', CRADLE_SOCIAL_FILE ),
			array( 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-server-side-render' ),
			\Cradle\Core\Assets::version( CRADLE_SOCIAL_FILE, 'assets/js/editor-controls.js', CRADLE_SOCIAL_VERSION ),
			array( 'in_footer' => false )
		);
		wp_set_script_translations( self::HANDLE, 'cradle-social', CRADLE_SOCIAL_DIR . 'languages' );
	}

	public static function enqueue() {
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}
		$profiles = array();
		foreach ( Config::enabled_profiles() as $row ) {
			$profiles[] = array(
				'id'    => $row['service'],
				'label' => Providers::label( $row['service'] ),
			);
		}
		$share = array();
		foreach ( Providers::share_ids() as $id ) {
			$share[] = array(
				'id'    => $id,
				'label' => Providers::label( $id ),
			);
		}
		$data = array(
			'profiles'      => $profiles,
			'share'         => $share,
			'shareDefaults' => Config::enabled_share_services(),
			'settingsUrl'   => current_user_can( Lifecycle::CAPABILITY ) ? admin_url( 'admin.php?page=cradle-social' ) : '',
		);
		wp_add_inline_script( self::HANDLE, 'window.cradleSocialEditor = ' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}
}
