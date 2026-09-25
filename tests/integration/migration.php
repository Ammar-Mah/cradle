<?php
/**
 * Cradle Migration (northstar §15, CR-18, CR-27).
 *
 * Source: the shared sandbox (wp/, cw_). It is only EXPORTED; fixtures created here
 * are removed right after the export. Target: the disposable independent WordPress in
 * wp-target/ (URL /cradle/wp-target, database cradle_wp_target, prefix ct_), reinstalled
 * fresh by this suite (tests/fixtures/migration/target.php). Restores never touch wp/.
 *
 * The main restore runs through the browser runner over real HTTP (chunked upload,
 * core job-step endpoint, approval, cutover with the job-scoped recovery token); the
 * §15.10 failure cases run through the package CLI in separate processes.
 */

use Cradle\Migration\Archives;
use Cradle\Migration\Exporter;
use Cradle\Migration\Importer;
use Cradle\Migration\Manifest;
use Cradle\Migration\Path_Guard;
use Cradle\Migration\Replacer;
use Cradle\Migration\Serialized;
use Cradle\Migration\Transformer;

require_once CRADLE_TEST_ROOT . '/tests/fixtures/migration/craft.php';

const CM_T_URL = 'http://localhost/cradle/wp-target';

$GLOBALS['cm'] = array(
	'archive'  => null,  // array( id, dir, manifest path )
	'job'      => null,  // main import job uuid (target)
	'session'  => null,  // target_admin session
	'tmp'      => sys_get_temp_dir() . '/cradle-mig-' . strtolower( wp_generate_password( 8, false ) ),
	'fixtures' => array(),
	'recover'  => null,  // job used for crash recovery + rollback
);

register_shutdown_function(
	static function () {
		// Crafted archive copies contain source password hashes: never leave them behind.
		if ( is_dir( $GLOBALS['cm']['tmp'] ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $GLOBALS['cm']['tmp'], FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $it as $item ) {
				$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
			}
			rmdir( $GLOBALS['cm']['tmp'] );
		}
	}
);

/* ------------------------------------------------------------------ */
/* Helpers                                                            */
/* ------------------------------------------------------------------ */

