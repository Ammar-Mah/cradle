<?php
/**
 * Cradle Admin (module `admin`) integration tests — northstar §11, CR-14.
 *
 * Run: php tests/run.php --suite=admin
 * Every test resets the module to its neutral defaults; the last test verifies the
 * final state is white-label OFF with the plugin active.
 */

use Cradle\Admin\Branding;
use Cradle\Admin\Css_Policy;
use Cradle\Admin\Custom_Css;
use Cradle\Admin\Labels;
use Cradle\Admin\Lifecycle;
use Cradle\Admin\Module;
use Cradle\Admin\Skin;

require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

$GLOBALS['cradle_admin_test_log_offset'] = is_file( WP_CONTENT_DIR . '/debug.log' ) ? filesize( WP_CONTENT_DIR . '/debug.log' ) : 0;

function cradle_admin_test_flush() {
	wp_cache_delete( 'alloptions', 'options' );
	foreach ( array( 'cradle_admin_branding_settings', 'cradle_admin_visual_settings', 'cradle_admin_labels_settings', Custom_Css::OPTION, Custom_Css::REVISIONS_OPTION, 'cradle_uninstall_policy', 'cradle_granted_capabilities' ) as $option ) {
		wp_cache_delete( $option, 'options' );
	}
	wp_cache_delete( 'notoptions', 'options' );
	Labels::flush();
}

/**
 * Neutral defaults: white-label off, no custom CSS, no history created by tests.
 */
function cradle_admin_test_reset() {
	foreach ( Module::sections() as $section ) {
		cradle()->settings()->reset( $section );
	}
	update_option( Custom_Css::OPTION, Custom_Css::empty_state(), false );
	update_option( Custom_Css::REVISIONS_OPTION, array(), false );
	cradle_admin_test_flush();
	Labels::restore_entities();
	Labels::apply_registered_entities();
}

function cradle_admin_test_update( string $section, array $values ): array {
	$previous = get_current_user_id();
	wp_set_current_user( cradle_test_user( 'administrator' ) );
	$result = cradle()->settings()->update( $section, $values );
	wp_set_current_user( $previous );
	cradle_admin_test_flush();
	if ( is_wp_error( $result ) ) {
		throw new Cradle_Test_Failure( 'Settings update failed: ' . $result->get_error_message() );
	}
	return $result['errors'];
}

function cradle_admin_test_body_classes( string $html ): array {
	if ( ! preg_match( '/<body[^>]*\bclass="([^"]*)"/', $html, $m ) ) {
		return array();
	}
	return preg_split( '/\s+/', trim( $m[1] ) );
}

function cradle_admin_test_has_id( string $html, string $id ): bool {
	return (bool) preg_match( '/\bid=["\x27]' . preg_quote( $id, '/' ) . '["\x27]/', $html );
}

function cradle_admin_test_get( string $path, int $user_id, ?array $session = null ): array {
	$response = cradle_test_http( 'GET', admin_url( $path ), array(), $user_id, $session );
	if ( is_wp_error( $response ) ) {
		throw new Cradle_Test_Failure( 'HTTP error for ' . $path . ': ' . $response->get_error_message() );
	}
	return array( wp_remote_retrieve_response_code( $response ), wp_remote_retrieve_body( $response ), $response );
}

function cradle_admin_test_assert_clean_page( string $path, int $code, string $body ) {
	assert_same( 200, $code, "{$path} should load." );
	foreach ( array( 'Fatal error', 'Parse error', '<b>Warning</b>', '<b>Notice</b>', '<b>Deprecated</b>', 'There has been a critical error' ) as $needle ) {
		assert_not_contains( $needle, $body, "{$path} must not show PHP errors." );
	}
	assert_contains( '</html>', $body, "{$path} should render completely." );
}

function cradle_admin_test_logo(): int {
	$existing = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'title'       => 'Cradle Admin test logo',
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
	if ( $existing ) {
		return (int) $existing[0];
	}
	$img = imagecreatetruecolor( 120, 40 );
	imagefill( $img, 0, 0, imagecolorallocate( $img, 20, 60, 120 ) );
	ob_start();
	imagepng( $img );
	$bytes = ob_get_clean();
	$upload = wp_upload_bits( 'cradle-admin-test-logo.png', null, $bytes );
	if ( ! empty( $upload['error'] ) ) {
		throw new Cradle_Test_Failure( 'Could not create logo fixture: ' . $upload['error'] );
	}
	$id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'Cradle Admin test logo',
			'post_status'    => 'inherit',
		),
		$upload['file']
	);
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	return (int) $id;
}

function cradle_admin_test_remove_logo() {
	$ids = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'title'       => 'Cradle Admin test logo',
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);
	foreach ( $ids as $id ) {
		wp_delete_attachment( (int) $id, true );
	}
}

/**
 * A disposable administrator-like role without unfiltered_html.
 */
