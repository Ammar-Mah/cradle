<?php
/**
 * "Add translation": creates a draft copy of an entry in another language, linked to its group.
 *
 * Copied: title, block content (unchanged), excerpt, featured image (same attachment), page
 * template, parent (its translation in the target language when one exists, otherwise none),
 * menu order, comment/ping status, terms of shared taxonomies, terms of translatable taxonomies
 * mapped to their translation in the target language (terms without one are skipped and
 * reported), public post meta and Cradle Content fields (`_cradle_cf_*`).
 * Not copied: status (always draft), dates, slug (source slug + "-<lang>", made unique), author
 * (the person translating), other protected/internal meta (locks, old slugs, provenance and
 * ownership markers, this module's own keys) and meta whose key looks like a secret or payment data.
 *
 * Cradle Duplicate's service is not used: it appends a "(Copy)" title suffix, copies only types
 * enabled in its own settings and copies private fields only when allowlisted there — a
 * translation needs the same title and every Cradle Content field regardless of that configuration.
 *
 * @package Cradle\Languages
 */

namespace Cradle\Languages;

defined( 'ABSPATH' ) || exit;

final class Translator {

	/** Protected keys that are copied (exact or `prefix*`). */
	const COPY_PROTECTED = array( '_cradle_cf_*', '_wp_page_template' );

	/** Keys never copied (exact or `prefix*`). */
	const NEVER = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_desired_post_slug', '_wp_trash_*', '_encloseme', '_pingme', '_trackbackme', 'enclosure', '_oembed_*', '_wp_attached_file', '_wp_attachment_*', '_menu_item_*', '_thumbnail_id', '_cradle_lang_*', '_cradle_duplicate_of', '_cradle_source_key', '_cradle_generat*', '_cradle_test_fixture' );

	/** Key segments that look like secrets or payment data (never copied). */
	const DENY_SEGMENTS = array( 'password', 'passwd', 'secret', 'token', 'apikey', 'api_key', 'auth', 'nonce', 'session', 'license', 'signature', 'webhook', 'payment', 'transaction', 'billing', 'card', 'iban', 'stripe', 'paypal' );

