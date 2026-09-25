<?php
/**
 * Website child themes: the parent theme's generator (`bin/child-theme.php new|check`) and starter, and
 * site components that live in the active child theme (a Cradle website has no site plugin).
 *
 * Generated children go to a temporary folder (or `sites/cradle-test-generator/` for the repository-source
 * case) and are removed by each test and again at shutdown. The component fixture is written into the
 * active child theme's `components/` and `blocks/` folders of the sandbox copy and removed the same way.
 */

$GLOBALS['cradle_ctg_cleanup'] = array();

function cradle_ctg_rmdir( string $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $dir );
}

function cradle_ctg_track( string $dir ): string {
	$GLOBALS['cradle_ctg_cleanup'][] = $dir;
	return $dir;
}

function cradle_ctg_cleanup_all() {
	foreach ( array_reverse( $GLOBALS['cradle_ctg_cleanup'] ) as $dir ) {
		cradle_ctg_rmdir( $dir );
	}
	$GLOBALS['cradle_ctg_cleanup'] = array();
}
register_shutdown_function( 'cradle_ctg_cleanup_all' );

/** The generator shipped with the installed parent theme (sandbox copy of packages/themes/cradle). */
function cradle_ctg_script(): string {
	$script = get_template_directory() . '/bin/child-theme.php';
	if ( 'cradle' !== get_template() || ! is_file( $script ) ) {
		skip_test( 'The Cradle parent theme with bin/child-theme.php is not the active template.' );
	}
	return $script;
}

function cradle_ctg_tmp(): string {
	return cradle_ctg_track( wp_normalize_path( sys_get_temp_dir() ) . '/cradle-ctg-' . strtolower( wp_generate_password( 8, false, false ) ) );
}

/**
 * Runs the generator in a separate PHP CLI process (no shell; arguments passed as an array).
 *
 * @return array{code:int,out:string,err:string}
 */
function cradle_ctg_run( string $script, array $args ): array {
	$proc = proc_open( array_merge( array( PHP_BINARY, $script ), $args ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $proc ) ) {
		throw new Cradle_Test_Failure( 'Could not start the generator process.' );
	}
	$out = stream_get_contents( $pipes[1] );
	$err = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	return array(
		'code' => proc_close( $proc ),
		'out'  => (string) $out,
		'err'  => (string) $err,
	);
}

/** Relative paths of every file below $dir. */
function cradle_ctg_files( string $dir ): array {
	$out = array();
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		$out[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) + 1 ) );
	}
	sort( $out );
	return $out;
}

