<?php
/**
 * The bounded field type system: field definition validation, value validation,
 * storage/REST schema mapping and display formatting.
 *
 * Supported types (see docs/fields.md for the full contract and limits):
 * text, textarea, rich_text (allowlisted HTML), number, boolean, url, email, date,
 * select, multiselect, attachment, post_ref, user_ref, term_ref, group (repeated rows).
 *
 * No field setting can contain PHP, SQL, callbacks or expressions.
 *
 * @package Cradle\Content
 */

namespace Cradle\Content;

defined( 'ABSPATH' ) || exit;

final class Field_Types {

	const META_PREFIX = '_cradle_cf_';

	const TYPES = array( 'text', 'textarea', 'rich_text', 'number', 'boolean', 'url', 'email', 'date', 'select', 'multiselect', 'attachment', 'post_ref', 'user_ref', 'term_ref', 'group' );

	/** Types allowed as sub-fields of a repeated group (single values only). */
	const SUB_TYPES = array( 'text', 'textarea', 'number', 'boolean', 'url', 'email', 'date', 'select', 'attachment', 'post_ref', 'term_ref' );

	const REF_TYPES = array( 'post_ref', 'user_ref', 'term_ref' );

	const EXPOSURES = array( 'public', 'editor', 'none' );

	const MAX_LENGTH = array(
		'text'      => 1000,
		'textarea'  => 10000,
		'rich_text' => 20000,
	);

	const DEFAULT_LENGTH = array(
		'text'      => 200,
		'textarea'  => 2000,
		'rich_text' => 5000,
	);

	const MAX_OPTIONS = 100;
	const MAX_ITEMS   = 50;
	const MAX_ROWS    = 50;

	/** Allowlisted markup for limited rich text. */
	const RICH_TAGS = array(
		'a'      => array(
			'href' => true,
			'rel'  => true,
		),
		'strong' => array(),
		'em'     => array(),
		'b'      => array(),
		'i'      => array(),
		'br'     => array(),
		'p'      => array(),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
		'code'   => array(),
	);

	public static function labels(): array {
		return array(
			'text'        => __( 'Text', 'cradle-content' ),
			'textarea'    => __( 'Text area', 'cradle-content' ),
			'rich_text'   => __( 'Limited rich text', 'cradle-content' ),
			'number'      => __( 'Number', 'cradle-content' ),
			'boolean'     => __( 'True / false', 'cradle-content' ),
			'url'         => __( 'URL', 'cradle-content' ),
			'email'       => __( 'Email', 'cradle-content' ),
			'date'        => __( 'Date', 'cradle-content' ),
			'select'      => __( 'Select (one)', 'cradle-content' ),
			'multiselect' => __( 'Select (many)', 'cradle-content' ),
			'attachment'  => __( 'Media item', 'cradle-content' ),
			'post_ref'    => __( 'Post reference', 'cradle-content' ),
			'user_ref'    => __( 'User reference', 'cradle-content' ),
			'term_ref'    => __( 'Term reference', 'cradle-content' ),
			'group'       => __( 'Repeated group', 'cradle-content' ),
		);
	}

	public static function meta_key( string $field_key ): string {
		return self::META_PREFIX . $field_key;
	}

	/**
	 * Whether the value is stored as several meta rows (queryable one-value-per-row).
	 */
	public static function is_multi( array $field ): bool {
		return 'multiselect' === $field['type'] || ( in_array( $field['type'], self::REF_TYPES, true ) && ! empty( $field['multiple'] ) );
	}

	/* ---------------------------------------------------------------------
	 * Field definitions
	 * ------------------------------------------------------------------- */

