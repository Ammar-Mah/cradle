<?php
/**
 * Accessible renderers for the structured (object) settings fields. Called by
 * core's Field_Renderer with ( $name, $id, $value, $field, $describedby ).
 * Group fields declare 'control' => 'radio' so core renders a group label
 * (span#<id>-label) that the fieldsets below reference.
 *
 * @package Cradle\Admin
 */

namespace Cradle\Admin\Admin;

use Cradle\Admin\Labels;
use Cradle\Admin\Sections;

defined( 'ABSPATH' ) || exit;

final class Fields {

	private static function aria( string $describedby ): string {
		return '' !== $describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : '';
	}

	/**
	 * Support/help links: five label + URL rows.
	 */
	public static function links( $name, $id, $value, $field, $describedby ) {
		unset( $field );
		$rows = is_array( $value ) ? array_values( $value ) : array();
		echo '<fieldset class="cradle-admin-rows" aria-labelledby="' . esc_attr( $id ) . '-label"' . self::aria( (string) $describedby ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<table class="cradle-admin-grid" role="presentation"><tbody>';
		for ( $i = 0; $i < 5; $i++ ) {
			$label = (string) ( $rows[ $i ]['label'] ?? '' );
			$url   = (string) ( $rows[ $i ]['url'] ?? '' );
			$base  = $id . '-' . $i;
			echo '<tr>';
			printf(
				'<td><label for="%1$s-label">%2$s</label><br /><input type="text" id="%1$s-label" name="%3$s[%4$d][label]" value="%5$s" maxlength="60" class="regular-text" /></td>',
				esc_attr( $base ),
				/* translators: %d: link number */
				esc_html( sprintf( __( 'Link %d label', 'cradle-admin' ), $i + 1 ) ),
				esc_attr( $name ),
				(int) $i,
				esc_attr( $label )
			);
			printf(
				'<td><label for="%1$s-url">%2$s</label><br /><input type="url" id="%1$s-url" name="%3$s[%4$d][url]" value="%5$s" class="regular-text" placeholder="https://" /></td>',
				esc_attr( $base ),
				/* translators: %d: link number */
				esc_html( sprintf( __( 'Link %d URL', 'cradle-admin' ), $i + 1 ) ),
				esc_attr( $name ),
				(int) $i,
				esc_attr( $url )
			);
			echo '</tr>';
		}
		echo '</tbody></table></fieldset>';
	}

	/**
	 * Limited rich text textarea (the label element points at it).
	 */
	public static function rich_text( $name, $id, $value, $field, $describedby ) {
		unset( $field );
		printf(
			'<textarea id="%1$s" name="%2$s" rows="8" class="large-text"%3$s>%4$s</textarea>',
			esc_attr( $id ),
			esc_attr( $name ),
			self::aria( (string) $describedby ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_textarea( is_string( $value ) ? $value : '' )
		);
	}

	public static function post_type_labels( $name, $id, $value, $field, $describedby ) {
		unset( $field );
		$types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			if ( ! empty( $type->_builtin ) && 0 === strpos( $type->name, 'wp_' ) ) {
				continue; // Internal editor entities (patterns, navigation, templates).
			}
			$types[ $type->name ] = $type;
		}
		self::entity_table( 'post_type', $types, $name, $id, is_array( $value ) ? $value : array(), (string) $describedby );
	}

	public static function taxonomy_labels( $name, $id, $value, $field, $describedby ) {
		unset( $field );
		$taxes = array();
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $tax ) {
			if ( ! empty( $tax->_builtin ) && 0 === strpos( $tax->name, 'wp_' ) ) {
				continue;
			}
			$taxes[ $tax->name ] = $tax;
		}
		self::entity_table( 'taxonomy', $taxes, $name, $id, is_array( $value ) ? $value : array(), (string) $describedby );
	}

