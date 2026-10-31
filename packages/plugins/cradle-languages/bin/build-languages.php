<?php
/**
 * Builds this plugin's shipped translations from languages/<text-domain>.json (CLI only; a web
 * request exits before anything runs). The same file ships in every Cradle add-on that carries
 * its own translations; it reads the text domain from the plugin header and needs no WordPress.
 *
 * Source: languages/<text-domain>.json, format `cradle-translations-examples`, one object per
 * locale (`de_DE`, `ar`, …) with `entries` of { domain, msgid, context?, msgidPlural?, forms }.
 * Generated (commit them; never edit them by hand):
 * - languages/<domain>-<locale>.l10n.php  WordPress PHP translation file (PHP strings, contexts and
 *   descriptor labels, block.json title/description/keywords with WordPress' block contexts);
 * - languages/<domain>-<locale>-<md5>.json  Jed JSON per script that calls wp.i18n, md5 of the
 *   script path relative to the plugin root (WordPress' lookup for wp_set_script_translations()).
 *
 * Checks before writing: every translatable string found in the plugin's PHP, JavaScript and
 * block.json files exists in every locale of the source, with the right number of forms; source
 * entries belong to this text domain and are not duplicated. Labels with the contexts
 * `module name`, `module description` and `capability label` are descriptor labels: they are not
 * found in code calls and are kept as they are.
 *
 * Usage (from the plugin folder or anywhere):
 *   php bin/build-languages.php              write the generated files into languages/
 *   php bin/build-languages.php --check      compare only; exit 3 when a file is missing, differs or is stale
 *   php bin/build-languages.php --out=<dir>  write into another existing folder (tests compare it with languages/)
 *   php bin/build-languages.php --quiet      no summary line
 *
 * Exit codes: 0 ok, 1 web request or usage, 2 invalid source or strings missing from it,
 * 3 check found differences, 4 a file could not be written.
 *
 * @package Cradle
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

/** Plural rules of the locales this generator knows (WordPress' Plural-Forms headers). */
const CRADLE_BUILD_LANGUAGES_PLURALS = array(
	'de_DE' => array( 2, 'nplurals=2; plural=(n != 1);' ),
	'ar'    => array( 6, 'nplurals=6; plural=(n == 0) ? 0 : ((n == 1) ? 1 : ((n == 2) ? 2 : ((n % 100 >= 3 && n % 100 <= 10) ? 3 : ((n % 100 >= 11 && n % 100 <= 99) ? 4 : 5))));' ),
);

/** Contexts of descriptor labels (translated through the `cradle/translate_label` filter). */
const CRADLE_BUILD_LANGUAGES_LABEL_CONTEXTS = array( 'module name', 'module description', 'capability label' );

/** Directories that are never scanned (same list as Cradle Translations' extractor). */
const CRADLE_BUILD_LANGUAGES_SKIP_DIRS = array( 'node_modules', 'vendor', '.git', '.svn', 'skills', 'tests', 'dist', 'languages' );

/** PHP translation functions and the roles of their arguments. */
const CRADLE_BUILD_LANGUAGES_PHP_FUNCTIONS = array(
	'__'         => array( 'text', 'domain' ),
	'_e'         => array( 'text', 'domain' ),
	'esc_html__' => array( 'text', 'domain' ),
	'esc_html_e' => array( 'text', 'domain' ),
	'esc_attr__' => array( 'text', 'domain' ),
	'esc_attr_e' => array( 'text', 'domain' ),
	'esc_xml__'  => array( 'text', 'domain' ),
	'esc_xml_e'  => array( 'text', 'domain' ),
	'_x'         => array( 'text', 'context', 'domain' ),
	'_ex'        => array( 'text', 'context', 'domain' ),
	'esc_html_x' => array( 'text', 'context', 'domain' ),
	'esc_attr_x' => array( 'text', 'context', 'domain' ),
	'esc_xml_x'  => array( 'text', 'context', 'domain' ),
	'_n'         => array( 'text', 'plural', 'number', 'domain' ),
	'_n_noop'    => array( 'text', 'plural', 'domain' ),
	'_nx'        => array( 'text', 'plural', 'number', 'context', 'domain' ),
	'_nx_noop'   => array( 'text', 'plural', 'context', 'domain' ),
);

/** wp.i18n functions and the roles of their arguments. */
const CRADLE_BUILD_LANGUAGES_JS_FUNCTIONS = array(
	'__'  => array( 'text', 'domain' ),
	'_x'  => array( 'text', 'context', 'domain' ),
	'_n'  => array( 'text', 'plural', 'number', 'domain' ),
	'_nx' => array( 'text', 'plural', 'number', 'context', 'domain' ),
);

/** WordPress' block.json translation contexts (get_block_metadata_i18n_schema()). */
const CRADLE_BUILD_LANGUAGES_BLOCK_CONTEXTS = array(
	'title'       => 'block title',
	'description' => 'block description',
	'keywords'    => 'block keyword',
	'style'       => 'block style label',
	'vtitle'      => 'block variation title',
	'vdesc'       => 'block variation description',
	'vkeyword'    => 'block variation keyword',
);

/**
 * Stops with a message on STDERR.
 */
function cradle_build_languages_fail( string $message, int $code ) {
	fwrite( STDERR, $message . "\n" );
	exit( $code );
}

