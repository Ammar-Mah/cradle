<?php
/**
 * Shared helpers for scripts/sync.php and scripts/build.php: the package
 * manifest, file listing with exclusions, and canonical skill injection.
 */

function cradle_manifest( string $root ): array {
	$manifest = json_decode( (string) file_get_contents( $root . '/scripts/packages.json' ), true );
	if ( ! is_array( $manifest ) ) {
		fwrite( STDERR, "scripts/packages.json is invalid\n" );
		exit( 1 );
	}
	// Website child themes created with packages/themes/cradle/bin/child-theme.php (sites/<site>/themes/<slug>)
	// are mirrored without editing packages.json.
	$listed = array_column( $manifest['packages'], 'source' );
	foreach ( glob( $root . '/sites/*/themes/*/style.css' ) ?: array() as $style ) {
		$source = substr( str_replace( '\\', '/', dirname( $style ) ), strlen( str_replace( '\\', '/', $root ) ) + 1 );
		if ( ! in_array( $source, $listed, true ) && preg_match( '/^[a-z0-9-]+$/', basename( $source ) ) ) {
			$manifest['packages'][] = array(
				'name'   => basename( $source ),
				'type'   => 'theme',
				'source' => $source,
				'kind'   => 'site',
			);
		}
	}
	return $manifest;
}

/**
 * Relative file paths under $dir, skipping excluded segments (fnmatch patterns).
 */
function cradle_list_files( string $dir, array $exclude ): array {
	$out = array();
	if ( ! is_dir( $dir ) ) {
		return $out;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$rel = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) + 1 ) );
		foreach ( $exclude as $pattern ) {
			foreach ( explode( '/', $rel ) as $segment ) {
				if ( fnmatch( $pattern, $segment ) ) {
					continue 3;
				}
			}
			if ( str_contains( $pattern, '/' ) && fnmatch( $pattern, $rel ) ) {
				continue 2;
			}
		}
		$out[] = $rel;
	}
	sort( $out );
	return $out;
}

/**
 * Files injected into a package: canonical skills, a filtered skill index, source hashes, briefs.
 *
 * @return array<string,array{path?:string,content?:string}>
 */
