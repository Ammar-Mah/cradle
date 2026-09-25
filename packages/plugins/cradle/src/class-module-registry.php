<?php
/**
 * Registry of trusted module descriptors supplied by active add-on bootstraps.
 *
 * Descriptors are PHP arrays registered in code. They are never read from
 * user-editable JSON and never name callbacks supplied through settings.
 *
 * @package Cradle
 */

namespace Cradle\Core;

defined( 'ABSPATH' ) || exit;

final class Module_Registry {

	const STATE_INSTALLED  = 'installed';
	const STATE_ACTIVE     = 'active';
	const STATE_COMPATIBLE = 'compatible';
	const STATE_READY      = 'ready';
	const STATE_DISABLED   = 'disabled';
	const STATE_FAILED     = 'failed';

	const ALLOWED_KEYS = array(
		'id',
		'name',
		'description',
		'version',
		'coreApiRange',
		'pluginFile',
		'dependencies',
		'settingsSections',
		'capabilities',
		'schemaVersion',
		'dataOwners',
		'export',
		'import',
		'cleanup',
		'boot',
		'adminPage',
	);

	const DATA_OWNER_KEYS = array( 'options', 'postTypes', 'taxonomies', 'postMeta', 'termMeta', 'userMeta', 'tables', 'cron', 'uploads', 'transients', 'blocks', 'files' );

	/** @var array<string,array> Accepted descriptors keyed by ID. */
	private $descriptors = array();

	/** @var array<string,string> Module state keyed by ID. */
	private $states = array();

	/** @var array<string,string[]> Problems keyed by module ID (or a synthetic key). */
	private $problems = array();

	/** @var string[] Dependency-ordered ready IDs. */
	private $order = array();

	private $resolved = false;

	/**
	 * Registers one descriptor. Only valid during `cradle/register_modules`.
	 *
	 * @param array $descriptor Module descriptor.
	 * @return true|\WP_Error
	 */
	public function register( array $descriptor ) {
		if ( $this->resolved ) {
			return new \WP_Error( 'cradle_registry_closed', 'Modules must register during cradle/register_modules.' );
		}
		$id = isset( $descriptor['id'] ) && is_string( $descriptor['id'] ) ? $descriptor['id'] : '';
		if ( '' !== $id && isset( $this->descriptors[ $id ] ) ) {
			$this->problems[ $id ][] = sprintf( 'Duplicate module ID "%s" was rejected.', $id );
			return new \WP_Error( 'cradle_duplicate_module', 'Duplicate module ID.' );
		}
		$normalized = $this->validate( $descriptor );
		if ( is_wp_error( $normalized ) ) {
			$key                      = '' !== $id && preg_match( '/^[a-z][a-z0-9-]{1,40}$/', $id ) ? $id : 'invalid:' . md5( wp_json_encode( array_keys( $descriptor ) ) . $id );
			$this->problems[ $key ][] = $normalized->get_error_message();
			$this->states[ $key ]     = self::STATE_FAILED;
			return $normalized;
		}
		$this->descriptors[ $normalized['id'] ] = $normalized;
		$this->states[ $normalized['id'] ]      = self::STATE_ACTIVE;
		return true;
	}