/**
 * Lookup key of an entry: context + EOT + msgid (WordPress' key format).
 */
function cradle_build_languages_key( string $msgid, ?string $context ): string {
	return ( null !== $context ? $context . "\4" : '' ) . $msgid;
}

/**
 * Files of the plugin to scan (PHP and JavaScript), sorted, without symlinks or skipped folders.
 *
 * @return string[] Paths relative to the plugin root with forward slashes.
 */
function cradle_build_languages_files( string $root ): array {
	$out      = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			static function ( SplFileInfo $current ) {
				if ( $current->isLink() ) {
					return false;
				}
				if ( $current->isDir() ) {
					return ! in_array( $current->getFilename(), CRADLE_BUILD_LANGUAGES_SKIP_DIRS, true );
				}
				return (bool) preg_match( '/\.(php|js)$/', $current->getFilename() );
			}
		)
	);
	foreach ( $iterator as $file ) {
		$rel = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
		if ( str_ends_with( $rel, '.min.js' ) && is_file( $root . '/' . substr( $rel, 0, -7 ) . '.js' ) ) {
			continue;
		}
		$out[] = $rel;
	}
	sort( $out, SORT_STRING );
	return $out;
}

/**
 * Decodes a PHP single- or double-quoted string literal without evaluating it.
 */
function cradle_build_languages_php_literal( string $literal ): ?string {
	if ( '' !== $literal && ( 'b' === $literal[0] || 'B' === $literal[0] ) ) {
		$literal = substr( $literal, 1 );
	}
	if ( strlen( $literal ) < 2 ) {
		return null;
	}
	$quote = $literal[0];
	$body  = substr( $literal, 1, -1 );
	if ( "'" === $quote ) {
		return preg_replace_callback( '/\\\\([\\\\\'])/', static fn( $m ) => $m[1], $body );
	}
	if ( '"' !== $quote ) {
		return null;
	}
	$simple = array(
		'n'  => "\n",
		'r'  => "\r",
		't'  => "\t",
		'v'  => "\v",
		'e'  => "\e",
		'f'  => "\f",
		'\\' => '\\',
		'$'  => '$',
		'"'  => '"',
	);
	return preg_replace_callback(
		'/\\\\(?:([nrtvef\\\\$"])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]{1,6})\})/',
		static function ( $m ) use ( $simple ) {
			if ( isset( $m[1] ) && '' !== $m[1] ) {
				return $simple[ $m[1] ];
			}
			if ( isset( $m[2] ) && '' !== $m[2] ) {
				return chr( octdec( $m[2] ) & 255 );
			}
			if ( isset( $m[3] ) && '' !== $m[3] ) {
				return chr( hexdec( $m[3] ) );
			}
			return mb_chr( (int) hexdec( $m[4] ), 'UTF-8' );
		},
		$body
	);
}

/**
 * Turns argument values into a string record for this domain, or a problem message.
 *
 * @param array<int,string|false|null> $values Literal string, false (not a literal) or null (absent).
 * @return array{0:?array,1:?string} [record, problem]
 */
function cradle_build_languages_record( array $roles, array $values, string $domain ): array {
	$map = array();
	foreach ( $roles as $i => $role ) {
		$map[ $role ] = $values[ $i ] ?? null;
	}
	if ( ! is_string( $map['domain'] ?? null ) || $domain !== $map['domain'] ) {
		return array( null, null ); // Another (or a computed) text domain: not ours.
	}
	foreach ( array( 'text', 'plural', 'context' ) as $role ) {
		if ( in_array( $role, $roles, true ) && ! is_string( $map[ $role ] ) ) {
			return array( null, 'the ' . $role . ' is not a string literal' );
		}
	}
	if ( '' === $map['text'] ) {
		return array( null, null );
	}
	return array(
		array(
			'msgid'       => $map['text'],
			'context'     => isset( $map['context'] ) && '' !== $map['context'] ? $map['context'] : null,
			'msgidPlural' => $map['plural'] ?? null,
		),
		null,
	);
}

/**
 * Translation calls of one PHP file (token_get_all(); nothing is included or executed).
 *
 * @return array{strings:array,problems:string[]}
 */
