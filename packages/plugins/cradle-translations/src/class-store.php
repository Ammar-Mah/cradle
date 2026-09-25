<?php
/**
 * Catalog persistence in Cradle private storage (outside updateable package code).
 *
 * Layout under `module_dir( 'translations', ... )`:
 *   catalogs/<locale>.json          authoritative catalog (pretty JSON)
 *   history/<locale>.r<N>.json      snapshot of revision N taken before it was replaced
 *   runtime/<locale>.json           derived lookup map, regenerated when published data changes
 *   manifest/manifest.json          extracted source manifest of Cradle packages
 * Only JSON files are written here; translator input never becomes executable PHP.
 *
 * Every write: named lock → read current file inside the lock → caller callback
 * (performs stale-revision checks) → size check → history snapshot → temp file +
 * rename → derived runtime + index update when published data changed.
 *
 * @package Cradle\Translations
 */

namespace Cradle\Translations;

defined( 'ABSPATH' ) || exit;

final class Store {

	/** @var array<string,string> */
	private static $dirs = array();

	/**
	 * @return string|\WP_Error
	 */
	public static function dir( string $sub ) {
		if ( isset( self::$dirs[ $sub ] ) && is_dir( self::$dirs[ $sub ] ) ) {
			return self::$dirs[ $sub ];
		}
		if ( ! in_array( $sub, array( 'catalogs', 'history', 'runtime', 'manifest' ), true ) ) {
			return new \WP_Error( 'cradle_translations_storage', 'Unknown storage area.' );
		}
		$core = function_exists( 'cradle' ) ? cradle() : null;
		if ( ! $core ) {
			return new \WP_Error( 'cradle_translations_core', __( 'Cradle core is not available.', 'cradle-translations' ) );
		}
		$dir = $core->storage()->module_dir( Config::MODULE, $sub );
		if ( ! is_wp_error( $dir ) ) {
			self::$dirs[ $sub ] = $dir;
		}
		return $dir;
	}

	/**
	 * @return string|\WP_Error
	 */
	private static function path( string $sub, string $locale, string $suffix = '.json' ) {
		if ( ! Config::valid_locale( $locale ) ) {
			return new \WP_Error( 'cradle_translations_locale', __( 'Invalid locale code.', 'cradle-translations' ) );
		}
		$dir = self::dir( $sub );
		return is_wp_error( $dir ) ? $dir : $dir . '/' . $locale . $suffix;
	}

	/**
	 * @return string[] Locales that have a catalog.
	 */
	public static function locales(): array {
		$dir = self::dir( 'catalogs' );
		if ( is_wp_error( $dir ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) scandir( $dir ) as $file ) {
			if ( is_string( $file ) && preg_match( '/^([A-Za-z0-9_]+)\.json$/', $file, $m ) && Config::valid_locale( $m[1] ) ) {
				$out[] = $m[1];
			}
		}
		sort( $out );
		return $out;
	}

	public static function exists( string $locale ): bool {
		$path = self::path( 'catalogs', $locale );
		return ! is_wp_error( $path ) && is_file( $path );
	}

	/**
	 * Reads and validates a JSON file with a size bound.
	 *
	 * @return array|\WP_Error
	 */
	private static function read_json( string $path, int $max_bytes ) {
		if ( ! is_file( $path ) || is_link( $path ) ) {
			return new \WP_Error( 'cradle_translations_not_found', __( 'The catalog does not exist.', 'cradle-translations' ) );
		}
		$size = filesize( $path );
		if ( false === $size || $size > $max_bytes ) {
			return new \WP_Error( 'cradle_translations_size', __( 'The stored file is larger than the allowed size.', 'cradle-translations' ) );
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw ) {
			return new \WP_Error( 'cradle_translations_read', __( 'The stored file could not be read.', 'cradle-translations' ) );
		}
		return Validator::decode_json( $raw, $max_bytes );
	}

	/**
	 * @return array|\WP_Error Validated catalog.
	 */
	public static function read( string $locale ) {
		$path = self::path( 'catalogs', $locale );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$data = self::read_json( $path, Config::MAX_CATALOG_BYTES );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return Validator::catalog( $data, array( 'locale' => $locale ) );
	}

	/**
	 * @return array|\WP_Error Validated snapshot of an earlier revision.
	 */
	public static function read_snapshot( string $locale, int $revision ) {
		$path = self::path( 'history', $locale, '.r' . max( 0, $revision ) . '.json' );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$data = self::read_json( $path, Config::MAX_CATALOG_BYTES );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return Validator::catalog( $data, array( 'locale' => $locale ) );
	}

