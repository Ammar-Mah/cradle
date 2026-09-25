<?php
/**
 * Cradle → Dummy content: batches, generation form, batch preview/cleanup, limits.
 *
 * Every mutation is a POST to admin-post.php with an action-specific nonce and
 * a capability check; batch and job IDs are validated server-side. GET only
 * renders. Jobs are driven by core's job runner (admin-ajax, `cradle_jobs`
 * nonce) with a no-JavaScript "run next step" fallback.
 *
 * @package Cradle\Dummy_Content
 */

namespace Cradle\Dummy_Content\Admin;

use Cradle\Dummy_Content\Batches;
use Cradle\Dummy_Content\Cleanup;
use Cradle\Dummy_Content\Config;
use Cradle\Dummy_Content\Generator;
use Cradle\Dummy_Content\Lifecycle;
use Cradle\Dummy_Content\Ownership;
use Cradle\Dummy_Content\Request;
use Cradle\Dummy_Content\Vocabulary;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const PAGE = 'cradle-dummy-content';

	/** @var string */
	private $hook = '';

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_cradle_dummy_generate', array( $this, 'handle_generate' ) );
		add_action( 'admin_post_cradle_dummy_cleanup', array( $this, 'handle_cleanup' ) );
		add_action( 'admin_post_cradle_dummy_job', array( $this, 'handle_job' ) );
		add_action( 'admin_post_cradle_dummy_forget', array( $this, 'handle_forget' ) );
		add_filter( 'display_post_states', array( $this, 'post_states' ), 10, 2 );
	}

	private static function can_view(): bool {
		return current_user_can( Lifecycle::CAP_GENERATE ) || current_user_can( Lifecycle::CAP_CLEANUP );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	public function menu() {
		$cap        = current_user_can( Lifecycle::CAP_GENERATE ) ? Lifecycle::CAP_GENERATE : Lifecycle::CAP_CLEANUP;
		$this->hook = (string) add_submenu_page( 'cradle', __( 'Dummy content', 'cradle-dummy-content' ), __( 'Dummy content', 'cradle-dummy-content' ), $cap, self::PAGE, array( $this, 'render' ) );
	}

	/**
	 * @param string $hook Admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( ! $this->hook || $hook !== $this->hook ) {
			return;
		}
		wp_enqueue_style( 'cradle-admin' );
		wp_enqueue_script( 'cradle-admin' );
		\Cradle\Core\Assets::register_style( 'cradle-dummy-admin', CRADLE_DUMMY_CONTENT_FILE, 'assets/css/dummy-admin.css', array( 'cradle-admin' ), CRADLE_DUMMY_CONTENT_VERSION );
		\Cradle\Core\Assets::register_script( 'cradle-dummy-admin', CRADLE_DUMMY_CONTENT_FILE, 'assets/js/dummy-admin.js', array( 'cradle-admin', 'wp-i18n' ), CRADLE_DUMMY_CONTENT_VERSION );
		wp_set_script_translations( 'cradle-dummy-admin', 'cradle-dummy-content', CRADLE_DUMMY_CONTENT_DIR . 'languages' );
		wp_enqueue_style( 'cradle-dummy-admin' );
		wp_enqueue_script( 'cradle-dummy-admin' );
	}

	/**
	 * Marks generated items in list tables (label only; not an authorization signal).
	 *
	 * @param array    $states Post states.
	 * @param \WP_Post $post   Post.
	 */
	public function post_states( $states, $post ) {
		if ( $post instanceof \WP_Post && Ownership::is_generated_post( $post->ID ) ) {
			$states['cradle_dummy'] = Ownership::is_modified( $post ) ? __( 'Dummy content (edited)', 'cradle-dummy-content' ) : __( 'Dummy content', 'cradle-dummy-content' );
		}
		return $states;
	}

	// ---------------------------------------------------------------------
	// Rendering.
	// ---------------------------------------------------------------------

	public function render() {
		if ( ! self::can_view() ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'cradle-dummy-content' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'batches';
		$batch = isset( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( $_GET['batch'] ) ) : '';
		// phpcs:enable
		echo '<div class="wrap cradle-wrap cradle-dummy">';
		echo '<h1>' . esc_html__( 'Dummy content', 'cradle-dummy-content' ) . '</h1>';
		$this->notices();
		$tabs = array( 'batches' => __( 'Batches & cleanup', 'cradle-dummy-content' ) );
		if ( current_user_can( Lifecycle::CAP_GENERATE ) ) {
			$tabs['generate'] = __( 'Generate', 'cradle-dummy-content' );
		}
		if ( current_user_can( 'manage_options' ) ) {
			$tabs['settings'] = __( 'Limits', 'cradle-dummy-content' );
		}
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'batches';
		}
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Dummy content sections', 'cradle-dummy-content' ) . '">';
		foreach ( $tabs as $key => $label ) {
			$current = $key === $tab && ( '' === $batch || 'batches' !== $key );
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( self::url( array( 'tab' => $key ) ) ),
				$current ? ' nav-tab-active' : '',
				$current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
		if ( Request::is_production() ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'This is a production environment.', 'cradle-dummy-content' ) . '</strong> ' . esc_html__( 'Generating sample content here needs an explicit confirmation. Prefer a development or staging copy.', 'cradle-dummy-content' ) . '</p></div>';
		}
		if ( '' !== $batch && 'batches' === $tab ) {
			$this->render_batch( $batch );
		} elseif ( 'generate' === $tab ) {
			$this->render_generate();
		} elseif ( 'settings' === $tab ) {
			cradle()->settings()->render_form( Config::SECTION );
		} else {
			$this->render_batches();
		}
		echo '</div>';
	}

	private function notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		$code     = isset( $_GET['cradle_notice'] ) ? sanitize_key( wp_unslash( $_GET['cradle_notice'] ) ) : '';
		$messages = array(
			'generation_started' => array( 'success', __( 'Generation started. Keep this page open, or come back later to resume it.', 'cradle-dummy-content' ) ),
			'cleanup_started'    => array( 'success', __( 'Cleanup started. Progress is saved after every step; you can cancel and resume later.', 'cradle-dummy-content' ) ),
			'job_cancelled'      => array( 'info', __( 'The job was cancelled. Everything done so far is kept and recorded in the report.', 'cradle-dummy-content' ) ),
			'job_stepped'        => array( 'info', __( 'One step ran. Continue until the job completes.', 'cradle-dummy-content' ) ),
			'job_finished'       => array( 'success', __( 'The job finished. The report is below.', 'cradle-dummy-content' ) ),
			'forgotten'          => array( 'success', __( 'The batch record was removed.', 'cradle-dummy-content' ) ),
			'error'              => array( 'error', __( 'The request could not be completed.', 'cradle-dummy-content' ) ),
		);
		if ( isset( $messages[ $code ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $code ][0] ), esc_html( $messages[ $code ][1] ) );
		}
		$error = get_transient( 'cradle_dummy_error_' . get_current_user_id() );
		if ( is_string( $error ) && '' !== $error ) {
			delete_transient( 'cradle_dummy_error_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
	}

	private function render_batches() {
		$records = Batches::all();
		$in_use  = Ownership::batches_in_use();
		$uuids   = array_unique( array_merge( array_keys( $records ), array_keys( $in_use ) ) );
		echo '<p>' . esc_html__( 'Every generated post, term and placeholder image carries protected ownership markers for its batch. Cleanup uses only those markers — never titles or category names — and previews what it will do first.', 'cradle-dummy-content' ) . '</p>';
		if ( current_user_can( Lifecycle::CAP_GENERATE ) ) {
			echo '<p><a class="button button-primary" href="' . esc_url( self::url( array( 'tab' => 'generate' ) ) ) . '">' . esc_html__( 'Generate a new batch', 'cradle-dummy-content' ) . '</a></p>';
		}
		echo '<table class="widefat striped cradle-table"><caption class="screen-reader-text">' . esc_html__( 'Dummy content batches', 'cradle-dummy-content' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Batch', 'cradle-dummy-content' ), __( 'Created', 'cradle-dummy-content' ), __( 'Type', 'cradle-dummy-content' ), __( 'Owned records (posts & media / terms)', 'cradle-dummy-content' ), __( 'State', 'cradle-dummy-content' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $uuids ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No batches yet.', 'cradle-dummy-content' ) . '</td></tr>';
		}
		foreach ( $uuids as $uuid ) {
			$record = $records[ $uuid ] ?? null;
			$owner  = $record ? get_userdata( $record['owner'] ) : null;
			$counts = $in_use[ $uuid ] ?? array(
				'posts' => 0,
				'terms' => 0,
			);
			echo '<tr><td><a href="' . esc_url( self::url( array( 'batch' => $uuid ) ) ) . '"><strong>' . esc_html( $record ? $record['name'] : __( 'Unrecorded batch', 'cradle-dummy-content' ) ) . '</strong></a><br /><code>' . esc_html( substr( $uuid, 0, 8 ) ) . '</code></td>';
			echo '<td>' . esc_html( $record ? get_date_from_gmt( $record['created'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '—' ) . ( $owner ? '<br />' . esc_html( $owner->display_name ) : '' ) . '</td>';
			echo '<td>' . esc_html( $record ? (string) ( $record['config']['post_type'] ?? '—' ) : '—' ) . '</td>';
			echo '<td>' . esc_html( sprintf( '%1$d / %2$d', $counts['posts'], $counts['terms'] ) ) . '</td>';
			$state = $record ? self::state_label( $record['state'] ) : __( 'Markers without a record', 'cradle-dummy-content' );
			if ( $record && 'generating' === $record['state'] && $record['job'] ) {
				$job = cradle()->jobs()->get( $record['job'] );
				if ( $job && in_array( $job['state'], array( 'cancelled', 'failed' ), true ) ) {
					/* translators: %s: job state */
					$state = sprintf( __( 'Generation stopped (%s) — can be resumed', 'cradle-dummy-content' ), $job['state'] );
				}
			}
			echo '<td>' . esc_html( $state ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function state_label( string $state ): string {
		$labels = array(
			'created'           => __( 'Queued', 'cradle-dummy-content' ),
			'generating'        => __( 'Generating', 'cradle-dummy-content' ),
			'generated'         => __( 'Generated', 'cradle-dummy-content' ),
			'trashed'           => __( 'In trash (some items may be kept)', 'cradle-dummy-content' ),
			'partially_removed' => __( 'Partially removed (kept items remain)', 'cradle-dummy-content' ),
			'removed'           => __( 'Removed', 'cradle-dummy-content' ),
			'unrecorded'        => __( 'Recovered record', 'cradle-dummy-content' ),
		);
		return $labels[ $state ] ?? $state;
	}

	private function render_job_box( array $job, string $batch ) {
		$label = 'dummy-content/generate' === $job['type'] ? __( 'Generation', 'cradle-dummy-content' ) : __( 'Cleanup', 'cradle-dummy-content' );
		echo '<div class="cradle-dummy-job card">';
		echo '<h2>' . esc_html( sprintf( /* translators: %s: job kind */ __( '%s in progress', 'cradle-dummy-content' ), $label ) ) . '</h2>';
		printf(
			'<div data-cradle-job="%1$s" class="cradle-dummy-runner"><progress class="cradle-progress" max="%2$d" value="%3$d"></progress><p aria-live="polite">%4$s</p></div>',
			esc_attr( $job['uuid'] ),
			max( 1, (int) $job['progress_total'] ),
			(int) $job['progress_done'],
			esc_html( $job['state'] . ( $job['phase'] ? ' — ' . $job['phase'] : '' ) )
		);
		echo '<div class="cradle-dummy-job-actions">';
		$this->job_form( $job['uuid'], $batch, 'run', __( 'Run next step', 'cradle-dummy-content' ), 'secondary cradle-dummy-nojs' );
		$this->job_form( $job['uuid'], $batch, 'cancel', __( 'Cancel job', 'cradle-dummy-content' ), 'secondary', __( 'Cancel this job? Work already done is kept and can be resumed later.', 'cradle-dummy-content' ) );
		echo '</div></div>';
	}

	private function job_form( string $job, string $batch, string $op, string $label, string $class, string $confirm = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cradle-inline-form"' . ( $confirm ? ' data-cradle-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="cradle_dummy_job" />';
		echo '<input type="hidden" name="job" value="' . esc_attr( $job ) . '" />';
		echo '<input type="hidden" name="batch" value="' . esc_attr( $batch ) . '" />';
		echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '" />';
		wp_nonce_field( 'cradle_dummy_job_' . $op . '_' . $job );
		submit_button( $label, $class, 'submit', false );
		echo '</form>';
	}

	private function active_jobs( string $uuid ): array {
		$out = array();
		foreach ( cradle()->jobs()->recent( 'dummy-content', 50 ) as $job ) {
			if ( ( $job['payload']['batch'] ?? '' ) === $uuid && ! in_array( $job['state'], \Cradle\Core\Jobs::TERMINAL_STATES, true ) && cradle()->jobs()->user_can_access( $job ) ) {
				$out[] = $job;
			}
		}
		return $out;
	}

	private function render_batch( string $uuid ) {
		$record = Batches::get( $uuid );
		$in_use = Ownership::batches_in_use();
		if ( ! $record && ! isset( $in_use[ $uuid ] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Unknown batch.', 'cradle-dummy-content' ) . '</p></div>';
			return;
		}
		$config = $record ? $record['config'] : array();
		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'All batches', 'cradle-dummy-content' ) . '</a></p>';
		echo '<h2>' . esc_html( $record ? $record['name'] : __( 'Unrecorded batch', 'cradle-dummy-content' ) ) . ' <code>' . esc_html( $uuid ) . '</code></h2>';
		if ( $record && '' !== $record['note'] ) {
			echo '<p class="cradle-dummy-note">' . nl2br( esc_html( $record['note'] ) ) . '</p>';
		}

		foreach ( $this->active_jobs( $uuid ) as $job ) {
			$this->render_job_box( $job, $uuid );
		}
		$running = Batches::active_job( $uuid );

		if ( $config ) {
			$this->render_config( $record );
		}
		if ( ! $running && $record && $record['job'] && current_user_can( Lifecycle::CAP_GENERATE ) ) {
			$gen = cradle()->jobs()->get( $record['job'] );
			if ( $gen && in_array( $gen['state'], array( 'cancelled', 'failed' ), true ) ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( /* translators: %s: job state */ __( 'Generation stopped (%s). Resuming skips items that already exist.', 'cradle-dummy-content' ), $gen['state'] ) ) . '</p>';
				$this->job_form( $gen['uuid'], $uuid, 'resume', __( 'Resume generation', 'cradle-dummy-content' ), 'secondary' );
				echo '</div>';
			}
		}

		$preview = Cleanup::preview( $uuid );
		$this->render_preview( $preview );
		if ( $running ) {
			echo '<p class="description">' . esc_html__( 'Cleanup actions are available when the running job has finished or has been cancelled.', 'cradle-dummy-content' ) . '</p>';
		} elseif ( current_user_can( Lifecycle::CAP_CLEANUP ) ) {
			$this->render_cleanup_forms( $uuid, $preview );
		}
		if ( $record ) {
			$this->render_reports( $record );
		}
	}

	private function render_config( array $record ) {
		$c    = $record['config'];
		$rows = array(
			__( 'Post type', 'cradle-dummy-content' )   => (string) ( $c['post_type'] ?? '' ),
			__( 'Count', 'cradle-dummy-content' )       => (string) ( $c['count'] ?? '' ),
			__( 'Status', 'cradle-dummy-content' )      => (string) ( $c['status'] ?? '' ),
			__( 'Seed', 'cradle-dummy-content' )        => (string) ( $c['seed'] ?? '' ),
			__( 'Titles / body', 'cradle-dummy-content' ) => sprintf( '%1$s%2$s / %3$s, %4$s', $c['title_pattern'] ?? '', ! empty( $c['title_template'] ) ? ' (' . $c['title_template'] . ')' : '', $c['body_pattern'] ?? '', $c['vocabulary'] ?? '' ),
			__( 'Dates', 'cradle-dummy-content' )       => ! empty( $c['dates'] ) ? implode( ' – ', (array) $c['dates'] ) : __( 'Current time', 'cradle-dummy-content' ),
			__( 'Featured images', 'cradle-dummy-content' ) => (string) ( $c['images']['mode'] ?? 'none' ),
			__( 'Production run', 'cradle-dummy-content' ) => ! empty( $c['production'] ) ? __( 'Yes (confirmed)', 'cradle-dummy-content' ) : __( 'No', 'cradle-dummy-content' ),
		);
		echo '<details class="cradle-dummy-config"><summary>' . esc_html__( 'Generation settings (reuse the seed to reproduce the same text)', 'cradle-dummy-content' ) . '</summary><table class="widefat striped cradle-table"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><code>' . esc_html( $value ) . '</code></td></tr>';
		}
		echo '</tbody></table></details>';
	}

	private function render_preview( array $p ) {
		echo '<h3>' . esc_html__( 'Cleanup preview', 'cradle-dummy-content' ) . '</h3>';
		echo '<table class="widefat striped cradle-table cradle-dummy-preview"><thead><tr><th scope="col">' . esc_html__( 'Entity', 'cradle-dummy-content' ) . '</th><th scope="col">' . esc_html__( 'Owned by this batch', 'cradle-dummy-content' ) . '</th><th scope="col">' . esc_html__( 'Kept by default', 'cradle-dummy-content' ) . '</th></tr></thead><tbody>';
		$types = array();
		foreach ( $p['posts']['by_type'] as $type => $n ) {
			$types[] = $type . ': ' . $n;
		}
		$statuses = array();
		foreach ( $p['posts']['by_status'] as $status => $n ) {
			$statuses[] = $status . ': ' . $n;
		}
		printf(
			'<tr><th scope="row">%1$s</th><td data-count="posts">%2$d<br /><small>%3$s<br />%4$s</small></td><td>%5$s</td></tr>',
			esc_html__( 'Posts', 'cradle-dummy-content' ),
			(int) $p['posts']['total'],
			esc_html( implode( ', ', $types ) ),
			esc_html( implode( ', ', $statuses ) ),
			esc_html(
				sprintf(
					/* translators: 1: edited count, 2: in-use count */
					__( '%1$d edited since generation, %2$d in use (menus or front page)', 'cradle-dummy-content' ),
					count( $p['posts']['modified'] ),
					count( $p['posts']['in_use'] )
				)
			)
		);
		printf(
			'<tr><th scope="row">%1$s</th><td data-count="terms">%2$d</td><td>%3$s</td></tr>',
			esc_html__( 'Terms', 'cradle-dummy-content' ),
			(int) $p['terms']['total'],
			esc_html( sprintf( /* translators: %d: count */ __( '%d used by other content (always kept); all terms are kept when moving to trash', 'cradle-dummy-content' ), count( $p['terms']['shared'] ) ) )
		);
		printf(
			'<tr><th scope="row">%1$s</th><td data-count="attachments">%2$d</td><td>%3$s</td></tr>',
			esc_html__( 'Placeholder images', 'cradle-dummy-content' ),
			(int) $p['attachments']['total'],
			esc_html( sprintf( /* translators: %d: count */ __( '%d used by other content (always kept); images are kept when moving to trash', 'cradle-dummy-content' ), count( $p['attachments']['in_use'] ) ) )
		);
		printf(
			'<tr><th scope="row">%1$s</th><td>—</td><td>%2$s</td></tr>',
			esc_html__( 'Pre-existing media and terms selected for this batch', 'cradle-dummy-content' ),
			esc_html( sprintf( /* translators: 1: media count, 2: term count */ __( '%1$d media items and %2$d terms were only referenced; they are never deleted.', 'cradle-dummy-content' ), $p['referenced']['attachments'], $p['referenced']['terms'] ) )
		);
		echo '</tbody></table>';
		if ( $p['posts']['modified'] || $p['posts']['in_use'] ) {
			echo '<h4>' . esc_html__( 'Items that changed since generation or are in use', 'cradle-dummy-content' ) . '</h4><ul class="ul-disc">';
			foreach ( array_slice( array_merge( $p['posts']['modified'], $p['posts']['in_use'] ), 0, 30 ) as $id ) {
				$title = get_the_title( $id );
				$link  = current_user_can( 'edit_post', $id ) ? get_edit_post_link( $id ) : '';
				$label = sprintf( '#%1$d %2$s', $id, '' !== $title ? $title : __( '(no title)', 'cradle-dummy-content' ) );
				echo '<li>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label ) ) . ( in_array( $id, $p['posts']['modified'], true ) ? ' — ' . esc_html__( 'edited (or a copy that inherited markers)', 'cradle-dummy-content' ) : ' — ' . esc_html__( 'in use', 'cradle-dummy-content' ) ) . '</li>';
			}
			echo '</ul>';
		}
	}

	private function render_cleanup_forms( string $uuid, array $p ) {
		$owned = $p['posts']['total'] + $p['terms']['total'] + $p['attachments']['total'];
		if ( ! $owned ) {
			echo '<p>' . esc_html__( 'This batch no longer owns any content.', 'cradle-dummy-content' ) . '</p>';
			if ( current_user_can( Lifecycle::CAP_CLEANUP ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-cradle-confirm="' . esc_attr__( 'Remove this batch record and its reports?', 'cradle-dummy-content' ) . '">';
				echo '<input type="hidden" name="action" value="cradle_dummy_forget" /><input type="hidden" name="batch" value="' . esc_attr( $uuid ) . '" />';
				wp_nonce_field( 'cradle_dummy_forget_' . $uuid );
				submit_button( __( 'Remove batch record', 'cradle-dummy-content' ), 'secondary', 'submit', false );
				echo '</form>';
			}
			return;
		}
		echo '<div class="cradle-dummy-cleanup">';
		// Default: trash.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="card">';
		echo '<h3>' . esc_html__( 'Move to trash (recommended)', 'cradle-dummy-content' ) . '</h3>';
		echo '<p>' . esc_html__( 'Moves this batch’s unedited generated posts to the trash. Terms and images stay until you purge, so trashed items can still be restored intact.', 'cradle-dummy-content' ) . '</p>';
		echo '<input type="hidden" name="action" value="cradle_dummy_cleanup" /><input type="hidden" name="batch" value="' . esc_attr( $uuid ) . '" /><input type="hidden" name="mode" value="trash" />';
		wp_nonce_field( 'cradle_dummy_cleanup_' . $uuid );
		echo '<p><label><input type="checkbox" name="include_modified" value="1" /> ' . esc_html__( 'Also include items edited since generation or in use', 'cradle-dummy-content' ) . '</label></p>';
		submit_button( __( 'Move generated items to trash', 'cradle-dummy-content' ), 'primary', 'submit', false );
		echo '</form>';
		// Separate explicit purge.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="card cradle-dummy-danger" data-cradle-confirm="' . esc_attr__( 'Permanently delete this batch’s generated content? This cannot be undone.', 'cradle-dummy-content' ) . '">';
		echo '<h3>' . esc_html__( 'Permanently delete (purge)', 'cradle-dummy-content' ) . '</h3>';
		echo '<p>' . esc_html__( 'Deletes generated posts (including trashed ones, their revisions and metadata), then generated terms and placeholder images that nothing else uses.', 'cradle-dummy-content' ) . '</p>';
		echo '<input type="hidden" name="action" value="cradle_dummy_cleanup" /><input type="hidden" name="batch" value="' . esc_attr( $uuid ) . '" /><input type="hidden" name="mode" value="purge" />';
		wp_nonce_field( 'cradle_dummy_cleanup_' . $uuid );
		echo '<p><label><input type="checkbox" name="include_modified" value="1" /> ' . esc_html__( 'Also include items edited since generation or in use', 'cradle-dummy-content' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="confirm_purge" value="1" required /> ' . esc_html__( 'I understand this permanently deletes content.', 'cradle-dummy-content' ) . '</label></p>';
		submit_button( __( 'Purge generated content', 'cradle-dummy-content' ), 'delete', 'submit', false );
		echo '</form></div>';
	}

	private function render_reports( array $record ) {
		if ( ! $record['reports'] ) {
			return;
		}
		echo '<h3>' . esc_html__( 'Operation reports', 'cradle-dummy-content' ) . '</h3>';
		echo '<table class="widefat striped cradle-table"><thead><tr><th scope="col">' . esc_html__( 'Operation', 'cradle-dummy-content' ) . '</th><th scope="col">' . esc_html__( 'When (UTC)', 'cradle-dummy-content' ) . '</th><th scope="col">' . esc_html__( 'By', 'cradle-dummy-content' ) . '</th><th scope="col">' . esc_html__( 'Job state', 'cradle-dummy-content' ) . '</th><th scope="col">' . esc_html__( 'Result', 'cradle-dummy-content' ) . '</th></tr></thead><tbody>';
		foreach ( array_reverse( $record['reports'] ) as $report ) {
			$job    = ! empty( $report['job'] ) && wp_is_uuid( (string) $report['job'], 4 ) ? cradle()->jobs()->get( (string) $report['job'] ) : null;
			$user   = get_userdata( (int) ( $report['user'] ?? 0 ) );
			$counts = array();
			foreach ( (array) ( $report['counts'] ?? array() ) as $key => $value ) {
				$counts[] = str_replace( '_', ' ', (string) $key ) . ': ' . (int) $value;
			}
			echo '<tr><td>' . esc_html( (string) ( $report['type'] ?? '' ) ) . ( ! empty( $report['include_modified'] ) ? ' <small>(' . esc_html__( 'incl. edited', 'cradle-dummy-content' ) . ')</small>' : '' ) . '</td>';
			echo '<td>' . esc_html( (string) ( $report['finished'] ?? $report['updated'] ?? $report['started'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $user ? $user->display_name : '—' ) . '</td>';
			echo '<td>' . esc_html( $job ? $job['state'] : ( ! empty( $report['complete'] ) ? 'completed' : '—' ) ) . '</td>';
			echo '<td>' . esc_html( $counts ? implode( ', ', $counts ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function render_generate() {
		if ( ! current_user_can( Lifecycle::CAP_GENERATE ) ) {
			echo '<p>' . esc_html__( 'You are not allowed to generate dummy content.', 'cradle-dummy-content' ) . '</p>';
			return;
		}
		$saved  = get_transient( 'cradle_dummy_form_' . get_current_user_id() );
		$saved  = is_array( $saved ) ? $saved : array();
		$input  = (array) ( $saved['input'] ?? array() );
		$errors = (array) ( $saved['errors'] ?? array() );
		delete_transient( 'cradle_dummy_form_' . get_current_user_id() );

		$types = Request::post_types();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selection.
		$type = isset( $_GET['dc_type'] ) ? sanitize_key( wp_unslash( $_GET['dc_type'] ) ) : (string) ( $input['post_type'] ?? 'post' );
		if ( ! isset( $types[ $type ] ) ) {
			$type = isset( $types['post'] ) ? 'post' : (string) array_key_first( $types );
		}
		if ( ! $type ) {
			echo '<p>' . esc_html__( 'No supported post types are registered.', 'cradle-dummy-content' ) . '</p>';
			return;
		}
		$pto = $types[ $type ];
		$val = static function ( $key, $default = '' ) use ( $input ) {
			return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : $default;
		};
		$err = static function ( $key ) use ( $errors ) {
			return isset( $errors[ $key ] ) ? '<p class="cradle-field-error" id="cradle-dummy-err-' . esc_attr( $key ) . '">' . esc_html( $errors[ $key ] ) . '</p>' : '';
		};
		$described = static function ( $key ) use ( $errors ) {
			return isset( $errors[ $key ] ) ? ' aria-invalid="true" aria-describedby="cradle-dummy-err-' . esc_attr( $key ) . '"' : '';
		};

		if ( $errors ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Nothing was generated. Fix the highlighted fields and submit again.', 'cradle-dummy-content' ) . '</p>' . ( isset( $errors['form'] ) ? '<p>' . esc_html( $errors['form'] ) . '</p>' : '' ) . '</div>';
		}

		// Step 1: choose the post type (GET, read-only).
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="cradle-dummy-type-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" /><input type="hidden" name="tab" value="generate" />';
		echo '<label for="cradle-dummy-type">' . esc_html__( 'Post type', 'cradle-dummy-content' ) . '</label> <select id="cradle-dummy-type" name="dc_type" data-cradle-autosubmit>';
		foreach ( $types as $name => $object ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $name ), selected( $type, $name, false ), esc_html( $object->labels->name ) );
		}
		echo '</select> ';
		submit_button( __( 'Change type', 'cradle-dummy-content' ), 'secondary', '', false );
		echo '</form>';

		$max     = min( Config::ABSOLUTE_MAX_ITEMS, (int) Config::get( 'max_items_per_batch' ) );
		$authors = get_users(
			array(
				'capability' => $pto->cap->edit_posts,
				'number'     => 200,
				'orderby'    => 'display_name',
				'fields'     => array( 'ID', 'display_name' ),
			)
		);
		$selected_authors = array_map( 'intval', (array) ( $input['authors'] ?? array( get_current_user_id() ) ) );
		$can_others       = current_user_can( $pto->cap->edit_others_posts );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cradle-dummy-generate" novalidate>';
		echo '<input type="hidden" name="action" value="cradle_dummy_generate" /><input type="hidden" name="post_type" value="' . esc_attr( $type ) . '" />';
		wp_nonce_field( 'cradle_dummy_generate' );
		echo '<table class="form-table" role="presentation"><tbody>';

		// Batch.
		echo '<tr><th scope="row"><label for="cradle-dummy-name">' . esc_html__( 'Batch name', 'cradle-dummy-content' ) . '</label></th><td><input type="text" id="cradle-dummy-name" name="batch_name" class="regular-text" maxlength="80" required value="' . esc_attr( $val( 'batch_name', sprintf( /* translators: %s: date */ __( 'Sample batch %s', 'cradle-dummy-content' ), current_time( 'Y-m-d H:i' ) ) ) ) . '"' . $described( 'batch_name' ) . ' />' . $err( 'batch_name' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th scope="row"><label for="cradle-dummy-note">' . esc_html__( 'Note', 'cradle-dummy-content' ) . '</label></th><td><textarea id="cradle-dummy-note" name="note" rows="2" class="large-text" maxlength="500"' . $described( 'note' ) . '>' . esc_textarea( $val( 'note' ) ) . '</textarea>' . $err( 'note' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Count and status.
		echo '<tr><th scope="row"><label for="cradle-dummy-count">' . esc_html__( 'Number of items', 'cradle-dummy-content' ) . '</label></th><td><input type="number" id="cradle-dummy-count" name="count" min="1" max="' . esc_attr( (string) $max ) . '" value="' . esc_attr( $val( 'count', '5' ) ) . '" class="small-text"' . $described( 'count' ) . ' /> <span class="description">' . esc_html( sprintf( /* translators: %d: maximum */ __( 'Up to %d (set under Limits).', 'cradle-dummy-content' ), $max ) ) . '</span>' . $err( 'count' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th scope="row"><label for="cradle-dummy-status">' . esc_html__( 'Status', 'cradle-dummy-content' ) . '</label></th><td><select id="cradle-dummy-status" name="status"' . $described( 'status' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$statuses = array(
			'draft'   => __( 'Draft (recommended)', 'cradle-dummy-content' ),
			'pending' => __( 'Pending review', 'cradle-dummy-content' ),
			'private' => __( 'Private', 'cradle-dummy-content' ),
			'publish' => __( 'Published', 'cradle-dummy-content' ),
		);
		foreach ( $statuses as $status => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $status ), selected( $val( 'status', (string) Config::get( 'default_status' ) ), $status, false ), esc_html( $label ) );
		}
		echo '</select>' . $err( 'status' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Authors.
		echo '<tr><th scope="row"><label for="cradle-dummy-authors">' . esc_html__( 'Authors', 'cradle-dummy-content' ) . '</label></th><td><select id="cradle-dummy-authors" name="authors[]" multiple size="4"' . $described( 'authors' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( $authors as $user ) {
			if ( ! $can_others && (int) $user->ID !== get_current_user_id() ) {
				continue;
			}
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $user->ID, selected( in_array( (int) $user->ID, $selected_authors, true ), true, false ), esc_html( $user->display_name ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Existing users only; items rotate between the selected authors. No users are ever created.', 'cradle-dummy-content' ) . '</p>' . $err( 'authors' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Title pattern.
		$title_patterns = array(
			'headline' => __( 'Headlines (“The quiet garden”)', 'cradle-dummy-content' ),
			'question' => __( 'Questions (“What makes a map useful?”)', 'cradle-dummy-content' ),
			'numbered' => __( 'Numbered (“Sample post 3”)', 'cradle-dummy-content' ),
			'custom'   => __( 'Custom template', 'cradle-dummy-content' ),
		);
		echo '<tr><th scope="row">' . esc_html__( 'Titles', 'cradle-dummy-content' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Title pattern', 'cradle-dummy-content' ) . '</legend>';
		foreach ( $title_patterns as $key => $label ) {
			printf( '<label><input type="radio" name="title_pattern" value="%1$s" %2$s /> %3$s</label><br />', esc_attr( $key ), checked( $val( 'title_pattern', 'headline' ), $key, false ), esc_html( $label ) );
		}
		echo '<label for="cradle-dummy-template">' . esc_html__( 'Custom template', 'cradle-dummy-content' ) . '</label> <input type="text" id="cradle-dummy-template" name="title_template" class="regular-text" maxlength="120" value="' . esc_attr( $val( 'title_template', '{adjective} {noun} #{n}' ) ) . '"' . $described( 'title_template' ) . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="description">' . esc_html__( 'Tokens: {n} {adjective} {noun} {verb} {word} {batch} {type}', 'cradle-dummy-content' ) . '</p>' . $err( 'title_template' ) . $err( 'title_pattern' ) . '</fieldset></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Body.
		echo '<tr><th scope="row">' . esc_html__( 'Body', 'cradle-dummy-content' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Body pattern', 'cradle-dummy-content' ) . '</legend>';
		$bodies = array(
			'article'    => __( 'Article: paragraphs with headings and a list', 'cradle-dummy-content' ),
			'paragraphs' => __( 'Paragraphs only', 'cradle-dummy-content' ),
			'short'      => __( 'One short paragraph', 'cradle-dummy-content' ),
		);
		foreach ( $bodies as $key => $label ) {
			printf( '<label><input type="radio" name="body_pattern" value="%1$s" %2$s /> %3$s</label><br />', esc_attr( $key ), checked( $val( 'body_pattern', 'article' ), $key, false ), esc_html( $label ) );
		}
		echo '<label for="cradle-dummy-vocab">' . esc_html__( 'Vocabulary', 'cradle-dummy-content' ) . '</label> <select id="cradle-dummy-vocab" name="vocabulary">';
		foreach ( Vocabulary::labels() as $key => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $key ), selected( $val( 'vocabulary', 'english' ), $key, false ), esc_html( $label ) );
		}
		echo '</select><br /><label for="cradle-dummy-pmin">' . esc_html__( 'Paragraphs from', 'cradle-dummy-content' ) . '</label> <input type="number" id="cradle-dummy-pmin" name="paragraphs_min" min="1" max="20" class="small-text" value="' . esc_attr( $val( 'paragraphs_min', '2' ) ) . '" /> <label for="cradle-dummy-pmax">' . esc_html__( 'to', 'cradle-dummy-content' ) . '</label> <input type="number" id="cradle-dummy-pmax" name="paragraphs_max" min="1" max="20" class="small-text" value="' . esc_attr( $val( 'paragraphs_max', '4' ) ) . '" />';
		echo '<br /><label><input type="checkbox" name="excerpt" value="1" ' . checked( $val( 'excerpt', '0' ), '1', false ) . ' /> ' . esc_html__( 'Add an excerpt', 'cradle-dummy-content' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Bodies are valid block markup (paragraph, heading and list blocks) built from bundled local word lists.', 'cradle-dummy-content' ) . '</p>' . $err( 'body_pattern' ) . $err( 'paragraphs' ) . $err( 'vocabulary' ) . '</fieldset></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Dates.
		echo '<tr><th scope="row">' . esc_html__( 'Date range', 'cradle-dummy-content' ) . '</th><td><label for="cradle-dummy-from">' . esc_html__( 'From', 'cradle-dummy-content' ) . '</label> <input type="date" id="cradle-dummy-from" name="date_from" value="' . esc_attr( $val( 'date_from' ) ) . '" max="' . esc_attr( current_time( 'Y-m-d' ) ) . '" /> <label for="cradle-dummy-to">' . esc_html__( 'to', 'cradle-dummy-content' ) . '</label> <input type="date" id="cradle-dummy-to" name="date_to" value="' . esc_attr( $val( 'date_to' ) ) . '" max="' . esc_attr( current_time( 'Y-m-d' ) ) . '" /><p class="description">' . esc_html__( 'Leave empty to use the current time.', 'cradle-dummy-content' ) . '</p>' . $err( 'dates' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Terms.
		$taxonomies = Request::taxonomies( $type );
		if ( $taxonomies ) {
			echo '<tr><th scope="row">' . esc_html__( 'Terms', 'cradle-dummy-content' ) . '</th><td>';
			foreach ( $taxonomies as $name => $taxonomy ) {
				$tin      = (array) ( $input['tax'][ $name ] ?? array() );
				$selected = array_map( 'intval', (array) ( $tin['existing'] ?? array() ) );
				$terms    = get_terms(
					array(
						'taxonomy'   => $name,
						'hide_empty' => false,
						'number'     => 100,
						'orderby'    => 'count',
						'order'      => 'DESC',
					)
				);
				echo '<fieldset class="cradle-dummy-tax"><legend><strong>' . esc_html( $taxonomy->labels->name ) . '</strong></legend>';
				if ( is_array( $terms ) && $terms ) {
					echo '<label for="cradle-dummy-tax-' . esc_attr( $name ) . '">' . esc_html__( 'Existing terms to reuse', 'cradle-dummy-content' ) . '</label><br /><select id="cradle-dummy-tax-' . esc_attr( $name ) . '" name="tax[' . esc_attr( $name ) . '][existing][]" multiple size="4">';
					foreach ( $terms as $term ) {
						printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $term->term_id, selected( in_array( (int) $term->term_id, $selected, true ), true, false ), esc_html( $term->name ) );
					}
					echo '</select><br />';
				}
				echo '<label>' . esc_html__( 'New terms to generate', 'cradle-dummy-content' ) . ' <input type="number" name="tax[' . esc_attr( $name ) . '][generate]" min="0" max="' . esc_attr( (string) Config::get( 'max_generated_terms' ) ) . '" class="small-text" value="' . esc_attr( (string) ( $tin['generate'] ?? '0' ) ) . '" /></label> ';
				echo '<label>' . esc_html__( 'Terms per item', 'cradle-dummy-content' ) . ' <input type="number" name="tax[' . esc_attr( $name ) . '][per_item]" min="0" max="5" class="small-text" value="' . esc_attr( (string) ( $tin['per_item'] ?? '1' ) ) . '" /></label>';
				echo $err( 'tax_' . $name ) . '</fieldset>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '<p class="description">' . esc_html__( 'Generated terms are tracked and removed on purge unless real content uses them. Reused terms are never deleted.', 'cradle-dummy-content' ) . '</p>' . $err( 'tax' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		// Metadata.
		$registered = array();
		foreach ( array_merge( get_registered_meta_keys( 'post', '' ), get_registered_meta_keys( 'post', $type ) ) as $key => $args ) {
			if ( true === Request::validate_meta_key( (string) $key ) && in_array( $args['type'] ?? '', array( 'string', 'integer', 'number', 'boolean' ), true ) ) {
				$registered[] = (string) $key;
			}
		}
		echo '<tr><th scope="row">' . esc_html__( 'Custom fields', 'cradle-dummy-content' ) . '</th><td><datalist id="cradle-dummy-meta-keys">';
		foreach ( $registered as $key ) {
			echo '<option value="' . esc_attr( $key ) . '"></option>';
		}
		echo '</datalist>';
		$types_meta = array(
			'word'     => __( 'Word', 'cradle-dummy-content' ),
			'sentence' => __( 'Sentence', 'cradle-dummy-content' ),
			'number'   => __( 'Number', 'cradle-dummy-content' ),
			'date'     => __( 'Date', 'cradle-dummy-content' ),
			'boolean'  => __( 'Yes/no (1/0)', 'cradle-dummy-content' ),
		);
		for ( $i = 0; $i < 3; $i++ ) {
			$row = (array) ( $input['meta'][ $i ] ?? array() );
			/* translators: %d: row number */
			echo '<label class="screen-reader-text" for="cradle-dummy-meta-' . (int) $i . '">' . esc_html( sprintf( __( 'Field key %d', 'cradle-dummy-content' ), $i + 1 ) ) . '</label><input type="text" id="cradle-dummy-meta-' . (int) $i . '" name="meta[' . (int) $i . '][key]" list="cradle-dummy-meta-keys" placeholder="' . esc_attr__( 'field_key', 'cradle-dummy-content' ) . '" value="' . esc_attr( (string) ( $row['key'] ?? '' ) ) . '" /> ';
			/* translators: %d: row number */
			echo '<label class="screen-reader-text" for="cradle-dummy-meta-type-' . (int) $i . '">' . esc_html( sprintf( __( 'Value type %d', 'cradle-dummy-content' ), $i + 1 ) ) . '</label><select id="cradle-dummy-meta-type-' . (int) $i . '" name="meta[' . (int) $i . '][type]">';
			foreach ( $types_meta as $key => $label ) {
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $key ), selected( (string) ( $row['type'] ?? 'word' ), $key, false ), esc_html( $label ) );
			}
			echo '</select><br />';
		}
		echo '<p class="description">' . esc_html__( 'Public field keys only (letters, digits, - and _). Protected keys starting with _ cannot be written.', 'cradle-dummy-content' ) . '</p>' . $err( 'meta' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Featured images.
		if ( post_type_supports( $type, 'thumbnail' ) ) {
			$mode = $val( 'image_mode', 'none' );
			echo '<tr><th scope="row">' . esc_html__( 'Featured images', 'cradle-dummy-content' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Featured image source', 'cradle-dummy-content' ) . '</legend>';
			echo '<label><input type="radio" name="image_mode" value="none" ' . checked( $mode, 'none', false ) . ' /> ' . esc_html__( 'None', 'cradle-dummy-content' ) . '</label><br />';
			echo '<label><input type="radio" name="image_mode" value="library" ' . checked( $mode, 'library', false ) . ' /> ' . esc_html__( 'Reuse images already in the Media Library (never deleted by cleanup)', 'cradle-dummy-content' ) . '</label><br />';
			$gd = Request::gd_available() && current_user_can( 'upload_files' );
			echo '<label><input type="radio" name="image_mode" value="placeholder" ' . checked( $mode, 'placeholder', false ) . disabled( ! $gd, true, false ) . ' /> ' . esc_html__( 'Draw local placeholder images (owned by the batch)', 'cradle-dummy-content' ) . '</label> ';
			echo '<label>' . esc_html__( 'How many', 'cradle-dummy-content' ) . ' <input type="number" name="image_count" min="1" max="' . esc_attr( (string) Config::get( 'max_placeholder_images' ) ) . '" class="small-text" value="' . esc_attr( $val( 'image_count', '3' ) ) . '" /></label>';
			$library  = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_mime_type' => 'image',
					'post_status'    => 'inherit',
					'numberposts'    => 60,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
			$selected = array_map( 'intval', (array) ( $input['image_library'] ?? array() ) );
			if ( $library ) {
				echo '<div class="cradle-dummy-library" role="group" aria-label="' . esc_attr__( 'Media Library images', 'cradle-dummy-content' ) . '">';
				foreach ( $library as $attachment ) {
					if ( ! current_user_can( 'read_post', $attachment->ID ) ) {
						continue;
					}
					printf(
						'<label class="cradle-dummy-thumb"><input type="checkbox" name="image_library[]" value="%1$d" %2$s /> %3$s<span>%4$s</span></label>',
						(int) $attachment->ID,
						checked( in_array( (int) $attachment->ID, $selected, true ), true, false ),
						wp_get_attachment_image( $attachment->ID, array( 80, 80 ), false, array( 'alt' => '' ) ),
						esc_html( get_the_title( $attachment ) )
					);
				}
				echo '</div>';
			} else {
				echo '<p class="description">' . esc_html__( 'The Media Library has no images yet.', 'cradle-dummy-content' ) . '</p>';
			}
			echo $err( 'images' ) . '</fieldset></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		// Relationships.
		echo '<tr><th scope="row">' . esc_html__( 'Relationships', 'cradle-dummy-content' ) . '</th><td>';
		if ( is_post_type_hierarchical( $type ) ) {
			echo '<label><input type="checkbox" name="rel_parent" value="1" ' . checked( $val( 'rel_parent', '0' ), '1', false ) . ' /> ' . esc_html__( 'Nest some items under other items of this batch', 'cradle-dummy-content' ) . '</label><br />';
		}
		echo '<label for="cradle-dummy-related">' . esc_html__( 'Store related item IDs in field', 'cradle-dummy-content' ) . '</label> <input type="text" id="cradle-dummy-related" name="rel_meta_key" placeholder="related_items" value="' . esc_attr( $val( 'rel_meta_key' ) ) . '" /><p class="description">' . esc_html__( 'Optional. Each item gets up to three IDs of earlier items from the same batch.', 'cradle-dummy-content' ) . '</p>' . $err( 'relationships' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Seed.
		echo '<tr><th scope="row"><label for="cradle-dummy-seed">' . esc_html__( 'Seed', 'cradle-dummy-content' ) . '</label></th><td><input type="text" id="cradle-dummy-seed" name="seed" maxlength="64" pattern="[A-Za-z0-9_\-]{1,64}" value="' . esc_attr( $val( 'seed' ) ) . '"' . $described( 'seed' ) . ' /><p class="description">' . esc_html__( 'The same seed and settings reproduce the same titles and text. Leave empty for a random seed (recorded with the batch).', 'cradle-dummy-content' ) . '</p>' . $err( 'seed' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Production confirmation.
		if ( Request::is_production() ) {
			echo '<tr class="cradle-dummy-production"><th scope="row"><label for="cradle-dummy-confirm">' . esc_html__( 'Production confirmation', 'cradle-dummy-content' ) . '</label></th><td><p><strong>' . esc_html( sprintf( /* translators: %s: host */ __( 'You are about to add sample content to the production site %s.', 'cradle-dummy-content' ), Request::production_phrase() ) ) . '</strong></p><p>' . esc_html__( 'Type the site host to confirm:', 'cradle-dummy-content' ) . '</p><input type="text" id="cradle-dummy-confirm" name="confirm_production" autocomplete="off"' . $described( 'confirm_production' ) . ' />' . $err( 'confirm_production' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</tbody></table>';
		/* translators: %s: post type name */
		echo '<p>' . esc_html( sprintf( __( 'Items are created as “%s” in bounded job steps and can be removed later from this batch.', 'cradle-dummy-content' ), $pto->labels->name ) ) . '</p>';
		submit_button( __( 'Generate batch', 'cradle-dummy-content' ) );
		echo '</form>';
	}

	// ---------------------------------------------------------------------
	// Handlers (POST only).
	// ---------------------------------------------------------------------

	private static function require_post() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'This action requires a form submission.', 'cradle-dummy-content' ), 405 );
		}
	}

	private static function posted_batch(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by the caller with the batch-specific action.
		$uuid = isset( $_POST['batch'] ) ? sanitize_text_field( wp_unslash( $_POST['batch'] ) ) : '';
		return wp_is_uuid( $uuid, 4 ) ? $uuid : '';
	}

	private static function fail( string $message, string $redirect ) {
		set_transient( 'cradle_dummy_error_' . get_current_user_id(), $message, 120 );
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_generate() {
		self::require_post();
		check_admin_referer( 'cradle_dummy_generate' );
		if ( ! current_user_can( Lifecycle::CAP_GENERATE ) ) {
			wp_die( esc_html__( 'You are not allowed to generate dummy content.', 'cradle-dummy-content' ), 403 );
		}
		$fields = array( 'batch_name', 'note', 'post_type', 'count', 'status', 'authors', 'title_pattern', 'title_template', 'body_pattern', 'vocabulary', 'paragraphs_min', 'paragraphs_max', 'excerpt', 'date_from', 'date_to', 'tax', 'meta', 'image_mode', 'image_library', 'image_count', 'rel_parent', 'rel_meta_key', 'seed', 'confirm_production' );
		$raw    = array();
		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$raw[ $field ] = wp_unslash( $_POST[ $field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated field by field in Request::validate().
			}
		}
		$result = Generator::start( $raw );
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			unset( $raw['confirm_production'] );
			set_transient(
				'cradle_dummy_form_' . get_current_user_id(),
				array(
					'input'  => $raw,
					'errors' => (array) ( $data['errors'] ?? array( 'form' => $result->get_error_message() ) ),
				),
				300
			);
			wp_safe_redirect(
				self::url(
					array(
						'tab'       => 'generate',
						'dc_type'   => isset( $raw['post_type'] ) && is_string( $raw['post_type'] ) ? sanitize_key( $raw['post_type'] ) : 'post',
					)
				)
			);
			exit;
		}
		wp_safe_redirect(
			self::url(
				array(
					'batch'         => $result['batch']['uuid'],
					'cradle_notice' => 'generation_started',
				)
			)
		);
		exit;
	}

	public function handle_cleanup() {
		self::require_post();
		$uuid = self::posted_batch();
		check_admin_referer( 'cradle_dummy_cleanup_' . $uuid );
		if ( ! current_user_can( Lifecycle::CAP_CLEANUP ) ) {
			wp_die( esc_html__( 'You are not allowed to clean up dummy content.', 'cradle-dummy-content' ), 403 );
		}
		$back = self::url( array( 'batch' => $uuid ) );
		if ( '' === $uuid ) {
			self::fail( __( 'Unknown batch.', 'cradle-dummy-content' ), self::url() );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$mode    = isset( $_POST['mode'] ) && 'purge' === $_POST['mode'] ? 'purge' : 'trash';
		$include = isset( $_POST['include_modified'] ) && '1' === $_POST['include_modified'];
		if ( 'purge' === $mode && ( ! isset( $_POST['confirm_purge'] ) || '1' !== $_POST['confirm_purge'] ) ) {
			self::fail( __( 'Confirm that you want to permanently delete content.', 'cradle-dummy-content' ), $back );
		}
		// phpcs:enable
		$job = Cleanup::start( $uuid, $mode, $include );
		if ( is_wp_error( $job ) ) {
			self::fail( $job->get_error_message(), $back );
		}
		wp_safe_redirect( add_query_arg( 'cradle_notice', 'cleanup_started', $back ) );
		exit;
	}

	/**
	 * No-JavaScript job controls: run one runner call, cancel, or resume generation.
	 */
	public function handle_job() {
		self::require_post();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below with the op + job specific action.
		$job_id = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '';
		$op     = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		// phpcs:enable
		$uuid = self::posted_batch();
		if ( ! in_array( $op, array( 'run', 'cancel', 'resume' ), true ) || ! wp_is_uuid( $job_id, 4 ) ) {
			wp_die( esc_html__( 'Invalid request.', 'cradle-dummy-content' ), 400 );
		}
		check_admin_referer( 'cradle_dummy_job_' . $op . '_' . $job_id );
		$job = cradle()->jobs()->get( $job_id );
		if ( ! $job || 'dummy-content' !== $job['module'] || ! cradle()->jobs()->user_can_access( $job ) || ( $job['payload']['batch'] ?? '' ) !== $uuid ) {
			wp_die( esc_html__( 'You are not allowed to control this job.', 'cradle-dummy-content' ), 403 );
		}
		$back = self::url( array( 'batch' => $uuid ) );
		if ( 'run' === $op ) {
			cradle()->jobs()->run( $job_id );
			$after = cradle()->jobs()->get( $job_id );
			wp_safe_redirect( add_query_arg( 'cradle_notice', $after && in_array( $after['state'], \Cradle\Core\Jobs::TERMINAL_STATES, true ) ? 'job_finished' : 'job_stepped', $back ) );
			exit;
		}
		if ( 'cancel' === $op ) {
			cradle()->jobs()->cancel( $job_id );
			cradle_log_activity( 'dummy-content', 'job_cancelled', sprintf( 'Job %s cancelled.', $job['type'] ), array( 'job' => $job_id ) );
			wp_safe_redirect( add_query_arg( 'cradle_notice', 'job_cancelled', $back ) );
			exit;
		}
		// Resume generation (a new idempotent job for the same batch).
		$batch = Batches::get( $uuid );
		if ( ! $batch || Generator::JOB_TYPE !== $job['type'] ) {
			wp_die( esc_html__( 'Only generation can be resumed this way.', 'cradle-dummy-content' ), 400 );
		}
		$new = Generator::resume( $batch );
		if ( is_wp_error( $new ) ) {
			self::fail( $new->get_error_message(), $back );
		}
		wp_safe_redirect( add_query_arg( 'cradle_notice', 'generation_started', $back ) );
		exit;
	}

	public function handle_forget() {
		self::require_post();
		$uuid = self::posted_batch();
		check_admin_referer( 'cradle_dummy_forget_' . $uuid );
		if ( ! current_user_can( Lifecycle::CAP_CLEANUP ) ) {
			wp_die( esc_html__( 'You are not allowed to remove batch records.', 'cradle-dummy-content' ), 403 );
		}
		$batch = Batches::get( $uuid );
		if ( ! $batch ) {
			self::fail( __( 'Unknown batch.', 'cradle-dummy-content' ), self::url() );
		}
		$preview = Cleanup::preview( $uuid );
		if ( Batches::active_job( $uuid ) || $preview['posts']['total'] + $preview['terms']['total'] + $preview['attachments']['total'] > 0 ) {
			self::fail( __( 'This batch still owns content or has a running job. Clean it up first.', 'cradle-dummy-content' ), self::url( array( 'batch' => $uuid ) ) );
		}
		Batches::forget( $batch );
		cradle_log_activity( 'dummy-content', 'batch_forgotten', sprintf( 'Batch record "%s" removed.', $batch['name'] ), array( 'batch' => $uuid ) );
		wp_safe_redirect( self::url( array( 'cradle_notice' => 'forgotten' ) ) );
		exit;
	}
}