function cradle_build_languages_scan_php( string $code, string $domain, string $rel ): array {
	$out    = array(
		'strings'  => array(),
		'problems' => array(),
	);
	$tokens = token_get_all( $code );
	$count  = count( $tokens );
	$prev   = null;
	$ignore = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );
	$block  = array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST );
	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		if ( ! is_array( $token ) ) {
			$prev = $token;
			continue;
		}
		if ( in_array( $token[0], $ignore, true ) || T_OPEN_TAG === $token[0] || T_INLINE_HTML === $token[0] ) {
			continue;
		}
		$name = ltrim( $token[1], '\\' );
		if ( ( T_STRING === $token[0] || T_NAME_FULLY_QUALIFIED === $token[0] ) && isset( CRADLE_BUILD_LANGUAGES_PHP_FUNCTIONS[ $name ] ) && ! in_array( $prev, $block, true ) ) {
			$open = $i + 1;
			while ( $open < $count && is_array( $tokens[ $open ] ) && in_array( $tokens[ $open ][0], $ignore, true ) ) {
				++$open;
			}
			if ( $open < $count && '(' === $tokens[ $open ] ) {
				$args  = array( array() );
				$depth = 0;
				for ( $j = $open + 1; $j < $count; $j++ ) {
					$t = $tokens[ $j ];
					if ( is_array( $t ) && in_array( $t[0], $ignore, true ) ) {
						continue;
					}
					if ( ! is_array( $t ) && 0 === $depth && ')' === $t ) {
						break;
					}
					if ( ! is_array( $t ) && 0 === $depth && ',' === $t ) {
						$args[] = array();
						continue;
					}
					if ( ! is_array( $t ) && in_array( $t, array( '(', '[', '{' ), true ) ) {
						++$depth;
					} elseif ( ! is_array( $t ) && in_array( $t, array( ')', ']', '}' ), true ) ) {
						--$depth;
					} elseif ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
						++$depth;
					}
					$args[ count( $args ) - 1 ][] = $t;
				}
				$values = array();
				foreach ( $args as $arg ) {
					if ( ! $arg ) {
						$values[] = null;
						continue;
					}
					$value  = '';
					$expect = true;
					foreach ( $arg as $t ) {
						if ( $expect && is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] && null !== cradle_build_languages_php_literal( $t[1] ) ) {
							$value .= cradle_build_languages_php_literal( $t[1] );
							$expect = false;
						} elseif ( ! $expect && '.' === $t ) {
							$expect = true;
						} else {
							$value = false;
							break;
						}
					}
					$values[] = ( false === $value || $expect ) ? false : $value;
				}
				list( $record, $problem ) = cradle_build_languages_record( CRADLE_BUILD_LANGUAGES_PHP_FUNCTIONS[ $name ], $values, $domain );
				if ( $record ) {
					$record['file']     = $rel;
					$out['strings'][] = $record;
				} elseif ( $problem ) {
					$out['problems'][] = $rel . ':' . $token[2] . ' ' . $name . '(): ' . $problem;
				}
			}
		}
		$prev = $token[0];
	}
	return $out;
}

/**
 * Reads a JavaScript string or template literal starting at $i.
 *
 * @return array{0:?string,1:int} Value (null when interpolated or unterminated) and the next offset.
 */
function cradle_build_languages_js_string( string $src, int $i ): array {
	$len      = strlen( $src );
	$quote    = $src[ $i ];
	$template = '`' === $quote;
	$buf      = '';
	$dynamic  = false;
	$closed   = false;
	$j        = $i + 1;
	$escapes  = array(
		'n' => "\n",
		'r' => "\r",
		't' => "\t",
		'b' => "\x08",
		'f' => "\f",
		'v' => "\v",
	);
	while ( $j < $len ) {
		$ch = $src[ $j ];
		if ( $ch === $quote ) {
			$closed = true;
			++$j;
			break;
		}
		if ( "\n" === $ch && ! $template ) {
			break;
		}
		if ( '\\' === $ch ) {
			$e = $src[ $j + 1 ] ?? '';
			if ( isset( $escapes[ $e ] ) ) {
				$buf .= $escapes[ $e ];
				$j   += 2;
			} elseif ( "\n" === $e ) {
				$j += 2;
			} elseif ( "\r" === $e ) {
				$j += ( "\n" === ( $src[ $j + 2 ] ?? '' ) ) ? 3 : 2;
			} elseif ( 'x' === $e && preg_match( '/^[0-9A-Fa-f]{2}/', substr( $src, $j + 2, 2 ) ) ) {
				$buf .= mb_chr( (int) hexdec( substr( $src, $j + 2, 2 ) ), 'UTF-8' );
				$j   += 4;
			} elseif ( 'u' === $e && preg_match( '/^\{([0-9A-Fa-f]{1,6})\}/', substr( $src, $j + 2, 8 ), $m ) ) {
				$buf .= (string) mb_chr( (int) hexdec( $m[1] ), 'UTF-8' );
				$j   += 4 + strlen( $m[1] );
			} elseif ( 'u' === $e && preg_match( '/^[0-9A-Fa-f]{4}/', substr( $src, $j + 2, 4 ) ) ) {
				$code = (int) hexdec( substr( $src, $j + 2, 4 ) );
				$j   += 6;
				if ( $code >= 0xD800 && $code <= 0xDBFF && preg_match( '/^\\\\u(d[c-f][0-9a-f]{2})/i', substr( $src, $j, 6 ), $low ) ) {
					$code = 0x10000 + ( ( $code - 0xD800 ) << 10 ) + ( (int) hexdec( $low[1] ) - 0xDC00 );
					$j   += 6;
				}
				$buf .= ( $code >= 0xD800 && $code <= 0xDFFF ) ? '' : (string) mb_chr( $code, 'UTF-8' );
			} else {
				$buf .= $e;
				$j   += 2;
			}
			continue;
		}
		if ( $template && '$' === $ch && '{' === ( $src[ $j + 1 ] ?? '' ) ) {
			$dynamic = true;
			$depth   = 1;
			$j      += 2;
			while ( $j < $len && $depth > 0 ) {
				$inner = $src[ $j ];
				if ( '"' === $inner || "'" === $inner || '`' === $inner ) {
					$j = cradle_build_languages_js_string( $src, $j )[1];
					continue;
				}
				if ( '{' === $inner ) {
					++$depth;
				} elseif ( '}' === $inner ) {
					--$depth;
				}
				++$j;
			}
			continue;
		}
		$buf .= $ch;
		++$j;
	}
	return array( ( $closed && ! $dynamic && mb_check_encoding( $buf, 'UTF-8' ) ) ? $buf : null, $j );
}

