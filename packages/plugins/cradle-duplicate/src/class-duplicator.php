<?php
/**
 * Creates a draft copy of one post using WordPress data APIs.
 *
 * Copied: title (optionally suffixed), block content unchanged, excerpt, featured
 * image reference, page template, parent and menu order, comment/ping status,
 * post password, supported taxonomy terms and allowed metadata.
 * Not copied: status (always draft), dates (current), slug (made unique),
 * attachments (references are reused), locks, revision bookkeeping, ownership
 * markers, secrets, order/payment data and unknown private plugin metadata.
 *
 * @package Cradle\Duplicate
 */

namespace Cradle\Duplicate;

defined( 'ABSPATH' ) || exit;

final class Duplicator {

	/** Provenance meta on the new draft: ID of the source. */
	const SOURCE_META = '_cradle_duplicate_of';

	/**
	 * Duplicates a post as the current user.
	 *
	 * @param int $post_id Source post ID.
	 * @return array|\WP_Error { id, source, edit_link, report }
	 */
	public static function duplicate( int $post_id ) {
		$check = Policy::check( $post_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$source  = get_post( $post_id );
		$user_id = get_current_user_id();

		// Refuse concurrent double submissions of the same item by the same user.
		$lock_name = 'duplicate:' . $source->ID . ':' . $user_id;
		$lock      = cradle()->locks()->acquire( $lock_name, 30 );
		if ( ! $lock ) {
			return new \WP_Error( 'cradle_duplicate_busy', __( 'This item is already being duplicated. Wait a moment and reload.', 'cradle-duplicate' ), array( 'status' => 409 ) );
		}
		try {
			return self::copy( $source, $user_id );
		} finally {
			cradle()->locks()->release( $lock_name, $lock );
		}
	}

	/**
	 * @return array|\WP_Error
	 */
	private static function copy( \WP_Post $source, int $user_id ) {
		$type   = get_post_type_object( $source->post_type );
		$report = array(
			'meta_copied'        => array(),
			'meta_skipped'       => array(),
			'terms'              => array(),
			'taxonomies_skipped' => array(),
			'featured_image'     => 0,
			'template'           => '',
			'synced_patterns'    => self::count_synced_patterns( $source->post_content ),
			'author'             => 0,
		);

		$title            = self::new_title( $source );
		$author           = self::resolve_author( $source, $user_id, $type );
		$report['author'] = $author;

		$base = '' !== $source->post_name ? $source->post_name : sanitize_title( $title );
		$base = preg_replace( '/__trashed(-\d+)?$/', '', (string) $base );
		// Drafts skip WordPress' uniqueness check, so compute a unique slug as if published.
		$slug = '' !== $base ? wp_unique_post_slug( $base, 0, 'publish', $source->post_type, (int) $source->post_parent ) : '';

		$postarr = array(
			'post_type'      => $source->post_type,
			'post_status'    => 'draft',
			'post_title'     => $title,
			'post_content'   => $source->post_content,
			'post_excerpt'   => $source->post_excerpt,
			'post_author'    => $author,
			'post_parent'    => (int) $source->post_parent,
			'menu_order'     => (int) $source->menu_order,
			'comment_status' => $source->comment_status,
			'ping_status'    => $source->ping_status,
			'post_password'  => $source->post_password,
			'post_name'      => $slug,
		);
		/**
		 * Filters the data passed to wp_insert_post() for the new draft.
		 * Status, type, dates, ID, meta_input and tax_input are enforced afterwards.
		 *
		 * @param array    $postarr Unslashed post data.
		 * @param \WP_Post $source  Source post.
		 */
		$postarr = (array) apply_filters( 'cradle_duplicate/post_data', $postarr, $source );
		unset( $postarr['ID'], $postarr['post_date'], $postarr['post_date_gmt'], $postarr['post_modified'], $postarr['post_modified_gmt'], $postarr['meta_input'], $postarr['tax_input'], $postarr['guid'] );
		$postarr['post_status'] = 'draft';
		$postarr['post_type']   = $source->post_type;
		$postarr['post_author'] = $author;

		$new_id = wp_insert_post( wp_slash( $postarr ), true, false );
		if ( is_wp_error( $new_id ) ) {
			return new \WP_Error( 'cradle_duplicate_insert', __( 'The draft could not be created.', 'cradle-duplicate' ), array( 'status' => 500 ) );
		}
		$new_id = (int) $new_id;

		// Page template (validated when the source was saved; copied as-is).
		$template = (string) get_post_meta( $source->ID, '_wp_page_template', true );
		if ( '' !== $template && 'default' !== $template ) {
			update_post_meta( $new_id, '_wp_page_template', wp_slash( $template ) );
			$report['template'] = $template;
		}

		// Featured image: reuse the reference; attachments are never cloned.
		$thumbnail = (int) get_post_thumbnail_id( $source );
		if ( $thumbnail && 'attachment' === get_post_type( $thumbnail ) ) {
			set_post_thumbnail( $new_id, $thumbnail );
			$report['featured_image'] = $thumbnail;
		}

		self::copy_terms( $source, $new_id, $report );
		self::copy_meta( $source, $new_id, $report );

		update_post_meta( $new_id, self::SOURCE_META, (int) $source->ID );
		clean_post_cache( $new_id );
		// Terms and meta are in place: now let other plugins react to the finished insert.
		wp_after_insert_post( $new_id, false, null );

		cradle_log_activity(
			Module::ID,
			'post_duplicated',
			sprintf( '%1$s #%2$d duplicated to draft #%3$d.', $source->post_type, $source->ID, $new_id ),
			array(
				'source'          => (int) $source->ID,
				'copy'            => $new_id,
				'meta_copied'     => count( $report['meta_copied'] ),
				'meta_skipped'    => count( $report['meta_skipped'] ),
				'synced_patterns' => $report['synced_patterns'],
			)
		);

		/**
		 * Fires after a draft copy was created.
		 *
		 * @param int      $new_id New draft ID.
		 * @param \WP_Post $source Source post.
		 * @param array    $report What was copied and skipped.
		 */
		do_action( 'cradle_duplicate/duplicated', $new_id, $source, $report );

		return array(
			'id'        => $new_id,
			'source'    => (int) $source->ID,
			'edit_link' => self::edit_link( $new_id ),
			'report'    => $report,
		);
	}

	/**
	 * Read-only plan for the confirmation screen: what duplicate() would copy for the current user.
	 */
	public static function preview( \WP_Post $source ): array {
		$user_id   = get_current_user_id();
		$type      = get_post_type_object( $source->post_type );
		$allowlist = Policy::meta_allowlist( $source );
		$patterns  = Policy::meta_deny_patterns( $source );
		$plan      = array(
			'title'              => self::new_title( $source ),
			'author'             => $type ? self::resolve_author( $source, $user_id, $type ) : $user_id,
			'template'           => '',
			'featured_image'     => 0,
			'terms'              => array(),
			'taxonomies_skipped' => array(),
			'meta_copied'        => array(),
			'meta_skipped'       => array(),
			'synced_patterns'    => self::count_synced_patterns( $source->post_content ),
		);
		$template = (string) get_post_meta( $source->ID, '_wp_page_template', true );
		if ( '' !== $template && 'default' !== $template ) {
			$plan['template'] = $template;
		}
		$thumbnail = (int) get_post_thumbnail_id( $source );
		if ( $thumbnail && 'attachment' === get_post_type( $thumbnail ) ) {
			$plan['featured_image'] = $thumbnail;
		}
		$allowed_tax = (array) apply_filters( 'cradle_duplicate/taxonomies', get_object_taxonomies( $source->post_type ), $source ); // Documented in copy_terms().
		foreach ( get_object_taxonomies( $source->post_type, 'objects' ) as $name => $taxonomy ) {
			if ( ! in_array( $name, $allowed_tax, true ) ) {
				continue;
			}
			if ( ! current_user_can( $taxonomy->cap->assign_terms ) ) {
				$plan['taxonomies_skipped'][] = $name;
				continue;
			}
			$ids = wp_get_object_terms( $source->ID, $name, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $ids ) && $ids ) {
				$plan['terms'][ $name ] = count( $ids );
			}
		}
		foreach ( array_keys( (array) get_post_meta( $source->ID ) ) as $key ) {
			$decision = Policy::meta_decision( (string) $key, $source, $allowlist, $patterns );
			if ( 'copy' === $decision ) {
				$plan['meta_copied'][] = (string) $key;
			} else {
				$plan['meta_skipped'][ (string) $key ] = $decision;
			}
		}
		return $plan;
	}

