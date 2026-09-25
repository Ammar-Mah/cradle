<?php
/**
 * Bundled local vocabularies and deterministic text templates. No network access.
 *
 * @package Cradle\Dummy_Content
 */

namespace Cradle\Dummy_Content;

defined( 'ABSPATH' ) || exit;

final class Vocabulary {

	const NAMES = array( 'english', 'latin' );

	/** Tokens accepted in user-supplied custom title templates. */
	const CUSTOM_TOKENS = array( 'n', 'adjective', 'noun', 'verb', 'word', 'batch', 'type' );

	/** @var array<string,array> */
	private static array $cache = array();

	private array $data;
	private string $name;

	public function __construct( string $name ) {
		$this->name = in_array( $name, self::NAMES, true ) ? $name : 'english';
		$this->data = self::load( $this->name );
	}

	/**
	 * Reads a bundled vocabulary file (fixed allowlisted path; JSON data, never code).
	 */
	private static function load( string $name ): array {
		if ( isset( self::$cache[ $name ] ) ) {
			return self::$cache[ $name ];
		}
		$file = CRADLE_DUMMY_CONTENT_DIR . 'data/vocabulary-' . $name . '.json';
		$data = is_file( $file ) ? wp_json_file_decode( $file, array( 'associative' => true ) ) : null;
		if ( ! is_array( $data ) || 'cradle-dummy-vocabulary' !== ( $data['format'] ?? '' ) ) {
			$data = array( 'words' => array( 'sample', 'text', 'content', 'example', 'placeholder' ) );
		}
		foreach ( array( 'adjectives', 'nouns', 'verbs', 'adverbs', 'words', 'sentences' ) as $key ) {
			$data[ $key ] = array_values( array_filter( (array) ( $data[ $key ] ?? array() ), 'is_string' ) );
		}
		if ( ! $data['words'] ) {
			$data['words'] = array_merge( $data['adjectives'], $data['nouns'], $data['verbs'] );
		}
		foreach ( array( 'adjectives', 'nouns', 'verbs', 'adverbs' ) as $key ) {
			if ( ! $data[ $key ] ) {
				$data[ $key ] = $data['words'];
			}
		}
		self::$cache[ $name ] = $data;
		return $data;
	}

	public static function labels(): array {
		return array(
			'english' => __( 'English sample words', 'cradle-dummy-content' ),
			'latin'   => __( 'Latin placeholder (lorem ipsum)', 'cradle-dummy-content' ),
		);
	}

	private static function plural( string $word ): string {
		if ( preg_match( '/[^aeiou]y$/', $word ) ) {
			return substr( $word, 0, -1 ) . 'ies';
		}
		if ( preg_match( '/(s|x|z|ch|sh)$/', $word ) ) {
			return $word . 'es';
		}
		return $word . 's';
	}

	private static function upper_first( string $text ): string {
		return '' === $text ? '' : mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
	}

	/**
	 * Replaces {tokens}; unknown tokens are left untouched (templates are validated elsewhere).
	 */
	private function fill( string $template, Random $r, array $context = array() ): string {
		$text = (string) preg_replace_callback(
			'/\{([A-Za-z]+)\}/',
			function ( $m ) use ( $r, $context ) {
				$token = $m[1];
				$lower = strtolower( $token );
				switch ( $lower ) {
					case 'adj':
					case 'adjective':
						$value = (string) $r->pick( $this->data['adjectives'] );
						break;
					case 'noun':
						$value = (string) $r->pick( $this->data['nouns'] );
						break;
					case 'nouns':
						$value = self::plural( (string) $r->pick( $this->data['nouns'] ) );
						break;
					case 'verb':
						$value = (string) $r->pick( $this->data['verbs'] );
						break;
					case 'verbs':
						$value = self::plural( (string) $r->pick( $this->data['verbs'] ) );
						break;
					case 'adverb':
						$value = (string) $r->pick( $this->data['adverbs'] );
						break;
					case 'word':
						$value = (string) $r->pick( $this->data['words'] );
						break;
					default:
						if ( array_key_exists( $lower, $context ) ) {
							return (string) $context[ $lower ];
						}
						return $m[0];
				}
				return ctype_upper( $token[0] ) ? self::upper_first( $value ) : $value;
			},
			$template
		);
		// English article agreement for generated words ("a early" → "an early").
		return 'english' === $this->name ? (string) preg_replace( '/\b([Aa]) ([aeiouAEIOU])/', '$1n $2', $text ) : $text;
	}

