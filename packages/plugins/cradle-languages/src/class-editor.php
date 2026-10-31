<?php
/**
 * Block editor integration: the configured languages for the two blocks (handle
 * `cradle-languages-editor`, inline data only) and the "Language" document panel for entries
 * of translatable types (handle `cradle-languages-panel`).
 *
 * @package Cradle\Languages
 */

namespace Cradle\Languages;

defined( 'ABSPATH' ) || exit;

final class Editor {

	const PANEL_HANDLE = 'cradle-languages-panel';

	public static function hooks() {
		self::register();
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue' ) );
	}

	public static function register() {
		if ( wp_script_is( Module::EDITOR_HANDLE, 'registered' ) ) {
			return;
		}
		// Data-only handle that the block editor scripts depend on (blocking: block scripts load in the head).
		wp_register_script( Module::EDITOR_HANDLE, false, array(), CRADLE_LANGUAGES_VERSION, false );
		$languages = array();
		foreach ( Languages::all() as $lang ) {
			$languages[] = array(
				'slug'      => $lang['slug'],
				'name'      => $lang['name'],
				'native'    => $lang['native'],
				'dir'       => $lang['dir'],
				'isDefault' => $lang['default'],
			);
		}
		wp_add_inline_script( Module::EDITOR_HANDLE, 'window.cradleLanguagesEditor = ' . wp_json_encode( array( 'languages' => $languages ) ) . ';' );
		\Cradle\Core\Assets::register_script(
			self::PANEL_HANDLE,
			CRADLE_LANGUAGES_FILE,
			'assets/js/panel.js',
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-notices', Module::EDITOR_HANDLE ),
			CRADLE_LANGUAGES_VERSION
		);
		wp_set_script_translations( self::PANEL_HANDLE, 'cradle-languages', CRADLE_LANGUAGES_DIR . 'languages' );
	}

	public static function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post   = get_post();
		if ( ! $screen || 'post' !== $screen->base || ! $post instanceof \WP_Post || ! Content::is_translatable_post_type( $post->post_type ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		wp_enqueue_script( self::PANEL_HANDLE );
	}
}