	/**
	 * Title of the new draft: source title plus the configured suffix, filterable.
	 */
	public static function new_title( \WP_Post $source ): string {
		$title  = $source->post_title;
		$suffix = trim( (string) Config::get( 'title_suffix' ) );
		if ( '' !== $suffix ) {
			$title = trim( $title . ' ' . $suffix );
		}
		/**
		 * Filters the title of the new draft.
		 *
		 * @param string   $title  Title with the configured suffix.
		 * @param \WP_Post $source Source post.
		 */
		return (string) apply_filters( 'cradle_duplicate/title', $title, $source );
	}

	/**
	 * Author policy: current user by default; the original author only when the
	 * acting user may edit others' items of this type. Filterable, then re-validated.
	 */
	public static function resolve_author( \WP_Post $source, int $user_id, \WP_Post_Type $type ): int {
		$can_others = user_can( $user_id, $type->cap->edit_others_posts );
		$author     = $user_id;
		if ( 'original' === Config::get( 'author_policy' ) && $can_others && get_userdata( (int) $source->post_author ) ) {
			$author = (int) $source->post_author;
		}
		/**
		 * Filters the author of the new draft.
		 *
		 * The result is accepted only when the user exists and is either the acting
		 * user or the acting user may edit others' items of this type.
		 *
		 * @param int      $author  Proposed author ID.
		 * @param \WP_Post $source  Source post.
		 * @param int      $user_id Acting user.
		 */
		$filtered = (int) apply_filters( 'cradle_duplicate/author', $author, $source, $user_id );
		if ( $filtered === $user_id || ( $filtered > 0 && $can_others && get_userdata( $filtered ) ) ) {
			return $filtered;
		}
		return $user_id;
	}

