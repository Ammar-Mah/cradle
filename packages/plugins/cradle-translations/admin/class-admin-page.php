<?php
/**
 * Cradle → Translations: Loco-like catalog editor.
 *
 * Server-rendered and usable without JavaScript. Every mutation is a POST to admin-post.php
 * with a per-action, per-locale nonce, a capability check and a stale-revision check.
 * Menu visibility is never used as authorization. Output is escaped by context.
 *
 * @package Cradle\Translations
 */

namespace Cradle\Translations\Admin;

use Cradle\Translations\Catalog;
use Cradle\Translations\Config;
use Cradle\Translations\Examples;
use Cradle\Translations\Extractor;
use Cradle\Translations\Plural_Rules;
use Cradle\Translations\Service;
use Cradle\Translations\Store;
use Cradle\Translations\Validator;

defined( 'ABSPATH' ) || exit;

final class Admin_Page {

	const SLUG     = 'cradle-translations';
	const TABS     = array( 'strings', 'import', 'sources', 'revisions', 'settings' );
	const STATUSES = array( 'missing', 'translated', 'fuzzy', 'obsolete', 'draft' );
	const ACTIONS  = array( 'add_locale', 'save_entry', 'add_entry', 'delete_entry', 'publish', 'discard_all', 'export', 'import_json', 'import_po', 'refresh', 'restore', 'delete_locale', 'export_pot' );

