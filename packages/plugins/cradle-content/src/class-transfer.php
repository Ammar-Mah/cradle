<?php
/**
 * JSON export and previewed import of content definitions.
 *
 * Import never touches definitions that are not in the file. Each incoming
 * definition is classified before anything is written:
 *   new       — key unused: created.
 *   identical — same as the stored definition: skipped.
 *   update    — differs from the stored definition: overwritten only when explicitly chosen.
 *   blocked   — key is code-managed or registered by other code: never imported.
 *   invalid   — fails validation: never imported (errors listed).
 *
 * @package Cradle\Content
 */

namespace Cradle\Content;

defined( 'ABSPATH' ) || exit;

final class Transfer {

	const FORMAT         = 'cradle-content-definitions';
	const FORMAT_VERSION = 1;
	const MAX_BYTES      = 1048576;
	const MAX_ITEMS      = 150;

	/** Keys never exported (runtime or bookkeeping data). */
	const PRIVATE_KEYS = array( '_source', 'owner', 'revision', 'modified', 'forked_from', 'renamed_from' );

	public static function strip( array $def ): array {
		foreach ( self::PRIVATE_KEYS as $key ) {
			unset( $def[ $key ] );
		}
		return $def;
	}

	/**
	 * Builds an export document.
	 *
	 * @param string[]|null $selection "kind:key" identifiers, or null for every database and code definition.
	 */
	public static function export( ?array $selection = null ): array {
		$defs = Definitions::instance();
		$out  = array_fill_keys( Definitions::KINDS, array() );
		foreach ( Definitions::KINDS as $kind ) {
			$all = $defs->db( $kind ) + $defs->code( $kind );
			foreach ( $defs->code( $kind ) as $key => $def ) {
				$all[ $key ] = $def; // Code is authoritative for its keys.
			}
			foreach ( $all as $key => $def ) {
				if ( null !== $selection && ! in_array( $kind . ':' . $key, $selection, true ) ) {
					continue;
				}
				$out[ $kind ][ $key ] = self::strip( $def );
			}
		}
		return array(
			'format'        => self::FORMAT,
			'formatVersion' => self::FORMAT_VERSION,
			'schemaVersion' => Definitions::SCHEMA,
			'generator'     => 'cradle-content ' . CRADLE_CONTENT_VERSION,
			'exported'      => gmdate( 'c' ),
			'definitions'   => $out,
		);
	}