	/**
	 * Validates one field definition row.
	 *
	 * @return array{field:array,errors:array<string,string>}
	 */
	public static function validate_definition( array $raw, array $ctx ): array {
		$errors = array();
		$key    = is_string( $raw['key'] ?? null ) ? strtolower( trim( $raw['key'] ) ) : '';
		if ( ! preg_match( Validator::FIELD_PATTERN, $key ) ) {
			$errors['key'] = __( 'Use 1–40 lowercase letters, numbers or underscores, starting with a letter.', 'cradle-content' );
		}
		$label = Validator::read_text( $raw['label'] ?? '', 100 );
		if ( is_wp_error( $label ) || '' === $label ) {
			$errors['label'] = is_wp_error( $label ) ? $label->get_error_message() : __( 'A label is required.', 'cradle-content' );
			$label           = is_wp_error( $label ) ? '' : $label;
		}
		$type = Validator::read_enum( $raw['type'] ?? 'text', self::TYPES, 'text' );
		if ( is_wp_error( $type ) ) {
			$errors['type'] = $type->get_error_message();
			$type           = 'text';
		}
		$parent = is_string( $raw['parent'] ?? null ) ? trim( $raw['parent'] ) : '';
		if ( '' !== $parent && ! preg_match( Validator::FIELD_PATTERN, $parent ) ) {
			$errors['parent'] = __( 'Invalid parent key.', 'cradle-content' );
			$parent           = '';
		}
		$help = Validator::read_text( $raw['help'] ?? '', 300 );
		if ( is_wp_error( $help ) ) {
			$errors['help'] = $help->get_error_message();
			$help           = '';
		}
		$required = Validator::read_bool( $raw['required'] ?? false );
		$exposure = Validator::read_enum( $raw['exposure'] ?? 'editor', self::EXPOSURES, 'editor' );
		if ( is_wp_error( $exposure ) ) {
			$errors['exposure'] = $exposure->get_error_message();
			$exposure           = 'editor';
		}

		$field = array(
			'key'      => $key,
			'label'    => $label,
			'type'     => $type,
			'parent'   => $parent,
			'help'     => $help,
			'required' => true === $required,
			'exposure' => $exposure,
		);

		switch ( $type ) {
			case 'text':
			case 'textarea':
			case 'rich_text':
				$max = Validator::read_int( ( $raw['max_length'] ?? '' ) ?: self::DEFAULT_LENGTH[ $type ], 1, self::MAX_LENGTH[ $type ] );
				if ( is_wp_error( $max ) ) {
					$errors['max_length'] = $max->get_error_message();
					$max                  = self::DEFAULT_LENGTH[ $type ];
				}
				$field['max_length'] = $max;
				break;

			case 'number':
				$integer = Validator::read_bool( $raw['integer'] ?? false );
				$field['integer'] = true === $integer;
				foreach ( array( 'min', 'max', 'step' ) as $prop ) {
					$num = Validator::read_number( $raw[ $prop ] ?? null );
					if ( is_wp_error( $num ) ) {
						$errors[ $prop ] = $num->get_error_message();
						$num             = null;
					}
					if ( null !== $num && $field['integer'] && floor( $num ) !== $num ) {
						$errors[ $prop ] = __( 'Must be a whole number for integer fields.', 'cradle-content' );
						$num             = null;
					}
					$field[ $prop ] = null === $num ? null : ( $field['integer'] ? (int) $num : $num );
				}
				if ( null !== $field['step'] && $field['step'] <= 0 ) {
					$errors['step'] = __( 'Step must be greater than zero.', 'cradle-content' );
					$field['step']  = null;
				}
				if ( null !== $field['min'] && null !== $field['max'] && $field['min'] > $field['max'] ) {
					$errors['max'] = __( 'Maximum must be greater than or equal to the minimum.', 'cradle-content' );
				}
				break;

			case 'select':
			case 'multiselect':
				$options = self::read_options( $raw['options'] ?? array() );
				if ( is_wp_error( $options ) ) {
					$errors['options'] = $options->get_error_message();
					$options           = array();
				} elseif ( ! $options ) {
					$errors['options'] = __( 'Add at least one choice.', 'cradle-content' );
				}
				$field['options'] = $options;
				if ( 'multiselect' === $type ) {
					$field['max_items'] = self::read_max_items( $raw, $errors, max( 1, count( $options ) ) );
				}
				break;

			case 'attachment':
				$mime = Validator::read_list( $raw['mime'] ?? array(), 10 );
				if ( is_wp_error( $mime ) || array_diff( $mime, Validator::MIME_PREFIXES ) ) {
					$errors['mime'] = __( 'Choose media types from the list.', 'cradle-content' );
					$mime           = array();
				}
				$field['mime'] = $mime;
				break;

			case 'post_ref':
			case 'term_ref':
			case 'user_ref':
				$targets = Validator::read_list( $raw['targets'] ?? array(), 20 );
				if ( is_wp_error( $targets ) ) {
					$errors['targets'] = $targets->get_error_message();
					$targets           = array();
				}
				if ( 'post_ref' === $type ) {
					$allowed = array_diff( Validator::referenceable_post_types( $ctx ), Validator::INTERNAL_POST_TYPES );
				} elseif ( 'term_ref' === $type ) {
					$allowed = array_diff( Validator::referenceable_taxonomies( $ctx ), Validator::INTERNAL_TAXONOMIES );
				} else {
					$allowed = array_keys( wp_roles()->roles );
				}
				$invalid = array_diff( $targets, $allowed );
				if ( $invalid && ( $ctx['strict'] ?? false ) ) {
					/* translators: %s: comma-separated keys */
					$errors['targets'] = sprintf( __( 'Unknown entity types: %s.', 'cradle-content' ), implode( ', ', $invalid ) );
				}
				foreach ( $targets as $target ) {
					if ( ! preg_match( '/^[a-z0-9_-]{1,32}$/', $target ) ) {
						$errors['targets'] = __( 'Contains an invalid key.', 'cradle-content' );
					}
				}
				if ( ! $targets && 'user_ref' !== $type ) {
					$errors['targets'] = 'post_ref' === $type ? __( 'Choose the post types that may be referenced.', 'cradle-content' ) : __( 'Choose the taxonomies that may be referenced.', 'cradle-content' );
				}
				$field['targets']  = array_values( $targets );
				$multiple          = Validator::read_bool( $raw['multiple'] ?? false );
				$field['multiple'] = true === $multiple;
				if ( $field['multiple'] ) {
					$field['max_items'] = self::read_max_items( $raw, $errors, self::MAX_ITEMS );
				}
				break;

			case 'group':
				$rows = Validator::read_int( ( $raw['max_rows'] ?? '' ) ?: 10, 1, self::MAX_ROWS );
				if ( is_wp_error( $rows ) ) {
					$errors['max_rows'] = $rows->get_error_message();
					$rows               = 10;
				}
				$field['max_rows'] = $rows;
				if ( '' !== $parent ) {
					$errors['parent'] = __( 'Repeated groups cannot be nested.', 'cradle-content' );
				}
				break;
		}

		// Defaults are allowed for simple scalar types only and must pass the field's own validation.
		$field['default'] = null;
		if ( in_array( $type, array( 'text', 'textarea', 'number', 'boolean', 'select', 'date', 'url', 'email' ), true ) && isset( $raw['default'] ) && '' !== $raw['default'] && null !== $raw['default'] ) {
			$default = self::validate_scalar( $field, $raw['default'], array( 'check_permissions' => false ) );
			if ( is_wp_error( $default ) ) {
				$errors['default'] = $default->get_error_message();
			} else {
				$field['default'] = $default;
			}
		} elseif ( 'boolean' === $type && isset( $raw['default'] ) && '' !== $raw['default'] ) {
			$field['default'] = true === Validator::read_bool( $raw['default'] );
		}

		return array(
			'field'  => $field,
			'errors' => $errors,
		);
	}

