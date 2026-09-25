<?php
/**
 * Component registry, descriptor validation and builder-neutral rendering.
 *
 * Components are registered from trusted package code only. Render callbacks
 * receive normalized properties and a plain context array; they never need a
 * WP_Block instance. Child themes may override presentation only through the
 * allowlisted `cradle/components/<slug>/render.php` path when the override
 * declares the same render contract.
 *
 * @package Cradle
 */

namespace Cradle\Core;

defined( 'ABSPATH' ) || exit;

final class Components {

	const DESCRIPTOR_SCHEMA = 1;
	const MAX_DEPTH         = 8;
	const PROPERTY_TYPES    = array( 'string', 'integer', 'number', 'boolean', 'array' );
	const STRING_FORMATS    = array( 'plain', 'limited-rich-text', 'url', 'slug', 'color', 'multiline' );
	const INTEGER_FORMATS   = array( 'attachment', 'post', 'term', 'user' );
	const FALLBACK_POLICIES = array( 'saved-semantic-html', 'static-snapshot', 'none' );

	/** @var array<string,array> */
	private $components = array();

	/** @var array<string,array> */
	private $handlers = array();

	/** @var array<string,Editor_Adapter> */
	private $adapters = array();

	/** @var string[] Render stack for recursion and cycle protection. */
	private $stack = array();

	/** @var array<string,int> */
	private $instances = array();

	/** @var array<string,string> Override problems keyed by component ID. */
	private $override_problems = array();

	/** @var bool True once components were handed to adapters on `init`. */
	private $adapters_registered = false;

	public function hooks() {
		add_action( 'init', array( $this, 'register_with_adapters' ), 20 );
	}

	/**
	 * Registers a component from a trusted descriptor array.
	 *
	 * @param array $descriptor Component descriptor (see docs/architecture/components.md).
	 * @param array $handlers   Trusted callables: `render` (required), optional `prepare`.
	 * @return true|\WP_Error
	 */
	public function register( array $descriptor, array $handlers ) {
		$normalized = self::validate_descriptor( $descriptor );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		if ( isset( $this->components[ $normalized['id'] ] ) ) {
			return new \WP_Error( 'cradle_component_duplicate', 'Component already registered: ' . $normalized['id'] );
		}
		if ( empty( $handlers['render'] ) || ! is_callable( $handlers['render'] ) ) {
			return new \WP_Error( 'cradle_component_render', 'A trusted render callable is required.' );
		}
		$this->components[ $normalized['id'] ] = $normalized;
		$this->handlers[ $normalized['id'] ]   = $handlers;
		if ( $this->adapters_registered ) {
			foreach ( $this->adapters as $adapter ) {
				if ( $adapter->is_available() ) {
					$adapter->register_component( $normalized );
				}
			}
		}
		return true;
	}

