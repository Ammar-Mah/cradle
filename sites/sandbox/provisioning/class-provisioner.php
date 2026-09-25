<?php
/**
 * Idempotent content provisioning (northstar §8.7).
 *
 * Every provisioned record carries protected meta `_cradle_source_key`; the registry option
 * `cradle_site_provisioning` maps source keys to IDs and to the content hash written last time.
 * On a later run:
 * - records are matched by source key, never by title or slug;
 * - a record whose current content still equals what provisioning wrote is updated when the
 *   definition changed;
 * - a record an editor has changed since is left alone and reported as a conflict unless its
 *   key is listed in `replace`;
 * - nothing is deleted.
 * Runs only through the CLI entry point sites/sandbox/bin/provision.php (demo tooling, not a plugin).
 *
 * @package Cradle Sandbox
 */

namespace Cradle\Sandbox;

defined( 'ABSPATH' ) || exit;

final class Provisioner {

	const REGISTRY = 'cradle_site_provisioning';
	const META_KEY = '_cradle_source_key';

	/** @var array */
	private $options;

	/** @var array */
	private $registry;

	/** @var array */
	private $report = array(
		'created'   => array(),
		'updated'   => array(),
		'unchanged' => array(),
		'conflicts' => array(),
		'errors'    => array(),
	);

	public function __construct( array $options = array() ) {
		$this->options  = wp_parse_args(
			$options,
			array(
				'dry_run'      => false,
				'replace'      => array(),
				'set_identity' => false,
				'set_reading'  => true,
			)
		);
		$registry       = get_option( self::REGISTRY, array() );
		$this->registry = is_array( $registry ) ? $registry : array();
	}

	/**
	 * @param array $definitions See content/definitions.php.
	 */
	public function run( array $definitions ): array {
		if ( ! current_user_can( 'unfiltered_html' ) || ! current_user_can( 'edit_theme_options' ) ) {
			$this->report['errors'][] = 'Provisioning must run as an administrator with unfiltered_html (block attributes would otherwise be kses-encoded).';
			return $this->report;
		}
		foreach ( $definitions['media'] ?? array() as $key => $def ) {
			$this->ensure_attachment( $key, $def );
		}
		foreach ( $definitions['terms'] ?? array() as $key => $def ) {
			$this->ensure_term( $key, $def );
		}
		foreach ( array( 'pages', 'projects', 'posts' ) as $group ) {
			foreach ( $definitions[ $group ] ?? array() as $key => $def ) {
				$this->ensure_post( $key, is_callable( $def ) ? $def( $this ) : $def );
			}
		}
		foreach ( $definitions['navigation'] ?? array() as $key => $def ) {
			$this->ensure_post( $key, is_callable( $def ) ? $def( $this ) : $def );
		}
		if ( $this->options['set_reading'] && ! empty( $definitions['reading'] ) ) {
			$this->set_reading( $definitions['reading'] );
		}
		if ( $this->options['set_identity'] && ! empty( $definitions['identity'] ) ) {
			$this->set_identity( $definitions['identity'] );
		}
		if ( ! $this->options['dry_run'] ) {
			update_option( self::REGISTRY, $this->registry, false );
			flush_rewrite_rules( false );
		}
		return $this->report;
	}

	/**
	 * ID of a provisioned record, or 0.
	 */
	public function id( string $key ): int {
		return (int) ( $this->registry[ $key ]['id'] ?? 0 );
	}

	public function url( string $key ): string {
		$id = $this->id( $key );
		if ( ! $id ) {
			return home_url( '/' );
		}
		return 'term' === ( $this->registry[ $key ]['type'] ?? '' ) ? (string) get_term_link( $id ) : (string) get_permalink( $id );
	}

