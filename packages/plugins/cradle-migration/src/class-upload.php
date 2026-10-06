<?php
/**
 * Chunked, resumable, idempotent upload of one `.cradle` file into private storage.
 *
 * A session records the expected file (stored as `archive.cradle`, whatever the
 * visitor's file was called) and a SHA-256 per received chunk. Chunks are written at
 * their fixed offset, so a repeated submission of the same chunk is acknowledged as a
 * duplicate without writing; a different payload for an already-received chunk is
 * refused. Selecting the same file again (name, size, modification time) resumes the
 * owner's unfinished session.
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

defined( 'ABSPATH' ) || exit;

final class Upload {

	const TTL = 172800;

	public static function chunk_size(): int {
		$limit = min( wp_convert_hr_to_bytes( (string) ini_get( 'upload_max_filesize' ) ), wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ) );
		$size  = $limit > 0 ? $limit - 65536 : 1048576;
		return (int) max( 262144, min( 8388608, $size ) );
	}

	/**
	 * @param array $files list with exactly one {name, size, modified} for a `.cradle` file.
	 * @return array|\WP_Error
	 */
	public static function begin( array $files, int $user ) {
		$protected = Workspace::ensure_protected();
		if ( is_wp_error( $protected ) ) {
			return $protected;
		}
		$limits = Manifest::limits();
		$file   = 1 === count( $files ) && is_array( reset( $files ) ) ? reset( $files ) : null;
		if ( ! $file ) {
			return new \WP_Error( 'cradle_migration_upload', __( 'Choose one .cradle file.', 'cradle-migration' ) );
		}
		$name = (string) ( $file['name'] ?? '' );
		$size = (int) ( $file['size'] ?? -1 );
		if ( ! preg_match( '/\.cradle$/i', $name ) ) {
			return new \WP_Error( 'cradle_migration_upload', __( 'This is not a .cradle file. Export the other site with Cradle Migration and choose the .cradle file it downloads.', 'cradle-migration' ) );
		}
		if ( $size < 100 || $size > (int) $limits['maxTotalBytes'] ) {
			return new \WP_Error( 'cradle_migration_upload', __( 'The size of this .cradle file is not acceptable.', 'cradle-migration' ) );
		}
		$label    = mb_substr( sanitize_file_name( $name ), 0, 120 );
		$modified = max( 0, (int) ( $file['modified'] ?? 0 ) );
		$area     = Workspace::area( 'incoming' );
		if ( is_wp_error( $area ) ) {
			return $area;
		}
		$free = Workspace::free_space( $area );
		if ( null !== $free && $size > $free * 0.3 ) {
			return new \WP_Error( 'cradle_migration_space', __( 'Not enough free disk space to receive, unpack, stage and back up this file.', 'cradle-migration' ) );
		}
		// Resume an unfinished session of this user for the same file.
		foreach ( (array) glob( $area . '/up-*/session.json' ) as $path ) {
			$s = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( is_array( $s ) && (int) ( $s['owner'] ?? 0 ) === $user && empty( $s['finished'] ) && (int) ( $s['expires'] ?? 0 ) > time()
				&& ( $s['label'] ?? '' ) === $label && (int) ( $s['modified'] ?? -1 ) === $modified && (int) ( $s['files'][0]['size'] ?? -1 ) === $size ) {
				return self::view( $s, true );
			}
		}
		$id  = 'up-' . strtolower( wp_generate_password( 20, false, false ) );
		$dir = Workspace::subdir( 'incoming', $id );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$chunk   = self::chunk_size();
		$session = array(
			'id'        => $id,
			'owner'     => $user,
			'created'   => time(),
			'expires'   => time() + self::TTL,
			'label'     => $label,
			'modified'  => $modified,
			'chunkSize' => $chunk,
			'files'     => array(
				array(
					'name'   => Container::UPLOAD,
					'size'   => $size,
					'chunks' => max( 1, (int) ceil( $size / $chunk ) ),
				),
			),
			'received'  => array( array() ),
			'finished'  => false,
		);
		$ok = Workspace::write_json( $dir . '/session.json', $session );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return self::view( $session, false );
	}

	private static function view( array $s, bool $resumed ): array {
		$files = array();
		foreach ( $s['files'] as $i => $f ) {
			$files[] = $f + array( 'received' => array_map( 'intval', array_keys( (array) ( $s['received'][ $i ] ?? array() ) ) ) );
		}
		return array(
			'session'   => $s['id'],
			'chunkSize' => (int) $s['chunkSize'],
			'files'     => $files,
			'resumed'   => $resumed,
			'finished'  => ! empty( $s['finished'] ),
		);
	}

	/**
	 * Runs $fn with the session loaded under an exclusive file lock; $fn may return a modified session.
	 *
	 * @return mixed|\WP_Error
	 */
	private static function with_session( string $id, int $user, callable $fn ) {
		if ( ! preg_match( '/^up-[a-z0-9]{20}$/', $id ) ) {
			return new \WP_Error( 'cradle_migration_upload', 'Unknown upload session.' );
		}
		$dir = Workspace::subdir( 'incoming', $id, false );
		if ( is_wp_error( $dir ) || ! is_file( $dir . '/session.json' ) ) {
			return new \WP_Error( 'cradle_migration_upload', 'Unknown upload session.' );
		}
		$lock = fopen( $dir . '/session.lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $lock || ! flock( $lock, LOCK_EX ) ) {
			return new \WP_Error( 'cradle_migration_upload', 'The upload session is busy.' );
		}
		try {
			$s = json_decode( (string) file_get_contents( $dir . '/session.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! is_array( $s ) || (int) $s['owner'] !== $user ) {
				return new \WP_Error( 'cradle_migration_upload', 'Unknown upload session.' );
			}
			if ( (int) $s['expires'] < time() ) {
				return new \WP_Error( 'cradle_migration_upload', 'The upload session expired. Start again.' );
			}
			$result = $fn( $s, $dir );
			if ( is_array( $result ) && isset( $result['__save'] ) ) {
				$ok = Workspace::write_json( $dir . '/session.json', $result['__save'] );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				unset( $result['__save'] );
			}
			return $result;
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * Stores one chunk.
	 *
	 * @return array|\WP_Error
	 */
	public static function chunk( string $id, int $file, int $index, string $data, int $user ) {
		return self::with_session(
			$id,
			$user,
			static function ( array $s, string $dir ) use ( $file, $index, $data ) {
				if ( ! empty( $s['finished'] ) ) {
					return new \WP_Error( 'cradle_migration_upload', 'This upload is already complete.' );
				}
				if ( ! isset( $s['files'][ $file ] ) || $index < 0 || $index >= $s['files'][ $file ]['chunks'] ) {
					return new \WP_Error( 'cradle_migration_upload', 'Chunk out of range.' );
				}
				$f        = $s['files'][ $file ];
				$chunk    = (int) $s['chunkSize'];
				$offset   = $index * $chunk;
				$expected = min( $chunk, $f['size'] - $offset );
				if ( strlen( $data ) !== $expected ) {
					return new \WP_Error( 'cradle_migration_upload', 'Chunk has the wrong length.' );
				}
				$hash = hash( 'sha256', $data );
				$prev = $s['received'][ $file ][ (string) $index ] ?? null;
				if ( null !== $prev ) {
					if ( hash_equals( $prev, $hash ) ) {
						return array(
							'duplicate' => true,
							'received'  => count( $s['received'][ $file ] ),
						);
					}
					return new \WP_Error( 'cradle_migration_upload_conflict', 'A different payload was already stored for this chunk; restart the upload.' );
				}
				$path = $dir . '/' . $f['name'] . '.uploading';
				$fh   = fopen( $path, 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				if ( ! $fh || 0 !== fseek( $fh, $offset ) || fwrite( $fh, $data ) !== $expected ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					if ( $fh ) {
						fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					}
					return new \WP_Error( 'cradle_migration_upload', 'Could not store the chunk (disk full?).' );
				}
				fflush( $fh );
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$s['received'][ $file ][ (string) $index ] = $hash;
				return array(
					'duplicate' => false,
					'received'  => count( $s['received'][ $file ] ),
					'__save'    => $s,
				);
			}
		);
	}

	public static function status( string $id, int $user ) {
		return self::with_session(
			$id,
			$user,
			static function ( array $s ) {
				return self::view( $s, true );
			}
		);
	}

	/**
	 * Verifies completeness, moves the file into place and creates the one-step import job.
	 *
	 * @return array|\WP_Error
	 */
	public static function finish( string $id, int $user, array $options = array() ) {
		$result = self::with_session(
			$id,
			$user,
			static function ( array $s, string $dir ) {
				foreach ( $s['files'] as $i => $f ) {
					if ( count( (array) ( $s['received'][ $i ] ?? array() ) ) !== (int) $f['chunks'] ) {
						return new \WP_Error( 'cradle_migration_upload', sprintf( '%s is incomplete; resume the upload.', $f['name'] ) );
					}
				}
				foreach ( $s['files'] as $f ) {
					$tmp = $dir . '/' . $f['name'] . '.uploading';
					if ( is_file( $tmp ) ) {
						clearstatcache( true, $tmp );
						if ( filesize( $tmp ) !== (int) $f['size'] ) {
							return new \WP_Error( 'cradle_migration_upload', sprintf( '%s has the wrong size after upload.', $f['name'] ) );
						}
						if ( ! rename( $tmp, $dir . '/' . $f['name'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
							return new \WP_Error( 'cradle_migration_upload', 'Could not finish the upload.' );
						}
					} elseif ( ! is_file( $dir . '/' . $f['name'] ) ) {
						return new \WP_Error( 'cradle_migration_upload', sprintf( '%s is missing.', $f['name'] ) );
					}
				}
				$s['finished'] = true;
				return array(
					'label'  => (string) ( $s['label'] ?? '' ),
					'__save' => $s,
				);
			}
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return Importer::create(
			array(
				'location'  => 'incoming/' . $id,
				'container' => 'incoming/' . $id . '/' . Container::UPLOAD,
				'options'   => $options,
				'auto'      => true,
			)
		);
	}

	/**
	 * Copies a local archive into private storage for a CLI import: a `.cradle` file, or the
	 * manifest of an unpacked archive (its parts are copied from the same folder).
	 *
	 * @return array{location:string,manifest?:string,container?:string}|\WP_Error
	 */
	public static function import_local( string $manifest_path ) {
		$manifest_path = wp_normalize_path( $manifest_path );
		$is_container  = (bool) preg_match( '/\.cradle$/i', $manifest_path );
		if ( ( ! $is_container && ! preg_match( '/^([A-Za-z0-9][A-Za-z0-9-]{7,63})\.manifest\.json$/', basename( $manifest_path ), $m ) ) || ! is_file( $manifest_path ) || is_link( $manifest_path ) ) {
			return new \WP_Error( 'cradle_migration_manifest', 'Pass the path of a .cradle file (or of an <archiveId>.manifest.json file).' );
		}
		$protected = Workspace::ensure_protected();
		if ( is_wp_error( $protected ) ) {
			return $protected;
		}
		$id  = 'cli-' . strtolower( wp_generate_password( 20, false, false ) );
		$dir = Workspace::subdir( 'incoming', $id );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		if ( $is_container ) {
			if ( ! copy( $manifest_path, $dir . '/' . Container::UPLOAD ) ) {
				return new \WP_Error( 'cradle_migration_copy', 'Could not copy the .cradle file (disk full?).' );
			}
			return array(
				'location'  => 'incoming/' . $id,
				'container' => 'incoming/' . $id . '/' . Container::UPLOAD,
			);
		}
		$src = dirname( $manifest_path );
		foreach ( (array) glob( $src . '/' . $m[1] . '.*' ) as $file ) {
			$name = basename( (string) $file );
			if ( ! preg_match( '/^' . preg_quote( $m[1], '/' ) . '\.(manifest\.json|part-[0-9]{4}\.zip)$/', $name ) || is_link( (string) $file ) ) {
				continue;
			}
			if ( ! copy( (string) $file, $dir . '/' . $name ) ) {
				return new \WP_Error( 'cradle_migration_copy', 'Could not copy ' . $name . ' (disk full?).' );
			}
		}
		return array(
			'location' => 'incoming/' . $id,
			'manifest' => basename( $manifest_path ),
		);
	}

	/**
	 * Removes finished or expired upload sessions older than the TTL.
	 */
	public static function sweep(): int {
		$area = Workspace::area( 'incoming' );
		if ( is_wp_error( $area ) ) {
			return 0;
		}
		$n = 0;
		foreach ( (array) glob( $area . '/up-*/session.json' ) as $path ) {
			$s = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( is_array( $s ) && empty( $s['finished'] ) && (int) $s['expires'] < time() ) {
				Workspace::remove_tree( dirname( (string) $path ) );
				++$n;
			}
		}
		return $n;
	}
}
