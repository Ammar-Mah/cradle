<?php
/**
 * Cradle security surface inventory — READ-ONLY.
 *
 * Usage (from the repository root):
 *   php skills/cradle-security/scripts/check-surface.php <dir-or-file> [more ...] [--format=text|json] [--max-file-kb=1024]
 *
 * Example:
 *   php skills/cradle-security/scripts/check-surface.php packages/plugins/cradle
 *   php skills/cradle-security/scripts/check-surface.php packages/plugins/cradle-login packages/plugins/cradle-migration
 *
 * What it reports (PHP via token_get_all(), JS/JSON via bounded text/JSON reads):
 *   - add_action()/add_filter() hooks for wp_ajax_*, wp_ajax_nopriv_*, admin_post_*, admin_post_nopriv_*,
 *     admin_action_*, rest_api_init and REST filters, auth/login/redirect hooks, upload/MIME hooks,
 *     translation hooks, capability filters, save_post-style hooks and request-time hooks whose handler
 *     reads request data. For each handler it tries to resolve the callback inside the scanned files and
 *     says whether a capability check and a nonce check were SEEN in the handler (following up to three
 *     levels of same-file method calls).
 *   - register_rest_route() calls and whether each route declares permission_callback (and whether it is
 *     __return_true), add_shortcode(), register_block_type*() render callbacks, block.json "render" files,
 *     Cradle component.json descriptors and ->register_from_file() calls, admin pages with their capability,
 *     register_setting() sanitization, role/capability grants, cron scheduling and WP-CLI commands.
 *   - $_GET/$_POST/$_REQUEST/$_FILES/$_COOKIE/$_SERVER/$_ENV reads, filter_input(), php://input,
 *     forwarded-IP headers, and direct echo/print of request data.
 *   - $wpdb query calls (prepared, static, or dynamic) and prepare() calls with interpolated fragments.
 *   - Filesystem, archive, upload and outbound HTTP calls; redirects.
 *   - eval, assert, create_function, unserialize/maybe_unserialize, shell functions and backticks,
 *     extract(), single-argument parse_str(), includes with variable paths, dynamic callables.
 *   - JavaScript DOM sinks (innerHTML, insertAdjacentHTML, document.write, RawHTML), eval-like calls,
 *     jQuery references and bare module imports (vanilla-code rule signals).
 *
 * Safety properties:
 *   - Never includes, requires, evaluates or executes a scanned file. PHP is only tokenized; JSON is only
 *     json_decode()d with a depth limit; JS is only read line by line.
 *   - Writes nothing. Output goes to STDOUT only.
 *   - Scans only paths inside the detected root: the Cradle framework repository (the ancestor that
 *     contains scripts/packages.json, AGENTS.md and skills/), or, for an installed skill copy, the
 *     wp-content directory (or the containing package). Paths outside the root are refused.
 *   - Refuses to scan the root itself (name package directories instead), does not follow symlinks,
 *     re-checks each file's canonical path, skips .git/.svn/.hg/node_modules, bounds file size and count.
 *   - Control characters from scanned files are replaced before printing.
 *
 * LIMITATIONS — read before using the output:
 *   Pattern search alone does not establish exploitability or prove safety. This inventory is a starting
 *   list for the manual review in skills/cradle-security/SKILL.md, not a verdict.
 *   - "capability: seen" means a capability function was called somewhere in the resolved handler or its
 *     same-file helpers. It does not prove the right capability, the object-level check, or its ordering.
 *   - "NOT SEEN" may be a false positive when authorization is delegated to another class, a parent
 *     method, a trait, a permission_callback built elsewhere, or core (for example options.php).
 *   - Callbacks built dynamically, hooks named with variables, and code outside the scanned paths are
 *     reported as unresolved or not at all.
 *   - It does not track data flow, sanitization, escaping, taint, or whether output is escaped.
 *   - JavaScript checks are line-based regular expressions and miss multi-line or obfuscated code.
 *   - It does not test anything at runtime. Confirm every finding with a denied-role/negative-case test
 *     against the disposable sandbox (php tests/run.php) before reporting it as confirmed.
 *
 * @package Cradle
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
if ( PHP_VERSION_ID < 80000 ) {
	fwrite( STDERR, "check-surface.php requires PHP 8.0 or newer.\n" );
	exit( 2 );
}

const CS_MAX_FILES      = 5000;
const CS_DEFAULT_MAX_KB = 1024;
const CS_SKIP_DIRS      = array( '.git', '.svn', '.hg', 'node_modules' );
const CS_PHP_EXT        = array( 'php', 'inc', 'phtml' );
const CS_JS_EXT         = array( 'js', 'mjs', 'cjs' );
const CS_POLICY_EXT     = array( 'jsx', 'tsx', 'ts', 'scss', 'sass', 'vue' );
const CS_POLICY_FILES   = array( 'composer.json', 'package.json', 'package-lock.json', 'yarn.lock', 'webpack.config.js', 'vite.config.js' );
const CS_SUPERGLOBALS   = array( '$_GET', '$_POST', '$_REQUEST', '$_FILES', '$_COOKIE', '$_SERVER', '$_ENV' );
const CS_CAP_FUNCS      = array( 'current_user_can', 'user_can', 'current_user_can_for_blog', 'current_user_can_for_site', 'author_can', 'is_super_admin' );
const CS_NONCE_FUNCS    = array( 'check_admin_referer', 'check_ajax_referer', 'wp_verify_nonce' );
const CS_FILE_FUNCS     = array( 'file_get_contents', 'file', 'fopen', 'readfile', 'fpassthru', 'parse_ini_file', 'file_put_contents', 'fwrite', 'fputs', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'touch', 'chmod', 'chown', 'chgrp', 'symlink', 'link', 'tempnam', 'move_uploaded_file', 'wp_delete_file', 'wp_mkdir_p', 'wp_handle_upload', 'wp_handle_sideload', 'media_handle_upload', 'media_handle_sideload', 'wp_upload_bits', 'unzip_file', 'wp_tempnam', 'download_url' );
const CS_REMOTE_FUNCS   = array( 'wp_remote_get', 'wp_remote_post', 'wp_remote_head', 'wp_remote_request', 'wp_safe_remote_get', 'wp_safe_remote_post', 'wp_safe_remote_head', 'wp_safe_remote_request', 'download_url', 'curl_init', 'curl_exec', 'fsockopen', 'stream_socket_client' );
const CS_SHELL_FUNCS    = array( 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'expect_popen' );
const CS_SQL_METHODS    = array( 'query', 'get_results', 'get_row', 'get_var', 'get_col' );
const CS_ADMIN_PAGES    = array(
	'add_menu_page'       => 2,
	'add_submenu_page'    => 3,
	'add_options_page'    => 2,
	'add_management_page' => 2,
	'add_theme_page'      => 2,
	'add_plugins_page'    => 2,
	'add_users_page'      => 2,
	'add_dashboard_page'  => 2,
	'add_posts_page'      => 2,
	'add_media_page'      => 2,
	'add_pages_page'      => 2,
	'add_comments_page'   => 2,
);
const CS_CATEGORIES     = array(
	'ajax-nopriv'       => 'AJAX handlers open to logged-out callers (wp_ajax_nopriv_*)',
	'ajax'              => 'AJAX handlers (wp_ajax_*)',
	'admin-post-nopriv' => 'admin-post handlers open to logged-out callers (admin_post_nopriv_*)',
	'admin-post'        => 'admin-post handlers (admin_post_*)',
	'admin-action'      => 'admin.php action handlers (admin_action_*)',
	'rest'              => 'REST routes (register_rest_route)',
	'rest-hook'         => 'REST registration and REST authentication filters',
	'auth'              => 'Authentication, login URL and redirect hooks',
	'privilege'         => 'Role/capability grants and capability filters',
	'save'              => 'Save/mutation hooks',
	'request'           => 'Request-time hooks whose handlers read request data',
	'upload'            => 'Upload and MIME hooks',
	'i18n'              => 'Translation hooks',
	'admin-page'        => 'Admin pages and required capability',
	'settings'          => 'Settings registrations',
	'shortcode'         => 'Shortcodes (attributes are editor-controlled input)',
	'block'             => 'Blocks and render callbacks (attributes are stored, editor-controlled input)',
	'component'         => 'Cradle components (render services and theme overrides)',
	'cron'              => 'Scheduled work',
	'cli'               => 'WP-CLI commands',
	'input'             => 'Request data reads',
	'output'            => 'Direct output of request data',
	'sql'               => 'Database queries',
	'file'              => 'Filesystem, upload and archive operations',
	'remote'            => 'Outbound HTTP',
	'redirect'          => 'Redirects',
	'exec'              => 'Code execution, deserialization and variable includes',
	'dynamic'           => 'Dynamic callables (inventory only)',
	'js'                => 'JavaScript sinks and requests',
	'policy'            => 'Dependency-policy signals (vanilla-code rule)',
);

exit( cs_main( $argv ) );

/**
 * Entry point.
 */
