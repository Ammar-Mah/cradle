<?php
/**
 * Cradle → Migration admin screen: one-click export to a `.cradle` file, archive list
 * with protected downloads, one-step import of a `.cradle` file (upload, checks,
 * staging, switch-over, then the login page), and recovery actions.
 *
 * Every mutation is a POST to admin-post.php with a nonce and capability check (plus
 * job-level access). The page works without JavaScript except for the chunked upload
 * and the browser-driven import, which have CLI equivalents shown on the page. Imports
 * created by the command line keep the review and switch-over confirmations; a one-step
 * import opened in a browser tab that did not start it waits for a switch-over click.
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration\Admin;

use Cradle\Core\Jobs;
use Cradle\Migration\Archives;
use Cradle\Migration\Exporter;
use Cradle\Migration\Guard;
use Cradle\Migration\Importer;
use Cradle\Migration\Job_Store;
use Cradle\Migration\Journal;
use Cradle\Migration\Manifest;
use Cradle\Migration\Recovery;
use Cradle\Migration\Upload;
use Cradle\Migration\Workspace;

defined( 'ABSPATH' ) || exit;

final class Page {

	const SLUG  = 'cradle-migration';
	const NONCE = 'cradle_migration_admin';

	/** @var string */
	private $hook = '';

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		foreach ( array( 'export', 'import_local', 'approve', 'delete_archive', 'release_hold', 'rollback', 'purge', 'discard' ) as $action ) {
			add_action( 'admin_post_cradle_migration_' . $action, array( $this, 'post_' . $action ) );
		}
		add_action( 'admin_post_cradle_migration_download', array( Archives::class, 'handle_download' ) );
	}

	public function menu() {
		$cap = current_user_can( 'cradle_export_site' ) ? 'cradle_export_site' : 'cradle_import_site';
		$this->hook = (string) add_submenu_page( 'cradle', __( 'Cradle Migration', 'cradle-migration' ), __( 'Migration', 'cradle-migration' ), $cap, self::SLUG, array( $this, 'render' ) );
	}

	public function assets( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}
		wp_enqueue_style( 'cradle-admin' );
		wp_enqueue_script( 'cradle-admin' );
		\Cradle\Core\Assets::register_style( 'cradle-migration-admin', CRADLE_MIGRATION_FILE, 'assets/css/migration.css', array( 'cradle-admin' ), CRADLE_MIGRATION_VERSION );
		\Cradle\Core\Assets::register_script( 'cradle-migration-admin', CRADLE_MIGRATION_FILE, 'assets/js/migration.js', array( 'cradle-admin', 'wp-i18n' ), CRADLE_MIGRATION_VERSION );
		wp_set_script_translations( 'cradle-migration-admin', 'cradle-migration', CRADLE_MIGRATION_DIR . 'languages' );
		wp_localize_script(
			'cradle-migration-admin',
			'cradleMigration',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'cradle_migration' ),
				'jobNonce'  => wp_create_nonce( 'cradle_jobs' ),
				'pageUrl'   => admin_url( 'admin.php?page=' . self::SLUG ),
				'chunkSize' => Upload::chunk_size(),
			)
		);
		wp_enqueue_style( 'cradle-migration-admin' );
		wp_enqueue_script( 'cradle-migration-admin' );
	}

	/* ------------------------------ helpers ------------------------------- */

	private function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	private function notice( string $message, bool $error = false ) {
		set_transient( 'cradle_migration_notice_' . get_current_user_id(), array( 'message' => $message, 'error' => $error ), 300 );
	}

	private function redirect( array $args ) {
		wp_safe_redirect( $this->url( $args ) );
		exit;
	}

	private function check( string $cap ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'cradle-migration' ), 403 );
		}
		check_admin_referer( self::NONCE );
	}

	private function posted_job( string $type = Importer::TYPE ) {
		$uuid = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in check().
		$job  = \Cradle\Migration\Ajax::job_for_user( $uuid, $type );
		if ( ! $job ) {
			wp_die( esc_html__( 'Job not found.', 'cradle-migration' ), 404 );
		}
		return $job;
	}

	private function form_open( string $action, string $confirm = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( '' !== $confirm ? ' data-cradle-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( 'cradle_migration_' . $action ) . '">';
		wp_nonce_field( self::NONCE );
	}

	/* ---------------------------- POST handlers --------------------------- */

	public function post_export() {
		$this->check( 'cradle_export_site' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$roots = isset( $_POST['root_files'] ) ? sanitize_text_field( wp_unslash( $_POST['root_files'] ) ) : '';
		$job   = Exporter::create(
			array(
				'database'  => ! empty( $_POST['database'] ),
				'uploads'   => ! empty( $_POST['uploads'] ),
				'languages' => ! empty( $_POST['languages'] ),
				'themes'    => isset( $_POST['themes'] ) ? sanitize_key( wp_unslash( $_POST['themes'] ) ) : 'active',
				'plugins'   => isset( $_POST['plugins'] ) ? sanitize_key( wp_unslash( $_POST['plugins'] ) ) : 'active',
				'quiesce'   => ! empty( $_POST['quiesce'] ),
				'partSize'  => isset( $_POST['part_size'] ) ? absint( $_POST['part_size'] ) * 1048576 : 67108864,
				'rootFiles' => array_filter( array_map( 'trim', explode( ',', $roots ) ) ),
				'label'     => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
			)
		);
		// phpcs:enable
		if ( is_wp_error( $job ) ) {
			$this->notice( $job->get_error_message(), true );
			$this->redirect( array( 'tab' => 'export' ) );
		}
		$this->redirect( array( 'tab' => 'export', 'job' => $job['uuid'] ) );
	}

	/**
	 * Archives → Restore without JavaScript. (With JavaScript the button uses the AJAX endpoint and
	 * the page switches the site over by itself; here the switch still needs its own click.)
	 */
	public function post_import_local() {
		$this->check( 'cradle_import_site' );
		$id  = isset( $_POST['archive'] ) ? sanitize_text_field( wp_unslash( $_POST['archive'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$job = Importer::create_from_archive( $id );
		if ( is_wp_error( $job ) ) {
			$this->notice( $job->get_error_message(), true );
			$this->redirect( array( 'tab' => 'import' ) );
		}
		$this->redirect( array( 'tab' => 'import', 'job' => $job['uuid'] ) );
	}

	public function post_approve() {
		$this->check( 'cradle_import_site' );
		$job = $this->posted_job();
		$ok  = Importer::approve( $job );
		if ( is_wp_error( $ok ) ) {
			$this->notice( $ok->get_error_message(), true );
		}
		$this->redirect( array( 'tab' => 'import', 'job' => $job['uuid'] ) );
	}

	public function post_delete_archive() {
		$this->check( 'cradle_export_site' );
		$id = isset( $_POST['archive'] ) ? sanitize_text_field( wp_unslash( $_POST['archive'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ok = Archives::delete( $id );
		$this->notice( is_wp_error( $ok ) ? $ok->get_error_message() : __( 'Archive deleted.', 'cradle-migration' ), is_wp_error( $ok ) );
		$this->redirect( array( 'tab' => 'archives' ) );
	}

	public function post_release_hold() {
		$this->check( 'cradle_import_site' );
		$released = Guard::release_hold();
		if ( $released ) {
			cradle_log_activity( 'migration', 'safety_hold_released', 'Post-restore safety hold released.' );
		}
		$this->notice( $released ? __( 'Safety hold released: email, scheduled tasks and outbound HTTP run normally again.', 'cradle-migration' ) : __( 'No safety hold was active.', 'cradle-migration' ) );
		$this->redirect( array( 'tab' => 'jobs' ) );
	}

	public function post_rollback() {
		$this->check( 'cradle_import_site' );
		$job = $this->posted_job();
		$dir = Workspace::job_dir( $job['uuid'] );
		$out = is_wp_error( $dir ) ? $dir : Recovery::rollback( $job['uuid'], $dir );
		$this->notice( is_wp_error( $out ) ? $out->get_error_message() : (string) $out['note'], is_wp_error( $out ) );
		$this->redirect( array( 'tab' => 'jobs' ) );
	}

	public function post_purge() {
		$this->check( 'cradle_import_site' );
		$job = $this->posted_job();
		$out = Importer::purge_backups( $job['uuid'] );
		/* translators: %d: number of tables */
		$this->notice( is_wp_error( $out ) ? $out->get_error_message() : sprintf( __( 'Backups purged (%d tables dropped, backup files deleted).', 'cradle-migration' ), $out['tablesDropped'] ), is_wp_error( $out ) );
		$this->redirect( array( 'tab' => 'jobs' ) );
	}

	public function post_discard() {
		$this->check( 'cradle_import_site' );
		$job = $this->posted_job();
		$dir = Workspace::job_dir( $job['uuid'] );
		if ( is_wp_error( $dir ) || 'not_started' !== ( new Journal( $dir ) )->stage() ) {
			$this->notice( __( 'This import already changed the site; use rollback instead.', 'cradle-migration' ), true );
			$this->redirect( array( 'tab' => 'jobs' ) );
		}
		if ( ! in_array( $job['state'], Jobs::TERMINAL_STATES, true ) ) {
			cradle()->jobs()->cancel( $job['uuid'] );
		}
		Importer::discard_staging( $job['uuid'] );
		$this->notice( __( 'Import discarded. The live site was not changed.', 'cradle-migration' ) );
		$this->redirect( array( 'tab' => 'jobs' ) );
	}

	/* ------------------------------- render ------------------------------- */

	public function render() {
		if ( ! current_user_can( 'cradle_export_site' ) && ! current_user_can( 'cradle_import_site' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'cradle-migration' ), 403 );
		}
		Importer::sweep();
		Upload::sweep();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ( current_user_can( 'cradle_export_site' ) ? 'export' : 'import' );
		$job = isset( $_GET['job'] ) ? \Cradle\Migration\Ajax::job_for_user( sanitize_text_field( wp_unslash( $_GET['job'] ) ) ) : null;
		// phpcs:enable
		$tabs = array();
		if ( current_user_can( 'cradle_export_site' ) ) {
			$tabs['export']   = __( 'Export', 'cradle-migration' );
			$tabs['archives'] = __( 'Archives', 'cradle-migration' );
		}
		if ( current_user_can( 'cradle_import_site' ) ) {
			$tabs['import'] = __( 'Import', 'cradle-migration' );
		}
		$tabs['jobs'] = __( 'Jobs & recovery', 'cradle-migration' );
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = (string) array_key_first( $tabs );
		}
		echo '<div class="wrap cradle-wrap cradle-migration">';
		echo '<h1>' . esc_html__( 'Cradle Migration', 'cradle-migration' ) . '</h1>';
		$notice = get_transient( 'cradle_migration_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'cradle_migration_notice_' . get_current_user_id() );
			echo '<div class="notice ' . ( $notice['error'] ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
		$storage = cradle()->storage()->verify_http_denial();
		if ( 'protected' !== $storage['status'] ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Private storage is not proven protected.', 'cradle-migration' ) . '</strong> ' . esc_html( $storage['detail'] ) . ' ' . esc_html__( 'Export and import stay disabled until archives can be stored safely.', 'cradle-migration' ) . '</p></div>';
		}
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Migration sections', 'cradle-migration' ) . '">';
		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $this->url( array( 'tab' => $key ) ) ) . '"' . ( $key === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		switch ( $tab ) {
			case 'export':
				$this->render_export( $job );
				break;
			case 'archives':
				$this->render_archives();
				break;
			case 'import':
				$this->render_import( $job );
				break;
			default:
				$this->render_jobs();
		}
		echo '</div>';
	}

	private function job_box( array $job ) {
		$view = Jobs::public_view( $job );
		echo '<div class="cradle-migration-runner" data-cradle-job="' . esc_attr( $job['uuid'] ) . '" data-type="' . esc_attr( $job['type'] ) . '">';
		echo '<progress class="cradle-progress" max="' . esc_attr( (string) max( 1, $view['progressTotal'] ) ) . '" value="' . esc_attr( (string) $view['progressDone'] ) . '"></progress>';
		echo '<p aria-live="polite">' . esc_html( $view['state'] . ( $view['phase'] ? ' — ' . $view['phase'] : '' ) ) . '</p></div>';
	}

	private function render_export( $job ) {
		echo '<h2>' . esc_html__( 'Export this site', 'cradle-migration' ) . '</h2>';
		if ( $job && Exporter::TYPE === $job['type'] ) {
			if ( ! in_array( $job['state'], Jobs::TERMINAL_STATES, true ) ) {
				echo '<p>' . esc_html__( 'Exporting… Keep this page open. The .cradle file downloads automatically when it is ready.', 'cradle-migration' ) . '</p>';
				$this->job_box( $job );
				return;
			}
			if ( 'completed' === $job['state'] ) {
				$this->render_export_done( $job );
			} else {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $job['state'] . ': ' . (string) $job['error_message'] ) . '</p></div>';
			}
		}
		echo '<p>' . esc_html__( 'Creates one .cradle file with this site\'s database, uploads, themes and plugins. To move the site, import that file on the other site under Cradle → Migration → Import.', 'cradle-migration' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'The file contains password hashes, customer data and any secrets stored in the database. Keep it as private as the database itself.', 'cradle-migration' ) . '</p>';
		$this->form_open( 'export' );
		submit_button( __( 'Export site', 'cradle-migration' ), 'primary', 'submit', true );
		echo '<details class="cradle-migration-options"><summary>' . esc_html__( 'Options', 'cradle-migration' ) . '</summary>';
		echo '<fieldset class="cradle-migration-fields"><legend class="screen-reader-text">' . esc_html__( 'Export contents', 'cradle-migration' ) . '</legend>';
		echo '<p><label><input type="checkbox" name="database" value="1" checked> ' . esc_html__( 'Database (all tables with this site\'s prefix)', 'cradle-migration' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="uploads" value="1" checked> ' . esc_html__( 'Uploads and fonts', 'cradle-migration' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="languages" value="1" checked> ' . esc_html__( 'Language files (WordPress, theme and plugin translations; they keep the site language and right-to-left layout)', 'cradle-migration' ) . '</label></p>';
		foreach ( array( 'themes' => __( 'Themes', 'cradle-migration' ), 'plugins' => __( 'Plugins', 'cradle-migration' ) ) as $key => $label ) {
			echo '<p><label for="cm-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label> <select id="cm-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
			echo '<option value="active">' . esc_html__( 'Active only', 'cradle-migration' ) . '</option><option value="all">' . esc_html__( 'All installed', 'cradle-migration' ) . '</option><option value="none">' . esc_html__( 'None', 'cradle-migration' ) . '</option></select></p>';
		}
		echo '<p><label for="cm-root">' . esc_html__( 'Additional root files (comma-separated, e.g. robots.txt; only .txt/.xml/.json/.ico/.webmanifest/.html)', 'cradle-migration' ) . '</label><br><input type="text" class="regular-text" id="cm-root" name="root_files" value=""></p>';
		echo '<p><label><input type="checkbox" name="quiesce" value="1"> ' . esc_html__( 'Consistent copy: make the site read-only while exporting (write-quiescence window)', 'cradle-migration' ) . '</label><br><span class="description">' . esc_html__( 'Without it, tables are read in batches while the site stays writable, so orders or comments made during the export may be partly included. The window refuses WordPress writes (forms, comments, REST/AJAX writes, WP-Cron); it cannot stop direct database writers outside WordPress.', 'cradle-migration' ) . '</span></p>';
		echo '</fieldset></details>';
		echo '</form>';
		echo '<details><summary>' . esc_html__( 'What is never migrated', 'cradle-migration' ) . '</summary><ul class="ul-disc">';
		foreach ( Manifest::NOT_MIGRATED as $item ) {
			echo '<li>' . esc_html( $item ) . '</li>';
		}
		echo '</ul><p>' . esc_html__( 'Excluded by default: wp-config.php and secrets, caches, logs, sessions/transients, prior archives and Cradle private storage, .git/node_modules, server-control files and executable files in uploads, symbolic links and anything outside the WordPress roots. Views, triggers, routines and unsupported table types are reported, never silently dropped.', 'cradle-migration' ) . '</p></details>';
	}

	/**
	 * Finished export: the download button (clicked automatically right after the export ran).
	 */
	private function render_export_done( array $job ) {
		$id      = (string) ( $job['payload']['archive']['id'] ?? '' );
		$dir     = preg_match( Manifest::ID_PATTERN, $id ) ? Workspace::subdir( 'archives', $id, false ) : null;
		$summary = is_string( $dir ) && is_dir( $dir ) ? Archives::summary( $dir, $id ) : null;
		if ( ! is_array( $summary ) || '' === $summary['file'] || ! Archives::user_can( $id ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( /* translators: %s: archive ID */ __( 'Export %s is complete.', 'cradle-migration' ), $id ) ) . ' <a href="' . esc_url( $this->url( array( 'tab' => 'archives' ) ) ) . '">' . esc_html__( 'Download it from Archives.', 'cradle-migration' ) . '</a></p></div>';
			return;
		}
		$auto = isset( $_GET['download'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view flag; the link itself is signed.
		echo '<div class="notice notice-success inline cradle-migration-ready"><p><strong>' . esc_html__( 'Your export is ready.', 'cradle-migration' ) . '</strong> ' . esc_html( $auto ? __( 'The download starts automatically; if it does not, use the button.', 'cradle-migration' ) : __( 'Download the file and import it on the other site.', 'cradle-migration' ) ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( Archives::download_url( $id, $summary['file'] ) ) . '"' . ( $auto ? ' data-cradle-autodownload="1"' : '' ) . '>' . esc_html( sprintf( /* translators: %s: file name */ __( 'Download %s', 'cradle-migration' ), Archives::download_name( $summary ) ) ) . '</a> <span class="description">' . esc_html( size_format( $summary['fileBytes'] ) ) . '</span></p></div>';
	}

	private function render_archives() {
		echo '<h2>' . esc_html__( 'Archives on this site', 'cradle-migration' ) . '</h2>';
		echo '<p>' . esc_html__( 'Download links are personal and expire after 10 minutes. Archives are stored in protected private storage and are never publicly reachable.', 'cradle-migration' ) . '</p>';
		$archives = array_filter( Archives::all(), static fn( $a ) => Archives::user_can( $a['id'] ) );
		if ( ! $archives ) {
			echo '<p>' . esc_html__( 'No archives yet.', 'cradle-migration' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Archive', 'cradle-migration' ) . '</th><th scope="col">' . esc_html__( 'Created (UTC)', 'cradle-migration' ) . '</th><th scope="col">' . esc_html__( 'Contents', 'cradle-migration' ) . '</th><th scope="col">' . esc_html__( 'Download', 'cradle-migration' ) . '</th><th scope="col">' . esc_html__( 'Actions', 'cradle-migration' ) . '</th></tr></thead><tbody>';
		foreach ( $archives as $a ) {
			echo '<tr><td><code>' . esc_html( $a['id'] ) . '</code><br>' . esc_html( $a['source'] ) . '</td><td>' . esc_html( $a['createdAt'] ) . '</td>';
			echo '<td>' . esc_html( sprintf( /* translators: 1: files 2: rows 3: size */ __( '%1$d files, %2$d rows, %3$s', 'cradle-migration' ), $a['files'], $a['rows'], size_format( '' !== $a['file'] ? $a['fileBytes'] : $a['bytes'] ) ) );
			if ( $a['unsupported'] ) {
				echo '<br><strong>' . esc_html( sprintf( /* translators: %d: count */ __( '%d unsupported object(s) reported', 'cradle-migration' ), $a['unsupported'] ) ) . '</strong>';
			}
			echo '</td><td>';
			if ( '' !== $a['file'] ) {
				echo '<a class="button" href="' . esc_url( Archives::download_url( $a['id'], $a['file'] ) ) . '">' . esc_html__( 'Download .cradle file', 'cradle-migration' ) . '</a>';
			} else {
				// Archive exported before 0.2.0: manifest and separate parts.
				echo '<ul class="cradle-migration-files"><li><a href="' . esc_url( Archives::download_url( $a['id'], Manifest::manifest_name( $a['id'] ) ) ) . '">' . esc_html__( 'manifest', 'cradle-migration' ) . '</a></li>';
				foreach ( $a['parts'] as $part ) {
					echo '<li><a href="' . esc_url( Archives::download_url( $a['id'], $part['name'] ) ) . '">' . esc_html( substr( $part['name'], strlen( $a['id'] ) + 1 ) ) . '</a> (' . esc_html( size_format( $part['size'] ) ) . ')</li>';
				}
				echo '</ul>';
			}
			echo '</td><td>';
			if ( current_user_can( 'cradle_import_site' ) ) {
				$this->form_open( 'import_local', __( 'Restore this archive over this site? Its content, users, uploads, themes and plugins replace the current ones, and you will need to sign in again.', 'cradle-migration' ) );
				echo '<input type="hidden" name="archive" value="' . esc_attr( $a['id'] ) . '" data-cradle-restore="1">';
				submit_button( __( 'Restore…', 'cradle-migration' ), 'secondary small', 'submit', false );
				echo '</form>';
			}
			$this->form_open( 'delete_archive', __( 'Delete this archive permanently?', 'cradle-migration' ) );
			echo '<input type="hidden" name="archive" value="' . esc_attr( $a['id'] ) . '">';
			submit_button( __( 'Delete', 'cradle-migration' ), 'delete small', 'submit', false );
			echo '</form></td></tr>';
		}
		echo '</tbody></table>';
	}

	private function render_import( $job ) {
		if ( ! current_user_can( 'cradle_import_site' ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Import a site', 'cradle-migration' ) . '</h2>';
		if ( ! $job ) {
			foreach ( Job_Store::active() as $row ) {
				if ( Importer::TYPE === $row['type'] ) {
					$job = \Cradle\Migration\Ajax::job_for_user( $row['uuid'], Importer::TYPE );
					break;
				}
			}
		}
		if ( $job && Importer::TYPE === $job['type'] ) {
			$this->render_import_job( $job );
			return;
		}
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Importing replaces this site\'s content, users, uploads, themes and plugins with the ones in the .cradle file. Only import files you trust: restoring themes and plugins deploys their code.', 'cradle-migration' ) . '</p></div>';
		echo '<form id="cradle-migration-upload" class="cradle-migration-upload" novalidate data-confirm="' . esc_attr__( 'Import this file? It replaces this site\'s content, users, uploads, themes and plugins. When it finishes you will be taken to the login page.', 'cradle-migration' ) . '">';
		echo '<p><label for="cm-file">' . esc_html__( '.cradle file (exported with Cradle Migration)', 'cradle-migration' ) . '</label><br><input type="file" id="cm-file" accept=".cradle" required></p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Import', 'cradle-migration' ) . '</button></p>';
		echo '<div class="cradle-migration-upload-status" hidden><progress max="100" value="0"></progress><p aria-live="polite"></p></div>';
		echo '<p class="description">' . esc_html__( 'The import checks the file, prepares the new site beside the current one and then switches over. Afterwards you are taken to the login page: sign in with an administrator account of the imported site. Everything that was replaced is kept as a backup, so the import can be rolled back under Jobs & recovery.', 'cradle-migration' ) . '</p>';
		echo '<details class="cradle-migration-options"><summary>' . esc_html__( 'Options', 'cradle-migration' ) . '</summary><fieldset><legend>' . esc_html__( 'Restore', 'cradle-migration' ) . '</legend>';
		foreach ( array( 'database' => __( 'Database', 'cradle-migration' ), 'uploads' => __( 'Uploads', 'cradle-migration' ), 'languages' => __( 'Language files', 'cradle-migration' ), 'themes' => __( 'Themes in the archive', 'cradle-migration' ), 'plugins' => __( 'Plugins in the archive', 'cradle-migration' ) ) as $key => $label ) {
			echo '<label class="cradle-migration-opt"><input type="checkbox" name="opt_' . esc_attr( $key ) . '" value="1" checked> ' . esc_html( $label ) . '</label> ';
		}
		echo '</fieldset></details>';
		echo '<noscript><p>' . esc_html__( 'Importing needs JavaScript. Without it, copy the .cradle file to the server and use the command line below.', 'cradle-migration' ) . '</p></noscript>';
		echo '</form>';
		echo '<details><summary>' . esc_html__( 'Command-line alternative', 'cradle-migration' ) . '</summary>';
		echo '<pre class="cradle-migration-cli">php ' . esc_html( wp_normalize_path( CRADLE_MIGRATION_DIR . 'bin/cradle-migrate.php' ) ) . ' --path=' . esc_html( untrailingslashit( wp_normalize_path( ABSPATH ) ) ) . ' import --archive=/path/to/site.cradle --yes</pre></details>';
	}

	private function render_import_job( array $job ) {
		$dir   = Workspace::job_dir( $job['uuid'] );
		$state = $job['state'];
		$auto  = ! empty( $job['payload']['auto'] );
		if ( ! $auto ) {
			echo '<p>' . esc_html( sprintf( /* translators: 1: job id 2: state 3: phase */ __( 'Import %1$s — %2$s (%3$s)', 'cradle-migration' ), $job['uuid'], $state, $job['phase'] ) ) . '</p>';
		}
		if ( in_array( $state, array( 'created', 'preflight', 'running' ), true ) && ! in_array( $job['phase'], \Cradle\Migration\Ajax::POST_CUTOVER, true ) ) {
			if ( $auto ) {
				// Driven by migration.js: steps, then (for the browser that started it) the switch-over.
				echo '<div class="cradle-migration-auto" data-job="' . esc_attr( $job['uuid'] ) . '">';
				echo '<p><strong>' . esc_html__( 'Importing… Keep this page open.', 'cradle-migration' ) . '</strong> ' . esc_html__( 'The site keeps working normally until the final switch, which takes a few seconds. Then you are taken to the login page.', 'cradle-migration' ) . '</p>';
				echo '<progress class="cradle-progress" max="100" value="0"></progress><p aria-live="polite"></p><ul class="cradle-migration-checks"></ul></div>';
				$this->form_open( 'discard', __( 'Cancel this import? The live site was not changed.', 'cradle-migration' ) );
				echo '<input type="hidden" name="job" value="' . esc_attr( $job['uuid'] ) . '">';
				submit_button( __( 'Cancel import', 'cradle-migration' ), 'secondary', 'submit', false );
				echo '</form>';
				return;
			}
			echo '<p>' . esc_html__( 'Checking or staging the archive. The live site is not changed in this phase.', 'cradle-migration' ) . '</p>';
			$this->job_box( $job );
			return;
		}
		if ( 'paused' === $state && 'review' === $job['phase'] ) {
			$report = is_wp_error( $dir ) ? $dir : Workspace::read_json( $dir . '/report.json' );
			if ( is_array( $report ) ) {
				$this->render_report( $report );
			}
			echo '<div class="cradle-migration-actions">';
			$this->form_open( 'approve' );
			echo '<input type="hidden" name="job" value="' . esc_attr( $job['uuid'] ) . '">';
			submit_button( __( 'Approve and stage (the live site is not changed yet)', 'cradle-migration' ), 'primary', 'submit', false );
			echo '</form>';
			$this->form_open( 'discard', __( 'Discard this import?', 'cradle-migration' ) );
			echo '<input type="hidden" name="job" value="' . esc_attr( $job['uuid'] ) . '">';
			submit_button( __( 'Discard', 'cradle-migration' ), 'secondary', 'submit', false );
			echo '</form></div>';
			return;
		}
		if ( 'awaiting_cutover' === $state ) {
			$profile = is_wp_error( $dir ) ? array() : Workspace::read_json( $dir . '/profile.json' );
			$admins  = is_array( $profile ) ? (array) ( $profile['admins'] ?? array() ) : array();
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Everything is staged and validated. The next step switches the site over.', 'cradle-migration' ) . '</strong></p><ul class="ul-disc">';
			echo '<li>' . esc_html__( 'Visitors see a maintenance page for the few seconds the switch takes; email, WP-Cron and outbound HTTP stay paused afterwards until you release the safety hold.', 'cradle-migration' ) . '</li>';
			echo '<li>' . esc_html( sprintf( /* translators: %s: logins */ __( 'Your current login stops working at cutover. Afterwards sign in as a source administrator: %s (source password).', 'cradle-migration' ), $admins ? implode( ', ', $admins ) : '—' ) ) . '</li>';
			echo '<li>' . esc_html__( 'Keep this tab open until it reports completion: it holds a one-time recovery token that lets it finish without a session. If the tab closes, finish with the CLI command below.', 'cradle-migration' ) . '</li></ul></div>';
			// data-auto: the browser that started a one-step import switches over without another click.
			echo '<div class="cradle-migration-cutover" data-job="' . esc_attr( $job['uuid'] ) . '"' . ( $auto ? ' data-auto="1"' : '' ) . '><p><button type="button" class="button button-primary cradle-migration-cutover-button">' . esc_html__( 'Switch the site over now', 'cradle-migration' ) . '</button></p>';
			echo '<div class="cradle-migration-cutover-status" hidden><progress max="100" value="0"></progress><p aria-live="polite"></p><ul class="cradle-migration-checks"></ul></div></div>';
			$this->form_open( 'discard', __( 'Discard this import?', 'cradle-migration' ) );
			echo '<input type="hidden" name="job" value="' . esc_attr( $job['uuid'] ) . '">';
			submit_button( __( 'Discard instead', 'cradle-migration' ), 'secondary', 'submit', false );
			echo '</form>';
			$this->cli_block( $job['uuid'] );
			return;
		}
		// Post-cutover or terminal.
		$this->render_outcome( $job, $dir );
	}

	private function render_outcome( array $job, $dir ) {
		if ( is_wp_error( $dir ) ) {
			return;
		}
		if ( 'completed' === $job['state'] ) {
			echo '<div class="notice notice-success inline"><p><strong>' . esc_html__( 'The site was imported.', 'cradle-migration' ) . '</strong> ' . esc_html__( 'Check the site, then release the safety hold under Jobs & recovery so that email, scheduled tasks and connections to other services work again. The replaced site is kept as a backup until you purge it there.', 'cradle-migration' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( $this->url( array( 'tab' => 'jobs' ) ) ) . '">' . esc_html__( 'Jobs & recovery', 'cradle-migration' ) . '</a> <a class="button" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'View site', 'cradle-migration' ) . '</a></p></div>';
		}
		if ( 'failed' === $job['state'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( (string) $job['error_message'] ) . '</p></div>';
			$report = is_file( $dir . '/report.json' ) ? Workspace::read_json( $dir . '/report.json' ) : null;
			if ( is_array( $report ) && 'preflight_failed' === $job['phase'] ) {
				$this->render_report( $report );
			}
		}
		$verify = is_file( $dir . '/verify.json' ) ? Workspace::read_json( $dir . '/verify.json' ) : null;
		if ( is_array( $verify ) ) {
			echo '<h3>' . esc_html__( 'Post-restore verification', 'cradle-migration' ) . '</h3><table class="widefat striped"><tbody>';
			foreach ( $verify['checks'] as $check ) {
				echo '<tr><th scope="row">' . esc_html( $check['label'] ) . '</th><td><span class="cradle-migration-status cradle-migration-status--' . esc_attr( $check['status'] ) . '">' . esc_html( $check['status'] ) . '</span></td><td>' . esc_html( $check['detail'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		$stage = ( new Journal( $dir ) )->stage();
		if ( ! in_array( $job['state'], Jobs::TERMINAL_STATES, true ) && 'not_started' !== $stage ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This restore is switching over. If the browser that started it was closed, finish or roll back from the server:', 'cradle-migration' ) . '</p></div>';
			$this->cli_block( $job['uuid'] );
		}
	}

	private function cli_block( string $uuid ) {
		$bin  = wp_normalize_path( CRADLE_MIGRATION_DIR . 'bin/cradle-migrate.php' );
		$path = untrailingslashit( wp_normalize_path( ABSPATH ) );
		echo '<pre class="cradle-migration-cli">php ' . esc_html( $bin ) . ' --path=' . esc_html( $path ) . ' status --job=' . esc_html( $uuid ) . "\n";
		echo 'php ' . esc_html( $bin ) . ' --path=' . esc_html( $path ) . ' recover --job=' . esc_html( $uuid ) . "            # finish\n";
		echo 'php ' . esc_html( $bin ) . ' --path=' . esc_html( $path ) . ' recover --job=' . esc_html( $uuid ) . " --rollback # undo</pre>";
	}

	private function render_report( array $report ) {
		if ( ! empty( $report['blocking'] ) ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Blocking problems — this archive cannot be restored:', 'cradle-migration' ) . '</strong></p><ul class="ul-disc">';
			foreach ( $report['blocking'] as $b ) {
				echo '<li>' . esc_html( (string) $b ) . '</li>';
			}
			echo '</ul></div>';
		}
		echo '<h3>' . esc_html__( 'Preflight checklist', 'cradle-migration' ) . '</h3><table class="widefat striped"><tbody>';
		foreach ( (array) $report['checks'] as $c ) {
			echo '<tr><th scope="row">' . esc_html( $c['label'] ) . '</th><td><span class="cradle-migration-status cradle-migration-status--' . esc_attr( $c['status'] ) . '">' . esc_html( $c['status'] ) . '</span></td><td>' . esc_html( $c['detail'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( ! empty( $report['archive'] ) ) {
			echo '<p>' . esc_html( sprintf( /* translators: 1: archive 2: source 3: wp version 4: created */ __( 'Archive %1$s from %2$s (WordPress %3$s), created %4$s.', 'cradle-migration' ), $report['archive']['id'], $report['archive']['source'], $report['archive']['wordpress'], $report['archive']['created'] ) ) . '</p>';
		}
		if ( ! empty( $report['admin']['summary'] ) ) {
			echo '<h3>' . esc_html__( 'Administrator login after restore', 'cradle-migration' ) . '</h3><p>' . esc_html( $report['admin']['summary'] ) . '</p>';
		}
		if ( ! empty( $report['urlMapping'] ) ) {
			echo '<h3>' . esc_html__( 'URL, path and prefix mapping', 'cradle-migration' ) . '</h3><ul class="ul-disc">';
			foreach ( $report['urlMapping'] as $pair ) {
				echo '<li><code>' . esc_html( $pair[0] ) . '</code> → <code>' . esc_html( $pair[1] ) . '</code></li>';
			}
			echo '</ul>';
		}
		foreach ( array( 'tables' => __( 'Database tables', 'cradle-migration' ), 'files' => __( 'Files', 'cradle-migration' ) ) as $key => $title ) {
			if ( empty( $report[ $key ] ) ) {
				continue;
			}
			echo '<details><summary>' . esc_html( $title ) . ' (' . count( $report[ $key ] ) . ')</summary><table class="widefat striped"><tbody>';
			foreach ( $report[ $key ] as $row ) {
				$name = 'tables' === $key ? $row['table'] : $row['category'] . ( '' !== $row['package'] ? '/' . $row['package'] : '' );
				$info = 'tables' === $key ? ( null === $row['rows'] ? '' : $row['rows'] . ' rows' ) : $row['files'] . ' files, ' . size_format( $row['bytes'] );
				echo '<tr><th scope="row"><code>' . esc_html( $name ) . '</code></th><td>' . esc_html( $info ) . '</td><td>' . esc_html( $row['action'] ) . '</td></tr>';
			}
			echo '</tbody></table></details>';
		}
		foreach ( array( 'external' => __( 'External services and safety profile', 'cradle-migration' ), 'warnings' => __( 'Warnings', 'cradle-migration' ) ) as $key => $title ) {
			if ( empty( $report[ $key ] ) ) {
				continue;
			}
			echo '<h3>' . esc_html( $title ) . '</h3><ul class="ul-disc">';
			foreach ( $report[ $key ] as $line ) {
				echo '<li>' . esc_html( (string) $line ) . '</li>';
			}
			echo '</ul>';
		}
		if ( ! empty( $report['exclusions'] ) ) {
			echo '<details><summary>' . esc_html__( 'Excluded at export', 'cradle-migration' ) . ' (' . count( $report['exclusions'] ) . ')</summary><ul class="ul-disc">';
			foreach ( $report['exclusions'] as $e ) {
				echo '<li><code>' . esc_html( ( $e['category'] ?? '' ) . '/' . ( $e['path'] ?? '' ) ) . '</code> — ' . esc_html( $e['reason'] ?? '' ) . '</li>';
			}
			echo '</ul></details>';
		}
	}

	private function render_jobs() {
		$hold = Guard::hold();
		echo '<h2>' . esc_html__( 'Safety hold', 'cradle-migration' ) . '</h2>';
		if ( $hold ) {
			echo '<p>' . esc_html__( 'Active since the last restore: outgoing email, WP-Cron and outbound HTTP to other hosts are paused. Release it once you have checked the site and its external integrations.', 'cradle-migration' ) . '</p>';
			if ( current_user_can( 'cradle_import_site' ) ) {
				$this->form_open( 'release_hold', __( 'Release the safety hold? Email, scheduled tasks and outbound HTTP will run again.', 'cradle-migration' ) );
				submit_button( __( 'Release safety hold', 'cradle-migration' ), 'secondary', 'submit', false );
				echo '</form>';
			}
		} else {
			echo '<p>' . esc_html__( 'No safety hold is active.', 'cradle-migration' ) . '</p>';
		}
		echo '<h2>' . esc_html__( 'Recent migration jobs', 'cradle-migration' ) . '</h2>';
		$rows = array_filter( cradle()->jobs()->recent( 'migration', 25 ), static fn( $j ) => cradle()->jobs()->user_can_access( $j ) );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No jobs yet.', 'cradle-migration' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Job', 'cradle-migration' ) . '</th><th scope="col">' . esc_html__( 'State', 'cradle-migration' ) . '</th><th scope="col">' . esc_html__( 'Updated (UTC)', 'cradle-migration' ) . '</th><th scope="col">' . esc_html__( 'Actions', 'cradle-migration' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $job ) {
			$stage  = '';
			$purged = false;
			if ( Importer::TYPE === $job['type'] ) {
				$dir    = Workspace::job_dir( $job['uuid'] );
				$stage  = is_wp_error( $dir ) ? '' : ( new Journal( $dir ) )->stage();
				$purged = ! is_wp_error( $dir ) && ( new Journal( $dir ) )->has( 'backups_purged' );
			}
			echo '<tr><td><code>' . esc_html( $job['type'] ) . '</code><br><small>' . esc_html( $job['uuid'] ) . '</small></td>';
			echo '<td>' . esc_html( $job['state'] . ' — ' . $job['phase'] ) . ( '' !== $stage && 'not_started' !== $stage ? '<br><small>' . esc_html( sprintf( /* translators: %s: stage */ __( 'cutover journal: %s', 'cradle-migration' ), $stage ) ) . '</small>' : '' ) . ( $job['error_message'] ? '<br><small>' . esc_html( (string) $job['error_message'] ) . '</small>' : '' ) . '</td>';
			echo '<td>' . esc_html( $job['updated_at'] ) . '</td><td>';
			if ( Importer::TYPE === $job['type'] && current_user_can( 'cradle_import_site' ) ) {
				echo '<a href="' . esc_url( $this->url( array( 'tab' => 'import', 'job' => $job['uuid'] ) ) ) . '">' . esc_html__( 'Open', 'cradle-migration' ) . '</a> ';
				if ( in_array( $stage, array( 'db_cutover_in_progress', 'db_swapped', 'files_in_progress', 'files_replaced', 'finalized', 'verified' ), true ) && 'rolled_back' !== $job['state'] ) {
					$this->form_open( 'rollback', __( 'Roll this restore back? The site returns to its state before the import.', 'cradle-migration' ) );
					echo '<input type="hidden" name="job" value="' . esc_attr( $job['uuid'] ) . '">';
					submit_button( __( 'Roll back', 'cradle-migration' ), 'secondary small', 'submit', false );
					echo '</form>';
				}
				if ( in_array( $job['state'], array( 'completed', 'rolled_back' ), true ) && ! $purged ) {
					$this->form_open( 'purge', __( 'Delete the backups of this restore? Rollback will no longer be possible.', 'cradle-migration' ) );
					echo '<input type="hidden" name="job" value="' . esc_attr( $job['uuid'] ) . '">';
					submit_button( __( 'Purge backups', 'cradle-migration' ), 'delete small', 'submit', false );
					echo '</form>';
				}
				if ( 'not_started' === $stage && ! in_array( $job['state'], array( 'completed', 'rolled_back' ), true ) && 'discarded' !== $job['phase'] ) {
					$this->form_open( 'discard', __( 'Discard this import? The live site was not changed.', 'cradle-migration' ) );
					echo '<input type="hidden" name="job" value="' . esc_attr( $job['uuid'] ) . '">';
					submit_button( __( 'Discard', 'cradle-migration' ), 'secondary small', 'submit', false );
					echo '</form>';
				}
			} elseif ( Exporter::TYPE === $job['type'] && ! in_array( $job['state'], Jobs::TERMINAL_STATES, true ) ) {
				echo '<a href="' . esc_url( $this->url( array( 'tab' => 'export', 'job' => $job['uuid'] ) ) ) . '">' . esc_html__( 'Resume', 'cradle-migration' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p>' . esc_html__( 'Operator recovery for an interrupted restore is documented in wp-content/plugins/cradle-migration/docs/recovery-guide.md.', 'cradle-migration' ) . '</p>';
	}
}