cradle_test(
	'child theme generator: new creates a complete child from the installed parent\'s starter',
	function () {
		$script  = cradle_ctg_script();
		$starter = get_template_directory() . '/starter/child-theme';
		$tmp     = cradle_ctg_tmp();
		$run     = cradle_ctg_run( $script, array( 'new', '--slug=acme-studio', '--name=Acme Studio & Co', '--site-id=acme-studio-test', '--target=' . $tmp ) );
		assert_same( 0, $run['code'], 'Generator exit code. ' . $run['err'] );
		assert_contains( 'northstar.md', $run['out'], 'The generator points to the brief.' );

		$child    = $tmp . '/acme-studio';
		$expected = array_map( static fn( $f ) => preg_replace( '/\.tpl$/', '', $f ), cradle_ctg_files( $starter ) );
		sort( $expected );
		assert_same( $expected, cradle_ctg_files( $child ), 'Child has exactly the starter files (.tpl suffix removed).' );
		foreach ( array( 'northstar.md', 'AGENTS.md', 'CLAUDE.md', 'cradle-workspace.json', 'style.css', 'theme.json', 'docs/project-state.md', 'docs/decisions.md', 'docs/component-map.md', 'docs/asset-map.md', 'docs/content-model.md', 'docs/verification.md' ) as $file ) {
			assert_true( is_file( $child . '/' . $file ), "Starter file {$file} created." );
		}
		foreach ( cradle_ctg_files( $child ) as $file ) {
			assert_not_contains( '{{', (string) file_get_contents( $child . '/' . $file ), "No unreplaced placeholder in {$file}." );
		}

		$style = (string) file_get_contents( $child . '/style.css' );
		assert_true( (bool) preg_match( '/^\s*Template:\s*cradle\s*$/mi', $style ), 'style.css declares Template: cradle.' );
		assert_true( (bool) preg_match( '/^\s*Theme Name:\s*Acme Studio & Co\s*$/mi', $style ), 'style.css carries the site name.' );
		// Thin child: site data only, no framework code to go stale when the parent updates.
		assert_same( array(), array_values( array_filter( cradle_ctg_files( $child ), static fn( $f ) => preg_match( '/\.(php|js)$/', $f ) ) ), 'The new child contains no PHP or JS.' );
		assert_contains( 'docs/child-themes.md', (string) file_get_contents( $child . '/AGENTS.md' ), 'Child instructions point to the rules shipped with the parent.' );
		assert_true( is_file( get_template_directory() . '/docs/child-themes.md' ), 'The installed parent ships those rules.' );
		assert_same( '', (string) file_get_contents( $child . '/northstar.md' ), 'The brief starts empty for the owner to fill.' );
		assert_true( is_file( get_template_directory() . '/docs/website-brief.md' ), 'The brief checklist ships with the parent.' );

		$workspace = json_decode( (string) file_get_contents( $child . '/cradle-workspace.json' ), true );
		assert_true( is_array( $workspace ), 'cradle-workspace.json is valid JSON.' );
		assert_same( 'acme-studio-test', $workspace['siteId'] ?? null, 'Site ID recorded.' );
		assert_false( array_key_exists( 'sitePlugin', $workspace ), 'Workspace declares no site plugin.' );
		assert_false( array_key_exists( 'sourceLayout', $workspace ), 'No repository layout outside the Cradle repository.' );
		assert_false( is_dir( $child . '/plugins' ), 'No site plugin folder.' );
	}
);

cradle_test(
	'child theme generator: refuses to overwrite, reserved slugs and unsafe input',
	function () {
		$script = cradle_ctg_script();
		$tmp    = cradle_ctg_tmp();
		$ok     = cradle_ctg_run( $script, array( 'new', '--slug=acme-site', '--name=Acme', '--target=' . $tmp ) );
		assert_same( 0, $ok['code'], 'First run succeeds. ' . $ok['err'] );
		file_put_contents( $tmp . '/acme-site/northstar.md', "# Acme brief written by the site owner\n" );

		$again = cradle_ctg_run( $script, array( 'new', '--slug=acme-site', '--name=Other', '--target=' . $tmp ) );
		assert_same( 1, $again['code'], 'Existing child folder is refused.' );
		assert_contains( 'Refusing to overwrite', $again['err'] );
		assert_same( "# Acme brief written by the site owner\n", file_get_contents( $tmp . '/acme-site/northstar.md' ), 'Existing brief unchanged.' );

		$cases = array(
			'reserved package slug' => array( '--slug=cradle-content', '--name=X' ),
			'parent slug'           => array( '--slug=cradle', '--name=X' ),
			'invalid slug'          => array( '--slug=Bad Slug', '--name=X' ),
			'path traversal slug'   => array( '--slug=../escape', '--name=X' ),
			'missing name'          => array( '--slug=good-slug' ),
			'markup in name'        => array( '--slug=good-slug', '--name=<b>X</b>' ),
			'invalid site id'       => array( '--slug=good-slug', '--name=X', '--site-id=Bad ID' ),
		);
		foreach ( $cases as $label => $args ) {
			$run = cradle_ctg_run( $script, array_merge( array( 'new' ), $args, array( '--target=' . $tmp ) ) );
			assert_same( 1, $run['code'], "Refused: {$label}." );
		}
		assert_same( array( 'acme-site' ), array_values( array_diff( scandir( $tmp ), array( '.', '..' ) ) ), 'Refused runs created nothing.' );
		assert_same( 1, cradle_ctg_run( $script, array( 'bogus' ) )['code'], 'Unknown command prints usage and fails.' );
	}
);

