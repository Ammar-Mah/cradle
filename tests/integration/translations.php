<?php
/**
 * Cradle Translations (northstar §14, CR-17) integration tests.
 *
 * Locales: de_CH (2 plural forms), fr_FR (n > 1) and ar_EG (6 plural forms) — never the built-in example
 * languages de_DE and ar, which a separate test checks and leaves untouched. Test catalogs,
 * users, the fixture package and the translator role are removed at the end of the suite.
 *
 * switch_to_locale() only accepts locales known when WP_Locale_Switcher is constructed. Locales
 * with published catalogs are offered through `get_available_languages`, so the helper below
 * rebuilds the switcher after publishing (what the next request would see).
 */

use Cradle\Translations\Catalog;
use Cradle\Translations\Examples;
use Cradle\Translations\Config;
use Cradle\Translations\Extractor;
use Cradle\Translations\Lifecycle;
use Cradle\Translations\Module;
use Cradle\Translations\Plural_Rules;
use Cradle\Translations\Runtime;
use Cradle\Translations\Service;
use Cradle\Translations\Store;
use Cradle\Translations\Validator;

const CT_LOCALES      = array( 'de_CH', 'fr_FR', 'ar_EG' );
const CT_EXTRA        = array( 'it_IT', 'ru_RU' );
const CT_FIXTURE_SLUG = 'cradle-zztrfixture';
const CT_ROLE         = 'cradle_tr_translator';

function ct_fixtures(): string {
	return CRADLE_TEST_ROOT . '/tests/fixtures/translations';
}

function ct_admin(): int {
	$id = cradle_test_user( 'administrator' );
	wp_set_current_user( $id );
	return $id;
}

/**
 * Suite-owned user (deleted at the end), so locale changes never touch shared test users.
 */
function ct_user( string $login, string $role ): int {
	$user = get_user_by( 'login', $login );
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
	return (int) $id;
}

function ct_ok( $result, string $what = '' ) {
	if ( is_wp_error( $result ) ) {
		throw new Cradle_Test_Failure( trim( $what . ' failed: ' . $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) ) );
	}
	return $result;
}

function ct_entry( string $locale, string $domain, string $msgid, ?string $context = null, ?string $plural = null ): ?array {
	$catalog = ct_ok( Store::read( $locale ), 'read ' . $locale );
	$found   = Catalog::find( $catalog, Catalog::entry_id( $domain, $msgid, $context, $plural ) );
	return $found ? $found[2] : null;
}

function ct_revision( string $locale ): int {
	return (int) ct_ok( Store::read( $locale ) )['revision'];
}

/**
 * Sets (and by default publishes) a translation, adding a manual entry when needed.
 */
function ct_set( string $locale, string $domain, string $msgid, ?string $context, ?string $plural, array $forms, bool $publish = true, bool $js = false ) {
	ct_admin();
	if ( ! ct_entry( $locale, $domain, $msgid, $context, $plural ) ) {
		ct_ok( Service::add_entry( $locale, $domain, $msgid, (string) $context, (string) $plural, $js, ct_revision( $locale ) ), 'add entry' );
	}
	$id = Catalog::entry_id( $domain, $msgid, $context, $plural );
	return ct_ok( Service::save_entry( $locale, $id, $forms, false, ct_revision( $locale ), $publish ), 'save ' . $msgid );
}

function ct_refresh_switcher() {
	if ( isset( $GLOBALS['wp_locale_switcher'] ) ) {
		$GLOBALS['wp_locale_switcher']->restore_current_locale();
	}
	$GLOBALS['wp_locale_switcher'] = new WP_Locale_Switcher();
	$GLOBALS['wp_locale_switcher']->init();
}

function ct_switch( string $locale ) {
	restore_current_locale();
	if ( 'en_US' === $locale ) {
		return;
	}
	if ( ! switch_to_locale( $locale ) ) {
		ct_refresh_switcher();
		assert_true( switch_to_locale( $locale ), 'switch_to_locale(' . $locale . ') must succeed for a published catalog locale.' );
	}
	assert_same( $locale, determine_locale() );
}

function ct_rmdir( string $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $items as $item ) {
		$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $dir );
}

function ct_copy_tree( string $from, string $to ) {
	if ( ! is_dir( $from ) ) {
		return;
	}
	wp_mkdir_p( $to );
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
	foreach ( $items as $item ) {
		$target = $to . '/' . substr( $item->getPathname(), strlen( $from ) + 1 );
		$item->isDir() ? wp_mkdir_p( $target ) : copy( $item->getPathname(), $target );
	}
}

function ct_install_fixture( string $version ) {
	$target = WP_PLUGIN_DIR . '/' . CT_FIXTURE_SLUG;
	ct_rmdir( $target );
	$source = ct_fixtures() . '/' . $version;
	$items  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
	mkdir( $target );
	foreach ( $items as $item ) {
		$dest = $target . '/' . substr( wp_normalize_path( $item->getPathname() ), strlen( wp_normalize_path( $source ) ) + 1 );
		$item->isDir() ? mkdir( $dest ) : copy( $item->getPathname(), $dest );
	}
}

function ct_remove_fixture() {
	ct_rmdir( WP_PLUGIN_DIR . '/' . CT_FIXTURE_SLUG );
}

/* ---------------------------------------------------------------------- */

cradle_test(
	'translations: setup — module ready, sources extracted with context/plural/JS awareness, locales created',
	function () {
		assert_true( is_plugin_active( 'cradle-translations/cradle-translations.php' ), 'Plugin must be active.' );
		assert_same( 'ready', cradle()->modules()->state( 'translations' ) );
		ct_admin();
		restore_current_locale();
		foreach ( array_merge( CT_LOCALES, CT_EXTRA ) as $locale ) {
			if ( Store::exists( $locale ) ) {
				ct_ok( Service::delete_locale( $locale ), 'remove leftover ' . $locale );
			}
		}
		ct_remove_fixture();
		$refresh  = ct_ok( Service::refresh_sources(), 'refresh' );
		$manifest = Store::manifest();
		assert_true( is_array( $manifest ) && count( $manifest['entries'] ) > 100, 'Manifest lists Cradle strings.' );
		assert_same( count( $refresh['manifest']['entries'] ), count( $manifest['entries'] ) );
		$by = array();
		foreach ( $manifest['entries'] as $entry ) {
			$by[ Catalog::identity( $entry['domain'], $entry['msgid'], $entry['context'], $entry['msgidPlural'] ) ] = $entry;
		}
		$select = $by[ Catalog::identity( 'cradle', 'Select media', null, null ) ] ?? null;
		assert_true( is_array( $select ) && $select['js'], 'Core JS string "Select media" is extracted and flagged as JavaScript.' );
		assert_true( (bool) array_filter( $select['references'], static fn( $r ) => str_starts_with( $r, 'plugins/cradle/assets/js/admin.js:' ) ), 'JS source location recorded.' );
		assert_true( isset( $by[ Catalog::identity( 'cradle', 'Search', 'search form label', null ) ], $by[ Catalog::identity( 'cradle', 'Search', 'search button text', null ) ] ), 'Theme strings with identical text and different contexts are two entries.' );
		$plural = $by[ Catalog::identity( 'cradle', '%d Cradle module is not running.', null, '%d Cradle modules are not running.' ) ] ?? null;
		assert_true( is_array( $plural ), 'Core plural string extracted with its plural original.' );
		assert_contains( 'translators:', $plural['comments'], 'Translator comment captured.' );
		assert_same( 'registered', ( $by[ Catalog::identity( 'cradle', 'Cradle Translations', 'module name', null ) ] ?? array() )['source'] ?? null, 'Module descriptor name registered as a label.' );
		foreach ( CT_LOCALES as $locale ) {
			ct_ok( Service::add_locale( $locale ), 'add ' . $locale );
			assert_true( Store::exists( $locale ) );
		}
		assert_wp_error( Service::add_locale( 'de_CH' ), 'cradle_translations_exists' );
		assert_wp_error( Service::add_locale( '../evil' ), 'cradle_translations_locale' );
		assert_wp_error( Service::add_locale( 'de_CH.php' ), 'cradle_translations_locale' );
		$entry = ct_entry( 'ar_EG', 'cradle', 'Select media' );
		assert_same( 'missing', $entry['status'] );
		assert_same( 'extracted', $entry['source'] );
		assert_same( 'arabic', ct_ok( Store::read( 'ar_EG' ) )['pluralRule'] );
	}
);

cradle_test(
	'translations: capability is administrator-only and grants no migration, import-site or code rights',
	function () {
		assert_true( get_role( 'administrator' )->has_cap( Config::CAP ) );
		foreach ( array( 'editor', 'author', 'contributor', 'subscriber', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				assert_false( $role->has_cap( Config::CAP ), $role_name . ' must not manage translations.' );
			}
		}
		remove_role( CT_ROLE );
		add_role(
			CT_ROLE,
			'Translator (Cradle test)',
			array(
				'read'      => true,
				Config::CAP => true,
			)
		);
		$translator = ct_user( 'cradle_tr_translator_user', CT_ROLE );
		assert_true( user_can( $translator, Config::CAP ) );
		foreach ( array( 'cradle_import_site', 'cradle_export_site', 'upload_plugins', 'install_plugins', 'activate_plugins', 'edit_plugins', 'manage_options', 'unfiltered_html', 'cradle_manage_settings' ) as $cap ) {
			assert_false( user_can( $translator, $cap ), 'Translator must not have ' . $cap );
		}
		$settings = cradle()->settings()->section( 'translations' );
		assert_same( Config::CAP, $settings['capability'] ?? null, 'Settings section uses the translations capability.' );
		wp_set_current_user( cradle_test_user( 'editor' ) );
		assert_wp_error( cradle()->settings()->update( 'translations', array( 'history_limit' => 5 ) ), 'cradle_forbidden' );
	}
);

