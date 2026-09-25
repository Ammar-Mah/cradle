<?php
/**
 * The Cradle administration area. Menu visibility is never used as authorization;
 * every page and action checks its capability.
 *
 * @package Cradle
 */

namespace Cradle\Core\Admin;

use Cradle\Core\Plugin;
use Cradle\Core\Health;
use Cradle\Core\Module_Registry;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const EXPECTED_PLUGINS = array(
		'cradle-blocks'         => 'Cradle Blocks',
		'cradle-login'          => 'Cradle Login',
		'cradle-admin'          => 'Cradle Admin',
		'cradle-content'        => 'Cradle Content',
		'cradle-duplicate'      => 'Cradle Duplicate',
		'cradle-translations'   => 'Cradle Translations',
		'cradle-migration'      => 'Cradle Migration',
		'cradle-dummy-content'  => 'Cradle Dummy Content',
		'cradle-social'         => 'Cradle Social',
	);

	/** Order of the Cradle menu (page slugs); pages not listed follow in registration order. */
	const MENU_ORDER = array( 'cradle', 'cradle-admin', 'cradle-content', 'cradle-migration', 'cradle-translations', 'cradle-dummy-content', 'cradle-social', 'cradle-duplicate', 'cradle-login' );

	/** Per-module choice read by each package's uninstall.php: retain (default) or purge. */
	const POLICY_OPTION = 'cradle_uninstall_policy';

	private Plugin $core;

	/** @var string[] Hook suffixes of Cradle screens, for scoped asset loading. */
	private $screens = array();

	public function __construct( Plugin $core ) {
		$this->core = $core;
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ), 9 );
		add_action( 'admin_menu', array( $this, 'order_menu' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'dependency_notices' ) );
		add_action( 'admin_post_cradle_uninstall_policy', array( $this, 'handle_uninstall_policy' ) );
	}

	public function menu() {
		$this->screens[] = add_menu_page( __( 'Cradle', 'cradle' ), __( 'Cradle', 'cradle' ), 'cradle_view_diagnostics', 'cradle', array( $this, 'page_overview' ), 'dashicons-screenoptions', 59 );
		$this->screens[] = add_submenu_page( 'cradle', __( 'Cradle overview', 'cradle' ), __( 'Overview', 'cradle' ), 'cradle_view_diagnostics', 'cradle', array( $this, 'page_overview' ) );

		foreach ( $this->core->settings()->sections() as $section ) {
			if ( ! $section['page'] ) {
				continue;
			}
			$id              = $section['id'];
			$this->screens[] = add_submenu_page(
				$section['menu_parent'],
				$section['title'],
				$section['menu_title'],
				$section['capability'],
				'cradle-' . $id,
				function () use ( $id ) {
					$this->page_section( $id );
				}
			);
		}

	}

	/**
	 * Puts the Cradle submenu in the fixed order of MENU_ORDER, whatever order add-ons registered in.
	 */
	public function order_menu() {
		global $submenu;
		if ( empty( $submenu['cradle'] ) || ! is_array( $submenu['cradle'] ) ) {
			return;
		}
		$rank  = array_flip( self::MENU_ORDER );
		$items = array_values( $submenu['cradle'] );
		$keyed = array();
		foreach ( $items as $index => $item ) {
			$keyed[] = array( $rank[ $item[2] ?? '' ] ?? ( count( $rank ) + $index ), $item );
		}
		usort(
			$keyed,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);
		$submenu['cradle'] = array_column( $keyed, 1 ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering Cradle's own submenu.
	}

	public function enqueue( $hook ) {
		if ( in_array( $hook, $this->screens, true ) ) {
			wp_enqueue_style( 'cradle-admin' );
			wp_enqueue_script( 'cradle-admin' );
		}
	}

	private function header( string $title ) {
		echo '<div class="wrap cradle-wrap"><h1>' . esc_html( $title ) . '</h1>';
	}

	private function footer() {
		echo '</div>';
	}

	public function page_section( string $section_id ) {
		$section = $this->core->settings()->section( $section_id );
		if ( ! $section || ! current_user_can( $section['capability'] ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'cradle' ), 403 );
		}
		$this->header( $section['title'] );
		$this->core->settings()->render_form( $section_id );
		$this->footer();
	}

	public function dependency_notices() {
		if ( ! current_user_can( 'activate_plugins' ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ( 'plugins' !== $screen->id && false === strpos( (string) $screen->id, 'cradle' ) ) ) {
			return;
		}
		$failed = array();
		foreach ( $this->core->modules()->report() as $row ) {
			if ( Module_Registry::STATE_FAILED === $row['state'] ) {
				$failed[] = $row['name'] . ' — ' . implode( ' ', $row['problems'] );
			}
		}
		if ( ! $failed ) {
			return;
		}
		/* translators: %d: number of Cradle modules that failed to load */
		$heading = sprintf( _n( '%d Cradle module is not running.', '%d Cradle modules are not running.', count( $failed ), 'cradle' ), count( $failed ) );
		echo '<div class="notice notice-error"><p><strong>' . esc_html( $heading ) . '</strong></p><ul class="ul-disc">';
		foreach ( $failed as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul><p>' . esc_html__( 'No data was deleted. Resolve the listed dependency or version problem and reload.', 'cradle' ) . '</p></div>';
	}

	/**
	 * The single Cradle status page: theme check, every Cradle module (installed or expected) with its
	 * state, activation link and "When deleted" data choice, and the redacted status report.
	 */
	public function page_overview() {
		if ( ! current_user_can( 'cradle_view_diagnostics' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'cradle' ), 403 );
		}
		$this->header( __( 'Cradle', 'cradle' ) );
		$theme      = wp_get_theme();
		$rows       = $this->core->modules()->report();
		$policies   = get_option( self::POLICY_OPTION, array() );
		$policies   = is_array( $policies ) ? $policies : array();
		$can_policy = current_user_can( 'delete_plugins' );
		$present    = array();
		foreach ( $rows as $row ) {
			if ( $row['basename'] ) {
				$present[ dirname( $row['basename'] ) ] = true;
			}
		}
		?>
		<h2><?php esc_html_e( 'Theme', 'cradle' ); ?></h2>
		<table class="widefat striped cradle-table">
			<tbody>
			<tr>
				<td><?php esc_html_e( 'Cradle parent theme installed', 'cradle' ); ?></td>
				<td><?php echo wp_get_theme( 'cradle' )->exists() ? $this->badge( 'ok', __( 'Installed', 'cradle' ) ) : $this->badge( 'bad', __( 'Missing', 'cradle' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Active theme is a Cradle child theme', 'cradle' ); ?></td>
				<td>
				<?php
				if ( $theme->parent() && 'cradle' === $theme->get_template() ) {
					echo $this->badge( 'ok', sprintf( /* translators: %s: theme name */ __( 'Active: %s', 'cradle' ), $theme->get( 'Name' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} elseif ( 'cradle' === get_stylesheet() ) {
					echo $this->badge( 'warn', __( 'The parent theme is active directly; activate the site child theme.', 'cradle' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					echo $this->badge( 'warn', sprintf( /* translators: %s: theme name */ __( 'Another theme is active: %s', 'cradle' ), $theme->get( 'Name' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
				</td>
			</tr>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Cradle modules', 'cradle' ); ?></h2>
		<p><?php esc_html_e( 'States: not installed, installed (present but inactive), ready (running), failed (see problems). Cradle never installs or activates packages by itself: upload ZIP packages through Plugins → Add New → Upload.', 'cradle' ); ?></p>
		<p><?php esc_html_e( '"When deleted" decides what happens to a module\'s own settings and data if its plugin is deleted from the Plugins screen. Deactivating never removes anything.', 'cradle' ); ?></p>
		<table class="widefat striped cradle-table">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Module', 'cradle' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Version', 'cradle' ); ?></th>
				<th scope="col"><?php esc_html_e( 'State', 'cradle' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Problems', 'cradle' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Action', 'cradle' ); ?></th>
				<th scope="col"><?php esc_html_e( 'When deleted', 'cradle' ); ?></th>
			</tr></thead>
			<tbody>
			<tr>
				<td><strong>Cradle</strong> <code>core</code></td>
				<td><?php echo esc_html( CRADLE_VERSION . ' / API ' . CRADLE_API_VERSION ); ?></td>
				<td><?php echo $this->badge( 'ok', $this->state_label( 'ready' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
				<td>—</td><td>—</td>
				<td><?php $this->policy_control( 'core', $policies, $can_policy ); ?></td>
			</tr>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $row['name'] ); ?></strong> <code><?php echo esc_html( $row['id'] ); ?></code></td>
					<td><?php echo esc_html( $row['version'] ); ?></td>
					<td><?php echo $this->badge( 'ready' === $row['state'] ? 'ok' : ( 'failed' === $row['state'] ? 'bad' : 'warn' ), $this->state_label( (string) $row['state'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td><?php echo $row['problems'] ? esc_html( implode( ' ', $row['problems'] ) ) : '—'; ?></td>
					<td>
					<?php
					if ( $row['basename'] && current_user_can( 'activate_plugins' ) ) {
						if ( $row['active'] ) {
							$url = wp_nonce_url( admin_url( 'plugins.php?action=deactivate&plugin=' . rawurlencode( $row['basename'] ) ), 'deactivate-plugin_' . $row['basename'] );
							echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Deactivate', 'cradle' ) . '</a>';
						} else {
							$url = wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $row['basename'] ) ), 'activate-plugin_' . $row['basename'] );
							echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Activate', 'cradle' ) . '</a>';
						}
					} else {
						echo '—';
					}
					?>
					</td>
					<td><?php $this->policy_control( (string) $row['id'], $policies, $can_policy ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php
			foreach ( self::EXPECTED_PLUGINS as $slug => $label ) :
				if ( isset( $present[ $slug ] ) ) {
					continue;
				}
				?>
				<tr>
					<td><strong><?php echo esc_html( $label ); ?></strong></td>
					<td>—</td>
					<td><?php echo $this->badge( 'warn', _x( 'not installed', 'module state', 'cradle' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td>—</td>
					<td><?php echo current_user_can( 'upload_plugins' ) ? '<a href="' . esc_url( admin_url( 'plugin-install.php?tab=upload' ) ) . '">' . esc_html__( 'Upload package', 'cradle' ) . '</a>' : '—'; ?></td>
					<td>—</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Status report', 'cradle' ); ?></h2>
		<p><?php esc_html_e( 'A redacted plain-text report for support. It omits credentials, email addresses and absolute paths.', 'cradle' ); ?></p>
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
		if ( isset( $_POST['cradle_status_report'] ) && check_admin_referer( 'cradle_status_report' ) ) :
			?>
			<label for="cradle-status-report" class="screen-reader-text"><?php esc_html_e( 'Status report', 'cradle' ); ?></label>
			<textarea id="cradle-status-report" class="large-text code" rows="18" readonly><?php echo esc_textarea( Health::status_report() ); ?></textarea>
		<?php else : ?>
			<form method="post">
				<?php wp_nonce_field( 'cradle_status_report' ); ?>
				<?php submit_button( __( 'Generate status report', 'cradle' ), 'secondary', 'cradle_status_report', false ); ?>
			</form>
		<?php endif; ?>
		<p><a href="<?php echo esc_url( admin_url( 'site-health.php' ) ); ?>"><?php esc_html_e( 'Open Site Health for Cradle checks', 'cradle' ); ?></a></p>
		<?php
		$this->footer();
	}

	/**
	 * Human label of a module state (the registry reports machine values).
	 */
	private function state_label( string $state ): string {
		$labels = array(
			'installed'  => _x( 'installed', 'module state', 'cradle' ),
			'active'     => _x( 'active', 'module state', 'cradle' ),
			'compatible' => _x( 'compatible', 'module state', 'cradle' ),
			'ready'      => _x( 'ready', 'module state', 'cradle' ),
			'disabled'   => _x( 'disabled', 'module state', 'cradle' ),
			'failed'     => _x( 'failed', 'module state', 'cradle' ),
		);
		return $labels[ $state ] ?? $state;
	}

	private function badge( string $kind, string $text ): string {
		return '<span class="cradle-badge cradle-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Keep/remove-data choice for one module. Keeping data is the default.
	 */
	private function policy_control( string $module, array $policies, bool $can_change ) {
		$policy = 'purge' === ( $policies[ $module ] ?? 'retain' ) ? 'purge' : 'retain';
		$labels = array(
			'retain' => __( 'Keep data', 'cradle' ),
			'purge'  => __( 'Remove its data', 'cradle' ),
		);
		if ( ! $can_change || ! preg_match( '/^[a-z][a-z0-9-]{1,40}$/', $module ) ) {
			echo esc_html( $labels[ $policy ] );
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cradle-inline-form">
			<input type="hidden" name="action" value="cradle_uninstall_policy" />
			<input type="hidden" name="module" value="<?php echo esc_attr( $module ); ?>" />
			<?php wp_nonce_field( 'cradle_uninstall_policy_' . $module ); ?>
			<label class="screen-reader-text" for="cradle-policy-<?php echo esc_attr( $module ); ?>">
				<?php
				/* translators: %s: module ID */
				echo esc_html( sprintf( __( 'When %s is deleted', 'cradle' ), $module ) );
				?>
			</label>
			<select id="cradle-policy-<?php echo esc_attr( $module ); ?>" name="policy">
				<?php foreach ( $labels as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $policy, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Save', 'cradle' ), 'small', 'submit', false ); ?>
		</form>
		<?php
	}

	public function handle_uninstall_policy() {
		$module = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
		check_admin_referer( 'cradle_uninstall_policy_' . $module );
		if ( ! current_user_can( 'delete_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to change what happens to plugin data.', 'cradle' ), 403 );
		}
		if ( 'core' !== $module && ! $this->core->modules()->get( $module ) && ! isset( $this->core->modules()->report()[ $module ] ) ) {
			wp_die( esc_html__( 'Unknown module.', 'cradle' ), 400 );
		}
		$policy   = isset( $_POST['policy'] ) && 'purge' === $_POST['policy'] ? 'purge' : 'retain';
		$policies = get_option( self::POLICY_OPTION, array() );
		$policies = is_array( $policies ) ? $policies : array();
		$policies[ $module ] = $policy;
		update_option( self::POLICY_OPTION, $policies, false );
		cradle_log_activity( 'core', 'uninstall_policy', sprintf( 'Data policy for %1$s set to %2$s.', $module, $policy ) );
		wp_safe_redirect( admin_url( 'admin.php?page=cradle' ) );
		exit;
	}
}