cradle_test(
	'child theme generator: check lists and adds missing starter files without changing existing ones',
	function () {
		$script = cradle_ctg_script();
		$tmp    = cradle_ctg_tmp();
		$run    = cradle_ctg_run( $script, array( 'new', '--slug=acme-check', '--name=Acme Check', '--target=' . $tmp ) );
		assert_same( 0, $run['code'], $run['err'] );
		$child = $tmp . '/acme-check';

		$clean = cradle_ctg_run( $script, array( 'check', '--child=' . $child ) );
		assert_same( 0, $clean['code'], 'A fresh child is complete.' );

		cradle_ctg_rmdir( $child . '/docs' );
		file_put_contents( $child . '/northstar.md', "# Edited brief\n" );
		file_put_contents( $child . '/theme.json', "{ \"version\": 3 }\n" );
		file_put_contents( $child . '/functions.php', "<?php\n// Site code added later.\n" );

		$list = cradle_ctg_run( $script, array( 'check', '--child=' . $child ) );
		assert_same( 2, $list['code'], 'Missing files make check exit 2.' );
		assert_contains( 'missing docs/decisions.md', $list['out'] );
		assert_contains( 'missing docs/verification.md', $list['out'] );
		assert_not_contains( 'northstar.md', $list['out'], 'Edited files are not reported.' );
		assert_false( is_file( $child . '/docs/decisions.md' ), 'Listing does not write.' );

		$add = cradle_ctg_run( $script, array( 'check', '--child=' . $child, '--add-missing' ) );
		assert_same( 0, $add['code'], $add['err'] );
		assert_contains( 'added   docs/decisions.md', $add['out'] );
		assert_true( is_file( $child . '/docs/project-state.md' ), 'Missing folder restored.' );
		assert_not_contains( '{{', (string) file_get_contents( $child . '/docs/decisions.md' ), 'Restored file has placeholders filled.' );
		assert_same( "# Edited brief\n", file_get_contents( $child . '/northstar.md' ), 'Brief unchanged.' );
		assert_same( "{ \"version\": 3 }\n", file_get_contents( $child . '/theme.json' ), 'theme.json unchanged.' );
		assert_same( "<?php\n// Site code added later.\n", file_get_contents( $child . '/functions.php' ), 'Site-added functions.php untouched.' );
		assert_same( 0, cradle_ctg_run( $script, array( 'check', '--child=' . $child ) )['code'], 'Complete after adding.' );

		mkdir( $tmp . '/other-theme' );
		file_put_contents( $tmp . '/other-theme/style.css', "/*\nTheme Name: Other\nTemplate: twentytwentyfive\n*/\n" );
		$foreign = cradle_ctg_run( $script, array( 'check', '--child=' . $tmp . '/other-theme', '--add-missing' ) );
		assert_same( 1, $foreign['code'], 'A child of another parent is refused.' );
		assert_same( array( 'style.css' ), cradle_ctg_files( $tmp . '/other-theme' ), 'Nothing written into a foreign theme.' );
	}
);

cradle_test(
	'child theme generator: from the repository source it writes sites/<slug>/themes with resolvable source paths',
	function () {
		$repo   = wp_normalize_path( dirname( ABSPATH ) );
		$script = $repo . '/packages/themes/cradle/bin/child-theme.php';
		if ( ! is_file( $script ) || ! is_file( $repo . '/scripts/sync.php' ) ) {
			skip_test( 'The sandbox is not inside the Cradle repository.' );
		}
		$site = $repo . '/sites/cradle-test-generator';
		if ( file_exists( $site ) ) {
			throw new Cradle_Test_Failure( "{$site} already exists; not touching it." );
		}
		cradle_ctg_track( $site );
		$run = cradle_ctg_run( $script, array( 'new', '--slug=cradle-test-generator', '--name=Generator Test' ) );
		assert_same( 0, $run['code'], $run['err'] );
		$child     = $site . '/themes/cradle-test-generator';
		$workspace = json_decode( (string) file_get_contents( $child . '/cradle-workspace.json' ), true );
		assert_true( is_array( $workspace['sourceLayout'] ?? null ), 'Repository layout recorded.' );
		foreach ( array(
			'parentTheme' => 'packages/themes/cradle',
			'corePlugin'  => 'packages/plugins/cradle',
			'skillIndex'  => 'skills/INDEX.md',
			'sandbox'     => 'wp',
		) as $key => $target ) {
			assert_same( realpath( $repo . '/' . $target ), realpath( $child . '/' . $workspace['sourceLayout'][ $key ] ), "sourceLayout.{$key} resolves." );
		}
		assert_false( isset( $workspace['sourceLayout']['sitePlugin'] ), 'No site plugin path.' );
		assert_same( array( 'themes' ), array_values( array_diff( scandir( $site ), array( '.', '..' ) ) ), 'Only a themes folder is created for the site.' );
		cradle_ctg_rmdir( $site );
	}
);