function cs_main( array $argv ): int {
	$format  = 'text';
	$max_kb  = CS_DEFAULT_MAX_KB;
	$targets = array();
	foreach ( array_slice( $argv, 1 ) as $arg ) {
		if ( '--help' === $arg || '-h' === $arg ) {
			cs_usage( STDOUT );
			return 0;
		}
		if ( str_starts_with( $arg, '--format=' ) ) {
			$format = substr( $arg, 9 );
			if ( ! in_array( $format, array( 'text', 'json' ), true ) ) {
				fwrite( STDERR, "--format must be text or json\n" );
				return 2;
			}
			continue;
		}
		if ( str_starts_with( $arg, '--max-file-kb=' ) ) {
			$max_kb = max( 1, min( 8192, (int) substr( $arg, 14 ) ) );
			continue;
		}
		if ( str_starts_with( $arg, '-' ) ) {
			fwrite( STDERR, 'Unknown option: ' . cs_clean( $arg ) . "\n" );
			return 2;
		}
		$targets[] = $arg;
	}
	if ( ! $targets ) {
		cs_usage( STDERR );
		return 2;
	}

	list( $root, $root_kind ) = cs_detect_root();
	$root                     = cs_norm( $root );
	$paths                    = array();
	$notes                    = array();
	$rel_targets              = array();

	foreach ( $targets as $target ) {
		$real = realpath( $target );
		if ( false === $real ) {
			fwrite( STDERR, 'Not found: ' . cs_clean( $target ) . "\n" );
			return 2;
		}
		$real = cs_norm( $real );
		if ( ! cs_within( $real, $root ) ) {
			fwrite( STDERR, 'Refusing to scan outside ' . $root . ': ' . cs_clean( $target ) . "\n" );
			return 2;
		}
		if ( cs_same( $real, $root ) ) {
			fwrite( STDERR, "Refusing to scan the whole root. Name package directories, for example packages/plugins/cradle.\n" );
			return 2;
		}
		$rel_targets[] = cs_rel( $real, $root );
		if ( is_file( $real ) ) {
			$paths[ $real ] = true;
			continue;
		}
		cs_collect( $real, $root, $paths, $notes );
	}

	$files   = array();
	$counts  = array(
		'php'    => 0,
		'js'     => 0,
		'json'   => 0,
		'skipped' => 0,
	);
	$max_b   = $max_kb * 1024;
	$policy  = array();
	foreach ( array_keys( $paths ) as $path ) {
		if ( count( $files ) >= CS_MAX_FILES ) {
			$notes[] = 'File limit ' . CS_MAX_FILES . ' reached; remaining files were not scanned.';
			break;
		}
		$rel  = cs_rel( $path, $root );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$base = strtolower( basename( $path ) );
		if ( in_array( $base, CS_POLICY_FILES, true ) || in_array( $ext, CS_POLICY_EXT, true ) ) {
			$policy[] = array( $rel, $base );
		}
		$kind = in_array( $ext, CS_PHP_EXT, true ) ? 'php' : ( in_array( $ext, CS_JS_EXT, true ) ? 'js' : ( in_array( $base, array( 'block.json', 'component.json' ), true ) ? 'json' : null ) );
		if ( null === $kind ) {
			continue;
		}
		$canonical = realpath( $path );
		if ( false === $canonical || ! cs_within( cs_norm( $canonical ), $root ) ) {
			$notes[] = 'Skipped (resolves outside root): ' . $rel;
			$counts['skipped']++;
			continue;
		}
		$size = filesize( $canonical );
		if ( false === $size || $size > $max_b ) {
			$notes[] = 'Skipped (larger than ' . $max_kb . ' KB): ' . $rel;
			$counts['skipped']++;
			continue;
		}
		$code = file_get_contents( $canonical ); // Read only; never included or executed.
		if ( false === $code ) {
			$notes[] = 'Skipped (unreadable): ' . $rel;
			$counts['skipped']++;
			continue;
		}
		$counts[ $kind ]++;
		$entry = array(
			'rel'  => $rel,
			'kind' => $kind,
			'code' => $code,
		);
		if ( 'php' === $kind ) {
			$entry['toks']  = cs_tokens( $code );
			$entry['funcs'] = cs_index_functions( $entry['toks'] );
		}
		$files[ $rel ] = $entry;
	}

	$findings = array();
	foreach ( $files as $file ) {
		if ( 'php' === $file['kind'] ) {
			cs_scan_php( $file, $files, $findings );
		} elseif ( 'js' === $file['kind'] ) {
			cs_scan_js( $file, $findings );
		} else {
			cs_scan_json( $file, $findings );
		}
	}
	foreach ( $policy as $p ) {
		$findings[] = cs_finding( 'policy', $p[0], 0, '?', $p[1], 'Build/runtime dependency signal; confirm it is development-only and recorded (northstar 2.1).' );
	}

	$report = array(
		'tool'        => 'cradle-security/check-surface',
		'root'        => $root,
		'rootKind'    => $root_kind,
		'targets'     => $rel_targets,
		'counts'      => $counts,
		'findings'    => $findings,
		'notes'       => $notes,
		'limitations' => 'Pattern search alone does not establish exploitability or prove safety. Confirm findings with allowed/denied-role and negative-case tests.',
	);

	if ( 'json' === $format ) {
		echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
		return 0;
	}
	cs_print_text( $report );
	return 0;
}

function cs_usage( $stream ) {
	fwrite( $stream, "Usage: php skills/cradle-security/scripts/check-surface.php <package-dir-or-file> [...] [--format=text|json] [--max-file-kb=N]\n" );
	fwrite( $stream, "Read-only inventory of request, file, SQL, execution and rendering entry points. Pattern search alone does not establish exploitability.\n" );
}

/* ---------------------------------------------------------------------------------------------
 * Paths
 * ------------------------------------------------------------------------------------------- */

function cs_norm( string $p ): string {
	$p = str_replace( '\\', '/', $p );
	$t = rtrim( $p, '/' );
	return '' === $t ? '/' : $t;
}

function cs_fold( string $p ): string {
	return '\\' === DIRECTORY_SEPARATOR ? strtolower( $p ) : $p;
}

function cs_same( string $a, string $b ): bool {
	return cs_fold( cs_norm( $a ) ) === cs_fold( cs_norm( $b ) );
}

function cs_within( string $path, string $root ): bool {
	$a = cs_fold( cs_norm( $path ) );
	$b = cs_fold( cs_norm( $root ) );
	return $a === $b || str_starts_with( $a, rtrim( $b, '/' ) . '/' );
}

function cs_rel( string $path, string $root ): string {
	$path = cs_norm( $path );
	$root = cs_norm( $root );
	return cs_same( $path, $root ) ? '.' : substr( $path, strlen( $root ) + 1 );
}

/**
 * Returns [root, description]. The root bounds every scan.
 */
function cs_detect_root(): array {
	$dir   = cs_norm( (string) realpath( __DIR__ ) );
	$probe = $dir;
	while ( true ) {
		if ( is_file( $probe . '/scripts/packages.json' ) && is_file( $probe . '/AGENTS.md' ) && is_dir( $probe . '/skills' ) ) {
			return array( $probe, 'Cradle framework repository' );
		}
		$parent = cs_norm( dirname( $probe ) );
		if ( $parent === $probe ) {
			break;
		}
		$probe = $parent;
	}
	$package   = cs_norm( dirname( $dir, 3 ) );
	$container = cs_norm( dirname( $package ) );
	$content   = cs_norm( dirname( $container ) );
	if ( in_array( basename( $container ), array( 'themes', 'plugins' ), true ) && is_dir( $content . '/themes' ) && is_dir( $content . '/plugins' ) ) {
		return array( $content, 'wp-content workspace (installed skill copy)' );
	}
	return array( $package, 'package containing this skill copy' );
}

