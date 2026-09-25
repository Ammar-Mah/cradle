<?php
/**
 * Cradle → Branding & admin page (tabs: Branding, Appearance, Labels, Custom CSS)
 * and the raw CSS handlers (save, restore, reset) on admin-post.php.
 *
 * The three structured tabs embed core settings sections (options.php, stale-edit
 * detection, reset/export/import). This page is never styled by the Cradle skin,
 * so it remains a recovery path when a visual setting is wrong.
 *
 * @package Cradle\Admin
 */

namespace Cradle\Admin\Admin;

use Cradle\Admin\Color;
use Cradle\Admin\Css_Policy;
use Cradle\Admin\Custom_Css;
use Cradle\Admin\Module;
use Cradle\Admin\Sections;
use Cradle\Admin\Skin;

defined( 'ABSPATH' ) || exit;

final class Page {

	const TABS = array( 'branding', 'appearance', 'labels', 'css' );

	/** @var string Hook suffix of the page. */
	private static $hook = '';

	public static function hooks() {
		add_action( 'admin_menu', array( self::class, 'menu' ), 10 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_post_cradle_admin_css_save', array( self::class, 'handle_css_save' ) );
		add_action( 'admin_post_cradle_admin_css_restore', array( self::class, 'handle_css_restore' ) );
		add_action( 'admin_post_cradle_admin_css_reset', array( self::class, 'handle_css_reset' ) );
	}

	public static function menu() {
		self::$hook = (string) add_submenu_page(
			'cradle',
			__( 'Branding and admin appearance', 'cradle-admin' ),
			__( 'Branding & admin', 'cradle-admin' ),
			Module::CAP,
			Module::PAGE,
			array( self::class, 'render' )
		);
	}

	public static function url( string $tab = 'branding', array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => Module::PAGE, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	private static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'branding';
		return in_array( $tab, self::TABS, true ) ? $tab : 'branding';
	}

	/**
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue( $hook ) {
		if ( '' === self::$hook || $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'cradle-admin' );
		wp_enqueue_script( 'cradle-admin' );
		if ( 'branding' === self::current_tab() ) {
			wp_enqueue_media();
		}
		\Cradle\Core\Assets::register_style( 'cradle-admin-settings', CRADLE_ADMIN_FILE, 'assets/css/settings.css', array( 'cradle-admin' ), CRADLE_ADMIN_VERSION );
		\Cradle\Core\Assets::register_script( 'cradle-admin-settings', CRADLE_ADMIN_FILE, 'assets/js/settings.js', array( 'wp-i18n' ), CRADLE_ADMIN_VERSION );
		wp_set_script_translations( 'cradle-admin-settings', 'cradle-admin', CRADLE_ADMIN_DIR . 'languages' );
		wp_enqueue_style( 'cradle-admin-settings' );
		wp_enqueue_script( 'cradle-admin-settings' );
	}

	public static function render() {
		if ( ! current_user_can( Module::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'cradle-admin' ), 403 );
		}
		$tab    = self::current_tab();
		$titles = array(
			'branding'   => __( 'Branding', 'cradle-admin' ),
			'appearance' => __( 'Appearance', 'cradle-admin' ),
			'labels'     => __( 'Labels', 'cradle-admin' ),
			'css'        => __( 'Custom CSS', 'cradle-admin' ),
		);
		echo '<div class="wrap cradle-wrap cradle-admin-page">';
		echo '<h1>' . esc_html__( 'Branding and admin appearance', 'cradle-admin' ) . '</h1>';
		self::status_notices();
		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Branding and admin sections', 'cradle-admin' ) . '">';
		foreach ( $titles as $key => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( self::url( $key ) ),
				$key === $tab ? ' nav-tab-active' : '',
				$key === $tab ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
		echo '<h2 class="screen-reader-text">' . esc_html( $titles[ $tab ] ) . '</h2>';

		$settings = Module::core()->settings();
		switch ( $tab ) {
			case 'appearance':
				self::preview();
				$settings->render_form( Module::SECTION_VISUAL );
				break;
			case 'labels':
				$settings->render_form( Module::SECTION_LABELS );
				break;
			case 'css':
				self::render_css_tab();
				break;
			default:
				$settings->render_form( Module::SECTION_BRANDING );
		}
		echo '</div>';
	}

	private static function status_notices() {
		if ( Module::safe_mode() ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Safe mode is on.', 'cradle-admin' ) . '</strong> ';
			if ( Module::safe_mode_by_constant() ) {
				echo esc_html__( 'CRADLE_ADMIN_SAFE_MODE is defined in the server configuration, so no Cradle admin styling, branding or label change is applied. You can still edit and reset settings here; remove the constant to apply them.', 'cradle-admin' );
			} else {
				echo esc_html__( 'Site code enabled safe mode through the cradle_admin/safe_mode filter, so no Cradle admin styling, branding or label change is applied.', 'cradle-admin' );
			}
			echo '</p></div>';
		}
		$visual = Module::values( Module::SECTION_VISUAL );
		if ( Skin::user_prefers_native( get_current_user_id(), $visual ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'You chose the default WordPress appearance on your profile, so the Cradle skin is not shown to you. Other users still see it.', 'cradle-admin' ) . ' <a href="' . esc_url( admin_url( 'profile.php#cradle-admin-appearance' ) ) . '">' . esc_html__( 'Change on your profile', 'cradle-admin' ) . '</a></p></div>';
		}
		echo '<p class="description">' . esc_html__( 'This page always uses the native WordPress appearance, so a wrong color or size can be corrected here at any time. Each tab has its own “Reset to defaults”.', 'cradle-admin' ) . '</p>';
	}

	/**
	 * Static preview driven by CSS custom properties (updated live by settings.js).
	 */
	private static function preview() {
		$v      = Module::values( Module::SECTION_VISUAL );
		$vars   = array(
			'--preview-accent'    => Skin::hex( $v['accent_color'], Sections::DEFAULTS['accent'] ),
			'--preview-bg'        => Skin::hex( $v['background_color'], Sections::DEFAULTS['background'] ),
			'--preview-text'      => Skin::hex( $v['text_color'], Sections::DEFAULTS['text'] ),
			'--preview-border'    => Skin::hex( $v['border_color'], Sections::DEFAULTS['border'] ),
			'--preview-focus'     => Skin::hex( $v['focus_color'], Sections::DEFAULTS['focus'] ),
			'--preview-menu-bg'   => Skin::hex( $v['menu_background'], Sections::DEFAULTS['menu_bg'] ),
			'--preview-menu-text' => Skin::hex( $v['menu_text'], Sections::DEFAULTS['menu_text'] ),
			'--preview-radius'    => Skin::px( $v['border_radius'], 0, 16, 2 ),
		);
		$style = '';
		foreach ( $vars as $name => $value ) {
			$style .= $name . ':' . $value . ';';
		}
		$ratios = array(
			'text'   => array( __( 'Text on background', 'cradle-admin' ), Color::contrast( $vars['--preview-text'], $vars['--preview-bg'] ), Sections::TEXT_CONTRAST ),
			'panel'  => array( __( 'Text on white panels', 'cradle-admin' ), Color::contrast( $vars['--preview-text'], '#ffffff' ), Sections::TEXT_CONTRAST ),
			'accent' => array( __( 'Accent on background', 'cradle-admin' ), Color::contrast( $vars['--preview-accent'], $vars['--preview-bg'] ), Sections::TEXT_CONTRAST ),
			'button' => array( __( 'White text on accent', 'cradle-admin' ), Color::contrast( $vars['--preview-accent'], '#ffffff' ), Sections::TEXT_CONTRAST ),
			'focus'  => array( __( 'Focus indicator on background', 'cradle-admin' ), Color::contrast( $vars['--preview-focus'], $vars['--preview-bg'] ), Sections::UI_CONTRAST ),
			'menu'   => array( __( 'Menu text on menu background', 'cradle-admin' ), Color::contrast( $vars['--preview-menu-text'], $vars['--preview-menu-bg'] ), Sections::TEXT_CONTRAST ),
		);
		?>
		<div class="cradle-admin-preview-wrap">
			<h3><?php esc_html_e( 'Preview', 'cradle-admin' ); ?></h3>
			<div class="cradle-admin-preview" style="<?php echo esc_attr( $style ); ?>" aria-hidden="true">
				<div class="cradle-admin-preview__menu">
					<span class="is-current"><?php esc_html_e( 'Dashboard', 'cradle-admin' ); ?></span>
					<span><?php esc_html_e( 'Posts', 'cradle-admin' ); ?></span>
					<span><?php esc_html_e( 'Media', 'cradle-admin' ); ?></span>
				</div>
				<div class="cradle-admin-preview__content">
					<span class="cradle-admin-preview__title"><?php esc_html_e( 'Screen title', 'cradle-admin' ); ?></span>
					<span class="cradle-admin-preview__text"><?php esc_html_e( 'Body text with a', 'cradle-admin' ); ?> <span class="cradle-admin-preview__link"><?php esc_html_e( 'link', 'cradle-admin' ); ?></span></span>
					<span class="cradle-admin-preview__panel">
						<span class="cradle-admin-preview__button"><?php esc_html_e( 'Primary', 'cradle-admin' ); ?></span>
						<span class="cradle-admin-preview__button is-secondary is-focused"><?php esc_html_e( 'Focused', 'cradle-admin' ); ?></span>
					</span>
				</div>
			</div>
			<table class="widefat striped cradle-admin-contrast" id="cradle-admin-contrast">
				<caption class="screen-reader-text"><?php esc_html_e( 'Contrast of the saved colors', 'cradle-admin' ); ?></caption>
				<thead><tr><th scope="col"><?php esc_html_e( 'Pair', 'cradle-admin' ); ?></th><th scope="col"><?php esc_html_e( 'Contrast', 'cradle-admin' ); ?></th><th scope="col"><?php esc_html_e( 'Required', 'cradle-admin' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $ratios as $key => $row ) : ?>
					<tr data-pair="<?php echo esc_attr( $key ); ?>">
						<td><?php echo esc_html( $row[0] ); ?></td>
						<td class="cradle-admin-contrast__value"><?php echo esc_html( number_format_i18n( $row[1], 2 ) . ':1 ' . ( $row[1] >= $row[2] ? __( '(passes)', 'cradle-admin' ) : __( '(too low)', 'cradle-admin' ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row[2], 1 ) . ':1' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'The preview and contrast table update as you change colors. Values are checked again on the server when you save; pairs below the required contrast are refused and keep their previous values.', 'cradle-admin' ); ?></p>
		</div>
		<?php
	}

	/*
	 * ---------------------------- Custom CSS tab ----------------------------
	 */

	private static function render_css_tab() {
		$user_id = get_current_user_id();
		$current = Custom_Css::current();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only status code mapped to fixed messages.
		$code     = isset( $_GET['cradle-admin-css'] ) ? sanitize_key( wp_unslash( $_GET['cradle-admin-css'] ) ) : '';
		$messages = array(
			'saved'     => array( 'success', __( 'Custom CSS saved.', 'cradle-admin' ) ),
			'unchanged' => array( 'info', __( 'The CSS was unchanged; nothing was saved.', 'cradle-admin' ) ),
			'restored'  => array( 'success', __( 'Revision restored. The CSS it replaced was added to the history.', 'cradle-admin' ) ),
			'reset'     => array( 'success', __( 'Custom CSS was cleared. The previous CSS is kept in the revision history.', 'cradle-admin' ) ),
			'rejected'  => array( 'error', __( 'The CSS was not saved. Fix the problems listed below; your draft is kept in the editor.', 'cradle-admin' ) ),
			'stale'     => array( 'error', __( 'The CSS changed after you opened this page. Nothing was saved; your draft is kept in the editor.', 'cradle-admin' ) ),
			'missing'   => array( 'error', __( 'That revision no longer exists.', 'cradle-admin' ) ),
			'failed'    => array( 'error', __( 'The revision could not be restored because it does not meet the current CSS policy.', 'cradle-admin' ) ),
		);
		if ( isset( $messages[ $code ] ) ) {
			printf( '<div class="notice notice-%1$s" role="status"><p>%2$s</p></div>', esc_attr( $messages[ $code ][0] ), esc_html( $messages[ $code ][1] ) );
		}

		echo '<p>' . esc_html__( 'Structured controls on the Appearance tab are the normal way to style the administration. This field is for trusted administrators only. The CSS is printed in its own style element on approved screens, after the Cradle skin; scope selectors with body.cradle-admin-custom-css.', 'cradle-admin' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Policy: at most 64 KB; no “<”, @import, expression(), script URLs, behavior/-moz-binding, image-set()/src()/image(); url() only for relative or same-site addresses, #fragments and data: images; update and security notices must not be hidden. These checks are guardrails, not a guarantee that arbitrary CSS is safe — that is why only trusted administrators can use this field.', 'cradle-admin' ) . '</p>';

		if ( ! Custom_Css::environment_allows() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Raw CSS is disabled on this site because unfiltered HTML is not allowed (DISALLOW_UNFILTERED_HTML) or site code turned the feature off. Stored CSS is kept but not applied. The structured controls remain available.', 'cradle-admin' ) . '</p></div>';
			return;
		}
		if ( ! Custom_Css::user_can_edit( $user_id ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Raw CSS can only be edited by trusted administrators (manage_options and unfiltered_html). The structured controls remain available to you.', 'cradle-admin' ) . '</p></div>';
			return;
		}

		$draft  = Custom_Css::take_draft( $user_id );
		$value  = $draft ? $draft['css'] : $current['css'];
		$errors = $draft ? (array) $draft['errors'] : array();
		$bytes  = strlen( $value );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cradle-admin-css-form" novalidate>
			<input type="hidden" name="action" value="cradle_admin_css_save" />
			<input type="hidden" name="base_revision" value="<?php echo esc_attr( (string) $current['revision'] ); ?>" />
			<?php wp_nonce_field( 'cradle_admin_css_save' ); ?>
			<p>
				<label for="cradle-admin-css-editor"><strong><?php esc_html_e( 'Custom admin CSS', 'cradle-admin' ); ?></strong></label>
			</p>
			<?php if ( $errors ) : ?>
				<div class="cradle-field-error" id="cradle-admin-css-errors">
					<p><?php esc_html_e( 'Problems found:', 'cradle-admin' ); ?></p>
					<ul class="ul-disc">
						<?php foreach ( $errors as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
			<textarea id="cradle-admin-css-editor" name="cradle_admin_css" rows="18" class="large-text code" spellcheck="false" autocapitalize="off" aria-describedby="cradle-admin-css-help cradle-admin-css-count<?php echo $errors ? ' cradle-admin-css-errors' : ''; ?>"><?php echo esc_textarea( $value ); ?></textarea>
			<p class="description" id="cradle-admin-css-help">
				<?php
				printf(
					/* translators: %s: current revision number */
					esc_html__( 'Editing revision %s. Saving keeps the previous CSS in the history below (last 10 versions).', 'cradle-admin' ),
					esc_html( (string) $current['revision'] )
				);
				?>
			</p>
			<p class="description" id="cradle-admin-css-count" data-max="<?php echo esc_attr( (string) Css_Policy::MAX_BYTES ); ?>">
				<?php
				printf(
					/* translators: 1: bytes used, 2: byte limit */
					esc_html__( '%1$s of %2$s bytes', 'cradle-admin' ),
					esc_html( number_format_i18n( $bytes ) ),
					esc_html( number_format_i18n( Css_Policy::MAX_BYTES ) )
				);
				?>
			</p>
			<?php submit_button( __( 'Save CSS', 'cradle-admin' ) ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cradle-inline-form" data-cradle-confirm="<?php esc_attr_e( 'Clear the custom CSS? It stays in the revision history and can be restored.', 'cradle-admin' ); ?>">
			<input type="hidden" name="action" value="cradle_admin_css_reset" />
			<?php wp_nonce_field( 'cradle_admin_css_reset' ); ?>
			<?php submit_button( __( 'Reset custom CSS', 'cradle-admin' ), 'secondary', 'submit', false ); ?>
		</form>

		<h2><?php esc_html_e( 'Revision history', 'cradle-admin' ); ?></h2>
		<?php
		$revisions = Custom_Css::revisions();
		if ( ! $revisions ) {
			echo '<p>' . esc_html__( 'No earlier versions yet.', 'cradle-admin' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped cradle-table">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Revision', 'cradle-admin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Saved', 'cradle-admin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'By', 'cradle-admin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Size', 'cradle-admin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'CSS', 'cradle-admin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Action', 'cradle-admin' ); ?></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( $revisions as $item ) :
				$author = $item['author'] ? get_userdata( $item['author'] ) : false;
				?>
				<tr>
					<td>#<?php echo esc_html( (string) $item['revision'] ); ?></td>
					<td><?php echo esc_html( $item['saved'] ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['saved'] ) : '—' ); ?></td>
					<td><?php echo esc_html( $author ? $author->display_name : __( 'Unknown user', 'cradle-admin' ) ); ?></td>
					<td>
					<?php
					/* translators: %s: size in bytes */
					echo esc_html( sprintf( __( '%s bytes', 'cradle-admin' ), number_format_i18n( $item['bytes'] ) ) );
					?>
					</td>
					<td>
						<details>
							<summary>
							<?php
							/* translators: %s: revision number */
							echo esc_html( sprintf( __( 'View revision %s', 'cradle-admin' ), $item['revision'] ) );
							?>
							</summary>
							<textarea readonly rows="8" class="large-text code" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: revision number */ __( 'CSS of revision %s', 'cradle-admin' ), $item['revision'] ) ); ?>"><?php echo esc_textarea( $item['css'] ); ?></textarea>
						</details>
					</td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cradle-inline-form">
							<input type="hidden" name="action" value="cradle_admin_css_restore" />
							<input type="hidden" name="revision" value="<?php echo esc_attr( (string) $item['revision'] ); ?>" />
							<?php wp_nonce_field( 'cradle_admin_css_restore' ); ?>
							<?php
							submit_button(
								__( 'Restore', 'cradle-admin' ),
								'secondary small',
								'submit',
								false,
								array(
									/* translators: %s: revision number */
									'aria-label' => sprintf( __( 'Restore revision %s', 'cradle-admin' ), $item['revision'] ),
								)
							);
							?>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Common guard for raw CSS mutations: POST only, nonce, trusted administrator.
	 */
	private static function guard( string $action ) {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'This action requires a form submission.', 'cradle-admin' ), '', array( 'response' => 405 ) );
		}
		check_admin_referer( $action );
		if ( ! Custom_Css::user_can_edit() ) {
			wp_die( esc_html__( 'You are not allowed to change the custom admin CSS.', 'cradle-admin' ), '', array( 'response' => 403 ) );
		}
	}

	private static function done( string $code ) {
		wp_safe_redirect( self::url( 'css', array( 'cradle-admin-css' => $code ) ) );
		exit;
	}

	public static function handle_css_save() {
		self::guard( 'cradle_admin_css_save' );
		$user_id = get_current_user_id();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw CSS is validated by Css_Policy; sanitizing would corrupt it.
		$css  = isset( $_POST['cradle_admin_css'] ) && is_string( $_POST['cradle_admin_css'] ) ? wp_unslash( $_POST['cradle_admin_css'] ) : null;
		$base = isset( $_POST['base_revision'] ) ? (int) $_POST['base_revision'] : -1;
		// phpcs:enable
		if ( null === $css ) {
			self::done( 'rejected' );
		}
		$before = Custom_Css::current()['revision'];
		$result = Custom_Css::save( $css, $base, $user_id );
		if ( is_wp_error( $result ) ) {
			Custom_Css::store_draft( $user_id, $css, 'cradle_admin_css_stale' === $result->get_error_code() ? array() : $result->get_error_messages() );
			self::done( 'cradle_admin_css_stale' === $result->get_error_code() ? 'stale' : 'rejected' );
		}
		self::done( $result['revision'] === $before ? 'unchanged' : 'saved' );
	}

	public static function handle_css_restore() {
		self::guard( 'cradle_admin_css_restore' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$revision = isset( $_POST['revision'] ) ? (int) $_POST['revision'] : 0;
		$result   = Custom_Css::restore( $revision, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			self::done( 'cradle_admin_css_missing' === $result->get_error_code() ? 'missing' : 'failed' );
		}
		self::done( 'restored' );
	}

	public static function handle_css_reset() {
		self::guard( 'cradle_admin_css_reset' );
		Custom_Css::reset( get_current_user_id() );
		self::done( 'reset' );
	}
}
