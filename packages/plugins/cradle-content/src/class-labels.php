<?php
/**
 * Generates the full WordPress label sets from the singular, plural and menu labels of a definition.
 *
 * The labels an administrator typed are site data, but they are shown in the admin like interface
 * text, so they go through `cradle/translate_label` (domain `cradle-content`) and are registered with
 * Cradle Translations when it is active (register()); without it the filter returns them unchanged.
 *
 * @package Cradle\Content
 */

namespace Cradle\Content;

defined( 'ABSPATH' ) || exit;

final class Labels {

	const CONTEXT_POST_TYPE   = 'post type label';
	const CONTEXT_TAXONOMY    = 'taxonomy label';
	const CONTEXT_FIELD_GROUP = 'field group title';
	const CONTEXT_FIELD       = 'field label';

	/**
	 * Translation of an administrator-defined label (override → shipped translation → original).
	 */
	public static function t( string $text, string $context ): string {
		if ( '' === $text ) {
			return $text;
		}
		$translated = apply_filters( 'cradle/translate_label', $text, $context, 'cradle-content' );
		return is_string( $translated ) && '' !== $translated ? $translated : $text;
	}

	/**
	 * Registers every definition label with Cradle Translations (`cradle/translations/register_labels`).
	 *
	 * @param object $labels Cradle Translations label registry.
	 */
	public static function register( $labels ) {
		$defs = Definitions::instance();
		foreach ( array( 'post_type' => self::CONTEXT_POST_TYPE, 'taxonomy' => self::CONTEXT_TAXONOMY ) as $kind => $context ) {
			foreach ( $defs->effective( $kind ) as $key => $def ) {
				foreach ( array( 'singular', 'plural', 'menu_name' ) as $field ) {
					if ( '' !== (string) ( $def[ $field ] ?? '' ) ) {
						$labels->add( 'cradle-content', (string) $def[ $field ], $context, str_replace( '_', '-', $kind ) . '/' . $key );
					}
				}
			}
		}
		foreach ( $defs->effective( 'field_group' ) as $key => $group ) {
			$labels->add( 'cradle-content', (string) $group['title'], self::CONTEXT_FIELD_GROUP, 'field-group/' . $key );
			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				if ( '' !== (string) ( $field['label'] ?? '' ) ) {
					$labels->add( 'cradle-content', (string) $field['label'], self::CONTEXT_FIELD, 'field-group/' . $key );
				}
			}
		}
	}

	public static function post_type( array $def ): array {
		$s = self::t( $def['singular'], self::CONTEXT_POST_TYPE );
		$p = self::t( $def['plural'], self::CONTEXT_POST_TYPE );
		$def['menu_name'] = self::t( (string) $def['menu_name'], self::CONTEXT_POST_TYPE );
		/* phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment -- %s is always the singular or plural label. */
		return array(
			'name'                     => $p,
			'singular_name'            => $s,
			'menu_name'                => '' !== $def['menu_name'] ? $def['menu_name'] : $p,
			'name_admin_bar'           => $s,
			'add_new'                  => __( 'Add New', 'cradle-content' ),
			'add_new_item'             => sprintf( __( 'Add New %s', 'cradle-content' ), $s ),
			'new_item'                 => sprintf( __( 'New %s', 'cradle-content' ), $s ),
			'edit_item'                => sprintf( __( 'Edit %s', 'cradle-content' ), $s ),
			'view_item'                => sprintf( __( 'View %s', 'cradle-content' ), $s ),
			'view_items'               => sprintf( __( 'View %s', 'cradle-content' ), $p ),
			'all_items'                => sprintf( __( 'All %s', 'cradle-content' ), $p ),
			'search_items'             => sprintf( __( 'Search %s', 'cradle-content' ), $p ),
			'parent_item_colon'        => sprintf( __( 'Parent %s:', 'cradle-content' ), $s ),
			'not_found'                => sprintf( __( 'No %s found.', 'cradle-content' ), $p ),
			'not_found_in_trash'       => sprintf( __( 'No %s found in Trash.', 'cradle-content' ), $p ),
			'archives'                 => sprintf( __( '%s archives', 'cradle-content' ), $s ),
			'attributes'               => sprintf( __( '%s attributes', 'cradle-content' ), $s ),
			'insert_into_item'         => sprintf( __( 'Insert into %s', 'cradle-content' ), $s ),
			'uploaded_to_this_item'    => sprintf( __( 'Uploaded to this %s', 'cradle-content' ), $s ),
			'filter_items_list'        => sprintf( __( 'Filter %s list', 'cradle-content' ), $p ),
			'items_list_navigation'    => sprintf( __( '%s list navigation', 'cradle-content' ), $p ),
			'items_list'               => sprintf( __( '%s list', 'cradle-content' ), $p ),
			'item_published'           => sprintf( __( '%s published.', 'cradle-content' ), $s ),
			'item_published_privately' => sprintf( __( '%s published privately.', 'cradle-content' ), $s ),
			'item_reverted_to_draft'   => sprintf( __( '%s reverted to draft.', 'cradle-content' ), $s ),
			'item_scheduled'           => sprintf( __( '%s scheduled.', 'cradle-content' ), $s ),
			'item_updated'             => sprintf( __( '%s updated.', 'cradle-content' ), $s ),
			'item_link'                => sprintf( __( '%s link', 'cradle-content' ), $s ),
		);
		/* phpcs:enable */
	}

	public static function taxonomy( array $def ): array {
		$s                = self::t( $def['singular'], self::CONTEXT_TAXONOMY );
		$p                = self::t( $def['plural'], self::CONTEXT_TAXONOMY );
		$def['menu_name'] = self::t( (string) $def['menu_name'], self::CONTEXT_TAXONOMY );
		/* phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment -- %s is always the singular or plural label. */
		return array(
			'name'                       => $p,
			'singular_name'              => $s,
			'menu_name'                  => '' !== $def['menu_name'] ? $def['menu_name'] : $p,
			'all_items'                  => sprintf( __( 'All %s', 'cradle-content' ), $p ),
			'edit_item'                  => sprintf( __( 'Edit %s', 'cradle-content' ), $s ),
			'view_item'                  => sprintf( __( 'View %s', 'cradle-content' ), $s ),
			'update_item'                => sprintf( __( 'Update %s', 'cradle-content' ), $s ),
			'add_new_item'               => sprintf( __( 'Add New %s', 'cradle-content' ), $s ),
			'new_item_name'              => sprintf( __( 'New %s name', 'cradle-content' ), $s ),
			'parent_item'                => sprintf( __( 'Parent %s', 'cradle-content' ), $s ),
			'parent_item_colon'          => sprintf( __( 'Parent %s:', 'cradle-content' ), $s ),
			'search_items'               => sprintf( __( 'Search %s', 'cradle-content' ), $p ),
			'popular_items'              => sprintf( __( 'Popular %s', 'cradle-content' ), $p ),
			'separate_items_with_commas' => sprintf( __( 'Separate %s with commas', 'cradle-content' ), $p ),
			'add_or_remove_items'        => sprintf( __( 'Add or remove %s', 'cradle-content' ), $p ),
			'choose_from_most_used'      => sprintf( __( 'Choose from the most used %s', 'cradle-content' ), $p ),
			'not_found'                  => sprintf( __( 'No %s found.', 'cradle-content' ), $p ),
			'no_terms'                   => sprintf( __( 'No %s', 'cradle-content' ), $p ),
			'items_list_navigation'      => sprintf( __( '%s list navigation', 'cradle-content' ), $p ),
			'items_list'                 => sprintf( __( '%s list', 'cradle-content' ), $p ),
			'back_to_items'              => sprintf( __( '&larr; Go to %s', 'cradle-content' ), $p ),
			'item_link'                  => sprintf( __( '%s link', 'cradle-content' ), $s ),
		);
		/* phpcs:enable */
	}
}
