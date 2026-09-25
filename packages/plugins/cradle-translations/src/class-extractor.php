<?php
/**
 * Source-string extraction for Cradle packages only.
 *
 * Roots are discovered, never supplied: directories named `cradle` or `cradle-*` directly inside
 * WP_PLUGIN_DIR and the theme root, canonicalized and required to stay inside those roots
 * (symlinked package directories or files that resolve elsewhere are skipped). Files are read
 * as text and tokenized; nothing is included or executed, and no remote URL is ever fetched.
 *
 * PHP: token_get_all() finds __(), _e(), esc_html__(), esc_html_e(), esc_attr__(), esc_attr_e(),
 * esc_xml__(), esc_xml_e(), _x(), _ex(), esc_html_x(), esc_attr_x(), esc_xml_x(), _n(), _nx(),
 * _n_noop() and _nx_noop() calls with string-literal arguments (adjacent literals joined by `.`).
 * JavaScript: a small tokenizer (strings, template literals, comments, regex literals) finds
 * __(), _x(), _n(), _nx() called bare or as i18n.<fn>() / wp.i18n.<fn>().
 *
 * Not detectable (reported as "dynamic" with file and line when the call is seen): variables,
 * constants or function calls as text/context/domain, heredoc/nowdoc, template literals with
 * ${} interpolation, strings built at runtime, bundled/minified code that renames i18n functions,
 * and translation functions called through aliases other than the names above.
 *
 * @package Cradle\Translations
 */

namespace Cradle\Translations;

use Cradle\Core\Storage;

defined( 'ABSPATH' ) || exit;

final class Extractor {

