<?php
/**
 * Server-rendered value controls for fields in classic meta boxes and term forms.
 *
 * @package Cradle\Content
 */

namespace Cradle\Content\Admin;

use Cradle\Content\Field_Types;
use Cradle\Content\Rest;
use Cradle\Content\Settings_Section;

defined( 'ABSPATH' ) || exit;

final class Field_Controls {

	/** @var array<string,array> Per-request memo of reference candidates. */
	private static $candidates = array();

	/**
	 * Outputs the control for one field.
	 *
	 * @param array  $field    Compiled field (or sub-field).
	 * @param string $name     Input name.
	 * @param string $id       Element ID.
	 * @param mixed  $value    Current value.
	 * @param string $describe aria-describedby value.
	 */
	public static function control( array $field, string $name, string $id, $value, string $describe = '' ) {
		$aria     = $describe ? ' aria-describedby="' . esc_attr( $describe ) . '"' : '';
		$required = ! empty( $field['required'] ) ? ' aria-required="true"' : '';
		switch ( $field['type'] ) {
			case 'textarea':
			case 'rich_text':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="%3$d" class="large-text" maxlength="%4$d"%5$s%6$s>%7$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					'rich_text' === $field['type'] ? 6 : 4,
					(int) ( $field['max_length'] ?? 2000 ),
					$aria, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$required, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_textarea( is_scalar( $value ) ? (string) $value : '' )
				);
				if ( 'rich_text' === $field['type'] ) {
					echo '<span class="description">' . esc_html__( 'Allowed HTML: a, strong, em, b, i, br, p, ul, ol, li, code.', 'cradle-content' ) . '</span>';
				}
				return;

			case 'boolean':
				printf(
					'<input type="hidden" name="%1$s" value="0" /><input type="checkbox" id="%2$s" name="%1$s" value="1" %3$s%4$s />',
					esc_attr( $name ),
					esc_attr( $id ),
					checked( (bool) $value, true, false ),
					$aria // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				return;

			case 'select':
				printf( '<select id="%1$s" name="%2$s"%3$s%4$s><option value="">%5$s</option>', esc_attr( $id ), esc_attr( $name ), $aria, $required, esc_html__( '— None —', 'cradle-content' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				foreach ( (array) ( $field['options'] ?? array() ) as $option ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option['value'] ), selected( (string) $value, $option['value'], false ), esc_html( $option['label'] ) );
				}
				echo '</select>';
				return;

			case 'multiselect':
				$values = array_map( 'strval', (array) $value );
				printf( '<fieldset id="%1$s"%2$s><input type="hidden" name="%3$s[]" value="" />', esc_attr( $id ), $aria, esc_attr( $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				foreach ( (array) ( $field['options'] ?? array() ) as $option ) {
					printf( '<label class="cradle-content-choice"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>', esc_attr( $name ), esc_attr( $option['value'] ), checked( in_array( $option['value'], $values, true ), true, false ), esc_html( $option['label'] ) );
				}
				echo '</fieldset>';
				return;

			case 'attachment':
				$att_id  = (int) $value;
				$preview = $att_id ? wp_get_attachment_image( $att_id, 'thumbnail', true ) : '';
				?>
				<div class="cradle-media-field" data-cradle-media="<?php echo esc_attr( (string) wp_json_encode( array( 'type' => array_values( (array) ( $field['mime'] ?? array() ) ) ) ) ); ?>">
					<input type="hidden" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $att_id ); ?>" />
					<div class="cradle-media-preview" aria-live="polite"><?php echo $preview; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup. ?></div>
					<button type="button" class="button cradle-media-select"<?php echo $aria; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php esc_html_e( 'Select media', 'cradle-content' ); ?></button>
					<button type="button" class="button-link cradle-media-remove"<?php echo $att_id ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'cradle-content' ); ?></button>
				</div>
				<?php
				return;

			case 'post_ref':
			case 'user_ref':
			case 'term_ref':
				$multiple = ! empty( $field['multiple'] );
				$current  = array_values( array_filter( array_map( 'intval', (array) $value ) ) );
				$limit    = max( 20, min( 500, (int) Settings_Section::get( 'select_limit', 200 ) ) );
				$options  = array();
				$memo_key = md5( (string) wp_json_encode( array( $field['type'], $field['targets'] ?? array(), $limit ) ) );
				if ( ! isset( self::$candidates[ $memo_key ] ) ) {
					self::$candidates[ $memo_key ] = Rest::candidates( $field, '', array(), $limit ); // Shared by every row of a repeated group.
				}
				foreach ( array_merge( $current ? Rest::candidates( $field, '', $current, count( $current ) ) : array(), self::$candidates[ $memo_key ] ) as $candidate ) {
					$options[ $candidate['id'] ] = $candidate['label'] . ( '' !== $candidate['detail'] ? ' — ' . $candidate['detail'] : '' );
				}
				foreach ( $current as $current_id ) {
					if ( ! isset( $options[ $current_id ] ) ) {
						/* translators: %d: ID */
						$options[ $current_id ] = sprintf( __( 'Item #%d (not selectable by you)', 'cradle-content' ), $current_id );
					}
				}
				if ( $multiple ) {
					printf( '<input type="hidden" name="%1$s[]" value="" /><select id="%2$s" name="%1$s[]" multiple size="6" class="cradle-content-multi"%3$s>', esc_attr( $name ), esc_attr( $id ), $aria ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					printf( '<select id="%1$s" name="%2$s"%3$s%4$s><option value="">%5$s</option>', esc_attr( $id ), esc_attr( $name ), $aria, $required, esc_html__( '— None —', 'cradle-content' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				foreach ( $options as $option_id => $label ) {
					printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $option_id, selected( in_array( (int) $option_id, $current, true ), true, false ), esc_html( $label ) );
				}
				echo '</select>';
				if ( count( $options ) >= $limit ) {
					/* translators: %d: limit */
					echo '<span class="description">' . esc_html( sprintf( __( 'Showing the first %d choices.', 'cradle-content' ), $limit ) ) . '</span>';
				}
				return;

			case 'group':
				self::group( $field, $name, $id, is_array( $value ) ? $value : array() );
				return;
		}

		$types = array(
			'number' => 'number',
			'url'    => 'url',
			'email'  => 'email',
			'date'   => 'date',
		);
		$extra = '';
		if ( 'number' === $field['type'] ) {
			$extra .= null !== ( $field['min'] ?? null ) ? ' min="' . esc_attr( (string) $field['min'] ) . '"' : '';
			$extra .= null !== ( $field['max'] ?? null ) ? ' max="' . esc_attr( (string) $field['max'] ) . '"' : '';
			$extra .= ' step="' . esc_attr( null !== ( $field['step'] ?? null ) ? (string) $field['step'] : ( ! empty( $field['integer'] ) ? '1' : 'any' ) ) . '"';
		} elseif ( 'text' === $field['type'] ) {
			$extra .= ' maxlength="' . esc_attr( (string) (int) ( $field['max_length'] ?? 200 ) ) . '"';
		}
		printf(
			'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="%5$s"%6$s%7$s%8$s />',
			esc_attr( $types[ $field['type'] ] ?? 'text' ),
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( is_scalar( $value ) ? (string) $value : '' ),
			'number' === $field['type'] ? 'small-text' : 'regular-text',
			$extra, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			$aria, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$required // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Repeated group rows with a template for vanilla-JS row management.
	 */
	private static function group( array $field, string $name, string $id, array $rows ) {
		$max = (int) ( $field['max_rows'] ?? 10 );
		printf( '<div class="cradle-content-group" data-cradle-group data-max="%1$d" id="%2$s">', (int) $max, esc_attr( $id ) );
		echo '<ol class="cradle-content-group__rows" data-cradle-rows>';
		$index = 0;
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				self::group_row( $field, $name, $id, (string) $index++, $row );
			}
		}
		if ( ! $rows ) {
			self::group_row( $field, $name, $id, '0', array() ); // One empty row works without JavaScript; empty rows are dropped on save.
		}
		echo '</ol><template data-cradle-template>';
		self::group_row( $field, $name, $id, '__r__', array() );
		/* translators: %d: maximum rows */
		echo '</template><button type="button" class="button" data-cradle-add>' . esc_html__( 'Add row', 'cradle-content' ) . '</button> <span class="description">' . esc_html( sprintf( __( 'Up to %d rows.', 'cradle-content' ), $max ) ) . '</span>';
		echo '<p class="screen-reader-text" aria-live="polite" data-cradle-status></p></div>';
	}

	private static function group_row( array $field, string $name, string $id, string $r, array $row ) {
		echo '<li class="cradle-content-group__row" data-cradle-row><fieldset><legend>' . esc_html( $field['label'] ) . ' — <span data-cradle-row-number>' . esc_html( '__r__' === $r ? '' : (string) ( (int) $r + 1 ) ) . '</span></legend>';
		foreach ( (array) ( $field['subfields'] ?? array() ) as $sub_key => $sub ) {
			$sub_id = $id . '-' . $r . '-' . $sub_key;
			echo '<p class="cradle-content-group__field"><label for="' . esc_attr( $sub_id ) . '">' . esc_html( $sub['label'] ) . '</label><br />';
			self::control( $sub, $name . '[' . $r . '][' . $sub_key . ']', $sub_id, $row[ $sub_key ] ?? null );
			echo '</p>';
		}
		echo '<span class="cradle-content-row-tools"><button type="button" class="button-link" data-cradle-up>' . esc_html__( 'Move up', 'cradle-content' ) . '</button> <button type="button" class="button-link" data-cradle-down>' . esc_html__( 'Move down', 'cradle-content' ) . '</button> <button type="button" class="button-link cradle-content-danger" data-cradle-remove>' . esc_html__( 'Remove row', 'cradle-content' ) . '</button></span>';
		echo '</fieldset></li>';
	}

	/**
	 * Strips the row template key and normalizes submitted group rows to a list.
	 *
	 * @param mixed $raw Submitted value.
	 * @return mixed
	 */
	public static function clean_submission( array $field, $raw ) {
		if ( 'group' === $field['type'] && is_array( $raw ) ) {
			unset( $raw['__r__'] );
			return array_values( array_filter( $raw, 'is_array' ) );
		}
		if ( Field_Types::is_multi( $field ) && is_array( $raw ) ) {
			return array_values( array_filter( $raw, static fn( $v ) => '' !== $v && null !== $v ) );
		}
		return $raw;
	}
}