/**
 * Tokens of a JavaScript file: strings, identifiers, numbers, regular expressions and
 * single-character punctuation (comments and whitespace dropped), with line numbers.
 *
 * @return array<int,array{t:string,v:mixed,line:int}>
 */
function cradle_build_languages_js_tokens( string $src ): array {
	$keywords = array( 'return', 'typeof', 'case', 'do', 'else', 'in', 'instanceof', 'new', 'void', 'delete', 'throw', 'yield', 'await', 'of' );
	$len      = strlen( $src );
	$tokens   = array();
	$prev     = null;
	$line     = 1;
	$i        = 0;
	while ( $i < $len ) {
		$c    = $src[ $i ];
		$next = $src[ $i + 1 ] ?? '';
		if ( "\n" === $c ) {
			++$line;
			++$i;
			continue;
		}
		if ( ctype_space( $c ) ) {
			++$i;
			continue;
		}
		if ( '/' === $c && '/' === $next ) {
			$end = strpos( $src, "\n", $i );
			$i   = false === $end ? $len : $end;
			continue;
		}
		if ( '/' === $c && '*' === $next ) {
			$end   = strpos( $src, '*/', $i + 2 );
			$end   = false === $end ? $len : $end + 2;
			$line += substr_count( $src, "\n", $i, $end - $i );
			$i     = $end;
			continue;
		}
		$token = null;
		if ( '"' === $c || "'" === $c || '`' === $c ) {
			list( $value, $end ) = cradle_build_languages_js_string( $src, $i );
			$token               = array(
				't'    => 'str',
				'v'    => $value,
				'line' => $line,
			);
			$line               += substr_count( $src, "\n", $i, $end - $i );
			$i                   = $end;
		} elseif ( ctype_alpha( $c ) || '_' === $c || '$' === $c || ord( $c ) > 127 ) {
			$j = $i;
			while ( $j < $len && ( ctype_alnum( $src[ $j ] ) || '_' === $src[ $j ] || '$' === $src[ $j ] || ord( $src[ $j ] ) > 127 ) ) {
				++$j;
			}
			$token = array(
				't'    => 'id',
				'v'    => substr( $src, $i, $j - $i ),
				'line' => $line,
			);
			$i     = $j;
		} elseif ( ctype_digit( $c ) || ( '.' === $c && ctype_digit( $next ) ) ) {
			$j = $i;
			while ( $j < $len && ( ctype_alnum( $src[ $j ] ) || '.' === $src[ $j ] || '_' === $src[ $j ] ) ) {
				++$j;
			}
			$token = array(
				't'    => 'num',
				'v'    => substr( $src, $i, $j - $i ),
				'line' => $line,
			);
			$i     = $j;
		} elseif ( '/' === $c && ( null === $prev || ( 'punc' === $prev['t'] && ! in_array( $prev['v'], array( ')', ']' ), true ) ) || ( 'id' === $prev['t'] && in_array( $prev['v'], $keywords, true ) ) ) ) {
			$j        = $i + 1;
			$in_class = false;
			$ok       = false;
			while ( $j < $len && "\n" !== $src[ $j ] ) {
				$ch = $src[ $j ];
				if ( '\\' === $ch ) {
					$j += 2;
					continue;
				}
				if ( '[' === $ch ) {
					$in_class = true;
				} elseif ( ']' === $ch ) {
					$in_class = false;
				} elseif ( '/' === $ch && ! $in_class ) {
					$ok = true;
					break;
				}
				++$j;
			}
			if ( $ok ) {
				++$j;
				while ( $j < $len && ctype_alpha( $src[ $j ] ) ) {
					++$j;
				}
				$token = array(
					't'    => 'regex',
					'v'    => '',
					'line' => $line,
				);
				$i     = $j;
			}
		}
		if ( null === $token ) {
			$token = array(
				't'    => 'punc',
				'v'    => $c,
				'line' => $line,
			);
			++$i;
		}
		$tokens[] = $token;
		$prev     = $token;
	}
	return $tokens;
}

/**
 * wp.i18n calls of one JavaScript file: bare __() / _x() / _n() / _nx() or i18n.<fn>() calls.
 *
 * @return array{strings:array,problems:string[]}
 */
