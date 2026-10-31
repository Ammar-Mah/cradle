<?php
/**
 * Test helper for tests/integration/forms.php: loads the sandbox WordPress in a separate
 * process with one plugin hidden from the active list FOR THIS PROCESS ONLY (a pre-set
 * `option_active_plugins` filter; nothing is written), then reports how Cradle Forms behaves.
 *
 * Usage: php run-isolated.php no-core
 *        php run-isolated.php no-languages <form-id>
 *        php run-isolated.php no-translations
 * Prints one JSON object on the last line.
 *
 * no-translations: Cradle Translations is hidden and the request locale is Arabic (a pre-set `locale`
 * filter, as for a site in Arabic); `ar` and `de_DE` are offered as available languages (a pre-set
 * `get_available_languages` filter, as installed WordPress language packs would) so switch_to_locale()
 * works. Reports whether the shipped languages/ files alone translate the plugin.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$cradle_forms_mode   = $argv[1] ?? '';
$cradle_forms_hidden = array(
	'no-core'         => 'cradle/cradle.php',
	'no-languages'    => 'cradle-languages/cradle-languages.php',
	'no-translations' => 'cradle-translations/cradle-translations.php',
)[ $cradle_forms_mode ] ?? null;
if ( null === $cradle_forms_hidden ) {
	fwrite( STDERR, "usage: run-isolated.php no-core|no-languages|no-translations [form-id]\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['SERVER_NAME']     = 'localhost';
$_SERVER['SERVER_PORT']     = 80;
$_SERVER['REQUEST_URI']     = 'no-core' === $cradle_forms_mode ? '/cradle/wp/wp-admin/' : '/cradle/wp/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
if ( 'no-core' === $cradle_forms_mode || 'no-translations' === $cradle_forms_mode ) {
	define( 'WP_ADMIN', true );
}

$GLOBALS['wp_filter'] = array(
	'option_active_plugins' => array(
		1 => array(
			'cradle_forms_hide' => array(
				'function'      => static function ( $plugins ) use ( $cradle_forms_hidden ) {
					return array_values( array_diff( (array) $plugins, array( $cradle_forms_hidden ) ) );
				},
				'accepted_args' => 1,
			),
		),
	),
);
if ( 'no-translations' === $cradle_forms_mode ) {
	$GLOBALS['wp_filter']['locale']                  = array(
		1 => array(
			'cradle_forms_ar' => array(
				'function'      => static fn() => 'ar',
				'accepted_args' => 1,
			),
		),
	);
	$GLOBALS['wp_filter']['get_available_languages'] = array(
		10 => array(
			'cradle_forms_packs' => array(
				'function'      => static fn( $languages ) => array_values( array_unique( array_merge( (array) $languages, array( 'ar', 'de_DE' ) ) ) ),
				'accepted_args' => 1,
			),
		),
	);
}

$cradle_forms_errors = array();
set_error_handler(
	static function ( $errno, $message, $file ) use ( &$cradle_forms_errors ) {
		if ( false !== stripos( strtr( (string) $file, chr( 92 ), '/' ), 'cradle-forms' ) ) {
			$cradle_forms_errors[] = $message . ' @ ' . basename( $file );
		}
		return false;
	}
);

require dirname( __DIR__, 3 ) . '/wp/wp-load.php';

if ( 'no-translations' === $cradle_forms_mode ) {
	/**
	 * Source entries whose translation differs in this locale: through the loaded translation
	 * file (no gettext filters) and through the public API.
	 */
	$cradle_forms_check = static function ( string $locale ): array {
		$source = json_decode( (string) file_get_contents( WP_PLUGIN_DIR . '/cradle-forms/languages/cradle-forms.json' ), true );
		$file   = get_translations_for_domain( 'cradle-forms' );
		$wrong  = array();
		foreach ( $source[ $locale ]['entries'] as $row ) {
			$context = (string) ( $row['context'] ?? '' );
			if ( isset( $row['msgidPlural'] ) ) {
				$expected = $row['forms'][ 'ar' === $locale ? 2 : 1 ]; // n = 2.
				$native   = $file->translate_plural( $row['msgid'], $row['msgidPlural'], 2, $context );
				$public   = '' !== $context ? _nx( $row['msgid'], $row['msgidPlural'], 2, $context, 'cradle-forms' ) : _n( $row['msgid'], $row['msgidPlural'], 2, 'cradle-forms' );
			} else {
				$expected = $row['forms'][0];
				$native   = $file->translate( $row['msgid'], $context );
				$public   = '' !== $context ? translate_with_gettext_context( $row['msgid'], $context, 'cradle-forms' ) : translate( $row['msgid'], 'cradle-forms' );
			}
			if ( $expected !== $native || $expected !== $public ) {
				$wrong[] = $row['msgid'];
			}
		}
		return array(
			'checked' => count( $source[ $locale ]['entries'] ),
			'wrong'   => $wrong,
		);
	};
	$cradle_forms_out = array(
		'translationsLoaded' => class_exists( '\\Cradle\\Translations\\Runtime', false ),
		'ready'              => function_exists( 'cradle' ) && cradle() && cradle()->modules()->is_ready( 'forms' ),
		'locale'             => determine_locale(),
		'ar'                 => $cradle_forms_check( 'ar' ),
		'arName'             => apply_filters( 'cradle/translate_label', \Cradle\Forms\Module::NAME, 'module name', 'cradle' ),
		'arCapability'       => apply_filters( 'cradle/translate_label', 'View form entries', 'capability label', 'cradle' ),
		'otherLabel'         => apply_filters( 'cradle/translate_label', 'Manage admin branding', 'capability label', 'cradle' ),
		'switched'           => switch_to_locale( 'de_DE' ),
	);
	$cradle_forms_out['de']           = $cradle_forms_check( 'de_DE' );
	$cradle_forms_out['deCapability'] = apply_filters( 'cradle/translate_label', 'View form entries', 'capability label', 'cradle' );
	restore_previous_locale();
	$cradle_forms_out['restored'] = determine_locale();
	$cradle_forms_out['errors']   = $cradle_forms_errors;
	echo wp_json_encode( $cradle_forms_out, JSON_UNESCAPED_UNICODE ) . "\n";
	exit( 0 );
}

