<?php
/**
 * Cradle Forms integration tests (acceptance in the package docs/contract.md).
 *
 * Runs against the shared sandbox with suite-owned users (prefix `cradle_forms_t_`), fixture
 * forms/pages tracked in $GLOBALS['cf_fixtures'] and removed (with their entries and files) at
 * shutdown. Emails are captured by a test-only mu-plugin (tests/fixtures/forms/mail-capture.php)
 * for subjects containing "CF Test"; it is removed at shutdown. Uninstall runs in a separate process.
 */

use Cradle\Forms\Admin\Csv;
use Cradle\Forms\Caps;
use Cradle\Forms\Entries;
use Cradle\Forms\Field_Types;
use Cradle\Forms\Form_Schema;
use Cradle\Forms\Form_Settings;
use Cradle\Forms\Language;
use Cradle\Forms\Lifecycle;
use Cradle\Forms\Post_Type;
use Cradle\Forms\Privacy;
use Cradle\Forms\Retention;
use Cradle\Forms\Schema;
use Cradle\Forms\Security;
use Cradle\Forms\Submission;
use Cradle\Forms\Uploads;

$GLOBALS['cf_form_pages'] = array();
$GLOBALS['cf_fixtures']   = array(
	'posts' => array(),
	'users' => array(),
);

const CF_MAIL_PLUGIN = 'cradle-forms-test-mail.php';

function cf_ready() {
	if ( ! is_plugin_active( 'cradle-forms/cradle-forms.php' ) || ! function_exists( 'cradle' ) || ! cradle() || ! cradle()->modules()->is_ready( 'forms' ) ) {
		skip_test( 'cradle-forms is not active and ready.' );
	}
	if ( ! Schema::exists() ) {
		skip_test( 'Cradle Forms tables are missing.' );
	}
}

/**
 * Suite-owned user (deleted at shutdown), so sessions never race with other suites.
 */
function cf_user( string $suffix, string $role ): int {
	$login = 'cradle_forms_t_' . $suffix;
	$user  = get_user_by( 'login', $login );
	if ( $user ) {
		$user->set_role( $role );
		return (int) $user->ID;
	}
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 32 ),
			'user_email' => $login . '@example.test',
			'role'       => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new Cradle_Test_Failure( 'Could not create ' . $login . ': ' . $id->get_error_message() );
	}
	$GLOBALS['cf_fixtures']['users'][] = (int) $id;
	return (int) $id;
}

function cf_admin(): int {
	$id = cf_user( 'admin', 'administrator' );
	wp_set_current_user( $id );
	return $id;
}

function cf_viewer(): int {
	if ( ! get_role( 'cradle_forms_t_viewer' ) ) {
		add_role(
			'cradle_forms_t_viewer',
			'CF entry viewer',
			array(
				'read'      => true,
				Caps::VIEW  => true,
			)
		);
	}
	return cf_user( 'viewer', 'cradle_forms_t_viewer' );
}

function cf_fresh() {
	wp_cache_flush();
	Form_Schema::flush();
	Cradle\Forms\Handler::reset_state();
	Cradle\Forms\Renderer::reset_instances();
}

/**
 * Creates a form post as the suite administrator.
 */
function cf_form( string $title, string $content, array $settings = array(), string $status = 'publish' ): int {
	$previous = get_current_user_id();
	cf_admin();
	$id = wp_insert_post(
		array(
			'post_type'    => Post_Type::NAME,
			'post_status'  => $status,
			'post_title'   => $title,
			'post_content' => wp_slash( $content ), // wp_insert_post() unslashes.
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		throw new Cradle_Test_Failure( 'Could not create form: ' . $id->get_error_message() );
	}
	$GLOBALS['cf_fixtures']['posts'][] = (int) $id;
	update_post_meta(
		$id,
		Form_Settings::META,
		array_merge(
			array(
				'minSeconds'    => 0,
				'rateLimit'     => 0,
				'notifications' => array(),
			),
			$settings
		)
	);
	wp_set_current_user( $previous );
	Form_Schema::flush( (int) $id );
	return (int) $id;
}

function cf_page( string $content, string $title = 'CF Test page' ): int {
	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title . ' ' . wp_generate_password( 6, false ),
			'post_content' => wp_slash( $content ),
			'post_author'  => cf_user( 'admin', 'administrator' ),
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		throw new Cradle_Test_Failure( 'Could not create page: ' . $id->get_error_message() );
	}
	$GLOBALS['cf_fixtures']['posts'][] = (int) $id;
	return (int) $id;
}

function cf_block( int $form_id, array $extra = array() ): string {
	return '<!-- wp:cradle/form ' . wp_json_encode( array_merge( array( 'formId' => $form_id ), $extra ) ) . ' /-->';
}

/**
 * Field block markup as the block editor saves it.
 */
function cf_field( string $type, array $attrs ): string {
	return '<!-- wp:cradle-forms/field-' . $type . ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' /-->';
}

const CF_CONSENT = 'I agree that my details are stored to answer my request. <a href="https://example.test/privacy">Privacy policy</a>';

function cf_contact_content(): string {
	return cf_field( 'text', array( 'name' => 'name', 'label' => 'Your name', 'required' => true, 'width' => 'half' ) )
		. cf_field( 'email', array( 'name' => 'email', 'label' => 'Email', 'required' => true, 'submitterEmail' => true, 'width' => 'half' ) )
		. cf_field(
			'select',
			array(
				'name'     => 'topic',
				'label'    => 'Topic',
				'required' => true,
				'choices'  => array(
					array( 'label' => 'Press enquiry', 'value' => 'press' ),
					array( 'label' => 'General question', 'value' => 'general' ),
				),
			)
		)
		. '<!-- wp:paragraph --><p>We answer within two days.</p><!-- /wp:paragraph -->'
		. cf_field( 'textarea', array( 'name' => 'message', 'label' => 'Message', 'required' => true, 'maxLength' => 500 ) )
		. cf_field( 'consent', array( 'name' => 'consent', 'label' => CF_CONSENT ) );
}

function cf_application_content( float $max_mb = 5 ): string {
	return cf_field( 'text', array( 'name' => 'name', 'label' => 'Name', 'required' => true ) )
		. cf_field( 'email', array( 'name' => 'email', 'label' => 'Email', 'required' => true, 'submitterEmail' => true ) )
		. cf_field( 'file', array( 'name' => 'cv', 'label' => 'CV (PDF)', 'required' => true, 'accept' => 'pdf', 'maxSize' => $max_mb ) );
}

/**
 * Signed render token as Security::form_token() creates it (format 2, with the source page),
 * but rendered $age seconds ago.
 */
function cf_token( int $form_id, int $age = 30, int $instance = 1, string $language = 'en', string $locale = 'en_US', int $source = 0 ): string {
	$payload = implode( '.', array( (string) ( time() - $age ), (string) $form_id, (string) $instance, (string) $source, $language, $locale ) );
	return $payload . '.' . substr( Security::hmac( $payload, 'form-token-2' ), 0, 40 );
}

/**
 * Format 1 token (no source page), as pages cached before the source was recorded still carry it.
 */
function cf_token_v1( int $form_id, int $age = 30, int $instance = 1, string $language = 'en', string $locale = 'en_US' ): string {
	$payload = implode( '.', array( (string) ( time() - $age ), (string) $form_id, (string) $instance, $language, $locale ) );
	return $payload . '.' . substr( Security::hmac( $payload, 'form-token' ), 0, 40 );
}

/**
 * Value of a hidden input in rendered HTML (entity-decoded).
 */
function cf_hidden( string $html, string $name ): string {
	return preg_match( '/name="' . preg_quote( $name, '/' ) . '" value="([^"]*)"/', $html, $m ) ? html_entity_decode( $m[1], ENT_QUOTES ) : '';
}

/**
 * Deletes every fixture of this suite (entries and files of fixture forms, posts, users, the
 * test role, the mail-capture mu-plugin, test options/transients, settings). Idempotent: called
 * by the last test, so later suites in the same run see a clean site, and again at shutdown.
 */
function cf_cleanup() {
	if ( ! function_exists( 'cradle' ) || ! class_exists( Entries::class ) ) {
		return;
	}
	global $wpdb;
	$forms = array();
	foreach ( array_unique( $GLOBALS['cf_fixtures']['posts'] ) as $id ) {
		if ( Post_Type::NAME === get_post_type( $id ) ) {
			$forms[] = (int) $id;
		}
	}
	if ( $forms && Schema::exists() ) {
		$in  = implode( ',', array_map( 'intval', $forms ) );
		$ids = $wpdb->get_col( 'SELECT id FROM ' . Schema::table( 'entries' ) . " WHERE form_id IN ({$in})" ); // phpcs:ignore WordPress.DB
		Entries::delete( array_map( 'intval', (array) $ids ) );
	}
	foreach ( array_unique( $GLOBALS['cf_fixtures']['posts'] ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( array_unique( $GLOBALS['cf_fixtures']['users'] ) as $id ) {
		wp_delete_user( (int) $id );
	}
	$GLOBALS['cf_fixtures']   = array(
		'posts' => array(),
		'users' => array(),
	);
	$GLOBALS['cf_form_pages'] = array();
	remove_role( 'cradle_forms_t_viewer' );
	$plugin = WP_CONTENT_DIR . '/mu-plugins/' . CF_MAIL_PLUGIN;
	if ( is_file( $plugin ) ) {
		unlink( $plugin );
	}
	delete_option( 'cradle_forms_test_mails' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_cradle_forms_rl_' ) . '%', $wpdb->esc_like( '_transient_timeout_cradle_forms_rl_' ) . '%', $wpdb->esc_like( '_transient_cradle_forms_r_' ) . '%', $wpdb->esc_like( '_transient_timeout_cradle_forms_r_' ) . '%' ) ); // phpcs:ignore WordPress.DB
	if ( cradle() && cradle()->settings()->section( 'forms' ) ) {
		cradle()->settings()->reset( 'forms' );
	}
	wp_cache_flush();
}

/**
 * multipart/form-data body. $files: list of array( field, filename, content, mime ).
 */
function cf_multipart( array $fields, array $files = array() ): array {
	$boundary = 'cfb' . wp_generate_password( 24, false );
	$body     = '';
	$add      = static function ( string $name, $value ) use ( &$add, &$body, $boundary ) {
		if ( is_array( $value ) ) {
			$list = array_is_list( $value );
			foreach ( $value as $key => $item ) {
				$add( $name . ( $list ? '[]' : '[' . $key . ']' ), $item );
			}
			return;
		}
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n" . (string) $value . "\r\n";
	};
	foreach ( $fields as $name => $value ) {
		$add( (string) $name, $value );
	}
	foreach ( $files as $file ) {
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"cradle_files[{$file[0]}][]\"; filename=\"{$file[1]}\"\r\nContent-Type: {$file[3]}\r\n\r\n{$file[2]}\r\n";
	}
	$body .= "--{$boundary}--\r\n";
	return array(
		'body'    => $body,
		'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
	);
}

/**
 * A published page that shows the form (created once per form), the PRG return target.
 */
function cf_form_page( int $form_id ): int {
	if ( ! isset( $GLOBALS['cf_form_pages'][ $form_id ] ) ) {
		$GLOBALS['cf_form_pages'][ $form_id ] = cf_page( cf_block( $form_id ) );
	}
	return $GLOBALS['cf_form_pages'][ $form_id ];
}

function cf_submit_url( int $form_id ): string {
	return add_query_arg(
		array(
			'action'      => 'cradle_forms_submit',
			'cradle_form' => $form_id,
		),
		admin_url( 'admin-post.php' )
	);
}

/**
 * No-JavaScript submission over HTTP. Returns the raw response.
 */
function cf_post( int $form_id, array $values, array $extra = array(), array $files = array(), array $headers = array(), int $user = 0, ?array $session = null ) {
	$fields    = array_merge(
		array(
			'_cf_ts'         => cf_token( $form_id ),
			'_cf_return'     => get_permalink( cf_form_page( $form_id ) ),
			'cradle_website' => '',
			'cradle_fields'  => $values,
		),
		$extra
	);
	$multipart = cf_multipart( $fields, $files );
	return cradle_test_http(
		'POST',
		cf_submit_url( $form_id ),
		array(
			'body'    => $multipart['body'],
			'headers' => array_merge( $multipart['headers'], $headers ),
		),
		$user,
		$session
	);
}

function cf_origin(): string {
	$home = wp_parse_url( home_url() );
	return $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
}

/**
 * REST submission over HTTP (as view.js sends it). Returns array( code, json, response ).
 */
function cf_rest( int $form_id, array $values, array $extra = array(), array $files = array(), ?array $headers = null, int $user = 0, ?array $session = null ): array {
	$fields    = array_merge(
		array(
			'_cf_ts'         => cf_token( $form_id ),
			'cradle_website' => '',
			'cradle_fields'  => $values,
		),
		$extra
	);
	$multipart = cf_multipart( $fields, $files );
	$response  = cradle_test_http(
		'POST',
		rest_url( 'cradle-forms/v1/submit/' . $form_id ),
		array(
			'body'    => $multipart['body'],
			'headers' => array_merge(
				$multipart['headers'],
				null === $headers ? array(
					'Origin'         => cf_origin(),
					'Sec-Fetch-Site' => 'same-origin',
				) : $headers
			),
		),
		$user,
		$session
	);
	return array( (int) wp_remote_retrieve_response_code( $response ), json_decode( (string) wp_remote_retrieve_body( $response ), true ), $response );
}

function cf_location( $response ): string {
	return (string) wp_remote_retrieve_header( $response, 'location' );
}

function cf_entries( int $form_id ): array {
	global $wpdb;
	$table = Schema::table( 'entries' );
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE form_id = %d ORDER BY id ASC", $form_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
}

function cf_values( int $entry_id ): array {
	$out = array();
	foreach ( Entries::values( $entry_id ) as $row ) {
		$out[ $row['field_name'] ] = $row['value'];
	}
	return $out;
}

function cf_contact_values( array $override = array() ): array {
	return array_merge(
		array(
			'name'    => 'Ada Example',
			'email'   => 'ada@example.test',
			'topic'   => 'general',
			'message' => "Hello,\nplease call me.",
			'consent' => '1',
		),
		$override
	);
}

function cf_consent_hash(): array {
	return array( 'cradle_consent' => array( 'consent' => Submission::consent_hash( wp_kses( CF_CONSENT, Field_Types::consent_tags() ) ) ) );
}

function cf_pdf( int $size = 0 ): string {
	$pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
	return $size > strlen( $pdf ) ? $pdf . str_repeat( '%', $size - strlen( $pdf ) - 6 ) . "\n%%EOF" : $pdf;
}

function cf_mails(): array {
	wp_cache_delete( 'cradle_forms_test_mails', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	$log = get_option( 'cradle_forms_test_mails', array() );
	return is_array( $log ) ? $log : array();
}

function cf_install_mail_capture() {
	$dir = WP_CONTENT_DIR . '/mu-plugins';
	wp_mkdir_p( $dir );
	copy( CRADLE_TEST_ROOT . '/tests/fixtures/forms/mail-capture.php', $dir . '/' . CF_MAIL_PLUGIN );
	delete_option( 'cradle_forms_test_mails' );
}

function cf_php(): string {
	return defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
}

// Safety net when the suite is interrupted; the last test normally cleans up already.
register_shutdown_function( 'cf_cleanup' );

/* ---------------------------------------------------------------------- */
/* Module, lifecycle, settings                                             */
/* ---------------------------------------------------------------------- */

cradle_test(
	'forms: schema update 1 → 2 adds the source columns idempotently; inserts work before the update',
	function () {
		cf_ready();
		global $wpdb;
		$table   = Schema::table( 'entries' );
		$columns = static fn() => array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) ); // phpcs:ignore WordPress.DB
		// Seed a version 1 table (as 0.1.0 created it) when the update already ran.
		if ( in_array( 'source_id', $columns(), true ) ) {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX form_source, DROP COLUMN source_id, DROP COLUMN source_title" ); // phpcs:ignore WordPress.DB
		}
		update_option( Schema::OPTION, 1, false );
		assert_false( Schema::has_source() );
		assert_false( in_array( 'source_id', $columns(), true ) );
		$form = cf_form( 'CF Test update', cf_field( 'text', array( 'name' => 'a', 'label' => 'A' ) ) );
		$old  = Entries::insert(
			array( 'form_id' => $form, 'form_title' => 'x', 'language' => 'en', 'locale' => 'en_US', 'ip_hash' => '', 'ua_hash' => '', 'submitter_email' => '', 'consent' => array(), 'source_id' => 5, 'source_title' => 'ignored' ),
			array()
		);
		assert_true( is_int( $old ) && $old > 0, 'A front-end submission before the update is still stored.' );
		Lifecycle::maybe_upgrade();
		Lifecycle::maybe_upgrade();
		$after = $columns();
		assert_same( Schema::VERSION, (int) get_option( Schema::OPTION ) );
		assert_true( in_array( 'source_id', $after, true ) && in_array( 'source_title', $after, true ), 'Columns added.' );
		assert_same( count( array_unique( $after ) ), count( $after ), 'Running the update twice changes nothing.' );
		$entry = Entries::get( $old );
		assert_same( 0, $entry['source_id'], 'Older entries read as source 0.' );
		$new = Entries::insert(
			array( 'form_id' => $form, 'form_title' => 'x', 'language' => 'en', 'locale' => 'en_US', 'ip_hash' => '', 'ua_hash' => '', 'submitter_email' => '', 'consent' => array(), 'source_id' => 7, 'source_title' => 'Page seven' ),
			array()
		);
		assert_same( array( 7, 'Page seven' ), array( Entries::get( $new )['source_id'], Entries::get( $new )['source_title'] ) );
		Entries::delete( array( $old, $new ) );
	}
);

cradle_test(
	'forms: module ready with descriptor, tables, capabilities, post type, blocks, component and cron',
	function () {
		cf_ready();
		$descriptor = cradle()->modules()->get( 'forms' );
		assert_same( array( 'blocks' => '^0.1' ), $descriptor['dependencies'] );
		assert_same( array( 'cradle_form' ), $descriptor['dataOwners']['postTypes'] );
		assert_same( array( 'cradle_form_entries', 'cradle_form_values', 'cradle_form_files' ), $descriptor['dataOwners']['tables'] );
		assert_true( in_array( 'cradle/form', $descriptor['dataOwners']['blocks'], true ) && in_array( 'cradle-forms/field-file', $descriptor['dataOwners']['blocks'], true ) );
		assert_same( array( 'forms' ), $descriptor['settingsSections'] );
		foreach ( Caps::all() as $cap ) {
			assert_true( isset( $descriptor['capabilities'][ $cap ] ), $cap . ' declared.' );
			assert_true( get_role( 'administrator' )->has_cap( $cap ), $cap . ' granted to administrators.' );
			assert_false( get_role( 'editor' )->has_cap( $cap ), $cap . ' not granted to editors.' );
		}
		$type = get_post_type_object( Post_Type::NAME );
		assert_true( $type && ! $type->public && ! $type->publicly_queryable && $type->show_in_rest && $type->show_ui );
		assert_same( Caps::MANAGE, $type->cap->edit_posts );
		foreach ( Field_Types::block_names() as $block ) {
			assert_true( WP_Block_Type_Registry::get_instance()->is_registered( $block ), $block . ' registered.' );
		}
		assert_true( WP_Block_Type_Registry::get_instance()->is_registered( 'cradle/form' ) );
		assert_true( (bool) cradle()->components()->get( 'cradle/form' ) );
		assert_true( shortcode_exists( 'cradle_form' ) );
		assert_true( (bool) wp_next_scheduled( Retention::HOOK ) );
		assert_same( Schema::VERSION, (int) get_option( Schema::OPTION ) );
	}
);

cradle_test(
	'forms: activation is idempotent and respects a removed capability; deactivation keeps data',
	function () {
		cf_ready();
		cf_admin();
		global $wpdb;
		$form = cf_form( 'CF Test lifecycle', cf_field( 'text', array( 'name' => 'a', 'label' => 'A' ) ) );
		Lifecycle::activate();
		Lifecycle::activate();
		assert_true( Schema::exists() );
		$role = get_role( 'administrator' );
		$role->remove_cap( Caps::EXPORT );
		try {
			Lifecycle::activate();
			wp_roles()->for_site( get_current_blog_id() );
			assert_false( get_role( 'administrator' )->has_cap( Caps::EXPORT ), 'A capability an administrator removed is not granted again.' );
		} finally {
			get_role( 'administrator' )->add_cap( Caps::EXPORT );
		}
		Lifecycle::deactivate();
		assert_false( (bool) wp_next_scheduled( Retention::HOOK ), 'Deactivation unschedules the retention cron.' );
		assert_true( Schema::exists() && (bool) get_post( $form ), 'Deactivation keeps tables and forms.' );
		Retention::schedule();
		assert_true( (bool) wp_next_scheduled( Retention::HOOK ) );
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'entries' ) ); // phpcs:ignore WordPress.DB
		Lifecycle::activate();
		assert_same( $count, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'entries' ) ), 'Re-activation creates no data.' ); // phpcs:ignore WordPress.DB
	}
);

