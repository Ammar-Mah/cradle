<?php
/**
 * Grants and revokes the primitive capabilities of definitions that use a custom capability model.
 *
 * Administrators always receive them; other editorial roles only when the
 * definition explicitly lists them. Every grant is recorded in the
 * non-autoloaded `cradle_content_caps` option so later changes and uninstall
 * revoke exactly what Cradle granted — and nothing another definition still needs.
 *
 * @package Cradle\Content
 */

namespace Cradle\Content;

defined( 'ABSPATH' ) || exit;

final class Caps {

	const OPTION = 'cradle_content_caps';

	/**
	 * Primitive capabilities for a definition, or an empty list for built-in models.
	 *
	 * @return string[]
	 */
	public static function for_definition( string $kind, array $def ): array {
		if ( 'post_type' === $kind && 'custom' === ( $def['capability_model'] ?? '' ) && '' !== ( $def['capability_plural'] ?? '' ) ) {
			$p = $def['capability_plural'];
			return array( "edit_{$p}", "edit_others_{$p}", "edit_private_{$p}", "edit_published_{$p}", "publish_{$p}", "read_private_{$p}", "delete_{$p}", "delete_others_{$p}", "delete_private_{$p}", "delete_published_{$p}" );
		}
		if ( 'taxonomy' === $kind && 'custom' === ( $def['capability_model'] ?? '' ) && '' !== ( $def['capability_base'] ?? '' ) ) {
			$b = $def['capability_base'];
			return array( "manage_{$b}", "edit_{$b}", "delete_{$b}", "assign_{$b}" );
		}
		return array();
	}

	private static function records(): array {
		$records = get_option( self::OPTION, array() );
		return is_array( $records ) ? $records : array();
	}

	/**
	 * Brings role capabilities in line with a saved definition.
	 */
	public static function sync( string $kind, string $key, array $def ) {
		$id      = $kind . ':' . $key;
		$caps    = self::for_definition( $kind, $def );
		$roles   = $caps ? array_values( array_unique( array_merge( array( 'administrator' ), (array) ( $def['capability_roles'] ?? array() ) ) ) ) : array();
		$records = self::records();
		$before  = $records[ $id ] ?? array(
			'caps'  => array(),
			'roles' => array(),
		);
		if ( $before['caps'] === $caps && $before['roles'] === $roles ) {
			return;
		}
		if ( $caps ) {
			$records[ $id ] = array(
				'caps'  => $caps,
				'roles' => $roles,
			);
		} else {
			unset( $records[ $id ] );
		}
		self::revoke_unwanted( $before, $records );
		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
		update_option( self::OPTION, $records, false );
	}

	/**
	 * Revokes everything recorded for a deleted definition (unless another definition still grants it).
	 */
	public static function forget( string $kind, string $key ) {
		$records = self::records();
		$id      = $kind . ':' . $key;
		if ( ! isset( $records[ $id ] ) ) {
			return;
		}
		$before = $records[ $id ];
		unset( $records[ $id ] );
		self::revoke_unwanted( $before, $records );
		update_option( self::OPTION, $records, false );
	}

	public static function rename( string $kind, string $from, string $to ) {
		$records = self::records();
		if ( isset( $records[ $kind . ':' . $from ] ) ) {
			$records[ $kind . ':' . $to ] = $records[ $kind . ':' . $from ];
			unset( $records[ $kind . ':' . $from ] );
			update_option( self::OPTION, $records, false );
		}
	}

	/**
	 * Removes caps from roles that no remaining record wants.
	 */
	private static function revoke_unwanted( array $before, array $remaining ) {
		foreach ( $before['roles'] as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $before['caps'] as $cap ) {
				$wanted = false;
				foreach ( $remaining as $record ) {
					if ( in_array( $cap, $record['caps'], true ) && in_array( $role_name, $record['roles'], true ) ) {
						$wanted = true;
						break;
					}
				}
				if ( ! $wanted && $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}

	/**
	 * Grants capabilities of code definitions (admin requests only; cheap comparison when unchanged).
	 */
	public static function sync_code_definitions() {
		$definitions = Definitions::instance();
		foreach ( array( 'post_type', 'taxonomy' ) as $kind ) {
			foreach ( $definitions->code( $kind ) as $key => $def ) {
				self::sync( $kind, $key, $def );
			}
		}
	}
}
