<?php
/**
 * The single-file `.cradle` container (docs/archive-format.md §1.1).
 *
 * A `.cradle` file is an ordinary ZIP file with another extension. It holds the
 * archive manifest and every part ZIP unchanged, stored without recompression.
 *
 * Writing is resumable across job steps: whole files are appended one after another
 * and the committed byte offset is kept in the job cursor, so an interrupted attempt
 * is cut back to that offset and repeated. Every entry uses ZIP64 size and offset
 * fields, so containers larger than 4 GiB stay valid.
 *
 * Reading goes through ZipArchive. Only a manifest and the part files of the same
 * archive are accepted, stored or deflated, either at the top level or inside one
 * common folder (a folder zipped by an operating system tool, including the "\"
 * separator that Windows PowerShell 5.1 writes).
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

defined( 'ABSPATH' ) || exit;

final class Container {

	const EXT        = '.cradle';
	const UPLOAD     = 'archive.cradle';
	const COPY_BYTES = 1048576;
	const MANIFEST   = '/^([A-Za-z0-9][A-Za-z0-9-]{7,63})\.manifest\.json$/';
	const PART       = '/^([A-Za-z0-9][A-Za-z0-9-]{7,63})\.part-([0-9]{4})\.zip$/';

	public static function file_name( string $archive_id ): string {
		return $archive_id . self::EXT;
	}

	/* ------------------------------------------------------------------ */
	/* Writing                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Appends whole files to the container at $path until the deadline (at least one per call).
	 *
	 * @param array $files list of { name: entry name, path: absolute source file }.
	 * @param array $state { entries: list of written entries, offset: committed byte offset }.
	 * @return array|\WP_Error Updated state; `complete` is true once the central directory is written.
	 */
	public static function write( string $path, array $files, array $state, float $deadline ) {
		$state = array(
			'entries' => array_values( (array) ( $state['entries'] ?? array() ) ),
			'offset'  => (int) ( $state['offset'] ?? 0 ),
		);
		$fh    = fopen( $path, 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return new \WP_Error( 'cradle_migration_write', 'Could not open the .cradle file for writing.' );
		}
		try {
			// Cut away anything an interrupted attempt wrote after the last committed entry.
			if ( ! ftruncate( $fh, $state['offset'] ) || 0 !== fseek( $fh, $state['offset'] ) ) {
				return new \WP_Error( 'cradle_migration_write', 'Could not prepare the .cradle file.' );
			}
			$units = 0;
			while ( count( $state['entries'] ) < count( $files ) && ( 0 === $units++ || microtime( true ) < $deadline ) ) {
				$entry = self::append( $fh, $files[ count( $state['entries'] ) ], $state['offset'] );
				if ( is_wp_error( $entry ) ) {
					return $entry;
				}
				$state['entries'][] = $entry;
				$state['offset']   += $entry['length'];
			}
			if ( count( $state['entries'] ) === count( $files ) ) {
				$ok = self::write_directory( $fh, $state['entries'], $state['offset'] );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				$state['complete'] = true;
			}
			fflush( $fh );
			return $state;
		} finally {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * Writes one local header plus the file bytes, then patches the CRC into the header.
	 *
	 * @param resource $fh     Container handle positioned at $offset.
	 * @param array    $file   { name, path }.
	 * @return array|\WP_Error Entry record.
	 */
	private static function append( $fh, array $file, int $offset ) {
		$name = (string) $file['name'];
		$src  = (string) $file['path'];
		clearstatcache( true, $src );
		if ( ! is_file( $src ) || is_link( $src ) ) {
			return new \WP_Error( 'cradle_migration_write', sprintf( '%s is missing; the export cannot be bundled.', $name ) );
		}
		$size = (int) filesize( $src );
		$dos  = self::dos_time( (int) filemtime( $src ) );
		$head = pack( 'VvvvvvVVVvv', 0x04034b50, 45, 0, 0, $dos[0], $dos[1], 0, 0xFFFFFFFF, 0xFFFFFFFF, strlen( $name ), 20 )
			. $name
			. pack( 'vvPP', 0x0001, 16, $size, $size );
		if ( ! self::put( $fh, $head ) ) {
			return new \WP_Error( 'cradle_migration_write', 'Could not write the .cradle file (disk full?).' );
		}
		$in = fopen( $src, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $in ) {
			return new \WP_Error( 'cradle_migration_write', sprintf( 'Could not read %s.', $name ) );
		}
		$crc    = hash_init( 'crc32b' );
		$copied = 0;
		while ( ! feof( $in ) ) {
			$buf = fread( $in, self::COPY_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $buf ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new \WP_Error( 'cradle_migration_write', sprintf( 'Could not read %s.', $name ) );
			}
			if ( '' === $buf ) {
				break;
			}
			hash_update( $crc, $buf );
			if ( ! self::put( $fh, $buf ) ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new \WP_Error( 'cradle_migration_write', 'Could not write the .cradle file (disk full?).' );
			}
			$copied += strlen( $buf );
		}
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( $copied !== $size ) {
			return new \WP_Error( 'cradle_migration_write', sprintf( '%s changed while it was being bundled.', $name ) );
		}
		$crc  = (int) hexdec( hash_final( $crc ) );
		$end  = $offset + strlen( $head ) + $size;
		// The CRC field sits 14 bytes into the local header.
		if ( 0 !== fseek( $fh, $offset + 14 ) || ! self::put( $fh, pack( 'V', $crc ) ) || 0 !== fseek( $fh, $end ) ) {
			return new \WP_Error( 'cradle_migration_write', 'Could not write the .cradle file.' );
		}
		return array(
			'name'   => $name,
			'offset' => $offset,
			'size'   => $size,
			'crc'    => $crc,
			'time'   => $dos[0],
			'date'   => $dos[1],
			'length' => strlen( $head ) + $size,
		);
	}

	/**
	 * Central directory, ZIP64 end record and locator, and the classic end record.
	 *
	 * @param resource $fh Handle positioned at $offset.
	 * @return true|\WP_Error
	 */
	private static function write_directory( $fh, array $entries, int $offset ) {
		$dir = '';
		foreach ( $entries as $e ) {
			// Made by Unix / version 4.5, stored, regular file with mode 0644.
			$dir .= pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 0x032D, 45, 0, 0, $e['time'], $e['date'], $e['crc'], 0xFFFFFFFF, 0xFFFFFFFF, strlen( $e['name'] ), 28, 0, 0, 0, 0x81A40000, 0xFFFFFFFF )
				. $e['name']
				. pack( 'vvPPP', 0x0001, 24, $e['size'], $e['size'], $e['offset'] );
		}
		$count  = count( $entries );
		$eocd64 = $offset + strlen( $dir );
		$tail   = pack( 'VPvvVVPPPP', 0x06064b50, 44, 0x032D, 45, 0, 0, $count, $count, strlen( $dir ), $offset )
			. pack( 'VVPV', 0x07064b50, 0, $eocd64, 1 )
			. pack( 'VvvvvVVv', 0x06054b50, 0, 0, min( $count, 0xFFFF ), min( $count, 0xFFFF ), 0xFFFFFFFF, 0xFFFFFFFF, 0 );
		if ( ! self::put( $fh, $dir . $tail ) || ! ftruncate( $fh, $eocd64 + strlen( $tail ) ) ) {
			return new \WP_Error( 'cradle_migration_write', 'Could not finish the .cradle file (disk full?).' );
		}
		return true;
	}

	/**
	 * @param resource $fh Handle.
	 */
	private static function put( $fh, string $bytes ): bool {
		$len = strlen( $bytes );
		$done = 0;
		while ( $done < $len ) {
			$n = fwrite( $fh, 0 === $done ? $bytes : substr( $bytes, $done ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			if ( false === $n || 0 === $n ) {
				return false;
			}
			$done += $n;
		}
		return true;
	}

	/**
	 * @return int[] { DOS time, DOS date }
	 */
	private static function dos_time( int $timestamp ): array {
		$t = getdate( max( 315532800, $timestamp ) );
		return array(
			( $t['hours'] << 11 ) | ( $t['minutes'] << 5 ) | intdiv( $t['seconds'], 2 ),
			( ( $t['year'] - 1980 ) << 9 ) | ( $t['mon'] << 5 ) | $t['mday'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* Reading                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Validates a container's entry list before anything is extracted.
	 *
	 * @return array|\WP_Error { archiveId, manifest, entries: list of { entry, name, size }, bytes }.
	 */
	public static function inspect( string $path, array $limits ) {
		if ( ! is_file( $path ) || is_link( $path ) ) {
			return new \WP_Error( 'cradle_migration_container', 'The .cradle file was not found.' );
		}
		$zip = new \ZipArchive();
		$rc  = $zip->open( $path, \ZipArchive::RDONLY );
		if ( true !== $rc ) {
			return new \WP_Error( 'cradle_migration_container', 'This is not a .cradle file: it cannot be read as a ZIP file.' );
		}
		try {
			$count = (int) $zip->numFiles;
			if ( $count < 2 || $count > (int) $limits['maxParts'] + 2 ) {
				return new \WP_Error( 'cradle_migration_container', 'This .cradle file does not contain a Cradle archive (a manifest and its part files).' );
			}
			$special = array( 0xA000 => 'symbolic link', 0x2000 => 'character device', 0x6000 => 'block device', 0x1000 => 'FIFO', 0xC000 => 'socket' );
			$raw     = array();
			$folder  = null;
			for ( $i = 0; $i < $count; $i++ ) {
				$stat = $zip->statIndex( $i, \ZipArchive::FL_UNCHANGED );
				if ( ! is_array( $stat ) ) {
					return new \WP_Error( 'cradle_migration_container', 'The .cradle file has an unreadable entry.' );
				}
				$entry = (string) $stat['name'];
				$label = Path_Guard::printable( $entry );
				if ( ! empty( $stat['encryption_method'] ) ) {
					return new \WP_Error( 'cradle_migration_container', 'Encrypted entry ' . $label . ' in the .cradle file is not supported.' );
				}
				$opsys = 0;
				$attr  = 0;
				if ( $zip->getExternalAttributesIndex( $i, $opsys, $attr, \ZipArchive::FL_UNCHANGED ) && \ZipArchive::OPSYS_UNIX === $opsys && isset( $special[ ( $attr >> 16 ) & 0xF000 ] ) ) {
					return new \WP_Error( 'cradle_migration_container', sprintf( 'Entry %1$s in the .cradle file is a %2$s; only regular files are accepted.', $label, $special[ ( $attr >> 16 ) & 0xF000 ] ) );
				}
				// Windows PowerShell 5.1 writes "\" between folder and file name; names are only matched
				// against the patterns below, never used as file system paths.
				$parts = explode( '/', str_replace( '\\', '/', $entry ) );
				if ( '' === end( $parts ) ) {
					// Only the one folder that wraps everything may appear as a directory entry.
					if ( 2 !== count( $parts ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9 ._()-]{0,100}$/', $parts[0] ) || 0 !== (int) $stat['size'] ) {
						return new \WP_Error( 'cradle_migration_container', 'Unexpected entry ' . $label . ' in the .cradle file.' );
					}
					continue;
				}
				if ( count( $parts ) > 2 ) {
					return new \WP_Error( 'cradle_migration_container', 'Unexpected entry ' . $label . ' in the .cradle file.' );
				}
				$dir = 2 === count( $parts ) ? $parts[0] : '';
				if ( null === $folder ) {
					$folder = $dir;
				}
				if ( $dir !== $folder || ( '' !== $dir && ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9 ._()-]{0,100}$/', $dir ) ) ) {
					return new \WP_Error( 'cradle_migration_container', 'Unexpected entry ' . $label . ' in the .cradle file (all files must be in the same folder).' );
				}
				$method = (int) $stat['comp_method'];
				if ( 0 !== $method && 8 !== $method ) {
					return new \WP_Error( 'cradle_migration_container', 'Entry ' . $label . ' uses an unsupported ZIP compression method.' );
				}
				$size = (int) $stat['size'];
				if ( 8 === $method && $size >= (int) $limits['ratioMinBytes'] && $size > (int) $stat['comp_size'] * (int) $limits['maxRatio'] ) {
					return new \WP_Error( 'cradle_migration_container', 'Entry ' . $label . ' exceeds the compression ratio limit (possible decompression bomb).' );
				}
				$raw[] = array(
					'entry' => $entry,
					'name'  => (string) end( $parts ),
					'size'  => $size,
				);
			}
		} finally {
			$zip->close();
		}

		$manifest = null;
		$id       = '';
		foreach ( $raw as $e ) {
			if ( preg_match( self::MANIFEST, $e['name'], $m ) ) {
				if ( null !== $manifest ) {
					return new \WP_Error( 'cradle_migration_container', 'The .cradle file contains more than one manifest.' );
				}
				$manifest = $e;
				$id       = $m[1];
			}
		}
		if ( null === $manifest ) {
			return new \WP_Error( 'cradle_migration_container', 'The .cradle file contains no archive manifest (<id>.manifest.json).' );
		}
		if ( $manifest['size'] < 2 || $manifest['size'] > (int) $limits['maxManifestBytes'] ) {
			return new \WP_Error( 'cradle_migration_container', 'The archive manifest size is not acceptable.' );
		}
		$parts = array();
		$bytes = 0;
		foreach ( $raw as $e ) {
			if ( $e === $manifest ) {
				continue;
			}
			if ( ! preg_match( self::PART, $e['name'], $m ) || $m[1] !== $id ) {
				return new \WP_Error( 'cradle_migration_container', 'Unexpected entry ' . Path_Guard::printable( $e['entry'] ) . ' in the .cradle file (only the manifest and part files of one archive are allowed).' );
			}
			if ( isset( $parts[ (int) $m[2] ] ) ) {
				return new \WP_Error( 'cradle_migration_container', 'The .cradle file contains ' . $e['name'] . ' twice.' );
			}
			if ( $e['size'] < 22 || $e['size'] > (int) $limits['maxPartBytes'] ) {
				return new \WP_Error( 'cradle_migration_container', 'The size of ' . $e['name'] . ' is not acceptable.' );
			}
			$parts[ (int) $m[2] ] = $e;
			$bytes               += $e['size'];
		}
		if ( ! $parts ) {
			return new \WP_Error( 'cradle_migration_container', 'The .cradle file contains no archive parts.' );
		}
		ksort( $parts );
		return array(
			'archiveId' => $id,
			'manifest'  => $manifest['name'],
			'entries'   => array_merge( array( $manifest ), array_values( $parts ) ),
			'bytes'     => $bytes + $manifest['size'],
		);
	}

	/**
	 * Extracts one inspected entry to $dest (through a temporary name), refusing any size mismatch.
	 *
	 * @return true|\WP_Error Code `cradle_migration_container` for a damaged file, `cradle_migration_write` for disk errors.
	 */
	public static function extract( string $path, array $entry, string $dest ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::RDONLY ) ) {
			return new \WP_Error( 'cradle_migration_container', 'The .cradle file can no longer be read.' );
		}
		$tmp = $dest . '.unpacking';
		try {
			$in = $zip->getStream( (string) $entry['entry'] );
			if ( ! $in ) {
				return new \WP_Error( 'cradle_migration_container', sprintf( 'Could not read %s from the .cradle file.', $entry['name'] ) );
			}
			$out = fopen( $tmp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $out ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new \WP_Error( 'cradle_migration_write', 'Could not unpack the .cradle file (disk full?).' );
			}
			$size    = (int) $entry['size'];
			$written = 0;
			$error   = null;
			while ( ! feof( $in ) && $written <= $size ) {
				$buf = fread( $in, (int) min( self::COPY_BYTES, $size - $written + 1 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $buf || '' === $buf ) {
					break;
				}
				if ( ! self::put( $out, $buf ) ) {
					$error = new \WP_Error( 'cradle_migration_write', 'Could not unpack the .cradle file (disk full?).' );
					break;
				}
				$written += strlen( $buf );
			}
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			if ( ! $error && $written !== $size ) {
				$error = new \WP_Error( 'cradle_migration_container', sprintf( '%s in the .cradle file is damaged or incomplete.', $entry['name'] ) );
			}
			if ( $error ) {
				wp_delete_file( $tmp );
				return $error;
			}
			if ( ! rename( $tmp, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				wp_delete_file( $tmp );
				return new \WP_Error( 'cradle_migration_write', 'Could not unpack the .cradle file.' );
			}
			return true;
		} finally {
			$zip->close();
		}
	}
}