function cradle_build_languages_scan_js( string $code, string $domain, string $rel ): array {
	$out    = array(
		'strings'  => array(),
		'problems' => array(),
	);
	$tokens = cradle_build_languages_js_tokens( $code );
	$count  = count( $tokens );
	for ( $k = 0; $k < $count; $k++ ) {
		$t = $tokens[ $k ];
		if ( 'id' !== $t['t'] || ! isset( CRADLE_BUILD_LANGUAGES_JS_FUNCTIONS[ $t['v'] ] ) || ! isset( $tokens[ $k + 1 ] ) || 'punc' !== $tokens[ $k + 1 ]['t'] || '(' !== $tokens[ $k + 1 ]['v'] ) {
			continue;
		}
		$before = $tokens[ $k - 1 ] ?? null;
		if ( $before && 'punc' === $before['t'] && '.' === $before['v'] ) {
			$object = $tokens[ $k - 2 ] ?? null;
			if ( ! $object || 'id' !== $object['t'] || 'i18n' !== $object['v'] ) {
				continue;
			}
		} elseif ( $before && 'id' === $before['t'] && 'function' === $before['v'] ) {
			continue;
		}
		$args  = array( array() );
		$depth = 0;
		for ( $j = $k + 2; $j < $count; $j++ ) {
			$a = $tokens[ $j ];
			if ( 'punc' === $a['t'] ) {
				if ( 0 === $depth && ')' === $a['v'] ) {
					break;
				}
				if ( 0 === $depth && ',' === $a['v'] ) {
					$args[] = array();
					continue;
				}
				if ( in_array( $a['v'], array( '(', '[', '{' ), true ) ) {
					++$depth;
				} elseif ( in_array( $a['v'], array( ')', ']', '}' ), true ) ) {
					--$depth;
				}
			}
			$args[ count( $args ) - 1 ][] = $a;
		}
		$values = array();
		foreach ( $args as $arg ) {
			if ( ! $arg ) {
				$values[] = null;
				continue;
			}
			$value  = '';
			$expect = true;
			foreach ( $arg as $a ) {
				if ( $expect && 'str' === $a['t'] && null !== $a['v'] ) {
					$value .= $a['v'];
					$expect = false;
				} elseif ( ! $expect && 'punc' === $a['t'] && '+' === $a['v'] ) {
					$expect = true;
				} else {
					$value = false;
					break;
				}
			}
			$values[] = ( false === $value || $expect ) ? false : $value;
		}
		list( $record, $problem ) = cradle_build_languages_record( CRADLE_BUILD_LANGUAGES_JS_FUNCTIONS[ $t['v'] ], $values, $domain );
		if ( $record ) {
			$record['file']     = $rel;
			$out['strings'][] = $record;
		} elseif ( $problem ) {
			$out['problems'][] = $rel . ':' . $t['line'] . ' ' . $t['v'] . '(): ' . $problem;
		}
	}
	return $out;
}

/**
 * block.json strings of this domain with WordPress' block metadata contexts.
 *
 * @return array{strings:array,problems:string[]}
 */
function cradle_build_languages_scan_blocks( string $root, string $domain ): array {
	$out   = array(
		'strings'  => array(),
		'problems' => array(),
	);
	$files = glob( $root . '/blocks/*/block.json' );
	sort( $files, SORT_STRING );
	foreach ( $files as $file ) {
		$rel  = substr( str_replace( '\\', '/', $file ), strlen( $root ) + 1 );
		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $data ) ) {
			$out['problems'][] = $rel . ': invalid JSON';
			continue;
		}
		if ( $domain !== ( $data['textdomain'] ?? '' ) ) {
			$out['problems'][] = $rel . ': textdomain must be "' . $domain . '"';
			continue;
		}
		$add = static function ( $text, string $context ) use ( &$out, $rel ) {
			if ( is_string( $text ) && '' !== $text ) {
				$out['strings'][] = array(
					'msgid'       => $text,
					'context'     => $context,
					'msgidPlural' => null,
					'file'        => $rel,
				);
			}
		};
		$add( $data['title'] ?? null, CRADLE_BUILD_LANGUAGES_BLOCK_CONTEXTS['title'] );
		$add( $data['description'] ?? null, CRADLE_BUILD_LANGUAGES_BLOCK_CONTEXTS['description'] );
		foreach ( (array) ( $data['keywords'] ?? array() ) as $keyword ) {
			$add( $keyword, CRADLE_BUILD_LANGUAGES_BLOCK_CONTEXTS['keywords'] );
		}
		foreach ( (array) ( $data['styles'] ?? array() ) as $style ) {
			$add( is_array( $style ) ? ( $style['label'] ?? null ) : null, CRADLE_BUILD_LANGUAGES_BLOCK_CONTEXTS['style'] );
		}
		foreach ( (array) ( $data['variations'] ?? array() ) as $variation ) {
			if ( is_array( $variation ) ) {
				$add( $variation['title'] ?? null, CRADLE_BUILD_LANGUAGES_BLOCK_CONTEXTS['vtitle'] );
				$add( $variation['description'] ?? null, CRADLE_BUILD_LANGUAGES_BLOCK_CONTEXTS['vdesc'] );
				foreach ( (array) ( $variation['keywords'] ?? array() ) as $keyword ) {
					$add( $keyword, CRADLE_BUILD_LANGUAGES_BLOCK_CONTEXTS['vkeyword'] );
				}
			}
		}
	}
	return $out;
}

/**
 * Validated source entries per locale, keyed by lookup key.
 *
 * @return array<string,array<string,array{msgid:string,context:?string,msgidPlural:?string,forms:string[]}>>
 */