function cs_collect( string $dir, string $root, array &$paths, array &$notes ) {
	$filter = new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO ),
		static function ( SplFileInfo $current ) use ( $root, &$notes ) {
			if ( $current->isLink() ) {
				$notes[] = 'Skipped symlink: ' . cs_rel( $current->getPathname(), $root );
				return false;
			}
			if ( $current->isDir() ) {
				return ! in_array( $current->getFilename(), CS_SKIP_DIRS, true );
			}
			return $current->isFile();
		}
	);
	$iterator = new RecursiveIteratorIterator( $filter );
	$list     = array();
	foreach ( $iterator as $file ) {
		$list[] = cs_norm( $file->getPathname() );
		if ( count( $list ) > CS_MAX_FILES * 4 ) {
			$notes[] = 'Directory walk stopped early in ' . cs_rel( $dir, $root ) . ' (too many files).';
			break;
		}
	}
	sort( $list );
	foreach ( $list as $p ) {
		$paths[ $p ] = true;
	}
}

/* ---------------------------------------------------------------------------------------------
 * Tokens
 * ------------------------------------------------------------------------------------------- */

/**
 * Significant tokens as [id|null, text, line]. Whitespace, comments and inline HTML are dropped.
 */
function cs_tokens( string $code ): array {
	$out  = array();
	$line = 1;
	$skip = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML );
	foreach ( @token_get_all( $code ) as $t ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- malformed files still tokenize.
		if ( is_array( $t ) ) {
			$line = $t[2];
			if ( ! in_array( $t[0], $skip, true ) ) {
				$out[] = array( $t[0], $t[1], $line );
			}
			$line += substr_count( $t[1], "\n" );
		} else {
			$out[] = array( null, $t, $line );
		}
	}
	return $out;
}

function cs_id( string $name ): int {
	return defined( $name ) ? constant( $name ) : -1;
}

function cs_member_ops(): array {
	return array( T_OBJECT_OPERATOR, cs_id( 'T_NULLSAFE_OBJECT_OPERATOR' ), T_DOUBLE_COLON );
}

function cs_opens( array $t ): bool {
	return ( null === $t[0] && ( '(' === $t[1] || '[' === $t[1] || '{' === $t[1] ) )
		|| in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, cs_id( 'T_ATTRIBUTE' ) ), true );
}

function cs_closes( array $t ): bool {
	return null === $t[0] && ( ')' === $t[1] || ']' === $t[1] || '}' === $t[1] );
}

function cs_match( array $toks, int $open ): int {
	$depth = 0;
	$n     = count( $toks );
	for ( $j = $open; $j < $n; $j++ ) {
		if ( cs_opens( $toks[ $j ] ) ) {
			$depth++;
		} elseif ( cs_closes( $toks[ $j ] ) ) {
			$depth--;
			if ( 0 === $depth ) {
				return $j;
			}
		}
	}
	return $n - 1;
}

/**
 * Lower-cased plain function name if $toks[$i] starts a plain function call, else null.
 */
function cs_call_name( array $toks, int $i ): ?string {
	$t = $toks[ $i ];
	if ( T_STRING === $t[0] ) {
		$name = $t[1];
	} elseif ( cs_id( 'T_NAME_FULLY_QUALIFIED' ) === $t[0] && 1 === substr_count( $t[1], '\\' ) ) {
		$name = ltrim( $t[1], '\\' );
	} else {
		return null;
	}
	if ( ! isset( $toks[ $i + 1 ] ) || '(' !== $toks[ $i + 1 ][1] ) {
		return null;
	}
	$prev = $i > 0 ? $toks[ $i - 1 ][0] : null;
	if ( in_array( $prev, array_merge( cs_member_ops(), array( T_FUNCTION, T_NEW, T_CONST ) ), true ) ) {
		return null;
	}
	return strtolower( $name );
}

/**
 * [operator, lower-cased method name] if $toks[$i] is a method/static call, else null.
 */
function cs_method_name( array $toks, int $i ): ?array {
	if ( T_STRING !== $toks[ $i ][0] || $i < 1 || ! isset( $toks[ $i + 1 ] ) || '(' !== $toks[ $i + 1 ][1] ) {
		return null;
	}
	if ( ! in_array( $toks[ $i - 1 ][0], cs_member_ops(), true ) ) {
		return null;
	}
	return array( $toks[ $i - 1 ][1], strtolower( $toks[ $i ][1] ) );
}

/**
 * Splits call arguments. Returns [list of token-index lists, index of closing paren].
 */
function cs_args( array $toks, int $open ): array {
	$args  = array();
	$cur   = array();
	$depth = 0;
	$n     = count( $toks );
	for ( $j = $open + 1; $j < $n; $j++ ) {
		$t = $toks[ $j ];
		if ( cs_opens( $t ) ) {
			$depth++;
		} elseif ( cs_closes( $t ) ) {
			if ( 0 === $depth ) {
				if ( $cur ) {
					$args[] = $cur;
				}
				return array( $args, $j );
			}
			$depth--;
		} elseif ( 0 === $depth && null === $t[0] && ',' === $t[1] ) {
			$args[] = $cur;
			$cur    = array();
			continue;
		}
		$cur[] = $j;
	}
	return array( $args, $n - 1 );
}

function cs_text( array $toks, array $idx, int $max = 96 ): string {
	$s         = '';
	$prev_word = false;
	foreach ( $idx as $j ) {
		$tx   = $toks[ $j ][1];
		$word = (bool) preg_match( '/^[\w$\\\\]/', $tx );
		if ( '' !== $s && $word && $prev_word ) {
			$s .= ' ';
		}
		if ( ',' === $tx ) {
			$s        .= ', ';
			$prev_word = false;
			continue;
		}
		if ( T_DOUBLE_ARROW === $toks[ $j ][0] ) {
			$s        .= ' => ';
			$prev_word = false;
			continue;
		}
		$s        .= $tx;
		$prev_word = (bool) preg_match( '/[\w$]$/', $tx );
	}
	return cs_clean( $s, $max );
}

function cs_unquote( string $s ): string {
	return strlen( $s ) >= 2 ? substr( $s, 1, -1 ) : $s;
}

function cs_literal( array $toks, array $idx ): ?string {
	if ( 1 === count( $idx ) && T_CONSTANT_ENCAPSED_STRING === $toks[ $idx[0] ][0] ) {
		return cs_unquote( $toks[ $idx[0] ][1] );
	}
	return null;
}

/**
 * Literal pattern of a string expression; non-literal parts become {…}.
 */
function cs_pattern( array $toks, array $idx ): string {
	$out = '';
	foreach ( $idx as $j ) {
		$t = $toks[ $j ];
		if ( T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
			$out .= cs_unquote( $t[1] );
		} elseif ( T_ENCAPSED_AND_WHITESPACE === $t[0] ) {
			$out .= $t[1];
		} elseif ( null === $t[0] && ( '.' === $t[1] || '"' === $t[1] ) ) {
			continue;
		} elseif ( ! str_ends_with( $out, '{…}' ) ) {
			$out .= '{…}';
		}
	}
	return cs_clean( $out, 120 );
}

function cs_has_var( array $toks, array $idx, array $ignore = array() ): array {
	$vars = array();
	foreach ( $idx as $j ) {
		if ( T_VARIABLE === $toks[ $j ][0] && ! in_array( $toks[ $j ][1], $ignore, true ) ) {
			$vars[ $toks[ $j ][1] ] = true;
		}
	}
	return array_keys( $vars );
}

function cs_has_superglobal( array $toks, array $idx ): bool {
	foreach ( $idx as $j ) {
		if ( T_VARIABLE === $toks[ $j ][0] && in_array( $toks[ $j ][1], CS_SUPERGLOBALS, true ) ) {
			return true;
		}
	}
	return false;
}

/* ---------------------------------------------------------------------------------------------
 * Function index and handler facts
 * ------------------------------------------------------------------------------------------- */

/**
 * Maps lower-cased function/method names to body token ranges [open-brace, close-brace].
 */