function cradle_admin_test_untrusted_user(): int {
	if ( ! get_role( 'cradle_admin_test_untrusted' ) ) {
		add_role(
			'cradle_admin_test_untrusted',
			'Cradle Admin test (no unfiltered_html)',
			array(
				'read'                   => true,
				'manage_options'         => true,
				'cradle_manage_branding' => true,
			)
		);
	}
	$user = get_user_by( 'login', 'cradle_admin_test_untrusted' );
	if ( $user ) {
		return (int) $user->ID;
	}
	$id = wp_insert_user(
		array(
			'user_login' => 'cradle_admin_test_untrusted',
			'user_pass'  => wp_generate_password( 32 ),
			'user_email' => 'cradle_admin_test_untrusted@example.test',
			'role'       => 'cradle_admin_test_untrusted',
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new Cradle_Test_Failure( 'Could not create untrusted user: ' . $id->get_error_message() );
	}
	return (int) $id;
}

function cradle_admin_test_remove_untrusted() {
	$user = get_user_by( 'login', 'cradle_admin_test_untrusted' );
	if ( $user ) {
		wp_delete_user( $user->ID );
	}
	remove_role( 'cradle_admin_test_untrusted' );
}

function cradle_admin_test_post( string $path, array $body, int $user_id, ?array $session = null ) {
	return cradle_test_http( 'POST', admin_url( $path ), array( 'body' => $body ), $user_id, $session );
}

cradle_test(
	'admin: plugin active, module ready, sections and capability policy',
	function () {
		assert_true( is_plugin_active( 'cradle-admin/cradle-admin.php' ), 'cradle-admin must be active.' );
		assert_same( 'ready', cradle()->modules()->state( 'admin' ) );
		foreach ( Module::sections() as $section ) {
			$def = cradle()->settings()->section( $section );
			assert_true( is_array( $def ), "Section {$section} is registered." );
			assert_same( 'cradle_manage_branding', $def['capability'] );
			assert_same( 'admin', $def['owner'] );
		}
		assert_true( get_role( 'administrator' )->has_cap( 'cradle_manage_branding' ), 'Administrators get the branding capability.' );
		assert_false( get_role( 'editor' )->has_cap( 'cradle_manage_branding' ), 'Editors must not get it by default.' );
		assert_false( user_can( cradle_test_user( 'subscriber' ), 'cradle_manage_branding' ) );
		assert_true( Custom_Css::user_can_edit( cradle_test_user( 'administrator' ) ), 'A single-site administrator is a trusted CSS editor.' );
		assert_false( Custom_Css::user_can_edit( cradle_test_user( 'editor' ) ) );
	}
);

cradle_test(
	'admin: defaults are neutral (white-label off until configured)',
	function () {
		cradle_admin_test_reset();
		wp_set_current_user( cradle_test_user( 'administrator' ) );
		$state = Skin::state( WP_Screen::get( 'dashboard' ) );
		assert_same( array(), $state['classes'], 'No body classes by default.' );
		assert_same( 'Original footer', Branding::footer_text( 'Original footer' ) );
		assert_same( 'Dashboard &lsaquo; Site &#8212; WordPress', Branding::admin_title( 'Dashboard &lsaquo; Site &#8212; WordPress' ) );
		assert_same( 'Posts', get_post_type_object( 'post' )->labels->name );
		assert_same( '', Custom_Css::printable() );
		$branding = cradle()->settings()->values( 'admin-branding' );
		foreach ( array( 'admin_bar_branding', 'welcome_enabled', 'login_branding' ) as $flag ) {
			assert_false( $branding[ $flag ], "{$flag} defaults to off." );
		}
		assert_false( cradle()->settings()->values( 'admin-visual' )['enabled'], 'The skin defaults to off.' );
		assert_false( get_option( 'cradle_admin_visual_settings', false ), 'Reading defaults never writes an option.' );
	}
);

cradle_test(
	'admin: appearance validation rejects bad colors, units, ranges, enums and low contrast',
	function () {
		cradle_admin_test_reset();
		$errors = cradle_admin_test_update(
			'admin-visual',
			array(
				'accent_color'     => 'red',
				'background_color' => '#12345',
				'border_color'     => 'javascript:alert(1)',
				'sidebar_width'    => array( 'value' => 20, 'unit' => 'vw' ),
				'content_spacing'  => array( 'value' => 500, 'unit' => 'px' ),
				'border_radius'    => array( 'value' => 'x', 'unit' => 'px' ),
				'font_size'        => '14px',
				'density'          => 'huge',
				'screens'          => array( 'dashboard', 'bogus' ),
				'font_family'      => 'comic-sans',
			)
		);
		foreach ( array( 'accent_color', 'background_color', 'border_color', 'sidebar_width', 'content_spacing', 'border_radius', 'font_size', 'density', 'screens', 'font_family' ) as $key ) {
			assert_true( isset( $errors[ $key ] ), "{$key} must be rejected." );
		}
		$values = cradle()->settings()->values( 'admin-visual' );
		assert_same( '#3858e9', $values['accent_color'], 'Rejected values keep the previous value.' );
		assert_same( array( 'value' => 160.0, 'unit' => 'px' ), $values['sidebar_width'] );

		$errors = cradle_admin_test_update(
			'admin-visual',
			array(
				'accent_color'  => '#135E96',
				'sidebar_width' => array( 'value' => '200', 'unit' => 'px' ),
				'density'       => 'compact',
			)
		);
		assert_same( array(), $errors );
		$values = cradle()->settings()->values( 'admin-visual' );
		assert_same( '#135e96', $values['accent_color'], 'Valid colors are normalized.' );
		assert_same( array( 'value' => 200.0, 'unit' => 'px' ), $values['sidebar_width'] );

		// Accessibility: pairs below WCAG contrast are refused and both fields keep valid values.
		$errors = cradle_admin_test_update(
			'admin-visual',
			array(
				'text_color'       => '#eeeeee',
				'background_color' => '#f8f8f8',
			)
		);
		assert_true( isset( $errors['text_color'], $errors['background_color'] ), 'Low text contrast is refused.' );
		$values = cradle()->settings()->values( 'admin-visual' );
		assert_same( '#1e1e1e', $values['text_color'] );
		assert_same( '#f0f0f0', $values['background_color'] );
		$errors = cradle_admin_test_update( 'admin-visual', array( 'accent_color' => '#ffff00' ) );
		assert_true( isset( $errors['accent_color'] ), 'An accent that fails 4.5:1 with white is refused.' );
		$errors = cradle_admin_test_update( 'admin-visual', array( 'menu_text' => '#333333' ) );
		assert_true( isset( $errors['menu_text'], $errors['menu_background'] ), 'Unreadable menu text is refused.' );
		$errors = cradle_admin_test_update( 'admin-visual', array( 'focus_color' => '#f0f0f0' ) );
		assert_true( isset( $errors['focus_color'] ), 'An invisible focus color is refused.' );
		cradle_admin_test_reset();
	}
);

cradle_test(
	'admin: branding validation (links, limited rich text, attachments, login contrast)',
	function () {
		cradle_admin_test_reset();
		$errors = cradle_admin_test_update(
			'admin-branding',
			array(
				'support_links' => array( array( 'label' => 'Help', 'url' => 'javascript:alert(1)' ) ),
				'admin_logo'    => 99999999,
				'login_accent'  => '#ffff00',
				'brand_name'    => str_repeat( 'x', 81 ),
				'brand_url'     => 'ftp://example.test/',
			)
		);
		foreach ( array( 'support_links', 'admin_logo', 'login_accent', 'brand_name', 'brand_url' ) as $key ) {
			assert_true( isset( $errors[ $key ] ), "{$key} must be rejected." );
		}
		$errors = cradle_admin_test_update(
			'admin-branding',
			array(
				'support_links'   => array(
					array( 'label' => 'Support', 'url' => 'https://support.example.test/' ),
					array( 'label' => '', 'url' => '' ),
				),
				'welcome_content' => '<p onclick="x()">Hello <strong>team</strong></p><script>alert(1)</script><a href="javascript:alert(1)">bad</a><a href="https://docs.example.test/">Docs</a><iframe src="https://x.test"></iframe>',
			)
		);
		assert_same( array(), $errors );
		$values = cradle()->settings()->values( 'admin-branding' );
		assert_same( array( array( 'label' => 'Support', 'url' => 'https://support.example.test/' ) ), $values['support_links'] );
		assert_not_contains( 'onclick', $values['welcome_content'] );
		assert_not_contains( '<script', $values['welcome_content'] );
		assert_not_contains( 'javascript:', $values['welcome_content'] );
		assert_not_contains( '<iframe', $values['welcome_content'] );
		assert_contains( '<strong>team</strong>', $values['welcome_content'] );
		assert_contains( 'href="https://docs.example.test/"', $values['welcome_content'] );
		cradle_admin_test_reset();
	}
);

cradle_test(
	'admin: options.php saves for administrators and refuses editors and subscribers',
	function () {
		cradle_admin_test_reset();
		$admin    = cradle_test_user( 'administrator' );
		$session  = cradle_test_cookies( $admin );
		$nonce    = cradle_test_nonce( $admin, $session, 'cradle_admin_visual-options' );
		$response = cradle_admin_test_post(
			'options.php',
			array(
				'option_page'                  => 'cradle_admin_visual',
				'action'                       => 'update',
				'_wpnonce'                     => $nonce,
				'_wp_http_referer'             => '/cradle/wp/wp-admin/admin.php?page=cradle-admin&tab=appearance',
				'cradle_admin_visual_settings' => array(
					'_revision'    => cradle()->settings()->revision( 'admin-visual' ),
					'enabled'      => '1',
					'accent_color' => '#135e96',
					'screens'      => array( 'dashboard' ),
				),
			),
			$admin,
			$session
		);
		assert_same( 302, wp_remote_retrieve_response_code( $response ), 'options.php redirects after saving.' );
		cradle_admin_test_flush();
		$values = cradle()->settings()->values( 'admin-visual' );
		assert_true( $values['enabled'], 'Administrator saved the skin switch.' );
		assert_same( array( 'dashboard' ), $values['screens'] );

		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			$user     = cradle_test_user( $role );
			$usession = cradle_test_cookies( $user );
			$unonce   = cradle_test_nonce( $user, $usession, 'cradle_admin_visual-options' );
			$response = cradle_admin_test_post(
				'options.php',
				array(
					'option_page'                  => 'cradle_admin_visual',
					'action'                       => 'update',
					'_wpnonce'                     => $unonce,
					'cradle_admin_visual_settings' => array(
						'_revision' => cradle()->settings()->revision( 'admin-visual' ),
						'enabled'   => '0',
					),
				),
				$user,
				$usession
			);
			$code = wp_remote_retrieve_response_code( $response );
			assert_true( in_array( $code, array( 403, 500 ), true ) || false !== strpos( wp_remote_retrieve_body( $response ), 'not allowed' ), "{$role} must be refused by options.php (got {$code})." );
			cradle_admin_test_flush();
			assert_true( cradle()->settings()->values( 'admin-visual' )['enabled'], "{$role} could not change the setting." );

			list( $page_code, $page_body ) = cradle_admin_test_get( 'admin.php?page=cradle-admin', $user, $usession );
			assert_true( 200 !== $page_code || false !== strpos( $page_body, 'not allowed' ), "{$role} cannot open the settings page." );
		}

		// The core reset handler honours the section capability too.
		$editor   = cradle_test_user( 'editor' );
		$esession = cradle_test_cookies( $editor );
		$response = cradle_admin_test_post(
			'admin-post.php',
			array(
				'action'   => 'cradle_settings_reset',
				'section'  => 'admin-visual',
				'_wpnonce' => cradle_test_nonce( $editor, $esession, 'cradle_settings_reset_admin-visual' ),
			),
			$editor,
			$esession
		);
		assert_same( 403, wp_remote_retrieve_response_code( $response ), 'Editor cannot reset the section.' );
		cradle_admin_test_flush();
		assert_true( cradle()->settings()->values( 'admin-visual' )['enabled'] );

		// Administrator reset restores defaults (one per section).
		foreach ( Module::sections() as $section ) {
			cradle_admin_test_update( $section, 'admin-visual' === $section ? array( 'enabled' => true ) : array( 'brand_name' => 'X', 'menu_labels' => array() ) );
			$response = cradle_admin_test_post(
				'admin-post.php',
				array(
					'action'           => 'cradle_settings_reset',
					'section'          => $section,
					'_wpnonce'         => cradle_test_nonce( $admin, $session, 'cradle_settings_reset_' . $section ),
					'_wp_http_referer' => '/cradle/wp/wp-admin/admin.php?page=cradle-admin',
				),
				$admin,
				$session
			);
			assert_same( 302, wp_remote_retrieve_response_code( $response ), "Reset of {$section} redirects." );
			cradle_admin_test_flush();
			assert_false( get_option( cradle()->settings()->section( $section )['option'], false ), "{$section} was reset to defaults." );
		}
		cradle_admin_test_reset();
	}
);

