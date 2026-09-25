<?php
/**
 * Module descriptor, readiness hook and boot.
 *
 * @package Cradle\Translations
 */

namespace Cradle\Translations;

defined( 'ABSPATH' ) || exit;

final class Module {

	/**
	 * Registers the trusted descriptor (plain strings only; no translation calls before init).
	 *
	 * @param \Cradle\Core\Module_Registry $registry Core registry.
	 */
	public static function register( $registry ) {
		$registry->register(
			array(
				'id'               => Config::MODULE,
				'name'             => 'Cradle Translations',
				'description'      => 'JSON translation catalogs and a Loco-like editor for interface strings.',
				'version'          => CRADLE_TRANSLATIONS_VERSION,
				'coreApiRange'     => '^1.0',
				'pluginFile'       => CRADLE_TRANSLATIONS_FILE,
				'settingsSections' => array( 'translations' ),
				'capabilities'     => array(
					Config::CAP => array(
						'label'       => 'Manage translations',
						'description' => 'Edit, import, export, publish and restore interface translation catalogs. Grants no migration, import-site or code upload rights.',
						'roles'       => array( 'administrator' ),
					),
				),
				'schemaVersion'    => 1,
				'dataOwners'       => array(
					'options'    => array( Config::OPTION, Config::INDEX_OPTION, Config::STATE_OPTION, 'cradle_translations_version', Examples::OPTION ),
					'cron'       => array( Config::CRON_REFRESH ),
					'transients' => array( 'cradle_translations_notice_*' ),
					'files'      => array( 'cradle-private/translations/catalogs/*.json', 'cradle-private/translations/history/*.json', 'cradle-private/translations/runtime/*.json', 'cradle-private/translations/manifest/manifest.json' ),
				),
				'boot'             => array( __CLASS__, 'boot' ),
				'export'           => array( __CLASS__, 'export' ),
				'import'           => array( __CLASS__, 'import' ),
				'cleanup'          => array( __CLASS__, 'cleanup' ),
				'adminPage'        => 'cradle-translations',
			)
		);
	}

	/**
	 * `cradle/ready` (plugins_loaded priority 5): attach the runtime before `init`, because core
	 * translates its own capability and settings labels at `init` priority 0.
	 *
	 * @param \Cradle\Core\Plugin $core        Core.
	 * @param string              $api_version Core API version.
	 */
	public static function ready( $core, $api_version ) {
		if ( ! is_string( $api_version ) || version_compare( $api_version, '1.0.0', '<' ) || ! $core->modules()->is_ready( Config::MODULE ) ) {
			return;
		}
		Runtime::instance()->start();
	}

	/**
	 * @param \Cradle\Core\Plugin $core       Core.
	 * @param array               $descriptor Normalized descriptor.
	 */
	public static function boot( $core, array $descriptor ) {
		if ( version_compare( $core->api_version(), '1.0.0', '<' ) ) {
			return;
		}
		$core->settings()->register_section( self::settings_section() );
		add_action( Config::CRON_REFRESH, array( __CLASS__, 'cron_refresh' ) );
		add_action( 'admin_init', array( Examples::class, 'maybe_install' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 10, 2 );
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->hooks();
		}
	}

	public static function settings_section(): array {
		return array(
			'id'            => 'translations',
			'owner'         => Config::MODULE,
			'title'         => __( 'Translation settings', 'cradle-translations' ),
			'description'   => __( 'Runtime behavior and limits of Cradle translation catalogs.', 'cradle-translations' ),
			'capability'    => Config::CAP,
			'schemaVersion' => 1,
			'page'          => false,
			'autoload'      => true,
			'lifecycle'     => array( 'uninstall' => 'retain' ),
			'fields'        => array(
				'runtime_enabled' => array(
					'type'    => 'boolean',
					'default' => true,
					'label'   => __( 'Apply published translations', 'cradle-translations' ),
					'help'    => __( 'Turn off to pause every override without deleting catalogs. WordPress then shows its shipped translations.', 'cradle-translations' ),
				),
				'js_enabled'      => array(
					'type'    => 'boolean',
					'default' => true,
					'label'   => __( 'Apply overrides to JavaScript translations', 'cradle-translations' ),
					'help'    => __( 'Merges published overrides into the translation data WordPress loads for registered scripts.', 'cradle-translations' ),
				),
				'expose_locales'  => array(
					'type'    => 'boolean',
					'default' => true,
					'label'   => __( 'Offer published catalog locales as languages', 'cradle-translations' ),
					'help'    => __( 'Lets users and the site select a locale that has published Cradle overrides even when no WordPress language pack is installed.', 'cradle-translations' ),
				),
				'auto_refresh'    => array(
					'type'    => 'boolean',
					'default' => true,
					'label'   => __( 'Refresh source strings after Cradle package updates', 'cradle-translations' ),
					'help'    => __( 'Schedules a background re-extraction when a Cradle plugin or theme is updated through WordPress. Translations are kept; vanished strings become obsolete.', 'cradle-translations' ),
				),
				'history_limit'   => array(
					'type'    => 'integer',
					'default' => 20,
					'minimum' => 3,
					'maximum' => 100,
					'label'   => __( 'Revisions kept per locale', 'cradle-translations' ),
				),
				'max_import_kb'   => array(
					'type'    => 'integer',
					'default' => 4096,
					'minimum' => 64,
					'maximum' => 8192,
					'step'    => 64,
					'label'   => __( 'Maximum import size (KB)', 'cradle-translations' ),
				),
				'per_page'        => array(
					'type'    => 'integer',
					'default' => 50,
					'minimum' => 20,
					'maximum' => 200,
					'step'    => 10,
					'label'   => __( 'Strings per page in the editor', 'cradle-translations' ),
				),
			),
		);
	}