function cs_index_functions( array $toks ): array {
	$map = array();
	$n   = count( $toks );
	for ( $i = 0; $i < $n; $i++ ) {
		if ( T_FUNCTION !== $toks[ $i ][0] ) {
			continue;
		}
		$k = $i + 1;
		if ( isset( $toks[ $k ] ) && '&' === $toks[ $k ][1] ) {
			$k++;
		}
		if ( ! isset( $toks[ $k ] ) || T_STRING !== $toks[ $k ][0] ) {
			continue; // Closure; analysed inline where used as a callback.
		}
		$name = strtolower( $toks[ $k ][1] );
		while ( $k < $n && '(' !== $toks[ $k ][1] ) {
			$k++;
		}
		$b = cs_match( $toks, $k ) + 1;
		while ( $b < $n && '{' !== $toks[ $b ][1] && ';' !== $toks[ $b ][1] ) {
			$b++;
		}
		if ( $b >= $n || ';' === $toks[ $b ][1] ) {
			continue;
		}
		$map[ $name ][] = array( $b, cs_match( $toks, $b ) );
	}
	return $map;
}

/**
 * Finds a function body: same file first, then a unique match across scanned files.
 */
function cs_resolve( string $name, array $file, array $all, bool $same_file_only = false ): ?array {
	if ( isset( $file['funcs'][ $name ] ) && 1 === count( $file['funcs'][ $name ] ) ) {
		return array( $file, $file['funcs'][ $name ][0][0], $file['funcs'][ $name ][0][1] );
	}
	if ( $same_file_only || isset( $file['funcs'][ $name ] ) ) {
		return null;
	}
	$hits = array();
	foreach ( $all as $other ) {
		if ( 'php' === $other['kind'] && isset( $other['funcs'][ $name ] ) ) {
			foreach ( $other['funcs'][ $name ] as $range ) {
				$hits[] = array( $other, $range[0], $range[1] );
			}
		}
	}
	return 1 === count( $hits ) ? $hits[0] : null;
}

/**
 * What a handler body does: capability check, nonce check, login check, request reads.
 */
function cs_facts( array $file, int $start, int $end, array $all, int $depth = 0, array &$seen = array() ): array {
	$facts = array(
		'cap'   => false,
		'nonce' => false,
		'login' => false,
		'input' => false,
	);
	$toks  = $file['toks'];
	$calls = array();
	for ( $j = $start; $j <= $end && $j < count( $toks ); $j++ ) {
		$t = $toks[ $j ];
		if ( T_VARIABLE === $t[0] && in_array( $t[1], CS_SUPERGLOBALS, true ) ) {
			$facts['input'] = true;
		}
		$fn = cs_call_name( $toks, $j );
		if ( null !== $fn ) {
			$facts['cap']   = $facts['cap'] || in_array( $fn, CS_CAP_FUNCS, true );
			$facts['nonce'] = $facts['nonce'] || in_array( $fn, CS_NONCE_FUNCS, true );
			$facts['login'] = $facts['login'] || 'is_user_logged_in' === $fn;
			$facts['input'] = $facts['input'] || in_array( $fn, array( 'filter_input', 'filter_input_array' ), true );
			$calls[]        = array( $fn, false );
			continue;
		}
		$m = cs_method_name( $toks, $j );
		if ( null !== $m ) {
			$calls[] = array( $m[1], true );
		}
	}
	if ( $depth < 3 ) {
		foreach ( $calls as $call ) {
			$key = $file['rel'] . '|' . $call[0];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$range        = cs_resolve( $call[0], $file, $all, $call[1] );
			if ( null === $range ) {
				continue;
			}
			$sub = cs_facts( $range[0], $range[1], $range[2], $all, $depth + 1, $seen );
			foreach ( $facts as $k => $v ) {
				$facts[ $k ] = $v || $sub[ $k ];
			}
		}
	}
	return $facts;
}

/**
 * Resolves a callback argument to handler facts, or null when it cannot be resolved statically.
 */
function cs_callback_facts( array $file, array $idx, array $all ): ?array {
	if ( ! $idx ) {
		return null;
	}
	$toks  = $file['toks'];
	$first = $toks[ $idx[0] ];
	$k     = $idx[0];
	if ( T_STATIC === $first[0] && isset( $idx[1] ) ) {
		$k = $idx[1];
	}
	if ( T_FUNCTION === $toks[ $k ][0] ) {
		$b    = $k;
		$last = end( $idx );
		while ( $b <= $last && '{' !== $toks[ $b ][1] ) {
			$b++;
		}
		return $b <= $last ? cs_facts( $file, $b, cs_match( $toks, $b ), $all ) : null;
	}
	if ( T_FN === $toks[ $k ][0] ) {
		return cs_facts( $file, $k, end( $idx ), $all );
	}
	$literal = cs_literal( $toks, $idx );
	if ( null !== $literal ) {
		$name  = strtolower( str_contains( $literal, '::' ) ? substr( $literal, strrpos( $literal, ':' ) + 1 ) : $literal );
		$range = cs_resolve( $name, $file, $all );
		return $range ? cs_facts( $range[0], $range[1], $range[2], $all ) : null;
	}
	// array( $this, 'method' ), [ __CLASS__, 'method' ], array( 'Class', 'method' ).
	if ( T_ARRAY === $first[0] || '[' === $first[1] ) {
		$method = null;
		foreach ( $idx as $j ) {
			if ( T_CONSTANT_ENCAPSED_STRING === $toks[ $j ][0] ) {
				$method = strtolower( cs_unquote( $toks[ $j ][1] ) );
			}
		}
		if ( null !== $method ) {
			$range = cs_resolve( $method, $file, $all );
			return $range ? cs_facts( $range[0], $range[1], $range[2], $all ) : null;
		}
	}
	// First-class callable syntax: $this->method( ... ).
	foreach ( $idx as $j ) {
		if ( T_ELLIPSIS === $toks[ $j ][0] && $j >= 2 && T_STRING === $toks[ $j - 2 ][0] ) {
			$range = cs_resolve( strtolower( $toks[ $j - 2 ][1] ), $file, $all );
			return $range ? cs_facts( $range[0], $range[1], $range[2], $all ) : null;
		}
	}
	return null;
}

function cs_guard_text( ?array $facts, bool $want_nonce = true ): array {
	if ( null === $facts ) {
		return array( '?', 'handler not resolved in scanned files; inspect manually' );
	}
	$parts = array( 'capability: ' . ( $facts['cap'] ? 'seen' : 'NOT SEEN' ) );
	if ( $want_nonce ) {
		$parts[] = 'nonce: ' . ( $facts['nonce'] ? 'seen' : 'NOT SEEN' );
	}
	if ( $facts['input'] ) {
		$parts[] = 'reads request data';
	}
	$level = ( ! $facts['cap'] || ( $want_nonce && ! $facts['nonce'] ) ) ? '!' : '-';
	return array( $level, implode( ', ', $parts ) );
}

/* ---------------------------------------------------------------------------------------------
 * Scanners
 * ------------------------------------------------------------------------------------------- */

function cs_finding( string $cat, string $file, int $line, string $level, string $what, string $detail = '' ): array {
	return array(
		'category' => $cat,
		'level'    => $level,
		'file'     => $file,
		'line'     => $line,
		'what'     => cs_clean( $what, 140 ),
		'detail'   => cs_clean( $detail, 220 ),
	);
}

