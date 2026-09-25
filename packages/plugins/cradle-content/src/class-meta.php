<?php
/**
 * Registers field storage as native post/term metadata and enforces it.
 *
 * - register_post_meta()/register_term_meta() with type, single, default,
 *   sanitize_callback (structural validation), auth_callback (object capability)
 *   and show_in_rest (only for fields exposed as "public" or "editor").
 * - REST writes are fully validated (including reference permissions) before
 *   WordPress touches the post, via `rest_request_before_callbacks`.
 * - REST reads drop "editor" fields outside the edit context and strip
 *   references to posts the viewer cannot read.
 * - Low-level writes with invalid values are refused (see Rejected_Value).
 *
 * @package Cradle\Content
 */

namespace Cradle\Content;

defined( 'ABSPATH' ) || exit;

final class Meta {

	/** @var Meta|null */
	private static $instance = null;

	/** @var array<string,array<string,array<string,array>>> object => subtype => meta key => compiled field. */
	private $fields = array(
		'post' => array(),
		'term' => array(),
	);

	/** @var array<string,array> Enabled groups: key => { definition, fields }. */
	private $groups = array();

	/** @var array|null REST route patterns. */
	private $routes = null;

	private $hooked = false;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Compiles enabled field groups and indexes their fields by target.
	 *
	 * @param array $groups Effective field group definitions.
	 */
	public function prepare( array $groups ): array {
		$this->fields = array(
			'post' => array(),
			'term' => array(),
		);
		$this->groups = array();
		foreach ( $groups as $key => $group ) {
			if ( empty( $group['enabled'] ) ) {
				continue;
			}
			$compiled = Field_Types::compile( $group );
			foreach ( $compiled as $name => $field ) {
				$compiled[ $name ]['label'] = Labels::t( (string) $field['label'], Labels::CONTEXT_FIELD );
				foreach ( (array) ( $field['subfields'] ?? array() ) as $sub => $subfield ) {
					$compiled[ $name ]['subfields'][ $sub ]['label'] = Labels::t( (string) $subfield['label'], Labels::CONTEXT_FIELD );
				}
			}
			$group['title']       = Labels::t( (string) $group['title'], Labels::CONTEXT_FIELD_GROUP );
			$this->groups[ $key ] = array(
				'definition' => $group,
				'fields'     => $compiled,
			);
			$object               = 'term' === ( $group['object'] ?? 'post' ) ? 'term' : 'post';
			foreach ( (array) $group['targets'] as $target ) {
				foreach ( $compiled as $field ) {
					$field['group'] = $key;
					if ( ! isset( $this->fields[ $object ][ $target ][ $field['meta_key'] ] ) ) {
						$this->fields[ $object ][ $target ][ $field['meta_key'] ] = $field;
					}
				}
			}
		}
		return $this->groups;
	}

	/**
	 * Registers the prepared fields and the enforcement hooks.
	 */
	public function register() {
		foreach ( $this->fields as $object => $subtypes ) {
			foreach ( $subtypes as $subtype => $fields ) {
				foreach ( $fields as $meta_key => $field ) {
					$args                      = Field_Types::meta_args( $field );
					$args['sanitize_callback'] = static function ( $value ) use ( $field ) {
						return Field_Types::sanitize_stored( $field, $value );
					};
					if ( 'post' === $object ) {
						$args['auth_callback'] = static function ( $allowed, $key, $object_id, $user_id ) {
							return user_can( (int) $user_id, 'edit_post', (int) $object_id );
						};
						register_post_meta( $subtype, $meta_key, $args );
					} else {
						$args['auth_callback'] = static function ( $allowed, $key, $object_id, $user_id ) {
							return user_can( (int) $user_id, 'edit_term', (int) $object_id );
						};
						register_term_meta( $subtype, $meta_key, $args );
					}
				}
				if ( $this->has_rest_fields( $object, $subtype ) ) {
					add_filter( "rest_prepare_{$subtype}", array( $this, 'rest_prepare' ), 10, 3 );
				}
			}
		}
		if ( $this->hooked ) {
			return;
		}
		$this->hooked = true;
		foreach ( array( 'post', 'term' ) as $type ) {
			add_filter( "add_{$type}_metadata", array( $this, 'refuse_rejected' ), 99, 4 );
			add_filter( "update_{$type}_metadata", array( $this, 'refuse_rejected' ), 99, 4 );
			add_filter( "update_{$type}_metadata_by_mid", array( $this, 'refuse_rejected_by_mid' ), 99, 4 );
		}
		add_filter( 'rest_request_before_callbacks', array( $this, 'rest_validate' ), 10, 3 );
	}

	/* ---------------------------------------------------------------------
	 * Lookups
	 * ------------------------------------------------------------------- */

