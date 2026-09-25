<?php
/**
 * Asset registration helpers built on the WordPress enqueue APIs.
 *
 * Handles are namespaced (`cradle-*`). Versions combine the package version and
 * file modification time so development changes bust caches predictably.
 *
 * @package Cradle
 */

namespace Cradle\Core;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'register_core_admin_assets' ), 1 );
	}

	public function register_core_admin_assets() {
		self::register_style( 'cradle-admin', CRADLE_FILE, 'assets/css/admin.css', array(), CRADLE_VERSION );
		self::register_script( 'cradle-admin', CRADLE_FILE, 'assets/js/admin.js', array( 'wp-i18n' ), CRADLE_VERSION );
		wp_set_script_translations( 'cradle-admin', 'cradle', CRADLE_DIR . 'languages' );
		wp_localize_script(
			'cradle-admin',
			'cradleAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'jobNonce' => wp_create_nonce( 'cradle_jobs' ),
			)
		);
	}

	public static function version( string $package_file, string $relative, string $package_version ): string {
		$path = plugin_dir_path( $package_file ) . ltrim( $relative, '/' );
		return $package_version . ( is_file( $path ) ? '.' . filemtime( $path ) : '' );
	}

	public static function register_style( string $handle, string $package_file, string $relative, array $deps, string $package_version ) {
		wp_register_style( $handle, plugins_url( ltrim( $relative, '/' ), $package_file ), $deps, self::version( $package_file, $relative, $package_version ) );
		wp_style_add_data( $handle, 'rtl', 'replace' === self::rtl_mode( $package_file, $relative ) ? 'replace' : false );
	}

	/**
	 * @param string $strategy `defer` (default), `async`, or '' for a blocking script (needed when
	 *                         blocking scripts such as editor block scripts depend on it).
	 */
	public static function register_script( string $handle, string $package_file, string $relative, array $deps, string $package_version, bool $in_footer = true, string $strategy = 'defer' ) {
		$args = array( 'in_footer' => $in_footer );
		if ( in_array( $strategy, array( 'defer', 'async' ), true ) ) {
			$args['strategy'] = $strategy;
		}
		wp_register_script(
			$handle,
			plugins_url( ltrim( $relative, '/' ), $package_file ),
			$deps,
			self::version( $package_file, $relative, $package_version ),
			$args
		);
	}

	/**
	 * Uses `name-rtl.css` beside a stylesheet when it exists; logical properties are preferred.
	 */
	private static function rtl_mode( string $package_file, string $relative ): string {
		$rtl = plugin_dir_path( $package_file ) . preg_replace( '/\.css$/', '-rtl.css', ltrim( $relative, '/' ) );
		return is_file( $rtl ) ? 'replace' : 'none';
	}
}