cradle_test(
	'admin: raw CSS policy rejects container escapes, imports, script vectors and external URLs',
	function () {
		$rejected = array(
			'style escape'           => 'body{color:red}</style><script>alert(1)</script>',
			'any angle bracket'      => 'a::after{content:"<b>"}',
			'import'                 => '@import url("/x.css");',
			'escaped import'         => '@\\69mport "x.css";',
			'expression'             => 'a{width:expression(alert(1))}',
			'escaped expression'     => 'a{width:e\\78pression(alert(1))}',
			'javascript url'         => 'a{background:url(javascript:alert(1))}',
			'escaped javascript'     => 'a{background:url("j\\61vascript:alert(1)")}',
			'behavior'               => 'a{behavior:url(x.htc)}',
			'moz binding'            => 'a{-moz-binding:url(x.xml#x)}',
			'external host'          => 'a{background:url(https://evil.example/x.png)}',
			'protocol relative'      => 'a{background:url(//evil.example/x.png)}',
			'backslash host'         => 'a{background:url("/\\\\evil.example/x.png")}',
			'escaped slash host'     => 'a{background:url("/\\2f evil.example/x.png")}',
			'unquoted escaped url'   => 'a{background:u\\72l(//evil.example/x.png)}',
			'data html'              => 'a{background:url("data:text/html,hi")}',
			'image-set string'       => 'a{background-image:image-set("https://evil.example/a.png" 1x)}',
			'unclosed comment'       => 'a{color:red} /* never closed',
			'unbalanced braces'      => 'a{color:red',
			'stray brace'            => 'a{color:red}}',
			'control character'      => "a{color:red}\x01",
			'hide update nag'        => '.update-nag{display:none}',
			'hide update bubble'     => '#adminmenu .update-plugins { visibility : hidden }',
			'hide notices in media'  => '@media screen{.notice-error{display:none!important}}',
			'too large'              => str_repeat( 'a{color:red}', 6000 ),
			'invalid utf8'           => "a{content:\"\xC3\x28\"}",
		);
		foreach ( $rejected as $label => $css ) {
			assert_true( (bool) Css_Policy::validate( $css ), "Policy must reject: {$label}." );
		}
		$accepted = array(
			'plain'             => 'body.cradle-admin-custom-css #wpbody-content > .wrap { max-width: 80rem; }',
			'dashicon escape'   => '.x::before { content: "\\f105"; }',
			'relative url'      => '.x { background: url(images/bg.png) no-repeat; }',
			'same-site url'     => '.x { background: url("' . content_url( 'uploads/x.png' ) . '"); }',
			'data image'        => '.x { background: url("data:image/png;base64,iVBORw0KGgo="); }',
			'fragment'          => '.x { filter: url(#blur); }',
			'media query'       => '@media (max-width: 782px) { .x { display: none; } }',
			'scroll behavior'   => 'html { scroll-behavior: smooth; }',
			'notice styling'    => '.notice-warning { border-inline-start-width: 6px; }',
			'braces in strings' => '.x::after { content: "}{"; }',
		);
		foreach ( $accepted as $label => $css ) {
			assert_same( array(), Css_Policy::validate( $css ), "Policy must accept: {$label}." );
		}
	}
);

cradle_test(
	'admin: raw CSS handlers require POST, a valid nonce and a trusted administrator',
	function () {
		cradle_admin_test_reset();
		$admin   = cradle_test_user( 'administrator' );
		$session = cradle_test_cookies( $admin );
		$css     = 'body.cradle-admin-custom-css #wpfooter { font-style: italic; }';

		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			$user     = cradle_test_user( $role );
			$usession = cradle_test_cookies( $user );
			$response = cradle_admin_test_post(
				'admin-post.php',
				array(
					'action'           => 'cradle_admin_css_save',
					'_wpnonce'         => cradle_test_nonce( $user, $usession, 'cradle_admin_css_save' ),
					'base_revision'    => 0,
					'cradle_admin_css' => $css,
				),
				$user,
				$usession
			);
			assert_same( 403, wp_remote_retrieve_response_code( $response ), "{$role} is refused." );
		}

		$untrusted = cradle_admin_test_untrusted_user();
		$usession  = cradle_test_cookies( $untrusted );
		assert_false( Custom_Css::user_can_edit( $untrusted ), 'manage_options without unfiltered_html is not trusted.' );
		$response = cradle_admin_test_post(
			'admin-post.php',
			array(
				'action'           => 'cradle_admin_css_save',
				'_wpnonce'         => cradle_test_nonce( $untrusted, $usession, 'cradle_admin_css_save' ),
				'base_revision'    => 0,
				'cradle_admin_css' => $css,
			),
			$untrusted,
			$usession
		);
		assert_same( 403, wp_remote_retrieve_response_code( $response ), 'Administrator without unfiltered_html is refused.' );
		list( $code, $body ) = cradle_admin_test_get( 'admin.php?page=cradle-admin&tab=css', $untrusted, $usession );
		assert_same( 200, $code, 'Branding managers can open the page.' );
		assert_false( cradle_admin_test_has_id( $body, 'cradle-admin-css-editor' ), 'but get no raw CSS editor.' );
		assert_contains( 'The structured controls remain available', $body );

		$response = cradle_test_http( 'GET', admin_url( 'admin-post.php?action=cradle_admin_css_reset' ), array(), $admin, $session );
		assert_same( 405, wp_remote_retrieve_response_code( $response ), 'GET never mutates.' );

		$response = cradle_admin_test_post(
			'admin-post.php',
			array(
				'action'           => 'cradle_admin_css_save',
				'_wpnonce'         => 'invalid',
				'base_revision'    => 0,
				'cradle_admin_css' => $css,
			),
			$admin,
			$session
		);
		assert_same( 403, wp_remote_retrieve_response_code( $response ), 'An invalid nonce is refused.' );
		cradle_admin_test_flush();
		assert_same( '', Custom_Css::current()['css'], 'Nothing was saved by refused requests.' );

		$nonce    = cradle_test_nonce( $admin, $session, 'cradle_admin_css_save' );
		$response = cradle_admin_test_post(
			'admin-post.php',
			array(
				'action'           => 'cradle_admin_css_save',
				'_wpnonce'         => $nonce,
				'base_revision'    => 0,
				'cradle_admin_css' => $css,
			),
			$admin,
			$session
		);
		assert_same( 302, wp_remote_retrieve_response_code( $response ) );
		assert_contains( 'cradle-admin-css=saved', (string) wp_remote_retrieve_header( $response, 'location' ) );
		cradle_admin_test_flush();
		assert_same( $css, Custom_Css::current()['css'] );
		assert_same( 1, Custom_Css::current()['revision'] );

		// Rejected CSS: nothing saved, draft kept for the user.
		$response = cradle_admin_test_post(
			'admin-post.php',
			array(
				'action'           => 'cradle_admin_css_save',
				'_wpnonce'         => $nonce,
				'base_revision'    => 1,
				'cradle_admin_css' => '@import url(https://evil.example/x.css);',
			),
			$admin,
			$session
		);
		assert_contains( 'cradle-admin-css=rejected', (string) wp_remote_retrieve_header( $response, 'location' ) );
		cradle_admin_test_flush();
		assert_same( $css, Custom_Css::current()['css'], 'Rejected CSS is not saved.' );
		wp_cache_delete( '_transient_' . Custom_Css::draft_key( $admin ), 'options' );
		$draft = Custom_Css::take_draft( $admin );
		assert_true( is_array( $draft ) && str_contains( $draft['css'], '@import' ) && $draft['errors'], 'The rejected draft and its problems are kept for the editor.' );

		// Stale edit: someone else saved in between.
		$response = cradle_admin_test_post(
			'admin-post.php',
			array(
				'action'           => 'cradle_admin_css_save',
				'_wpnonce'         => $nonce,
				'base_revision'    => 0,
				'cradle_admin_css' => 'a{color:red}',
			),
			$admin,
			$session
		);
		assert_contains( 'cradle-admin-css=stale', (string) wp_remote_retrieve_header( $response, 'location' ) );
		cradle_admin_test_flush();
		assert_same( $css, Custom_Css::current()['css'], 'Stale edits do not overwrite newer CSS.' );
		delete_transient( Custom_Css::draft_key( $admin ) );

		list( $code, $body ) = cradle_admin_test_get( 'admin.php?page=cradle-admin&tab=css', $admin, $session );
		cradle_admin_test_assert_clean_page( 'CSS tab', $code, $body );
		assert_true( cradle_admin_test_has_id( $body, 'cradle-admin-css-editor' ) );
		cradle_admin_test_remove_untrusted();
		cradle_admin_test_reset();
	}
);