function cradle_injections( string $root, array $manifest, array $pkg ): array {
	$out    = array();
	$skills = $pkg['skills'] ?? array();
	$sdir   = $root . '/' . $manifest['skillsSource'];
	if ( $skills && is_dir( $sdir ) ) {
		$names  = '*' === $skills ? array_values( array_filter( scandir( $sdir ), fn( $d ) => '.' !== $d[0] && is_dir( $sdir . '/' . $d ) ) ) : $skills;
		$hashes = array();
		foreach ( $names as $name ) {
			if ( ! is_dir( $sdir . '/' . $name ) ) {
				continue;
			}
			foreach ( cradle_list_files( $sdir . '/' . $name, $manifest['exclude'] ) as $rel ) {
				$path                                     = $sdir . '/' . $name . '/' . $rel;
				$out[ 'skills/' . $name . '/' . $rel ]    = array( 'path' => $path );
				$hashes[ 'skills/' . $name . '/' . $rel ] = hash_file( 'sha256', $path );
			}
		}
		$out['skills/INDEX.md']    = array( 'content' => cradle_skill_index( $sdir, $names ) );
		$out['skills/SOURCE.json'] = array(
			'content' => json_encode(
				array(
					'generated' => 'Copied from the canonical skills/ folder by scripts/sync.php or scripts/build.php. Do not edit here; edit the canonical source and resync.',
					'files'     => $hashes,
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			) . "\n",
		);
	}
	foreach ( $pkg['inject'] ?? array() as $file ) {
		if ( is_file( $root . '/' . $file ) ) {
			$out[ $file ] = array( 'path' => $root . '/' . $file );
		}
	}
	return $out;
}

/**
 * Package-local INDEX.md with only the rows for injected skills.
 */
function cradle_skill_index( string $sdir, array $names ): string {
	$canonical = is_file( $sdir . '/INDEX.md' ) ? file( $sdir . '/INDEX.md', FILE_IGNORE_NEW_LINES ) : array();
	$head      = array();
	$rows      = array();
	$in_table  = false;
	foreach ( $canonical as $line ) {
		if ( str_starts_with( $line, '|' ) ) {
			if ( ! $in_table ) {
				$in_table = true;
				$head[]   = $line;
				continue;
			}
			if ( preg_match( '/^\|\s*:?-{3}/', $line ) ) {
				$head[] = $line;
				continue;
			}
			foreach ( $names as $name ) {
				if ( preg_match( '/^\|\s*`?' . preg_quote( $name, '/' ) . '`?\s*\|/', $line ) ) {
					$rows[] = $line;
				}
			}
			continue;
		}
		if ( ! $in_table ) {
			$head[] = $line;
		}
	}
	return implode( "\n", array_merge( $head, $rows ) ) . "\n\n_Generated from the canonical skills/INDEX.md. Paths are relative to this skills folder._\n";
}

/**
 * Complete desired file map for a package (source files + injections).
 */
function cradle_package_files( string $root, array $manifest, array $pkg, array $extra_exclude = array() ): array {
	$src   = $root . '/' . $pkg['source'];
	$files = array();
	foreach ( cradle_list_files( $src, array_merge( $manifest['exclude'], $extra_exclude ) ) as $rel ) {
		$files[ $rel ] = array( 'path' => $src . '/' . $rel );
	}
	foreach ( cradle_injections( $root, $manifest, $pkg ) as $rel => $entry ) {
		$files[ $rel ] = $entry;
	}
	return $files;
}

/**
 * Writes thin Claude Code skill wrappers (.claude/skills/<name>/SKILL.md) that point at the
 * canonical skills/<name>/SKILL.md, so automatic skill discovery works without duplicating
 * procedures. Wrappers carry the canonical hash and are regenerated, never hand-edited.
 *
 * @return int Number of wrapper files written or removed.
 */
function cradle_write_skill_wrappers( string $root ): int {
	$sdir    = $root . '/skills';
	$wdir    = $root . '/.claude/skills';
	$changes = 0;
	if ( ! is_dir( $sdir ) ) {
		return 0;
	}
	$names = array_values( array_filter( scandir( $sdir ), fn( $d ) => '.' !== $d[0] && is_file( $sdir . '/' . $d . '/SKILL.md' ) ) );
	$index = array();
	foreach ( $names as $name ) {
		$source = $sdir . '/' . $name . '/SKILL.md';
		$raw    = (string) file_get_contents( $source );
		if ( ! preg_match( '/^---\s*\n(.*?)\n---/s', $raw, $m ) ) {
			continue;
		}
		$hash    = hash( 'sha256', $raw );
		$wrapper = "---\n" . $m[1] . "\n---\n\n"
			. "<!-- Generated by scripts/sync.php from skills/{$name}/SKILL.md (sha256 {$hash}). Do not edit. -->\n\n"
			. "# {$name}\n\n"
			. "The canonical procedure is `skills/{$name}/SKILL.md` in the repository root. Read that file in full and follow it,\n"
			. "including the references it lists under `skills/{$name}/references/`. Start with `skills/INDEX.md` when unsure which skills apply.\n";
		$target  = $wdir . '/' . $name . '/SKILL.md';
		if ( ! is_file( $target ) || file_get_contents( $target ) !== $wrapper ) {
			if ( ! is_dir( dirname( $target ) ) ) {
				mkdir( dirname( $target ), 0775, true );
			}
			file_put_contents( $target, $wrapper );
			$changes++;
		}
		$index[ $name ] = $hash;
	}
	if ( is_dir( $wdir ) ) {
		foreach ( scandir( $wdir ) as $entry ) {
			if ( '.' !== $entry[0] && is_dir( $wdir . '/' . $entry ) && ! isset( $index[ $entry ] ) && is_file( $wdir . '/' . $entry . '/SKILL.md' ) && str_contains( (string) file_get_contents( $wdir . '/' . $entry . '/SKILL.md' ), 'Generated by scripts/sync.php' ) ) {
				unlink( $wdir . '/' . $entry . '/SKILL.md' );
				@rmdir( $wdir . '/' . $entry ); // phpcs:ignore
				$changes++;
			}
		}
	}
	return $changes;
}

/**
 * Reads the Version header of a theme style.css or plugin bootstrap.
 */
function cradle_package_version( string $root, array $pkg ): string {
	$src  = $root . '/' . $pkg['source'];
	$file = 'theme' === $pkg['type'] ? $src . '/style.css' : $src . '/' . $pkg['name'] . '.php';
	if ( ! is_file( $file ) ) {
		return '0.0.0';
	}
	$head = (string) file_get_contents( $file, false, null, 0, 8192 );
	return preg_match( '/^[ \t\/*#@]*Version:\s*(\S+)/mi', $head, $m ) ? $m[1] : '0.0.0';
}