function cs_hook_category( string $hook ): ?string {
	$prefixes = array(
		'wp_ajax_nopriv_'           => 'ajax-nopriv',
		'wp_ajax_'                  => 'ajax',
		'admin_post_nopriv_'        => 'admin-post-nopriv',
		'admin_post_'               => 'admin-post',
		'admin_action_'             => 'admin-action',
		'login_form_'               => 'auth',
		'auth_cookie'               => 'auth',
		'retrieve_password'         => 'auth',
		'lostpassword_'             => 'auth',
		'application_password'      => 'auth',
		'wp_is_application_passwords' => 'auth',
		'gettext'                   => 'i18n',
		'ngettext'                  => 'i18n',
		'load_textdomain'           => 'i18n',
		'load_script_translation'   => 'i18n',
		'option_page_capability_'   => 'privilege',
		'save_post'                 => 'save',
		'rest_'                     => 'rest-hook',
	);
	$exact    = array(
		'rest_api_init'              => 'rest-hook',
		'login_init'                 => 'auth',
		'authenticate'               => 'auth',
		'wp_authenticate'            => 'auth',
		'wp_login'                   => 'auth',
		'wp_logout'                  => 'auth',
		'wp_login_failed'            => 'auth',
		'password_reset'             => 'auth',
		'after_password_reset'       => 'auth',
		'validate_password_reset'    => 'auth',
		'login_url'                  => 'auth',
		'logout_url'                 => 'auth',
		'register_url'               => 'auth',
		'site_url'                   => 'auth',
		'network_site_url'           => 'auth',
		'wp_redirect'                => 'auth',
		'login_redirect'             => 'auth',
		'logout_redirect'            => 'auth',
		'allowed_redirect_hosts'     => 'auth',
		'determine_current_user'     => 'auth',
		'registration_errors'        => 'auth',
		'register_post'              => 'auth',
		'user_has_cap'               => 'privilege',
		'map_meta_cap'               => 'privilege',
		'editable_roles'             => 'privilege',
		'upload_mimes'               => 'upload',
		'mime_types'                 => 'upload',
		'wp_handle_upload_prefilter' => 'upload',
		'wp_handle_sideload_prefilter' => 'upload',
		'wp_handle_upload'           => 'upload',
		'wp_check_filetype_and_ext'  => 'upload',
		'pre_move_uploaded_file'     => 'upload',
		'font_dir'                   => 'upload',
		'upload_dir'                 => 'upload',
		'override_load_textdomain'   => 'i18n',
		'pre_load_script_translations' => 'i18n',
		'locale'                     => 'i18n',
		'determine_locale'           => 'i18n',
		'wp_insert_post_data'        => 'save',
		'edit_post'                  => 'save',
		'pre_post_update'            => 'save',
		'delete_post'                => 'save',
		'before_delete_post'         => 'save',
		'wp_trash_post'              => 'save',
		'edited_term'                => 'save',
		'personal_options_update'    => 'save',
		'edit_user_profile_update'   => 'save',
		'cron_schedules'             => 'cron',
		'init'                       => 'request',
		'admin_init'                 => 'request',
		'wp_loaded'                  => 'request',
		'template_redirect'          => 'request',
		'parse_request'              => 'request',
		'wp'                         => 'request',
		'send_headers'               => 'request',
		'plugins_loaded'             => 'request',
		'setup_theme'                => 'request',
		'after_setup_theme'          => 'request',
		'current_screen'             => 'request',
	);
	if ( isset( $exact[ $hook ] ) ) {
		return $exact[ $hook ];
	}
	foreach ( $prefixes as $prefix => $cat ) {
		if ( str_starts_with( $hook, $prefix ) ) {
			return $cat;
		}
	}
	if ( str_starts_with( $hook, 'load-' ) ) {
		return 'request';
	}
	return null;
}