	private static function read_max_items( array $raw, array &$errors, int $cap ): int {
		$max = Validator::read_int( ( $raw['max_items'] ?? '' ) ?: min( $cap, 10 ), 1, max( 1, min( $cap, self::MAX_ITEMS ) ) );
		if ( is_wp_error( $max ) ) {
			$errors['max_items'] = $max->get_error_message();
			return min( $cap, 10 );
		}
		return $max;
	}

	/**
	 * Reads select choices from a list of {value,label} objects or from "value|Label" lines.
	 *
	 * @return array<int,array{value:string,label:string}>|\WP_Error
	 */
	public static function read_options( $raw ) {
		if ( is_string( $raw ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', $raw );
			$raw   = array();
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$parts = explode( '|', $line, 2 );
				$raw[] = array(
					'value' => trim( $parts[0] ),
					'label' => isset( $parts[1] ) ? trim( $parts[1] ) : trim( $parts[0] ),
				);
			}
		}
		if ( ! is_array( $raw ) ) {
			return new \WP_Error( 'options', __( 'Choices must be a list.', 'cradle-content' ) );
		}
		$out  = array();
		$seen = array();
		foreach ( $raw as $option ) {
			if ( is_string( $option ) ) {
				$option = array(
					'value' => $option,
					'label' => $option,
				);
			}
			if ( ! is_array( $option ) || ! is_scalar( $option['value'] ?? null ) ) {
				return new \WP_Error( 'options', __( 'Each choice needs a value.', 'cradle-content' ) );
			}
			$value = (string) $option['value'];
			if ( ! preg_match( '/^[A-Za-z0-9_.-]{1,64}$/', $value ) ) {
				/* translators: %s: choice value */
				return new \WP_Error( 'options', sprintf( __( 'Choice value "%s" may only contain letters, numbers, dots, dashes and underscores (up to 64).', 'cradle-content' ), $value ) );
			}
			if ( isset( $seen[ $value ] ) ) {
				/* translators: %s: choice value */
				return new \WP_Error( 'options', sprintf( __( 'Choice value "%s" is listed twice.', 'cradle-content' ), $value ) );
			}
			$label = Validator::read_text( $option['label'] ?? $value, 100 );
			if ( is_wp_error( $label ) ) {
				return $label;
			}
			$seen[ $value ] = true;
			$out[]          = array(
				'value' => $value,
				'label' => '' === $label ? $value : $label,
			);
			if ( count( $out ) > self::MAX_OPTIONS ) {
				/* translators: %d: maximum */
				return new \WP_Error( 'options', sprintf( __( 'At most %d choices are allowed.', 'cradle-content' ), self::MAX_OPTIONS ) );
			}
		}
		return $out;
	}

