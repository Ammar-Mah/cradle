<?php
/**
 * Built-in example languages: German (de_DE) and Arabic (ar).
 *
 * examples/<locale>.json ships a complete translation of every Cradle string (format
 * `cradle-translations-examples`). The example locales are always present: they are created when
 * missing (activation and the first admin request after an update), cannot be deleted, and receive
 * shipped translations only for entries that have neither a translation nor a draft, so an
 * administrator's edits always win. Clearing an entry brings the shipped text back on the next
 * refresh. Arabic also translates WordPress' own "text direction" string to `rtl`, so the admin
 * switches to right-to-left even without a WordPress language pack.
 *
 * @package Cradle\Translations
 */

namespace Cradle\Translations;

defined( 'ABSPATH' ) || exit;

final class Examples {

	const LOCALES = array( 'de_DE', 'ar' );
	const OPTION  = 'cradle_translations_examples';
	const FORMAT  = 'cradle-translations-examples';

	public static function is_example( string $locale ): bool {
		return in_array( $locale, self::LOCALES, true );
	}

	public static function path( string $locale ): string {
		return CRADLE_TRANSLATIONS_DIR . 'examples/' . $locale . '.json';
	}

	/**
	 * Cheap change marker of a shipped file (no read): size and modification time.
	 */
	private static function signature( string $locale ): string {
		$path = self::path( $locale );
		return is_file( $path ) ? filesize( $path ) . ':' . filemtime( $path ) : '';
	}

	/**
	 * Installs or refreshes the examples when a catalog is missing or a shipped file changed.
	 * Ordinary requests cost one option read and two `stat` calls.
	 */
	public static function maybe_install() {
		if ( ! function_exists( 'cradle' ) || ! cradle() ) {
			return;
		}
		$applied = get_option( self::OPTION, array() );
		$applied = is_array( $applied ) ? $applied : array();
		foreach ( self::LOCALES as $locale ) {
			if ( Store::exists( $locale ) && ( $applied[ $locale ] ?? '' ) === self::signature( $locale ) ) {
				continue;
			}
			self::install( $locale );
		}
	}