function cs_scan_php( array $file, array $all, array &$findings ) {
	$toks     = $file['toks'];
	$rel      = $file['rel'];
	$n        = count( $toks );
	$inputs   = array();
	$backtick = false;

	for ( $i = 0; $i < $n; $i++ ) {
		$t    = $toks[ $i ];
		$line = $t[2];

		// Request data reads.
		if ( T_VARIABLE === $t[0] && in_array( $t[1], CS_SUPERGLOBALS, true ) ) {
			$key = '';
			if ( isset( $toks[ $i + 2 ] ) && '[' === $toks[ $i + 1 ][1] ) {
				$key = T_CONSTANT_ENCAPSED_STRING === $toks[ $i + 2 ][0] ? cs_unquote( $toks[ $i + 2 ][1] ) : '…';
			}
			$inputs[ $t[1] . ( '' !== $key ? "['" . $key . "']" : '' ) . ':' . $line ] = true;
			if ( '$_SERVER' === $t[1] && preg_match( '/^HTTP_(X_FORWARDED|X_REAL_IP|CLIENT_IP|FORWARDED|CF_CONNECTING_IP)/', $key ) ) {
				$findings[] = cs_finding( 'input', $rel, $line, '?', '$_SERVER[\'' . $key . '\']', 'Client-supplied IP/forwarding header; untrusted unless a trusted proxy policy is configured (northstar 20.5).' );
			}
			continue;
		}

		// Direct output of request data.
		if ( in_array( $t[0], array( T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO ), true ) && isset( $toks[ $i + 1 ] ) && T_VARIABLE === $toks[ $i + 1 ][0] && in_array( $toks[ $i + 1 ][1], CS_SUPERGLOBALS, true ) ) {
			$findings[] = cs_finding( 'output', $rel, $line, '!', trim( $t[1] ) . ' ' . $toks[ $i + 1 ][1], 'Request data echoed without an escaping call in the same expression.' );
		}

		// eval, includes, backticks.
		if ( T_EVAL === $t[0] ) {
			$findings[] = cs_finding( 'exec', $rel, $line, '!', 'eval()', 'Never acceptable for translations, formulas, attributes or configuration (northstar 20.4).' );
			continue;
		}
		if ( in_array( $t[0], array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ), true ) ) {
			$expr = array();
			for ( $j = $i + 1; $j < $n && ';' !== $toks[ $j ][1]; $j++ ) {
				$expr[] = $j;
			}
			$text = cs_text( $toks, $expr );
			$vars = cs_has_var( $toks, $expr );
			if ( str_contains( strtolower( $text ), 'vendor/autoload' ) ) {
				$findings[] = cs_finding( 'policy', $rel, $line, '?', strtolower( $t[1] ) . ' ' . $text, 'Composer autoloader; runtime Composer packages are not allowed without a recorded decision (northstar 2.1).' );
			}
			if ( $vars ) {
				$level      = cs_has_superglobal( $toks, $expr ) ? '!' : '?';
				$findings[] = cs_finding( 'exec', $rel, $line, $level, strtolower( $t[1] ) . ' ' . $text, 'Variable include path (' . implode( ', ', $vars ) . '); confirm it is built from an allowlist and canonicalized inside a known root.' );
			}
			continue;
		}
		if ( null === $t[0] && '`' === $t[1] ) {
			$backtick = ! $backtick;
			if ( $backtick ) {
				$findings[] = cs_finding( 'exec', $rel, $line, '!', 'backtick shell execution', 'Shell execution is not allowed in Cradle packages (northstar 20.4).' );
			}
			continue;
		}

		// new ZipArchive / PharData / $dynamic.
		if ( T_NEW === $t[0] && isset( $toks[ $i + 1 ] ) ) {
			$class = ltrim( $toks[ $i + 1 ][1], '\\' );
			if ( in_array( strtolower( $class ), array( 'ziparchive', 'phardata', 'phar' ), true ) ) {
				$findings[] = cs_finding( 'file', $rel, $line, '?', 'new ' . $class, 'Archive handling: enforce path traversal, symlink, size, count and ratio limits before extraction (northstar 15.5).' );
			} elseif ( T_VARIABLE === $toks[ $i + 1 ][0] ) {
				$findings[] = cs_finding( 'dynamic', $rel, $line, '-', 'new ' . $toks[ $i + 1 ][1], 'Dynamic class instantiation; confirm the class name comes from trusted code.' );
			}
			continue;
		}

		// Variable function call: $fn( ... ).
		if ( T_VARIABLE === $t[0] && isset( $toks[ $i + 1 ] ) && '(' === $toks[ $i + 1 ][1] && ( $i < 1 || ! in_array( $toks[ $i - 1 ][0], cs_member_ops(), true ) ) && ( $i < 1 || T_FUNCTION !== $toks[ $i - 1 ][0] ) ) {
			$findings[] = cs_finding( 'dynamic', $rel, $line, '-', $t[1] . '( … )', 'Variable function call; confirm the callable is trusted code, never a stored setting.' );
			continue;
		}

		// Method calls.
		$m = cs_method_name( $toks, $i );
		if ( null !== $m ) {
			cs_scan_method( $file, $i, $m, $all, $findings );
			continue;
		}

		$fn = cs_call_name( $toks, $i );
		if ( null === $fn ) {
			continue;
		}
		list( $args ) = cs_args( $toks, $i + 1 );
		$arg0         = $args[0] ?? array();

		switch ( true ) {
			case in_array( $fn, array( 'add_action', 'add_filter' ), true ):
				if ( ! $arg0 ) {
					break;
				}
				$hook = cs_pattern( $toks, $arg0 );
				$cat  = cs_hook_category( $hook );
				if ( null === $cat ) {
					break;
				}
				$cb_text = isset( $args[1] ) ? cs_text( $toks, $args[1], 60 ) : '(no callback)';
				$facts   = isset( $args[1] ) ? cs_callback_facts( $file, $args[1], $all ) : null;
				if ( in_array( $cat, array( 'ajax', 'admin-post', 'admin-action', 'save' ), true ) ) {
					list( $level, $guard ) = cs_guard_text( $facts, true );
					if ( 'save' === $cat ) {
						if ( null !== $facts && ! $facts['input'] ) {
							$level = '-';
							$guard = 'handler does not read request data directly';
						}
					}
					$findings[] = cs_finding( $cat, $rel, $line, $level, $hook . ' -> ' . $cb_text, $guard );
				} elseif ( in_array( $cat, array( 'ajax-nopriv', 'admin-post-nopriv' ), true ) ) {
					list( , $guard ) = cs_guard_text( $facts, true );
					$findings[]      = cs_finding( $cat, $rel, $line, '!', $hook . ' -> ' . $cb_text, 'Reachable without login. ' . $guard . '. Confirm it is intentionally public, bounded and rate-aware.' );
				} elseif ( 'request' === $cat ) {
					if ( null !== $facts && $facts['input'] ) {
						list( $level, $guard ) = cs_guard_text( $facts, true );
						$findings[]            = cs_finding( 'request', $rel, $line, $level, $hook . ' -> ' . $cb_text, $guard . '. Early hooks run for every visitor; confirm the mutation path is authorized.' );
					}
				} else {
					$level      = in_array( $cat, array( 'auth', 'privilege', 'upload' ), true ) ? '?' : '-';
					$findings[] = cs_finding( $cat, $rel, $line, $level, $fn . ' ' . $hook . ' -> ' . $cb_text );
				}
				break;

			case 'register_rest_route' === $fn:
				cs_scan_rest_route( $file, $i, $args, $all, $findings );
				break;

			case 'add_shortcode' === $fn:
				$findings[] = cs_finding( 'shortcode', $rel, $line, '-', '[' . cs_pattern( $toks, $arg0 ) . '] -> ' . ( isset( $args[1] ) ? cs_text( $toks, $args[1], 60 ) : '?' ), 'Validate attributes against the component schema and escape output by context.' );
				break;

			case in_array( $fn, array( 'register_block_type', 'register_block_type_from_metadata' ), true ):
				$text   = isset( $args[1] ) ? cs_text( $toks, $args[1], 400 ) : '';
				$render = str_contains( $text, "'render_callback'" ) || str_contains( $text, '"render_callback"' );
				$findings[] = cs_finding( 'block', $rel, $line, '-', $fn . '( ' . cs_text( $toks, $arg0, 70 ) . ' )', $render ? 'render_callback declared: treat attributes/inner content as untrusted and escape.' : ( isset( $args[1] ) ? 'args without render_callback (static block or block.json render file)' : 'metadata only; check block.json "render"' ) );
				break;

			case isset( CS_ADMIN_PAGES[ $fn ] ):
				$pos  = CS_ADMIN_PAGES[ $fn ];
				$cap  = isset( $args[ $pos ] ) ? cs_literal( $toks, $args[ $pos ] ) : null;
				$shown = null !== $cap ? $cap : ( isset( $args[ $pos ] ) ? cs_text( $toks, $args[ $pos ], 50 ) : '?' );
				$level = ( null === $cap || in_array( $cap, array( 'read', 'exist', 'level_0' ), true ) ) ? '?' : '-';
				$findings[] = cs_finding( 'admin-page', $rel, $line, $level, $fn . ' capability=' . $shown, 'A menu capability is not an authorization check for actions; each handler must check again.' );
				break;

			case 'register_setting' === $fn:
				$text       = isset( $args[2] ) ? cs_text( $toks, $args[2], 400 ) : '';
				$sanitize   = str_contains( $text, 'sanitize_callback' );
				$findings[] = cs_finding( 'settings', $rel, $line, $sanitize ? '-' : '?', 'register_setting( ' . cs_text( $toks, $arg0, 40 ) . ', ' . ( isset( $args[1] ) ? cs_text( $toks, $args[1], 40 ) : '?' ) . ' )', $sanitize ? 'sanitize_callback declared' : 'sanitize_callback not seen in the call; verify validation happens elsewhere' );
				break;

			case in_array( $fn, array( 'wp_schedule_event', 'wp_schedule_single_event', 'as_schedule_single_action', 'as_schedule_recurring_action', 'as_enqueue_async_action' ), true ):
				$hook_arg   = 'wp_schedule_event' === $fn ? ( $args[2] ?? array() ) : ( 'wp_schedule_single_event' === $fn ? ( $args[1] ?? array() ) : ( 'as_schedule_recurring_action' === $fn ? ( $args[2] ?? array() ) : ( 'as_enqueue_async_action' === $fn ? $arg0 : ( $args[1] ?? array() ) ) ) );
				$hook_name  = cs_pattern( $toks, $hook_arg );
				$findings[] = cs_finding( 'cron', $rel, $line, '-', $fn . ' ' . ( '{…}' === $hook_name ? cs_text( $toks, $hook_arg, 60 ) : $hook_name ), 'Background work runs without a user; authorize at job creation and keep steps idempotent.' );
				break;

			case in_array( $fn, array( 'unserialize', 'maybe_unserialize' ), true ):
				$text       = cs_text( $toks, $arg0, 60 ) . ( isset( $args[1] ) ? ', ' . cs_text( $toks, $args[1], 40 ) : '' );
				$findings[] = cs_finding( 'exec', $rel, $line, 'unserialize' === $fn ? '!' : '?', $fn . '( ' . $text . ' )', 'Never deserialize untrusted archives/imports; allowed_classes=false alone is not a complete policy (northstar 15.7).' );
				break;

			case in_array( $fn, CS_SHELL_FUNCS, true ):
				$findings[] = cs_finding( 'exec', $rel, $line, '!', $fn . '()', 'Shell execution is not allowed in Cradle packages (northstar 20.4).' );
				break;

			case 'create_function' === $fn:
				$findings[] = cs_finding( 'exec', $rel, $line, '!', 'create_function()', 'Code evaluation primitive (removed in PHP 8); never acceptable.' );
				break;

			case 'assert' === $fn:
				$string_arg = $arg0 && ( T_CONSTANT_ENCAPSED_STRING === $toks[ $arg0[0] ][0] || cs_has_var( $toks, $arg0 ) );
				$findings[] = cs_finding( 'exec', $rel, $line, $string_arg ? '!' : '-', 'assert( ' . cs_text( $toks, $arg0, 60 ) . ' )', 'Do not rely on assert() for validation; string assertions evaluate code on legacy runtimes.' );
				break;

			case 'extract' === $fn:
				$findings[] = cs_finding( 'exec', $rel, $line, cs_has_superglobal( $toks, $arg0 ) ? '!' : '?', 'extract( ' . cs_text( $toks, $arg0, 60 ) . ' )', 'Creates variables from array keys; never use with request or stored data.' );
				break;

			case 'parse_str' === $fn && count( $args ) < 2:
				$findings[] = cs_finding( 'exec', $rel, $line, '!', 'parse_str( single argument )', 'Single-argument parse_str() overwrites local variables.' );
				break;

			case in_array( $fn, array( 'call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array' ), true ):
				if ( $arg0 && null === cs_literal( $toks, $arg0 ) ) {
					$findings[] = cs_finding( 'dynamic', $rel, $line, cs_has_superglobal( $toks, $arg0 ) ? '!' : '-', $fn . '( ' . cs_text( $toks, $arg0, 60 ) . ', … )', 'Dynamic callable; confirm it is registered by trusted PHP, never taken from settings or requests.' );
				}
				break;

			case in_array( $fn, CS_FILE_FUNCS, true ):
				$vars  = cs_has_var( $toks, $arg0 );
				$super = cs_has_superglobal( $toks, $arg0 ) || ( 'move_uploaded_file' === $fn );
				$level = $super ? '!' : ( $vars ? '?' : '-' );
				if ( in_array( $fn, array( 'file_get_contents', 'file', 'fopen', 'readfile' ), true ) && ! $vars ) {
					$lit = cs_pattern( $toks, $arg0 );
					if ( str_starts_with( $lit, 'php://input' ) ) {
						$findings[] = cs_finding( 'input', $rel, $line, '?', $fn . '( php://input )', 'Raw request body read; validate size, type and schema.' );
						break;
					}
				}
				$findings[] = cs_finding( 'file', $rel, $line, $level, $fn . '( ' . cs_text( $toks, $arg0, 70 ) . ' )', $super ? 'Path or file derived from request data: canonicalize inside a known root, validate type/signature/size.' : ( $vars ? 'Variable path: confirm canonical containment (Cradle\\Core\\Storage::path_within) and symlink handling.' : '' ) );
				break;

			case in_array( $fn, CS_REMOTE_FUNCS, true ):
				$safe       = str_starts_with( $fn, 'wp_safe_remote_' );
				$findings[] = cs_finding( 'remote', $rel, $line, $safe ? '-' : '?', $fn . '( ' . cs_text( $toks, $arg0, 70 ) . ' )', $safe ? 'Safe HTTP API; still restrict hosts/schemes, redirects, size and timeout.' : 'Not the wp_safe_remote_* variant: restrict hosts/schemes, reject private destinations, bound size/time (northstar 20.4).' );
				break;

			case in_array( $fn, array( 'wp_redirect', 'wp_safe_redirect' ), true ):
				$dynamic    = (bool) cs_has_var( $toks, $arg0 ) || (bool) array_filter( $arg0, static fn( $j ) => T_STRING === $toks[ $j ][0] );
				$level      = 'wp_redirect' === $fn && $dynamic ? '?' : '-';
				$findings[] = cs_finding( 'redirect', $rel, $line, $level, $fn . '( ' . cs_text( $toks, $arg0, 70 ) . ' )', 'wp_redirect' === $fn ? 'Use wp_safe_redirect() or validate the target host.' : '' );
				break;

			case in_array( $fn, array( 'add_role', 'remove_role' ), true ):
				$findings[] = cs_finding( 'privilege', $rel, $line, '?', $fn . '( ' . cs_text( $toks, $arg0, 60 ) . ' )', 'Role change; confirm lifecycle (activation/uninstall) and least privilege.' );
				break;

			case in_array( $fn, array( 'filter_input', 'filter_input_array', 'getallheaders', 'apache_request_headers' ), true ):
				$inputs[ $fn . '():' . $line ] = true;
				break;
		}
	}

	if ( $inputs ) {
		$inputs     = array_keys( $inputs );
		$findings[] = cs_finding( 'input', $rel, 0, '-', count( $inputs ) . ' read location(s)', implode( ' ', array_slice( $inputs, 0, 14 ) ) . ( count( $inputs ) > 14 ? ' …' : '' ) );
	}
}