	private function find_by_key( string $key, string $type ): int {
		$id = $this->id( $key );
		if ( $id ) {
			$exists = 'term' === $type ? get_term( $id ) : get_post( $id );
			$marker = 'term' === $type ? get_term_meta( $id, self::META_KEY, true ) : get_post_meta( $id, self::META_KEY, true );
			if ( $exists && ! is_wp_error( $exists ) && $marker === $key ) {
				return $id;
			}
		}
		if ( 'term' === $type ) {
			$terms = get_terms(
				array(
					'hide_empty' => false,
					'meta_key'   => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
					'number'     => 1,
					'fields'     => 'ids',
				)
			);
			return is_array( $terms ) && $terms ? (int) $terms[0] : 0;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'any' === $type ? array( 'post', 'page', 'attachment', 'wp_navigation', 'project' ) : $type,
				'post_status'    => 'any',
				'meta_key'       => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return $posts ? (int) $posts[0] : 0;
	}

	private function ensure_attachment( string $key, array $def ) {
		$existing = $this->find_by_key( $key, 'attachment' );
		if ( $existing ) {
			$this->registry[ $key ] = array( 'id' => $existing, 'type' => 'attachment', 'hash' => '' );
			$this->report['unchanged'][] = $key;
			return;
		}
		if ( $this->options['dry_run'] ) {
			$this->report['created'][] = $key . ' (dry run)';
			return;
		}
		$source = (string) $def['file'];
		if ( ! is_file( $source ) ) {
			$this->report['errors'][] = "{$key}: source image missing";
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$upload = wp_upload_bits( basename( $source ), null, (string) file_get_contents( $source ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! empty( $upload['error'] ) ) {
			$this->report['errors'][] = "{$key}: " . $upload['error'];
			return;
		}
		$type = wp_check_filetype( $upload['file'] );
		$id   = wp_insert_attachment(
			array(
				'post_title'     => $def['title'],
				'post_mime_type' => $type['type'],
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( is_wp_error( $id ) || ! $id ) {
			$this->report['errors'][] = "{$key}: attachment insert failed";
			return;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		update_post_meta( $id, '_wp_attachment_image_alt', $def['alt'] );
		update_post_meta( $id, self::META_KEY, $key );
		$this->registry[ $key ]    = array( 'id' => $id, 'type' => 'attachment', 'hash' => '' );
		$this->report['created'][] = $key;
	}

	private function ensure_term( string $key, array $def ) {
		$existing = $this->find_by_key( $key, 'term' );
		if ( ! $existing ) {
			$by_slug = get_term_by( 'slug', $def['slug'], $def['taxonomy'] );
			if ( $by_slug ) {
				// A term with this slug already exists and is not ours: reuse it without claiming ownership.
				$this->registry[ $key ]      = array( 'id' => (int) $by_slug->term_id, 'type' => 'term', 'hash' => '', 'owned' => false );
				$this->report['unchanged'][] = $key . ' (existing, not owned)';
				return;
			}
			if ( $this->options['dry_run'] ) {
				$this->report['created'][] = $key . ' (dry run)';
				return;
			}
			$result = wp_insert_term( $def['name'], $def['taxonomy'], array( 'slug' => $def['slug'], 'description' => $def['description'] ?? '' ) );
			if ( is_wp_error( $result ) ) {
				$this->report['errors'][] = "{$key}: " . $result->get_error_message();
				return;
			}
			update_term_meta( $result['term_id'], self::META_KEY, $key );
			$this->registry[ $key ]    = array( 'id' => (int) $result['term_id'], 'type' => 'term', 'hash' => '', 'owned' => true );
			$this->report['created'][] = $key;
			return;
		}
		$this->registry[ $key ]      = array( 'id' => $existing, 'type' => 'term', 'hash' => '', 'owned' => true );
		$this->report['unchanged'][] = $key;
	}

	private static function content_hash( $post ): string {
		return md5( $post->post_title . "\0" . $post->post_content . "\0" . $post->post_excerpt );
	}

	private function ensure_post( string $key, array $def ) {
		$type     = $def['post_type'];
		$existing = $this->find_by_key( $key, $type );
		$postarr  = array(
			'post_type'    => $type,
			'post_title'   => $def['title'],
			'post_name'    => $def['slug'] ?? '',
			'post_content' => $def['content'] ?? '',
			'post_excerpt' => $def['excerpt'] ?? '',
			'post_status'  => $def['status'] ?? 'publish',
			'menu_order'   => $def['menu_order'] ?? 0,
		);
		if ( isset( $def['date'] ) ) {
			$postarr['post_date'] = $def['date'];
		}
		$definition_hash = md5( wp_json_encode( $def ) );

		if ( $existing ) {
			$post       = get_post( $existing );
			$record     = $this->registry[ $key ] ?? array();
			$edited     = isset( $record['hash'] ) && '' !== $record['hash'] && self::content_hash( $post ) !== $record['hash'];
			$def_change = ( $record['definition'] ?? '' ) !== $definition_hash;
			if ( $edited && ! in_array( $key, $this->options['replace'], true ) ) {
				$this->registry[ $key ]['id'] = $existing;
				$this->report['conflicts'][]  = $key . ' (edited in WordPress since provisioning; left unchanged — rerun with --replace=' . $key . ' to overwrite)';
				return;
			}
			if ( ! $def_change && ! $edited ) {
				$this->registry[ $key ]['id'] = $existing;
				$this->report['unchanged'][]  = $key;
				return;
			}
			if ( $this->options['dry_run'] ) {
				$this->report['updated'][] = $key . ' (dry run)';
				return;
			}
			$postarr['ID'] = $existing;
			unset( $postarr['post_status'] );
			$result = wp_update_post( wp_slash( $postarr ), true );
		} else {
			if ( $this->options['dry_run'] ) {
				$this->report['created'][] = $key . ' (dry run)';
				return;
			}
			$result = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $result ) ) {
			$this->report['errors'][] = "{$key}: " . $result->get_error_message();
			return;
		}
		$id = (int) $result;
		update_post_meta( $id, self::META_KEY, $key );
		if ( ! empty( $def['template'] ) ) {
			update_post_meta( $id, '_wp_page_template', $def['template'] );
		}
		foreach ( $def['meta'] ?? array() as $meta_key => $value ) {
			update_post_meta( $id, $meta_key, $value );
		}
		foreach ( $def['terms'] ?? array() as $taxonomy => $term_keys ) {
			$ids = array_filter( array_map( array( $this, 'id' ), $term_keys ) );
			wp_set_object_terms( $id, array_map( 'intval', $ids ), $taxonomy );
		}
		if ( ! empty( $def['featured'] ) && $this->id( $def['featured'] ) ) {
			set_post_thumbnail( $id, $this->id( $def['featured'] ) );
		}
		clean_post_cache( $id );
		$this->registry[ $key ] = array(
			'id'         => $id,
			'type'       => $type,
			'hash'       => self::content_hash( get_post( $id ) ),
			'definition' => $definition_hash,
		);
		$this->report[ $existing ? 'updated' : 'created' ][] = $key;
	}

	private function set_reading( array $reading ) {
		$front = $this->id( $reading['front'] );
		$posts = $this->id( $reading['posts'] );
		if ( ! $front || ! $posts ) {
			return;
		}
		$current_front = (int) get_option( 'page_on_front' );
		$ours          = in_array( $current_front, array( 0, $front ), true );
		if ( 'posts' !== get_option( 'show_on_front' ) && ! $ours ) {
			$this->report['conflicts'][] = 'reading: a different static front page is configured; left unchanged';
			return;
		}
		if ( $this->options['dry_run'] ) {
			return;
		}
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );
		update_option( 'page_for_posts', $posts );
		$this->report['updated'][] = 'reading';
	}

	private function set_identity( array $identity ) {
		if ( $this->options['dry_run'] ) {
			return;
		}
		update_option( 'blogname', $identity['name'] );
		update_option( 'blogdescription', $identity['tagline'] ?? '' );
		$this->report['updated'][] = 'identity';
	}
}
