<?php
/**
 * REST controller for `wp/v2/cradle-forms`: the standard posts controller, but reading forms
 * (even published ones) requires `cradle_manage_forms`. Forms hold notification addresses and
 * routing rules; visitors receive form markup only through the `cradle/form` block.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

class Rest_Forms_Controller extends \WP_REST_Posts_Controller {

	private function deny() {
		return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to view forms.', 'cradle-forms' ), array( 'status' => rest_authorization_required_code() ) );
	}

	public function get_items_permissions_check( $request ) {
		return current_user_can( Caps::MANAGE ) ? parent::get_items_permissions_check( $request ) : $this->deny();
	}

	public function get_item_permissions_check( $request ) {
		return current_user_can( Caps::MANAGE ) ? parent::get_item_permissions_check( $request ) : $this->deny();
	}

	public function check_read_permission( $post ) {
		return current_user_can( Caps::MANAGE ) && parent::check_read_permission( $post );
	}
}
