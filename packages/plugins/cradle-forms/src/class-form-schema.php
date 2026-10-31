<?php
/**
 * Form schema: derived from the field blocks of a `cradle_form` post on every save, validated,
 * and stored as JSON in `_cradle_form_schema`. An invalid derivation keeps the previous valid
 * schema and records its problems in `_cradle_form_schema_errors` (shown in the editor).
 *
 * The stored schema also carries `source_hash` (SHA-256 of the post content it was derived
 * from). The renderer uses the post content only while the hash matches; otherwise it renders
 * the stored fields in order, so what visitors see always matches what the server validates.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Form_Schema {

	const META        = '_cradle_form_schema';
	const ERRORS_META = '_cradle_form_schema_errors';
	const VERSION     = 1;
	const MAX_FIELDS  = 100;

	/** @var array<int,array|null> */
	private static $cache = array();

	/**
	 * @return array{fields:array,errors:string[]}
	 */
	public static function derive( string $content ): array {
		$fields = array();
		$errors = array();
		self::walk( parse_blocks( $content ), $fields, $errors );

		$names = array();
		$mails = 0;
		foreach ( $fields as $field ) {
			if ( '' === $field['name'] ) {
				continue;
			}
			if ( isset( $names[ $field['name'] ] ) ) {
				/* translators: %s: field name */
				$errors[] = sprintf( __( 'The name “%s” is used by more than one field. Each field needs its own name.', 'cradle-forms' ), $field['name'] );
			}
			$names[ $field['name'] ] = true;
			if ( 'email' === $field['type'] && ! empty( $field['submitter_email'] ) ) {
				++$mails;
			}
		}
		if ( $mails > 1 ) {
			$errors[] = __( 'Only one email field can be marked as the submitter’s own email address.', 'cradle-forms' );
		}
		if ( count( $fields ) > self::MAX_FIELDS ) {
			/* translators: %d: maximum number of fields */
			$errors[] = sprintf( __( 'A form can have at most %d fields.', 'cradle-forms' ), self::MAX_FIELDS );
		}
		return array(
			'fields' => $fields,
			'errors' => array_values( array_unique( $errors ) ),
		);
	}

	private static function walk( array $blocks, array &$fields, array &$errors ) {
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( 0 === strpos( $name, Field_Types::BLOCK_PREFIX ) ) {
				$type = Field_Types::type_of_block( $name );
				if ( null === $type ) {
					/* translators: %s: block name */
					$errors[] = sprintf( __( 'Unknown field block %s.', 'cradle-forms' ), $name );
					continue;
				}
				// Saved block comments omit attributes equal to their block.json default: apply them.
				$attrs    = (array) ( $block['attrs'] ?? array() );
				$block_ty = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
				$attrs    = $block_ty ? $block_ty->prepare_attributes_for_render( $attrs ) : $attrs;
				$config   = Field_Types::config_from_block( $type, $attrs );
				$fields[] = $config['field'];
				$errors   = array_merge( $errors, $config['errors'] );
				continue;
			}
			if ( 'cradle/form' === $name ) {
				$errors[] = __( 'A form cannot contain another form block.', 'cradle-forms' );
				continue;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::walk( $block['innerBlocks'], $fields, $errors );
			}
		}
	}

	/**
	 * Re-derives and stores the schema of a form post. Called after every insert/update.
	 *
	 * @return array{valid:bool,errors:string[]}
	 */
	public static function update( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post || Post_Type::NAME !== $post->post_type ) {
			return array(
				'valid'  => false,
				'errors' => array(),
			);
		}
		$result = self::derive( (string) $post->post_content );
		unset( self::$cache[ $post_id ] );
		if ( $result['errors'] ) {
			update_post_meta( $post_id, self::ERRORS_META, $result['errors'] );
			return array(
				'valid'  => false,
				'errors' => $result['errors'],
			);
		}
		$schema = array(
			'schema_version' => self::VERSION,
			'source_hash'    => hash( 'sha256', (string) $post->post_content ),
			'updated'        => gmdate( 'c' ),
			'fields'         => $result['fields'],
		);
		update_post_meta( $post_id, self::META, wp_slash( (string) wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
		delete_post_meta( $post_id, self::ERRORS_META );
		return array(
			'valid'  => true,
			'errors' => array(),
		);
	}

	public static function get( int $form_id ): ?array {
		if ( array_key_exists( $form_id, self::$cache ) ) {
			return self::$cache[ $form_id ];
		}
		$raw    = get_post_meta( $form_id, self::META, true );
		$schema = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true, 32 ) : null;
		if ( ! is_array( $schema ) || ! isset( $schema['fields'] ) || ! is_array( $schema['fields'] ) || (int) ( $schema['schema_version'] ?? 0 ) !== self::VERSION ) {
			$schema = null;
		}
		self::$cache[ $form_id ] = $schema;
		return $schema;
	}

	public static function flush( int $form_id = 0 ) {
		if ( $form_id ) {
			unset( self::$cache[ $form_id ] );
		} else {
			self::$cache = array();
		}
	}

	public static function fields( int $form_id ): array {
		$schema = self::get( $form_id );
		return $schema ? $schema['fields'] : array();
	}

	public static function field( array $fields, string $name ): ?array {
		foreach ( $fields as $field ) {
			if ( $field['name'] === $name ) {
				return $field;
			}
		}
		return null;
	}

	public static function has_type( array $fields, string $type ): bool {
		return in_array( $type, wp_list_pluck( $fields, 'type' ), true );
	}

	public static function submitter_email_field( array $fields ): ?array {
		foreach ( $fields as $field ) {
			if ( 'email' === $field['type'] && ! empty( $field['submitter_email'] ) ) {
				return $field;
			}
		}
		return null;
	}

	public static function errors( int $form_id ): array {
		$errors = get_post_meta( $form_id, self::ERRORS_META, true );
		return is_array( $errors ) ? array_values( array_map( 'strval', $errors ) ) : array();
	}

	/**
	 * Whether the stored schema was derived from the current post content.
	 */
	public static function matches_content( \WP_Post $post, array $schema ): bool {
		return hash_equals( (string) ( $schema['source_hash'] ?? '' ), hash( 'sha256', (string) $post->post_content ) );
	}

	/**
	 * Entries are always stored for forms with consent or file fields (proof of consent; files
	 * are only reachable through a stored entry).
	 */
	public static function forces_storage( array $fields ): bool {
		return self::has_type( $fields, 'consent' ) || self::has_type( $fields, 'file' );
	}

	/**
	 * Editor status for one form (REST field `cradle_form_status`, edit context only).
	 */
	public static function status( int $form_id ): array {
		$fields = self::fields( $form_id );
		$files  = self::has_type( $fields, 'file' );
		return array(
			'valid'            => ! self::errors( $form_id ),
			'errors'           => self::errors( $form_id ),
			'fieldCount'       => count( $fields ),
			'hasSchema'        => null !== self::get( $form_id ),
			'forcedStorage'    => self::forces_storage( $fields ),
			'hasFileField'     => $files,
			'storageProtected' => $files ? Uploads::storage_protected() : null,
		);
	}
}