cradle_test(
	'forms: settings section validates types and refuses blocked extensions; editor cannot change it',
	function () {
		cf_ready();
		cf_admin();
		cradle()->settings()->reset( 'forms' );
		$result = cradle()->settings()->update( 'forms', array( 'allowed_extensions' => 'pdf,php,docx' ) );
		assert_true( isset( $result['errors']['allowed_extensions'] ), 'Blocked extension refused.' );
		assert_same( 'pdf,doc,docx,odt,rtf,txt,jpg,jpeg,png', cradle_get_setting( 'forms', 'allowed_extensions' ), 'Invalid value keeps the previous value.' );
		$result = cradle()->settings()->update( 'forms', array( 'max_upload_mb' => 0 ) );
		assert_true( isset( $result['errors']['max_upload_mb'] ), 'Out-of-range size refused.' );
		$result = cradle()->settings()->update( 'forms', array( 'fallback_recipient' => 'not-an-email' ) );
		assert_true( isset( $result['errors']['fallback_recipient'] ) );
		$result = cradle()->settings()->update( 'forms', array( 'allowed_extensions' => 'PDF, jpg' ) );
		assert_same( array(), $result['errors'] ?? array() );
		assert_same( array( 'pdf', 'jpg' ), Cradle\Forms\Settings_Section::allowed_extensions() );
		wp_set_current_user( cf_user( 'editor', 'editor' ) );
		assert_wp_error( cradle()->settings()->update( 'forms', array( 'allowed_extensions' => 'pdf' ) ) );
		cf_admin();
		cradle()->settings()->reset( 'forms' );
	}
);

/* ---------------------------------------------------------------------- */
/* Schema derivation and form settings                                     */
/* ---------------------------------------------------------------------- */

cradle_test(
	'forms: schema is derived from field blocks on save; invalid saves keep the previous schema with errors',
	function () {
		cf_ready();
		$id     = cf_form( 'CF Test contact', cf_contact_content() );
		$schema = Form_Schema::get( $id );
		assert_true( is_array( $schema ), 'Schema stored.' );
		assert_same( Form_Schema::VERSION, $schema['schema_version'] );
		assert_same( array( 'name', 'email', 'topic', 'message', 'consent' ), wp_list_pluck( $schema['fields'], 'name' ) );
		assert_same( array( 'text', 'email', 'select', 'textarea', 'consent' ), wp_list_pluck( $schema['fields'], 'type' ) );
		assert_same( 'half', $schema['fields'][0]['width'] );
		assert_true( $schema['fields'][1]['submitter_email'] );
		assert_same( array( 'press', 'general' ), Field_Types::choice_values( $schema['fields'][2] ) );
		assert_same( 500, $schema['fields'][3]['max_length'] );
		assert_true( $schema['fields'][4]['required'], 'Consent is required by default.' );
		assert_same( array(), Form_Schema::errors( $id ) );
		assert_true( is_string( get_post_meta( $id, Form_Schema::META, true ) ) && null !== json_decode( get_post_meta( $id, Form_Schema::META, true ), true ), 'Stored as JSON.' );

		cf_admin();
		$cases = array(
			'duplicate'  => cf_field( 'text', array( 'name' => 'name', 'label' => 'A' ) ) . cf_field( 'email', array( 'name' => 'name', 'label' => 'B' ) ),
			'bad name'   => cf_field( 'text', array( 'name' => '1-Bad Name', 'label' => 'A' ) ),
			'no choices' => cf_field( 'radio', array( 'name' => 'r', 'label' => 'R' ) ),
			'two emails' => cf_field( 'email', array( 'name' => 'a', 'label' => 'A', 'submitterEmail' => true ) ) . cf_field( 'email', array( 'name' => 'b', 'label' => 'B', 'submitterEmail' => true ) ),
			'nested'     => cf_field( 'text', array( 'name' => 'a', 'label' => 'A' ) ) . cf_block( $id ),
			'unknown'    => '<!-- wp:cradle-forms/field-evil {"name":"x"} /-->',
			'min>max'    => cf_field( 'number', array( 'name' => 'n', 'label' => 'N', 'min' => 5, 'max' => 1 ) ),
			'no label'   => cf_field( 'text', array( 'name' => 'a' ) ),
			'bad file'   => cf_field( 'file', array( 'name' => 'f', 'label' => 'F', 'accept' => 'pdf,exe' ) ),
		);
		foreach ( $cases as $case => $content ) {
			wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => wp_slash( $content ),
				)
			);
			cf_fresh();
			assert_true( (bool) Form_Schema::errors( $id ), "Invalid schema ({$case}) records errors." );
			assert_same( array( 'name', 'email', 'topic', 'message', 'consent' ), wp_list_pluck( Form_Schema::fields( $id ), 'name' ), "Invalid schema ({$case}) keeps the previous fields." );
		}
		$status = Form_Schema::status( $id );
		assert_false( $status['valid'] );
		assert_true( $status['hasSchema'] && $status['forcedStorage'] );

		// Visitors still get the previous valid fields (rendered from the stored schema).
		wp_set_current_user( 0 );
		$html = cradle_render_component( 'cradle/form', array( 'formId' => $id ) );
		assert_contains( 'name="cradle_fields[topic]"', $html, 'Fallback rendering from the stored schema.' );
		assert_not_contains( 'cradle-forms/field-evil', $html );

		cf_admin();
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => wp_slash( cf_contact_content() ),
			)
		);
		cf_fresh();
		assert_same( array(), Form_Schema::errors( $id ), 'A valid save clears the errors.' );
	}
);

cradle_test(
	'forms: form settings meta is sanitized; REST reading needs cradle_manage_forms; editor cannot edit forms',
	function () {
		cf_ready();
		$id = cf_form( 'CF Test settings', cf_contact_content() );
		update_post_meta(
			$id,
			Form_Settings::META,
			array(
				'submitLabel'   => '<b>Send</b>',
				'retentionDays' => 99999,
				'minSeconds'    => -4,
				'rateLimit'     => 5,
				'evil'          => 'x',
				'notifications' => array(
					array(
						'to'     => 'office@example.test, not-an-email, ',
						'routes' => array(
							array( 'field' => 'topic', 'value' => 'press', 'to' => 'press@example.test' ),
							array( 'field' => 'Bad Field', 'value' => 'x', 'to' => 'x@example.test' ),
							array( 'field' => 'topic', 'value' => 'y', 'to' => 'nobody' ),
						),
					),
				),
			)
		);
		$settings = Form_Settings::get( $id );
		assert_false( isset( $settings['evil'] ) );
		assert_same( 'Send', $settings['submitLabel'] );
		assert_same( 3650, $settings['retentionDays'] );
		assert_same( 0, $settings['minSeconds'] );
		assert_same( 'office@example.test', $settings['notifications'][0]['to'] );
		assert_same( 1, count( $settings['notifications'][0]['routes'] ), 'Invalid routing rules dropped.' );
		assert_false( $settings['notifications'][0]['copyToSubmitter'], 'Copy to submitter is off by default.' );

		// REST: anonymous and editors cannot read forms (they hold notification addresses).
		$anon = cradle_test_http( 'GET', rest_url( 'wp/v2/cradle-forms/' . $id ) );
		assert_true( in_array( (int) wp_remote_retrieve_response_code( $anon ), array( 401, 403 ), true ), 'Anonymous REST read refused.' );
		$editor  = cf_user( 'editor', 'editor' );
		$session = cradle_test_cookies( $editor );
		$nonce   = cradle_test_nonce( $editor, $session, 'wp_rest' );
		$denied  = cradle_test_http( 'GET', rest_url( 'wp/v2/cradle-forms/' . $id ), array( 'headers' => array( 'X-WP-Nonce' => $nonce ) ), $editor, $session );
		assert_same( 403, (int) wp_remote_retrieve_response_code( $denied ), 'Editor REST read refused.' );
		$update = cradle_test_http(
			'POST',
			rest_url( 'wp/v2/cradle-forms/' . $id ),
			array(
				'headers' => array(
					'X-WP-Nonce'   => $nonce,
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( array( 'title' => 'Hacked' ) ),
			),
			$editor,
			$session
		);
		assert_same( 403, (int) wp_remote_retrieve_response_code( $update ), 'Editor cannot update a form.' );
		cf_fresh();
		assert_same( 'CF Test settings', get_post( $id )->post_title );

		$admin   = cf_user( 'admin', 'administrator' );
		$session = cradle_test_cookies( $admin );
		$nonce   = cradle_test_nonce( $admin, $session, 'wp_rest' );
		$read    = cradle_test_http( 'GET', rest_url( 'wp/v2/cradle-forms/' . $id . '?context=edit' ), array( 'headers' => array( 'X-WP-Nonce' => $nonce ) ), $admin, $session );
		assert_same( 200, (int) wp_remote_retrieve_response_code( $read ) );
		$json = json_decode( (string) wp_remote_retrieve_body( $read ), true );
		assert_true( isset( $json['cradle_form_status']['valid'] ) && $json['cradle_form_status']['valid'], 'Editor status field present.' );
		$saved = cradle_test_http(
			'POST',
			rest_url( 'wp/v2/cradle-forms/' . $id ),
			array(
				'headers' => array(
					'X-WP-Nonce'   => $nonce,
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'meta' => array(
							Form_Settings::META => array_merge(
								Form_Settings::defaults(),
								array(
									'submitLabel' => 'Send now',
									'rateLimit'   => 0,
									'minSeconds'  => 0,
								)
							),
						),
					)
				),
			),
			$admin,
			$session
		);
		assert_same( 200, (int) wp_remote_retrieve_response_code( $saved ), 'Administrator saves settings over REST: ' . substr( (string) wp_remote_retrieve_body( $saved ), 0, 300 ) );
		cf_fresh();
		assert_same( 'Send now', Form_Settings::get( $id )['submitLabel'] );
	}
);

cradle_test(
	'forms: inside a form only field and simple content blocks are allowed; other editors are unchanged',
	function () {
		cf_ready();
		$id      = cf_form( 'CF Test blocks', cf_contact_content() );
		$context = new WP_Block_Editor_Context( array( 'post' => get_post( $id ) ) );
		$allowed = apply_filters( 'allowed_block_types_all', true, $context );
		assert_true( is_array( $allowed ) && in_array( 'cradle-forms/field-email', $allowed, true ) && in_array( 'core/paragraph', $allowed, true ) );
		assert_false( in_array( 'cradle/form', $allowed, true ), 'No form block inside a form.' );
		$page    = cf_page( 'x' );
		$context = new WP_Block_Editor_Context( array( 'post' => get_post( $page ) ) );
		assert_true( true === apply_filters( 'allowed_block_types_all', true, $context ), 'Pages keep every block.' );
	}
);

/* ---------------------------------------------------------------------- */
/* Rendering and submissions                                               */
/* ---------------------------------------------------------------------- */

cradle_test(
	'forms: the form block renders accessible server markup on a real page; shortcode and unpublished forms',
	function () {
		cf_ready();
		$id   = cf_form( 'CF Test render', cf_contact_content(), array( 'submitLabel' => 'Send message' ) );
		$page = cf_page( cf_block( $id, array( 'showTitle' => true ) ) . '<!-- wp:shortcode -->[cradle_form id="' . $id . '"]<!-- /wp:shortcode -->' );
		$res  = cradle_test_http( 'GET', get_permalink( $page ) );
		$html = (string) wp_remote_retrieve_body( $res );
		assert_same( 200, (int) wp_remote_retrieve_response_code( $res ) );
		assert_contains( 'id="cradle-form-' . $id . '"', $html );
		assert_contains( 'id="cradle-form-' . $id . '-2"', $html, 'Second instance has its own IDs.' );
		assert_contains( 'action="' . esc_attr( cf_submit_url( $id ) ) . '"', $html, 'Posts to admin-post.php without JavaScript.' );
		assert_contains( 'method="post"', $html );
		assert_contains( 'data-rest="' . esc_url( rest_url( 'cradle-forms/v1/submit/' . $id ) ) . '"', $html );
		assert_contains( '<label class="cradle-form__label" for="cradle-form-' . $id . '-name">Your name', $html );
		assert_contains( 'aria-describedby="cradle-form-' . $id . '-message-error"', $html );
		assert_contains( 'name="cradle_website"', $html, 'Honeypot present.' );
		assert_contains( 'class="cradle-form__hp" aria-hidden="true"', $html );
		assert_contains( 'name="_cf_ts"', $html, 'Signed render token present.' );
		assert_contains( 'We answer within two days.</p>', $html, 'Static content between fields kept.' );
		assert_contains( 'Send message</button>', $html );
		assert_contains( '<h2 class="cradle-form__title">CF Test render</h2>', $html );
		assert_contains( 'cradle-form__field--half', $html );
		assert_contains( 'href="https://example.test/privacy"', $html, 'Consent label keeps its link.' );
		assert_contains( 'name="cradle_consent[consent]"', $html );
		assert_not_contains( '_cf_nonce', $html, 'No nonce for anonymous visitors (cached pages).' );
		assert_contains( 'assets/js/view.js', $html, 'View script enqueued where the form renders.' );
		assert_contains( 'assets/css/forms.css', $html );

		$draft = cf_form( 'CF Test draft', cf_contact_content(), array(), 'draft' );
		$page2 = cf_page( cf_block( $draft ) );
		$html2 = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', get_permalink( $page2 ) ) );
		assert_not_contains( 'cradle-form__form', $html2, 'Draft form not shown.' );
		assert_not_contains( 'not published', $html2, 'Manager notice hidden from visitors.' );
	}
);

