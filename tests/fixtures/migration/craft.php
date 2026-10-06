<?php
/**
 * Builds hostile / corrupt / modified Cradle archives for the migration failure tests.
 *
 * Checksums detect corruption, not authenticity: every crafted archive here carries
 * *valid* recomputed checksums (except the deliberate bad-checksum case), so the
 * importer's other defences are what must reject it.
 *
 * Library only (included by tests/integration/migration.php).
 */

/**
 * Reads a whole archive (manifest + all part entries) into memory.
 *
 * @return array{id:string,manifest:array,entries:array<string,string>}
 */
function cm_craft_read( string $manifest_path ): array {
	// The whole sandbox archive (uploads, language packs, …) is held in memory, then copied per variant.
	if ( wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) < 1073741824 && '-1' !== (string) ini_get( 'memory_limit' ) ) {
		ini_set( 'memory_limit', '1024M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- test helper only.
	}
	$dir      = dirname( $manifest_path );
	$id       = basename( $manifest_path, '.manifest.json' );
	$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
	$entries  = array();
	foreach ( $manifest['parts'] as $part ) {
		$zip = new ZipArchive();
		$zip->open( $dir . '/' . $part['name'], ZipArchive::RDONLY );
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entries[ $zip->getNameIndex( $i ) ] = $zip->getFromIndex( $i );
		}
		$zip->close();
	}
	return array(
		'id'       => $id,
		'manifest' => $manifest,
		'entries'  => $entries,
	);
}

/**
 * Writes an archive as a single part with recomputed part/chunk metadata.
 *
 * @param array $attrs entry => array( opsys, attr ) external attributes to force.
 * @return string Manifest path.
 */
function cm_craft_write( string $out_dir, array $archive, array $attrs = array() ): string {
	if ( ! is_dir( $out_dir ) ) {
		mkdir( $out_dir, 0775, true );
	}
	foreach ( (array) glob( $out_dir . '/*' ) as $old ) {
		unlink( (string) $old );
	}
	$id   = $archive['id'];
	$m    = $archive['manifest'];
	$name = sprintf( '%s.part-%04d.zip', $id, 1 );
	$zip  = new ZipArchive();
	$zip->open( $out_dir . '/' . $name, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	foreach ( $archive['entries'] as $entry => $bytes ) {
		$zip->addFromString( $entry, $bytes );
		$zip->setCompressionName( $entry, ZipArchive::CM_DEFLATE );
		if ( isset( $attrs[ $entry ] ) ) {
			$zip->setExternalAttributesName( $entry, $attrs[ $entry ][0], $attrs[ $entry ][1] );
		}
	}
	$zip->close();
	foreach ( $m['database']['tables'] as $ti => $t ) {
		$rows = 0;
		foreach ( $t['chunks'] as $ci => $c ) {
			$data                                              = $archive['entries'][ $c['entry'] ] ?? '';
			$m['database']['tables'][ $ti ]['chunks'][ $ci ]['part']   = 1;
			$m['database']['tables'][ $ti ]['chunks'][ $ci ]['bytes']  = strlen( $data );
			$m['database']['tables'][ $ti ]['chunks'][ $ci ]['sha256'] = hash( 'sha256', $data );
			$m['database']['tables'][ $ti ]['chunks'][ $ci ]['rows']   = '' === $data ? 0 : substr_count( $data, "\n" );
			$rows += $m['database']['tables'][ $ti ]['chunks'][ $ci ]['rows'];
		}
		$m['database']['tables'][ $ti ]['rows'] = $rows;
	}
	foreach ( $m['files'] as $fi => $f ) {
		foreach ( $f['segments'] as $si => $s ) {
			$m['files'][ $fi ]['segments'][ $si ]['part'] = 1;
		}
	}
	$m['parts'] = array(
		array(
			'name'   => $name,
			'index'  => 1,
			'size'   => filesize( $out_dir . '/' . $name ),
			'sha256' => hash_file( 'sha256', $out_dir . '/' . $name ),
		),
	);
	$path = $out_dir . '/' . $id . '.manifest.json';
	file_put_contents( $path, json_encode( $m, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
	return $path;
}

/**
 * Adds a file (manifest + entry) to an in-memory archive.
 */
function cm_craft_add_file( array &$archive, string $category, string $path, string $bytes ): string {
	$entry                       = 'files/' . $category . '/' . $path;
	$archive['entries'][ $entry ] = $bytes;
	$archive['manifest']['files'][] = array(
		'category' => $category,
		'path'     => $path,
		'size'     => strlen( $bytes ),
		'sha256'   => hash( 'sha256', $bytes ),
		'mtime'    => time(),
		'segments' => array(
			array(
				'part'  => 1,
				'entry' => $entry,
				'size'  => strlen( $bytes ),
			),
		),
	);
	return $entry;
}

/**
 * Applies $fn( array $row, array $columns ): ?array to every row of a table (null deletes the row);
 * $extra rows (column => value maps) are appended to the table's last chunk.
 */
function cm_craft_rows( array &$archive, string $table, ?callable $fn, array $extra = array() ) {
	foreach ( $archive['manifest']['database']['tables'] as $t ) {
		if ( $t['name'] !== $table ) {
			continue;
		}
		$names = array_column( $t['columns'], 'name' );
		$last  = count( $t['chunks'] ) - 1;
		foreach ( $t['chunks'] as $ci => $c ) {
			$out = '';
			foreach ( explode( "\n", rtrim( $archive['entries'][ $c['entry'] ], "\n" ) ) as $line ) {
				$row = json_decode( $line, true );
				if ( $fn ) {
					$row = $fn( $row, $names );
				}
				if ( null !== $row ) {
					$out .= json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
				}
			}
			if ( $ci === $last ) {
				foreach ( $extra as $values ) {
					$row = array();
					foreach ( $names as $n ) {
						$row[] = array_key_exists( $n, $values ) ? $values[ $n ] : null;
					}
					$out .= json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
				}
			}
			$archive['entries'][ $c['entry'] ] = $out;
		}
	}
}
