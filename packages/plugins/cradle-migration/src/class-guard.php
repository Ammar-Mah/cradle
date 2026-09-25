<?php
/**
 * Write-quiescence windows and the post-restore destination safety hold.
 *
 * Quiescence (option cradle_migration_quiesce) is used for a consistent export and for
 * the critical phase of a restore (DB cutover → file replacement → finalize):
 *   - export window: reads keep working; POST/PUT/DELETE requests, comments, XML-RPC,
 *     REST writes, AJAX (except migration/job runners) and WP-Cron are refused (503).
 *   - import window: every frontend/REST request answers 503 + Retry-After; admin GETs
 *     still render so the operator can watch progress; WP-Cron and email are paused.
 * A window names its job and expires on its own; it is ignored once the job is terminal.
 *
 * Safety hold (option cradle_migration_safety_hold) is set by every restore and stays
 * until an administrator releases it: outgoing email (pre_wp_mail), WP-Cron execution and
 * outbound HTTP to other hosts (pre_http_request) are paused so a clone never sends
 * production email, runs imported jobs or calls webhooks/payment APIs before review.
 * Limits: direct SQL writers, system cron invoking PHP scripts other than wp-cron.php,
 * and inbound webhooks from third parties are outside WordPress and are not stopped.
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

defined( 'ABSPATH' ) || exit;

final class Guard {

	const QUIESCE_OPTION = 'cradle_migration_quiesce';
	const HOLD_OPTION    = 'cradle_migration_safety_hold';

	const ALLOWED_AJAX = array( 'cradle_job_step', 'cradle_job_status', 'cradle_job_cancel' );

	/** @var array|null|false Per-request cache (false = not loaded). */
	private static $quiesce = false;

	public static function hooks() {
		add_action( 'init', array( __CLASS__, 'enforce' ), 1 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest' ), 1, 3 );
		add_filter( 'pre_get_ready_cron_jobs', array( __CLASS__, 'cron' ), 1 );
		add_filter( 'pre_wp_mail', array( __CLASS__, 'mail' ), 1, 2 );
		add_filter( 'pre_http_request', array( __CLASS__, 'http' ), 1, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/* -------------------------------- state ------------------------------- */

	public static function start_quiesce( string $job, string $mode, int $ttl = 900 ) {
		$value = array(
			'job'   => $job,
			'mode'  => 'import' === $mode ? 'import' : 'export',
			'since' => time(),
			'until' => time() + max( 60, $ttl ),
		);
		update_option( self::QUIESCE_OPTION, $value, true );
		self::$quiesce = $value;
		return $value;
	}

	public static function end_quiesce( string $job = '' ) {
		$current = get_option( self::QUIESCE_OPTION );
		if ( '' === $job || ( is_array( $current ) && ( $current['job'] ?? '' ) === $job ) ) {
			delete_option( self::QUIESCE_OPTION );
		}
		self::$quiesce = false;
	}

	/**
	 * Active window or null. Expired windows and windows of finished jobs are ignored.
	 */
	public static function quiesce() {
		if ( false !== self::$quiesce ) {
			return self::$quiesce;
		}
		$value = get_option( self::QUIESCE_OPTION );
		if ( ! is_array( $value ) || empty( $value['job'] ) || (int) ( $value['until'] ?? 0 ) < time() ) {
			self::$quiesce = null;
			return null;
		}
		if ( function_exists( 'cradle' ) && cradle() ) {
			$job = cradle()->jobs()->get( (string) $value['job'] );
			if ( ! $job || in_array( $job['state'], array( 'completed', 'failed', 'cancelled', 'rolled_back' ), true ) ) {
				self::$quiesce = null;
				return null;
			}
		}
		self::$quiesce = $value;
		return $value;
	}

	public static function hold() {
		$value = get_option( self::HOLD_OPTION );
		return is_array( $value ) && ! empty( $value['active'] ) ? $value : null;
	}

	public static function set_hold( array $info ) {
		update_option( self::HOLD_OPTION, array( 'active' => true ) + $info, true );
	}

	public static function release_hold(): bool {
		$hold = self::hold();
		if ( ! $hold ) {
			return false;
		}
		update_option(
			self::HOLD_OPTION,
			array(
				'active'     => false,
				'releasedAt' => time(),
				'releasedBy' => get_current_user_id(),
				'job'        => $hold['job'] ?? '',
			),
			true
		);
		return true;
	}

	/* ------------------------------ enforcement --------------------------- */

	private static function is_cli(): bool {
		return 'cli' === PHP_SAPI || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Runs at init priority 1 on every request while a window is active.
	 */
	public static function enforce() {
		if ( self::is_cli() || wp_doing_cron() ) {
			return;
		}
		$window = self::quiesce();
		if ( ! $window ) {
			return;
		}
		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );
		$read   = in_array( $method, array( 'GET', 'HEAD' ), true );
		$page   = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';

		if ( wp_doing_ajax() ) {
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( in_array( $action, self::ALLOWED_AJAX, true ) || 0 === strpos( $action, 'cradle_migration_' ) ) {
				return;
			}
			wp_send_json_error( array( 'message' => __( 'Site maintenance is in progress. Please retry shortly.', 'cradle-migration' ) ), 503 );
		}
		if ( 'wp-login.php' === $page ) {
			return;
		}
		if ( is_admin() ) {
			if ( $read ) {
				return;
			}
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( 'admin-post.php' === $page && 0 === strpos( $action, 'cradle_migration_' ) ) {
				return;
			}
			self::deny();
		}
		// Frontend, REST, XML-RPC, comment posting.
		if ( 'import' === $window['mode'] || ! $read || 'xmlrpc.php' === $page || 'wp-comments-post.php' === $page ) {
			self::deny();
		}
	}

	private static function deny() {
		status_header( 503 );
		header( 'Retry-After: 120' );
		nocache_headers();
		wp_die(
			esc_html__( 'This site is briefly unavailable for scheduled maintenance. Please try again in a few minutes.', 'cradle-migration' ),
			esc_html__( 'Maintenance', 'cradle-migration' ),
			array( 'response' => 503 )
		);
	}

	public static function rest( $result, $server, $request ) {
		$window = self::quiesce();
		if ( ! $window || self::is_cli() ) {
			return $result;
		}
		if ( 'import' === $window['mode'] || 'GET' !== $request->get_method() ) {
			return new \WP_Error( 'cradle_maintenance', __( 'Site maintenance is in progress.', 'cradle-migration' ), array( 'status' => 503 ) );
		}
		return $result;
	}

	public static function cron( $pre ) {
		if ( self::quiesce() || self::hold() ) {
			return array();
		}
		return $pre;
	}

	public static function mail( $pre, $atts ) {
		if ( null === $pre && ( self::quiesce() || self::hold() ) ) {
			return false;
		}
		return $pre;
	}

	public static function http( $pre, $args, $url ) {
		if ( false !== $pre || ( ! self::quiesce() && ! self::hold() ) ) {
			return $pre;
		}
		$host  = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		$local = array_filter(
			array(
				strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
				strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
				'localhost',
				'127.0.0.1',
				'::1',
			)
		);
		if ( in_array( $host, $local, true ) ) {
			return $pre;
		}
		return new \WP_Error( 'cradle_safety_hold', __( 'Outbound HTTP is paused by the Cradle Migration safety hold until an administrator releases it.', 'cradle-migration' ) );
	}

	public static function notice() {
		if ( ! current_user_can( 'cradle_import_site' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( self::hold() ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Cradle Migration safety hold is active.', 'cradle-migration' ) . '</strong> ' . esc_html__( 'After a restore, outgoing email, scheduled tasks (WP-Cron) and outbound HTTP to other hosts are paused so this copy cannot send production email or call external services. Review the site, then release the hold on Cradle → Migration.', 'cradle-migration' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=cradle-migration&tab=jobs' ) ) . '">' . esc_html__( 'Review', 'cradle-migration' ) . '</a></p></div>';
		}
		$window = self::quiesce();
		if ( $window ) {
			echo '<div class="notice notice-info"><p>' . esc_html( 'import' === $window['mode'] ? __( 'A restore is switching this site over. Visitors see a maintenance page until it finishes.', 'cradle-migration' ) : __( 'An export write-quiescence window is active: the site is read-only until the export finishes.', 'cradle-migration' ) ) . '</p></div>';
		}
	}
}