cradle_test(
	'admin: raw CSS revisions are bounded, restorable and reset keeps history',
	function () {
		cradle_admin_test_reset();
		$admin = cradle_test_user( 'administrator' );
		wp_set_current_user( $admin );
		for ( $i = 1; $i <= 12; $i++ ) {
			$state = Custom_Css::save( ".v{$i}{order:{$i}}", Custom_Css::current()['revision'], $admin );
			assert_true( is_array( $state ), 'Save ' . $i . ' succeeds.' );
		}
		assert_same( 12, Custom_Css::current()['revision'] );
		$revisions = Custom_Css::revisions();
		assert_same( Custom_Css::MAX_REVISIONS, count( $revisions ), 'History is bounded.' );
		assert_same( 11, $revisions[0]['revision'], 'Newest previous version first.' );
		assert_same( '.v2{order:2}', end( $revisions )['css'], 'Oldest versions are dropped first.' );

		$restored = Custom_Css::restore( 5, $admin );
		assert_true( is_array( $restored ) );
		assert_same( '.v5{order:5}', Custom_Css::current()['css'], 'Any revision can be restored.' );
		assert_same( '.v12{order:12}', Custom_Css::revisions()[0]['css'], 'The replaced CSS joins the history.' );
		assert_wp_error( Custom_Css::restore( 999, $admin ), 'cradle_admin_css_missing' );

		// A stored revision that no longer meets policy cannot be restored.
		$history      = get_option( Custom_Css::REVISIONS_OPTION );
		$history[0]['css'] = '@import "x.css";';
		update_option( Custom_Css::REVISIONS_OPTION, $history, false );
		assert_wp_error( Custom_Css::restore( $history[0]['revision'], $admin ), 'cradle_admin_css_rejected' );

		// Restore over HTTP.
		$session  = cradle_test_cookies( $admin );
		$response = cradle_admin_test_post(
			'admin-post.php',
			array(
				'action'   => 'cradle_admin_css_restore',
				'revision' => 8,
				'_wpnonce' => cradle_test_nonce( $admin, $session, 'cradle_admin_css_restore' ),
			),
			$admin,
			$session
		);
		assert_contains( 'cradle-admin-css=restored', (string) wp_remote_retrieve_header( $response, 'location' ) );
		cradle_admin_test_flush();
		assert_same( '.v8{order:8}', Custom_Css::current()['css'] );

		// Reset over HTTP clears the active CSS but keeps it in history.
		$response = cradle_admin_test_post(
			'admin-post.php',
			array(
				'action'   => 'cradle_admin_css_reset',
				'_wpnonce' => cradle_test_nonce( $admin, $session, 'cradle_admin_css_reset' ),
			),
			$admin,
			$session
		);
		assert_contains( 'cradle-admin-css=reset', (string) wp_remote_retrieve_header( $response, 'location' ) );
		cradle_admin_test_flush();
		assert_same( '', Custom_Css::current()['css'] );
		assert_same( '.v8{order:8}', Custom_Css::revisions()[0]['css'], 'Reset keeps the previous CSS restorable.' );

		// Tampered storage: without a valid signature the CSS is validated again before output.
		update_option( Custom_Css::OPTION, array( 'css' => '@import "https://evil.example/x.css";', 'revision' => 99, 'modified' => time(), 'author' => $admin, 'signature' => 'forged' ), false );
		cradle_admin_test_flush();
		assert_same( '', Custom_Css::printable(), 'Tampered CSS is not printed.' );
		cradle_admin_test_reset();
	}
);

cradle_test(
	'admin: styles apply only on approved screens, never on the settings page or front end; per-user choice',
	function () {
		cradle_admin_test_reset();
		$admin   = cradle_test_user( 'administrator' );
		$session = cradle_test_cookies( $admin );
		delete_user_meta( $admin, Module::USER_META );
		cradle_admin_test_update(
			'admin-visual',
			array(
				'enabled'       => true,
				'screens'       => array( 'dashboard', 'content' ),
				'sidebar_width' => array( 'value' => 220, 'unit' => 'px' ),
			)
		);
		wp_set_current_user( $admin );
		Custom_Css::save( 'body.cradle-admin-custom-css #wpfooter{font-style:italic}', 0, $admin );
		cradle_admin_test_flush();

		list( $code, $body ) = cradle_admin_test_get( 'index.php', $admin, $session );
		cradle_admin_test_assert_clean_page( 'index.php', $code, $body );
		$classes = cradle_admin_test_body_classes( $body );
		assert_true( in_array( 'cradle-admin-skin', $classes, true ) && in_array( 'cradle-admin-layout', $classes, true ), 'Dashboard is styled.' );
		assert_true( cradle_admin_test_has_id( $body, 'cradle-admin-skin-css' ) );
		assert_contains( '--wp-admin-theme-color:#3858e9', $body );
		assert_contains( '--cradle-admin-sidebar-width:220px', $body );
		assert_true( cradle_admin_test_has_id( $body, 'cradle-admin-custom-css-inline-css' ) );
		assert_contains( 'font-style:italic', $body );

		list( $code, $body ) = cradle_admin_test_get( 'upload.php', $admin, $session );
		cradle_admin_test_assert_clean_page( 'upload.php', $code, $body );
		assert_false( in_array( 'cradle-admin-skin', cradle_admin_test_body_classes( $body ), true ), 'Media is not an approved screen here.' );
		assert_not_contains( 'cradle-admin-skin-css', $body );
		assert_not_contains( 'cradle-admin-custom-css-inline-css', $body );

		list( $code, $body ) = cradle_admin_test_get( 'admin.php?page=cradle-admin&tab=appearance', $admin, $session );
		cradle_admin_test_assert_clean_page( 'settings page', $code, $body );
		assert_not_contains( 'cradle-admin-skin-css', $body, 'The recovery screen is never styled.' );
		assert_not_contains( 'cradle-admin-custom-css-inline-css', $body );
		assert_contains( 'cradle-admin-preview', $body );

		$front = cradle_test_http( 'GET', home_url( '/' ), array(), $admin, $session );
		assert_not_contains( 'cradle-admin-skin', wp_remote_retrieve_body( $front ), 'The front end is untouched.' );

		// Per-user choice: default WordPress appearance for this account only.
		update_user_meta( $admin, Module::USER_META, '1' );
		list( , $body ) = cradle_admin_test_get( 'index.php', $admin, $session );
		assert_false( in_array( 'cradle-admin-skin', cradle_admin_test_body_classes( $body ), true ), 'Opted-out user sees the native appearance.' );
		assert_not_contains( 'cradle-admin-custom-css-inline-css', $body );
		cradle_admin_test_update( 'admin-visual', array( 'allow_user_choice' => false ) );
		list( , $body ) = cradle_admin_test_get( 'index.php', $admin, $session );
		assert_true( in_array( 'cradle-admin-skin', cradle_admin_test_body_classes( $body ), true ), 'The choice is ignored when not permitted.' );
		delete_user_meta( $admin, Module::USER_META );
		cradle_admin_test_reset();
	}
);

