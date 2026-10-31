<?php
/**
 * The `cradle_form` post type: forms are content edited in the block editor.
 *
 * Not public (no front-end URL, not searchable), listed on Cradle → Forms. Every capability
 * maps to `cradle_manage_forms`. The REST endpoint (`wp/v2/cradle-forms`) is readable only by
 * form managers (see Rest_Forms_Controller), so notification addresses never leak.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Post_Type {

	const NAME = 'cradle_form';

	/** Address names (`formSlug`) that can select a form: lowercase letters, digits and hyphens. */
	const SLUG_PATTERN = '/^[a-z0-9-]{1,200}$/';

	/** Static content blocks allowed between fields (plus the field blocks). */
	const CONTENT_BLOCKS = array( 'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/separator', 'core/spacer' );

	public static function register() {
		register_post_type(
			self::NAME,
			array(
				'labels'                => array(
					'name'               => __( 'Forms', 'cradle-forms' ),
					'singular_name'      => __( 'Form', 'cradle-forms' ),
					'add_new'            => __( 'Add form', 'cradle-forms' ),
					'add_new_item'       => __( 'Add form', 'cradle-forms' ),
					'edit_item'          => __( 'Edit form', 'cradle-forms' ),
					'new_item'           => __( 'New form', 'cradle-forms' ),
					'search_items'       => __( 'Search forms', 'cradle-forms' ),
					'not_found'          => __( 'No forms found.', 'cradle-forms' ),
					'not_found_in_trash' => __( 'No forms found in Trash.', 'cradle-forms' ),
					'item_published'     => __( 'Form published.', 'cradle-forms' ),
					'item_updated'       => __( 'Form updated.', 'cradle-forms' ),
				),
				'public'                => false,
				'publicly_queryable'    => false,
				'exclude_from_search'   => true,
				'show_ui'               => true,
				'show_in_menu'          => false,
				'show_in_nav_menus'     => false,
				'show_in_admin_bar'     => false,
				'show_in_rest'          => true,
				'rest_base'             => 'cradle-forms',
				'rest_controller_class' => Rest_Forms_Controller::class,
				'has_archive'           => false,
				'rewrite'               => false,
				'query_var'             => false,
				'hierarchical'          => false,
				'supports'              => array( 'title', 'editor', 'revisions', 'custom-fields' ),
				'map_meta_cap'          => true,
				'capability_type'       => array( 'cradle_form', 'cradle_forms' ),
				'capabilities'          => array(
					'edit_posts'             => Caps::MANAGE,
					'edit_others_posts'      => Caps::MANAGE,
					'edit_private_posts'     => Caps::MANAGE,
					'edit_published_posts'   => Caps::MANAGE,
					'publish_posts'          => Caps::MANAGE,
					'read_private_posts'     => Caps::MANAGE,
					'delete_posts'           => Caps::MANAGE,
					'delete_private_posts'   => Caps::MANAGE,
					'delete_published_posts' => Caps::MANAGE,
					'delete_others_posts'    => Caps::MANAGE,
					'create_posts'           => Caps::MANAGE,
				),
				'template'              => array(
					array(
						'cradle-forms/field-text',
						array(
							'name'         => 'name',
							'label'        => __( 'Name', 'cradle-forms' ),
							'required'     => true,
							'autocomplete' => 'name',
						),
					),
					array(
						'cradle-forms/field-email',
						array(
							'name'           => 'email',
							'label'          => __( 'Email', 'cradle-forms' ),
							'required'       => true,
							'submitterEmail' => true,
						),
					),
					array(
						'cradle-forms/field-textarea',
						array(
							'name'     => 'message',
							'label'    => __( 'Message', 'cradle-forms' ),
							'required' => true,
						),
					),
				),
			)
		);

		// Cradle Languages (optional): forms are translatable by default; each language has its own
		// linked form post. "Add translation" copies the settings; the schema is derived from the copy.
		add_post_type_support( self::NAME, 'cradle-languages', array( 'default' => 'translatable' ) );
		add_filter( 'cradle_languages/copy_protected_meta', array( __CLASS__, 'copy_meta' ), 10, 2 );

		Form_Settings::register_meta();

		register_rest_field(
			self::NAME,
			'cradle_form_status',
			array(
				'get_callback' => static function ( $post ) {
					return Form_Schema::status( (int) ( is_array( $post ) ? $post['id'] : 0 ) );
				},
				'schema'       => array(
					'type'     => 'object',
					'context'  => array( 'edit' ),
					'readonly' => true,
				),
			)
		);

		add_action( 'wp_after_insert_post', array( __CLASS__, 'after_save' ), 10, 2 );
		add_filter( 'allowed_block_types_all', array( __CLASS__, 'allowed_blocks' ), 20, 2 );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ), 10, 2 );
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'use_block_editor' ), 20, 2 );
	}

	/**
	 * Derives the schema after the post, its meta and terms are saved (REST and classic saves).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function after_save( $post_id, $post ) {
		if ( ! $post instanceof \WP_Post || self::NAME !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		Form_Schema::update( (int) $post_id );
	}

	/**
	 * Inside a form: only field blocks and simple static content. Elsewhere unchanged
	 * (field blocks hide themselves from the inserter outside forms; see fields-editor.js).
	 *
	 * @param bool|string[]            $allowed Allowed block types.
	 * @param \WP_Block_Editor_Context $context Editor context.
	 * @return bool|string[]
	 */
	public static function allowed_blocks( $allowed, $context ) {
		if ( ! is_object( $context ) || empty( $context->post ) || self::NAME !== $context->post->post_type ) {
			return $allowed;
		}
		return array_merge( Field_Types::block_names(), self::CONTENT_BLOCKS );
	}

	/**
	 * Cradle Languages copies protected meta into a new translation only when allowed. The form
	 * settings (notifications, routing, spam limits) are copied; the schema and its errors are not:
	 * they are derived again from the copied content when the translation is inserted, so the
	 * schema always comes from the post content.
	 *
	 * @param bool   $copy Whether to copy.
	 * @param string $key  Meta key.
	 */
	public static function copy_meta( $copy, $key ) {
		if ( Form_Settings::META === $key ) {
			return true;
		}
		if ( Form_Schema::META === $key || Form_Schema::ERRORS_META === $key ) {
			return false;
		}
		return $copy;
	}

	/**
	 * Forms always use the block editor (their fields are blocks).
	 */
	public static function use_block_editor( $use, $post_type ) {
		return self::NAME === $post_type ? true : $use;
	}

	/**
	 * Highlights Cradle → Forms while a form is edited.
	 */
	public static function parent_file( $parent_file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && self::NAME === $screen->post_type ? 'cradle' : $parent_file;
	}

	public static function submenu_file( $submenu_file, $parent_file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && self::NAME === $screen->post_type ? 'cradle-forms' : $submenu_file;
	}

	/**
	 * A published form with this ID, or null.
	 */
	public static function published( int $form_id ): ?\WP_Post {
		$post = $form_id > 0 ? get_post( $form_id ) : null;
		return $post && self::NAME === $post->post_type && 'publish' === $post->post_status && '' === $post->post_password ? $post : null;
	}

	/**
	 * ID of the published form whose address name (post_name) is $slug, or 0. Every language is
	 * searched (`cradle_lang` = all): slugs are unique per post type.
	 */
	public static function published_by_slug( string $slug ): int {
		if ( ! preg_match( self::SLUG_PATTERN, $slug ) ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'        => self::NAME,
				'post_status'      => 'publish',
				'name'             => $slug,
				'fields'           => 'ids',
				'numberposts'      => 1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'cradle_lang'      => 'all',
			)
		);
		return $ids && self::published( (int) $ids[0] ) ? (int) $ids[0] : 0;
	}
}