function cm_exec( array $cmd, int $timeout = 900 ): array {
	$spec = array(
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$proc = proc_open( $cmd, $spec, $pipes, CRADLE_TEST_ROOT, null, array( 'bypass_shell' => true ) );
	if ( ! is_resource( $proc ) ) {
		throw new Cradle_Test_Failure( 'Could not start ' . implode( ' ', $cmd ) );
	}
	$out  = stream_get_contents( $pipes[1] );
	$err  = stream_get_contents( $pipes[2] );
	$code = proc_close( $proc );
	$lines = array_values( array_filter( explode( "\n", trim( (string) $out ) ) ) );
	$json  = json_decode( (string) end( $lines ), true );
	return array(
		'code'   => $code,
		'json'   => is_array( $json ) ? $json : null,
		'stdout' => (string) $out,
		'stderr' => (string) $err,
	);
}

function cm_target( string ...$args ): array {
	$r = cm_exec( array_merge( array( PHP_BINARY, CRADLE_TEST_ROOT . '/tests/fixtures/migration/target.php' ), $args ) );
	if ( null === $r['json'] ) {
		throw new Cradle_Test_Failure( 'target.php ' . implode( ' ', $args ) . ' gave no JSON: ' . substr( $r['stdout'] . $r['stderr'], 0, 800 ) );
	}
	return $r['json'];
}

function cm_cli( string ...$args ): array {
	return cm_exec( array_merge( array( PHP_BINARY, CRADLE_TEST_ROOT . '/wp-target/wp-content/plugins/cradle-migration/bin/cradle-migrate.php', '--path=' . CRADLE_TEST_ROOT . '/wp-target', '--json' ), $args ) );
}

function cm_session( string $login ): array {
	$s = cm_target( 'cookies', $login );
	if ( isset( $s['error'] ) ) {
		throw new Cradle_Test_Failure( 'No target session for ' . $login . ': ' . $s['error'] );
	}
	$s['jar'] = array_map( static fn( $c ) => new WP_Http_Cookie( array( 'name' => $c['name'], 'value' => $c['value'] ) ), $s['cookies'] );
	return $s;
}

function cm_http( string $method, string $url, ?array $session = null, array $args = array() ) {
	return wp_remote_request(
		str_starts_with( $url, 'http' ) ? $url : CM_T_URL . $url,
		array_merge(
			array(
				'method'      => $method,
				'timeout'     => 120,
				'redirection' => 0,
				'cookies'     => $session ? $session['jar'] : array(),
			),
			$args
		)
	);
}

function cm_ajax( ?array $session, array $fields, ?string $file = null ) {
	if ( null === $file ) {
		return cm_http( 'POST', '/wp-admin/admin-ajax.php', $session, array( 'body' => $fields ) );
	}
	$boundary = 'cradle' . wp_generate_password( 16, false );
	$body     = '';
	foreach ( $fields as $k => $v ) {
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n";
	}
	$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"chunk\"; filename=\"blob\"\r\nContent-Type: application/octet-stream\r\n\r\n" . $file . "\r\n--{$boundary}--\r\n";
	return cm_http( 'POST', '/wp-admin/admin-ajax.php', $session, array( 'body' => $body, 'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ) ) );
}

function cm_json( $response ): array {
	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	return is_array( $data ) ? $data : array( 'success' => false, 'data' => array( 'message' => 'HTTP ' . wp_remote_retrieve_response_code( $response ) . ': ' . substr( (string) wp_remote_retrieve_body( $response ), 0, 300 ) ) );
}

function cm_step_until( array $session, string $uuid, array $stop, int $max = 300 ): array {
	for ( $i = 0; $i < $max; $i++ ) {
		$r = cm_json( cm_ajax( $session, array( 'action' => 'cradle_job_step', 'nonce' => $session['nonces']['cradle_jobs'], 'job' => $uuid ) ) );
		if ( empty( $r['success'] ) ) {
			throw new Cradle_Test_Failure( 'Job step failed: ' . wp_json_encode( $r['data'] ) );
		}
		if ( $r['data']['terminal'] || in_array( $r['data']['state'], $stop, true ) ) {
			return $r['data'];
		}
	}
	throw new Cradle_Test_Failure( 'Job did not reach ' . implode( '/', $stop ) );
}

function cm_src_url(): string {
	return untrailingslashit( (string) get_option( 'home' ) );
}

function cm_require_archive(): array {
	if ( empty( $GLOBALS['cm']['archive'] ) ) {
		skip_test( 'The source export did not run.' );
	}
	return $GLOBALS['cm']['archive'];
}

function cm_require_restore(): string {
	if ( empty( $GLOBALS['cm']['restored'] ) ) {
		skip_test( 'The main restore did not complete.' );
	}
	return (string) $GLOBALS['cm']['job'];
}

/**
 * Creates a directory link that PHP may not recognise as a symlink (Windows junction) or a POSIX symlink.
 */
function cm_make_link( string $link, string $target ): bool {
	if ( '\\' === DIRECTORY_SEPARATOR ) {
		exec( 'cmd /c mklink /J ' . escapeshellarg( str_replace( '/', '\\', $link ) ) . ' ' . escapeshellarg( str_replace( '/', '\\', $target ) ) . ' 2>&1', $out, $code );
		return 0 === $code;
	}
	return @symlink( $target, $link ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

function cm_remove_link( string $link ) {
	if ( '\\' === DIRECTORY_SEPARATOR ) {
		exec( 'cmd /c rmdir ' . escapeshellarg( str_replace( '/', '\\', $link ) ) . ' 2>&1' );
	} elseif ( is_link( $link ) ) {
		unlink( $link );
	}
}

function cm_open_import(): string {
	foreach ( (array) ( cm_cli( 'status' )['json']['jobs'] ?? array() ) as $job ) {
		if ( 'migration/import' === $job['type'] && ! $job['terminal'] ) {
			return (string) $job['uuid'];
		}
	}
	return '';
}

function cm_blocking( array $r ): string {
	return implode( ' | ', (array) ( $r['json']['report']['blocking'] ?? array() ) ) . ' ' . (string) ( $r['json']['error'] ?? '' ) . ' ' . $r['stderr'];
}

/* ------------------------------------------------------------------ */
/* Unit-level behavior of the transformation and validation engine    */
/* ------------------------------------------------------------------ */

cradle_test(
	'migration: bounded serialized parser round-trips and rejects malformed or deep input',
	function () {
		assert_true( is_plugin_active( 'cradle-migration/cradle-migration.php' ), 'Cradle Migration must be active in the source sandbox.' );
		$value = array(
			'a'   => array( 'b' => 'x";}s:1:"y', 'n' => null, 't' => true, 'f' => 1.5, 'i' => -7 ),
			'u'   => 'Grüße ✓',
			7     => serialize( array( 'inner' => 'deep' ) ),
		);
		$s     = serialize( $value );
		$node  = Serialized::parse( $s );
		assert_true( is_array( $node ), 'Valid serialized data parses.' );
		assert_same( $s, Serialized::write( $node ), 'Writer is byte-exact.' );
		assert_same( null, Serialized::parse( substr( $s, 0, -1 ) ), 'Truncated data is rejected.' );
		assert_same( null, Serialized::parse( $s . 'x' ), 'Trailing bytes are rejected.' );
		assert_same( null, Serialized::parse( 's:10:"short";' ), 'Wrong string length is rejected.' );
		assert_same( null, Serialized::parse( 'a:99999999:{}' ), 'Absurd element counts are rejected before allocation.' );
		$obj = Serialized::parse( 'a:1:{i:0;O:8:"stdClass":1:{s:1:"p";s:1:"v";}}' );
		assert_same( 'opaque', $obj['items'][0][1]['t'], 'Objects are opaque nodes, never instantiated.' );
		$deep = str_repeat( 'a:1:{i:0;', 70 ) . 'N;' . str_repeat( '}', 70 );
		assert_same( 'too_deep', Serialized::inspect( $deep )['status'], 'Nesting beyond the limit is reported.' );
	}
);

cradle_test(
	'migration: URL/path replacement is boundary-aware, JSON/serialization-aware and never rewrites objects',
	function () {
		$src = 'http://localhost/cradle/wp';
		$dst = 'http://localhost/cradle/wp-target';
		$r   = new Replacer( array( array( $src, $dst ) ), array( array( 'C:/wamp64/www/cradle/wp', 'C:/wamp64/www/cradle/wp-target' ) ) );
		assert_same( $dst . '/a/', $r->transform( $src . '/a/' ) );
		assert_same( $dst, $r->transform( $src ), 'No trailing slash.' );
		assert_same( 'https://localhost/cradle/wp-target/s', $r->transform( 'https://localhost/cradle/wp/s' ), 'Scheme variant keeps its scheme.' );
		assert_same( 'http://localhost/cradle/wpx/', $r->transform( 'http://localhost/cradle/wpx/' ), 'Look-alike prefix is untouched.' );
		assert_same( 'http://example.com/cradle/wp/', $r->transform( 'http://example.com/cradle/wp/' ), 'Other hosts are untouched.' );
		assert_same( '{"u":"http:\/\/localhost\/cradle\/wp-target\/j\/"}', $r->transform( '{"u":"http:\/\/localhost\/cradle\/wp\/j\/"}' ), 'Escaped JSON URL.' );
		assert_same( rawurlencode( $dst . '/q' ), $r->transform( rawurlencode( $src . '/q' ) ), 'URL-encoded form.' );
		assert_same( '<!-- wp:image {"url":"' . $dst . '/wp-content/uploads/a.jpg"} -->', $r->transform( '<!-- wp:image {"url":"' . $src . '/wp-content/uploads/a.jpg"} -->' ), 'Block attribute.' );
		assert_same( 'C:\\wamp64\\www\\cradle\\wp-target\\wp-content', $r->transform( 'C:\\wamp64\\www\\cradle\\wp\\wp-content' ), 'Backslash path.' );
		assert_same( '"C:\\\\wamp64\\\\www\\\\cradle\\\\wp-target\\\\x"', $r->transform( '"C:\\\\wamp64\\\\www\\\\cradle\\\\wp\\\\x"' ), 'JSON-escaped backslash path.' );
		$data = array(
			'url'    => $src . '/about/',
			'nested' => array( 'deep' => array( 'deeper' => $src . '/d/' ) ),
			'ser'    => serialize( array( 'x' => $src . '/s/' ) ),
			'uni'    => 'Grüße → ' . $src . '/ü/',
			'delim'  => 'a";s:5:"evil";}' . $src,
			'n'      => 42,
		);
		$out  = $r->transform( serialize( $data ) );
		$back = unserialize( $out, array( 'allowed_classes' => false ) ); // phpcs:ignore -- test-owned fixture data.
		assert_true( is_array( $back ), 'Rewritten serialized value keeps valid lengths.' );
		assert_same( $dst . '/about/', $back['url'] );
		assert_same( $dst . '/d/', $back['nested']['deep']['deeper'] );
		assert_same( array( 'x' => $dst . '/s/' ), unserialize( $back['ser'], array( 'allowed_classes' => false ) ), 'Nested serialized string rewritten.' ); // phpcs:ignore
		assert_same( 'Grüße → ' . $dst . '/ü/', $back['uni'] );
		assert_same( 'a";s:5:"evil";}' . $dst, $back['delim'] );
		$object = 'a:2:{s:1:"o";O:8:"stdClass":1:{s:3:"url";s:' . strlen( $src . '/o/' ) . ':"' . $src . '/o/";}s:1:"p";s:5:"plain";}';
		assert_same( $object, $r->transform( $object ), 'An object that needs rewriting is left unchanged…' );
		assert_same( 'serialized_object', $r->issues()[0]['code'] ?? '', '…and reported as a blocking issue.' );
		$safe = 'a:2:{s:1:"o";O:8:"stdClass":1:{s:1:"k";s:1:"v";}s:1:"u";s:' . strlen( $src . '/x' ) . ':"' . $src . '/x";}';
		$new  = $r->transform( $safe );
		assert_contains( 'O:8:"stdClass":1:{s:1:"k";s:1:"v";}', $new, 'An object needing no change is preserved byte-for-byte.' );
		assert_contains( $dst . '/x', $new );
		$tf  = new Transformer(
			array( 'homeUrl' => $src, 'siteUrl' => $src, 'uploadsUrl' => $src . '/wp-content/uploads', 'abspath' => 'C:/a/', 'contentDir' => 'C:/a/wp-content', 'uploadsDir' => 'C:/a/wp-content/uploads', 'tablePrefix' => 'cw_' ),
			array( 'home' => $dst, 'siteurl' => $dst, 'uploadsUrl' => $dst . '/wp-content/uploads', 'abspath' => 'C:/b/', 'contentDir' => 'C:/b/wp-content', 'uploadsDir' => 'C:/b/wp-content/uploads', 'prefix' => 'ct_' )
		);
		$cols = array( array( 'name' => 'umeta_id', 'text' => false ), array( 'name' => 'meta_key', 'text' => true ), array( 'name' => 'meta_value', 'text' => true ) );
		assert_same( 'ct_capabilities', $tf->row( 'usermeta', $cols, array( '1', 'cw_capabilities', 'a:0:{}' ) )['row'][1], 'Per-site user meta keys follow the prefix change.' );
		$pcols = array( array( 'name' => 'ID', 'text' => false ), array( 'name' => 'post_content', 'text' => true ), array( 'name' => 'guid', 'text' => true ) );
		$prow  = $tf->row( 'posts', $pcols, array( '5', $src . '/p/', $src . '/?p=5' ) )['row'];
		assert_same( array( '5', $dst . '/p/', $src . '/?p=5' ), $prow, 'Content rewritten, GUID untouched.' );
	}
);

cradle_test(
	'migration: archive path guard rejects traversal, absolute, device, reserved and conflicting paths',
	function () {
		foreach ( array( '../x', 'a/../../x', '/etc/passwd', 'C:/Windows/win.ini', 'a\\b', "a\0b", 'a/./b', 'a//b', 'con.txt', 'x/NUL', 'a/b.', 'a/b ', '~root', str_repeat( 'd/', 40 ) . 'f' ) as $bad ) {
			assert_wp_error( Path_Guard::validate_relative( $bad ), 'cradle_migration_path', 'Rejected: ' . addcslashes( $bad, "\0" ) );
		}
		assert_true( true === Path_Guard::validate_relative( '2026/09/photo-1.jpg' ) );
		assert_same( 2, count( Path_Guard::conflicts( array( 'uploads/A.txt', 'uploads/a.txt', 'uploads/x', 'uploads/x/y' ) ) ), 'Case duplicates and file/dir conflicts are found.' );
		assert_true( Path_Guard::is_executable_name( 'x/shell.php' ) && Path_Guard::is_executable_name( 'x/a.php.jpg' ) && ! Path_Guard::is_executable_name( 'x/a.jpg' ) );
	}
);

/* ------------------------------------------------------------------ */
/* Independent target, source export                                  */
/* ------------------------------------------------------------------ */

cradle_test(
	'migration: disposable target is a fresh, independent WordPress with a different URL, path and prefix',
	function () {
		$r = cm_target( 'install', '--fresh' );
		assert_true( ! empty( $r['installed'] ), 'Target install: ' . wp_json_encode( $r ) );
		assert_same( 'protected', $r['prepare']['storage'] ?? '', 'Target private storage must be proven protected.' );
		$info = cm_target( 'info' );
		assert_same( CM_T_URL, $info['home'] );
		assert_same( 'ct_', $info['prefix'] );
		assert_same( 'original-target', $info['marker'] );
		assert_true( $info['uploadsTargetOnly'] );
		global $wpdb;
		assert_same( 'cw_', $wpdb->prefix, 'Source keeps its own prefix.' );
		assert_true( CM_T_URL !== cm_src_url() && 'C:/wamp64/www/cradle/wp-target/' !== wp_normalize_path( ABSPATH ) );
		assert_same( 200, wp_remote_retrieve_response_code( cm_http( 'GET', '/' ) ) );
	}
);

cradle_test(
	'migration: full export of the source sandbox (DB, uploads, active themes and plugins) with fixtures',
	function () {
		$admin = cradle_test_user( 'administrator' );
		wp_set_current_user( $admin );
		$src = cm_src_url();
		$fx  = &$GLOBALS['cm']['fixtures'];
		$tag = strtolower( wp_generate_password( 6, false ) );
		try {
			// A real attachment file.
			$png  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' );
			$bits = wp_upload_bits( 'cradle-mig-fixture-' . $tag . '.png', null, $png );
			assert_true( empty( $bits['error'] ), 'Upload fixture: ' . $bits['error'] );
			$att                 = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Cradle Migration Fixture Image ' . $tag, 'post_status' => 'inherit' ), $bits['file'] );
			$fx['attachment']    = $att;
			$fx['file_url']      = $bits['url'];
			$fx['file_rel']      = substr( wp_normalize_path( $bits['file'] ), strlen( wp_normalize_path( wp_upload_dir()['basedir'] ) ) + 1 );
			$fx['png']           = $png;
			wp_update_attachment_metadata( $att, array( 'width' => 1, 'height' => 1, 'file' => $fx['file_rel'] ) );
			$term                = wp_insert_term( 'Cradle Migration Fixture Cat ' . $tag, 'category' );
			$fx['term']          = is_wp_error( $term ) ? 0 : (int) $term['term_id'];
			$fx['post_title']    = 'Cradle Migration Fixture Post ' . $tag;
			$post                = wp_insert_post(
				wp_slash(
					array(
						'post_type'     => 'post',
						'post_status'   => 'publish',
						'post_title'    => $fx['post_title'],
						'post_name'     => 'cradle-mig-fixture-post-' . $tag,
						'post_category' => array( $fx['term'] ),
						'post_content'  => "<!-- wp:paragraph -->\n<p>Escaped JSON: <code>{\"url\":\"http:\\/\\/localhost\\/cradle\\/wp\\/wp-content\\/uploads\\/escaped.png\"}</code></p>\n<!-- /wp:paragraph -->",
					)
				)
			);
			$fx['post']          = $post;
			update_post_meta( $post, '_cradle_mig_nested', array( 'level1' => array( 'url' => $src . '/a/', 'inner' => serialize( array( 'deep' => $src . '/b/' ) ) ), 'list' => array( $src, 'plain' ) ) );
			update_post_meta( $post, '_cradle_mig_json', wp_slash( wp_json_encode( array( 'url' => $src . '/j/' ) ) ) );
			$fx['page_title']    = 'Cradle Migration Fixture Page ' . $tag;
			$fx['page']          = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_title'   => $fx['page_title'],
						'post_name'    => 'cradle-mig-fixture-page-' . $tag,
						'post_content' => '<!-- wp:image {"id":' . $att . ',"sizeSlug":"full","url":"' . $bits['url'] . '"} -->' . "\n" . '<figure class="wp-block-image size-full"><img src="' . esc_url( $bits['url'] ) . '" alt="" class="wp-image-' . $att . '"/></figure>' . "\n<!-- /wp:image -->\n\n<!-- wp:paragraph -->\n<p>See <a href=\"" . esc_url( $src . '/cradle-mig-fixture-post-' . $tag . '/' ) . "\">the post</a> and <a href=\"http://localhost/cradle/wpx/\">a look-alike</a>.</p>\n<!-- /wp:paragraph -->",
					)
				)
			);
			$fx['option'] = array(
				'url'        => $src . '/about/',
				'noslash'    => $src,
				'https'      => 'https://localhost/cradle/wp/secure/',
				'json'       => wp_json_encode( array( 'u' => $src . '/j/' ) ),
				'nested'     => array( 'deep' => array( 'deeper' => $src . '/d/' ) ),
				'serialized' => serialize( array( 'x' => $src . '/s/' ) ),
				'path'       => wp_normalize_path( ABSPATH ) . 'wp-content/uploads',
				'winpath'    => str_replace( '/', '\\', wp_normalize_path( ABSPATH ) ) . 'wp-content',
				'unicode'    => 'Grüße → ' . $src . '/ü/',
				'delims'     => 'a";s:5:"evil";}' . $src,
				'lookalike'  => 'http://localhost/cradle/wpx/not-me',
				'other_site' => 'http://example.com/cradle/wp/',
				'int'        => 42,
				'bool'       => true,
				'null'       => null,
			);
			update_option( 'cradle_mig_fixture', $fx['option'], false );

			$job = Exporter::create( array( 'partSize' => 1048576, 'themes' => 'active', 'plugins' => 'active' ) );
			assert_true( is_array( $job ), 'Export job: ' . ( is_wp_error( $job ) ? $job->get_error_message() : '' ) );
			$GLOBALS['cm']['export_job'] = $job['uuid'];
			$n                           = 0;
			do {
				$job = cradle()->jobs()->run( $job['uuid'] );
			} while ( ! in_array( $job['state'], Cradle\Core\Jobs::TERMINAL_STATES, true ) && ++$n < 500 );
			assert_same( 'completed', $job['state'], 'Export: ' . $job['error_message'] );
			$archive = $job['payload']['archive'];
			$dir     = Cradle\Migration\Workspace::resolve_rel( $archive['location'] );
			$path    = $dir . '/' . Manifest::manifest_name( $archive['id'] );
			$m       = json_decode( (string) file_get_contents( $path ), true );
			assert_same( hash_file( 'sha256', $path ), $job['manifest_digest'], 'Job stores the verified manifest digest.' );
			assert_true( count( $m['parts'] ) >= 2, 'Archive is split into bounded parts.' );
			foreach ( $m['parts'] as $p ) {
				assert_same( $p['sha256'], hash_file( 'sha256', $dir . '/' . $p['name'] ), 'Part checksum.' );
			}
			assert_same( 'cw_', $m['source']['tablePrefix'] );
			assert_same( $src, $m['source']['homeUrl'] );
			$paths = array_map( static fn( $f ) => $f['category'] . '/' . $f['path'], $m['files'] );
			assert_true( in_array( 'uploads/' . $fx['file_rel'], $paths, true ), 'Uploads are archived: ' . $fx['file_rel'] . ' ' . wp_json_encode( array_slice( array_values( array_filter( $paths, static fn( $p ) => str_starts_with( $p, 'uploads/' ) ) ), 0, 5 ) ) );
			assert_true( in_array( 'themes/' . get_stylesheet() . '/style.css', $paths, true ), 'Active child theme is archived.' );
			assert_true( in_array( 'themes/' . get_template() . '/style.css', $paths, true ), 'Parent theme is archived.' );
			assert_true( in_array( 'plugins/cradle/cradle.php', $paths, true ), 'Active plugins are archived.' );
			foreach ( $paths as $p ) {
				assert_true( false === stripos( $p, 'cradle-private-' ) && ! str_ends_with( $p, 'wp-config.php' ) && false === strpos( $p, '/.git/' ), 'Excluded path archived: ' . $p );
			}
			$names = array_column( $m['database']['tables'], 'name' );
			foreach ( array( 'posts', 'options', 'users', 'usermeta', 'cradle_jobs' ) as $t ) {
				assert_true( in_array( $t, $names, true ), 'Table ' . $t . ' is described.' );
			}
			assert_contains( 'private storage', wp_json_encode( $m['exclusions'] ), 'Exclusions are listed.' );
			assert_true( isset( $m['unsupported'], $m['notMigrated'], $m['consistency']['strategy'] ) );
			$GLOBALS['cm']['archive'] = array(
				'id'       => $archive['id'],
				'dir'      => $dir,
				'manifest' => $path,
			);
		} finally {
			// The source sandbox is shared: remove every fixture immediately after the export.
			if ( ! empty( $fx['page'] ) ) {
				wp_delete_post( $fx['page'], true );
			}
			if ( ! empty( $fx['post'] ) ) {
				wp_delete_post( $fx['post'], true );
			}
			if ( ! empty( $fx['attachment'] ) ) {
				wp_delete_attachment( $fx['attachment'], true );
			}
			if ( ! empty( $fx['term'] ) ) {
				wp_delete_term( $fx['term'], 'category' );
			}
			delete_option( 'cradle_mig_fixture' );
		}
	}
);

cradle_test(
	'migration: denied roles cannot export, upload, download, cancel or view; foreign job status is refused',
	function () {
		$a      = cm_require_archive();
		$admin  = cradle_test_user( 'administrator' );
		$ajax   = admin_url( 'admin-ajax.php' );
		$export = (string) $GLOBALS['cm']['export_job'];
		if ( ! get_role( 'cradle_test_migrator' ) ) {
			add_role( 'cradle_test_migrator', 'Cradle test migrator', array( 'read' => true, 'cradle_export_site' => true, 'cradle_import_site' => true ) );
		}
		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			$uid = cradle_test_user( $role );
			wp_set_current_user( $uid );
			assert_wp_error( Exporter::create( array() ), 'cradle_migration_forbidden', $role . ' cannot create an export job.' );
			assert_wp_error( Importer::create( array( 'location' => 'archives/' . $a['id'], 'manifest' => Manifest::manifest_name( $a['id'] ) ) ), 'cradle_migration_forbidden', $role . ' cannot create an import job.' );
			$session = cradle_test_cookies( $uid );
			$nonce   = cradle_test_nonce( $uid, $session, 'cradle_migration' );
			$r       = cradle_test_http( 'POST', $ajax, array( 'body' => array( 'action' => 'cradle_migration_upload_begin', 'nonce' => $nonce, 'files' => '[]' ) ), $uid, $session );
			assert_same( 403, wp_remote_retrieve_response_code( $r ), $role . ' cannot start an upload.' );
			$jn = cradle_test_nonce( $uid, $session, 'cradle_jobs' );
			foreach ( array( 'cradle_job_status', 'cradle_job_cancel', 'cradle_job_step' ) as $action ) {
				$r = cradle_test_http( 'POST', $ajax, array( 'body' => array( 'action' => $action, 'nonce' => $jn, 'job' => $export ) ), $uid, $session );
				assert_same( 404, wp_remote_retrieve_response_code( $r ), $role . ' is refused ' . $action . '.' );
			}
			$an = cradle_test_nonce( $uid, $session, 'cradle_migration_admin' );
			$r  = cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => array( 'action' => 'cradle_migration_export', '_wpnonce' => $an, 'database' => '1' ) ), $uid, $session );
			assert_same( 403, wp_remote_retrieve_response_code( $r ), $role . ' cannot export via admin-post.' );
			wp_set_current_user( $uid );
			$_COOKIE[ LOGGED_IN_COOKIE ] = $session['logged_in'];
			$url                         = Archives::download_url( $a['id'], Manifest::manifest_name( $a['id'] ) );
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
			$r = cradle_test_http( 'GET', $url, array(), $uid, $session );
			assert_same( 403, wp_remote_retrieve_response_code( $r ), $role . ' cannot download an archive even with its own signed link.' );
			$r = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-migration' ), array(), $uid, $session );
			assert_true( 200 !== wp_remote_retrieve_response_code( $r ) || false !== strpos( wp_remote_retrieve_body( $r ), 'not allowed' ), $role . ' cannot open the Migration screen.' );
		}
		// A user holding the migration capabilities but not owning the job (and without manage_options).
		$mig     = cradle_test_user( 'cradle_test_migrator' );
		$msess   = cradle_test_cookies( $mig );
		$r       = cradle_test_http( 'POST', $ajax, array( 'body' => array( 'action' => 'cradle_job_status', 'nonce' => cradle_test_nonce( $mig, $msess, 'cradle_jobs' ), 'job' => $export ) ), $mig, $msess );
		assert_same( 404, wp_remote_retrieve_response_code( $r ), 'Status of another user\'s job is refused.' );
		wp_set_current_user( $mig );
		$_COOKIE[ LOGGED_IN_COOKIE ] = $msess['logged_in'];
		$url                         = Archives::download_url( $a['id'], Manifest::manifest_name( $a['id'] ) );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		assert_same( 403, wp_remote_retrieve_response_code( cradle_test_http( 'GET', $url, array(), $mig, $msess ) ), 'Another user\'s archive cannot be downloaded.' );
		// The owner can download through the short-lived link; tampering or expiry is refused.
		$session                     = cradle_test_cookies( $admin );
		wp_set_current_user( $admin );
		$_COOKIE[ LOGGED_IN_COOKIE ] = $session['logged_in'];
		$url                         = Archives::download_url( $a['id'], Manifest::manifest_name( $a['id'] ) );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		$r = cradle_test_http( 'GET', $url, array(), $admin, $session );
		assert_same( 200, wp_remote_retrieve_response_code( $r ), 'Owner download works.' );
		assert_same( filesize( $a['manifest'] ), strlen( wp_remote_retrieve_body( $r ) ), 'Download is complete.' );
		assert_contains( 'no-store', (string) wp_remote_retrieve_header( $r, 'cache-control' ) );
		$r = cradle_test_http( 'GET', add_query_arg( 'exp', time() - 10, $url ), array(), $admin, $session );
		assert_same( 403, wp_remote_retrieve_response_code( $r ), 'Expired/tampered link is refused.' );
		$r = cradle_test_http( 'GET', add_query_arg( 'file', '../../../wp-config.php', $url ), array(), $admin, $session );
		assert_same( 403, wp_remote_retrieve_response_code( $r ), 'Path tampering is refused.' );
		assert_same( 200, wp_remote_retrieve_response_code( cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-migration&tab=archives' ), array(), $admin, $session ) ), 'Admin opens the Migration screen.' );
		$private = cradle()->storage()->private_root();
		$rel     = substr( wp_normalize_path( $a['manifest'] ), strlen( $private['path'] ) );
		$r       = wp_remote_get( $private['url'] . $rel, array( 'redirection' => 0 ) );
		assert_true( 200 !== wp_remote_retrieve_response_code( $r ), 'Archives have no public URL.' );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $mig );
		remove_role( 'cradle_test_migrator' );
	}
);

