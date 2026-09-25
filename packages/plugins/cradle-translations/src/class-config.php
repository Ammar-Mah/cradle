<?php
/**
 * Constants, hard limits and settings access that work before the settings
 * section is registered (the runtime attaches on `cradle/ready`, before `init`).
 *
 * @package Cradle\Translations
 */

namespace Cradle\Translations;

defined( 'ABSPATH' ) || exit;

final class Config {

	const MODULE       = 'translations';
	const TEXT_DOMAIN  = 'cradle-translations';
	const CAP          = 'cradle_manage_translations';
	const OPTION       = 'cradle_translations_settings';
	const INDEX_OPTION = 'cradle_translations_index';
	const STATE_OPTION = 'cradle_translations_state';
	const CRON_REFRESH = 'cradle_translations_refresh';
	const LOCK_PREFIX  = 'translations:';
	const CACHE_GROUP  = 'cradle_translations';

	/** Catalog schema version written by this package. */
	const SCHEMA_VERSION = 1;

	/** Hard ceilings (settings may only lower the import size). */
	const MAX_IMPORT_BYTES  = 8388608;   // 8 MiB per uploaded catalog.
	const MAX_CATALOG_BYTES = 25165824;  // 24 MiB per stored catalog.
	const MAX_ENTRIES       = 20000;     // Entries per catalog.
	const MAX_DOMAINS       = 200;       // Text domains per catalog.
	const MAX_STRING_CHARS  = 10000;     // Characters per source or translated string.
	const MAX_JSON_DEPTH    = 8;         // json_decode() nesting limit for catalogs.
	const MAX_REFERENCES    = 30;        // Source references kept per entry.
	const MAX_REFERENCE_LEN = 300;
	const MAX_COMMENT_CHARS = 2000;
	const MAX_PLURAL_FORMS  = 6;
	const MAX_LOG           = 100;       // Revision log rows kept inside a catalog.
	const LOCK_TTL          = 30;

	/**
	 * Defaults of the `translations` settings section. Labels live in Module::settings_section().
	 */
	public static function defaults(): array {
		return array(
			'runtime_enabled' => true,
			'js_enabled'      => true,
			'expose_locales'  => true,
			'auto_refresh'    => true,
			'history_limit'   => 20,
			'max_import_kb'   => 4096,
			'per_page'        => 50,
		);
	}

	/**
	 * Reads one setting from the stored option merged over defaults. Never writes.
	 *
	 * @return mixed
	 */
	public static function get( string $key ) {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		if ( ! array_key_exists( $key, $defaults ) ) {
			return null;
		}
		$value = array_key_exists( $key, $stored ) ? $stored[ $key ] : $defaults[ $key ];
		if ( is_bool( $defaults[ $key ] ) ) {
			return (bool) $value;
		}
		if ( is_int( $defaults[ $key ] ) ) {
			return (int) $value;
		}
		return $value;
	}

	public static function import_limit(): int {
		$kb = max( 64, min( 8192, (int) self::get( 'max_import_kb' ) ) );
		return min( self::MAX_IMPORT_BYTES, $kb * 1024 );
	}

	/**
	 * Canonical WordPress locale code check (en_US, de_DE_formal, pt_PT_ao90, ar, ca_valencia).
	 */
	public static function valid_locale( $locale ): bool {
		return is_string( $locale ) && strlen( $locale ) <= 32 && (bool) preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z0-9]{2,20})?$/', $locale );
	}

	/**
	 * Text domain check. WordPress domains are slugs; `default` is WordPress core.
	 */
	public static function valid_domain( $domain ): bool {
		return is_string( $domain ) && (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/', $domain );
	}
}
