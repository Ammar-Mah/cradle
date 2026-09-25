<?php
/**
 * Settings schema registry built on the WordPress Settings API.
 *
 * Each section is one registered option owned by one module. The option holds
 * only declared fields (unknown keys are dropped) plus the reserved `_revision`
 * and `_schema` keys used for stale-edit detection and schema migration.
 *
 * @package Cradle
 */

namespace Cradle\Core;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const FIELD_TYPES = array( 'boolean', 'integer', 'number', 'string', 'enum', 'multi_enum', 'url', 'email', 'attachment', 'color', 'dimension', 'object' );

	/** @var array<string,array> */
	private $sections = array();

	/** @var array<string,mixed> Per-request memo guarding WordPress' double sanitize on first save. */
	private $sanitized = array();

	/** @var string|null Option whose sanitize callback is bypassed for pre-validated programmatic writes. */
	private $bypass = null;

	private Capabilities $capabilities;

	public function __construct( Capabilities $capabilities ) {
		$this->capabilities = $capabilities;
	}

	/**
	 * Registers a settings section. Call from a module `boot` callback.
	 *
	 * @return true|\WP_Error
	 */
	public function register_section( array $section ) {
		$id = isset( $section['id'] ) ? (string) $section['id'] : '';
		if ( ! preg_match( '/^[a-z][a-z0-9_-]{1,40}$/', $id ) ) {
			return new \WP_Error( 'cradle_section_id', 'Invalid section ID.' );
		}
		if ( isset( $this->sections[ $id ] ) ) {
			return new \WP_Error( 'cradle_section_duplicate', 'Section already registered.' );
		}
		$option = isset( $section['option'] ) ? (string) $section['option'] : 'cradle_' . str_replace( '-', '_', $id ) . '_settings';
		if ( ! preg_match( '/^cradle_[a-z0-9_]{2,80}$/', $option ) ) {
			return new \WP_Error( 'cradle_section_option', 'Section options must use the cradle_ prefix.' );
		}
		$fields = array();
		foreach ( (array) ( $section['fields'] ?? array() ) as $key => $field ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z][a-z0-9_]{0,60}$/', $key ) || ! is_array( $field ) ) {
				return new \WP_Error( 'cradle_field_key', sprintf( 'Invalid field key in section %s.', $id ) );
			}
			$type = $field['type'] ?? '';
			if ( ! in_array( $type, self::FIELD_TYPES, true ) ) {
				return new \WP_Error( 'cradle_field_type', sprintf( 'Unsupported type for %s.%s.', $id, $key ) );
			}
			if ( 'object' === $type && ( empty( $field['validate'] ) || ! is_callable( $field['validate'] ) ) ) {
				return new \WP_Error( 'cradle_field_object', 'Structured object fields need a trusted validate callback.' );
			}
			$field['label']    = (string) ( $field['label'] ?? $key );
			$field['help']     = (string) ( $field['help'] ?? '' );
			$field['exposure'] = wp_parse_args(
				(array) ( $field['exposure'] ?? array() ),
				array(
					'rest'        => false,
					'export'      => empty( $field['sensitive'] ),
					'diagnostics' => empty( $field['sensitive'] ),
				)
			);
			$fields[ $key ]    = $field;
		}
		$this->sections[ $id ] = array(
			'id'            => $id,
			'owner'         => (string) ( $section['owner'] ?? 'core' ),
			'title'         => (string) ( $section['title'] ?? $id ),
			'description'   => (string) ( $section['description'] ?? '' ),
			'capability'    => (string) ( $section['capability'] ?? 'cradle_manage_settings' ),
			'option'        => $option,
			'schemaVersion' => max( 1, (int) ( $section['schemaVersion'] ?? 1 ) ),
			'fields'        => $fields,
			'validate'      => isset( $section['validate'] ) && is_callable( $section['validate'] ) ? $section['validate'] : null,
			'migrate'       => isset( $section['migrate'] ) && is_callable( $section['migrate'] ) ? $section['migrate'] : null,
			'page'          => ! empty( $section['page'] ),
			'menu_title'    => (string) ( $section['menu_title'] ?? ( $section['title'] ?? $id ) ),
			// Admin menu that lists the page: the Cradle menu, or a menu the owning module adds (e.g. a post type's).
			'menu_parent'   => (string) ( $section['menu_parent'] ?? 'cradle' ),
			'autoload'      => array_key_exists( 'autoload', $section ) ? (bool) $section['autoload'] : true,
			'lifecycle'     => wp_parse_args( (array) ( $section['lifecycle'] ?? array() ), array( 'uninstall' => 'retain' ) ),
		);
		if ( did_action( 'admin_init' ) ) {
			$this->register_with_wordpress( $this->sections[ $id ] );
		}
		return true;
	}

	public function hooks() {
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_post_cradle_settings_reset', array( $this, 'handle_reset' ) );
		add_action( 'admin_post_cradle_settings_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_cradle_settings_import', array( $this, 'handle_import' ) );
	}

	public function admin_init() {
		foreach ( $this->sections as $section ) {
			$this->register_with_wordpress( $section );
			$this->maybe_migrate( $section );
		}
	}

	private function register_with_wordpress( array $section ) {
		$group = $this->group( $section['id'] );
		register_setting(
			$group,
			$section['option'],
			array(
				'type'              => 'array',
				'show_in_rest'      => false,
				'sanitize_callback' => function ( $input ) use ( $section ) {
					return $this->sanitize_submission( $section['id'], $input );
				},
			)
		);
		$cap = $section['capability'];
		add_filter(
			'option_page_capability_' . $group,
			static function () use ( $cap ) {
				return $cap;
			}
		);
	}

	/**
	 * Applies a section's migrate callback when the stored schema is older. Admin/CLI only.
	 */
	private function maybe_migrate( array $section ) {
		$stored = get_option( $section['option'], null );
		if ( ! is_array( $stored ) ) {
			return;
		}
		$from = (int) ( $stored['_schema'] ?? 1 );
		if ( $from >= $section['schemaVersion'] || ! $section['migrate'] ) {
			return;
		}
		$migrated = call_user_func( $section['migrate'], $stored, $from, $section['schemaVersion'] );
		if ( is_array( $migrated ) ) {
			$result = $this->validate_values( $section, $migrated, $this->defaults( $section['id'] ) );
			$this->write( $section, $result['values'], (int) ( $stored['_revision'] ?? 0 ) + 1 );
		}
	}

	public function group( string $section_id ): string {
		return 'cradle_' . str_replace( '-', '_', $section_id );
	}

	public function section( string $id ) {
		return $this->sections[ $id ] ?? null;
	}

	public function sections(): array {
		return $this->sections;
	}

	public function defaults( string $section_id ): array {
		$section = $this->section( $section_id );
		if ( ! $section ) {
			return array();
		}
		$out = array();
		foreach ( $section['fields'] as $key => $field ) {
			$out[ $key ] = $field['default'] ?? self::type_default( $field['type'] );
		}
		return $out;
	}

	/**
	 * Current values merged over defaults. Never writes.
	 */
	public function values( string $section_id ): array {
		$section = $this->section( $section_id );
		if ( ! $section ) {
			return array();
		}
		$stored = get_option( $section['option'], array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = $this->defaults( $section_id );
		foreach ( $out as $key => $default ) {
			if ( array_key_exists( $key, $stored ) ) {
				$out[ $key ] = $stored[ $key ];
			}
		}
		return $out;
	}

	public function get( string $section_id, string $field ) {
		$values = $this->values( $section_id );
		return array_key_exists( $field, $values ) ? $values[ $field ] : null;
	}

	public function revision( string $section_id ): int {
		$section = $this->section( $section_id );
		$stored  = $section ? get_option( $section['option'], array() ) : array();
		return is_array( $stored ) ? (int) ( $stored['_revision'] ?? 0 ) : 0;
	}

	/**
	 * Validates and saves values programmatically (imports, module code).
	 * Invalid fields are left unchanged and reported.
	 *
	 * @return array{saved:bool,errors:array<string,string>}|\WP_Error
	 */
	public function update( string $section_id, array $input, bool $check_capability = true ) {
		$section = $this->section( $section_id );
		if ( ! $section ) {
			return new \WP_Error( 'cradle_section_missing', 'Unknown section.' );
		}
		if ( $check_capability && ! current_user_can( $section['capability'] ) ) {
			return new \WP_Error( 'cradle_forbidden', __( 'You are not allowed to change these settings.', 'cradle' ) );
		}
		$result = $this->validate_values( $section, $input, $this->values( $section_id ) );
		$this->write( $section, $result['values'], $this->revision( $section_id ) + 1 );
		return array(
			'saved'  => true,
			'errors' => $result['errors'],
		);
	}

	public function reset( string $section_id ): bool {
		$section = $this->section( $section_id );
		return $section ? delete_option( $section['option'] ) : false;
	}

	/**
	 * Exportable (non-sensitive) values of a section.
	 */
	public function export( string $section_id ): array {
		$section = $this->section( $section_id );
		if ( ! $section ) {
			return array();
		}
		$out = array();
		foreach ( $this->values( $section_id ) as $key => $value ) {
			if ( ! empty( $section['fields'][ $key ]['exposure']['export'] ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	private function write( array $section, array $values, int $revision ) {
		$values['_revision'] = $revision;
		$values['_schema']   = $section['schemaVersion'];
		$this->bypass        = $section['option'];
		update_option( $section['option'], $values, $section['autoload'] );
		$this->bypass = null;
	}

	/**
	 * Settings API sanitize callback for options.php submissions.
	 *
	 * @param mixed $input Submitted value.
	 */
	public function sanitize_submission( string $section_id, $input ) {
		$section = $this->section( $section_id );
		if ( ! $section ) {
			return $input;
		}
		$option = $section['option'];
		if ( $this->bypass === $option ) {
			return $input;
		}
		if ( array_key_exists( $option, $this->sanitized ) && $this->sanitized[ $option ] === $input ) {
			return $input;
		}
		$stored = get_option( $option, array() );
		$stored = is_array( $stored ) ? $stored : array();

		if ( ! current_user_can( $section['capability'] ) ) {
			return $stored;
		}
		if ( ! is_array( $input ) ) {
			add_settings_error( $option, 'cradle-invalid', __( 'The submitted settings were malformed and were not saved.', 'cradle' ) );
			$this->sanitized[ $option ] = $stored;
			return $stored;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verified the group nonce.
		$is_form = isset( $_POST['option_page'] ) && $this->group( $section_id ) === $_POST['option_page'];
		if ( $is_form ) {
			$submitted = isset( $input['_revision'] ) ? (int) $input['_revision'] : -1;
			if ( $submitted !== (int) ( $stored['_revision'] ?? 0 ) ) {
				add_settings_error( $option, 'cradle-stale', __( 'These settings changed after you opened this page. Nothing was saved; review the current values and try again.', 'cradle' ) );
				$this->sanitized[ $option ] = $stored;
				return $stored;
			}
		}

		$current = $this->values( $section_id );
		$result  = $this->validate_values( $section, $input, $current );
		foreach ( $result['errors'] as $key => $message ) {
			add_settings_error( $option, 'cradle-field-' . $key, sprintf( '%1$s: %2$s', $section['fields'][ $key ]['label'] ?? $key, $message ) );
		}
		if ( $result['errors'] && $result['values'] !== $current ) {
			add_settings_error( $option, 'cradle-partial', __( 'Other valid changes were saved.', 'cradle' ), 'info' );
		}
		$out              = $result['values'];
		$out['_revision'] = (int) ( $stored['_revision'] ?? 0 ) + 1;
		$out['_schema']   = $section['schemaVersion'];

		$this->sanitized[ $option ] = $out;
		if ( function_exists( 'cradle_log_activity' ) ) {
			cradle_log_activity( $section['owner'], 'settings_saved', sprintf( 'Settings section "%s" saved.', $section_id ), array( 'fields' => array_keys( array_diff_assoc( array_map( 'maybe_serialize', $out ), array_map( 'maybe_serialize', $current ) ) ) ) );
		}
		return $out;
	}

	/**
	 * Validates submitted fields. Missing fields keep current values; invalid
	 * fields keep current values and produce an error message.
	 *
	 * @return array{values:array,errors:array<string,string>}
	 */
	public function validate_values( array $section, array $input, array $current ): array {
		$values = $current;
		$errors = array();
		foreach ( $section['fields'] as $key => $field ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$result = self::validate_field( $field, $input[ $key ], $input );
			if ( is_wp_error( $result ) ) {
				$errors[ $key ] = $result->get_error_message();
				continue;
			}
			$values[ $key ] = $result;
		}
		if ( $section['validate'] ) {
			$cross = call_user_func( $section['validate'], $values, $current );
			if ( is_array( $cross ) ) {
				foreach ( $cross as $key => $message ) {
					if ( isset( $section['fields'][ $key ] ) ) {
						$errors[ $key ] = (string) $message;
						$values[ $key ] = $current[ $key ];
					}
				}
			}
		}
		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Rejects invalid types before normalizing accepted values.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Raw value.
	 * @param array $all   All raw input (for trusted custom validators).
	 * @return mixed|\WP_Error
	 */
	public static function validate_field( array $field, $value, array $all = array() ) {
		switch ( $field['type'] ) {
			case 'boolean':
				if ( is_bool( $value ) ) {
					return $value;
				}
				if ( in_array( $value, array( '1', 1, 'true', 'on' ), true ) ) {
					return true;
				}
				if ( in_array( $value, array( '0', 0, 'false', '', 'off' ), true ) ) {
					return false;
				}
				return new \WP_Error( 'type', __( 'Must be on or off.', 'cradle' ) );

			case 'integer':
			case 'number':
				if ( is_string( $value ) ) {
					$value = trim( $value );
				}
				if ( ! is_numeric( $value ) || ( 'integer' === $field['type'] && (string) (int) $value !== (string) $value && ! is_int( $value ) ) ) {
					return new \WP_Error( 'type', 'integer' === $field['type'] ? __( 'Must be a whole number.', 'cradle' ) : __( 'Must be a number.', 'cradle' ) );
				}
				$num = 'integer' === $field['type'] ? (int) $value : (float) $value;
				if ( isset( $field['minimum'] ) && $num < $field['minimum'] ) {
					/* translators: %s: minimum value */
					return new \WP_Error( 'range', sprintf( __( 'Must be at least %s.', 'cradle' ), $field['minimum'] ) );
				}
				if ( isset( $field['maximum'] ) && $num > $field['maximum'] ) {
					/* translators: %s: maximum value */
					return new \WP_Error( 'range', sprintf( __( 'Must be at most %s.', 'cradle' ), $field['maximum'] ) );
				}
				return $num;

			case 'string':
				if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
					return new \WP_Error( 'type', __( 'Must be text.', 'cradle' ) );
				}
				$value = (string) $value;
				if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
					return new \WP_Error( 'utf8', __( 'Contains invalid characters.', 'cradle' ) );
				}
				$max = (int) ( $field['maxLength'] ?? 1000 );
				if ( mb_strlen( $value ) > $max ) {
					/* translators: %d: maximum length */
					return new \WP_Error( 'length', sprintf( __( 'Must be %d characters or fewer.', 'cradle' ), $max ) );
				}
				$value = ( 'textarea' === ( $field['control'] ?? '' ) ) ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
				if ( isset( $field['pattern'] ) && '' !== $value && ! preg_match( $field['pattern'], $value ) ) {
					return new \WP_Error( 'pattern', (string) ( $field['pattern_message'] ?? __( 'Has an invalid format.', 'cradle' ) ) );
				}
				return $value;

			case 'enum':
				$allowed = self::enum_values( $field );
				if ( ! is_scalar( $value ) || ! in_array( (string) $value, $allowed, true ) ) {
					return new \WP_Error( 'enum', __( 'Is not an allowed choice.', 'cradle' ) );
				}
				return (string) $value;

			case 'multi_enum':
				$allowed = self::enum_values( $field );
				$value   = '' === $value ? array() : $value;
				if ( ! is_array( $value ) ) {
					return new \WP_Error( 'type', __( 'Must be a list of choices.', 'cradle' ) );
				}
				$out = array();
				foreach ( $value as $item ) {
					if ( ! is_scalar( $item ) || ! in_array( (string) $item, $allowed, true ) ) {
						return new \WP_Error( 'enum', __( 'Contains a choice that is not allowed.', 'cradle' ) );
					}
					$out[] = (string) $item;
				}
				return array_values( array_unique( $out ) );

			case 'url':
				if ( ! is_string( $value ) ) {
					return new \WP_Error( 'type', __( 'Must be a URL.', 'cradle' ) );
				}
				$value = trim( $value );
				if ( '' === $value ) {
					return '';
				}
				$protocols = (array) ( $field['protocols'] ?? array( 'https', 'http' ) );
				$clean     = esc_url_raw( $value, $protocols );
				$scheme    = strtolower( (string) wp_parse_url( $clean, PHP_URL_SCHEME ) );
				$host      = (string) wp_parse_url( $clean, PHP_URL_HOST );
				if ( '' === $clean || ! in_array( $scheme, $protocols, true ) || ( in_array( $scheme, array( 'http', 'https' ), true ) && '' === $host ) ) {
					return new \WP_Error( 'url', __( 'Must be a valid URL using an allowed protocol.', 'cradle' ) );
				}
				return $clean;

			case 'email':
				if ( ! is_string( $value ) ) {
					return new \WP_Error( 'type', __( 'Must be an email address.', 'cradle' ) );
				}
				$value = trim( $value );
				if ( '' !== $value && ! is_email( $value ) ) {
					return new \WP_Error( 'email', __( 'Must be a valid email address.', 'cradle' ) );
				}
				return sanitize_email( $value );

			case 'attachment':
				if ( '' === $value || '0' === $value || 0 === $value ) {
					return 0;
				}
				if ( ! is_numeric( $value ) || (int) $value <= 0 ) {
					return new \WP_Error( 'type', __( 'Must reference a media item.', 'cradle' ) );
				}
				$id   = (int) $value;
				$post = get_post( $id );
				if ( ! $post || 'attachment' !== $post->post_type ) {
					return new \WP_Error( 'missing', __( 'The selected media item does not exist.', 'cradle' ) );
				}
				if ( ! empty( $field['mime'] ) ) {
					$mime = (string) get_post_mime_type( $post );
					$ok   = false;
					foreach ( (array) $field['mime'] as $prefix ) {
						$ok = $ok || str_starts_with( $mime, $prefix );
					}
					if ( ! $ok ) {
						return new \WP_Error( 'mime', __( 'The selected media type is not allowed here.', 'cradle' ) );
					}
				}
				return $id;

			case 'color':
				if ( ! is_string( $value ) ) {
					return new \WP_Error( 'type', __( 'Must be a color.', 'cradle' ) );
				}
				$value = trim( $value );
				if ( '' === $value && ! empty( $field['allow_empty'] ) ) {
					return '';
				}
				$hex = sanitize_hex_color( $value );
				if ( ! $hex ) {
					return new \WP_Error( 'color', __( 'Must be a hex color such as #1a2b3c.', 'cradle' ) );
				}
				return strtolower( $hex );

			case 'dimension':
				if ( ! is_array( $value ) || ! isset( $value['value'], $value['unit'] ) ) {
					return new \WP_Error( 'type', __( 'Must be a size with a unit.', 'cradle' ) );
				}
				$units = (array) ( $field['units'] ?? array( 'px', 'rem', 'em', '%', 'vh', 'vw' ) );
				if ( ! is_string( $value['unit'] ) || ! in_array( $value['unit'], $units, true ) ) {
					return new \WP_Error( 'unit', __( 'Uses a unit that is not allowed.', 'cradle' ) );
				}
				if ( ! is_numeric( $value['value'] ) ) {
					return new \WP_Error( 'type', __( 'Must be a number.', 'cradle' ) );
				}
				$num = (float) $value['value'];
				$min = $field['minimum'] ?? 0;
				$max = $field['maximum'] ?? 10000;
				if ( $num < $min || $num > $max ) {
					/* translators: 1: minimum, 2: maximum */
					return new \WP_Error( 'range', sprintf( __( 'Must be between %1$s and %2$s.', 'cradle' ), $min, $max ) );
				}
				return array(
					'value' => $num,
					'unit'  => $value['unit'],
				);

			case 'object':
				return call_user_func( $field['validate'], $value, $all );
		}
		return new \WP_Error( 'type', 'Unsupported field type.' );
	}

	public static function enum_values( array $field ): array {
		$enum = (array) ( $field['enum'] ?? array() );
		return array_map( 'strval', array_is_list( $enum ) ? $enum : array_keys( $enum ) );
	}

	public static function enum_labels( array $field ): array {
		$enum = (array) ( $field['enum'] ?? array() );
		return array_is_list( $enum ) ? array_combine( array_map( 'strval', $enum ), array_map( 'strval', $enum ) ) : array_map( 'strval', $enum );
	}

	public static function type_default( string $type ) {
		switch ( $type ) {
			case 'boolean':
				return false;
			case 'integer':
			case 'attachment':
				return 0;
			case 'number':
				return 0.0;
			case 'multi_enum':
			case 'object':
				return array();
			case 'dimension':
				return array(
					'value' => 0,
					'unit'  => 'px',
				);
		}
		return '';
	}

	/**
	 * Formats a dimension value for CSS output.
	 */
	public static function css_dimension( $value ): string {
		if ( ! is_array( $value ) || ! isset( $value['value'], $value['unit'] ) ) {
			return '';
		}
		return rtrim( rtrim( number_format( (float) $value['value'], 3, '.', '' ), '0' ), '.' ) . preg_replace( '/[^a-z%]/', '', (string) $value['unit'] );
	}

	/**
	 * Outputs the complete settings form for a section (Settings API + options.php).
	 */
	public function render_form( string $section_id ) {
		$section = $this->section( $section_id );
		if ( ! $section || ! current_user_can( $section['capability'] ) ) {
			return;
		}
		$option = $section['option'];
		$errors = array();
		foreach ( get_settings_errors( $option ) as $error ) {
			if ( 0 === strpos( $error['code'], 'cradle-field-' ) ) {
				$errors[ substr( $error['code'], 13 ) ] = $error['message'];
			}
		}
		// Custom pages outside Settings do not print notices automatically: show the core
		// "Settings saved." message (setting `general`) and this section's messages.
		settings_errors( 'general' );
		settings_errors( $option );
		$values = $this->values( $section_id );
		Field_Renderer::enqueue();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="cradle-settings-form" novalidate>
			<?php settings_fields( $this->group( $section_id ) ); ?>
			<input type="hidden" name="<?php echo esc_attr( $option ); ?>[_revision]" value="<?php echo esc_attr( (string) $this->revision( $section_id ) ); ?>" />
			<?php if ( $section['description'] ) : ?>
				<p class="description"><?php echo esc_html( $section['description'] ); ?></p>
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tbody>
				<?php
				foreach ( $section['fields'] as $key => $field ) {
					Field_Renderer::row( $option, $key, $field, $values[ $key ], $errors[ $key ] ?? '' );
				}
				?>
				</tbody>
			</table>
			<?php submit_button( __( 'Save changes', 'cradle' ) ); ?>
		</form>
		<div class="cradle-settings-tools">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cradle-inline-form" data-cradle-confirm="<?php esc_attr_e( 'Reset every field in this section to its default value?', 'cradle' ); ?>">
				<input type="hidden" name="action" value="cradle_settings_reset" />
				<input type="hidden" name="section" value="<?php echo esc_attr( $section_id ); ?>" />
				<?php wp_nonce_field( 'cradle_settings_reset_' . $section_id ); ?>
				<?php submit_button( __( 'Reset to defaults', 'cradle' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cradle-inline-form">
				<input type="hidden" name="action" value="cradle_settings_export" />
				<input type="hidden" name="section" value="<?php echo esc_attr( $section_id ); ?>" />
				<?php wp_nonce_field( 'cradle_settings_export_' . $section_id ); ?>
				<?php submit_button( __( 'Export configuration', 'cradle' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="cradle-inline-form">
				<input type="hidden" name="action" value="cradle_settings_import" />
				<input type="hidden" name="section" value="<?php echo esc_attr( $section_id ); ?>" />
				<?php wp_nonce_field( 'cradle_settings_import_' . $section_id ); ?>
				<label for="cradle-import-<?php echo esc_attr( $section_id ); ?>" class="screen-reader-text"><?php esc_html_e( 'Configuration file', 'cradle' ); ?></label>
				<input type="file" id="cradle-import-<?php echo esc_attr( $section_id ); ?>" name="cradle_config" accept="application/json,.json" />
				<?php submit_button( __( 'Import configuration', 'cradle' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	private function request_section() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by caller with the section-specific action.
		$section_id = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';
		$section    = $this->section( $section_id );
		if ( ! $section ) {
			wp_die( esc_html__( 'Unknown settings section.', 'cradle' ), 400 );
		}
		return $section;
	}

	public function handle_reset() {
		$section = $this->request_section();
		check_admin_referer( 'cradle_settings_reset_' . $section['id'] );
		if ( ! current_user_can( $section['capability'] ) ) {
			wp_die( esc_html__( 'You are not allowed to reset these settings.', 'cradle' ), 403 );
		}
		$this->reset( $section['id'] );
		cradle_log_activity( $section['owner'], 'settings_reset', sprintf( 'Settings section "%s" reset to defaults.', $section['id'] ) );
		add_settings_error( $section['option'], 'cradle-reset', __( 'Settings were reset to their defaults.', 'cradle' ), 'success' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', wp_get_referer() ?: admin_url( 'admin.php?page=cradle' ) ) );
		exit;
	}

	public function handle_export() {
		$section = $this->request_section();
		check_admin_referer( 'cradle_settings_export_' . $section['id'] );
		if ( ! current_user_can( $section['capability'] ) ) {
			wp_die( esc_html__( 'You are not allowed to export these settings.', 'cradle' ), 403 );
		}
		$payload = array(
			'format'        => 'cradle-settings',
			'formatVersion' => 1,
			'section'       => $section['id'],
			'schemaVersion' => $section['schemaVersion'],
			'exported'      => gmdate( 'c' ),
			'values'        => $this->export( $section['id'] ),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="cradle-' . $section['id'] . '-settings.json"' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	public function handle_import() {
		$section = $this->request_section();
		check_admin_referer( 'cradle_settings_import_' . $section['id'] );
		if ( ! current_user_can( $section['capability'] ) ) {
			wp_die( esc_html__( 'You are not allowed to import these settings.', 'cradle' ), 403 );
		}
		$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=cradle' );
		$payload  = self::read_uploaded_json( 'cradle_config', 262144 );
		if ( is_wp_error( $payload ) ) {
			add_settings_error( $section['option'], 'cradle-import', $payload->get_error_message() );
		} elseif ( ( $payload['format'] ?? '' ) !== 'cradle-settings' || ( $payload['section'] ?? '' ) !== $section['id'] || ! is_array( $payload['values'] ?? null ) ) {
			add_settings_error( $section['option'], 'cradle-import', __( 'This file is not a configuration export for this section.', 'cradle' ) );
		} else {
			$importable = array();
			foreach ( $payload['values'] as $key => $value ) {
				if ( ! empty( $section['fields'][ $key ]['exposure']['export'] ) ) {
					$importable[ $key ] = $value;
				}
			}
			$result = $this->update( $section['id'], $importable );
			foreach ( $result['errors'] as $key => $message ) {
				add_settings_error( $section['option'], 'cradle-field-' . $key, sprintf( '%1$s: %2$s', $section['fields'][ $key ]['label'], $message ) );
			}
			add_settings_error( $section['option'], 'cradle-imported', __( 'Configuration imported. Invalid fields kept their previous values.', 'cradle' ), $result['errors'] ? 'warning' : 'success' );
			cradle_log_activity( $section['owner'], 'settings_imported', sprintf( 'Settings section "%s" imported.', $section['id'] ) );
		}
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', $redirect ) );
		exit;
	}

	/**
	 * Reads a bounded uploaded JSON file without trusting its declared type.
	 *
	 * @return array|\WP_Error
	 */
	public static function read_uploaded_json( string $field, int $max_bytes, int $max_depth = 32 ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify nonces.
		$file = $_FILES[ $field ] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'cradle_upload', __( 'No file was uploaded.', 'cradle' ) );
		}
		if ( (int) $file['size'] > $max_bytes || filesize( $file['tmp_name'] ) > $max_bytes ) {
			return new \WP_Error( 'cradle_upload_size', __( 'The file is too large.', 'cradle' ) );
		}
		$raw = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw || ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			return new \WP_Error( 'cradle_upload_encoding', __( 'The file is not valid UTF-8.', 'cradle' ) );
		}
		$data = json_decode( $raw, true, $max_depth );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'cradle_upload_json', __( 'The file is not valid JSON.', 'cradle' ) );
		}
		return $data;
	}
}
