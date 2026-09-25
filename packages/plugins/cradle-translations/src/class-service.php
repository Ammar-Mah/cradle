<?php
/**
 * Catalog operations used by the admin screen, module handlers and cron.
 *
 * Every user-initiated operation checks `cradle_manage_translations` itself (defense in depth;
 * the admin handlers also verify nonces and the HTTP method). Stale-edit protection:
 * - entry edits carry the catalog revision the form was rendered from; they are rejected when
 *   that entry changed after that revision (edits to other entries do not conflict);
 * - whole-catalog operations (publish all, discard all, import, restore) require the exact
 *   current revision.
 *
 * @package Cradle\Translations
 */

namespace Cradle\Translations;

defined( 'ABSPATH' ) || exit;

final class Service {

	/**
	 * @return true|\WP_Error
	 */
	public static function authorize() {
		if ( ! current_user_can( Config::CAP ) ) {
			return new \WP_Error( 'cradle_translations_forbidden', __( 'You are not allowed to manage translations.', 'cradle-translations' ), array( 'status' => 403 ) );
		}
		return true;
	}

	private static function stale(): \WP_Error {
		return new \WP_Error( 'cradle_translations_stale', __( 'This catalog changed after you opened it. Nothing was saved; reload to see the current version.', 'cradle-translations' ), array( 'status' => 409 ) );
	}

	/**
	 * Creates a catalog for a locale, filled with the current source manifest.
	 *
	 * @return array|\WP_Error Result of Store::mutate().
	 */
	public static function add_locale( string $locale ) {
		$auth = self::authorize();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		if ( ! Config::valid_locale( $locale ) ) {
			return new \WP_Error( 'cradle_translations_locale', __( 'Enter a locale code such as de_DE, fr_FR, ar or pt_BR.', 'cradle-translations' ) );
		}
		if ( Store::exists( $locale ) ) {
			return new \WP_Error( 'cradle_translations_exists', __( 'A catalog for this locale already exists.', 'cradle-translations' ) );
		}
		if ( count( Store::locales() ) >= 100 ) {
			return new \WP_Error( 'cradle_translations_limit', __( 'The maximum number of locale catalogs is reached.', 'cradle-translations' ) );
		}
		$manifest = Store::manifest();
		if ( ! $manifest ) {
			$manifest = Extractor::build_manifest();
			Store::write_manifest( $manifest );
		}
		$sources = $manifest['entries'];
		return Store::mutate(
			$locale,
			'create',
			static function ( array $current, int $revision ) use ( $sources ) {
				$synced = Catalog::sync( $current, $sources, array( 'extracted', 'registered' ), $revision );
				return array(
					'catalog' => $synced['catalog'],
					'changed' => true,
					/* translators: %d: number of strings */
					'summary' => sprintf( _n( 'Catalog created with %d source string.', 'Catalog created with %d source strings.', $synced['added'], 'cradle-translations' ), $synced['added'] ),
				);
			},
			array( 'create' => true )
		);
	}

	/**
	 * Saves an entry as draft, or saves and publishes it.
	 *
	 * @param mixed $forms Submitted forms.
	 * @return array|\WP_Error
	 */
	public static function save_entry( string $locale, string $id, $forms, bool $fuzzy, int $expected_revision, bool $publish ) {
		$auth = self::authorize();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		return Store::mutate(
			$locale,
			$publish ? 'publish_entry' : 'draft',
			static function ( array $catalog, int $revision ) use ( $id, $forms, $fuzzy, $expected_revision, $publish, $locale ) {
				$found = Catalog::find( $catalog, $id );
				if ( ! $found ) {
					return new \WP_Error( 'cradle_translations_entry', __( 'The entry does not exist in this catalog.', 'cradle-translations' ), array( 'status' => 404 ) );
				}
				list( $domain, $pos, $entry ) = $found;
				if ( $expected_revision > $catalog['revision'] || $entry['changedRevision'] > $expected_revision ) {
					return self::stale();
				}
				$checked = Validator::check_translation( $entry, $forms, $locale );
				if ( is_wp_error( $checked ) ) {
					return $checked;
				}
				$entry                                 = Catalog::set_draft( $entry, $checked, $fuzzy, $revision );
				$catalog['domains'][ $domain ][ $pos ] = $entry;
				/* translators: %s: source text excerpt */
				$summary = sprintf( __( 'Draft saved for "%s".', 'cradle-translations' ), mb_substr( $entry['msgid'], 0, 60 ) );
				if ( $publish && null !== $entry['draft'] ) {
					$published = Catalog::publish( $catalog, array( $id ), $revision );
					if ( is_wp_error( $published ) ) {
						return $published;
					}
					$catalog = $published['catalog'];
					/* translators: %s: source text excerpt */
					$summary = sprintf( __( 'Published translation of "%s".', 'cradle-translations' ), mb_substr( $entry['msgid'], 0, 60 ) );
				}
				return array(
					'catalog' => $catalog,
					'changed' => true,
					'summary' => $summary,
				);
			}
		);
	}