if ( 'no-core' === $cradle_forms_mode ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
	require_once ABSPATH . 'wp-admin/includes/screen.php';
	set_current_screen( 'plugins' );
	$cradle_forms_admin = get_user_by( 'login', 'cradle_forms_t_admin' );
	if ( $cradle_forms_admin ) {
		wp_set_current_user( $cradle_forms_admin->ID );
	}
	ob_start();
	do_action( 'admin_notices' );
	$cradle_forms_notices = (string) ob_get_clean();
	$cradle_forms_post    = get_post( (int) ( $argv[2] ?? 0 ) );
	echo wp_json_encode(
		array(
			'formsLoaded'     => defined( 'CRADLE_FORMS_VERSION' ),
			'coreLoaded'      => function_exists( 'cradle' ) && null !== cradle(),
			'notice'          => false !== strpos( $cradle_forms_notices, 'Cradle Forms needs the Cradle core plugin' ),
			'postType'        => post_type_exists( 'cradle_form' ),
			'blockRegistered' => WP_Block_Type_Registry::get_instance()->is_registered( 'cradle/form' ),
			'submitHandler'   => has_action( 'admin_post_nopriv_cradle_forms_submit' ),
			'renderedBlock'   => trim( do_blocks( '<!-- wp:cradle/form {"formId":1} /-->' ) ),
			'errors'          => $cradle_forms_errors,
		)
	) . "\n";
	exit( 0 );
}

// no-languages: Cradle Languages is hidden; Cradle Forms must fall back to the WordPress locale.
$cradle_forms_id = (int) ( $argv[2] ?? 0 );
$cradle_forms_html = cradle_render_component( 'cradle/form', array( 'formId' => $cradle_forms_id ) );
preg_match( '/name="_cf_ts" value="([^"]+)"/', $cradle_forms_html, $cradle_forms_m );
echo wp_json_encode(
	array(
		'languagesLoaded' => function_exists( 'cradle_current_language' ),
		'active'          => Cradle\Forms\Language::languages_active(),
		'current'         => Cradle\Forms\Language::current(),
		'locale'          => determine_locale(),
		'translated'      => Cradle\Forms\Language::translated_form( $cradle_forms_id ),
		'token'           => $cradle_forms_m[1] ?? '',
		'rendered'        => false !== strpos( $cradle_forms_html, 'data-cradle-form="' . $cradle_forms_id . '"' ),
		'errors'          => $cradle_forms_errors,
	)
) . "\n";
exit( 0 );