	/**
	 * @return array|\WP_Error Normalized descriptor.
	 */
	public function validate( array $d ) {
		$unknown = array_diff( array_keys( $d ), self::ALLOWED_KEYS );
		if ( $unknown ) {
			return new \WP_Error( 'cradle_module_keys', 'Unknown descriptor keys: ' . implode( ', ', $unknown ) );
		}
		foreach ( array( 'id', 'name', 'version', 'coreApiRange', 'pluginFile' ) as $required ) {
			if ( empty( $d[ $required ] ) || ! is_string( $d[ $required ] ) ) {
				return new \WP_Error( 'cradle_module_required', sprintf( 'Descriptor field "%s" is required.', $required ) );
			}
		}
		if ( ! preg_match( '/^[a-z][a-z0-9-]{1,40}$/', $d['id'] ) ) {
			return new \WP_Error( 'cradle_module_id', 'Module ID must match ^[a-z][a-z0-9-]{1,40}$.' );
		}
		if ( ! Version_Range::is_version( $d['version'] ) ) {
			return new \WP_Error( 'cradle_module_version', 'Module version must be a semantic version.' );
		}
		if ( ! Version_Range::is_valid_range( $d['coreApiRange'] ) ) {
			return new \WP_Error( 'cradle_module_range', 'coreApiRange is not a supported range.' );
		}
		$plugin_dir  = wp_normalize_path( (string) realpath( WP_PLUGIN_DIR ) );
		$plugin_file = wp_normalize_path( (string) realpath( $d['pluginFile'] ) );
		if ( '' === $plugin_file || 0 !== strpos( $plugin_file, trailingslashit( $plugin_dir ) ) ) {
			return new \WP_Error( 'cradle_module_file', 'pluginFile must be a file inside the plugins directory.' );
		}

		$deps = array();
		foreach ( (array) ( $d['dependencies'] ?? array() ) as $key => $value ) {
			$dep_id = is_int( $key ) ? $value : $key;
			$range  = is_int( $key ) ? '' : $value;
			if ( ! is_string( $dep_id ) || ! preg_match( '/^[a-z][a-z0-9-]{1,40}$/', $dep_id ) || ( '' !== $range && ! Version_Range::is_valid_range( $range ) ) ) {
				return new \WP_Error( 'cradle_module_dependency', 'Invalid dependency declaration.' );
			}
			$deps[ $dep_id ] = $range;
		}

		$caps = array();
		foreach ( (array) ( $d['capabilities'] ?? array() ) as $cap => $definition ) {
			if ( ! is_string( $cap ) || ! preg_match( '/^cradle_[a-z0-9_]{2,60}$/', $cap ) || ! is_array( $definition ) ) {
				return new \WP_Error( 'cradle_module_capability', 'Capabilities must be cradle_* keys mapped to definitions.' );
			}
			$caps[ $cap ] = $definition;
		}

		$owners = array();
		foreach ( (array) ( $d['dataOwners'] ?? array() ) as $key => $list ) {
			if ( ! in_array( $key, self::DATA_OWNER_KEYS, true ) || ! is_array( $list ) ) {
				return new \WP_Error( 'cradle_module_owners', sprintf( 'Unsupported dataOwners key "%s".', $key ) );
			}
			$owners[ $key ] = array_values( array_filter( $list, 'is_string' ) );
		}

		foreach ( array( 'export', 'import', 'cleanup', 'boot' ) as $handler ) {
			if ( isset( $d[ $handler ] ) && ! is_callable( $d[ $handler ] ) ) {
				return new \WP_Error( 'cradle_module_handler', sprintf( 'Handler "%s" is not callable.', $handler ) );
			}
		}

		$schema_version = isset( $d['schemaVersion'] ) ? (int) $d['schemaVersion'] : 1;
		if ( $schema_version < 1 ) {
			return new \WP_Error( 'cradle_module_schema', 'schemaVersion must be a positive integer.' );
		}

		return array(
			'id'               => $d['id'],
			'name'             => $d['name'],
			'description'      => isset( $d['description'] ) ? (string) $d['description'] : '',
			'version'          => $d['version'],
			'coreApiRange'     => $d['coreApiRange'],
			'pluginFile'       => $plugin_file,
			'pluginBasename'   => plugin_basename( $plugin_file ),
			'dependencies'     => $deps,
			'settingsSections' => array_values( array_filter( (array) ( $d['settingsSections'] ?? array() ), 'is_string' ) ),
			'capabilities'     => $caps,
			'schemaVersion'    => $schema_version,
			'dataOwners'       => $owners,
			'export'           => $d['export'] ?? null,
			'import'           => $d['import'] ?? null,
			'cleanup'          => $d['cleanup'] ?? null,
			'boot'             => $d['boot'] ?? null,
			'adminPage'        => isset( $d['adminPage'] ) ? sanitize_key( $d['adminPage'] ) : '',
		);
	}

	/**
	 * Checks API compatibility, dependencies, and cycles. Called once by core.
	 */
	public function resolve( string $api_version ) {
		$this->resolved = true;

		foreach ( $this->descriptors as $id => $d ) {
			$ok = Version_Range::satisfies( $api_version, $d['coreApiRange'] );
			if ( true !== $ok ) {
				$this->fail( $id, sprintf( 'Requires core API %1$s; core provides %2$s.', $d['coreApiRange'], $api_version ) );
				continue;
			}
			$this->states[ $id ] = self::STATE_COMPATIBLE;
		}

		// Iterate until stable so failures propagate through dependency chains.
		do {
			$changed = false;
			foreach ( $this->descriptors as $id => $d ) {
				if ( self::STATE_COMPATIBLE !== $this->states[ $id ] ) {
					continue;
				}
				foreach ( $d['dependencies'] as $dep_id => $range ) {
					if ( ! isset( $this->descriptors[ $dep_id ] ) ) {
						$this->fail( $id, sprintf( 'Required module "%s" is not active.', $dep_id ) );
						$changed = true;
						continue 2;
					}
					if ( self::STATE_FAILED === $this->states[ $dep_id ] ) {
						$this->fail( $id, sprintf( 'Required module "%s" failed to load.', $dep_id ) );
						$changed = true;
						continue 2;
					}
					if ( '' !== $range && true !== Version_Range::satisfies( $this->descriptors[ $dep_id ]['version'], $range ) ) {
						$this->fail( $id, sprintf( 'Requires "%1$s" %2$s; installed %3$s.', $dep_id, $range, $this->descriptors[ $dep_id ]['version'] ) );
						$changed = true;
						continue 2;
					}
				}
			}
		} while ( $changed );

		$this->order = array();
		$visiting    = array();
		$visited     = array();
		foreach ( array_keys( $this->descriptors ) as $id ) {
			if ( self::STATE_COMPATIBLE === $this->states[ $id ] ) {
				$this->visit( $id, $visiting, $visited );
			}
		}
		foreach ( $this->order as $id ) {
			if ( self::STATE_COMPATIBLE === $this->states[ $id ] ) {
				$this->states[ $id ] = self::STATE_READY;
			}
		}
		$this->order = array_values(
			array_filter(
				$this->order,
				function ( $id ) {
					return self::STATE_READY === $this->states[ $id ];
				}
			)
		);
	}