cradle_test(
	'translations: structured plural rules per locale match their gettext expressions (never evaluated with eval)',
	function () {
		$expect = array(
			'de_CH' => array( 2, array( 0 => 1, 1 => 0, 2 => 1, 5 => 1 ) ),
			'fr_FR' => array( 2, array( 0 => 0, 1 => 0, 2 => 1, 5 => 1 ) ),
			'ar_EG'    => array( 6, array( 0 => 0, 1 => 1, 2 => 2, 3 => 3, 10 => 3, 11 => 4, 99 => 4, 100 => 5, 102 => 5, 103 => 3, 111 => 4 ) ),
			'ru_RU' => array( 3, array( 1 => 0, 2 => 1, 4 => 1, 5 => 2, 11 => 2, 12 => 2, 21 => 0, 22 => 1, 25 => 2, 112 => 2 ) ),
			'pl_PL' => array( 3, array( 1 => 0, 2 => 1, 5 => 2, 12 => 2, 21 => 2, 22 => 1 ) ),
			'ja'    => array( 1, array( 0 => 0, 1 => 0, 7 => 0 ) ),
		);
		foreach ( $expect as $locale => $row ) {
			assert_same( $row[0], Plural_Rules::nplurals( $locale ), $locale . ' nplurals' );
			foreach ( $row[1] as $n => $index ) {
				assert_same( $index, Plural_Rules::index( $locale, $n ), "{$locale} n={$n}" );
			}
		}
		assert_same( 'nplurals=6; plural=(' . Plural_Rules::RULES['arabic'][1] . ');', Plural_Rules::header( 'ar_EG' ) );
		require_once ABSPATH . WPINC . '/pomo/plural-forms.php';
		foreach ( Plural_Rules::RULES as $rule => $definition ) {
			$parser = new Plural_Forms( $definition[1] ); // WordPress' own non-eval parser, used here only as an oracle.
			for ( $n = 0; $n <= 1000; $n++ ) {
				assert_same( (int) $parser->execute( $n ), Plural_Rules::index_for_rule( $rule, $n ), "rule {$rule} n={$n}" );
			}
		}
		assert_same( 6, Plural_Rules::header_nplurals( 'nplurals=6; plural=system("x");' ), 'Only the integer is read from an untrusted header.' );
		assert_false( Plural_Rules::is_known( 'xx_YY' ) );
		assert_same( 2, Plural_Rules::nplurals( 'xx_YY' ), 'Unknown locales fall back to the two-form rule.' );
	}
);

cradle_test(
	'translations: placeholder and approved-HTML validation',
	function () {
		ct_admin();
		$e    = static fn( $m, $p = null ) => array(
			'msgid'       => $m,
			'msgidPlural' => $p,
			'context'     => null,
		);
		$good = array(
			array( $e( 'Hello %s' ), array( 'Hallo %s' ) ),
			array( $e( '%s of %d' ), array( '%2$d von %1$s' ) ),
			array( $e( '50%% off %s' ), array( '50 %% Rabatt %s' ) ),
			array( $e( '50 percent' ), array( '50 % de réduction' ) ),
			array( $e( '%d file', '%d files' ), array( 'Eine Datei', '%d Dateien' ) ),
			array( $e( 'Read <a href="%s">docs</a>' ), array( '<a href="%s">Doku</a> lesen' ) ),
			array( $e( 'a <strong>b</strong>' ), array( 'x<br />y <strong>z</strong>' ) ),
			array( $e( 'Less' ), array( 'a < b' ) ),
		);
		foreach ( $good as list( $entry, $forms ) ) {
			assert_same( $forms, Validator::check_translation( $entry, $forms, 'de_CH' ), 'Valid: ' . $forms[0] );
		}
		$bad = array(
			array( $e( 'Hello %s' ), array( 'Hallo' ) ),
			array( $e( 'Hello %s' ), array( 'Hallo %d' ) ),
			array( $e( 'Hello %s' ), array( 'Hallo %s %s' ) ),
			array( $e( '%s of %d' ), array( '%d von %s' ) ),
			array( $e( '%1$s' ), array( '%1$s %s' ) ),
			array( $e( '50%% off %s' ), array( '50 % Rabatt %s' ) ),
			array( $e( 'Save' ), array( 'Speichern %s' ) ),
			array( $e( '%d file', '%d files' ), array( '%s Datei', '%d Dateien' ) ),
			array( $e( '%d file', '%d files' ), array( 'Datei' ) ),
			array( $e( 'Read <a href="%s">docs</a>' ), array( '<a href="https://evil.test">Doku</a>' ) ),
			array( $e( 'Save' ), array( '<script>alert(1)</script>' ) ),
			array( $e( '<strong>Save</strong>' ), array( '<strong onclick="x()">S</strong>' ) ),
			array( $e( 'Save' ), array( '<b>fett</b>' ) ),
			array( $e( 'Save' ), array( '<?php echo 1; ?>' ) ),
			array( $e( 'Save' ), array( '<!-- x -->' ) ),
			array( $e( 'Save' ), array( "bad\x01" ) ),
			array( $e( 'Save' ), array( "bad\xC3\x28" ) ),
			array( $e( 'Save' ), array( str_repeat( 'x', Config::MAX_STRING_CHARS + 1 ) ) ),
		);
		foreach ( $bad as list( $entry, $forms ) ) {
			assert_wp_error( Validator::check_translation( $entry, $forms, 'de_CH' ), '', 'Must reject: ' . substr( $forms[0], 0, 40 ) );
		}
		// Arabic needs all six forms.
		assert_wp_error( Validator::check_translation( $e( '%d item', '%d items' ), array( '%d a', '%d b' ), 'ar_EG' ) );
		assert_same( 6, count( Validator::check_translation( $e( '%d item', '%d items' ), array( 'a', '%d b', '%d c', '%d d', '%d e', '%d f' ), 'ar_EG' ) ) );
		assert_same( array(), Validator::check_translation( $e( 'Save' ), array( '' ), 'de_CH' ), 'All-empty forms clear a translation.' );
	}
);

cradle_test(
	'translations: invalid catalog imports (malformed JSON, oversized, invalid UTF-8, bad schema) are rejected and change nothing',
	function () {
		ct_admin();
		$rev   = ct_revision( 'de_CH' );
		$doc   = static fn( array $domains, array $extra = array() ) => wp_json_encode(
			array_merge(
				array(
					'schemaVersion' => 1,
					'locale'        => 'de_CH',
					'revision'      => 1,
					'domains'       => $domains,
				),
				$extra
			)
		);
		$many  = array();
		for ( $i = 0; $i <= Config::MAX_ENTRIES; $i++ ) {
			$many[] = array( 'msgid' => 's' . $i );
		}
		$cases = array(
			'malformed'        => '{"schemaVersion":1,"locale":"de_CH",',
			'not an object'    => '[1,2,3]',
			'invalid utf-8'    => "{\"schemaVersion\":1,\"locale\":\"de_CH\",\"domains\":{\"x\":[{\"msgid\":\"a\xC3\x28\"}]}}",
			'oversized'        => str_repeat( ' ', Config::import_limit() + 1 ) . '{}',
			'too deep'         => '{"schemaVersion":1,"locale":"de_CH","domains":{"x":[{"msgid":"a","translations":[[[[[[["x"]]]]]]]}]}}',
			'unknown key'      => $doc( array(), array( 'evil' => 1 ) ),
			'schema version'   => $doc( array(), array( 'schemaVersion' => 2 ) ),
			'wrong locale'     => $doc( array(), array( 'locale' => 'fr_FR' ) ),
			'bad domain'       => $doc( array( '../x' => array( array( 'msgid' => 'a' ) ) ) ),
			'entry type'       => $doc( array( 'x' => array( 'not an entry' ) ) ),
			'unknown entry'    => $doc( array( 'x' => array( array( 'msgid' => 'a', 'eval' => 'x' ) ) ) ),
			'empty msgid'      => $doc( array( 'x' => array( array( 'msgid' => '' ) ) ) ),
			'translations str' => $doc( array( 'x' => array( array( 'msgid' => 'a', 'translations' => 'b' ) ) ) ),
			'plural forms'     => $doc( array( 'x' => array( array( 'msgid' => 'a', 'msgidPlural' => 'b', 'translations' => array_fill( 0, 7, 'z' ) ) ) ) ),
			'duplicate'        => $doc( array( 'x' => array( array( 'msgid' => 'a' ), array( 'msgid' => 'a' ) ) ) ),
			'placeholder'      => $doc( array( 'x' => array( array( 'msgid' => 'Hello %s', 'translations' => array( 'Hallo' ) ) ) ) ),
			'php payload'      => $doc( array( 'x' => array( array( 'msgid' => 'Save', 'translations' => array( '<?php system("id"); ?>' ) ) ) ) ),
			'script payload'   => $doc( array( 'x' => array( array( 'msgid' => 'Save', 'draft' => array( '<script>x</script>' ) ) ) ) ),
			'too many entries' => $doc( array( 'x' => $many ) ),
		);
		foreach ( $cases as $label => $raw ) {
			assert_wp_error( Service::import_json( 'de_CH', $raw, true, $rev ), '', 'Import must be rejected: ' . $label );
		}
		assert_same( $rev, ct_revision( 'de_CH' ), 'Rejected imports change nothing.' );
		assert_wp_error( Validator::decode_json( '{"a":' . str_repeat( '[', 20 ) . str_repeat( ']', 20 ) . '}', 1000 ), 'cradle_translations_depth' );
	}
);

