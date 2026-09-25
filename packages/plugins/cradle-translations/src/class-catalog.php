<?php
/**
 * Pure operations on a catalog array (no I/O).
 *
 * Catalog shape (schemaVersion 1), one per locale:
 *   { schemaVersion, locale, revision, publishedRevision, updated, pluralRule,
 *     domains: { "<text domain>": [ entry, ... ] }, log: [ ... ] }
 * Entry identity = text domain + msgid + context + plural original. The `id`
 * is a stable hash of that identity for the UI; it never replaces the gettext identity.
 * `translations` holds the published forms; `draft` holds unpublished forms
 * (null = no pending draft, [] = pending removal).
 *
 * @package Cradle\Translations
 */

namespace Cradle\Translations;

defined( 'ABSPATH' ) || exit;

final class Catalog {

	public static function identity( string $domain, string $msgid, ?string $context, ?string $plural ): string {
		return $domain . "\x1F" . ( null === $context ? '' : $context . "\x04" ) . $msgid . ( null === $plural ? '' : "\x00" . $plural );
	}

	public static function entry_id( string $domain, string $msgid, ?string $context, ?string $plural ): string {
		return 'e' . substr( md5( self::identity( $domain, $msgid, $context, $plural ) ), 0, 16 );
	}

	/**
	 * Gettext lookup key: "context\x04msgid" (Jed uses the same separator).
	 */
	public static function lookup_key( string $msgid, ?string $context ): string {
		return ( null === $context || '' === $context ? '' : $context . "\x04" ) . $msgid;
	}

	public static function create( string $locale ): array {
		return array(
			'schemaVersion'     => Config::SCHEMA_VERSION,
			'locale'            => $locale,
			'revision'          => 0,
			'publishedRevision' => 0,
			'updated'           => '',
			'pluralRule'        => Plural_Rules::rule_id( $locale ),
			'domains'           => array(),
			'log'               => array(),
		);
	}

	public static function new_entry( string $domain, string $msgid, ?string $context, ?string $plural, string $source, int $revision ): array {
		return array(
			'id'              => self::entry_id( $domain, $msgid, $context, $plural ),
			'msgid'           => $msgid,
			'context'         => $context,
			'msgidPlural'     => $plural,
			'translations'    => array(),
			'draft'           => null,
			'draftFuzzy'      => null,
			'fuzzy'           => false,
			'obsolete'        => false,
			'source'          => $source,
			'references'      => array(),
			'comments'        => '',
			'js'              => false,
			'changedRevision' => $revision,
			'status'          => 'missing',
		);
	}

	/**
	 * Whether forms are a complete translation for the entry in the locale.
	 */
	public static function complete( array $entry, ?array $forms, string $locale ): bool {
		if ( ! $forms ) {
			return false;
		}
		$expected = null === $entry['msgidPlural'] ? 1 : Plural_Rules::nplurals( $locale );
		if ( count( $forms ) !== $expected ) {
			return false;
		}
		foreach ( $forms as $form ) {
			if ( '' === $form ) {
				return false;
			}
		}
		return true;
	}

	public static function status( array $entry, string $locale ): string {
		if ( ! empty( $entry['obsolete'] ) ) {
			return 'obsolete';
		}
		if ( ! self::complete( $entry, $entry['translations'], $locale ) ) {
			return 'missing';
		}
		return ! empty( $entry['fuzzy'] ) ? 'fuzzy' : 'translated';
	}

	/**
	 * @return array{by_id:array<string,array{0:string,1:int}>,by_identity:array<string,array{0:string,1:int}>}
	 */
	public static function index( array $catalog ): array {
		$by_id       = array();
		$by_identity = array();
		foreach ( $catalog['domains'] as $domain => $entries ) {
			foreach ( $entries as $pos => $entry ) {
				$by_id[ $entry['id'] ] = array( $domain, $pos );
				$by_identity[ self::identity( $domain, $entry['msgid'], $entry['context'], $entry['msgidPlural'] ) ] = array( $domain, $pos );
			}
		}
		return array(
			'by_id'       => $by_id,
			'by_identity' => $by_identity,
		);
	}

