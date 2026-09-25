<?php
/**
 * Mirrors Cradle source packages into the sandbox WordPress (wp/wp-content).
 *
 * Development happens in this repository; the sandbox is a disposable test
 * target. Each package directory in the sandbox is an exact mirror of its
 * source plus the canonical skills/briefs injected by this script (the same
 * injection the release build performs). Files outside the targeted package
 * directories are never touched, so user content, uploads, and other
 * packages survive a sync exactly as they would survive a real package update.
 *
 * Usage:
 *   php scripts/sync.php                     Sync every package whose source exists.
 *   php scripts/sync.php cradle-blocks cradle  Sync named packages (theme and/or plugin).
 *   php scripts/sync.php --type=plugin       Limit by type.
 *   php scripts/sync.php --hook              Read a Claude Code hook payload on stdin and
 *                                            sync only the package owning the edited file.
 *   Flags: --dry-run  --verbose  --quiet
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require __DIR__ . '/lib/packages.php';

$root     = dirname( __DIR__ );
$manifest = cradle_manifest( $root );

$args    = array_slice( $argv, 1 );
$flags   = array_filter( $args, fn( $a ) => str_starts_with( $a, '--' ) );
$names   = array_values( array_diff( $args, $flags ) );
$dry     = in_array( '--dry-run', $flags, true );
$verbose = in_array( '--verbose', $flags, true );
$quiet   = in_array( '--quiet', $flags, true );
$type    = null;
foreach ( $flags as $flag ) {
	if ( str_starts_with( $flag, '--type=' ) ) {
		$type = substr( $flag, 7 );
	}
}

$packages = $manifest['packages'];

if ( in_array( '--hook', $flags, true ) ) {
	$payload = json_decode( (string) stream_get_contents( STDIN ), true );
	$file    = $payload['tool_input']['file_path'] ?? ( $payload['tool_input']['notebook_path'] ?? '' );
	$file    = str_replace( '\\', '/', (string) $file );
	$base    = str_replace( '\\', '/', $root ) . '/';
	if ( '' === $file || 0 !== stripos( $file, $base ) ) {
		exit( 0 );
	}
	$rel      = substr( $file, strlen( $base ) );
	$selected = array();
	foreach ( $packages as $pkg ) {
		if ( 0 === stripos( $rel, $pkg['source'] . '/' ) ) {
			$selected[] = $pkg;
		} elseif ( ( str_starts_with( $rel, 'skills/' ) && ! empty( $pkg['skills'] ) ) || in_array( $rel, $pkg['inject'] ?? array(), true ) ) {
			$selected[] = $pkg;
		}
	}
	if ( ! $selected ) {
		exit( 0 );
	}
	$packages = $selected;
	$quiet    = true;
} else {
	$packages = array_values(
		array_filter(
			$packages,
			fn( $p ) => ( ! $names || in_array( $p['name'], $names, true ) ) && ( ! $type || $p['type'] === $type )
		)
	);
}

$wp_content = $root . '/' . $manifest['wpRoot'] . '/wp-content';
if ( ! is_dir( $wp_content ) ) {
	fwrite( STDERR, "Sandbox wp-content not found at {$wp_content}\n" );
	exit( 1 );
}

foreach ( $packages as $pkg ) {
	$src = $root . '/' . $pkg['source'];
	if ( ! is_dir( $src ) ) {
		if ( $verbose ) {
			echo "skip {$pkg['type']}/{$pkg['name']} (no source)\n";
		}
		continue;
	}
	if ( ! preg_match( '/^[a-z0-9-]+$/', $pkg['name'] ) || ! in_array( $pkg['type'], array( 'theme', 'plugin' ), true ) ) {
		fwrite( STDERR, "Invalid package entry\n" );
		exit( 1 );
	}
	$dest  = $wp_content . '/' . $pkg['type'] . 's/' . $pkg['name'];
	$files = cradle_package_files( $root, $manifest, $pkg );

	$copied  = 0;
	$deleted = 0;
	foreach ( $files as $rel => $entry ) {
		$target = $dest . '/' . $rel;
		if ( isset( $entry['content'] ) ) {
			if ( ! is_file( $target ) || file_get_contents( $target ) !== $entry['content'] ) {
				$copied++;
				if ( ! $dry ) {
					sync_ensure_dir( dirname( $target ) );
					file_put_contents( $target, $entry['content'] );
				}
				if ( $verbose ) {
					echo "  gen  {$rel}\n";
				}
			}
			continue;
		}
		if ( ! is_file( $target ) || filesize( $target ) !== filesize( $entry['path'] ) || hash_file( 'sha1', $target ) !== hash_file( 'sha1', $entry['path'] ) ) {
			$copied++;
			if ( ! $dry ) {
				sync_ensure_dir( dirname( $target ) );
				copy( $entry['path'], $target );
				touch( $target, filemtime( $entry['path'] ) );
			}
			if ( $verbose ) {
				echo "  copy {$rel}\n";
			}
		}
	}
	if ( is_dir( $dest ) ) {
		foreach ( cradle_list_files( $dest, array() ) as $rel ) {
			if ( ! isset( $files[ $rel ] ) ) {
				$deleted++;
				if ( ! $dry ) {
					unlink( $dest . '/' . $rel );
				}
				if ( $verbose ) {
					echo "  del  {$rel}\n";
				}
			}
		}
		if ( ! $dry ) {
			sync_remove_empty_dirs( $dest );
		}
	}
	if ( ! $quiet || $copied || $deleted ) {
		printf( "%s %s/%s: %d updated, %d removed\n", $dry ? '[dry]' : 'synced', $pkg['type'], $pkg['name'], $copied, $deleted );
	}
}

if ( ! $dry ) {
	$wrappers = cradle_write_skill_wrappers( $root );
	if ( $wrappers && ( ! $quiet || $verbose ) ) {
		echo "skill wrappers: {$wrappers} updated\n";
	}
}

exit( 0 );

function sync_ensure_dir( string $dir ) {
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0775, true );
	}
}

function sync_remove_empty_dirs( string $dir ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $item ) {
		if ( $item->isDir() && 2 === count( scandir( $item->getPathname() ) ) ) {
			rmdir( $item->getPathname() );
		}
	}
}