	/**
	 * Top-level fields of a group with their sub-fields attached under `subfields`.
	 */
	public static function compile( array $group ): array {
		$out = array();
		foreach ( $group['fields'] ?? array() as $field ) {
			if ( '' === ( $field['parent'] ?? '' ) ) {
				$field['subfields']    = array();
				$field['meta_key']     = self::meta_key( $field['key'] );
				$out[ $field['key'] ] = $field;
			}
		}
		foreach ( $group['fields'] ?? array() as $field ) {
			$parent = $field['parent'] ?? '';
			if ( '' !== $parent && isset( $out[ $parent ] ) && 'group' === $out[ $parent ]['type'] ) {
				$field['exposure']                          = $out[ $parent ]['exposure'];
				$out[ $parent ]['subfields'][ $field['key'] ] = $field;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Values
	 * ------------------------------------------------------------------- */

	/**
	 * Whether a raw value counts as empty for a field.
	 */
	public static function is_empty( $value ): bool {
		return null === $value || '' === $value || array() === $value;
	}

	/**
	 * Validates a complete value for a (compiled, top-level) field.
	 *
	 * @param array $field Compiled field.
	 * @param mixed $value Raw value.
	 * @param array $ctx   check_permissions (bool), current (stored value for "unchanged" permission exemptions).
	 * @return mixed|null|\WP_Error Normalized value; null means "no value" (delete).
	 */
	public static function validate( array $field, $value, array $ctx = array() ) {
		$ctx += array(
			'check_permissions' => true,
			'current'           => null,
		);
		if ( 'boolean' === $field['type'] ) {
			$bool = Validator::read_bool( $value );
			return is_wp_error( $bool ) ? $bool : $bool;
		}
		if ( self::is_empty( $value ) ) {
			return ! empty( $field['required'] ) ? new \WP_Error( 'required', __( 'This field is required.', 'cradle-content' ) ) : null;
		}

		if ( 'group' === $field['type'] ) {
			return self::validate_group( $field, $value, $ctx );
		}

		if ( self::is_multi( $field ) ) {
			if ( ! is_array( $value ) ) {
				$value = array( $value );
			}
			if ( ! array_is_list( $value ) ) {
				return new \WP_Error( 'type', __( 'Must be a list.', 'cradle-content' ) );
			}
			$max = (int) ( $field['max_items'] ?? self::MAX_ITEMS );
			$out = array();
			foreach ( $value as $item ) {
				if ( self::is_empty( $item ) ) {
					continue;
				}
				$ok = self::validate_scalar( $field, $item, $ctx );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				if ( null !== $ok && ! in_array( $ok, $out, true ) ) {
					$out[] = $ok;
				}
			}
			if ( count( $out ) > $max ) {
				/* translators: %d: maximum number of values */
				return new \WP_Error( 'max_items', sprintf( __( 'Choose at most %d values.', 'cradle-content' ), $max ) );
			}
			if ( ! $out ) {
				return ! empty( $field['required'] ) ? new \WP_Error( 'required', __( 'This field is required.', 'cradle-content' ) ) : null;
			}
			return $out;
		}

		if ( is_array( $value ) ) {
			return new \WP_Error( 'type', __( 'Must be a single value.', 'cradle-content' ) );
		}
		return self::validate_scalar( $field, $value, $ctx );
	}

	/**
	 * @return array|null|\WP_Error
	 */
	private static function validate_group( array $field, $value, array $ctx ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true, 8 );
			$value   = is_array( $decoded ) ? $decoded : $value;
		}
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return new \WP_Error( 'type', __( 'Must be a list of rows.', 'cradle-content' ) );
		}
		$max  = (int) ( $field['max_rows'] ?? 10 );
		$rows = array();
		foreach ( $value as $index => $row ) {
			if ( ! is_array( $row ) ) {
				return new \WP_Error( 'type', __( 'Each row must be a set of fields.', 'cradle-content' ) );
			}
			$clean = array();
			$empty = true;
			foreach ( $field['subfields'] ?? array() as $sub_key => $sub ) {
				$raw     = $row[ $sub_key ] ?? null;
				$sub_ctx = $ctx;
				// Permission exemption for values already stored in any row of this field.
				$sub_ctx['current'] = is_array( $ctx['current'] ) ? array_column( array_filter( $ctx['current'], 'is_array' ), $sub_key ) : null;
				if ( 'boolean' === $sub['type'] ) {
					$ok = Validator::read_bool( $raw );
				} elseif ( self::is_empty( $raw ) || 0 === $raw || '0' === $raw ) {
					$ok = null;
					if ( ! empty( $sub['required'] ) ) {
						/* translators: 1: row number, 2: field label */
						return new \WP_Error( 'required', sprintf( __( 'Row %1$d: %2$s is required.', 'cradle-content' ), $index + 1, $sub['label'] ) );
					}
				} else {
					$ok = self::validate_scalar( $sub, $raw, $sub_ctx );
				}
				if ( is_wp_error( $ok ) ) {
					/* translators: 1: row number, 2: field label, 3: error */
					return new \WP_Error( $ok->get_error_code(), sprintf( __( 'Row %1$d, %2$s: %3$s', 'cradle-content' ), $index + 1, $sub['label'], $ok->get_error_message() ) );
				}
				if ( null !== $ok && false !== $ok ) {
					$empty = false;
				}
				$clean[ $sub_key ] = null === $ok ? self::empty_sub_value( $sub ) : $ok;
			}
			if ( ! $empty ) {
				$rows[] = $clean;
			}
		}
		if ( count( $rows ) > $max ) {
			/* translators: %d: maximum rows */
			return new \WP_Error( 'max_rows', sprintf( __( 'At most %d rows are allowed.', 'cradle-content' ), $max ) );
		}
		if ( ! $rows ) {
			return ! empty( $field['required'] ) ? new \WP_Error( 'required', __( 'This field is required.', 'cradle-content' ) ) : null;
		}
		return $rows;
	}

