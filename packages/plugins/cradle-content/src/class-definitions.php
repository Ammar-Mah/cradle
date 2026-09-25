<?php
/**
 * Storage and ownership of content definitions.
 *
 * Two sources exist and each key has exactly one authoritative owner:
 *
 * - Database definitions: one autoloaded option (`cradle_content_definitions`),
 *   edited through Cradle → Content types. Autoloaded deliberately because it is
 *   read on every request during `init` to register types; it is bounded to
 *   50 definitions per kind and 300 fields in total (typically a few KB).
 * - Code definitions: supplied by another Cradle plugin through the
 *   `cradle_content/code_definitions` filter. They are read-only in admin and win
 *   over a database copy with the same key; such a copy is shown as "shadowed"
 *   and is never registered while the code definition exists.
 *
 * Writes use a read-modify-write under a core lock so concurrent admin saves
 * cannot silently drop each other's changes, plus per-definition revisions for
 * stale-edit detection.
 *
 * @package Cradle\Content
 */

namespace Cradle\Content;

defined( 'ABSPATH' ) || exit;

final class Definitions {

	const OPTION       = 'cradle_content_definitions';
	const SCHEMA       = 1;
	const KINDS        = array( 'post_type', 'taxonomy', 'field_group' );
	const MAX_PER_KIND = 50;
	const LOCK         = 'content:definitions';
	const CODE_FILTER  = 'cradle_content/code_definitions';

	/** @var Definitions|null */
	private static $instance = null;

	/** @var array|null Memo of the stored option. */
	private $store = null;

	/** @var array|null Validated code definitions, kind => key => definition. */
	private $code = null;

	/** @var array<string,array> Validation errors of rejected code definitions keyed "kind:key". */
	private $code_errors = array();

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function empty_store(): array {
		return array(
			'schema'      => self::SCHEMA,
			'post_type'   => array(),
			'taxonomy'    => array(),
			'field_group' => array(),
		);
	}

	public static function kind_label( string $kind ): string {
		switch ( $kind ) {
			case 'post_type':
				return __( 'Post type', 'cradle-content' );
			case 'taxonomy':
				return __( 'Taxonomy', 'cradle-content' );
			case 'field_group':
				return __( 'Field group', 'cradle-content' );
		}
		return $kind;
	}

	/**
	 * Clears per-request memos (after external writes, in tests, or when code definitions change).
	 */
	public function reset_cache() {
		$this->store       = null;
		$this->code        = null;
		$this->code_errors = array();
	}

	/**
	 * The stored option, shape-normalized. Reading never writes.
	 */
	public function store( bool $fresh = false ): array {
		if ( $fresh ) {
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$this->store = null;
		}
		if ( null === $this->store ) {
			$raw   = get_option( self::OPTION, array() );
			$store = self::empty_store();
			if ( is_array( $raw ) ) {
				foreach ( self::KINDS as $kind ) {
					if ( isset( $raw[ $kind ] ) && is_array( $raw[ $kind ] ) ) {
						foreach ( $raw[ $kind ] as $key => $def ) {
							if ( is_string( $key ) && is_array( $def ) ) {
								$store[ $kind ][ $key ] = $def + Validator::defaults( $kind ) + array(
									'revision' => 0,
									'modified' => '',
								);
							}
						}
					}
				}
			}
			$this->store = $store;
		}
		return $this->store;
	}

	/**
	 * Database definitions of one kind (enabled or not).
	 */
	public function db( string $kind ): array {
		return $this->store()[ $kind ] ?? array();
	}

	/**
	 * Validated code definitions of one kind.
	 */
	public function code( string $kind ): array {
		if ( null === $this->code ) {
			$this->load_code();
		}
		return $this->code[ $kind ] ?? array();
	}

	public function code_errors(): array {
		if ( null === $this->code ) {
			$this->load_code();
		}
		return $this->code_errors;
	}

