<?php
/**
 * REST endpoint: POST /cradle-duplicate/v1/posts/{id}
 *
 * Cookie-authenticated browser requests must carry the `wp_rest` nonce
 * (X-WP-Nonce); without it WordPress treats the request as anonymous and the
 * permission callback refuses it. The permission callback performs the full
 * object-level check (read + edit source, create target type).
 *
 * @package Cradle\Duplicate
 */

namespace Cradle\Duplicate;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const ROUTE_NAMESPACE = 'cradle-duplicate/v1';

	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/posts/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function permission( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_forbidden', __( 'You need to be logged in to duplicate content.', 'cradle-duplicate' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return Policy::check( (int) $request['id'] );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( $request ) {
		$result = Duplicator::duplicate( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$post     = get_post( $result['id'] );
		$response = rest_ensure_response(
			array(
				'id'             => $result['id'],
				'source'         => $result['source'],
				'status'         => $post ? $post->post_status : 'draft',
				'title'          => $post ? get_the_title( $post ) : '',
				'editLink'       => $result['edit_link'],
				'syncedPatterns' => (int) $result['report']['synced_patterns'],
				'metaCopied'     => count( $result['report']['meta_copied'] ),
				'metaSkipped'    => count( $result['report']['meta_skipped'] ),
			)
		);
		$response->set_status( 201 );
		return $response;
	}
}