/* ------------------------------------------------------------------ */
/* Browser-runner restore into the independent target                 */
/* ------------------------------------------------------------------ */

cradle_test(
	'migration: chunked upload to the target resumes after an interruption and ignores duplicate chunks',
	function () {
		$a       = cm_require_archive();
		$session = cm_session( 'target_admin' );
		$GLOBALS['cm']['session'] = $session;
		$files   = array( basename( $a['manifest'] ) => $a['manifest'] );
		foreach ( json_decode( (string) file_get_contents( $a['manifest'] ), true )['parts'] as $p ) {
			$files[ $p['name'] ] = $a['dir'] . '/' . $p['name'];
		}
		$list  = array_map( static fn( $n, $p ) => array( 'name' => $n, 'size' => filesize( $p ) ), array_keys( $files ), $files );
		$begin = static fn() => cm_json( cm_ajax( $session, array( 'action' => 'cradle_migration_upload_begin', 'nonce' => $session['nonces']['cradle_migration'], 'files' => wp_json_encode( $list ) ) ) );
		$s     = $begin();
		assert_true( ! empty( $s['success'] ), 'Upload session: ' . wp_json_encode( $s['data'] ?? null ) );
		$s      = $s['data'];
		$paths  = array_values( $files );
		$byname = $files;
		$send   = static function ( array $s, int $fi, int $ci, ?string $override = null ) use ( $session, $byname ) {
			$f     = $s['files'][ $fi ];
			$bytes = null !== $override ? $override : (string) file_get_contents( $byname[ $f['name'] ], false, null, $ci * $s['chunkSize'], $s['chunkSize'] );
			return cm_json( cm_ajax( $session, array( 'action' => 'cradle_migration_upload_chunk', 'nonce' => $session['nonces']['cradle_migration'], 'session' => $s['session'], 'file' => $fi, 'index' => $ci ), $bytes ) );
		};
		$all = array();
		foreach ( $s['files'] as $fi => $f ) {
			for ( $ci = 0; $ci < $f['chunks']; $ci++ ) {
				$all[] = array( $fi, $ci );
			}
		}
		$half = (int) ceil( count( $all ) / 2 );
		foreach ( array_slice( $all, 0, $half ) as $c ) {
			$r = $send( $s, $c[0], $c[1] );
			assert_true( ! empty( $r['success'] ), 'Chunk upload: ' . wp_json_encode( $r['data'] ?? null ) );
		}
		// Interruption: the browser forgets everything; re-selecting the same files resumes.
		$again = $begin();
		assert_true( ! empty( $again['data']['resumed'] ) && $again['data']['session'] === $s['session'], 'Same files resume the unfinished session.' );
		$received = array_sum( array_map( static fn( $f ) => count( $f['received'] ), $again['data']['files'] ) );
		assert_same( $half, $received, 'Server reports exactly the chunks already stored.' );
		$dup = $send( $s, $all[0][0], $all[0][1] );
		assert_true( ! empty( $dup['success'] ) && ! empty( $dup['data']['duplicate'] ), 'A duplicate chunk submission is acknowledged without writing twice.' );
		$len  = strlen( (string) file_get_contents( $byname[ $s['files'][ $all[0][0] ]['name'] ], false, null, 0, $s['chunkSize'] ) );
		$evil = $send( $s, $all[0][0], $all[0][1], str_repeat( 'X', $len ) );
		assert_true( empty( $evil['success'] ), 'A different payload for a stored chunk is refused.' );
		$early = cm_json( cm_ajax( $session, array( 'action' => 'cradle_migration_upload_finish', 'nonce' => $session['nonces']['cradle_migration'], 'session' => $s['session'] ) ) );
		assert_true( empty( $early['success'] ), 'An incomplete upload cannot be finished.' );
		foreach ( array_slice( $all, $half ) as $c ) {
			$r = $send( $s, $c[0], $c[1] );
			assert_true( ! empty( $r['success'] ) && empty( $r['data']['duplicate'] ), 'Resumed chunk upload.' );
		}
		$done = cm_json( cm_ajax( $session, array( 'action' => 'cradle_migration_upload_finish', 'nonce' => $session['nonces']['cradle_migration'], 'session' => $s['session'] ) ) );
		assert_true( ! empty( $done['success'] ), 'Finish: ' . wp_json_encode( $done['data'] ?? null ) );
		$GLOBALS['cm']['job'] = $done['data']['uuid'];
		unset( $paths );
	}
);

