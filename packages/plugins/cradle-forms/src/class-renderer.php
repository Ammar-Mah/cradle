<?php
/**
 * Renders the `cradle/form` component (block, shortcode, PHP) and the field blocks inside it.
 *
 * The form is a server-rendered `<form method="post">` posting to admin-post.php, so it works
 * without JavaScript; view.js enhances it to submit with fetch() to the REST route. Field blocks
 * render only inside a form render and always from the stored schema (never from raw block
 * attributes), so the markup matches what the server validates.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	/** @var array[] Stack of form render contexts (field blocks read the top one). */
	private static $stack = array();

	/** @var array<int,int> Rendered instances per form ID on this request. */
	private static $instances = array();

	public static function reset_instances() {
		self::$instances = array();
	}

	/**
	 * `[cradle_form id="12" title="yes"]` or `[cradle_form slug="contact"]` (the form's address
	 * name, for theme templates where IDs differ per site; `id` wins when both are given).
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'slug'  => '',
				'title' => 'no',
			),
			is_array( $atts ) ? $atts : array(),
			'cradle_form'
		);
		return cradle_render_component(
			'cradle/form',
			array(
				'formId'    => absint( $atts['id'] ),
				'formSlug'  => is_string( $atts['slug'] ) ? $atts['slug'] : '',
				'showTitle' => in_array( strtolower( (string) $atts['title'] ), array( 'yes', '1', 'true' ), true ),
			),
			array( 'adapter' => 'shortcode' )
		);
	}

	/**
	 * Form selected by the properties: `formId` when set, otherwise the published form whose
	 * address name is `formSlug` (with Cradle Languages: its default-language version). 0 = none.
	 *
	 * @param array $props Normalized properties.
	 */
	public static function selected_form( array $props ): int {
		$form_id = (int) ( $props['formId'] ?? 0 );
		if ( $form_id > 0 ) {
			return $form_id;
		}
		$slug = is_string( $props['formSlug'] ?? null ) ? $props['formSlug'] : '';
		return '' !== $slug ? Language::default_form( Post_Type::published_by_slug( $slug ) ) : 0;
	}

	private static function manager_notice( string $text, int $form_id = 0 ): string {
		if ( ! current_user_can( Caps::MANAGE ) ) {
			return '';
		}
		$link = $form_id ? ' <a href="' . esc_url( (string) get_edit_post_link( $form_id, 'raw' ) ) . '">' . esc_html__( 'Edit form', 'cradle-forms' ) . '</a>' : '';
		return '<div class="cradle-form-notice"><p>' . esc_html( $text ) . $link . '</p></div>';
	}

	/**
	 * The singular post the form is rendered on (with Cradle Languages: the translation actually
	 * shown), 0 on archives, search, the posts page and other non-singular views. Signed into the
	 * render token, so visitors cannot change it.
	 */
	public static function source_id(): int {
		if ( ! is_singular() ) {
			return 0;
		}
		$id = (int) get_queried_object_id();
		return $id > 0 && get_post( $id ) ? $id : 0;
	}

	/**
	 * Current page URL (host from home_url, path from the request) without the result parameter.
	 */
	private static function current_url(): string {
		$home = wp_parse_url( home_url() );
		$base = ( $home['scheme'] ?? 'http' ) . '://' . ( $home['host'] ?? 'localhost' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated with wp_validate_redirect on use.
		if ( '' === $uri || '/' !== $uri[0] ) {
			$uri = '/';
		}
		if ( is_singular() && get_queried_object_id() ) {
			$permalink = get_permalink( get_queried_object_id() );
			if ( $permalink ) {
				return remove_query_arg( Handler::RESULT_PARAM, (string) $permalink );
			}
		}
		return remove_query_arg( Handler::RESULT_PARAM, $base . $uri );
	}

	/**
	 * Component render callable.
	 *
	 * @param array $props   Normalized properties: formId, formSlug, showTitle.
	 * @param array $context Render context.
	 */
	public static function render( array $props, array $context = array() ): string {
		$form_id = self::selected_form( $props );
		if ( ! $form_id ) {
			$slug = is_string( $props['formSlug'] ?? null ) ? $props['formSlug'] : '';
			if ( '' !== $slug ) {
				/* translators: %s: address name (slug) of a form */
				return self::manager_notice( sprintf( __( 'No published form has the address name “%s”, so visitors see nothing here.', 'cradle-forms' ), $slug ) );
			}
			return self::manager_notice( __( 'Choose a form in the block settings.', 'cradle-forms' ) );
		}
		$form_id = Language::translated_form( $form_id );
		$form    = Post_Type::published( $form_id );
		if ( ! $form ) {
			return self::manager_notice( __( 'This form is not published, so visitors do not see it.', 'cradle-forms' ), $form_id );
		}
		$schema = Form_Schema::get( $form_id );
		$fields = $schema ? $schema['fields'] : array();
		if ( ! $fields ) {
			return self::manager_notice( __( 'This form has no valid fields yet, so visitors do not see it.', 'cradle-forms' ), $form_id );
		}

		self::$instances[ $form_id ] = ( self::$instances[ $form_id ] ?? 0 ) + 1;
		$instance                    = self::$instances[ $form_id ];
		$root                        = 'cradle-form-' . $form_id . ( $instance > 1 ? '-' . $instance : '' );
		$settings                    = Form_Settings::get( $form_id );
		$state                       = Handler::state_for( $form_id, $instance );
		$title                       = ! empty( $props['showTitle'] ) ? '<h2 class="cradle-form__title">' . esc_html( get_the_title( $form ) ) . '</h2>' : '';

		if ( $state && ! empty( $state['ok'] ) ) {
			return '<div class="cradle-form cradle-form--sent" id="' . esc_attr( $root ) . '">' . $title
				. '<div class="cradle-form__confirmation" id="' . esc_attr( $root . '-confirmation' ) . '" tabindex="-1" role="status">'
				. wp_kses( (string) $state['confirmation'], Form_Settings::message_tags() ) . '</div></div>';
		}

		$errors      = $state ? array_map( 'strval', (array) $state['field_errors'] ) : array();
		$form_errors = $state ? array_map( 'strval', (array) $state['form_errors'] ) : array();
		if ( $state && ! $errors && ! $form_errors && '' !== (string) $state['message'] ) {
			$form_errors[] = (string) $state['message'];
		}
		if ( $state && ! empty( $state['had_files'] ) && ( $errors || $form_errors ) ) {
			$form_errors[] = __( 'For your security, files are not kept when a form has errors. Please choose your files again.', 'cradle-forms' );
		}

		self::$stack[] = array(
			'form'     => $form,
			'fields'   => $fields,
			'root'     => $root,
			'errors'   => $errors,
			'values'   => $state ? (array) $state['values'] : null,
			'rendered' => array(),
		);
		try {
			$inner = Form_Schema::matches_content( $form, $schema ) ? do_blocks( (string) $form->post_content ) : '';
			foreach ( $fields as $field ) {
				// Fallback (content changed into an invalid schema) and safety net: every schema field is present once.
				if ( ! isset( self::$stack[ count( self::$stack ) - 1 ]['rendered'][ $field['name'] ] ) ) {
					$inner .= self::field_html( $field );
				}
			}
		} finally {
			array_pop( self::$stack );
		}

		$summary_items = '';
		foreach ( $fields as $field ) {
			if ( isset( $errors[ $field['name'] ] ) ) {
				$label          = 'consent' === $field['type'] ? (string) $field['label_text'] : $field['label'];
				$summary_items .= '<li><a href="#' . esc_attr( Field_Types::target_id( $field, $root . '-' . $field['name'] ) ) . '">' . esc_html( ( '' !== $label ? $label . ': ' : '' ) . $errors[ $field['name'] ] ) . '</a></li>';
			}
		}
		foreach ( $form_errors as $message ) {
			$summary_items .= '<li>' . esc_html( $message ) . '</li>';
		}

		$has_files = Form_Schema::has_type( $fields, 'file' );
		$logged_in = is_user_logged_in();
		$messages  = array(
			'summaryTitle' => __( 'There is a problem', 'cradle-forms' ),
			'sending'      => __( 'Sending…', 'cradle-forms' ),
			'failed'       => __( 'Your submission could not be sent. Please check your connection and try again.', 'cradle-forms' ),
		);
		$attrs     = array(
			'class'               => 'cradle-form__form',
			'method'              => 'post',
			'action'              => add_query_arg(
				array(
					'action'      => Handler::ACTION,
					'cradle_form' => $form_id,
				),
				admin_url( 'admin-post.php' )
			),
			'enctype'             => $has_files ? 'multipart/form-data' : null,
			'data-cradle-form'    => (string) $form_id,
			'data-rest'           => rest_url( Rest::NAMESPACE . '/submit/' . $form_id ),
			'data-rest-nonce'     => $logged_in ? wp_create_nonce( 'wp_rest' ) : null,
			'data-messages'       => (string) wp_json_encode( $messages, JSON_UNESCAPED_UNICODE ),
			'aria-describedby'    => $summary_items ? $root . '-summary' : null,
			'data-cradle-summary' => $root . '-summary',
		);

		$hidden  = '<input type="hidden" name="' . esc_attr( Submission::TOKEN ) . '" value="' . esc_attr( Security::form_token( $form_id, $instance, Language::current(), Language::locale(), self::source_id() ) ) . '" />';
		$hidden .= '<input type="hidden" name="_cf_return" value="' . esc_attr( self::current_url() ) . '" />';
		if ( $logged_in ) {
			$hidden .= '<input type="hidden" name="_cf_nonce" value="' . esc_attr( wp_create_nonce( Handler::nonce_action( $form_id ) ) ) . '" />';
		}
		$honeypot = '<div class="cradle-form__hp" aria-hidden="true"><label for="' . esc_attr( $root . '-website' ) . '">' . esc_html__( 'Leave this field empty', 'cradle-forms' ) . '</label><input type="text" id="' . esc_attr( $root . '-website' ) . '" name="' . esc_attr( Submission::HONEYPOT ) . '" value="" tabindex="-1" autocomplete="off" /></div>';
		$submit   = '' !== $settings['submitLabel'] ? $settings['submitLabel'] : __( 'Submit', 'cradle-forms' );

		return '<div class="cradle-form" id="' . esc_attr( $root ) . '">' . $title
			. '<div class="cradle-form__summary" id="' . esc_attr( $root . '-summary' ) . '" tabindex="-1" aria-labelledby="' . esc_attr( $root . '-summary-title' ) . '"' . ( '' === $summary_items ? ' hidden' : '' ) . '>'
			. '<h2 class="cradle-form__summary-title" id="' . esc_attr( $root . '-summary-title' ) . '">' . esc_html( $messages['summaryTitle'] ) . '</h2>'
			. '<ul class="cradle-form__summary-list">' . $summary_items . '</ul></div>'
			. '<form' . Field_Types::attrs( $attrs ) . '>' . $hidden
			. '<div class="cradle-form__fields">' . $inner . '</div>'
			. $honeypot
			. '<div class="cradle-form__actions"><button type="submit" class="cradle-form__submit wp-element-button">' . esc_html( $submit ) . '</button></div>'
			. '<p class="cradle-form__status" role="status" aria-live="polite"></p>'
			. '</form></div>';
	}

	/**
	 * Render callback of every `cradle-forms/field-*` block. Outputs nothing outside a form render.
	 *
	 * @param array          $attributes Block attributes (only `name` is used: the schema is authoritative).
	 * @param string         $content    Saved content (none).
	 * @param \WP_Block|null $block      Block instance.
	 */
	public static function render_field_block( $attributes, $content = '', $block = null ): string {
		if ( ! self::$stack ) {
			return '';
		}
		$top   = self::$stack[ count( self::$stack ) - 1 ];
		$name  = is_string( $attributes['name'] ?? null ) ? strtolower( trim( $attributes['name'] ) ) : '';
		$field = Form_Schema::field( $top['fields'], $name );
		$type  = $block instanceof \WP_Block ? Field_Types::type_of_block( (string) $block->name ) : null;
		if ( ! $field || ( null !== $type && $type !== $field['type'] ) || isset( $top['rendered'][ $name ] ) ) {
			return '';
		}
		return self::field_html( $field );
	}

	private static function field_html( array $field ): string {
		$index = count( self::$stack ) - 1;
		$top   = self::$stack[ $index ];
		$name  = $field['name'];

		self::$stack[ $index ]['rendered'][ $name ] = true;

		if ( null !== $top['values'] ) {
			$value = $top['values'][ $name ] ?? ( 'checkboxes' === $field['type'] ? array() : '' );
		} else {
			$value = $field['default'];
		}
		if ( 'hidden' === $field['type'] && '' !== $field['from_query'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill of a public form.
			$value = isset( $_GET[ $field['from_query'] ] ) ? Field_Types::plain( wp_unslash( $_GET[ $field['from_query'] ] ), 200 ) : $field['default'];
		}
		return Field_Types::render( $field, $value, $top['root'] . '-' . $name, (string) ( $top['errors'][ $name ] ?? '' ) );
	}
}