cradle_test(
	'translations: contextual strings with identical text stay distinct at runtime',
	function () {
		ct_set( 'de_CH', 'cradle', 'Search', 'search form label', null, array( 'Suchbegriff' ) );
		ct_set( 'de_CH', 'cradle', 'Search', 'search button text', null, array( 'Suchen' ) );
		$label  = ct_entry( 'de_CH', 'cradle', 'Search', 'search form label' );
		$button = ct_entry( 'de_CH', 'cradle', 'Search', 'search button text' );
		assert_true( $label['id'] !== $button['id'] );
		ct_switch( 'de_CH' );
		assert_same( 'Suchbegriff', esc_attr_x( 'Search', 'search form label', 'cradle' ) );
		assert_same( 'Suchen', _x( 'Search', 'search button text', 'cradle' ) );
		assert_same( 'Search', __( 'Search', 'cradle' ), 'The context-free string is a different entry and stays untranslated.' );
		assert_same( 'Search', _x( 'Search', 'another context', 'cradle' ) );
		restore_current_locale();
		assert_same( 'Search', esc_attr_x( 'Search', 'search form label', 'cradle' ), 'en_US keeps the original.' );
	}
);

cradle_test(
	'translations: drafts are not applied until published; publishing one locale leaves other locales cached data alone',
	function () {
		ct_set( 'fr_FR', 'cradle', 'Cradle modules', null, null, array( 'Modules Cradle' ) );
		$fr_before = Store::index()['locales']['fr_FR'];
		ct_set( 'de_CH', 'cradle', 'Cradle modules', null, null, array( 'Cradle-Module (DE)' ), false );
		$entry = ct_entry( 'de_CH', 'cradle', 'Cradle modules' );
		assert_same( array( 'Cradle-Module (DE)' ), $entry['draft'] );
		assert_same( 'missing', $entry['status'] );
		ct_switch( 'de_CH' );
		assert_same( 'Cradle modules', __( 'Cradle modules', 'cradle' ), 'Draft is not live.' );
		ct_admin();
		$result = ct_ok( Service::publish( 'de_CH', ct_revision( 'de_CH' ) ), 'publish' );
		assert_true( $result['runtimeChanged'] );
		ct_switch( 'de_CH' );
		assert_same( 'Cradle-Module (DE)', __( 'Cradle modules', 'cradle' ) );
		assert_same( $fr_before, Store::index()['locales']['fr_FR'], 'fr_FR runtime data and hash are untouched by a de_CH publish.' );
		$catalog = ct_ok( Store::read( 'de_CH' ) );
		assert_same( $catalog['revision'], $catalog['publishedRevision'] );
		restore_current_locale();
	}
);

cradle_test(
	'translations: plural selection follows each locale (de_CH 2 forms, fr_FR n>1, ar 6 forms) for _n and _nx',
	function () {
		ct_set( 'de_CH', 'cradle', '%d Cradle module is not running.', null, '%d Cradle modules are not running.', array( '%d Modul läuft nicht.', '%d Module laufen nicht.' ) );
		ct_set( 'fr_FR', 'cradle', '%d Cradle module is not running.', null, '%d Cradle modules are not running.', array( '%d module (fr)', '%d modules (fr)' ) );
		$ar = array( 'صفر %d', 'واحد %d', 'اثنان %d', 'قليل %d', 'كثير %d', 'مئة %d' );
		ct_set( 'ar_EG', 'cradle', '%d Cradle module is not running.', null, '%d Cradle modules are not running.', $ar );
		assert_wp_error( Service::save_entry( 'ar_EG', Catalog::entry_id( 'cradle', '%d Cradle module is not running.', null, '%d Cradle modules are not running.' ), array( '%d a', '%d b' ), false, ct_revision( 'ar_EG' ), true ), 'cradle_translations_forms', 'Arabic requires six forms.' );
		ct_set( 'de_CH', 'cradle-tr-test', '%d file', 'folder listing', '%d files', array( '%d Datei im Ordner', '%d Dateien im Ordner' ) );

		ct_switch( 'de_CH' );
		foreach ( array( 0 => '%d Module laufen nicht.', 1 => '%d Modul läuft nicht.', 2 => '%d Module laufen nicht.', 21 => '%d Module laufen nicht.' ) as $n => $want ) {
			assert_same( $want, _n( '%d Cradle module is not running.', '%d Cradle modules are not running.', $n, 'cradle' ), "de_CH n={$n}" );
		}
		assert_same( '%d Datei im Ordner', _nx( '%d file', '%d files', 1, 'folder listing', 'cradle-tr-test' ) );
		assert_same( '%d Dateien im Ordner', _nx( '%d file', '%d files', 3, 'folder listing', 'cradle-tr-test' ) );
		assert_same( '%d files', _nx( '%d file', '%d files', 3, 'other context', 'cradle-tr-test' ), 'Other context is not matched.' );
		assert_same( '3 Module laufen nicht.', sprintf( _n( '%d Cradle module is not running.', '%d Cradle modules are not running.', 3, 'cradle' ), 3 ) );

		ct_switch( 'fr_FR' );
		foreach ( array( 0 => '%d module (fr)', 1 => '%d module (fr)', 2 => '%d modules (fr)' ) as $n => $want ) {
			assert_same( $want, _n( '%d Cradle module is not running.', '%d Cradle modules are not running.', $n, 'cradle' ), "fr_FR n={$n}" );
		}
		ct_switch( 'ar_EG' );
		foreach ( array( 0 => 0, 1 => 1, 2 => 2, 3 => 3, 11 => 4, 100 => 5 ) as $n => $form ) {
			assert_same( $ar[ $form ], _n( '%d Cradle module is not running.', '%d Cradle modules are not running.', $n, 'cradle' ), "ar n={$n}" );
		}
		restore_current_locale();
		assert_same( '%d Cradle modules are not running.', _n( '%d Cradle module is not running.', '%d Cradle modules are not running.', 2, 'cradle' ) );
	}
);

cradle_test(
	'translations: placeholder mismatches are rejected on save and before publishing',
	function () {
		ct_admin();
		$id = Catalog::entry_id( 'cradle', 'Active: %s', null, null );
		assert_true( (bool) ct_entry( 'de_CH', 'cradle', 'Active: %s' ), 'Core string "Active: %s" is in the catalog.' );
		foreach ( array( 'Aktiv', 'Aktiv: %d', 'Aktiv: %s %s', '100% aktiv: %s' ) as $bad ) {
			assert_wp_error( Service::save_entry( 'de_CH', $id, array( $bad ), false, ct_revision( 'de_CH' ), false ), 'cradle_translations_placeholder', 'Rejected: ' . $bad );
		}
		ct_ok( Service::save_entry( 'de_CH', $id, array( 'Aktiv: %1$s' ), false, ct_revision( 'de_CH' ), true ) );
		ct_switch( 'de_CH' );
		assert_same( 'Aktiv: Twenty', sprintf( __( 'Active: %s', 'cradle' ), 'Twenty' ) );
		restore_current_locale();

		// A draft that bypassed validation (edited on disk) is still refused at publish time.
		$path = Store::dir( 'catalogs' ) . '/de_CH.json';
		$data = json_decode( file_get_contents( $path ), true );
		foreach ( $data['domains']['cradle'] as &$entry ) {
			if ( 'Active: %s' === $entry['msgid'] ) {
				$entry['draft']      = array( 'Aktiv: %d' );
				$entry['draftFuzzy'] = false;
			}
		}
		unset( $entry );
		file_put_contents( $path, wp_json_encode( $data ) );
		$published = Service::publish( 'de_CH', ct_revision( 'de_CH' ) );
		assert_wp_error( $published, 'cradle_translations_publish_invalid' );
		assert_contains( 'Active: %s', implode( ' ', $published->get_error_data()['problems'] ) );
		ct_switch( 'de_CH' );
		assert_same( 'Aktiv: %1$s', __( 'Active: %s', 'cradle' ), 'Published value unchanged after the refused publish.' );
		restore_current_locale();
		ct_ok( Service::discard_entry( 'de_CH', $id, ct_revision( 'de_CH' ) ) );
	}
);

