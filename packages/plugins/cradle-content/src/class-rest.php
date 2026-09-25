<?php
/**
 * REST endpoint for the reference pickers: `GET /cradle-content/v1/lookup`.
 *
 * Returns candidate entities for one reference field. Permission: the user must
 * be able to edit objects of the subtype the field belongs to (edit_posts cap of
 * the post type, or edit_terms of the taxonomy). Every candidate is additionally
 * filtered by the same rules the value validator enforces (read_post for posts,
 * list_users for other users, assign_terms for terms).
 *
 * @package Cradle\Content
 */

namespace Cradle\Content;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NAMESPACE = 'cradle-content/v1';

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NAMESPACE,
			'/lookup',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'lookup' ),
				'permission_callback' => array( __CLASS__, 'can_lookup' ),
				'args'                => array(
					'object'  => array(
						'type'     => 'string',
						'enum'     => array( 'post', 'term' ),
						'required' => true,
					),
					'subtype' => array(
						'type'     => 'string',
						'pattern'  => '^[a-z0-9_-]{1,32}$',
						'required' => true,
					),
					'field'   => array(
						'type'     => 'string',
						'pattern'  => '^[a-z][a-z0-9_]{0,39}$',
						'required' => true,
					),
					'sub'     => array(
						'type'    => 'string',
						'pattern' => '^([a-z][a-z0-9_]{0,39})?$',
						'default' => '',
					),
					'search'  => array(
						'type'      => 'string',
						'maxLength' => 100,
						'default'   => '',
					),
					'include' => array(
						'type'     => 'array',
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'maxItems' => 50,
						'default'  => array(),
					),
				),
			)
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function can_lookup( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'cradle-content' ), array( 'status' => 401 ) );
		}
		$subtype = (string) $request['subtype'];
		if ( 'term' === $request['object'] ) {
			$taxonomy = get_taxonomy( $subtype );
			$allowed  = $taxonomy && current_user_can( $taxonomy->cap->edit_terms );
		} else {
			$type    = get_post_type_object( $subtype );
			$allowed = $type && current_user_can( $type->cap->edit_posts );
		}
		return $allowed ? true : new \WP_Error( 'rest_forbidden', __( 'You are not allowed to edit this content.', 'cradle-content' ), array( 'status' => 403 ) );
	}

	public static function lookup( \WP_REST_Request $request ) {
		$field = Meta::instance()->field( (string) $request['object'], (string) $request['subtype'], (string) $request['field'] );
		if ( $field && '' !== (string) $request['sub'] ) {
			$field = $field['subfields'][ (string) $request['sub'] ] ?? null;
		}
		if ( ! $field || ! in_array( $field['type'], Field_Types::REF_TYPES, true ) ) {
			return new \WP_Error( 'rest_not_found', __( 'Unknown reference field.', 'cradle-content' ), array( 'status' => 404 ) );
		}
		$search  = sanitize_text_field( (string) $request['search'] );
		$include = array_values( array_filter( array_map( 'intval', (array) $request['include'] ) ) );
		$limit   = max( 5, min( 50, (int) Settings_Section::get( 'lookup_limit', 20 ) ) );
		return rest_ensure_response( self::candidates( $field, $search, $include, $limit ) );
	}

	/**
	 * Candidate entities for a reference field that the current user may reference.
	 *
	 * @return array<int,array{id:int,label:string,detail:string}>
	 */
	public static function candidates( array $field, string $search, array $include, int $limit ): array {
		$out = array();
		switch ( $field['type'] ) {
			case 'post_ref':
				$types = array_values( array_filter( (array) $field['targets'], 'post_type_exists' ) );
				if ( ! $types ) {
					return array();
				}
				$args = array(
					'post_type'              => $types,
					'post_status'            => array( 'publish', 'private', 'future', 'draft', 'pending' ),
					'posts_per_page'         => $include ? count( $include ) : $limit,
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'orderby'                => $search ? 'relevance' : 'title',
					'order'                  => 'ASC',
				);
				if ( $include ) {
					$args['post__in'] = $include;
				} elseif ( '' !== $search ) {
					$args['s'] = $search;
				}
				foreach ( ( new \WP_Query( $args ) )->posts as $post ) {
					if ( ! current_user_can( 'read_post', $post->ID ) ) {
						continue;
					}
					$type  = get_post_type_object( $post->post_type );
					$out[] = array(
						'id'     => (int) $post->ID,
						/* translators: %d: post ID */
						'label'  => '' !== $post->post_title ? wp_strip_all_tags( $post->post_title ) : sprintf( __( '(no title) #%d', 'cradle-content' ), $post->ID ),
						'detail' => ( $type ? $type->labels->singular_name : $post->post_type ) . ( 'publish' !== $post->post_status ? ' · ' . $post->post_status : '' ),
					);
				}
				return $out;

			case 'term_ref':
				$taxonomies = array();
				foreach ( (array) $field['targets'] as $taxonomy ) {
					$object = get_taxonomy( $taxonomy );
					if ( $object && current_user_can( $object->cap->assign_terms ) ) {
						$taxonomies[] = $taxonomy;
					}
				}
				if ( ! $taxonomies ) {
					return array();
				}
				$args = array(
					'taxonomy'   => $taxonomies,
					'hide_empty' => false,
					'number'     => $include ? count( $include ) : $limit,
					'orderby'    => 'name',
				);
				if ( $include ) {
					$args['include'] = $include;
				} elseif ( '' !== $search ) {
					$args['search'] = $search;
				}
				$terms = get_terms( $args );
				foreach ( is_array( $terms ) ? $terms : array() as $term ) {
					$tax   = get_taxonomy( $term->taxonomy );
					$out[] = array(
						'id'     => (int) $term->term_id,
						'label'  => $term->name,
						'detail' => $tax ? $tax->labels->singular_name : $term->taxonomy,
					);
				}
				return $out;

			case 'user_ref':
				if ( ! current_user_can( 'list_users' ) ) {
					$user = wp_get_current_user();
					if ( $user->exists() && ( empty( $field['targets'] ) || array_intersect( (array) $user->roles, (array) $field['targets'] ) ) ) {
						$out[] = array(
							'id'     => (int) $user->ID,
							'label'  => $user->display_name,
							'detail' => '',
						);
					}
					return $out;
				}
				$args = array(
					'number'  => $include ? count( $include ) : $limit,
					'orderby' => 'display_name',
					'fields'  => array( 'ID', 'display_name' ),
				);
				if ( ! empty( $field['targets'] ) ) {
					$args['role__in'] = (array) $field['targets'];
				}
				if ( $include ) {
					$args['include'] = $include;
				} elseif ( '' !== $search ) {
					$args['search']         = '*' . $search . '*';
					$args['search_columns'] = array( 'user_login', 'user_nicename', 'display_name' );
				}
				foreach ( get_users( $args ) as $user ) {
					$out[] = array(
						'id'     => (int) $user->ID,
						'label'  => $user->display_name,
						'detail' => '',
					);
				}
				return $out;
		}
		return $out;
	}
}
