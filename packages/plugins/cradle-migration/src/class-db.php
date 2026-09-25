<?php
/**
 * Database schema introspection, allowlisted DDL, bounded row reading and loading.
 *
 * The archive never contains SQL. Export records structured table metadata; import
 * validates every identifier, type, default, engine and collation against strict
 * allowlists and builds the DDL itself. Values are escaped with the live mysqli
 * connection. Identifiers are validated, never merely escaped.
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

defined( 'ABSPATH' ) || exit;

final class Db {

	/** Cradle runtime tables: exported schema-only, never replaced on import. */
	const RUNTIME_TABLES = array( 'cradle_jobs', 'cradle_locks', 'cradle_activity' );

	const ENGINES = array( 'innodb', 'myisam', 'aria' );

	const IDENT = '/^[A-Za-z0-9_]{1,64}$/';

	const BINARY_TYPES = array( 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit' );

	const TEXT_TYPES = array( 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json' );

	/** @var array|null Cached destination environment. */
	private static $env = null;

	private static function dbh() {
		global $wpdb;
		if ( ! $wpdb->dbh || ! ( $wpdb->dbh instanceof \mysqli ) ) {
			$wpdb->check_connection( false );
		}
		return $wpdb->dbh;
	}

	/**
	 * Executes a statement built by this class. Returns the mysqli result/true or WP_Error.
	 *
	 * @return \mysqli_result|true|\WP_Error
	 */
	public static function exec( string $sql ) {
		global $wpdb;
		$dbh = self::dbh();
		try {
			$result = mysqli_query( $dbh, $sql ); // phpcs:ignore WordPress.DB.RestrictedFunctions
		} catch ( \Throwable $e ) {
			$result = false;
			$error  = $e->getMessage();
		}
		if ( false === $result ) {
			$error = $error ?? ( $dbh instanceof \mysqli ? mysqli_error( $dbh ) : 'no connection' );
			// Let wpdb reconnect on its next query after "server has gone away".
			if ( $dbh instanceof \mysqli && in_array( mysqli_errno( $dbh ), array( 2006, 2013, 1317, 4031 ), true ) ) {
				$wpdb->check_connection( false );
			}
			return new \WP_Error( 'cradle_migration_db', 'Database error: ' . substr( (string) $error, 0, 300 ) );
		}
		return $result;
	}

	/**
	 * @return array<int,array>|\WP_Error Associative rows.
	 */
	public static function rows( string $sql ) {
		$result = self::exec( $sql );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$rows = array();
		if ( $result instanceof \mysqli_result ) {
			while ( $row = mysqli_fetch_assoc( $result ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				$rows[] = $row;
			}
			mysqli_free_result( $result );
		}
		return $rows;
	}

	public static function quote( ?string $value ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		return "'" . mysqli_real_escape_string( self::dbh(), $value ) . "'";
	}

	public static function ident( string $name ): string {
		if ( ! preg_match( self::IDENT, $name ) ) {
			throw new \InvalidArgumentException( 'Invalid identifier.' );
		}
		return '`' . $name . '`';
	}

	public static function is_mariadb(): bool {
		global $wpdb;
		return false !== stripos( (string) $wpdb->db_server_info(), 'mariadb' );
	}

	/**
	 * Destination capabilities used to validate archive schemas.
	 */
	public static function env(): array {
		if ( null !== self::$env ) {
			return self::$env;
		}
		global $wpdb;
		$collations = array();
		$rows       = self::rows( 'SHOW COLLATION' );
		if ( is_wp_error( $rows ) || ! $rows ) {
			// Never cache a failed probe: callers see 'ok' => false and stop instead of guessing.
			return array(
				'ok'         => false,
				'error'      => is_wp_error( $rows ) ? $rows->get_error_message() : 'SHOW COLLATION returned nothing.',
				'collations' => array(),
				'engines'    => array(),
				'maxPacket'  => 1048576,
				'lowerCase'  => 0,
				'version'    => '',
				'mariadb'    => false,
				'database'   => (string) $wpdb->dbname,
			);
		}
		foreach ( $rows as $row ) {
			$collations[ strtolower( $row['Collation'] ) ] = strtolower( $row['Charset'] );
		}
		$engines = array();
		$rows    = self::rows( 'SHOW ENGINES' );
		foreach ( is_wp_error( $rows ) ? array() : $rows as $row ) {
			if ( in_array( strtoupper( $row['Support'] ), array( 'YES', 'DEFAULT' ), true ) ) {
				$engines[] = strtolower( $row['Engine'] );
			}
		}
		$vars = array();
		$rows = self::rows( "SHOW VARIABLES WHERE Variable_name IN ('max_allowed_packet','lower_case_table_names','version')" );
		foreach ( is_wp_error( $rows ) ? array() : $rows as $row ) {
			$vars[ $row['Variable_name'] ] = $row['Value'];
		}
		self::$env = array(
			'ok'           => true,
			'collations'   => $collations,
			'engines'      => $engines,
			'maxPacket'    => max( 1048576, (int) ( $vars['max_allowed_packet'] ?? 4194304 ) ),
			'lowerCase'    => (int) ( $vars['lower_case_table_names'] ?? 0 ),
			'version'      => (string) ( $vars['version'] ?? $wpdb->db_server_info() ),
			'mariadb'      => self::is_mariadb(),
			'database'     => (string) $wpdb->dbname,
		);
		return self::$env;
	}

	/* ------------------------------------------------------------------ */
	/* Export: discovery and description                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Lists this site's tables and database objects that cannot be exported.
	 *
	 * @return array{tables:array<int,array{physical:string,logical:string}>,unsupported:array<int,array>}
	 */
	public static function discover( string $prefix ): array {
		global $wpdb;
		$tables      = array();
		$unsupported = array();
		$like        = $wpdb->esc_like( $prefix ) . '%';
		$rows        = self::rows( 'SHOW FULL TABLES LIKE ' . self::quote( $like ) );
		foreach ( is_wp_error( $rows ) ? array() : $rows as $row ) {
			$values   = array_values( $row );
			$physical = (string) $values[0];
			$type     = strtoupper( (string) ( $values[1] ?? 'BASE TABLE' ) );
			if ( 0 !== strpos( $physical, $prefix ) ) {
				continue;
			}
			$logical = substr( $physical, strlen( $prefix ) );
			if ( 'VIEW' === $type ) {
				$unsupported[] = array(
					'kind'   => 'view',
					'name'   => $physical,
					'detail' => 'Views are not exported (their definitions are SQL).',
				);
				continue;
			}
			if ( 'BASE TABLE' !== $type ) {
				$unsupported[] = array(
					'kind'   => 'table-type',
					'name'   => $physical,
					'detail' => 'Unsupported table type ' . $type . '.',
				);
				continue;
			}
			if ( ! preg_match( '/^[A-Za-z0-9_]{1,54}$/', $logical ) ) {
				$unsupported[] = array(
					'kind'   => 'table-name',
					'name'   => $physical,
					'detail' => 'Table name contains characters outside [A-Za-z0-9_] or is too long.',
				);
				continue;
			}
			$tables[] = array(
				'physical' => $physical,
				'logical'  => $logical,
			);
		}
		$db = self::quote( (string) $wpdb->dbname );
		foreach ( self::rows_or_empty( "SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = {$db}" ) as $row ) {
			if ( 0 === strpos( (string) $row['EVENT_OBJECT_TABLE'], $prefix ) ) {
				$unsupported[] = array(
					'kind'   => 'trigger',
					'name'   => $row['TRIGGER_NAME'],
					'detail' => 'Trigger on ' . $row['EVENT_OBJECT_TABLE'] . ' is not exported.',
				);
			}
		}
		foreach ( self::rows_or_empty( "SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = {$db}" ) as $row ) {
			$unsupported[] = array(
				'kind'   => 'routine',
				'name'   => $row['ROUTINE_NAME'],
				'detail' => 'Stored ' . strtolower( (string) $row['ROUTINE_TYPE'] ) . ' in this database is not exported.',
			);
		}
		foreach ( self::rows_or_empty( "SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = {$db}" ) as $row ) {
			$unsupported[] = array(
				'kind'   => 'event',
				'name'   => $row['EVENT_NAME'],
				'detail' => 'Scheduled database event is not exported.',
			);
		}
		foreach ( self::rows_or_empty( "SELECT CONSTRAINT_NAME, TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = {$db}" ) as $row ) {
			if ( 0 === strpos( (string) $row['TABLE_NAME'], $prefix ) ) {
				$unsupported[] = array(
					'kind'   => 'foreign-key',
					'name'   => $row['CONSTRAINT_NAME'],
					'detail' => 'Foreign key on ' . $row['TABLE_NAME'] . ' is not recreated on import (rows are kept).',
				);
			}
		}
		return array(
			'tables'      => $tables,
			'unsupported' => $unsupported,
		);
	}

	private static function rows_or_empty( string $sql ): array {
		$rows = self::rows( $sql );
		return is_wp_error( $rows ) ? array() : $rows;
	}

	/**
	 * Describes one table as structured metadata.
	 *
	 * @return array Table metadata with 'problems' (non-empty = unsupported).
	 */
	public static function describe( string $physical, string $logical ): array {
		global $wpdb;
		$db       = self::quote( (string) $wpdb->dbname );
		$tq       = self::quote( $physical );
		$problems = array();
		$info     = self::rows_or_empty( "SELECT ENGINE, TABLE_COLLATION, AUTO_INCREMENT, CREATE_OPTIONS FROM information_schema.TABLES WHERE TABLE_SCHEMA = {$db} AND TABLE_NAME = {$tq}" );
		$info     = $info[0] ?? array();
		$engine   = (string) ( $info['ENGINE'] ?? '' );
		$coll     = strtolower( (string) ( $info['TABLE_COLLATION'] ?? '' ) );
		if ( ! in_array( strtolower( $engine ), self::ENGINES, true ) ) {
			$problems[] = 'Storage engine ' . ( '' === $engine ? 'unknown' : $engine ) . ' is not supported.';
		}
		if ( false !== stripos( (string) ( $info['CREATE_OPTIONS'] ?? '' ), 'partitioned' ) ) {
			$problems[] = 'Partitioned tables are not supported.';
		}
		$env     = self::env();
		$columns = array();
		foreach ( self::rows_or_empty( "SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = {$db} AND TABLE_NAME = {$tq} ORDER BY ORDINAL_POSITION" ) as $c ) {
			$col = self::describe_column( $c, $env['mariadb'] );
			if ( isset( $col['problem'] ) ) {
				$problems[] = $col['problem'];
				unset( $col['problem'] );
			}
			$columns[] = $col;
		}
		$indexes = array();
		foreach ( self::rows_or_empty( "SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = {$db} AND TABLE_NAME = {$tq} ORDER BY INDEX_NAME, SEQ_IN_INDEX" ) as $s ) {
			$name = (string) $s['INDEX_NAME'];
			if ( ! isset( $indexes[ $name ] ) ) {
				$type = strtoupper( (string) $s['INDEX_TYPE'] );
				if ( in_array( $type, array( 'SPATIAL', 'RTREE' ), true ) ) {
					$problems[] = 'Spatial index ' . $name . ' is not supported.';
				}
				$indexes[ $name ] = array(
					'name'    => $name,
					'unique'  => '0' === (string) $s['NON_UNIQUE'],
					'type'    => 'FULLTEXT' === $type ? 'FULLTEXT' : 'BTREE',
					'columns' => array(),
				);
			}
			if ( null === $s['COLUMN_NAME'] || '' === (string) $s['COLUMN_NAME'] ) {
				$problems[] = 'Functional index ' . $name . ' is not supported.';
				continue;
			}
			$indexes[ $name ]['columns'][] = array(
				'name'    => (string) $s['COLUMN_NAME'],
				'subPart' => null === $s['SUB_PART'] ? null : (int) $s['SUB_PART'],
			);
		}
		$primary = array();
		if ( isset( $indexes['PRIMARY'] ) ) {
			foreach ( $indexes['PRIMARY']['columns'] as $pc ) {
				$primary[] = $pc['name'];
			}
		}
		$charset = $env['collations'][ $coll ] ?? ( '' !== $coll ? strtok( $coll, '_' ) : 'utf8mb4' );
		$meta    = array(
			'name'          => $logical,
			'engine'        => $engine,
			'charset'       => $charset,
			'collation'     => $coll,
			'autoIncrement' => null === ( $info['AUTO_INCREMENT'] ?? null ) ? null : (int) $info['AUTO_INCREMENT'],
			'columns'       => $columns,
			'indexes'       => array_values( $indexes ),
			'primaryKey'    => $primary,
		);
		$meta['problems'] = $problems;
		$check            = self::validate_table( $meta, false );
		if ( is_wp_error( $check ) ) {
			$meta['problems'][] = $check->get_error_message();
		}
		return $meta;
	}

	private static function describe_column( array $c, bool $mariadb ): array {
		$type     = strtolower( trim( (string) $c['COLUMN_TYPE'] ) );
		$data     = strtolower( (string) $c['DATA_TYPE'] );
		$nullable = 'YES' === strtoupper( (string) $c['IS_NULLABLE'] );
		$extra    = strtolower( trim( (string) $c['EXTRA'] ) );
		$default  = $c['COLUMN_DEFAULT'];
		$col      = array(
			'name'        => (string) $c['COLUMN_NAME'],
			'type'        => $type,
			'nullable'    => $nullable,
			'default'     => null,
			'defaultKind' => 'none',
			'extra'       => '',
			'charset'     => null === $c['CHARACTER_SET_NAME'] ? null : strtolower( (string) $c['CHARACTER_SET_NAME'] ),
			'collation'   => null === $c['COLLATION_NAME'] ? null : strtolower( (string) $c['COLLATION_NAME'] ),
			'binary'      => in_array( $data, self::BINARY_TYPES, true ),
		);
		if ( ! empty( $c['GENERATION_EXPRESSION'] ) || preg_match( '/\b(virtual|stored|persistent)\b/', $extra ) ) {
			$col['problem'] = 'Generated column ' . $col['name'] . ' is not supported.';
		}
		if ( false !== strpos( $extra, 'invisible' ) ) {
			$col['problem'] = 'Invisible column ' . $col['name'] . ' is not supported.';
		}
		$is_generated_default = false !== strpos( $extra, 'default_generated' );
		$extra                = trim( str_replace( 'default_generated', '', $extra ) );
		if ( preg_match( '/^on update current_timestamp(\(([0-6]?)\))?$/', $extra, $m ) ) {
			$col['extra'] = 'on update CURRENT_TIMESTAMP' . ( ! empty( $m[2] ) ? '(' . $m[2] . ')' : '' );
		} elseif ( 'auto_increment' === $extra ) {
			$col['extra'] = 'auto_increment';
		} elseif ( '' !== $extra && ! isset( $col['problem'] ) ) {
			$col['problem'] = 'Column ' . $col['name'] . ' has unsupported attributes (' . $extra . ').';
		}
		if ( $mariadb ) {
			if ( null === $default || 'NULL' === $default ) {
				$col['defaultKind'] = $nullable ? 'null' : 'none';
			} elseif ( preg_match( '/^current_timestamp(\(([0-6]?)\))?$/i', (string) $default, $m ) ) {
				$col['defaultKind'] = 'expression';
				$col['default']     = 'CURRENT_TIMESTAMP' . ( ! empty( $m[2] ) ? '(' . $m[2] . ')' : '' );
			} elseif ( preg_match( "/^'(.*)'$/s", (string) $default, $m ) ) {
				$col['defaultKind'] = 'literal';
				$col['default']     = str_replace( "''", "'", $m[1] );
			} elseif ( is_numeric( $default ) ) {
				$col['defaultKind'] = 'literal';
				$col['default']     = (string) $default;
			} else {
				$col['problem'] = 'Column ' . $col['name'] . ' has an unsupported default expression.';
			}
		} elseif ( null === $default ) {
			$col['defaultKind'] = $nullable ? 'null' : 'none';
		} elseif ( $is_generated_default ) {
			if ( preg_match( '/^current_timestamp(\(([0-6]?)\))?$/i', (string) $default, $m ) ) {
				$col['defaultKind'] = 'expression';
				$col['default']     = 'CURRENT_TIMESTAMP' . ( ! empty( $m[2] ) ? '(' . $m[2] . ')' : '' );
			} else {
				$col['problem'] = 'Column ' . $col['name'] . ' has an unsupported default expression.';
			}
		} else {
			$col['defaultKind'] = 'literal';
			$col['default']     = (string) $default;
		}
		return $col;
	}

	/* ------------------------------------------------------------------ */
	/* Import: validation and DDL                                          */
	/* ------------------------------------------------------------------ */

	public static function valid_type( string $type ): bool {
		if ( strlen( $type ) > 4096 || preg_match( '/[\x00-\x1f]/', $type ) ) {
			return false;
		}
		$lit      = "'(?:[^'\\\\]|''|\\\\.)*'";
		$patterns = array(
			'/^(tinyint|smallint|mediumint|int|integer|bigint)(\([0-9]{1,3}\))?( unsigned)?( zerofill)?$/',
			'/^(decimal|numeric)(\([0-9]{1,2}(,[0-9]{1,2})?\))?( unsigned)?( zerofill)?$/',
			'/^(float|double)(\([0-9]{1,3},[0-9]{1,2}\))?( unsigned)?( zerofill)?$/',
			'/^bit(\([0-9]{1,2}\))?$/',
			'/^(char|varchar|binary|varbinary)\([0-9]{1,5}\)$/',
			'/^(tinytext|text|mediumtext|longtext|tinyblob|blob|mediumblob|longblob|json|date|year|year\(4\))$/',
			'/^(datetime|timestamp|time)(\([0-6]\))?$/',
			'/^(enum|set)\(' . $lit . '(,' . $lit . ')*\)$/s',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $type ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validates archive table metadata against the supported structures.
	 *
	 * @param bool $destination Also map engine/collation onto this server (import side).
	 * @return array|\WP_Error Normalized metadata (with 'warnings') or the reason it is unsupported.
	 */
	public static function validate_table( array $t, bool $destination = true ) {
		$err = static function ( string $why ) use ( $t ) {
			return new \WP_Error( 'cradle_migration_schema', sprintf( 'Table "%1$s": %2$s', is_string( $t['name'] ?? null ) ? preg_replace( '/[^A-Za-z0-9_]/', '?', $t['name'] ) : '?', $why ) );
		};
		if ( ! is_string( $t['name'] ?? null ) || ! preg_match( '/^[A-Za-z0-9_]{1,54}$/', $t['name'] ) ) {
			return $err( 'invalid table name.' );
		}
		if ( ! is_array( $t['columns'] ?? null ) || ! $t['columns'] || count( $t['columns'] ) > 1000 ) {
			return $err( 'missing or too many columns.' );
		}
		$warnings = array();
		$env      = $destination ? self::env() : null;
		$engine   = strtolower( (string) ( $t['engine'] ?? '' ) );
		if ( ! in_array( $engine, self::ENGINES, true ) ) {
			return $err( 'unsupported engine.' );
		}
		if ( $env && ! in_array( $engine, $env['engines'], true ) ) {
			$warnings[] = sprintf( 'Engine %s is unavailable here; InnoDB is used.', $engine );
			$engine     = 'innodb';
		}
		$collation = strtolower( (string) ( $t['collation'] ?? '' ) );
		if ( ! preg_match( '/^[a-z0-9_]{1,64}$/', $collation ) ) {
			return $err( 'invalid collation.' );
		}
		if ( $env ) {
			$mapped = self::map_collation( $collation, $env );
			if ( null === $mapped ) {
				return $err( 'collation ' . $collation . ' is not available on this database server.' );
			}
			if ( $mapped !== $collation ) {
				$warnings[] = sprintf( 'Collation %1$s mapped to %2$s.', $collation, $mapped );
			}
			$collation = $mapped;
		}
		$names   = array();
		$columns = array();
		foreach ( $t['columns'] as $c ) {
			if ( ! is_array( $c ) || ! is_string( $c['name'] ?? null ) || ! preg_match( self::IDENT, $c['name'] ) ) {
				return $err( 'invalid column name.' );
			}
			if ( isset( $names[ strtolower( $c['name'] ) ] ) ) {
				return $err( 'duplicate column.' );
			}
			$names[ strtolower( $c['name'] ) ] = true;
			$type                              = strtolower( (string) ( $c['type'] ?? '' ) );
			if ( ! self::valid_type( $type ) ) {
				return $err( 'column ' . $c['name'] . ' has an unsupported type.' );
			}
			$kind = (string) ( $c['defaultKind'] ?? 'none' );
			if ( ! in_array( $kind, array( 'none', 'null', 'literal', 'expression' ), true ) ) {
				return $err( 'column ' . $c['name'] . ' has an invalid default kind.' );
			}
			if ( 'expression' === $kind && ! preg_match( '/^CURRENT_TIMESTAMP(\([0-6]\))?$/', (string) ( $c['default'] ?? '' ) ) ) {
				return $err( 'column ' . $c['name'] . ' has an unsupported default expression.' );
			}
			if ( 'literal' === $kind && ( ! is_string( $c['default'] ?? null ) || strlen( $c['default'] ) > 65535 ) ) {
				return $err( 'column ' . $c['name'] . ' has an invalid default.' );
			}
			$extra = (string) ( $c['extra'] ?? '' );
			if ( '' !== $extra && 'auto_increment' !== $extra && ! preg_match( '/^on update CURRENT_TIMESTAMP(\([0-6]\))?$/', $extra ) ) {
				return $err( 'column ' . $c['name'] . ' has unsupported attributes.' );
			}
			$col_coll = null;
			if ( null !== ( $c['collation'] ?? null ) ) {
				$col_coll = strtolower( (string) $c['collation'] );
				if ( ! preg_match( '/^[a-z0-9_]{1,64}$/', $col_coll ) ) {
					return $err( 'invalid column collation.' );
				}
				if ( $env ) {
					$mapped = self::map_collation( $col_coll, $env );
					if ( null === $mapped ) {
						return $err( 'collation ' . $col_coll . ' is not available on this database server.' );
					}
					$col_coll = $mapped;
				}
			}
			$base      = strtok( $type, '( ' );
			$columns[] = array(
				'name'        => $c['name'],
				'type'        => $type,
				'nullable'    => ! empty( $c['nullable'] ),
				'default'     => 'literal' === $kind || 'expression' === $kind ? (string) $c['default'] : null,
				'defaultKind' => $kind,
				'extra'       => $extra,
				'collation'   => $col_coll,
				'binary'      => in_array( $base, self::BINARY_TYPES, true ),
				'text'        => in_array( $base, self::TEXT_TYPES, true ),
			);
		}
		$indexes = array();
		foreach ( (array) ( $t['indexes'] ?? array() ) as $ix ) {
			if ( ! is_array( $ix ) || ! is_string( $ix['name'] ?? null ) || ! preg_match( self::IDENT, $ix['name'] ) ) {
				return $err( 'invalid index name.' );
			}
			$type = (string) ( $ix['type'] ?? 'BTREE' );
			if ( ! in_array( $type, array( 'BTREE', 'FULLTEXT' ), true ) ) {
				return $err( 'unsupported index type.' );
			}
			$cols = array();
			foreach ( (array) ( $ix['columns'] ?? array() ) as $ic ) {
				if ( ! is_array( $ic ) || ! is_string( $ic['name'] ?? null ) || ! isset( $names[ strtolower( $ic['name'] ) ] ) ) {
					return $err( 'index references an unknown column.' );
				}
				$sub = $ic['subPart'] ?? null;
				if ( null !== $sub && ( ! is_int( $sub ) || $sub < 1 || $sub > 3072 ) ) {
					return $err( 'invalid index prefix length.' );
				}
				$cols[] = array(
					'name'    => $ic['name'],
					'subPart' => $sub,
				);
			}
			if ( ! $cols || count( $cols ) > 32 ) {
				return $err( 'index without columns.' );
			}
			$indexes[] = array(
				'name'    => $ix['name'],
				'unique'  => ! empty( $ix['unique'] ),
				'type'    => $type,
				'columns' => $cols,
			);
		}
		$primary = array();
		foreach ( (array) ( $t['primaryKey'] ?? array() ) as $pk ) {
			if ( ! is_string( $pk ) || ! isset( $names[ strtolower( $pk ) ] ) ) {
				return $err( 'primary key references an unknown column.' );
			}
			$primary[] = $pk;
		}
		$ai = $t['autoIncrement'] ?? null;
		return array(
			'name'          => $t['name'],
			'engine'        => $engine,
			'collation'     => $collation,
			'charset'       => $env ? ( $env['collations'][ $collation ] ?? 'utf8mb4' ) : (string) ( $t['charset'] ?? 'utf8mb4' ),
			'autoIncrement' => is_int( $ai ) && $ai > 0 ? $ai : null,
			'columns'       => $columns,
			'indexes'       => $indexes,
			'primaryKey'    => $primary,
			'warnings'      => $warnings,
		);
	}

	private static function map_collation( string $collation, array $env ) {
		$collation = str_replace( 'utf8_', 'utf8mb3_', $collation );
		if ( isset( $env['collations'][ $collation ] ) ) {
			return $collation;
		}
		if ( 0 === strpos( $collation, 'utf8mb3_' ) && isset( $env['collations'][ str_replace( 'utf8mb3_', 'utf8_', $collation ) ] ) ) {
			return str_replace( 'utf8mb3_', 'utf8_', $collation );
		}
		if ( 0 === strpos( $collation, 'utf8mb4_' ) ) {
			foreach ( array( 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci' ) as $fallback ) {
				if ( isset( $env['collations'][ $fallback ] ) ) {
					return $fallback;
				}
			}
		}
		return null;
	}

	/**
	 * Builds CREATE TABLE from validated metadata (validate_table() output).
	 */
	public static function create_sql( string $physical, array $t ): string {
		$lines = array();
		foreach ( $t['columns'] as $c ) {
			$line = self::ident( $c['name'] ) . ' ' . $c['type'];
			if ( null !== $c['collation'] ) {
				$line .= ' COLLATE ' . $c['collation'];
			}
			$line .= $c['nullable'] ? ' NULL' : ' NOT NULL';
			if ( 'null' === $c['defaultKind'] && $c['nullable'] ) {
				$line .= ' DEFAULT NULL';
			} elseif ( 'literal' === $c['defaultKind'] ) {
				$line .= ' DEFAULT ' . self::quote( $c['default'] );
			} elseif ( 'expression' === $c['defaultKind'] ) {
				$line .= ' DEFAULT ' . $c['default'];
			}
			if ( 'auto_increment' === $c['extra'] ) {
				$line .= ' AUTO_INCREMENT';
			} elseif ( '' !== $c['extra'] ) {
				$line .= ' ' . strtoupper( substr( $c['extra'], 0, 9 ) ) . substr( $c['extra'], 9 );
			}
			$lines[] = $line;
		}
		foreach ( $t['indexes'] as $ix ) {
			$cols = array();
			foreach ( $ix['columns'] as $ic ) {
				$cols[] = self::ident( $ic['name'] ) . ( null !== $ic['subPart'] ? '(' . (int) $ic['subPart'] . ')' : '' );
			}
			if ( 'PRIMARY' === $ix['name'] ) {
				$lines[] = 'PRIMARY KEY (' . implode( ',', $cols ) . ')';
			} elseif ( 'FULLTEXT' === $ix['type'] ) {
				$lines[] = 'FULLTEXT KEY ' . self::ident( $ix['name'] ) . ' (' . implode( ',', $cols ) . ')';
			} else {
				$lines[] = ( $ix['unique'] ? 'UNIQUE KEY ' : 'KEY ' ) . self::ident( $ix['name'] ) . ' (' . implode( ',', $cols ) . ')';
			}
		}
		$engines = array(
			'innodb' => 'InnoDB',
			'myisam' => 'MyISAM',
			'aria'   => 'Aria',
		);
		$sql     = 'CREATE TABLE ' . self::ident( $physical ) . " (\n  " . implode( ",\n  ", $lines ) . "\n) ENGINE=" . $engines[ $t['engine'] ];
		$sql    .= ' DEFAULT CHARSET=' . preg_replace( '/[^a-z0-9_]/', '', $t['charset'] ) . ' COLLATE=' . $t['collation'];
		if ( $t['autoIncrement'] ) {
			$sql .= ' AUTO_INCREMENT=' . (int) $t['autoIncrement'];
		}
		return $sql;
	}

	/* ------------------------------------------------------------------ */
	/* Rows                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Reads a bounded batch of rows in a stable order.
	 *
	 * @param array $meta   describe() output.
	 * @param array $cursor array{last?:string,offset?:int}
	 * @return array{rows:array<int,array>,exhausted:bool}|\WP_Error Rows as numeric arrays (strings/null).
	 */
	public static function read_batch( string $physical, array $meta, array $cursor, int $limit ) {
		$cols = array();
		foreach ( $meta['columns'] as $c ) {
			$cols[] = self::ident( $c['name'] );
		}
		$sql   = 'SELECT ' . implode( ',', $cols ) . ' FROM ' . self::ident( $physical );
		$keyed = self::keyset_column( $meta );
		if ( null !== $keyed ) {
			if ( isset( $cursor['last'] ) && preg_match( '/^-?[0-9]{1,20}$/', (string) $cursor['last'] ) ) {
				$sql .= ' WHERE ' . self::ident( $keyed ) . ' > ' . $cursor['last'];
			}
			$sql .= ' ORDER BY ' . self::ident( $keyed ) . ' LIMIT ' . (int) $limit;
		} else {
			if ( $meta['primaryKey'] ) {
				$sql .= ' ORDER BY ' . implode( ',', array_map( array( __CLASS__, 'ident' ), $meta['primaryKey'] ) );
			}
			$sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) ( $cursor['offset'] ?? 0 );
		}
		$result = self::exec( $sql );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$rows = array();
		while ( $row = mysqli_fetch_row( $result ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$rows[] = $row;
		}
		mysqli_free_result( $result );
		return array(
			'rows'      => $rows,
			'exhausted' => count( $rows ) < $limit,
		);
	}

	/**
	 * Single integer primary-key column usable for keyset pagination, or null.
	 */
	public static function keyset_column( array $meta ) {
		if ( 1 !== count( $meta['primaryKey'] ) ) {
			return null;
		}
		foreach ( $meta['columns'] as $i => $c ) {
			if ( $c['name'] === $meta['primaryKey'][0] ) {
				return preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint)\b/', $c['type'] ) ? $c['name'] : null;
			}
		}
		return null;
	}

	/**
	 * Inserts decoded rows. REPLACE is used for keyed tables so a re-run chunk is idempotent.
	 *
	 * @param array $t    validate_table() output.
	 * @param array $rows Rows of values: null | string (binary values already decoded).
	 * @return true|\WP_Error
	 */
	public static function insert_rows( string $physical, array $t, array $rows, bool $replace ) {
		if ( ! $rows ) {
			return true;
		}
		$cols = array();
		foreach ( $t['columns'] as $c ) {
			$cols[] = self::ident( $c['name'] );
		}
		$env = self::env();
		if ( empty( $env['ok'] ) ) {
			return new \WP_Error( 'cradle_migration_db', 'Database unavailable: ' . ( $env['error'] ?? 'unknown error' ) );
		}
		$head  = ( $replace ? 'REPLACE' : 'INSERT' ) . ' INTO ' . self::ident( $physical ) . ' (' . implode( ',', $cols ) . ') VALUES ';
		$limit = (int) min( 8 * 1048576, $env['maxPacket'] / 2 );
		$batch = array();
		$size  = strlen( $head );
		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $t['columns'] as $i => $c ) {
				$v = $row[ $i ] ?? null;
				if ( null === $v ) {
					$values[] = 'NULL';
				} elseif ( $c['binary'] ) {
					$values[] = '' === $v ? "''" : "X'" . bin2hex( $v ) . "'";
				} else {
					$values[] = self::quote( (string) $v );
				}
			}
			$tuple = '(' . implode( ',', $values ) . ')';
			if ( $batch && $size + strlen( $tuple ) + 1 > $limit ) {
				$ok = self::exec( $head . implode( ',', $batch ) );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				$batch = array();
				$size  = strlen( $head );
			}
			$batch[] = $tuple;
			$size   += strlen( $tuple ) + 1;
		}
		$ok = self::exec( $head . implode( ',', $batch ) );
		return is_wp_error( $ok ) ? $ok : true;
	}

	public static function table_exists( string $name ): bool {
		global $wpdb;
		$rows = self::rows( 'SHOW TABLES LIKE ' . self::quote( $wpdb->esc_like( $name ) ) );
		if ( is_wp_error( $rows ) ) {
			return false;
		}
		foreach ( $rows as $row ) {
			if ( 0 === strcasecmp( (string) array_values( $row )[0], $name ) ) {
				return true;
			}
		}
		return false;
	}

	public static function count( string $name ): int {
		$rows = self::rows( 'SELECT COUNT(*) AS c FROM ' . self::ident( $name ) );
		return is_wp_error( $rows ) ? -1 : (int) ( $rows[0]['c'] ?? 0 );
	}

	/**
	 * Lists tables whose names start with a (validated) prefix.
	 *
	 * @return string[]
	 */
	public static function tables_with_prefix( string $prefix ): array {
		global $wpdb;
		$out  = array();
		$rows = self::rows( 'SHOW TABLES LIKE ' . self::quote( $wpdb->esc_like( $prefix ) . '%' ) );
		foreach ( is_wp_error( $rows ) ? array() : $rows as $row ) {
			$name = (string) array_values( $row )[0];
			if ( 0 === stripos( $name, $prefix ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	public static function drop( string $name ) {
		return self::exec( 'DROP TABLE IF EXISTS ' . self::ident( $name ) );
	}

	/**
	 * Renames several tables in one RENAME TABLE statement.
	 *
	 * @param array<int,array{0:string,1:string}> $pairs
	 * @return true|\WP_Error
	 */
	public static function rename( array $pairs ) {
		if ( ! $pairs ) {
			return true;
		}
		$parts = array();
		foreach ( $pairs as $pair ) {
			$parts[] = self::ident( $pair[0] ) . ' TO ' . self::ident( $pair[1] );
		}
		$ok = self::exec( 'RENAME TABLE ' . implode( ', ', $parts ) );
		return is_wp_error( $ok ) ? $ok : true;
	}
}