	/** @var string */
	private $hook = '';

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_cradle_translations_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	public function menu() {
		$this->hook = (string) add_submenu_page(
			'cradle',
			__( 'Translations', 'cradle-translations' ),
			__( 'Translations', 'cradle-translations' ),
			Config::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue( $hook ) {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}
		\Cradle\Core\Assets::register_style( 'cradle-translations-admin', CRADLE_TRANSLATIONS_FILE, 'assets/css/admin.css', array(), CRADLE_TRANSLATIONS_VERSION );
		\Cradle\Core\Assets::register_script( 'cradle-translations-admin', CRADLE_TRANSLATIONS_FILE, 'assets/js/admin.js', array( 'wp-i18n' ), CRADLE_TRANSLATIONS_VERSION );
		wp_set_script_translations( 'cradle-translations-admin', 'cradle-translations', CRADLE_TRANSLATIONS_DIR . 'languages' );
		wp_enqueue_style( 'cradle-admin' );
		wp_enqueue_style( 'cradle-translations-admin' );
		wp_enqueue_script( 'cradle-translations-admin' );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), array_filter( $args, static fn( $v ) => null !== $v && '' !== $v ) ), admin_url( 'admin.php' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Request helpers
	 * ------------------------------------------------------------------ */

	private static function get_param( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameters, validated below.
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	private static function post_param( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by the calling handler.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
	}

	/**
	 * Common guard for every handler: POST only, capability, nonce bound to action and locale.
	 */
	private function require_post() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'This action requires a POST request.', 'cradle-translations' ), 405 );
		}
	}

	private function guard( string $action, string $locale = '' ) {
		$this->require_post();
		if ( ! current_user_can( Config::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to manage translations.', 'cradle-translations' ), 403 );
		}
		check_admin_referer( 'cradle_translations_' . $action . ( '' !== $locale ? '_' . $locale : '' ) );
	}

	private function locale_from_post(): string {
		$this->require_post();
		$locale = $this->post_param( 'locale' );
		if ( ! Config::valid_locale( $locale ) ) {
			wp_die( esc_html__( 'Invalid locale code.', 'cradle-translations' ), 400 );
		}
		return $locale;
	}

	private static function notice_key(): string {
		return 'cradle_translations_notice_' . get_current_user_id();
	}

	private function notify( string $type, string $message, array $details = array() ) {
		set_transient(
			self::notice_key(),
			array(
				'type'    => $type,
				'message' => $message,
				'details' => array_slice( array_map( 'strval', $details ), 0, 50 ),
			),
			120
		);
	}

	/**
	 * Stores the outcome of a service call as a notice and redirects back to the screen.
	 *
	 * @param mixed $result Service result.
	 */
	private function finish( $result, array $args, string $success = '' ) {
		if ( is_wp_error( $result ) ) {
			$data    = $result->get_error_data();
			$details = is_array( $data ) && isset( $data['problems'] ) ? (array) $data['problems'] : array();
			$this->notify( 'error', $result->get_error_message(), $details );
		} else {
			$message = '' !== $success ? $success : ( is_array( $result ) && isset( $result['summary'] ) ? (string) $result['summary'] : __( 'Done.', 'cradle-translations' ) );
			if ( is_array( $result ) && isset( $result['written'] ) && ! $result['written'] ) {
				$message = __( 'Nothing changed.', 'cradle-translations' );
			}
			$problems = is_array( $result ) && ! empty( $result['problems'] ) ? (array) $result['problems'] : array();
			$this->notify( $problems ? 'warning' : 'success', $message, $problems );
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Reads one uploaded file with size bounds, without trusting its declared type.
	 *
	 * @return string|\WP_Error
	 */
	private function read_upload( string $field, array $extensions ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by the calling handler.
		$file = $_FILES[ $field ] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_string( $file['tmp_name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'cradle_translations_upload', __( 'No file was uploaded.', 'cradle-translations' ) );
		}
		$name = is_string( $file['name'] ?? null ) ? strtolower( $file['name'] ) : '';
		$ext  = pathinfo( $name, PATHINFO_EXTENSION );
		if ( ! in_array( $ext, $extensions, true ) ) {
			/* translators: %s: list of file extensions */
			return new \WP_Error( 'cradle_translations_upload', sprintf( __( 'Upload a file with one of these extensions: %s.', 'cradle-translations' ), implode( ', ', $extensions ) ) );
		}
		$size = filesize( $file['tmp_name'] );
		if ( false === $size || $size > Config::import_limit() ) {
			/* translators: %s: size such as 4 MB */
			return new \WP_Error( 'cradle_translations_size', sprintf( __( 'The catalog is larger than the %s limit.', 'cradle-translations' ), size_format( Config::import_limit() ) ) );
		}
		$raw = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false === $raw ? new \WP_Error( 'cradle_translations_upload', __( 'The uploaded file could not be read.', 'cradle-translations' ) ) : $raw;
	}

	/* ------------------------------------------------------------------ *
	 * Handlers (admin-post.php)
	 * ------------------------------------------------------------------ */

	public function handle_add_locale() {
		$this->guard( 'add_locale' );
		$locale = trim( $this->post_param( 'new_locale' ) );
		$result = Service::add_locale( $locale );
		$this->finish( $result, is_wp_error( $result ) ? array() : array( 'locale' => $locale ) );
	}

	public function handle_save_entry() {
		$locale = $this->locale_from_post();
		$this->guard( 'save_entry', $locale );
		$id     = $this->post_param( 'entry' );
		$intent = $this->post_param( 'intent' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard(); validated by Validator::check_translation().
		$raw      = isset( $_POST['forms'] ) && is_array( $_POST['forms'] ) ? wp_unslash( $_POST['forms'] ) : array();
		$forms    = array_values( array_map( static fn( $f ) => is_string( $f ) ? $f : '', array_slice( (array) $raw, 0, Config::MAX_PLURAL_FORMS + 1 ) ) );
		$revision = (int) $this->post_param( 'revision' );
		$fuzzy    = '1' === $this->post_param( 'fuzzy' );
		$args     = array(
			'locale' => $locale,
			'entry'  => $id,
		);
		if ( 'discard' === $intent ) {
			$this->finish( Service::discard_entry( $locale, $id, $revision ), $args );
		}
		$result = Service::save_entry( $locale, $id, $forms, $fuzzy, $revision, 'publish' === $intent );
		$this->finish( $result, $args );
	}

	public function handle_add_entry() {
		$locale = $this->locale_from_post();
		$this->guard( 'add_entry', $locale );
		$result = Service::add_entry(
			$locale,
			trim( $this->post_param( 'domain' ) ),
			$this->post_param( 'msgid' ),
			$this->post_param( 'context' ),
			$this->post_param( 'msgid_plural' ),
			'1' === $this->post_param( 'js' ),
			(int) $this->post_param( 'revision' )
		);
		$this->finish(
			$result,
			array(
				'locale' => $locale,
				'entry'  => is_array( $result ) ? ( $result['id'] ?? '' ) : '',
			)
		);
	}

	public function handle_delete_entry() {
		$locale = $this->locale_from_post();
		$this->guard( 'delete_entry', $locale );
		$this->finish( Service::delete_entry( $locale, $this->post_param( 'entry' ), (int) $this->post_param( 'revision' ) ), array( 'locale' => $locale ) );
	}

	public function handle_publish() {
		$locale = $this->locale_from_post();
		$this->guard( 'publish', $locale );
		$this->finish( Service::publish( $locale, (int) $this->post_param( 'revision' ) ), array( 'locale' => $locale ) );
	}

	public function handle_discard_all() {
		$locale = $this->locale_from_post();
		$this->guard( 'discard_all', $locale );
		$this->finish( Service::discard_all( $locale, (int) $this->post_param( 'revision' ) ), array( 'locale' => $locale ) );
	}

	public function handle_export() {
		$locale = $this->locale_from_post();
		$this->guard( 'export', $locale );
		$json = Service::export_json( $locale );
		if ( is_wp_error( $json ) ) {
			$this->finish(
				$json,
				array(
					'locale' => $locale,
					'tab'    => 'import',
				)
			);
		}
		cradle_log_activity( Config::MODULE, 'catalog_exported', sprintf( 'Translation catalog %s exported.', $locale ), array( 'locale' => $locale ) );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="cradle-translations-' . $locale . '.json"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON file download.
		exit;
	}

	public function handle_export_pot() {
		$this->guard( 'export_pot' );
		$domain   = $this->post_param( 'domain' );
		$manifest = Store::manifest();
		if ( ! $manifest || ! Config::valid_domain( $domain ) ) {
			$this->finish( new \WP_Error( 'cradle_translations_pot', __( 'Refresh the source strings first.', 'cradle-translations' ) ), array( 'tab' => 'sources' ) );
		}
		nocache_headers();
		header( 'Content-Type: text/x-gettext-translation; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . $domain . '.pot"' );
		echo Extractor::pot( $manifest, $domain ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- POT file download.
		exit;
	}

	public function handle_import_json() {
		$locale = $this->locale_from_post();
		$this->guard( 'import_json', $locale );
		$args = array(
			'locale' => $locale,
			'tab'    => 'import',
		);
		$raw  = $this->read_upload( 'catalog', array( 'json' ) );
		if ( is_wp_error( $raw ) ) {
			$this->finish( $raw, $args );
		}
		$this->finish( Service::import_json( $locale, $raw, '1' === $this->post_param( 'publish' ), (int) $this->post_param( 'revision' ) ), $args );
	}

	public function handle_import_po() {
		$locale = $this->locale_from_post();
		$this->guard( 'import_po', $locale );
		$args = array(
			'locale' => $locale,
			'tab'    => 'import',
		);
		$raw  = $this->read_upload( 'catalog', array( 'po', 'pot' ) );
		if ( is_wp_error( $raw ) ) {
			$this->finish( $raw, $args );
		}
		$result = Service::import_po( $locale, $raw, trim( $this->post_param( 'domain' ) ), '1' === $this->post_param( 'with_translations' ), '1' === $this->post_param( 'mark_obsolete' ), (int) $this->post_param( 'revision' ) );
		$this->finish( $result, $args );
	}

	public function handle_refresh() {
		$this->guard( 'refresh' );
		$result = Service::refresh_sources();
		if ( is_wp_error( $result ) ) {
			$this->finish( $result, array( 'tab' => 'sources' ) );
		}
		$details = array();
		foreach ( $result['locales'] as $locale => $line ) {
			$details[] = $locale . ': ' . $line;
		}
		$this->notify(
			'success',
			/* translators: %d: number of source strings */
			sprintf( __( 'Source strings refreshed: %d strings found in Cradle packages.', 'cradle-translations' ), count( $result['manifest']['entries'] ) ),
			$details
		);
		wp_safe_redirect(
			self::url(
				array(
					'tab'    => 'sources',
					'locale' => $this->post_param( 'locale' ),
				)
			)
		);
		exit;
	}

	public function handle_restore() {
		$locale = $this->locale_from_post();
		$this->guard( 'restore', $locale );
		$this->finish(
			Service::restore( $locale, (int) $this->post_param( 'target' ), (int) $this->post_param( 'revision' ) ),
			array(
				'locale' => $locale,
				'tab'    => 'revisions',
			)
		);
	}

	public function handle_delete_locale() {
		$locale = $this->locale_from_post();
		$this->guard( 'delete_locale', $locale );
		if ( $this->post_param( 'confirm_locale' ) !== $locale ) {
			$this->finish(
				new \WP_Error( 'cradle_translations_confirm', __( 'Type the locale code to confirm deleting the catalog.', 'cradle-translations' ) ),
				array(
					'locale' => $locale,
					'tab'    => 'import',
				)
			);
		}
		$result = Service::delete_locale( $locale );
		/* translators: %s: locale code */
		$this->finish( $result, is_wp_error( $result ) ? array( 'locale' => $locale ) : array(), sprintf( __( 'Catalog %s and its history were deleted.', 'cradle-translations' ), $locale ) );
	}

	/* ------------------------------------------------------------------ *
	 * Rendering
	 * ------------------------------------------------------------------ */

	private static function nonce_field( string $action, string $locale = '' ) {
		wp_nonce_field( 'cradle_translations_' . $action . ( '' !== $locale ? '_' . $locale : '' ) );
	}

	private static function form_open( string $action, string $locale = '', array $attrs = array() ) {
		$extra = '';
		foreach ( $attrs as $name => $value ) {
			$extra .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped above.
		echo '<input type="hidden" name="action" value="' . esc_attr( 'cradle_translations_' . $action ) . '" />';
		if ( '' !== $locale ) {
			echo '<input type="hidden" name="locale" value="' . esc_attr( $locale ) . '" />';
		}
		self::nonce_field( $action, $locale );
	}

	private static function status_label( string $status ): string {
		$labels = array(
			'missing'    => __( 'Missing', 'cradle-translations' ),
			'translated' => __( 'Translated', 'cradle-translations' ),
			'fuzzy'      => __( 'Needs review', 'cradle-translations' ),
			'obsolete'   => __( 'Obsolete', 'cradle-translations' ),
			'draft'      => __( 'Draft pending', 'cradle-translations' ),
		);
		return $labels[ $status ] ?? $status;
	}

	private static function source_label( string $source ): string {
		$labels = array(
			'extracted'  => __( 'Cradle source', 'cradle-translations' ),
			'imported'   => __( 'Imported catalog', 'cradle-translations' ),
			'manual'     => __( 'Added manually', 'cradle-translations' ),
			'registered' => __( 'Registered label', 'cradle-translations' ),
		);
		return $labels[ $source ] ?? $source;
	}

	private static function badge( string $status ): string {
		return '<span class="ct-badge ct-badge--' . esc_attr( $status ) . '">' . esc_html( self::status_label( $status ) ) . '</span>';
	}

	private static function locale_label( string $locale ): string {
		$name  = Plural_Rules::autonym( $locale );
		$label = '' !== $name ? $name . ' (' . $locale . ')' : $locale;
		/* translators: %s: language name and code */
		return Examples::is_example( $locale ) ? sprintf( __( '%s — built-in example', 'cradle-translations' ), $label ) : $label;
	}

	public function render() {
		if ( ! current_user_can( Config::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to manage translations.', 'cradle-translations' ), 403 );
		}
		$locales = Store::locales();
		$locale  = self::get_param( 'locale' );
		if ( ! in_array( $locale, $locales, true ) ) {
			$locale = $locales[0] ?? '';
		}
		$tab = self::get_param( 'tab' );
		$tab = in_array( $tab, self::TABS, true ) ? $tab : 'strings';

		echo '<div class="wrap cradle-wrap ct-wrap">';
		echo '<h1>' . esc_html__( 'Translations', 'cradle-translations' ) . '</h1>';
		$this->render_notice();
		echo '<p class="description ct-scope">' . esc_html__( 'Overrides interface strings exposed through WordPress translation functions, Cradle-registered labels and script translations. It does not translate page content, menus or permalinks; multilingual content needs a separate design.', 'cradle-translations' ) . '</p>';
		$this->render_locale_bar( $locales, $locale );

		echo '<nav class="nav-tab-wrapper ct-tabs" aria-label="' . esc_attr__( 'Translation sections', 'cradle-translations' ) . '">';
		$labels = array(
			'strings'   => __( 'Strings', 'cradle-translations' ),
			'import'    => __( 'Import & export', 'cradle-translations' ),
			'sources'   => __( 'Sources', 'cradle-translations' ),
			'revisions' => __( 'Revisions', 'cradle-translations' ),
			'settings'  => __( 'Settings', 'cradle-translations' ),
		);
		foreach ( $labels as $key => $label ) {
			$current = $key === $tab;
			echo '<a class="nav-tab' . ( $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url(
				self::url(
					array(
						'tab'    => $key,
						'locale' => $locale,
					)
				)
			) . '"' . ( $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav><div class="ct-panel">';

		$catalog = null;
		if ( '' !== $locale && in_array( $tab, array( 'strings', 'import', 'revisions' ), true ) ) {
			$catalog = Store::read( $locale );
			if ( is_wp_error( $catalog ) ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $catalog->get_error_message() ) . '</p></div>';
				$catalog = null;
			}
		}
		switch ( $tab ) {
			case 'import':
				$this->render_import( $locale, $catalog );
				break;
			case 'sources':
				$this->render_sources( $locale );
				break;
			case 'revisions':
				$this->render_revisions( $locale, $catalog );
				break;
			case 'settings':
				echo '<h2>' . esc_html__( 'Settings', 'cradle-translations' ) . '</h2>';
				cradle()->settings()->render_form( 'translations' );
				break;
			default:
				if ( ! $catalog ) {
					echo '<p>' . esc_html__( 'Add a locale above to create its catalog. The catalog is filled with the strings found in Cradle packages; you can import WordPress or WooCommerce source catalogs afterwards.', 'cradle-translations' ) . '</p>';
					break;
				}
				$entry_id = self::get_param( 'entry' );
				if ( preg_match( '/^e[0-9a-f]{16}$/', $entry_id ) ) {
					$this->render_editor( $catalog, $entry_id );
				} else {
					$this->render_strings( $catalog );
				}
		}
		echo '</div></div>';
	}

	private function render_notice() {
		$notice = get_transient( self::notice_key() );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( self::notice_key() );
		$type = in_array( $notice['type'] ?? '', array( 'success', 'error', 'warning' ), true ) ? $notice['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible" role="' . ( 'error' === $type ? 'alert' : 'status' ) . '"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</p>';
		if ( ! empty( $notice['details'] ) ) {
			echo '<ul class="ul-disc">';
			foreach ( (array) $notice['details'] as $line ) {
				echo '<li>' . esc_html( (string) $line ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	private function render_locale_bar( array $locales, string $locale ) {
		echo '<div class="ct-locale-bar">';
		if ( $locales ) {
			echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="ct-inline">';
			echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
			echo '<label for="ct-locale">' . esc_html__( 'Catalog', 'cradle-translations' ) . '</label> ';
			echo '<select id="ct-locale" name="locale" data-ct-autosubmit>';
			foreach ( $locales as $code ) {
				echo '<option value="' . esc_attr( $code ) . '"' . selected( $code, $locale, false ) . '>' . esc_html( self::locale_label( $code ) ) . '</option>';
			}
			echo '</select> ';
			submit_button( __( 'Open', 'cradle-translations' ), 'secondary', '', false );
			echo '</form>';
		}
		self::form_open( 'add_locale', '', array( 'class' => 'ct-inline' ) );
		echo '<label for="ct-new-locale">' . esc_html__( 'Add locale', 'cradle-translations' ) . '</label> ';
		echo '<input type="text" id="ct-new-locale" name="new_locale" class="regular-text ct-locale-input" list="ct-known-locales" pattern="[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]{2,20})?" placeholder="de_DE" aria-describedby="ct-new-locale-help" required /> ';
		echo '<datalist id="ct-known-locales">';
		foreach ( array_keys( Plural_Rules::LANGUAGES ) as $code ) {
			echo '<option value="' . esc_attr( $code ) . '">' . esc_html( Plural_Rules::autonym( $code ) ) . '</option>';
		}
		echo '</datalist>';
		submit_button( __( 'Add locale', 'cradle-translations' ), 'secondary', '', false );
		echo '<p id="ct-new-locale-help" class="description">' . esc_html__( 'A WordPress locale code such as de_DE, fr_FR, ar, ja or pt_BR.', 'cradle-translations' ) . '</p>';
		echo '</form></div>';
	}

	private function render_strings( array $catalog ) {
		$locale = $catalog['locale'];
		$stats  = Catalog::stats( $catalog );
		$index  = Store::index()['locales'][ $locale ] ?? null;
		$live   = $index ? array_sum( $index['domains'] ) : 0;

		echo '<h2>' . esc_html( self::locale_label( $locale ) ) . '</h2>';
		echo '<p class="ct-stats">';
		echo esc_html(
			sprintf(
				/* translators: 1: total, 2: translated, 3: missing, 4: needs review, 5: obsolete */
				__( '%1$d strings: %2$d translated, %3$d missing, %4$d need review, %5$d obsolete.', 'cradle-translations' ),
				$stats['total'],
				$stats['translated'],
				$stats['missing'],
				$stats['fuzzy'],
				$stats['obsolete']
			)
		);
		echo ' ';
		echo esc_html(
			sprintf(
				/* translators: 1: revision, 2: published revision, 3: live overrides, 4: plural forms */
				__( 'Revision %1$d; published at revision %2$d; %3$d overrides live; %4$d plural forms.', 'cradle-translations' ),
				$catalog['revision'],
				$catalog['publishedRevision'],
				$live,
				Plural_Rules::nplurals( $locale )
			)
		);
		if ( ! Plural_Rules::is_known( $locale ) ) {
			echo ' <strong>' . esc_html__( 'The plural rule of this locale is unknown; the two-form rule (n != 1) is used.', 'cradle-translations' ) . '</strong>';
		}
		echo '</p>';

		if ( $stats['drafts'] ) {
			echo '<div class="ct-publish-bar">';
			/* translators: %d: number of drafts */
			echo '<p><strong>' . esc_html( sprintf( _n( '%d draft is not published yet.', '%d drafts are not published yet.', $stats['drafts'], 'cradle-translations' ), $stats['drafts'] ) ) . '</strong> ' . esc_html__( 'Drafts are not used on the site until you publish them.', 'cradle-translations' ) . '</p>';
			self::form_open( 'publish', $locale, array( 'class' => 'ct-inline' ) );
			echo '<input type="hidden" name="revision" value="' . esc_attr( (string) $catalog['revision'] ) . '" />';
			submit_button( __( 'Publish all drafts', 'cradle-translations' ), 'primary', '', false );
			echo '</form> ';
			self::form_open(
				'discard_all',
				$locale,
				array(
					'class'           => 'ct-inline',
					'data-ct-confirm' => __( 'Discard every unpublished draft in this catalog?', 'cradle-translations' ),
				)
			);
			echo '<input type="hidden" name="revision" value="' . esc_attr( (string) $catalog['revision'] ) . '" />';
			submit_button( __( 'Discard all drafts', 'cradle-translations' ), 'secondary', '', false );
			echo '</form></div>';
		}

		$filters = array(
			'domain' => self::get_param( 'domain' ),
			'status' => self::get_param( 'status' ),
			'source' => self::get_param( 'source' ),
			'search' => mb_substr( self::get_param( 's' ), 0, 200 ),
		);
		if ( ! isset( $stats['domains'][ $filters['domain'] ] ) ) {
			$filters['domain'] = '';
		}
		if ( ! in_array( $filters['status'], self::STATUSES, true ) ) {
			$filters['status'] = '';
		}
		if ( ! in_array( $filters['source'], Validator::SOURCES, true ) ) {
			$filters['source'] = '';
		}

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="ct-filters" role="search">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="hidden" name="locale" value="' . esc_attr( $locale ) . '" />';
		echo '<label for="ct-search">' . esc_html__( 'Search', 'cradle-translations' ) . '</label> <input type="search" id="ct-search" name="s" value="' . esc_attr( $filters['search'] ) . '" /> ';
		echo '<label for="ct-domain">' . esc_html__( 'Text domain', 'cradle-translations' ) . '</label> <select id="ct-domain" name="domain"><option value="">' . esc_html__( 'All domains', 'cradle-translations' ) . '</option>';
		foreach ( $stats['domains'] as $domain => $count ) {
			echo '<option value="' . esc_attr( (string) $domain ) . '"' . selected( $domain, $filters['domain'], false ) . '>' . esc_html( $domain . ' (' . $count . ')' ) . '</option>';
		}
		echo '</select> ';
		echo '<label for="ct-status">' . esc_html__( 'Status', 'cradle-translations' ) . '</label> <select id="ct-status" name="status"><option value="">' . esc_html__( 'Any status', 'cradle-translations' ) . '</option>';
		foreach ( self::STATUSES as $status ) {
			echo '<option value="' . esc_attr( $status ) . '"' . selected( $status, $filters['status'], false ) . '>' . esc_html( self::status_label( $status ) ) . '</option>';
		}
		echo '</select> ';
		echo '<label for="ct-source">' . esc_html__( 'Source', 'cradle-translations' ) . '</label> <select id="ct-source" name="source"><option value="">' . esc_html__( 'Any source', 'cradle-translations' ) . '</option>';
		foreach ( Validator::SOURCES as $source ) {
			echo '<option value="' . esc_attr( $source ) . '"' . selected( $source, $filters['source'], false ) . '>' . esc_html( self::source_label( $source ) ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'cradle-translations' ), 'secondary', '', false );
		echo '</form>';

		$rows     = Catalog::query( $catalog, $filters );
		$per_page = max( 20, min( 200, (int) Config::get( 'per_page' ) ) );
		$total    = count( $rows );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$paged    = min( $pages, max( 1, (int) self::get_param( 'paged' ) ) );
		$rows     = array_slice( $rows, ( $paged - 1 ) * $per_page, $per_page );
		$base     = array(
			'locale' => $locale,
			'domain' => $filters['domain'],
			'status' => $filters['status'],
			'source' => $filters['source'],
			's'      => $filters['search'],
		);

		/* translators: %d: number of matching strings */
		echo '<p class="ct-count" aria-live="polite">' . esc_html( sprintf( _n( '%d matching string', '%d matching strings', $total, 'cradle-translations' ), $total ) ) . '</p>';
		echo '<table class="widefat striped ct-table"><thead><tr>';
		foreach ( array( __( 'Source text', 'cradle-translations' ), __( 'Context', 'cradle-translations' ), __( 'Domain', 'cradle-translations' ), __( 'Translation', 'cradle-translations' ), __( 'Status', 'cradle-translations' ), __( 'Source location', 'cradle-translations' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No strings match these filters.', 'cradle-translations' ) . '</td></tr>';
		}
		$dir = Plural_Rules::is_rtl( $locale ) ? 'rtl' : 'ltr';
		foreach ( $rows as $row ) {
			$entry = $row['entry'];
			$link  = self::url(
				$base + array(
					'entry' => $entry['id'],
					'paged' => $paged > 1 ? (string) $paged : '',
				)
			);
			echo '<tr><td class="ct-source-cell"><a href="' . esc_url( $link ) . '" class="ct-source">' . esc_html( $entry['msgid'] ) . '</a>';
			if ( null !== $entry['msgidPlural'] ) {
				echo '<br /><span class="ct-plural">' . esc_html__( 'Plural:', 'cradle-translations' ) . ' ' . esc_html( $entry['msgidPlural'] ) . '</span>';
			}
			echo '</td><td>' . ( null !== $entry['context'] ? '<span class="ct-context">' . esc_html( $entry['context'] ) . '</span>' : '<span aria-hidden="true">—</span>' ) . '</td>';
			echo '<td><code>' . esc_html( $row['domain'] ) . '</code></td>';
			echo '<td lang="' . esc_attr( str_replace( '_', '-', $locale ) ) . '" dir="' . esc_attr( $dir ) . '">';
			foreach ( $entry['translations'] as $i => $form ) {
				echo '<span class="ct-form">' . ( count( $entry['translations'] ) > 1 ? '<span class="ct-form-n">' . esc_html( (string) ( $i + 1 ) ) . '</span> ' : '' ) . esc_html( $form ) . '</span>';
			}
			if ( null !== $entry['draft'] ) {
				echo '<span class="ct-draft"><span class="ct-badge ct-badge--draft">' . esc_html__( 'Draft', 'cradle-translations' ) . '</span> ' . esc_html( $entry['draft'] ? implode( ' | ', $entry['draft'] ) : __( '(remove translation)', 'cradle-translations' ) ) . '</span>';
			}
			echo '</td><td>' . self::badge( $entry['status'] ) . '</td><td class="ct-refs">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
			$refs = $entry['references'];
			foreach ( array_slice( $refs, 0, 2 ) as $ref ) {
				echo '<code>' . esc_html( $ref ) . '</code><br />';
			}
			if ( count( $refs ) > 2 ) {
				/* translators: %d: number of additional references */
				echo esc_html( sprintf( __( '+%d more', 'cradle-translations' ), count( $refs ) - 2 ) );
			}
			if ( ! $refs ) {
				echo esc_html( self::source_label( $entry['source'] ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		if ( $pages > 1 ) {
			echo '<nav class="tablenav ct-pagination" aria-label="' . esc_attr__( 'Pages', 'cradle-translations' ) . '"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%', self::url( $base ) ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $pages,
						'prev_text' => __( '« Previous', 'cradle-translations' ),
						'next_text' => __( 'Next »', 'cradle-translations' ),
					)
				)
			);
			echo '</div></nav>';
		}

		echo '<details class="ct-add"><summary>' . esc_html__( 'Add a string that is not listed', 'cradle-translations' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'For WordPress, WooCommerce or other strings that extraction cannot discover. The text, context and plural must match the source code exactly.', 'cradle-translations' ) . '</p>';
		self::form_open( 'add_entry', $locale );
		echo '<input type="hidden" name="revision" value="' . esc_attr( (string) $catalog['revision'] ) . '" />';
		echo '<p><label for="ct-add-domain">' . esc_html__( 'Text domain', 'cradle-translations' ) . '</label><br /><input type="text" id="ct-add-domain" name="domain" class="regular-text" value="default" required /></p>';
		echo '<p><label for="ct-add-msgid">' . esc_html__( 'Source text', 'cradle-translations' ) . '</label><br /><textarea id="ct-add-msgid" name="msgid" rows="2" class="large-text" required></textarea></p>';
		echo '<p><label for="ct-add-context">' . esc_html__( 'Context (optional)', 'cradle-translations' ) . '</label><br /><input type="text" id="ct-add-context" name="context" class="regular-text" /></p>';
		echo '<p><label for="ct-add-plural">' . esc_html__( 'Plural source text (optional)', 'cradle-translations' ) . '</label><br /><input type="text" id="ct-add-plural" name="msgid_plural" class="large-text" /></p>';
		echo '<p><label><input type="checkbox" name="js" value="1" /> ' . esc_html__( 'Also used by JavaScript', 'cradle-translations' ) . '</label></p>';
		submit_button( __( 'Add string', 'cradle-translations' ), 'secondary', '', false );
		echo '</form></details>';
	}

	private function render_editor( array $catalog, string $id ) {
		$locale = $catalog['locale'];
		$found  = Catalog::find( $catalog, $id );
		$back   = self::url(
			array(
				'locale' => $locale,
				'domain' => self::get_param( 'domain' ),
				'status' => self::get_param( 'status' ),
				'source' => self::get_param( 'source' ),
				's'      => self::get_param( 's' ),
				'paged'  => self::get_param( 'paged' ),
			)
		);
		echo '<p><a href="' . esc_url( $back ) . '">' . esc_html__( '← Back to the string list', 'cradle-translations' ) . '</a></p>';
		if ( ! $found ) {
			echo '<p>' . esc_html__( 'This string is not in the catalog.', 'cradle-translations' ) . '</p>';
			return;
		}
		list( $domain, , $entry ) = $found;
		$plural                   = null !== $entry['msgidPlural'];
		$tokens                   = Validator::source_tokens( $entry );
		$lang                     = str_replace( '_', '-', $locale );
		$dir                      = Plural_Rules::is_rtl( $locale ) ? 'rtl' : 'ltr';

		echo '<h2>' . esc_html__( 'Edit translation', 'cradle-translations' ) . ' ' . self::badge( $entry['status'] ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
		echo '<dl class="ct-meta">';
		echo '<dt>' . esc_html__( 'Source text', 'cradle-translations' ) . '</dt><dd><span class="ct-source-text" lang="en" dir="ltr">' . esc_html( $entry['msgid'] ) . '</span></dd>';
		if ( $plural ) {
			echo '<dt>' . esc_html__( 'Plural source text', 'cradle-translations' ) . '</dt><dd><span class="ct-source-text" lang="en" dir="ltr">' . esc_html( $entry['msgidPlural'] ) . '</span></dd>';
		}
		echo '<dt>' . esc_html__( 'Context', 'cradle-translations' ) . '</dt><dd>' . ( null !== $entry['context'] ? esc_html( $entry['context'] ) : esc_html__( 'None', 'cradle-translations' ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Text domain', 'cradle-translations' ) . '</dt><dd><code>' . esc_html( $domain ) . '</code></dd>';
		echo '<dt>' . esc_html__( 'Origin', 'cradle-translations' ) . '</dt><dd>' . esc_html( self::source_label( $entry['source'] ) ) . ( $entry['js'] ? ' — ' . esc_html__( 'used by JavaScript', 'cradle-translations' ) : '' ) . '</dd>';
		if ( '' !== $entry['comments'] ) {
			echo '<dt>' . esc_html__( 'Note for translators', 'cradle-translations' ) . '</dt><dd>' . esc_html( $entry['comments'] ) . '</dd>';
		}
		echo '<dt>' . esc_html__( 'Source location', 'cradle-translations' ) . '</dt><dd>';
		if ( $entry['references'] ) {
			echo '<ul class="ct-ref-list">';
			foreach ( $entry['references'] as $ref ) {
				echo '<li><code>' . esc_html( $ref ) . '</code></li>';
			}
			echo '</ul>';
		} else {
			echo esc_html__( 'Not available', 'cradle-translations' );
		}
		echo '</dd>';
		if ( $tokens ) {
			echo '<dt>' . esc_html__( 'Placeholders', 'cradle-translations' ) . '</dt><dd>';
			foreach ( $tokens as $token ) {
				echo '<code>' . esc_html( $token ) . '</code> ';
			}
			echo '</dd>';
		}
		echo '</dl>';
		if ( $entry['obsolete'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This string no longer appears in the current source. Its translation is kept for review and still applies if the string is used again.', 'cradle-translations' ) . '</p></div>';
		}

		$current = null !== $entry['draft'] ? $entry['draft'] : $entry['translations'];
		$fuzzy   = null !== $entry['draft'] ? (bool) $entry['draftFuzzy'] : (bool) $entry['fuzzy'];
		$count   = $plural ? Plural_Rules::nplurals( $locale ) : 1;
		$samples = Plural_Rules::samples( $locale );

		self::form_open(
			'save_entry',
			$locale,
			array(
				'class'                => 'ct-editor',
				'data-ct-placeholders' => (string) wp_json_encode( $tokens ),
			)
		);
		echo '<input type="hidden" name="entry" value="' . esc_attr( $entry['id'] ) . '" />';
		echo '<input type="hidden" name="revision" value="' . esc_attr( (string) $catalog['revision'] ) . '" />';
		for ( $i = 0; $i < $count; $i++ ) {
			$field_id = 'ct-form-' . $i;
			if ( $plural ) {
				/* translators: 1: plural form number, 2: example numbers */
				$label = sprintf( __( 'Plural form %1$d (used for n = %2$s…)', 'cradle-translations' ), $i + 1, implode( ', ', $samples[ $i ] ?? array() ) );
			} else {
				$label = __( 'Translation', 'cradle-translations' );
			}
			echo '<p class="ct-field"><label for="' . esc_attr( $field_id ) . '">' . esc_html( $label ) . '</label><br />';
			echo '<textarea id="' . esc_attr( $field_id ) . '" name="forms[]" rows="3" class="large-text ct-translation" lang="' . esc_attr( $lang ) . '" dir="' . esc_attr( $dir ) . '" aria-describedby="ct-check-' . esc_attr( (string) $i ) . '">' . esc_textarea( $current[ $i ] ?? '' ) . '</textarea>';
			echo '<span id="ct-check-' . esc_attr( (string) $i ) . '" class="ct-check" aria-live="polite"></span></p>';
		}
		echo '<p><label><input type="checkbox" name="fuzzy" value="1"' . checked( $fuzzy, true, false ) . ' /> ' . esc_html__( 'Needs review (not used on the site while checked)', 'cradle-translations' ) . '</label></p>';
		if ( null !== $entry['draft'] && $entry['translations'] ) {
			echo '<p class="description">' . esc_html__( 'Published translation:', 'cradle-translations' ) . ' <span lang="' . esc_attr( $lang ) . '" dir="' . esc_attr( $dir ) . '">' . esc_html( implode( ' | ', $entry['translations'] ) ) . '</span></p>';
		}
		echo '<p class="description">' . esc_html__( 'Leave every field empty and save to remove the translation. Allowed HTML: tags the source text already uses (and line breaks); links must keep the source address.', 'cradle-translations' ) . '</p>';
		echo '<p class="submit">';
		echo '<button type="submit" name="intent" value="draft" class="button">' . esc_html__( 'Save draft', 'cradle-translations' ) . '</button> ';
		echo '<button type="submit" name="intent" value="publish" class="button button-primary">' . esc_html__( 'Save and publish', 'cradle-translations' ) . '</button> ';
		if ( null !== $entry['draft'] ) {
			echo '<button type="submit" name="intent" value="discard" class="button" formnovalidate>' . esc_html__( 'Discard draft', 'cradle-translations' ) . '</button>';
		}
		echo '</p></form>';

		if ( Service::deletable( $entry ) ) {
			self::form_open(
				'delete_entry',
				$locale,
				array(
					'class'           => 'ct-delete',
					'data-ct-confirm' => __( 'Delete this string and its translation from the catalog?', 'cradle-translations' ),
				)
			);
			echo '<input type="hidden" name="entry" value="' . esc_attr( $entry['id'] ) . '" />';
			echo '<input type="hidden" name="revision" value="' . esc_attr( (string) $catalog['revision'] ) . '" />';
			submit_button( __( 'Delete string', 'cradle-translations' ), 'delete small', '', false );
			echo '</form>';
		}
	}

	private function render_import( string $locale, ?array $catalog ) {
		if ( ! $catalog ) {
			echo '<p>' . esc_html__( 'Add or open a locale first.', 'cradle-translations' ) . '</p>';
			return;
		}
		$limit = size_format( Config::import_limit() );
		echo '<h2>' . esc_html__( 'Export', 'cradle-translations' ) . '</h2>';
		echo '<p>' . esc_html__( 'Download the authoritative JSON catalog of this locale, including drafts.', 'cradle-translations' ) . '</p>';
		self::form_open( 'export', $locale );
		submit_button( __( 'Export JSON catalog', 'cradle-translations' ), 'secondary', '', false );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Import a Cradle JSON catalog', 'cradle-translations' ) . '</h2>';
		/* translators: %s: maximum size */
		echo '<p>' . esc_html( sprintf( __( 'Merges a catalog exported from Cradle for the same locale. Matching strings receive the file\'s translations as drafts; new strings are added. Invalid files change nothing. Maximum size: %s.', 'cradle-translations' ), $limit ) ) . '</p>';
		self::form_open(
			'import_json',
			$locale,
			array( 'enctype' => 'multipart/form-data' )
		);
		echo '<input type="hidden" name="revision" value="' . esc_attr( (string) $catalog['revision'] ) . '" />';
		echo '<p><label for="ct-import-json">' . esc_html__( 'Catalog file (.json)', 'cradle-translations' ) . '</label><br /><input type="file" id="ct-import-json" name="catalog" accept=".json,application/json" required /></p>';
		echo '<p><label><input type="checkbox" name="publish" value="1" /> ' . esc_html__( 'Publish imported translations immediately', 'cradle-translations' ) . '</label></p>';
		submit_button( __( 'Import JSON', 'cradle-translations' ), 'secondary', '', false );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Import a source catalog (.po or .pot)', 'cradle-translations' ) . '</h2>';
		echo '<p>' . esc_html__( 'Adds the strings of a WordPress, WooCommerce or other text domain so they can be overridden here. Only UTF-8 gettext files are accepted; nothing in the file is executed. Plural rules in the file are never evaluated.', 'cradle-translations' ) . '</p>';
		self::form_open(
			'import_po',
			$locale,
			array( 'enctype' => 'multipart/form-data' )
		);
		echo '<input type="hidden" name="revision" value="' . esc_attr( (string) $catalog['revision'] ) . '" />';
		echo '<p><label for="ct-po-domain">' . esc_html__( 'Text domain of the file', 'cradle-translations' ) . '</label><br /><input type="text" id="ct-po-domain" name="domain" class="regular-text" list="ct-po-domains" value="default" required /><datalist id="ct-po-domains"><option value="default"></option><option value="woocommerce"></option></datalist></p>';
		echo '<p><label for="ct-import-po">' . esc_html__( 'Catalog file (.po or .pot)', 'cradle-translations' ) . '</label><br /><input type="file" id="ct-import-po" name="catalog" accept=".po,.pot" required /></p>';
		echo '<p><label><input type="checkbox" name="with_translations" value="1" /> ' . esc_html__( 'Also import translations from a .po file as drafts (invalid ones are skipped)', 'cradle-translations' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="mark_obsolete" value="1" checked /> ' . esc_html__( 'Mark previously imported strings of this domain that are missing from the file as obsolete (translations are kept)', 'cradle-translations' ) . '</label></p>';
		submit_button( __( 'Import source catalog', 'cradle-translations' ), 'secondary', '', false );
		echo '</form>';

		if ( Examples::is_example( $locale ) ) {
			echo '<h2>' . esc_html__( 'Built-in example language', 'cradle-translations' ) . '</h2>';
			echo '<p>' . esc_html__( 'This language ships with Cradle Translations and is always available. Your edits always win over the shipped text; clearing an entry brings the shipped translation back after the next source refresh. It cannot be deleted.', 'cradle-translations' ) . '</p>';
			return;
		}
		echo '<h2>' . esc_html__( 'Delete this catalog', 'cradle-translations' ) . '</h2>';
		echo '<p>' . esc_html__( 'Deletes the catalog, its drafts and its revision history, and stops its overrides. Export it first if you may need it again.', 'cradle-translations' ) . '</p>';
		self::form_open(
			'delete_locale',
			$locale,
			array( 'data-ct-confirm' => __( 'Delete this catalog and its history permanently?', 'cradle-translations' ) )
		);
		/* translators: %s: locale code */
		echo '<p><label for="ct-confirm-locale">' . esc_html( sprintf( __( 'Type %s to confirm', 'cradle-translations' ), $locale ) ) . '</label><br /><input type="text" id="ct-confirm-locale" name="confirm_locale" class="regular-text" autocomplete="off" required /></p>';
		submit_button( __( 'Delete catalog', 'cradle-translations' ), 'delete', '', false );
		echo '</form>';
	}

	private function render_sources( string $locale ) {
		$manifest = Store::manifest();
		$drift    = Service::source_drift();
		echo '<h2>' . esc_html__( 'Source strings', 'cradle-translations' ) . '</h2>';
		echo '<p>' . esc_html__( 'Strings are extracted from Cradle plugins and themes only, by reading their PHP and JavaScript source with a tokenizer. Nothing is executed and no other path or URL is accepted. Refreshing keeps every translation; strings that disappeared are marked obsolete for review.', 'cradle-translations' ) . '</p>';
		if ( ! $manifest ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No source manifest yet.', 'cradle-translations' ) . '</p></div>';
		} elseif ( $drift['changed'] ) {
			/* translators: %s: list of packages */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'Cradle packages changed since the last extraction: %s. Refresh to update the catalogs.', 'cradle-translations' ), implode( ', ', $drift['changed'] ) ) ) . '</p></div>';
		}
		self::form_open( 'refresh' );
		echo '<input type="hidden" name="locale" value="' . esc_attr( $locale ) . '" />';
		submit_button( __( 'Refresh source strings', 'cradle-translations' ), 'primary', '', false );
		echo '</form>';
		if ( ! $manifest ) {
			return;
		}
		$stats = (array) $manifest['stats'];
		/* translators: 1: date, 2: strings, 3: files, 4: skipped files */
		echo '<p>' . esc_html( sprintf( __( 'Last extraction: %1$s — %2$d strings from %3$d files (%4$d files skipped as too large).', 'cradle-translations' ), (string) $manifest['generated'], count( $manifest['entries'] ), (int) ( $stats['files'] ?? 0 ), (int) ( $stats['skipped'] ?? 0 ) ) ) . '</p>';
		if ( ! empty( $stats['truncated'] ) ) {
			echo '<p><strong>' . esc_html__( 'Extraction limits were reached; some files or strings were not processed.', 'cradle-translations' ) . '</strong></p>';
		}
		echo '<table class="widefat striped ct-table"><thead><tr><th scope="col">' . esc_html__( 'Package', 'cradle-translations' ) . '</th><th scope="col">' . esc_html__( 'Version', 'cradle-translations' ) . '</th><th scope="col">' . esc_html__( 'Files', 'cradle-translations' ) . '</th><th scope="col">' . esc_html__( 'Strings', 'cradle-translations' ) . '</th></tr></thead><tbody>';
		foreach ( (array) $manifest['packages'] as $key => $package ) {
			echo '<tr><td>' . esc_html( (string) $package['name'] ) . ' <code>' . esc_html( (string) $key ) . '</code></td><td>' . esc_html( (string) $package['version'] ) . '</td><td>' . esc_html( (string) $package['files'] ) . '</td><td>' . esc_html( (string) $package['strings'] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$domains = array();
		foreach ( $manifest['entries'] as $entry ) {
			$domains[ $entry['domain'] ] = ( $domains[ $entry['domain'] ] ?? 0 ) + 1;
		}
		ksort( $domains );
		echo '<h3>' . esc_html__( 'Text domains', 'cradle-translations' ) . '</h3><ul class="ct-domains">';
		foreach ( $domains as $domain => $count ) {
			echo '<li><code>' . esc_html( (string) $domain ) . '</code> — ' . esc_html( (string) $count ) . ' ';
			self::form_open( 'export_pot', '', array( 'class' => 'ct-inline' ) );
			echo '<input type="hidden" name="domain" value="' . esc_attr( (string) $domain ) . '" />';
			/* translators: %s: text domain */
			echo '<button type="submit" class="button-link">' . esc_html( sprintf( __( 'Download %s.pot', 'cradle-translations' ), $domain ) ) . '</button></form></li>';
		}
		echo '</ul>';

		echo '<h3>' . esc_html__( 'Calls that cannot be extracted', 'cradle-translations' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Translation calls whose text, context or domain is not a string literal (variables, constants, concatenated expressions, template literals with interpolation). Add these strings manually if they need overrides.', 'cradle-translations' ) . '</p>';
		if ( ! $manifest['dynamic'] ) {
			echo '<p>' . esc_html__( 'None found.', 'cradle-translations' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped ct-table"><thead><tr><th scope="col">' . esc_html__( 'Location', 'cradle-translations' ) . '</th><th scope="col">' . esc_html__( 'Function', 'cradle-translations' ) . '</th><th scope="col">' . esc_html__( 'Not a literal', 'cradle-translations' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( (array) $manifest['dynamic'], 0, 200 ) as $item ) {
			echo '<tr><td><code>' . esc_html( $item['file'] . ':' . $item['line'] ) . '</code></td><td><code>' . esc_html( (string) $item['function'] ) . '()</code></td><td>' . esc_html( (string) $item['reason'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function render_revisions( string $locale, ?array $catalog ) {
		if ( ! $catalog ) {
			echo '<p>' . esc_html__( 'Add or open a locale first.', 'cradle-translations' ) . '</p>';
			return;
		}
		$snapshots = array_flip( Store::snapshots( $locale ) );
		echo '<h2>' . esc_html__( 'Revisions', 'cradle-translations' ) . '</h2>';
		/* translators: %d: number of revisions kept */
		echo '<p>' . esc_html( sprintf( __( 'Every change creates a revision. The previous %d revisions are kept and can be restored; restoring creates a new revision and republishes the restored translations.', 'cradle-translations' ), (int) Config::get( 'history_limit' ) ) ) . '</p>';
		echo '<table class="widefat striped ct-table"><thead><tr><th scope="col">' . esc_html__( 'Revision', 'cradle-translations' ) . '</th><th scope="col">' . esc_html__( 'Date', 'cradle-translations' ) . '</th><th scope="col">' . esc_html__( 'User', 'cradle-translations' ) . '</th><th scope="col">' . esc_html__( 'Change', 'cradle-translations' ) . '</th><th scope="col">' . esc_html__( 'Action', 'cradle-translations' ) . '</th></tr></thead><tbody>';
		foreach ( array_reverse( $catalog['log'] ) as $row ) {
			$user = $row['user'] ? get_userdata( $row['user'] ) : null;
			$time = strtotime( $row['time'] );
			echo '<tr><td>' . esc_html( (string) $row['revision'] ) . '</td>';
			echo '<td>' . esc_html( $time ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : '' ) . '</td>';
			echo '<td>' . esc_html( $user ? $user->display_name : __( 'System', 'cradle-translations' ) ) . '</td>';
			echo '<td>' . esc_html( $row['summary'] ) . '</td><td>';
			if ( $row['revision'] === $catalog['revision'] ) {
				echo esc_html__( 'Current', 'cradle-translations' );
			} elseif ( isset( $snapshots[ $row['revision'] ] ) ) {
				self::form_open(
					'restore',
					$locale,
					array(
						'class'           => 'ct-inline',
						'data-ct-confirm' => __( 'Restore this revision? Current translations are kept in the history.', 'cradle-translations' ),
					)
				);
				echo '<input type="hidden" name="target" value="' . esc_attr( (string) $row['revision'] ) . '" />';
				echo '<input type="hidden" name="revision" value="' . esc_attr( (string) $catalog['revision'] ) . '" />';
				/* translators: %d: revision number */
				echo '<button type="submit" class="button button-small" aria-label="' . esc_attr( sprintf( __( 'Restore revision %d', 'cradle-translations' ), $row['revision'] ) ) . '">' . esc_html__( 'Restore', 'cradle-translations' ) . '</button></form>';
			} else {
				echo '<span class="description">' . esc_html__( 'Not kept', 'cradle-translations' ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
