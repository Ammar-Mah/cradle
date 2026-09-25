<?php
/**
 * Label override registry: post type and taxonomy labels, admin menu items and
 * exact strings of supported text domains. No output buffering and no global
 * string replacement over admin HTML: each override uses the WordPress API for
 * that label (label filters/objects, the admin menu arrays, gettext filters).
 *
 * @package Cradle\Admin
 */

namespace Cradle\Admin;

defined( 'ABSPATH' ) || exit;

final class Labels {

	/** @var array<string,array> Original labels of patched entities, keyed "post_type:slug" / "taxonomy:slug". */
	private static $entity_originals = array();

	/** @var array<string,array{label:string,children:array<string,string>}> Captured admin menu before overrides. */
	private static $menu_originals = array();

	/** @var array|null Lookup map domain → context → original → [replacement, screen]. */
	private static $text_map = null;

	/** @var array|null Effective overrides for this request (flushed when the option changes). */
	private static $overrides = null;

	public static function hooks() {
		add_action( 'init', array( self::class, 'apply_registered_entities' ), 999 );
		add_action( 'registered_post_type', array( self::class, 'on_registered_post_type' ), 10, 2 );
		add_action( 'registered_taxonomy', array( self::class, 'on_registered_taxonomy' ), 10, 3 );
		add_action( 'admin_menu', array( self::class, 'apply_menu_labels' ), 9999 );
		if ( is_admin() ) {
			add_filter( 'gettext', array( self::class, 'gettext' ), 10, 3 );
			add_filter( 'gettext_with_context', array( self::class, 'gettext_with_context' ), 10, 4 );
		}
		foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $prefix ) {
			add_action( $prefix . 'cradle_admin_labels_settings', array( self::class, 'flush' ) );
		}
		// Entities registered before this module booted (core post types, early plugins).
		self::apply_registered_entities();
	}

	/**
	 * Effective overrides: stored settings plus code-registered overrides from
	 * `cradle_admin/label_overrides`, validated with the same rules.
	 */
	public static function overrides(): array {
		if ( null === self::$overrides ) {
			self::$overrides = array(); // Re-entrancy guard (validators translate strings).
			self::$overrides = self::build_overrides();
		}
		return self::$overrides;
	}

	private static function build_overrides(): array {
		$values = Module::values( Module::SECTION_LABELS );
		$out    = array(
			'post_type_labels' => is_array( $values['post_type_labels'] ?? null ) ? $values['post_type_labels'] : array(),
			'taxonomy_labels'  => is_array( $values['taxonomy_labels'] ?? null ) ? $values['taxonomy_labels'] : array(),
			'menu_labels'      => is_array( $values['menu_labels'] ?? null ) ? $values['menu_labels'] : array(),
			'text_overrides'   => is_array( $values['text_overrides'] ?? null ) ? $values['text_overrides'] : array(),
		);
		if ( has_filter( 'cradle_admin/label_overrides' ) ) {
			/**
			 * Filters label overrides registered in code (a child theme or another plugin). Values are validated
			 * with the same rules as the settings screen; invalid additions are ignored.
			 *
			 * @param array $overrides Keys post_type_labels, taxonomy_labels, menu_labels, text_overrides.
			 */
			$filtered = apply_filters( 'cradle_admin/label_overrides', $out );
			if ( is_array( $filtered ) ) {
				foreach ( array( 'post_type_labels', 'taxonomy_labels', 'menu_labels', 'text_overrides' ) as $key ) {
					if ( ! array_key_exists( $key, $filtered ) || $filtered[ $key ] === $out[ $key ] ) {
						continue;
					}
					$method = 'validate_' . $key;
					$valid  = Sections::$method( $filtered[ $key ] );
					if ( ! is_wp_error( $valid ) ) {
						$out[ $key ] = $valid;
					}
				}
			}
		}
		return $out;
	}

	/*
	 * ---------------------------- Entities ----------------------------
	 */

	public static function apply_registered_entities() {
		if ( Module::safe_mode() ) {
			self::restore_entities();
			return;
		}
		$overrides = self::overrides();
		foreach ( $overrides['post_type_labels'] as $type => $labels ) {
			$object = get_post_type_object( $type );
			if ( $object ) {
				self::patch_entity( 'post_type', $type, $object, $labels );
			}
		}
		foreach ( $overrides['taxonomy_labels'] as $tax => $labels ) {
			$object = get_taxonomy( $tax );
			if ( $object ) {
				self::patch_entity( 'taxonomy', $tax, $object, $labels );
			}
		}
	}

	/**
	 * @param string        $post_type Post type key.
	 * @param \WP_Post_Type $object    Registered object.
	 */
	public static function on_registered_post_type( $post_type, $object ) {
		if ( Module::safe_mode() || ! $object instanceof \WP_Post_Type ) {
			return;
		}
		$labels = self::overrides()['post_type_labels'][ $post_type ] ?? null;
		if ( $labels ) {
			unset( self::$entity_originals[ 'post_type:' . $post_type ] ); // Fresh registration: fresh originals.
			self::patch_entity( 'post_type', (string) $post_type, $object, $labels );
		}
	}

	/**
	 * @param string       $taxonomy    Taxonomy key.
	 * @param array|string $object_type Object types.
	 * @param array        $args        Registration arguments.
	 */
	public static function on_registered_taxonomy( $taxonomy, $object_type, $args ) {
		unset( $object_type, $args );
		if ( Module::safe_mode() ) {
			return;
		}
		$labels = self::overrides()['taxonomy_labels'][ $taxonomy ] ?? null;
		$object = get_taxonomy( $taxonomy );
		if ( $labels && $object ) {
			unset( self::$entity_originals[ 'taxonomy:' . $taxonomy ] );
			self::patch_entity( 'taxonomy', (string) $taxonomy, $object, $labels );
		}
	}

	/**
	 * Applies overrides to a registered object's labels. Dependent labels that
	 * WordPress derives from the plural name (menu_name) or singular name
	 * (name_admin_bar) follow when they were equal to their source originally.
	 */
	private static function patch_entity( string $kind, string $key, $object, array $labels ) {
		if ( ! isset( $object->labels ) || ! is_object( $object->labels ) ) {
			return;
		}
		$id = $kind . ':' . $key;
		if ( ! isset( self::$entity_originals[ $id ] ) ) {
			self::$entity_originals[ $id ] = array(
				'labels' => (array) $object->labels,
				'label'  => $object->label ?? '',
			);
		}
		$original = self::$entity_originals[ $id ]['labels'];
		$allowed  = Sections::entity_label_keys( $kind );
		// Start from originals so removed overrides are undone within the same request.
		foreach ( $original as $label_key => $label_value ) {
			$object->labels->$label_key = $label_value;
		}
		foreach ( $labels as $label_key => $value ) {
			if ( isset( $allowed[ $label_key ] ) && is_string( $value ) && '' !== $value ) {
				$object->labels->$label_key = $value;
			}
		}
		if ( isset( $labels['name'] ) && ! isset( $labels['menu_name'] ) && ( $original['menu_name'] ?? null ) === ( $original['name'] ?? null ) ) {
			$object->labels->menu_name = $labels['name'];
		}
		if ( 'post_type' === $kind && isset( $labels['singular_name'] ) && ! isset( $labels['name_admin_bar'] ) && ( $original['name_admin_bar'] ?? null ) === ( $original['singular_name'] ?? null ) ) {
			$object->labels->name_admin_bar = $labels['singular_name'];
		}
		$object->label = $object->labels->name;
	}

	/**
	 * Undoes entity patches (safe mode switched on during a request, or tests).
	 */
	public static function restore_entities() {
		foreach ( self::$entity_originals as $id => $original ) {
			list( $kind, $key ) = explode( ':', $id, 2 );
			$object             = 'taxonomy' === $kind ? get_taxonomy( $key ) : get_post_type_object( $key );
			if ( $object && isset( $object->labels ) && is_object( $object->labels ) ) {
				foreach ( $original['labels'] as $label_key => $label_value ) {
					$object->labels->$label_key = $label_value;
				}
				$object->label = $original['label'];
			}
		}
		self::$entity_originals = array();
	}

	/**
	 * Registered (pre-override) labels of an entity, for placeholders on the settings screen.
	 */
	public static function original_labels( string $kind, string $key ): array {
		$id = $kind . ':' . $key;
		if ( isset( self::$entity_originals[ $id ] ) ) {
			return self::$entity_originals[ $id ]['labels'];
		}
		$object = 'taxonomy' === $kind ? get_taxonomy( $key ) : get_post_type_object( $key );
		return $object && isset( $object->labels ) ? (array) $object->labels : array();
	}

	/*
	 * ---------------------------- Admin menu ----------------------------
	 */

	/**
	 * Runs late on admin_menu: captures the original menu, then renames selected items.
	 * Count bubbles (updates, comments) that follow the text are preserved.
	 */
	public static function apply_menu_labels() {
		global $menu, $submenu;
		self::$menu_originals = array();
		$ordered              = (array) $menu;
		uksort(
			$ordered,
			static function ( $a, $b ) {
				return (float) $a <=> (float) $b;
			}
		);
		foreach ( $ordered as $item ) {
			if ( empty( $item[2] ) || ( isset( $item[4] ) && false !== strpos( (string) $item[4], 'wp-menu-separator' ) ) ) {
				continue;
			}
			self::$menu_originals[ (string) $item[2] ] = array(
				'label'    => self::plain_label( (string) $item[0] ),
				'children' => array(),
			);
		}
		foreach ( (array) $submenu as $parent => $items ) {
			if ( ! isset( self::$menu_originals[ $parent ] ) ) {
				continue;
			}
			foreach ( (array) $items as $item ) {
				if ( ! empty( $item[2] ) ) {
					self::$menu_originals[ $parent ]['children'][ (string) $item[2] ] = self::plain_label( (string) $item[0] );
				}
			}
		}
		if ( Module::safe_mode() ) {
			return;
		}
		$overrides = self::overrides()['menu_labels'];
		if ( ! $overrides ) {
			return;
		}
		foreach ( (array) $menu as $position => $item ) {
			$slug = (string) ( $item[2] ?? '' );
			if ( '' !== $slug && isset( $overrides[ $slug ] ) ) {
				$menu[ $position ][0] = self::replace_label( (string) $item[0], $overrides[ $slug ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- documented admin menu API.
			}
		}
		foreach ( (array) $submenu as $parent => $items ) {
			foreach ( (array) $items as $index => $item ) {
				$key = $parent . '|' . ( $item[2] ?? '' );
				if ( isset( $overrides[ $key ] ) ) {
					$submenu[ $parent ][ $index ][0] = self::replace_label( (string) $item[0], $overrides[ $key ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				}
			}
		}
	}

	/**
	 * New escaped label plus the trailing markup of the original (count bubbles).
	 */
	public static function replace_label( string $original_html, string $label ): string {
		$pos = strpos( $original_html, '<' );
		if ( false === $pos || 0 === $pos ) {
			return esc_html( $label );
		}
		return esc_html( $label ) . ' ' . ltrim( substr( $original_html, $pos ) );
	}

	public static function plain_label( string $html ): string {
		$text = preg_replace( '/<span\b[^>]*>.*<\/span>/s', '', $html );
		return trim( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) );
	}

	public static function menu_originals(): array {
		return self::$menu_originals;
	}

	public static function menu_key_present( string $key ): bool {
		if ( false !== strpos( $key, '|' ) ) {
			list( $parent, $child ) = explode( '|', $key, 2 );
			return isset( self::$menu_originals[ $parent ]['children'][ $child ] );
		}
		return isset( self::$menu_originals[ $key ] );
	}

	/*
	 * ---------------------------- Text domains ----------------------------
	 */

	private static function text_map(): array {
		if ( null === self::$text_map ) {
			// Guard first: building the map may itself translate strings (validation messages).
			self::$text_map = array();
			$map            = array();
			foreach ( self::overrides()['text_overrides'] as $row ) {
				$map[ $row['domain'] ][ $row['context'] ][ $row['original'] ] = array( $row['replacement'], $row['screen'] );
			}
			self::$text_map = $map;
		}
		return self::$text_map;
	}

	/**
	 * Forgets the cached lookup map (after settings change within a request).
	 */
	public static function flush() {
		self::$text_map  = null;
		self::$overrides = null;
	}

	/**
	 * @param string $translation Translated text.
	 * @param string $text        Original text.
	 * @param string $domain      Text domain.
	 */
	public static function gettext( $translation, $text, $domain ) {
		return self::lookup( $translation, (string) $text, '', (string) $domain );
	}

	/**
	 * @param string $translation Translated text.
	 * @param string $text        Original text.
	 * @param string $context     Context.
	 * @param string $domain      Text domain.
	 */
	public static function gettext_with_context( $translation, $text, $context, $domain ) {
		return self::lookup( $translation, (string) $text, (string) $context, (string) $domain );
	}

	private static function lookup( $translation, string $text, string $context, string $domain ) {
		$map = self::text_map();
		if ( ! isset( $map[ $domain ][ $context ][ $text ] ) ) {
			return $translation;
		}
		list( $replacement, $screen_id ) = $map[ $domain ][ $context ][ $text ];
		if ( '' !== $screen_id ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || $screen->id !== $screen_id ) {
				return $translation;
			}
		}
		return Module::safe_mode() ? $translation : $replacement;
	}
}