	private static function entity_table( string $kind, array $objects, string $name, string $id, array $value, string $describedby ) {
		$keys = Sections::entity_label_keys( $kind );
		echo '<fieldset class="cradle-admin-rows" aria-labelledby="' . esc_attr( $id ) . '-label"' . self::aria( $describedby ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! $objects ) {
			echo '<p>' . esc_html__( 'Nothing to relabel.', 'cradle-admin' ) . '</p>';
		}
		foreach ( $objects as $slug => $object ) {
			$original = Labels::original_labels( $kind, $slug );
			$current  = isset( $value[ $slug ] ) && is_array( $value[ $slug ] ) ? $value[ $slug ] : array();
			$count    = count( array_filter( $current ) );
			$summary  = sprintf( '%1$s (%2$s)', (string) ( $original['name'] ?? $slug ), $slug );
			if ( $count ) {
				/* translators: %d: number of overridden labels */
				$summary .= ' — ' . sprintf( _n( '%d override', '%d overrides', $count, 'cradle-admin' ), $count );
			}
			echo '<details class="cradle-admin-entity"' . ( $count ? ' open' : '' ) . '><summary>' . esc_html( $summary ) . '</summary><div class="cradle-admin-entity__fields">';
			foreach ( $keys as $key => $label ) {
				$input_id = $id . '-' . $slug . '-' . $key;
				printf(
					'<p><label for="%1$s">%2$s</label><br /><input type="text" id="%1$s" name="%3$s[%4$s][%5$s]" value="%6$s" placeholder="%7$s" maxlength="80" class="regular-text" /></p>',
					esc_attr( $input_id ),
					esc_html( $label ),
					esc_attr( $name ),
					esc_attr( $slug ),
					esc_attr( $key ),
					esc_attr( (string) ( $current[ $key ] ?? '' ) ),
					esc_attr( is_string( $original[ $key ] ?? null ) ? $original[ $key ] : '' )
				);
			}
			echo '</div></details>';
		}
		// Overrides for entities that are not registered right now are kept (and listed).
		$orphans = array_diff_key( $value, $objects );
		if ( $orphans ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: comma-separated list of keys */
					__( 'Kept for entities that are not registered right now: %s', 'cradle-admin' ),
					implode( ', ', array_map( 'strval', array_keys( $orphans ) ) )
				)
			) . '</p>';
		}
		echo '</fieldset>';
	}

	public static function menu_labels( $name, $id, $value, $field, $describedby ) {
		unset( $field );
		$value     = is_array( $value ) ? $value : array();
		$originals = Labels::menu_originals();
		echo '<fieldset class="cradle-admin-rows" aria-labelledby="' . esc_attr( $id ) . '-label"' . self::aria( (string) $describedby ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! $originals ) {
			echo '<p>' . esc_html__( 'The admin menu is not available in this context.', 'cradle-admin' ) . '</p></fieldset>';
			return;
		}
		$index = 0;
		foreach ( $originals as $slug => $item ) {
			if ( '' === $item['label'] ) {
				continue;
			}
			++$index;
			$count = isset( $value[ $slug ] ) ? 1 : 0;
			foreach ( array_keys( $item['children'] ) as $child ) {
				$count += isset( $value[ $slug . '|' . $child ] ) ? 1 : 0;
			}
			echo '<details class="cradle-admin-entity"' . ( $count ? ' open' : '' ) . '><summary>' . esc_html( $item['label'] ) . '</summary><div class="cradle-admin-entity__fields">';
			self::menu_input( $name, $id . '-m' . $index, $slug, __( 'Menu item', 'cradle-admin' ), $item['label'], $value );
			$sub = 0;
			foreach ( $item['children'] as $child => $child_label ) {
				++$sub;
				if ( '' === $child_label ) {
					continue;
				}
				/* translators: %s: submenu item label */
				self::menu_input( $name, $id . '-m' . $index . '-' . $sub, $slug . '|' . $child, sprintf( __( 'Submenu: %s', 'cradle-admin' ), $child_label ), $child_label, $value );
			}
			echo '</div></details>';
		}
		echo '</fieldset>';
	}

	private static function menu_input( string $name, string $input_id, string $key, string $label, string $placeholder, array $value ) {
		if ( ! Sections::menu_key_valid( $key ) ) {
			return; // Slugs with unsupported characters cannot be overridden.
		}
		printf(
			'<p><label for="%1$s">%2$s</label> <code>%3$s</code><br /><input type="text" id="%1$s" name="%4$s[%5$s]" value="%6$s" placeholder="%7$s" maxlength="60" class="regular-text" /></p>',
			esc_attr( $input_id ),
			esc_html( $label ),
			esc_html( $key ),
			esc_attr( $name ),
			esc_attr( $key ),
			esc_attr( (string) ( $value[ $key ] ?? '' ) ),
			esc_attr( $placeholder )
		);
	}

	public static function text_overrides( $name, $id, $value, $field, $describedby ) {
		unset( $field );
		$rows    = is_array( $value ) ? array_values( $value ) : array();
		$domains = Sections::text_domains();
		$total   = count( $rows ) + 3;
		echo '<fieldset class="cradle-admin-rows" aria-labelledby="' . esc_attr( $id ) . '-label"' . self::aria( (string) $describedby ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<table class="widefat cradle-admin-grid"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Text domain', 'cradle-admin' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Original text', 'cradle-admin' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Replacement', 'cradle-admin' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Context (optional)', 'cradle-admin' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Screen ID (optional)', 'cradle-admin' ) . '</th>';
		echo '</tr></thead><tbody>';
		for ( $i = 0; $i < $total; $i++ ) {
			$row  = $rows[ $i ] ?? array();
			$base = $id . '-' . $i;
			/* translators: %d: row number */
			$n = sprintf( __( 'row %d', 'cradle-admin' ), $i + 1 );
			echo '<tr><td>';
			printf( '<label class="screen-reader-text" for="%1$s-domain">%2$s</label><select id="%1$s-domain" name="%3$s[%4$d][domain]">', esc_attr( $base ), esc_html( __( 'Text domain', 'cradle-admin' ) . ', ' . $n ), esc_attr( $name ), (int) $i );
			foreach ( $domains as $domain ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $domain ), selected( (string) ( $row['domain'] ?? 'default' ), $domain, false ), esc_html( 'default' === $domain ? __( 'WordPress (default)', 'cradle-admin' ) : $domain ) );
			}
			echo '</select></td>';
			foreach ( array(
				'original'    => __( 'Original text', 'cradle-admin' ),
				'replacement' => __( 'Replacement', 'cradle-admin' ),
				'context'     => __( 'Context', 'cradle-admin' ),
				'screen'      => __( 'Screen ID', 'cradle-admin' ),
			) as $key => $label ) {
				printf(
					'<td><label class="screen-reader-text" for="%1$s-%2$s">%3$s</label><input type="text" id="%1$s-%2$s" name="%4$s[%5$d][%2$s]" value="%6$s" class="widefat" /></td>',
					esc_attr( $base ),
					esc_attr( $key ),
					esc_html( $label . ', ' . $n ),
					esc_attr( $name ),
					(int) $i,
					esc_attr( (string) ( $row[ $key ] ?? '' ) )
				);
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Empty rows are ignored. To remove an override, clear its original and replacement text. At most 50 overrides.', 'cradle-admin' ) . '</p>';
		echo '</fieldset>';
	}
}
