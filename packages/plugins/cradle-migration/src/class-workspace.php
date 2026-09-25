<?php
/**
 * Private working storage for archives, uploads and job state.
 *
 * Layout inside cradle()->storage()->module_dir( 'migration' ):
 *   archives/<archiveId>/   completed export archives (manifest + parts)
 *   incoming/<session>/     chunked browser uploads and CLI-copied archives
 *   jobs/<job uuid>/        job working files, staging tree, backups, journal, recovery context
 *
 * Nothing is written before private storage is proven protected (outside the web root,
 * or denied over HTTP on this server).
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

use Cradle\Core\Storage;

defined( 'ABSPATH' ) || exit;

final class Workspace {

	/**
	 * @return string|\WP_Error Module root (absolute, normalized).
	 */
	public static function root() {
		if ( ! function_exists( 'cradle' ) || ! cradle() ) {
			return new \WP_Error( 'cradle_migration_core', 'Cradle core is not running.' );
		}
		return cradle()->storage()->module_dir( 'migration' );
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function ensure_protected( bool $force = false ) {
		if ( ! function_exists( 'cradle' ) || ! cradle() ) {
			return new \WP_Error( 'cradle_migration_core', 'Cradle core is not running.' );
		}
		$probe = cradle()->storage()->verify_http_denial( $force );
		if ( 'protected' !== $probe['status'] ) {
			return new \WP_Error( 'cradle_migration_storage', 'Private storage is not proven protected (' . $probe['status'] . '). Define CRADLE_PRIVATE_DIR outside the web root or fix the server deny rules, then re-check in Site Health (Cradle private storage). ' . $probe['detail'] );
		}
		return true;
	}

	/**
	 * @return string|\WP_Error One of the top-level areas.
	 */
	public static function area( string $name ) {
		if ( ! in_array( $name, array( 'archives', 'incoming', 'jobs' ), true ) ) {
			return new \WP_Error( 'cradle_migration_area', 'Unknown storage area.' );
		}
		if ( ! function_exists( 'cradle' ) || ! cradle() ) {
			return new \WP_Error( 'cradle_migration_core', 'Cradle core is not running.' );
		}
		return cradle()->storage()->module_dir( 'migration', $name );
	}

	/**
	 * Returns (and creates) a named subdirectory of an area after strict name validation.
	 *
	 * @return string|\WP_Error
	 */
	public static function subdir( string $area, string $name, bool $create = true ) {
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9-]{3,79}$/', $name ) ) {
			return new \WP_Error( 'cradle_migration_name', 'Invalid storage name.' );
		}
		$base = self::area( $area );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$dir = $base . '/' . $name;
		if ( ! Storage::path_within( $dir, $base ) ) {
			return new \WP_Error( 'cradle_migration_escape', 'Storage path escapes its area.' );
		}
		if ( $create && ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'cradle_migration_mkdir', 'Could not create a private working directory.' );
		}
		return $dir;
	}

	public static function job_dir( string $uuid ) {
		return self::subdir( 'jobs', $uuid );
	}

	/**
	 * Resolves a payload-relative location such as "incoming/<id>" or "archives/<id>".
	 *
	 * @return string|\WP_Error
	 */
	public static function resolve_rel( string $rel ) {
		if ( ! preg_match( '#^(archives|incoming)/([A-Za-z0-9][A-Za-z0-9-]{3,79})$#', $rel, $m ) ) {
			return new \WP_Error( 'cradle_migration_location', 'Invalid archive location.' );
		}
		return self::subdir( $m[1], $m[2], false );
	}

	/**
	 * Atomic JSON write (temporary file + rename).
	 *
	 * @return true|\WP_Error
	 */
	public static function write_json( string $path, $data ) {
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		if ( false === $json ) {
			return new \WP_Error( 'cradle_migration_json', 'Could not encode JSON.' );
		}
		$tmp = $path . '.tmp-' . wp_generate_password( 6, false, false );
		if ( false === file_put_contents( $tmp, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new \WP_Error( 'cradle_migration_write', 'Could not write a working file (disk full or permissions).' );
		}
		// PHP's rename() replaces an existing target on Windows as well as POSIX.
		if ( ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			wp_delete_file( $tmp );
			return new \WP_Error( 'cradle_migration_write', 'Could not replace a working file.' );
		}
		return true;
	}

	/**
	 * Reads a JSON file written by this plugin (or an archive manifest) with a size bound.
	 *
	 * @return array|\WP_Error
	 */
	public static function read_json( string $path, int $max_bytes = 67108864 ) {
		if ( ! is_file( $path ) ) {
			return new \WP_Error( 'cradle_migration_missing', 'A required file is missing: ' . basename( $path ) );
		}
		$size = filesize( $path );
		if ( false === $size || $size > $max_bytes ) {
			return new \WP_Error( 'cradle_migration_size', 'A JSON file exceeds its size limit: ' . basename( $path ) );
		}
		$data = json_decode( (string) file_get_contents( $path ), true, 64 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'cradle_migration_json', 'Invalid JSON in ' . basename( $path ) . '.' );
		}
		return $data;
	}

	/**
	 * Appends a JSON line; the file is truncated to $offset first so a retried step never duplicates lines.
	 *
	 * @return int|\WP_Error New file length.
	 */
	public static function append_lines( string $path, array $records, int $offset ) {
		$fh = fopen( $path, 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return new \WP_Error( 'cradle_migration_write', 'Could not open a working file.' );
		}
		ftruncate( $fh, $offset );
		fseek( $fh, $offset );
		foreach ( $records as $record ) {
			if ( false === fwrite( $fh, wp_json_encode( $record, JSON_UNESCAPED_SLASHES ) . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new \WP_Error( 'cradle_migration_write', 'Could not write a working file (disk full?).' );
			}
		}
		fflush( $fh );
		$len = ftell( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return (int) $len;
	}

	/**
	 * Reads JSON lines (own working files only).
	 *
	 * @return array<int,array>
	 */
	public static function read_lines( string $path, int $limit = 0 ): array {
		$out = array();
		if ( ! is_file( $path ) ) {
			return $out;
		}
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		while ( $fh && ( $line = fgets( $fh ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$row = json_decode( $line, true );
			if ( is_array( $row ) ) {
				$out[] = $row;
				if ( $limit && count( $out ) >= $limit ) {
					break;
				}
			}
		}
		if ( $fh ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return $out;
	}

	/**
	 * Removes a tree inside the migration module directory only.
	 */
	public static function remove_tree( string $dir ): bool {
		if ( ! function_exists( 'cradle' ) || ! cradle() ) {
			return false;
		}
		return cradle()->storage()->remove_tree( $dir, 'migration' );
	}

	public static function free_space( string $dir ) {
		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false === $free ? null : (float) $free;
	}

	public static function dir_size( string $dir ): int {
		$total = 0;
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() && ! $file->isLink() ) {
				$total += (int) $file->getSize();
			}
		}
		return $total;
	}
}