cradle_test(
	'forms: no-JavaScript submission stores the entry and shows the confirmation once (PRG)',
	function () {
		cf_ready();
		$id   = cf_form( 'CF Test prg', cf_contact_content(), array( 'minSeconds' => 1, 'confirmationMessage' => '<p>Thanks, <strong>we got it</strong>.</p><script>x</script>' ) );
		$page = cf_page( cf_block( $id ) );
		$url  = get_permalink( $page );
		$html = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', $url ) );
		preg_match( '/name="_cf_ts" value="([^"]+)"/', $html, $m );
		preg_match( '/name="_cf_return" value="([^"]+)"/', $html, $r );
		assert_true( isset( $m[1], $r[1] ), 'Token and return URL rendered.' );
		sleep( 2 ); // Minimum fill time of the form.
		$res = cf_post( $id, cf_contact_values(), array_merge( array( '_cf_ts' => html_entity_decode( $m[1] ), '_cf_return' => html_entity_decode( $r[1] ) ), cf_consent_hash() ) );
		assert_same( 303, (int) wp_remote_retrieve_response_code( $res ), 'Redirect after POST.' );
		$location = cf_location( $res );
		assert_contains( 'cradle_form_result=', $location );
		assert_contains( '#cradle-form-' . $id . '-confirmation', $location );
		assert_same( 0, strpos( $location, $url ), 'Back to the page of the form.' );

		$entries = cf_entries( $id );
		assert_same( 1, count( $entries ), 'One entry stored.' );
		$entry = $entries[0];
		assert_same( 'new', $entry['status'] );
		assert_same( 'ada@example.test', $entry['submitter_email'] );
		assert_true( (bool) preg_match( '/^[a-f0-9]{64}$/', $entry['ip_hash'] ), 'IP stored only as a keyed hash.' );
		assert_not_contains( '127.0.0.1', wp_json_encode( $entry ) );
		assert_same( 'CF Test prg', $entry['form_title'] );
		assert_true( '' !== $entry['language'] && '' !== $entry['locale'] );
		$values = cf_values( (int) $entry['id'] );
		assert_same( 'Ada Example', $values['name'] );
		assert_same( "Hello,\nplease call me.", $values['message'] );
		assert_same( 'general', $values['topic'] );

		$result = cradle_test_http( 'GET', $location );
		$body   = (string) wp_remote_retrieve_body( $result );
		assert_contains( 'cradle-form__confirmation', $body );
		assert_contains( 'Thanks, <strong>we got it</strong>.', $body );
		assert_not_contains( '<script>x</script>', $body, 'Confirmation message is kses-filtered.' );
		assert_contains( 'no-store', strtolower( (string) wp_remote_retrieve_header( $result, 'cache-control' ) ), 'Result page is not cacheable.' );
		$again = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', $location ) );
		assert_not_contains( 'cradle-form__confirmation', $again, 'The result token is used once.' );
		assert_contains( 'cradle-form__form', $again );

		$get = cradle_test_http( 'GET', cf_submit_url( $id ) );
		assert_same( 405, (int) wp_remote_retrieve_response_code( $get ), 'GET to the submit handler is refused.' );
	}
);

cradle_test(
	'forms: invalid no-JavaScript submissions return field errors with the submitted values kept',
	function () {
		cf_ready();
		$id   = cf_form( 'CF Test errors', cf_contact_content() );
		$page = cf_page( cf_block( $id ) );
		$res  = cf_post(
			$id,
			cf_contact_values(
				array(
					'name'    => '',
					'email'   => "ada@example.test\r\nBcc: victim@example.test",
					'topic'   => 'evil',
					'message' => str_repeat( 'x', 501 ),
					'consent' => '',
				)
			),
			array_merge( array( '_cf_return' => get_permalink( $page ) ), cf_consent_hash() )
		);
		assert_same( 303, (int) wp_remote_retrieve_response_code( $res ) );
		$location = cf_location( $res );
		assert_contains( '#cradle-form-' . $id . '-summary', $location );
		assert_same( array(), cf_entries( $id ), 'Nothing stored.' );
		$html = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', $location ) );
		assert_contains( 'cradle-form__summary', $html );
		assert_not_contains( 'id="cradle-form-' . $id . '-summary" tabindex="-1" aria-labelledby="cradle-form-' . $id . '-summary-title" hidden', $html, 'Summary visible.' );
		foreach ( array( 'name', 'email', 'topic', 'message', 'consent' ) as $field ) {
			assert_contains( 'href="#cradle-form-' . $id . '-' . $field . '"', $html, "Summary links to {$field}." );
		}
		assert_contains( 'This field is required.', $html );
		assert_contains( 'Please enter a valid email address.', $html );
		assert_contains( 'Please choose from the available options.', $html );
		assert_contains( 'Please enter at most 500 characters.', $html );
		assert_contains( 'aria-invalid="true"', $html );
		assert_contains( 'value="ada@example.test Bcc: victim@example.test"', $html, 'Submitted value kept (sanitized).' );
		assert_contains( 'has-error', $html );
	}
);

cradle_test(
	'forms: honeypot, too-fast, forged or foreign tokens and cross-site posts are rejected',
	function () {
		cf_ready();
		$id    = cf_form( 'CF Test spam', cf_contact_content(), array( 'minSeconds' => 20 ) );
		$other = cf_form( 'CF Test spam other', cf_contact_content() );
		$cases = array(
			'honeypot' => array( 'cradle_website' => 'http://spam.example', '_cf_ts' => cf_token( $id, 60 ) ),
			'too fast' => array( '_cf_ts' => cf_token( $id, 2 ) ),
			'forged'   => array( '_cf_ts' => ( time() - 60 ) . '.' . $id . '.1.en.en_US.' . str_repeat( 'a', 40 ) ),
			'foreign'  => array( '_cf_ts' => cf_token( $other, 60 ) ),
			'missing'  => array( '_cf_ts' => '' ),
			'too old'  => array( '_cf_ts' => cf_token( $id, 40 * DAY_IN_SECONDS ) ),
		);
		foreach ( $cases as $case => $extra ) {
			$res = cf_post( $id, cf_contact_values(), array_merge( cf_consent_hash(), $extra ) );
			assert_same( 303, (int) wp_remote_retrieve_response_code( $res ), $case );
			$html = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', cf_location( $res ) ) );
			assert_contains( esc_html( Submission::rejected_message() ), $html, "{$case}: rejected with the generic message." );
		}
		assert_same( array(), cf_entries( $id ), 'No spam stored.' );
		$res = cf_post( $id, cf_contact_values(), array_merge( cf_consent_hash(), array( '_cf_ts' => cf_token( $id, 60 ) ) ), array(), array( 'Origin' => 'https://evil.example' ) );
		assert_same( 403, (int) wp_remote_retrieve_response_code( $res ), 'Cross-site no-JS post refused.' );
		$res = cf_post( $id, cf_contact_values(), array_merge( cf_consent_hash(), array( '_cf_ts' => cf_token( $id, 60 ) ) ), array(), array( 'Sec-Fetch-Site' => 'cross-site' ) );
		assert_same( 403, (int) wp_remote_retrieve_response_code( $res ) );
		$res = cf_post( $id, cf_contact_values(), array_merge( cf_consent_hash(), array( '_cf_ts' => cf_token( $id, 60 ) ) ) );
		assert_same( 1, count( cf_entries( $id ) ), 'A real submission (old enough, same site) is accepted.' );
	}
);

cradle_test(
	'forms: rate limit per network and form; validation mistakes do not count',
	function () {
		cf_ready();
		$id    = cf_form( 'CF Test rate', cf_contact_content(), array( 'rateLimit' => 2 ) );
		$other = cf_form( 'CF Test rate other', cf_contact_content(), array( 'rateLimit' => 2 ) );
		$bad   = cf_post( $id, cf_contact_values( array( 'email' => 'nope' ) ), cf_consent_hash() );
		assert_contains( 'cradle_form_result=', cf_location( $bad ) );
		cf_post( $id, cf_contact_values(), cf_consent_hash() );
		cf_post( $id, cf_contact_values(), cf_consent_hash() );
		assert_same( 2, count( cf_entries( $id ) ), 'Two submissions accepted (the invalid one gave its slot back).' );
		$third = cf_post( $id, cf_contact_values(), cf_consent_hash() );
		$html  = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', cf_location( $third ) ) );
		assert_contains( 'Too many submissions came from your network.', $html );
		assert_same( 2, count( cf_entries( $id ) ), 'Third submission refused.' );
		cf_post( $other, cf_contact_values(), cf_consent_hash() );
		assert_same( 1, count( cf_entries( $other ) ), 'The limit is per form.' );
		[ $code ] = cf_rest( $id, cf_contact_values(), cf_consent_hash() );
		assert_same( 429, $code, 'REST shares the limit.' );
	}
);

cradle_test(
	'forms: REST submission needs same-origin proof and returns JSON results and field errors',
	function () {
		cf_ready();
		$id = cf_form( 'CF Test rest', cf_contact_content(), array( 'confirmationMessage' => '<p>Done.</p>' ) );
		[ $code, $json ] = cf_rest( $id, cf_contact_values(), cf_consent_hash() );
		assert_same( 200, $code, wp_json_encode( $json ) );
		assert_same( 'sent', $json['status'] );
		assert_same( '<p>Done.</p>', $json['confirmation'] );
		assert_same( 1, count( cf_entries( $id ) ) );

		[ $code, $json ] = cf_rest( $id, cf_contact_values( array( 'email' => 'bad', 'topic' => '' ) ), cf_consent_hash() );
		assert_same( 422, $code );
		assert_same( 'cradle_forms_invalid', $json['code'] );
		assert_same( array( 'email', 'topic' ), array_keys( $json['data']['field_errors'] ) );

		[ $code, $json ] = cf_rest( $id, cf_contact_values(), cf_consent_hash(), array(), array() );
		assert_same( 403, $code, 'No origin evidence: refused.' );
		[ $code ] = cf_rest( $id, cf_contact_values(), cf_consent_hash(), array(), array( 'Origin' => 'https://evil.example' ) );
		assert_same( 403, $code, 'Foreign origin refused.' );
		[ $code ] = cf_rest( $id, cf_contact_values(), array_merge( cf_consent_hash(), array( 'cradle_website' => 'x' ) ) );
		assert_same( 400, $code, 'Honeypot over REST.' );
		[ $code ] = cf_rest( 999999, cf_contact_values() );
		assert_same( 404, $code, 'Unknown form.' );
		$get = cradle_test_http( 'GET', rest_url( 'cradle-forms/v1/submit/' . $id ) );
		assert_same( 404, (int) wp_remote_retrieve_response_code( $get ), 'No GET route.' );
		assert_same( 1, count( cf_entries( $id ) ) );
	}
);

cradle_test(
	'forms: logged-in users need the form nonce (no-JS) or the REST nonce',
	function () {
		cf_ready();
		$id      = cf_form( 'CF Test nonce', cf_contact_content() );
		$user    = cf_user( 'subscriber', 'subscriber' );
		$session = cradle_test_cookies( $user );
		$res     = cf_post( $id, cf_contact_values(), cf_consent_hash(), array(), array(), $user, $session );
		$html    = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', cf_location( $res ), array(), $user, $session ) );
		assert_contains( 'Your session changed since the page was loaded.', $html );
		assert_contains( 'value="Ada Example"', $html, 'Values kept after a nonce failure.' );
		assert_same( array(), cf_entries( $id ) );
		$nonce = cradle_test_nonce( $user, $session, 'cradle_forms_submit_' . $id );
		cf_post( $id, cf_contact_values(), array_merge( cf_consent_hash(), array( '_cf_nonce' => $nonce ) ), array(), array(), $user, $session );
		assert_same( 1, count( cf_entries( $id ) ), 'Valid nonce accepted.' );
		$rest_nonce = cradle_test_nonce( $user, $session, 'wp_rest' );
		[ $code ] = cf_rest(
			$id,
			cf_contact_values(),
			cf_consent_hash(),
			array(),
			array(
				'Origin'     => cf_origin(),
				'X-WP-Nonce' => $rest_nonce,
			),
			$user,
			$session
		);
		assert_same( 200, $code );
		[ $code, $json ] = cf_rest(
			$id,
			cf_contact_values(),
			cf_consent_hash(),
			array(),
			array(
				'Origin'     => cf_origin(),
				'X-WP-Nonce' => 'bad',
			),
			$user,
			$session
		);
		assert_same( 403, $code, 'Bad REST nonce refused by WordPress.' );
		assert_same( 2, count( cf_entries( $id ) ) );
		$page = cf_page( cf_block( $id ) );
		$html = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', get_permalink( $page ), array(), $user, $session ) );
		assert_contains( 'name="_cf_nonce"', $html, 'Logged-in visitors get a nonce field.' );
		assert_contains( 'data-rest-nonce=', $html );
	}
);

cradle_test(
	'forms: consent records keep the exact text, its hash, the language and the time; changed text is refused',
	function () {
		cf_ready();
		$id = cf_form( 'CF Test consent', cf_contact_content() );
		cf_post( $id, cf_contact_values(), cf_consent_hash() );
		$entries = cf_entries( $id );
		assert_same( 1, count( $entries ) );
		$entry = Entries::get( (int) $entries[0]['id'] );
		assert_same( 1, count( $entry['consent'] ) );
		$record = $entry['consent'][0];
		$shown  = wp_kses( CF_CONSENT, Field_Types::consent_tags() );
		assert_same( 'consent', $record['field'] );
		assert_same( $shown, $record['text'], 'Exact label HTML stored.' );
		assert_same( hash( 'sha256', $shown ), $record['hash'], 'Text version hash.' );
		assert_same( $entry['language'], $record['language'] );
		assert_true( abs( strtotime( $record['given_at'] ) - time() ) < 300, 'Timestamp stored.' );
		assert_same( '1', cf_values( (int) $entry['id'] )['consent'] );

		[ $code, $json ] = cf_rest( $id, cf_contact_values(), array( 'cradle_consent' => array( 'consent' => hash( 'sha256', 'older text' ) ) ) );
		assert_same( 422, $code, 'Consent to a different text version refused.' );
		assert_contains( 'changed after the page was loaded', $json['data']['field_errors']['consent'] );
	}
);