	const PHP_FUNCTIONS = array(
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

	const JS_FUNCTIONS = array(
		'__'  => array( 'text', 'domain' ),
		'_x'  => array( 'text', 'context', 'domain' ),
		'_n'  => array( 'text', 'plural', 'number', 'domain' ),
		'_nx' => array( 'text', 'plural', 'number', 'context', 'domain' ),
	);

	const SKIP_DIRS      = array( 'node_modules', 'vendor', '.git', '.svn', 'skills', 'tests', 'dist', 'languages' );
	const MAX_FILES      = 4000;
	const MAX_FILE_BYTES = 1048576;
	const MAX_DYNAMIC    = 500;
	const MAX_ARG_TOKENS = 4000;

	const JS_REGEX_KEYWORDS = array( 'return', 'typeof', 'case', 'do', 'else', 'in', 'instanceof', 'new', 'void', 'delete', 'throw', 'yield', 'await', 'of' );

	/**
	 * Discovers Cradle packages in the plugin and theme roots.
	 *
	 * @return array<string,array{type:string,slug:string,dir:string,name:string,version:string,textDomain:string}>
	 */
	public static function packages(): array {
		$out   = array();
		$roots = array(
			'plugin' => WP_PLUGIN_DIR,
			'theme'  => get_theme_root(),
		);
		foreach ( $roots as $type => $root ) {
			$real_root = realpath( $root );
			if ( ! $real_root || ! is_dir( $real_root ) ) {
				continue;
			}
			foreach ( (array) scandir( $real_root ) as $slug ) {
				if ( ! is_string( $slug ) || ! preg_match( '/^cradle(?:-[a-z0-9]+)*$/', $slug ) ) {
					continue;
				}
				$dir = $real_root . DIRECTORY_SEPARATOR . $slug;
				if ( is_link( $dir ) || ! is_dir( $dir ) ) {
					continue;
				}
				$real = realpath( $dir );
				if ( ! $real || ! Storage::path_within( $real, $real_root ) ) {
					continue;
				}
				$header = 'plugin' === $type ? $real . '/' . $slug . '.php' : $real . '/style.css';
				$data   = is_file( $header ) ? get_file_data(
					$header,
					array(
						'name'       => 'plugin' === $type ? 'Plugin Name' : 'Theme Name',
						'version'    => 'Version',
						'textDomain' => 'Text Domain',
					)
				) : array();
				$out[ $type . ':' . $slug ] = array(
					'type'       => $type,
					'slug'       => $slug,
					'dir'        => wp_normalize_path( $real ),
					'name'       => (string) ( $data['name'] ?? '' ) ? (string) $data['name'] : $slug,
					'version'    => (string) ( $data['version'] ?? '' ),
					'textDomain' => (string) ( $data['textDomain'] ?? '' ),
				);
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Builds the source manifest for all Cradle packages plus registered labels.
	 */
	public static function build_manifest(): array {
		$packages = self::packages();
		$entries  = array();
		$dynamic  = array();
		$stats    = array(
			'files'     => 0,
			'php'       => 0,
			'js'        => 0,
			'calls'     => 0,
			'skipped'   => 0,
			'truncated' => false,
		);
		$summary  = array();
		foreach ( $packages as $key => $package ) {
			$summary[ $key ] = array(
				'type'    => $package['type'],
				'slug'    => $package['slug'],
				'name'    => $package['name'],
				'version' => $package['version'],
				'files'   => 0,
				'strings' => 0,
			);
			$base  = ( 'plugin' === $package['type'] ? 'plugins/' : 'themes/' ) . $package['slug'] . '/';
			$files = self::files( $package['dir'], $stats );
			foreach ( $files as $file ) {
				$rel  = substr( wp_normalize_path( $file ), strlen( $package['dir'] ) + 1 );
				$size = filesize( $file );
				if ( false === $size || $size > self::MAX_FILE_BYTES ) {
					++$stats['skipped'];
					continue;
				}
				$code = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( false === $code ) {
					++$stats['skipped'];
					continue;
				}
				$is_js  = str_ends_with( $file, '.js' );
				$result = $is_js ? self::extract_js( $code ) : self::extract_php( $code );
				++$stats['files'];
				++$stats[ $is_js ? 'js' : 'php' ];
				++$summary[ $key ]['files'];
				foreach ( $result['strings'] as $string ) {
					++$stats['calls'];
					$identity = Catalog::identity( $string['domain'], $string['msgid'], $string['context'], $string['msgidPlural'] );
					if ( ! isset( $entries[ $identity ] ) ) {
						if ( count( $entries ) >= Config::MAX_ENTRIES ) {
							$stats['truncated'] = true;
							continue;
						}
						$entries[ $identity ] = array(
							'domain'      => $string['domain'],
							'msgid'       => $string['msgid'],
							'context'     => $string['context'],
							'msgidPlural' => $string['msgidPlural'],
							'references'  => array(),
							'comments'    => '',
							'js'          => false,
							'source'      => 'extracted',
						);
						++$summary[ $key ]['strings'];
					}
					if ( count( $entries[ $identity ]['references'] ) < Config::MAX_REFERENCES ) {
						$entries[ $identity ]['references'][] = $base . $rel . ':' . $string['line'];
					}
					if ( '' !== $string['comment'] && '' === $entries[ $identity ]['comments'] ) {
						$entries[ $identity ]['comments'] = mb_substr( $string['comment'], 0, Config::MAX_COMMENT_CHARS );
					}
					$entries[ $identity ]['js'] = $entries[ $identity ]['js'] || $is_js;
				}
				foreach ( $result['dynamic'] as $item ) {
					if ( count( $dynamic ) < self::MAX_DYNAMIC ) {
						$dynamic[] = array(
							'file'     => $base . $rel,
							'line'     => $item['line'],
							'function' => $item['function'],
							'reason'   => $item['reason'],
						);
					}
				}
			}
		}
		foreach ( Labels::collect() as $label ) {
			$identity = Catalog::identity( $label['domain'], $label['msgid'], $label['context'], null );
			if ( isset( $entries[ $identity ] ) || count( $entries ) >= Config::MAX_ENTRIES ) {
				continue;
			}
			$entries[ $identity ] = array(
				'domain'      => $label['domain'],
				'msgid'       => $label['msgid'],
				'context'     => $label['context'],
				'msgidPlural' => null,
				'references'  => Validator::clean_references( array( 'registry/' . $label['origin'] ) ),
				'comments'    => '',
				'js'          => false,
				'source'      => 'registered',
			);
		}
		return array(
			'schemaVersion' => 1,
			'generated'     => gmdate( 'c' ),
			'generator'     => 'cradle-translations ' . CRADLE_TRANSLATIONS_VERSION,
			'packages'      => $summary,
			'stats'         => $stats,
			'entries'       => array_values( $entries ),
			'dynamic'       => $dynamic,
		);
	}

	/**
	 * PHP and JS files of a package directory, bounded, without following symlinks.
	 *
	 * @return string[]
	 */
	private static function files( string $dir, array &$stats ): array {
		$out      = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				static function ( \SplFileInfo $current ) {
					if ( $current->isLink() ) {
						return false;
					}
					if ( $current->isDir() ) {
						return ! in_array( $current->getFilename(), self::SKIP_DIRS, true );
					}
					return (bool) preg_match( '/\.(php|js)$/', $current->getFilename() );
				}
			)
		);
		foreach ( $iterator as $file ) {
			$path = wp_normalize_path( $file->getPathname() );
			if ( str_ends_with( $path, '.min.js' ) && is_file( substr( $path, 0, -7 ) . '.js' ) ) {
				continue;
			}
			if ( ! Storage::path_within( $path, $dir ) ) {
				continue;
			}
			if ( count( $out ) >= self::MAX_FILES ) {
				$stats['truncated'] = true;
				break;
			}
			$out[] = $path;
		}
		sort( $out );
		return $out;
	}

	/**
	 * Maps call arguments to a string record, or a dynamic-call record.
	 *
	 * @param array<int,string|false|null> $values Literal string, false (dynamic) or null (absent).
	 * @return array{string:?array,dynamic:?array}
	 */
	private static function record( string $function, array $roles, array $values, int $line, string $comment ): array {
		$map = array();
		foreach ( $roles as $i => $role ) {
			$map[ $role ] = $values[ $i ] ?? null;
		}
		foreach ( array( 'text', 'plural', 'context' ) as $role ) {
			if ( in_array( $role, $roles, true ) && ! is_string( $map[ $role ] ) ) {
				return array(
					'string'  => null,
					'dynamic' => array(
						'line'     => $line,
						'function' => $function,
						'reason'   => $role,
					),
				);
			}
		}
		$domain = $map['domain'] ?? null;
		if ( null === $domain ) {
			$domain = 'default';
		}
		if ( ! is_string( $domain ) || ! Config::valid_domain( $domain ) ) {
			return array(
				'string'  => null,
				'dynamic' => array(
					'line'     => $line,
					'function' => $function,
					'reason'   => 'domain',
				),
			);
		}
		foreach ( array( 'text', 'plural', 'context' ) as $role ) {
			if ( isset( $map[ $role ] ) && is_string( $map[ $role ] ) && true !== Validator::check_text( $map[ $role ], $role ) ) {
				return array(
					'string'  => null,
					'dynamic' => array(
						'line'     => $line,
						'function' => $function,
						'reason'   => 'invalid',
					),
				);
			}
		}
		if ( '' === $map['text'] ) {
			return array(
				'string'  => null,
				'dynamic' => null,
			);
		}
		$context = isset( $map['context'] ) && '' !== $map['context'] ? $map['context'] : null;
		return array(
			'string'  => array(
				'domain'      => $domain,
				'msgid'       => $map['text'],
				'context'     => $context,
				'msgidPlural' => $map['plural'] ?? null,
				'line'        => $line,
				'comment'     => $comment,
			),
			'dynamic' => null,
		);
	}

	private static function clean_comment( string $text ): string {
		$text = preg_replace( '#^\s*(?://|/\*+|\#)|\*+/\s*$#', '', $text );
		$text = preg_replace( '/^\s*\*\s?/m', '', (string) $text );
		$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $text ) );
		$pos  = stripos( $text, 'translators:' );
		return false === $pos ? '' : substr( $text, $pos );
	}

