<?php
/**
 * Test helper for tests/integration/languages.php: loads the sandbox WordPress in a separate
 * process with one plugin hidden from the active list FOR THIS PROCESS ONLY (a pre-initialized
 * `option_active_plugins` filter; nothing is written to the database) and reports how Cradle
 * Languages behaves.
 *
 * Usage: php run-guard.php no-core|no-blocks|no-translations
 * Prints one JSON object.
 *
 * no-translations: Cradle Translations is hidden and the request locale is Arabic (a pre-set `locale`
 * filter, as for a site in Arabic); `ar` and `de_DE` are offered as available languages (a pre-set
 * `get_available_languages` filter, as installed WordPress language packs would) so switch_to_locale()
 * works. Reports whether the shipped languages/ files alone translate the plugin.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$cradle_languages_mode   = $argv[1] ?? '';
$cradle_languages_hidden = array(
	'no-core'         => 'cradle/cradle.php',
	'no-blocks'       => 'cradle-blocks/cradle-blocks.php',
	'no-translations' => 'cradle-translations/cradle-translations.php',
)[ $cradle_languages_mode ] ?? null;
if ( null === $cradle_languages_hidden ) {
	fwrite( STDERR, "usage: run-guard.php no-core|no-blocks|no-translations\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['SERVER_NAME']     = 'localhost';
$_SERVER['SERVER_PORT']     = 80;
$_SERVER['REQUEST_URI']     = '/cradle/wp/wp-admin/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
define( 'WP_ADMIN', true );

// WordPress converts a pre-set $wp_filter array into hooks when plugin.php loads.
$GLOBALS['wp_filter'] = array(
	'option_active_plugins' => array(
		1 => array(
			'cradle_languages_hide' => array(
				'function'      => static function ( $plugins ) use ( $cradle_languages_hidden ) {
					return array_values( array_diff( (array) $plugins, array( $cradle_languages_hidden ) ) );
				},
				'accepted_args' => 1,
			),
		),
	),
);
if ( 'no-translations' === $cradle_languages_mode ) {
	$GLOBALS['wp_filter']['locale']                  = array(
		1 => array(
			'cradle_languages_ar' => array(
				'function'      => static fn() => 'ar',
				'accepted_args' => 1,
			),
		),
	);
	$GLOBALS['wp_filter']['get_available_languages'] = array(
		10 => array(
			'cradle_languages_packs' => array(
				'function'      => static fn( $languages ) => array_values( array_unique( array_merge( (array) $languages, array( 'ar', 'de_DE' ) ) ) ),
				'accepted_args' => 1,
			),
		),
	);
}

$cradle_languages_errors = array();
set_error_handler(
	static function ( $errno, $message, $file ) use ( &$cradle_languages_errors ) {
		if ( false !== stripos( str_replace( '\\', '/', $file ), 'cradle-languages' ) ) {
			$cradle_languages_errors[] = $message . ' @ ' . basename( $file );
		}
		return false;
	}
);

require dirname( __DIR__, 3 ) . '/wp/wp-load.php';

if ( 'no-translations' === $cradle_languages_mode ) {
	/**
	 * Source entries whose translation differs in this locale: through the loaded translation
	 * file (no gettext filters) and through the public API.
	 */
	$cradle_languages_check = static function ( string $locale ): array {
		$source = json_decode( (string) file_get_contents( WP_PLUGIN_DIR . '/cradle-languages/languages/cradle-languages.json' ), true );
		$file   = get_translations_for_domain( 'cradle-languages' );
		$wrong  = array();
		foreach ( $source[ $locale ]['entries'] as $row ) {
			$context = (string) ( $row['context'] ?? '' );
			if ( isset( $row['msgidPlural'] ) ) {
				$expected = $row['forms'][ 'ar' === $locale ? 2 : 1 ]; // n = 2.
				$native   = $file->translate_plural( $row['msgid'], $row['msgidPlural'], 2, $context );
				$public   = '' !== $context ? _nx( $row['msgid'], $row['msgidPlural'], 2, $context, 'cradle-languages' ) : _n( $row['msgid'], $row['msgidPlural'], 2, 'cradle-languages' );
			} else {
				$expected = $row['forms'][0];
				$native   = $file->translate( $row['msgid'], $context );
				$public   = '' !== $context ? translate_with_gettext_context( $row['msgid'], $context, 'cradle-languages' ) : translate( $row['msgid'], 'cradle-languages' );
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
	$cradle_languages_out = array(
		'translationsLoaded' => class_exists( '\\Cradle\\Translations\\Runtime', false ),
		'ready'              => function_exists( 'cradle_languages_ready' ) && cradle_languages_ready(),
		'locale'             => determine_locale(),
		'ar'                 => $cradle_languages_check( 'ar' ),
		'arName'             => apply_filters( 'cradle/translate_label', \Cradle\Languages\Module::NAME, 'module name', 'cradle' ),
		'arCapability'       => apply_filters( 'cradle/translate_label', \Cradle\Languages\Module::CAP_LABEL, 'capability label', 'cradle' ),
		'otherLabel'         => apply_filters( 'cradle/translate_label', 'Manage admin branding', 'capability label', 'cradle' ),
		'switched'           => switch_to_locale( 'de_DE' ),
	);
	$cradle_languages_out['de']           = $cradle_languages_check( 'de_DE' );
	$cradle_languages_out['deCapability'] = apply_filters( 'cradle/translate_label', \Cradle\Languages\Module::CAP_LABEL, 'capability label', 'cradle' );
	restore_previous_locale();
	$cradle_languages_out['restored'] = determine_locale();
	$cradle_languages_out['errors']   = $cradle_languages_errors;
	echo wp_json_encode( $cradle_languages_out, JSON_UNESCAPED_UNICODE ) . "\n";
	exit( 0 );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
set_current_screen( 'plugins' );

$cradle_languages_admin = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
if ( $cradle_languages_admin ) {
	wp_set_current_user( $cradle_languages_admin[0]->ID );
}

ob_start();
do_action( 'admin_notices' );
$cradle_languages_notices = (string) ob_get_clean();

$cradle_languages_core = function_exists( 'cradle' ) ? cradle() : null;
$cradle_languages_row  = null;
if ( $cradle_languages_core ) {
	foreach ( $cradle_languages_core->modules()->report() as $cradle_languages_r ) {
		if ( 'languages' === $cradle_languages_r['id'] ) {
			$cradle_languages_row = $cradle_languages_r;
		}
	}
}

echo wp_json_encode(
	array(
		'mode'            => $cradle_languages_mode,
		'loaded'          => defined( 'CRADLE_LANGUAGES_VERSION' ),
		'coreLoaded'      => (bool) $cradle_languages_core,
		'notice'          => false !== strpos( $cradle_languages_notices, 'Cradle Languages needs the Cradle core plugin' ),
		'state'           => $cradle_languages_row ? $cradle_languages_row['state'] : '',
		'problems'        => $cradle_languages_row ? $cradle_languages_row['problems'] : array(),
		'ready'           => function_exists( 'cradle_languages_ready' ) && cradle_languages_ready(),
		'taxonomy'        => taxonomy_exists( 'cradle_language' ),
		'blockRegistered' => WP_Block_Type_Registry::get_instance()->is_registered( 'cradle/language-switcher' ),
		'apiEmpty'        => array() === cradle_languages() && null === cradle_current_language(),
		'errors'          => $cradle_languages_errors,
	)
);