cradle_test(
	'migration: preflight via the browser runner, operator review/approval and staging (live target untouched)',
	function () {
		$uuid    = (string) $GLOBALS['cm']['job'];
		$session = $GLOBALS['cm']['session'];
		if ( '' === $uuid ) {
			skip_test( 'Upload did not finish.' );
		}
		$view = cm_step_until( $session, $uuid, array( 'paused' ) );
		assert_same( 'paused', $view['state'], 'Preflight stops for review: ' . $view['error'] );
		assert_same( 'review', $view['phase'] );
		$report = cm_json( cm_ajax( $session, array( 'action' => 'cradle_migration_report', 'nonce' => $session['nonces']['cradle_migration'], 'job' => $uuid ) ) );
		assert_true( ! empty( $report['success'] ) && ! $report['data']['blocking'], 'No blocking problems: ' . wp_json_encode( $report['data']['blocking'] ?? null ) );
		assert_contains( 'cradle_admin', $report['data']['admin']['summary'], 'The report names the administrator account that works after restore.' );
		assert_true( count( $report['data']['checks'] ) >= 8 );
		// A target editor cannot approve or cut over.
		$editor = cm_target( 'user', 'editor' );
		$editor['jar'] = array_map( static fn( $c ) => new WP_Http_Cookie( array( 'name' => $c['name'], 'value' => $c['value'] ) ), $editor['cookies'] );
		foreach ( array( 'cradle_migration_approve', 'cradle_migration_cutover', 'cradle_migration_report' ) as $action ) {
			$r = cm_ajax( $editor, array( 'action' => $action, 'nonce' => $editor['nonces']['cradle_migration'], 'job' => $uuid ) );
			assert_same( 403, wp_remote_retrieve_response_code( $r ), 'Editor refused ' . $action );
		}
		$r = cm_ajax( $editor, array( 'action' => 'cradle_job_cancel', 'nonce' => $editor['nonces']['cradle_jobs'], 'job' => $uuid ) );
		assert_same( 404, wp_remote_retrieve_response_code( $r ), 'Editor cannot cancel the import.' );
		$ok = cm_json( cm_ajax( $session, array( 'action' => 'cradle_migration_approve', 'nonce' => $session['nonces']['cradle_migration'], 'job' => $uuid ) ) );
		assert_true( ! empty( $ok['success'] ), 'Approve: ' . wp_json_encode( $ok['data'] ?? null ) );
		$view = cm_step_until( $session, $uuid, array( 'awaiting_cutover' ) );
		assert_same( 'awaiting_cutover', $view['state'], 'Staging completes: ' . $view['error'] );
		$info = cm_target( 'info' );
		assert_same( 'original-target', $info['marker'], 'The live target is unchanged before cutover.' );
		assert_true( (bool) array_filter( $info['tables'], static fn( $t ) => str_starts_with( $t, 'cmig_' ) ), 'Staged tables exist beside the live ones.' );
		$page = cm_http( 'GET', '/wp-admin/admin.php?page=cradle-migration&tab=import&job=' . $uuid, $session );
		assert_contains( 'Switch the site over now', wp_remote_retrieve_body( $page ), 'The cutover confirmation screen renders.' );
	}
);