function cs_scan_method( array $file, int $i, array $m, array $all, array &$findings ) {
	$toks = $file['toks'];
	$rel  = $file['rel'];
	$line = $toks[ $i ][2];
	list( $op, $name ) = $m;
	list( $args )      = cs_args( $toks, $i + 1 );
	$arg0              = $args[0] ?? array();
	$object            = $i >= 2 ? $toks[ $i - 2 ][1] : '';

	if ( '$wpdb' === $object && in_array( $name, CS_SQL_METHODS, true ) ) {
		if ( ! $arg0 ) {
			return;
		}
		$is_prepared = isset( $arg0[2] ) && '$wpdb' === $toks[ $arg0[0] ][1] && 'prepare' === strtolower( $toks[ $arg0[2] ][1] );
		if ( ! $is_prepared ) {
			foreach ( $arg0 as $j ) {
				if ( T_STRING === $toks[ $j ][0] && 'prepare' === strtolower( $toks[ $j ][1] ) ) {
					$is_prepared = true; // Conditional/ternary prepare() calls.
				}
			}
		}
		if ( $is_prepared ) {
			return; // Reported at the prepare() call.
		}
		$vars = cs_has_var( $toks, $arg0, array( '$wpdb' ) );
		if ( ! $vars ) {
			$findings[] = cs_finding( 'sql', $rel, $line, '-', '$wpdb->' . $name . '( static SQL )', 'No variables except $wpdb table names.' );
		} elseif ( 1 === count( $arg0 ) && T_VARIABLE === $toks[ $arg0[0] ][0] ) {
			$findings[] = cs_finding( 'sql', $rel, $line, '?', '$wpdb->' . $name . '( ' . $toks[ $arg0[0] ][1] . ' )', 'SQL built elsewhere; trace whether it was prepared.' );
		} else {
			$findings[] = cs_finding( 'sql', $rel, $line, cs_has_superglobal( $toks, $arg0 ) ? '!' : '?', '$wpdb->' . $name . '( ' . cs_text( $toks, $arg0, 80 ) . ' )', 'SQL with variables (' . implode( ', ', $vars ) . ') and no prepare(): values need placeholders; identifiers need a strict allowlist.' );
		}
		return;
	}

	if ( '$wpdb' === $object && 'prepare' === $name && $arg0 ) {
		$vars = cs_has_var( $toks, $arg0, array( '$wpdb' ) );
		if ( $vars ) {
			$findings[] = cs_finding( 'sql', $rel, $line, cs_has_superglobal( $toks, $arg0 ) ? '!' : '?', '$wpdb->prepare() with interpolated fragment ' . implode( ', ', $vars ), 'Placeholders do not protect identifiers/lists; confirm each interpolated fragment is an allowlisted identifier or internally built.' );
		}
		return;
	}

	if ( 'add_command' === $name && '::' === $op && $i >= 2 && 'WP_CLI' === ltrim( $toks[ $i - 2 ][1], '\\' ) ) {
		$findings[] = cs_finding( 'cli', $rel, $line, '-', 'WP_CLI::add_command( ' . cs_text( $toks, $arg0, 50 ) . ' )', 'CLI runs with shell-level trust; still validate arguments and share the authorization-independent engine safely.' );
		return;
	}

	if ( in_array( $name, array( 'add_cap', 'remove_cap' ), true ) ) {
		$findings[] = cs_finding( 'privilege', $rel, $line, '?', '->' . $name . '( ' . cs_text( $toks, $arg0, 60 ) . ' )', 'Capability grant/revoke; do not grant high-impact caps to editors/shop managers by default (northstar 6.3).' );
		return;
	}

	if ( 'register_from_file' === $name ) {
		$findings[] = cs_finding( 'component', $rel, $line, '-', '->register_from_file( ' . cs_text( $toks, $arg0, 70 ) . ' )', 'Render callable receives normalized props; it must escape output and tolerate missing entities.' );
		return;
	}

	if ( 'register_rest_route' === $name ) {
		cs_scan_rest_route( $file, $i, $args, $all, $findings );
	}
}

function cs_scan_rest_route( array $file, int $i, array $args, array $all, array &$findings ) {
	$toks  = $file['toks'];
	$rel   = $file['rel'];
	$line  = $toks[ $i ][2];
	$route = cs_pattern( $toks, $args[0] ?? array() ) . cs_pattern( $toks, $args[1] ?? array() );
	$spec  = $args[2] ?? array();
	if ( ! $spec ) {
		$findings[] = cs_finding( 'rest', $rel, $line, '!', $route, 'No route arguments: permission_callback missing.' );
		return;
	}
	if ( ! in_array( $toks[ $spec[0] ][0], array( T_ARRAY ), true ) && '[' !== $toks[ $spec[0] ][1] ) {
		$findings[] = cs_finding( 'rest', $rel, $line, '?', $route, 'Route arguments built elsewhere (' . cs_text( $toks, $spec, 40 ) . '); inspect permission_callback manually.' );
		return;
	}
	$callbacks = 0;
	$perms     = array();
	$schema    = false;
	$count     = count( $spec );
	for ( $k = 0; $k < $count; $k++ ) {
		$j = $spec[ $k ];
		if ( T_CONSTANT_ENCAPSED_STRING !== $toks[ $j ][0] || ! isset( $spec[ $k + 1 ] ) || T_DOUBLE_ARROW !== $toks[ $spec[ $k + 1 ] ][0] ) {
			continue;
		}
		$key = cs_unquote( $toks[ $j ][1] );
		if ( 'callback' === $key ) {
			$callbacks++;
		} elseif ( in_array( $key, array( 'args', 'schema', 'validate_callback', 'sanitize_callback' ), true ) ) {
			$schema = true;
		} elseif ( 'permission_callback' === $key ) {
			// Value tokens: until the next comma/closer at the same depth.
			$value = array();
			$depth = 0;
			for ( $v = $k + 2; $v < $count; $v++ ) {
				$tv = $toks[ $spec[ $v ] ];
				if ( cs_opens( $tv ) ) {
					$depth++;
				} elseif ( cs_closes( $tv ) ) {
					if ( 0 === $depth ) {
						break;
					}
					$depth--;
				} elseif ( 0 === $depth && ',' === $tv[1] && null === $tv[0] ) {
					break;
				}
				$value[] = $spec[ $v ];
			}
			$perms[] = $value;
		}
	}
	if ( ! $perms ) {
		$findings[] = cs_finding( 'rest', $rel, $line, '!', $route, 'permission_callback missing (' . $callbacks . ' callback(s)). Every route needs a deliberate permission_callback (northstar 20.2).' );
		return;
	}
	$details = array();
	$level   = '-';
	foreach ( $perms as $value ) {
		$literal = cs_literal( $toks, $value );
		if ( '__return_true' === $literal ) {
			$details[] = 'PUBLIC (__return_true): confirm intentionally public, data-limited response';
			$level     = '!' === $level ? '!' : '?';
			continue;
		}
		$facts = cs_callback_facts( $file, $value, $all );
		if ( null === $facts ) {
			$details[] = 'permission_callback ' . cs_text( $toks, $value, 40 ) . ' not resolved';
			$level     = '!' === $level ? '!' : '?';
		} elseif ( ! $facts['cap'] ) {
			$details[] = 'permission_callback ' . cs_text( $toks, $value, 40 ) . ': capability NOT SEEN';
			$level     = '!';
		} else {
			$details[] = 'permission_callback ' . cs_text( $toks, $value, 40 ) . ': capability seen';
		}
	}
	if ( $callbacks > count( $perms ) ) {
		$details[] = ( $callbacks - count( $perms ) ) . ' endpoint(s) without permission_callback';
		$level     = '!';
	}
	$details[]  = $schema ? 'arg schema/validation declared' : 'no args schema seen';
	$findings[] = cs_finding( 'rest', $rel, $line, $level, $route, implode( '; ', $details ) );
}