function cradle_build_languages_source( string $file, string $domain, array &$problems ): array {
	$data = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true, 16 ) : null;
	if ( ! is_array( $data ) || ! $data ) {
		$problems[] = 'Source missing or not valid JSON: ' . $file;
		return array();
	}
	$out = array();
	foreach ( $data as $locale => $catalog ) {
		if ( ! isset( CRADLE_BUILD_LANGUAGES_PLURALS[ $locale ] ) ) {
			$problems[] = 'Unknown locale "' . $locale . '": add its plural rule to CRADLE_BUILD_LANGUAGES_PLURALS.';
			continue;
		}
		if ( ! is_array( $catalog ) || 'cradle-translations-examples' !== ( $catalog['format'] ?? '' ) || $locale !== ( $catalog['locale'] ?? '' ) || ! is_array( $catalog['entries'] ?? null ) ) {
			$problems[] = $locale . ': expected { format: "cradle-translations-examples", locale: "' . $locale . '", entries: [ … ] }.';
			continue;
		}
		$nplurals       = CRADLE_BUILD_LANGUAGES_PLURALS[ $locale ][0];
		$out[ $locale ] = array();
		foreach ( $catalog['entries'] as $index => $row ) {
			$where = $locale . ' entry ' . $index;
			if ( ! is_array( $row ) || ! is_string( $row['msgid'] ?? null ) || '' === $row['msgid'] || ! is_array( $row['forms'] ?? null ) || ! array_is_list( $row['forms'] ) ) {
				$problems[] = $where . ': needs a msgid and a list of forms.';
				continue;
			}
			$where .= ' (' . $row['msgid'] . ')';
			if ( $domain !== ( $row['domain'] ?? null ) ) {
				$problems[] = $where . ': domain must be "' . $domain . '".';
				continue;
			}
			$context = isset( $row['context'] ) && '' !== $row['context'] ? $row['context'] : null;
			$plural  = isset( $row['msgidPlural'] ) && '' !== $row['msgidPlural'] ? $row['msgidPlural'] : null;
			if ( ( null !== $context && ! is_string( $context ) ) || ( null !== $plural && ! is_string( $plural ) ) ) {
				$problems[] = $where . ': context and msgidPlural must be strings.';
				continue;
			}
			$expected = null === $plural ? 1 : $nplurals;
			$forms    = $row['forms'];
			if ( count( $forms ) !== $expected || count( array_filter( $forms, static fn( $f ) => is_string( $f ) && '' !== $f && mb_check_encoding( $f, 'UTF-8' ) ) ) !== $expected ) {
				$problems[] = $where . ': needs exactly ' . $expected . ' non-empty form(s).';
				continue;
			}
			$key = cradle_build_languages_key( $row['msgid'], $context );
			if ( isset( $out[ $locale ][ $key ] ) ) {
				$problems[] = $where . ': duplicate entry.';
				continue;
			}
			$out[ $locale ][ $key ] = array(
				'msgid'       => $row['msgid'],
				'context'     => $context,
				'msgidPlural' => $plural,
				'forms'       => array_values( $forms ),
			);
		}
	}
	return $out;
}

/**
 * PHP source literal for a string: single-quoted when it has no control characters, otherwise
 * double-quoted with escapes (the context separator becomes "\4", the plural separator "\0").
 */
function cradle_build_languages_export_string( string $value ): string {
	if ( ! preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
		return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value ) . "'";
	}
	$named = array(
		"\n" => '\\n',
		"\r" => '\\r',
		"\t" => '\\t',
		"\v" => '\\v',
		"\f" => '\\f',
		"\e" => '\\e',
	);
	$out   = '';
	$len   = strlen( $value );
	for ( $i = 0; $i < $len; $i++ ) {
		$c = $value[ $i ];
		$o = ord( $c );
		if ( '\\' === $c || '"' === $c || '$' === $c ) {
			$out .= '\\' . $c;
		} elseif ( isset( $named[ $c ] ) ) {
			$out .= $named[ $c ];
		} elseif ( $o < 32 || 127 === $o ) {
			// Shortest octal escape unless an octal digit follows (then the unambiguous 3-digit form).
			$out .= '\\' . ( preg_match( '/^[0-7]/', substr( $value, $i + 1, 1 ) ) ? sprintf( '%03o', $o ) : decoct( $o ) );
		} else {
			$out .= $c;
		}
	}
	return '"' . $out . '"';
}

/**
 * Contents of a WordPress PHP translation file.
 */
function cradle_build_languages_l10n_php( string $domain, string $locale, array $entries ): string {
	ksort( $entries, SORT_STRING );
	$php  = "<?php\n";
	$php .= '// Generated by bin/build-languages.php from languages/' . $domain . ".json. Do not edit: change the source and run the generator.\n";
	$php .= "return array(\n";
	$php .= "\t'x-generator'  => " . cradle_build_languages_export_string( $domain . ' bin/build-languages.php' ) . ",\n";
	$php .= "\t'language'     => " . cradle_build_languages_export_string( $locale ) . ",\n";
	$php .= "\t'plural-forms' => " . cradle_build_languages_export_string( CRADLE_BUILD_LANGUAGES_PLURALS[ $locale ][1] ) . ",\n";
	$php .= "\t'messages'     => array(\n";
	foreach ( $entries as $key => $entry ) {
		$php .= "\t\t" . cradle_build_languages_export_string( (string) $key ) . ' => ' . cradle_build_languages_export_string( implode( "\0", $entry['forms'] ) ) . ",\n";
	}
	$php .= "\t),\n);\n";
	return $php;
}

