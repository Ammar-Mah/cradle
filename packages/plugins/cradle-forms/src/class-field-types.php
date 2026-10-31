<?php
/**
 * Field types: block registration, configuration normalization (schema derivation), server-side
 * value validation, front-end rendering and display of stored values.
 *
 * Based on the field model of the Complaint Management System by WND (GPL-2.0-or-later): one
 * definition per type that sanitizes its configuration, validates submitted values against an
 * allowlist/range and renders accessible markup. Reworked as one table-driven class for blocks.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Field_Types {

	const TYPES        = array( 'text', 'email', 'tel', 'url', 'number', 'textarea', 'select', 'radio', 'checkboxes', 'date', 'consent', 'file', 'hidden' );
	const CHOICE_TYPES = array( 'select', 'radio', 'checkboxes' );
	const BLOCK_PREFIX = 'cradle-forms/field-';
	const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,40}$/';
	const MAX_CHOICES  = 100;
	const MAX_FILES    = 10;

	/** Autocomplete tokens offered for text fields (WCAG 1.3.5). */
	const AUTOCOMPLETE = array( '', 'name', 'given-name', 'family-name', 'nickname', 'organization', 'organization-title', 'street-address', 'address-line1', 'address-line2', 'address-level1', 'address-level2', 'postal-code', 'country-name', 'bday', 'sex', 'language', 'off' );

	public static function block_names(): array {
		return array_map(
			static function ( $type ) {
				return self::BLOCK_PREFIX . $type;
			},
			self::TYPES
		);
	}

	public static function type_of_block( string $block_name ): ?string {
		if ( 0 !== strpos( $block_name, self::BLOCK_PREFIX ) ) {
			return null;
		}
		$type = substr( $block_name, strlen( self::BLOCK_PREFIX ) );
		return in_array( $type, self::TYPES, true ) ? $type : null;
	}

	public static function register_blocks() {
		foreach ( self::TYPES as $type ) {
			$dir = CRADLE_FORMS_DIR . 'blocks/field-' . $type;
			if ( is_file( $dir . '/block.json' ) && ! \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK_PREFIX . $type ) ) {
				register_block_type( $dir, array( 'render_callback' => array( Renderer::class, 'render_field_block' ) ) );
			}
		}
	}

	public static function is_input( string $type ): bool {
		return in_array( $type, self::TYPES, true );
	}

	public static function consent_tags(): array {
		return array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
		);
	}

	public static function plain( $value, int $max = 255 ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		// Block attributes saved by users without unfiltered_html arrive kses-encoded ("&" → "&amp;").
		$value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return mb_substr( sanitize_text_field( $value ), 0, $max );
	}

	private static function multiline( $value, int $max = 1000 ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return mb_substr( sanitize_textarea_field( $value ), 0, $max );
	}

	private static function number_or_null( $value ): ?float {
		if ( null === $value || '' === $value || is_bool( $value ) || ! is_numeric( $value ) ) {
			return null;
		}
		return (float) $value;
	}

	public static function is_date( string $date ): bool {
		return 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/* ------------------------------------------------------------------ */
	/* Configuration (schema derivation)                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Normalizes a field block's attributes into a schema field.
	 *
	 * @return array{field:array,errors:string[]}
	 */
	public static function config_from_block( string $type, array $a ): array {
		$errors = array();
		$name   = is_string( $a['name'] ?? null ) ? strtolower( trim( $a['name'] ) ) : '';
		$label  = 'consent' === $type
			? mb_substr( trim( wp_kses( is_scalar( $a['label'] ?? null ) ? (string) $a['label'] : '', self::consent_tags() ) ), 0, 2000 )
			: self::plain( $a['label'] ?? '', 255 );
		$field  = array(
			'name'        => $name,
			'type'        => $type,
			'label'       => $label,
			'help'        => self::multiline( $a['help'] ?? '', 1000 ),
			'placeholder' => self::plain( $a['placeholder'] ?? '', 200 ),
			'required'    => 'hidden' !== $type && ! empty( $a['required'] ),
			'width'       => 'half' === ( $a['width'] ?? '' ) ? 'half' : 'full',
			'default'     => '',
		);
		$shown  = '' !== $name ? $name : $type;

		if ( ! preg_match( self::NAME_PATTERN, $name ) ) {
			/* translators: %s: field name or type */
			$errors[] = sprintf( __( 'Field “%s”: the name must start with a letter and use only lowercase letters, digits and underscores (at most 41 characters).', 'cradle-forms' ), $shown );
		}
		if ( 'hidden' !== $type && '' === trim( wp_strip_all_tags( $label ) ) ) {
			/* translators: %s: field name */
			$errors[] = sprintf( __( 'Field “%s” needs a label.', 'cradle-forms' ), $shown );
		}
		if ( 'hidden' === $type && '' === $label ) {
			$field['label'] = $name;
		}

		switch ( $type ) {
			case 'text':
				$field['max_length']   = max( 1, min( 5000, (int) ( $a['maxLength'] ?? 255 ) ?: 255 ) );
				$field['autocomplete'] = in_array( $a['autocomplete'] ?? '', self::AUTOCOMPLETE, true ) ? (string) ( $a['autocomplete'] ?? '' ) : '';
				$field['default']      = mb_substr( self::plain( $a['defaultValue'] ?? '', 5000 ), 0, $field['max_length'] );
				break;

			case 'textarea':
				$field['max_length'] = max( 1, min( 20000, (int) ( $a['maxLength'] ?? 5000 ) ?: 5000 ) );
				$field['rows']       = max( 2, min( 20, (int) ( $a['rows'] ?? 5 ) ?: 5 ) );
				$field['default']    = mb_substr( self::multiline( $a['defaultValue'] ?? '', 20000 ), 0, $field['max_length'] );
				break;

			case 'email':
				$field['submitter_email'] = ! empty( $a['submitterEmail'] );
				$default                  = self::plain( $a['defaultValue'] ?? '', 254 );
				$field['default']         = is_email( $default ) ? $default : '';
				break;

			case 'tel':
			case 'url':
				$field['default'] = self::plain( $a['defaultValue'] ?? '', 'url' === $type ? 2000 : 40 );
				break;

			case 'number':
				$field['min']  = self::number_or_null( $a['min'] ?? null );
				$field['max']  = self::number_or_null( $a['max'] ?? null );
				$field['step'] = self::number_or_null( $a['step'] ?? null );
				if ( null !== $field['min'] && null !== $field['max'] && $field['min'] > $field['max'] ) {
					/* translators: %s: field name */
					$errors[] = sprintf( __( 'Field “%s”: the minimum is greater than the maximum.', 'cradle-forms' ), $shown );
				}
				if ( null !== $field['step'] && $field['step'] <= 0 ) {
					$field['step'] = null;
				}
				$default          = self::plain( $a['defaultValue'] ?? '', 40 );
				$field['default'] = is_numeric( $default ) ? $default : '';
				break;

			case 'date':
				$field['min'] = self::is_date( (string) ( $a['min'] ?? '' ) ) ? (string) $a['min'] : '';
				$field['max'] = self::is_date( (string) ( $a['max'] ?? '' ) ) ? (string) $a['max'] : '';
				if ( '' !== $field['min'] && '' !== $field['max'] && $field['min'] > $field['max'] ) {
					/* translators: %s: field name */
					$errors[] = sprintf( __( 'Field “%s”: the minimum is greater than the maximum.', 'cradle-forms' ), $shown );
				}
				$field['default'] = self::is_date( (string) ( $a['defaultValue'] ?? '' ) ) ? (string) $a['defaultValue'] : '';
				break;

			case 'select':
			case 'radio':
			case 'checkboxes':
				$choices = array();
				$seen    = array();
				$raw     = is_array( $a['choices'] ?? null ) ? array_slice( $a['choices'], 0, self::MAX_CHOICES ) : array();
				foreach ( $raw as $index => $choice ) {
					if ( ! is_array( $choice ) ) {
						continue;
					}
					$choice_label = self::plain( $choice['label'] ?? '', 200 );
					$value        = self::plain( $choice['value'] ?? '', 100 );
					if ( '' === $value ) {
						$value = '' !== $choice_label ? mb_substr( $choice_label, 0, 100 ) : 'choice_' . ( $index + 1 );
					}
					if ( '' === $choice_label ) {
						$choice_label = $value;
					}
					if ( isset( $seen[ $value ] ) ) {
						/* translators: 1: field name, 2: choice value */
						$errors[] = sprintf( __( 'Field “%1$s” has the choice value “%2$s” twice.', 'cradle-forms' ), $shown, $value );
						continue;
					}
					$seen[ $value ] = true;
					$choices[]      = array(
						'value' => $value,
						'label' => $choice_label,
					);
				}
				if ( ! $choices ) {
					/* translators: %s: field name */
					$errors[] = sprintf( __( 'Field “%s” needs at least one choice.', 'cradle-forms' ), $shown );
				}
				$field['choices'] = $choices;
				$default          = self::plain( $a['defaultValue'] ?? '', 100 );
				$field['default'] = 'checkboxes' !== $type && isset( $seen[ $default ] ) ? $default : ( 'checkboxes' === $type ? array() : '' );
				if ( 'checkboxes' === $type ) {
					$field['min_choices'] = max( 0, min( self::MAX_CHOICES, (int) ( $a['minChoices'] ?? 0 ) ) );
					$field['max_choices'] = max( 0, min( self::MAX_CHOICES, (int) ( $a['maxChoices'] ?? 0 ) ) );
					if ( $field['max_choices'] && $field['min_choices'] > $field['max_choices'] ) {
						/* translators: %s: field name */
						$errors[] = sprintf( __( 'Field “%s”: the minimum is greater than the maximum.', 'cradle-forms' ), $shown );
					}
				}
				break;

			case 'consent':
				$field['required'] = ! array_key_exists( 'required', $a ) || ! empty( $a['required'] );
				$field['label_text'] = trim( wp_strip_all_tags( $label ) );
				break;

			case 'file':
				$site      = Settings_Section::allowed_extensions();
				$requested = Settings_Section::parse_extensions( is_string( $a['accept'] ?? null ) ? $a['accept'] : '' );
				$refused   = array_values( array_diff( $requested, $site ) );
				if ( $refused ) {
					/* translators: 1: field name, 2: list of file extensions */
					$errors[] = sprintf( __( 'Field “%1$s”: these file types are not allowed on this site: %2$s.', 'cradle-forms' ), $shown, implode( ', ', $refused ) );
				}
				$accept = $requested ? array_values( array_intersect( $requested, $site ) ) : $site;
				if ( ! $accept ) {
					/* translators: %s: field name */
					$errors[] = sprintf( __( 'Field “%s” has no allowed file types. Check Cradle → Forms → Settings.', 'cradle-forms' ), $shown );
				}
				$size_mb            = is_numeric( $a['maxSize'] ?? null ) ? (float) $a['maxSize'] : 5.0;
				$field['accept']    = $accept;
				$field['max_bytes'] = (int) max( 1024, min( 1024 * MB_IN_BYTES, round( max( 0.1, $size_mb ) * MB_IN_BYTES ) ) );
				$field['max_files'] = max( 1, min( self::MAX_FILES, (int) ( $a['maxFiles'] ?? 1 ) ?: 1 ) );
				break;

			case 'hidden':
				$field['default']    = self::plain( $a['defaultValue'] ?? '', 200 );
				$query               = is_string( $a['fromQuery'] ?? null ) ? strtolower( trim( $a['fromQuery'] ) ) : '';
				$field['from_query'] = preg_match( '/^[a-z0-9_]{1,40}$/', $query ) ? $query : '';
				break;
		}
		return array(
			'field'  => $field,
			'errors' => $errors,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Values                                                              */
	/* ------------------------------------------------------------------ */

	public static function is_empty( $value ): bool {
		return is_array( $value ) ? array() === $value : ( null === $value || '' === trim( (string) $value ) );
	}

	/**
	 * Effective per-file limit of a file field: its own size, the site setting and the server
	 * limit of the current request, whichever is smallest.
	 */
	public static function file_max_bytes( array $field ): int {
		return max( 1, min( (int) ( $field['max_bytes'] ?? 0 ), Settings_Section::max_bytes() ) );
	}

	private static function result( $value, ?string $error = null ): array {
		return array(
			'value' => $value,
			'error' => $error,
		);
	}

	public static function required_message(): string {
		return __( 'This field is required.', 'cradle-forms' );
	}

	public static function choice_values( array $field ): array {
		return array_map( 'strval', wp_list_pluck( (array) ( $field['choices'] ?? array() ), 'value' ) );
	}

	public static function normalize_digits( string $value ): string {
		return strtr(
			$value,
			array(
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
				'۰' => '0',
				'۱' => '1',
				'۲' => '2',
				'۳' => '3',
				'۴' => '4',
				'۵' => '5',
				'۶' => '6',
				'۷' => '7',
				'۸' => '8',
				'۹' => '9',
				'٫' => '.',
			)
		);
	}

	/**
	 * Sanitizes and validates one submitted (unslashed) value. File and hidden fields are handled
	 * by the submission pipeline.
	 *
	 * @return array{value:mixed,error:?string}
	 */
	public static function check( array $field, $raw ): array {
		$type     = $field['type'];
		$required = ! empty( $field['required'] );
		$invalid  = __( 'This value is not valid.', 'cradle-forms' );

		if ( 'checkboxes' === $type ) {
			$list = is_array( $raw ) ? $raw : ( is_scalar( $raw ) && '' !== (string) $raw ? array( $raw ) : array() );
			$out  = array();
			foreach ( array_slice( $list, 0, self::MAX_CHOICES + 1 ) as $item ) {
				if ( ! is_scalar( $item ) ) {
					return self::result( array(), $invalid );
				}
				$out[] = sanitize_text_field( (string) $item );
			}
			$out = array_values( array_unique( array_filter( $out, 'strlen' ) ) );
			if ( ! $out ) {
				return self::result( array(), $required ? self::required_message() : null );
			}
			if ( array_diff( $out, self::choice_values( $field ) ) ) {
				return self::result( array(), __( 'Please choose from the available options.', 'cradle-forms' ) );
			}
			$count = count( $out );
			if ( ! empty( $field['min_choices'] ) && $count < $field['min_choices'] ) {
				/* translators: %d: minimum number of selections */
				return self::result( $out, sprintf( _n( 'Please select at least %d option.', 'Please select at least %d options.', (int) $field['min_choices'], 'cradle-forms' ), (int) $field['min_choices'] ) );
			}
			if ( ! empty( $field['max_choices'] ) && $count > $field['max_choices'] ) {
				/* translators: %d: maximum number of selections */
				return self::result( $out, sprintf( _n( 'Please select no more than %d option.', 'Please select no more than %d options.', (int) $field['max_choices'], 'cradle-forms' ), (int) $field['max_choices'] ) );
			}
			return self::result( $out );
		}

		if ( null === $raw ) {
			$raw = '';
		}
		if ( ! is_scalar( $raw ) || ! mb_check_encoding( (string) $raw, 'UTF-8' ) ) {
			return self::result( '', $invalid );
		}
		$raw = (string) $raw;

		switch ( $type ) {
			case 'text':
			case 'textarea':
				$value = 'textarea' === $type ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
				if ( '' === trim( $value ) ) {
					return self::result( '', $required ? self::required_message() : null );
				}
				$max = (int) ( $field['max_length'] ?? 255 );
				if ( mb_strlen( $value ) > $max ) {
					/* translators: %d: maximum number of characters */
					return self::result( mb_substr( $value, 0, $max ), sprintf( _n( 'Please enter at most %d character.', 'Please enter at most %d characters.', $max, 'cradle-forms' ), $max ) );
				}
				return self::result( $value );

			case 'email':
				$value = trim( sanitize_text_field( $raw ) );
				if ( '' === $value ) {
					return self::result( '', $required ? self::required_message() : null );
				}
				if ( strlen( $value ) > 254 || ! is_email( $value ) ) {
					return self::result( mb_substr( $value, 0, 254 ), __( 'Please enter a valid email address.', 'cradle-forms' ) );
				}
				return self::result( $value );

			case 'tel':
				$value = trim( sanitize_text_field( self::normalize_digits( $raw ) ) );
				if ( '' === $value ) {
					return self::result( '', $required ? self::required_message() : null );
				}
				$digits = strlen( (string) preg_replace( '/\D/', '', $value ) );
				if ( ! preg_match( '/^\+?[0-9\s().\/-]{3,40}$/', $value ) || $digits < 5 || $digits > 15 ) {
					return self::result( mb_substr( $value, 0, 40 ), __( 'Please enter a valid phone number.', 'cradle-forms' ) );
				}
				return self::result( $value );

			case 'url':
				$value = trim( sanitize_text_field( $raw ) );
				if ( '' === $value ) {
					return self::result( '', $required ? self::required_message() : null );
				}
				$scheme = strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) );
				$host   = (string) wp_parse_url( $value, PHP_URL_HOST );
				if ( strlen( $value ) > 2000 || false === filter_var( $value, FILTER_VALIDATE_URL ) || ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
					return self::result( mb_substr( $value, 0, 2000 ), __( 'Please enter a valid web address starting with https://.', 'cradle-forms' ) );
				}
				return self::result( esc_url_raw( $value, array( 'http', 'https' ) ) );

			case 'number':
				$value = trim( sanitize_text_field( self::normalize_digits( $raw ) ) );
				if ( '' === $value ) {
					return self::result( '', $required ? self::required_message() : null );
				}
				if ( ! preg_match( '/^-?\d{1,15}(\.\d{1,10})?$/', $value ) ) {
					return self::result( mb_substr( $value, 0, 40 ), __( 'Please enter a valid number.', 'cradle-forms' ) );
				}
				$number = (float) $value;
				if ( null !== ( $field['min'] ?? null ) && $number < $field['min'] ) {
					/* translators: %s: minimum value */
					return self::result( $value, sprintf( __( 'Please enter a value of at least %s.', 'cradle-forms' ), self::format_number( $field['min'] ) ) );
				}
				if ( null !== ( $field['max'] ?? null ) && $number > $field['max'] ) {
					/* translators: %s: maximum value */
					return self::result( $value, sprintf( __( 'Please enter a value no greater than %s.', 'cradle-forms' ), self::format_number( $field['max'] ) ) );
				}
				if ( null !== ( $field['step'] ?? null ) && $field['step'] > 0 ) {
					$steps = ( $number - (float) ( $field['min'] ?? 0 ) ) / (float) $field['step'];
					if ( abs( $steps - round( $steps ) ) > 1e-9 ) {
						/* translators: %s: step size */
						return self::result( $value, sprintf( __( 'Please enter a value in steps of %s.', 'cradle-forms' ), self::format_number( $field['step'] ) ) );
					}
				}
				return self::result( $value );

			case 'select':
			case 'radio':
				$value = sanitize_text_field( $raw );
				if ( '' === $value ) {
					return self::result( '', $required ? self::required_message() : null );
				}
				if ( ! in_array( $value, self::choice_values( $field ), true ) ) {
					return self::result( '', __( 'Please choose from the available options.', 'cradle-forms' ) );
				}
				return self::result( $value );

			case 'date':
				$value = trim( sanitize_text_field( self::normalize_digits( $raw ) ) );
				if ( '' === $value ) {
					return self::result( '', $required ? self::required_message() : null );
				}
				if ( ! self::is_date( $value ) ) {
					return self::result( mb_substr( $value, 0, 20 ), __( 'Please enter a valid date (YYYY-MM-DD).', 'cradle-forms' ) );
				}
				if ( '' !== ( $field['min'] ?? '' ) && $value < $field['min'] ) {
					/* translators: %s: earliest allowed date */
					return self::result( $value, sprintf( __( 'Please enter a date on or after %s.', 'cradle-forms' ), self::format_date( $field['min'] ) ) );
				}
				if ( '' !== ( $field['max'] ?? '' ) && $value > $field['max'] ) {
					/* translators: %s: latest allowed date */
					return self::result( $value, sprintf( __( 'Please enter a date on or before %s.', 'cradle-forms' ), self::format_date( $field['max'] ) ) );
				}
				return self::result( $value );

			case 'consent':
				$given = in_array( strtolower( trim( $raw ) ), array( '1', 'on', 'yes', 'true' ), true );
				if ( ! $given ) {
					return self::result( '', $required ? self::required_message() : null );
				}
				return self::result( '1' );
		}
		return self::result( '', $invalid );
	}

	private static function format_number( $number ): string {
		$string   = (string) $number;
		$dot      = strpos( $string, '.' );
		$decimals = false === $dot ? 0 : min( 6, strlen( rtrim( substr( $string, $dot + 1 ), '0' ) ) );
		return number_format_i18n( (float) $number, $decimals );
	}

	private static function format_date( string $date ): string {
		$time = strtotime( $date . ' 00:00:00 UTC' );
		return false === $time ? $date : wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $time, new \DateTimeZone( 'UTC' ) );
	}

	public static function to_storage( $value ): string {
		return is_array( $value ) ? (string) wp_json_encode( array_values( $value ), JSON_UNESCAPED_UNICODE ) : (string) $value;
	}

	/**
	 * Plain-text display of a stored value (admin, CSV, email). Escape for the output context.
	 *
	 * @param string     $type   Field type at submission time.
	 * @param string     $stored Stored value.
	 * @param array|null $field  Current schema field with the same name and type (for choice labels).
	 */
	public static function display( string $type, ?string $stored, ?array $field = null ): string {
		$stored = (string) $stored;
		if ( in_array( $type, array( 'checkboxes', 'file' ), true ) ) {
			$list = json_decode( $stored, true );
			$list = is_array( $list ) ? array_map( 'strval', $list ) : ( '' !== $stored ? array( $stored ) : array() );
			if ( 'checkboxes' === $type && $field ) {
				$list = array_map(
					static function ( $value ) use ( $field ) {
						return self::choice_label( $field, $value );
					},
					$list
				);
			}
			return implode( ', ', $list );
		}
		if ( 'consent' === $type ) {
			return '1' === $stored ? __( 'Yes', 'cradle-forms' ) : __( 'No', 'cradle-forms' );
		}
		if ( in_array( $type, array( 'select', 'radio' ), true ) && $field && '' !== $stored ) {
			return self::choice_label( $field, $stored );
		}
		return $stored;
	}

	public static function choice_label( array $field, string $value ): string {
		foreach ( (array) ( $field['choices'] ?? array() ) as $choice ) {
			if ( (string) $choice['value'] === $value ) {
				return (string) $choice['label'];
			}
		}
		return $value;
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Escaped attribute string. true → boolean attribute; null/false/'' → omitted (except value).
	 */
	public static function attrs( array $attrs ): string {
		$out = '';
		foreach ( $attrs as $name => $value ) {
			if ( null === $value || false === $value || ( '' === $value && 'value' !== $name ) ) {
				continue;
			}
			$out .= true === $value ? ' ' . esc_attr( $name ) : ' ' . esc_attr( $name ) . '="' . esc_attr( (string) $value ) . '"';
		}
		return $out;
	}

	private static function required_marker( array $field ): string {
		if ( empty( $field['required'] ) ) {
			return '';
		}
		return ' <span class="cradle-form__required" aria-hidden="true">*</span><span class="cradle-form__sr">' . esc_html__( '(required)', 'cradle-forms' ) . '</span>';
	}

	private static function describedby( array $field, string $id ): string {
		$ids = array();
		if ( '' !== $field['help'] ) {
			$ids[] = $id . '-help';
		}
		if ( 'file' === $field['type'] ) {
			$ids[] = $id . '-hint';
		}
		$ids[] = $id . '-error';
		return implode( ' ', $ids );
	}

	private static function help_html( array $field, string $id ): string {
		return '' === $field['help'] ? '' : '<p class="cradle-form__help" id="' . esc_attr( $id . '-help' ) . '">' . nl2br( esc_html( $field['help'] ) ) . '</p>';
	}

	private static function error_html( string $id, string $error ): string {
		return '<p class="cradle-form__error" id="' . esc_attr( $id . '-error' ) . '"' . ( '' === $error ? ' hidden' : '' ) . '>' . esc_html( $error ) . '</p>';
	}

	/**
	 * The element an error-summary link points to.
	 */
	public static function target_id( array $field, string $id ): string {
		return in_array( $field['type'], array( 'radio', 'checkboxes' ), true ) ? $id . '-0' : $id;
	}

	/**
	 * Escaped HTML for one field.
	 *
	 * @param array  $field Schema field.
	 * @param mixed  $value Current value (default or previously submitted).
	 * @param string $id    Element ID base.
	 * @param string $error Error message ('' for none).
	 */
	public static function render( array $field, $value, string $id, string $error = '' ): string {
		$type = $field['type'];
		$name = 'cradle_fields[' . $field['name'] . ']';

		if ( 'hidden' === $type ) {
			if ( '' === ( $field['from_query'] ?? '' ) ) {
				return ''; // The configured value is added on the server; nothing to send.
			}
			return '<input' . self::attrs(
				array(
					'type'  => 'hidden',
					'name'  => $name,
					'value' => is_scalar( $value ) ? (string) $value : '',
				)
			) . ' />';
		}

		$classes = 'cradle-form__field cradle-form__field--' . $type . ' cradle-form__field--' . $field['width'] . ( '' !== $error ? ' has-error' : '' );
		$common  = array(
			'id'               => $id,
			'name'             => $name,
			'class'            => 'cradle-form__input',
			'required'         => ! empty( $field['required'] ),
			'aria-invalid'     => '' !== $error ? 'true' : null,
			'aria-describedby' => self::describedby( $field, $id ),
		);
		$label   = '<label class="cradle-form__label" for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . self::required_marker( $field ) . '</label>';
		$scalar  = is_scalar( $value ) ? (string) $value : '';

		switch ( $type ) {
			case 'radio':
			case 'checkboxes':
				$selected = array_map( 'strval', (array) $value );
				$input    = 'radio' === $type ? 'radio' : 'checkbox';
				$html     = '<fieldset class="' . esc_attr( $classes ) . '" data-cradle-field="' . esc_attr( $field['name'] ) . '" aria-describedby="' . esc_attr( self::describedby( $field, $id ) ) . '"' . ( '' !== $error ? ' aria-invalid="true"' : '' ) . '>';
				$html    .= '<legend class="cradle-form__label">' . esc_html( $field['label'] ) . self::required_marker( $field ) . '</legend>';
				$html    .= self::help_html( $field, $id ) . '<div class="cradle-form__choices">';
				foreach ( $field['choices'] as $index => $choice ) {
					$choice_id = $id . '-' . $index;
					$html     .= '<div class="cradle-form__choice"><input' . self::attrs(
						array(
							'type'         => $input,
							'id'           => $choice_id,
							'name'         => 'checkbox' === $input ? $name . '[]' : $name,
							'value'        => $choice['value'],
							'checked'      => in_array( (string) $choice['value'], $selected, true ),
							'required'     => 'radio' === $input && ! empty( $field['required'] ),
							'aria-invalid' => '' !== $error ? 'true' : null,
						)
					) . ' /> <label for="' . esc_attr( $choice_id ) . '">' . esc_html( $choice['label'] ) . '</label></div>';
				}
				return $html . '</div>' . self::error_html( $id, $error ) . '</fieldset>';

			case 'consent':
				$label_html = wp_kses( $field['label'], self::consent_tags() );
				$html       = '<div class="' . esc_attr( $classes ) . '" data-cradle-field="' . esc_attr( $field['name'] ) . '"><div class="cradle-form__choice"><input' . self::attrs(
					array_merge(
						$common,
						array(
							'type'    => 'checkbox',
							'value'   => '1',
							'checked' => '1' === $scalar,
							'class'   => 'cradle-form__checkbox',
						)
					)
				) . ' /> <label class="cradle-form__consent-label" for="' . esc_attr( $id ) . '">' . $label_html . self::required_marker( $field ) . '</label></div>';
				$html      .= self::help_html( $field, $id ) . self::error_html( $id, $error );
				$html      .= '<input type="hidden" name="' . esc_attr( 'cradle_consent[' . $field['name'] . ']' ) . '" value="' . esc_attr( Submission::consent_hash( $label_html ) ) . '" />';
				return $html . '</div>';

			case 'textarea':
				$control = '<textarea' . self::attrs(
					array_merge(
						$common,
						array(
							'rows'        => (string) $field['rows'],
							'maxlength'   => (string) $field['max_length'],
							'placeholder' => $field['placeholder'],
						)
					)
				) . '>' . esc_textarea( $scalar ) . '</textarea>';
				break;

			case 'select':
				$control  = '<select' . self::attrs( $common ) . '>';
				$control .= '<option value="">' . esc_html( '' !== $field['placeholder'] ? $field['placeholder'] : __( 'Select an option', 'cradle-forms' ) ) . '</option>';
				foreach ( $field['choices'] as $choice ) {
					$control .= '<option' . self::attrs(
						array(
							'value'    => $choice['value'],
							'selected' => (string) $choice['value'] === $scalar,
						)
					) . '>' . esc_html( $choice['label'] ) . '</option>';
				}
				$control .= '</select>';
				break;

			case 'file':
				$accept  = implode(
					',',
					array_map(
						static function ( $ext ) {
							return '.' . $ext;
						},
						$field['accept']
					)
				);
				$control = '<input' . self::attrs(
					array_merge(
						$common,
						array(
							'type'           => 'file',
							'name'           => 'cradle_files[' . $field['name'] . '][]',
							'accept'         => $accept,
							'multiple'       => $field['max_files'] > 1,
							'data-max-bytes' => (string) self::file_max_bytes( $field ),
							'data-max-files' => (string) $field['max_files'],
						)
					)
				) . ' />';
				$hint    = $field['max_files'] > 1
					/* translators: 1: list of file types, 2: maximum size such as "5 MB", 3: maximum number of files */
					? sprintf( __( 'Allowed: %1$s. Up to %2$s per file, at most %3$d files.', 'cradle-forms' ), strtoupper( implode( ', ', $field['accept'] ) ), size_format( self::file_max_bytes( $field ) ), $field['max_files'] )
					/* translators: 1: list of file types, 2: maximum size such as "5 MB" */
					: sprintf( __( 'Allowed: %1$s. Up to %2$s.', 'cradle-forms' ), strtoupper( implode( ', ', $field['accept'] ) ), size_format( self::file_max_bytes( $field ) ) );
				$control .= '<p class="cradle-form__hint" id="' . esc_attr( $id . '-hint' ) . '">' . esc_html( $hint ) . '</p>';
				break;

			default:
				$extra = array(
					'type'        => 'text',
					'value'       => $scalar,
					'placeholder' => $field['placeholder'],
				);
				switch ( $type ) {
					case 'text':
						$extra['maxlength']    = (string) $field['max_length'];
						$extra['autocomplete'] = $field['autocomplete'];
						break;
					case 'email':
						$extra['type']         = 'email';
						$extra['autocomplete'] = 'email';
						$extra['dir']          = 'ltr';
						$extra['maxlength']    = '254';
						break;
					case 'tel':
						$extra['type']         = 'tel';
						$extra['autocomplete'] = 'tel';
						$extra['dir']          = 'ltr';
						$extra['maxlength']    = '40';
						break;
					case 'url':
						$extra['type']         = 'url';
						$extra['autocomplete'] = 'url';
						$extra['dir']          = 'ltr';
						$extra['maxlength']    = '2000';
						break;
					case 'number':
						$extra['type']      = 'number';
						$extra['inputmode'] = 'decimal';
						$extra['min']       = null !== $field['min'] ? (string) ( 0 + $field['min'] ) : null;
						$extra['max']       = null !== $field['max'] ? (string) ( 0 + $field['max'] ) : null;
						$extra['step']      = null !== $field['step'] ? (string) ( 0 + $field['step'] ) : 'any';
						break;
					case 'date':
						$extra['type'] = 'date';
						$extra['min']  = $field['min'];
						$extra['max']  = $field['max'];
						break;
				}
				$control = '<input' . self::attrs( array_merge( $common, $extra ) ) . ' />';
		}

		return '<div class="' . esc_attr( $classes ) . '" data-cradle-field="' . esc_attr( $field['name'] ) . '">' . $label . self::help_html( $field, $id ) . $control . self::error_html( $id, $error ) . '</div>';
	}
}
