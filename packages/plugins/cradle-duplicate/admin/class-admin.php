<?php
/**
 * Admin integration: list row actions, classic/block editor entry points, the
 * no-JS confirmation screen and its protected POST handler.
 *
 * GET requests never duplicate. The confirmation screen (GET) only shows what
 * will happen; its form POSTs to admin-post.php with an item-specific nonce.
 * The one-click JavaScript path POSTs to the REST endpoint with the REST nonce.
 * Both paths run the same object-level authorization in Policy::check().
 *
 * @package Cradle\Duplicate
 */

namespace Cradle\Duplicate\Admin;

use Cradle\Duplicate\Duplicator;
use Cradle\Duplicate\Policy;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const CONFIRM_PAGE = 'cradle-duplicate-confirm';
	const POST_ACTION  = 'cradle_duplicate';

	public function hooks() {
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_' . self::POST_ACTION, array( $this, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor' ) );
		add_action( 'post_submitbox_misc_actions', array( $this, 'classic_submitbox' ) );
		add_action( 'admin_notices', array( $this, 'classic_notice' ) );
	}

	public static function confirm_url( int $post_id ): string {
		return add_query_arg(
			array(
				'page' => self::CONFIRM_PAGE,
				'post' => $post_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Adds "Duplicate" to list rows the current user may duplicate.
	 *
	 * @param array    $actions Row actions.
	 * @param \WP_Post $post    Row item.
	 */
	public function row_actions( $actions, $post ) {
		if ( ! $post instanceof \WP_Post || true !== Policy::check( $post ) ) {
			return $actions;
		}
		$actions['cradle_duplicate'] = sprintf(
			'<a href="%1$s" data-cradle-duplicate="%2$d" aria-label="%3$s">%4$s</a>',
			esc_url( self::confirm_url( $post->ID ) ),
			(int) $post->ID,
			/* translators: %s: item title */
			esc_attr( sprintf( __( 'Duplicate “%s” as a new draft', 'cradle-duplicate' ), wp_strip_all_tags( get_the_title( $post ) ) ?: __( '(no title)', 'cradle-duplicate' ) ) ),
			esc_html__( 'Duplicate', 'cradle-duplicate' )
		);
		return $actions;
	}

	public function menu() {
		// Hidden screen (no menu entry). Real authorization happens per item in page_confirm().
		$hook = add_submenu_page( '', __( 'Duplicate item', 'cradle-duplicate' ), __( 'Duplicate item', 'cradle-duplicate' ), 'read', self::CONFIRM_PAGE, array( $this, 'page_confirm' ) );
		if ( $hook ) {
			// Hidden pages have no menu title for admin-header.php; provide the document title.
			add_action(
				'load-' . $hook,
				static function () {
					$GLOBALS['title'] = __( 'Duplicate item', 'cradle-duplicate' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				}
			);
		}
	}

	/**
	 * Confirmation screen for the no-JavaScript path. Read-only: shows the plan and a POST form.
	 */
	public function page_confirm() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen.
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		$check   = Policy::check( $post_id );
		if ( is_wp_error( $check ) ) {
			wp_die( esc_html( $check->get_error_message() ), esc_html__( 'Cannot duplicate', 'cradle-duplicate' ), array( 'response' => (int) ( $check->get_error_data()['status'] ?? 403 ), 'back_link' => true ) );
		}
		$post    = get_post( $post_id );
		$preview = Duplicator::preview( $post );
		$type    = get_post_type_object( $post->post_type );
		$author  = get_userdata( $preview['author'] );
		$back    = admin_url( 'edit.php' . ( 'post' === $post->post_type ? '' : '?post_type=' . rawurlencode( $post->post_type ) ) );
		?>
		<div class="wrap cradle-wrap cradle-duplicate-confirm">
			<h1>
			<?php
			/* translators: %s: item title */
			echo esc_html( sprintf( __( 'Duplicate “%s”', 'cradle-duplicate' ), wp_strip_all_tags( get_the_title( $post ) ) ?: __( '(no title)', 'cradle-duplicate' ) ) );
			?>
			</h1>
			<p><?php esc_html_e( 'A new draft will be created from the saved version of this item. The original is not changed.', 'cradle-duplicate' ); ?></p>
			<table class="widefat striped cradle-table">
				<tbody>
					<tr><th scope="row"><?php esc_html_e( 'New title', 'cradle-duplicate' ); ?></th><td><?php echo esc_html( $preview['title'] ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Type and status', 'cradle-duplicate' ); ?></th><td><?php echo esc_html( sprintf( '%1$s — %2$s', $type ? $type->labels->singular_name : $post->post_type, __( 'Draft', 'cradle-duplicate' ) ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Author', 'cradle-duplicate' ); ?></th><td><?php echo esc_html( $author ? $author->display_name : '—' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Dates and slug', 'cradle-duplicate' ); ?></th><td><?php esc_html_e( 'Current date; a unique slug is assigned.', 'cradle-duplicate' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Featured image', 'cradle-duplicate' ); ?></th><td><?php echo $preview['featured_image'] ? esc_html__( 'The same image is referenced (the media file is not copied).', 'cradle-duplicate' ) : '—'; ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Template', 'cradle-duplicate' ); ?></th><td><?php echo '' !== $preview['template'] ? '<code>' . esc_html( $preview['template'] ) . '</code>' : esc_html__( 'Default', 'cradle-duplicate' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Terms', 'cradle-duplicate' ); ?></th><td>
					<?php
					$parts = array();
					foreach ( $preview['terms'] as $taxonomy => $count ) {
						$tax     = get_taxonomy( $taxonomy );
						$parts[] = sprintf( '%1$s: %2$d', $tax ? $tax->labels->name : $taxonomy, $count );
					}
					echo esc_html( $parts ? implode( ', ', $parts ) : '—' );
					if ( $preview['taxonomies_skipped'] ) {
						echo '<br /><small>' . esc_html__( 'Some taxonomies are skipped because you cannot assign their terms.', 'cradle-duplicate' ) . '</small>';
					}
					?>
					</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Metadata', 'cradle-duplicate' ); ?></th><td>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: copied field count, 2: skipped field count */
							__( '%1$d field(s) copied, %2$d skipped (locks, revision bookkeeping, ownership markers, secrets, order/payment data and private plugin data).', 'cradle-duplicate' ),
							count( $preview['meta_copied'] ),
							count( $preview['meta_skipped'] )
						)
					);
					?>
					</td></tr>
				</tbody>
			</table>
			<?php if ( $preview['synced_patterns'] ) : ?>
				<div class="notice notice-info inline"><p><?php echo esc_html( self::synced_pattern_text( $preview['synced_patterns'] ) ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::POST_ACTION ); ?>" />
				<input type="hidden" name="post" value="<?php echo esc_attr( (string) $post->ID ); ?>" />
				<?php wp_nonce_field( 'cradle_duplicate_post_' . $post->ID ); ?>
				<p class="submit">
					<?php submit_button( __( 'Create draft copy', 'cradle-duplicate' ), 'primary', 'submit', false ); ?>
					<a class="button" href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'Cancel', 'cradle-duplicate' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	public static function synced_pattern_text( int $count ): string {
		return sprintf(
			/* translators: %d: number of synced pattern references */
			_n(
				'This content uses %d synced pattern. The copy keeps referencing the same synced pattern: editing it changes every place that uses it, including the original. To change it only in the copy, use “Detach” on that pattern in the editor.',
				'This content uses %d synced patterns. The copy keeps referencing the same synced patterns: editing one changes every place that uses it, including the original. To change one only in the copy, use “Detach” on that pattern in the editor.',
				$count,
				'cradle-duplicate'
			),
			$count
		);
	}

	/**
	 * admin-post.php?action=cradle_duplicate — the protected mutation of the no-JS path.
	 */
	public function handle_post() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' !== $method ) {
			// GET is never authorization: show the confirmation screen instead of mutating.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- no state change.
			$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
			wp_safe_redirect( self::confirm_url( $post_id ) );
			exit;
		}
		$post_id = isset( $_POST['post'] ) ? absint( wp_unslash( $_POST['post'] ) ) : 0;
		check_admin_referer( 'cradle_duplicate_post_' . $post_id );
		$result = Duplicator::duplicate( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), esc_html__( 'Duplication failed', 'cradle-duplicate' ), array( 'response' => (int) ( $result->get_error_data()['status'] ?? 403 ), 'back_link' => true ) );
		}
		wp_safe_redirect( $result['edit_link'] );
		exit;
	}

	/**
	 * @param string $hook Admin page hook.
	 */
	public function enqueue( $hook ) {
		$screen = get_current_screen();
		$list   = 'edit.php' === $hook && $screen && Policy::is_supported_type( (string) $screen->post_type );
		$classic = in_array( $hook, array( 'post.php' ), true ) && $screen && ! $screen->is_block_editor() && Policy::is_supported_type( (string) $screen->post_type );
		if ( ! $list && ! $classic && 'admin_page_' . self::CONFIRM_PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'cradle-admin' );
		if ( $list || $classic ) {
			\Cradle\Core\Assets::register_script( 'cradle-duplicate-list', CRADLE_DUPLICATE_FILE, 'assets/js/duplicate-list.js', array( 'wp-api-fetch', 'wp-i18n', 'wp-a11y' ), CRADLE_DUPLICATE_VERSION );
			wp_set_script_translations( 'cradle-duplicate-list', 'cradle-duplicate', CRADLE_DUPLICATE_DIR . 'languages' );
			wp_enqueue_script( 'cradle-duplicate-list' );
			\Cradle\Core\Assets::register_style( 'cradle-duplicate-admin', CRADLE_DUPLICATE_FILE, 'assets/css/duplicate-admin.css', array(), CRADLE_DUPLICATE_VERSION );
			wp_enqueue_style( 'cradle-duplicate-admin' );
		}
	}

	public function enqueue_editor() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post   = get_post();
		if ( ! $screen || 'post' !== $screen->base || ! $post instanceof \WP_Post || ! Policy::is_supported_type( $post->post_type ) ) {
			return;
		}
		$check = Policy::check( $post );
		// A brand-new item (auto-draft) becomes duplicable once saved; the button stays disabled until then.
		$can = true === $check || ( is_wp_error( $check ) && 'cradle_duplicate_status' === $check->get_error_code() && 'auto-draft' === $post->post_status );
		if ( ! $can ) {
			return;
		}
		\Cradle\Core\Assets::register_script(
			'cradle-duplicate-editor',
			CRADLE_DUPLICATE_FILE,
			'assets/js/duplicate-editor.js',
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-notices', 'wp-dom-ready' ),
			CRADLE_DUPLICATE_VERSION
		);
		wp_set_script_translations( 'cradle-duplicate-editor', 'cradle-duplicate', CRADLE_DUPLICATE_DIR . 'languages' );

		$source_id = (int) get_post_meta( $post->ID, Duplicator::SOURCE_META, true );
		$source    = $source_id ? get_post( $source_id ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		$just = isset( $_GET['cradle_duplicated'] ) && $source && current_user_can( 'read_post', $source_id );
		wp_add_inline_script(
			'cradle-duplicate-editor',
			'window.cradleDuplicateEditor = ' . wp_json_encode(
				array(
					'postId'         => (int) $post->ID,
					'syncedPatterns' => Duplicator::count_synced_patterns( $post->post_content ),
					'syncedText'     => self::synced_pattern_text( max( 1, Duplicator::count_synced_patterns( $post->post_content ) ) ),
					'justDuplicated' => (bool) $just,
					'sourceTitle'    => $just ? wp_strip_all_tags( get_the_title( $source ) ) : '',
					'sourceLink'     => $just && current_user_can( 'edit_post', $source_id ) ? (string) get_edit_post_link( $source_id, 'raw' ) : '',
				)
			) . ';',
			'before'
		);
		wp_enqueue_script( 'cradle-duplicate-editor' );
	}

	/**
	 * Classic editor: link in the Publish box (a form cannot be nested in the post form).
	 *
	 * @param \WP_Post $post Current item.
	 */
	public function classic_submitbox( $post ) {
		if ( ! $post instanceof \WP_Post || true !== Policy::check( $post ) ) {
			return;
		}
		printf(
			'<div class="misc-pub-section cradle-duplicate-misc"><a href="%1$s" data-cradle-duplicate="%2$d">%3$s</a></div>',
			esc_url( self::confirm_url( $post->ID ) ),
			(int) $post->ID,
			esc_html__( 'Duplicate as new draft', 'cradle-duplicate' )
		);
	}

	/**
	 * Classic editor notice right after duplication (display only).
	 */
	public function classic_notice() {
		$screen = get_current_screen();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		if ( ! $screen || 'post' !== $screen->base || $screen->is_block_editor() || ! isset( $_GET['cradle_duplicated'] ) ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		$source_id = (int) get_post_meta( $post->ID, Duplicator::SOURCE_META, true );
		if ( ! $source_id || ! current_user_can( 'read_post', $source_id ) ) {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>';
		/* translators: %s: source title */
		echo esc_html( sprintf( __( 'Draft created. This is a copy of “%s”; the original was not changed.', 'cradle-duplicate' ), wp_strip_all_tags( get_the_title( $source_id ) ) ) );
		$synced = Duplicator::count_synced_patterns( $post->post_content );
		if ( $synced ) {
			echo '</p><p>' . esc_html( self::synced_pattern_text( $synced ) );
		}
		echo '</p></div>';
	}
}
