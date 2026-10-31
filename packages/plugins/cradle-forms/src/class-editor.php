<?php
/**
 * Block editor integration: the shared field-block editor script, the `cradle/form` block data
 * and the form document panels (settings, notifications, schema notices).
 *
 * Client data is non-sensitive: published form IDs, address names and titles (form picker), the
 * site's allowed upload types and size, and for form managers on a form screen the list of
 * published pages (confirmation page picker).
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Editor {

	public static function hooks() {
		self::register_assets();
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue' ) );
		add_filter( 'block_categories_all', array( __CLASS__, 'category' ), 10, 2 );
	}

	/**
	 * Inserter category for the field blocks (they are only insertable inside forms).
	 */
	public static function category( $categories, $context = null ) {
		foreach ( (array) $categories as $category ) {
			if ( 'cradle-forms' === ( $category['slug'] ?? '' ) ) {
				return $categories;
			}
		}
		$categories[] = array(
			'slug'  => 'cradle-forms',
			'title' => __( 'Form fields', 'cradle-forms' ),
			'icon'  => null,
		);
		return $categories;
	}

	public static function register_assets() {
		$version = static function ( string $rel ) {
			return \Cradle\Core\Assets::version( CRADLE_FORMS_FILE, $rel, CRADLE_FORMS_VERSION );
		};
		// Loaded like block editor scripts (blocking, in the head), because block scripts depend on it.
		wp_register_script(
			Module::FIELDS_SCRIPT,
			plugins_url( 'assets/js/fields-editor.js', CRADLE_FORMS_FILE ),
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-data', 'wp-hooks' ),
			$version( 'assets/js/fields-editor.js' ),
			array( 'in_footer' => false )
		);
		wp_set_script_translations( Module::FIELDS_SCRIPT, 'cradle-forms', CRADLE_FORMS_DIR . 'languages' );
		wp_register_style( Module::EDITOR_STYLE, plugins_url( 'assets/css/editor.css', CRADLE_FORMS_FILE ), array(), $version( 'assets/css/editor.css' ) );
		wp_register_script(
			Module::FORM_EDITOR_SCRIPT,
			plugins_url( 'assets/js/form-editor.js', CRADLE_FORMS_FILE ),
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-core-data', 'wp-i18n', 'wp-notices', Module::FIELDS_SCRIPT ),
			$version( 'assets/js/form-editor.js' ),
			array( 'in_footer' => true )
		);
		wp_set_script_translations( Module::FORM_EDITOR_SCRIPT, 'cradle-forms', CRADLE_FORMS_DIR . 'languages' );
	}

	public static function enqueue() {
		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( Caps::MANAGE ) ) {
			return;
		}
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post_type = $screen && 'post' === $screen->base ? (string) $screen->post_type : '';
		$is_form   = Post_Type::NAME === $post_type;
		$forms     = array();
		if ( ! $is_form ) {
			foreach ( get_posts(
				array(
					'post_type'        => Post_Type::NAME,
					'post_status'      => 'publish',
					'numberposts'      => 200,
					'orderby'          => 'title',
					'order'            => 'ASC',
					'suppress_filters' => false,
				)
			) as $post ) {
				$forms[] = array(
					'id'    => (int) $post->ID,
					'slug'  => (string) $post->post_name,
					'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				);
			}
		}
		$data = array(
			'postType'     => $post_type,
			'isForm'       => $is_form,
			'forms'        => $forms,
			'canManage'    => current_user_can( Caps::MANAGE ),
			'manageUrl'    => admin_url( 'admin.php?page=cradle-forms' ),
			'newFormUrl'   => admin_url( 'post-new.php?post_type=' . Post_Type::NAME ),
			'extensions'   => Settings_Section::allowed_extensions(),
			'maxUploadMb'  => round( Settings_Section::max_bytes() / MB_IN_BYTES, 1 ),
			'autocomplete' => array_values( array_filter( Field_Types::AUTOCOMPLETE ) ),
		);
		wp_add_inline_script( Module::FIELDS_SCRIPT, 'window.cradleFormsEditor = ' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );

		if ( $is_form && current_user_can( Caps::MANAGE ) ) {
			$pages = array();
			foreach ( get_pages(
				array(
					'post_status' => 'publish',
					'number'      => 300,
					'sort_column' => 'post_title',
				)
			) as $page ) {
				$pages[] = array(
					'id'    => (int) $page->ID,
					'title' => html_entity_decode( get_the_title( $page ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				);
			}
			wp_enqueue_script( Module::FORM_EDITOR_SCRIPT );
			wp_add_inline_script(
				Module::FORM_EDITOR_SCRIPT,
				'window.cradleFormsDocument = ' . wp_json_encode(
					array(
						'pages'       => $pages,
						'defaults'    => Form_Settings::defaults(),
						'notice'      => Form_Settings::notification_defaults(),
						'entriesUrl'  => admin_url( 'admin.php?page=cradle-forms&view=entries&form=' ),
						'settingsUrl' => admin_url( 'admin.php?page=cradle-forms&view=settings' ),
					),
					JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
				) . ';',
				'before'
			);
			wp_enqueue_style( Module::EDITOR_STYLE );
		}
	}
}