	/**
	 * Representation of an empty sub-field value inside a stored row.
	 */
	public static function empty_sub_value( array $sub ) {
		switch ( $sub['type'] ) {
			case 'number':
				return null;
			case 'boolean':
				return false;
			case 'attachment':
			case 'post_ref':
			case 'term_ref':
				return 0;
		}
		return '';
	}

	/**
	 * Validates one scalar value (not empty).
	 *
	 * @return mixed|null|\WP_Error
	 */
	public static function validate_scalar( array $field, $value, array $ctx = array() ) {
		$ctx += array(
			'check_permissions' => true,
			'current'           => null,
		);
		if ( is_array( $value ) || is_object( $value ) ) {
			return new \WP_Error( 'type', __( 'Must be a single value.', 'cradle-content' ) );
		}
		switch ( $field['type'] ) {
			case 'text':
			case 'textarea':
				$text = Validator::read_text( $value, (int) ( $field['max_length'] ?? self::DEFAULT_LENGTH[ $field['type'] ] ), 'textarea' === $field['type'] );
				return ( is_string( $text ) && '' === $text ) ? null : $text;

			case 'rich_text':
				if ( ! is_string( $value ) || ! mb_check_encoding( $value, 'UTF-8' ) ) {
					return new \WP_Error( 'type', __( 'Must be text.', 'cradle-content' ) );
				}
				$max = (int) ( $field['max_length'] ?? self::DEFAULT_LENGTH['rich_text'] );
				if ( mb_strlen( $value ) > $max ) {
					/* translators: %d: maximum characters */
					return new \WP_Error( 'length', sprintf( __( 'Must be %d characters or fewer.', 'cradle-content' ), $max ) );
				}
				$clean = trim( wp_kses( $value, self::RICH_TAGS, array( 'http', 'https', 'mailto' ) ) );
				return '' === $clean ? null : $clean;

			case 'number':
				$num = Validator::read_number( $value );
				if ( is_wp_error( $num ) || null === $num ) {
					return $num;
				}
				if ( ! empty( $field['integer'] ) ) {
					if ( floor( $num ) !== $num ) {
						return new \WP_Error( 'type', __( 'Must be a whole number.', 'cradle-content' ) );
					}
					$num = (int) $num;
				}
				if ( isset( $field['min'] ) && null !== $field['min'] && $num < $field['min'] ) {
					/* translators: %s: minimum */
					return new \WP_Error( 'range', sprintf( __( 'Must be at least %s.', 'cradle-content' ), $field['min'] ) );
				}
				if ( isset( $field['max'] ) && null !== $field['max'] && $num > $field['max'] ) {
					/* translators: %s: maximum */
					return new \WP_Error( 'range', sprintf( __( 'Must be at most %s.', 'cradle-content' ), $field['max'] ) );
				}
				return $num;

			case 'boolean':
				return Validator::read_bool( $value );

			case 'url':
				if ( ! is_string( $value ) ) {
					return new \WP_Error( 'type', __( 'Must be a URL.', 'cradle-content' ) );
				}
				$value = trim( $value );
				if ( '' === $value ) {
					return null;
				}
				if ( strlen( $value ) > 2000 ) {
					return new \WP_Error( 'length', __( 'The URL is too long.', 'cradle-content' ) );
				}
				$clean  = esc_url_raw( $value, array( 'http', 'https' ) );
				$scheme = strtolower( (string) wp_parse_url( $clean, PHP_URL_SCHEME ) );
				if ( '' === $clean || ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === (string) wp_parse_url( $clean, PHP_URL_HOST ) ) {
					return new \WP_Error( 'url', __( 'Must be a valid http or https URL.', 'cradle-content' ) );
				}
				return $clean;

			case 'email':
				if ( ! is_string( $value ) ) {
					return new \WP_Error( 'type', __( 'Must be an email address.', 'cradle-content' ) );
				}
				$value = trim( $value );
				if ( '' === $value ) {
					return null;
				}
				if ( strlen( $value ) > 254 || ! is_email( $value ) ) {
					return new \WP_Error( 'email', __( 'Must be a valid email address.', 'cradle-content' ) );
				}
				return sanitize_email( $value );

			case 'date':
				if ( ! is_string( $value ) ) {
					return new \WP_Error( 'type', __( 'Must be a date.', 'cradle-content' ) );
				}
				$value = trim( $value );
				if ( '' === $value ) {
					return null;
				}
				if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
					return new \WP_Error( 'date', __( 'Must be a date in YYYY-MM-DD format.', 'cradle-content' ) );
				}
				return $value;

			case 'select':
			case 'multiselect':
				if ( ! is_scalar( $value ) || ! in_array( (string) $value, array_column( $field['options'] ?? array(), 'value' ), true ) ) {
					return new \WP_Error( 'enum', __( 'Is not an allowed choice.', 'cradle-content' ) );
				}
				return (string) $value;

			case 'attachment':
			case 'post_ref':
			case 'user_ref':
			case 'term_ref':
				return self::validate_reference( $field, $value, $ctx );
		}
		return new \WP_Error( 'type', __( 'Unsupported field type.', 'cradle-content' ) );
	}

	/**
	 * Validates an entity reference: existence, allowed entity type, and the current user's right to reference it.
	 *
	 * @return int|null|\WP_Error
	 */
	private static function validate_reference( array $field, $value, array $ctx ) {
		if ( is_string( $value ) ) {
			$value = trim( $value );
		}
		if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) {
			return new \WP_Error( 'type', __( 'Must reference an item by its ID.', 'cradle-content' ) );
		}
		$id = (int) $value;
		if ( $id <= 0 ) {
			return null;
		}
		$current   = $ctx['current'];
		$unchanged = ( is_array( $current ) && in_array( $id, array_map( 'intval', $current ), true ) ) || ( is_scalar( $current ) && (int) $current === $id );
		$check     = ! empty( $ctx['check_permissions'] ) && ! $unchanged;

		switch ( $field['type'] ) {
			case 'attachment':
				$post = get_post( $id );
				if ( ! $post || 'attachment' !== $post->post_type ) {
					return new \WP_Error( 'missing', __( 'The selected media item does not exist.', 'cradle-content' ) );
				}
				if ( ! empty( $field['mime'] ) ) {
					$mime = (string) get_post_mime_type( $post );
					$ok   = false;
					foreach ( (array) $field['mime'] as $prefix ) {
						$ok = $ok || str_starts_with( $mime, $prefix );
					}
					if ( ! $ok ) {
						return new \WP_Error( 'mime', __( 'This media type is not allowed here.', 'cradle-content' ) );
					}
				}
				if ( $check && ! current_user_can( 'read_post', $id ) ) {
					return new \WP_Error( 'forbidden', __( 'You are not allowed to use this media item.', 'cradle-content' ) );
				}
				return $id;

			case 'post_ref':
				$post = get_post( $id );
				if ( ! $post || ! in_array( $post->post_type, (array) ( $field['targets'] ?? array() ), true ) || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
					return new \WP_Error( 'missing', __( 'The referenced item does not exist or is not of an allowed type.', 'cradle-content' ) );
				}
				if ( $check && ! current_user_can( 'read_post', $id ) ) {
					return new \WP_Error( 'forbidden', __( 'You are not allowed to reference this item.', 'cradle-content' ) );
				}
				return $id;

			case 'user_ref':
				$user = get_userdata( $id );
				if ( ! $user ) {
					return new \WP_Error( 'missing', __( 'The referenced user does not exist.', 'cradle-content' ) );
				}
				if ( ! empty( $field['targets'] ) && ! array_intersect( (array) $user->roles, (array) $field['targets'] ) ) {
					return new \WP_Error( 'role', __( 'The referenced user does not have an allowed role.', 'cradle-content' ) );
				}
				if ( $check && get_current_user_id() !== $id && ! current_user_can( 'list_users' ) ) {
					return new \WP_Error( 'forbidden', __( 'You are not allowed to reference other users.', 'cradle-content' ) );
				}
				return $id;

			case 'term_ref':
				$term = get_term( $id );
				if ( ! $term instanceof \WP_Term || ! in_array( $term->taxonomy, (array) ( $field['targets'] ?? array() ), true ) ) {
					return new \WP_Error( 'missing', __( 'The referenced term does not exist or is not in an allowed taxonomy.', 'cradle-content' ) );
				}
				$tax = get_taxonomy( $term->taxonomy );
				if ( $check && ( ! $tax || ! current_user_can( $tax->cap->assign_terms ) ) ) {
					return new \WP_Error( 'forbidden', __( 'You are not allowed to assign terms from this taxonomy.', 'cradle-content' ) );
				}
				return $id;
		}
		return new \WP_Error( 'type', __( 'Unsupported reference.', 'cradle-content' ) );
	}

	/**
	 * Structural re-validation used as the registered meta sanitize callback (no permission checks).
	 * Returns a Rejected_Value marker for invalid data so low-level writes are refused, never coerced.
	 *
	 * @param array $field Compiled field.
	 * @param mixed $value One stored value (an item for multi-row fields).
	 */
	public static function sanitize_stored( array $field, $value ) {
		$ctx = array( 'check_permissions' => false );
		if ( self::is_multi( $field ) ) {
			if ( self::is_empty( $value ) ) {
				return new Rejected_Value( 'empty item' );
			}
			$ok = self::validate_scalar( $field, $value, $ctx );
			return ( is_wp_error( $ok ) || null === $ok ) ? new Rejected_Value( is_wp_error( $ok ) ? $ok->get_error_message() : 'empty' ) : $ok;
		}
		if ( 'boolean' === $field['type'] ) {
			$bool = Validator::read_bool( $value );
			return is_wp_error( $bool ) ? new Rejected_Value( $bool->get_error_message() ) : $bool;
		}
		if ( self::is_empty( $value ) ) {
			return 'group' === $field['type'] ? array() : '';
		}
		$field['required'] = false;
		$ok                = self::validate( $field, $value, $ctx );
		if ( is_wp_error( $ok ) ) {
			return new Rejected_Value( $ok->get_error_message() );
		}
		return null === $ok ? ( 'group' === $field['type'] ? array() : '' ) : $ok;
	}

	/* ---------------------------------------------------------------------
	 * Storage and REST schema
	 * ------------------------------------------------------------------- */

	/**
	 * WordPress meta `type` for register_meta().
	 */
	public static function meta_type( array $field ): string {
		switch ( $field['type'] ) {
			case 'number':
				return ! empty( $field['integer'] ) ? 'integer' : 'number';
			case 'boolean':
				return 'boolean';
			case 'attachment':
			case 'post_ref':
			case 'user_ref':
			case 'term_ref':
				return 'integer';
			case 'group':
				return 'array';
		}
		return 'string';
	}

	/**
	 * JSON schema of one value (of one item for multi-row fields).
	 */
	public static function value_schema( array $field, bool $in_group = false ): array {
		switch ( $field['type'] ) {
			case 'text':
			case 'textarea':
			case 'rich_text':
				return array(
					'type'      => 'string',
					'maxLength' => (int) ( $field['max_length'] ?? self::DEFAULT_LENGTH[ $field['type'] ] ),
				);
			case 'url':
				return array(
					'type'      => 'string',
					'maxLength' => 2000,
				);
			case 'email':
				return array(
					'type'      => 'string',
					'maxLength' => 254,
				);
			case 'date':
				return array(
					'type'    => 'string',
					'pattern' => '^(\d{4}-\d{2}-\d{2})?$',
				);
			case 'number':
				$schema = array( 'type' => ! empty( $field['integer'] ) ? 'integer' : 'number' );
				if ( isset( $field['min'] ) && null !== $field['min'] ) {
					$schema['minimum'] = $field['min'];
				}
				if ( isset( $field['max'] ) && null !== $field['max'] ) {
					$schema['maximum'] = $field['max'];
				}
				if ( $in_group ) {
					$schema['type'] = array( $schema['type'], 'null' );
				}
				return $schema;
			case 'boolean':
				return array( 'type' => 'boolean' );
			case 'select':
				$values = array_column( $field['options'] ?? array(), 'value' );
				return array(
					'type' => 'string',
					'enum' => array_values( array_merge( $values, array( '' ) ) ),
				);
			case 'multiselect':
				return array(
					'type' => 'string',
					'enum' => array_values( array_column( $field['options'] ?? array(), 'value' ) ),
				);
			case 'attachment':
			case 'post_ref':
			case 'user_ref':
			case 'term_ref':
				return array(
					'type'    => 'integer',
					'minimum' => 0,
				);
			case 'group':
				$properties = array();
				foreach ( $field['subfields'] ?? array() as $sub_key => $sub ) {
					$properties[ $sub_key ] = self::value_schema( $sub, true ) + array( 'title' => $sub['label'] );
				}
				return array(
					'type'     => 'array',
					'maxItems' => (int) ( $field['max_rows'] ?? 10 ),
					'items'    => array(
						'type'                 => 'object',
						'properties'           => $properties,
						'additionalProperties' => false,
					),
				);
		}
		return array( 'type' => 'string' );
	}

	/**
	 * Registered meta arguments for a compiled field (without callbacks).
	 */
	public static function meta_args( array $field ): array {
		$args = array(
			'type'              => self::meta_type( $field ),
			'single'            => ! self::is_multi( $field ),
			'description'       => $field['label'],
			'label'             => $field['label'],
			'show_in_rest'      => false,
			'revisions_enabled' => false,
		);
		if ( null !== ( $field['default'] ?? null ) ) {
			$args['default'] = $field['default'];
		} elseif ( 'group' === $field['type'] ) {
			$args['default'] = array();
		} elseif ( 'boolean' === $field['type'] ) {
			$args['default'] = false;
		}
		if ( 'none' !== $field['exposure'] ) {
			$schema = self::value_schema( $field );
			if ( 'editor' === $field['exposure'] ) {
				$schema['context'] = array( 'edit' );
			}
			$args['show_in_rest'] = array( 'schema' => $schema );
		}
		return $args;
	}

	/* ---------------------------------------------------------------------
	 * Display
	 * ------------------------------------------------------------------- */

	/**
	 * Plain-text representation of a stored value (admin columns, bindings). Not escaped.
	 */
	public static function display( array $field, $value ): string {
		if ( self::is_empty( $value ) && 'boolean' !== $field['type'] ) {
			return '';
		}
		switch ( $field['type'] ) {
			case 'boolean':
				return $value ? __( 'Yes', 'cradle-content' ) : __( 'No', 'cradle-content' );
			case 'rich_text':
				return trim( wp_strip_all_tags( (string) $value ) );
			case 'select':
			case 'multiselect':
				$labels = array_column( $field['options'] ?? array(), 'label', 'value' );
				$out    = array();
				foreach ( (array) $value as $item ) {
					$out[] = $labels[ (string) $item ] ?? (string) $item;
				}
				return implode( ', ', $out );
			case 'attachment':
				$id = (int) $value;
				return $id ? (string) get_the_title( $id ) : '';
			case 'post_ref':
				$out = array();
				foreach ( (array) $value as $id ) {
					$id = (int) $id;
					if ( $id && ( is_post_publicly_viewable( $id ) || current_user_can( 'read_post', $id ) ) ) {
						$out[] = get_the_title( $id );
					}
				}
				return implode( ', ', $out );
			case 'user_ref':
				$out = array();
				foreach ( (array) $value as $id ) {
					$user = get_userdata( (int) $id );
					if ( $user ) {
						$out[] = $user->display_name;
					}
				}
				return implode( ', ', $out );
			case 'term_ref':
				$out = array();
				foreach ( (array) $value as $id ) {
					$term = get_term( (int) $id );
					if ( $term instanceof \WP_Term ) {
						$out[] = $term->name;
					}
				}
				return implode( ', ', $out );
			case 'group':
				$count = is_array( $value ) ? count( $value ) : 0;
				/* translators: %d: number of rows */
				return sprintf( _n( '%d row', '%d rows', $count, 'cradle-content' ), $count );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}
}