/**
 * Jed 1.x JSON for one script (WordPress' script translation format). <, > and & are
 * hex-escaped because WordPress prints the JSON inside an inline script.
 */
function cradle_build_languages_jed( string $domain, string $locale, string $script, array $entries ): string {
	ksort( $entries, SORT_STRING );
	$messages = array(
		'' => array(
			'domain'       => 'messages',
			'lang'         => $locale,
			'plural-forms' => CRADLE_BUILD_LANGUAGES_PLURALS[ $locale ][1],
		),
	);
	foreach ( $entries as $key => $entry ) {
		$messages[ (string) $key ] = $entry['forms'];
	}
	return json_encode(
		array(
			'generator'   => $domain . ' bin/build-languages.php',
			'source'      => $script,
			'domain'      => 'messages',
			'locale_data' => array( 'messages' => $messages ),
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
	) . "\n";
}

/* ------------------------------------------------------------------------- */

$cradle_build = array(
	'check' => false,
	'out'   => '',
	'quiet' => false,
);
foreach ( array_slice( $argv, 1 ) as $cradle_build_arg ) {
	if ( '--check' === $cradle_build_arg ) {
		$cradle_build['check'] = true;
	} elseif ( '--quiet' === $cradle_build_arg ) {
		$cradle_build['quiet'] = true;
	} elseif ( str_starts_with( $cradle_build_arg, '--out=' ) && '' !== substr( $cradle_build_arg, 6 ) ) {
		$cradle_build['out'] = substr( $cradle_build_arg, 6 );
	} else {
		cradle_build_languages_fail( 'Usage: php bin/build-languages.php [--check] [--out=<dir>] [--quiet]', 1 );
	}
}

$cradle_build_root = str_replace( '\\', '/', dirname( __DIR__ ) );
$cradle_build_main = $cradle_build_root . '/' . basename( $cradle_build_root ) . '.php';
$cradle_build_head = is_file( $cradle_build_main ) ? (string) file_get_contents( $cradle_build_main, false, null, 0, 8192 ) : '';
if ( ! preg_match( '/^[ \t\/*#@]*Text Domain:\s*([a-z0-9-]+)\s*$/mi', $cradle_build_head, $cradle_build_m ) ) {
	cradle_build_languages_fail( 'No "Text Domain:" header found in ' . $cradle_build_main, 1 );
}
$cradle_build_domain = $cradle_build_m[1];
$cradle_build_lang   = $cradle_build_root . '/languages';
$cradle_build_target = '' !== $cradle_build['out'] ? rtrim( str_replace( '\\', '/', $cradle_build['out'] ), '/' ) : $cradle_build_lang;
if ( ! is_dir( $cradle_build_target ) ) {
	cradle_build_languages_fail( 'Output folder does not exist: ' . $cradle_build_target, 1 );
}

// 1. Source.
$cradle_build_problems = array();
$cradle_build_source   = cradle_build_languages_source( $cradle_build_lang . '/' . $cradle_build_domain . '.json', $cradle_build_domain, $cradle_build_problems );

// 2. Strings in code and block.json.
$cradle_build_found   = array(); // key => record.
$cradle_build_scripts = array(); // script path => [ key => true ].
foreach ( cradle_build_languages_files( $cradle_build_root ) as $cradle_build_rel ) {
	$cradle_build_code = (string) file_get_contents( $cradle_build_root . '/' . $cradle_build_rel );
	$cradle_build_js   = str_ends_with( $cradle_build_rel, '.js' );
	$cradle_build_scan = $cradle_build_js ? cradle_build_languages_scan_js( $cradle_build_code, $cradle_build_domain, $cradle_build_rel ) : cradle_build_languages_scan_php( $cradle_build_code, $cradle_build_domain, $cradle_build_rel );
	foreach ( $cradle_build_scan['problems'] as $cradle_build_problem ) {
		$cradle_build_problems[] = 'Not translatable: ' . $cradle_build_problem;
	}
	foreach ( $cradle_build_scan['strings'] as $cradle_build_string ) {
		$cradle_build_key                         = cradle_build_languages_key( $cradle_build_string['msgid'], $cradle_build_string['context'] );
		$cradle_build_found[ $cradle_build_key ] ??= $cradle_build_string;
		if ( $cradle_build_js ) {
			$cradle_build_scripts[ $cradle_build_rel ][ $cradle_build_key ] = true;
		}
	}
}
$cradle_build_blocks = cradle_build_languages_scan_blocks( $cradle_build_root, $cradle_build_domain );
foreach ( $cradle_build_blocks['problems'] as $cradle_build_problem ) {
	$cradle_build_problems[] = $cradle_build_problem;
}
foreach ( $cradle_build_blocks['strings'] as $cradle_build_string ) {
	$cradle_build_found[ cradle_build_languages_key( $cradle_build_string['msgid'], $cradle_build_string['context'] ) ] ??= $cradle_build_string;
}

// 3. Coverage: every string found is translated in every locale (plural strings as plurals).
foreach ( $cradle_build_source as $cradle_build_locale => $cradle_build_entries ) {
	foreach ( $cradle_build_found as $cradle_build_key => $cradle_build_string ) {
		$cradle_build_entry = $cradle_build_entries[ $cradle_build_key ] ?? null;
		$cradle_build_label = $cradle_build_string['msgid'] . ( null !== $cradle_build_string['context'] ? ' [' . $cradle_build_string['context'] . ']' : '' ) . ' (' . $cradle_build_string['file'] . ')';
		if ( ! $cradle_build_entry ) {
			$cradle_build_problems[] = $cradle_build_locale . ': missing translation of ' . $cradle_build_label;
		} elseif ( $cradle_build_entry['msgidPlural'] !== $cradle_build_string['msgidPlural'] ) {
			$cradle_build_problems[] = $cradle_build_locale . ': plural form differs for ' . $cradle_build_label;
		}
	}
}
$cradle_build_notes = array();
foreach ( $cradle_build_source as $cradle_build_locale => $cradle_build_entries ) {
	foreach ( $cradle_build_entries as $cradle_build_key => $cradle_build_entry ) {
		if ( ! isset( $cradle_build_found[ $cradle_build_key ] ) && ! in_array( $cradle_build_entry['context'], CRADLE_BUILD_LANGUAGES_LABEL_CONTEXTS, true ) ) {
			$cradle_build_notes[] = $cradle_build_locale . ': not used in code (kept): ' . $cradle_build_entry['msgid'];
		}
	}
}
if ( $cradle_build_problems ) {
	cradle_build_languages_fail( $cradle_build_domain . ": nothing written.\n  " . implode( "\n  ", $cradle_build_problems ), 2 );
}

// 4. Files.
ksort( $cradle_build_scripts, SORT_STRING );
$cradle_build_files = array();
foreach ( $cradle_build_source as $cradle_build_locale => $cradle_build_entries ) {
	$cradle_build_files[ $cradle_build_domain . '-' . $cradle_build_locale . '.l10n.php' ] = cradle_build_languages_l10n_php( $cradle_build_domain, $cradle_build_locale, $cradle_build_entries );
	foreach ( $cradle_build_scripts as $cradle_build_script => $cradle_build_keys ) {
		$cradle_build_files[ $cradle_build_domain . '-' . $cradle_build_locale . '-' . md5( $cradle_build_script ) . '.json' ] = cradle_build_languages_jed( $cradle_build_domain, $cradle_build_locale, $cradle_build_script, array_intersect_key( $cradle_build_entries, $cradle_build_keys ) );
	}
}
ksort( $cradle_build_files, SORT_STRING );

// Generated files of this domain that are no longer produced.
$cradle_build_stale = array();
foreach ( (array) glob( $cradle_build_target . '/' . $cradle_build_domain . '-*' ) as $cradle_build_path ) {
	$cradle_build_name = basename( (string) $cradle_build_path );
	if ( ! isset( $cradle_build_files[ $cradle_build_name ] ) && preg_match( '/^' . preg_quote( $cradle_build_domain, '/' ) . '-[A-Za-z_]+(?:\.l10n\.php|-[0-9a-f]{32}\.json)$/', $cradle_build_name ) ) {
		$cradle_build_stale[] = $cradle_build_name;
	}
}

if ( $cradle_build['check'] ) {
	$cradle_build_diff = array();
	foreach ( $cradle_build_files as $cradle_build_name => $cradle_build_content ) {
		$cradle_build_path = $cradle_build_target . '/' . $cradle_build_name;
		if ( ! is_file( $cradle_build_path ) ) {
			$cradle_build_diff[] = 'missing ' . $cradle_build_name;
		} elseif ( str_replace( "\r\n", "\n", (string) file_get_contents( $cradle_build_path ) ) !== $cradle_build_content ) {
			$cradle_build_diff[] = 'differs ' . $cradle_build_name;
		}
	}
	foreach ( $cradle_build_stale as $cradle_build_name ) {
		$cradle_build_diff[] = 'stale ' . $cradle_build_name;
	}
	if ( $cradle_build_diff ) {
		cradle_build_languages_fail( $cradle_build_domain . ": generated files are out of date (run php bin/build-languages.php).\n  " . implode( "\n  ", $cradle_build_diff ), 3 );
	}
	if ( ! $cradle_build['quiet'] ) {
		echo $cradle_build_domain . ': ' . count( $cradle_build_files ) . " generated files match the source.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
	}
	exit( 0 );
}

foreach ( $cradle_build_files as $cradle_build_name => $cradle_build_content ) {
	if ( false === file_put_contents( $cradle_build_target . '/' . $cradle_build_name, $cradle_build_content, LOCK_EX ) ) {
		cradle_build_languages_fail( 'Could not write ' . $cradle_build_target . '/' . $cradle_build_name, 4 );
	}
}
foreach ( $cradle_build_stale as $cradle_build_name ) {
	unlink( $cradle_build_target . '/' . $cradle_build_name );
}
if ( ! $cradle_build['quiet'] ) {
	foreach ( $cradle_build_notes as $cradle_build_note ) {
		fwrite( STDERR, 'note: ' . $cradle_build_note . "\n" );
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
	echo $cradle_build_domain . ': ' . count( $cradle_build_found ) . ' strings, ' . count( $cradle_build_source ) . ' locales, ' . count( $cradle_build_scripts ) . ' scripts; ' . count( $cradle_build_files ) . ' files written' . ( $cradle_build_stale ? ', ' . count( $cradle_build_stale ) . ' stale removed' : '' ) . ".\n";
}
exit( 0 );
