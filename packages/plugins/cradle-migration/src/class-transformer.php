<?php
/**
 * Per-row import transformation: URL/path replacement, table-prefix renames and
 * the never-transform rules. Used identically by the preflight dry run (to find
 * blocking issues before anything changes) and by the staged load.
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

defined( 'ABSPATH' ) || exit;

final class Transformer {

	/** Columns that are never rewritten (identity or secrets). */
	const SKIP_COLUMNS = array(
		'posts'    => array( 'guid' ),
		'users'    => array( 'user_pass', 'user_activation_key' ),
		'usermeta' => array(),
	);

	/** Key column used for opaque-value matching per core table. */
	const KEY_COLUMNS = array(
		'options'     => 'option_name',
		'usermeta'    => 'meta_key',
		'postmeta'    => 'meta_key',
		'termmeta'    => 'meta_key',
		'commentmeta' => 'meta_key',
		'sitemeta'    => 'meta_key',
	);

	/** @var Replacer */
	private $replacer;

	/** @var string */
	private $src_prefix;

	/** @var string */
	private $dst_prefix;

	/** @var string[] Opaque key patterns (exact, or trailing * for prefix). */
	private $opaque;

	public function __construct( array $source, array $dest, array $opaque = array() ) {
		$url_pairs = array(
			array( $source['homeUrl'], $dest['home'] ),
			array( $source['siteUrl'], $dest['siteurl'] ),
		);
		$src_up    = (string) ( $source['uploadsUrl'] ?? '' );
		if ( '' !== $src_up && 0 !== strpos( $src_up, untrailingslashit( $source['homeUrl'] ) ) && 0 !== strpos( $src_up, untrailingslashit( $source['siteUrl'] ) ) ) {
			$url_pairs[] = array( $src_up, $dest['uploadsUrl'] );
		}
		$path_pairs       = array(
			array( $source['abspath'], $dest['abspath'] ),
			array( $source['contentDir'], $dest['contentDir'] ),
			array( $source['uploadsDir'], $dest['uploadsDir'] ),
		);
		$this->replacer   = new Replacer( $url_pairs, $path_pairs );
		$this->src_prefix = (string) $source['tablePrefix'];
		$this->dst_prefix = (string) $dest['prefix'];
		$defaults         = array( 'cradle_migration_*' );
		$this->opaque     = array_values( array_unique( array_merge( $defaults, array_filter( array_map( 'strval', $opaque ) ) ) ) );
	}

	public function replacer(): Replacer {
		return $this->replacer;
	}

	private function is_opaque( string $key ): bool {
		foreach ( $this->opaque as $pattern ) {
			if ( str_ends_with( $pattern, '*' ) ? 0 === strpos( $key, substr( $pattern, 0, -1 ) ) : $key === $pattern ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Transforms one decoded row.
	 *
	 * @param string $table   Logical table name.
	 * @param array  $columns validate_table() columns.
	 * @param array  $row     Values (null|string), binary already decoded.
	 * @return array{row:array,issues:array,changed:bool}
	 */
	public function row( string $table, array $columns, array $row ): array {
		$issues  = array();
		$changed = false;
		$key_col = self::KEY_COLUMNS[ $table ] ?? null;
		$key_idx = null;
		$pk      = null;
		foreach ( $columns as $i => $col ) {
			if ( $key_col === $col['name'] ) {
				$key_idx = $i;
			}
			if ( null === $pk && str_ends_with( strtolower( $col['name'] ), 'id' ) ) {
				$pk = $i;
			}
		}
		$key = null !== $key_idx && is_string( $row[ $key_idx ] ?? null ) ? $row[ $key_idx ] : null;

		// Table prefix change for per-site option names and user meta keys.
		if ( $this->src_prefix !== $this->dst_prefix && null !== $key && ( 'options' === $table || 'usermeta' === $table ) && 0 === strpos( $key, $this->src_prefix ) ) {
			$row[ $key_idx ] = $this->dst_prefix . substr( $key, strlen( $this->src_prefix ) );
			$changed         = true;
		}
		if ( $this->replacer->is_empty() || ( null !== $key && $this->is_opaque( $key ) ) ) {
			return array(
				'row'     => $row,
				'issues'  => $issues,
				'changed' => $changed,
			);
		}
		$skip = self::SKIP_COLUMNS[ $table ] ?? array();
		foreach ( $columns as $i => $col ) {
			$value = $row[ $i ] ?? null;
			if ( null === $value || ! empty( $col['binary'] ) || empty( $col['text'] ) || in_array( $col['name'], $skip, true ) || $i === $key_idx ) {
				continue;
			}
			$new = $this->replacer->transform( $value );
			foreach ( $this->replacer->issues() as $issue ) {
				$issue['table']  = $table;
				$issue['column'] = $col['name'];
				$issue['key']    = null !== $key ? mb_substr( $key, 0, 120 ) : ( null !== $pk ? mb_substr( (string) $row[ $pk ], 0, 40 ) : '' );
				$issues[]        = $issue;
			}
			if ( $new !== $value ) {
				$row[ $i ] = $new;
				$changed   = true;
			}
		}
		return array(
			'row'     => $row,
			'issues'  => $issues,
			'changed' => $changed,
		);
	}

	/**
	 * Decodes one JSON-lines row against validated columns.
	 *
	 * @return array|null Values or null when the line is malformed.
	 */
	public static function decode_line( string $line, array $columns ) {
		$data = json_decode( $line, true, 4 );
		if ( ! is_array( $data ) || ! array_is_list( $data ) || count( $data ) !== count( $columns ) ) {
			return null;
		}
		foreach ( $data as $i => $v ) {
			if ( null === $v || is_string( $v ) ) {
				continue;
			}
			if ( is_array( $v ) && 1 === count( $v ) && isset( $v['b64'] ) && is_string( $v['b64'] ) ) {
				$bytes = base64_decode( $v['b64'], true );
				if ( false === $bytes ) {
					return null;
				}
				$data[ $i ] = $bytes;
				continue;
			}
			return null;
		}
		return $data;
	}
}