cradle_test(
	'forms: entries record the page the form was sent from (signed, title snapshot) in detail, list, CSV, notifications and entry_saved',
	function () {
		cf_ready();
		cf_install_mail_capture();
		$id    = cf_form( 'CF Test source', cf_contact_content(), array( 'notifications' => array( array( 'to' => 'office@example.test', 'subject' => 'CF Test from {source}' ) ) ) );
		$page  = cf_page( cf_block( $id ), '@CF Source A' ); // A title starting with @ must be neutralised in the CSV.
		$other = cf_page( 'x', 'CF Source other' );
		$title = get_post( $page )->post_title;
		$html  = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', get_permalink( $page ) ) );
		$raw   = cf_hidden( $html, '_cf_ts' );
		$token = Security::read_token( $raw );
		assert_same( 7, count( explode( '.', $raw ) ), 'Render token format 2.' );
		assert_same( $page, $token['source'] ?? null, 'The singular page is signed into the token.' );

		cf_post( $id, cf_contact_values(), array_merge( cf_consent_hash(), array( '_cf_ts' => $raw ) ) );
		$entries = cf_entries( $id );
		assert_same( 1, count( $entries ) );
		assert_same( array( $page, $title ), array( (int) $entries[0]['source_id'], $entries[0]['source_title'] ), 'Source page ID and title stored.' );
		$entry_id = (int) $entries[0]['id'];
		$mails    = array_values( array_filter( cf_mails(), static fn( $m ) => 0 === strpos( $m['subject'], 'CF Test from ' ) ) );
		assert_same( 'CF Test from ' . $title, $mails[0]['subject'] ?? null, '{source} placeholder in the subject.' );
		assert_contains( 'Sent from: ' . $title . ' (' . get_permalink( $page ) . ')', $mails[0]['message'] ?? '', 'Source line in the notification body.' );

		// Renaming the page later keeps the snapshot.
		wp_update_post(
			array(
				'ID'         => $page,
				'post_title' => 'CF Source renamed',
			)
		);
		cf_fresh();
		assert_same( $title, Entries::get( $entry_id )['source_title'], 'The title snapshot survives a rename.' );

		// A tampered source breaks the signature.
		$parts    = explode( '.', $raw );
		$parts[3] = (string) $other;
		$res      = cf_post( $id, cf_contact_values(), array_merge( cf_consent_hash(), array( '_cf_ts' => implode( '.', $parts ) ) ) );
		assert_contains( esc_html( Submission::rejected_message() ), (string) wp_remote_retrieve_body( cradle_test_http( 'GET', cf_location( $res ) ) ), 'Tampered source rejected.' );
		assert_same( 1, count( cf_entries( $id ) ) );

		// Format 1 tokens (pages cached before the source was recorded) still submit, as source 0.
		cf_post( $id, cf_contact_values(), array_merge( cf_consent_hash(), array( '_cf_ts' => cf_token_v1( $id ) ) ) );
		$entries = cf_entries( $id );
		assert_same( 2, count( $entries ), 'Old token accepted.' );
		assert_same( array( 0, '' ), array( (int) $entries[1]['source_id'], $entries[1]['source_title'] ) );

		// Rendered outside a singular view (this process has no singular main query): source 0.
		assert_false( is_singular() );
		cf_fresh();
		$plain = Security::read_token( cf_hidden( cradle_render_component( 'cradle/form', array( 'formId' => $id ) ), '_cf_ts' ) );
		assert_same( 0, $plain['source'] ?? null, 'Non-singular render records no source.' );
		cf_post( $id, cf_contact_values(), array_merge( cf_consent_hash(), array( '_cf_ts' => cf_token( $id, 30, 1, 'en', 'en_US', 0 ) ) ) );
		assert_same( 0, (int) cf_entries( $id )[2]['source_id'] );

		// The cradle_forms/entry_saved payload carries the source (in this process; mail is short-circuited).
		$seen  = null;
		$watch = static function ( $entry ) use ( &$seen ) {
			$seen = $entry;
		};
		$quiet = static fn() => true;
		add_action( 'cradle_forms/entry_saved', $watch );
		add_filter( 'pre_wp_mail', $quiet );
		try {
			$result = Submission::process( $id, array_merge( cf_consent_hash(), array( '_cf_ts' => cf_token( $id, 30, 1, 'en', 'en_US', $page ), 'cradle_website' => '', 'cradle_fields' => cf_contact_values() ) ), array(), 'rest' );
		} finally {
			remove_action( 'cradle_forms/entry_saved', $watch );
			remove_filter( 'pre_wp_mail', $quiet );
		}
		assert_true( $result['ok'], wp_json_encode( $result ) );
		assert_same( array( $page, 'CF Source renamed' ), array( $seen['source_id'] ?? null, $seen['source_title'] ?? null ), 'entry_saved has the source; new entries take the current title.' );

		// Admin: detail and list show it; only users who may edit the page get a link; denied roles see nothing.
		$admin   = cf_user( 'admin', 'administrator' );
		$session = cradle_test_cookies( $admin );
		$detail  = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-forms&view=entry&entry=' . $entry_id ), array(), $admin, $session ) );
		assert_contains( '<th scope="row">Sent from</th>', $detail );
		assert_contains( '<a href="' . esc_url( admin_url( 'post.php?post=' . $page . '&action=edit' ) ) . '">' . esc_html( $title ) . '</a>', $detail, 'Linked to the edit screen for an editor of the page.' );
		$list = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-forms&view=entries&form=' . $id ), array(), $admin, $session ) );
		assert_contains( '<th scope="col">Sent from</th>', $list );
		assert_contains( esc_html( $title ), $list );
		$viewer   = cf_viewer();
		$vsession = cradle_test_cookies( $viewer );
		$vdetail  = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-forms&view=entry&entry=' . $entry_id ), array(), $viewer, $vsession ) );
		assert_contains( '<td>' . esc_html( $title ) . '</td>', $vdetail, 'Plain text for a viewer who cannot edit the page.' );
		assert_not_contains( 'post.php?post=' . $page, $vdetail );
		$editor   = cf_user( 'editor', 'editor' );
		$esession = cradle_test_cookies( $editor );
		$denied   = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-forms&view=entry&entry=' . $entry_id ), array(), $editor, $esession );
		assert_same( 403, (int) wp_remote_retrieve_response_code( $denied ) );
		assert_not_contains( esc_html( $title ), (string) wp_remote_retrieve_body( $denied ), 'Denied roles see nothing.' );

		// CSV: source ID and title columns, formula start neutralised.
		$nonce = cradle_test_nonce( $admin, $session, 'cradle_forms_export_' . $id );
		$csv   = (string) wp_remote_retrieve_body( cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array( 'action' => 'cradle_forms_export', 'form' => $id, '_wpnonce' => $nonce ) ), $admin, $session ) );
		assert_contains( 'Source ID,Sent from', str_replace( '"', '', $csv ), 'Source columns in the header.' );
		assert_contains( ',' . $page . ',' . "'" . $title, str_replace( '"', '', $csv ), 'Source ID and neutralised title in the row.' );
		$vcsv = cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array( 'action' => 'cradle_forms_export', 'form' => $id, '_wpnonce' => cradle_test_nonce( $viewer, $vsession, 'cradle_forms_export_' . $id ) ) ), $viewer, $vsession );
		assert_same( 403, (int) wp_remote_retrieve_response_code( $vcsv ) );
		assert_not_contains( esc_html( $title ), (string) wp_remote_retrieve_body( $vcsv ) );
	}
);

cradle_test(
	'forms: file uploads are checked (type, real content, double extension, size) and stored privately',
	function () {
		cf_ready();
		$id = cf_form( 'CF Test application', cf_application_content( 5 ) );
		$v  = array(
			'name'  => 'Grace',
			'email' => 'grace@example.test',
		);
		$bad = array(
			'wrong type'       => array( 'cv', 'cv.exe', cf_pdf(), 'application/octet-stream' ),
			'not allowed'      => array( 'cv', 'cv.docx', "PK\x03\x04docx", 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ),
			'spoofed MIME'     => array( 'cv', 'cv.pdf', "<?php echo 'owned'; ?>", 'application/pdf' ),
			'random bytes'     => array( 'cv', 'cv.pdf', random_bytes( 2048 ), 'application/pdf' ),
			'double extension' => array( 'cv', 'cv.php.pdf', cf_pdf(), 'application/pdf' ),
			'oversize'         => array( 'cv', 'big.pdf', cf_pdf( (int) ( 5.5 * MB_IN_BYTES ) ), 'application/pdf' ),
		);
		foreach ( $bad as $case => $file ) {
			[ $code, $json ] = cf_rest( $id, $v, array(), array( $file ) );
			assert_same( 422, $code, "{$case}: " . wp_json_encode( $json ) );
			assert_true( isset( $json['data']['field_errors']['cv'] ), "{$case}: error on the file field." );
		}
		[ $code, $json ] = cf_rest( $id, $v );
		assert_same( 'This field is required.', $json['data']['field_errors']['cv'] ?? '', 'Required file.' );
		assert_same( array(), cf_entries( $id ), 'Nothing stored for refused files.' );

		$attachments = (int) wp_count_posts( 'attachment' )->inherit;
		$content     = cf_pdf( 3000 );
		[ $code, $json ] = cf_rest( $id, $v, array(), array( array( 'cv', 'Lebenslauf Grace.pdf', $content, 'application/pdf' ) ) );
		assert_same( 200, $code, wp_json_encode( $json ) );
		$entries = cf_entries( $id );
		$files   = Entries::files( (int) $entries[0]['id'] );
		assert_same( 1, count( $files ) );
		$file = $files[0];
		assert_true( (bool) preg_match( '/^[a-f0-9]{32}$/', $file['stored_name'] ), 'Random stored name.' );
		assert_same( 'Lebenslauf Grace.pdf', $file['original_name'], 'Original name kept only as data.' );
		assert_same( 'application/pdf', $file['mime'] );
		$path = Uploads::path( $file['stored_name'] );
		assert_true( is_file( $path ) && $content === file_get_contents( $path ), 'Stored in private storage.' ); // phpcs:ignore
		assert_true( Cradle\Core\Storage::path_within( $path, cradle()->storage()->module_dir( 'forms', 'uploads' ) ) );
		assert_same( '["Lebenslauf Grace.pdf"]', cf_values( (int) $entries[0]['id'] )['cv'] );
		wp_cache_flush();
		assert_same( $attachments, (int) wp_count_posts( 'attachment' )->inherit, 'Not added to the media library.' );

		$root = cradle()->storage()->private_root();
		if ( is_array( $root ) && ! empty( $root['url'] ) ) {
			$direct = cradle_test_http( 'GET', $root['url'] . '/forms/uploads/' . $file['stored_name'] );
			assert_false( 200 === (int) wp_remote_retrieve_response_code( $direct ) && $content === wp_remote_retrieve_body( $direct ), 'The stored file is not reachable by URL.' );
		}

		$multi = cf_form( 'CF Test application multi', cf_field( 'file', array( 'name' => 'docs', 'label' => 'Docs', 'accept' => 'pdf', 'maxFiles' => 2 ) ) );
		[ $code, $json ] = cf_rest( $multi, array(), array(), array( array( 'docs', 'a.pdf', cf_pdf(), 'application/pdf' ), array( 'docs', 'b.pdf', cf_pdf(), 'application/pdf' ), array( 'docs', 'c.pdf', cf_pdf(), 'application/pdf' ) ) );
		assert_same( 422, $code, 'Too many files refused.' );
		assert_contains( 'at most 2 files', $json['data']['field_errors']['docs'] );
	}
);

cradle_test(
	'forms: oversized posts and unprotected private storage are refused without storing anything',
	function () {
		cf_ready();
		$id    = cf_form( 'CF Test toolarge', cf_application_content( 5 ) );
		$limit = wp_convert_hr_to_bytes( '8M' );
		$res   = cf_post( $id, array( 'name' => 'A', 'email' => 'a@example.test' ), array(), array( array( 'cv', 'huge.pdf', cf_pdf( $limit + 1024 * 1024 ), 'application/pdf' ) ), array( 'Referer' => get_permalink( cf_form_page( $id ) ) ) );
		assert_same( 303, (int) wp_remote_retrieve_response_code( $res ) );
		$html = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', cf_location( $res ) ) );
		assert_contains( 'too large to upload', $html );
		assert_same( array(), cf_entries( $id ) );

		// Storage that is not proven protected (simulated in this process only): files are refused.
		$exposed = static fn() => array( 'status' => 'exposed', 'detail' => 'test', 'checked' => time() );
		add_filter( 'pre_option_cradle_storage_probe', $exposed );
		try {
			assert_false( Uploads::storage_protected() );
			$field = Form_Schema::field( Form_Schema::fields( $id ), 'cv' );
			assert_wp_error( Uploads::store( array( 'name' => 'cv.pdf', 'tmp_name' => __FILE__, 'error' => 0, 'size' => 10 ), $field, 1 ), 'cradle_forms_storage', 'Nothing is stored in unprotected storage.' );
			assert_false( Form_Schema::status( $id )['storageProtected'], 'The editor status reports it.' );
		} finally {
			remove_filter( 'pre_option_cradle_storage_probe', $exposed );
		}
		assert_true( is_file( __FILE__ ), 'The source file was not moved.' );
	}
);

cradle_test(
	'forms: JPEG metadata (EXIF, comments) is removed when the file is stored',
	function () {
		cf_ready();
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			skip_test( 'GD is not available.' );
		}
		$image = imagecreatetruecolor( 16, 16 );
		ob_start();
		imagejpeg( $image, null, 90 );
		$jpeg = (string) ob_get_clean();
		$tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x00\x00\x00\x00\x00";
		$app1 = "\xFF\xE1" . pack( 'n', strlen( 'Exif' . "\0\0" . $tiff ) + 2 ) . "Exif\0\0" . $tiff;
		$com  = "\xFF\xFE" . pack( 'n', strlen( 'CFSECRETGPS' ) + 2 ) . 'CFSECRETGPS';
		$jpeg = substr( $jpeg, 0, 2 ) . $app1 . $com . substr( $jpeg, 2 );
		$id   = cf_form( 'CF Test photo', cf_field( 'file', array( 'name' => 'photo', 'label' => 'Photo', 'accept' => 'jpg,jpeg' ) ) );
		[ $code, $json ] = cf_rest( $id, array(), array(), array( array( 'photo', 'me.jpg', $jpeg, 'image/jpeg' ) ) );
		assert_same( 200, $code, wp_json_encode( $json ) );
		$file = Entries::files( (int) cf_entries( $id )[0]['id'] )[0];
		$data = (string) file_get_contents( Uploads::path( $file['stored_name'] ) ); // phpcs:ignore
		assert_same( '1', (string) $file['metadata_stripped'] );
		assert_not_contains( 'Exif', $data, 'EXIF removed.' );
		assert_not_contains( 'CFSECRETGPS', $data, 'Comment removed.' );
		assert_same( "\xFF\xD8\xFF", substr( $data, 0, 3 ), 'Still a JPEG.' );
	}
);

cradle_test(
	'forms: notifications go to static recipients and routing matches; reply-to and copy are safe',
	function () {
		cf_ready();
		cf_install_mail_capture();
		$id = cf_form(
			'CF Test notify',
			cf_contact_content(),
			array(
				'notifications' => array(
					array(
						'to'           => 'office@example.test',
						'subject'      => 'CF Test {topic} from {name}',
						'replyToField' => 'email',
						'routes'       => array(
							array( 'field' => 'topic', 'value' => 'press', 'to' => 'press@example.test' ),
							array( 'field' => 'topic', 'value' => 'general', 'to' => 'info@example.test' ),
						),
					),
				),
			)
		);
		cf_post( $id, cf_contact_values( array( 'topic' => 'press', 'name' => "Ada\r\nBcc: evil@example.test" ) ), cf_consent_hash() );
		$mails = cf_mails();
		assert_same( 1, count( $mails ), 'One notification captured.' );
		$mail = $mails[0];
		assert_same( array( 'office@example.test', 'press@example.test' ), $mail['to'], 'Static recipient plus the matching route only.' );
		assert_false( (bool) preg_match( '/[\r\n]/', $mail['subject'] ), 'No line breaks in the subject.' );
		assert_contains( 'CF Test Press enquiry from Ada Bcc: evil@example.test', $mail['subject'] );
		assert_true( in_array( 'Reply-To: ada@example.test', $mail['headers'], true ), 'Reply-To from the email field.' );
		assert_same( 0, count( array_filter( $mail['headers'], static fn( $h ) => 0 === stripos( (string) $h, 'bcc' ) ) ), 'No injected header.' );
		assert_contains( 'Press enquiry', $mail['message'] );
		assert_contains( 'admin.php?page=cradle-forms&view=entry&entry=', $mail['message'], 'Link to the entry.' );
		assert_same( array(), $mail['attachments'] );

		delete_option( 'cradle_forms_test_mails' );
		cf_post( $id, cf_contact_values( array( 'topic' => 'general' ) ), cf_consent_hash() );
		$mails = cf_mails();
		assert_same( array( 'office@example.test', 'info@example.test' ), $mails[0]['to'] ?? null, 'Other topic routes elsewhere.' );

		cf_admin();
		$settings                                       = Form_Settings::get( $id );
		$settings['notifications'][0]['copyToSubmitter'] = true;
		update_post_meta( $id, Form_Settings::META, $settings );
		delete_option( 'cradle_forms_test_mails' );
		cf_post( $id, cf_contact_values(), cf_consent_hash() );
		$mails = cf_mails();
		assert_same( 2, count( $mails ), 'Notification plus the copy to the submitter.' );
		assert_same( array( 'ada@example.test' ), $mails[1]['to'] );
		assert_not_contains( 'view=entry', $mails[1]['message'], 'The copy has no admin link.' );

		// Files: never attached; the email links to the entry.
		$app = cf_form(
			'CF Test notify files',
			cf_application_content( 5 ),
			array( 'notifications' => array( array( 'to' => 'jobs@example.test', 'subject' => 'CF Test application' ) ) )
		);
		delete_option( 'cradle_forms_test_mails' );
		[ $code ] = cf_rest( $app, array( 'name' => 'G', 'email' => 'g@example.test' ), array(), array( array( 'cv', 'cv.pdf', cf_pdf(), 'application/pdf' ) ) );
		assert_same( 200, $code );
		$mails = cf_mails();
		assert_same( array( 'jobs@example.test' ), $mails[0]['to'] ?? null );
		assert_same( array(), $mails[0]['attachments'] );
		assert_contains( '1 file was uploaded', $mails[0]['message'] );
		assert_not_contains( '%PDF', $mails[0]['message'], 'File contents never in the email.' );

		// No recipient and no matching rule: the fallback recipient.
		cf_admin();
		cradle()->settings()->update( 'forms', array( 'fallback_recipient' => 'fallback@example.test' ) );
		$fallback = cf_form( 'CF Test notify fallback', cf_contact_content(), array( 'notifications' => array( array( 'subject' => 'CF Test fallback', 'routes' => array( array( 'field' => 'topic', 'value' => 'press', 'to' => 'press@example.test' ) ) ) ) ) );
		delete_option( 'cradle_forms_test_mails' );
		cf_post( $fallback, cf_contact_values( array( 'topic' => 'general' ) ), cf_consent_hash() );
		assert_same( array( 'fallback@example.test' ), cf_mails()[0]['to'] ?? null );
		cradle()->settings()->reset( 'forms' );
	}
);