cradle_test(
	'admin: safe mode (cradle_admin/safe_mode filter, same path as CRADLE_ADMIN_SAFE_MODE) disables styling, branding and labels',
	function () {
		cradle_admin_test_reset();
		$admin = cradle_test_user( 'administrator' );
		wp_set_current_user( $admin );
		cradle_admin_test_update( 'admin-visual', array( 'enabled' => true ) );
		cradle_admin_test_update(
			'admin-branding',
			array(
				'brand_name'         => 'Acme Studio',
				'footer_text'        => 'Built by Acme',
				'admin_bar_branding' => true,
				'login_branding'     => true,
			)
		);
		cradle_admin_test_update(
			'admin-labels',
			array(
				'post_type_labels' => array( 'post' => array( 'name' => 'Articles' ) ),
				'text_overrides'   => array(
					array( 'domain' => 'default', 'context' => '', 'original' => 'Cradle safe mode probe %s', 'replacement' => 'Probe replaced %s', 'screen' => '' ),
				),
			)
		);
		Custom_Css::save( '.probe{order:1}', Custom_Css::current()['revision'], $admin );
		cradle_admin_test_flush();
		Labels::apply_registered_entities();

		$screen = WP_Screen::get( 'dashboard' );
		assert_false( Module::safe_mode() );
		assert_true( (bool) Skin::state( $screen )['classes'] );
		assert_contains( 'Built by Acme', Branding::footer_text( 'x' ) );
		assert_contains( 'Acme Studio', Branding::admin_title( 'Dashboard &lsaquo; Site &#8212; WordPress' ) );
		assert_same( 'Articles', get_post_type_object( 'post' )->labels->name );
		assert_same( 'Probe replaced %s', __( 'Cradle safe mode probe %s' ) ); // phpcs:ignore WordPress.WP.I18n
		assert_same( '.probe{order:1}', Custom_Css::printable() );
		assert_true( in_array( 'cradle-admin-login', Branding::login_body_class( array() ), true ) );

		add_filter( 'cradle_admin/safe_mode', '__return_true' );
		try {
			assert_true( Module::safe_mode() );
			Labels::apply_registered_entities();
			assert_same( array(), Skin::state( $screen )['classes'], 'No body classes in safe mode.' );
			assert_same( 'x', Branding::footer_text( 'x' ) );
			assert_same( 'Dashboard &lsaquo; Site &#8212; WordPress', Branding::admin_title( 'Dashboard &lsaquo; Site &#8212; WordPress' ) );
			assert_same( 'Posts', get_post_type_object( 'post' )->labels->name, 'Labels are restored.' );
			assert_same( 'Cradle safe mode probe %s', __( 'Cradle safe mode probe %s' ) ); // phpcs:ignore WordPress.WP.I18n
			assert_same( '', Custom_Css::printable(), 'Custom CSS is not printed.' );
			assert_same( array(), Branding::login_body_class( array() ) );
			assert_same( 'https://wordpress.org/', Branding::login_headerurl( 'https://wordpress.org/' ) );
			$bar = new WP_Admin_Bar();
			$bar->add_node( array( 'id' => 'wp-logo', 'title' => 'WP' ) );
			Branding::admin_bar( $bar );
			assert_true( null !== $bar->get_node( 'wp-logo' ) && null === $bar->get_node( 'cradle-brand' ), 'Admin bar untouched.' );
		} finally {
			remove_filter( 'cradle_admin/safe_mode', '__return_true' );
		}
		// The filter can only switch safe mode on.
		add_filter( 'cradle_admin/safe_mode', '__return_zero' );
		assert_false( Module::safe_mode() );
		remove_filter( 'cradle_admin/safe_mode', '__return_zero' );
		cradle_admin_test_reset();
		assert_same( 'Posts', get_post_type_object( 'post' )->labels->name );
	}
);

cradle_test(
	'admin: the real CRADLE_ADMIN_SAFE_MODE constant (defined before plugins load) restores the native admin',
	function () {
		cradle_admin_test_reset();
		$admin = cradle_test_user( 'administrator' );
		wp_set_current_user( $admin );
		cradle_admin_test_update( 'admin-visual', array( 'enabled' => true ) );
		cradle_admin_test_update(
			'admin-branding',
			array(
				'brand_name'         => 'Acme Studio',
				'footer_text'        => 'Built by Acme',
				'admin_bar_branding' => true,
				'login_branding'     => true,
			)
		);
		cradle_admin_test_update(
			'admin-labels',
			array(
				'post_type_labels' => array( 'post' => array( 'name' => 'Articles' ) ),
				'text_overrides'   => array(
					array( 'domain' => 'default', 'context' => '', 'original' => 'Cradle safe mode probe %s', 'replacement' => 'Probe replaced %s', 'screen' => '' ),
				),
			)
		);
		Custom_Css::save( '.probe{order:1}', Custom_Css::current()['revision'], $admin );
		cradle_admin_test_flush();

		$probe = function ( string $mode ): array {
			$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( CRADLE_TEST_ROOT . '/tests/fixtures/admin/safe-mode-probe.php' ) . ' ' . $mode . ' 2>&1';
			$out = (string) shell_exec( $cmd );
			$pos = strrpos( $out, '{"safe_mode"' );
			$data = false === $pos ? null : json_decode( substr( $out, $pos ), true );
			if ( ! is_array( $data ) ) {
				throw new Cradle_Test_Failure( 'Probe output was not JSON: ' . substr( $out, 0, 300 ) );
			}
			return $data;
		};

		$off = $probe( 'off' );
		assert_false( $off['safe_mode'] );
		assert_true( in_array( 'cradle-admin-skin', $off['classes'], true ), 'Baseline: skin active.' );
		assert_same( 'Articles', $off['post_label'] );
		assert_contains( 'Built by Acme', $off['footer'] );
		assert_same( '.probe{order:1}', $off['css'] );
		assert_same( 'Probe replaced %s', $off['text'] );
		assert_true( $off['brand_node'] );

		$on = $probe( 'on' );
		assert_true( $on['safe_mode'], 'The constant switches safe mode on.' );
		assert_same( array(), $on['classes'], 'No Cradle styling.' );
		assert_same( 'Posts', $on['post_label'], 'Registered labels, not overrides.' );
		assert_same( 'native footer', $on['footer'] );
		assert_same( '', $on['css'], 'No custom CSS.' );
		assert_same( 'Cradle safe mode probe %s', $on['text'] );
		assert_same( array(), $on['login'] );
		assert_false( $on['brand_node'], 'Native admin bar.' );

		cradle_admin_test_reset();
	}
);

