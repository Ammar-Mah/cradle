<?php
/**
 * Capability registry and grant policy.
 *
 * Policy: each Cradle capability is granted to its declared default roles
 * (administrator unless stated) exactly once. The set of already-granted
 * capabilities is remembered, so an administrator who later removes a
 * capability from a role is not overridden on the next request.
 *
 * @package Cradle
 */

namespace Cradle\Core;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	const GRANTED_OPTION = 'cradle_granted_capabilities';

	/** @var array<string,array> */
	private $caps = array();

	public function register_core() {
		$this->register(
			'cradle_manage_settings',
			array(
				'label'       => __( 'Manage Cradle settings', 'cradle' ),
				'description' => __( 'Open the Cradle area and change core settings.', 'cradle' ),
			),
			'core'
		);
		$this->register(
			'cradle_view_diagnostics',
			array(
				'label'       => __( 'View Cradle diagnostics', 'cradle' ),
				'description' => __( 'View module states, health and the redacted status report.', 'cradle' ),
			),
			'core'
		);
	}

	public function register( string $cap, array $definition, string $owner ) {
		$this->caps[ $cap ] = array(
			'label'       => (string) ( $definition['label'] ?? $cap ),
			'description' => (string) ( $definition['description'] ?? '' ),
			'roles'       => array_values( array_filter( (array) ( $definition['roles'] ?? array( 'administrator' ) ), 'is_string' ) ),
			'owner'       => $owner,
		);
	}

	public function all(): array {
		return $this->caps;
	}

	public function hooks() {
		// Grants run in admin/CLI contexts only, never on ordinary frontend requests.
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_action( 'init', array( $this, 'grant_pending' ), 1 );
		}
	}

	/**
	 * Grants newly registered capabilities to their default roles once.
	 */
	public function grant_pending() {
		$granted = get_option( self::GRANTED_OPTION, array() );
		$granted = is_array( $granted ) ? $granted : array();
		$changed = false;
		foreach ( $this->caps as $cap => $def ) {
			if ( isset( $granted[ $cap ] ) ) {
				continue;
			}
			self::grant( $cap, $def['roles'] );
			$granted[ $cap ] = $def['owner'];
			$changed         = true;
		}
		if ( $changed ) {
			update_option( self::GRANTED_OPTION, $granted, false );
		}
	}

	/**
	 * Adds a capability to roles and records the grant, so core's one-time grant policy
	 * does not re-add it after an administrator removes it. Usable from add-on activation hooks.
	 */
	public static function grant( string $cap, array $roles = array( 'administrator' ), string $owner = 'activation' ) {
		$granted = get_option( self::GRANTED_OPTION, array() );
		$granted = is_array( $granted ) ? $granted : array();
		if ( isset( $granted[ $cap ] ) ) {
			// Granted before: a later removal by an administrator is deliberate and must stand,
			// even when the owning plugin is reactivated.
			return;
		}
		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
		$granted[ $cap ] = $owner;
		update_option( self::GRANTED_OPTION, $granted, false );
	}

	/**
	 * Removes capabilities from every role; used only by explicit purge/uninstall.
	 */
	public static function revoke_everywhere( array $caps ) {
		$roles = wp_roles();
		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
		$granted = get_option( self::GRANTED_OPTION, array() );
		if ( is_array( $granted ) ) {
			foreach ( $caps as $cap ) {
				unset( $granted[ $cap ] );
			}
			update_option( self::GRANTED_OPTION, $granted, false );
		}
	}
}