	public static function cron_refresh() {
		if ( Config::get( 'auto_refresh' ) ) {
			Service::refresh_sources( true );
		}
	}

	/**
	 * Schedules a source refresh after a Cradle package update through the WordPress upgrader.
	 *
	 * @param mixed $upgrader Upgrader instance.
	 * @param mixed $extra    Hook extra data.
	 */
	public static function after_upgrade( $upgrader, $extra ) {
		if ( ! Config::get( 'auto_refresh' ) || ! is_array( $extra ) || 'update' !== ( $extra['action'] ?? '' ) ) {
			return;
		}
		$items = array();
		if ( 'plugin' === ( $extra['type'] ?? '' ) ) {
			$items = array_map( 'dirname', (array) ( $extra['plugins'] ?? ( isset( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() ) ) );
		} elseif ( 'theme' === ( $extra['type'] ?? '' ) ) {
			$items = (array) ( $extra['themes'] ?? ( isset( $extra['theme'] ) ? array( $extra['theme'] ) : array() ) );
		}
		foreach ( $items as $slug ) {
			if ( is_string( $slug ) && preg_match( '/^cradle(?:-[a-z0-9]+)*$/', $slug ) ) {
				if ( ! wp_next_scheduled( Config::CRON_REFRESH ) ) {
					wp_schedule_single_event( time() + MINUTE_IN_SECONDS, Config::CRON_REFRESH );
				}
				return;
			}
		}
	}

	private static function handler_allowed( string $site_cap ): bool {
		return current_user_can( Config::CAP ) || current_user_can( $site_cap ) || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Module export handler (for migration): every catalog in the authoritative format.
	 */
	public static function export(): array {
		if ( ! self::handler_allowed( 'cradle_export_site' ) ) {
			return array();
		}
		$catalogs = array();
		foreach ( Store::locales() as $locale ) {
			$catalog = Store::read( $locale );
			if ( ! is_wp_error( $catalog ) ) {
				$catalogs[ $locale ] = Catalog::export( $catalog );
			}
		}
		return array(
			'format'        => 'cradle-translations',
			'formatVersion' => 1,
			'catalogs'      => $catalogs,
		);
	}

	/**
	 * Module import handler: validates every catalog first, then replaces each as a new revision.
	 *
	 * @return true|\WP_Error
	 */
	public static function import( array $data ) {
		if ( ! self::handler_allowed( 'cradle_import_site' ) ) {
			return new \WP_Error( 'cradle_translations_forbidden', __( 'You are not allowed to import translations.', 'cradle-translations' ) );
		}
		if ( 'cradle-translations' !== ( $data['format'] ?? '' ) || ! is_array( $data['catalogs'] ?? null ) || count( $data['catalogs'] ) > 100 ) {
			return new \WP_Error( 'cradle_translations_import', __( 'This is not a Cradle translations export.', 'cradle-translations' ) );
		}
		$valid = array();
		foreach ( $data['catalogs'] as $locale => $catalog ) {
			$checked = Validator::catalog(
				$catalog,
				array(
					'locale'   => (string) $locale,
					'semantic' => true,
				)
			);
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
			$valid[ (string) $locale ] = $checked;
		}
		foreach ( $valid as $locale => $incoming ) {
			$result = Store::mutate(
				$locale,
				'module_import',
				static function ( array $current ) use ( $incoming ) {
					$current['domains'] = $incoming['domains'];
					return array(
						'catalog' => $current,
						'changed' => true,
						'summary' => 'Catalog replaced by a module import.',
					);
				},
				array( 'create' => true )
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	/**
	 * Module cleanup handler: reports stored catalogs; deletes listed locales when asked.
	 *
	 * @param array $scope `locales` (string[]) to delete, `dryRun` (bool, default true).
	 */
	public static function cleanup( array $scope ): array {
		$report = array(
			'locales' => array(),
			'deleted' => array(),
		);
		foreach ( Store::locales() as $locale ) {
			$report['locales'][ $locale ] = count( Store::snapshots( $locale ) ) + 1;
		}
		if ( ( $scope['dryRun'] ?? true ) || ! current_user_can( Config::CAP ) ) {
			return $report;
		}
		foreach ( (array) ( $scope['locales'] ?? array() ) as $locale ) {
			if ( is_string( $locale ) && Store::exists( $locale ) && true === Store::delete_locale( $locale ) ) {
				$report['deleted'][] = $locale;
			}
		}
		return $report;
	}
}