/* ---------------------------------------------------------------------- */
/* Admin: entries, files, export, delete                                   */
/* ---------------------------------------------------------------------- */

cradle_test(
	'forms: entries screens and file downloads need the view capability; GET never changes an entry',
	function () {
		cf_ready();
		$id = cf_form( 'CF Test admin', cf_application_content( 5 ) );
		[ $code ] = cf_rest( $id, array( 'name' => '=HYPERLINK("http://evil")', 'email' => 'h@example.test' ), array(), array( array( 'cv', 'cv.pdf', cf_pdf( 1500 ), 'application/pdf' ) ) );
		assert_same( 200, $code );
		$entry = cf_entries( $id )[0];
		$file  = Entries::files( (int) $entry['id'] )[0];

		$admin   = cf_user( 'admin', 'administrator' );
		$session = cradle_test_cookies( $admin );
		$list    = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-forms' ), array(), $admin, $session );
		assert_same( 200, (int) wp_remote_retrieve_response_code( $list ) );
		assert_contains( 'CF Test admin', (string) wp_remote_retrieve_body( $list ) );
		assert_contains( 'cradle-forms-badge', (string) wp_remote_retrieve_body( $list ), 'New-entry count shown.' );
		$core_list = cradle_test_http( 'GET', admin_url( 'edit.php?post_type=cradle_form' ), array(), $admin, $session );
		assert_same( 302, (int) wp_remote_retrieve_response_code( $core_list ) );
		assert_contains( 'page=cradle-forms', cf_location( $core_list ), 'The core form list opens Cradle → Forms.' );
		$entries = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-forms&view=entries&form=' . $id ), array(), $admin, $session ) );
		assert_contains( 'name="entries[]" value="' . $entry['id'] . '"', $entries );
		$detail = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-forms&view=entry&entry=' . $entry['id'] ), array(), $admin, $session ) );
		assert_contains( 'cv.pdf</a>', $detail, 'File listed with a download link.' );
		assert_contains( '=HYPERLINK(&quot;http://evil&quot;)', $detail, 'Values escaped.' );
		cf_fresh();
		assert_same( 'new', Entries::get( (int) $entry['id'] )['status'], 'Viewing does not mark as read (GET never mutates).' );

		$nonce    = cradle_test_nonce( $admin, $session, 'cradle_forms_download_' . $file['id'] );
		$download = cradle_test_http( 'GET', admin_url( 'admin-post.php?action=cradle_forms_download&file=' . $file['id'] . '&_wpnonce=' . $nonce ), array(), $admin, $session );
		assert_same( 200, (int) wp_remote_retrieve_response_code( $download ) );
		assert_same( cf_pdf( 1500 ), wp_remote_retrieve_body( $download ), 'Exact bytes.' );
		assert_contains( 'attachment;', (string) wp_remote_retrieve_header( $download, 'content-disposition' ) );
		assert_same( 'nosniff', (string) wp_remote_retrieve_header( $download, 'x-content-type-options' ) );

		$bad = cradle_test_http( 'GET', admin_url( 'admin-post.php?action=cradle_forms_download&file=' . $file['id'] . '&_wpnonce=bad' ), array(), $admin, $session );
		assert_same( 403, (int) wp_remote_retrieve_response_code( $bad ), 'Bad nonce refused.' );

		$editor   = cf_user( 'editor', 'editor' );
		$esession = cradle_test_cookies( $editor );
		$enonce   = cradle_test_nonce( $editor, $esession, 'cradle_forms_download_' . $file['id'] );
		$denied   = cradle_test_http( 'GET', admin_url( 'admin-post.php?action=cradle_forms_download&file=' . $file['id'] . '&_wpnonce=' . $enonce ), array(), $editor, $esession );
		assert_same( 403, (int) wp_remote_retrieve_response_code( $denied ), 'Editor cannot download.' );
		assert_not_contains( '%PDF', (string) wp_remote_retrieve_body( $denied ) );
		$page = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-forms&view=entries&form=' . $id ), array(), $editor, $esession );
		assert_same( 403, (int) wp_remote_retrieve_response_code( $page ), 'Editor cannot open the entries screen.' );
		$anon = cradle_test_http( 'GET', admin_url( 'admin-post.php?action=cradle_forms_download&file=' . $file['id'] . '&_wpnonce=' . $nonce ) );
		assert_not_contains( '%PDF', (string) wp_remote_retrieve_body( $anon ), 'Anonymous visitors get nothing.' );

		$viewer   = cf_viewer();
		$vsession = cradle_test_cookies( $viewer );
		$vnonce   = cradle_test_nonce( $viewer, $vsession, 'cradle_forms_download_' . $file['id'] );
		$ok       = cradle_test_http( 'GET', admin_url( 'admin-post.php?action=cradle_forms_download&file=' . $file['id'] . '&_wpnonce=' . $vnonce ), array(), $viewer, $vsession );
		assert_same( 200, (int) wp_remote_retrieve_response_code( $ok ), 'A role with only the view capability can download.' );
	}
);

cradle_test(
	'forms: mark read/spam and delete need capability and nonce; delete removes files; other forms untouched',
	function () {
		cf_ready();
		$id    = cf_form( 'CF Test triage', cf_application_content( 5 ) );
		$other = cf_form( 'CF Test triage other', cf_contact_content() );
		cf_rest( $id, array( 'name' => 'A', 'email' => 'a@example.test' ), array(), array( array( 'cv', 'a.pdf', cf_pdf(), 'application/pdf' ) ) );
		cf_rest( $id, array( 'name' => 'B', 'email' => 'b@example.test' ), array(), array( array( 'cv', 'b.pdf', cf_pdf(), 'application/pdf' ) ) );
		cf_post( $other, cf_contact_values(), cf_consent_hash() );
		$rows      = cf_entries( $id );
		$foreign   = (int) cf_entries( $other )[0]['id'];
		$admin     = cf_user( 'admin', 'administrator' );
		$session   = cradle_test_cookies( $admin );
		$post      = static function ( array $body, int $user, array $sess ) {
			return cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array_merge( array( 'action' => 'cradle_forms_entries' ), $body ) ), $user, $sess );
		};
		$nonce     = cradle_test_nonce( $admin, $session, 'cradle_forms_entries' );
		$res       = $post( array( 'op' => 'read', 'form' => $id, 'entries' => array( $rows[0]['id'], $foreign ), '_wpnonce' => $nonce ), $admin, $session );
		assert_same( 302, (int) wp_remote_retrieve_response_code( $res ) );
		cf_fresh();
		assert_same( 'read', Entries::get( (int) $rows[0]['id'] )['status'] );
		assert_same( 'new', Entries::get( $foreign )['status'], 'An entry of another form is not changed.' );

		$res = $post( array( 'op' => 'spam', 'form' => $id, 'entries' => array( $rows[1]['id'] ), '_wpnonce' => 'bad' ), $admin, $session );
		assert_same( 403, (int) wp_remote_retrieve_response_code( $res ), 'Bad nonce refused.' );
		cf_fresh();
		assert_same( 'new', Entries::get( (int) $rows[1]['id'] )['status'] );

		$editor   = cf_user( 'editor', 'editor' );
		$esession = cradle_test_cookies( $editor );
		$res      = $post( array( 'op' => 'spam', 'form' => $id, 'entries' => array( $rows[1]['id'] ), '_wpnonce' => cradle_test_nonce( $editor, $esession, 'cradle_forms_entries' ) ), $editor, $esession );
		assert_same( 403, (int) wp_remote_retrieve_response_code( $res ), 'Editor cannot change entries.' );

		$viewer   = cf_viewer();
		$vsession = cradle_test_cookies( $viewer );
		$vnonce   = cradle_test_nonce( $viewer, $vsession, 'cradle_forms_entries' );
		$res      = $post( array( 'op' => 'spam', 'form' => $id, 'entries' => array( $rows[1]['id'] ), '_wpnonce' => $vnonce ), $viewer, $vsession );
		assert_same( 302, (int) wp_remote_retrieve_response_code( $res ), 'Viewer can mark spam.' );
		$res = $post( array( 'op' => 'delete', 'form' => $id, 'entries' => array( $rows[1]['id'] ), '_wpnonce' => $vnonce ), $viewer, $vsession );
		assert_same( 403, (int) wp_remote_retrieve_response_code( $res ), 'Viewer cannot delete.' );
		cf_fresh();
		assert_same( 'spam', Entries::get( (int) $rows[1]['id'] )['status'] );

		$get = cradle_test_http( 'GET', admin_url( 'admin-post.php?action=cradle_forms_entries&op=delete&form=' . $id . '&entries[]=' . $rows[1]['id'] . '&_wpnonce=' . $nonce ), array(), $admin, $session );
		assert_same( 405, (int) wp_remote_retrieve_response_code( $get ), 'GET cannot delete.' );

		$path = Uploads::path( Entries::files( (int) $rows[1]['id'] )[0]['stored_name'] );
		assert_true( is_file( $path ) );
		$res = $post( array( 'op' => 'delete', 'form' => $id, 'entries' => array( $rows[1]['id'] ), '_wpnonce' => $nonce ), $admin, $session );
		assert_same( 302, (int) wp_remote_retrieve_response_code( $res ) );
		cf_fresh();
		assert_same( null, Entries::get( (int) $rows[1]['id'] ), 'Entry deleted.' );
		assert_same( array(), Entries::values( (int) $rows[1]['id'] ) );
		assert_same( array(), Entries::files( (int) $rows[1]['id'] ) );
		clearstatcache();
		assert_false( is_file( $path ), 'Its file is deleted.' );
		assert_true( (bool) Entries::get( (int) $rows[0]['id'] ), 'The other entry stays.' );
	}
);

cradle_test(
	'forms: CSV export needs capability, nonce and POST; formula cells are neutralised',
	function () {
		cf_ready();
		$id = cf_form( 'CF Test export', cf_contact_content() );
		cf_post( $id, cf_contact_values( array( 'name' => '=HYPERLINK("http://evil","x")', 'message' => '+SUM(A1)' ) ), cf_consent_hash() );
		cf_post( $id, cf_contact_values( array( 'name' => '@cmd', 'message' => '-1' ) ), cf_consent_hash() );
		$admin   = cf_user( 'admin', 'administrator' );
		$session = cradle_test_cookies( $admin );
		$nonce   = cradle_test_nonce( $admin, $session, 'cradle_forms_export_' . $id );
		$res     = cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array( 'action' => 'cradle_forms_export', 'form' => $id, '_wpnonce' => $nonce ) ), $admin, $session );
		assert_same( 200, (int) wp_remote_retrieve_response_code( $res ) );
		assert_contains( 'text/csv', (string) wp_remote_retrieve_header( $res, 'content-type' ) );
		$csv = (string) wp_remote_retrieve_body( $res );
		assert_contains( 'Your name', $csv );
		assert_contains( "'=HYPERLINK", $csv );
		assert_contains( "'+SUM(A1)", $csv );
		assert_contains( "'@cmd", $csv );
		assert_contains( "'-1", $csv );
		assert_contains( 'General question', $csv, 'Choice labels exported.' );
		assert_false( (bool) preg_match( '/(^|,)"?=HYPERLINK/m', $csv ), 'No raw formula at a cell start.' );
		assert_same( "'=x", Csv::cell( '=x' ) );
		assert_same( 'x=1', Csv::cell( 'x=1' ) );

		$res = cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array( 'action' => 'cradle_forms_export', 'form' => $id, '_wpnonce' => 'bad' ) ), $admin, $session );
		assert_same( 403, (int) wp_remote_retrieve_response_code( $res ) );
		$viewer   = cf_viewer();
		$vsession = cradle_test_cookies( $viewer );
		$res      = cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array( 'action' => 'cradle_forms_export', 'form' => $id, '_wpnonce' => cradle_test_nonce( $viewer, $vsession, 'cradle_forms_export_' . $id ) ) ), $viewer, $vsession );
		assert_same( 403, (int) wp_remote_retrieve_response_code( $res ), 'Viewing does not include exporting.' );
		assert_not_contains( 'HYPERLINK', (string) wp_remote_retrieve_body( $res ) );
		$res = cradle_test_http( 'GET', admin_url( 'admin-post.php?action=cradle_forms_export&form=' . $id . '&_wpnonce=' . $nonce ), array(), $admin, $session );
		assert_same( 405, (int) wp_remote_retrieve_response_code( $res ) );
	}
);

cradle_test(
	'forms: retention cron deletes entries and files older than the form setting; 0 keeps everything',
	function () {
		cf_ready();
		global $wpdb;
		$keep  = cf_form( 'CF Test keep', cf_application_content( 5 ) );
		$short = cf_form( 'CF Test retention', cf_application_content( 5 ), array( 'retentionDays' => 30 ) );
		foreach ( array( $keep, $short ) as $form ) {
			cf_rest( $form, array( 'name' => 'Old', 'email' => 'old@example.test' ), array(), array( array( 'cv', 'old.pdf', cf_pdf(), 'application/pdf' ) ) );
			cf_rest( $form, array( 'name' => 'New', 'email' => 'new@example.test' ), array(), array( array( 'cv', 'new.pdf', cf_pdf(), 'application/pdf' ) ) );
		}
		$old_keep  = (int) cf_entries( $keep )[0]['id'];
		$old_short = (int) cf_entries( $short )[0]['id'];
		$new_short = (int) cf_entries( $short )[1]['id'];
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'entries' ) . ' SET created_at = %s WHERE id IN (%d, %d)', gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ), $old_keep, $old_short ) ); // phpcs:ignore WordPress.DB
		$path   = Uploads::path( Entries::files( $old_short )[0]['stored_name'] );
		$report = Retention::run();
		assert_true( $report['deleted'] >= 1 );
		assert_same( null, Entries::get( $old_short ), 'Old entry of a form with retention deleted.' );
		clearstatcache();
		assert_false( is_file( $path ), 'Its file deleted.' );
		assert_true( (bool) Entries::get( $new_short ), 'Recent entry kept.' );
		assert_true( (bool) Entries::get( $old_keep ), 'Retention 0 keeps old entries.' );
		assert_true( false !== has_action( Retention::HOOK, array( Retention::class, 'run' ) ), 'Cron callback attached.' );
	}
);

cradle_test(
	'forms: privacy exporter and eraser match only the field marked as the submitter’s own email',
	function () {
		cf_ready();
		$marked   = cf_form( 'CF Test privacy', cf_contact_content() );
		$unmarked = cf_form(
			'CF Test privacy referee',
			cf_field( 'text', array( 'name' => 'name', 'label' => 'Name' ) ) . cf_field( 'email', array( 'name' => 'referee', 'label' => 'Referee email' ) )
		);
		cf_post( $marked, cf_contact_values( array( 'email' => 'Person@Example.test' ) ), cf_consent_hash() );
		cf_post( $unmarked, array( 'name' => 'Someone', 'referee' => 'person@example.test' ) );
		assert_same( 1, count( cf_entries( $unmarked ) ) );
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		assert_true( isset( $exporters['cradle-forms'], $erasers['cradle-forms'] ) );
		$export = Privacy::export( 'person@example.test', 1 );
		$ours   = array_values( array_filter( $export['data'], static fn( $item ) => false !== strpos( wp_json_encode( $item ), 'CF Test privacy' ) ) );
		assert_same( 1, count( $ours ), 'Only the entry with the marked field is exported.' );
		assert_contains( 'Ada Example', wp_json_encode( $ours[0]['data'] ) );
		assert_not_contains( 'CF Test privacy referee', wp_json_encode( $export['data'] ) );
		$erase = Privacy::erase( 'person@example.test', 1 );
		assert_true( $erase['items_removed'] );
		assert_same( array(), cf_entries( $marked ), 'Marked entry erased.' );
		assert_same( 1, count( cf_entries( $unmarked ) ), 'Entry that only mentions the address stays.' );
	}
);

