<?php
/**
 * REST submission: `POST /cradle-forms/v1/submit/<form>` (multipart/form-data or urlencoded,
 * same field names as the no-JavaScript form). Used by view.js.
 *
 * The permission callback is deliberately public (anonymous visitors submit forms) but refuses
 * requests without browser proof of the same origin (`Sec-Fetch-Site: same-origin` or an
 * `Origin` of this site), which stops cross-site form posts and scripted use from other pages.
 * Logged-in users are authenticated by WordPress only with a valid `X-WP-Nonce`; without it the
 * request runs as anonymous. The pipeline then applies the signed token, honeypot, minimum time
 * and rate limit exactly like the admin-post path.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NAMESPACE = 'cradle-forms/v1';

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NAMESPACE,
			'/submit/(?P<form>\d+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => array( __CLASS__, 'can_submit' ),
				'args'                => array(
					'form' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function can_submit( \WP_REST_Request $request ) {
		if ( 'same' !== Security::request_origin() ) {
			return new \WP_Error( 'cradle_forms_origin', __( 'This form can only be submitted from this website.', 'cradle-forms' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function submit( \WP_REST_Request $request ) {
		$params = $request->get_body_params();
		$result = Submission::process( (int) $request['form'], is_array( $params ) ? $params : array(), (array) $request->get_file_params(), 'rest' );
		if ( ! $result['ok'] ) {
			return new \WP_Error(
				$result['code'],
				$result['message'],
				array(
					'status'       => $result['status'],
					'field_errors' => (object) $result['field_errors'],
					'form_errors'  => array_values( $result['form_errors'] ),
				)
			);
		}
		$response = rest_ensure_response(
			array(
				'status'       => 'sent',
				'message'      => $result['message'],
				'confirmation' => wp_kses( $result['confirmation'], Form_Settings::message_tags() ),
				'redirect'     => $result['redirect'],
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
