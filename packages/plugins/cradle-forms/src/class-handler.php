<?php
/**
 * No-JavaScript submission: `POST admin-post.php?action=cradle_forms_submit&cradle_form=<id>`
 * (nopriv and logged-in actions), then Post/Redirect/Get back to the page with a one-time result
 * token (`?cradle_form_result=<32 hex>`, transient `cradle_forms_r_<token>`, 10 minutes) that
 * the form renders once: the confirmation, or the errors with the submitted values.
 *
 * Why no nonce for anonymous visitors: pages with forms are often served from a page cache for
 * hours; a nonce in cached HTML expires (12–24 h) and is identical for every anonymous visitor,
 * so it would only break legitimate submissions without adding protection. Anonymous requests
 * are protected by the browser origin check (a proven foreign origin is refused), the signed
 * render token, the honeypot, the minimum fill time and the rate limit. Logged-in users (whose
 * pages are not cached) must send a valid nonce.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Handler {

	const ACTION       = 'cradle_forms_submit';
	const RESULT_PARAM = 'cradle_form_result';
	const STATE_TTL    = 10 * MINUTE_IN_SECONDS;

	/** @var array|false|null Loaded result state (false: none on this request). */
	private static $state = null;

	public static function hooks() {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ) );
	}

	public static function nonce_action( int $form_id ): string {
		return 'cradle_forms_submit_' . $form_id;
	}

	/**
	 * A page showing a one-time result must never be stored by a cache.
	 */
	public static function no_cache() {
		if ( isset( $_GET[ self::RESULT_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only.
			nocache_headers();
		}
	}

	private static function return_url( $raw ): string {
		$url = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $url ) {
			$url = (string) wp_get_raw_referer();
		}
		$url = wp_validate_redirect( $url, home_url( '/' ) );
		return remove_query_arg( self::RESULT_PARAM, strtok( $url, '#' ) );
	}

	private static function root_id( int $form_id, int $instance ): string {
		return 'cradle-form-' . $form_id . ( $instance > 1 ? '-' . $instance : '' );
	}

	public static function handle() {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
			wp_die( esc_html__( 'This form must be submitted with POST.', 'cradle-forms' ), '', array( 'response' => 405 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification -- anonymous public form (see class comment); logged-in users are checked below.
		$form_id   = absint( wp_unslash( $_GET['cradle_form'] ?? ( $_POST['cradle_form'] ?? 0 ) ) );
		$params    = wp_unslash( $_POST );
		$params    = is_array( $params ) ? $params : array();
		$had_files = false;
		if ( isset( $_FILES['cradle_files']['name'] ) && is_array( $_FILES['cradle_files']['name'] ) ) {
			$names = $_FILES['cradle_files']['name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only checked for non-empty strings.
			array_walk_recursive(
				$names,
				static function ( $name ) use ( &$had_files ) {
					if ( is_string( $name ) && '' !== $name ) {
						$had_files = true;
					}
				}
			);
		}
		// phpcs:enable
		$return = self::return_url( $params['_cf_return'] ?? '' );
		$token  = Security::read_token( is_string( $params[ Submission::TOKEN ] ?? null ) ? $params[ Submission::TOKEN ] : '' );
		$base   = array(
			'ok'           => false,
			'form_id'      => $form_id,
			'instance'     => $token ? $token['instance'] : 1,
			'field_errors' => array(),
			'form_errors'  => array(),
			'values'       => array(),
			'message'      => '',
			'confirmation' => '',
		);

		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		if ( ! $params && empty( $_FILES ) && $length > 0 && $length > wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ) ) {
			$base['form_errors'][] = __( 'Your files were too large to upload, so nothing was sent. Please choose smaller files and submit the form again.', 'cradle-forms' );
			self::redirect_with( $base, $return, false );
		}

		if ( 'cross' === Security::request_origin() ) {
			wp_die( esc_html__( 'This form can only be submitted from this website.', 'cradle-forms' ), '', array( 'response' => 403 ) );
		}

		if ( is_user_logged_in() && ! wp_verify_nonce( is_string( $params['_cf_nonce'] ?? null ) ? $params['_cf_nonce'] : '', self::nonce_action( $form_id ) ) ) {
			$base['form_errors'][] = __( 'Your session changed since the page was loaded. Please check your entries and submit the form again.', 'cradle-forms' );
			$base['values']        = self::display_values( $form_id, $params );
			self::redirect_with( $base, $return, $had_files );
		}

		$result = Submission::process( $form_id, $params, $_FILES, 'post' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		if ( $result['ok'] && '' !== $result['redirect'] ) {
			wp_safe_redirect( $result['redirect'], 303 );
			exit;
		}
		self::redirect_with( array_merge( $base, $result ), $return, $had_files );
	}

	/**
	 * Values kept for re-display when the submission is refused before validation.
	 */
	private static function display_values( int $form_id, array $params ): array {
		$raw = is_array( $params['cradle_fields'] ?? null ) ? $params['cradle_fields'] : array();
		$out = array();
		foreach ( Form_Schema::fields( $form_id ) as $field ) {
			if ( ! in_array( $field['type'], array( 'file', 'hidden' ), true ) ) {
				$out[ $field['name'] ] = Field_Types::check( $field, $raw[ $field['name'] ] ?? null )['value'];
			}
		}
		return $out;
	}

	/**
	 * Stores the result under a one-time token and redirects back (303 See Other).
	 */
	private static function redirect_with( array $result, string $return, bool $had_files ) {
		$token = bin2hex( random_bytes( 16 ) );
		$state = array(
			'ok'           => ! empty( $result['ok'] ),
			'form_id'      => (int) $result['form_id'],
			'instance'     => max( 1, (int) $result['instance'] ),
			'field_errors' => (array) $result['field_errors'],
			'form_errors'  => array_values( (array) $result['form_errors'] ),
			'values'       => empty( $result['ok'] ) ? (array) $result['values'] : array(),
			'message'      => empty( $result['ok'] ) ? (string) $result['message'] : '',
			'confirmation' => (string) $result['confirmation'],
			'had_files'    => $had_files,
		);
		set_transient( 'cradle_forms_r_' . $token, $state, self::STATE_TTL );
		$fragment = self::root_id( $state['form_id'], $state['instance'] ) . ( $state['ok'] ? '-confirmation' : '-summary' );
		wp_safe_redirect( add_query_arg( self::RESULT_PARAM, $token, $return ) . '#' . $fragment, 303 );
		exit;
	}

	/**
	 * The result for one rendered form instance, consumed on first use.
	 */
	public static function state_for( int $form_id, int $instance ): ?array {
		if ( null === self::$state ) {
			self::$state = false;
			$token       = isset( $_GET[ self::RESULT_PARAM ] ) && is_string( $_GET[ self::RESULT_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- random one-time token.
			if ( preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
				$state = get_transient( 'cradle_forms_r_' . $token );
				if ( is_array( $state ) ) {
					self::$state          = $state;
					self::$state['token'] = $token;
				}
			}
		}
		if ( ! is_array( self::$state ) || (int) self::$state['form_id'] !== $form_id || (int) self::$state['instance'] !== $instance ) {
			return null;
		}
		$state = self::$state;
		delete_transient( 'cradle_forms_r_' . $state['token'] );
		self::$state = false;
		return $state;
	}

	/**
	 * Test/CLI helper: forget the per-request state cache.
	 */
	public static function reset_state() {
		self::$state = null;
	}
}