/* ---------------------------------------------------------------------- */
/* Units: validation, IP buckets, languages, isolation, uninstall          */
/* ---------------------------------------------------------------------- */

cradle_test(
	'forms: server-side validation of field types',
	function () {
		cf_ready();
		$f = static function ( string $type, array $attrs = array() ) {
			return Field_Types::config_from_block( $type, array_merge( array( 'name' => 'f', 'label' => 'F' ), $attrs ) )['field'];
		};
		$err = static fn( array $field, $value ) => Field_Types::check( $field, $value )['error'];
		assert_same( null, $err( $f( 'tel' ), '+49 (30) 123-4567' ) );
		assert_same( null, $err( $f( 'tel' ), '٠١٢٣٤٥٦٧٨' ), 'Arabic-Indic digits accepted.' );
		assert_true( null !== $err( $f( 'tel' ), 'call me' ) );
		assert_same( null, $err( $f( 'url' ), 'https://example.test/x' ) );
		assert_true( null !== $err( $f( 'url' ), 'javascript:alert(1)' ), 'javascript: refused.' );
		assert_true( null !== $err( $f( 'url' ), 'ftp://example.test' ) );
		$number = $f( 'number', array( 'min' => 1, 'max' => 10, 'step' => 0.5 ) );
		assert_same( null, $err( $number, '2.5' ) );
		assert_true( null !== $err( $number, '2.25' ), 'Step enforced.' );
		assert_true( null !== $err( $number, '11' ) );
		assert_true( null !== $err( $number, '1e3' ) );
		$date = $f( 'date', array( 'min' => '2026-01-01', 'max' => '2026-12-31' ) );
		assert_same( null, $err( $date, '2026-02-28' ) );
		assert_true( null !== $err( $date, '2026-02-30' ) );
		assert_true( null !== $err( $date, '2025-12-31' ) );
		$boxes = $f(
			'checkboxes',
			array(
				'choices'    => array( array( 'label' => 'A', 'value' => 'a' ), array( 'label' => 'B', 'value' => 'b' ), array( 'label' => 'C' ) ),
				'maxChoices' => 2,
			)
		);
		assert_same( array( 'a', 'C' ), Field_Types::check( $boxes, array( 'a', 'C' ) )['value'] );
		assert_true( null !== $err( $boxes, array( 'a', 'zzz' ) ), 'Unlisted choice refused.' );
		assert_true( null !== $err( $boxes, array( 'a', 'b', 'C' ) ), 'Maximum selections.' );
		assert_true( null !== $err( $f( 'text' ), array( 'x' ) ), 'Arrays refused for scalar fields.' );
		assert_true( null !== $err( $f( 'text', array( 'maxLength' => 3 ) ), 'abcd' ) );
		assert_same( 'abc', Field_Types::check( $f( 'text' ), '<b>abc</b>' )['value'], 'Tags stripped.' );
		assert_same( Field_Types::required_message(), $err( $f( 'email', array( 'required' => true ) ), '  ' ) );
		$consent = $f( 'consent', array( 'label' => 'I <strong>agree</strong> <script>x</script><a href="javascript:x" onclick="y">z</a>' ) );
		assert_not_contains( '<script', $consent['label'] );
		assert_not_contains( 'onclick', $consent['label'] );
		assert_not_contains( 'javascript:', $consent['label'] );
		assert_contains( '<strong>agree</strong>', $consent['label'] );
	}
);

cradle_test(
	'forms: IPv4-mapped IPv6 addresses get their own rate-limit bucket; IPv6 groups by /64; proxy filter',
	function () {
		cf_ready();
		assert_same( 'v4:203.0.113.7', Security::network_key( '::ffff:203.0.113.7' ) );
		assert_same( Security::network_key( '203.0.113.7' ), Security::network_key( '::ffff:203.0.113.7' ) );
		assert_true( Security::network_key( '::ffff:203.0.113.7' ) !== Security::network_key( '::ffff:203.0.113.8' ), 'Different IPv4 visitors are different buckets.' );
		assert_same( Security::network_key( '2001:db8:1:2::1' ), Security::network_key( '2001:db8:1:2:ffff::9' ), 'Same /64.' );
		assert_true( Security::network_key( '2001:db8:1:2::1' ) !== Security::network_key( '2001:db8:1:3::1' ) );
		$filter = static fn() => '198.51.100.20';
		add_filter( 'cradle_forms/client_ip', $filter );
		try {
			assert_same( '198.51.100.20', Security::client_ip() );
			assert_same( Security::hmac( 'v4:198.51.100.20', 'ip' ), Security::client_hash() );
		} finally {
			remove_filter( 'cradle_forms/client_ip', $filter );
		}
		add_filter( 'cradle_forms/client_ip', $bad = static fn() => 'not-an-ip' );
		try {
			assert_same( '0.0.0.0', Security::client_ip(), 'Invalid filtered value falls back safely.' );
		} finally {
			remove_filter( 'cradle_forms/client_ip', $bad );
		}
	}
);

cradle_test(
	'forms: language integration falls back to the locale without Cradle Languages (isolated process)',
	function () {
		cf_ready();
		$id     = cf_form( 'CF Test language', cf_contact_content() );
		$output = array();
		exec( escapeshellarg( cf_php() ) . ' ' . escapeshellarg( CRADLE_TEST_ROOT . '/tests/fixtures/forms/run-isolated.php' ) . ' no-languages ' . (int) $id . ' 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$data = json_decode( (string) end( $output ), true );
		assert_true( 0 === $code && is_array( $data ), 'Runner failed: ' . substr( implode( "\n", $output ), 0, 500 ) );
		assert_false( $data['active'], 'Cradle Languages not active in the isolated process.' );
		assert_same( $data['locale'], $data['current'], 'Language = WordPress locale.' );
		assert_same( $id, $data['translated'], 'The selected form is rendered as is.' );
		assert_true( $data['rendered'] );
		assert_contains( '.' . $data['locale'] . '.' . $data['locale'] . '.', $data['token'], 'Token carries the locale.' );
		assert_same( array(), $data['errors'] );
	}
);

cradle_test(
	'forms: with Cradle Languages, a form translation created over REST renders on the Arabic page; entries record ar, the translated page and an /ar/ link',
	function () {
		cf_ready();
		if ( ! Language::languages_active() || ! function_exists( 'cradle_link_translations' ) || ! cradle()->settings()->section( 'languages' ) ) {
			skip_test( 'Cradle Languages is not active.' );
		}
		cf_admin();
		cf_install_mail_capture();
		$flush = static function () {
			wp_cache_flush();
			if ( class_exists( 'Cradle\Languages\Config' ) ) {
				Cradle\Languages\Config::flush();
			}
			if ( class_exists( 'Cradle\Languages\Content' ) && method_exists( 'Cradle\Languages\Content', 'flush_cache' ) ) {
				Cradle\Languages\Content::flush_cache();
			}
			Form_Schema::flush();
		};
		$terms_before = array_map( 'strval', (array) get_terms( array( 'taxonomy' => 'cradle_language', 'hide_empty' => false, 'fields' => 'slugs' ) ) );
		$saved        = get_option( 'cradle_languages_settings', null ); // Restored afterwards when it existed.
		$own          = array();
		try {
			$result = cradle()->settings()->update(
				'languages',
				array(
					'languages' => array(
						array( 'slug' => 'en', 'locale' => 'en_US', 'name' => 'English', 'native' => 'English', 'dir' => 'ltr', 'default' => true ),
						array( 'slug' => 'ar', 'locale' => 'ar', 'name' => 'Arabic', 'native' => 'العربية', 'dir' => 'rtl', 'default' => false ),
					),
				)
			);
			assert_true( is_array( $result ) && ! $result['errors'], 'Arabic configured: ' . wp_json_encode( is_array( $result ) ? $result['errors'] : $result ) );
			$flush();
			assert_true( post_type_supports( Post_Type::NAME, 'cradle-languages' ) );
			assert_true( in_array( Post_Type::NAME, Cradle\Languages\Config::translatable_post_types(), true ), 'Forms are translatable by default.' );

			$content = cf_field( 'text', array( 'name' => 'name', 'label' => 'Name', 'required' => true ) )
				. cf_field( 'email', array( 'name' => 'email', 'label' => 'Email', 'submitterEmail' => true ) )
				. cf_field(
					'select',
					array(
						'name'    => 'topic',
						'label'   => 'Topic',
						'choices' => array(
							array( 'label' => 'Press', 'value' => 'press' ),
							array( 'label' => 'General', 'value' => 'general' ),
						),
					)
				);
			$en_form = cf_form(
				'CF Test lang form en',
				$content,
				array(
					'notifications' => array(
						array(
							'to'      => 'office@example.test',
							'subject' => 'CF Test lang {source}',
							'routes'  => array( array( 'field' => 'topic', 'value' => 'press', 'to' => 'press@example.test' ) ),
						),
					),
				)
			);
			$own[] = $en_form;

			// An editor (no cradle_manage_forms) cannot create a form translation.
			$editor   = cf_user( 'editor', 'editor' );
			$esession = cradle_test_cookies( $editor );
			$denied   = cradle_test_http( 'POST', rest_url( 'cradle-languages/v1/posts/' . $en_form . '/translations' ), array( 'body' => array( 'lang' => 'ar' ), 'headers' => array( 'X-WP-Nonce' => cradle_test_nonce( $editor, $esession, 'wp_rest' ) ) ), $editor, $esession );
			assert_same( 403, (int) wp_remote_retrieve_response_code( $denied ), 'Editor refused: ' . substr( (string) wp_remote_retrieve_body( $denied ), 0, 200 ) );
			$flush();
			assert_same( 0, (int) cradle_get_translation( $en_form, 'ar' ), 'No translation was created.' );

			// The administrator adds the Arabic translation over REST (a draft copy).
			$admin   = cf_user( 'admin', 'administrator' );
			$session = cradle_test_cookies( $admin );
			$nonce   = cradle_test_nonce( $admin, $session, 'wp_rest' );
			$created = cradle_test_http( 'POST', rest_url( 'cradle-languages/v1/posts/' . $en_form . '/translations' ), array( 'body' => array( 'lang' => 'ar' ), 'headers' => array( 'X-WP-Nonce' => $nonce ) ), $admin, $session );
			$json    = json_decode( (string) wp_remote_retrieve_body( $created ), true );
			assert_same( 201, (int) wp_remote_retrieve_response_code( $created ), 'Translation created: ' . substr( (string) wp_remote_retrieve_body( $created ), 0, 300 ) );
			$ar_form = (int) $json['id'];
			$own[]   = $ar_form;
			$GLOBALS['cf_fixtures']['posts'][] = $ar_form;
			assert_true( in_array( Form_Settings::META, (array) $json['report']['metaCopied'], true ), 'Settings copied.' );
			assert_false( in_array( Form_Schema::META, (array) $json['report']['metaCopied'], true ), 'The schema is not copied: it is derived from the copy.' );
			$flush();
			$copy = get_post( $ar_form );
			assert_same( array( Post_Type::NAME, 'draft' ), array( $copy->post_type, $copy->post_status ) );
			assert_same( 'ar', cradle_language_of( $ar_form )['slug'] ?? null );
			assert_same( $ar_form, (int) cradle_get_translation( $en_form, 'ar' ) );
			$schema = Form_Schema::get( $ar_form );
			assert_true( is_array( $schema ) && Form_Schema::matches_content( $copy, $schema ), 'Schema derived from the copied content.' );
			assert_same( Form_Schema::fields( $en_form ), Form_Schema::fields( $ar_form ), 'Same fields.' );
			assert_same( Form_Settings::get( $en_form ), Form_Settings::get( $ar_form ), 'Same settings and routing.' );

			// The translator translates a label and publishes the translation (REST, as the editor does).
			$ar_content = str_replace( '"label":"Name"', '"label":"الاسم"', $copy->post_content );
			$published  = cradle_test_http(
				'POST',
				rest_url( 'wp/v2/cradle-forms/' . $ar_form ),
				array(
					'headers' => array(
						'X-WP-Nonce'   => $nonce,
						'Content-Type' => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'status'  => 'publish',
							'title'   => 'CF Test lang form ar',
							'content' => $ar_content,
						)
					),
				),
				$admin,
				$session
			);
			assert_same( 200, (int) wp_remote_retrieve_response_code( $published ), substr( (string) wp_remote_retrieve_body( $published ), 0, 300 ) );
			$flush();
			assert_same( 'الاسم', Form_Schema::field( Form_Schema::fields( $ar_form ), 'name' )['label'] ?? null, 'Translated label in the derived schema.' );

			// Pages: the English page embeds the English form; its Arabic translation keeps the same block.
			$en_page = cf_page( cf_block( $en_form ), 'CF Test lang page' );
			$ar_page = cf_page( cf_block( $en_form ), 'CF Test lang page ar' );
			$own     = array_merge( $own, array( $en_page, $ar_page ) );
			$linked  = cradle_link_translations(
				array(
					'en' => $en_page,
					'ar' => $ar_page,
				)
			);
			assert_true( ! is_wp_error( $linked ), 'Pages linked: ' . ( is_wp_error( $linked ) ? $linked->get_error_message() : '' ) );
			$flush();

			$en_html = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', get_permalink( $en_page ) ) );
			assert_contains( 'data-cradle-form="' . $en_form . '"', $en_html, 'The English page renders the English form.' );

			$slug = (string) get_post_field( 'post_name', $ar_page );
			$res  = cradle_test_http( 'GET', home_url( '/ar/' . $slug . '/' ) );
			$html = (string) wp_remote_retrieve_body( $res );
			assert_same( 200, (int) wp_remote_retrieve_response_code( $res ), 'The Arabic page is served under /ar/.' );
			assert_true( (bool) preg_match( '/<html[^>]*dir="rtl"/', $html ), 'Right-to-left page.' );
			assert_contains( 'data-cradle-form="' . $ar_form . '"', $html, 'The Arabic page renders the translation of the selected form.' );
			assert_contains( 'الاسم', $html );
			$token = Security::read_token( cf_hidden( $html, '_cf_ts' ) );
			assert_same( array( $ar_form, 'ar', 'ar', $ar_page ), array( $token['form'] ?? null, $token['language'] ?? null, $token['locale'] ?? null, $token['source'] ?? null ), 'Token: translated form, language, locale and the translated page.' );

			delete_option( 'cradle_forms_test_mails' );
			cf_post( $ar_form, array( 'name' => 'سارة', 'email' => 'sara@example.test', 'topic' => 'press' ), array( '_cf_ts' => cf_hidden( $html, '_cf_ts' ), '_cf_return' => cf_hidden( $html, '_cf_return' ) ) );
			$entries = cf_entries( $ar_form );
			assert_same( 1, count( $entries ), 'Entry stored for the Arabic form.' );
			assert_same( array( 'ar', 'ar', $ar_page ), array( $entries[0]['language'], $entries[0]['locale'], (int) $entries[0]['source_id'] ) );
			assert_same( get_post( $ar_page )->post_title, $entries[0]['source_title'] );
			assert_same( array(), cf_entries( $en_form ), 'Nothing stored for the English form.' );

			$mails = array_values( array_filter( cf_mails(), static fn( $m ) => 0 === strpos( $m['subject'], 'CF Test lang ' ) ) );
			assert_same( 1, count( $mails ), 'One notification.' );
			assert_same( array( 'office@example.test', 'press@example.test' ), $mails[0]['to'], 'Copied routing applies to the translation.' );
			assert_true( (bool) preg_match( '#Sent from: .+ \((https?://[^)]*/ar/' . preg_quote( $slug, '#' ) . '/)\)#', $mails[0]['message'], $m ), 'The "Sent from" link of the Arabic page carries /ar/: ' . substr( $mails[0]['message'], -300 ) );
		} finally {
			cradle()->settings()->reset( 'languages' );
			if ( null !== $saved ) {
				update_option( 'cradle_languages_settings', $saved );
			}
			foreach ( $own as $id ) {
				Entries::delete( array_map( 'intval', wp_list_pluck( cf_entries( (int) $id ), 'id' ) ) );
				wp_delete_post( (int) $id, true );
			}
			foreach ( (array) get_terms( array( 'taxonomy' => 'cradle_language', 'hide_empty' => false ) ) as $term ) {
				if ( $term instanceof WP_Term && ! in_array( $term->slug, $terms_before, true ) ) {
					wp_delete_term( $term->term_id, 'cradle_language' );
				}
			}
			$flush();
		}
		if ( null === $saved ) {
			assert_false( (bool) get_option( 'cradle_languages_settings' ), 'Cradle Languages settings reset.' );
		}
	}
);