cradle_test(
	'translations: JavaScript override is in the script-translation JSON of cradle-admin and agrees with PHP',
	function () {
		ct_set( 'de_CH', 'cradle', 'Select media', null, null, array( 'Medien auswählen' ) );
		ct_set( 'ar_EG', 'cradle', 'Select media', null, null, array( 'اختر الوسائط' ) );
		if ( ! wp_script_is( 'cradle-admin', 'registered' ) ) {
			cradle()->assets()->register_core_admin_assets();
		}
		assert_true( wp_script_is( 'cradle-admin', 'registered' ) );

		ct_switch( 'de_CH' );
		$json = load_script_textdomain( 'cradle-admin', 'cradle', CRADLE_DIR . 'languages' );
		assert_true( is_string( $json ), 'WordPress receives Jed JSON for the cradle-admin handle.' );
		$data     = json_decode( $json, true );
		$messages = $data['locale_data']['messages'];
		assert_same( array( 'Medien auswählen' ), $messages['Select media'] );
		assert_same( __( 'Select media', 'cradle' ), $messages['Select media'][0], 'PHP and JS agree.' );
		assert_same( 'nplurals=2; plural=(n != 1);', $messages['']['plural-forms'] );
		assert_same( 'de_CH', $messages['']['lang'] );
		assert_false( isset( $messages['Cradle modules'] ), 'PHP-only strings are not shipped to JavaScript.' );
		$printed = (string) wp_scripts()->print_translations( 'cradle-admin', false );
		assert_contains( 'wp.i18n.setLocaleData', $printed );
		assert_contains( 'Medien auswählen', $printed );

		ct_switch( 'ar_EG' );
		$data = json_decode( (string) load_script_textdomain( 'cradle-admin', 'cradle', CRADLE_DIR . 'languages' ), true );
		assert_same( array( 'اختر الوسائط' ), $data['locale_data']['messages']['Select media'] );
		assert_same( 6, Plural_Rules::header_nplurals( $data['locale_data']['messages']['']['plural-forms'] ) );
		assert_same( __( 'Select media', 'cradle' ), $data['locale_data']['messages']['Select media'][0] );

		ct_switch( 'fr_FR' );
		assert_false( load_script_textdomain( 'cradle-admin', 'cradle', CRADLE_DIR . 'languages' ), 'No fr_FR JS overrides: WordPress gets no data (no leak from de_CH).' );
		restore_current_locale();
		assert_false( load_script_textdomain( 'cradle-admin', 'cradle', CRADLE_DIR . 'languages' ) );
	}
);

cradle_test(
	'translations: fallback order override → shipped translation → original (PHP and JS)',
	function () {
		ct_set( 'de_CH', 'cradle-trfixture', 'Overridden', null, null, array( 'Überschrieben' ), true, true );
		ct_admin();
		ct_ok( Service::add_entry( 'de_CH', 'cradle-trfixture', 'Shipped only', '', '', true, ct_revision( 'de_CH' ) ) );
		wp_register_script( 'cradle-tr-fixture', plugins_url( 'cradle-translations/assets/js/admin.js' ), array(), '1', true );
		wp_set_script_translations( 'cradle-tr-fixture', 'cradle-trfixture', ct_fixtures() . '/shipped' );

		ct_switch( 'de_CH' );
		$data = json_decode( (string) load_script_textdomain( 'cradle-tr-fixture', 'cradle-trfixture', ct_fixtures() . '/shipped' ), true );
		assert_same( array( 'Überschrieben' ), $data['locale_data']['messages']['Overridden'], 'Override wins over the shipped JS file.' );
		assert_same( array( 'Nur mitgeliefert' ), $data['locale_data']['messages']['Shipped only'], 'Shipped JS translation kept.' );
		assert_same( 'Cradle test fixture', $data['generator'] ?? null, 'Merged into the shipped file, not replaced.' );

		$mo = new MO();
		$mo->add_entry(
			new Translation_Entry(
				array(
					'singular'     => 'Shipped only',
					'translations' => array( 'Nur mitgeliefert' ),
				)
			)
		);
		$mo->add_entry(
			new Translation_Entry(
				array(
					'singular'     => 'Overridden',
					'translations' => array( 'Mitgeliefert' ),
				)
			)
		);
		$GLOBALS['l10n']['cradle-trfixture'] = $mo;
		assert_same( 'Überschrieben', __( 'Overridden', 'cradle-trfixture' ) );
		assert_same( 'Nur mitgeliefert', __( 'Shipped only', 'cradle-trfixture' ), 'No override: shipped translation.' );
		assert_same( 'Untouched', __( 'Untouched', 'cradle-trfixture' ), 'Neither: original text.' );
		unset( $GLOBALS['l10n']['cradle-trfixture'] );
		wp_deregister_script( 'cradle-tr-fixture' );
		restore_current_locale();
	}
);

cradle_test(
	'translations: switching locales never leaks cached translations; each runtime map is read at most once per request',
	function () {
		$runtime = Runtime::instance();
		foreach ( CT_LOCALES as $locale ) {
			wp_cache_delete( 'map:' . $locale, Config::CACHE_GROUP );
			$runtime->invalidate( $locale );
		}
		$runtime->reset_stats();
		$expected = array(
			'de_CH' => 'Cradle-Module (DE)',
			'fr_FR' => 'Modules Cradle',
			'ar_EG'    => 'Cradle modules',
			'en_US' => 'Cradle modules',
		);
		foreach ( array( 'de_CH', 'fr_FR', 'ar_EG', 'en_US', 'de_CH', 'fr_FR', 'ar_EG', 'de_CH' ) as $locale ) {
			ct_switch( $locale );
			for ( $i = 0; $i < 50; $i++ ) {
				assert_same( $expected[ $locale ], __( 'Cradle modules', 'cradle' ), "Locale {$locale}" );
			}
			assert_same( 'de_CH' === $locale ? 'Suchen' : 'Search', _x( 'Search', 'search button text', 'cradle' ), "Context in {$locale}" );
		}
		restore_current_locale();
		$reads = $runtime->stats()['reads'];
		foreach ( array( 'de_CH', 'fr_FR', 'ar_EG' ) as $locale ) {
			assert_same( 1, $reads[ $locale ] ?? 0, "Exactly one runtime read for {$locale}." );
		}
		assert_false( isset( $reads['en_US'] ), 'No read at all for a locale without a catalog.' );
		assert_contains( 'cradle', implode( ',', $runtime->stats()['attached'] ) );
		assert_false( has_filter( 'gettext_cradle-never-overridden' ), 'Filters are scoped to domains with overrides.' );
	}
);

cradle_test(
	'translations: stale revisions and concurrent writes are rejected',
	function () {
		ct_admin();
		$rev = ct_revision( 'de_CH' );
		$a   = Catalog::entry_id( 'cradle', 'Cradle modules', null, null );
		$b   = Catalog::entry_id( 'cradle', 'Select media', null, null );
		ct_ok( Service::save_entry( 'de_CH', $a, array( 'Entwurf A1' ), false, $rev, false ) );
		assert_wp_error( Service::save_entry( 'de_CH', $a, array( 'Entwurf A2' ), false, $rev, false ), 'cradle_translations_stale', 'Second edit of the same entry from the old revision is stale.' );
		assert_same( array( 'Entwurf A1' ), ct_entry( 'de_CH', 'cradle', 'Cradle modules' )['draft'], 'First edit survives.' );
		ct_ok( Service::save_entry( 'de_CH', $b, array( 'Entwurf B' ), false, $rev, false ), 'An untouched entry can be saved from the same revision.' );
		assert_wp_error( Service::publish( 'de_CH', $rev ), 'cradle_translations_stale', 'Whole-catalog publish needs the current revision.' );
		assert_wp_error( Service::save_entry( 'de_CH', $b, array( 'X' ), false, ct_revision( 'de_CH' ) + 5, false ), 'cradle_translations_stale', 'Future revision refused.' );
		assert_wp_error( Service::restore( 'de_CH', $rev, $rev ), 'cradle_translations_stale' );

		$lock = cradle()->locks()->acquire( Config::LOCK_PREFIX . 'catalog:de_CH', 30 );
		assert_true( is_array( $lock ) );
		assert_wp_error( Service::save_entry( 'de_CH', $b, array( 'Y' ), false, ct_revision( 'de_CH' ), false ), 'cradle_translations_busy', 'Writes wait for the catalog lock.' );
		cradle()->locks()->release( Config::LOCK_PREFIX . 'catalog:de_CH', $lock );

		ct_ok( Service::discard_all( 'de_CH', ct_revision( 'de_CH' ) ) );
		assert_same( null, ct_entry( 'de_CH', 'cradle', 'Cradle modules' )['draft'] );
		ct_switch( 'de_CH' );
		assert_same( 'Cradle-Module (DE)', __( 'Cradle modules', 'cradle' ) );
		restore_current_locale();
	}
);

cradle_test(
	'translations: PHP override of a Cradle core admin label and the JS data appear on a real admin page in the user locale',
	function () {
		$uid = ct_user( 'cradle_tr_admin_de', 'administrator' );
		update_user_meta( $uid, 'locale', 'de_CH' );
		$response = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle' ), array(), $uid );
		$body     = wp_remote_retrieve_body( $response );
		assert_same( 200, wp_remote_retrieve_response_code( $response ) );
		assert_contains( '<h2>Cradle-Module (DE)</h2>', $body, 'Core admin label overridden in the administrator locale.' );
		assert_contains( 'cradle-admin-js-translations', $body, 'WordPress printed script translations for cradle-admin.' );
		assert_contains( 'Medien auswählen', $body, 'JS override delivered in the same request.' );
		assert_not_contains( 'Fatal error', $body );

		$other = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle' ), array(), cradle_test_user( 'administrator' ) );
		assert_contains( '<h2>Cradle modules</h2>', wp_remote_retrieve_body( $other ), 'An en_US administrator sees the original.' );

		// Frontend uses the site locale (en_US), not the user locale.
		$front = wp_remote_retrieve_body( cradle_test_http( 'GET', home_url( '/?s=cradle-translations-probe' ), array(), $uid ) );
		if ( false !== strpos( $front, 'wp-block-search' ) ) {
			assert_not_contains( 'Suchbegriff', $front, 'Frontend ignores the administrator user locale.' );
		}
	}
);