cradle_test(
	'admin: label overrides apply to post type, taxonomy, menu items and supported strings',
	function () {
		cradle_admin_test_reset();
		$admin   = cradle_test_user( 'administrator' );
		$session = cradle_test_cookies( $admin );

		// Validation.
		$errors = cradle_admin_test_update(
			'admin-labels',
			array(
				'post_type_labels' => array( 'post' => array( 'name' => '<b>Bold</b>' ) ),
				'taxonomy_labels'  => array( 'no_such_tax' => array( 'name' => 'X' ) ),
				'menu_labels'      => array( 'plugins.php' => 'Ext<script>' ),
				'text_overrides'   => array( array( 'domain' => 'default', 'original' => 'Howdy, %s', 'replacement' => 'Hi', 'context' => '', 'screen' => '' ) ),
			)
		);
		assert_true( isset( $errors['post_type_labels'], $errors['taxonomy_labels'], $errors['menu_labels'], $errors['text_overrides'] ), 'Markup, unknown entities and placeholder mismatches are refused.' );
		$errors = cradle_admin_test_update(
			'admin-labels',
			array( 'text_overrides' => array( array( 'domain' => 'not-supported', 'original' => 'Posts', 'replacement' => 'Articles', 'context' => '', 'screen' => '' ) ) )
		);
		assert_true( isset( $errors['text_overrides'] ), 'Unsupported text domains are refused.' );

		$errors = cradle_admin_test_update(
			'admin-labels',
			array(
				'post_type_labels' => array(
					'post' => array(
						'name'          => 'Articles',
						'singular_name' => 'Article',
						'add_new_item'  => 'Write an article',
						'menu_name'     => '',
					),
				),
				'taxonomy_labels'  => array( 'category' => array( 'name' => 'Topics' ) ),
				'menu_labels'      => array( 'plugins.php' => 'Extensions' ),
				'text_overrides'   => array( array( 'domain' => 'default', 'original' => 'Howdy, %s', 'replacement' => 'Hello there, %s', 'context' => '', 'screen' => '' ) ),
			)
		);
		assert_same( array(), $errors );
		Labels::apply_registered_entities();
		$post = get_post_type_object( 'post' );
		assert_same( 'Articles', $post->labels->name );
		assert_same( 'Articles', $post->labels->menu_name, 'Menu name follows the plural name.' );
		assert_same( 'Article', $post->labels->singular_name );
		assert_same( 'Articles', $post->label );
		assert_same( 'Topics', get_taxonomy( 'category' )->labels->name );

		// Entities registered after boot are relabelled through registered_post_type.
		register_post_type( 'cradle_admin_t', array( 'show_ui' => true, 'label' => 'Things' ) );
		cradle_admin_test_update( 'admin-labels', array( 'post_type_labels' => array( 'cradle_admin_t' => array( 'name' => 'Widgets' ) ) + cradle()->settings()->values( 'admin-labels' )['post_type_labels'] ) );
		register_post_type( 'cradle_admin_t', array( 'show_ui' => true, 'label' => 'Things' ) );
		assert_same( 'Widgets', get_post_type_object( 'cradle_admin_t' )->labels->name );
		unregister_post_type( 'cradle_admin_t' );

		list( $code, $body ) = cradle_admin_test_get( 'edit.php', $admin, $session );
		cradle_admin_test_assert_clean_page( 'edit.php', $code, $body );
		assert_true( (bool) preg_match( '#<h1 class="wp-heading-inline">\s*Articles\s*</h1>#', $body ), 'List screen title uses the override.' );
		assert_contains( "<div class='wp-menu-name'>Articles</div>", $body, 'Menu uses the override.' );
		assert_contains( 'Hello there, ', $body, 'Supported string override applied in the admin bar.' );
		assert_contains( 'Extensions', $body, 'Menu label override applied.' );
		assert_true( (bool) preg_match( '/Extensions\s*<span class=["\']update-plugins/', $body ), 'The plugin update bubble is kept after the renamed label.' );

		list( $code, $body ) = cradle_admin_test_get( 'edit-tags.php?taxonomy=category', $admin, $session );
		cradle_admin_test_assert_clean_page( 'edit-tags.php', $code, $body );
		assert_contains( 'Topics', $body );

		list( $code, $body ) = cradle_admin_test_get( 'admin.php?page=cradle-admin&tab=labels', $admin, $session );
		cradle_admin_test_assert_clean_page( 'labels tab', $code, $body );
		assert_contains( 'placeholder="Posts"', $body, 'Registered labels are shown as placeholders.' );
		assert_contains( 'name="cradle_admin_labels_settings[menu_labels][plugins.php]" value="Extensions"', $body );

		$rest = cradle_test_http( 'GET', rest_url( 'wp/v2/types/post' ), array( 'headers' => array( 'X-WP-Nonce' => cradle_test_nonce( $admin, $session, 'wp_rest' ) ) ), $admin, $session );
		$data = json_decode( wp_remote_retrieve_body( $rest ), true );
		assert_same( 'Articles', $data['name'] ?? null, 'The block editor sees the override through REST.' );

		cradle_admin_test_reset();
		assert_same( 'Posts', get_post_type_object( 'post' )->labels->name, 'Reset restores registered labels.' );
		list( , $body ) = cradle_admin_test_get( 'edit.php', $admin, $session );
		assert_true( (bool) preg_match( '#<h1 class="wp-heading-inline">\s*Posts\s*</h1>#', $body ), 'Registered title is back.' );
	}
);

cradle_test(
	'admin: native screens (menus, media modal, notices, list tables, block editor) load with full branding enabled',
	function () {
		cradle_admin_test_reset();
		$admin   = cradle_test_user( 'administrator' );
		$session = cradle_test_cookies( $admin );
		$logo    = cradle_admin_test_logo();
		cradle_admin_test_update(
			'admin-branding',
			array(
				'brand_name'         => 'Acme Studio',
				'brand_url'          => 'https://acme.example.test/',
				'admin_logo'         => $logo,
				'footer_text'        => 'Built and supported by Acme',
				'support_links'      => array( array( 'label' => 'Acme support', 'url' => 'https://acme.example.test/support' ) ),
				'admin_bar_branding' => true,
				'welcome_enabled'    => true,
				'welcome_title'      => 'Welcome, team',
				'welcome_content'    => '<p>Start with <strong>Posts</strong>.</p>',
			)
		);
		cradle_admin_test_update(
			'admin-visual',
			array(
				'enabled'         => true,
				'accent_color'    => '#135e96',
				'menu_background' => '#10263a',
				'sidebar_width'   => array( 'value' => 200, 'unit' => 'px' ),
				'density'         => 'compact',
				'control_size'    => 'compact',
				'border_radius'   => array( 'value' => 6, 'unit' => 'px' ),
				'font_family'     => 'system-ui',
				'font_size'       => array( 'value' => 14, 'unit' => 'px' ),
				'logo_placement'  => 'both',
			)
		);
		wp_set_current_user( $admin );
		Custom_Css::save( 'body.cradle-admin-custom-css #wpfooter{letter-spacing:.01em}', 0, $admin );
		cradle_admin_test_flush();

		$pages = array( 'index.php', 'edit.php', 'edit.php?post_type=page', 'post-new.php', 'post-new.php?post_type=page', 'upload.php', 'upload.php?mode=grid', 'media-new.php', 'users.php', 'profile.php', 'edit-comments.php', 'edit-tags.php?taxonomy=category', 'options-general.php', 'plugins.php', 'themes.php', 'tools.php', 'update-core.php', 'admin.php?page=cradle', 'admin.php?page=cradle-admin' );
		$bodies = array();
		foreach ( $pages as $page ) {
			list( $code, $body ) = cradle_admin_test_get( $page, $admin, $session );
			cradle_admin_test_assert_clean_page( $page, $code, $body );
			$bodies[ $page ] = $body;
		}

		$dash = $bodies['index.php'];
		assert_contains( 'Built and supported by Acme', $dash, 'Footer text.' );
		assert_true( cradle_admin_test_has_id( $dash, 'wp-admin-bar-cradle-brand' ), 'Admin bar brand menu.' );
		assert_false( cradle_admin_test_has_id( $dash, 'wp-admin-bar-wp-logo' ), 'WordPress logo menu replaced.' );
		assert_true( cradle_admin_test_has_id( $dash, 'wp-admin-bar-cradle-brand-about-wordpress' ), 'About WordPress stays reachable.' );
		assert_contains( 'Acme support', $dash );
		assert_true( cradle_admin_test_has_id( $dash, 'cradle_admin_welcome' ), 'Welcome panel.' );
		assert_contains( '<strong>Posts</strong>', $dash );
		assert_contains( '&#8212; Acme Studio</title>', $dash, 'Branded document title.' );
		assert_contains( '--cradle-admin-logo:url(', $dash, 'Menu logo variable.' );
		assert_contains( 'class="cradle-admin-bar-logo"', $dash );
		assert_contains( 'update-plugins', $dash, 'Update count bubble still rendered.' );
		assert_true( cradle_admin_test_has_id( $dash, 'footer-upgrade' ), 'WordPress version footer kept.' );
		$classes = cradle_admin_test_body_classes( $dash );
		foreach ( array( 'cradle-admin-skin', 'cradle-admin-layout', 'cradle-admin-density-compact', 'cradle-admin-controls-compact', 'cradle-admin-font-family', 'cradle-admin-font-size', 'cradle-admin-logo', 'cradle-admin-custom-css' ) as $class ) {
			assert_true( in_array( $class, $classes, true ), "Dashboard body has {$class}." );
		}

		// List tables.
		assert_contains( 'wp-list-table', $bodies['edit.php'] );
		assert_contains( 'wp-list-table', $bodies['users.php'] );
		// Media library grid and modal templates remain.
		assert_true( cradle_admin_test_has_id( $bodies['upload.php?mode=grid'], 'tmpl-attachment' ), 'Media modal templates render.' );
		assert_true( cradle_admin_test_has_id( $bodies['upload.php?mode=grid'], 'tmpl-media-modal' ) );
		// Block editor: accent only, layout never changed.
		$editor = cradle_admin_test_body_classes( $bodies['post-new.php'] );
		assert_true( in_array( 'block-editor-page', $editor, true ), 'post-new.php is the block editor.' );
		assert_true( in_array( 'cradle-admin-skin', $editor, true ), 'Block editor gets the accent color.' );
		assert_false( in_array( 'cradle-admin-layout', $editor, true ), 'Block editor layout is untouched.' );
		assert_contains( 'wp-block-editor', $bodies['post-new.php'] );
		// Settings page stays native.
		assert_false( in_array( 'cradle-admin-skin', cradle_admin_test_body_classes( $bodies['admin.php?page=cradle-admin'] ), true ) );

		cradle_admin_test_reset();
		list( , $body ) = cradle_admin_test_get( 'index.php', $admin, $session );
		assert_not_contains( 'wp-admin-bar-cradle-brand', $body, 'Reset restores the native admin bar.' );
		assert_true( cradle_admin_test_has_id( $body, 'wp-admin-bar-wp-logo' ) );
		assert_not_contains( 'cradle-admin-skin', implode( ' ', cradle_admin_test_body_classes( $body ) ) );
		cradle_admin_test_remove_logo();
	}
);

