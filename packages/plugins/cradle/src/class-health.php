<?php
/**
 * Site Health tests and the administrator-triggered status report.
 *
 * @package Cradle
 */

namespace Cradle\Core;

defined( 'ABSPATH' ) || exit;

final class Health {

	/** @var Plugin */
	private static $core;

	public static function hooks( Plugin $core ) {
		self::$core = $core;
		add_filter( 'site_status_tests', array( __CLASS__, 'register_tests' ) );
	}

	public static function register_tests( array $tests ): array {
		$tests['direct']['cradle_modules'] = array(
			'label' => __( 'Cradle modules', 'cradle' ),
			'test'  => array( __CLASS__, 'test_modules' ),
		);
		$tests['direct']['cradle_runtime'] = array(
			'label' => __( 'Cradle runtime', 'cradle' ),
			'test'  => array( __CLASS__, 'test_runtime' ),
		);
		$tests['direct']['cradle_storage'] = array(
			'label' => __( 'Cradle private storage', 'cradle' ),
			'test'  => array( __CLASS__, 'test_storage' ),
		);
		return $tests;
	}

	private static function result( string $test, string $status, string $label, string $description ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => 'Cradle',
				'color' => 'good' === $status ? 'green' : ( 'critical' === $status ? 'red' : 'orange' ),
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( admin_url( 'admin.php?page=cradle' ) ) . '">' . esc_html__( 'Open the Cradle overview', 'cradle' ) . '</a></p>',
			'test'        => $test,
		);
	}

	public static function test_modules(): array {
		$failed = array();
		foreach ( self::$core->modules()->report() as $row ) {
			if ( 'failed' === $row['state'] ) {
				$failed[] = $row['name'] . ': ' . implode( ' ', $row['problems'] );
			}
		}
		if ( $failed ) {
			return self::result( 'cradle_modules', 'critical', __( 'One or more Cradle modules failed to load', 'cradle' ), implode( ' | ', $failed ) );
		}
		return self::result( 'cradle_modules', 'good', __( 'Cradle modules are loaded', 'cradle' ), __( 'Every active Cradle add-on registered successfully.', 'cradle' ) );
	}

	public static function test_runtime(): array {
		$problems = array();
		if ( version_compare( PHP_VERSION, CRADLE_MIN_PHP, '<' ) ) {
			$problems[] = sprintf( 'PHP %s is below the Cradle floor %s.', PHP_VERSION, CRADLE_MIN_PHP );
		}
		if ( is_multisite() ) {
			$problems[] = 'Multisite is not a supported Cradle environment yet.';
		}
		if ( $problems ) {
			return self::result( 'cradle_runtime', 'critical', __( 'Cradle runtime requirements are not met', 'cradle' ), implode( ' ', $problems ) );
		}
		return self::result( 'cradle_runtime', 'good', __( 'Cradle runtime requirements are met', 'cradle' ), sprintf( 'PHP %1$s, WordPress %2$s.', PHP_VERSION, get_bloginfo( 'version' ) ) );
	}

	public static function test_storage(): array {
		$probe = self::$core->storage()->verify_http_denial();
		$map   = array(
			'protected'   => 'good',
			'exposed'     => 'critical',
			'unknown'     => 'recommended',
			'unavailable' => 'recommended',
		);
		return self::result( 'cradle_storage', $map[ $probe['status'] ] ?? 'recommended', __( 'Cradle private storage protection', 'cradle' ) . ': ' . $probe['status'], $probe['detail'] );
	}

	/**
	 * Builds a redacted plain-text status report. No secrets, credentials or absolute paths.
	 */
	public static function status_report(): string {
		global $wpdb;
		$core  = self::$core;
		$lines = array(
			'Cradle status report',
			'Generated (UTC): ' . gmdate( 'Y-m-d H:i:s' ),
			'Core: ' . CRADLE_VERSION . ' (API ' . CRADLE_API_VERSION . ')',
			'WordPress: ' . get_bloginfo( 'version' ),
			'PHP: ' . PHP_VERSION,
			'Database: ' . $wpdb->db_server_info(),
			'Multisite: ' . ( is_multisite() ? 'yes' : 'no' ),
			'Environment: ' . wp_get_environment_type(),
			'Active theme: ' . get_stylesheet() . ' (template: ' . get_template() . ')',
			'Custom table prefix: ' . ( 'wp_' === $wpdb->prefix ? 'no' : 'yes' ),
			'Subdirectory install: ' . ( '' !== trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' ) ? 'yes' : 'no' ),
			'ZipArchive: ' . ( class_exists( 'ZipArchive' ) ? 'available' : 'missing' ),
			'Private storage: ' . $core->storage()->verify_http_denial()['status'],
			'',
			'Modules:',
		);
		foreach ( $core->modules()->report() as $row ) {
			$lines[] = sprintf( '- %1$s %2$s: %3$s%4$s', $row['id'], $row['version'], $row['state'], $row['problems'] ? ' (' . implode( '; ', $row['problems'] ) . ')' : '' );
		}
		$lines[] = '';
		$lines[] = 'Components: ' . implode( ', ', array_keys( $core->components()->all() ) );
		$overridden = array();
		foreach ( $core->components()->all() as $id => $component ) {
			if ( $core->components()->resolve_override( $component ) ) { // Also records contract mismatches.
				$overridden[] = $id;
			}
		}
		$lines[] = 'Theme overrides in use: ' . ( $overridden ? implode( ', ', $overridden ) : 'none' );
		foreach ( $core->components()->override_problems() as $id => $problem ) {
			$lines[] = '- override problem ' . $id . ': ' . Activity::redact_string( $problem );
		}
		return implode( "\n", $lines );
	}
}
