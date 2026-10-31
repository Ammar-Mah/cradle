<?php
/**
 * File uploads: validation, private storage, metadata stripping and authorized streaming.
 *
 * Based on the upload checks of the Complaint Management System by WND (GPL-2.0-or-later):
 * genuine-upload check, size limits, every extension segment against a blocklist, the final
 * extension against an allowlist, and the real content type via wp_check_filetype_and_ext().
 * Changes after its review: files are stored in core private storage
 * (`module_dir( 'forms', 'uploads' )`) only when `is_protected()` is true; stored names are 32
 * random hex characters without extension (the original name is kept only as data); JPEG and
 * PNG files are re-encoded with GD to drop EXIF/metadata when possible.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Uploads {

	const SUBDIR = 'uploads';

	/** Images above this pixel count are stored unchanged (re-encoding could exhaust memory). */
	const MAX_REENCODE_PIXELS = 25000000;

	/**
	 * @return string|\WP_Error Private upload directory.
	 */
	public static function storage_dir() {
		if ( ! function_exists( 'cradle' ) || ! cradle() ) {
			return new \WP_Error( 'cradle_forms_storage', 'Cradle core is not available.' );
		}
		return cradle()->storage()->module_dir( Module::ID, self::SUBDIR );
	}

	/**
	 * Whether private storage is proven protected (outside the web root, or an HTTP probe was denied).
	 */
	public static function storage_protected(): bool {
		return function_exists( 'cradle' ) && cradle() && cradle()->storage()->is_protected();
	}

	/**
	 * Flattens $_FILES['cradle_files'] (inputs named cradle_files[field][]) into field => list.
	 * Inputs left empty (UPLOAD_ERR_NO_FILE) are dropped.
	 *
	 * @param mixed $files $_FILES['cradle_files'].
	 * @return array<string,array<int,array{name:string,tmp_name:string,error:int,size:int}>>
	 */
	public static function normalize( $files ): array {
		$out = array();
		if ( ! is_array( $files ) || ! isset( $files['name'] ) || ! is_array( $files['name'] ) ) {
			return $out;
		}
		foreach ( $files['name'] as $field => $names ) {
			$field = (string) $field;
			if ( ! preg_match( Field_Types::NAME_PATTERN, $field ) ) {
				continue;
			}
			foreach ( array_slice( (array) $names, 0, Field_Types::MAX_FILES + 1, true ) as $i => $name ) {
				$error = isset( $files['error'][ $field ][ $i ] ) ? (int) $files['error'][ $field ][ $i ] : UPLOAD_ERR_NO_FILE;
				if ( UPLOAD_ERR_NO_FILE === $error ) {
					continue;
				}
				$out[ $field ][] = array(
					'name'     => is_string( $name ) ? $name : '',
					'tmp_name' => isset( $files['tmp_name'][ $field ][ $i ] ) ? (string) $files['tmp_name'][ $field ][ $i ] : '',
					'error'    => $error,
					'size'     => isset( $files['size'][ $field ][ $i ] ) ? (int) $files['size'][ $field ][ $i ] : 0,
				);
			}
		}
		return $out;
	}

	/**
	 * Original file name as display data only (never used as a path).
	 */
	public static function display_name( string $name ): string {
		$name = wp_basename( str_replace( '\\', '/', $name ) );
		$name = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', '', $name );
		$name = mb_substr( sanitize_text_field( $name ), 0, 255 );
		return '' !== $name ? $name : 'file';
	}

	/**
	 * Validates one upload against a file field. Returns an error message or null.
	 */
	public static function validate( array $file, array $field ): ?string {
		$shown = self::display_name( (string) $file['name'] );
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true )
				/* translators: 1: file name, 2: maximum size such as "5 MB" */
				? sprintf( __( '“%1$s” is larger than %2$s.', 'cradle-forms' ), $shown, size_format( Field_Types::file_max_bytes( $field ) ) )
				/* translators: %s: file name */
				: sprintf( __( '“%s” could not be uploaded. Please try again.', 'cradle-forms' ), $shown );
		}
		$tmp = (string) $file['tmp_name'];
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return __( 'The file upload was not valid. Please try again.', 'cradle-forms' );
		}
		$size = max( (int) $file['size'], (int) filesize( $tmp ) );
		if ( $size <= 0 ) {
			/* translators: %s: file name */
			return sprintf( __( '“%s” is empty.', 'cradle-forms' ), $shown );
		}
		if ( $size > Field_Types::file_max_bytes( $field ) ) {
			/* translators: 1: file name, 2: maximum size such as "5 MB" */
			return sprintf( __( '“%1$s” is larger than %2$s.', 'cradle-forms' ), $shown, size_format( Field_Types::file_max_bytes( $field ) ) );
		}

		$type_error = sprintf(
			/* translators: 1: file name, 2: list of allowed file types */
			__( '“%1$s” is not an allowed file type. Allowed: %2$s.', 'cradle-forms' ),
			$shown,
			strtoupper( implode( ', ', $field['accept'] ) )
		);
		$base = strtolower( wp_basename( str_replace( '\\', '/', (string) $file['name'] ) ) );
		if ( '' === $base || preg_match( '/[\x00-\x1F\x7F]/', $base ) ) {
			return $type_error;
		}
		$segments = explode( '.', $base );
		array_shift( $segments );
		if ( ! $segments ) {
			return $type_error;
		}
		foreach ( $segments as $segment ) {
			// Every extension segment, so "cv.php.pdf" or "cv.phtml .pdf" is refused.
			if ( in_array( trim( $segment, " \t_-" ), Settings_Section::BLOCKED_EXTENSIONS, true ) ) {
				return $type_error;
			}
		}
		$ext = (string) end( $segments );
		if ( ! in_array( $ext, (array) $field['accept'], true ) || ! in_array( $ext, Settings_Section::allowed_extensions(), true ) ) {
			return $type_error;
		}
		$check = wp_check_filetype_and_ext( $tmp, sanitize_file_name( $base ), self::mime_map( (array) $field['accept'] ) );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) || ! empty( $check['proper_filename'] ) || strtolower( (string) $check['ext'] ) !== $ext || ! self::signature_matches( $tmp, $ext ) ) {
			return $type_error;
		}
		return null;
	}

	/**
	 * Leading bytes of well-known types. WordPress accepts generic binary content
	 * (application/octet-stream) for application/* extensions; these types must also start with
	 * their signature. Extensions without an entry are checked by wp_check_filetype_and_ext() only.
	 */
	private static function signature_matches( string $path, string $ext ): bool {
		$signatures = array(
			'pdf'  => array( '%PDF-' ),
			'png'  => array( "\x89PNG\r\n\x1a\n" ),
			'jpg'  => array( "\xFF\xD8\xFF" ),
			'jpeg' => array( "\xFF\xD8\xFF" ),
			'gif'  => array( 'GIF87a', 'GIF89a' ),
			'webp' => array( 'RIFF' ),
			'docx' => array( "PK\x03\x04" ),
			'xlsx' => array( "PK\x03\x04" ),
			'pptx' => array( "PK\x03\x04" ),
			'odt'  => array( "PK\x03\x04" ),
			'ods'  => array( "PK\x03\x04" ),
			'zip'  => array( "PK\x03\x04" ),
			'doc'  => array( "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" ),
			'xls'  => array( "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" ),
			'rtf'  => array( '{\\rtf' ),
		);
		if ( ! isset( $signatures[ $ext ] ) ) {
			return true;
		}
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return false;
		}
		$head = (string) fread( $handle, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		foreach ( $signatures[ $ext ] as $signature ) {
			if ( 0 === strncmp( $head, $signature, strlen( $signature ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Extension => MIME map restricted to the given extensions.
	 *
	 * @return array<string,string>
	 */
	public static function mime_map( array $extensions ): array {
		$map = array();
		foreach ( wp_get_mime_types() as $exts => $mime ) {
			foreach ( explode( '|', $exts ) as $ext ) {
				if ( in_array( $ext, $extensions, true ) ) {
					$map[ $ext ] = $mime;
				}
			}
		}
		return $map;
	}

	/**
	 * Moves a validated upload into private storage and records it for an entry.
	 *
	 * @return array|\WP_Error File row.
	 */
	public static function store( array $file, array $field, int $entry_id ) {
		global $wpdb;
		$unavailable = new \WP_Error( 'cradle_forms_storage', __( 'Your file could not be stored. Please try again later.', 'cradle-forms' ), array( 'status' => 500 ) );
		$dir         = self::storage_dir();
		if ( is_wp_error( $dir ) || ! self::storage_protected() ) {
			return $unavailable;
		}
		$size = (int) filesize( (string) $file['tmp_name'] );
		if ( function_exists( 'disk_free_space' ) ) {
			$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled by open_basedir.

			/**
			 * Free disk space (bytes) that must remain after storing an upload.
			 *
			 * @param int $reserve Default 100 MB.
			 */
			$reserve = (int) apply_filters( 'cradle_forms/disk_reserve_bytes', 100 * MB_IN_BYTES );
			if ( false !== $free && $free - $size < $reserve ) {
				return $unavailable;
			}
		}
		$stored = bin2hex( random_bytes( 16 ) );
		$target = $dir . '/' . $stored;
		if ( ! \Cradle\Core\Storage::path_within( $target, $dir ) || ! move_uploaded_file( (string) $file['tmp_name'], $target ) ) {
			return $unavailable;
		}
		chmod( $target, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		$base     = sanitize_file_name( strtolower( wp_basename( str_replace( '\\', '/', (string) $file['name'] ) ) ) );
		$check    = wp_check_filetype( $base, self::mime_map( (array) $field['accept'] ) );
		$mime     = $check['type'] ? (string) $check['type'] : 'application/octet-stream';
		$stripped = self::strip_metadata( $target, $mime );
		clearstatcache( true, $target );
		$row = array(
			'entry_id'          => $entry_id,
			'field_name'        => $field['name'],
			'stored_name'       => $stored,
			'original_name'     => self::display_name( (string) $file['name'] ),
			'mime'              => $mime,
			'size'              => (int) filesize( $target ),
			'metadata_stripped' => $stripped ? 1 : 0,
			'created_at'        => gmdate( 'Y-m-d H:i:s' ),
		);
		if ( ! $wpdb->insert( Schema::table( 'files' ), $row, array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_delete_file( $target );
			return $unavailable;
		}
		$row['id'] = (int) $wpdb->insert_id;
		return $row;
	}

	/**
	 * Re-encodes JPEG/PNG with GD, which writes no EXIF, XMP or text chunks. JPEG orientation is
	 * applied first when the exif extension is available. Other types keep their metadata
	 * (documented in docs/security.md).
	 */
	public static function strip_metadata( string $path, string $mime ): bool {
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) || ! function_exists( 'imagecreatefromjpeg' ) || ! function_exists( 'imagecreatefrompng' ) ) {
			return false;
		}
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid images return false.
		if ( ! $info || $info[0] * $info[1] > self::MAX_REENCODE_PIXELS ) {
			return false;
		}
		wp_raise_memory_limit( 'image' );
		$jpeg  = 'image/jpeg' === $mime;
		$image = $jpeg ? @imagecreatefromjpeg( $path ) : @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $image ) {
			return false;
		}
		if ( $jpeg && function_exists( 'exif_read_data' ) ) {
			$exif   = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$rotate = array(
				3 => 180,
				6 => -90,
				8 => 90,
			)[ (int) ( is_array( $exif ) ? ( $exif['Orientation'] ?? 1 ) : 1 ) ] ?? 0;
			if ( $rotate ) {
				$rotated = imagerotate( $image, $rotate, 0 );
				if ( $rotated ) {
					imagedestroy( $image );
					$image = $rotated;
				}
			}
		}
		$tmp = $path . '.tmp';
		if ( $jpeg ) {
			$ok = imagejpeg( $image, $tmp, 90 );
		} else {
			imagealphablending( $image, false );
			imagesavealpha( $image, true );
			$ok = imagepng( $image, $tmp, 6 );
		}
		imagedestroy( $image );
		if ( ! $ok || ! is_file( $tmp ) || filesize( $tmp ) <= 0 ) {
			wp_delete_file( $tmp );
			return false;
		}
		return rename( $tmp, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
	}

	/**
	 * Absolute path of a stored file, or null when the name is malformed or escapes storage.
	 */
	public static function path( string $stored_name ): ?string {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $stored_name ) ) {
			return null;
		}
		$dir = self::storage_dir();
		if ( is_wp_error( $dir ) ) {
			return null;
		}
		$path = $dir . '/' . $stored_name;
		return \Cradle\Core\Storage::path_within( $path, $dir ) ? $path : null;
	}

	public static function delete_stored( string $stored_name ) {
		$path = self::path( $stored_name );
		if ( $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Sends a stored file as an attachment and exits. Callers MUST authorize first.
	 */
	public static function stream( array $row ) {
		$path = self::path( (string) $row['stored_name'] );
		if ( ! $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'The file is no longer available.', 'cradle-forms' ), '', array( 'response' => 404 ) );
		}
		$mime = preg_match( '#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', (string) $row['mime'] ) ? (string) $row['mime'] : 'application/octet-stream';
		$name = (string) $row['original_name'];
		$safe = trim( (string) preg_replace( '/[^A-Za-z0-9._-]+/', '_', remove_accents( $name ) ), '._' );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . ( '' !== $safe ? $safe : 'download' ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; sandbox" );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $handle ) {
			while ( ! feof( $handle ) ) {
				echo fread( $handle, 65536 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.WP.AlternativeFunctions.file_system_operations_fread -- binary file body.
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		exit;
	}
}
