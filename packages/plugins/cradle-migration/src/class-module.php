<?php
/**
 * Module descriptor and boot for Cradle Migration.
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

defined( 'ABSPATH' ) || exit;

final class Module {

	/**
	 * @param \Cradle\Core\Module_Registry $registry Registry.
	 */
	public static function register( $registry ) {
		$registry->register(
			array(
				'id'            => 'migration',
				'name'          => 'Cradle Migration',
				'description'   => 'Full-site export, import, restore and move with durable jobs, journaled cutover and operator recovery.',
				'version'       => CRADLE_MIGRATION_VERSION,
				'coreApiRange'  => '^1.0',
				'pluginFile'    => CRADLE_MIGRATION_FILE,
				'capabilities'  => array(
					'cradle_export_site' => array(
						'label'       => 'Export the whole site',
						'description' => 'Create and download full-site archives (they contain password hashes and secrets stored in the database).',
						'roles'       => array( 'administrator' ),
					),
					'cradle_import_site' => array(
						'label'       => 'Import and restore the whole site',
						'description' => 'Upload archives, restore them over this site (privileged code deployment) and run recovery.',
						'roles'       => array( 'administrator' ),
					),
				),
				'schemaVersion' => 1,
				'dataOwners'    => array(
					'options' => array( 'cradle_migration_quiesce', 'cradle_migration_safety_hold', 'cradle_migration_last_restore' ),
					// Restore backups are unprefixed tables cmig_<job>_* / cold_<job>_* (see AGENTS.md); core's
					// inventory only understands prefixed table names, so they are listed on the Migration screen.
					'files'   => array( 'private:migration/archives', 'private:migration/incoming', 'private:migration/jobs' ),
				),
				'boot'          => array( __CLASS__, 'boot' ),
				'adminPage'     => 'cradle-migration',
			)
		);
	}

	/**
	 * @param \Cradle\Core\Plugin $core       Core.
	 * @param array               $descriptor Normalized descriptor.
	 */
	public static function boot( $core, array $descriptor ) {
		if ( version_compare( $core->api_version(), '1.0.0', '<' ) ) {
			return;
		}
		$core->jobs()->register_type(
			Exporter::TYPE,
			array(
				'capability'    => 'cradle_export_site',
				'step'          => array( Exporter::class, 'step' ),
				'max_retries'   => 3,
				'lease_seconds' => 120,
				'time_budget'   => 8,
			)
		);
		$core->jobs()->register_type(
			Importer::TYPE,
			array(
				'capability'    => 'cradle_import_site',
				'step'          => array( Importer::class, 'step' ),
				'max_retries'   => 3,
				'lease_seconds' => 120,
				'time_budget'   => 8,
			)
		);
		Guard::hooks();
		Ajax::hooks();
		if ( is_admin() ) {
			( new Admin\Page() )->hooks();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command(
				'cradle-migrate',
				static function ( $args, $assoc ) {
					$code = Cli::dispatch( (array) $args, (array) $assoc );
					if ( 0 !== $code ) {
						\WP_CLI::halt( $code );
					}
				},
				array( 'shortdesc' => 'Cradle Migration: export | import | status | approve | cutover | recover | rollback | release-safety | purge | archives' )
			);
		}
	}
}