	private function visit( string $id, array &$visiting, array &$visited ): bool {
		if ( isset( $visited[ $id ] ) ) {
			return self::STATE_FAILED !== $this->states[ $id ];
		}
		if ( isset( $visiting[ $id ] ) ) {
			$this->fail( $id, 'Dependency cycle detected.' );
			return false;
		}
		$visiting[ $id ] = true;
		foreach ( array_keys( $this->descriptors[ $id ]['dependencies'] ) as $dep_id ) {
			if ( ! $this->visit( $dep_id, $visiting, $visited ) ) {
				$this->fail( $id, sprintf( 'Dependency "%s" is part of a cycle or failed.', $dep_id ) );
			}
		}
		unset( $visiting[ $id ] );
		$visited[ $id ] = true;
		if ( self::STATE_FAILED !== $this->states[ $id ] ) {
			$this->order[] = $id;
			return true;
		}
		return false;
	}

	/**
	 * Calls each ready module's boot callback in dependency order.
	 */
	public function boot_ready_modules( Plugin $core ) {
		foreach ( $this->order as $id ) {
			$boot = $this->descriptors[ $id ]['boot'];
			if ( ! $boot || self::STATE_READY !== $this->states[ $id ] ) {
				continue;
			}
			try {
				call_user_func( $boot, $core, $this->descriptors[ $id ] );
			} catch ( \Throwable $e ) {
				$this->fail( $id, 'Boot failed: ' . $e->getMessage() );
			}
		}
	}

	private function fail( string $id, string $reason ) {
		$this->states[ $id ]     = self::STATE_FAILED;
		$this->problems[ $id ][] = $reason;
	}

	public function get( string $id ) {
		return $this->descriptors[ $id ] ?? null;
	}

	public function all(): array {
		return $this->descriptors;
	}

	public function state( string $id ): string {
		return $this->states[ $id ] ?? self::STATE_DISABLED;
	}

	public function is_ready( string $id ): bool {
		return self::STATE_READY === $this->state( $id );
	}

	public function problems(): array {
		return $this->problems;
	}

	public function ready_descriptors(): array {
		$out = array();
		foreach ( $this->order as $id ) {
			$out[ $id ] = $this->descriptors[ $id ];
		}
		return $out;
	}

	/**
	 * Installed first-party packages, including inactive ones. Admin-only: reads plugin headers.
	 *
	 * @return array<string,array> Keyed by plugin basename.
	 */
	public function installed_packages(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_plugins() as $basename => $data ) {
			$module = get_file_data( WP_PLUGIN_DIR . '/' . $basename, array( 'module' => 'Cradle Module' ) )['module'];
			if ( '' === $module && 'cradle/cradle.php' !== $basename ) {
				continue;
			}
			$out[ $basename ] = array(
				'module'  => '' === $module ? 'core' : sanitize_key( $module ),
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => is_plugin_active( $basename ),
			);
		}
		return $out;
	}

	/**
	 * Row summary for diagnostics screens, combining registry and installed-package data.
	 */
	public function report(): array {
		$rows = array();
		foreach ( $this->installed_packages() as $basename => $pkg ) {
			if ( 'core' === $pkg['module'] ) {
				continue;
			}
			$id          = $pkg['module'];
			$rows[ $id ] = array(
				'id'        => $id,
				'name'      => $pkg['name'],
				'version'   => $pkg['version'],
				'basename'  => $basename,
				'installed' => true,
				'active'    => $pkg['active'],
				'state'     => $pkg['active'] ? $this->state( $id ) : self::STATE_INSTALLED,
				'problems'  => $this->problems[ $id ] ?? array(),
			);
			if ( $pkg['active'] && ! isset( $this->descriptors[ $id ] ) && self::STATE_FAILED !== $this->state( $id ) ) {
				$rows[ $id ]['state']      = self::STATE_FAILED;
				$rows[ $id ]['problems'][] = 'Plugin is active but did not register a module descriptor.';
			}
		}
		foreach ( $this->problems as $id => $problems ) {
			if ( ! isset( $rows[ $id ] ) ) {
				$rows[ $id ] = array(
					'id'        => $id,
					'name'      => $this->descriptors[ $id ]['name'] ?? $id,
					'version'   => $this->descriptors[ $id ]['version'] ?? '',
					'basename'  => $this->descriptors[ $id ]['pluginBasename'] ?? '',
					'installed' => true,
					'active'    => true,
					'state'     => self::STATE_FAILED,
					'problems'  => $problems,
				);
			}
		}
		ksort( $rows );
		return $rows;
	}
}
