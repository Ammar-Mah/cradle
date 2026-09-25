<?php
/**
 * Creates and maintains Cradle website child themes from this parent theme's starter (CLI only).
 *
 * A Cradle website is a child theme of this parent: everything site-specific lives in it (brief in
 * northstar.md, state and decisions in docs/, presentation, site components). Content types are
 * Cradle Content definitions. There is no site plugin.
 *
 * Usage:
 *   php <parent>/bin/child-theme.php new   --slug=acme --name="Acme Studio" [--site-id=<stable-id>] [--target=<themes dir>]
 *   php <parent>/bin/child-theme.php check --child=<child theme dir> [--add-missing]
 *
 * new    Copies starter/child-theme into <target>/<slug>. The target defaults to the themes directory that
 *        contains this parent (an installed site), or sites/<slug>/themes when run from the Cradle
 *        repository source. Never overwrites: an existing folder makes it refuse.
 * check  Compares a child with the current starter (after a parent update) and lists starter files it
 *        lacks; --add-missing copies them in (filled with the child's name and slug). Existing files are
 *        never changed.
 *
 * @package Cradle
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$parent  = dirname( __DIR__ );
$starter = $parent . '/starter/child-theme';
$command = $argv[1] ?? '';
$opts    = array();
// getopt() stops at the command word, so options after it are parsed here (--key=value or --flag).
foreach ( array_slice( $argv, 2 ) as $arg ) {
	if ( preg_match( '/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m ) && in_array( $m[1], array( 'slug', 'name', 'site-id', 'target', 'child', 'add-missing' ), true ) ) {
		$opts[ $m[1] ] = $m[2] ?? true;
	}
}

/** Framework package folder names a website slug must not take. */
const CRADLE_RESERVED_SLUGS = array( 'cradle', 'cradle-admin', 'cradle-blocks', 'cradle-content', 'cradle-dummy-content', 'cradle-duplicate', 'cradle-login', 'cradle-migration', 'cradle-social', 'cradle-translations' );

/**
 * Relative file list of the starter (paths as they appear in a child: ".tpl" removed).
 */
function cradle_starter_files( string $starter ): array {
	$out = array();
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $starter, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		$rel         = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $starter ) + 1 ) );
		$out[ $rel ] = preg_replace( '/\.tpl$/', '', $rel );
	}
	ksort( $out );
	return $out;
}

/**
 * Copies one starter file with placeholders replaced. Returns false when the target exists.
 */
function cradle_copy_starter_file( string $from, string $to, array $replacements ): bool {
	if ( file_exists( $to ) ) {
		return false;
	}
	if ( ! is_dir( dirname( $to ) ) ) {
		mkdir( dirname( $to ), 0775, true );
	}
	$contents = (string) file_get_contents( $from );
	if ( preg_match( '/\.(php|tpl|css|js|json|md|html|txt)$/', $from ) ) {
		$contents = strtr( $contents, $replacements );
	}
	return false !== file_put_contents( $to, $contents );
}

