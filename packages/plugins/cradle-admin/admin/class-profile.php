<?php
/**
 * Per-user appearance choice on the profile screen: "use the default WordPress
 * appearance" instead of the Cradle skin. Stored as user meta; requires the
 * edit_user object capability and a dedicated nonce.
 *
 * @package Cradle\Admin
 */

namespace Cradle\Admin\Admin;

use Cradle\Admin\Branding;
use Cradle\Admin\Custom_Css;
use Cradle\Admin\Module;

defined( 'ABSPATH' ) || exit;

final class Profile {

	const FIELD = 'cradle_admin_native_appearance';

	public static function hooks() {
		add_action( 'show_user_profile', array( self::class, 'render' ) );
		add_action( 'edit_user_profile', array( self::class, 'render' ) );
		add_action( 'personal_options_update', array( self::class, 'save' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save' ) );
	}

	/**
	 * The choice is offered only when users may choose and there is Cradle styling to opt out of.
	 */
	public static function available(): bool {
		$visual = Module::values( Module::SECTION_VISUAL );
		if ( empty( $visual['allow_user_choice'] ) ) {
			return false;
		}
		$logo = in_array( $visual['logo_placement'] ?? 'menu', array( 'menu', 'both' ), true ) && '' !== Branding::logo_url( 'admin' );
		return ! empty( $visual['enabled'] ) || $logo || '' !== Custom_Css::current()['css'];
	}

	/**
	 * @param \WP_User $user Profile being edited.
	 */
	public static function render( $user ) {
		if ( ! $user instanceof \WP_User || ! current_user_can( 'edit_user', $user->ID ) || ! self::available() ) {
			return;
		}
		$checked = '1' === (string) get_user_meta( $user->ID, Module::USER_META, true );
		?>
		<h2 id="cradle-admin-appearance"><?php esc_html_e( 'Admin appearance', 'cradle-admin' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Cradle admin skin', 'cradle-admin' ); ?></th>
				<td>
					<?php wp_nonce_field( 'cradle_admin_profile_' . $user->ID, 'cradle_admin_profile_nonce' ); ?>
					<input type="hidden" name="<?php echo esc_attr( self::FIELD ); ?>" value="0" />
					<label for="cradle-admin-native-appearance">
						<input type="checkbox" id="cradle-admin-native-appearance" name="<?php echo esc_attr( self::FIELD ); ?>" value="1" aria-describedby="cradle-admin-native-appearance-help" <?php checked( $checked ); ?> />
						<?php esc_html_e( 'Use the default WordPress appearance instead of the site’s Cradle admin skin', 'cradle-admin' ); ?>
					</label>
					<p class="description" id="cradle-admin-native-appearance-help"><?php esc_html_e( 'Affects only how the administration looks for this account (colors, layout, menu logo and custom CSS). Branding text and labels stay the same.', 'cradle-admin' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * @param int $user_id Profile being saved (WordPress already checked its own nonce and edit_user).
	 */
	public static function save( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce value.
		$nonce = isset( $_POST['cradle_admin_profile_nonce'] ) && is_string( $_POST['cradle_admin_profile_nonce'] ) ? wp_unslash( $_POST['cradle_admin_profile_nonce'] ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'cradle_admin_profile_' . $user_id ) || ! self::available() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared to a fixed value.
		$value = isset( $_POST[ self::FIELD ] ) && is_string( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : '0';
		if ( '1' === $value ) {
			update_user_meta( $user_id, Module::USER_META, '1' );
		} elseif ( '0' === $value ) {
			delete_user_meta( $user_id, Module::USER_META );
		}
	}
}