	/**
	 * @return array{0:string,1:int,2:array}|null Domain, position and entry.
	 */
	public static function find( array $catalog, string $id ): ?array {
		foreach ( $catalog['domains'] as $domain => $entries ) {
			foreach ( $entries as $pos => $entry ) {
				if ( $entry['id'] === $id ) {
					return array( $domain, $pos, $entry );
				}
			}
		}
		return null;
	}

	public static function refresh_statuses( array &$catalog ) {
		foreach ( $catalog['domains'] as $domain => $entries ) {
			foreach ( $entries as $pos => $entry ) {
				$catalog['domains'][ $domain ][ $pos ]['status'] = self::status( $entry, $catalog['locale'] );
			}
		}
	}

	/**
	 * Merges source strings into a catalog: adds new entries, refreshes references, revives
	 * returning strings and marks vanished strings of the synced source types obsolete.
	 * Translations are never deleted.
	 *
	 * @param array    $sources Items: domain, msgid, context, msgidPlural, references, comments, js, source.
	 * @param string[] $scope   Source types owned by this sync (entries of other types are never obsoleted).
	 * @param string   $only_domain Restrict obsoletion to one domain ('' = all domains).
	 * @return array{catalog:array,added:int,obsoleted:int,revived:int,updated:int}
	 */
	public static function sync( array $catalog, array $sources, array $scope, int $revision, string $only_domain = '' ): array {
		$index   = self::index( $catalog )['by_identity'];
		$present = array();
		$stats   = array(
			'added'     => 0,
			'obsoleted' => 0,
			'revived'   => 0,
			'updated'   => 0,
		);
		foreach ( $sources as $s ) {
			$key             = self::identity( $s['domain'], $s['msgid'], $s['context'], $s['msgidPlural'] );
			$present[ $key ] = true;
			if ( isset( $index[ $key ] ) ) {
				list( $domain, $pos ) = $index[ $key ];
				$entry                = $catalog['domains'][ $domain ][ $pos ];
				$before               = $entry;
				$entry['references']  = $s['references'];
				if ( '' !== $s['comments'] ) {
					$entry['comments'] = $s['comments'];
				}
				$entry['js'] = $entry['js'] || $s['js'];
				if ( $entry['obsolete'] && in_array( $entry['source'], $scope, true ) ) {
					$entry['obsolete']        = false;
					$entry['changedRevision'] = $revision;
					++$stats['revived'];
				}
				if ( $entry !== $before ) {
					$catalog['domains'][ $domain ][ $pos ] = $entry;
					++$stats['updated'];
				}
				continue;
			}
			if ( count( $index ) + $stats['added'] >= Config::MAX_ENTRIES ) {
				continue;
			}
			$entry               = self::new_entry( $s['domain'], $s['msgid'], $s['context'], $s['msgidPlural'], $s['source'], $revision );
			$entry['references'] = $s['references'];
			$entry['comments']   = $s['comments'];
			$entry['js']         = $s['js'];
			$catalog['domains'][ $s['domain'] ][] = $entry;
			++$stats['added'];
		}
		foreach ( $catalog['domains'] as $domain => $entries ) {
			if ( '' !== $only_domain && $domain !== $only_domain ) {
				continue;
			}
			foreach ( $entries as $pos => $entry ) {
				$key = self::identity( $domain, $entry['msgid'], $entry['context'], $entry['msgidPlural'] );
				if ( ! isset( $present[ $key ] ) && ! $entry['obsolete'] && in_array( $entry['source'], $scope, true ) ) {
					$catalog['domains'][ $domain ][ $pos ]['obsolete']        = true;
					$catalog['domains'][ $domain ][ $pos ]['changedRevision'] = $revision;
					++$stats['obsoleted'];
				}
			}
		}
		self::refresh_statuses( $catalog );
		return array( 'catalog' => $catalog ) + $stats;
	}