	/**
	 * Registers a component from a JSON descriptor shipped inside a Cradle plugin or the active theme
	 * (a website's own components live in its child theme under components/<slug>/).
	 *
	 * @return true|\WP_Error
	 */
	public function register_from_file( string $path, array $handlers ) {
		$real  = realpath( $path );
		$roots = array_filter( array_map( 'realpath', array( WP_PLUGIN_DIR, get_stylesheet_directory(), get_template_directory() ) ) );
		$ok    = false;
		foreach ( $roots as $root ) {
			if ( $real && 0 === strpos( wp_normalize_path( $real ), wp_normalize_path( $root ) . '/' ) ) {
				$ok = true;
				break;
			}
		}
		if ( ! $ok || '.json' !== substr( $real, -5 ) ) {
			return new \WP_Error( 'cradle_component_path', 'Component descriptors must be JSON files inside a plugin package or the active theme.' );
		}
		$data = json_decode( (string) file_get_contents( $real ), true, 32 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'cradle_component_json', 'Invalid component JSON: ' . basename( $real ) );
		}
		return $this->register( $data, $handlers );
	}

	public function register_adapter( Editor_Adapter $adapter ) {
		$this->adapters[ $adapter->id() ] = $adapter;
		if ( $this->adapters_registered && $adapter->is_available() ) {
			foreach ( $this->components as $component ) {
				$adapter->register_component( $component );
			}
		}
	}

	public function adapters(): array {
		return $this->adapters;
	}

	public function adapter( string $id ) {
		return $this->adapters[ $id ] ?? null;
	}

	public function register_with_adapters() {
		$this->adapters_registered = true;
		foreach ( $this->adapters as $adapter ) {
			if ( ! $adapter->is_available() ) {
				continue;
			}
			foreach ( $this->components as $component ) {
				$adapter->register_component( $component );
			}
		}
	}

	public function get( string $id ) {
		return $this->components[ $id ] ?? null;
	}

	/**
	 * Trusted handlers registered with a component (render, prepare, blockDir, ...).
	 */
	public function handlers( string $id ): array {
		return $this->handlers[ $id ] ?? array();
	}

	public function all(): array {
		return $this->components;
	}

	public function override_problems(): array {
		return $this->override_problems;
	}

	/**
	 * @return array|\WP_Error
	 */
	public static function validate_descriptor( array $d ) {
		$errors = array();
		if ( (int) ( $d['schemaVersion'] ?? 0 ) !== self::DESCRIPTOR_SCHEMA ) {
			$errors[] = 'schemaVersion must be 1.';
		}
		$id = $d['id'] ?? '';
		if ( ! is_string( $id ) || ! preg_match( '#^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$#', $id ) ) {
			$errors[] = 'id must be namespace/name in lowercase.';
		}
		if ( ! Version_Range::is_version( $d['version'] ?? null ) ) {
			$errors[] = 'version must be semantic.';
		}
		if ( empty( $d['owner'] ) || ! is_string( $d['owner'] ) ) {
			$errors[] = 'owner is required.';
		}
		if ( empty( $d['renderContract'] ) || ! is_string( $d['renderContract'] ) || ! preg_match( '/^[a-z0-9-]+-v\d+$/', $d['renderContract'] ) ) {
			$errors[] = 'renderContract must look like name-v1.';
		}
		$properties = array();
		foreach ( (array) ( $d['properties'] ?? array() ) as $name => $prop ) {
			$prop_error = self::validate_property( $name, $prop );
			if ( $prop_error ) {
				$errors[] = $prop_error;
				continue;
			}
			$properties[ $name ] = $prop;
		}
		$slots = array();
		foreach ( (array) ( $d['slots'] ?? array() ) as $slot ) {
			if ( ! is_array( $slot ) || empty( $slot['name'] ) || ! preg_match( '/^[a-z][a-zA-Z0-9]*$/', (string) $slot['name'] ) ) {
				$errors[] = 'Each slot needs a camelCase name.';
				continue;
			}
			$slots[] = array(
				'name'        => $slot['name'],
				'description' => (string) ( $slot['description'] ?? '' ),
				'allowed'     => array_values( array_filter( (array) ( $slot['allowed'] ?? array() ), 'is_string' ) ),
			);
		}
		$override = $d['themeOverride'] ?? '';
		if ( '' !== $override && ( ! is_string( $override ) || ! preg_match( '#^cradle/components/[a-z0-9-]+/render\.php$#', $override ) ) ) {
			$errors[] = 'themeOverride must be cradle/components/<slug>/render.php.';
		}
		$fallback = $d['fallbackPolicy'] ?? 'none';
		if ( ! in_array( $fallback, self::FALLBACK_POLICIES, true ) ) {
			$errors[] = 'fallbackPolicy is not supported.';
		}
		$assets = (array) ( $d['assets'] ?? array() );
		foreach ( array( 'styleHandles', 'viewScriptHandles', 'editorStyleHandles' ) as $asset_key ) {
			foreach ( (array) ( $assets[ $asset_key ] ?? array() ) as $handle ) {
				if ( ! is_string( $handle ) || ! preg_match( '/^[a-z0-9-]+$/', $handle ) ) {
					$errors[] = 'Asset handles must be lowercase slugs.';
				}
			}
		}
		$cache = $d['cache'] ?? 'none';
		if ( ! in_array( $cache, array( 'none', 'public' ), true ) ) {
			$errors[] = 'cache must be none or public.';
		}
		if ( $errors ) {
			return new \WP_Error( 'cradle_component_invalid', implode( ' ', $errors ) );
		}
		return array(
			'schemaVersion'  => self::DESCRIPTOR_SCHEMA,
			'id'             => $id,
			'version'        => $d['version'],
			'owner'          => $d['owner'],
			'title'          => (string) ( $d['title'] ?? $id ),
			'description'    => (string) ( $d['description'] ?? '' ),
			'category'       => (string) ( $d['category'] ?? 'content' ),
			'properties'     => $properties,
			'slots'          => $slots,
			'adapters'       => (array) ( $d['adapters'] ?? array() ),
			'assets'         => array(
				'styleHandles'       => array_values( (array) ( $assets['styleHandles'] ?? array() ) ),
				'viewScriptHandles'  => array_values( (array) ( $assets['viewScriptHandles'] ?? array() ) ),
				'editorStyleHandles' => array_values( (array) ( $assets['editorStyleHandles'] ?? array() ) ),
			),
			'renderContract' => $d['renderContract'],
			'themeOverride'  => $override,
			'fallbackPolicy' => $fallback,
			'cache'          => $cache,
		);
	}

	private static function validate_property( $name, $prop ) {
		if ( ! is_string( $name ) || ! preg_match( '/^[a-z][a-zA-Z0-9]{0,40}$/', $name ) ) {
			return 'Property names must be camelCase.';
		}
		if ( ! is_array( $prop ) || ! in_array( $prop['type'] ?? '', self::PROPERTY_TYPES, true ) ) {
			return sprintf( 'Property %s has an unsupported type.', $name );
		}
		if ( 'string' === $prop['type'] && isset( $prop['format'] ) && ! in_array( $prop['format'], self::STRING_FORMATS, true ) ) {
			return sprintf( 'Property %s has an unsupported string format.', $name );
		}
		if ( 'integer' === $prop['type'] && isset( $prop['format'] ) && ! in_array( $prop['format'], self::INTEGER_FORMATS, true ) ) {
			return sprintf( 'Property %s has an unsupported integer format.', $name );
		}
		if ( isset( $prop['enum'] ) && ( ! is_array( $prop['enum'] ) || ! array_is_list( $prop['enum'] ) ) ) {
			return sprintf( 'Property %s enum must be a list.', $name );
		}
		if ( 'array' === $prop['type'] ) {
			$items = $prop['items'] ?? null;
			if ( ! is_array( $items ) || ! in_array( $items['type'] ?? '', array( 'string', 'integer', 'number', 'boolean', 'object' ), true ) ) {
				return sprintf( 'Property %s needs an items type.', $name );
			}
			if ( 'object' === $items['type'] ) {
				foreach ( (array) ( $items['properties'] ?? array() ) as $child => $child_prop ) {
					if ( is_array( $child_prop ) && 'array' === ( $child_prop['type'] ?? '' ) ) {
						return sprintf( 'Property %s nests arrays too deeply.', $name );
					}
					$child_error = self::validate_property( $child, $child_prop );
					if ( $child_error ) {
						return $child_error;
					}
				}
			}
		}
		return null;
	}

	/**
	 * Normalizes raw properties against a component schema. Unknown keys are dropped,
	 * invalid values fall back to defaults, and entity references are checked.
	 */
	public function normalize( string $id, array $raw ): array {
		$component = $this->get( $id );
		if ( ! $component ) {
			return array();
		}
		$out = array();
		foreach ( $component['properties'] as $name => $prop ) {
			$out[ $name ] = self::normalize_value( $prop, array_key_exists( $name, $raw ) ? $raw[ $name ] : null );
		}
		return $out;
	}

	public static function normalize_value( array $prop, $value ) {
		$default = $prop['default'] ?? self::type_default( $prop['type'] );
		if ( null === $value ) {
			return $default;
		}
		switch ( $prop['type'] ) {
			case 'boolean':
				return is_bool( $value ) ? $value : ( in_array( $value, array( 1, '1', 'true' ), true ) ? true : ( in_array( $value, array( 0, '0', 'false', '' ), true ) ? false : $default ) );

			case 'integer':
				if ( ! is_numeric( $value ) ) {
					return $default;
				}
				$value = (int) $value;
				if ( ( isset( $prop['minimum'] ) && $value < $prop['minimum'] ) || ( isset( $prop['maximum'] ) && $value > $prop['maximum'] ) ) {
					return $default;
				}
				if ( isset( $prop['format'] ) && $value > 0 && ! self::entity_exists( $prop['format'], $value ) ) {
					return 0;
				}
				return $value;

			case 'number':
				if ( ! is_numeric( $value ) ) {
					return $default;
				}
				$value = (float) $value;
				if ( ( isset( $prop['minimum'] ) && $value < $prop['minimum'] ) || ( isset( $prop['maximum'] ) && $value > $prop['maximum'] ) ) {
					return $default;
				}
				return $value;

			case 'string':
				if ( ! is_scalar( $value ) ) {
					return $default;
				}
				$value = (string) $value;
				if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
					return $default;
				}
				$max = (int) ( $prop['maxLength'] ?? 5000 );
				if ( mb_strlen( $value ) > $max ) {
					$value = mb_substr( $value, 0, $max );
				}
				if ( isset( $prop['enum'] ) ) {
					return in_array( $value, array_map( 'strval', $prop['enum'] ), true ) ? $value : $default;
				}
				$format = $prop['format'] ?? 'plain';
				if ( 'limited-rich-text' !== $format ) {
					// Block attributes saved by users without unfiltered_html pass through kses,
					// which entity-encodes them ("&" → "&amp;"). Plain values are stored decoded
					// and escaped once at output.
					$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
				switch ( $format ) {
					case 'limited-rich-text':
						return wp_kses( $value, self::limited_rich_text_tags() );
					case 'url':
						return esc_url_raw( $value, array( 'http', 'https', 'mailto', 'tel' ) );
					case 'slug':
						return sanitize_title( $value );
					case 'color':
						return preg_match( '/^(#[0-9a-fA-F]{3,8}|[a-z0-9-]{1,60})$/', $value ) ? $value : $default;
					case 'multiline':
						return sanitize_textarea_field( $value );
				}
				return sanitize_text_field( $value );

			case 'array':
				if ( ! is_array( $value ) ) {
					return $default;
				}
				$max   = (int) ( $prop['maxItems'] ?? 50 );
				$items = array_slice( array_values( $value ), 0, $max );
				$out   = array();
				foreach ( $items as $item ) {
					if ( 'object' === $prop['items']['type'] ) {
						if ( ! is_array( $item ) ) {
							continue;
						}
						$row = array();
						foreach ( (array) ( $prop['items']['properties'] ?? array() ) as $child => $child_prop ) {
							$row[ $child ] = self::normalize_value( $child_prop, $item[ $child ] ?? null );
						}
						$out[] = $row;
					} else {
						$normalized = self::normalize_value( $prop['items'], $item );
						if ( null !== $normalized ) {
							$out[] = $normalized;
						}
					}
				}
				return $out;
		}
		return $default;
	}

	private static function entity_exists( string $format, int $id ): bool {
		switch ( $format ) {
			case 'attachment':
				$post = get_post( $id );
				return $post && 'attachment' === $post->post_type;
			case 'post':
				return (bool) get_post( $id );
			case 'term':
				return (bool) get_term( $id );
			case 'user':
				return (bool) get_userdata( $id );
		}
		return false;
	}

	public static function limited_rich_text_tags(): array {
		return array(
			'a'      => array(
				'href'   => true,
				'title'  => true,
				'rel'    => true,
				'target' => true,
			),
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'br'     => array(),
			'code'   => array(),
			'mark'   => array( 'class' => true ),
			'span'   => array( 'class' => true ),
			'sup'    => array(),
			'sub'    => array(),
			's'      => array(),
		);
	}

	private static function type_default( string $type ) {
		return array(
			'string'  => '',
			'integer' => 0,
			'number'  => 0,
			'boolean' => false,
			'array'   => array(),
		)[ $type ] ?? null;
	}

	/**
	 * Renders a component through override or registered render callable.
	 *
	 * @param string $id      Component ID.
	 * @param array  $raw     Raw properties.
	 * @param array  $context Plain context: adapter, post_id, inner_html, entity_key.
	 */
	public function render( string $id, array $raw, array $context = array() ): string {
		$component = $this->get( $id );
		if ( ! $component ) {
			return '';
		}
		$guard_key = $id . '|' . ( $context['entity_key'] ?? '' );
		if ( count( $this->stack ) >= self::MAX_DEPTH || ( '' !== ( $context['entity_key'] ?? '' ) && in_array( $guard_key, $this->stack, true ) ) ) {
			return '';
		}
		$this->stack[] = $guard_key;

		try {
			$props = $this->normalize( $id, $raw );
			if ( isset( $this->handlers[ $id ]['prepare'] ) && is_callable( $this->handlers[ $id ]['prepare'] ) ) {
				$props = (array) call_user_func( $this->handlers[ $id ]['prepare'], $props, $context );
			}
			$this->instances[ $id ] = ( $this->instances[ $id ] ?? 0 ) + 1;
			$context               += array(
				'adapter'    => 'php',
				'post_id'    => (int) get_the_ID(),
				'inner_html' => '',
			);
			$slug                   = str_replace( '/', '-', $id );
			$context['instance_id'] = ( str_starts_with( $slug, 'cradle-' ) ? '' : 'cradle-' ) . $slug . '-' . $this->instances[ $id ];

			foreach ( $component['assets']['styleHandles'] as $handle ) {
				if ( wp_style_is( $handle, 'registered' ) ) {
					wp_enqueue_style( $handle );
				}
			}
			foreach ( $component['assets']['viewScriptHandles'] as $handle ) {
				if ( wp_script_is( $handle, 'registered' ) ) {
					wp_enqueue_script( $handle );
				}
			}

			$override = $this->resolve_override( $component );
			if ( $override ) {
				$html = self::include_override( $override, $props, $context, $component );
			} else {
				$html = (string) call_user_func( $this->handlers[ $id ]['render'], $props, $context, $component );
			}

			/**
			 * Filters rendered component HTML. Returned markup must remain escaped.
			 */
			return (string) apply_filters( 'cradle/component_html', $html, $id, $props, $context );
		} finally {
			array_pop( $this->stack );
		}
	}

	/**
	 * Returns an allowlisted child/parent override path whose declared contract matches.
	 */
	public function resolve_override( array $component ) {
		if ( '' === $component['themeOverride'] ) {
			return null;
		}
		$roots = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );
		foreach ( $roots as $root ) {
			$path = wp_normalize_path( $root . '/' . $component['themeOverride'] );
			if ( ! is_file( $path ) ) {
				continue;
			}
			$real = wp_normalize_path( (string) realpath( $path ) );
			if ( 0 !== strpos( $real, wp_normalize_path( (string) realpath( $root ) ) . '/' ) ) {
				continue;
			}
			$declared = get_file_data( $real, array( 'contract' => 'Render contract' ) )['contract'];
			if ( $declared !== $component['renderContract'] ) {
				$this->override_problems[ $component['id'] ] = sprintf( 'Override %1$s declares contract "%2$s" but the component provides "%3$s"; the default renderer was used.', $real, $declared, $component['renderContract'] );
				continue;
			}
			return $real;
		}
		return null;
	}

	private static function include_override( string $file, array $props, array $context, array $component ): string {
		ob_start();
		( static function () use ( $file, $props, $context, $component ) {
			include $file;
		} )();
		return (string) ob_get_clean();
	}

	/**
	 * Scans stored usages across available adapters. Admin-triggered only.
	 */
	public function find_usages( string $id ): array {
		$out = array();
		foreach ( $this->adapters as $adapter_id => $adapter ) {
			if ( $adapter->is_available() ) {
				$out[ $adapter_id ] = $adapter->find_component_usages( $id );
			}
		}
		return $out;
	}
}
