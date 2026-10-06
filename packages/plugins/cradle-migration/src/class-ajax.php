<?php
/**
 * Browser endpoints (admin-ajax, POST only) for uploads, operator decisions and
 * post-cutover continuation. Every endpoint checks a nonce plus the migration
 * capability and job-level access (owner, or an administrator with manage_options),
 * except the continuation endpoint, whose credential is the one-time job-scoped
 * recovery token (the browser session is invalidated by the restore itself).
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

use Cradle\Core\Jobs;

defined( 'ABSPATH' ) || exit;

final class Ajax {

	const NONCE = 'cradle_migration';

	const POST_CUTOVER = array( 'cutover_db', 'replace_files', 'finalize', 'verify', 'verify_failed', 'done' );

	public static function hooks() {
		foreach ( array( 'upload_begin', 'upload_chunk', 'upload_status', 'upload_finish', 'restore', 'approve', 'cutover', 'report' ) as $action ) {
			add_action( 'wp_ajax_cradle_migration_' . $action, array( __CLASS__, $action ) );
		}
		add_action( 'wp_ajax_cradle_migration_continue', array( __CLASS__, 'continue_job' ) );
		add_action( 'wp_ajax_nopriv_cradle_migration_continue', array( __CLASS__, 'continue_job' ) );
		// Core has no per-type cancel veto (API gap): refuse cancelling an import once cutover began.
		add_action( 'wp_ajax_cradle_job_cancel', array( __CLASS__, 'guard_cancel' ), 1 );
	}

	private static function deny( string $message, int $status = 403 ) {
		wp_send_json_error( array( 'message' => $message ), $status );
	}

	private static function require_post() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
			self::deny( 'POST required.', 405 );
		}
	}

	private static function authorize( string $cap ) {
		self::require_post();
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			self::deny( __( 'Your session expired. Reload the page.', 'cradle-migration' ) );
		}
		if ( ! current_user_can( $cap ) ) {
			self::deny( __( 'You are not allowed to do this.', 'cradle-migration' ) );
		}
	}

	/**
	 * Loads a migration job the current user may act on (object-level check), or exits with 404.
	 */
	public static function job_for_user( string $uuid, string $type = '' ) {
		$job = Job_Store::get( $uuid );
		if ( ! $job || ( '' !== $type && $job['type'] !== $type ) || ! cradle()->jobs()->user_can_access( $job ) ) {
			return null;
		}
		return $job;
	}

	private static function post_string( string $key ): string {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
	}

	public static function upload_begin() {
		self::authorize( 'cradle_import_site' );
		$files = json_decode( isset( $_POST['files'] ) ? wp_unslash( $_POST['files'] ) : '[]', true, 4 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON validated field by field.
		$result = Upload::begin( is_array( $files ) ? $files : array(), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			self::deny( $result->get_error_message(), 400 );
		}
		wp_send_json_success( $result );
	}

	public static function upload_chunk() {
		self::authorize( 'cradle_import_site' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$file  = isset( $_POST['file'] ) ? absint( $_POST['file'] ) : -1;
		$index = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : -1;
		$tmp   = isset( $_FILES['chunk']['tmp_name'] ) ? (string) $_FILES['chunk']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			self::deny( 'No chunk received.', 400 );
		}
		$max = Upload::chunk_size();
		if ( filesize( $tmp ) > $max ) {
			self::deny( 'Chunk too large.', 413 );
		}
		$data   = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$result = Upload::chunk( self::post_string( 'session' ), $file, $index, $data, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			self::deny( $result->get_error_message(), 'cradle_migration_upload_conflict' === $result->get_error_code() ? 409 : 400 );
		}
		wp_send_json_success( $result );
	}

	public static function upload_status() {
		self::authorize( 'cradle_import_site' );
		$result = Upload::status( self::post_string( 'session' ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			self::deny( $result->get_error_message(), 404 );
		}
		wp_send_json_success( $result );
	}

	public static function upload_finish() {
		self::authorize( 'cradle_import_site' );
		$opts = array();
		foreach ( array( 'uploads', 'themes', 'plugins', 'languages', 'database' ) as $k ) {
			$opts[ $k ] = '0' !== self::post_string( 'opt_' . $k );
		}
		$result = Upload::finish( self::post_string( 'session' ), get_current_user_id(), $opts );
		if ( is_wp_error( $result ) ) {
			self::deny( $result->get_error_message(), 400 );
		}
		wp_send_json_success( Jobs::public_view( $result ) );
	}

	/**
	 * One-step restore of an archive stored on this site (Archives → Restore).
	 */
	public static function restore() {
		self::authorize( 'cradle_import_site' );
		$job = Importer::create_from_archive( self::post_string( 'archive' ) );
		if ( is_wp_error( $job ) ) {
			self::deny( $job->get_error_message(), 400 );
		}
		wp_send_json_success( Jobs::public_view( $job ) );
	}

	public static function approve() {
		self::authorize( 'cradle_import_site' );
		$job = self::job_for_user( self::post_string( 'job' ), Importer::TYPE );
		if ( ! $job ) {
			self::deny( __( 'Job not found.', 'cradle-migration' ), 404 );
		}
		$ok = Importer::approve( $job );
		if ( is_wp_error( $ok ) ) {
			self::deny( $ok->get_error_message(), 409 );
		}
		wp_send_json_success( Jobs::public_view( cradle()->jobs()->get( $job['uuid'] ) ) );
	}

	public static function cutover() {
		self::authorize( 'cradle_import_site' );
		$job = self::job_for_user( self::post_string( 'job' ), Importer::TYPE );
		if ( ! $job ) {
			self::deny( __( 'Job not found.', 'cradle-migration' ), 404 );
		}
		$result = Importer::begin_cutover( $job );
		if ( is_wp_error( $result ) ) {
			self::deny( $result->get_error_message(), 409 );
		}
		nocache_headers();
		wp_send_json_success( $result + array( 'job' => Jobs::public_view( cradle()->jobs()->get( $job['uuid'] ) ) ) );
	}

	public static function report() {
		self::authorize( 'cradle_import_site' );
		$job = self::job_for_user( self::post_string( 'job' ), Importer::TYPE );
		if ( ! $job ) {
			self::deny( __( 'Job not found.', 'cradle-migration' ), 404 );
		}
		$dir    = Workspace::job_dir( $job['uuid'] );
		$report = is_wp_error( $dir ) ? $dir : Workspace::read_json( $dir . '/report.json' );
		if ( is_wp_error( $report ) ) {
			self::deny( $report->get_error_message(), 404 );
		}
		wp_send_json_success( $report );
	}

	/**
	 * Post-cutover runner. Authenticated by the one-time job-scoped recovery token only;
	 * it can do nothing except advance that one job through its remaining fixed phases.
	 */
	public static function continue_job() {
		self::require_post();
		nocache_headers();
		$uuid  = self::post_string( 'job' );
		$token = self::post_string( 'token' );
		if ( ! wp_is_uuid( $uuid, 4 ) || ! Importer::check_token( $uuid, $token ) ) {
			self::deny( __( 'The recovery token is not valid for this job (it may have expired). Use the CLI recovery command.', 'cradle-migration' ) );
		}
		$job = cradle()->jobs()->get( $uuid );
		if ( ! $job || ! in_array( $job['phase'], self::POST_CUTOVER, true ) ) {
			self::deny( __( 'This job is not in its cutover phase.', 'cradle-migration' ), 409 );
		}
		if ( ! in_array( $job['state'], Jobs::TERMINAL_STATES, true ) ) {
			$job = cradle()->jobs()->run( $uuid );
			if ( is_wp_error( $job ) ) {
				self::deny( $job->get_error_message(), 409 );
			}
		}
		$view = Jobs::public_view( $job );
		if ( $view['terminal'] ) {
			$dir    = Workspace::job_dir( $uuid );
			$verify = is_wp_error( $dir ) ? null : Workspace::read_json( $dir . '/verify.json' );
			if ( is_array( $verify ) ) {
				$view['verification'] = array(
					'passed' => (bool) $verify['passed'],
					'admins' => array_map( 'strval', (array) $verify['admins'] ),
					'checks' => array_map(
						static fn( $c ) => array(
							'label'  => (string) $c['label'],
							'status' => (string) $c['status'],
							'detail' => (string) $c['detail'],
						),
						(array) $verify['checks']
					),
				);
			}
			// After signing in, the administrator lands on this import's outcome.
			$view['loginUrl'] = wp_login_url( admin_url( 'admin.php?page=cradle-migration&tab=import&job=' . $uuid ) );
		}
		wp_send_json_success( $view );
	}

	/**
	 * Refuses core job cancellation for an import whose cutover began (rollback is the safe path).
	 */
	public static function guard_cancel() {
		$uuid = self::post_string( 'job' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verifies the nonce next; this only refuses.
		$job  = wp_is_uuid( $uuid, 4 ) ? Job_Store::get( $uuid ) : null;
		if ( ! $job || Importer::TYPE !== $job['type'] ) {
			return;
		}
		$dir = Workspace::job_dir( $uuid );
		if ( is_wp_error( $dir ) ) {
			return;
		}
		if ( 'not_started' !== ( new Journal( $dir ) )->stage() || in_array( $job['phase'], self::POST_CUTOVER, true ) ) {
			self::deny( __( 'This restore has started switching the site over and cannot be cancelled. Let it finish, or roll it back from Cradle → Migration or the CLI.', 'cradle-migration' ), 409 );
		}
	}
}
