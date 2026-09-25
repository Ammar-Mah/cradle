<?php
/**
 * Module descriptor, boot, and the export/import/cleanup handlers used by core services.
 *
 * @package Cradle\Content
 */

namespace Cradle\Content;

defined( 'ABSPATH' ) || exit;

final class Module {

	const ID  = 'content';
	const CAP = 'cradle_manage_content_types';

	/** Options this module owns. */
	const OPTIONS = array( Definitions::OPTION, 'cradle_content_settings', Caps::OPTION, Registry::SIGNATURE_OPTION );

	/**
	 * Registers the trusted descriptor on `cradle/register_modules` (no translation calls here).
	 *
	 * @param \Cradle\Core\Module_Registry $registry Registry.
	 */
	public static function register( $registry ) {
		// Post types and taxonomies stored in the database are listed so the core inventory can count
		// their content. Uninstall never deletes that content (see docs/lifecycle.md).
		$stored     = get_option( Definitions::OPTION, array() );
		$post_types = is_array( $stored ) && is_array( $stored['post_type'] ?? null ) ? array_values( array_filter( array_keys( $stored['post_type'] ), 'is_string' ) ) : array();
		$taxonomies = is_array( $stored ) && is_array( $stored['taxonomy'] ?? null ) ? array_values( array_filter( array_keys( $stored['taxonomy'] ), 'is_string' ) ) : array();

		$registry->register(
			array(
				'id'               => self::ID,
				'name'             => 'Cradle Content',
				'description'      => 'Custom post types, taxonomies and bounded field schemas with export/import and key migrations.',
				'version'          => CRADLE_CONTENT_VERSION,
				'coreApiRange'     => '^1.0',
				'pluginFile'       => CRADLE_CONTENT_FILE,
				'settingsSections' => array( Settings_Section::ID ),
				'capabilities'     => array(
					self::CAP => array(
						'label'       => 'Manage content types, taxonomies and fields',
						'description' => 'Create, edit, disable, rename, export and import Cradle content definitions.',
						'roles'       => array( 'administrator' ),
					),
				),
				'schemaVersion'    => Definitions::SCHEMA,
				'dataOwners'       => array(
					'options'    => self::OPTIONS,
					'postTypes'  => $post_types,
					'taxonomies' => $taxonomies,
					'postMeta'   => array( Field_Types::META_PREFIX . '*' ),
					'termMeta'   => array( Field_Types::META_PREFIX . '*' ),
					'cron'       => array( Migrations::CRON_HOOK ),
				),
				'boot'             => array( __CLASS__, 'boot' ),
				'export'           => array( __CLASS__, 'export' ),
				'import'           => array( __CLASS__, 'import' ),
				'cleanup'          => array( __CLASS__, 'cleanup' ),
				'adminPage'        => 'cradle-content',
			)
		);
	}

	/**
	 * Attaches every feature hook. Runs at init priority 0 when the module is ready.
	 *
	 * @param \Cradle\Core\Plugin $core       Core.
	 * @param array               $descriptor Normalized descriptor.
	 */
	public static function boot( $core, array $descriptor = array() ) {
		if ( version_compare( $core->api_version(), '1.0.0', '<' ) ) {
			return;
		}
		load_plugin_textdomain( 'cradle-content', false, dirname( plugin_basename( CRADLE_CONTENT_FILE ) ) . '/languages' );

		Settings_Section::register( $core );
		add_action( 'cradle/translations/register_labels', array( Labels::class, 'register' ) );
		Registry::instance()->boot();
		Migrations::register( $core );
		Rest::hooks();
		Bindings::register();

		if ( is_admin() ) {
			( new Admin\Admin() )->hooks();
			Admin\Meta_Box::hooks();
			Admin\Editor::hooks();
			add_action( 'admin_init', array( Caps::class, 'sync_code_definitions' ) );
		}
	}

	/**
	 * Module export for migrations/backups: every database definition plus settings.
	 */
	public static function export(): array {
		$document = Transfer::export();
		foreach ( Definitions::KINDS as $kind ) {
			foreach ( array_keys( $document['definitions'][ $kind ] ) as $key ) {
				if ( Definitions::instance()->is_code_owned( $kind, $key ) ) {
					unset( $document['definitions'][ $kind ][ $key ] ); // Code definitions travel with the plugin that ships them.
				}
			}
		}
		return array(
			'definitions' => $document,
			'settings'    => function_exists( 'cradle' ) && cradle() ? cradle()->settings()->export( Settings_Section::ID ) : array(),
		);
	}