	/**
	 * @return int[] Revisions with a stored snapshot, newest first.
	 */
	public static function snapshots( string $locale ): array {
		$dir = self::dir( 'history' );
		if ( is_wp_error( $dir ) || ! Config::valid_locale( $locale ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) scandir( $dir ) as $file ) {
			if ( is_string( $file ) && preg_match( '/^' . preg_quote( $locale, '/' ) . '\.r(\d{1,9})\.json$/', $file, $m ) ) {
				$out[] = (int) $m[1];
			}
		}
		rsort( $out );
		return $out;
	}

	public static function encode( array $data, bool $pretty = true ): string {
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ( $pretty ? JSON_PRETTY_PRINT : 0 );
		return (string) wp_json_encode( $data, $flags );
	}

	/**
	 * Atomic replace: write a temporary file in the same directory, then rename over the target.
	 *
	 * @return true|\WP_Error
	 */
	public static function write_atomic( string $path, string $contents ) {
		$dir = dirname( $path );
		if ( ! str_ends_with( $path, '.json' ) ) {
			return new \WP_Error( 'cradle_translations_write', 'Only JSON files are written.' );
		}
		$tmp   = $dir . '/.' . basename( $path, '.json' ) . '.' . strtolower( wp_generate_password( 10, false, false ) ) . '.tmp';
		$bytes = file_put_contents( $tmp, $contents, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( strlen( $contents ) !== $bytes ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'cradle_translations_write', __( 'The catalog could not be written to private storage.', 'cradle-translations' ) );
		}
		// Windows refuses to replace a file another process has open for a moment; retry briefly.
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			if ( @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				return true;
			}
			usleep( 40000 );
		}
		wp_delete_file( $tmp );
		return new \WP_Error( 'cradle_translations_write', __( 'The catalog could not be replaced atomically.', 'cradle-translations' ) );
	}

	/**
	 * The locale → published runtime summary index (small, autoloaded).
	 */
	public static function index(): array {
		$index = get_option( Config::INDEX_OPTION, array() );
		$index = is_array( $index ) ? $index : array();
		$index += array(
			'locales' => array(),
			'domains' => array(),
		);
		return $index;
	}

	private static function save_index( array $index ) {
		$domains = array();
		foreach ( $index['locales'] as $row ) {
			foreach ( array_keys( $row['domains'] ?? array() ) as $domain ) {
				$domains[ $domain ] = true;
			}
		}
		ksort( $domains );
		$index['domains'] = $domains;
		update_option( Config::INDEX_OPTION, $index, true );
	}

	/**
	 * @return array|null Validated runtime map for a locale, or null when none is published.
	 */
	public static function read_runtime( string $locale ) {
		$path = self::path( 'runtime', $locale );
		if ( is_wp_error( $path ) || ! is_file( $path ) ) {
			return null;
		}
		$data = self::read_json( $path, Config::MAX_CATALOG_BYTES );
		if ( is_wp_error( $data ) || 1 !== ( $data['schemaVersion'] ?? null ) || $locale !== ( $data['locale'] ?? null ) || ! is_array( $data['domains'] ?? null ) ) {
			return null;
		}
		$domains = array();
		foreach ( $data['domains'] as $domain => $maps ) {
			if ( ! Config::valid_domain( (string) $domain ) || ! is_array( $maps ) ) {
				continue;
			}
			foreach ( array( 's', 'p', 'j' ) as $kind ) {
				if ( isset( $maps[ $kind ] ) && is_array( $maps[ $kind ] ) ) {
					$domains[ $domain ][ $kind ] = $maps[ $kind ];
				}
			}
		}
		return array(
			'locale'  => $locale,
			'rule'    => Plural_Rules::rule_id( $locale ),
			'header'  => Plural_Rules::header( $locale ),
			'domains' => $domains,
			'hash'    => is_string( $data['hash'] ?? null ) ? $data['hash'] : '',
		);
	}

	/**
	 * Writes (or removes) the derived runtime file and updates the index.
	 *
	 * @return true|\WP_Error
	 */
	public static function publish_runtime( string $locale, array $runtime ) {
		$path = self::path( 'runtime', $locale );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$index = self::index();
		if ( ! $runtime['domains'] ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
			unset( $index['locales'][ $locale ] );
		} else {
			$written = self::write_atomic( $path, self::encode( $runtime, false ) );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
			$row = array(
				'hash'     => $runtime['hash'],
				'revision' => $runtime['revision'],
				'domains'  => array(),
				'js'       => array(),
			);
			foreach ( $runtime['domains'] as $domain => $maps ) {
				$row['domains'][ $domain ] = count( $maps['s'] ?? array() ) + count( $maps['p'] ?? array() );
				if ( ! empty( $maps['j'] ) ) {
					$row['js'][ $domain ] = count( $maps['j'] );
				}
			}
			$index['locales'][ $locale ] = $row;
		}
		self::save_index( $index );
		wp_cache_delete( 'map:' . $locale, Config::CACHE_GROUP );
		Runtime::instance()->invalidate( $locale );
		return true;
	}

	/**
	 * The single write path for catalogs.
	 *
	 * @param callable $mutator fn( array $current, int $new_revision ): array{catalog:array,changed:bool,summary:string}|\WP_Error
	 * @param array    $opts    create (bool): start from an empty catalog when none exists.
	 * @return array{catalog:array,written:bool,runtimeChanged:bool,summary:string}|\WP_Error
	 */
	public static function mutate( string $locale, string $action, callable $mutator, array $opts = array() ) {
		if ( ! Config::valid_locale( $locale ) ) {
			return new \WP_Error( 'cradle_translations_locale', __( 'Invalid locale code.', 'cradle-translations' ) );
		}
		$core = function_exists( 'cradle' ) ? cradle() : null;
		if ( ! $core ) {
			return new \WP_Error( 'cradle_translations_core', __( 'Cradle core is not available.', 'cradle-translations' ) );
		}
		$path = self::path( 'catalogs', $locale );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$lock   = Config::LOCK_PREFIX . 'catalog:' . $locale;
		$handle = $core->locks()->acquire( $lock, Config::LOCK_TTL );
		if ( ! $handle ) {
			return new \WP_Error( 'cradle_translations_busy', __( 'Another change to this catalog is in progress. Try again in a moment.', 'cradle-translations' ) );
		}
		try {
			$exists = is_file( $path );
			if ( $exists ) {
				$current = self::read( $locale );
				if ( is_wp_error( $current ) ) {
					return $current;
				}
			} elseif ( ! empty( $opts['create'] ) ) {
				$current = Catalog::create( $locale );
			} else {
				return new \WP_Error( 'cradle_translations_not_found', __( 'The catalog does not exist.', 'cradle-translations' ) );
			}
			$new_revision = $current['revision'] + 1;
			$result       = call_user_func( $mutator, $current, $new_revision );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$summary = (string) ( $result['summary'] ?? $action );
			$extra   = array_diff_key( $result, array_flip( array( 'catalog', 'changed', 'summary' ) ) );
			if ( $exists && empty( $result['changed'] ) ) {
				return array(
					'catalog'        => $current,
					'written'        => false,
					'runtimeChanged' => false,
					'summary'        => $summary,
				) + $extra;
			}
			$catalog                  = $result['catalog'];
			$catalog['schemaVersion'] = Config::SCHEMA_VERSION;
			$catalog['locale']        = $locale;
			$catalog['revision']      = $new_revision;
			$catalog['updated']       = gmdate( 'c' );
			$catalog['pluralRule']    = Plural_Rules::rule_id( $locale );
			Catalog::refresh_statuses( $catalog );

			$runtime   = Catalog::runtime( $catalog );
			$old_hash  = (string) ( self::index()['locales'][ $locale ]['hash'] ?? '' );
			$runtime_p = self::path( 'runtime', $locale );
			$changed   = $old_hash !== $runtime['hash'] || ( '' !== $runtime['hash'] && ! is_wp_error( $runtime_p ) && ! is_file( $runtime_p ) );
			if ( $changed ) {
				$catalog['publishedRevision'] = $new_revision;
			}
			$catalog['log'][] = array(
				'revision' => $new_revision,
				'time'     => gmdate( 'c' ),
				'user'     => get_current_user_id(),
				'action'   => sanitize_key( $action ),
				'summary'  => mb_substr( $summary, 0, 300 ),
			);
			$catalog['log'] = array_slice( $catalog['log'], -Config::MAX_LOG );

			$json = self::encode( $catalog );
			if ( '' === $json ) {
				return new \WP_Error( 'cradle_translations_encode', __( 'The catalog could not be encoded as JSON.', 'cradle-translations' ) );
			}
			if ( strlen( $json ) > Config::MAX_CATALOG_BYTES ) {
				return new \WP_Error( 'cradle_translations_size', __( 'The catalog would exceed the maximum stored size. Nothing was changed.', 'cradle-translations' ) );
			}
			if ( $exists ) {
				$snapshot = self::path( 'history', $locale, '.r' . $current['revision'] . '.json' );
				if ( is_wp_error( $snapshot ) || ! copy( $path, $snapshot ) ) {
					return new \WP_Error( 'cradle_translations_history', __( 'The previous revision could not be saved to history. Nothing was changed.', 'cradle-translations' ) );
				}
			}
			if ( ! $core->locks()->holds( $lock, $handle ) ) {
				return new \WP_Error( 'cradle_translations_lock_lost', __( 'The catalog lock expired before the write. Nothing was changed; try again.', 'cradle-translations' ) );
			}
			$written = self::write_atomic( $path, $json );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
			if ( $changed ) {
				$published = self::publish_runtime( $locale, $runtime );
				if ( is_wp_error( $published ) ) {
					return $published;
				}
			}
			self::prune_history( $locale );
			cradle_log_activity(
				Config::MODULE,
				'catalog_' . sanitize_key( $action ),
				sprintf( 'Translation catalog %1$s changed to revision %2$d (%3$s).', $locale, $new_revision, mb_substr( $summary, 0, 120 ) ),
				array(
					'locale'   => $locale,
					'revision' => $new_revision,
				)
			);
			return array(
				'catalog'        => $catalog,
				'written'        => true,
				'runtimeChanged' => $changed,
				'summary'        => $summary,
			) + $extra;
		} finally {
			$core->locks()->release( $lock, $handle );
		}
	}

	public static function prune_history( string $locale ) {
		$keep = max( 3, min( 100, (int) Config::get( 'history_limit' ) ) );
		foreach ( array_slice( self::snapshots( $locale ), $keep ) as $revision ) {
			$path = self::path( 'history', $locale, '.r' . $revision . '.json' );
			if ( ! is_wp_error( $path ) && is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Deletes a locale's catalog, history and runtime data.
	 *
	 * @return true|\WP_Error
	 */
	public static function delete_locale( string $locale ) {
		$core = function_exists( 'cradle' ) ? cradle() : null;
		if ( ! $core || ! Config::valid_locale( $locale ) ) {
			return new \WP_Error( 'cradle_translations_locale', __( 'Invalid locale code.', 'cradle-translations' ) );
		}
		$lock   = Config::LOCK_PREFIX . 'catalog:' . $locale;
		$handle = $core->locks()->acquire( $lock, Config::LOCK_TTL );
		if ( ! $handle ) {
			return new \WP_Error( 'cradle_translations_busy', __( 'Another change to this catalog is in progress. Try again in a moment.', 'cradle-translations' ) );
		}
		try {
			foreach ( self::snapshots( $locale ) as $revision ) {
				$path = self::path( 'history', $locale, '.r' . $revision . '.json' );
				if ( ! is_wp_error( $path ) ) {
					wp_delete_file( $path );
				}
			}
			foreach ( array( 'catalogs', 'runtime' ) as $sub ) {
				$path = self::path( $sub, $locale );
				if ( ! is_wp_error( $path ) && is_file( $path ) ) {
					wp_delete_file( $path );
				}
			}
			$index = self::index();
			unset( $index['locales'][ $locale ] );
			self::save_index( $index );
			wp_cache_delete( 'map:' . $locale, Config::CACHE_GROUP );
			Runtime::instance()->invalidate( $locale );
		} finally {
			$core->locks()->release( $lock, $handle );
		}
		cradle_log_activity( Config::MODULE, 'locale_deleted', sprintf( 'Translation catalog %s deleted with its history.', $locale ), array( 'locale' => $locale ) );
		return true;
	}

	/**
	 * @return array|null Stored source manifest.
	 */
	public static function manifest() {
		$dir = self::dir( 'manifest' );
		if ( is_wp_error( $dir ) ) {
			return null;
		}
		$path = $dir . '/manifest.json';
		if ( ! is_file( $path ) || filesize( $path ) > Config::MAX_CATALOG_BYTES ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $path ), true, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_array( $data ) && 1 === ( $data['schemaVersion'] ?? null ) && is_array( $data['entries'] ?? null ) ? $data : null;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function write_manifest( array $manifest ) {
		$dir = self::dir( 'manifest' );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		return self::write_atomic( $dir . '/manifest.json', self::encode( $manifest ) );
	}
}
