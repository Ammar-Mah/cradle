<?php
/**
 * Chunked, resumable, idempotent archive uploads into private storage.
 *
 * A session records the expected files (name + size) and a SHA-256 per received
 * chunk. Chunks are written at their fixed offset, so a repeated submission of the
 * same chunk rewrites identical bytes (and is acknowledged as a duplicate without
 * writing); a different payload for an already-received chunk is refused.
 * Re-selecting the same files resumes the owner's unfinished session.
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
	 * @param array $files list of {name,size}.
	 * @return array|\WP_Error
	 */
	public static function begin( array $files, int $user ) {
		$protected = Workspace::ensure_protected();
		if ( is_wp_error( $protected ) ) {
			return $protected;
		}
		$limits   = Manifest::limits();
		$manifest = null;
		$norm     = array();
		$total    = 0;
		if ( ! $files || count( $files ) > $limits['maxParts'] + 1 ) {
			return new \WP_Error( 'cradle_migration_upload', 'Select the archive manifest and all of its parts.' );
		}
		foreach ( $files as $f ) {
			$name = is_array( $f ) ? (string) ( $f['name'] ?? '' ) : '';
			$size = is_array( $f ) ? (int) ( $f['size'] ?? -1 ) : -1;
			if ( preg_match( '/^([A-Za-z0-9][A-Za-z0-9-]{7,63})\.manifest\.json$/', $name, $m ) ) {
				if ( null !== $manifest ) {
					return new \WP_Error( 'cradle_migration_upload', 'Select exactly one manifest.' );
				}
				$manifest = $m[1];
				if ( $size < 2 || $size > $limits['maxManifestBytes'] ) {
					return new \WP_Error( 'cradle_migration_upload', 'The manifest size is not acceptable.' );
				}
			} elseif ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9-]{7,63}\.part-[0-9]{4}\.zip$/', $name ) || $size < 22 || $size > $limits['maxPartBytes'] ) {
				return new \WP_Error( 'cradle_migration_upload', sprintf( '"%s" is not a Cradle archive file (expected <id>.manifest.json and <id>.part-NNNN.zip).', Path_Guard::printable( $name ) ) );
			}
			if ( isset( $norm[ $name ] ) ) {
				return new \WP_Error( 'cradle_migration_upload', 'A file was selected twice.' );
			}
			$norm[ $name ] = $size;
			$total        += $size;
		}
		if ( null === $manifest ) {
			return new \WP_Error( 'cradle_migration_upload', 'The archive manifest (<id>.manifest.json) is required.' );
		}
		foreach ( array_keys( $norm ) as $name ) {
			if ( 0 !== strpos( $name, $manifest . '.' ) ) {
				return new \WP_Error( 'cradle_migration_upload', 'All files must belong to the same archive.' );
			}
		}
		ksort( $norm );
		$area = Workspace::area( 'incoming' );
		if ( is_wp_error( $area ) ) {
			return $area;
		}
		$free = Workspace::free_space( $area );
		if ( null !== $free && $total > $free * 0.45 ) {
			return new \WP_Error( 'cradle_migration_space', 'Not enough free disk space to receive, stage and back up this archive.' );
		}
		// Resume an unfinished session of this user with the same files.
		foreach ( (array) glob( $area . '/up-*/session.json' ) as $path ) {
			$s = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( is_array( $s ) && (int) $s['owner'] === $user && empty( $s['finished'] ) && (int) $s['expires'] > time() && $s['files'] === self::shape( $norm, (int) $s['chunkSize'], $s['files'] ) ) {
				return self::view( $s, true );
			}
		}
		$id  = 'up-' . strtolower( wp_generate_password( 20, false, false ) );
		$dir = Workspace::subdir( 'incoming', $id );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$session = array(
			'id'        => $id,
			'owner'     => $user,
			'created'   => time(),
			'expires'   => time() + self::TTL,
			'archiveId' => $manifest,
			'chunkSize' => self::chunk_size(),
			'files'     => array(),
			'received'  => array(),
			'finished'  => false,
		);
		$session['files'] = self::shape( $norm, $session['chunkSize'], array() );
		foreach ( $session['files'] as $i => $f ) {
			$session['received'][ $i ] = array();
		}
		$ok = Workspace::write_json( $dir . '/session.json', $session );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return self::view( $session, false );
	}

	private static function shape( array $norm, int $chunk, array $unused ): array {
		$out = array();
		foreach ( $norm as $name => $size ) {
			$out[] = array(
				'name'   => $name,
				'size'   => $size,
				'chunks' => max( 1, (int) ceil( $size / $chunk ) ),
			);
		}
		return $out;
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
	 * Verifies completeness, moves files into place and creates the import job.
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
					'archiveId' => $s['archiveId'],
					'__save'    => $s,
				);
			}
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return Importer::create(
			array(
				'location' => 'incoming/' . $id,
				'manifest' => Manifest::manifest_name( $result['archiveId'] ),
				'options'  => $options,
			)
		);
	}

	/**
	 * Copies a local archive (manifest + parts) into private storage for a CLI import.
	 *
	 * @return array{location:string,manifest:string}|\WP_Error
	 */
	public static function import_local( string $manifest_path ) {
		$manifest_path = wp_normalize_path( $manifest_path );
		if ( ! preg_match( '/^([A-Za-z0-9][A-Za-z0-9-]{7,63})\.manifest\.json$/', basename( $manifest_path ), $m ) || ! is_file( $manifest_path ) ) {
			return new \WP_Error( 'cradle_migration_manifest', 'Pass the path of an <archiveId>.manifest.json file.' );
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