	/**
	 * Module import: creates new definitions and overwrites differing database definitions with the same key.
	 * Code-managed and foreign keys are never touched.
	 *
	 * @return true|\WP_Error
	 */
	public static function import( array $data ) {
		$preview = Transfer::preview( (array) ( $data['definitions'] ?? array() ) );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$overwrite = array();
		foreach ( $preview['items'] as $item ) {
			if ( 'update' === $item['status'] ) {
				$overwrite[] = $item['kind'] . ':' . $item['key'];
			}
		}
		$report = Transfer::apply( $preview, $overwrite );
		if ( ! empty( $data['settings'] ) && is_array( $data['settings'] ) && function_exists( 'cradle' ) && cradle() ) {
			cradle()->settings()->update( Settings_Section::ID, $data['settings'], false );
		}
		$problems = array();
		foreach ( $preview['items'] as $item ) {
			if ( in_array( $item['status'], array( 'invalid', 'blocked' ), true ) ) {
				$problems[] = $item['kind'] . ':' . $item['key'] . ' — ' . implode( ' ', $item['errors'] );
			}
		}
		foreach ( $report['failed'] as $id => $message ) {
			$problems[] = $id . ' — ' . $message;
		}
		return $problems ? new \WP_Error( 'cradle_content_import_partial', implode( ' | ', $problems ), $report ) : true;
	}

	/**
	 * Cleanup handler. Reports owned data; with `delete_orphaned_field_values` in scope, removes
	 * stored values whose field no longer exists in any definition (bounded to 500 rows per call).
	 *
	 * @param array $scope Scope flags.
	 */
	public static function cleanup( array $scope = array() ): array {
		global $wpdb;
		$defined = array();
		foreach ( Definitions::instance()->effective( 'field_group' ) as $group ) {
			foreach ( (array) $group['fields'] as $field ) {
				if ( '' === ( $field['parent'] ?? '' ) ) {
					$defined[] = Field_Types::meta_key( $field['key'] );
				}
			}
		}
		$like   = $wpdb->esc_like( Field_Types::META_PREFIX ) . '%';
		$report = array(
			'definitions' => array(),
			'orphaned'    => array(),
			'deleted'     => 0,
		);
		foreach ( Definitions::KINDS as $kind ) {
			$report['definitions'][ $kind ] = count( Definitions::instance()->db( $kind ) );
		}
		foreach ( array(
			'post' => $wpdb->postmeta,
			'term' => $wpdb->termmeta,
		) as $object => $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb.
			$keys = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, COUNT(*) AS c FROM {$table} WHERE meta_key LIKE %s GROUP BY meta_key LIMIT 500", $like ), ARRAY_A );
			foreach ( $keys ?: array() as $row ) {
				if ( ! in_array( $row['meta_key'], $defined, true ) ) {
					$report['orphaned'][ $object ][ $row['meta_key'] ] = (int) $row['c'];
				}
			}
		}
		if ( ! empty( $scope['delete_orphaned_field_values'] ) && current_user_can( self::CAP ) ) {
			$budget = 500;
			foreach ( $report['orphaned'] as $object => $keys ) {
				$table = 'term' === $object ? $wpdb->termmeta : $wpdb->postmeta;
				foreach ( array_keys( $keys ) as $meta_key ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column from allowlist.
					$ids = $wpdb->get_col( $wpdb->prepare( "SELECT meta_id FROM {$table} WHERE meta_key = %s LIMIT %d", $meta_key, $budget ) );
					foreach ( $ids as $mid ) {
						delete_metadata_by_mid( $object, (int) $mid );
						++$report['deleted'];
						--$budget;
					}
					if ( $budget <= 0 ) {
						break 2;
					}
				}
			}
			cradle_log_activity( self::ID, 'cleanup_orphaned', sprintf( 'Deleted %d orphaned field values.', $report['deleted'] ) );
		}
		return $report;
	}
}
