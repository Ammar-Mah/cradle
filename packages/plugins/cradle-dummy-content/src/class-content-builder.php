<?php
/**
 * Builds deterministic titles, block markup bodies, excerpts and field values.
 *
 * Output depends only on (seed, item index, config) so the same seed and
 * settings reproduce the same text regardless of job step boundaries.
 * Bodies use serialized core blocks (paragraph, heading, list/list-item).
 *
 * @package Cradle\Dummy_Content
 */

namespace Cradle\Dummy_Content;

defined( 'ABSPATH' ) || exit;

final class Content_Builder {

	private array $config;
	private Vocabulary $vocabulary;
	private string $type_label;

	public function __construct( array $config ) {
		$this->config     = $config;
		$this->vocabulary = new Vocabulary( (string) ( $config['vocabulary'] ?? 'english' ) );
		$object           = get_post_type_object( (string) $config['post_type'] );
		$this->type_label = $object ? strtolower( (string) $object->labels->singular_name ) : (string) $config['post_type'];
	}

	private function random( int $index, string $purpose ): Random {
		return new Random( (string) $this->config['seed'], 'item:' . $index . ':' . $purpose );
	}

	public function title( int $index ): string {
		return $this->vocabulary->title(
			$this->random( $index, 'title' ),
			(string) $this->config['title_pattern'],
			(string) ( $this->config['title_template'] ?? '' ),
			$index + 1,
			$this->type_label,
			(string) $this->config['batch_name']
		);
	}

	public function excerpt( int $index ): string {
		return empty( $this->config['excerpt'] ) ? '' : $this->vocabulary->sentence( $this->random( $index, 'excerpt' ) );
	}

	public function body( int $index ): string {
		$r      = $this->random( $index, 'body' );
		$range  = (array) ( $this->config['paragraphs'] ?? array( 2, 4 ) );
		$count  = $r->int( (int) $range[0], (int) $range[1] );
		$blocks = array();
		switch ( $this->config['body_pattern'] ?? 'article' ) {
			case 'short':
				$blocks[] = self::paragraph( $this->vocabulary->paragraph( $r, 2, 3 ) );
				break;
			case 'paragraphs':
				for ( $i = 0; $i < $count; $i++ ) {
					$blocks[] = self::paragraph( $this->vocabulary->paragraph( $r ) );
				}
				break;
			case 'article':
			default:
				$blocks[] = self::paragraph( $this->vocabulary->paragraph( $r ) );
				for ( $i = 1; $i < $count; $i++ ) {
					if ( 1 === $i % 2 ) {
						$blocks[] = self::heading( $this->vocabulary->heading( $r ) );
					}
					$blocks[] = self::paragraph( $this->vocabulary->paragraph( $r ) );
					if ( 1 === $i ) {
						$items = array();
						$n     = $r->int( 3, 5 );
						for ( $j = 0; $j < $n; $j++ ) {
							$items[] = $this->vocabulary->phrase( $r );
						}
						$blocks[] = self::list_block( $items );
					}
				}
				break;
		}
		return implode( "\n\n", $blocks );
	}

	/**
	 * Deterministic value for a metadata row.
	 *
	 * @return string|int
	 */
	public function meta_value( int $index, string $key, string $type ) {
		$r = $this->random( $index, 'meta:' . $key );
		switch ( $type ) {
			case 'number':
				return $r->int( 1, 1000 );
			case 'boolean':
				return $r->chance( 50 ) ? '1' : '0';
			case 'date':
				return gmdate( 'Y-m-d', gmmktime( 0, 0, 0, 1, 1, 2020 ) + $r->int( 0, 5 * 365 ) * DAY_IN_SECONDS );
			case 'sentence':
				return $this->vocabulary->sentence( $r );
			case 'word':
			default:
				return $this->vocabulary->word( $r );
		}
	}

	/**
	 * Local post date inside the configured range, or '' for "now".
	 */
	public function date( int $index ): string {
		$dates = (array) ( $this->config['dates'] ?? array() );
		if ( 2 !== count( $dates ) ) {
			return '';
		}
		$r     = $this->random( $index, 'date' );
		$start = strtotime( $dates[0] . ' 00:00:00 UTC' );
		$end   = strtotime( $dates[1] . ' 23:59:59 UTC' );
		$now   = strtotime( current_time( 'mysql' ) . ' UTC' );
		$end   = min( $end, $now );
		$stamp = $start + $r->int( 0, max( 0, $end - $start ) );
		return gmdate( 'Y-m-d H:i:s', $stamp );
	}

	public function author( int $index ): int {
		$authors = (array) ( $this->config['authors'] ?? array() );
		return (int) ( $this->random( $index, 'author' )->pick( $authors ) ?? get_current_user_id() );
	}

	/**
	 * Picks terms for an item from a pool.
	 */
	public function terms( int $index, string $taxonomy, array $pool, int $per_item ): array {
		return array_map( 'intval', $this->random( $index, 'terms:' . $taxonomy )->sample( $pool, $per_item ) );
	}

	public function pick_image( int $index, array $pool ): int {
		return (int) ( $this->random( $index, 'image' )->pick( $pool ) ?? 0 );
	}

	public function term_name( string $taxonomy, int $k, int $attempt ): string {
		$name = $this->vocabulary->term_name( new Random( (string) $this->config['seed'], 'term:' . $taxonomy . ':' . $k ) );
		return $attempt > 0 ? $name . ' ' . ( $attempt + 1 ) : $name;
	}

	/**
	 * Parent index (earlier item) for nested hierarchies, or -1 for top level.
	 */
	public function parent_index( int $index ): int {
		if ( $index < 1 ) {
			return -1;
		}
		$r = $this->random( $index, 'parent' );
		return $r->chance( 60 ) ? $r->int( 0, $index - 1 ) : -1;
	}

	/**
	 * Earlier item indexes related to this item (up to 3).
	 */
	public function related_indexes( int $index ): array {
		if ( $index < 1 ) {
			return array();
		}
		$r = $this->random( $index, 'related' );
		return $r->sample( range( 0, $index - 1 ), $r->int( 1, min( 3, $index ) ) );
	}

	public static function paragraph( string $text ): string {
		return "<!-- wp:paragraph -->\n<p>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->";
	}

	public static function heading( string $text ): string {
		return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $text ) . "</h2>\n<!-- /wp:heading -->";
	}

	public static function list_block( array $items ): string {
		$inner = '';
		foreach ( $items as $item ) {
			$inner .= "<!-- wp:list-item -->\n<li>" . esc_html( (string) $item ) . "</li>\n<!-- /wp:list-item -->";
		}
		return "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . $inner . "</ul>\n<!-- /wp:list -->";
	}
}