	/**
	 * @return array|\WP_Error
	 */
	public static function discard_entry( string $locale, string $id, int $expected_revision ) {
		$auth = self::authorize();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		return Store::mutate(
			$locale,
			'discard',
			static function ( array $catalog, int $revision ) use ( $id, $expected_revision ) {
				$found = Catalog::find( $catalog, $id );
				if ( ! $found ) {
					return new \WP_Error( 'cradle_translations_entry', __( 'The entry does not exist in this catalog.', 'cradle-translations' ), array( 'status' => 404 ) );
				}
				if ( $expected_revision > $catalog['revision'] || $found[2]['changedRevision'] > $expected_revision ) {
					return self::stale();
				}
				$result = Catalog::discard( $catalog, array( $id ), $revision );
				return array(
					'catalog' => $result['catalog'],
					'changed' => $result['discarded'] > 0,
					'summary' => __( 'Draft discarded.', 'cradle-translations' ),
				);
			}
		);
	}

	/**
	 * Adds a string that extraction cannot discover (for example a WordPress or WooCommerce string).
	 *
	 * @return array|\WP_Error
	 */
	public static function add_entry( string $locale, string $domain, string $msgid, string $context, string $plural, bool $js, int $expected_revision ) {
		$auth = self::authorize();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		if ( ! Config::valid_domain( $domain ) ) {
			return new \WP_Error( 'cradle_translations_domain', __( 'Enter a valid text domain, for example default or woocommerce.', 'cradle-translations' ) );
		}
		foreach ( array( $msgid, $context, $plural ) as $i => $text ) {
			$ok = Validator::check_text( $text, __( 'Source text', 'cradle-translations' ), 0 !== $i );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		$context = '' === $context ? null : $context;
		$plural  = '' === $plural ? null : $plural;
		return Store::mutate(
			$locale,
			'add_entry',
			static function ( array $catalog, int $revision ) use ( $domain, $msgid, $context, $plural, $js, $expected_revision ) {
				if ( $expected_revision > $catalog['revision'] ) {
					return self::stale();
				}
				$index = Catalog::index( $catalog )['by_identity'];
				if ( isset( $index[ Catalog::identity( $domain, $msgid, $context, $plural ) ] ) ) {
					return new \WP_Error( 'cradle_translations_duplicate', __( 'This string (same domain, text, context and plural) is already in the catalog.', 'cradle-translations' ) );
				}
				if ( count( $index ) >= Config::MAX_ENTRIES ) {
					return new \WP_Error( 'cradle_translations_limit', __( 'The catalog has reached its entry limit.', 'cradle-translations' ) );
				}
				$entry       = Catalog::new_entry( $domain, $msgid, $context, $plural, 'manual', $revision );
				$entry['js'] = $js;
				$catalog['domains'][ $domain ][] = $entry;
				return array(
					'catalog' => $catalog,
					'changed' => true,
					'id'      => $entry['id'],
					/* translators: %s: source text excerpt */
					'summary' => sprintf( __( 'Added string "%s".', 'cradle-translations' ), mb_substr( $msgid, 0, 60 ) ),
				);
			}
		);
	}

	/**
	 * Deletes a manual, imported or obsolete entry (active extracted strings cannot be deleted).
	 *
	 * @return array|\WP_Error
	 */
	public static function delete_entry( string $locale, string $id, int $expected_revision ) {
		$auth = self::authorize();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		return Store::mutate(
			$locale,
			'delete_entry',
			static function ( array $catalog, int $revision ) use ( $id, $expected_revision ) {
				$found = Catalog::find( $catalog, $id );
				if ( ! $found ) {
					return new \WP_Error( 'cradle_translations_entry', __( 'The entry does not exist in this catalog.', 'cradle-translations' ), array( 'status' => 404 ) );
				}
				list( $domain, $pos, $entry ) = $found;
				if ( $expected_revision > $catalog['revision'] || $entry['changedRevision'] > $expected_revision ) {
					return self::stale();
				}
				if ( ! self::deletable( $entry ) ) {
					return new \WP_Error( 'cradle_translations_delete', __( 'Strings found in current Cradle source cannot be deleted. Clear the translation instead.', 'cradle-translations' ) );
				}
				array_splice( $catalog['domains'][ $domain ], $pos, 1 );
				if ( ! $catalog['domains'][ $domain ] ) {
					unset( $catalog['domains'][ $domain ] );
				}
				return array(
					'catalog' => $catalog,
					'changed' => true,
					/* translators: %s: source text excerpt */
					'summary' => sprintf( __( 'Deleted string "%s".', 'cradle-translations' ), mb_substr( $entry['msgid'], 0, 60 ) ),
				);
			}
		);
	}

	public static function deletable( array $entry ): bool {
		return $entry['obsolete'] || in_array( $entry['source'], array( 'manual', 'imported' ), true );
	}

	/**
	 * Publishes every pending draft (all-or-nothing) and regenerates the runtime data.
	 *
	 * @return array|\WP_Error
	 */
	public static function publish( string $locale, int $expected_revision ) {
		$auth = self::authorize();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		return Store::mutate(
			$locale,
			'publish',
			static function ( array $catalog, int $revision ) use ( $expected_revision ) {
				if ( $expected_revision !== $catalog['revision'] ) {
					return self::stale();
				}
				$result = Catalog::publish( $catalog, null, $revision );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return array(
					'catalog' => $result['catalog'],
					'changed' => $result['published'] > 0,
					/* translators: %d: number of drafts */
					'summary' => sprintf( _n( 'Published %d draft.', 'Published %d drafts.', $result['published'], 'cradle-translations' ), $result['published'] ),
				);
			}
		);
	}

	/**
	 * @return array|\WP_Error
	 */
	public static function discard_all( string $locale, int $expected_revision ) {
		$auth = self::authorize();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		return Store::mutate(
			$locale,
			'discard_all',
			static function ( array $catalog, int $revision ) use ( $expected_revision ) {
				if ( $expected_revision !== $catalog['revision'] ) {
					return self::stale();
				}
				$result = Catalog::discard( $catalog, null, $revision );
				return array(
					'catalog' => $result['catalog'],
					'changed' => $result['discarded'] > 0,
					/* translators: %d: number of drafts */
					'summary' => sprintf( _n( 'Discarded %d draft.', 'Discarded %d drafts.', $result['discarded'], 'cradle-translations' ), $result['discarded'] ),
				);
			}
		);
	}

	/**
	 * Merges an authoritative JSON catalog: matching entries receive the file's translations as
	 * drafts (or published directly), unknown entries are added. Invalid files change nothing.
	 *
	 * @return array|\WP_Error
	 */
	public static function import_json( string $locale, string $raw, bool $publish, int $expected_revision ) {
		$auth = self::authorize();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$data = Validator::decode_json( $raw, Config::import_limit() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$incoming = Validator::catalog(
			$data,
			array(
				'locale'   => $locale,
				'semantic' => true,
			)
		);
		if ( is_wp_error( $incoming ) ) {
			return $incoming;
		}
		return Store::mutate(
			$locale,
			'import',
			static function ( array $catalog, int $revision ) use ( $incoming, $publish, $expected_revision ) {
				if ( $expected_revision !== $catalog['revision'] ) {
					return self::stale();
				}
				$index   = Catalog::index( $catalog )['by_identity'];
				$added   = 0;
				$updated = 0;
				foreach ( $incoming['domains'] as $domain => $entries ) {
					foreach ( $entries as $in ) {
						$key   = Catalog::identity( $domain, $in['msgid'], $in['context'], $in['msgidPlural'] );
						$forms = null !== $in['draft'] ? $in['draft'] : $in['translations'];
						$fuzzy = null !== $in['draft'] ? (bool) $in['draftFuzzy'] : (bool) $in['fuzzy'];
						if ( isset( $index[ $key ] ) ) {
							if ( ! $forms ) {
								continue; // An untranslated entry in the file never clears an existing translation.
							}
							list( $d, $pos ) = $index[ $key ];
							$entry           = $catalog['domains'][ $d ][ $pos ];
							if ( $in['references'] && ! $entry['references'] ) {
								$entry['references'] = $in['references'];
							}
						} else {
							if ( count( $index ) + $added >= Config::MAX_ENTRIES ) {
								return new \WP_Error( 'cradle_translations_limit', __( 'The import would exceed the catalog entry limit. Nothing was changed.', 'cradle-translations' ) );
							}
							$entry               = Catalog::new_entry( $domain, $in['msgid'], $in['context'], $in['msgidPlural'], in_array( $in['source'], array( 'manual', 'imported' ), true ) ? $in['source'] : 'imported', $revision );
							$entry['references'] = $in['references'];
							$entry['comments']   = $in['comments'];
							$entry['js']         = $in['js'];
							++$added;
						}
						if ( $forms ) {
							$entry = Catalog::set_draft( $entry, $forms, $fuzzy, $revision );
						}
						if ( $publish && null !== $entry['draft'] ) {
							$entry['translations'] = $entry['draft'];
							$entry['fuzzy']        = (bool) $entry['draftFuzzy'];
							$entry['draft']        = null;
							$entry['draftFuzzy']   = null;
						}
						if ( isset( $index[ $key ] ) ) {
							list( $d, $pos )                  = $index[ $key ];
							$catalog['domains'][ $d ][ $pos ] = $entry;
						} else {
							$catalog['domains'][ $domain ][] = $entry;
							$index[ $key ]                   = array( $domain, count( $catalog['domains'][ $domain ] ) - 1 );
						}
						++$updated;
					}
				}
				return array(
					'catalog' => $catalog,
					'changed' => $updated > 0,
					/* translators: 1: number of entries imported, 2: number of new entries */
					'summary' => sprintf( __( 'Imported %1$d entries (%2$d new).', 'cradle-translations' ), $updated, $added ) . ( $publish ? ' ' . __( 'Published immediately.', 'cradle-translations' ) : '' ),
				);
			}
		);
	}

	/**
	 * Imports a .po/.pot source catalog for a text domain (WordPress, WooCommerce, ...).
	 * Source strings are added; translations become drafts when requested. Invalid translations
	 * are skipped and reported. Imported strings missing from the file can be marked obsolete.
	 *
	 * @return array|\WP_Error
	 */
	public static function import_po( string $locale, string $raw, string $domain, bool $with_translations, bool $mark_obsolete, int $expected_revision ) {
		$auth = self::authorize();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		if ( ! Config::valid_domain( $domain ) ) {
			return new \WP_Error( 'cradle_translations_domain', __( 'Enter a valid text domain, for example default or woocommerce.', 'cradle-translations' ) );
		}
		$parsed = Po_Parser::parse( $raw, Config::import_limit() );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$sources = array();
		foreach ( $parsed['entries'] as $i => $entry ) {
			foreach ( array( 'msgid' => $entry['msgid'], 'context' => (string) $entry['context'], 'plural' => (string) $entry['msgidPlural'] ) as $what => $text ) {
				$ok = Validator::check_text( $text, $what, 'msgid' !== $what );
				if ( is_wp_error( $ok ) ) {
					/* translators: 1: entry number, 2: problem */
					return new \WP_Error( 'cradle_translations_po', sprintf( __( 'Entry %1$d: %2$s', 'cradle-translations' ), $i + 1, $ok->get_error_message() ) );
				}
			}
			$sources[] = array(
				'domain'      => $domain,
				'msgid'       => $entry['msgid'],
				'context'     => $entry['context'],
				'msgidPlural' => $entry['msgidPlural'],
				'references'  => $entry['references'],
				'comments'    => $entry['comments'],
				'js'          => (bool) array_filter( $entry['references'], static fn( $r ) => (bool) preg_match( '/\.js(?::\d+)?$/', $r ) ),
				'source'      => 'imported',
			);
		}
		$header_forms = isset( $parsed['headers']['plural-forms'] ) ? Plural_Rules::header_nplurals( $parsed['headers']['plural-forms'] ) : null;
		return Store::mutate(
			$locale,
			'import_po',
			static function ( array $catalog, int $revision ) use ( $sources, $parsed, $domain, $with_translations, $mark_obsolete, $expected_revision, $header_forms, $locale ) {
				if ( $expected_revision !== $catalog['revision'] ) {
					return self::stale();
				}
				$synced   = Catalog::sync( $catalog, $sources, $mark_obsolete ? array( 'imported' ) : array(), $revision, $domain );
				$catalog  = $synced['catalog'];
				$drafts   = 0;
				$skipped  = array();
				$problems = 0;
				if ( $with_translations ) {
					if ( null !== $header_forms && $header_forms !== Plural_Rules::nplurals( $locale ) ) {
						/* translators: 1: plural forms in the file, 2: plural forms of the locale */
						$skipped[] = sprintf( __( 'The file declares %1$d plural forms but this locale uses %2$d; plural translations were skipped.', 'cradle-translations' ), $header_forms, Plural_Rules::nplurals( $locale ) );
					}
					$index = Catalog::index( $catalog )['by_identity'];
					foreach ( $parsed['entries'] as $in ) {
						if ( ! array_filter( $in['translations'], static fn( $f ) => '' !== $f ) ) {
							continue;
						}
						if ( null !== $in['msgidPlural'] && null !== $header_forms && $header_forms !== Plural_Rules::nplurals( $locale ) ) {
							continue;
						}
						$key = Catalog::identity( $domain, $in['msgid'], $in['context'], $in['msgidPlural'] );
						if ( ! isset( $index[ $key ] ) ) {
							continue;
						}
						list( $d, $pos ) = $index[ $key ];
						$entry           = $catalog['domains'][ $d ][ $pos ];
						$checked         = Validator::check_translation( $entry, $in['translations'], $locale );
						if ( is_wp_error( $checked ) ) {
							++$problems;
							if ( count( $skipped ) < 20 ) {
								$skipped[] = mb_substr( $in['msgid'], 0, 60 ) . ' — ' . $checked->get_error_message();
							}
							continue;
						}
						$catalog['domains'][ $d ][ $pos ] = Catalog::set_draft( $entry, $checked, (bool) $in['fuzzy'], $revision );
						++$drafts;
					}
				}
				return array(
					'catalog'  => $catalog,
					'changed'  => $synced['added'] + $synced['obsoleted'] + $synced['revived'] + $synced['updated'] + $drafts > 0,
					'problems' => $skipped,
					/* translators: 1: text domain, 2: new strings, 3: drafts, 4: skipped translations, 5: strings marked obsolete */
					'summary'  => sprintf( __( 'Source catalog for %1$s imported: %2$d new strings, %3$d translation drafts, %4$d invalid translations skipped, %5$d strings marked obsolete.', 'cradle-translations' ), $domain, $synced['added'], $drafts, $problems, $synced['obsoleted'] ),
				);
			}
		);
	}

	/**
	 * Authoritative JSON export of a catalog.
	 *
	 * @return string|\WP_Error
	 */
	public static function export_json( string $locale ) {
		$auth = self::authorize();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$catalog = Store::read( $locale );
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}
		return Store::encode( Catalog::export( $catalog ) );
	}

	/**
	 * Restores an earlier revision as a new revision (history is never rewound).
	 *
	 * @return array|\WP_Error
	 */
	public static function restore( string $locale, int $target, int $expected_revision ) {
		$auth = self::authorize();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$snapshot = Store::read_snapshot( $locale, $target );
		if ( is_wp_error( $snapshot ) ) {
			return new \WP_Error( 'cradle_translations_revision', __( 'That revision is not available in the history.', 'cradle-translations' ) );
		}
		return Store::mutate(
			$locale,
			'restore',
			static function ( array $catalog, int $revision ) use ( $snapshot, $target, $expected_revision ) {
				if ( $expected_revision !== $catalog['revision'] ) {
					return self::stale();
				}
				$restored            = $catalog;
				$restored['domains'] = $snapshot['domains'];
				foreach ( $restored['domains'] as $domain => $entries ) {
					foreach ( $entries as $pos => $entry ) {
						$restored['domains'][ $domain ][ $pos ]['changedRevision'] = $revision;
					}
				}
				return array(
					'catalog' => $restored,
					'changed' => true,
					/* translators: %d: revision number */
					'summary' => sprintf( __( 'Restored revision %d.', 'cradle-translations' ), $target ),
				);
			}
		);
	}

	/**
	 * Re-extracts Cradle package sources and merges them into every catalog.
	 *
	 * @param bool $system True for cron/CLI refreshes (no user capability involved).
	 * @return array{manifest:array,locales:array<string,string>}|\WP_Error
	 */
	public static function refresh_sources( bool $system = false ) {
		if ( ! $system ) {
			$auth = self::authorize();
			if ( is_wp_error( $auth ) ) {
				return $auth;
			}
		}
		$manifest = Extractor::build_manifest();
		$written  = Store::write_manifest( $manifest );
		if ( is_wp_error( $written ) ) {
			return $written;
		}
		$state = get_option( Config::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		update_option(
			Config::STATE_OPTION,
			array_merge(
				$state,
				array(
					'refreshed' => time(),
					'strings'   => count( $manifest['entries'] ),
				)
			),
			false
		);
		$sources = $manifest['entries'];
		$report  = array();
		foreach ( Store::locales() as $locale ) {
			$result = Store::mutate(
				$locale,
				'sync',
				static function ( array $catalog, int $revision ) use ( $sources ) {
					$synced = Catalog::sync( $catalog, $sources, array( 'extracted', 'registered' ), $revision );
					return array(
						'catalog' => $synced['catalog'],
						'changed' => $synced['added'] + $synced['obsoleted'] + $synced['revived'] + $synced['updated'] > 0,
						/* translators: 1: new strings, 2: obsolete strings, 3: returning strings */
						'summary' => sprintf( __( 'Sources refreshed: %1$d new, %2$d obsolete, %3$d returned.', 'cradle-translations' ), $synced['added'], $synced['obsoleted'], $synced['revived'] ),
					);
				}
			);
			$report[ $locale ] = is_wp_error( $result ) ? $result->get_error_message() : $result['summary'];
			// Built-in example languages receive their shipped translation for strings that just arrived.
			if ( ! is_wp_error( $result ) && Examples::is_example( $locale ) ) {
				$shipped = Examples::read( $locale );
				if ( ! is_wp_error( $shipped ) ) {
					Examples::fill( $locale, $shipped, 'examples' );
				}
			}
		}
		cradle_log_activity( Config::MODULE, 'sources_refreshed', sprintf( 'Source manifest regenerated with %d strings.', count( $manifest['entries'] ) ) );
		return array(
			'manifest' => $manifest,
			'locales'  => $report,
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function delete_locale( string $locale ) {
		$auth = self::authorize();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		if ( ! Store::exists( $locale ) ) {
			return new \WP_Error( 'cradle_translations_not_found', __( 'The catalog does not exist.', 'cradle-translations' ) );
		}
		if ( Examples::is_example( $locale ) ) {
			return new \WP_Error( 'cradle_translations_example_locale', __( 'German and Arabic are built-in example languages and cannot be deleted. Edit their translations instead; clearing an entry brings the shipped translation back.', 'cradle-translations' ) );
		}
		return Store::delete_locale( $locale );
	}

	/**
	 * Package keys (type:slug) whose version differs from the stored manifest.
	 *
	 * @return array{changed:string[],manifest:bool}
	 */
	public static function source_drift(): array {
		$manifest = Store::manifest();
		if ( ! $manifest ) {
			return array(
				'changed'  => array(),
				'manifest' => false,
			);
		}
		$changed = array();
		$current = Extractor::packages();
		foreach ( $current as $key => $package ) {
			if ( ! isset( $manifest['packages'][ $key ] ) || (string) $manifest['packages'][ $key ]['version'] !== $package['version'] ) {
				$changed[] = $key;
			}
		}
		foreach ( array_keys( (array) $manifest['packages'] ) as $key ) {
			if ( ! isset( $current[ $key ] ) ) {
				$changed[] = $key;
			}
		}
		return array(
			'changed'  => $changed,
			'manifest' => true,
		);
	}
}