cradle_test(
	'translations: editors, subscribers and anonymous visitors are denied (service and HTTP)',
	function () {
		$id  = Catalog::entry_id( 'cradle', 'Select media', null, null );
		$rev = ct_revision( 'de_CH' );
		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			wp_set_current_user( cradle_test_user( $role ) );
			foreach ( array(
				'add_locale'  => fn() => Service::add_locale( 'it_IT' ),
				'save'        => fn() => Service::save_entry( 'de_CH', $id, array( 'Hack' ), false, $rev, true ),
				'publish'     => fn() => Service::publish( 'de_CH', $rev ),
				'discard_all' => fn() => Service::discard_all( 'de_CH', $rev ),
				'add_entry'   => fn() => Service::add_entry( 'de_CH', 'default', 'x', '', '', false, $rev ),
				'import_json' => fn() => Service::import_json( 'de_CH', '{}', true, $rev ),
				'import_po'   => fn() => Service::import_po( 'de_CH', '', 'default', true, true, $rev ),
				'export'      => fn() => Service::export_json( 'de_CH' ),
				'restore'     => fn() => Service::restore( 'de_CH', 1, $rev ),
				'refresh'     => fn() => Service::refresh_sources(),
				'delete'      => fn() => Service::delete_locale( 'de_CH' ),
			) as $label => $call ) {
				assert_wp_error( $call(), 'cradle_translations_forbidden', "{$role} must be refused: {$label}" );
			}
			assert_same( array(), Module::export(), "{$role} cannot use the module export handler." );
			assert_wp_error( Module::import( array() ), 'cradle_translations_forbidden' );
		}
		assert_same( $rev, ct_revision( 'de_CH' ) );
		assert_true( Store::exists( 'de_CH' ) );

		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			$user     = cradle_test_user( $role );
			$session  = cradle_test_cookies( $user );
			$nonce    = cradle_test_nonce( $user, $session, 'cradle_translations_save_entry_de_CH' );
			$response = cradle_test_http(
				'POST',
				admin_url( 'admin-post.php' ),
				array(
					'body' => array(
						'action'   => 'cradle_translations_save_entry',
						'locale'   => 'de_CH',
						'entry'    => $id,
						'revision' => $rev,
						'forms'    => array( 'Gehackt' ),
						'intent'   => 'publish',
						'_wpnonce' => $nonce,
					),
				),
				$user,
				$session
			);
			assert_same( 403, wp_remote_retrieve_response_code( $response ), "{$role} POST with own nonce is refused." );
			$page = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-translations' ), array(), $user );
			assert_true( 200 !== wp_remote_retrieve_response_code( $page ) || false !== stripos( wp_remote_retrieve_body( $page ), 'not allowed' ), "{$role} cannot open the editor." );
		}
		$anon = cradle_test_http(
			'POST',
			admin_url( 'admin-post.php' ),
			array(
				'body' => array(
					'action' => 'cradle_translations_publish',
					'locale' => 'de_CH',
				),
			)
		);
		assert_true( in_array( wp_remote_retrieve_response_code( $anon ), array( 302, 400, 403 ), true ), 'Anonymous admin-post is refused.' );
		assert_same( $rev, ct_revision( 'de_CH' ), 'No denied request changed the catalog.' );
		ct_switch( 'de_CH' );
		assert_same( 'Medien auswählen', __( 'Select media', 'cradle' ) );
		restore_current_locale();
	}
);

cradle_test(
	'translations: administrator HTTP flow — save and publish with nonce; bad nonce and GET refused',
	function () {
		$admin   = cradle_test_user( 'administrator' );
		$session = cradle_test_cookies( $admin );
		$id      = Catalog::entry_id( 'cradle', 'Select media', null, null );
		$post    = function ( array $body, string $nonce_action ) use ( $admin, $session ) {
			$body['_wpnonce'] = cradle_test_nonce( $admin, $session, $nonce_action );
			return cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => $body ), $admin, $session );
		};
		$response = $post(
			array(
				'action'   => 'cradle_translations_save_entry',
				'locale'   => 'fr_FR',
				'entry'    => $id,
				'revision' => ct_revision( 'fr_FR' ),
				'forms'    => array( 'Choisir un média' ),
				'intent'   => 'publish',
			),
			'cradle_translations_save_entry_fr_FR'
		);
		assert_same( 302, wp_remote_retrieve_response_code( $response ) );
		assert_contains( 'page=cradle-translations', (string) wp_remote_retrieve_header( $response, 'location' ) );
		assert_same( array( 'Choisir un média' ), ct_entry( 'fr_FR', 'cradle', 'Select media' )['translations'] );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( Config::INDEX_OPTION, 'options' );
		Runtime::instance()->invalidate( 'fr_FR' );
		ct_switch( 'fr_FR' );
		assert_same( 'Choisir un média', __( 'Select media', 'cradle' ) );
		restore_current_locale();

		// Nonce bound to another locale is refused.
		$wrong = $post(
			array(
				'action'   => 'cradle_translations_publish',
				'locale'   => 'de_CH',
				'revision' => ct_revision( 'de_CH' ),
			),
			'cradle_translations_publish_fr_FR'
		);
		assert_same( 403, wp_remote_retrieve_response_code( $wrong ) );
		$get = cradle_test_http( 'GET', admin_url( 'admin-post.php?action=cradle_translations_publish&locale=de_CH' ), array(), $admin, $session );
		assert_same( 405, wp_remote_retrieve_response_code( $get ), 'GET never mutates.' );

		// Add-locale and refresh over HTTP.
		$added = $post(
			array(
				'action'     => 'cradle_translations_add_locale',
				'new_locale' => 'it_IT',
			),
			'cradle_translations_add_locale'
		);
		assert_same( 302, wp_remote_retrieve_response_code( $added ) );
		assert_true( Store::exists( 'it_IT' ) );
		ct_admin();
		ct_ok( Service::delete_locale( 'it_IT' ) );
	}
);

cradle_test(
	'translations: admin screens render for administrators and the translator role',
	function () {
		$admin = cradle_test_user( 'administrator' );
		$pages = array(
			'strings'   => 'Publish all drafts|matching string',
			'import'    => 'Import a Cradle JSON catalog',
			'sources'   => 'Refresh source strings',
			'revisions' => 'Restore',
			'settings'  => 'Apply published translations',
		);
		foreach ( $pages as $tab => $needle ) {
			$response = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-translations&locale=de_CH&tab=' . $tab ), array(), $admin );
			$body     = wp_remote_retrieve_body( $response );
			assert_same( 200, wp_remote_retrieve_response_code( $response ), "Tab {$tab}" );
			assert_not_contains( 'Fatal error', $body );
			assert_true( (bool) preg_match( '/' . $needle . '/', $body ), "Tab {$tab} shows its content." );
		}
		$search = wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-translations&locale=de_CH&s=Select+media&status=translated' ), array(), $admin ) );
		assert_contains( 'Medien auswählen', $search, 'Search and status filters find the translated string.' );
		$editor = wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-translations&locale=ar_EG&entry=' . Catalog::entry_id( 'cradle', '%d Cradle module is not running.', null, '%d Cradle modules are not running.' ) ), array(), $admin ) );
		assert_same( 6, substr_count( $editor, 'name="forms[]"' ), 'Arabic plural editor shows six forms.' );
		assert_contains( 'dir="rtl"', $editor );
		assert_contains( 'plugins/cradle/admin/class-admin.php:', $editor, 'Source location shown.' );
		assert_contains( 'cradle-translations-admin-js', $editor, 'Editor script enqueued on its own screen.' );

		$translator = ct_user( 'cradle_tr_translator_user', CT_ROLE );
		$response   = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-translations&locale=de_CH' ), array(), $translator );
		assert_same( 200, wp_remote_retrieve_response_code( $response ), 'A role with only the translations capability can open the editor.' );
		$modules = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle' ), array(), $translator );
		assert_true( 200 !== wp_remote_retrieve_response_code( $modules ) || false !== stripos( wp_remote_retrieve_body( $modules ), 'not allowed' ), 'Translator cannot open other Cradle screens.' );
	}
);

cradle_test(
	'translations: settings save through options.php with the translations capability; editors refused',
	function () {
		$post = function ( int $user, int $per_page ) {
			$session = cradle_test_cookies( $user );
			return cradle_test_http(
				'POST',
				admin_url( 'options.php' ),
				array(
					'body' => array(
						'option_page'                  => 'cradle_translations',
						'action'                       => 'update',
						'_wpnonce'                     => cradle_test_nonce( $user, $session, 'cradle_translations-options' ),
						'_wp_http_referer'             => '/cradle/wp/wp-admin/admin.php?page=cradle-translations&tab=settings',
						'cradle_translations_settings' => array(
							'_revision'       => cradle()->settings()->revision( 'translations' ),
							'runtime_enabled' => '1',
							'js_enabled'      => '1',
							'expose_locales'  => '1',
							'auto_refresh'    => '1',
							'history_limit'   => 20,
							'max_import_kb'   => 4096,
							'per_page'        => $per_page,
						),
					),
				),
				$user,
				$session
			);
		};
		$flush = static function () {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( Config::OPTION, 'options' );
		};
		$editor   = cradle_test_user( 'editor' );
		$response = $post( $editor, 30 );
		$flush();
		assert_true( 200 !== wp_remote_retrieve_response_code( $response ) || false !== stripos( wp_remote_retrieve_body( $response ), 'not allowed' ), 'Editor is refused.' );
		assert_same( 50, (int) Config::get( 'per_page' ) );
		$translator = ct_user( 'cradle_tr_translator_user', CT_ROLE );
		$response   = $post( $translator, 30 );
		$flush();
		assert_same( 302, wp_remote_retrieve_response_code( $response ) );
		assert_same( 30, (int) Config::get( 'per_page' ), 'A translations manager can save translation settings.' );
		$post( $translator, 999 );
		$flush();
		assert_same( 30, (int) Config::get( 'per_page' ), 'Out-of-range values keep the previous value.' );
		cradle()->settings()->reset( 'translations' );
		$flush();
		assert_same( 50, (int) Config::get( 'per_page' ) );
	}
);