	/**
	 * Stores validated forms as a draft; a draft equal to the published state is dropped.
	 */
	public static function set_draft( array $entry, array $forms, bool $fuzzy, int $revision ): array {
		if ( $forms === $entry['translations'] && $fuzzy === (bool) $entry['fuzzy'] ) {
			$entry['draft']      = null;
			$entry['draftFuzzy'] = null;
		} else {
			$entry['draft']      = $forms;
			$entry['draftFuzzy'] = $fuzzy;
		}
		$entry['changedRevision'] = $revision;
		return $entry;
	}

	/**
	 * Re-validates and publishes pending drafts. All-or-nothing.
	 *
	 * @param string[]|null $ids Entry IDs to publish, or null for every draft.
	 * @return array{catalog:array,published:int}|\WP_Error
	 */
	public static function publish( array $catalog, ?array $ids, int $revision ) {
		$problems  = array();
		$published = 0;
		foreach ( $catalog['domains'] as $domain => $entries ) {
			foreach ( $entries as $pos => $entry ) {
				if ( null === $entry['draft'] || ( null !== $ids && ! in_array( $entry['id'], $ids, true ) ) ) {
					continue;
				}
				if ( $entry['draft'] ) {
					$checked = Validator::check_translation( $entry, $entry['draft'], $catalog['locale'] );
					if ( is_wp_error( $checked ) ) {
						$problems[] = sprintf( '%s — %s: %s', $domain, mb_substr( $entry['msgid'], 0, 60 ), $checked->get_error_message() );
						continue;
					}
					$entry['translations'] = $checked;
				} else {
					$entry['translations'] = array();
				}
				$entry['fuzzy']           = null === $entry['draftFuzzy'] ? $entry['fuzzy'] : (bool) $entry['draftFuzzy'];
				$entry['draft']           = null;
				$entry['draftFuzzy']      = null;
				$entry['changedRevision'] = $revision;
				$entry['status']          = self::status( $entry, $catalog['locale'] );
				$catalog['domains'][ $domain ][ $pos ] = $entry;
				++$published;
			}
		}
		if ( $problems ) {
			$error = new \WP_Error( 'cradle_translations_publish_invalid', __( 'Nothing was published: some drafts are invalid. Fix or discard them first.', 'cradle-translations' ) );
			$error->add_data( array( 'problems' => array_slice( $problems, 0, 50 ) ) );
			return $error;
		}
		return array(
			'catalog'   => $catalog,
			'published' => $published,
		);
	}

	/**
	 * @param string[]|null $ids Entry IDs, or null for every draft.
	 * @return array{catalog:array,discarded:int}
	 */
	public static function discard( array $catalog, ?array $ids, int $revision ): array {
		$count = 0;
		foreach ( $catalog['domains'] as $domain => $entries ) {
			foreach ( $entries as $pos => $entry ) {
				if ( null === $entry['draft'] || ( null !== $ids && ! in_array( $entry['id'], $ids, true ) ) ) {
					continue;
				}
				$catalog['domains'][ $domain ][ $pos ]['draft']           = null;
				$catalog['domains'][ $domain ][ $pos ]['draftFuzzy']      = null;
				$catalog['domains'][ $domain ][ $pos ]['changedRevision'] = $revision;
				++$count;
			}
		}
		return array(
			'catalog'   => $catalog,
			'discarded' => $count,
		);
	}

