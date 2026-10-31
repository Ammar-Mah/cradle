<?php
/**
 * wp-admin lists and term screens:
 * - Posts lists of translatable types: "Languages" column (the entry's language and links to its
 *   translations) and a language filter (default: all languages; `cradle_lang` query var).
 * - Term screens of translatable taxonomies: language select and translation links on the add and
 *   edit forms (nonce `cradle_languages_term`, capability edit_term / the taxonomy's manage_terms),
 *   plus a language column.
 *
 * @package Cradle\Languages
 */

namespace Cradle\Languages\Admin;

use Cradle\Languages\Content;
use Cradle\Languages\Languages;
use Cradle\Languages\Query;

defined( 'ABSPATH' ) || exit;

final class Lists {

	const TERM_NONCE = 'cradle_languages_term';

	public static function hooks() {
		add_action( 'load-edit.php', array( __CLASS__, 'load_posts_list' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_list' ) );
		add_action( 'load-edit-tags.php', array( __CLASS__, 'load_terms' ) );
		add_action( 'load-term.php', array( __CLASS__, 'load_terms' ) );
		add_action( 'saved_term', array( __CLASS__, 'save_term' ), 20, 3 );
	}

	private static function screen_type(): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen ? (string) $screen->post_type : '';
	}

	public static function load_posts_list() {
		$type = self::screen_type();
		if ( '' === $type ) {
			$type = 'post';
		}
		if ( ! Content::is_translatable_post_type( $type ) || ! Languages::is_multilingual() ) {
			return;
		}
		add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'dropdown' ), 10, 1 );
	}

	public static function columns( $columns ) {
		$out = array();
		foreach ( (array) $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['cradle_languages'] = __( 'Languages', 'cradle-languages' );
			}
		}
		if ( ! isset( $out['cradle_languages'] ) ) {
			$out['cradle_languages'] = __( 'Languages', 'cradle-languages' );
		}
		return $out;
	}

	public static function column( $column, $post_id ) {
		if ( 'cradle_languages' !== $column ) {
			return;
		}
		$own          = Content::post_language( (int) $post_id );
		$translations = Content::translations( (int) $post_id );
		if ( ! $own ) {
			echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Language not configured', 'cradle-languages' ) . '</span>';
			return;
		}
		echo '<strong title="' . esc_attr( $own['name'] ) . '">' . esc_html( strtoupper( $own['slug'] ) ) . '</strong>';
		foreach ( $translations as $slug => $id ) {
			if ( $id === (int) $post_id ) {
				continue;
			}
			$lang = Languages::get( $slug );
			$link = current_user_can( 'edit_post', $id ) ? get_edit_post_link( $id ) : '';
			/* translators: 1: language name, 2: entry title */
			$label = sprintf( __( '%1$s translation: %2$s', 'cradle-languages' ), $lang['name'], wp_strip_all_tags( get_the_title( $id ) ) );
			echo ' ';
			if ( $link ) {
				echo '<a href="' . esc_url( $link ) . '" title="' . esc_attr( $label ) . '"><span aria-hidden="true">' . esc_html( strtoupper( $slug ) ) . '</span><span class="screen-reader-text">' . esc_html( $label ) . '</span></a>';
			} else {
				echo '<span title="' . esc_attr( $label ) . '">' . esc_html( strtoupper( $slug ) ) . '</span>';
			}
		}
	}

	/**
	 * @param string $post_type Current post type.
	 */
	public static function dropdown( $post_type ) {
		if ( ! Content::is_translatable_post_type( (string) $post_type ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter (read-only).
		$current = isset( $_GET[ Query::VAR ] ) ? sanitize_key( wp_unslash( $_GET[ Query::VAR ] ) ) : '';
		echo '<label class="screen-reader-text" for="cradle-languages-filter">' . esc_html__( 'Filter by language', 'cradle-languages' ) . '</label>';
		echo '<select name="' . esc_attr( Query::VAR ) . '" id="cradle-languages-filter"><option value="">' . esc_html__( 'All languages', 'cradle-languages' ) . '</option>';
		foreach ( Languages::all() as $slug => $lang ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $current, $slug, false ) . '>' . esc_html( $lang['name'] ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Main wp-admin list query: the selected language (read-only GET filter).
	 */
	public static function filter_list( \WP_Query $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || ! isset( $GLOBALS['pagenow'] ) || ! in_array( $GLOBALS['pagenow'], array( 'edit.php', 'upload.php' ), true ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter (read-only).
		$slug = isset( $_GET[ Query::VAR ] ) ? sanitize_key( wp_unslash( $_GET[ Query::VAR ] ) ) : '';
		if ( Languages::exists( $slug ) ) {
			$query->set( Query::VAR, $slug );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Terms                                                               */
	/* ------------------------------------------------------------------ */

	public static function load_terms() {
		$screen   = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$taxonomy = $screen ? (string) $screen->taxonomy : '';
		if ( ! Content::is_translatable_taxonomy( $taxonomy ) || ! Languages::is_multilingual() ) {
			return;
		}
		add_action( "{$taxonomy}_add_form_fields", array( __CLASS__, 'add_fields' ) );
		add_action( "{$taxonomy}_edit_form_fields", array( __CLASS__, 'edit_fields' ), 10, 2 );
		add_filter( "manage_edit-{$taxonomy}_columns", array( __CLASS__, 'term_columns' ) );
		add_filter( "manage_{$taxonomy}_custom_column", array( __CLASS__, 'term_column' ), 10, 3 );
	}

	/**
	 * @param string $taxonomy Taxonomy.
	 */
	public static function add_fields( $taxonomy ) {
		echo '<div class="form-field">';
		self::language_select( (string) $taxonomy, Languages::default_slug(), 'cradle-languages-term-lang' );
		echo '</div>';
	}

	/**
	 * @param \WP_Term $term     Term.
	 * @param string   $taxonomy Taxonomy.
	 */
	public static function edit_fields( $term, $taxonomy ) {
		$lang = Content::term_language( $term );
		echo '<tr class="form-field"><th scope="row"><label for="cradle-languages-term-lang">' . esc_html__( 'Language', 'cradle-languages' ) . '</label></th><td>';
		self::language_select( (string) $taxonomy, $lang ? $lang['slug'] : Languages::default_slug(), 'cradle-languages-term-lang', false );
		echo '</td></tr>';
		$translations = Content::term_translations( (int) $term->term_id );
		foreach ( Languages::all() as $slug => $row ) {
			if ( $lang && $slug === $lang['slug'] ) {
				continue;
			}
			$options = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 200,
					Query::VAR   => $slug,
					'exclude'    => array( (int) $term->term_id ),
				)
			);
			$id = 'cradle-languages-term-tr-' . $slug;
			/* translators: %s: language name */
			echo '<tr class="form-field"><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( sprintf( __( '%s translation', 'cradle-languages' ), $row['name'] ) ) . '</label></th><td>';
			echo '<select id="' . esc_attr( $id ) . '" name="cradle_languages_term[translations][' . esc_attr( $slug ) . ']"><option value="0">' . esc_html__( '— None —', 'cradle-languages' ) . '</option>';
			foreach ( is_array( $options ) ? $options : array() as $option ) {
				echo '<option value="' . (int) $option->term_id . '"' . selected( (int) ( $translations[ $slug ] ?? 0 ), (int) $option->term_id, false ) . '>' . esc_html( $option->name ) . '</option>';
			}
			echo '</select></td></tr>';
		}
	}

	private static function language_select( string $taxonomy, string $selected, string $id, bool $label = true ) {
		unset( $taxonomy );
		wp_nonce_field( self::TERM_NONCE, 'cradle_languages_term_nonce' );
		if ( $label ) {
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html__( 'Language', 'cradle-languages' ) . '</label>';
		}
		echo '<select id="' . esc_attr( $id ) . '" name="cradle_languages_term[lang]">';
		foreach ( Languages::all() as $slug => $lang ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $selected, $slug, false ) . '>' . esc_html( $lang['name'] ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * `saved_term`: language and translations from the term forms (nonce + edit_term).
	 */
	public static function save_term( $term_id, $tt_id, $taxonomy ) {
		unset( $tt_id );
		if ( ! isset( $_POST['cradle_languages_term_nonce'], $_POST['cradle_languages_term'] ) || ! Content::is_translatable_taxonomy( (string) $taxonomy ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['cradle_languages_term_nonce'] ) ), self::TERM_NONCE ) || ! current_user_can( 'edit_term', (int) $term_id ) ) {
			return;
		}
		$data = wp_unslash( $_POST['cradle_languages_term'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
		$data = is_array( $data ) ? $data : array();
		$slug = is_string( $data['lang'] ?? null ) ? sanitize_key( $data['lang'] ) : '';
		if ( Languages::exists( $slug ) ) {
			Content::set_term_language( (int) $term_id, $slug );
		}
		if ( ! isset( $data['translations'] ) || ! is_array( $data['translations'] ) ) {
			return;
		}
		$own = Content::term_language( (int) $term_id );
		$map = array( $own['slug'] => (int) $term_id );
		foreach ( $data['translations'] as $lang => $id ) {
			$lang = sanitize_key( (string) $lang );
			$id   = (int) $id;
			if ( $id > 0 && Languages::exists( $lang ) && $lang !== $own['slug'] && current_user_can( 'edit_term', $id ) ) {
				$term = get_term( $id );
				if ( $term instanceof \WP_Term && $term->taxonomy === $taxonomy ) {
					$map[ $lang ] = $id;
				}
			}
		}
		if ( count( $map ) > 1 ) {
			Content::link_term_translations( $map );
			cradle_log_activity( 'languages', 'term_translations_linked', sprintf( 'Translations linked for term #%d.', (int) $term_id ), array( 'terms' => array_values( $map ) ) );
		} elseif ( '' !== (string) get_term_meta( (int) $term_id, Content::TERM_GROUP_META, true ) ) {
			delete_term_meta( (int) $term_id, Content::TERM_GROUP_META );
		}
	}

	public static function term_columns( $columns ) {
		$columns                     = (array) $columns;
		$columns['cradle_languages'] = __( 'Language', 'cradle-languages' );
		return $columns;
	}

	public static function term_column( $content, $column, $term_id ) {
		if ( 'cradle_languages' !== $column ) {
			return $content;
		}
		$lang = Content::term_language( (int) $term_id );
		return $lang ? '<span title="' . esc_attr( $lang['name'] ) . '">' . esc_html( strtoupper( $lang['slug'] ) ) . '</span>' : '—';
	}
}
