<?php
/**
 * Cradle → Content types: menu, screen routing and every state-changing handler.
 *
 * All mutations go through admin-post.php with POST, a per-action nonce and the
 * `cradle_manage_content_types` capability. Screens only read; previews (rename,
 * delete, import) never write.
 *
 * @package Cradle\Content
 */

namespace Cradle\Content\Admin;

use Cradle\Content\Definitions;
use Cradle\Content\Field_Types;
use Cradle\Content\Migrations;
use Cradle\Content\Module;
use Cradle\Content\Transfer;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const PAGE = 'cradle-content';

	const TABS = array( 'post_type', 'taxonomy', 'field_group', 'tools', 'migrations', 'settings' );

	/** @var string Hook suffix of the screen. */
	private static $hook = '';

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		foreach ( array( 'save', 'toggle', 'delete', 'rename', 'fork', 'export', 'import', 'import_apply', 'confirm_fields' ) as $action ) {
			add_action( 'admin_post_cradle_content_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	public function menu() {
		self::$hook = (string) add_submenu_page(
			'cradle',
			__( 'Content types', 'cradle-content' ),
			__( 'Content types', 'cradle-content' ),
			Module::CAP,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	public function enqueue( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'cradle-admin' );
		wp_enqueue_script( 'cradle-admin' );
		wp_enqueue_style( 'cradle-content-admin', plugins_url( 'assets/css/admin.css', CRADLE_CONTENT_FILE ), array( 'dashicons' ), self::asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script(
			'cradle-content-admin',
			plugins_url( 'assets/js/admin.js', CRADLE_CONTENT_FILE ),
			array(),
			self::asset_version( 'assets/js/admin.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	public static function asset_version( string $relative ): string {
		$path = CRADLE_CONTENT_DIR . $relative;
		return CRADLE_CONTENT_VERSION . ( is_file( $path ) ? '.' . filemtime( $path ) : '' );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/* ---------------------------------------------------------------------
	 * Screen
	 * ------------------------------------------------------------------- */

	public function render() {
		if ( ! current_user_can( Module::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to manage content types.', 'cradle-content' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'post_type';
		$tab    = in_array( $tab, self::TABS, true ) ? $tab : 'post_type';
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$key    = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap cradle-wrap cradle-content">';
		echo '<h1>' . esc_html__( 'Content types', 'cradle-content' ) . '</h1>';
		self::notices();
		$this->tabs( $tab );

		if ( in_array( $tab, Definitions::KINDS, true ) ) {
			switch ( $action ) {
				case 'new':
				case 'edit':
					Forms::render( $tab, 'edit' === $action ? $key : '' );
					break;
				case 'rename':
					Views::rename( $tab, $key );
					break;
				case 'delete':
					Views::delete( $tab, $key );
					break;
				case 'fork':
					Views::fork( $tab, $key );
					break;
				case 'confirm':
					Views::confirm_fields();
					break;
				default:
					Views::index( $tab );
			}
		} elseif ( 'tools' === $tab ) {
			Views::tools();
		} elseif ( 'migrations' === $tab ) {
			Views::migrations();
		} else {
			echo '<h2>' . esc_html__( 'Settings', 'cradle-content' ) . '</h2>';
			cradle()->settings()->render_form( \Cradle\Content\Settings_Section::ID );
		}
		echo '</div>';
	}

	private function tabs( string $current ) {
		$labels = array(
			'post_type'   => __( 'Post types', 'cradle-content' ),
			'taxonomy'    => __( 'Taxonomies', 'cradle-content' ),
			'field_group' => __( 'Field groups', 'cradle-content' ),
			'tools'       => __( 'Export / import', 'cradle-content' ),
			'migrations'  => __( 'Migrations', 'cradle-content' ),
			'settings'    => __( 'Settings', 'cradle-content' ),
		);
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Content types sections', 'cradle-content' ) . '">';
		foreach ( $labels as $tab => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( self::url( array( 'tab' => $tab ) ) ),
				$tab === $current ? ' nav-tab-active' : '',
				$tab === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/* ---------------------------------------------------------------------
	 * Notices and form state (per user, short-lived)
	 * ------------------------------------------------------------------- */

	public static function add_notice( string $type, string $message ) {
		$key     = 'cradle_content_notice_' . get_current_user_id();
		$notices = get_transient( $key );
		$notices = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'type'    => in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info',
			'message' => $message,
		);
		set_transient( $key, array_slice( $notices, -10 ), 5 * MINUTE_IN_SECONDS );
	}

	private static function notices() {
		$key     = 'cradle_content_notice_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notices as $notice ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );
		}
	}

	public static function set_form_state( array $state ) {
		set_transient( 'cradle_content_form_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS );
	}

	public static function take_form_state( string $kind, string $original ) {
		$key   = 'cradle_content_form_' . get_current_user_id();
		$state = get_transient( $key );
		if ( ! is_array( $state ) || ( $state['kind'] ?? '' ) !== $kind || ( $state['original'] ?? '' ) !== $original ) {
			return null;
		}
		delete_transient( $key );
		return $state;
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------- */

	/**
	 * Common gate: POST only, valid nonce for the action, capability.
	 */
	private static function guard( string $nonce_action ) {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'This action requires a form submission.', 'cradle-content' ), 405 );
		}
		check_admin_referer( $nonce_action );
		if ( ! current_user_can( Module::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to manage content types.', 'cradle-content' ), 403 );
		}
	}

	private static function post_kind(): string {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'This action requires a form submission.', 'cradle-content' ), 405 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by guard() with a kind-specific action.
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		if ( ! in_array( $kind, Definitions::KINDS, true ) ) {
			wp_die( esc_html__( 'Unknown definition kind.', 'cradle-content' ), 400 );
		}
		return $kind;
	}

	private static function post_key( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers run guard() first.
		$value = isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
		return preg_match( '/^[a-z0-9_-]{0,40}$/', $value ) ? $value : '';
	}

	private static function redirect( string $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Converts form rows into definition input (only structure; validation happens in the Validator).
	 */
	public static function normalize_form_input( string $kind, array $input ): array {
		if ( 'field_group' === $kind && ( isset( $input['post_targets'] ) || isset( $input['term_targets'] ) ) ) {
			$input['targets'] = 'term' === ( $input['object'] ?? 'post' ) ? ( $input['term_targets'] ?? array() ) : ( $input['post_targets'] ?? array() );
			unset( $input['post_targets'], $input['term_targets'] );
		}
		if ( 'field_group' === $kind && isset( $input['fields'] ) && is_array( $input['fields'] ) ) {
			$fields = array();
			foreach ( $input['fields'] as $index => $row ) {
				if ( '__i__' === (string) $index || ! is_array( $row ) ) {
					continue;
				}
				$type = is_string( $row['type'] ?? null ) ? $row['type'] : 'text';
				if ( 'post_ref' === $type ) {
					$row['targets'] = $row['post_targets'] ?? array();
				} elseif ( 'term_ref' === $type ) {
					$row['targets'] = $row['term_targets'] ?? array();
				} elseif ( 'user_ref' === $type ) {
					$row['targets'] = $row['role_targets'] ?? array();
				}
				unset( $row['post_targets'], $row['term_targets'], $row['role_targets'] );
				$fields[] = $row;
			}
			$input['fields'] = $fields;
		}
		if ( 'post_type' === $kind && isset( $input['template'] ) && is_array( $input['template'] ) ) {
			$rows = array();
			foreach ( $input['template'] as $index => $row ) {
				if ( '__i__' !== (string) $index && is_array( $row ) ) {
					$rows[] = $row;
				}
			}
			$input['template'] = $rows;
		}
		return $input;
	}

	public function handle_save() {
		$kind = self::post_kind();
		self::guard( 'cradle_content_save_' . $kind );
		$original = self::post_key( 'original' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$revision = isset( $_POST['revision'] ) && '' !== $_POST['revision'] ? (int) $_POST['revision'] : null;
		$input    = isset( $_POST['def'] ) && is_array( $_POST['def'] ) ? wp_unslash( $_POST['def'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated field by field in Validator.
		// phpcs:enable
		$input = self::normalize_form_input( $kind, $input );

		// Field storage changes (renamed or removed fields holding data) need an explicit preview first,
		// but only for input that is otherwise valid.
		if ( 'field_group' === $kind && '' !== $original && isset( Definitions::instance()->db( $kind )[ $original ] ) ) {
			$check = \Cradle\Content\Validator::validate( $kind, $input, Definitions::instance()->context( $kind, $original ) );
			if ( $check['errors'] ) {
				self::save_failed(
					$kind,
					$original,
					$input,
					new \WP_Error(
						'cradle_content_invalid',
						__( 'The definition was not saved. Correct the highlighted fields.', 'cradle-content' ),
						array(
							'errors'   => $check['errors'],
							'warnings' => $check['warnings'],
						)
					)
				);
			}
			$impact = Views::field_storage_impact( $original, $input );
			if ( $impact['changes'] ) {
				$token = wp_generate_password( 20, false );
				set_transient(
					'cradle_content_pending_' . get_current_user_id(),
					array(
						'token'    => $token,
						'original' => $original,
						'revision' => $revision,
						'input'    => $input,
						'impact'   => $impact,
					),
					30 * MINUTE_IN_SECONDS
				);
				self::redirect(
					self::url(
						array(
							'tab'    => 'field_group',
							'action' => 'confirm',
							'token'  => $token,
						)
					)
				);
			}
		}

		$result = Definitions::instance()->save( $kind, $input, $original, $revision );
		if ( is_wp_error( $result ) ) {
			self::save_failed( $kind, $original, $input, $result );
		}
		foreach ( $result['warnings'] as $warning ) {
			self::add_notice( 'warning', $warning );
		}
		/* translators: %s: definition key */
		self::add_notice( 'success', sprintf( __( 'Saved "%s".', 'cradle-content' ), $result['definition']['key'] ) );
		self::redirect(
			self::url(
				array(
					'tab'   => $kind,
					'saved' => $result['definition']['key'],
				)
			)
		);
	}

	private static function save_failed( string $kind, string $original, array $input, \WP_Error $error ) {
		$data = (array) $error->get_error_data();
		self::set_form_state(
			array(
				'kind'     => $kind,
				'original' => $original,
				'input'    => $input,
				'errors'   => (array) ( $data['errors'] ?? array() ),
				'warnings' => (array) ( $data['warnings'] ?? array() ),
			)
		);
		self::add_notice( 'error', $error->get_error_message() );
		self::redirect(
			self::url(
				array(
					'tab'    => $kind,
					'action' => '' === $original ? 'new' : 'edit',
					'key'    => $original,
					'error'  => 1,
				)
			)
		);
	}

	public function handle_confirm_fields() {
		self::guard( 'cradle_content_confirm_fields' );
		$pending = get_transient( 'cradle_content_pending_' . get_current_user_id() );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$choices = isset( $_POST['storage'] ) && is_array( $_POST['storage'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['storage'] ) ) : array();
		// phpcs:enable
		if ( ! is_array( $pending ) || ! hash_equals( (string) $pending['token'], $token ) ) {
			self::add_notice( 'error', __( 'This confirmation expired. Save the field group again.', 'cradle-content' ) );
			self::redirect( self::url( array( 'tab' => 'field_group' ) ) );
		}
		delete_transient( 'cradle_content_pending_' . get_current_user_id() );
		$original = $pending['original'];
		$result   = Definitions::instance()->save( 'field_group', $pending['input'], $original, $pending['revision'] );
		if ( is_wp_error( $result ) ) {
			self::save_failed( 'field_group', $original, $pending['input'], $result );
		}
		$ops = array();
		foreach ( $pending['impact']['changes'] as $change ) {
			$choice = $choices[ $change['from'] ] ?? 'keep';
			if ( 'rename' === $change['type'] && 'migrate' === $choice ) {
				$ops[] = array(
					'op'       => 'meta_rename',
					'object'   => $pending['impact']['object'],
					'from'     => Field_Types::meta_key( $change['from'] ),
					'to'       => Field_Types::meta_key( $change['to'] ),
					'subtypes' => $pending['impact']['targets'],
				);
			} elseif ( 'remove' === $change['type'] && 'delete' === $choice ) {
				$ops[] = array(
					'op'       => 'meta_delete',
					'object'   => $pending['impact']['object'],
					'key'      => Field_Types::meta_key( $change['from'] ),
					'subtypes' => $pending['impact']['targets'],
				);
			}
		}
		/* translators: %s: field group key */
		self::add_notice( 'success', sprintf( __( 'Saved "%s".', 'cradle-content' ), $original ) );
		if ( $ops ) {
			/* translators: %s: field group key */
			$job = Migrations::start( $ops, sprintf( __( 'Field storage changes for "%s"', 'cradle-content' ), $original ) );
			self::after_job( $job );
		}
		self::redirect( self::url( array( 'tab' => 'field_group' ) ) );
	}

	/**
	 * Redirects to the migrations tab for a started job, or reports why it could not start.
	 *
	 * @param array|\WP_Error $job Job.
	 */
	private static function after_job( $job ) {
		if ( is_wp_error( $job ) ) {
			self::add_notice( 'error', $job->get_error_message() );
			return;
		}
		if ( 'completed' === $job['state'] ) {
			self::add_notice( 'success', __( 'Stored content was migrated.', 'cradle-content' ) );
			return;
		}
		self::add_notice( 'info', __( 'Stored content is being migrated in batches. Keep this tab open to follow progress; the migration also continues in the background.', 'cradle-content' ) );
		self::redirect(
			self::url(
				array(
					'tab' => 'migrations',
					'job' => $job['uuid'],
				)
			)
		);
	}

	public function handle_toggle() {
		$kind = self::post_kind();
		self::guard( 'cradle_content_toggle_' . $kind );
		$key = self::post_key( 'key' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$enabled = isset( $_POST['enabled'] ) && '1' === $_POST['enabled'];
		$result  = Definitions::instance()->set_enabled( $kind, $key, $enabled );
		if ( is_wp_error( $result ) ) {
			self::add_notice( 'error', $result->get_error_message() );
		} elseif ( $enabled ) {
			/* translators: %s: key */
			self::add_notice( 'success', sprintf( __( '"%s" is enabled again. Its stored content is accessible.', 'cradle-content' ), $key ) );
		} else {
			/* translators: %s: key */
			self::add_notice( 'success', sprintf( __( '"%s" is disabled. Nothing was deleted: its content stays in the database and returns when you enable it again.', 'cradle-content' ), $key ) );
		}
		self::redirect( self::url( array( 'tab' => $kind ) ) );
	}

	public function handle_delete() {
		$kind = self::post_kind();
		self::guard( 'cradle_content_delete_' . $kind );
		$key  = self::post_key( 'key' );
		$defs = Definitions::instance();
		$def  = $defs->db( $kind )[ $key ] ?? null;
		if ( ! $def ) {
			self::add_notice( 'error', __( 'Only database definitions can be deleted here.', 'cradle-content' ) );
			self::redirect( self::url( array( 'tab' => $kind ) ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$content    = isset( $_POST['content'] ) ? sanitize_key( wp_unslash( $_POST['content'] ) ) : 'keep';
		$convert_to = self::post_key( 'convert_to' );
		$confirmed  = isset( $_POST['confirm'] ) && '1' === $_POST['confirm'];
		// phpcs:enable
		if ( ! $confirmed ) {
			self::add_notice( 'error', __( 'Confirm the deletion by ticking the confirmation box.', 'cradle-content' ) );
			self::redirect(
				self::url(
					array(
						'tab'    => $kind,
						'action' => 'delete',
						'key'    => $key,
					)
				)
			);
		}
		$ops = array();
		if ( 'post_type' === $kind && 'convert' === $content ) {
			if ( '' === $convert_to || $convert_to === $key || ! post_type_exists( $convert_to ) || in_array( $convert_to, \Cradle\Content\Validator::INTERNAL_POST_TYPES, true ) || 'attachment' === $convert_to ) {
				self::add_notice( 'error', __( 'Choose an existing post type to convert the content to.', 'cradle-content' ) );
				self::redirect(
					self::url(
						array(
							'tab'    => $kind,
							'action' => 'delete',
							'key'    => $key,
						)
					)
				);
			}
			$ops[] = array(
				'op'   => 'post_type',
				'from' => $key,
				'to'   => $convert_to,
			);
			$ops[] = array(
				'op'   => 'menus',
				'from' => $key,
				'to'   => $convert_to,
			);
		} elseif ( 'field_group' === $kind && 'delete' === $content ) {
			foreach ( Field_Types::compile( $def ) as $field ) {
				$ops[] = array(
					'op'       => 'meta_delete',
					'object'   => $def['object'],
					'key'      => $field['meta_key'],
					'subtypes' => (array) $def['targets'],
				);
			}
		}
		$result = $defs->delete( $kind, $key );
		if ( is_wp_error( $result ) ) {
			self::add_notice( 'error', $result->get_error_message() );
			self::redirect( self::url( array( 'tab' => $kind ) ) );
		}
		/* translators: %s: key */
		self::add_notice( 'success', sprintf( __( 'Definition "%s" deleted.', 'cradle-content' ), $key ) );
		if ( $ops ) {
			/* translators: %s: key */
			self::after_job( Migrations::start( $ops, sprintf( __( 'Content of deleted definition "%s"', 'cradle-content' ), $key ) ) );
		} elseif ( 'field_group' !== $kind ) {
			self::add_notice( 'info', __( 'Its content was kept in the database. Create a definition with the same key (or import one) to make it accessible again.', 'cradle-content' ) );
		}
		self::redirect( self::url( array( 'tab' => $kind ) ) );
	}

	public function handle_rename() {
		$kind = self::post_kind();
		self::guard( 'cradle_content_rename_' . $kind );
		$from = self::post_key( 'key' );
		$to   = strtolower( self::post_key( 'new_key' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$keep_urls = isset( $_POST['keep_urls'] ) && '1' === $_POST['keep_urls'];
		$result    = Definitions::instance()->rename( $kind, $from, $to, array( 'keep_urls' => $keep_urls ) );
		if ( is_wp_error( $result ) ) {
			self::add_notice( 'error', $result->get_error_message() );
			self::redirect(
				self::url(
					array(
						'tab'     => $kind,
						'action'  => 'rename',
						'key'     => $from,
						'new_key' => $to,
					)
				)
			);
		}
		/* translators: 1: old key, 2: new key */
		self::add_notice( 'success', sprintf( __( 'Key "%1$s" renamed to "%2$s".', 'cradle-content' ), $from, $to ) );
		$job = Migrations::start(
			array(
				array(
					'op'   => $kind,
					'from' => $from,
					'to'   => $to,
				),
				array(
					'op'   => 'menus',
					'from' => $from,
					'to'   => $to,
				),
			),
			/* translators: 1: old key, 2: new key */
			sprintf( __( 'Rename "%1$s" to "%2$s"', 'cradle-content' ), $from, $to )
		);
		self::after_job( $job );
		self::redirect( self::url( array( 'tab' => $kind ) ) );
	}

	public function handle_fork() {
		$kind = self::post_kind();
		self::guard( 'cradle_content_fork_' . $kind );
		$key     = self::post_key( 'key' );
		$new_key = strtolower( self::post_key( 'new_key' ) );
		$result  = Definitions::instance()->fork( $kind, $key, '' !== $new_key ? $new_key : $key );
		if ( is_wp_error( $result ) ) {
			self::add_notice( 'error', Transfer::error_text( $result ) );
			self::redirect(
				self::url(
					array(
						'tab'    => $kind,
						'action' => 'fork',
						'key'    => $key,
					)
				)
			);
		}
		self::add_notice(
			'success',
			$new_key === $key || '' === $new_key
				? __( 'Database copy created. It stays inactive while the code definition exists; remove the code definition to hand ownership to the database copy.', 'cradle-content' )
				: __( 'Independent database copy created under the new key.', 'cradle-content' )
		);
		self::redirect( self::url( array( 'tab' => $kind ) ) );
	}

	public function handle_export() {
		self::guard( 'cradle_content_export' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$selection = isset( $_POST['selection'] ) && is_array( $_POST['selection'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['selection'] ) ) ) ) : array();
		$document  = Transfer::export( $selection ? $selection : null );
		cradle_log_activity( Module::ID, 'definitions_exported', sprintf( 'Exported %d definitions.', array_sum( array_map( 'count', $document['definitions'] ) ) ) );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="cradle-content-definitions-' . gmdate( 'Ymd-His' ) . '.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	public function handle_import() {
		self::guard( 'cradle_content_import' );
		$json = '';
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		if ( isset( $_FILES['cradle_content_file'] ) && is_array( $_FILES['cradle_content_file'] ) && UPLOAD_ERR_NO_FILE !== (int) ( $_FILES['cradle_content_file']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$file = $_FILES['cradle_content_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only tmp_name/size/error are used, checked below.
			if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) || (int) $file['size'] > Transfer::MAX_BYTES || filesize( $file['tmp_name'] ) > Transfer::MAX_BYTES ) {
				self::add_notice( 'error', __( 'The upload failed or the file is larger than 1 MB.', 'cradle-content' ) );
				self::redirect( self::url( array( 'tab' => 'tools' ) ) );
			}
			$json = (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local uploaded temp file.
		} elseif ( isset( $_POST['json'] ) && is_string( $_POST['json'] ) ) {
			$json = trim( wp_unslash( $_POST['json'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded as JSON and validated.
		}
		// phpcs:enable
		if ( '' === $json ) {
			self::add_notice( 'error', __( 'Choose an export file or paste its JSON.', 'cradle-content' ) );
			self::redirect( self::url( array( 'tab' => 'tools' ) ) );
		}
		$payload = Transfer::decode( $json );
		$preview = is_wp_error( $payload ) ? $payload : Transfer::preview( $payload );
		if ( is_wp_error( $preview ) ) {
			self::add_notice( 'error', $preview->get_error_message() );
			self::redirect( self::url( array( 'tab' => 'tools' ) ) );
		}
		$token = wp_generate_password( 20, false );
		set_transient(
			'cradle_content_import_' . get_current_user_id(),
			array(
				'token'   => $token,
				'preview' => $preview,
			),
			HOUR_IN_SECONDS
		);
		self::redirect(
			self::url(
				array(
					'tab'    => 'tools',
					'import' => $token,
				)
			)
		);
	}

	public function handle_import_apply() {
		self::guard( 'cradle_content_import_apply' );
		$stored = get_transient( 'cradle_content_import_' . get_current_user_id() );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$token     = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$overwrite = isset( $_POST['overwrite'] ) && is_array( $_POST['overwrite'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['overwrite'] ) ) : array();
		// phpcs:enable
		if ( ! is_array( $stored ) || ! hash_equals( (string) $stored['token'], $token ) ) {
			self::add_notice( 'error', __( 'This import preview expired. Upload the file again.', 'cradle-content' ) );
			self::redirect( self::url( array( 'tab' => 'tools' ) ) );
		}
		delete_transient( 'cradle_content_import_' . get_current_user_id() );
		$report = Transfer::apply( $stored['preview'], $overwrite );
		self::add_notice(
			$report['failed'] ? 'warning' : 'success',
			/* translators: 1: created, 2: updated, 3: skipped, 4: failed */
			sprintf( __( 'Import finished: %1$d created, %2$d updated, %3$d skipped, %4$d failed.', 'cradle-content' ), count( $report['created'] ), count( $report['updated'] ), count( $report['skipped'] ), count( $report['failed'] ) )
		);
		foreach ( $report['failed'] as $id => $message ) {
			self::add_notice( 'error', $id . ': ' . $message );
		}
		self::redirect( self::url( array( 'tab' => 'tools' ) ) );
	}
}
