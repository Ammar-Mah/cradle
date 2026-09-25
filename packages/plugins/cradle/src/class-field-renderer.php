<?php
/**
 * Accessible server-rendered controls for registered settings fields.
 *
 * @package Cradle
 */

namespace Cradle\Core;

defined( 'ABSPATH' ) || exit;

final class Field_Renderer {

	public static function enqueue() {
		wp_enqueue_style( 'cradle-admin' );
		wp_enqueue_script( 'cradle-admin' );
		if ( function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Outputs one `<tr>` for a field.
	 *
	 * @param string $option Option name used for the input name prefix.
	 * @param string $key    Field key.
	 * @param array  $field  Field definition.
	 * @param mixed  $value  Current value.
	 * @param string $error  Field-specific error message.
	 */
	public static function row( string $option, string $key, array $field, $value, string $error = '' ) {
		$id       = 'cradle-' . str_replace( '_', '-', $option ) . '-' . str_replace( '_', '-', $key );
		$name     = $option . '[' . $key . ']';
		$help_id  = $id . '-help';
		$error_id = $id . '-error';
		$describe = trim( ( $field['help'] ? $help_id : '' ) . ' ' . ( $error ? $error_id : '' ) );
		// Grouped controls (and custom-rendered fields, whose markup core cannot see) get a text
		// label referenced by aria-labelledby instead of a <label for> that could point at nothing.
		$is_group = in_array( $field['type'], array( 'dimension', 'multi_enum', 'boolean' ), true ) || 'radio' === ( $field['control'] ?? '' ) || ( isset( $field['render'] ) && is_callable( $field['render'] ) );
		?>
		<tr class="cradle-field cradle-field--<?php echo esc_attr( $field['type'] ); ?><?php echo $error ? ' cradle-field--error' : ''; ?>">
			<th scope="row">
				<?php if ( $is_group ) : ?>
					<span id="<?php echo esc_attr( $id ); ?>-label"><?php echo esc_html( $field['label'] ); ?></span>
				<?php else : ?>
					<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
				<?php endif; ?>
			</th>
			<td>
				<?php
				if ( isset( $field['render'] ) && is_callable( $field['render'] ) ) {
					call_user_func( $field['render'], $name, $id, $value, $field, $describe );
				} else {
					self::control( $name, $id, $field, $value, $describe );
				}
				if ( $field['help'] ) {
					echo '<p class="description" id="' . esc_attr( $help_id ) . '">' . esc_html( $field['help'] ) . '</p>';
				}
				if ( $error ) {
					echo '<p class="cradle-field-error" id="' . esc_attr( $error_id ) . '">' . esc_html( $error ) . '</p>';
				}
				?>
			</td>
		</tr>
		<?php
	}

	private static function control( string $name, string $id, array $field, $value, string $describe ) {
		$aria = $describe ? ' aria-describedby="' . esc_attr( $describe ) . '"' : '';
		switch ( $field['type'] ) {
			case 'boolean':
				?>
				<fieldset aria-labelledby="<?php echo esc_attr( $id ); ?>-label">
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0" />
					<label for="<?php echo esc_attr( $id ); ?>">
						<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (bool) $value ); ?><?php echo $aria; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
						<?php echo esc_html( $field['checkbox_label'] ?? __( 'Enabled', 'cradle' ) ); ?>
					</label>
				</fieldset>
				<?php
				return;

			case 'integer':
			case 'number':
				$type = 'range' === ( $field['control'] ?? '' ) ? 'range' : 'number';
				printf(
					'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="small-text"%5$s%6$s%7$s%8$s />',
					esc_attr( $type ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					isset( $field['minimum'] ) ? ' min="' . esc_attr( (string) $field['minimum'] ) . '"' : '',
					isset( $field['maximum'] ) ? ' max="' . esc_attr( (string) $field['maximum'] ) . '"' : '',
					' step="' . esc_attr( (string) ( $field['step'] ?? ( 'integer' === $field['type'] ? 1 : 'any' ) ) ) . '"',
					$aria // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				if ( 'range' === $type ) {
					echo ' <output for="' . esc_attr( $id ) . '">' . esc_html( (string) $value ) . '</output>';
				}
				return;

			case 'enum':
				$labels = Settings::enum_labels( $field );
				if ( 'radio' === ( $field['control'] ?? '' ) ) {
					echo '<fieldset aria-labelledby="' . esc_attr( $id ) . '-label">';
					foreach ( $labels as $option_value => $label ) {
						printf(
							'<label><input type="radio" name="%1$s" value="%2$s" %3$s /> %4$s</label><br />',
							esc_attr( $name ),
							esc_attr( $option_value ),
							checked( (string) $value, $option_value, false ),
							esc_html( $label )
						);
					}
					echo '</fieldset>';
					return;
				}
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $aria . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				foreach ( $labels as $option_value => $label ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option_value ), selected( (string) $value, $option_value, false ), esc_html( $label ) );
				}
				echo '</select>';
				return;

			case 'multi_enum':
				$labels = Settings::enum_labels( $field );
				$value  = (array) $value;
				echo '<fieldset aria-labelledby="' . esc_attr( $id ) . '-label">';
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="" />';
				foreach ( $labels as $option_value => $label ) {
					printf(
						'<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label><br />',
						esc_attr( $name ),
						esc_attr( $option_value ),
						checked( in_array( $option_value, $value, true ), true, false ),
						esc_html( $label )
					);
				}
				echo '</fieldset>';
				return;

			case 'color':
				printf(
					'<input type="color" id="%1$s" name="%2$s" value="%3$s"%4$s /> <code class="cradle-color-value">%5$s</code>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value ? (string) $value : '#000000' ),
					$aria, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html( (string) $value )
				);
				return;

			case 'dimension':
				$value = is_array( $value ) ? $value : Settings::type_default( 'dimension' );
				$units = (array) ( $field['units'] ?? array( 'px', 'rem', 'em', '%', 'vh', 'vw' ) );
				echo '<fieldset class="cradle-dimension" aria-labelledby="' . esc_attr( $id ) . '-label">';
				printf(
					'<label for="%1$s" class="screen-reader-text">%2$s</label><input type="number" id="%1$s" name="%3$s[value]" value="%4$s" class="small-text" step="%5$s"%6$s%7$s%8$s />',
					esc_attr( $id ),
					esc_html__( 'Size', 'cradle' ),
					esc_attr( $name ),
					esc_attr( (string) $value['value'] ),
					esc_attr( (string) ( $field['step'] ?? 'any' ) ),
					isset( $field['minimum'] ) ? ' min="' . esc_attr( (string) $field['minimum'] ) . '"' : '',
					isset( $field['maximum'] ) ? ' max="' . esc_attr( (string) $field['maximum'] ) . '"' : '',
					$aria // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				printf( '<label for="%1$s-unit" class="screen-reader-text">%2$s</label><select id="%1$s-unit" name="%3$s[unit]">', esc_attr( $id ), esc_html__( 'Unit', 'cradle' ), esc_attr( $name ) );
				foreach ( $units as $unit ) {
					printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $unit ), selected( $value['unit'], $unit, false ) );
				}
				echo '</select></fieldset>';
				return;

			case 'attachment':
				$att_id  = (int) $value;
				$preview = $att_id ? wp_get_attachment_image( $att_id, 'thumbnail', true ) : '';
				?>
				<div class="cradle-media-field" data-cradle-media="<?php echo esc_attr( wp_json_encode( array( 'type' => (array) ( $field['mime'] ?? array() ) ) ) ); ?>">
					<input type="hidden" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $att_id ); ?>" />
					<div class="cradle-media-preview" aria-live="polite"><?php echo $preview; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-generated image markup. ?></div>
					<button type="button" class="button cradle-media-select"<?php echo $aria; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php esc_html_e( 'Select media', 'cradle' ); ?></button>
					<button type="button" class="button-link cradle-media-remove"<?php echo $att_id ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'cradle' ); ?></button>
				</div>
				<?php
				return;

			case 'object':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="6" class="large-text code"%3$s>%4$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					$aria, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_textarea( is_string( $value ) ? $value : (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) )
				);
				return;
		}

		$control = $field['control'] ?? 'text';
		if ( 'textarea' === $control ) {
			printf(
				'<textarea id="%1$s" name="%2$s" rows="%3$d" class="large-text"%4$s%5$s>%6$s</textarea>',
				esc_attr( $id ),
				esc_attr( $name ),
				(int) ( $field['rows'] ?? 4 ),
				isset( $field['maxLength'] ) ? ' maxlength="' . esc_attr( (string) $field['maxLength'] ) . '"' : '',
				$aria, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_textarea( (string) $value )
			);
			return;
		}
		$input_type = array(
			'url'   => 'url',
			'email' => 'email',
		)[ $field['type'] ] ?? 'text';
		printf(
			'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text"%5$s%6$s%7$s />',
			esc_attr( $input_type ),
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( (string) $value ),
			isset( $field['maxLength'] ) ? ' maxlength="' . esc_attr( (string) $field['maxLength'] ) . '"' : '',
			isset( $field['placeholder'] ) ? ' placeholder="' . esc_attr( (string) $field['placeholder'] ) . '"' : '',
			$aria // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}
}