cradle_test(
	'admin: WooCommerce screens load with the skin enabled',
	function () {
		if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			skip_test( 'WooCommerce is not installed/active in the sandbox; its screens were not verified.' );
		}
		cradle_admin_test_reset();
		$admin = cradle_test_user( 'administrator' );
		cradle_admin_test_update( 'admin-visual', array( 'enabled' => true, 'screens' => array_merge( Cradle\Admin\Screens::default_groups(), array( 'other' ) ) ) );
		foreach ( array( 'edit.php?post_type=product', 'post-new.php?post_type=product', 'edit.php?post_type=shop_order', 'admin.php?page=wc-settings', 'admin.php?page=wc-orders' ) as $page ) {
			list( $code, $body ) = cradle_admin_test_get( $page, $admin );
			cradle_admin_test_assert_clean_page( $page, $code, $body );
		}
		cradle_admin_test_reset();
	}
);

cradle_test(
	'admin: login page branding (hooks in process, wp-login.php over HTTP when reachable)',
	function () {
		cradle_admin_test_reset();
		$logo = cradle_admin_test_logo();
		cradle_admin_test_update(
			'admin-branding',
			array(
				'brand_name'       => 'Acme & Co',
				'brand_url'        => 'https://acme.example.test/',
				'login_branding'   => true,
				'login_logo'       => $logo,
				'login_background' => '#10263a',
				'login_text'       => '#ffffff',
				'login_accent'     => '#135e96',
			)
		);
		assert_same( 'https://acme.example.test/', Branding::login_headerurl( 'https://wordpress.org/' ) );
		assert_same( 'Acme &amp; Co', Branding::login_headertext( 'Powered by WordPress' ), 'Header text is escaped for wp-login.php.' );
		$classes = Branding::login_body_class( array( 'login' ) );
		assert_true( in_array( 'cradle-admin-login', $classes, true ) && in_array( 'cradle-admin-login--logo', $classes, true ) );
		assert_contains( 'Acme & Co', Branding::login_title( 'Log In &lsaquo; Site &#8212; WordPress' ) );
		Branding::login_enqueue();
		assert_true( wp_style_is( Branding::LOGIN_HANDLE, 'enqueued' ) );
		$inline = implode( '', (array) wp_styles()->get_data( Branding::LOGIN_HANDLE, 'after' ) );
		assert_contains( '--cradle-login-logo:url("http', $inline );
		assert_contains( '--wp-admin-theme-color:#135e96', $inline );
		assert_not_contains( '<', $inline );

		$response = cradle_test_http( 'GET', site_url( 'wp-login.php' ) );
		$code     = wp_remote_retrieve_response_code( $response );
		if ( 200 === $code && false !== strpos( wp_remote_retrieve_body( $response ), 'loginform' ) ) {
			$body = wp_remote_retrieve_body( $response );
			assert_true( cradle_admin_test_has_id( $body, 'cradle-admin-login-css' ) );
			assert_contains( 'cradle-admin-login', $body );
			assert_contains( 'href="https://acme.example.test/"', $body );
			assert_contains( 'Acme &amp; Co', $body );
		} else {
			// Cradle Login may move the login URL; the hooks above were still verified in process.
			echo '      note: wp-login.php returned ' . (int) $code . " (login URL likely customized); HTTP login check not run\n";
		}
		cradle_admin_test_reset();
		cradle_admin_test_remove_logo();
	}
);

cradle_test(
	'admin: profile appearance choice requires the edit_user capability and a nonce',
	function () {
		cradle_admin_test_reset();
		cradle_admin_test_update( 'admin-visual', array( 'enabled' => true ) );
		$sub      = cradle_test_user( 'subscriber' );
		$admin    = cradle_test_user( 'administrator' );
		$editor   = cradle_test_user( 'editor' );
		delete_user_meta( $sub, Module::USER_META );
		delete_user_meta( $admin, Module::USER_META );
		$ssession = cradle_test_cookies( $sub );
		$user     = get_userdata( $sub );
		list( $code, $body ) = cradle_admin_test_get( 'profile.php', $sub, $ssession );
		cradle_admin_test_assert_clean_page( 'profile.php (subscriber)', $code, $body );
		assert_true( cradle_admin_test_has_id( $body, 'cradle-admin-native-appearance' ), 'Subscribers see their own choice.' );
		$profile_post = function ( array $extra ) use ( $sub, $ssession, $user ) {
			return cradle_admin_test_post(
				'profile.php',
				array_merge(
					array(
						'action'       => 'update',
						'user_id'      => $sub,
						'_wpnonce'     => cradle_test_nonce( $sub, $ssession, 'update-user_' . $sub ),
						'email'        => $user->user_email,
						'nickname'     => $user->nickname,
						'display_name' => $user->display_name,
						'first_name'   => $user->first_name,
						'last_name'    => $user->last_name,
					),
					$extra
				),
				$sub,
				$ssession
			);
		};
		$profile_post( array( 'cradle_admin_native_appearance' => '1' ) );
		wp_cache_delete( $sub, 'user_meta' );
		assert_same( '', (string) get_user_meta( $sub, Module::USER_META, true ), 'Without the Cradle nonce nothing changes.' );
		$profile_post(
			array(
				'cradle_admin_native_appearance' => '1',
				'cradle_admin_profile_nonce'     => cradle_test_nonce( $sub, $ssession, 'cradle_admin_profile_' . $sub ),
			)
		);
		wp_cache_delete( $sub, 'user_meta' );
		assert_same( '1', (string) get_user_meta( $sub, Module::USER_META, true ), 'The user chose the default appearance.' );

		// An editor cannot change an administrator's choice (edit_user object check).
		$esession = cradle_test_cookies( $editor );
		cradle_admin_test_post(
			'user-edit.php',
			array(
				'action'                         => 'update',
				'user_id'                        => $admin,
				'_wpnonce'                       => cradle_test_nonce( $editor, $esession, 'update-user_' . $admin ),
				'cradle_admin_native_appearance' => '1',
				'cradle_admin_profile_nonce'     => cradle_test_nonce( $editor, $esession, 'cradle_admin_profile_' . $admin ),
			),
			$editor,
			$esession
		);
		wp_cache_delete( $admin, 'user_meta' );
		assert_same( '', (string) get_user_meta( $admin, Module::USER_META, true ), 'Editor cannot change another user.' );
		wp_set_current_user( $editor );
		$_POST['cradle_admin_profile_nonce']     = wp_create_nonce( 'cradle_admin_profile_' . $admin );
		$_POST['cradle_admin_native_appearance'] = '1';
		Cradle\Admin\Admin\Profile::save( $admin );
		unset( $_POST['cradle_admin_profile_nonce'], $_POST['cradle_admin_native_appearance'] );
		assert_same( '', (string) get_user_meta( $admin, Module::USER_META, true ), 'The handler itself checks edit_user.' );

		delete_user_meta( $sub, Module::USER_META );
		cradle_admin_test_reset();
	}
);

