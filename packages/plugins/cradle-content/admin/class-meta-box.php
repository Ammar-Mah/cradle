<?php
/**
 * Classic editing surfaces for fields: meta boxes (post types without the block editor,
 * and "admin forms only" fields everywhere) and the term add/edit forms.
 *
 * Saves require the form nonce plus the object capability (edit_post / edit_term); each
 * field is validated independently, invalid fields keep their stored value and are reported.
 *
 * @package Cradle\Content\Admin
 */

namespace Cradle\Content\Admin;

use Cradle\Content\Field_Types;
use Cradle\Content\Meta;

defined( 'ABSPATH' ) || exit;

final class Meta_Box {

	const INPUT = 'cradle_cf';

	public static function hooks() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save_post' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		foreach ( Meta::instance()->subtypes( 'term' ) as $taxonomy ) {
			add_action( "{$taxonomy}_add_form_fields", array( __CLASS__, 'term_add_fields' ) );
			add_action( "{$taxonomy}_edit_form_fields", array( __CLASS__, 'term_edit_fields' ), 10, 2 );
			add_action( "created_{$taxonomy}", array( __CLASS__, 'save_term' ) );
			add_action( "edited_{$taxonomy}", array( __CLASS__, 'save_term' ) );
		}
	}

	/**
	 * Groups and fields edited in classic meta boxes for a post type.
	 *
	 * @return array<string,array{definition:array,fields:array}>
	 */
	public static function classic_groups( string $post_type ): array {
		$block_editor = function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $post_type );
		$out          = array();
		foreach ( Meta::instance()->groups_for( 'post', $post_type ) as $key => $group ) {
			$fields = array();
			foreach ( $group['fields'] as $field_key => $field ) {
				if ( ! $block_editor || 'none' === $field['exposure'] ) {
					$fields[ $field_key ] = $field;
				}
			}
			if ( $fields ) {
				$out[ $key ] = array(
					'definition' => $group['definition'],
					'fields'     => $fields,
				);
			}
		}
		return $out;
	}

	public static function enqueue( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$needed = false;
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && $screen->post_type ) {
			$needed = (bool) self::classic_groups( $screen->post_type );
		} elseif ( in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) && $screen->taxonomy ) {
			$needed = (bool) Meta::instance()->groups_for( 'term', $screen->taxonomy );
		}
		if ( ! $needed ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'cradle-admin' );
		wp_enqueue_script( 'cradle-admin' );
		wp_enqueue_style( 'cradle-content-fields', plugins_url( 'assets/css/fields.css', CRADLE_CONTENT_FILE ), array(), Admin::asset_version( 'assets/css/fields.css' ) );
		wp_enqueue_script(
			'cradle-content-fields',
			plugins_url( 'assets/js/fields.js', CRADLE_CONTENT_FILE ),
			array( 'wp-i18n', 'cradle-admin' ),
			Admin::asset_version( 'assets/js/fields.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_set_script_translations( 'cradle-content-fields', 'cradle-content', CRADLE_CONTENT_DIR . 'languages' );
	}

	/* ---------------------------------------------------------------------
	 * Posts
	 * ------------------------------------------------------------------- */

	public static function add( $post_type, $post = null ) {
		if ( ! is_string( $post_type ) ) {
			return;
		}
		foreach ( self::classic_groups( $post_type ) as $key => $group ) {
			add_meta_box(
				'cradle-content-' . $key,
				$group['definition']['title'],
				array( __CLASS__, 'render_box' ),
				$post_type,
				'side' === ( $group['definition']['position'] ?? 'normal' ) ? 'side' : 'normal',
				'default',
				array(
					'group'                              => $key,
					'__back_compat_meta_box'             => false,
					'__block_editor_compatible_meta_box' => true,
				)
			);
		}
	}

	public static function render_box( $post, $box ) {
		if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		$groups = self::classic_groups( $post->post_type );
		$group  = $groups[ $box['args']['group'] ?? '' ] ?? null;
		if ( ! $group ) {
			return;
		}
		wp_nonce_field( 'cradle_content_fields_' . $post->ID, 'cradle_content_fields_nonce' );
		if ( '' !== ( $group['definition']['description'] ?? '' ) ) {
			echo '<p class="description">' . esc_html( $group['definition']['description'] ) . '</p>';
		}
		echo '<div class="cradle-content-box">';
		foreach ( $group['fields'] as $field ) {
			$value = get_post_meta( $post->ID, $field['meta_key'], ! Field_Types::is_multi( $field ) );
			self::field_block( $field, $value, 'cradle-cf-' . $field['key'] );
		}
		echo '</div>';
	}

	private static function field_block( array $field, $value, string $id ) {
		$help_id = $field['help'] ? $id . '-help' : '';
		$label   = $field['label'] . ( ! empty( $field['required'] ) ? ' *' : '' );
		echo '<div class="cradle-content-box__field">';
		if ( in_array( $field['type'], array( 'group', 'multiselect' ), true ) ) {
			echo '<p class="cradle-content-box__label" id="' . esc_attr( $id ) . '-label"><strong>' . esc_html( $label ) . '</strong></p>';
		} else {
			echo '<p class="cradle-content-box__label"><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong></label></p>';
		}
		Field_Controls::control( $field, self::INPUT . '[' . $field['key'] . ']', $id, $value, $help_id );
		if ( $field['help'] ) {
			echo '<p class="description" id="' . esc_attr( $help_id ) . '">' . esc_html( $field['help'] ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Validates and stores submitted classic fields.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function save_post( $post_id, $post ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below.
		if ( ! isset( $_POST['cradle_content_fields_nonce'], $_POST[ self::INPUT ] ) || ! is_array( $_POST[ self::INPUT ] ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cradle_content_fields_nonce'] ) ), 'cradle_content_fields_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || ! $post instanceof \WP_Post ) {
			return;
		}
		$submitted = wp_unslash( $_POST[ self::INPUT ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated per field type.
		// phpcs:enable
		$errors = array();
		foreach ( self::classic_groups( $post->post_type ) as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( ! array_key_exists( $field['key'], $submitted ) ) {
					continue;
				}
				$error = self::save_field( 'post', (int) $post_id, $field, $submitted[ $field['key'] ] );
				if ( $error ) {
					$errors[] = $error;
				}
			}
		}
		self::remember_errors( $errors );
	}

	/**
	 * @return string Error message, or '' when stored.
	 */
	private static function save_field( string $object, int $object_id, array $field, $raw ): string {
		$raw     = Field_Controls::clean_submission( $field, $raw );
		$current = get_metadata( $object, $object_id, $field['meta_key'], ! Field_Types::is_multi( $field ) );
		$value   = Field_Types::validate(
			$field,
			$raw,
			array(
				'check_permissions' => true,
				'current'           => $current,
			)
		);
		if ( is_wp_error( $value ) ) {
			return sprintf( '%1$s: %2$s', $field['label'], $value->get_error_message() );
		}
		if ( ! Meta::store( $object, $object_id, $field, $value ) ) {
			/* translators: %s: field label */
			return sprintf( __( '%s could not be saved.', 'cradle-content' ), $field['label'] );
		}
		return '';
	}

	private static function remember_errors( array $errors ) {
		if ( $errors ) {
			set_transient( 'cradle_content_field_errors_' . get_current_user_id(), array_slice( $errors, 0, 20 ), 5 * MINUTE_IN_SECONDS );
		}
	}

	public static function notice() {
		$key    = 'cradle_content_field_errors_' . get_current_user_id();
		$errors = get_transient( $key );
		if ( ! is_array( $errors ) ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Some fields were not saved and kept their previous values:', 'cradle-content' ) . '</p><ul class="ul-disc">';
		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}
		echo '</ul></div>';
	}

	/* ---------------------------------------------------------------------
	 * Terms
	 * ------------------------------------------------------------------- */

	public static function term_add_fields( $taxonomy ) {
		$groups = Meta::instance()->groups_for( 'term', (string) $taxonomy );
		if ( ! $groups ) {
			return;
		}
		wp_nonce_field( 'cradle_content_term_new', 'cradle_content_term_nonce' );
		foreach ( $groups as $group ) {
			foreach ( $group['fields'] as $field ) {
				echo '<div class="form-field cradle-content-term-field">';
				self::field_block( $field, $field['default'] ?? null, 'cradle-cf-' . $field['key'] );
				echo '</div>';
			}
		}
	}

	public static function term_edit_fields( $term, $taxonomy ) {
		if ( ! $term instanceof \WP_Term || ! current_user_can( 'edit_term', $term->term_id ) ) {
			return;
		}
		$groups = Meta::instance()->groups_for( 'term', (string) $taxonomy );
		if ( ! $groups ) {
			return;
		}
		wp_nonce_field( 'cradle_content_term_' . $term->term_id, 'cradle_content_term_nonce' );
		foreach ( $groups as $group ) {
			foreach ( $group['fields'] as $field ) {
				$id    = 'cradle-cf-' . $field['key'];
				$value = get_term_meta( $term->term_id, $field['meta_key'], ! Field_Types::is_multi( $field ) );
				echo '<tr class="form-field cradle-content-term-field"><th scope="row">';
				if ( in_array( $field['type'], array( 'group', 'multiselect' ), true ) ) {
					echo esc_html( $field['label'] );
				} else {
					echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label>';
				}
				echo '</th><td>';
				Field_Controls::control( $field, self::INPUT . '[' . $field['key'] . ']', $id, $value, $field['help'] ? $id . '-help' : '' );
				if ( $field['help'] ) {
					echo '<p class="description" id="' . esc_attr( $id . '-help' ) . '">' . esc_html( $field['help'] ) . '</p>';
				}
				echo '</td></tr>';
			}
		}
	}

	/**
	 * @param int $term_id Term ID.
	 */
	public static function save_term( $term_id ) {
		$term_id = (int) $term_id;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below.
		if ( ! isset( $_POST['cradle_content_term_nonce'], $_POST[ self::INPUT ] ) || ! is_array( $_POST[ self::INPUT ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['cradle_content_term_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'cradle_content_term_' . $term_id ) && ! ( 'created_' === substr( current_action(), 0, 8 ) && wp_verify_nonce( $nonce, 'cradle_content_term_new' ) ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term ) {
			return;
		}
		$submitted = wp_unslash( $_POST[ self::INPUT ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated per field type.
		// phpcs:enable
		$errors = array();
		foreach ( Meta::instance()->groups_for( 'term', $term->taxonomy ) as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( ! array_key_exists( $field['key'], $submitted ) ) {
					continue;
				}
				$error = self::save_field( 'term', $term_id, $field, $submitted[ $field['key'] ] );
				if ( $error ) {
					$errors[] = $error;
				}
			}
		}
		self::remember_errors( $errors );
	}
}