	private function load_code() {
		$this->code        = array_fill_keys( self::KINDS, array() );
		$this->code_errors = array();
		/**
		 * Supplies code-managed content definitions (versioned in the plugin that ships them).
		 *
		 * Return array( 'post_type' => array( key => definition ), 'taxonomy' => ..., 'field_group' => ... ).
		 * Definitions use the same schema as exports; they are validated and shown read-only in admin.
		 * Add the filter before `init` (for example at plugin load time).
		 *
		 * @param array $definitions Definitions by kind.
		 */
		$raw = apply_filters( self::CODE_FILTER, array() );
		if ( ! is_array( $raw ) ) {
			return;
		}
		foreach ( self::KINDS as $kind ) {
			$count = 0;
			foreach ( (array) ( $raw[ $kind ] ?? array() ) as $key => $def ) {
				if ( ! is_array( $def ) ) {
					continue;
				}
				if ( is_string( $key ) && ! isset( $def['key'] ) ) {
					$def['key'] = $key;
				}
				$id     = $kind . ':' . ( is_string( $def['key'] ?? null ) ? $def['key'] : (string) $key );
				$result = Validator::validate( $kind, $def, array( 'strict' => false ) );
				if ( $result['errors'] ) {
					$this->code_errors[ $id ] = $result['errors'];
					continue;
				}
				if ( ++$count > self::MAX_PER_KIND ) {
					$this->code_errors[ $id ] = array( 'key' => __( 'Too many code definitions of this kind.', 'cradle-content' ) );
					continue;
				}
				$definition             = $result['definition'];
				$owner                  = is_string( $def['owner'] ?? null ) ? sanitize_text_field( substr( $def['owner'], 0, 60 ) ) : '';
				$definition['owner']    = '' !== $owner ? $owner : 'code';
				$definition['revision'] = 0;
				$definition['modified'] = '';
				$this->code[ $kind ][ $definition['key'] ] = $definition;
			}
		}
	}