	/**
	 * Words-only sentence (used by vocabularies without sentence templates).
	 */
	private function word_sentence( Random $r, int $min, int $max, string $end = '.' ): string {
		$words = array();
		$count = $r->int( $min, $max );
		for ( $i = 0; $i < $count; $i++ ) {
			$words[] = (string) $r->pick( $this->data['words'] );
		}
		return self::upper_first( implode( ' ', $words ) ) . $end;
	}

	/**
	 * @param string $pattern  headline|question|numbered|custom.
	 * @param string $template Custom template (validated tokens only).
	 * @param int    $number   1-based item number.
	 * @param string $type     Singular post type label.
	 * @param string $batch    Batch name.
	 */
	public function title( Random $r, string $pattern, string $template, int $number, string $type, string $batch ): string {
		$context = array(
			'n'     => $number,
			'type'  => $type,
			'batch' => $batch,
		);
		switch ( $pattern ) {
			case 'numbered':
				/* translators: 1: post type label, 2: item number */
				return sprintf( __( 'Sample %1$s %2$d', 'cradle-dummy-content' ), $type, $number );
			case 'custom':
				return self::upper_first( trim( $this->fill( $template, $r, $context ) ) );
			case 'question':
			case 'headline':
			default:
				$templates = (array) ( $this->data['titles'][ 'question' === $pattern ? 'question' : 'headline' ] ?? array() );
				if ( ! $templates ) {
					return $this->word_sentence( $r, 3, 6, 'question' === $pattern ? '?' : '' );
				}
				return self::upper_first( $this->fill( (string) $r->pick( $templates ), $r, $context ) );
		}
	}

	public function sentence( Random $r ): string {
		if ( $this->data['sentences'] ) {
			return self::upper_first( $this->fill( (string) $r->pick( $this->data['sentences'] ), $r ) );
		}
		return $this->word_sentence( $r, 7, 14 );
	}

	public function paragraph( Random $r, int $min_sentences = 3, int $max_sentences = 6 ): string {
		$out   = array();
		$count = $r->int( $min_sentences, $max_sentences );
		for ( $i = 0; $i < $count; $i++ ) {
			$out[] = $this->sentence( $r );
		}
		return implode( ' ', $out );
	}

	public function heading( Random $r ): string {
		if ( isset( $this->data['titles']['headline'] ) ) {
			return self::upper_first( $this->fill( (string) $r->pick( $this->data['titles']['headline'] ), $r ) );
		}
		return $this->word_sentence( $r, 2, 5, '' );
	}

	public function phrase( Random $r, int $min = 2, int $max = 5 ): string {
		if ( 'english' === $this->name ) {
			return self::upper_first( $this->fill( $r->pick( array( '{adj} {noun}', '{verb} the {noun}', '{adj} {nouns}', '{verb} {adverb}' ) ), $r ) );
		}
		return $this->word_sentence( $r, $min, $max, '' );
	}

	public function term_name( Random $r ): string {
		if ( 'english' === $this->name ) {
			return self::upper_first( $this->fill( '{adj} {nouns}', $r ) );
		}
		return $this->word_sentence( $r, 1, 2, '' );
	}

	public function word( Random $r ): string {
		return (string) $r->pick( $this->data['words'] ?: $this->data['nouns'] );
	}
}