cradle_test(
	'translations: JSON export/import round trip (drafts by default, optional publish, locale checked)',
	function () {
		ct_admin();
		$json = ct_ok( Service::export_json( 'de_CH' ) );
		$data = json_decode( $json, true );
		assert_same( 1, $data['schemaVersion'] );
		assert_same( 'de_CH', $data['locale'] );
		assert_false( isset( $data['log'] ), 'Export omits the internal revision log (user IDs).' );
		foreach ( $data['domains']['cradle'] as &$entry ) {
			if ( 'Select media' === $entry['msgid'] ) {
				$entry['translations'] = array( 'Medien wählen (Import)' );
			}
		}
		unset( $entry );
		$data['domains']['cradle-tr-import'] = array(
			array(
				'msgid'        => 'Imported probe',
				'translations' => array( 'Importierte Probe' ),
				'js'           => true,
			),
		);
		assert_wp_error( Service::import_json( 'fr_FR', wp_json_encode( $data ), false, ct_revision( 'fr_FR' ) ), 'cradle_translations_locale_mismatch' );
		$result = ct_ok( Service::import_json( 'de_CH', wp_json_encode( $data ), false, ct_revision( 'de_CH' ) ) );
		assert_true( $result['written'] );
		assert_same( array( 'Medien wählen (Import)' ), ct_entry( 'de_CH', 'cradle', 'Select media' )['draft'] );
		ct_switch( 'de_CH' );
		assert_same( 'Medien auswählen', __( 'Select media', 'cradle' ), 'Imported values are drafts until published.' );
		ct_admin();
		ct_ok( Service::discard_all( 'de_CH', ct_revision( 'de_CH' ) ) );
		ct_ok( Service::import_json( 'de_CH', wp_json_encode( $data ), true, ct_revision( 'de_CH' ) ) );
		ct_switch( 'de_CH' );
		assert_same( 'Medien wählen (Import)', __( 'Select media', 'cradle' ), 'Import with publish applies immediately.' );
		assert_same( 'Importierte Probe', __( 'Imported probe', 'cradle-tr-import' ) );
		restore_current_locale();
		ct_set( 'de_CH', 'cradle', 'Select media', null, null, array( 'Medien auswählen' ) );
	}
);

cradle_test(
	'translations: .po/.pot source catalog import for a non-Cradle domain',
	function () {
		ct_admin();
		$domain = 'cradle-tr-po-test';
		$result = ct_ok( Service::import_po( 'de_CH', file_get_contents( ct_fixtures() . '/sample-v1.po' ), $domain, true, true, ct_revision( 'de_CH' ) ) );
		assert_contains( 'Price: %s', implode( ' ', $result['problems'] ), 'Invalid translation reported and skipped.' );
		assert_same( array( 'In den Warenkorb' ), ct_entry( 'de_CH', $domain, 'Add to cart' )['draft'] );
		assert_same( array( 'Bestellen' ), ct_entry( 'de_CH', $domain, 'Order', 'verb' )['draft'] );
		assert_same( array( 'Bestellung' ), ct_entry( 'de_CH', $domain, 'Order', 'noun' )['draft'] );
		$plural = ct_entry( 'de_CH', $domain, '%s product', null, '%s products' );
		assert_same( array( '%s Produkt', '%s Produkte' ), $plural['draft'] );
		assert_true( $plural['js'], 'Strings referenced from .js files are flagged for JavaScript.' );
		assert_same( 'translators: %s: product count', $plural['comments'] );
		assert_true( ct_entry( 'de_CH', $domain, 'Checkout' )['draftFuzzy'], 'Fuzzy flag preserved.' );
		assert_same( null, ct_entry( 'de_CH', $domain, 'Price: %s' )['draft'] );
		assert_true( (bool) ct_entry( 'de_CH', $domain, "Multi line\ntext" ) );
		assert_same( null, ct_entry( 'de_CH', $domain, 'Old string' ), '#~ obsolete entries are ignored.' );
		assert_same( 'imported', ct_entry( 'de_CH', $domain, 'Add to cart' )['source'] );
		ct_ok( Service::publish( 'de_CH', ct_revision( 'de_CH' ) ) );
		ct_switch( 'de_CH' );
		assert_same( 'In den Warenkorb', __( 'Add to cart', $domain ) );
		assert_same( 'Bestellung', _x( 'Order', 'noun', $domain ) );
		assert_same( '%s Produkte', _n( '%s product', '%s products', 4, $domain ) );
		assert_same( 'Checkout', __( 'Checkout', $domain ), 'Fuzzy translations are not applied.' );
		restore_current_locale();

		ct_admin();
		ct_ok( Service::import_po( 'de_CH', file_get_contents( ct_fixtures() . '/sample-v2.po' ), $domain, false, true, ct_revision( 'de_CH' ) ) );
		$checkout = ct_entry( 'de_CH', $domain, 'Checkout' );
		assert_same( 'obsolete', $checkout['status'] );
		assert_same( array( 'Kasse' ), $checkout['translations'], 'Obsolete entries keep their translation for review.' );
		assert_same( 'translated', ct_entry( 'de_CH', $domain, 'Add to cart' )['status'] );

		$rev = ct_revision( 'de_CH' );
		assert_wp_error( Service::import_po( 'de_CH', "msgid \"a\"\nbogus line\n", $domain, true, true, $rev ), 'cradle_translations_po' );
		assert_wp_error( Service::import_po( 'de_CH', "msgid \"\"\nmsgstr \"Content-Type: text/plain; charset=ISO-8859-1\\n\"\n", $domain, true, true, $rev ), 'cradle_translations_charset' );
		assert_wp_error( Service::import_po( 'de_CH', "msgid \"a\xC3\x28\"\nmsgstr \"\"\n", $domain, true, true, $rev ), 'cradle_translations_encoding' );
		assert_wp_error( Service::import_po( 'de_CH', "msgid \"a\"\nmsgstr \"b\"\n", '../../etc', true, true, $rev ), 'cradle_translations_domain' );
		assert_same( $rev, ct_revision( 'de_CH' ) );
	}
);

cradle_test(
	'translations: simulated package update (re-extraction) keeps overrides and marks vanished strings obsolete',
	function () {
		ct_admin();
		ct_install_fixture( 'pkg-v1' );
		ct_ok( Service::refresh_sources() );
		$manifest = Store::manifest();
		assert_same( '1.0.0', $manifest['packages'][ 'plugin:' . CT_FIXTURE_SLUG ]['version'] ?? null );
		assert_true( (bool) array_filter( $manifest['dynamic'], static fn( $d ) => str_starts_with( $d['file'], 'plugins/' . CT_FIXTURE_SLUG . '/' ) && 'text' === $d['reason'] ), 'Dynamic __( $label ) call is reported, not guessed.' );
		$d = CT_FIXTURE_SLUG;
		assert_true( (bool) ct_entry( 'fr_FR', $d, 'Fixture removed' ), 'New source strings reach every catalog.' );
		assert_true( ct_entry( 'de_CH', $d, 'Fixture JS kept' )['js'] );
		assert_true( ct_entry( 'de_CH', $d, 'Fixture word', 'first meaning' )['id'] !== ct_entry( 'de_CH', $d, 'Fixture word', 'second meaning' )['id'] );
		ct_set( 'de_CH', $d, 'Fixture kept', null, null, array( 'Behalten' ) );
		ct_set( 'de_CH', $d, 'Fixture removed', null, null, array( 'Entfernt' ) );
		ct_set( 'de_CH', $d, 'Fixture JS kept', null, null, array( 'JS behalten' ) );
		ct_set( 'de_CH', $d, '%d fixture apple', null, '%d fixture apples', array( '%d Apfel', '%d Äpfel' ) );

		ct_install_fixture( 'pkg-v2' );
		assert_contains( 'plugin:' . CT_FIXTURE_SLUG, implode( ',', Service::source_drift()['changed'] ), 'Version change detected before refresh.' );
		ct_ok( Service::refresh_sources() );
		assert_same( '2.0.0', Store::manifest()['packages'][ 'plugin:' . CT_FIXTURE_SLUG ]['version'] ?? null );
		assert_same( array(), Service::source_drift()['changed'] );

		$kept = ct_entry( 'de_CH', $d, 'Fixture kept' );
		assert_same( 'translated', $kept['status'] );
		assert_same( array( 'Behalten' ), $kept['translations'] );
		$removed = ct_entry( 'de_CH', $d, 'Fixture removed' );
		assert_same( 'obsolete', $removed['status'], 'Vanished string is obsolete.' );
		assert_same( array( 'Entfernt' ), $removed['translations'], 'Its translation is preserved for review.' );
		assert_same( 'missing', ct_entry( 'de_CH', $d, 'Fixture added' )['status'] );
		assert_same( 'obsolete', ct_entry( 'de_CH', $d, 'Fixture JS removed' )['status'] );
		assert_same( 'missing', ct_entry( 'fr_FR', $d, 'Fixture added' )['status'] );
		assert_same( array( '%d Apfel', '%d Äpfel' ), ct_entry( 'de_CH', $d, '%d fixture apple', null, '%d fixture apples' )['translations'] );

		ct_switch( 'de_CH' );
		assert_same( 'Behalten', __( 'Fixture kept', $d ), 'Override survives the update.' );
		assert_same( '%d Äpfel', _n( '%d fixture apple', '%d fixture apples', 7, $d ) );
		assert_same( 'Entfernt', __( 'Fixture removed', $d ), 'Obsolete translations still apply if the string is used again.' );
		restore_current_locale();

		ct_install_fixture( 'pkg-v1' );
		ct_ok( Service::refresh_sources() );
		assert_same( 'translated', ct_entry( 'de_CH', $d, 'Fixture removed' )['status'], 'A returning string is revived with its translation.' );
		ct_remove_fixture();
	}
);