	/**
	 * Authoritative definitions: code definitions plus database definitions not shadowed by code.
	 * Each carries `_source` (db|code).
	 */
	public function effective( string $kind ): array {
		$out = array();
		foreach ( $this->code( $kind ) as $key => $def ) {
			$out[ $key ] = $def + array( '_source' => 'code' );
		}
		foreach ( $this->db( $kind ) as $key => $def ) {
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $def + array( '_source' => 'db' );
			}
		}
		return $out;
	}

	/**
	 * @return array{definition:array,source:string,shadowed:bool}|null
	 */
	public function find( string $kind, string $key ) {
		$code = $this->code( $kind );
		$db   = $this->db( $kind );
		if ( isset( $code[ $key ] ) ) {
			return array(
				'definition' => $code[ $key ],
				'source'     => 'code',
				'shadowed'   => isset( $db[ $key ] ),
			);
		}
		if ( isset( $db[ $key ] ) ) {
			return array(
				'definition' => $db[ $key ],
				'source'     => 'db',
				'shadowed'   => false,
			);
		}
		return null;
	}

	public function is_code_owned( string $kind, string $key ): bool {
		return isset( $this->code( $kind )[ $key ] );
	}

	/**
	 * Validation context for editing one definition (strict environment checks).
	 *
	 * @param string $kind     Kind being edited.
	 * @param string $original Key being edited ('' for new).
	 * @param array  $extra    Additional definitions to consider (for imports), kind => key => def.
	 */
	public function context( string $kind, string $original = '', array $extra = array() ): array {
		$defs = array();
		foreach ( self::KINDS as $k ) {
			$defs[ $k ] = array_merge( $this->db( $k ), $this->code( $k ), $extra[ $k ] ?? array() );
		}
		if ( '' !== $original ) {
			unset( $defs[ $kind ][ $original ] );
		}
		$registry = Registry::instance();
		return array(
			'strict'        => true,
			'original'      => $original,
			'definitions'   => $defs,
			'foreign'       => array(
				'post_type' => $registry->foreign_keys( 'post_type' ),
				'taxonomy'  => $registry->foreign_keys( 'taxonomy' ),
			),
			'foreign_slugs' => $registry->foreign_slugs(),
		);
	}

	/**
	 * Runs a read-modify-write of the stored option under the definitions lock.
	 *
	 * @param callable $mutator fn( array $store ): array|\WP_Error.
	 * @return true|\WP_Error
	 */
	public function write( callable $mutator ) {
		$core   = function_exists( 'cradle' ) ? cradle() : null;
		$handle = $core ? $core->locks()->acquire( self::LOCK, 30 ) : null;
		if ( $core && ! $handle ) {
			return new \WP_Error( 'cradle_content_busy', __( 'Another content definition change is being saved. Try again in a moment.', 'cradle-content' ) );
		}
		try {
			$store  = $this->store( true );
			$result = $mutator( $store );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			foreach ( self::KINDS as $kind ) {
				if ( count( $result[ $kind ] ?? array() ) > self::MAX_PER_KIND ) {
					/* translators: %d: maximum definitions */
					return new \WP_Error( 'cradle_content_limit', sprintf( __( 'At most %d definitions of each kind can be stored.', 'cradle-content' ), self::MAX_PER_KIND ) );
				}
			}
			$result['schema'] = self::SCHEMA;
			update_option( self::OPTION, $result, true );
			$this->store = null;
			return true;
		} finally {
			if ( $handle ) {
				$core->locks()->release( self::LOCK, $handle );
			}
		}
	}

	/**
	 * Strips runtime-only keys before storage or export.
	 */
	public static function clean( array $def ): array {
		unset( $def['_source'], $def['owner'] );
		return $def;
	}

	/**
	 * Validates and saves a database definition (create or update; the key cannot change here — use rename()).
	 *
	 * @param string   $kind     Definition kind.
	 * @param array    $input    Raw input.
	 * @param string   $original Existing key when editing, '' when creating.
	 * @param int|null $revision Revision the editor started from (stale-edit detection) or null.
	 * @param array    $options  allow_shadow (fork with the same key as a code definition), forked_from.
	 * @return array{definition:array,warnings:string[]}|\WP_Error Error data holds `errors` for field messages.
	 */
	public function save( string $kind, array $input, string $original = '', ?int $revision = null, array $options = array() ) {
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return new \WP_Error( 'cradle_content_kind', __( 'Unknown definition kind.', 'cradle-content' ) );
		}
		$key = is_string( $input['key'] ?? null ) ? strtolower( trim( $input['key'] ) ) : '';
		if ( '' === $original && $this->is_code_owned( $kind, $key ) && empty( $options['allow_shadow'] ) ) {
			return new \WP_Error(
				'cradle_content_code_owned',
				__( 'This key is managed in code by another plugin. Fork it to create an editable database copy.', 'cradle-content' ),
				array( 'errors' => array( 'key' => __( 'This key is managed in code.', 'cradle-content' ) ) )
			);
		}
		if ( '' !== $original && ! isset( $this->db( $kind )[ $original ] ) ) {
			return new \WP_Error( 'cradle_content_missing', $this->is_code_owned( $kind, $original ) ? __( 'Code-managed definitions are read-only. Fork the definition to edit a database copy.', 'cradle-content' ) : __( 'The definition no longer exists.', 'cradle-content' ) );
		}
		if ( '' !== $original && $key !== $original ) {
			return new \WP_Error( 'cradle_content_key_change', __( 'Keys are changed with "Rename key", which previews and migrates existing content.', 'cradle-content' ), array( 'errors' => array( 'key' => __( 'Use Rename key to change the key.', 'cradle-content' ) ) ) );
		}

		$extra = is_array( $options['extra'] ?? null ) ? $options['extra'] : array();
		$ctx   = $this->context( $kind, $original, $extra );
		if ( ! empty( $options['allow_shadow'] ) || isset( $extra[ $kind ][ $key ] ) ) {
			// Forks with the same key, and import items (present in $extra), must not collide with themselves.
			unset( $ctx['definitions'][ $kind ][ $key ] );
		}
		$result = Validator::validate( $kind, $input, $ctx );
		if ( $result['errors'] ) {
			return new \WP_Error(
				'cradle_content_invalid',
				__( 'The definition was not saved. Correct the highlighted fields.', 'cradle-content' ),
				array(
					'errors'   => $result['errors'],
					'warnings' => $result['warnings'],
				)
			);
		}
		$definition = $result['definition'];
		$key        = $definition['key'];

		$written = $this->write(
			function ( array $store ) use ( $kind, $key, $original, $revision, $options, &$definition ) {
				if ( '' !== $original ) {
					if ( ! isset( $store[ $kind ][ $original ] ) ) {
						return new \WP_Error( 'cradle_content_missing', __( 'The definition no longer exists.', 'cradle-content' ) );
					}
					$current = (int) ( $store[ $kind ][ $original ]['revision'] ?? 0 );
					if ( null !== $revision && $revision !== $current ) {
						return new \WP_Error( 'cradle_content_stale', __( 'This definition changed after you opened it. Nothing was saved; reload to see the current version.', 'cradle-content' ) );
					}
					$definition['revision']    = $current + 1;
					$definition['forked_from'] = (string) ( $store[ $kind ][ $original ]['forked_from'] ?? '' );
				} else {
					if ( isset( $store[ $kind ][ $key ] ) ) {
						return new \WP_Error( 'cradle_content_duplicate', __( 'A definition with this key already exists.', 'cradle-content' ), array( 'errors' => array( 'key' => __( 'A definition with this key already exists.', 'cradle-content' ) ) ) );
					}
					$definition['revision']    = 1;
					$definition['forked_from'] = (string) ( $options['forked_from'] ?? '' );
				}
				$definition['modified']  = gmdate( 'c' );
				$store[ $kind ][ $key ] = self::clean( $definition );
				return $store;
			}
		);
		if ( is_wp_error( $written ) ) {
			return $written;
		}
		Caps::sync( $kind, $key, $definition );
		cradle_log_activity( Module::ID, '' === $original ? 'definition_created' : 'definition_updated', sprintf( '%1$s "%2$s" %3$s.', $kind, $key, '' === $original ? 'created' : 'updated' ), array( 'kind' => $kind, 'definition' => $key ) );
		return array(
			'definition' => $definition,
			'warnings'   => $result['warnings'],
		);
	}

	/**
	 * Enables or disables a database definition. Disabling never deletes content.
	 *
	 * @return true|\WP_Error
	 */
	public function set_enabled( string $kind, string $key, bool $enabled ) {
		if ( $this->is_code_owned( $kind, $key ) && ! isset( $this->db( $kind )[ $key ] ) ) {
			return new \WP_Error( 'cradle_content_code_owned', __( 'Code-managed definitions are enabled or disabled in code.', 'cradle-content' ) );
		}
		$written = $this->write(
			static function ( array $store ) use ( $kind, $key, $enabled ) {
				if ( ! isset( $store[ $kind ][ $key ] ) ) {
					return new \WP_Error( 'cradle_content_missing', __( 'The definition no longer exists.', 'cradle-content' ) );
				}
				$store[ $kind ][ $key ]['enabled']  = $enabled;
				$store[ $kind ][ $key ]['revision'] = (int) $store[ $kind ][ $key ]['revision'] + 1;
				$store[ $kind ][ $key ]['modified'] = gmdate( 'c' );
				return $store;
			}
		);
		if ( true === $written ) {
			cradle_log_activity( Module::ID, $enabled ? 'definition_enabled' : 'definition_disabled', sprintf( '%1$s "%2$s" %3$s.', $kind, $key, $enabled ? 'enabled' : 'disabled (content retained)' ), array( 'kind' => $kind, 'definition' => $key ) );
		}
		return $written;
	}

	/**
	 * Deletes a database definition. Posts, terms and field values stay in the database.
	 *
	 * @return true|\WP_Error
	 */
	public function delete( string $kind, string $key ) {
		$written = $this->write(
			static function ( array $store ) use ( $kind, $key ) {
				if ( ! isset( $store[ $kind ][ $key ] ) ) {
					return new \WP_Error( 'cradle_content_missing', __( 'The definition no longer exists.', 'cradle-content' ) );
				}
				unset( $store[ $kind ][ $key ] );
				return $store;
			}
		);
		if ( true === $written ) {
			if ( ! $this->is_code_owned( $kind, $key ) ) {
				Caps::forget( $kind, $key );
			}
			cradle_log_activity( Module::ID, 'definition_deleted', sprintf( '%1$s "%2$s" definition deleted; stored content retained.', $kind, $key ), array( 'kind' => $kind, 'definition' => $key ) );
		}
		return $written;
	}

	/**
	 * Renames a database definition's key and every reference to it in other database definitions.
	 * Stored content is moved separately by a migration job (see Migrations).
	 *
	 * @param array $options keep_urls (bool): pin rewrite slug and REST base to the old key when they were derived from it.
	 * @return array|\WP_Error Updated definition.
	 */
	public function rename( string $kind, string $from, string $to, array $options = array() ) {
		$existing = $this->db( $kind )[ $from ] ?? null;
		if ( ! $existing || 'field_group' === $kind ) {
			return new \WP_Error( 'cradle_content_missing', __( 'Only database post types and taxonomies can be renamed.', 'cradle-content' ) );
		}
		if ( $this->is_code_owned( $kind, $from ) ) {
			return new \WP_Error( 'cradle_content_code_owned', __( 'This key is managed in code and cannot be renamed here.', 'cradle-content' ) );
		}
		$input        = self::clean( $existing );
		$input['key'] = $to;
		if ( ! empty( $options['keep_urls'] ) ) {
			if ( ! empty( $input['rewrite'] ) && '' === $input['rewrite_slug'] ) {
				$input['rewrite_slug'] = $from;
			}
			if ( '' === $input['rest_base'] ) {
				$input['rest_base'] = $from;
			}
		}
		$ctx = $this->context( $kind, $from );
		// The renamed definition keeps its own slugs; they must not collide with itself.
		$result = Validator::validate( $kind, $input, $ctx );
		if ( $result['errors'] ) {
			return new \WP_Error( 'cradle_content_invalid', implode( ' ', $result['errors'] ), array( 'errors' => $result['errors'] ) );
		}
		$definition = $result['definition'];
		$written    = $this->write(
			static function ( array $store ) use ( $kind, $from, $to, &$definition ) {
				if ( ! isset( $store[ $kind ][ $from ] ) ) {
					return new \WP_Error( 'cradle_content_missing', __( 'The definition no longer exists.', 'cradle-content' ) );
				}
				if ( isset( $store[ $kind ][ $to ] ) ) {
					return new \WP_Error( 'cradle_content_duplicate', __( 'A definition with the new key already exists.', 'cradle-content' ) );
				}
				$definition['revision']    = (int) $store[ $kind ][ $from ]['revision'] + 1;
				$definition['forked_from'] = (string) ( $store[ $kind ][ $from ]['forked_from'] ?? '' );
				$definition['modified']    = gmdate( 'c' );
				$definition['renamed_from'] = $from;
				unset( $store[ $kind ][ $from ] );
				$store[ $kind ][ $to ] = self::clean( $definition );
				return self::replace_references( $store, $kind, $from, $to );
			}
		);
		if ( is_wp_error( $written ) ) {
			return $written;
		}
		Caps::rename( $kind, $from, $to );
		cradle_log_activity( Module::ID, 'definition_renamed', sprintf( '%1$s key "%2$s" renamed to "%3$s".', $kind, $from, $to ), array( 'kind' => $kind, 'from' => $from, 'to' => $to ) );
		return $definition;
	}

	/**
	 * Replaces references to a renamed post type or taxonomy inside stored definitions.
	 */
	public static function replace_references( array $store, string $kind, string $from, string $to ): array {
		$swap = static function ( array $list ) use ( $from, $to ): array {
			return array_values( array_unique( array_map( static fn( $v ) => $v === $from ? $to : $v, $list ) ) );
		};
		if ( 'post_type' === $kind ) {
			foreach ( $store['taxonomy'] as &$tax ) {
				$tax['object_types'] = $swap( (array) $tax['object_types'] );
			}
			unset( $tax );
		} else {
			foreach ( $store['post_type'] as &$type ) {
				$type['taxonomies'] = $swap( (array) $type['taxonomies'] );
			}
			unset( $type );
		}
		$object = 'post_type' === $kind ? 'post' : 'term';
		$ref    = 'post_type' === $kind ? 'post_ref' : 'term_ref';
		foreach ( $store['field_group'] as &$group ) {
			if ( ( $group['object'] ?? 'post' ) === $object ) {
				$group['targets'] = $swap( (array) $group['targets'] );
			}
			foreach ( $group['fields'] as &$field ) {
				if ( $ref === $field['type'] ) {
					$field['targets'] = $swap( (array) ( $field['targets'] ?? array() ) );
				}
			}
			unset( $field );
		}
		unset( $group );
		return $store;
	}

	/**
	 * Copies a code definition into the database (same key: shadowed until the code definition is removed; new key: independent).
	 *
	 * @return array|\WP_Error
	 */
	public function fork( string $kind, string $key, string $new_key ) {
		$code = $this->code( $kind )[ $key ] ?? null;
		if ( ! $code ) {
			return new \WP_Error( 'cradle_content_missing', __( 'Only code-managed definitions can be forked.', 'cradle-content' ) );
		}
		$input        = self::clean( $code );
		$input['key'] = $new_key;
		unset( $input['revision'], $input['modified'] );
		if ( $new_key !== $key ) {
			// An independent copy must not claim the original's URLs, REST base or capabilities.
			foreach ( array( 'rewrite_slug', 'archive_slug', 'rest_base', 'capability_singular', 'capability_plural', 'capability_base' ) as $identity ) {
				if ( isset( $input[ $identity ] ) ) {
					$input[ $identity ] = '';
				}
			}
		}
		$result = $this->save(
			$kind,
			$input,
			'',
			null,
			array(
				'allow_shadow' => $new_key === $key,
				'forked_from'  => 'code:' . $key,
			)
		);
		if ( ! is_wp_error( $result ) ) {
			cradle_log_activity( Module::ID, 'definition_forked', sprintf( 'Code %1$s "%2$s" forked to database key "%3$s".', $kind, $key, $new_key ) );
		}
		return $result;
	}
}