cradle_test(
	'child theme generator: not runnable over the web and the starter is not listed as a theme',
	function () {
		cradle_ctg_script();
		$marker = get_theme_root() . '/cradle-web-generator';
		$url    = get_template_directory_uri() . '/bin/child-theme.php?new+--slug=cradle-web-generator+--name=Web';
		$res    = cradle_test_http( 'GET', $url );
		assert_false( is_wp_error( $res ), 'Request completed.' );
		assert_same( '', trim( (string) wp_remote_retrieve_body( $res ) ), 'No output over the web.' );
		assert_false( file_exists( $marker ), 'Nothing created over the web.' );

		wp_clean_themes_cache();
		foreach ( wp_get_themes( array( 'errors' => null ) ) as $slug => $theme ) {
			assert_not_contains( 'starter', (string) $slug, 'The starter inside the parent is not detected as a theme.' );
			assert_not_contains( '{{', (string) $theme->get( 'Name' ), 'No placeholder theme listed.' );
		}
	}
);

cradle_test(
	'child theme contract: the parent registers child components (as blocks) and loads child styles and script',
	function () {
		if ( ! function_exists( 'cradle' ) || ! cradle() || ! cradle()->modules()->is_ready( 'blocks' ) ) {
			skip_test( 'Cradle core and Cradle Blocks must be active.' );
		}
		if ( get_stylesheet() === get_template() ) {
			skip_test( 'No child theme is active.' );
		}
		$child    = get_stylesheet_directory();
		$slug     = 'cradle-test-note';
		$id       = get_stylesheet() . '/' . $slug;
		$callback = 'cradle_theme_register_child_components';
		assert_true( false !== has_action( 'cradle/booted', $callback ), 'The parent theme registers child components on cradle/booted.' );

		// Track every folder this test creates (outermost first) so cleanup leaves the child as it was.
		foreach ( array( 'components', 'components/' . $slug, 'blocks', 'blocks/' . $slug, 'assets', 'assets/js' ) as $rel ) {
			if ( ! is_dir( $child . '/' . $rel ) ) {
				cradle_ctg_track( $child . '/' . $rel );
			}
		}
		$component_dir = $child . '/components/' . $slug;
		$block_dir     = $child . '/blocks/' . $slug;
		$script_file   = $child . '/assets/js/site.js';
		if ( file_exists( $script_file ) ) {
			skip_test( 'The active child already has assets/js/site.js; not overwriting it.' );
		}
		wp_mkdir_p( $component_dir );
		wp_mkdir_p( $block_dir );
		wp_mkdir_p( dirname( $script_file ) );
		file_put_contents( $script_file, "document.documentElement.dataset.cradleChildScript = '1';
" );
		file_put_contents(
			$component_dir . '/component.json',
			wp_json_encode(
				array(
					'schemaVersion'  => 1,
					'id'             => $id,
					'version'        => '1.0.0',
					'owner'          => get_stylesheet(),
					'title'          => 'Test note',
					'properties'     => array(
						'text' => array(
							'type'      => 'string',
							'default'   => '',
							'maxLength' => 200,
						),
					),
					'adapters'       => array( 'gutenberg' => array( 'blockName' => $id ) ),
					'renderContract' => 'cradle-test-note-v1',
					'fallbackPolicy' => 'none',
					'cache'          => 'none',
				)
			)
		);
		file_put_contents( $component_dir . '/render.php', "<?php\ndefined( 'ABSPATH' ) || exit;\n?><p class=\"cradle-test-note\"><?php echo esc_html( \$props['text'] ); ?></p>\n" );
		file_put_contents(
			$block_dir . '/block.json',
			wp_json_encode(
				array(
					'apiVersion' => 3,
					'name'       => $id,
					'title'      => 'Test note',
					'category'   => 'text',
					'attributes' => array(
						'text' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				)
			)
		);

		// In this process `cradle/booted` already ran; call the child's registration directly.
		call_user_func( $callback, cradle() );
		assert_true( is_array( cradle()->components()->get( $id ) ), 'Component registered from the child theme.' );
		assert_same( '<p class="cradle-test-note">Hello &amp; welcome</p>', trim( cradle()->components()->render( $id, array( 'text' => 'Hello & welcome' ) ) ), 'Rendered by the child render.php.' );
		assert_true( WP_Block_Type_Registry::get_instance()->is_registered( $id ), 'Gutenberg block registered from blocks/<slug>.' );

		// Outside the plugin directory and the active theme a descriptor is refused.
		$outside = cradle_ctg_tmp();
		wp_mkdir_p( $outside );
		file_put_contents( $outside . '/component.json', (string) file_get_contents( $component_dir . '/component.json' ) );
		assert_wp_error( cradle()->components()->register_from_file( $outside . '/component.json', array( 'render' => '__return_empty_string' ) ), 'cradle_component_path' );

		// A real request: boot → child registration → block render. The attribute carries markup (as an
		// editor with unfiltered_html could save it), so kses is bypassed for the fixture insert only.
		kses_remove_filters();
		$page = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Cradle child component fixture',
					'post_content' => '<!-- wp:' . $id . ' ' . serialize_block_attributes( array( 'text' => 'Child & <script>alert(1)</script> component' ) ) . ' /-->',
				)
			),
			true
		);
		kses_init();
		assert_false( is_wp_error( $page ), 'Fixture page created.' );
		update_post_meta( $page, '_cradle_test_fixture', 'child-theme' );
		try {
			$res  = cradle_test_http( 'GET', get_permalink( $page ) );
			$body = (string) wp_remote_retrieve_body( $res );
			assert_same( 200, wp_remote_retrieve_response_code( $res ), 'Fixture page loads.' );
			assert_true( (bool) preg_match( '#<p class="cradle-test-note[^"]*">(.*?)</p>#s', $body, $para ), 'Child component rendered on the front end.' );
			assert_contains( 'Child &amp;', $para[1], 'Block text escaped.' );
			assert_not_contains( 'alert(1)', $para[1], 'Markup in a string property is stripped.' );
			assert_not_contains( '<script>alert(1)', $body, 'No raw script from block attributes.' );

			// Child assets are loaded by the parent, each once, in the documented order.
			$child_uri = wp_make_link_relative( get_stylesheet_directory_uri() );
			if ( is_file( $child . '/assets/css/design.css' ) ) {
				assert_same( 1, substr_count( $body, $child_uri . '/assets/css/design.css' ), 'Child design.css loaded once.' );
				assert_true( strpos( $body, "id='cradle-base-css'" ) < strpos( $body, "id='cradle-child-design-css'" ), 'Child design styles load after cradle-base.' );
			}
			assert_true( (bool) preg_match( "#<script[^>]+src=['\"][^'\"]*" . preg_quote( $child_uri . '/assets/js/site.js', '#' ) . "[^'\"]*['\"][^>]*id=['\"]cradle-child-site-js['\"][^>]*>#", $body, $tag ) || (bool) preg_match( "#<script[^>]*id=['\"]cradle-child-site-js['\"][^>]*>#", $body, $tag ), 'Child site.js enqueued as cradle-child-site.' );
			assert_contains( 'defer', $tag[0], 'Child script is deferred.' );
		} finally {
			wp_delete_post( $page, true );
			if ( is_file( $script_file ) ) {
				unlink( $script_file );
			}
			cradle_ctg_cleanup_all();
		}
	}
);