cradle_test(
	'translations: revision history lists changes and restores an earlier revision as a new revision',
	function () {
		ct_admin();
		$before = ct_revision( 'de_CH' );
		ct_set( 'de_CH', 'cradle', 'Cradle modules', null, null, array( 'Temporär' ) );
		ct_switch( 'de_CH' );
		assert_same( 'Temporär', __( 'Cradle modules', 'cradle' ) );
		ct_admin();
		$current = ct_revision( 'de_CH' );
		assert_true( in_array( $before, Store::snapshots( 'de_CH' ), true ), 'The replaced revision is in the history.' );
		assert_true( count( Store::snapshots( 'de_CH' ) ) <= max( 3, (int) Config::get( 'history_limit' ) ), 'History is bounded.' );
		$log = ct_ok( Store::read( 'de_CH' ) )['log'];
		assert_same( $current, end( $log )['revision'] );
		assert_same( 'publish_entry', end( $log )['action'] );
		assert_wp_error( Service::restore( 'de_CH', 999999, $current ), 'cradle_translations_revision' );
		$restored = ct_ok( Service::restore( 'de_CH', $before, $current ) );
		assert_same( $current + 1, $restored['catalog']['revision'], 'Restore creates a new revision.' );
		ct_switch( 'de_CH' );
		assert_same( 'Cradle-Module (DE)', __( 'Cradle modules', 'cradle' ), 'Restored translation is live again.' );
		restore_current_locale();
	}
);

cradle_test(
	'translations: Cradle-registered labels resolve through cradle/translate_label',
	function () {
		ct_admin();
		$probe = static function ( $labels ) {
			$labels->add( 'cradle-tr-test', 'Probe label', 'test label', 'test/probe' );
			$labels->add( 'bad domain!', 'Rejected', '', 'x' );
		};
		add_action( 'cradle/translations/register_labels', $probe );
		ct_ok( Service::refresh_sources() );
		remove_action( 'cradle/translations/register_labels', $probe );
		assert_same( 'registered', ct_entry( 'de_CH', 'cradle-tr-test', 'Probe label', 'test label' )['source'] ?? null );
		assert_same( 'registered', ct_entry( 'de_CH', 'cradle', 'Cradle Translations', 'module name' )['source'] ?? null );
		ct_set( 'de_CH', 'cradle', 'Cradle Translations', 'module name', null, array( 'Cradle-Übersetzungen' ) );
		ct_switch( 'de_CH' );
		assert_same( 'Cradle-Übersetzungen', apply_filters( 'cradle/translate_label', 'Cradle Translations', 'module name', 'cradle' ) );
		assert_same( 'Unregistered', apply_filters( 'cradle/translate_label', 'Unregistered', 'module name', 'cradle' ) );
		restore_current_locale();
		assert_same( 'Cradle Translations', apply_filters( 'cradle/translate_label', 'Cradle Translations', 'module name', 'cradle' ) );
	}
);

cradle_test(
	'translations: private storage holds only JSON data (no PHP from translator input) and is not served over HTTP',
	function () {
		$base = cradle()->storage()->module_dir( 'translations' );
		assert_true( is_string( $base ) && is_dir( $base ) );
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
		$json  = 0;
		foreach ( $files as $file ) {
			$name = $file->getFilename();
			if ( str_ends_with( $name, '.php' ) ) {
				assert_same( 'index.php', $name, 'Only the core directory guard may be a PHP file.' );
				assert_same( "<?php\n// Silence is golden.\n", file_get_contents( $file->getPathname() ), 'The guard file is unchanged.' );
				continue;
			}
			if ( str_ends_with( $name, '.json' ) ) {
				++$json;
				assert_false( str_starts_with( ltrim( file_get_contents( $file->getPathname() ) ), '<?' ), 'Catalog data is JSON, not code.' );
				continue;
			}
			assert_true( in_array( $name, array( '.htaccess', 'web.config' ), true ), 'Unexpected file in storage: ' . $name );
		}
		assert_true( $json >= 6, 'Catalogs, history, runtime and manifest files exist as JSON.' );
		$root = cradle()->storage()->private_root();
		if ( is_wp_error( $root ) || 'in-webroot' !== $root['mode'] ) {
			return;
		}
		$response = wp_remote_get( $root['url'] . '/translations/catalogs/de_CH.json', array( 'timeout' => 10 ) );
		assert_false( 200 === wp_remote_retrieve_response_code( $response ) && false !== strpos( wp_remote_retrieve_body( $response ), 'schemaVersion' ), 'Catalog must not be downloadable over HTTP.' );
	}
);

cradle_test(
	'translations: runtime guard when core is missing (separate process, nothing persisted)',
	function () {
		$script = ct_fixtures() . '/core-missing.php';
		$output = array();
		$code   = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1', $output, $code );
		$last   = (string) end( $output );
		$result = json_decode( $last, true );
		assert_same( 0, $code, 'Subprocess exits cleanly: ' . implode( "\n", array_slice( $output, -5 ) ) );
		assert_true( is_array( $result ), 'Subprocess reported JSON: ' . $last );
		assert_false( $result['core'], 'Core is not loaded in the subprocess.' );
		assert_true( $result['plugin'], 'The add-on bootstrap loaded.' );
		assert_false( $result['runtime'], 'No runtime classes were loaded without core.' );
		assert_false( $result['gettext'], 'No gettext overrides without core.' );
		assert_contains( 'Cradle Translations needs the Cradle core plugin', $result['notice'] );
		assert_same( 'Cradle modules', $result['translation'] );
		assert_true( is_plugin_active( 'cradle/cradle.php' ) && is_plugin_active( 'cradle-translations/cradle-translations.php' ), 'Real plugin state unchanged.' );
	}
);

cradle_test(
	'translations: lifecycle — deactivation clears cron and locks but keeps data; upgrades schedule a refresh',
	function () {
		ct_admin();
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, Config::CRON_REFRESH );
		$lock = cradle()->locks()->acquire( Config::LOCK_PREFIX . 'probe', 120 );
		assert_true( is_array( $lock ) );
		Lifecycle::deactivate();
		assert_false( wp_next_scheduled( Config::CRON_REFRESH ), 'Cron cleared.' );
		$again = cradle()->locks()->acquire( Config::LOCK_PREFIX . 'probe', 5 );
		assert_true( is_array( $again ), 'Own locks released.' );
		cradle()->locks()->release( Config::LOCK_PREFIX . 'probe', $again );
		foreach ( CT_LOCALES as $locale ) {
			assert_true( Store::exists( $locale ), 'Catalogs kept on deactivation.' );
		}
		Lifecycle::activate( false );
		assert_true( get_role( 'administrator' )->has_cap( Config::CAP ) );

		Module::after_upgrade(
			null,
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'plugins' => array( 'akismet/akismet.php' ),
			)
		);
		assert_false( wp_next_scheduled( Config::CRON_REFRESH ), 'Non-Cradle updates do not trigger extraction.' );
		Module::after_upgrade(
			null,
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'plugins' => array( 'cradle-social/cradle-social.php' ),
			)
		);
		assert_true( (bool) wp_next_scheduled( Config::CRON_REFRESH ), 'A Cradle package update schedules a source refresh.' );
		wp_clear_scheduled_hook( Config::CRON_REFRESH );
	}
);

cradle_test(
	'translations: cleanup — test catalogs, fixture package, users and role removed; nothing left published',
	function () {
		ct_admin();
		restore_current_locale();
		foreach ( array_merge( CT_LOCALES, CT_EXTRA ) as $locale ) {
			if ( Store::exists( $locale ) ) {
				ct_ok( Service::delete_locale( $locale ) );
			}
			assert_false( Store::exists( $locale ) );
			assert_same( array(), Store::snapshots( $locale ) );
		}
		ct_remove_fixture();
		ct_ok( Service::refresh_sources() );
		assert_false( isset( Store::manifest()['packages'][ 'plugin:' . CT_FIXTURE_SLUG ] ) );
		// HTTP sub-requests in this and earlier suites write options in other processes; re-read from the DB.
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( Config::INDEX_OPTION, 'options' );
		assert_same( array(), array_diff( array_keys( Store::index()['locales'] ), Examples::LOCALES ), 'No published overrides remain besides the built-in example languages.' );
		foreach ( Examples::LOCALES as $locale ) {
			assert_true( isset( Store::index()['locales'][ $locale ] ), "Built-in example {$locale} is still published after the suite." );
		}
		ct_refresh_switcher();
		assert_false( switch_to_locale( 'de_CH' ), 'de_CH is no longer offered once its catalog is gone.' );
		assert_same( 'Cradle modules', __( 'Cradle modules', 'cradle' ) );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( array( 'cradle_tr_admin_de', 'cradle_tr_translator_user' ) as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user ) {
				wp_delete_user( $user->ID );
			}
		}
		remove_role( CT_ROLE );
		assert_same( null, get_role( CT_ROLE ) );
	}
);

