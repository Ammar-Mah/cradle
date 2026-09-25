<?php
/**
 * Append-only cutover journal and the job-scoped recovery context.
 *
 * Both live in the job's private directory, not in any table that a restore
 * replaces. Each journal line is a JSON object flushed (and fsync'ed where
 * available) before the operation it announces, so a crash at any point leaves
 * enough information to roll forward or back (see docs/recovery-guide.md).
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

defined( 'ABSPATH' ) || exit;

final class Journal {

	/** @var string */
	private $dir;

	public function __construct( string $job_dir ) {
		$this->dir = rtrim( $job_dir, '/' );
	}

	public function dir(): string {
		return $this->dir;
	}

	public function path(): string {
		return $this->dir . '/journal.jsonl';
	}

	/**
	 * @return true|\WP_Error
	 */
	public function append( string $op, array $data = array() ) {
		$record = array(
			'op'   => $op,
			'time' => gmdate( 'c' ),
		) + $data;
		$fh     = fopen( $this->path(), 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return new \WP_Error( 'cradle_migration_journal', 'The recovery journal could not be opened; refusing to continue.' );
		}
		$ok = fwrite( $fh, json_encode( $record, JSON_UNESCAPED_SLASHES ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fflush( $fh );
		if ( function_exists( 'fsync' ) ) {
			@fsync( $fh ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return false === $ok ? new \WP_Error( 'cradle_migration_journal', 'The recovery journal could not be written (disk full?); refusing to continue.' ) : true;
	}

	/**
	 * @return array<int,array>
	 */
	public function events(): array {
		$out = array();
		if ( ! is_file( $this->path() ) ) {
			return $out;
		}
		foreach ( (array) file( $this->path(), FILE_IGNORE_NEW_LINES ) as $line ) {
			$row = json_decode( (string) $line, true );
			if ( is_array( $row ) && isset( $row['op'] ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	public function has( string $op ): bool {
		foreach ( $this->events() as $event ) {
			if ( $event['op'] === $op ) {
				return true;
			}
		}
		return false;
	}

	public function last( string $op ) {
		$found = null;
		foreach ( $this->events() as $event ) {
			if ( $event['op'] === $op ) {
				$found = $event;
			}
		}
		return $found;
	}

	/**
	 * Indexes of completed file operations.
	 *
	 * @return array<int,bool>
	 */
	public function done_file_ops(): array {
		$done = array();
		foreach ( $this->events() as $event ) {
			if ( 'file_op_done' === $event['op'] ) {
				$done[ (int) $event['i'] ] = true;
			}
		}
		return $done;
	}

	/**
	 * Summarizes where a restore stopped.
	 */
	public function stage(): string {
		$stage = 'not_started';
		foreach ( $this->events() as $event ) {
			switch ( $event['op'] ) {
				case 'db_cutover_begin':
					$stage = 'db_cutover_in_progress';
					break;
				case 'db_cutover_done':
					$stage = 'db_swapped';
					break;
				case 'file_op_begin':
				case 'file_op_done':
					$stage = 'files_in_progress';
					break;
				case 'files_done':
					$stage = 'files_replaced';
					break;
				case 'finalized':
					$stage = 'finalized';
					break;
				case 'verified':
					$stage = 'verified';
					break;
				case 'rollback_begin':
					$stage = 'rollback_in_progress';
					break;
				case 'rolled_back':
					$stage = 'rolled_back';
					break;
			}
		}
		return $stage;
	}

	public function recovery_path(): string {
		return $this->dir . '/recovery.json';
	}

	public function recovery() {
		if ( ! is_file( $this->recovery_path() ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $this->recovery_path() ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_array( $data ) ? $data : null;
	}

	/**
	 * @return true|\WP_Error
	 */
	public function write_recovery( array $data ) {
		$tmp = $this->recovery_path() . '.tmp';
		if ( false === file_put_contents( $tmp, json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new \WP_Error( 'cradle_migration_recovery', 'The recovery context could not be written.' );
		}
		if ( ! rename( $tmp, $this->recovery_path() ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			return new \WP_Error( 'cradle_migration_recovery', 'The recovery context could not be saved.' );
		}
		$check = $this->recovery();
		return ( is_array( $check ) && ( $check['job'] ?? '' ) === ( $data['job'] ?? null ) ) ? true : new \WP_Error( 'cradle_migration_recovery', 'The recovery context could not be verified after writing.' );
	}
}