cradle_test(
	'admin: raw CSS is disabled (not editable, not printed) when unfiltered HTML is unavailable',
	function () {
		cradle_admin_test_reset();
		$admin = cradle_test_user( 'administrator' );
		wp_set_current_user( $admin );
		Custom_Css::save( '.x{order:2}', 0, $admin );
		cradle_admin_test_update( 'admin-visual', array( 'enabled' => true ) );
		assert_same( '.x{order:2}', Custom_Css::printable() );
		add_filter( 'cradle_admin/raw_css_available', '__return_false' );
		try {
			assert_false( Custom_Css::environment_allows() );
			assert_false( Custom_Css::user_can_edit( $admin ) );
			assert_same( '', Custom_Css::printable(), 'Stored CSS is kept but not printed.' );
			assert_wp_error( Custom_Css::save( '.y{order:3}', Custom_Css::current()['revision'], $admin ), 'cradle_admin_css_forbidden' );
			$state = Skin::state( WP_Screen::get( 'dashboard' ) );
			assert_true( in_array( 'cradle-admin-skin', $state['classes'], true ), 'Structured controls remain.' );
			assert_false( in_array( 'cradle-admin-custom-css', $state['classes'], true ) );
		} finally {
			remove_filter( 'cradle_admin/raw_css_available', '__return_false' );
		}
		assert_same( '.x{order:2}', Custom_Css::current()['css'], 'Nothing was deleted.' );
		cradle_admin_test_reset();
	}
);

cradle_test(
	'admin: module export/import round trip is validated and capability-checked',
	function () {
		cradle_admin_test_reset();
		$admin = cradle_test_user( 'administrator' );
		wp_set_current_user( $admin );
		cradle_admin_test_update( 'admin-branding', array( 'brand_name' => 'Exported brand' ) );
		$export = Module::export();
		assert_same( 'Exported brand', $export['sections']['admin-branding']['brand_name'] );
		cradle_admin_test_reset();
		wp_set_current_user( $admin );
		$export['sections']['admin-visual']['accent_color'] = 'not-a-color';
		$result = Module::import( $export );
		assert_wp_error( $result, 'cradle_admin_import_partial', 'Invalid imported values are reported.' );
		cradle_admin_test_flush();
		assert_same( 'Exported brand', cradle()->settings()->get( 'admin-branding', 'brand_name' ) );
		assert_same( '#3858e9', cradle()->settings()->get( 'admin-visual', 'accent_color' ) );
		wp_set_current_user( cradle_test_user( 'editor' ) );
		assert_wp_error( Module::import( $export ), 'cradle_admin_forbidden' );
		cradle_admin_test_reset();
	}
);

cradle_test(
	'admin: lifecycle — activation idempotent, deactivation keeps data, uninstall retains by default and purges on request',
	function () {
		cradle_admin_test_reset();
		$admin = cradle_test_user( 'administrator' );
		Lifecycle::activate( false );
		Lifecycle::activate( false );
		assert_true( get_role( 'administrator' )->has_cap( 'cradle_manage_branding' ) );
		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Custom_Css::OPTION ) );
		assert_true( in_array( $autoload, array( 'off', 'no' ), true ), 'Raw CSS option is not autoloaded.' );

		cradle_admin_test_update( 'admin-branding', array( 'brand_name' => 'Kept' ) );
		update_user_meta( $admin, Module::USER_META, '1' );
		Lifecycle::deactivate();
		cradle_admin_test_flush();
		assert_same( 'Kept', cradle()->settings()->get( 'admin-branding', 'brand_name' ), 'Deactivation keeps data.' );

		$runner = CRADLE_TEST_ROOT . '/tests/fixtures/admin/run-uninstall.php';
		$php    = escapeshellarg( PHP_BINARY );
		$out    = shell_exec( $php . ' ' . escapeshellarg( $runner ) . ' 2>&1' );
		assert_contains( 'uninstall-ran', (string) $out );
		cradle_admin_test_flush();
		assert_same( 'Kept', cradle()->settings()->get( 'admin-branding', 'brand_name' ), 'Uninstall retains data by default.' );

		$policies          = get_option( 'cradle_uninstall_policy', array() );
		$policies          = is_array( $policies ) ? $policies : array();
		$policies['admin'] = 'purge';
		update_option( 'cradle_uninstall_policy', $policies, false );
		try {
			$out = shell_exec( $php . ' ' . escapeshellarg( $runner ) . ' 2>&1' );
			assert_contains( 'uninstall-ran', (string) $out );
			cradle_admin_test_flush();
			wp_cache_delete( $admin, 'user_meta' );
			assert_false( get_option( 'cradle_admin_branding_settings', false ), 'Purge removes owned options.' );
			assert_false( get_option( Custom_Css::OPTION, false ) );
			assert_same( '', (string) get_user_meta( $admin, Module::USER_META, true ), 'Purge removes owned user meta.' );
			wp_roles()->for_site();
			assert_false( get_role( 'administrator' )->has_cap( 'cradle_manage_branding' ), 'Purge revokes the capability.' );
			$after = get_option( 'cradle_uninstall_policy', array() );
			assert_false( isset( $after['admin'] ), 'The purge choice is consumed.' );
		} finally {
			// Restore the installed state exactly as activation would.
			$policies = get_option( 'cradle_uninstall_policy', array() );
			if ( is_array( $policies ) && isset( $policies['admin'] ) ) {
				unset( $policies['admin'] );
				update_option( 'cradle_uninstall_policy', $policies, false );
			}
			$granted = get_option( 'cradle_granted_capabilities', array() );
			if ( is_array( $granted ) ) {
				unset( $granted['cradle_manage_branding'] );
				update_option( 'cradle_granted_capabilities', $granted, false );
			}
			Lifecycle::activate( false );
			delete_user_meta( $admin, Module::USER_META );
		}
		assert_true( get_role( 'administrator' )->has_cap( 'cradle_manage_branding' ), 'Capability restored after the purge test.' );
		cradle_admin_test_reset();
	}
);

cradle_test(
	'admin: runtime guard when core is missing (no fatal, nothing applied, admin notice)',
	function () {
		$cmd  = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( CRADLE_TEST_ROOT . '/tests/fixtures/admin/core-missing-probe.php' ) . ' 2>&1';
		$out  = (string) shell_exec( $cmd );
		$pos  = strrpos( $out, '{"core_loaded"' );
		$data = false === $pos ? null : json_decode( substr( $out, $pos ), true );
		assert_true( is_array( $data ), 'Probe output was not JSON: ' . substr( $out, 0, 300 ) );
		assert_not_contains( 'Fatal error', $out );
		assert_false( $data['core_loaded'], 'Core was hidden for the probe process.' );
		assert_true( $data['admin_plugin_loaded'], 'cradle-admin still loads.' );
		assert_false( $data['module_class_loaded'], 'The module never boots without core.' );
		assert_false( $data['body_class_filtered'], 'No styling hooks without core.' );
		assert_true( $data['notice'], 'Administrators see the core-missing notice.' );
	}
);

cradle_test(
	'admin: no PHP notices, warnings or errors from cradle-admin in debug.log',
	function () {
		$file = WP_CONTENT_DIR . '/debug.log';
		if ( ! is_file( $file ) ) {
			return;
		}
		clearstatcache( true, $file );
		$fh = fopen( $file, 'rb' );
		fseek( $fh, (int) $GLOBALS['cradle_admin_test_log_offset'] );
		$new = (string) stream_get_contents( $fh );
		fclose( $fh );
		$lines = array_filter(
			explode( "\n", $new ),
			static function ( $line ) {
				return (bool) preg_match( '#plugins[/\\\\]cradle-admin[/\\\\]#', $line );
			}
		);
		assert_same( array(), array_values( $lines ), 'cradle-admin wrote to debug.log.' );
	}
);

cradle_test(
	'admin: final state is neutral (white-label off) with the plugin active',
	function () {
		cradle_admin_test_reset();
		cradle_admin_test_remove_logo();
		cradle_admin_test_remove_untrusted();
		foreach ( array( 'administrator', 'editor', 'subscriber' ) as $role ) {
			delete_user_meta( cradle_test_user( $role ), Module::USER_META );
			delete_transient( Custom_Css::draft_key( cradle_test_user( $role ) ) );
		}
		foreach ( Module::sections() as $section ) {
			assert_false( get_option( cradle()->settings()->section( $section )['option'], false ), "{$section} holds no stored overrides." );
		}
		assert_same( '', Custom_Css::current()['css'] );
		assert_same( array(), Custom_Css::revisions() );
		assert_true( is_plugin_active( 'cradle-admin/cradle-admin.php' ) );
		assert_same( 'ready', cradle()->modules()->state( 'admin' ) );
	}
);
