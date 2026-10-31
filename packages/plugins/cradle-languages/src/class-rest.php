<?php
/**
 * REST API.
 *
 * - `cradle_lang` collection parameter on translatable types' core endpoints (`all` or a slug;
 *   omitted = every language, which is what the block editor gets) and a read-only `cradle_lang`
 *   field on their entries.
 * - `cradle-languages/v1` routes used by the editor panel:
 *   GET  /posts/{id}                language, translation group and what the user may do (edit_post)
 *   POST /posts/{id}/language       { lang } set the language of an entry (edit_post)
 *   POST /posts/{id}/translations   { lang } create a draft translation (edit_post on the source + create_posts)
 *   POST /posts/{id}/link           { translations: { lang: id } } link existing entries (edit_post on each)
 *
 * @package Cradle\Languages
 */

namespace Cradle\Languages;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NS = 'cradle-languages/v1';

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		foreach ( Config::translatable_post_types() as $type ) {
			$object = get_post_type_object( $type );
			if ( ! $object || empty( $object->show_in_rest ) ) {
				continue;
			}
			add_filter( "rest_{$type}_collection_params", array( __CLASS__, 'collection_params' ) );
			add_filter( "rest_{$type}_query", array( __CLASS__, 'collection_query' ), 10, 2 );
			register_rest_field(
				$type,
				'cradle_lang',
				array(
					'get_callback' => static fn( $post ) => Content::post_language_slug( (int) ( $post['id'] ?? 0 ) ),
					'schema'       => array(
						'description' => 'Language slug of the entry (Cradle Languages).',
						'type'        => 'string',
						'context'     => array( 'view', 'edit', 'embed' ),
						'readonly'    => true,
					),
				)
			);
		}

		$id_arg = array(
			'id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);
		$lang_arg = array(
			'type'     => 'string',
			'required' => true,
			'pattern'  => '^[a-z]{2,3}(-[a-z]{2})?$',
		);
		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_post' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/language',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'set_language' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => $id_arg + array( 'lang' => $lang_arg ),
			)
		);
		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/translations',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_translation' ),
				'permission_callback' => array( __CLASS__, 'can_translate' ),
				'args'                => $id_arg + array( 'lang' => $lang_arg ),
			)
		);
		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/link',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'link' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => $id_arg + array(
					'translations' => array(
						'type'                 => 'object',
						'required'             => true,
						'additionalProperties' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'maxProperties'        => Languages::MAX,
					),
				),
			)
		);
	}

	public static function collection_params( array $params ): array {
		$params[ Query::VAR ] = array(
			'description' => 'Limit results to a language (slug) or "all" (default).',
			'type'        => 'string',
			'enum'        => array_merge( array( 'all' ), Languages::slugs() ),
		);
		return $params;
	}

	public static function collection_query( array $args, \WP_REST_Request $request ): array {
		$lang = $request->get_param( Query::VAR );
		if ( is_string( $lang ) && ( 'all' === $lang || Languages::exists( $lang ) ) ) {
			$args[ Query::VAR ] = $lang;
		}
		return $args;
	}

	/**
	 * Object-level permission: logged in (cookie + REST nonce) and edit_post on a translatable entry.
	 * A post the user cannot read answers 404 so its existence is not revealed.
	 *
	 * @return true|\WP_Error
	 */
	public static function can_edit( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'You must be signed in.', 'cradle-languages' ), array( 'status' => 401 ) );
		}
		$post = get_post( (int) $request['id'] );
		if ( ! $post || ! current_user_can( 'read_post', $post->ID ) ) {
			return new \WP_Error( 'rest_post_invalid_id', __( 'The entry does not exist.', 'cradle-languages' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You are not allowed to change this entry.', 'cradle-languages' ), array( 'status' => 403 ) );
		}
		if ( ! Content::is_translatable_post_type( $post->post_type ) ) {
			return new \WP_Error( 'cradle_languages_shared', __( 'Entries of this type are shared by all languages.', 'cradle-languages' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function can_translate( \WP_REST_Request $request ) {
		$check = self::can_edit( $request );
		if ( true !== $check ) {
			return $check;
		}
		$type = get_post_type_object( get_post_type( (int) $request['id'] ) );
		if ( ! $type || ! current_user_can( $type->cap->create_posts ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You are not allowed to create entries of this type.', 'cradle-languages' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function get_post( \WP_REST_Request $request ) {
		return rest_ensure_response( self::describe( (int) $request['id'] ) );
	}

	/**
	 * Editor panel data for an entry, limited to what the current user may see.
	 */
	public static function describe( int $post_id ): array {
		$post         = get_post( $post_id );
		$translations = Content::translations( $post_id );
		$type         = get_post_type_object( $post->post_type );
		$can_create   = $type && current_user_can( $type->cap->create_posts );
		$languages    = array();
		foreach ( Languages::all() as $slug => $lang ) {
			$item = array(
				'slug'        => $slug,
				'name'        => $lang['name'],
				'native'      => $lang['native'],
				'dir'         => $lang['dir'],
				'isDefault'   => $lang['default'],
				'translation' => null,
			);
			$id   = $translations[ $slug ] ?? 0;
			if ( $id && $id !== $post_id ) {
				$readable            = current_user_can( 'read_post', $id );
				$item['translation'] = array(
					'id'       => $id,
					'title'    => $readable ? wp_strip_all_tags( get_the_title( $id ) ) : '',
					'status'   => $readable ? (string) get_post_status( $id ) : '',
					'editLink' => current_user_can( 'edit_post', $id ) ? (string) get_edit_post_link( $id, 'raw' ) : '',
					'viewLink' => $readable && 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : '',
				);
			}
			$item['canCreate'] = $can_create && ! $id;
			$languages[]       = $item;
		}
		$own = Content::post_language_slug( $post );
		return array(
			'id'             => $post_id,
			'lang'           => $own,
			'group'          => count( $translations ) > 1,
			'languageLocked' => count( $translations ) > 1,
			'languages'      => $languages,
		);
	}

	public static function set_language( \WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$slug    = (string) $request['lang'];
		$result  = Content::set_language( $post_id, $slug );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		cradle_log_activity( Module::ID, 'language_set', sprintf( 'Language of %1$s #%2$d set to %3$s.', get_post_type( $post_id ), $post_id, $slug ), array( 'post' => $post_id ) );
		return rest_ensure_response( self::describe( $post_id ) );
	}

	public static function create_translation( \WP_REST_Request $request ) {
		$result = Translator::create( (int) $request['id'], (string) $request['lang'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = rest_ensure_response(
			array(
				'id'       => $result['id'],
				'source'   => (int) $request['id'],
				'lang'     => (string) $request['lang'],
				'status'   => 'draft',
				'editLink' => $result['edit_link'],
				'report'   => $result['report'],
			)
		);
		$response->set_status( 201 );
		return $response;
	}

	public static function link( \WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$map     = array();
		foreach ( (array) $request['translations'] as $slug => $id ) {
			if ( ! Languages::exists( (string) $slug ) ) {
				return new \WP_Error( 'cradle_languages_unknown', __( 'This language is not configured.', 'cradle-languages' ), array( 'status' => 400 ) );
			}
			$id = (int) $id;
			if ( $id > 0 && ! current_user_can( 'edit_post', $id ) ) {
				return new \WP_Error( 'rest_forbidden', __( 'You are not allowed to change one of these entries.', 'cradle-languages' ), array( 'status' => 403 ) );
			}
			if ( $id > 0 ) {
				$map[ (string) $slug ] = $id;
			}
		}
		$own = Content::post_language_slug( $post_id );
		if ( ! in_array( $post_id, $map, true ) ) {
			if ( isset( $map[ $own ] ) ) {
				return new \WP_Error( 'cradle_languages_taken', __( 'The entry itself must keep its language.', 'cradle-languages' ), array( 'status' => 400 ) );
			}
			$map[ $own ] = $post_id;
		}
		$group = Content::link_translations( $map );
		if ( is_wp_error( $group ) ) {
			return $group;
		}
		cradle_log_activity( Module::ID, 'translations_linked', sprintf( 'Translations linked for %1$s #%2$d.', get_post_type( $post_id ), $post_id ), array( 'posts' => array_values( $map ) ) );
		return rest_ensure_response( self::describe( $post_id ) );
	}
}