	/**
	 * Decodes a PHP T_CONSTANT_ENCAPSED_STRING literal without evaluating it.
	 */
	public static function php_string( string $literal ): ?string {
		if ( '' !== $literal && ( 'b' === $literal[0] || 'B' === $literal[0] ) ) {
			$literal = substr( $literal, 1 );
		}
		if ( strlen( $literal ) < 2 ) {
			return null;
		}
		$quote = $literal[0];
		$body  = substr( $literal, 1, -1 );
		if ( "'" === $quote ) {
			$out = preg_replace_callback( '/\\\\([\\\\\'])/', static fn( $m ) => $m[1], $body );
		} elseif ( '"' === $quote ) {
			$out = preg_replace_callback(
				'/\\\\(?:([nrtvef\\\\$"])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]{1,6})\})/',
				static function ( $m ) {
					if ( isset( $m[1] ) && '' !== $m[1] ) {
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
						return $simple[ $m[1] ];
					}
					if ( isset( $m[2] ) && '' !== $m[2] ) {
						return chr( octdec( $m[2] ) & 255 );
					}
					if ( isset( $m[3] ) && '' !== $m[3] ) {
						return chr( hexdec( $m[3] ) );
					}
					$code = hexdec( $m[4] );
					return $code <= 0x10FFFF ? (string) mb_chr( (int) $code, 'UTF-8' ) : '';
				},
				$body
			);
		} else {
			return null;
		}
		return is_string( $out ) && mb_check_encoding( $out, 'UTF-8' ) ? $out : null;
	}