cradle_test(
	'forms: core-missing guard pauses forms without errors (isolated process)',
	function () {
		cf_ready();
		cf_user( 'admin', 'administrator' );
		$output = array();
		exec( escapeshellarg( cf_php() ) . ' ' . escapeshellarg( CRADLE_TEST_ROOT . '/tests/fixtures/forms/run-isolated.php' ) . ' no-core 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$data = json_decode( (string) end( $output ), true );
		assert_true( 0 === $code && is_array( $data ), 'Runner failed: ' . substr( implode( "\n", $output ), 0, 500 ) );
		assert_true( $data['formsLoaded'] && ! $data['coreLoaded'] );
		assert_true( $data['notice'], 'Administrators see the core-missing notice.' );
		assert_false( $data['postType'] || $data['blockRegistered'] || $data['submitHandler'], 'Nothing is booted without core.' );
		assert_same( '', $data['renderedBlock'] );
		assert_same( array(), $data['errors'] );
	}
);

cradle_test(
	'forms: uninstall retains everything by default and purges only its own data under the purge policy',
	function () {
		cf_ready();
		global $wpdb;
		$runner = CRADLE_TEST_ROOT . '/tests/fixtures/forms/run-uninstall.php';
		$id     = cf_form( 'CF Test uninstall', cf_application_content( 5 ) );
		[ $code ] = cf_rest( $id, array( 'name' => 'U', 'email' => 'u@example.test' ), array(), array( array( 'cv', 'u.pdf', cf_pdf(), 'application/pdf' ) ) );
		assert_same( 200, $code );
		$entry    = (int) cf_entries( $id )[0]['id'];
		$path     = Uploads::path( Entries::files( $entry )[0]['stored_name'] );
		$sentinel = get_option( 'blogname' );
		$page     = cf_page( cf_block( $id ) );

		exec( escapeshellarg( cf_php() ) . ' ' . escapeshellarg( $runner ) . ' 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		assert_same( 0, $code, implode( "\n", $output ) );
		cf_fresh();
		assert_true( Schema::exists() && (bool) Entries::get( $entry ) && is_file( $path ) && (bool) get_post( $id ), 'Retain (default) keeps tables, entries, files and forms.' );

		$policies          = get_option( 'cradle_uninstall_policy', array() );
		$policies          = is_array( $policies ) ? $policies : array();
		$policies['forms'] = 'purge';
		update_option( 'cradle_uninstall_policy', $policies, false );
		$output = array();
		try {
			exec( escapeshellarg( cf_php() ) . ' ' . escapeshellarg( $runner ) . ' 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			assert_same( 0, $code, implode( "\n", $output ) );
			cf_fresh();
			wp_roles()->for_site( get_current_blog_id() );
			assert_false( Schema::exists(), 'Purge drops the tables.' );
			assert_same( null, get_post( $id ), 'Purge deletes forms.' );
			assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $id ) ) ); // phpcs:ignore WordPress.DB
			clearstatcache();
			assert_false( is_file( $path ), 'Purge deletes uploaded files.' );
			assert_false( get_option( Schema::OPTION ) );
			assert_false( (bool) wp_next_scheduled( Retention::HOOK ) );
			assert_false( get_role( 'administrator' )->has_cap( Caps::MANAGE ), 'Purge revokes the capabilities.' );
			assert_true( get_role( 'administrator' )->has_cap( 'cradle_manage_settings' ), 'Core capabilities untouched.' );
			assert_same( $sentinel, get_option( 'blogname' ) );
			assert_true( (bool) get_post( $page ), 'Pages embedding a form are user content and stay.' );
			assert_false( isset( get_option( 'cradle_uninstall_policy', array() )['forms'] ), 'The purge policy is consumed.' );
		} finally {
			wp_cache_flush();
			$after = get_option( 'cradle_uninstall_policy', array() );
			if ( is_array( $after ) && isset( $after['forms'] ) ) {
				unset( $after['forms'] );
				update_option( 'cradle_uninstall_policy', $after, false );
			}
			// Restore the active plugin's state: tables, capabilities, cron, version.
			Lifecycle::activate();
			wp_roles()->for_site( get_current_blog_id() );
			foreach ( Caps::all() as $cap ) {
				get_role( 'administrator' )->add_cap( $cap );
			}
		}
		assert_true( Schema::exists() && get_role( 'administrator' )->has_cap( Caps::MANAGE ), 'Plugin state restored after the purge test.' );
	}
);

/* ---------------------------------------------------------------------- */
/* Shipped translations (languages/) and forms selected by address name    */
/* ---------------------------------------------------------------------- */

/**
 * The shipped translation source: locale => lookup key ("context\4msgid") => entry.
 */
function cf_shipped_source(): array {
	$data = json_decode( (string) file_get_contents( WP_PLUGIN_DIR . '/cradle-forms/languages/cradle-forms.json' ), true );
	$out  = array();
	foreach ( array( 'de_DE', 'ar' ) as $locale ) {
		foreach ( $data[ $locale ]['entries'] as $row ) {
			$out[ $locale ][ ( isset( $row['context'] ) ? $row['context'] . "\4" : '' ) . $row['msgid'] ] = $row;
		}
	}
	return $out;
}

/**
 * Runs $fn with Cradle Translations' runtime overrides switched off (when it is active), so only
 * the plugin's own languages/ files can translate; the settings are restored afterwards.
 *
 * @return mixed
 */
function cf_without_overrides( callable $fn ) {
	$active = cradle()->modules()->is_ready( 'translations' ) && cradle()->settings()->section( 'translations' );
	if ( ! $active ) {
		return $fn();
	}
	$saved  = get_option( 'cradle_translations_settings', null );
	$values = cradle()->settings()->values( 'translations' );
	cradle()->settings()->update( 'translations', array_merge( $values, array( 'runtime_enabled' => false ) ), false );
	try {
		assert_false( (bool) ( get_option( 'cradle_translations_settings' )['runtime_enabled'] ?? true ), 'Cradle Translations overrides are off.' );
		return $fn();
	} finally {
		if ( null === $saved ) {
			delete_option( 'cradle_translations_settings' );
		} else {
			cradle()->settings()->update( 'translations', $values, false );
		}
	}
}

/** Drops this process' caches of language settings, translation groups and schemas. */
function cf_lang_flush() {
	wp_cache_flush();
	if ( class_exists( 'Cradle\Languages\Config' ) ) {
		Cradle\Languages\Config::flush();
	}
	if ( class_exists( 'Cradle\Languages\Content' ) ) {
		Cradle\Languages\Content::flush_cache();
	}
	Form_Schema::flush();
}

/**
 * Runs $fn with English (default) and Arabic configured in Cradle Languages; restores the previous
 * language settings and removes language terms created meanwhile. Skips without Cradle Languages.
 *
 * @return mixed
 */
function cf_with_arabic( callable $fn ) {
	if ( ! Language::languages_active() || ! function_exists( 'cradle_link_translations' ) || ! cradle()->settings()->section( 'languages' ) ) {
		skip_test( 'Cradle Languages is not active.' );
	}
	cf_admin();
	$terms_before = array_map( 'strval', (array) get_terms( array( 'taxonomy' => 'cradle_language', 'hide_empty' => false, 'fields' => 'slugs' ) ) );
	$saved        = get_option( 'cradle_languages_settings', null );
	try {
		$result = cradle()->settings()->update(
			'languages',
			array(
				'languages' => array(
					array( 'slug' => 'en', 'locale' => 'en_US', 'name' => 'English', 'native' => 'English', 'dir' => 'ltr', 'default' => true ),
					array( 'slug' => 'ar', 'locale' => 'ar', 'name' => 'Arabic', 'native' => 'العربية', 'dir' => 'rtl', 'default' => false ),
				),
			)
		);
		assert_true( is_array( $result ) && ! $result['errors'], 'Arabic configured: ' . wp_json_encode( is_array( $result ) ? $result['errors'] : $result ) );
		cf_lang_flush();
		return $fn();
	} finally {
		cf_admin();
		cradle()->settings()->reset( 'languages' );
		if ( null !== $saved ) {
			update_option( 'cradle_languages_settings', $saved );
		}
		foreach ( (array) get_terms( array( 'taxonomy' => 'cradle_language', 'hide_empty' => false ) ) as $term ) {
			if ( $term instanceof WP_Term && ! in_array( $term->slug, $terms_before, true ) ) {
				wp_delete_term( $term->term_id, 'cradle_language' );
			}
		}
		cf_lang_flush();
	}
}

/** Sets a form's address name (post_name) as an administrator. */
function cf_set_slug( int $form_id, string $slug ) {
	cf_admin();
	wp_update_post(
		array(
			'ID'        => $form_id,
			'post_name' => $slug,
		)
	);
	clean_post_cache( $form_id );
	Form_Schema::flush( $form_id );
	assert_same( $slug, get_post_field( 'post_name', $form_id ), 'Slug stored.' );
}

cradle_test(
	'forms: shipped translations cover every string the Cradle Translations extractor finds, valid in de_DE and ar',
	function () {
		cf_ready();
		if ( ! cradle()->modules()->is_ready( 'translations' ) || ! class_exists( '\\Cradle\\Translations\\Extractor' ) ) {
			skip_test( 'Cradle Translations is not active (its extractor and validator are needed).' );
		}
		$manifest = \Cradle\Translations\Extractor::build_manifest();
		$entries  = array_values( array_filter( $manifest['entries'], static fn( $e ) => 'cradle-forms' === $e['domain'] ) );
		assert_true( count( $entries ) > 250, 'Strings found: ' . count( $entries ) );
		$dir = WP_PLUGIN_DIR . '/cradle-forms/languages/';
		foreach ( array( 'de_DE', 'ar' ) as $locale ) {
			$file = include $dir . 'cradle-forms-' . $locale . '.l10n.php';
			assert_true( is_array( $file ) && is_array( $file['messages'] ?? null ), "{$locale} .l10n.php returns a translation array." );
			assert_same( $locale, $file['language'] );
			assert_contains( 'ar' === $locale ? 'nplurals=6;' : 'nplurals=2;', (string) $file['plural-forms'] );
			$missing = array();
			$invalid = array();
			foreach ( $entries as $e ) {
				$key = ( null !== $e['context'] ? $e['context'] . "\4" : '' ) . $e['msgid'];
				if ( ! isset( $file['messages'][ $key ] ) ) {
					$missing[] = $e['msgid'];
					continue;
				}
				$forms   = null !== $e['msgidPlural'] ? explode( "\0", $file['messages'][ $key ] ) : array( $file['messages'][ $key ] );
				$checked = \Cradle\Translations\Validator::check_translation( $e, $forms, $locale );
				if ( is_wp_error( $checked ) || ! $checked ) {
					$invalid[] = $e['msgid'] . ( is_wp_error( $checked ) ? ' — ' . $checked->get_error_message() : '' );
				}
				if ( $e['js'] ) {
					// Every script that uses the string ships it in its Jed file.
					foreach ( $e['references'] as $ref ) {
						if ( preg_match( '#^plugins/cradle-forms/(.+\.js):\d+$#', $ref, $m ) ) {
							$jed = json_decode( (string) file_get_contents( $dir . 'cradle-forms-' . $locale . '-' . md5( $m[1] ) . '.json' ), true );
							if ( ( $jed['locale_data']['messages'][ $key ] ?? null ) !== $forms ) {
								$missing[] = $e['msgid'] . ' (' . $m[1] . ')';
							}
						}
					}
				}
			}
			assert_same( array(), array_slice( $missing, 0, 20 ), "{$locale}: every string is shipped." );
			assert_same( array(), array_slice( $invalid, 0, 20 ), "{$locale}: every shipped translation is valid." );
		}
	}
);