cradle_test(
	'translations: built-in German and Arabic examples are always present, complete, valid, protected and never overwrite edits',
	function () {
		$admin = ct_admin();
		Examples::maybe_install();
		$framework = array( 'cradle', 'cradle-admin', 'cradle-blocks', 'cradle-content', 'cradle-dummy-content', 'cradle-duplicate', 'cradle-login', 'cradle-migration', 'cradle-social', 'cradle-translations' );
		$manifest  = Extractor::build_manifest();
		$sources   = array();
		$owners    = array(); // Registered label origin (registry/module/<id>, registry/capability/<cap>) => plugin folder.
		foreach ( cradle()->modules()->all() as $id => $descriptor ) {
			$owners[ 'registry/module/' . $id ] = dirname( plugin_basename( (string) $descriptor['pluginFile'] ) );
			foreach ( array_keys( (array) $descriptor['capabilities'] ) as $cap ) {
				$owners[ 'registry/capability/' . $cap ] = $owners[ 'registry/module/' . $id ];
			}
		}
		// Add-ons outside the built-in example set ship their own translations.
		$add_on_label = static fn( array $e ): bool => 'registered' === ( $e['source'] ?? '' ) && isset( $owners[ $e['references'][0] ?? '' ] ) && ! in_array( $owners[ $e['references'][0] ], $framework, true );
		foreach ( $manifest['entries'] as $e ) {
			// Labels of a site's own Cradle Content definitions are site data, not framework strings.
			if ( in_array( $e['domain'], $framework, true ) && ! ( 'registered' === ( $e['source'] ?? '' ) && 'cradle-content' === $e['domain'] ) && ! $add_on_label( $e ) ) {
				$sources[ Catalog::identity( $e['domain'], $e['msgid'], '' === (string) $e['context'] ? null : $e['context'], '' === (string) $e['msgidPlural'] ? null : $e['msgidPlural'] ) ] = $e;
			}
		}
		assert_true( count( $sources ) > 1500, 'Framework strings found: ' . count( $sources ) );
		foreach ( Examples::LOCALES as $locale ) {
			assert_true( Store::exists( $locale ), "{$locale} example catalog exists." );
			$shipped = ct_ok( Examples::read( $locale ) );
			$covered = array();
			foreach ( $shipped as $row ) {
				$covered[ Catalog::identity( $row['domain'], $row['msgid'], $row['context'], $row['msgidPlural'] ) ] = true;
				$entry = array(
					'msgid'       => $row['msgid'],
					'msgidPlural' => $row['msgidPlural'],
					'context'     => $row['context'],
				);
				$checked = Validator::check_translation( $entry, $row['forms'], $locale );
				assert_true( ! is_wp_error( $checked ) && $checked, "{$locale} shipped translation is valid: {$row['msgid']}" . ( is_wp_error( $checked ) ? ' — ' . $checked->get_error_message() : '' ) );
			}
			$missing = array_diff_key( $sources, $covered );
			assert_same( array(), array_slice( array_map( static fn( $e ) => $e['domain'] . ': ' . $e['msgid'], $missing ), 0, 20 ), "{$locale} example covers every framework string." );
			$catalog = ct_ok( Store::read( $locale ) );
			$untranslated = array();
			foreach ( $framework as $domain ) {
				foreach ( $catalog['domains'][ $domain ] ?? array() as $entry ) {
					if ( empty( $entry['obsolete'] ) && ! $entry['translations'] && null === $entry['draft'] && ! ( 'registered' === $entry['source'] && 'cradle-content' === $domain ) && ! $add_on_label( $entry ) ) {
						$untranslated[] = $domain . ': ' . $entry['msgid'];
					}
				}
			}
			assert_same( array(), array_slice( $untranslated, 0, 20 ), "{$locale} catalog has a translation for every framework string." );
			assert_wp_error( Service::delete_locale( $locale ), 'cradle_translations_example_locale', "{$locale} cannot be deleted." );
		}

		// Runtime: German strings, Arabic strings and right-to-left direction.
		$de = array_values( array_filter( ct_ok( Examples::read( 'de_DE' ) ), static fn( $r ) => 'cradle' === $r['domain'] && 'Cradle modules' === $r['msgid'] ) )[0]['forms'][0];
		$ar = array_values( array_filter( ct_ok( Examples::read( 'ar' ) ), static fn( $r ) => 'cradle' === $r['domain'] && 'Cradle modules' === $r['msgid'] ) )[0]['forms'][0];
		ct_switch( 'de_DE' );
		assert_same( $de, __( 'Cradle modules', 'cradle' ) );
		assert_false( is_rtl() );
		ct_switch( 'ar' );
		assert_same( $ar, __( 'Cradle modules', 'cradle' ) );
		assert_true( is_rtl(), 'Arabic example turns WordPress right-to-left.' );
		restore_current_locale();
		assert_same( 'Cradle modules', __( 'Cradle modules', 'cradle' ) );
		$uid = ct_user( 'cradle_tr_admin_ar', 'administrator' );
		update_user_meta( $uid, 'locale', 'ar' );
		$body = wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle' ), array(), $uid ) );
		assert_contains( 'dir="rtl"', $body, 'Arabic admin page is right-to-left.' );
		assert_contains( '<h2>' . esc_html( $ar ) . '</h2>', $body, 'Arabic label on a real admin page.' );

		// An administrator's draft is never replaced by shipped text; discarding restores the catalog.
		$id  = Catalog::entry_id( 'cradle', 'Cradle modules', null, null );
		ct_ok( Service::save_entry( 'de_DE', $id, array( 'Eigene Module' ), false, ct_revision( 'de_DE' ), false ) );
		Examples::install( 'de_DE' );
		assert_same( array( 'Eigene Module' ), ct_entry( 'de_DE', 'cradle', 'Cradle modules' )['draft'], 'Draft kept.' );
		ct_ok( Service::discard_entry( 'de_DE', $id, ct_revision( 'de_DE' ) ) );
		assert_same( array( $de ), ct_entry( 'de_DE', 'cradle', 'Cradle modules' )['translations'], 'Shipped translation still published.' );
		$page = wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-translations&locale=ar&tab=import' ), array(), $admin ) );
		assert_contains( 'Built-in example language', $page );
		assert_not_contains( 'Delete catalog', $page, 'No delete button for an example language.' );
	}
);

cradle_test(
	'translations: uninstall retains data by default and purges only with an explicit policy',
	function () {
		ct_admin();
		ct_ok( Service::add_locale( 'ru_RU' ) );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'cradle-translations/cradle-translations.php' );
		}
		$uninstall = WP_PLUGIN_DIR . '/cradle-translations/uninstall.php';
		include $uninstall;
		assert_true( Store::exists( 'ru_RU' ), 'Default policy retains catalogs.' );
		assert_true( get_role( 'administrator' )->has_cap( Config::CAP ), 'Default policy keeps the capability.' );

		$policies                 = get_option( 'cradle_uninstall_policy', array() );
		$policies                 = is_array( $policies ) ? $policies : array();
		$policies['translations'] = 'purge';
		update_option( 'cradle_uninstall_policy', $policies, false );
		// Keep the shared sandbox's catalogs (built-in examples with site additions) to restore after the purge.
		$root    = cradle()->storage()->private_root();
		$backup  = sys_get_temp_dir() . '/cradle-translations-backup-' . wp_generate_password( 6, false );
		$options = array();
		foreach ( array( Config::OPTION, Config::INDEX_OPTION, Config::STATE_OPTION, 'cradle_translations_version', Examples::OPTION ) as $option ) {
			$options[ $option ] = get_option( $option, null );
		}
		ct_copy_tree( $root['path'] . '/translations', $backup );
		include $uninstall;
		assert_false( is_dir( $root['path'] . '/translations' ), 'Purge removes the private translations directory.' );
		assert_false( get_option( Config::INDEX_OPTION, false ) );
		wp_roles()->for_site();
		assert_false( get_role( 'administrator' )->has_cap( Config::CAP ), 'Purge revokes the capability.' );
		assert_false( isset( get_option( 'cradle_uninstall_policy', array() )['translations'] ), 'Policy entry consumed.' );
		assert_true( is_dir( $root['path'] ), 'Core private storage and other modules are untouched.' );

		// Restore the active plugin's baseline (it stays active in the shared sandbox).
		ct_copy_tree( $backup, $root['path'] . '/translations' );
		ct_rmdir( $backup );
		foreach ( $options as $option => $value ) {
			if ( null !== $value ) {
				update_option( $option, $value, Config::INDEX_OPTION === $option );
			}
		}
		ct_ok( Service::delete_locale( 'ru_RU' ), 'remove the restored test locale' );
		Lifecycle::activate( false );
		wp_roles()->for_site();
		assert_true( get_role( 'administrator' )->has_cap( Config::CAP ) );
		Runtime::instance()->invalidate( 'ru_RU' );
		assert_false( Store::exists( 'ru_RU' ) );
		assert_true( is_plugin_active( 'cradle-translations/cradle-translations.php' ) );
	}
);