	private static function copy_terms( \WP_Post $source, int $new_id, array &$report ) {
		$taxonomies = get_object_taxonomies( $source->post_type, 'objects' );
		/**
		 * Filters which taxonomies' terms are copied.
		 *
		 * @param string[] $names  Taxonomy names.
		 * @param \WP_Post $source Source post.
		 */
		$allowed = (array) apply_filters( 'cradle_duplicate/taxonomies', array_keys( $taxonomies ), $source );
		foreach ( $taxonomies as $name => $taxonomy ) {
			if ( ! in_array( $name, $allowed, true ) ) {
				continue;
			}
			if ( ! current_user_can( $taxonomy->cap->assign_terms ) ) {
				$report['taxonomies_skipped'][] = $name;
				continue;
			}
			$ids = wp_get_object_terms(
				$source->ID,
				$name,
				array(
					'fields'  => 'ids',
					'orderby' => 'term_order',
				)
			);
			if ( is_wp_error( $ids ) ) {
				continue;
			}
			$ids = array_map( 'intval', $ids );
			if ( $ids ) {
				wp_set_object_terms( $new_id, $ids, $name );
			}
			$report['terms'][ $name ] = count( $ids );
		}
	}

	private static function copy_meta( \WP_Post $source, int $new_id, array &$report ) {
		$all       = get_post_meta( $source->ID );
		$allowlist = Policy::meta_allowlist( $source );
		$patterns  = Policy::meta_deny_patterns( $source );
		foreach ( array_keys( is_array( $all ) ? $all : array() ) as $key ) {
			$key      = (string) $key;
			$decision = Policy::meta_decision( $key, $source, $allowlist, $patterns );
			if ( 'copy' !== $decision ) {
				$report['meta_skipped'][ $key ] = $decision;
				continue;
			}
			if ( metadata_exists( 'post', $new_id, $key ) ) {
				// Another plugin already set it while the draft was inserted; do not stack values.
				$report['meta_skipped'][ $key ] = 'exists';
				continue;
			}
			foreach ( get_post_meta( $source->ID, $key, false ) as $value ) {
				add_post_meta( $new_id, $key, self::slash_deep( $value ) );
			}
			$report['meta_copied'][] = $key;
		}
	}

	/**
	 * Adds slashes to strings at any depth (arrays and objects), matching add_metadata()'s unslashing.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function slash_deep( $value ) {
		if ( is_object( $value ) ) {
			$value = clone $value;
		}
		return map_deep(
			$value,
			static function ( $item ) {
				return is_string( $item ) ? addslashes( $item ) : $item;
			}
		);
	}

	/**
	 * Counts synced pattern references (core/block with a ref) at any depth.
	 */
	public static function count_synced_patterns( string $content ): int {
		if ( false === strpos( $content, '<!-- wp:block' ) ) {
			return 0;
		}
		$count = 0;
		$walk  = static function ( array $blocks ) use ( &$walk, &$count ) {
			foreach ( $blocks as $block ) {
				if ( 'core/block' === ( $block['blockName'] ?? '' ) && ! empty( $block['attrs']['ref'] ) ) {
					++$count;
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};
		$walk( parse_blocks( $content ) );
		return $count;
	}

	/**
	 * Edit link of a new draft with the one-time "just duplicated" marker (display only).
	 */
	public static function edit_link( int $post_id ): string {
		$link = get_edit_post_link( $post_id, 'raw' );
		if ( ! $link ) {
			$link = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		}
		return add_query_arg( 'cradle_duplicated', '1', $link );
	}
}