function cs_scan_js( array $file, array &$findings ) {
	$checks = array(
		array( '/\.(innerHTML|outerHTML)\s*\+?=(?!=)/', '?', 'DOM HTML sink', 'Use textContent/createElement; never insert untrusted strings as HTML (northstar 20.3).' ),
		array( '/insertAdjacentHTML\s*\(|document\.write(ln)?\s*\(/', '?', 'DOM HTML sink', 'Use safe DOM APIs for untrusted strings.' ),
		array( '/\bRawHTML\b|dangerouslySetInnerHTML/', '?', 'Raw HTML in editor element', 'Only for sanitized, trusted markup.' ),
		array( '/\beval\s*\(|\bnew\s+Function\s*\(|set(Timeout|Interval)\s*\(\s*[\'"`]/', '!', 'eval-like execution', 'Not allowed in Cradle code.' ),
		array( '/\bjQuery\b|\$\s*\(\s*document\s*\)/', '?', 'jQuery reference', 'Vanilla-code rule: no jQuery-based custom code (northstar 2.1).' ),
		array( '/^\s*import\s[^;]*\sfrom\s+[\'"][^.\/\'"]|\brequire\s*\(\s*[\'"][^.\/\'"]/', '?', 'bare module import', 'Implies a build chain or npm runtime package; use wp.* globals via declared dependencies (northstar 2.1).' ),
		array( '/\bfetch\s*\(|\bapiFetch\s*\(|XMLHttpRequest/', '-', 'network request', 'Server must enforce capability/nonce; REST calls send X-WP-Nonce via apiFetch.' ),
	);
	$lines = preg_split( '/\r\n|\r|\n/', $file['code'] );
	foreach ( $lines as $num => $text ) {
		$trim = ltrim( $text );
		if ( str_starts_with( $trim, '//' ) || str_starts_with( $trim, '*' ) || str_starts_with( $trim, '/*' ) ) {
			continue;
		}
		foreach ( $checks as $check ) {
			if ( preg_match( $check[0], $text ) ) {
				$findings[] = cs_finding( 'js', $file['rel'], $num + 1, $check[1], $check[2] . ': ' . trim( $text ), $check[3] );
			}
		}
	}
}

function cs_scan_json( array $file, array &$findings ) {
	$data = json_decode( $file['code'], true, 32 );
	if ( ! is_array( $data ) ) {
		$findings[] = cs_finding( 'block', $file['rel'], 0, '?', 'invalid JSON', 'Could not parse (depth limit 32).' );
		return;
	}
	if ( 'component.json' === strtolower( basename( $file['rel'] ) ) ) {
		$findings[] = cs_finding( 'component', $file['rel'], 0, '-', (string) ( $data['id'] ?? '?' ) . ' contract=' . (string) ( $data['renderContract'] ?? '?' ), 'themeOverride=' . (string) ( $data['themeOverride'] ?? '' ) . ' (resolved only from allowlisted child/parent paths).' );
		return;
	}
	$render = isset( $data['render'] ) && is_string( $data['render'] ) ? $data['render'] : '';
	$api    = (int) ( $data['apiVersion'] ?? 1 );
	$notes  = array( 'apiVersion ' . $api );
	if ( '' !== $render ) {
		$notes[] = 'dynamic render ' . $render . ': escape attributes/content';
	}
	foreach ( array( 'viewScript', 'viewScriptModule', 'script' ) as $key ) {
		if ( isset( $data[ $key ] ) ) {
			$notes[] = $key . ' present';
		}
	}
	$findings[] = cs_finding( 'block', $file['rel'], 0, $api < 3 ? '?' : '-', (string) ( $data['name'] ?? '?' ), implode( '; ', $notes ) );
}

/* ---------------------------------------------------------------------------------------------
 * Output
 * ------------------------------------------------------------------------------------------- */

function cs_clean( string $s, int $max = 200 ): string {
	$s = preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/', '?', $s );
	$s = preg_replace( '/\s+/', ' ', (string) $s );
	if ( function_exists( 'mb_strimwidth' ) ) {
		return mb_strimwidth( (string) $s, 0, $max, '…', 'UTF-8' );
	}
	return strlen( (string) $s ) > $max ? substr( (string) $s, 0, $max ) . '...' : (string) $s;
}

function cs_print_text( array $report ) {
	$by_cat = array();
	foreach ( $report['findings'] as $f ) {
		$by_cat[ $f['category'] ][] = $f;
	}
	$c = $report['counts'];
	echo "Cradle security surface inventory (read-only)\n";
	echo 'Root:    ' . $report['root'] . ' (' . $report['rootKind'] . ")\n";
	echo 'Targets: ' . implode( ', ', $report['targets'] ) . "\n";
	echo "Files:   {$c['php']} PHP, {$c['js']} JS, {$c['json']} block/component JSON scanned; {$c['skipped']} skipped\n";
	echo "Legend:  ! priority manual review   ? needs manual review   - inventory\n";
	$priority = 0;
	$review   = 0;
	foreach ( CS_CATEGORIES as $cat => $title ) {
		if ( empty( $by_cat[ $cat ] ) ) {
			continue;
		}
		echo "\n== {$title} [" . count( $by_cat[ $cat ] ) . "]\n";
		foreach ( $by_cat[ $cat ] as $f ) {
			$priority += '!' === $f['level'] ? 1 : 0;
			$review   += '?' === $f['level'] ? 1 : 0;
			$loc       = $f['file'] . ( $f['line'] ? ':' . $f['line'] : '' );
			echo ' ' . $f['level'] . ' ' . $loc . '  ' . $f['what'] . "\n";
			if ( '' !== $f['detail'] ) {
				echo '     ' . $f['detail'] . "\n";
			}
		}
	}
	echo "\n== Summary\n";
	foreach ( CS_CATEGORIES as $cat => $title ) {
		if ( ! empty( $by_cat[ $cat ] ) ) {
			printf( " %-18s %d\n", $cat, count( $by_cat[ $cat ] ) );
		}
	}
	echo " priority (!)       {$priority}\n needs review (?)   {$review}\n";
	if ( $report['notes'] ) {
		echo "\n== Notes\n";
		foreach ( array_unique( $report['notes'] ) as $note ) {
			echo ' - ' . cs_clean( $note ) . "\n";
		}
	}
	echo "\nLimitations: pattern search alone does not establish exploitability or prove safety.\n";
	echo "\"seen\" is not proof of a correct check; \"NOT SEEN\" may be delegated elsewhere. Confirm each item with\n";
	echo "allowed-role and denied-role tests in the disposable sandbox before reporting it as a finding.\n";
}