function cradle_fail( string $message ) {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

if ( 'new' === $command ) {
	$slug = (string) ( $opts['slug'] ?? '' );
	$name = trim( (string) ( $opts['name'] ?? '' ) );
	if ( ! preg_match( '/^[a-z][a-z0-9-]{1,38}[a-z0-9]$/', $slug ) || in_array( $slug, CRADLE_RESERVED_SLUGS, true ) ) {
		cradle_fail( '--slug must be a lowercase folder name (3–40 characters) that is not a Cradle package name.' );
	}
	if ( '' === $name || strlen( $name ) > 80 || preg_match( '/[<>{}\\\\]/', $name ) ) {
		cradle_fail( '--name is required (max 80 characters, no <>{}\\).' );
	}
	$site_id = (string) ( $opts['site-id'] ?? ( $slug . '-' . bin2hex( random_bytes( 4 ) ) ) );
	if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{2,60}$/', $site_id ) ) {
		cradle_fail( '--site-id must be lowercase letters, digits and dashes.' );
	}

	// Running from the Cradle repository source (packages/themes/cradle)?
	$repo    = dirname( $parent, 3 );
	$in_repo = is_file( $repo . '/scripts/sync.php' ) && is_dir( $repo . '/packages/themes/cradle' ) && realpath( $repo . '/packages/themes/cradle' ) === realpath( $parent );
	$target  = (string) ( $opts['target'] ?? ( $in_repo ? $repo . '/sites/' . $slug . '/themes' : dirname( $parent ) ) );
	$target  = preg_match( '#^([A-Za-z]:)?[/\\\\]#', $target ) ? $target : getcwd() . '/' . $target;
	$target  = rtrim( str_replace( '\\', '/', $target ), '/' );
	$dest    = $target . '/' . $slug;
	if ( file_exists( $dest ) ) {
		cradle_fail( "Refusing to overwrite existing {$dest}. Website folders belong to the website." );
	}

	$source_layout = '';
	$repo_norm     = str_replace( '\\', '/', (string) realpath( $repo ) );
	if ( $in_repo && 0 === strpos( $dest, $repo_norm . '/' ) ) {
		$up            = str_repeat( '../', substr_count( substr( $dest, strlen( $repo_norm ) + 1 ), '/' ) + 1 );
		$source_layout = ",\n  \"sourceLayout\": {\n"
			. "    \"note\": \"Use these paths when this child is opened from the Cradle repository. Edit here; scripts/sync.php mirrors packages into the sandbox.\",\n"
			. "    \"parentTheme\": \"{$up}packages/themes/cradle\",\n"
			. "    \"corePlugin\": \"{$up}packages/plugins/cradle\",\n"
			. "    \"pluginRoot\": \"{$up}packages/plugins\",\n"
			. "    \"skillIndex\": \"{$up}skills/INDEX.md\",\n"
			. "    \"sandbox\": \"{$up}wp\"\n"
			. '  }';
	}
	$replacements = array(
		'{{SITE_NAME}}'     => $name,
		'{{SITE_SLUG}}'     => $slug,
		'{{SITE_ID}}'       => $site_id,
		'{{SITE_PREFIX}}'   => str_replace( '-', '_', $slug ),
		'{{CREATED}}'       => gmdate( 'Y-m-d' ),
		'{{SOURCE_LAYOUT}}' => $source_layout,
	);
	foreach ( cradle_starter_files( $starter ) as $from => $to ) {
		if ( ! cradle_copy_starter_file( $starter . '/' . $from, $dest . '/' . $to, $replacements ) ) {
			cradle_fail( "Could not write {$dest}/{$to}." );
		}
	}
	echo "Created child theme: {$dest}\n";
	echo "Site ID: {$site_id}\n";
	echo "Next: put your website brief in {$dest}/northstar.md (empty; see the parent's docs/website-brief.md) and reference material in design-reference/, activate the child theme, then ask your coding agent to build it (skill cradle-design-to-wordpress).\n";
	exit( 0 );
}

if ( 'check' === $command ) {
	$child = rtrim( str_replace( '\\', '/', (string) ( $opts['child'] ?? '' ) ), '/' );
	if ( '' === $child || ! is_file( $child . '/style.css' ) ) {
		cradle_fail( '--child must point to a child theme folder.' );
	}
	$header = (string) file_get_contents( $child . '/style.css', false, null, 0, 8192 );
	if ( ! preg_match( '/^[ \t\/*#@]*Template:\s*cradle\s*$/mi', $header ) ) {
		cradle_fail( 'This theme is not a Cradle child theme (style.css must declare "Template: cradle").' );
	}
	$name      = preg_match( '/^[ \t\/*#@]*Theme Name:\s*(.+)$/mi', $header, $m ) ? trim( $m[1] ) : basename( $child );
	$slug      = basename( $child );
	$workspace = json_decode( (string) @file_get_contents( $child . '/cradle-workspace.json' ), true ); // phpcs:ignore
	$replacements = array(
		'{{SITE_NAME}}'     => $name,
		'{{SITE_SLUG}}'     => $slug,
		'{{SITE_ID}}'       => is_array( $workspace ) ? (string) ( $workspace['siteId'] ?? $slug ) : $slug,
		'{{SITE_PREFIX}}'   => str_replace( '-', '_', $slug ),
		'{{CREATED}}'       => gmdate( 'Y-m-d' ),
		'{{SOURCE_LAYOUT}}' => '',
	);
	$missing = array();
	foreach ( cradle_starter_files( $starter ) as $from => $to ) {
		if ( ! file_exists( $child . '/' . $to ) ) {
			$missing[ $from ] = $to;
		}
	}
	if ( ! $missing ) {
		echo "{$slug}: has every file of the current starter.\n";
		exit( 0 );
	}
	foreach ( $missing as $from => $to ) {
		if ( isset( $opts['add-missing'] ) ) {
			cradle_copy_starter_file( $starter . '/' . $from, $child . '/' . $to, $replacements );
			echo "added   {$to}\n";
		} else {
			echo "missing {$to}\n";
		}
	}
	exit( isset( $opts['add-missing'] ) ? 0 : 2 );
}

fwrite( STDERR, "Usage:\n  php bin/child-theme.php new --slug=<slug> --name=\"<Site Name>\" [--site-id=<id>] [--target=<themes dir>]\n  php bin/child-theme.php check --child=<child theme dir> [--add-missing]\n" );
exit( 1 );
