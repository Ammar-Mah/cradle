<?php
/**
 * Protected storage for non-public managed files (catalog revisions, job working
 * files, migration archives).
 *
 * Preferred: a directory outside the web root declared by the trusted constant
 * CRADLE_PRIVATE_DIR in wp-config.php. Fallback: a randomly named directory
 * under uploads with Apache/IIS deny rules. The fallback is only treated as
 * protected after an HTTP probe proves direct access is denied on this server;
 * an Apache rule does not protect Nginx.
 *
 * @package Cradle
 */

namespace Cradle\Core;

defined( 'ABSPATH' ) || exit;

final class Storage {

	const SUFFIX_OPTION = 'cradle_private_dir_suffix';
	const PROBE_OPTION  = 'cradle_storage_probe';

	/**
	 * @return array{path:string,mode:string}|\WP_Error
	 */
	public function private_root() {
		if ( defined( 'CRADLE_PRIVATE_DIR' ) && is_string( CRADLE_PRIVATE_DIR ) && '' !== CRADLE_PRIVATE_DIR ) {
			$path = wp_normalize_path( untrailingslashit( CRADLE_PRIVATE_DIR ) );
			if ( ! path_is_absolute( $path ) ) {
				return new \WP_Error( 'cradle_storage_relative', 'CRADLE_PRIVATE_DIR must be an absolute path.' );
			}
			if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
				return new \WP_Error( 'cradle_storage_create', 'CRADLE_PRIVATE_DIR could not be created.' );
			}
			$real = wp_normalize_path( (string) realpath( $path ) );
			if ( self::path_within( $real, (string) realpath( ABSPATH ) ) || self::path_within( $real, (string) realpath( WP_CONTENT_DIR ) ) ) {
				return new \WP_Error( 'cradle_storage_inside', 'CRADLE_PRIVATE_DIR must be outside the WordPress directories.' );
			}
			return array(
				'path' => $real,
				'mode' => 'outside-webroot',
			);
		}

		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'cradle_storage_uploads', 'The uploads directory is unavailable.' );
		}
		$suffix = get_option( self::SUFFIX_OPTION );
		if ( ! is_string( $suffix ) || ! preg_match( '/^[a-z0-9]{20}$/', $suffix ) ) {
			$suffix = strtolower( wp_generate_password( 20, false, false ) );
			update_option( self::SUFFIX_OPTION, $suffix, false );
		}
		$path = wp_normalize_path( $uploads['basedir'] . '/cradle-private-' . $suffix );
		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return new \WP_Error( 'cradle_storage_create', 'The private storage directory could not be created.' );
		}
		self::write_deny_rules( $path );
		return array(
			'path' => wp_normalize_path( (string) realpath( $path ) ),
			'mode' => 'in-webroot',
			'url'  => $uploads['baseurl'] . '/cradle-private-' . $suffix,
		);
	}

	/**
	 * Returns (and creates) a module's private directory.
	 *
	 * @return string|\WP_Error
	 */
	public function module_dir( string $module, string $sub = '' ) {
		if ( ! preg_match( '/^[a-z][a-z0-9-]{1,40}$/', $module ) || ( '' !== $sub && ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $sub ) ) ) {
			return new \WP_Error( 'cradle_storage_name', 'Invalid storage name.' );
		}
		$root = $this->private_root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$dir = $root['path'] . '/' . $module . ( '' !== $sub ? '/' . $sub : '' );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'cradle_storage_create', 'Module storage could not be created.' );
		}
		if ( 'in-webroot' === $root['mode'] ) {
			self::write_deny_rules( $dir );
		}
		return wp_normalize_path( (string) realpath( $dir ) );
	}

	private static function write_deny_rules( string $dir ) {
		$files = array(
			'.htaccess'  => "# Cradle private storage\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\nOptions -Indexes\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);
		foreach ( $files as $name => $contents ) {
			if ( ! is_file( $dir . '/' . $name ) ) {
				file_put_contents( $dir . '/' . $name, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/**
	 * Proves (or disproves) that private files cannot be fetched over HTTP.
	 *
	 * @param bool $force Re-run even when a recent result exists.
	 * @return array{status:string,detail:string,checked:int}
	 */
	public function verify_http_denial( bool $force = false ): array {
		$root = $this->private_root();
		if ( is_wp_error( $root ) ) {
			return array(
				'status'  => 'unavailable',
				'detail'  => $root->get_error_message(),
				'checked' => time(),
			);
		}
		if ( 'outside-webroot' === $root['mode'] ) {
			return array(
				'status'  => 'protected',
				'detail'  => 'Stored outside the WordPress directories.',
				'checked' => time(),
			);
		}
		$cached = get_option( self::PROBE_OPTION );
		if ( ! $force && is_array( $cached ) && ( time() - (int) ( $cached['checked'] ?? 0 ) ) < DAY_IN_SECONDS ) {
			return $cached;
		}
		$token = wp_generate_password( 32, false, false );
		$name  = 'probe-' . strtolower( wp_generate_password( 12, false, false ) ) . '.txt';
		$file  = $root['path'] . '/' . $name;
		file_put_contents( $file, $token ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$response = wp_remote_get(
			$root['url'] . '/' . $name,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => false,
			)
		);
		wp_delete_file( $file );
		if ( is_wp_error( $response ) ) {
			$result = array(
				'status'  => 'unknown',
				'detail'  => 'The HTTP probe could not reach this site: ' . $response->get_error_message(),
				'checked' => time(),
			);
		} else {
			$code   = (int) wp_remote_retrieve_response_code( $response );
			$body   = (string) wp_remote_retrieve_body( $response );
			$leaked = 200 === $code && false !== strpos( $body, $token );
			$result = array(
				'status'  => $leaked ? 'exposed' : 'protected',
				'detail'  => $leaked ? 'A probe file was readable over HTTP. Configure CRADLE_PRIVATE_DIR outside the web root or add server deny rules.' : sprintf( 'Direct HTTP access returned status %d.', $code ),
				'checked' => time(),
			);
		}
		update_option( self::PROBE_OPTION, $result, false );
		return $result;
	}

	public function is_protected(): bool {
		return 'protected' === $this->verify_http_denial()['status'];
	}

	/**
	 * Canonical containment check that resolves symlinks of the nearest existing parent.
	 */
	public static function path_within( string $path, string $root ): bool {
		if ( '' === $root ) {
			return false;
		}
		$root  = rtrim( wp_normalize_path( (string) ( realpath( $root ) ?: $root ) ), '/' );
		$check = wp_normalize_path( $path );
		$probe = $check;
		$tail  = '';
		while ( ! file_exists( $probe ) ) {
			$parent = dirname( $probe );
			if ( $parent === $probe ) {
				return false;
			}
			$tail  = '/' . basename( $probe ) . $tail;
			$probe = $parent;
		}
		$resolved = rtrim( wp_normalize_path( (string) realpath( $probe ) ), '/' ) . $tail;
		if ( false !== strpos( $tail, '/../' ) || str_ends_with( $tail, '/..' ) ) {
			return false;
		}
		$is_windows = '\\' === DIRECTORY_SEPARATOR;
		$a          = $is_windows ? strtolower( $resolved ) : $resolved;
		$b          = $is_windows ? strtolower( $root ) : $root;
		return $a === $b || 0 === strpos( $a, $b . '/' );
	}

	/**
	 * Recursively removes a directory that must be inside a module's private dir.
	 */
	public function remove_tree( string $dir, string $module ): bool {
		$base = $this->module_dir( $module );
		if ( is_wp_error( $base ) || ! self::path_within( $dir, $base ) || wp_normalize_path( $dir ) === $base ) {
			return false;
		}
		if ( ! is_dir( $dir ) ) {
			return true;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isLink() || $item->isFile() ) {
				wp_delete_file( $item->getPathname() );
			} else {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
		}
		return rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
