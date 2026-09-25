<?php
/**
 * Archive path validation and canonical containment.
 *
 * Archive paths are untrusted. They are validated as relative, portable,
 * non-traversing names before anything is extracted, and every filesystem write
 * is re-checked against its root with Cradle\Core\Storage::path_within(), which
 * resolves existing symlinked parents.
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

use Cradle\Core\Storage;

defined( 'ABSPATH' ) || exit;

final class Path_Guard {

	const RESERVED   = '/^(con|prn|aux|nul|com[0-9]|lpt[0-9]|conin\$|conout\$)$/i';
	const EXECUTABLE = '/\.(php[0-9]?|phtml|phar|pht|phps|shtml|cgi|pl|py|sh|asp|aspx|jsp)$/i';

	/**
	 * @return true|\WP_Error
	 */
	public static function validate_relative( string $path, int $max_depth = 32, int $max_len = 512 ) {
		$err = static function ( string $why ) use ( $path ) {
			return new \WP_Error( 'cradle_migration_path', sprintf( 'Rejected archive path "%1$s": %2$s.', self::printable( $path ), $why ) );
		};
		if ( '' === $path ) {
			return $err( 'empty path' );
		}
		if ( strlen( $path ) > $max_len ) {
			return $err( 'path too long' );
		}
		if ( false !== strpos( $path, "\0" ) ) {
			return $err( 'null byte' );
		}
		if ( preg_match( '/[\x01-\x1f\x7f]/', $path ) ) {
			return $err( 'control character' );
		}
		if ( ! mb_check_encoding( $path, 'UTF-8' ) ) {
			return $err( 'invalid UTF-8' );
		}
		if ( false !== strpos( $path, '\\' ) ) {
			return $err( 'backslash' );
		}
		if ( false !== strpos( $path, ':' ) ) {
			return $err( 'drive letter or stream separator' );
		}
		if ( '/' === $path[0] || '~' === $path[0] ) {
			return $err( 'absolute path' );
		}
		$segments = explode( '/', $path );
		if ( count( $segments ) > $max_depth ) {
			return $err( 'too deep' );
		}
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return $err( 'empty or traversal segment' );
			}
			if ( '.' === substr( $segment, -1 ) || ' ' === substr( $segment, -1 ) ) {
				return $err( 'segment ends with a dot or space' );
			}
			if ( preg_match( '/[<>"|?*]/', $segment ) ) {
				return $err( 'reserved character' );
			}
			$stem = strtok( $segment, '.' );
			if ( false !== $stem && preg_match( self::RESERVED, $stem ) ) {
				return $err( 'reserved device name' );
			}
		}
		return true;
	}

	/**
	 * Finds case-insensitive duplicates and file/directory conflicts in a path list.
	 *
	 * @param string[] $paths Paths (already individually validated), e.g. "uploads/2026/a.jpg".
	 * @return string[] Human-readable conflicts (empty when none).
	 */
	public static function conflicts( array $paths ): array {
		$seen     = array();
		$dirs     = array();
		$problems = array();
		foreach ( $paths as $path ) {
			$key = mb_strtolower( $path, 'UTF-8' );
			if ( isset( $seen[ $key ] ) ) {
				$problems[] = sprintf( 'Duplicate or case-conflicting path "%s".', self::printable( $path ) );
				continue;
			}
			$seen[ $key ] = true;
			$parts        = explode( '/', $key );
			array_pop( $parts );
			$prefix = '';
			foreach ( $parts as $part ) {
				$prefix          .= ( '' === $prefix ? '' : '/' ) . $part;
				$dirs[ $prefix ] = true;
			}
		}
		foreach ( array_keys( $seen ) as $key ) {
			if ( isset( $dirs[ $key ] ) ) {
				$problems[] = sprintf( 'Path "%s" is both a file and a directory.', self::printable( $key ) );
			}
		}
		return $problems;
	}

	/**
	 * Joins a validated relative path to a root and proves canonical containment,
	 * refusing any existing symlink between the root and the target.
	 *
	 * @return string|\WP_Error Absolute normalized path.
	 */
	public static function join( string $root, string $rel ) {
		$valid = self::validate_relative( $rel, 64, 1024 );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$root   = rtrim( wp_normalize_path( $root ), '/' );
		$target = $root . '/' . $rel;
		$probe  = $root;
		foreach ( explode( '/', $rel ) as $segment ) {
			$probe .= '/' . $segment;
			if ( is_link( $probe ) ) {
				return new \WP_Error( 'cradle_migration_symlink', sprintf( 'Refused to write through an existing symbolic link at "%s".', self::printable( $rel ) ) );
			}
			if ( ! file_exists( $probe ) ) {
				break;
			}
		}
		if ( ! Storage::path_within( $target, $root ) ) {
			return new \WP_Error( 'cradle_migration_escape', sprintf( 'Path "%s" resolves outside its root.', self::printable( $rel ) ) );
		}
		return $target;
	}

	public static function is_executable_name( string $path ): bool {
		return (bool) preg_match( self::EXECUTABLE, $path ) || (bool) preg_match( '/\.(php[0-9]?|phtml|phar)\./i', basename( $path ) );
	}

	public static function printable( string $path ): string {
		$clean = preg_replace( '/[^\x20-\x7e]/', '?', $path );
		return strlen( (string) $clean ) > 160 ? substr( (string) $clean, 0, 157 ) . '...' : (string) $clean;
	}
}