cradle_test(
	'migration: cutover via the job-scoped recovery token; the old admin session stops working (session change)',
	function () {
		$uuid    = (string) $GLOBALS['cm']['job'];
		$session = $GLOBALS['cm']['session'];
		if ( '' === $uuid ) {
			skip_test( 'No staged import.' );
		}
		$cut = cm_json( cm_ajax( $session, array( 'action' => 'cradle_migration_cutover', 'nonce' => $session['nonces']['cradle_migration'], 'job' => $uuid ) ) );
		assert_true( ! empty( $cut['success'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $cut['data']['token'] ), 'Cutover returns a one-time token.' );
		$token = $cut['data']['token'];
		$bad   = cm_ajax( null, array( 'action' => 'cradle_migration_continue', 'job' => $uuid, 'token' => str_repeat( 'a', 64 ) ) );
		assert_same( 403, wp_remote_retrieve_response_code( $bad ), 'A wrong token is refused.' );
		$view = null;
		for ( $i = 0; $i < 100; $i++ ) {
			// Deliberately without cookies: after cutover the browser session no longer exists.
			$r = cm_json( cm_ajax( null, array( 'action' => 'cradle_migration_continue', 'job' => $uuid, 'token' => $token ) ) );
			assert_true( ! empty( $r['success'] ), 'Continuation: ' . wp_json_encode( $r['data'] ?? null ) );
			$view = $r['data'];
			if ( $view['terminal'] ) {
				break;
			}
		}
		assert_same( 'completed', $view['state'], 'Restore completes: ' . $view['error'] );
		assert_true( ! empty( $view['verification']['passed'] ), 'Post-restore verification passed: ' . wp_json_encode( $view['verification'] ?? null ) );
		assert_true( in_array( 'cradle_admin', $view['verification']['admins'], true ), 'Documented admin account exists.' );
		$GLOBALS['cm']['restored'] = true;
		$old = cm_ajax( $session, array( 'action' => 'cradle_job_status', 'nonce' => $session['nonces']['cradle_jobs'], 'job' => $uuid ) );
		assert_true( empty( cm_json( $old )['success'] ), 'The pre-restore admin session no longer authorizes job requests.' );
		$again = cm_ajax( null, array( 'action' => 'cradle_migration_continue', 'job' => $uuid, 'token' => $token ) );
		assert_same( 403, wp_remote_retrieve_response_code( $again ), 'The token is revoked once verification ran.' );
	}
);

cradle_test(
	'migration: restored target serves the migrated site (URL + path + prefix change) over HTTP',
	function () {
		$uuid = cm_require_restore();
		$fx   = $GLOBALS['cm']['fixtures'];
		$src  = 'http://localhost/cradle/wp';
		$r    = cm_http( 'GET', '/' );
		assert_same( 200, wp_remote_retrieve_response_code( $r ), 'Front page.' );
		assert_not_contains( $src . '/', str_replace( $src . '-target', '', wp_remote_retrieve_body( $r ) ), 'No source URLs on the front page.' );
		$page = cm_target( 'post', $fx['page_title'] )['post'];
		assert_true( is_array( $page ), 'Fixture page restored.' );
		$dst_file = CM_T_URL . '/wp-content/uploads/' . $fx['file_rel'];
		assert_contains( '"url":"' . $dst_file . '"', $page['post_content'], 'Block attribute URL rewritten.' );
		assert_contains( 'src="' . $dst_file . '"', $page['post_content'], 'Media URL in markup rewritten.' );
		assert_contains( 'http://localhost/cradle/wpx/', $page['post_content'], 'Look-alike URL untouched.' );
		$r = cm_http( 'GET', $page['permalink'] );
		assert_same( 200, wp_remote_retrieve_response_code( $r ), 'Content page ' . $page['permalink'] );
		$media = cm_http( 'GET', $dst_file );
		assert_same( 200, wp_remote_retrieve_response_code( $media ), 'Media file served from the new URL.' );
		assert_same( $fx['png'], wp_remote_retrieve_body( $media ), 'Media bytes intact.' );
		$post = cm_target( 'post', $fx['post_title'] )['post'];
		assert_contains( 'http:\\/\\/localhost\\/cradle\\/wp-target\\/wp-content\\/uploads\\/escaped.png', $post['post_content'], 'Escaped JSON URL rewritten.' );
		assert_true( str_starts_with( $post['guid'], $src . '/' ), 'GUID is not rewritten.' );
		$meta = array_column( $post['meta'], 'meta_value', 'meta_key' );
		$nested = unserialize( $meta['_cradle_mig_nested'], array( 'allowed_classes' => false ) ); // phpcs:ignore -- test-owned fixture data.
		assert_same( CM_T_URL . '/a/', $nested['level1']['url'], 'Nested serialized meta rewritten with valid lengths.' );
		assert_same( array( 'deep' => CM_T_URL . '/b/' ), unserialize( $nested['level1']['inner'], array( 'allowed_classes' => false ) ) ); // phpcs:ignore
		assert_same( array( 'url' => CM_T_URL . '/j/' ), json_decode( $meta['_cradle_mig_json'], true ) );
		assert_same( 200, wp_remote_retrieve_response_code( cm_http( 'GET', $post['permalink'] ) ), 'Single post.' );
		$search = cm_http( 'GET', '/?s=' . rawurlencode( $fx['post_title'] ) );
		assert_same( 200, wp_remote_retrieve_response_code( $search ) );
		assert_contains( esc_html( $fx['post_title'] ), wp_remote_retrieve_body( $search ), 'Search finds the migrated post.' );
		$rest = cm_http( 'GET', '/wp-json/' );
		assert_same( 200, wp_remote_retrieve_response_code( $rest ), 'REST index.' );
		assert_same( CM_T_URL, json_decode( wp_remote_retrieve_body( $rest ), true )['home'] ?? '' );
		$opt = unserialize( (string) cm_target( 'option', 'cradle_mig_fixture' )['value'], array( 'allowed_classes' => false ) ); // phpcs:ignore
		assert_true( is_array( $opt ), 'Serialized option is still valid (lengths recomputed).' );
		$exp = $fx['option'];
		foreach ( array( 'url', 'noslash', 'unicode', 'delims' ) as $k ) {
			$exp[ $k ] = str_replace( $src, CM_T_URL, $exp[ $k ] );
		}
		$exp['https']      = 'https://localhost/cradle/wp-target/secure/';
		$exp['json']       = wp_json_encode( array( 'u' => CM_T_URL . '/j/' ) );
		$exp['nested']     = array( 'deep' => array( 'deeper' => CM_T_URL . '/d/' ) );
		$exp['serialized'] = serialize( array( 'x' => CM_T_URL . '/s/' ) );
		$exp['path']       = 'C:/wamp64/www/cradle/wp-target/wp-content/uploads';
		$exp['winpath']    = 'C:\\wamp64\\www\\cradle\\wp-target\\wp-content';
		assert_same( $exp, $opt, 'Every serialized/JSON/path variant rewritten; look-alikes and other hosts untouched.' );
		$info = cm_target( 'info' );
		assert_same( 'ct_', $info['prefix'] );
		assert_true( $info['userRolesOpt'], 'ct_user_roles exists after the prefix change.' );
		assert_same( 0, $info['oldPrefixKeys'], 'No cw_ user meta keys remain.' );
		assert_same( 0, $info['sessions'], 'Imported sessions were cleared.' );
		assert_same( CM_T_URL, $info['home'], 'Destination home preserved.' );
		assert_same( array(), $info['missingPlugins'], 'All active plugins are present.' );
		assert_true( $info['themeExists'], 'Active theme (and parent) present.' );
		assert_same( get_stylesheet(), $info['stylesheet'], 'Same child theme as the source.' );
		sort( $info['activePlugins'] );
		$src_active = (array) get_option( 'active_plugins' );
		sort( $src_active );
		assert_same( $src_active, $info['activePlugins'], 'Same active plugins as the source.' );
		assert_true( ! empty( $info['hold']['active'] ), 'Safety hold active.' );
		assert_true( false === $info['marker'] && ! $info['uploadsTargetOnly'], 'Target-only data was replaced (and kept in backups).' );
		$admin = cm_session( 'cradle_admin' );
		$dash  = cm_http( 'GET', '/wp-admin/', $admin );
		assert_same( 200, wp_remote_retrieve_response_code( $dash ), 'Documented admin account reaches wp-admin.' );
		assert_contains( 'Dashboard', wp_remote_retrieve_body( $dash ) );
		$mig = cm_http( 'GET', '/wp-admin/admin.php?page=cradle-migration&tab=jobs', $admin );
		assert_contains( 'safety hold', strtolower( wp_remote_retrieve_body( $mig ) ) );
		// Staging, backups and the journal are not publicly readable.
		$priv = cm_target( 'private-url', $uuid );
		assert_true( in_array( 'journal.jsonl', $priv['files'], true ) && in_array( 'backup', $priv['files'], true ), 'Job keeps journal and backups.' );
		foreach ( array( 'journal.jsonl', 'recovery.json', 'report.json', 'backup/uploads/target-only/marker.txt' ) as $f ) {
			$code = wp_remote_retrieve_response_code( cm_http( 'GET', $priv['url'] . '/' . $f ) );
			assert_true( in_array( $code, array( 403, 404 ), true ), $f . ' must not be publicly readable (got ' . $code . ').' );
		}
	}
);

/* ------------------------------------------------------------------ */
/* §15.10 failure tests (CLI runner against the target)               */
/* ------------------------------------------------------------------ */

cradle_test(
	'migration: corrupt and hostile archives fail preflight without touching the target',
	function () {
		$a    = cm_require_archive();
		cm_require_restore();
		$tmp  = $GLOBALS['cm']['tmp'];
		$base = cm_craft_read( $a['manifest'] );
		$src  = 'http://localhost/cradle/wp';
		$before = cm_target( 'info' );
		$cases  = array();

		// Bad checksum: flip one byte of part 1 (genuine manifest).
		$dir = $tmp . '/bad-checksum';
		mkdir( $dir, 0775, true );
		foreach ( glob( $a['dir'] . '/' . $a['id'] . '.*' ) as $f ) {
			copy( $f, $dir . '/' . basename( $f ) );
		}
		$p1        = $dir . '/' . Manifest::part_name( $a['id'], 1 );
		$bytes     = file_get_contents( $p1 );
		$mid       = (int) ( strlen( $bytes ) / 2 );
		$bytes[ $mid ] = chr( ord( $bytes[ $mid ] ) ^ 0xFF );
		file_put_contents( $p1, $bytes );
		$cases['bad checksum'] = array( $dir . '/' . basename( $a['manifest'] ), 'SHA-256 checksum' );

		// Missing part.
		$dir = $tmp . '/missing-part';
		mkdir( $dir, 0775, true );
		foreach ( glob( $a['dir'] . '/' . $a['id'] . '.*' ) as $f ) {
			copy( $f, $dir . '/' . basename( $f ) );
		}
		unlink( $dir . '/' . Manifest::part_name( $a['id'], 2 ) );
		$cases['missing part'] = array( $dir . '/' . basename( $a['manifest'] ), 'is missing' );

		$variant = static function ( string $name, callable $mutate, array $attrs = array() ) use ( $base, $tmp ) {
			$arch = $base;
			$mutate( $arch );
			return cm_craft_write( $tmp . '/' . $name, $arch, $attrs );
		};
		$cases['parent traversal'] = array( $variant( 'traversal', static fn( &$x ) => cm_craft_add_file( $x, 'uploads', '../../evil.php', '<?php echo 1;' ) ), 'traversal segment' );
		$cases['absolute path']    = array( $variant( 'absolute', static fn( &$x ) => cm_craft_add_file( $x, 'uploads', '/etc/passwd', 'x' ) ), 'absolute path' );
		$cases['drive path']       = array( $variant( 'drive', static fn( &$x ) => cm_craft_add_file( $x, 'uploads', 'C:/Windows/win.ini', 'x' ) ), 'drive letter' );
		$cases['null byte']        = array( $variant( 'nullbyte', static fn( &$x ) => cm_craft_add_file( $x, 'uploads', "a\0.php", 'x' ) ), 'null byte' );
		$cases['case duplicate']   = array(
			$variant(
				'dup',
				static function ( &$x ) {
					cm_craft_add_file( $x, 'uploads', 'Dup.txt', 'a' );
					cm_craft_add_file( $x, 'uploads', 'dup.txt', 'b' );
				}
			),
			'case-conflicting',
		);
		$cases['reserved private dir'] = array( $variant( 'reserved', static fn( &$x ) => cm_craft_add_file( $x, 'uploads', 'cradle-private-abc/x.txt', 'x' ) ), 'reserved Cradle private storage' );
		$cases['root file not allowlisted'] = array( $variant( 'rootfile', static fn( &$x ) => cm_craft_add_file( $x, 'root', 'wp-config.php', 'x' ) ), 'root-file allowlist' );
		$link = null;
		$cases['symlink entry']    = array(
			$variant(
				'symlink',
				static function ( &$x ) use ( &$link ) {
					$link = cm_craft_add_file( $x, 'uploads', '2026/link.txt', '../../../../wp-config.php' );
				},
				array( 'files/uploads/2026/link.txt' => array( ZipArchive::OPSYS_UNIX, ( 0120777 << 16 ) ) )
			),
			'symbolic link',
		);
		$cases['device entry']     = array(
			$variant( 'device', static fn( &$x ) => cm_craft_add_file( $x, 'uploads', '2026/dev.txt', 'x' ), array( 'files/uploads/2026/dev.txt' => array( ZipArchive::OPSYS_UNIX, ( 020644 << 16 ) ) ) ),
			'character device',
		);
		$cases['decompression bomb'] = array( $variant( 'bomb', static fn( &$x ) => cm_craft_add_file( $x, 'uploads', 'bomb.bin', str_repeat( "\0", 24 * 1048576 ) ) ), 'decompression bomb' );
		$cases['unexpected entry']   = array(
			$variant(
				'extra',
				static function ( &$x ) {
					$x['entries']['files/uploads/sneaky.php'] = '<?php echo 1;';
				}
			),
			'Unexpected archive entry',
		);
		$cases['newer format']       = array(
			$variant(
				'format',
				static function ( &$x ) {
					$x['manifest']['formatVersion'] = 2;
				}
			),
			'newer than this importer supports',
		);
		$obj_url = $src . '/object/';
		$cases['unsupported serialized object'] = array(
			$variant(
				'object',
				static function ( &$x ) use ( $obj_url ) {
					cm_craft_rows( $x, 'options', null, array( array( 'option_id' => '990001', 'option_name' => 'cradle_mig_object', 'option_value' => 'O:8:"stdClass":1:{s:3:"url";s:' . strlen( $obj_url ) . ':"' . $obj_url . '";}', 'autoload' => 'off' ) ) );
				}
			),
			'cradle_mig_object',
		);
		$cases['disk full (operator quota)'] = array( $a['manifest'], 'Insufficient disk space', '--max-disk-use=4096' );

		$results = array();
		foreach ( $cases as $label => $case ) {
			$args = array( 'import', '--archive=' . $case[0] );
			if ( isset( $case[2] ) ) {
				$args[] = $case[2];
			}
			$r = cm_cli( ...$args );
			$results[ $label ] = $r;
			assert_same( 2, $r['code'], $label . ' must fail (exit 2); got ' . $r['code'] . ' ' . substr( $r['stdout'] . $r['stderr'], 0, 400 ) );
			assert_contains( $case[1], cm_blocking( $r ), $label . ' reports the right reason.' );
			assert_same( 'preflight_failed', $r['json']['phase'] ?? '', $label . ' stops in preflight.' );
		}
		$after = cm_target( 'info' );
		assert_same( $before['tables'], $after['tables'], 'No staged or replaced tables after rejected archives.' );
		assert_same( $before['activePlugins'], $after['activePlugins'] );
		$GLOBALS['cm']['hostile'] = array_map( static fn( $r ) => $r['code'], $results );
	}
);

cradle_test(
	'migration: expired lease takeover repeats a load step idempotently and refuses the stale commit',
	function () {
		$a = cm_require_archive();
		cm_require_restore();
		$r = cm_target( 'lease-test', $a['manifest'] );
		assert_true( empty( $r['error'] ), 'lease-test: ' . wp_json_encode( $r ) );
		assert_true( '' !== (string) ( $r['repeated'] ?? '' ), 'A chunk load was performed by the expired worker and repeated by the new one: ' . ( $r['repeated'] ?? '' ) );
		assert_true( $r['takeover'], 'A new runner takes over an expired lease with a higher fencing generation.' );
		assert_true( $r['lateCommitRefused'], 'The expired worker cannot commit afterwards.' );
		assert_same( 'awaiting_cutover', $r['state'] );
		assert_same( array(), $r['mismatch'], 'Staged row counts equal the archive (no duplicated rows).' );
		assert_true( $r['tables'] >= 10 );
	}
);

cradle_test(
	'migration: database failure during staging is retried; a persistent failure leaves the live site untouched',
	function () {
		$a      = cm_require_archive();
		cm_require_restore();
		$before = cm_target( 'info' );
		$r      = cm_cli( 'import', '--archive=' . $a['manifest'] );
		assert_same( 0, $r['code'], 'Preflight: ' . $r['stderr'] );
		$uuid = $r['json']['uuid'];
		try {
			$r = cm_cli( 'approve', '--job=' . $uuid, '--fault=db-load-once' );
			assert_same( 'awaiting_cutover', $r['json']['state'] ?? '', 'Staging recovers from a killed DB connection: ' . $r['stderr'] . $r['stdout'] );
			assert_true( ( $r['json']['job']['retriesTotal'] ?? 0 ) >= 1, 'The failed chunk was retried (cumulative retry count).' );
			assert_same( 0, $r['json']['job']['retries'], 'The failure streak ended after the successful retry.' );
		} finally {
			$d = cm_cli( 'discard', '--job=' . $uuid );
		}
		assert_same( 0, $d['code'] );
		$r     = cm_cli( 'import', '--archive=' . $a['manifest'] );
		$uuid2 = $r['json']['uuid'];
		try {
			$r = cm_cli( 'approve', '--job=' . $uuid2, '--fault=db-load' );
			assert_same( 2, $r['code'], 'A persistent database failure fails the job.' );
			assert_same( 'failed', $r['json']['state'] ?? '' );
			assert_contains( 'Database', (string) ( $r['json']['job']['error'] ?? '' ) );
			$after = cm_target( 'info' );
			assert_same( $before['activePlugins'], $after['activePlugins'], 'Live site unchanged.' );
			assert_same( $before['home'], $after['home'] );
			assert_same( $before['hold'], $after['hold'] );
		} finally {
			cm_cli( 'discard', '--job=' . $uuid2 );
		}
		$tok = substr( str_replace( '-', '', $uuid2 ), 0, 6 );
		$left = array_filter( cm_target( 'info' )['tables'], static fn( $t ) => str_starts_with( $t, 'cmig_' . $tok ) );
		assert_same( array(), array_values( $left ), 'Staged tables of the failed job are discarded.' );
	}
);

cradle_test(
	'migration: crash after DB cutover but before file replacement is finished from the journal via the CLI',
	function () {
		$a = cm_require_archive();
		cm_require_restore();
		$tmp  = $GLOBALS['cm']['tmp'];
		$arch = cm_craft_read( $a['manifest'] );
		$opaque = 'O:8:"stdClass":1:{s:4:"note";s:5:"plain";}';
		cm_craft_rows(
			$arch,
			'options',
			static fn( $row, $names ) => 'cradle_login_settings' === $row[ array_search( 'option_name', $names, true ) ] ? null : $row,
			array(
				array( 'option_id' => '990002', 'option_name' => 'cradle_login_settings', 'option_value' => serialize( array( 'enabled' => true, 'slug' => 'secret-door', 'blocked_response' => 'not_found', 'recovery_note' => '', '_revision' => 3, '_schema' => 1 ) ), 'autoload' => 'on' ),
				array( 'option_id' => '990003', 'option_name' => 'cradle_mig_opaque', 'option_value' => $opaque, 'autoload' => 'off' ),
			)
		);
		$manifest = cm_craft_write( $tmp . '/login-route', $arch );
		cm_target( 'marker', 'set', 'before-second-import' );
		cm_target( 'upload-marker', 'set', 'pre-import file' );
		$r = cm_cli( 'import', '--archive=' . $manifest, '--yes', '--fault=after-db-cutover' );
		assert_same( 70, $r['code'], 'The simulated crash stops the process after the DB cutover: ' . $r['stderr'] );
		$st   = cm_cli( 'status' );
		$uuid = '';
		foreach ( $st['json']['jobs'] as $job ) {
			if ( 'migration/import' === $job['type'] && ! $job['terminal'] ) {
				$uuid = $job['uuid'];
				break;
			}
		}
		assert_true( '' !== $uuid, 'The interrupted job is listed.' );
		$GLOBALS['cm']['recover'] = $uuid;
		$st = cm_cli( 'status', '--job=' . $uuid );
		assert_same( 'db_swapped', $st['json']['recovery']['stage'], 'Journal shows the DB swapped and files pending.' );
		assert_same( 503, wp_remote_retrieve_response_code( cm_http( 'GET', '/' ) ), 'Visitors get a maintenance response during the critical phase.' );
		assert_true( cm_target( 'upload-marker' )['exists'], 'Files were not replaced yet.' );
		$rec = cm_cli( 'recover', '--job=' . $uuid );
		assert_same( 0, $rec['code'], 'recover: ' . $rec['stderr'] . substr( $rec['stdout'], 0, 500 ) );
		assert_same( 'completed', $rec['json']['state'] ?? '' );
		assert_true( ! empty( $rec['json']['verify']['passed'] ), 'Verification after recovery passed.' );
		$info = cm_target( 'info' );
		assert_true( false === $info['marker'], 'Database now holds the imported data.' );
		assert_true( ! cm_target( 'upload-marker' )['exists'], 'Files were replaced by the recovery.' );
		assert_same( false, $info['login']['enabled'] ?? null, 'Imported custom login route is disabled.' );
		assert_same( 'secret-door', $info['login']['slug'] ?? '', 'The rest of the login configuration is kept for the administrator to re-enable.' );
		assert_same( 200, wp_remote_retrieve_response_code( cm_http( 'GET', '/wp-login.php' ) ), 'Standard login works.' );
		$door = cm_http( 'GET', '/secret-door/' );
		assert_not_contains( 'name="log"', (string) wp_remote_retrieve_body( $door ), 'The imported custom login route does not serve a login form.' );
		assert_same( $opaque, cm_target( 'option', 'cradle_mig_opaque' )['value'], 'An opaque serialized object needing no change is kept byte-for-byte.' );
		assert_same( 200, wp_remote_retrieve_response_code( cm_http( 'GET', '/' ) ) );
	}
);

cradle_test(
	'migration: full rollback restores the pre-import database and files',
	function () {
		$uuid = (string) $GLOBALS['cm']['recover'];
		if ( '' === $uuid ) {
			skip_test( 'No recovered job to roll back.' );
		}
		$r = cm_cli( 'rollback', '--job=' . $uuid );
		assert_same( 0, $r['code'], 'rollback: ' . $r['stderr'] );
		assert_same( 'rolled_back', $r['json']['stage'] ?? '' );
		$info = cm_target( 'info' );
		assert_same( 'before-second-import', $info['marker'], 'Pre-import database is live again.' );
		$file = cm_target( 'upload-marker' );
		assert_true( $file['exists'] && 'pre-import file' === $file['content'], 'Pre-import uploads are back.' );
		assert_same( false, $info['quiesce'], 'No maintenance window remains.' );
		assert_same( 200, wp_remote_retrieve_response_code( cm_http( 'GET', '/' ) ) );
		$st = cm_cli( 'status', '--job=' . $uuid );
		assert_same( 'rolled_back', $st['json']['job']['state'] );
		$p = cm_cli( 'purge', '--job=' . $uuid );
		assert_same( 0, $p['code'], 'Backups of the rolled-back job can be purged explicitly.' );
	}
);

cradle_test(
	'migration: symlink/junction escapes are refused in staging paths and in destination roots',
	function () {
		$a   = cm_require_archive();
		cm_require_restore();
		$tmp = $GLOBALS['cm']['tmp'];
		wp_mkdir_p( $tmp . '/outside' );
		wp_mkdir_p( $tmp . '/root' );
		file_put_contents( $tmp . '/outside/keep.txt', 'outside' );
		if ( ! cm_make_link( $tmp . '/root/link', $tmp . '/outside' ) ) {
			skip_test( 'This host cannot create a symlink or junction.' );
		}
		try {
			assert_wp_error( Path_Guard::join( wp_normalize_path( $tmp . '/root' ), 'link/evil.txt' ), '', 'Writing through an existing linked parent is refused.' );
			assert_true( null !== Cradle\Migration\Exporter::exclusion_reason( 'uploads', '', 'link', wp_normalize_path( $tmp . '/root/link' ) ), 'Export never follows or archives links/junctions.' );
		} finally {
			cm_remove_link( $tmp . '/root/link' );
		}
		// A link planted in the destination uploads root: the restore must refuse before cutover.
		$planted = CRADLE_TEST_ROOT . '/wp-target/wp-content/uploads/evil-link';
		assert_true( cm_make_link( $planted, $tmp . '/outside' ), 'Planted link in the target uploads.' );
		$before = cm_target( 'info' );
		try {
			$r = cm_cli( 'import', '--archive=' . $a['manifest'], '--yes' );
			assert_same( 2, $r['code'], 'Import with a planted link fails.' );
			assert_contains( 'left the allowed roots', (string) ( $r['json']['job']['error'] ?? '' ) . $r['stderr'] );
			assert_same( 'failed', $r['json']['state'] ?? '', 'The job fails before cutover.' );
			$after = cm_target( 'info' );
			assert_same( $before['marker'], $after['marker'], 'The live site was not switched.' );
			assert_same( 'outside', file_get_contents( $tmp . '/outside/keep.txt' ), 'Nothing outside the roots was touched.' );
		} finally {
			cm_remove_link( $planted );
			$open = cm_open_import();
			if ( '' !== $open ) {
				cm_cli( 'discard', '--job=' . $open );
			}
		}
	}
);

cradle_test(
	'migration: crash during file replacement is rolled back with the minimal (SHORTINIT) CLI bootstrap',
	function () {
		$a = cm_require_archive();
		cm_require_restore();
		cm_target( 'marker', 'set', 'before-raw' );
		cm_target( 'upload-marker', 'set', 'raw file' );
		$r = cm_cli( 'import', '--archive=' . $a['manifest'], '--yes', '--fault=after-file-op=1' );
		assert_same( 70, $r['code'], 'Simulated crash during file replacement: ' . $r['stderr'] );
		$uuid = cm_open_import();
		assert_true( '' !== $uuid );
		$st = cm_cli( 'status', '--job=' . $uuid );
		assert_same( 'files_in_progress', $st['json']['recovery']['stage'] ?? '' );
		assert_same( 2, $st['json']['recovery']['fileDone'] ?? -1, 'Two file operations completed before the crash.' );
		assert_same( 503, wp_remote_retrieve_response_code( cm_http( 'GET', '/' ) ), 'Maintenance response while half-switched.' );
		$rb = cm_cli( 'recover', '--job=' . $uuid, '--raw', '--rollback' );
		assert_same( 0, $rb['code'], 'Raw rollback: ' . $rb['stderr'] . $rb['stdout'] );
		assert_same( 'rolled_back', $rb['json']['stage'] ?? '' );
		$info = cm_target( 'info' );
		assert_same( 'before-raw', $info['marker'], 'Original database is live again.' );
		$file = cm_target( 'upload-marker' );
		assert_true( $file['exists'] && 'raw file' === $file['content'], 'Original uploads are back.' );
		assert_same( false, $info['quiesce'] );
		assert_same( 200, wp_remote_retrieve_response_code( cm_http( 'GET', '/' ) ) );
		assert_same( 'rolled_back', cm_cli( 'status', '--job=' . $uuid )['json']['job']['state'] ?? '' );
		assert_same( 0, cm_cli( 'purge', '--job=' . $uuid )['code'] );
	}
);

cradle_test(
	'migration: export write-quiescence window refuses writes and is reported; unsupported view is reported',
	function () {
		cm_require_restore();
		cm_target( 'view', 'create' );
		try {
			$start = cm_target( 'quiesce', 'start' );
			assert_true( ! empty( $start['window']['job'] ), 'Window opened: ' . wp_json_encode( $start ) );
			assert_same( 200, wp_remote_retrieve_response_code( cm_http( 'GET', '/' ) ), 'Reads keep working during an export window.' );
			$c = cm_http( 'POST', '/wp-comments-post.php', null, array( 'body' => array( 'comment' => 'x', 'comment_post_ID' => 1 ) ) );
			assert_same( 503, wp_remote_retrieve_response_code( $c ), 'Comment posting is refused.' );
			$rest = cm_http( 'POST', '/wp-json/wp/v2/posts', null, array( 'body' => array( 'title' => 'x' ) ) );
			assert_same( 503, wp_remote_retrieve_response_code( $rest ), 'REST writes are refused.' );
			$fin = cm_target( 'quiesce', 'finish', $start['job'] );
			assert_same( 'completed', $fin['state'] );
			assert_same( false, $fin['window'], 'The window closes with the export.' );
			assert_same( 'write-quiescence', $fin['consistency']['strategy'] ?? '' );
			assert_contains( 'mig_view', wp_json_encode( $fin['unsupported'] ), 'The view is reported as unsupported, not silently dropped.' );
			$c = cm_http( 'POST', '/wp-comments-post.php', null, array( 'body' => array( 'comment' => 'x', 'comment_post_ID' => 1 ) ) );
			assert_true( 503 !== wp_remote_retrieve_response_code( $c ), 'Writes are accepted again.' );
		} finally {
			cm_target( 'view', 'drop' );
		}
	}
);

cradle_test(
	'migration: administrator screen flows on the restored target (export form, archive list, download, delete, release hold)',
	function () {
		cm_require_restore();
		$admin = cm_session( 'cradle_admin' );
		$post  = static fn( array $body ) => cm_http( 'POST', '/wp-admin/admin-post.php', $admin, array( 'body' => $body + array( '_wpnonce' => $admin['nonces']['cradle_migration_admin'] ) ) );
		$r     = $post( array( 'action' => 'cradle_migration_export', 'database' => '1', 'themes' => 'none', 'plugins' => 'none', 'part_size' => '16' ) );
		assert_same( 302, wp_remote_retrieve_response_code( $r ), 'Export form redirects to the runner.' );
		parse_str( (string) wp_parse_url( (string) wp_remote_retrieve_header( $r, 'location' ), PHP_URL_QUERY ), $q );
		assert_true( wp_is_uuid( $q['job'] ?? '', 4 ), 'Redirect names the new job.' );
		$page = cm_http( 'GET', '/wp-admin/admin.php?page=cradle-migration&tab=export&job=' . $q['job'], $admin );
		assert_contains( 'data-cradle-job="' . $q['job'] . '"', wp_remote_retrieve_body( $page ), 'The runner markup is rendered.' );
		$view = cm_step_until( $admin, $q['job'], array( 'completed' ) );
		assert_same( 'completed', $view['state'], 'Browser-driven export completes: ' . $view['error'] );
		$list = wp_remote_retrieve_body( cm_http( 'GET', '/wp-admin/admin.php?page=cradle-migration&tab=archives', $admin ) );
		assert_true( (bool) preg_match( '/<code>(cradle-[A-Za-z0-9-]+)<\/code>/', $list, $m ), 'Archive listed.' );
		$id = $m[1];
		assert_true( (bool) preg_match( '/href="([^"]+action=cradle_migration_download[^"]+manifest\.json[^"]*)"/', $list, $l ), 'Signed download link rendered.' );
		$dl = cm_http( 'GET', html_entity_decode( $l[1] ), $admin );
		assert_same( 200, wp_remote_retrieve_response_code( $dl ), 'Download through the signed link.' );
		assert_same( $id, json_decode( wp_remote_retrieve_body( $dl ), true )['archiveId'] ?? '' );
		assert_same( 302, wp_remote_retrieve_response_code( $post( array( 'action' => 'cradle_migration_delete_archive', 'archive' => $id ) ) ) );
		assert_not_contains( $id, wp_remote_retrieve_body( cm_http( 'GET', '/wp-admin/admin.php?page=cradle-migration&tab=archives', $admin ) ), 'Archive deleted.' );
		assert_true( ! empty( cm_target( 'info' )['hold']['active'] ) );
		assert_same( 302, wp_remote_retrieve_response_code( $post( array( 'action' => 'cradle_migration_release_hold' ) ) ) );
		assert_true( empty( cm_target( 'info' )['hold']['active'] ), 'Safety hold released by the administrator.' );
		$bad = cm_http( 'POST', '/wp-admin/admin-post.php', $admin, array( 'body' => array( 'action' => 'cradle_migration_release_hold', '_wpnonce' => 'nope' ) ) );
		assert_same( 403, wp_remote_retrieve_response_code( $bad ), 'Admin actions require a valid nonce.' );
	}
);

cradle_test(
	'migration: HPOS store restore',
	function () {
		skip_test( 'WooCommerce is not installed in the source or target sandbox; the HPOS store case was not run.' );
	}
);

cradle_test(
	'migration: disk exhaustion during a real write',
	function () {
		skip_test( 'A genuinely full disk cannot be produced safely on this host; only the preflight quota check (see hostile-archive test) was exercised.' );
	}
);

cradle_test(
	'migration: cleanup of source-side archives (they contain password hashes)',
	function () {
		$admin = cradle_test_user( 'administrator' );
		wp_set_current_user( $admin );
		foreach ( Archives::all() as $archive ) {
			assert_true( true === Archives::delete( $archive['id'] ), 'Archive deleted: ' . $archive['id'] );
		}
		assert_same( array(), Archives::all() );
		assert_true( is_plugin_active( 'cradle-migration/cradle-migration.php' ), 'Plugin stays active in the source sandbox.' );
		assert_same( false, get_option( 'cradle_migration_quiesce' ), 'No window left open on the source.' );
		assert_same( false, get_option( 'cradle_mig_fixture' ), 'Source fixtures removed.' );
	}
);