	/**
	 * Shipped entries of a locale.
	 *
	 * @return array<int,array{domain:string,msgid:string,context:?string,msgidPlural:?string,forms:string[]}>|\WP_Error
	 */
	public static function read( string $locale ) {
		if ( ! self::is_example( $locale ) || ! is_file( self::path( $locale ) ) ) {
			return new \WP_Error( 'cradle_translations_example', __( 'No shipped example translation exists for this locale.', 'cradle-translations' ) );
		}
		$data = Validator::decode_json( (string) file_get_contents( self::path( $locale ) ), Config::MAX_CATALOG_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local packaged file.
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( self::FORMAT !== ( $data['format'] ?? '' ) || $locale !== ( $data['locale'] ?? '' ) || ! is_array( $data['entries'] ?? null ) ) {
			return new \WP_Error( 'cradle_translations_example', __( 'The shipped example translation file is malformed.', 'cradle-translations' ) );
		}
		$out = array();
		foreach ( $data['entries'] as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['domain'] ?? null ) || ! is_string( $row['msgid'] ?? null ) || ! is_array( $row['forms'] ?? null ) ) {
				continue;
			}
			$out[] = array(
				'domain'      => $row['domain'],
				'msgid'       => $row['msgid'],
				'context'     => isset( $row['context'] ) && '' !== $row['context'] ? (string) $row['context'] : null,
				'msgidPlural' => isset( $row['msgidPlural'] ) && '' !== $row['msgidPlural'] ? (string) $row['msgidPlural'] : null,
				'forms'       => array_values( array_map( 'strval', $row['forms'] ) ),
			);
		}
		return $out;
	}

	/**
	 * Creates the example catalog if needed and fills entries that have no translation and no draft.
	 *
	 * @return array{created:bool,filled:int,kept:int,invalid:string[]}|\WP_Error
	 */
	public static function install( string $locale ) {
		$entries = self::read( $locale );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}
		$created = false;
		if ( ! Store::exists( $locale ) ) {
			$manifest = Store::manifest();
			if ( ! $manifest ) {
				$manifest = Extractor::build_manifest();
				Store::write_manifest( $manifest );
			}
			$sources = $manifest['entries'];
			$result  = Store::mutate(
				$locale,
				'create',
				static function ( array $current, int $revision ) use ( $sources ) {
					$synced = Catalog::sync( $current, $sources, array( 'extracted', 'registered' ), $revision );
					return array(
						'catalog' => $synced['catalog'],
						'changed' => true,
						'summary' => 'Built-in example language created.',
					);
				},
				array( 'create' => true )
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$created = true;
		}
		$report = self::fill( $locale, $entries, 'examples' );
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		$applied            = get_option( self::OPTION, array() );
		$applied            = is_array( $applied ) ? $applied : array();
		$applied[ $locale ] = self::signature( $locale );
		update_option( self::OPTION, $applied, false );
		return array( 'created' => $created ) + $report;
	}

	/**
	 * Publishes the given translations for entries of an existing catalog that have neither a
	 * translation nor a draft. Entries of WordPress' own `default` domain are added when missing.
	 * Also used by site tooling to ship translations for a site's own theme strings.
	 *
	 * @param array  $entries Rows as returned by read().
	 * @param string $action  Log action name.
	 * @return array{filled:int,kept:int,invalid:string[]}|\WP_Error
	 */
	public static function fill( string $locale, array $entries, string $action = 'examples' ) {
		$report = array(
			'filled'  => 0,
			'kept'    => 0,
			'invalid' => array(),
		);
		$result = Store::mutate(
			$locale,
			$action,
			static function ( array $catalog, int $revision ) use ( $entries, $locale, &$report ) {
				$index = Catalog::index( $catalog )['by_identity'];
				foreach ( $entries as $row ) {
					$identity = Catalog::identity( $row['domain'], $row['msgid'], $row['context'], $row['msgidPlural'] );
					if ( isset( $index[ $identity ] ) ) {
						list( $domain, $pos ) = $index[ $identity ];
						$entry                = $catalog['domains'][ $domain ][ $pos ];
					} elseif ( 'default' === $row['domain'] ) {
						$domain                         = 'default';
						$entry                          = Catalog::new_entry( 'default', $row['msgid'], $row['context'], $row['msgidPlural'], 'imported', $revision );
						$catalog['domains']['default']  = $catalog['domains']['default'] ?? array();
						$catalog['domains']['default'][] = $entry;
						$pos                            = array_key_last( $catalog['domains']['default'] );
						$index[ $identity ]             = array( $domain, $pos );
					} else {
						continue; // The string is not (or no longer) in this catalog's sources.
					}
					if ( $entry['translations'] || null !== $entry['draft'] ) {
						++$report['kept'];
						continue;
					}
					$checked = Validator::check_translation( $entry, $row['forms'], $locale );
					if ( is_wp_error( $checked ) || ! $checked ) {
						$report['invalid'][] = $row['domain'] . ': ' . mb_substr( $row['msgid'], 0, 60 );
						continue;
					}
					$entry['translations']                 = $checked;
					$entry['changedRevision']              = $revision;
					$entry['status']                       = Catalog::status( $entry, $locale );
					$catalog['domains'][ $domain ][ $pos ] = $entry;
					++$report['filled'];
				}
				return array(
					'catalog' => $catalog,
					'changed' => $report['filled'] > 0,
					/* translators: %d: number of entries */
					'summary' => sprintf( _n( 'Shipped translation applied to %d entry.', 'Shipped translations applied to %d entries.', $report['filled'], 'cradle-translations' ), $report['filled'] ),
				);
			}
		);
		return is_wp_error( $result ) ? $result : $report;
	}
}