	/**
	 * Parses JSON text with size and depth limits.
	 *
	 * @return array|\WP_Error
	 */
	public static function decode( string $json ) {
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return new \WP_Error( 'cradle_content_import_size', __( 'The file is too large (1 MB maximum).', 'cradle-content' ) );
		}
		if ( ! mb_check_encoding( $json, 'UTF-8' ) ) {
			return new \WP_Error( 'cradle_content_import_encoding', __( 'The file is not valid UTF-8.', 'cradle-content' ) );
		}
		$data = json_decode( $json, true, 16 );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'cradle_content_import_json', __( 'The file is not valid JSON.', 'cradle-content' ) );
		}
		return $data;
	}

	/**
	 * Classifies every definition of an export document without writing anything.
	 *
	 * @return array{items:array,counts:array}|\WP_Error
	 */
	public static function preview( array $payload ) {
		if ( self::FORMAT !== ( $payload['format'] ?? '' ) || ! is_array( $payload['definitions'] ?? null ) ) {
			return new \WP_Error( 'cradle_content_import_format', __( 'This file is not a Cradle content definitions export.', 'cradle-content' ) );
		}
		if ( (int) ( $payload['formatVersion'] ?? 0 ) !== self::FORMAT_VERSION || (int) ( $payload['schemaVersion'] ?? 0 ) > Definitions::SCHEMA ) {
			return new \WP_Error( 'cradle_content_import_version', __( 'This export was made by a newer, incompatible version of Cradle Content.', 'cradle-content' ) );
		}
		$defs     = Definitions::instance();
		$registry = Registry::instance();
		$incoming = array_fill_keys( Definitions::KINDS, array() );
		$total    = 0;
		foreach ( Definitions::KINDS as $kind ) {
			$list = $payload['definitions'][ $kind ] ?? array();
			if ( ! is_array( $list ) ) {
				return new \WP_Error( 'cradle_content_import_format', __( 'The definitions list is malformed.', 'cradle-content' ) );
			}
			foreach ( $list as $key => $def ) {
				if ( ! is_array( $def ) ) {
					continue;
				}
				if ( ++$total > self::MAX_ITEMS ) {
					/* translators: %d: maximum */
					return new \WP_Error( 'cradle_content_import_size', sprintf( __( 'A file may contain at most %d definitions.', 'cradle-content' ), self::MAX_ITEMS ) );
				}
				$def_key = is_string( $def['key'] ?? null ) ? $def['key'] : ( is_string( $key ) ? $key : '' );
				if ( is_string( $key ) && $def_key !== $key ) {
					return new \WP_Error( 'cradle_content_import_format', __( 'A definition key does not match its entry.', 'cradle-content' ) );
				}
				$def['key']                   = $def_key;
				$incoming[ $kind ][ $def_key ] = self::strip( $def );
			}
		}

		$items  = array();
		$counts = array_fill_keys( array( 'new', 'identical', 'update', 'blocked', 'invalid' ), 0 );
		foreach ( Definitions::KINDS as $kind ) {
			foreach ( $incoming[ $kind ] as $key => $def ) {
				$item = array(
					'kind'       => $kind,
					'key'        => $key,
					'status'     => 'new',
					'errors'     => array(),
					'warnings'   => array(),
					'definition' => array(),
				);
				$stored = $defs->db( $kind )[ $key ] ?? null;
				if ( '' !== $key && $defs->is_code_owned( $kind, $key ) ) {
					$item['status']   = 'blocked';
					$item['errors'][] = __( 'This key is managed in code by another plugin; the code definition stays authoritative.', 'cradle-content' );
				} elseif ( 'field_group' !== $kind && '' !== $key && ! $stored && in_array( $key, $registry->foreign_keys( $kind ), true ) ) {
					$item['status']   = 'blocked';
					$item['errors'][] = __( 'This key is registered by other code (a plugin or theme) and is never overwritten.', 'cradle-content' );
				} else {
					$extra = $incoming;
					unset( $extra[ $kind ][ $key ] );
					$ctx    = $defs->context( $kind, $stored ? $key : '', $extra );
					$result = Validator::validate( $kind, $def, $ctx );
					if ( $result['errors'] ) {
						$item['status'] = 'invalid';
						foreach ( $result['errors'] as $field => $message ) {
							$item['errors'][] = $field . ': ' . $message;
						}
					} else {
						$item['definition'] = $result['definition'];
						$item['warnings']   = $result['warnings'];
						if ( $stored ) {
							$item['status'] = self::same( $stored, $result['definition'] ) ? 'identical' : 'update';
						}
					}
				}
				++$counts[ $item['status'] ];
				$items[] = $item;
			}
		}
		return array(
			'items'  => $items,
			'counts' => $counts,
		);
	}

	/**
	 * Compares two definitions ignoring bookkeeping keys.
	 */
	public static function same( array $a, array $b ): bool {
		$a = self::strip( $a );
		$b = self::strip( $b );
		ksort( $a );
		ksort( $b );
		return wp_json_encode( $a ) === wp_json_encode( $b );
	}

	/**
	 * Applies a preview. Only "new" items and "update" items explicitly chosen for overwrite are written.
	 *
	 * @param array $preview   Result of preview().
	 * @param array $overwrite "kind:key" identifiers the user chose to overwrite.
	 * @return array{created:string[],updated:string[],skipped:string[],failed:array<string,string>}
	 */
	public static function apply( array $preview, array $overwrite ): array {
		$report = array(
			'created' => array(),
			'updated' => array(),
			'skipped' => array(),
			'failed'  => array(),
		);
		$extra  = array_fill_keys( Definitions::KINDS, array() );
		foreach ( $preview['items'] as $item ) {
			if ( in_array( $item['status'], array( 'new', 'update' ), true ) ) {
				$extra[ $item['kind'] ][ $item['key'] ] = $item['definition'];
			}
		}
		$defs = Definitions::instance();
		// Taxonomies and post types reference each other; pass the whole incoming set as context.
		foreach ( array( 'taxonomy', 'post_type', 'field_group' ) as $kind ) {
			foreach ( $preview['items'] as $item ) {
				if ( $item['kind'] !== $kind ) {
					continue;
				}
				$id = $kind . ':' . $item['key'];
				if ( 'new' === $item['status'] ) {
					$result = $defs->save( $kind, $item['definition'], '', null, array( 'extra' => $extra ) );
					if ( is_wp_error( $result ) ) {
						$report['failed'][ $id ] = self::error_text( $result );
					} else {
						$report['created'][] = $id;
					}
				} elseif ( 'update' === $item['status'] && in_array( $id, $overwrite, true ) ) {
					$result = $defs->save( $kind, $item['definition'], $item['key'], null, array( 'extra' => $extra ) );
					if ( is_wp_error( $result ) ) {
						$report['failed'][ $id ] = self::error_text( $result );
					} else {
						$report['updated'][] = $id;
					}
				} else {
					$report['skipped'][] = $id;
				}
			}
		}
		cradle_log_activity(
			Module::ID,
			'definitions_imported',
			sprintf( 'Definitions imported: %1$d created, %2$d updated, %3$d skipped, %4$d failed.', count( $report['created'] ), count( $report['updated'] ), count( $report['skipped'] ), count( $report['failed'] ) )
		);
		return $report;
	}

	public static function error_text( \WP_Error $error ): string {
		$data = $error->get_error_data();
		if ( is_array( $data ) && ! empty( $data['errors'] ) ) {
			$parts = array();
			foreach ( $data['errors'] as $field => $message ) {
				$parts[] = $field . ': ' . $message;
			}
			return implode( ' ', $parts );
		}
		return $error->get_error_message();
	}
}