	/**
	 * Derived runtime data: published, complete, non-fuzzy translations re-validated and keyed
	 * for O(1) lookups. `s` = singular PHP map, `p` = plural PHP map (key + "\0" + plural),
	 * `j` = Jed entries for JavaScript (strings referenced from JS source).
	 */
	public static function runtime( array $catalog ): array {
		$locale  = $catalog['locale'];
		$domains = array();
		foreach ( $catalog['domains'] as $domain => $entries ) {
			foreach ( $entries as $entry ) {
				if ( ! empty( $entry['fuzzy'] ) || ! self::complete( $entry, $entry['translations'], $locale ) ) {
					continue;
				}
				if ( is_wp_error( Validator::check_translation( $entry, $entry['translations'], $locale ) ) ) {
					continue; // Defense in depth: never serve a translation that fails validation.
				}
				$key = self::lookup_key( $entry['msgid'], $entry['context'] );
				if ( null === $entry['msgidPlural'] ) {
					$domains[ $domain ]['s'][ $key ] = $entry['translations'][0];
				} else {
					$domains[ $domain ]['p'][ $key . "\x00" . $entry['msgidPlural'] ] = $entry['translations'];
				}
				if ( ! empty( $entry['js'] ) && ( null !== $entry['msgidPlural'] || ! isset( $domains[ $domain ]['j'][ $key ] ) ) ) {
					$domains[ $domain ]['j'][ $key ] = $entry['translations'];
				}
			}
		}
		ksort( $domains );
		return array(
			'schemaVersion' => 1,
			'locale'        => $locale,
			'revision'      => (int) $catalog['revision'],
			'rule'          => Plural_Rules::rule_id( $locale ),
			'header'        => Plural_Rules::header( $locale ),
			'domains'       => $domains,
			'hash'          => $domains ? md5( (string) wp_json_encode( $domains ) ) : '',
		);
	}

	/**
	 * @return array{total:int,missing:int,translated:int,fuzzy:int,obsolete:int,drafts:int,domains:array<string,int>}
	 */
	public static function stats( array $catalog ): array {
		$out = array(
			'total'      => 0,
			'missing'    => 0,
			'translated' => 0,
			'fuzzy'      => 0,
			'obsolete'   => 0,
			'drafts'     => 0,
			'domains'    => array(),
		);
		foreach ( $catalog['domains'] as $domain => $entries ) {
			$out['domains'][ $domain ] = count( $entries );
			foreach ( $entries as $entry ) {
				++$out['total'];
				++$out[ $entry['status'] ];
				if ( null !== $entry['draft'] ) {
					++$out['drafts'];
				}
			}
		}
		ksort( $out['domains'] );
		return $out;
	}

	/**
	 * Filters entries for the editor list.
	 *
	 * @param array $filters domain, status (missing|translated|fuzzy|obsolete|draft), source, search.
	 * @return array<int,array{domain:string,entry:array}>
	 */
	public static function query( array $catalog, array $filters ): array {
		$out    = array();
		$search = isset( $filters['search'] ) ? mb_strtolower( trim( (string) $filters['search'] ), 'UTF-8' ) : '';
		foreach ( $catalog['domains'] as $domain => $entries ) {
			if ( ! empty( $filters['domain'] ) && $filters['domain'] !== $domain ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( ! empty( $filters['status'] ) ) {
					if ( 'draft' === $filters['status'] ? null === $entry['draft'] : $filters['status'] !== $entry['status'] ) {
						continue;
					}
				}
				if ( ! empty( $filters['source'] ) && $filters['source'] !== $entry['source'] ) {
					continue;
				}
				if ( '' !== $search ) {
					$haystack = implode( "\n", array_merge( array( $entry['msgid'], (string) $entry['msgidPlural'], (string) $entry['context'], $entry['comments'] ), $entry['translations'], (array) $entry['draft'], $entry['references'] ) );
					if ( false === mb_strpos( mb_strtolower( $haystack, 'UTF-8' ), $search, 0, 'UTF-8' ) ) {
						continue;
					}
				}
				$out[] = array(
					'domain' => $domain,
					'entry'  => $entry,
				);
			}
		}
		return $out;
	}

	/**
	 * Catalog prepared for export: authoritative format without the internal revision log.
	 */
	public static function export( array $catalog ): array {
		unset( $catalog['log'] );
		$catalog['generator'] = 'cradle-translations ' . CRADLE_TRANSLATIONS_VERSION;
		$catalog['exported']  = gmdate( 'c' );
		return $catalog;
	}
}
