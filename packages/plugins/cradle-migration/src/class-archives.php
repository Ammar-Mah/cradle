<?php
/**
 * Archive listing, protected downloads and deletion.
 *
 * Downloads never use public URLs: admin-post.php streams a file only for a user
 * with cradle_export_site who owns the archive (or can manage options), with a
 * valid nonce and an HMAC-signed link that expires after 10 minutes.
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

use Cradle\Core\Storage;

defined( 'ABSPATH' ) || exit;

final class Archives {

	const LINK_TTL = 600;

	/**
	 * @return array<int,array> Newest first.
	 */
	public static function all(): array {
		$area = Workspace::area( 'archives' );
		if ( is_wp_error( $area ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) glob( $area . '/*/*.manifest.json' ) as $path ) {
			$id = basename( (string) $path, '.manifest.json' );
			if ( ! preg_match( Manifest::ID_PATTERN, $id ) || basename( dirname( (string) $path ) ) !== $id ) {
				continue;
			}
			// Still being bundled into its .cradle file by a running export.
			if ( is_file( dirname( (string) $path ) . '/' . Container::file_name( $id ) . '.partial' ) ) {
				continue;
			}
			$summary = self::summary( dirname( (string) $path ), $id );
			if ( $summary ) {
				$out[] = $summary;
			}
		}
		usort( $out, static fn( $a, $b ) => strcmp( $b['createdAt'], $a['createdAt'] ) );
		return $out;
	}

	/**
	 * Summary of one archive. `file`/`fileBytes` describe its `.cradle` file ('' for an archive
	 * exported before 0.2.0, which only has the manifest and separate parts).
	 */
	public static function summary( string $dir, string $id ) {
		$container = $dir . '/' . Container::file_name( $id );
		$file      = is_file( $container ) ? array(
			'file'      => Container::file_name( $id ),
			'fileBytes' => (int) filesize( $container ),
		) : array(
			'file'      => '',
			'fileBytes' => 0,
		);
		$cache     = $dir . '/summary.json';
		if ( is_file( $cache ) ) {
			$s = json_decode( (string) file_get_contents( $cache ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( is_array( $s ) ) {
				return $file + $s;
			}
		}
		$m = Workspace::read_json( $dir . '/' . Manifest::manifest_name( $id ) );
		if ( is_wp_error( $m ) ) {
			return null;
		}
		$s = array(
			'id'          => $id,
			'createdAt'   => (string) ( $m['createdAt'] ?? '' ),
			'mode'        => (string) ( $m['mode'] ?? '' ),
			'source'      => (string) ( $m['source']['homeUrl'] ?? '' ),
			'consistency' => (string) ( $m['consistency']['strategy'] ?? 'none' ),
			'parts'       => array_map( static fn( $p ) => array( 'name' => (string) $p['name'], 'size' => (int) $p['size'] ), (array) ( $m['parts'] ?? array() ) ),
			'bytes'       => (int) ( $m['totals']['partBytes'] ?? 0 ),
			'files'       => (int) ( $m['totals']['files'] ?? 0 ),
			'rows'        => (int) ( $m['totals']['rows'] ?? 0 ),
			'unsupported' => count( (array) ( $m['unsupported'] ?? array() ) ),
			'warnings'    => count( (array) ( $m['warnings'] ?? array() ) ),
			'owner'       => self::owner( $id ),
		);
		Workspace::write_json( $cache, $s );
		return $file + $s;
	}

	/**
	 * Download name of a `.cradle` file: the site address plus the export time, e.g.
	 * `example-com-20260929T101500Z-3f9c2a1b.cradle` (the importer ignores the name).
	 */
	public static function download_name( array $summary ): string {
		$host = (string) wp_parse_url( (string) ( $summary['source'] ?? '' ), PHP_URL_HOST ) . (string) wp_parse_url( (string) ( $summary['source'] ?? '' ), PHP_URL_PATH );
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $host ) ), '-' );
		$slug = '' !== $slug ? substr( $slug, 0, 40 ) : 'site';
		return $slug . '-' . preg_replace( '/^cradle-/', '', (string) $summary['id'] ) . Container::EXT;
	}

	/**
	 * Owner of the export job that produced an archive (0 when unknown).
	 */
	public static function owner( string $id ): int {
		global $wpdb;
		$table = \Cradle\Core\Schema::tables()['jobs'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$owner = $wpdb->get_var( $wpdb->prepare( 'SELECT owner_id FROM ' . Db::ident( $table ) . " WHERE type = 'migration/export' AND payload LIKE %s ORDER BY id DESC LIMIT 1", '%' . $wpdb->esc_like( '"archiveId":"' . $id . '"' ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $owner;
	}

	public static function user_can( string $id ): bool {
		if ( ! current_user_can( 'cradle_export_site' ) ) {
			return false;
		}
		return current_user_can( 'manage_options' ) || self::owner( $id ) === get_current_user_id();
	}

	private static function signature( int $user, string $id, string $file, int $exp ): string {
		return hash_hmac( 'sha256', $user . '|' . $id . '|' . $file . '|' . $exp, wp_salt( 'auth' ) );
	}

	public static function download_url( string $id, string $file ): string {
		$exp = time() + self::LINK_TTL;
		return add_query_arg(
			array(
				'action'   => 'cradle_migration_download',
				'archive'  => $id,
				'file'     => $file,
				'exp'      => $exp,
				'sig'      => self::signature( get_current_user_id(), $id, $file, $exp ),
				'_wpnonce' => wp_create_nonce( 'cradle_migration_download' ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * admin-post.php?action=cradle_migration_download (GET, read-only).
	 */
	public static function handle_download() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below.
		$id   = isset( $_GET['archive'] ) ? sanitize_text_field( wp_unslash( $_GET['archive'] ) ) : '';
		$file = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
		$exp  = isset( $_GET['exp'] ) ? absint( $_GET['exp'] ) : 0;
		$sig  = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';
		// phpcs:enable
		if ( ! is_user_logged_in() || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'cradle_migration_download' ) ) {
			wp_die( esc_html__( 'This download link is not valid.', 'cradle-migration' ), 403 );
		}
		if ( ! preg_match( Manifest::ID_PATTERN, $id ) || ! self::user_can( $id ) ) {
			wp_die( esc_html__( 'You are not allowed to download this archive.', 'cradle-migration' ), 403 );
		}
		if ( $exp < time() || $exp > time() + self::LINK_TTL + 5 || ! hash_equals( self::signature( get_current_user_id(), $id, $file, $exp ), $sig ) ) {
			wp_die( esc_html__( 'This download link expired. Reload the archive list for a new one.', 'cradle-migration' ), 403 );
		}
		$dir = Workspace::subdir( 'archives', $id, false );
		if ( is_wp_error( $dir ) ) {
			wp_die( esc_html__( 'Archive not found.', 'cradle-migration' ), 404 );
		}
		$summary = self::summary( $dir, $id );
		$allowed = array( Manifest::manifest_name( $id ), Container::file_name( $id ) );
		foreach ( (array) ( $summary['parts'] ?? array() ) as $part ) {
			$allowed[] = $part['name'];
		}
		$path = $dir . '/' . $file;
		if ( ! in_array( $file, $allowed, true ) || ! Storage::path_within( $path, $dir ) || ! is_file( $path ) || is_link( $path ) ) {
			wp_die( esc_html__( 'Archive file not found.', 'cradle-migration' ), 404 );
		}
		cradle_log_activity( 'migration', 'archive_downloaded', 'Archive file downloaded.', array( 'archive' => $id, 'file' => $file ) );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$is_container = Container::file_name( $id ) === $file;
		nocache_headers();
		// A .cradle file is sent as a generic binary so browsers keep its extension.
		header( 'Content-Type: ' . ( $is_container ? 'application/octet-stream' : ( str_ends_with( $file, '.json' ) ? 'application/json' : 'application/zip' ) ) );
		header( 'Content-Disposition: attachment; filename="' . ( $is_container && is_array( $summary ) ? self::download_name( $summary ) : $file ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-store, private' );
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		while ( $fh && ! feof( $fh ) ) {
			echo fread( $fh, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.WP.AlternativeFunctions.file_system_operations_fread -- binary file stream.
			flush();
		}
		if ( $fh ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		exit;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function delete( string $id ) {
		if ( ! preg_match( Manifest::ID_PATTERN, $id ) || ! self::user_can( $id ) ) {
			return new \WP_Error( 'cradle_migration_forbidden', __( 'You are not allowed to delete this archive.', 'cradle-migration' ) );
		}
		$dir = Workspace::subdir( 'archives', $id, false );
		if ( is_wp_error( $dir ) || ! is_dir( $dir ) ) {
			return new \WP_Error( 'cradle_migration_missing', 'Archive not found.' );
		}
		Workspace::remove_tree( $dir );
		cradle_log_activity( 'migration', 'archive_deleted', 'Archive deleted.', array( 'archive' => $id ) );
		return true;
	}
}
