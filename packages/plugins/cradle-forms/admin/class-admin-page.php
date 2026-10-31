<?php
/**
 * Cradle → Forms: forms list (with new-entry counts), entries per form (filters, bulk status and
 * delete, CSV export), entry detail (values, files, consent records) and the Settings tab.
 *
 * Every mutation is a POST to admin-post.php with a nonce and a capability check; downloads are
 * GET links with a per-file nonce plus the view capability. GET requests never change data.
 *
 * @package Cradle\Forms\Admin
 */

namespace Cradle\Forms\Admin;

use Cradle\Forms\Caps;
use Cradle\Forms\Entries;
use Cradle\Forms\Field_Types;
use Cradle\Forms\Form_Schema;
use Cradle\Forms\Module;
use Cradle\Forms\Post_Type;
use Cradle\Forms\Schema;
use Cradle\Forms\Settings_Section;
use Cradle\Forms\Uploads;

defined( 'ABSPATH' ) || exit;

final class Admin_Page {

	const SLUG     = 'cradle-forms';
	const PER_PAGE = 20;

	/** @var string */
	private $hook = '';

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_cradle_forms_entries', array( $this, 'handle_entries' ) );
		add_action( 'admin_post_cradle_forms_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_cradle_forms_download', array( $this, 'handle_download' ) );
		add_action( 'load-edit.php', array( $this, 'redirect_list' ) );
	}

	public function menu() {
		if ( ! Caps::can_open() ) {
			return;
		}
		$this->hook = (string) add_submenu_page(
			'cradle',
			__( 'Forms', 'cradle-forms' ),
			__( 'Forms', 'cradle-forms' ),
			current_user_can( Caps::VIEW ) ? Caps::VIEW : Caps::MANAGE,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}
		\Cradle\Core\Assets::register_style( Module::ADMIN_STYLE, CRADLE_FORMS_FILE, 'assets/css/admin.css', array(), CRADLE_FORMS_VERSION );
		wp_enqueue_style( Module::ADMIN_STYLE );
		wp_enqueue_style( 'cradle-admin' );
		wp_enqueue_script( 'cradle-admin' );
	}

	/**
	 * The core list of form posts (the editor's back link) opens Cradle → Forms instead.
	 */
	public function redirect_list() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen switch.
		if ( array( 'post_type' ) === array_keys( $_GET ) && Post_Type::NAME === $_GET['post_type'] && Caps::can_open() ) { // Filtered views (trash, language) stay available.
			wp_safe_redirect( self::url() );
			exit;
		}
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function deny( string $message = '' ) {
		wp_die( esc_html( '' !== $message ? $message : __( 'You are not allowed to do this.', 'cradle-forms' ) ), '', array( 'response' => 403 ) );
	}

	private static function require_post() {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
			wp_die( esc_html__( 'This action requires a POST request.', 'cradle-forms' ), '', array( 'response' => 405 ) );
		}
	}

	private static function form_or_die( int $form_id ): \WP_Post {
		$form = $form_id ? get_post( $form_id ) : null;
		if ( ! $form || Post_Type::NAME !== $form->post_type ) {
			wp_die( esc_html__( 'This form does not exist.', 'cradle-forms' ), '', array( 'response' => 404 ) );
		}
		return $form;
	}

	/* ------------------------------------------------------------------ */
	/* Handlers                                                            */
	/* ------------------------------------------------------------------ */

	public function handle_entries() {
		self::require_post();
		check_admin_referer( 'cradle_forms_entries' );
		$op      = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$form_id = isset( $_POST['form'] ) ? absint( wp_unslash( $_POST['form'] ) ) : 0;
		$ids     = isset( $_POST['entries'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['entries'] ) ) : array();
		$return  = isset( $_POST['return'] ) && 'entry' === $_POST['return'] ? 'entry' : 'entries';
		if ( ! in_array( $op, array( 'read', 'new', 'spam', 'delete' ), true ) ) {
			wp_die( esc_html__( 'Unknown action.', 'cradle-forms' ), '', array( 'response' => 400 ) );
		}
		if ( ! current_user_can( 'delete' === $op ? Caps::DELETE : Caps::VIEW ) ) {
			self::deny( 'delete' === $op ? __( 'You are not allowed to delete entries.', 'cradle-forms' ) : __( 'You are not allowed to change entries.', 'cradle-forms' ) );
		}
		self::form_or_die( $form_id );
		$ids = Entries::existing( array_slice( $ids, 0, 500 ), $form_id );
		if ( 'delete' === $op ) {
			$count = Entries::delete( $ids );
			cradle_log_activity( Module::ID, 'entries_deleted', sprintf( 'Deleted %d entries of form %d.', $count, $form_id ), array( 'form' => $form_id ) );
			wp_safe_redirect( self::url( array( 'view' => 'entries', 'form' => $form_id, 'cradle_forms_notice' => 'deleted', 'count' => $count ) ) );
			exit;
		}
		$count = Entries::set_status( $ids, $op );
		$args  = 'entry' === $return && 1 === count( $ids )
			? array( 'view' => 'entry', 'entry' => $ids[0], 'cradle_forms_notice' => 'updated', 'count' => $count )
			: array( 'view' => 'entries', 'form' => $form_id, 'cradle_forms_notice' => 'updated', 'count' => $count );
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	public function handle_export() {
		self::require_post();
		$form_id = isset( $_POST['form'] ) ? absint( wp_unslash( $_POST['form'] ) ) : 0;
		check_admin_referer( 'cradle_forms_export_' . $form_id );
		if ( ! current_user_can( Caps::EXPORT ) ) {
			self::deny( __( 'You are not allowed to export entries.', 'cradle-forms' ) );
		}
		$form    = self::form_or_die( $form_id );
		$filters = self::filters( wp_unslash( $_POST ) );
		cradle_log_activity( Module::ID, 'entries_exported', sprintf( 'Exported entries of form %d as CSV.', $form_id ), array( 'form' => $form_id ) );
		Csv::stream( $form, $filters );
	}

	public function handle_download() {
		$file_id = isset( $_GET['file'] ) ? absint( wp_unslash( $_GET['file'] ) ) : 0;
		check_admin_referer( 'cradle_forms_download_' . $file_id );
		if ( ! current_user_can( Caps::VIEW ) ) {
			self::deny( __( 'You are not allowed to download files.', 'cradle-forms' ) );
		}
		$row = Entries::file( $file_id );
		if ( ! $row || ! Entries::get( (int) $row['entry_id'] ) ) {
			wp_die( esc_html__( 'The file is no longer available.', 'cradle-forms' ), '', array( 'response' => 404 ) );
		}
		cradle_log_activity( Module::ID, 'file_downloaded', sprintf( 'Downloaded file %d of entry %d.', $file_id, (int) $row['entry_id'] ), array( 'entry' => (int) $row['entry_id'] ) );
		Uploads::stream( $row );
	}

	public static function download_url( int $file_id ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'cradle_forms_download', 'file' => $file_id ), admin_url( 'admin-post.php' ) ), 'cradle_forms_download_' . $file_id );
	}

	/**
	 * Allowlisted list filters from request data.
	 */
	private static function filters( $source ): array {
		$source = is_array( $source ) ? $source : array();
		$status = isset( $source['status'] ) ? sanitize_key( $source['status'] ) : '';
		$from   = isset( $source['from'] ) ? sanitize_text_field( (string) $source['from'] ) : '';
		$to     = isset( $source['to'] ) ? sanitize_text_field( (string) $source['to'] ) : '';
		return array(
			'status' => in_array( $status, array_merge( Entries::STATUSES, array( 'all' ) ), true ) ? $status : '',
			'from'   => Field_Types::is_date( $from ) ? $from : '',
			'to'     => Field_Types::is_date( $to ) ? $to : '',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Screens                                                             */
	/* ------------------------------------------------------------------ */

	public function render() {
		if ( ! Caps::can_open() ) {
			self::deny();
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen selection.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'forms';
		echo '<div class="wrap cradle-forms-admin">';
		$this->notices();
		switch ( $view ) {
			case 'entries':
				$this->entries( isset( $_GET['form'] ) ? absint( wp_unslash( $_GET['form'] ) ) : 0, self::filters( wp_unslash( $_GET ) ), isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1 );
				break;
			case 'entry':
				$this->entry( isset( $_GET['entry'] ) ? absint( wp_unslash( $_GET['entry'] ) ) : 0 );
				break;
			case 'settings':
				$this->settings();
				break;
			default:
				$this->forms();
		}
		// phpcs:enable
		echo '</div>';
	}

	private function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display of a redirect message.
		$notice = isset( $_GET['cradle_forms_notice'] ) ? sanitize_key( wp_unslash( $_GET['cradle_forms_notice'] ) ) : '';
		$count  = isset( $_GET['count'] ) ? absint( wp_unslash( $_GET['count'] ) ) : 0;
		// phpcs:enable
		if ( 'deleted' === $notice ) {
			/* translators: %d: number of entries */
			$text = sprintf( _n( '%d entry deleted.', '%d entries deleted.', $count, 'cradle-forms' ), $count );
		} elseif ( 'updated' === $notice ) {
			/* translators: %d: number of entries */
			$text = sprintf( _n( '%d entry updated.', '%d entries updated.', $count, 'cradle-forms' ), $count );
		} else {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	private function tabs( string $active ) {
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Forms sections', 'cradle-forms' ) . '">';
		echo '<a class="nav-tab' . ( 'forms' === $active ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url() ) . '"' . ( 'forms' === $active ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'Forms', 'cradle-forms' ) . '</a>';
		if ( current_user_can( Caps::MANAGE ) ) {
			echo '<a class="nav-tab' . ( 'settings' === $active ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( array( 'view' => 'settings' ) ) ) . '"' . ( 'settings' === $active ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'Settings', 'cradle-forms' ) . '</a>';
		}
		echo '</nav>';
	}

	private function storage_warning( array $forms ) {
		foreach ( $forms as $form ) {
			if ( Form_Schema::has_type( Form_Schema::fields( (int) $form->ID ), 'file' ) ) {
				if ( ! Uploads::storage_protected() ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'File uploads are refused: the private storage of this site is not protected from direct web access. Define CRADLE_PRIVATE_DIR outside the web root in wp-config.php, or make the server deny access to the private uploads folder.', 'cradle-forms' ) . '</p></div>';
				}
				return;
			}
		}
	}

	private function forms() {
		$forms  = get_posts(
			array(
				'post_type'        => Post_Type::NAME,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts'      => 500,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		$counts = Entries::counts_by_form();
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Forms', 'cradle-forms' ) . '</h1>';
		if ( current_user_can( Caps::MANAGE ) ) {
			echo ' <a class="page-title-action" href="' . esc_url( admin_url( 'post-new.php?post_type=' . Post_Type::NAME ) ) . '">' . esc_html__( 'Add form', 'cradle-forms' ) . '</a>';
		}
		echo '<hr class="wp-header-end">';
		$this->tabs( 'forms' );
		$this->storage_warning( $forms );
		echo '<p>' . esc_html__( 'Build a form with field blocks, publish it, then add the Form block to any page.', 'cradle-forms' ) . '</p>';
		$trashed = (int) ( wp_count_posts( Post_Type::NAME )->trash ?? 0 );
		if ( $trashed && current_user_can( Caps::MANAGE ) ) {
			/* translators: %d: number of forms in the trash */
			echo '<p><a href="' . esc_url( admin_url( 'edit.php?post_type=' . Post_Type::NAME . '&post_status=trash' ) ) . '">' . esc_html( sprintf( _n( '%d form in the trash', '%d forms in the trash', $trashed, 'cradle-forms' ), $trashed ) ) . '</a></p>';
		}
		if ( ! $forms ) {
			echo '<p>' . esc_html__( 'No forms yet.', 'cradle-forms' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped cradle-forms-table"><thead><tr>';
		$languages = \Cradle\Forms\Language::languages_active() && function_exists( 'cradle_language_of' );
		$headings  = array( __( 'Form', 'cradle-forms' ), __( 'Status', 'cradle-forms' ), __( 'Fields', 'cradle-forms' ), __( 'New entries', 'cradle-forms' ), __( 'Entries', 'cradle-forms' ) );
		if ( $languages ) {
			array_splice( $headings, 1, 0, array( __( 'Language', 'cradle-forms' ) ) );
		}
		foreach ( $headings as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $forms as $form ) {
			$id      = (int) $form->ID;
			$title   = '' !== $form->post_title ? $form->post_title : __( '(no title)', 'cradle-forms' );
			$status  = get_post_status_object( $form->post_status );
			$errors  = Form_Schema::errors( $id );
			$entries = self::url( array( 'view' => 'entries', 'form' => $id ) );
			echo '<tr><td><strong>';
			if ( current_user_can( 'edit_post', $id ) ) {
				echo '<a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( $title ) . '</a>';
			} else {
				echo esc_html( $title );
			}
			echo '</strong>';
			if ( $errors ) {
				echo '<br><span class="cradle-forms-problem">' . esc_html__( 'Has problems: visitors see the last valid version.', 'cradle-forms' ) . '</span>';
			}
			echo '<div class="row-actions">';
			if ( current_user_can( Caps::VIEW ) ) {
				echo '<a href="' . esc_url( $entries ) . '">' . esc_html__( 'Entries', 'cradle-forms' ) . '</a>';
			}
			if ( current_user_can( 'edit_post', $id ) ) {
				echo ( current_user_can( Caps::VIEW ) ? ' | ' : '' ) . '<a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html__( 'Edit', 'cradle-forms' ) . '</a>';
			}
			echo '</div></td>';
			if ( $languages ) {
				$language = cradle_language_of( $form );
				echo '<td>' . esc_html( is_array( $language ) ? (string) ( $language['native'] ?? $language['slug'] ?? '' ) : '' ) . '</td>';
			}
			echo '<td>' . esc_html( $status ? $status->label : $form->post_status ) . '</td>';
			echo '<td>' . esc_html( (string) count( Form_Schema::fields( $id ) ) ) . '</td>';
			$new = (int) ( $counts[ $id ]['new'] ?? 0 );
			echo '<td>' . ( $new && current_user_can( Caps::VIEW ) ? '<a href="' . esc_url( add_query_arg( 'status', 'new', $entries ) ) . '"><span class="cradle-forms-badge">' . esc_html( number_format_i18n( $new ) ) . '</span></a>' : esc_html( number_format_i18n( $new ) ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) ( $counts[ $id ]['total'] ?? 0 ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function entries( int $form_id, array $filters, int $page ) {
		if ( ! current_user_can( Caps::VIEW ) ) {
			self::deny( __( 'You are not allowed to view entries.', 'cradle-forms' ) );
		}
		$form   = self::form_or_die( $form_id );
		$result = Entries::query(
			array_merge(
				$filters,
				array(
					'form_id'  => $form_id,
					'page'     => $page,
					'per_page' => self::PER_PAGE,
				)
			)
		);
		$values = Entries::values_for( wp_list_pluck( $result['rows'], 'id' ) );
		$fields = Form_Schema::fields( $form_id );

		/* translators: %s: form title */
		echo '<h1>' . esc_html( sprintf( __( 'Entries: %s', 'cradle-forms' ), $form->post_title ) ) . '</h1>';
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( '← All forms', 'cradle-forms' ) . '</a></p>';

		echo '<form method="get" class="cradle-forms-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="view" value="entries"><input type="hidden" name="form" value="' . esc_attr( (string) $form_id ) . '">';
		echo '<label for="cradle-forms-status">' . esc_html__( 'Status', 'cradle-forms' ) . '</label> <select id="cradle-forms-status" name="status">';
		foreach ( array(
			''     => __( 'All except spam', 'cradle-forms' ),
			'new'  => __( 'New', 'cradle-forms' ),
			'read' => __( 'Read', 'cradle-forms' ),
			'spam' => __( 'Spam', 'cradle-forms' ),
			'all'  => __( 'All', 'cradle-forms' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $filters['status'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <label for="cradle-forms-from">' . esc_html__( 'From', 'cradle-forms' ) . '</label> <input type="date" id="cradle-forms-from" name="from" value="' . esc_attr( $filters['from'] ) . '"> ';
		echo '<label for="cradle-forms-to">' . esc_html__( 'To', 'cradle-forms' ) . '</label> <input type="date" id="cradle-forms-to" name="to" value="' . esc_attr( $filters['to'] ) . '"> ';
		submit_button( __( 'Filter', 'cradle-forms' ), 'secondary', '', false );
		echo '</form>';

		if ( current_user_can( Caps::EXPORT ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cradle-forms-export"><input type="hidden" name="action" value="cradle_forms_export"><input type="hidden" name="form" value="' . esc_attr( (string) $form_id ) . '">';
			foreach ( $filters as $key => $value ) {
				echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
			}
			wp_nonce_field( 'cradle_forms_export_' . $form_id );
			submit_button( __( 'Export CSV', 'cradle-forms' ), 'secondary', '', false );
			echo '</form>';
		}

		/* translators: %d: number of entries */
		echo '<p class="cradle-forms-count">' . esc_html( sprintf( _n( '%d entry', '%d entries', $result['total'], 'cradle-forms' ), $result['total'] ) ) . '</p>';
		if ( ! $result['rows'] ) {
			echo '<p>' . esc_html__( 'No entries match.', 'cradle-forms' ) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-cradle-confirm="' . esc_attr__( 'Apply this action to the selected entries? Deleting cannot be undone.', 'cradle-forms' ) . '"><input type="hidden" name="action" value="cradle_forms_entries"><input type="hidden" name="form" value="' . esc_attr( (string) $form_id ) . '">';
		wp_nonce_field( 'cradle_forms_entries' );
		echo '<div class="tablenav top"><label for="cradle-forms-op" class="screen-reader-text">' . esc_html__( 'Bulk action', 'cradle-forms' ) . '</label><select id="cradle-forms-op" name="op">';
		echo '<option value="read">' . esc_html__( 'Mark as read', 'cradle-forms' ) . '</option><option value="new">' . esc_html__( 'Mark as new', 'cradle-forms' ) . '</option><option value="spam">' . esc_html__( 'Mark as spam', 'cradle-forms' ) . '</option>';
		if ( current_user_can( Caps::DELETE ) ) {
			echo '<option value="delete">' . esc_html__( 'Delete permanently', 'cradle-forms' ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Apply', 'cradle-forms' ), 'secondary', '', false );
		echo '</div><table class="widefat striped cradle-forms-table"><thead><tr><td class="manage-column column-cb check-column"><input type="checkbox" id="cradle-forms-select-all"><label class="screen-reader-text" for="cradle-forms-select-all">' . esc_html__( 'Select all', 'cradle-forms' ) . '</label></td>';
		foreach ( array( __( 'Submitted', 'cradle-forms' ), __( 'Summary', 'cradle-forms' ), __( 'Sent from', 'cradle-forms' ), __( 'Status', 'cradle-forms' ), __( 'Language', 'cradle-forms' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $result['rows'] as $row ) {
			$id      = (int) $row['id'];
			$summary = array();
			foreach ( $values[ $id ] ?? array() as $value ) {
				if ( in_array( $value['type'], array( 'consent', 'hidden' ), true ) ) {
					continue;
				}
				$field = Form_Schema::field( $fields, $value['field_name'] );
				$text  = Field_Types::display( $value['type'], $value['value'], $field && $field['type'] === $value['type'] ? $field : null );
				if ( '' !== $text ) {
					$summary[] = wp_html_excerpt( $text, 60, '…' );
				}
				if ( count( $summary ) >= 3 ) {
					break;
				}
			}
			$link = self::url( array( 'view' => 'entry', 'entry' => $id ) );
			echo '<tr' . ( 'new' === $row['status'] ? ' class="cradle-forms-new"' : '' ) . '><th scope="row" class="check-column"><input type="checkbox" name="entries[]" value="' . esc_attr( (string) $id ) . '" id="cradle-forms-entry-' . esc_attr( (string) $id ) . '"><label class="screen-reader-text" for="cradle-forms-entry-' . esc_attr( (string) $id ) . '">' . esc_html( sprintf( /* translators: %d: entry ID */ __( 'Select entry %d', 'cradle-forms' ), $id ) ) . '</label></th>';
			echo '<td><a href="' . esc_url( $link ) . '">' . esc_html( get_date_from_gmt( (string) $row['created_at'], 'Y-m-d H:i' ) ) . '</a></td>';
			echo '<td>' . esc_html( implode( ' · ', $summary ) ) . '</td>';
			echo '<td>' . self::source_html( (int) ( $row['source_id'] ?? 0 ), (string) ( $row['source_title'] ?? '' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by source_html().
			echo '<td>' . esc_html( self::status_label( (string) $row['status'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['language'] ) . '</td></tr>';
		}
		echo '</tbody></table></form>';

		$pages = (int) ceil( $result['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<nav class="tablenav bottom" aria-label="' . esc_attr__( 'Entry pages', 'cradle-forms' ) . '"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%', self::url( array_merge( array( 'view' => 'entries', 'form' => $form_id ), array_filter( $filters ) ) ) ),
						'format'    => '',
						'current'   => $page,
						'total'     => $pages,
						'prev_text' => __( '‹ Previous', 'cradle-forms' ),
						'next_text' => __( 'Next ›', 'cradle-forms' ),
					)
				)
			);
			echo '</div></nav>';
		}
	}

	/**
	 * Escaped "Sent from" value: the title snapshot, linked to the edit screen when the current
	 * user may edit that entry; empty when the form was not sent from a singular page.
	 */
	public static function source_html( int $source_id, string $title ): string {
		if ( $source_id <= 0 ) {
			return '';
		}
		$text = '' !== $title ? $title : '#' . $source_id;
		if ( get_post( $source_id ) && current_user_can( 'edit_post', $source_id ) ) {
			$link = (string) get_edit_post_link( $source_id, 'raw' );
			if ( '' !== $link ) {
				return '<a href="' . esc_url( $link ) . '">' . esc_html( $text ) . '</a>';
			}
		}
		return esc_html( $text );
	}

	private static function status_label( string $status ): string {
		return array(
			'new'  => __( 'New', 'cradle-forms' ),
			'read' => __( 'Read', 'cradle-forms' ),
			'spam' => __( 'Spam', 'cradle-forms' ),
		)[ $status ] ?? $status;
	}

	private function entry( int $entry_id ) {
		if ( ! current_user_can( Caps::VIEW ) ) {
			self::deny( __( 'You are not allowed to view entries.', 'cradle-forms' ) );
		}
		$entry = Entries::get( $entry_id );
		if ( ! $entry ) {
			wp_die( esc_html__( 'This entry does not exist.', 'cradle-forms' ), '', array( 'response' => 404 ) );
		}
		$fields = Form_Schema::fields( $entry['form_id'] );
		$files  = array();
		foreach ( Entries::files( $entry_id ) as $file ) {
			$files[ $file['field_name'] ][] = $file;
		}
		/* translators: %d: entry ID */
		echo '<h1>' . esc_html( sprintf( __( 'Entry %d', 'cradle-forms' ), $entry_id ) ) . '</h1>';
		echo '<p><a href="' . esc_url( self::url( array( 'view' => 'entries', 'form' => $entry['form_id'] ) ) ) . '">' . esc_html__( '← Entries of this form', 'cradle-forms' ) . '</a></p>';

		echo '<table class="widefat striped cradle-forms-meta"><tbody>';
		foreach ( array(
			__( 'Form', 'cradle-forms' )      => $entry['form_title'],
			__( 'Submitted', 'cradle-forms' ) => get_date_from_gmt( (string) $entry['created_at'], 'Y-m-d H:i:s' ),
			__( 'Status', 'cradle-forms' )    => self::status_label( (string) $entry['status'] ),
			__( 'Language', 'cradle-forms' )  => trim( $entry['language'] . ( $entry['locale'] && $entry['locale'] !== $entry['language'] ? ' (' . $entry['locale'] . ')' : '' ) ),
		) as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		if ( $entry['source_id'] ) {
			echo '<tr><th scope="row">' . esc_html__( 'Sent from', 'cradle-forms' ) . '</th><td>' . self::source_html( $entry['source_id'], $entry['source_title'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by source_html().
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Values', 'cradle-forms' ) . '</h2><table class="widefat striped cradle-forms-values"><tbody>';
		foreach ( Entries::values( $entry_id ) as $row ) {
			$field = Form_Schema::field( $fields, $row['field_name'] );
			echo '<tr><th scope="row">' . esc_html( $row['label'] ) . ' <code>' . esc_html( $row['field_name'] ) . '</code></th><td>';
			if ( 'file' === $row['type'] ) {
				$list = $files[ $row['field_name'] ] ?? array();
				if ( ! $list ) {
					echo esc_html__( 'The file is no longer available.', 'cradle-forms' );
				}
				echo '<ul class="cradle-forms-files">';
				foreach ( $list as $file ) {
					echo '<li><a href="' . esc_url( self::download_url( (int) $file['id'] ) ) . '">' . esc_html( $file['original_name'] ) . '</a> <span class="description">(' . esc_html( size_format( (int) $file['size'] ) . ', ' . $file['mime'] ) . ')</span></li>';
				}
				echo '</ul>';
			} else {
				echo '<div class="cradle-forms-value">' . nl2br( esc_html( Field_Types::display( $row['type'], $row['value'], $field && $field['type'] === $row['type'] ? $field : null ) ) ) . '</div>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		if ( $entry['consent'] ) {
			echo '<h2>' . esc_html__( 'Consent records', 'cradle-forms' ) . '</h2><table class="widefat striped cradle-forms-consent"><thead><tr><th scope="col">' . esc_html__( 'Text shown', 'cradle-forms' ) . '</th><th scope="col">' . esc_html__( 'Text version (SHA-256)', 'cradle-forms' ) . '</th><th scope="col">' . esc_html__( 'Language', 'cradle-forms' ) . '</th><th scope="col">' . esc_html__( 'Given at (UTC)', 'cradle-forms' ) . '</th></tr></thead><tbody>';
			foreach ( $entry['consent'] as $record ) {
				echo '<tr><td>' . wp_kses( (string) ( $record['text'] ?? '' ), Field_Types::consent_tags() ) . '</td><td><code>' . esc_html( (string) ( $record['hash'] ?? '' ) ) . '</code></td><td>' . esc_html( trim( (string) ( $record['language'] ?? '' ) . ' ' . (string) ( $record['locale'] ?? '' ) ) ) . '</td><td>' . esc_html( (string) ( $record['given_at'] ?? '' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<div class="cradle-forms-entry-actions">';
		foreach ( array(
			'read' => __( 'Mark as read', 'cradle-forms' ),
			'new'  => __( 'Mark as new', 'cradle-forms' ),
			'spam' => __( 'Mark as spam', 'cradle-forms' ),
		) as $op => $label ) {
			if ( $op === $entry['status'] ) {
				continue;
			}
			$this->entry_action( $entry, $op, $label, 'secondary' );
		}
		if ( current_user_can( Caps::DELETE ) ) {
			$this->entry_action( $entry, 'delete', __( 'Delete permanently', 'cradle-forms' ), 'delete', __( 'Delete this entry and its files permanently?', 'cradle-forms' ) );
		}
		echo '</div>';
	}

	private function entry_action( array $entry, string $op, string $label, string $class, string $confirm = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cradle-inline-form"' . ( '' !== $confirm ? ' data-cradle-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="cradle_forms_entries"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="form" value="' . esc_attr( (string) $entry['form_id'] ) . '"><input type="hidden" name="entries[]" value="' . esc_attr( (string) $entry['id'] ) . '"><input type="hidden" name="return" value="entry">';
		wp_nonce_field( 'cradle_forms_entries' );
		submit_button( $label, $class, '', false );
		echo '</form> ';
	}

	private function settings() {
		if ( ! current_user_can( Caps::MANAGE ) ) {
			self::deny();
		}
		echo '<h1>' . esc_html__( 'Forms', 'cradle-forms' ) . '</h1>';
		$this->tabs( 'settings' );
		$protected = Uploads::storage_protected();
		echo '<div class="notice ' . ( $protected ? 'notice-info' : 'notice-warning' ) . ' inline"><p>' . esc_html(
			$protected
				? __( 'Private file storage is protected: uploaded files cannot be opened by their address.', 'cradle-forms' )
				: __( 'Private file storage is not proven protected, so file uploads are refused. Define CRADLE_PRIVATE_DIR outside the web root in wp-config.php, or make the server deny access to the private uploads folder.', 'cradle-forms' )
		) . '</p></div>';
		if ( function_exists( 'cradle' ) && cradle() ) {
			cradle()->settings()->render_form( Settings_Section::ID );
		}
	}
}
