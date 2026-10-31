<?php
/**
 * Cradle → Languages: language status (interface translations per locale), the settings form,
 * the default-language assignment and the Polylang import (dry run first). Every view and action
 * checks cradle_manage_languages; actions are nonce-protected POSTs to admin-post.php.
 *
 * @package Cradle\Languages
 */

namespace Cradle\Languages\Admin;

use Cradle\Languages\Config;
use Cradle\Languages\Languages;
use Cradle\Languages\Links;
use Cradle\Languages\Module;
use Cradle\Languages\Polylang;
use Cradle\Languages\Tasks;

defined( 'ABSPATH' ) || exit;

final class Admin_Page {

	const SLUG = 'cradle-languages';

	const REPORT_TRANSIENT = 'cradle_languages_polylang_';

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
		add_action( 'admin_post_cradle_languages_assign', array( $this, 'handle_assign' ) );
		add_action( 'admin_post_cradle_languages_polylang_check', array( $this, 'handle_polylang_check' ) );
		add_action( 'admin_post_cradle_languages_polylang_import', array( $this, 'handle_polylang_import' ) );
	}

	public function menu() {
		$suffix            = add_submenu_page( 'cradle', __( 'Languages', 'cradle-languages' ), __( 'Languages', 'cradle-languages' ), Module::CAP, self::SLUG, array( $this, 'render' ) );
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

	private static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	private static function badge( string $kind, string $text ): string {
		return '<span class="cradle-badge cradle-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	private function deny() {
		wp_die( esc_html__( 'You are not allowed to manage languages.', 'cradle-languages' ), 403 );
	}

	public function render() {
		if ( ! current_user_can( Module::CAP ) ) {
			$this->deny();
		}
		echo '<div class="wrap cradle-wrap cradle-languages-screen"><h1>' . esc_html__( 'Languages', 'cradle-languages' ) . '</h1>';
		$this->render_notices();
		$this->render_status();
		echo '<h2>' . esc_html__( 'Settings', 'cradle-languages' ) . '</h2>';
		$this->core->settings()->render_form( Config::SECTION );
		$this->render_assign();
		$this->render_polylang();
		echo '</div>';
	}

	private function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only parameters, validated.
		$notice = isset( $_GET['cradle_languages_notice'] ) ? sanitize_key( wp_unslash( $_GET['cradle_languages_notice'] ) ) : '';
		$job    = isset( $_GET['job'] ) ? sanitize_text_field( wp_unslash( $_GET['job'] ) ) : '';
		// phpcs:enable
		$messages = array(
			'stale'    => array( 'error', __( 'The Polylang data changed since the dry run. Review the new dry run before importing.', 'cradle-languages' ) ),
			'problems' => array( 'error', __( 'The import cannot start while the dry run lists problems.', 'cradle-languages' ) ),
			'nodry'    => array( 'error', __( 'Run the dry run first and review its report.', 'cradle-languages' ) ),
			'failed'   => array( 'error', __( 'The job could not be started.', 'cradle-languages' ) ),
			'checked'  => array( 'success', __( 'Dry run finished. Nothing was changed; review the report below.', 'cradle-languages' ) ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $messages[ $notice ][0] ), esc_html( $messages[ $notice ][1] ) );
		}
		if ( wp_is_uuid( $job, 4 ) ) {
			$row = $this->core->jobs()->get( $job );
			if ( $row && $this->core->jobs()->user_can_access( $row ) && 'languages' === $row['module'] ) {
				echo '<div class="notice notice-info"><p>' . esc_html( Tasks::IMPORT === $row['type'] ? __( 'Importing from Polylang…', 'cradle-languages' ) : __( 'Assigning the default language…', 'cradle-languages' ) ) . '</p>';
				echo '<div data-cradle-job="' . esc_attr( $row['uuid'] ) . '"><progress class="cradle-progress"></progress><p aria-live="polite"></p></div></div>';
			}
		}
	}

	private function render_status() {
		$translations = $this->core->modules()->is_ready( 'translations' ) && class_exists( '\\Cradle\\Translations\\Store' );
		$link         = admin_url( 'admin.php?page=cradle-translations' );
		echo '<h2>' . esc_html__( 'Configured languages', 'cradle-languages' ) . '</h2>';
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Plain permalinks are in use: language addresses use /index.php/<prefix>/. Choose a pretty permalink structure under Settings → Permalinks for /<prefix>/ addresses.', 'cradle-languages' ) . '</p></div>';
		}
		echo '<table class="widefat striped cradle-table"><thead><tr>';
		foreach ( array( __( 'Language', 'cradle-languages' ), __( 'Locale', 'cradle-languages' ), __( 'Home page', 'cradle-languages' ), __( 'Direction', 'cradle-languages' ), __( 'WordPress language pack', 'cradle-languages' ), __( 'Cradle Translations catalog', 'cradle-languages' ) ) as $column ) {
			echo '<th scope="col">' . esc_html( $column ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( Languages::all() as $lang ) {
			$locale  = $lang['locale'];
			$pack    = 'en_US' === $locale || is_file( WP_LANG_DIR . '/' . $locale . '.mo' ) || is_file( WP_LANG_DIR . '/' . $locale . '.l10n.php' );
			$catalog = 'en_US' === $locale || ( $translations && \Cradle\Translations\Store::exists( $locale ) );
			$home    = Links::language_home( $lang );
			echo '<tr>';
			echo '<td><strong>' . esc_html( $lang['name'] ) . '</strong> <span lang="' . esc_attr( Languages::bcp47( $lang ) ) . '" dir="' . esc_attr( $lang['dir'] ) . '">(' . esc_html( $lang['native'] ) . ')</span>' . ( $lang['default'] ? ' ' . self::badge( 'info', __( 'Default', 'cradle-languages' ) ) : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
			echo '<td><code>' . esc_html( $locale ) . '</code></td>';
			echo '<td><a href="' . esc_url( $home ) . '">' . esc_html( $home ) . '</a></td>';
			echo '<td>' . esc_html( 'rtl' === $lang['dir'] ? __( 'Right to left', 'cradle-languages' ) : __( 'Left to right', 'cradle-languages' ) ) . '</td>';
			echo '<td>' . ( $pack ? self::badge( 'ok', __( 'Installed', 'cradle-languages' ) ) : self::badge( 'warn', __( 'Not installed', 'cradle-languages' ) ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
			echo '<td>';
			if ( 'en_US' === $locale ) {
				echo self::badge( 'ok', __( 'Source language', 'cradle-languages' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
			} elseif ( ! $translations ) {
				echo self::badge( 'warn', __( 'Cradle Translations is not active', 'cradle-languages' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
			} elseif ( $catalog ) {
				echo self::badge( 'ok', __( 'Catalog exists', 'cradle-languages' ) ) . ' <a href="' . esc_url( $link ) . '">' . esc_html__( 'Edit in Translations', 'cradle-languages' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
			} else {
				echo self::badge( 'warn', __( 'No catalog', 'cradle-languages' ) ) . ' <a href="' . esc_url( $link ) . '">' . esc_html__( 'Add it in Translations', 'cradle-languages' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Interface texts of each language come from its locale: WordPress language packs and Cradle Translations catalogs. Page content is translated per entry with “Add translation” in the editor’s Language panel.', 'cradle-languages' ) . '</p>';
	}

	private function render_assign() {
		$count = Tasks::untagged_post_count();
		echo '<h2>' . esc_html__( 'Entries without a language', 'cradle-languages' ) . '</h2>';
		/* translators: 1: number of entries, 2: language name */
		echo '<p>' . esc_html( sprintf( _n( '%1$d entry of a translatable type has no language yet; it counts as %2$s.', '%1$d entries of translatable types have no language yet; they count as %2$s.', $count, 'cradle-languages' ), $count, Languages::default_language()['name'] ) ) . '</p>';
		if ( $count > 0 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="cradle_languages_assign" />';
			wp_nonce_field( 'cradle_languages_assign' );
			submit_button( __( 'Assign the default language', 'cradle-languages' ), 'secondary', 'submit', false );
			echo '</form>';
		}
	}

	private function render_polylang() {
		echo '<h2 id="cradle-languages-polylang">' . esc_html__( 'Import from Polylang', 'cradle-languages' ) . '</h2>';
		if ( ! Polylang::has_data() ) {
			echo '<p>' . esc_html__( 'No Polylang data was found in this site’s database.', 'cradle-languages' ) . '</p>';
			return;
		}
		echo '<p>' . esc_html__( 'Reads the languages, language of each entry and term, translation links, translated types and menu locations that Polylang stored (Polylang may be inactive). The dry run changes nothing; the import never changes or deletes Polylang data and can be repeated.', 'cradle-languages' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cradle-inline-form">';
		echo '<input type="hidden" name="action" value="cradle_languages_polylang_check" />';
		wp_nonce_field( 'cradle_languages_polylang_check' );
		submit_button( __( 'Run dry run', 'cradle-languages' ), 'secondary', 'submit', false );
		echo '</form>';
		$report = get_transient( self::REPORT_TRANSIENT . get_current_user_id() );
		if ( ! is_array( $report ) ) {
			return;
		}
		self::render_report( $report );
		if ( ! $report['problems'] ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-cradle-confirm="' . esc_attr__( 'Import the Polylang languages and translations into Cradle Languages now? The language settings are replaced by Polylang’s.', 'cradle-languages' ) . '">';
			echo '<input type="hidden" name="action" value="cradle_languages_polylang_import" />';
			echo '<input type="hidden" name="fingerprint" value="' . esc_attr( (string) $report['fingerprint'] ) . '" />';
			wp_nonce_field( 'cradle_languages_polylang_import' );
			submit_button( __( 'Import', 'cradle-languages' ), 'primary', 'submit', false );
			echo '</form>';
		}
	}

	public static function render_report( array $report ) {
		echo '<h3>' . esc_html__( 'Dry run report', 'cradle-languages' ) . '</h3>';
		echo '<table class="widefat striped cradle-table"><thead><tr><th scope="col">' . esc_html__( 'Language', 'cradle-languages' ) . '</th><th scope="col">' . esc_html__( 'Locale', 'cradle-languages' ) . '</th><th scope="col">' . esc_html__( 'Entries', 'cradle-languages' ) . '</th><th scope="col">' . esc_html__( 'Terms', 'cradle-languages' ) . '</th></tr></thead><tbody>';
		foreach ( (array) $report['languages'] as $row ) {
			echo '<tr><td>' . esc_html( $row['name'] . ' (' . $row['slug'] . ')' ) . ( $row['default'] ? ' ' . self::badge( 'info', __( 'Default', 'cradle-languages' ) ) : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
			echo '<td><code>' . esc_html( $row['locale'] ) . '</code>' . ( 'rtl' === $row['dir'] ? ' ' . esc_html__( 'right to left', 'cradle-languages' ) : '' ) . '</td>';
			echo '<td>' . (int) ( $report['counts']['posts'][ $row['slug'] ] ?? 0 ) . '</td><td>' . (int) ( $report['counts']['terms'][ $row['slug'] ] ?? 0 ) . '</td></tr>';
		}
		echo '</tbody></table><ul class="ul-disc">';
		/* translators: %d: number of groups */
		echo '<li>' . esc_html( sprintf( __( 'Linked entry translations: %d groups', 'cradle-languages' ), (int) $report['counts']['postGroups'] ) ) . '</li>';
		/* translators: %d: number of groups */
		echo '<li>' . esc_html( sprintf( __( 'Linked term translations: %d groups', 'cradle-languages' ), (int) $report['counts']['termGroups'] ) ) . '</li>';
		/* translators: %d: number of menu assignments */
		echo '<li>' . esc_html( sprintf( __( 'Menu locations per language: %d', 'cradle-languages' ), (int) $report['counts']['menus'] ) ) . '</li>';
		$types      = self::report_modes( (array) $report['postTypes'], 'post_type' );
		$taxonomies = self::report_modes( (array) ( $report['taxonomies'] ?? array() ), 'taxonomy' );
		$none       = _x( 'none', 'empty list', 'cradle-languages' );
		/* translators: %s: list of post types */
		echo '<li>' . esc_html( sprintf( __( 'Translatable content types: %s', 'cradle-languages' ), $types['translatable'] ? implode( ', ', $types['translatable'] ) : $none ) ) . '</li>';
		/* translators: %s: list of post types */
		echo '<li>' . esc_html( sprintf( __( 'Shared content types (one entry for every language): %s', 'cradle-languages' ), $types['shared'] ? implode( ', ', $types['shared'] ) : $none ) ) . '</li>';
		/* translators: %s: list of taxonomies */
		echo '<li>' . esc_html( sprintf( __( 'Translatable taxonomies: %s', 'cradle-languages' ), $taxonomies['translatable'] ? implode( ', ', $taxonomies['translatable'] ) : $none ) ) . '</li>';
		/* translators: %s: list of taxonomies */
		echo '<li>' . esc_html( sprintf( __( 'Shared taxonomies (one term for every language): %s', 'cradle-languages' ), $taxonomies['shared'] ? implode( ', ', $taxonomies['shared'] ) : $none ) ) . '</li>';
		echo '<li>' . esc_html( $report['hideDefault'] ? __( 'The default language keeps addresses without a prefix.', 'cradle-languages' ) : __( 'The default language gets its prefix too.', 'cradle-languages' ) ) . '</li>';
		echo '</ul>';
		foreach ( (array) $report['problems'] as $problem ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $problem ) . '</p></div>';
		}
		foreach ( (array) $report['warnings'] as $warning ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $warning ) . '</p></div>';
		}
	}

	/**
	 * Labels of a report's type mapping grouped by mode ("Projects (project)"). Accepts the older
	 * report shape (a list of translatable names) still stored for up to 30 minutes.
	 *
	 * @param array  $modes Name => mode, or a list of translatable names.
	 * @param string $kind  post_type|taxonomy.
	 * @return array{translatable:string[],shared:string[]}
	 */
	private static function report_modes( array $modes, string $kind ): array {
		if ( array_is_list( $modes ) ) {
			$modes = array_fill_keys( array_map( 'strval', $modes ), 'translatable' );
		}
		$out = array(
			'translatable' => array(),
			'shared'       => array(),
		);
		foreach ( $modes as $name => $mode ) {
			$object = 'taxonomy' === $kind ? get_taxonomy( (string) $name ) : get_post_type_object( (string) $name );
			$label  = $object ? $object->labels->name . ' (' . $name . ')' : (string) $name;
			$out[ 'shared' === $mode ? 'shared' : 'translatable' ][] = $label;
		}
		return $out;
	}

	public function handle_assign() {
		check_admin_referer( 'cradle_languages_assign' );
		if ( ! current_user_can( Module::CAP ) ) {
			$this->deny();
		}
		$job = Tasks::start_assign();
		wp_safe_redirect( self::url( is_wp_error( $job ) ? array( 'cradle_languages_notice' => 'failed' ) : array( 'job' => $job['uuid'] ) ) );
		exit;
	}

	public function handle_polylang_check() {
		check_admin_referer( 'cradle_languages_polylang_check' );
		if ( ! current_user_can( Module::CAP ) ) {
			$this->deny();
		}
		set_transient( self::REPORT_TRANSIENT . get_current_user_id(), Polylang::dry_run(), 30 * MINUTE_IN_SECONDS );
		cradle_log_activity( Module::ID, 'polylang_dry_run', 'Polylang import dry run.' );
		wp_safe_redirect( self::url( array( 'cradle_languages_notice' => 'checked' ) ) . '#cradle-languages-polylang' );
		exit;
	}

	public function handle_polylang_import() {
		check_admin_referer( 'cradle_languages_polylang_import' );
		if ( ! current_user_can( Module::CAP ) ) {
			$this->deny();
		}
		$report = get_transient( self::REPORT_TRANSIENT . get_current_user_id() );
		if ( ! is_array( $report ) ) {
			wp_safe_redirect( self::url( array( 'cradle_languages_notice' => 'nodry' ) ) );
			exit;
		}
		$fingerprint = isset( $_POST['fingerprint'] ) ? sanitize_key( wp_unslash( $_POST['fingerprint'] ) ) : '';
		$job         = Polylang::start( $fingerprint );
		if ( is_wp_error( $job ) ) {
			set_transient( self::REPORT_TRANSIENT . get_current_user_id(), Polylang::dry_run(), 30 * MINUTE_IN_SECONDS );
			$code = 'cradle_languages_polylang_stale' === $job->get_error_code() ? 'stale' : ( 'cradle_languages_polylang_problems' === $job->get_error_code() ? 'problems' : 'failed' );
			wp_safe_redirect( self::url( array( 'cradle_languages_notice' => $code ) ) );
			exit;
		}
		delete_transient( self::REPORT_TRANSIENT . get_current_user_id() );
		wp_safe_redirect( self::url( array( 'job' => $job['uuid'] ) ) );
		exit;
	}
}