	/**
	 * Creates the translation as the current user. Callers check edit_post on the source and the
	 * type's create_posts capability (REST permission callback); this re-checks them.
	 *
	 * @return array{id:int,edit_link:string,report:array}|\WP_Error
	 */
	public static function create( int $source_id, string $slug ) {
		$source = get_post( $source_id );
		if ( ! $source || in_array( $source->post_status, Content::IGNORED_STATUSES, true ) ) {
			return new \WP_Error( 'cradle_languages_source', __( 'Save the entry before adding a translation.', 'cradle-languages' ), array( 'status' => 409 ) );
		}
		$type = get_post_type_object( $source->post_type );
		if ( ! $type || ! Content::is_translatable_post_type( $source->post_type ) ) {
			return new \WP_Error( 'cradle_languages_shared', __( 'Entries of this type are shared by all languages.', 'cradle-languages' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'edit_post', $source->ID ) || ! current_user_can( $type->cap->create_posts ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You are not allowed to translate this entry.', 'cradle-languages' ), array( 'status' => 403 ) );
		}
		$target = Languages::get( $slug );
		if ( ! $target ) {
			return new \WP_Error( 'cradle_languages_unknown', __( 'This language is not configured.', 'cradle-languages' ), array( 'status' => 400 ) );
		}
		$own = Content::post_language_slug( $source );
		if ( $own === $slug ) {
			return new \WP_Error( 'cradle_languages_same', __( 'The entry is already in this language.', 'cradle-languages' ), array( 'status' => 400 ) );
		}
		$core = cradle();
		$lock = 'languages:translate:' . $source->ID;
		$hold = $core ? $core->locks()->acquire( $lock, 30 ) : null;
		if ( $core && ! $hold ) {
			return new \WP_Error( 'cradle_languages_busy', __( 'A translation of this entry is being created. Wait a moment and reload.', 'cradle-languages' ), array( 'status' => 409 ) );
		}
		try {
			if ( Content::translation( $source->ID, $slug ) ) {
				return new \WP_Error( 'cradle_languages_exists', __( 'A translation in this language already exists.', 'cradle-languages' ), array( 'status' => 409 ) );
			}
			if ( '' === Content::post_language_term( $source->ID ) ) {
				Content::set_language( $source->ID, $own ? $own : Languages::default_slug() );
			}
			return self::copy( $source, $target );
		} finally {
			if ( $core && $hold ) {
				$core->locks()->release( $lock, $hold );
			}
		}
	}

	private static function copy( \WP_Post $source, array $target ) {
		$report = array(
			'terms'         => array(),
			'termsSkipped'  => array(),
			'metaCopied'    => array(),
			'metaSkipped'   => array(),
			'featuredImage' => 0,
		);
		$group  = Content::ensure_group( $source->ID );
		$parent = 0;
		if ( $source->post_parent ) {
			$parent = Content::translation( (int) $source->post_parent, $target['slug'] );
		}
		$base = '' !== $source->post_name ? preg_replace( '/__trashed(-\d+)?$/', '', $source->post_name ) : sanitize_title( $source->post_title );
		$slug = '' !== $base ? wp_unique_post_slug( $base . '-' . $target['slug'], 0, 'publish', $source->post_type, $parent ) : '';

		$postarr = array(
			'post_type'      => $source->post_type,
			'post_status'    => 'draft',
			'post_title'     => $source->post_title,
			'post_content'   => $source->post_content,
			'post_excerpt'   => $source->post_excerpt,
			'post_author'    => get_current_user_id(),
			'post_parent'    => $parent,
			'menu_order'     => (int) $source->menu_order,
			'comment_status' => $source->comment_status,
			'ping_status'    => $source->ping_status,
			'post_name'      => $slug,
		);
		Content::set_pending_language( $target['slug'] );
		try {
			$new_id = wp_insert_post( wp_slash( $postarr ), true, false );
		} finally {
			Content::set_pending_language( null );
		}
		if ( is_wp_error( $new_id ) ) {
			return new \WP_Error( 'cradle_languages_insert', __( 'The translation could not be created.', 'cradle-languages' ), array( 'status' => 500 ) );
		}
		$new_id = (int) $new_id;
		Content::set_language( $new_id, $target['slug'] );
		update_post_meta( $new_id, Content::GROUP_META, $group );
		Content::flush_cache();

		$thumbnail = (int) get_post_thumbnail_id( $source );
		if ( $thumbnail && 'attachment' === get_post_type( $thumbnail ) ) {
			set_post_thumbnail( $new_id, $thumbnail );
			$report['featuredImage'] = $thumbnail;
		}
		self::copy_terms( $source, $new_id, $target['slug'], $report );
		self::copy_meta( $source, $new_id, $report );
		clean_post_cache( $new_id );
		wp_after_insert_post( $new_id, false, null );

		cradle_log_activity(
			Module::ID,
			'translation_created',
			sprintf( '%1$s #%2$d translated to %3$s as draft #%4$d.', $source->post_type, $source->ID, $target['slug'], $new_id ),
			array(
				'source' => (int) $source->ID,
				'post'   => $new_id,
				'lang'   => $target['slug'],
			)
		);

		/**
		 * Fires after a draft translation was created and linked.
		 *
		 * @param int      $new_id New draft ID.
		 * @param \WP_Post $source Source entry.
		 * @param string   $lang   Target language slug.
		 * @param array    $report What was copied and skipped.
		 */
		do_action( 'cradle_languages/translation_created', $new_id, $source, $target['slug'], $report );

		$link = get_edit_post_link( $new_id, 'raw' );
		return array(
			'id'        => $new_id,
			'edit_link' => $link ? $link : admin_url( 'post.php?post=' . $new_id . '&action=edit' ),
			'report'    => $report,
		);
	}

	private static function copy_terms( \WP_Post $source, int $new_id, string $slug, array &$report ) {
		foreach ( get_object_taxonomies( $source->post_type, 'objects' ) as $name => $taxonomy ) {
			if ( Content::TAXONOMY === $name || ! current_user_can( $taxonomy->cap->assign_terms ) ) {
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
			if ( is_wp_error( $ids ) || ! $ids ) {
				continue;
			}
			$out = array();
			foreach ( array_map( 'intval', $ids ) as $id ) {
				if ( Content::is_translatable_taxonomy( $name ) ) {
					$translated = Content::term_translation( $id, $slug );
					if ( ! $translated ) {
						$report['termsSkipped'][ $name ][] = $id;
						continue;
					}
					$out[] = $translated;
				} else {
					$out[] = $id;
				}
			}
			if ( $out ) {
				wp_set_object_terms( $new_id, $out, $name );
				$report['terms'][ $name ] = count( $out );
			}
		}
	}

	private static function copy_meta( \WP_Post $source, int $new_id, array &$report ) {
		$all = get_post_meta( $source->ID );
		foreach ( array_keys( is_array( $all ) ? $all : array() ) as $key ) {
			$key = (string) $key;
			if ( ! self::copyable( $key ) ) {
				$report['metaSkipped'][] = $key;
				continue;
			}
			if ( metadata_exists( 'post', $new_id, $key ) ) {
				delete_post_meta( $new_id, $key ); // A save hook set a default; the source value wins.
			}
			foreach ( get_post_meta( $source->ID, $key, false ) as $value ) {
				add_post_meta( $new_id, $key, self::slash_deep( $value ) );
			}
			$report['metaCopied'][] = $key;
		}
	}

	public static function copyable( string $key ): bool {
		foreach ( self::NEVER as $pattern ) {
			if ( self::matches( $key, $pattern ) ) {
				return false;
			}
		}
		foreach ( preg_split( '/[_\-.:]+/', strtolower( $key ) ) as $segment ) {
			if ( '' !== $segment && in_array( $segment, self::DENY_SEGMENTS, true ) ) {
				return false;
			}
		}
		if ( is_protected_meta( $key, 'post' ) ) {
			foreach ( self::COPY_PROTECTED as $pattern ) {
				if ( self::matches( $key, $pattern ) ) {
					return true;
				}
			}
			/**
			 * Filters whether a protected (underscore) meta key is copied into translations.
			 *
			 * @param bool   $copy Default false.
			 * @param string $key  Meta key.
			 */
			return (bool) apply_filters( 'cradle_languages/copy_protected_meta', false, $key );
		}
		return true;
	}

	private static function matches( string $key, string $pattern ): bool {
		return str_ends_with( $pattern, '*' ) ? str_starts_with( $key, substr( $pattern, 0, -1 ) ) : $key === $pattern;
	}

	private static function slash_deep( $value ) {
		if ( is_object( $value ) ) {
			$value = clone $value;
		}
		return map_deep(
			$value,
			static fn( $item ) => is_string( $item ) ? addslashes( $item ) : $item
		);
	}
}
