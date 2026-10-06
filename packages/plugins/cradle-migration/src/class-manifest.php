<?php
/**
 * Cradle archive manifest: constants, limits and strict import-side validation.
 *
 * See docs/archive-format.md. The manifest is untrusted input. Validation produces
 * a normalized structure plus the complete set of ZIP entries the parts must contain;
 * anything else in a part is rejected.
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

defined( 'ABSPATH' ) || exit;

final class Manifest {

	const FORMAT         = 'cradle-archive';
	const FORMAT_VERSION = 1;
	const ID_PATTERN     = '/^[A-Za-z0-9][A-Za-z0-9-]{7,63}$/';
	const CATEGORIES     = array( 'uploads', 'themes', 'plugins', 'root', 'languages', 'translations' );
	// WordPress language files (WP_LANG_DIR): .mo/.po/.json at the top level or in plugins/ or themes/.
	// PHP translation caches (.l10n.php) are never archived: WordPress uses the .mo file instead.
	const LANGUAGE_FILE  = '/^(?:(?:plugins|themes)\/)?[A-Za-z0-9@][A-Za-z0-9@._-]{0,199}\.(?:mo|po|json)$/';
	// Cradle Translations data in private storage (catalogs, their history, runtime maps, source manifest).
	const TRANSLATION_FILE = '/^(?:catalogs|history|runtime|manifest)\/[A-Za-z0-9@][A-Za-z0-9@._-]{0,199}\.json$/';
	const ROOT_FILE      = '/^(?!index\.)[A-Za-z0-9._-]{1,64}\.(txt|xml|json|ico|webmanifest|html)$/i';
	const SEGMENT_BYTES  = 33554432;

	const TOP_KEYS = array( 'format', 'formatVersion', 'archiveId', 'createdAt', 'completedAt', 'generator', 'mode', 'scope', 'redacted', 'consistency', 'source', 'packages', 'database', 'files', 'exclusions', 'unsupported', 'notMigrated', 'warnings', 'parts', 'totals' );

	const NOT_MIGRATED = array(
		'DNS records and domain configuration',
		'Email accounts and mail server settings',
		'TLS/SSL certificates',
		'Server packages, PHP/web-server configuration and hosting users',
		'Arbitrary account directories outside the WordPress roots',
		'WordPress core files (install a trusted copy of the recorded version instead)',
		'wp-config.php, database credentials, salts and environment secrets',
		'Must-use plugins, drop-ins (object-cache.php, advanced-cache.php, db.php) and web-server rules (.htaccess, web.config) — listed, never transplanted',
	);

	/**
	 * Effective limits. Filter `cradle_migration_limits` may change them; $lower may only lower them.
	 */
	public static function limits( array $lower = array() ): array {
		$limits = apply_filters(
			'cradle_migration_limits',
			array(
				'maxParts'         => 2000,
				'maxPartBytes'     => 2147483648,
				'maxEntries'       => 500000,
				'maxFiles'         => 400000,
				'maxTotalBytes'    => 53687091200,
				'maxFileBytes'     => 4294967296,
				'maxChunkBytes'    => 67108864,
				'maxRatio'         => 200,
				'ratioMinBytes'    => 1048576,
				'maxDepth'         => 32,
				'maxPathBytes'     => 512,
				'maxManifestBytes' => 67108864,
				'maxTables'        => 5000,
				'serializedDepth'  => Serialized::MAX_DEPTH,
				'diskQuota'        => 0,
			)
		);
		foreach ( $lower as $key => $value ) {
			if ( isset( $limits[ $key ] ) && is_numeric( $value ) && (float) $value >= 0 ) {
				if ( 'diskQuota' === $key || (float) $value < (float) $limits[ $key ] ) {
					$limits[ $key ] = (int) $value;
				}
			}
		}
		return $limits;
	}

	public static function part_name( string $archive_id, int $index ): string {
		return sprintf( '%s.part-%04d.zip', $archive_id, $index );
	}

	public static function manifest_name( string $archive_id ): string {
		return $archive_id . '.manifest.json';
	}

	public static function chunk_entry( string $table, int $index ): string {
		return sprintf( 'db/%s/%06d.jsonl', $table, $index );
	}

	public static function file_entry( string $category, string $path, int $segment, int $segments ): string {
		$base = 'files/' . $category . '/' . $path;
		return $segments > 1 ? sprintf( '%s.cradle-seg%04d', $base, $segment ) : $base;
	}

	/**
	 * Validates a decoded manifest.
	 *
	 * @return array{manifest:array,entries:array<string,array>,errors:string[],warnings:string[]}
	 */
	public static function validate( $m, array $limits, string $expected_id = '' ): array {
		$errors   = array();
		$warnings = array();
		$entries  = array();
		$fail     = static function ( string $msg ) use ( &$errors ) {
			if ( count( $errors ) < 200 ) {
				$errors[] = $msg;
			}
		};
		$out      = array(
			'manifest' => array(),
			'entries'  => array(),
			'errors'   => array(),
			'warnings' => array(),
		);
		if ( ! is_array( $m ) ) {
			$out['errors'][] = 'The manifest is not a JSON object.';
			return $out;
		}
		$unknown = array_diff( array_keys( $m ), self::TOP_KEYS );
		if ( $unknown ) {
			$fail( 'Unknown manifest keys: ' . implode( ', ', array_map( array( Path_Guard::class, 'printable' ), array_map( 'strval', $unknown ) ) ) . '.' );
		}
		foreach ( array( 'format', 'formatVersion', 'archiveId', 'source', 'database', 'files', 'parts', 'scope' ) as $key ) {
			if ( ! array_key_exists( $key, $m ) ) {
				$fail( 'Missing manifest key: ' . $key . '.' );
			}
		}
		if ( $errors ) {
			$out['errors'] = $errors;
			return $out;
		}
		if ( self::FORMAT !== $m['format'] ) {
			$fail( 'This is not a Cradle archive manifest.' );
		}
		if ( ! is_int( $m['formatVersion'] ) || $m['formatVersion'] < 1 ) {
			$fail( 'Invalid format version.' );
		} elseif ( $m['formatVersion'] > self::FORMAT_VERSION ) {
			$fail( sprintf( 'Archive format version %d is newer than this importer supports (%d). Update Cradle Migration.', $m['formatVersion'], self::FORMAT_VERSION ) );
		}
		$id = is_string( $m['archiveId'] ) ? $m['archiveId'] : '';
		if ( ! preg_match( self::ID_PATTERN, $id ) ) {
			$fail( 'Invalid archive ID.' );
		} elseif ( '' !== $expected_id && $expected_id !== $id ) {
			$fail( 'The archive ID does not match the manifest file name.' );
		}

		// Source.
		$src = is_array( $m['source'] ) ? $m['source'] : array();
		foreach ( array( 'siteUrl', 'homeUrl', 'abspath', 'contentDir', 'uploadsDir', 'tablePrefix', 'wordpress' ) as $key ) {
			if ( ! is_string( $src[ $key ] ?? null ) || strlen( $src[ $key ] ) > 2048 ) {
				$fail( 'Source field ' . $key . ' is missing or invalid.' );
			}
		}
		if ( ! empty( $src['multisite'] ) ) {
			$fail( 'Multisite archives are not supported.' );
		}
		foreach ( array( 'siteUrl', 'homeUrl' ) as $key ) {
			$scheme = is_string( $src[ $key ] ?? null ) ? strtolower( (string) wp_parse_url( $src[ $key ], PHP_URL_SCHEME ) ) : '';
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === (string) wp_parse_url( $src[ $key ], PHP_URL_HOST ) ) {
				$fail( 'Source ' . $key . ' is not an http(s) URL.' );
			}
		}
		if ( ! preg_match( '/^[A-Za-z0-9_]{1,20}$/', (string) ( $src['tablePrefix'] ?? '' ) ) ) {
			$fail( 'Invalid source table prefix.' );
		}

		// Parts.
		$parts = array();
		if ( ! is_array( $m['parts'] ) || ! array_is_list( $m['parts'] ) || ! $m['parts'] ) {
			$fail( 'The part list is missing.' );
		} elseif ( count( $m['parts'] ) > $limits['maxParts'] ) {
			$fail( 'Too many archive parts.' );
		} else {
			foreach ( $m['parts'] as $i => $p ) {
				$n = $i + 1;
				if ( ! is_array( $p ) || ( $p['index'] ?? null ) !== $n || ( $p['name'] ?? null ) !== self::part_name( $id, $n ) || ! is_int( $p['size'] ?? null ) || $p['size'] < 22 || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $p['sha256'] ?? '' ) ) ) {
					$fail( sprintf( 'Part %d is described incorrectly.', $n ) );
					continue;
				}
				if ( $p['size'] > $limits['maxPartBytes'] ) {
					$fail( sprintf( 'Part %d exceeds the part size limit.', $n ) );
				}
				$parts[ $n ] = array(
					'index'  => $n,
					'name'   => $p['name'],
					'size'   => $p['size'],
					'sha256' => $p['sha256'],
				);
			}
		}
		$part_count = count( $parts );
		$add_entry  = static function ( string $name, int $part, int $size, array $ref ) use ( &$entries, $fail, $part_count ) {
			if ( $part < 1 || $part > $part_count ) {
				$fail( 'An entry refers to a part that does not exist.' );
				return;
			}
			if ( isset( $entries[ $name ] ) ) {
				$fail( 'Duplicate archive entry ' . Path_Guard::printable( $name ) . '.' );
				return;
			}
			$entries[ $name ] = array(
				'part' => $part,
				'size' => $size,
			) + $ref;
		};

		// Database.
		$tables = array();
		$db     = is_array( $m['database'] ) ? $m['database'] : array();
		if ( 'jsonl-v1' !== ( $db['encoding'] ?? null ) ) {
			$fail( 'Unsupported database encoding.' );
		}
		$list = $db['tables'] ?? array();
		if ( ! is_array( $list ) || ! array_is_list( $list ) || count( $list ) > $limits['maxTables'] ) {
			$fail( 'The table list is invalid.' );
			$list = array();
		}
		$seen_tables = array();
		foreach ( $list as $t ) {
			if ( ! is_array( $t ) ) {
				$fail( 'A table entry is invalid.' );
				continue;
			}
			$valid = Db::validate_table( $t, true );
			if ( is_wp_error( $valid ) ) {
				$fail( $valid->get_error_message() );
				continue;
			}
			$key = strtolower( $valid['name'] );
			if ( isset( $seen_tables[ $key ] ) ) {
				$fail( 'Duplicate (or case-conflicting) table ' . $valid['name'] . '.' );
				continue;
			}
			$seen_tables[ $key ] = true;
			foreach ( $valid['warnings'] as $w ) {
				$warnings[] = $valid['name'] . ': ' . $w;
			}
			$mode = $t['rowsMode'] ?? 'full';
			if ( ! in_array( $mode, array( 'full', 'schema-only' ), true ) ) {
				$fail( 'Table ' . $valid['name'] . ' has an invalid rows mode.' );
				continue;
			}
			$chunks = array();
			$rows   = 0;
			foreach ( (array) ( $t['chunks'] ?? array() ) as $ci => $c ) {
				$n = $ci + 1;
				if ( ! is_array( $c ) || ( $c['index'] ?? null ) !== $n || ( $c['entry'] ?? null ) !== self::chunk_entry( $valid['name'], $n ) || ! is_int( $c['part'] ?? null ) || ! is_int( $c['rows'] ?? null ) || $c['rows'] < 0 || ! is_int( $c['bytes'] ?? null ) || $c['bytes'] < 0 || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $c['sha256'] ?? '' ) ) ) {
					$fail( sprintf( 'Chunk %d of table %s is described incorrectly.', $n, $valid['name'] ) );
					continue;
				}
				if ( $c['bytes'] > $limits['maxChunkBytes'] ) {
					$fail( sprintf( 'Chunk %d of table %s exceeds the chunk size limit.', $n, $valid['name'] ) );
					continue;
				}
				$rows    += $c['rows'];
				$chunks[] = array(
					'index'  => $n,
					'part'   => $c['part'],
					'entry'  => $c['entry'],
					'rows'   => $c['rows'],
					'bytes'  => $c['bytes'],
					'sha256' => $c['sha256'],
				);
				$add_entry( $c['entry'], $c['part'], $c['bytes'], array( 'kind' => 'chunk', 'table' => $valid['name'], 'chunk' => $n, 'sha256' => $c['sha256'] ) );
			}
			if ( 'schema-only' === $mode && $chunks ) {
				$fail( 'Schema-only table ' . $valid['name'] . ' carries rows.' );
			}
			if ( isset( $t['rows'] ) && $t['rows'] !== $rows ) {
				$fail( 'Row count of table ' . $valid['name'] . ' does not match its chunks.' );
			}
			$valid['rowsMode'] = $mode;
			$valid['rows']     = $rows;
			$valid['chunks']   = $chunks;
			$tables[]          = $valid;
		}

		// Files.
		$files = array();
		$list  = $m['files'];
		if ( ! is_array( $list ) || ! array_is_list( $list ) ) {
			$fail( 'The file list is invalid.' );
			$list = array();
		} elseif ( count( $list ) > $limits['maxFiles'] ) {
			$fail( 'The archive lists more files than allowed.' );
			$list = array();
		}
		$paths = array();
		$total = 0;
		foreach ( $list as $fi => $f ) {
			if ( ! is_array( $f ) || ! in_array( $f['category'] ?? null, self::CATEGORIES, true ) || ! is_string( $f['path'] ?? null ) ) {
				$fail( sprintf( 'File entry %d is invalid.', $fi + 1 ) );
				continue;
			}
			$path  = $f['path'];
			$valid = Path_Guard::validate_relative( $path, $limits['maxDepth'], $limits['maxPathBytes'] );
			if ( is_wp_error( $valid ) ) {
				$fail( $valid->get_error_message() );
				continue;
			}
			if ( 'uploads' === $f['category'] && preg_match( '/^cradle-private-/i', $path ) ) {
				$fail( 'Upload path ' . Path_Guard::printable( $path ) . ' targets reserved Cradle private storage.' );
				continue;
			}
			if ( 'root' === $f['category'] && ! preg_match( self::ROOT_FILE, $path ) ) {
				$fail( 'Root file ' . Path_Guard::printable( $path ) . ' is not in the root-file allowlist.' );
				continue;
			}
			if ( 'languages' === $f['category'] && ! preg_match( self::LANGUAGE_FILE, $path ) ) {
				$fail( 'Language file ' . Path_Guard::printable( $path ) . ' is not a WordPress translation file (.mo, .po or .json in languages/, languages/plugins/ or languages/themes/).' );
				continue;
			}
			if ( 'translations' === $f['category'] && ! preg_match( self::TRANSLATION_FILE, $path ) ) {
				$fail( 'Translation data file ' . Path_Guard::printable( $path ) . ' is not a Cradle Translations JSON file.' );
				continue;
			}
			if ( ! is_int( $f['size'] ?? null ) || $f['size'] < 0 || $f['size'] > $limits['maxFileBytes'] || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $f['sha256'] ?? '' ) ) ) {
				$fail( 'File ' . Path_Guard::printable( $path ) . ' has an invalid size or checksum.' );
				continue;
			}
			$segs = $f['segments'] ?? null;
			if ( ! is_array( $segs ) || ! $segs || ! array_is_list( $segs ) || count( $segs ) > 4096 ) {
				$fail( 'File ' . Path_Guard::printable( $path ) . ' has no segments.' );
				continue;
			}
			$sum      = 0;
			$segments = array();
			foreach ( $segs as $si => $s ) {
				$entry = self::file_entry( $f['category'], $path, $si + 1, count( $segs ) );
				if ( ! is_array( $s ) || ( $s['entry'] ?? null ) !== $entry || ! is_int( $s['part'] ?? null ) || ! is_int( $s['size'] ?? null ) || $s['size'] < 0 ) {
					$fail( 'File ' . Path_Guard::printable( $path ) . ' has an invalid segment.' );
					continue 2;
				}
				$sum       += $s['size'];
				$segments[] = array(
					'part'  => $s['part'],
					'entry' => $entry,
					'size'  => $s['size'],
				);
			}
			if ( $sum !== $f['size'] ) {
				$fail( 'File ' . Path_Guard::printable( $path ) . ' segments do not add up to its size.' );
				continue;
			}
			$index = count( $files );
			foreach ( $segments as $si => $s ) {
				$add_entry( $s['entry'], $s['part'], $s['size'], array( 'kind' => 'file', 'file' => $index, 'segment' => $si ) );
			}
			$paths[] = $f['category'] . '/' . $path;
			$total  += $f['size'];
			$files[] = array(
				'category'   => $f['category'],
				'path'       => $path,
				'size'       => $f['size'],
				'sha256'     => $f['sha256'],
				'segments'   => $segments,
				'executable' => 'uploads' === $f['category'] && Path_Guard::is_executable_name( $path ),
			);
		}
		foreach ( Path_Guard::conflicts( $paths ) as $conflict ) {
			$fail( $conflict );
		}
		if ( $total > $limits['maxTotalBytes'] ) {
			$fail( 'The archive expands beyond the total size limit.' );
		}
		if ( count( $entries ) > $limits['maxEntries'] ) {
			$fail( 'The archive has more entries than allowed.' );
		}

		$text_list = static function ( $value, int $max = 2000 ): array {
			$out = array();
			foreach ( is_array( $value ) ? $value : array() as $item ) {
				if ( count( $out ) >= $max ) {
					break;
				}
				if ( is_string( $item ) ) {
					$out[] = mb_substr( $item, 0, 500 );
				} elseif ( is_array( $item ) ) {
					$row = array();
					foreach ( array( 'kind', 'name', 'detail', 'category', 'path', 'reason', 'version', 'slug', 'basename', 'active', 'included', 'parent', 'coreVersion' ) as $k ) {
						if ( isset( $item[ $k ] ) && is_scalar( $item[ $k ] ) ) {
							$row[ $k ] = mb_substr( (string) $item[ $k ], 0, 500 );
						}
					}
					$out[] = $row;
				}
			}
			return $out;
		};

		$scope    = is_array( $m['scope'] ) ? $m['scope'] : array();
		$packages = is_array( $m['packages'] ?? null ) ? $m['packages'] : array();

		$out['manifest'] = array(
			'archiveId'   => $id,
			'formatVersion' => (int) $m['formatVersion'],
			'createdAt'   => is_string( $m['createdAt'] ?? null ) ? substr( $m['createdAt'], 0, 40 ) : '',
			'generator'   => $text_list( array( $m['generator'] ?? array() ) )[0] ?? array(),
			'mode'        => in_array( $m['mode'] ?? '', array( 'full', 'selective' ), true ) ? $m['mode'] : 'selective',
			'scope'       => array(
				'database'     => ! empty( $scope['database'] ),
				'uploads'      => ! empty( $scope['uploads'] ),
				'themes'       => array_values( array_filter( (array) ( $scope['themes'] ?? array() ), 'is_string' ) ),
				'plugins'      => array_values( array_filter( (array) ( $scope['plugins'] ?? array() ), 'is_string' ) ),
				'languages'    => ! empty( $scope['languages'] ),
				'translations' => ! empty( $scope['translations'] ),
			),
			'consistency' => is_array( $m['consistency'] ?? null ) ? array(
				'strategy' => (string) ( $m['consistency']['strategy'] ?? 'none' ),
				'notes'    => mb_substr( (string) ( $m['consistency']['notes'] ?? '' ), 0, 1000 ),
			) : array( 'strategy' => 'none', 'notes' => '' ),
			'source'      => array(
				'siteUrl'       => (string) ( $src['siteUrl'] ?? '' ),
				'homeUrl'       => (string) ( $src['homeUrl'] ?? '' ),
				'abspath'       => (string) ( $src['abspath'] ?? '' ),
				'contentDir'    => (string) ( $src['contentDir'] ?? '' ),
				'uploadsDir'    => (string) ( $src['uploadsDir'] ?? '' ),
				'uploadsUrl'    => is_string( $src['uploadsUrl'] ?? null ) ? $src['uploadsUrl'] : '',
				'tablePrefix'   => (string) ( $src['tablePrefix'] ?? '' ),
				'wordpress'     => (string) ( $src['wordpress'] ?? '' ),
				'dbVersion'     => (int) ( $src['dbVersion'] ?? 0 ),
				'php'           => is_string( $src['php'] ?? null ) ? substr( $src['php'], 0, 40 ) : '',
				'locale'        => is_string( $src['locale'] ?? null ) && preg_match( '/^[A-Za-z0-9_@-]{2,40}$/', $src['locale'] ) ? $src['locale'] : '',
				// Recorded since 0.3.0; '' for older archives.
				'textDirection' => in_array( $src['textDirection'] ?? '', array( 'ltr', 'rtl' ), true ) ? $src['textDirection'] : '',
				'template'      => is_string( $src['template'] ?? null ) ? $src['template'] : '',
				'stylesheet'    => is_string( $src['stylesheet'] ?? null ) ? $src['stylesheet'] : '',
				'activePlugins' => array_values( array_filter( (array) ( $src['activePlugins'] ?? array() ), 'is_string' ) ),
			),
			'packages'    => array(
				'themes'  => $text_list( $packages['themes'] ?? array() ),
				'plugins' => $text_list( $packages['plugins'] ?? array() ),
			),
			'tables'      => $tables,
			'files'       => $files,
			'parts'       => array_values( $parts ),
			'exclusions'  => $text_list( $m['exclusions'] ?? array() ),
			'unsupported' => $text_list( $m['unsupported'] ?? array() ),
			'warnings'    => $text_list( $m['warnings'] ?? array() ),
			'totalBytes'  => $total,
		);
		$out['entries']  = $entries;
		$out['errors']   = $errors;
		$out['warnings'] = $warnings;
		return $out;
	}
}
