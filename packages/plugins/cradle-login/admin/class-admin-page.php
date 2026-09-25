<?php
/**
 * Cradle → Login screen: state, effective login URL, settings form, route test,
 * recovery instructions and compatibility diagnostics. Every view and action checks
 * cradle_manage_login; the route test is a nonce-protected POST.
 *
 * @package Cradle\Login
 */

namespace Cradle\Login\Admin;

use Cradle\Login\Config;
use Cradle\Login\Diagnostics;
use Cradle\Login\Health_Check;
use Cradle\Login\Lifecycle;
use Cradle\Login\Module;
use Cradle\Login\Router;
use Cradle\Login\Slug_Policy;

defined( 'ABSPATH' ) || exit;

final class Admin_Page {

	const SLUG = 'cradle-login';

	const CHECK_ACTION = 'cradle_login_health_check';

	/** @var \Cradle\Core\Plugin */
	private $core;

	private string $hook_suffix = '';

	/**
	 * @param \Cradle\Core\Plugin $core Core.
	 */
	public function __construct( $core ) {
		$this->core = $core;
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ), 10 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::CHECK_ACTION, array( $this, 'handle_health_check' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	public function menu() {
		$suffix            = add_submenu_page( 'cradle', __( 'Login routing', 'cradle-login' ), __( 'Login', 'cradle-login' ), Module::CAP, self::SLUG, array( $this, 'render' ) );
		$this->hook_suffix = is_string( $suffix ) ? $suffix : '';
	}

	/**
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( '' !== $this->hook_suffix && $hook === $this->hook_suffix ) {
			wp_enqueue_style( 'cradle-admin' );
			wp_enqueue_script( 'cradle-admin' );
		}
	}

	private static function badge( string $kind, string $text ): string {
		return '<span class="cradle-badge cradle-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Human description of the routing status.
	 *
	 * @return array{0:string,1:string} [badge kind, text]
	 */
	private static function describe_status( string $status ): array {
		switch ( $status ) {
			case 'active':
				return array( 'ok', __( 'Active — the login page is served at the custom route.', 'cradle-login' ) );
			case 'constant':
				/* translators: %s: constant name */
				return array( 'warn', sprintf( __( 'Off — switched off by %s in the server configuration.', 'cradle-login' ), Config::CONSTANT ) );
			case 'filter':
				return array( 'warn', __( 'Off — switched off by the cradle_login_disable_routing filter.', 'cradle-login' ) );
			case 'multisite':
				return array( 'bad', __( 'Off — not supported on multisite.', 'cradle-login' ) );
			case 'no_slug':
				return array( 'warn', __( 'Off — enabled without a valid slug.', 'cradle-login' ) );
		}
		return array( 'info', __( 'Off (default) — WordPress serves wp-login.php normally.', 'cradle-login' ) );
	}

	public function notices() {
		if ( ! current_user_can( Module::CAP ) || ! get_transient( Lifecycle::RESUMED_NOTICE ) ) {
			return;
		}
		delete_transient( Lifecycle::RESUMED_NOTICE );
		if ( 'active' !== Config::status() ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			/* translators: %s: login URL */
			esc_html( sprintf( __( 'Cradle Login is active again and the custom login route is in use: %s', 'cradle-login' ), wp_login_url() ) ),
			esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
			esc_html__( 'Review login routing', 'cradle-login' )
		);
	}

	public function render() {
		if ( ! current_user_can( Module::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to manage login routing.', 'cradle-login' ), 403 );
		}
		$status  = Config::status();
		$values  = Config::values();
		$health  = Health_Check::last();
		$check   = get_transient( 'cradle_login_check_' . get_current_user_id() );
		$state   = self::describe_status( $status );
		$labels  = array(
			'not_found'       => __( 'generic 404 page (theme template)', 'cradle-login' ),
			'plain_not_found' => __( 'minimal 404 response', 'cradle-login' ),
			'redirect_home'   => __( 'redirect to the home page', 'cradle-login' ),
		);
		?>
		<div class="wrap cradle-wrap cradle-login-screen">
			<h1><?php esc_html_e( 'Login routing', 'cradle-login' ); ?></h1>
			<?php settings_errors( 'general' ); ?>
			<p><?php esc_html_e( 'Cradle Login can serve the native WordPress login page at a custom path and answer signed-out requests to wp-login.php and /wp-admin/ with an ordinary "not found" response. This reduces automated requests to the default login URL. It is not a security control: keep WordPress updated and use strong passwords, two-factor authentication, HTTPS and rate limiting.', 'cradle-login' ); ?></p>

			<h2><?php esc_html_e( 'Current state', 'cradle-login' ); ?></h2>
			<table class="widefat striped cradle-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Routing', 'cradle-login' ); ?></th>
						<td><?php echo self::badge( $state[0], $state[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Effective login URL', 'cradle-login' ); ?></th>
						<td><code class="cradle-login-url"><?php echo esc_html( wp_login_url() ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Signed-out requests to wp-login.php and /wp-admin/', 'cradle-login' ); ?></th>
						<td>
							<?php
							if ( 'active' === $status ) {
								/* translators: %s: response description */
								echo esc_html( sprintf( __( 'Answered with a %s.', 'cradle-login' ), $labels[ $values['blocked_response'] ] ?? $labels['not_found'] ) );
							} else {
								esc_html_e( 'Normal WordPress behavior.', 'cradle-login' );
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><code><?php echo esc_html( Config::CONSTANT ); ?></code></th>
						<td><?php echo Config::disabled_by_constant() ? self::badge( 'warn', __( 'Set — routing is off', 'cradle-login' ) ) : esc_html__( 'Not set', 'cradle-login' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last health check', 'cradle-login' ); ?></th>
						<td>
							<?php
							if ( ! empty( $health['time'] ) ) {
								echo self::badge( ! empty( $health['ok'] ) ? 'ok' : 'bad', ! empty( $health['ok'] ) ? __( 'Passed', 'cradle-login' ) : __( 'Failed', 'cradle-login' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
								echo ' ' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $health['time'] ) . ' — ' . (string) ( $health['message'] ?? '' ) );
							} else {
								esc_html_e( 'Not run yet.', 'cradle-login' );
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>
			<?php if ( '' !== $values['recovery_note'] ) : ?>
				<div class="notice notice-info inline"><p><strong><?php esc_html_e( 'Administrator recovery note:', 'cradle-login' ); ?></strong><br /><?php echo nl2br( esc_html( $values['recovery_note'] ) ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Settings', 'cradle-login' ); ?></h2>
			<?php $this->core->settings()->render_form( Module::SECTION ); ?>

			<h2 id="cradle-login-test"><?php esc_html_e( 'Test a route without saving', 'cradle-login' ); ?></h2>
			<p><?php esc_html_e( 'Runs the same loopback health check used when enabling: the site requests the route from itself as a signed-out visitor and expects the native login form. Nothing is saved except the result.', 'cradle-login' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cradle-inline-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CHECK_ACTION ); ?>" />
				<?php wp_nonce_field( self::CHECK_ACTION ); ?>
				<label for="cradle-login-test-slug"><?php esc_html_e( 'Slug to test', 'cradle-login' ); ?></label>
				<input type="text" id="cradle-login-test-slug" name="slug" class="regular-text" maxlength="64" value="<?php echo esc_attr( $values['slug'] ); ?>" aria-describedby="cradle-login-test-result" />
				<?php submit_button( __( 'Run health check', 'cradle-login' ), 'secondary', 'submit', false ); ?>
			</form>
			<p id="cradle-login-test-result" role="status">
				<?php
				if ( is_array( $check ) && isset( $check['message'] ) ) {
					echo self::badge( ! empty( $check['ok'] ) ? 'ok' : 'bad', ! empty( $check['ok'] ) ? __( 'Passed', 'cradle-login' ) : __( 'Failed', 'cradle-login' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
					echo ' ' . esc_html( (string) ( $check['slug'] ?? '' ) . ': ' . (string) $check['message'] );
				}
				?>
			</p>

			<h2><?php esc_html_e( 'Recovery', 'cradle-login' ); ?></h2>
			<p><?php esc_html_e( 'If the custom route stops working, restore the normal login page without editing the database. There is no secret query string and no recovery URL.', 'cradle-login' ); ?></p>
			<ol>
				<li>
					<?php
					/* translators: 1: constant definition, 2: file name */
					echo wp_kses( sprintf( __( 'Add %1$s to %2$s (above the "stop editing" line). Routing turns off immediately; wp-login.php works again. Sign in, fix or disable the route here, then remove the line.', 'cradle-login' ), '<code>define( \'' . esc_html( Config::CONSTANT ) . '\', true );</code>', '<code>wp-config.php</code>' ), array( 'code' => array() ) );
					?>
				</li>
				<li><?php echo wp_kses( __( 'Or deactivate Cradle Login (Plugins screen, <code>wp plugin deactivate cradle-login</code>, or rename the <code>wp-content/plugins/cradle-login</code> folder). Settings are kept; reactivating resumes the saved route.', 'cradle-login' ), array( 'code' => array() ) ); ?></li>
				<li><?php esc_html_e( 'Signed-in sessions are never ended by changing the route, and signed-in users can always open /wp-admin/.', 'cradle-login' ); ?></li>
			</ol>
			<p><?php echo wp_kses( __( 'Full runbook: <code>wp-content/plugins/cradle-login/docs/recovery.md</code>.', 'cradle-login' ), array( 'code' => array() ) ); ?></p>

			<h2><?php esc_html_e( 'Compatibility diagnostics', 'cradle-login' ); ?></h2>
			<table class="widefat striped cradle-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Check', 'cradle-login' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'cradle-login' ); ?></th><th scope="col"><?php esc_html_e( 'Details', 'cradle-login' ); ?></th></tr></thead>
				<tbody>
				<?php
				$level_labels = array(
					'ok'   => __( 'OK', 'cradle-login' ),
					'warn' => __( 'Check', 'cradle-login' ),
					'bad'  => __( 'Problem', 'cradle-login' ),
					'info' => __( 'Info', 'cradle-login' ),
				);
				foreach ( Diagnostics::rows() as $row ) :
					?>
					<tr>
						<td><?php echo esc_html( $row[0] ); ?></td>
						<td><?php echo 'info' === $row[1] ? esc_html( $level_labels['info'] ) : self::badge( $row[1], $level_labels[ $row[1] ] ?? $row[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?></td>
						<td><?php echo esc_html( $row[2] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * admin-post: runs the loopback health check for a slug without saving settings.
	 */
	public function handle_health_check() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'This action requires a POST request.', 'cradle-login' ), 405 );
		}
		check_admin_referer( self::CHECK_ACTION );
		if ( ! current_user_can( Module::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to manage login routing.', 'cradle-login' ), 403 );
		}
		$slug = isset( $_POST['slug'] ) && is_string( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';

		$result = array(
			'ok'      => false,
			'code'    => 0,
			'message' => '',
			'time'    => time(),
		);
		if ( ! Config::is_valid_slug_format( $slug ) ) {
			$result['message'] = __( 'Use 3–64 lowercase letters, digits and hyphens, starting and ending with a letter or digit.', 'cradle-login' );
			$slug              = '';
		} else {
			$active_slug = Router::instance()->slug();
			$collision   = $slug === $active_slug ? true : Slug_Policy::check( $slug );
			if ( is_wp_error( $collision ) ) {
				$result['message'] = $collision->get_error_message();
			} else {
				$result = Health_Check::probe( $slug );
				Health_Check::record( $result, 'manual' );
			}
		}
		$result['slug'] = $slug;
		set_transient( 'cradle_login_check_' . get_current_user_id(), $result, HOUR_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '#cradle-login-test' ) );
		exit;
	}
}