	/**
	 * Extracts translatable strings from PHP source using the tokenizer.
	 *
	 * @return array{strings:array,dynamic:array}
	 */
	public static function extract_php( string $code ): array {
		$out    = array(
			'strings' => array(),
			'dynamic' => array(),
		);
		$tokens = token_get_all( $code );
		$count  = count( $tokens );
		$prev   = null;
		$note   = null;
		$skip   = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML );
		$block  = array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST );
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) ) {
				$prev = $token;
				continue;
			}
			list( $id, $text, $line ) = $token;
			if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
				if ( false !== stripos( $text, 'translators:' ) ) {
					$note = array(
						'text' => self::clean_comment( $text ),
						'line' => $line + substr_count( $text, "\n" ),
					);
				}
				continue;
			}
			if ( in_array( $id, $skip, true ) ) {
				continue;
			}
			if ( ( T_STRING === $id || T_NAME_FULLY_QUALIFIED === $id ) && isset( self::PHP_FUNCTIONS[ ltrim( $text, '\\' ) ] ) && ! in_array( $prev, $block, true ) ) {
				$function = ltrim( $text, '\\' );
				$open     = $i + 1;
				while ( $open < $count && is_array( $tokens[ $open ] ) && in_array( $tokens[ $open ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					++$open;
				}
				if ( $open < $count && '(' === $tokens[ $open ] ) {
					$values  = self::php_args( $tokens, $open );
					$comment = ( $note && $line - $note['line'] <= 2 ) ? $note['text'] : '';
					$rec     = self::record( $function, self::PHP_FUNCTIONS[ $function ], $values, $line, $comment );
					if ( $rec['string'] ) {
						$out['strings'][] = $rec['string'];
						$note             = null;
					} elseif ( $rec['dynamic'] ) {
						$out['dynamic'][] = $rec['dynamic'];
					}
				}
			}
			$prev = $id;
		}
		return $out;
	}

	/**
	 * Literal values of call arguments starting at the "(" token.
	 *
	 * @return array<int,string|false|null>
	 */
	private static function php_args( array $tokens, int $open ): array {
		$args    = array( array() );
		$depth   = 0;
		$count   = count( $tokens );
		$limit   = min( $count, $open + self::MAX_ARG_TOKENS );
		$closers = array( ')', ']', '}' );
		for ( $i = $open + 1; $i < $limit; $i++ ) {
			$t = $tokens[ $i ];
			if ( is_array( $t ) ) {
				if ( in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				if ( in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
					++$depth;
				}
				$args[ count( $args ) - 1 ][] = $t;
				continue;
			}
			if ( 0 === $depth && ')' === $t ) {
				break;
			}
			if ( 0 === $depth && ',' === $t ) {
				$args[] = array();
				continue;
			}
			if ( in_array( $t, array( '(', '[', '{' ), true ) ) {
				++$depth;
			} elseif ( in_array( $t, $closers, true ) ) {
				--$depth;
			}
			$args[ count( $args ) - 1 ][] = $t;
		}
		$values = array();
		foreach ( $args as $arg ) {
			$values[] = self::php_literal( $arg );
		}
		return $values;
	}

	/**
	 * @return string|false|null String for literals (joined by "."), false when dynamic, null when empty.
	 */
	private static function php_literal( array $tokens ) {
		if ( ! $tokens ) {
			return null;
		}
		$out    = '';
		$expect = true;
		foreach ( $tokens as $t ) {
			if ( $expect ) {
				if ( ! is_array( $t ) || T_CONSTANT_ENCAPSED_STRING !== $t[0] ) {
					return false;
				}
				$decoded = self::php_string( $t[1] );
				if ( null === $decoded ) {
					return false;
				}
				$out   .= $decoded;
				$expect = false;
			} else {
				if ( '.' !== $t ) {
					return false;
				}
				$expect = true;
			}
		}
		return $expect ? false : $out;
	}

	/**
	 * Tokenizes JavaScript enough to find i18n calls: strings, template literals, comments,
	 * regular expression literals, identifiers, numbers and single-character punctuation.
	 *
	 * @return array<int,array{t:string,v:mixed,line:int}>
	 */
	public static function js_tokens( string $src ): array {
		$len    = strlen( $src );
		$i      = 0;
		$line   = 1;
		$tokens = array();
		$prev   = null;
		while ( $i < $len ) {
			$c = $src[ $i ];
			if ( "\n" === $c ) {
				++$line;
				++$i;
				continue;
			}
			if ( ' ' === $c || "\t" === $c || "\r" === $c || "\f" === $c || "\v" === $c ) {
				++$i;
				continue;
			}
			$next = $i + 1 < $len ? $src[ $i + 1 ] : '';
			if ( '/' === $c && '/' === $next ) {
				$end = strpos( $src, "\n", $i );
				$end = false === $end ? $len : $end;
				$tokens[] = array(
					't'    => 'comment',
					'v'    => substr( $src, $i, $end - $i ),
					'line' => $line,
				);
				$i = $end;
				continue;
			}
			if ( '/' === $c && '*' === $next ) {
				$end      = strpos( $src, '*/', $i + 2 );
				$end      = false === $end ? $len : $end + 2;
				$text     = substr( $src, $i, $end - $i );
				$line    += substr_count( $text, "\n" );
				$tokens[] = array(
					't'    => 'comment',
					'v'    => $text,
					'line' => $line,
				);
				$i = $end;
				continue;
			}
			if ( '"' === $c || "'" === $c || '`' === $c ) {
				$start_line = $line;
				list( $value, $i, $line ) = self::js_string( $src, $i, $line );
				$token = array(
					't'    => 'str',
					'v'    => $value,
					'line' => $start_line,
				);
				$tokens[] = $token;
				$prev     = $token;
				continue;
			}
			if ( ctype_alpha( $c ) || '_' === $c || '$' === $c || ord( $c ) > 127 ) {
				$j = $i;
				while ( $j < $len && ( ctype_alnum( $src[ $j ] ) || '_' === $src[ $j ] || '$' === $src[ $j ] || ord( $src[ $j ] ) > 127 ) ) {
					++$j;
				}
				$token    = array(
					't'    => 'id',
					'v'    => substr( $src, $i, $j - $i ),
					'line' => $line,
				);
				$tokens[] = $token;
				$prev     = $token;
				$i        = $j;
				continue;
			}
			if ( ctype_digit( $c ) || ( '.' === $c && ctype_digit( $next ) ) ) {
				$j = $i;
				while ( $j < $len && ( ctype_alnum( $src[ $j ] ) || '.' === $src[ $j ] || '_' === $src[ $j ] ) ) {
					++$j;
				}
				$token    = array(
					't'    => 'num',
					'v'    => substr( $src, $i, $j - $i ),
					'line' => $line,
				);
				$tokens[] = $token;
				$prev     = $token;
				$i        = $j;
				continue;
			}
			if ( '/' === $c && self::js_regex_allowed( $prev ) ) {
				$j        = $i + 1;
				$in_class = false;
				$ok       = false;
				while ( $j < $len ) {
					$ch = $src[ $j ];
					if ( "\n" === $ch ) {
						break;
					}
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
					$token    = array(
						't'    => 'regex',
						'v'    => '',
						'line' => $line,
					);
					$tokens[] = $token;
					$prev     = $token;
					$i        = $j;
					continue;
				}
			}
			$token    = array(
				't'    => 'punc',
				'v'    => $c,
				'line' => $line,
			);
			$tokens[] = $token;
			$prev     = $token;
			++$i;
		}
		return $tokens;
	}

	private static function js_regex_allowed( ?array $prev ): bool {
		if ( null === $prev ) {
			return true;
		}
		if ( 'punc' === $prev['t'] ) {
			return ! in_array( $prev['v'], array( ')', ']' ), true );
		}
		if ( 'id' === $prev['t'] ) {
			return in_array( $prev['v'], self::JS_REGEX_KEYWORDS, true );
		}
		return false;
	}

	/**
	 * Reads a JS string or template literal starting at $i.
	 *
	 * @return array{0:string|null,1:int,2:int} Value (null when unterminated or interpolated), next offset, line.
	 */
	private static function js_string( string $src, int $i, int $line ): array {
		$len      = strlen( $src );
		$quote    = $src[ $i ];
		$j        = $i + 1;
		$buf      = '';
		$dynamic  = false;
		$closed   = false;
		$template = '`' === $quote;
		while ( $j < $len ) {
			$ch = $src[ $j ];
			if ( $ch === $quote ) {
				$closed = true;
				++$j;
				break;
			}
			if ( "\n" === $ch ) {
				if ( ! $template ) {
					break;
				}
				++$line;
			}
			if ( '\\' === $ch ) {
				list( $decoded, $j, $line ) = self::js_escape( $src, $j, $line );
				$buf                       .= $decoded;
				continue;
			}
			if ( $template && '$' === $ch && $j + 1 < $len && '{' === $src[ $j + 1 ] ) {
				$dynamic = true;
				$depth   = 1;
				$j      += 2;
				while ( $j < $len && $depth > 0 ) {
					$inner = $src[ $j ];
					if ( '"' === $inner || "'" === $inner || '`' === $inner ) {
						list( , $j, $line ) = self::js_string( $src, $j, $line );
						continue;
					}
					if ( '{' === $inner ) {
						++$depth;
					} elseif ( '}' === $inner ) {
						--$depth;
					} elseif ( "\n" === $inner ) {
						++$line;
					}
					++$j;
				}
				continue;
			}
			$buf .= $ch;
			++$j;
		}
		$value = ( $closed && ! $dynamic && mb_check_encoding( $buf, 'UTF-8' ) ) ? $buf : null;
		return array( $value, $j, $line );
	}

	/**
	 * Decodes one JS escape sequence at $j (pointing at the backslash).
	 *
	 * @return array{0:string,1:int,2:int}
	 */
	private static function js_escape( string $src, int $j, int $line ): array {
		$len = strlen( $src );
		$e   = $j + 1 < $len ? $src[ $j + 1 ] : '';
		$map = array(
			'n' => "\n",
			'r' => "\r",
			't' => "\t",
			'b' => "\x08",
			'f' => "\f",
			'v' => "\v",
		);
		if ( isset( $map[ $e ] ) ) {
			return array( $map[ $e ], $j + 2, $line );
		}
		if ( "\n" === $e ) {
			return array( '', $j + 2, $line + 1 );
		}
		if ( "\r" === $e ) {
			$skip = ( $j + 2 < $len && "\n" === $src[ $j + 2 ] ) ? 3 : 2;
			return array( '', $j + $skip, $line + 1 );
		}
		if ( '0' === $e && ! ( $j + 2 < $len && ctype_digit( $src[ $j + 2 ] ) ) ) {
			return array( "\0", $j + 2, $line );
		}
		if ( 'x' === $e && preg_match( '/^[0-9A-Fa-f]{2}/', substr( $src, $j + 2, 2 ) ) ) {
			return array( (string) mb_chr( (int) hexdec( substr( $src, $j + 2, 2 ) ), 'UTF-8' ), $j + 4, $line );
		}
		if ( 'u' === $e ) {
			if ( preg_match( '/^\{([0-9A-Fa-f]{1,6})\}/', substr( $src, $j + 2, 8 ), $m ) ) {
				$code = (int) hexdec( $m[1] );
				return array( $code <= 0x10FFFF ? (string) mb_chr( $code, 'UTF-8' ) : '', $j + 4 + strlen( $m[1] ), $line );
			}
			if ( preg_match( '/^[0-9A-Fa-f]{4}/', substr( $src, $j + 2, 4 ) ) ) {
				$code = (int) hexdec( substr( $src, $j + 2, 4 ) );
				$next = $j + 6;
				if ( $code >= 0xD800 && $code <= 0xDBFF && preg_match( '/^\\\\u(d[c-f][0-9a-f]{2})/i', substr( $src, $next, 6 ), $low ) ) {
					$code = 0x10000 + ( ( $code - 0xD800 ) << 10 ) + ( (int) hexdec( $low[1] ) - 0xDC00 );
					$next += 6;
				}
				return array( ( $code >= 0xD800 && $code <= 0xDFFF ) ? '' : (string) mb_chr( $code, 'UTF-8' ), $next, $line );
			}
		}
		return array( $e, $j + 2, $line );
	}

	/**
	 * Extracts wp.i18n calls from JavaScript source.
	 *
	 * @return array{strings:array,dynamic:array}
	 */
	public static function extract_js( string $code ): array {
		$out   = array(
			'strings' => array(),
			'dynamic' => array(),
		);
		$sig   = array();
		$notes = array();
		foreach ( self::js_tokens( $code ) as $token ) {
			if ( 'comment' === $token['t'] ) {
				if ( false !== stripos( $token['v'], 'translators:' ) ) {
					$notes[ count( $sig ) ] = array(
						'text' => self::clean_comment( $token['v'] ),
						'line' => $token['line'],
					);
				}
				continue;
			}
			$sig[] = $token;
		}
		$count = count( $sig );
		for ( $k = 0; $k < $count; $k++ ) {
			$t = $sig[ $k ];
			if ( 'id' !== $t['t'] || ! isset( self::JS_FUNCTIONS[ $t['v'] ] ) || ! isset( $sig[ $k + 1 ] ) || 'punc' !== $sig[ $k + 1 ]['t'] || '(' !== $sig[ $k + 1 ]['v'] ) {
				continue;
			}
			$before = $sig[ $k - 1 ] ?? null;
			if ( $before && 'punc' === $before['t'] && '.' === $before['v'] ) {
				$object = $sig[ $k - 2 ] ?? null;
				if ( ! $object || 'id' !== $object['t'] || 'i18n' !== $object['v'] ) {
					continue;
				}
			} elseif ( $before && 'id' === $before['t'] && 'function' === $before['v'] ) {
				continue;
			}
			$values  = self::js_args( $sig, $k + 1 );
			$comment = '';
			foreach ( $notes as $pos => $note ) {
				if ( $pos <= $k && $t['line'] - $note['line'] <= 2 && $t['line'] >= $note['line'] ) {
					$comment = $note['text'];
				}
			}
			$rec = self::record( $t['v'], self::JS_FUNCTIONS[ $t['v'] ], $values, $t['line'], $comment );
			if ( $rec['string'] ) {
				$out['strings'][] = $rec['string'];
			} elseif ( $rec['dynamic'] ) {
				$out['dynamic'][] = $rec['dynamic'];
			}
		}
		return $out;
	}

	/**
	 * @return array<int,string|false|null>
	 */
	private static function js_args( array $sig, int $open ): array {
		$args  = array( array() );
		$depth = 0;
		$limit = min( count( $sig ), $open + self::MAX_ARG_TOKENS );
		for ( $i = $open + 1; $i < $limit; $i++ ) {
			$t = $sig[ $i ];
			if ( 'punc' === $t['t'] ) {
				if ( 0 === $depth && ')' === $t['v'] ) {
					break;
				}
				if ( 0 === $depth && ',' === $t['v'] ) {
					$args[] = array();
					continue;
				}
				if ( in_array( $t['v'], array( '(', '[', '{' ), true ) ) {
					++$depth;
				} elseif ( in_array( $t['v'], array( ')', ']', '}' ), true ) ) {
					--$depth;
				}
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
				if ( $expect ) {
					if ( 'str' !== $t['t'] || null === $t['v'] ) {
						$value = false;
						break;
					}
					$value .= $t['v'];
					$expect = false;
				} elseif ( 'punc' === $t['t'] && '+' === $t['v'] ) {
					$expect = true;
				} else {
					$value = false;
					break;
				}
			}
			$values[] = ( false === $value || $expect ) ? false : $value;
		}
		return $values;
	}

	/**
	 * POT text for one domain of a manifest (build artifact for languages/*.pot).
	 */
	public static function pot( array $manifest, string $domain ): string {
		$quote = static function ( string $s ): string {
			$s = str_replace( array( '\\', '"', "\t", "\r" ), array( '\\\\', '\\"', '\\t', '\\r' ), $s );
			if ( false === strpos( $s, "\n" ) ) {
				return '"' . $s . '"';
			}
			$lines = explode( "\n", $s );
			$out   = '""';
			foreach ( $lines as $i => $l ) {
				$out .= "\n\"" . $l . ( $i < count( $lines ) - 1 ? '\\n' : '' ) . '"';
			}
			return $out;
		};
		$pot  = "# Generated by Cradle Translations from Cradle package sources.\n";
		$pot .= "msgid \"\"\nmsgstr \"\"\n\"Project-Id-Version: " . $domain . "\\n\"\n\"POT-Creation-Date: " . gmdate( 'Y-m-d H:iO' ) . "\\n\"\n\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n\"X-Domain: " . $domain . "\\n\"\n";
		foreach ( $manifest['entries'] as $entry ) {
			if ( $entry['domain'] !== $domain ) {
				continue;
			}
			$pot .= "\n";
			if ( '' !== $entry['comments'] ) {
				$pot .= '#. ' . str_replace( "\n", ' ', $entry['comments'] ) . "\n";
			}
			if ( $entry['references'] ) {
				$pot .= '#: ' . implode( ' ', $entry['references'] ) . "\n";
			}
			if ( null !== $entry['context'] ) {
				$pot .= 'msgctxt ' . $quote( $entry['context'] ) . "\n";
			}
			$pot .= 'msgid ' . $quote( $entry['msgid'] ) . "\n";
			if ( null !== $entry['msgidPlural'] ) {
				$pot .= 'msgid_plural ' . $quote( $entry['msgidPlural'] ) . "\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n";
			} else {
				$pot .= "msgstr \"\"\n";
			}
		}
		return $pot;
	}
}
