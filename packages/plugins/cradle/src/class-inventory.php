<?php
/**
 * Ownership inventory and usage scanning for cleanup previews.
 *
 * Scans are bounded and run only when an authorized administrator requests
 * them; nothing here runs on ordinary page requests.
 *
 * @package Cradle
 */

namespace Cradle\Core;

defined( 'ABSPATH' ) || exit;

final class Inventory {

	/** Post types whose content may hold block markup that references Cradle components. */
	const CONTENT_POST_TYPES = array( 'post', 'page', 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation', 'revision' );

	private Module_Registry $modules;
	private Components $components;

	public function __construct( Module_Registry $modules, Components $components ) {
		$this->modules    = $modules;
		$this->components = $components;
	}

	/**
	 * Data owned by core itself.
	 */
	public static function core_owners(): array {
		return array(
			'options' => array( 'cradle_core_schema', 'cradle_granted_capabilities', 'cradle_private_dir_suffix', 'cradle_storage_probe', 'cradle_uninstall_policy', 'cradle_installed_version' ),
			'tables'  => array( 'cradle_jobs', 'cradle_locks', 'cradle_activity' ),
			'cron'    => array( Activity::CRON_HOOK ),
			'files'   => array( 'uploads/cradle-private-*' ),
		);
	}

	/**
	 * @return array<string,array> Owners keyed by module ID, including `core`.
	 */
	public function owners(): array {
		$out = array( 'core' => self::core_owners() );
		foreach ( $this->modules->all() as $id => $descriptor ) {
			$out[ $id ] = $descriptor['dataOwners'];
			if ( $descriptor['capabilities'] ) {
				$out[ $id ]['capabilities'] = array_keys( $descriptor['capabilities'] );
			}
		}
		return $out;
	}

	/**
	 * Counts owned data for a module. Admin-triggered.
	 */
	public function scan( string $module ): array {
		global $wpdb;
		$owners = $this->owners()[ $module ] ?? array();
		$report = array();

		foreach ( $owners['options'] ?? array() as $option ) {
			if ( str_ends_with( $option, '*' ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$count                          = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( rtrim( $option, '*' ) ) . '%' ) );
				$report['options'][ $option ] = $count;
			} else {
				$report['options'][ $option ] = false !== get_option( $option, false ) ? 1 : 0;
			}
		}
		foreach ( $owners['postTypes'] ?? array() as $post_type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$report['postTypes'][ $post_type ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $post_type ) );
		}
		foreach ( $owners['taxonomies'] ?? array() as $taxonomy ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$report['taxonomies'][ $taxonomy ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy ) );
		}
		foreach ( array(
			'postMeta' => $wpdb->postmeta,
			'termMeta' => $wpdb->termmeta,
			'userMeta' => $wpdb->usermeta,
		) as $key => $table ) {
			foreach ( $owners[ $key ] ?? array() as $meta_key ) {
				$like = str_ends_with( $meta_key, '*' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$report[ $key ][ $meta_key ] = (int) $wpdb->get_var( $like ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE meta_key LIKE %s", $wpdb->esc_like( rtrim( $meta_key, '*' ) ) . '%' ) : $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE meta_key = %s", $meta_key ) );
			}
		}
		foreach ( $owners['tables'] ?? array() as $table ) {
			$full = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$exists                     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $full ) ) ) === $full;
			$report['tables'][ $table ] = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$full}`" ) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		foreach ( $owners['cron'] ?? array() as $hook ) {
			$report['cron'][ $hook ] = (bool) wp_next_scheduled( $hook );
		}
		foreach ( $owners['blocks'] ?? array() as $block ) {
			$report['blocks'][ $block ] = $this->count_block_usage( $block );
		}
		foreach ( $owners['capabilities'] ?? array() as $cap ) {
			$roles = array();
			foreach ( wp_roles()->roles as $role_name => $role ) {
				if ( ! empty( $role['capabilities'][ $cap ] ) ) {
					$roles[] = $role_name;
				}
			}
			$report['capabilities'][ $cap ] = $roles;
		}
		foreach ( $owners['transients'] ?? array() as $transient ) {
			$report['transients'][ $transient ] = false !== get_transient( $transient ) ? 1 : 0;
		}
		return $report;
	}

	/**
	 * Counts stored block comments for a block name across content-bearing post types.
	 *
	 * @return array{total:int,by_type:array<string,int>}
	 */
	public function count_block_usage( string $block_name ): array {
		global $wpdb;
		$needle = '<!-- wp:' . ( str_starts_with( $block_name, 'core/' ) ? substr( $block_name, 5 ) : $block_name );
		$types  = "'" . implode( "','", array_map( 'esc_sql', self::CONTENT_POST_TYPES ) ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT post_type, COUNT(*) AS c FROM {$wpdb->posts} WHERE post_type IN ({$types}) AND post_content LIKE %s GROUP BY post_type", '%' . $wpdb->esc_like( $needle ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_type = array();
		foreach ( $rows ?: array() as $row ) {
			$by_type[ $row['post_type'] ] = (int) $row['c'];
		}
		return array(
			'total'   => array_sum( $by_type ),
			'by_type' => $by_type,
		);
	}
}