cradle_test(
	'forms: generated translation files match languages/cradle-forms.json (generator rerun into a temporary folder; CLI only)',
	function () {
		cf_ready();
		$script = WP_PLUGIN_DIR . '/cradle-forms/bin/build-languages.php';
		$dir    = WP_PLUGIN_DIR . '/cradle-forms/languages';
		$res    = cradle_test_http( 'GET', content_url( 'plugins/cradle-forms/bin/build-languages.php' ) );
		assert_same( '', trim( (string) wp_remote_retrieve_body( $res ) ), 'A web request gets no output.' );
		$tmp = get_temp_dir() . 'cradle-forms-build-' . wp_generate_password( 8, false, false );
		wp_mkdir_p( $tmp );
		try {
			exec( escapeshellarg( cf_php() ) . ' ' . escapeshellarg( $script ) . ' --quiet --out=' . escapeshellarg( $tmp ) . ' 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			assert_same( 0, $code, 'Generator: ' . implode( "\n", $output ) );
			$built   = array_map( 'basename', (array) glob( $tmp . '/*' ) );
			$shipped = array_values( array_diff( array_map( 'basename', (array) glob( $dir . '/*' ) ), array( 'cradle-forms.json' ) ) );
			sort( $built );
			sort( $shipped );
			assert_same( $shipped, $built, 'The same files are generated.' );
			foreach ( array( 'assets/js/fields-editor.js', 'assets/js/form-editor.js', 'blocks/form/editor.js' ) as $script_path ) {
				foreach ( array( 'de_DE', 'ar' ) as $locale ) {
					assert_true( in_array( 'cradle-forms-' . $locale . '-' . md5( $script_path ) . '.json', $built, true ), "Jed file for {$script_path} ({$locale})." );
				}
			}
			foreach ( $built as $name ) {
				assert_same( str_replace( "\r\n", "\n", (string) file_get_contents( $dir . '/' . $name ) ), (string) file_get_contents( $tmp . '/' . $name ), "{$name} matches the source." );
			}
			$output = array();
			exec( escapeshellarg( cf_php() ) . ' ' . escapeshellarg( $script ) . ' --check 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			assert_same( 0, $code, '--check: ' . implode( "\n", $output ) );
		} finally {
			foreach ( (array) glob( $tmp . '/*' ) as $file ) {
				unlink( $file );
			}
			rmdir( $tmp );
		}
	}
);

cradle_test(
	'forms: shipped translations load in switch_to_locale() (ar, de_DE), for editor scripts, for block metadata and for module labels',
	function () {
		cf_ready();
		$source = cf_shipped_source();
		foreach ( array( 'ar', 'de_DE' ) as $locale ) {
			if ( ! switch_to_locale( $locale ) ) {
				skip_test( "switch_to_locale( '{$locale}' ) is refused: no WordPress language pack and no Cradle Translations catalog offers it." );
			}
			try {
				$file  = get_translations_for_domain( 'cradle-forms' );
				$wrong = array();
				foreach ( $source[ $locale ] as $row ) {
					$context  = (string) ( $row['context'] ?? '' );
					$plural   = isset( $row['msgidPlural'] );
					$expected = $row['forms'][ $plural ? ( 'ar' === $locale ? 2 : 1 ) : 0 ];
					$native   = $plural ? $file->translate_plural( $row['msgid'], $row['msgidPlural'], 2, $context ) : $file->translate( $row['msgid'], $context );
					if ( $expected !== $native ) {
						$wrong[] = $row['msgid'];
					}
				}
				assert_same( array(), array_slice( $wrong, 0, 10 ), "{$locale}: the .l10n.php file is loaded and gives every shipped translation." );
				assert_same( $source[ $locale ]['Submit']['forms'][0], __( 'Submit', 'cradle-forms' ) );
				$forms = $source[ $locale ]['%d entry']['forms'];
				assert_same( str_replace( '%d', '3', $forms[ 'ar' === $locale ? 3 : 1 ] ), sprintf( _n( '%d entry', '%d entries', 3, 'cradle-forms' ), 3 ), 'Plural form for 3.' );
				assert_same( $source[ $locale ][ "block title\4Text field" ]['forms'][0], translate_with_gettext_context( 'Text field', 'block title', 'cradle-forms' ), 'Block title context.' );
				assert_same( $source[ $locale ][ "block keyword\4form" ]['forms'][0], translate_with_gettext_context( 'form', 'block keyword', 'cradle-forms' ), 'Block keyword context.' );
				foreach ( array( Cradle\Forms\Module::FIELDS_SCRIPT, Cradle\Forms\Module::FORM_EDITOR_SCRIPT, 'cradle-form-editor-script' ) as $handle ) {
					$registered = wp_scripts()->registered[ $handle ] ?? null;
					assert_true( $registered && 'cradle-forms' === $registered->textdomain, "{$handle} has script translations." );
					assert_same( wp_normalize_path( WP_PLUGIN_DIR . '/cradle-forms/languages' ), wp_normalize_path( untrailingslashit( (string) $registered->translations_path ) ), "{$handle} points at languages/." );
					$jed = json_decode( (string) load_script_textdomain( $handle, 'cradle-forms', $registered->translations_path ), true );
					assert_same( $locale, $jed['locale_data']['messages']['']['lang'] ?? null, "{$handle}: Jed file of the locale loads." );
				}
				$jed = json_decode( (string) load_script_textdomain( 'cradle-form-editor-script', 'cradle-forms', wp_scripts()->registered['cradle-form-editor-script']->translations_path ), true );
				assert_same( array( $source[ $locale ]['Choose the form to show here.']['forms'][0] ), $jed['locale_data']['messages']['Choose the form to show here.'] ?? null );
				$name = $source[ $locale ][ "module name\4" . Cradle\Forms\Module::NAME ]['forms'][0];
				assert_same( $name, apply_filters( 'cradle/translate_label', Cradle\Forms\Module::NAME, 'module name', 'cradle' ), 'Module name label.' );
				if ( 'ar' === $locale ) {
					assert_false( Cradle\Forms\Module::NAME === $name, 'The Arabic module name is translated.' );
				}
				$cap = $source[ $locale ][ "capability label\4Export form entries" ]['forms'][0];
				assert_same( $cap, Cradle\Forms\Module::translate_label( 'Export form entries', 'capability label', 'cradle' ), 'Capability label from the plugin domain.' );
				assert_same( 'Manage admin branding', Cradle\Forms\Module::translate_label( 'Manage admin branding', 'capability label', 'cradle' ), 'Labels of other modules are not touched by this plugin.' );
				assert_same( 'Already translated', Cradle\Forms\Module::translate_label( 'Already translated', 'module name', 'cradle' ), 'A label translated earlier is kept.' );
				assert_same( Cradle\Forms\Module::NAME, Cradle\Forms\Module::translate_label( Cradle\Forms\Module::NAME, 'module name', 'cradle-forms' ), 'Only the cradle domain is handled.' );
			} finally {
				restore_previous_locale();
			}
		}
		assert_same( 'Submit', __( 'Submit', 'cradle-forms' ), 'Back to English after restore_previous_locale().' );
	}
);

cradle_test(
	'forms: without Cradle Translations the shipped files alone translate strings and labels, also inside switch_to_locale() (isolated process)',
	function () {
		cf_ready();
		$output = array();
		exec( escapeshellarg( cf_php() ) . ' ' . escapeshellarg( CRADLE_TEST_ROOT . '/tests/fixtures/forms/run-isolated.php' ) . ' no-translations 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$data = json_decode( (string) end( $output ), true );
		assert_true( 0 === $code && is_array( $data ), 'Runner failed: ' . substr( implode( "\n", $output ), 0, 500 ) );
		$source = cf_shipped_source();
		assert_false( $data['translationsLoaded'], 'Cradle Translations is not loaded in the isolated process.' );
		assert_true( $data['ready'] );
		assert_same( 'ar', $data['locale'] );
		assert_true( $data['ar']['checked'] > 250 );
		assert_same( array(), $data['ar']['wrong'], 'Arabic: every shipped string.' );
		assert_same( $source['ar'][ "module name\4" . Cradle\Forms\Module::NAME ]['forms'][0], $data['arName'] );
		assert_same( $source['ar'][ "capability label\4View form entries" ]['forms'][0], $data['arCapability'] );
		assert_same( 'Manage admin branding', $data['otherLabel'], 'Labels of other modules stay as they are.' );
		assert_true( $data['switched'], 'switch_to_locale( de_DE ) with the locale available.' );
		assert_same( array(), $data['de']['wrong'], 'German inside switch_to_locale(): every shipped string.' );
		assert_same( $source['de_DE'][ "capability label\4View form entries" ]['forms'][0], $data['deCapability'] );
		assert_same( 'ar', $data['restored'] );
		assert_same( array(), $data['errors'] );
	}
);

cradle_test(
	'forms: on an /ar/ page the form shows the Arabic submit label and required marker from the shipped file (HTTP); the editor prints the Arabic script translations',
	function () {
		cf_ready();
		$source = cf_shipped_source();
		cf_with_arabic(
			static function () use ( $source ) {
				$form = cf_form( 'CF Test shipped ar', cf_field( 'text', array( 'name' => 'name', 'label' => 'Name', 'required' => true ) ) );
				$page = cf_page( cf_block( $form ), 'CF Test shipped page' );
				assert_true( true === cradle_set_language( $page, 'ar' ), 'Page in Arabic.' );
				cf_lang_flush();
				$url = get_permalink( $page );
				assert_contains( '/ar/', $url );
				$submit   = esc_html( $source['ar']['Submit']['forms'][0] ) . '</button>';
				$required = '<span class="cradle-form__sr">' . esc_html( $source['ar']['(required)']['forms'][0] ) . '</span>';
				cf_without_overrides(
					static function () use ( $url, $form, $submit, $required ) {
						$res  = cradle_test_http( 'GET', $url );
						$html = (string) wp_remote_retrieve_body( $res );
						assert_same( 200, (int) wp_remote_retrieve_response_code( $res ) );
						assert_true( (bool) preg_match( '/<html[^>]*dir="rtl"/', $html ), 'Right-to-left page.' );
						assert_contains( 'data-cradle-form="' . $form . '"', $html );
						assert_contains( $submit, $html, 'Arabic default submit label.' );
						assert_contains( $required, $html, 'Arabic required marker.' );
					}
				);
				$html = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', $url ) );
				assert_contains( $submit, $html, 'Same with Cradle Translations overrides on.' );
				assert_contains( $required, $html );

				// Block editor in Arabic: WordPress prints the shipped Jed data of the form block script.
				$admin = cf_user( 'admin', 'administrator' );
				update_user_meta( $admin, 'locale', 'ar' );
				try {
					$expected = $source['ar']['Choose the form to show here.']['forms'][0];
					cf_without_overrides(
						static function () use ( $admin, $expected ) {
							$editor = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'post-new.php?post_type=page' ), array(), $admin ) );
							assert_contains( 'cradle-form-editor-script', $editor, 'The form block script is printed in the editor.' );
							assert_contains( $expected, $editor, 'Its Arabic translations are printed with it.' );
						}
					);
				} finally {
					delete_user_meta( $admin, 'locale' );
				}
			}
		);
	}
);

cradle_test(
	'forms: a form can be selected by its address name (formSlug, [cradle_form slug]); formId wins; unknown or invalid slugs render nothing without PHP notices',
	function () {
		cf_ready();
		$slug  = 'cf-test-slug-' . strtolower( wp_generate_password( 6, false, false ) );
		$id    = cf_form( 'CF Test slug', cf_contact_content() );
		$other = cf_form( 'CF Test slug other', cf_application_content() );
		cf_set_slug( $id, $slug );
		assert_same( $id, Post_Type::published_by_slug( $slug ) );
		assert_same( 0, Post_Type::published_by_slug( 'Bad Slug!' ), 'Only [a-z0-9-] slugs are looked up.' );
		assert_same( $other, Cradle\Forms\Renderer::selected_form( array( 'formId' => $other, 'formSlug' => $slug ) ), 'formId wins.' );
		assert_same( $id, Cradle\Forms\Renderer::selected_form( array( 'formId' => 0, 'formSlug' => $slug ) ) );

		$page = cf_page(
			'<!-- wp:cradle/form {"formSlug":"' . $slug . '"} /-->'
			. '<!-- wp:shortcode -->[cradle_form slug="' . $slug . '"]<!-- /wp:shortcode -->'
			. cf_block( $other, array( 'formSlug' => $slug ) )
		);
		$res  = cradle_test_http( 'GET', get_permalink( $page ) );
		$html = (string) wp_remote_retrieve_body( $res );
		assert_same( 200, (int) wp_remote_retrieve_response_code( $res ) );
		assert_same( 2, substr_count( $html, 'data-cradle-form="' . $id . '"' ), 'Block and shortcode render the form selected by its slug.' );
		assert_contains( 'id="cradle-form-' . $id . '-2"', $html, 'Second instance has its own IDs.' );
		assert_same( 1, substr_count( $html, 'data-cradle-form="' . $other . '"' ), 'formId wins over formSlug.' );
		assert_contains( '<label class="cradle-form__label" for="cradle-form-' . $id . '-name">Your name', $html );

		// The block editor preview (block renderer) and the form picker data know the slug.
		$admin    = cf_user( 'admin', 'administrator' );
		$session  = cradle_test_cookies( $admin );
		$nonce    = cradle_test_nonce( $admin, $session, 'wp_rest' );
		$rendered = cradle_test_http(
			'GET',
			add_query_arg(
				array(
					'context'    => 'edit',
					'attributes' => array( 'formSlug' => $slug ),
					'_wpnonce'   => $nonce,
				),
				rest_url( 'wp/v2/block-renderer/cradle/form' )
			),
			array(),
			$admin,
			$session
		);
		assert_same( 200, (int) wp_remote_retrieve_response_code( $rendered ), substr( (string) wp_remote_retrieve_body( $rendered ), 0, 300 ) );
		$preview = json_decode( (string) wp_remote_retrieve_body( $rendered ), true );
		assert_contains( 'data-cradle-form="' . $id . '"', (string) ( $preview['rendered'] ?? '' ), 'Editor preview of a slug-selected form.' );
		$editor = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'post-new.php?post_type=page' ), array(), $admin ) );
		assert_contains( '"slug":"' . $slug . '"', $editor, 'The form picker data carries the address name.' );

		// Unknown and invalid slugs: nothing for visitors, a notice for form managers, no PHP notices.
		$unknown = 'cf-test-no-such-form-' . strtolower( wp_generate_password( 6, false, false ) );
		$log     = (string) ini_get( 'error_log' );
		$before  = is_file( $log ) ? count( (array) file( $log ) ) : 0;
		$page2   = cf_page(
			'<!-- wp:cradle/form {"formSlug":"' . $unknown . '"} /-->'
			. '<!-- wp:cradle/form {"formSlug":"Bad Slug!"} /-->'
			. '<!-- wp:shortcode -->[cradle_form slug="' . $unknown . '"]<!-- /wp:shortcode -->'
			. '<!-- wp:paragraph --><p>CF slug end marker</p><!-- /wp:paragraph -->'
		);
		$res   = cradle_test_http( 'GET', get_permalink( $page2 ) );
		$html2 = (string) wp_remote_retrieve_body( $res );
		assert_same( 200, (int) wp_remote_retrieve_response_code( $res ) );
		assert_contains( 'CF slug end marker', $html2 );
		assert_not_contains( 'class="cradle-form', $html2, 'Unknown slugs render nothing for visitors.' );
		assert_not_contains( 'address name', $html2, 'No manager notice for visitors.' );
		clearstatcache();
		$after = is_file( $log ) ? array_slice( (array) file( $log ), $before ) : array();
		assert_same( array(), array_values( array_filter( $after, static fn( $line ) => false !== stripos( (string) $line, 'cradle-forms' ) ) ), 'No PHP notice from Cradle Forms in the log.' );

		$errors = array();
		set_error_handler(
			static function ( $errno, $message, $file, $line ) use ( &$errors ) {
				$errors[] = $message . ' @ ' . basename( (string) $file ) . ':' . $line;
				return true;
			}
		);
		try {
			wp_set_current_user( 0 );
			$visitor = array(
				cradle_render_component( 'cradle/form', array( 'formSlug' => $unknown ), array( 'adapter' => 'php' ) ),
				cradle_render_component( 'cradle/form', array( 'formSlug' => 'Bad Slug!' ), array( 'adapter' => 'php' ) ),
				cradle_render_component( 'cradle/form', array( 'formSlug' => str_repeat( 'a', 300 ) ), array( 'adapter' => 'php' ) ),
				do_shortcode( '[cradle_form slug="' . $unknown . '"]' ),
				do_shortcode( '[cradle_form slug=""]' ),
			);
			cf_admin();
			$manager = cradle_render_component( 'cradle/form', array( 'formSlug' => $unknown ), array( 'adapter' => 'php' ) );
		} finally {
			restore_error_handler();
		}
		assert_same( array( '', '', '', '', '' ), $visitor, 'Nothing for visitors.' );
		assert_contains( esc_html( $unknown ), $manager, 'Form managers see which address name is unknown.' );
		assert_same( array(), $errors, 'No PHP notices while rendering.' );
	}
);

cradle_test(
	'forms: with Cradle Languages a slug selects the default-language form and renders its Arabic translation on /ar/',
	function () {
		cf_ready();
		cf_with_arabic(
			static function () {
				$slug    = 'cf-test-slug-lang-' . strtolower( wp_generate_password( 6, false, false ) );
				$content = cf_field( 'text', array( 'name' => 'name', 'label' => 'Name', 'required' => true ) );
				$en_form = cf_form( 'CF Test slug lang en', $content );
				cf_set_slug( $en_form, $slug );
				$copy = Cradle\Languages\Translator::create( $en_form, 'ar' );
				assert_false( is_wp_error( $copy ), is_wp_error( $copy ) ? $copy->get_error_message() : '' );
				$ar_form                           = (int) $copy['id'];
				$GLOBALS['cf_fixtures']['posts'][] = $ar_form;
				wp_update_post(
					array(
						'ID'           => $ar_form,
						'post_status'  => 'publish',
						'post_title'   => 'CF Test slug lang ar',
						'post_content' => wp_slash( str_replace( '"label":"Name"', '"label":"الاسم"', (string) get_post_field( 'post_content', $ar_form ) ) ),
					)
				);
				cf_lang_flush();
				assert_same( $ar_form, (int) cradle_get_translation( $en_form, 'ar' ), 'The Arabic form is the translation.' );
				$ar_slug = (string) get_post_field( 'post_name', $ar_form );
				assert_false( $slug === $ar_slug, 'The translation has its own address name.' );

				$block   = '<!-- wp:cradle/form {"formSlug":"' . $slug . '"} /-->';
				$en_page = cf_page( $block . '<!-- wp:cradle/form {"formSlug":"' . $ar_slug . '"} /-->', 'CF Test slug lang page' );
				$ar_page = cf_page( $block, 'CF Test slug lang page ar' );
				assert_true( ! is_wp_error( cradle_link_translations( array( 'en' => $en_page, 'ar' => $ar_page ) ) ), 'Pages linked.' );
				cf_lang_flush();

				$en_html = (string) wp_remote_retrieve_body( cradle_test_http( 'GET', get_permalink( $en_page ) ) );
				assert_same( 2, substr_count( $en_html, 'data-cradle-form="' . $en_form . '"' ), 'English page: the slug and the translation\'s slug both render the English form.' );
				assert_not_contains( 'data-cradle-form="' . $ar_form . '"', $en_html );

				$url = get_permalink( $ar_page );
				assert_contains( '/ar/', $url );
				$res  = cradle_test_http( 'GET', $url );
				$html = (string) wp_remote_retrieve_body( $res );
				assert_same( 200, (int) wp_remote_retrieve_response_code( $res ) );
				assert_contains( 'data-cradle-form="' . $ar_form . '"', $html, 'Arabic page: the Arabic translation of the slug-selected form.' );
				assert_not_contains( 'data-cradle-form="' . $en_form . '"', $html );
				assert_contains( 'الاسم', $html );
				$token = Security::read_token( cf_hidden( $html, '_cf_ts' ) );
				assert_same( array( $ar_form, 'ar', $ar_page ), array( $token['form'] ?? null, $token['language'] ?? null, $token['source'] ?? null ), 'Token: translated form, language and page.' );
			}
		);
	}
);

cradle_test(
	'forms: cleanup — every fixture of this suite is removed before later suites run (runs last)',
	function () {
		global $wpdb;
		$posts = array_unique( $GLOBALS['cf_fixtures']['posts'] );
		cf_cleanup();
		foreach ( $posts as $id ) {
			assert_same( null, get_post( (int) $id ), "Fixture {$id} deleted." );
		}
		assert_same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'CF Test%' OR post_title LIKE '%CF Source%'" ), 'No fixture posts left.' ); // phpcs:ignore WordPress.DB
		assert_false( get_user_by( 'login', 'cradle_forms_t_admin' ) );
		assert_false( is_file( WP_CONTENT_DIR . '/mu-plugins/' . CF_MAIL_PLUGIN ) );
		assert_same( null, get_role( 'cradle_forms_t_viewer' ) );
	}
);