	public function has_rest_fields( string $object, string $subtype ): bool {
		foreach ( $this->fields[ $object ][ $subtype ] ?? array() as $field ) {
			if ( 'none' !== $field['exposure'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<string,array> Meta key => compiled field.
	 */
	public function fields_for( string $object, string $subtype ): array {
		return $this->fields[ $object ][ $subtype ] ?? array();
	}

	public function field( string $object, string $subtype, string $field_key ) {
		return $this->fields[ $object ][ $subtype ][ Field_Types::meta_key( $field_key ) ] ?? null;
	}

	/**
	 * Enabled groups (with compiled fields) that target a subtype.
	 */
	public function groups_for( string $object, string $subtype ): array {
		$out = array();
		foreach ( $this->groups as $key => $group ) {
			$group_object = 'term' === ( $group['definition']['object'] ?? 'post' ) ? 'term' : 'post';
			if ( $group_object === $object && in_array( $subtype, (array) $group['definition']['targets'], true ) ) {
				$out[ $key ] = $group;
			}
		}
		return $out;
	}

	public function subtypes( string $object ): array {
		return array_keys( $this->fields[ $object ] ?? array() );
	}

	/* ---------------------------------------------------------------------
	 * Enforcement
	 * ------------------------------------------------------------------- */

	/**
	 * Refuses add/update of a value the sanitize callback rejected.
	 */
	public function refuse_rejected( $check, $object_id, $meta_key, $meta_value ) {
		if ( $meta_value instanceof Rejected_Value ) {
			return false;
		}
		return $check;
	}

	/**
	 * Same protection for update_metadata_by_mid(), whose filter runs before sanitization.
	 */
	public function refuse_rejected_by_mid( $check, $meta_id, $meta_value, $meta_key ) {
		if ( null !== $check || ! is_string( $meta_key ) || ! str_starts_with( $meta_key, Field_Types::META_PREFIX ) ) {
			return $check;
		}
		$type = str_contains( current_filter(), '_term_' ) ? 'term' : 'post';
		$row  = get_metadata_by_mid( $type, (int) $meta_id );
		if ( ! $row ) {
			return $check;
		}
		$id_column = $type . '_id';
		$subtype   = get_object_subtype( $type, (int) $row->{$id_column} );
		$sanitized = sanitize_meta( $meta_key, wp_unslash( $meta_value ), $type, $subtype );
		return $sanitized instanceof Rejected_Value ? false : $check;
	}

	/**
	 * Validates REST meta writes for Cradle fields before the controller runs, so an invalid
	 * value produces a 400 without partially updating the post or term.
	 *
	 * @param mixed            $response Response so far (null or WP_Error).
	 * @param array            $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 */
	public function rest_validate( $response, $handler, $request ) {
		if ( null !== $response || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return $response;
		}
		$meta = $request->get_param( 'meta' );
		if ( ! is_array( $meta ) || ! $meta ) {
			return $response;
		}
		$target = $this->match_route( $request->get_route() );
		if ( ! $target ) {
			return $response;
		}
		list( $object, $subtype, $object_id ) = $target;
		$errors                               = array();
		foreach ( $meta as $meta_key => $value ) {
			$field = $this->fields[ $object ][ $subtype ][ $meta_key ] ?? null;
			if ( ! $field || 'none' === $field['exposure'] ) {
				continue;
			}
			if ( null === $value ) {
				if ( ! empty( $field['required'] ) ) {
					$errors[ $meta_key ] = sprintf( '%1$s: %2$s', $field['label'], __( 'This field is required.', 'cradle-content' ) );
				}
				continue;
			}
			$current = $object_id ? get_metadata( $object, $object_id, $meta_key, ! Field_Types::is_multi( $field ) ) : null;
			$result  = Field_Types::validate(
				$field,
				$value,
				array(
					'check_permissions' => true,
					'current'           => $current,
				)
			);
			if ( is_wp_error( $result ) ) {
				$errors[ $meta_key ] = sprintf( '%1$s: %2$s', $field['label'], $result->get_error_message() );
			}
		}
		if ( ! $errors ) {
			return $response;
		}
		return new \WP_Error(
			'rest_invalid_param',
			/* translators: %s: error messages */
			sprintf( __( 'Invalid field values: %s', 'cradle-content' ), implode( ' ', $errors ) ),
			array(
				'status'  => 400,
				'params'  => array( 'meta' => implode( ' ', $errors ) ),
				'details' => array( 'meta' => $errors ),
			)
		);
	}

	/**
	 * @return array{0:string,1:string,2:int}|null object, subtype, object ID (0 on create).
	 */
	private function match_route( string $route ) {
		if ( null === $this->routes ) {
			$this->routes = array();
			foreach ( array_keys( $this->fields['post'] ) as $subtype ) {
				$object = get_post_type_object( $subtype );
				if ( $object && ! empty( $object->show_in_rest ) ) {
					$this->routes[] = array( trim( $object->rest_namespace ?: 'wp/v2', '/' ) . '/' . trim( $object->rest_base ?: $object->name, '/' ), 'post', $subtype );
				}
			}
			foreach ( array_keys( $this->fields['term'] ) as $subtype ) {
				$object = get_taxonomy( $subtype );
				if ( $object && ! empty( $object->show_in_rest ) ) {
					$this->routes[] = array( trim( $object->rest_namespace ?: 'wp/v2', '/' ) . '/' . trim( $object->rest_base ?: $object->name, '/' ), 'term', $subtype );
				}
			}
		}
		foreach ( $this->routes as $route_def ) {
			if ( preg_match( '#^/' . preg_quote( $route_def[0], '#' ) . '(?:/(\d+))?/?$#', $route, $m ) ) {
				return array( $route_def[1], $route_def[2], isset( $m[1] ) ? (int) $m[1] : 0 );
			}
		}
		return null;
	}

	/**
	 * Removes "editor" fields outside the edit context and unreadable references from REST output.
	 *
	 * @param \WP_REST_Response|mixed $response Response.
	 * @param \WP_Post|\WP_Term       $item     Item.
	 * @param \WP_REST_Request        $request  Request.
	 */
	public function rest_prepare( $response, $item, $request ) {
		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['meta'] ) || ! is_array( $data['meta'] ) ) {
			return $response;
		}
		if ( $item instanceof \WP_Term ) {
			$object  = 'term';
			$subtype = $item->taxonomy;
		} elseif ( $item instanceof \WP_Post ) {
			$object  = 'post';
			$subtype = $item->post_type;
		} else {
			return $response;
		}
		$context     = $request instanceof \WP_REST_Request && is_string( $request->get_param( 'context' ) ) ? $request->get_param( 'context' ) : 'view';
		$filter_refs = (bool) Settings_Section::get( 'rest_filter_unreadable_refs', true );
		foreach ( $this->fields[ $object ][ $subtype ] ?? array() as $meta_key => $field ) {
			if ( ! array_key_exists( $meta_key, $data['meta'] ) || 'edit' === $context ) {
				continue;
			}
			if ( 'editor' === $field['exposure'] ) {
				unset( $data['meta'][ $meta_key ] );
				continue;
			}
			if ( $filter_refs ) {
				$data['meta'][ $meta_key ] = self::strip_unreadable( $field, $data['meta'][ $meta_key ] );
			}
		}
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Replaces post references the current viewer may not read with 0 (or drops them from lists).
	 */
	public static function strip_unreadable( array $field, $value ) {
		$readable = static function ( $id ): bool {
			$id = (int) $id;
			return $id > 0 && ( is_post_publicly_viewable( $id ) || current_user_can( 'read_post', $id ) );
		};
		if ( 'post_ref' === $field['type'] ) {
			if ( is_array( $value ) ) {
				return array_values( array_filter( $value, $readable ) );
			}
			return $readable( $value ) ? $value : 0;
		}
		if ( 'group' === $field['type'] && is_array( $value ) ) {
			foreach ( $field['subfields'] ?? array() as $sub_key => $sub ) {
				if ( 'post_ref' !== $sub['type'] ) {
					continue;
				}
				foreach ( $value as $i => $row ) {
					if ( is_array( $row ) && ! empty( $row[ $sub_key ] ) && ! $readable( $row[ $sub_key ] ) ) {
						$value[ $i ][ $sub_key ] = 0;
					}
				}
			}
		}
		return $value;
	}

	/* ---------------------------------------------------------------------
	 * Programmatic write helper (admin forms)
	 * ------------------------------------------------------------------- */

	/**
	 * Stores an already validated value (null deletes).
	 *
	 * @return bool
	 */
	public static function store( string $object, int $object_id, array $field, $value ): bool {
		$meta_key = $field['meta_key'];
		if ( null === $value ) {
			delete_metadata( $object, $object_id, $meta_key );
			return true;
		}
		if ( Field_Types::is_multi( $field ) ) {
			$current = array_map( 'strval', (array) get_metadata( $object, $object_id, $meta_key, false ) );
			$wanted  = array_map( 'strval', (array) $value );
			foreach ( array_diff( $current, $wanted ) as $old ) {
				delete_metadata( $object, $object_id, $meta_key, $old );
			}
			foreach ( array_diff( $wanted, $current ) as $new ) {
				if ( false === add_metadata( $object, $object_id, $meta_key, wp_slash( $new ) ) ) {
					return false;
				}
			}
			return true;
		}
		// update_metadata() also returns false for an unchanged value; the value was validated already.
		update_metadata( $object, $object_id, $meta_key, wp_slash( $value ) );
		return true;
	}
}
