<?php
/**
 * Cradle Social integration tests (northstar §19, acceptance CR-22).
 *
 * Fixtures are posts marked `_cradle_test_fixture = social`; they are deleted and the
 * `social` settings section is reset to defaults at shutdown (and by the last test).
 * No external requests are made: provider URLs are only built and inspected.
 */

use Cradle\Social\Config;
use Cradle\Social\Icons;
use Cradle\Social\Providers;

$GLOBALS['cradle_social_fixtures'] = array();

function cradle_social_ready() {
	if ( ! is_plugin_active( 'cradle-social/cradle-social.php' ) || ! function_exists( 'cradle' ) || ! cradle() || ! cradle()->modules()->is_ready( 'social' ) ) {
		skip_test( 'cradle-social is not active and ready.' );
	}
}

function cradle_social_admin(): int {
	$admin = cradle_test_user( 'administrator' );
	wp_set_current_user( $admin );
	return $admin;
}

/**
 * Fresh settings values (HTTP requests change the option outside this process' cache).
 */
function cradle_social_values(): array {
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( Config::OPTION, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	return cradle()->settings()->values( 'social' );
}

/**
 * Resets the section and applies values as an administrator.
 */
function cradle_social_configure( array $values, bool $reset = true ): array {
	cradle_social_admin();
	if ( $reset ) {
		cradle()->settings()->reset( 'social' );
	}
	Providers::flush();
	$out = cradle()->settings()->update( 'social', $values );
	cradle_social_values();
	return is_array( $out ) ? $out : array( 'errors' => array( 'wp_error' => $out->get_error_message() ) );
}

function cradle_social_post( array $args ): int {
	$id = wp_insert_post(
		array_merge(
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
				'post_author' => cradle_test_user( 'administrator' ),
			),
			$args
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		throw new Cradle_Test_Failure( 'Could not create fixture: ' . $id->get_error_message() );
	}
	update_post_meta( $id, '_cradle_test_fixture', 'social' );
	$GLOBALS['cradle_social_fixtures'][] = (int) $id;
	return (int) $id;
}

function cradle_social_profiles_fixture(): array {
	return array(
		array( 'service' => 'facebook', 'url' => 'https://www.facebook.com/cradle.example', 'enabled' => true ),
		array( 'service' => 'x', 'url' => 'https://x.com/cradle_example', 'enabled' => true ),
		array( 'service' => 'linkedin', 'url' => 'https://www.linkedin.com/company/cradle-example', 'enabled' => true ),
		array( 'service' => 'bluesky', 'url' => 'https://bsky.app/profile/cradle.example', 'enabled' => true ),
		array( 'service' => 'mastodon', 'url' => 'https://mastodon.example/@cradle', 'enabled' => true ),
		array( 'service' => 'instagram', 'url' => 'https://www.instagram.com/cradle.example/', 'enabled' => true ),
		array( 'service' => 'youtube', 'url' => 'https://www.youtube.com/@cradle-example', 'enabled' => true ),
		array( 'service' => 'email', 'url' => 'mailto:hello@example.com', 'enabled' => true ),
	);
}

/**
 * Attribute values of every tag with $attr, in document order (entities decoded by the tag processor).
 */
function cradle_social_attrs( string $html, string $tag, string $attr ): array {
	$out = array();
	$p   = new WP_HTML_Tag_Processor( $html );
	while ( $p->next_tag( array( 'tag_name' => $tag ) ) ) {
		$value = $p->get_attribute( $attr );
		if ( null !== $value ) {
			$out[] = true === $value ? '' : (string) $value;
		}
	}
	return $out;
}

/**
 * Provider item IDs in rendered order (from cradle-social__item--{id} classes).
 */
function cradle_social_order( string $html ): array {
	preg_match_all( '/cradle-social__item cradle-social__item--([a-z0-9-]+)/', $html, $m );
	return $m[1];
}

function cradle_social_debug_log_size(): int {
	$file = WP_CONTENT_DIR . '/debug.log';
	clearstatcache( true, $file );
	return is_file( $file ) ? (int) filesize( $file ) : 0;
}

function cradle_social_debug_log_since( int $offset ): string {
	$file = WP_CONTENT_DIR . '/debug.log';
	clearstatcache( true, $file );
	if ( ! is_file( $file ) || filesize( $file ) <= $offset ) {
		return '';
	}
	return (string) file_get_contents( $file, false, null, $offset ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}

register_shutdown_function(
	static function () {
		foreach ( array_unique( $GLOBALS['cradle_social_fixtures'] ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		if ( function_exists( 'cradle' ) && cradle() && cradle()->settings()->section( 'social' ) ) {
			cradle()->settings()->reset( 'social' );
		}
	}
);

cradle_test(
	'social: module ready with capability, settings section, components, blocks and providers',
	function () {
		cradle_social_ready();
		assert_same( 'ready', cradle()->modules()->state( 'social' ) );
		assert_true( (bool) cradle()->settings()->section( 'social' ), 'Settings section registered.' );
		assert_same( 'cradle_manage_social', cradle()->settings()->section( 'social' )['capability'] );
		assert_true( get_role( 'administrator' )->has_cap( 'cradle_manage_social' ), 'Administrators hold the capability.' );
		assert_false( get_role( 'editor' )->has_cap( 'cradle_manage_social' ), 'Editors do not.' );
		assert_false( get_role( 'subscriber' )->has_cap( 'cradle_manage_social' ), 'Subscribers do not.' );
		$registry = WP_Block_Type_Registry::get_instance();
		foreach ( array( 'cradle/social-links', 'cradle/share-links' ) as $name ) {
			assert_true( (bool) cradle()->components()->get( $name ), "Component {$name} registered." );
			assert_true( $registry->is_registered( $name ), "Block {$name} registered." );
			assert_same( 3, $registry->get_registered( $name )->api_version );
			assert_true( is_callable( $registry->get_registered( $name )->render_callback ), 'Dynamic block renders server-side.' );
			assert_true( in_array( 'cradle-social', $registry->get_registered( $name )->style_handles, true ), 'Block style handle declared.' );
		}
		Providers::flush();
		assert_same( array(), Providers::problems(), 'Built-in providers validate.' );
		foreach ( array( 'facebook', 'x', 'linkedin', 'bluesky', 'whatsapp', 'telegram', 'reddit', 'pinterest', 'email' ) as $id ) {
			assert_true( null !== Providers::get( $id )['share'], "{$id} can share." );
		}
		foreach ( array( 'mastodon', 'instagram', 'youtube' ) as $id ) {
			assert_same( null, Providers::get( $id )['share'], "{$id} is profile-only." );
			assert_true( null !== Providers::get( $id )['profile'] );
		}
		assert_true( in_array( 'copy', Providers::share_ids(), true ) && in_array( 'native', Providers::share_ids(), true ) );
	}
);

cradle_test(
	'social: settings accept valid https profile URLs on the provider hosts (list and form shapes)',
	function () {
		cradle_social_ready();
		$profiles = cradle_social_profiles_fixture();
		$profiles[0]['url'] = 'https://WWW.Facebook.com/cradle.example';
		$profiles[7]['url'] = 'hello@example.com';
		$out = cradle_social_configure( array( 'profiles' => $profiles ) );
		assert_same( array(), $out['errors'], 'Valid profile URLs are accepted.' );
		$stored = cradle_social_values()['profiles'];
		assert_same( 8, count( $stored ) );
		assert_same( 'https://www.facebook.com/cradle.example', $stored[0]['url'], 'Host is normalized to lowercase.' );
		assert_same( 'mailto:hello@example.com', $stored[7]['url'], 'Email is normalized to mailto:.' );
		assert_same( 'https://mastodon.example/@cradle', $stored[4]['url'], 'Mastodon accepts any public instance with an /@name path.' );

		// Admin form shape: order numbers decide the order; unticked rows keep their URL but are not shown.
		$out = cradle()->settings()->update(
			'social',
			array(
				'profiles' => array(
					'__form'   => '1',
					'facebook' => array( 'enabled' => '1', 'order' => '3', 'url' => 'https://facebook.com/cradle.example' ),
					'x'        => array( 'enabled' => '1', 'order' => '1', 'url' => 'https://x.com/cradle_example' ),
					'linkedin' => array( 'order' => '2', 'url' => 'https://linkedin.com/company/cradle-example' ),
					'youtube'  => array( 'order' => '4', 'url' => '' ),
				),
			)
		);
		assert_same( array(), $out['errors'] );
		$stored = cradle_social_values()['profiles'];
		assert_same( array( 'x', 'linkedin', 'facebook' ), array_column( $stored, 'service' ) );
		assert_same( array( true, false, true ), array_column( $stored, 'enabled' ) );
		assert_same( array( 'x', 'facebook' ), array_column( Config::enabled_profiles(), 'service' ) );

		$out = cradle()->settings()->update( 'social', array( 'profiles' => array( 'instagram' => array( 'enabled' => '1', 'order' => '1', 'url' => '' ) ) ) );
		assert_true( isset( $out['errors']['profiles'] ), 'Enabling a profile without an address is refused.' );

		// Core JSON export → import round trip (list shapes) validates without errors.
		$export = cradle()->settings()->export( 'social' );
		cradle()->settings()->reset( 'social' );
		$out = cradle()->settings()->update( 'social', $export );
		assert_same( array(), $out['errors'], 'Exported configuration imports cleanly.' );
		assert_same( $export, cradle()->settings()->export( 'social' ) );
	}
);

cradle_test(
	'social: settings reject javascript:, non-https, off-host and look-alike profile URLs',
	function () {
		cradle_social_ready();
		$baseline = array( array( 'service' => 'facebook', 'url' => 'https://www.facebook.com/cradle.example', 'enabled' => true ) );
		cradle_social_configure( array( 'profiles' => $baseline ) );
		$bad = array(
			array( 'facebook', 'javascript:alert(1)' ),
			array( 'facebook', 'JaVaScRiPt:alert(document.cookie)//https://facebook.com/x' ),
			array( 'facebook', 'http://www.facebook.com/cradle.example' ),
			array( 'facebook', 'https://evil.example/www.facebook.com/cradle' ),
			array( 'facebook', 'https://www.facebook.com.evil.example/cradle' ),
			array( 'facebook', 'https://evilfacebook.com/cradle' ),
			array( 'facebook', 'https://user:pass@www.facebook.com/cradle' ),
			array( 'facebook', 'https://www.facebook.com:8443/cradle' ),
			array( 'facebook', 'https://www.facebook.com/' ),
			array( 'facebook', '//www.facebook.com/cradle' ),
			array( 'facebook', 'data:text/html,<script>alert(1)</script>' ),
			array( 'facebook', 'https://www.facebook.com/cradle"onmouseover="alert(1)' ),
			array( 'facebook', 'https://www.facebook.com/cradle#frag' ),
			array( 'x', 'https://x.com.evil.example/cradle' ),
			array( 'linkedin', 'https://www.linkedin.com.evil.example/in/x' ),
			array( 'bluesky', 'https://bsky.app/search?q=cradle' ),
			array( 'mastodon', 'https://127.0.0.1/@cradle' ),
			array( 'mastodon', 'https://mastodon.example/about' ),
			array( 'mastodon', 'http://mastodon.example/@cradle' ),
			array( 'email', 'mailto:hello@example.com?bcc=victim@example.com' ),
			array( 'email', 'hello@example.com,other@example.com' ),
			array( 'email', 'javascript:alert(1)' ),
		);
		foreach ( $bad as $case ) {
			list( $service, $url ) = $case;
			assert_wp_error( Providers::validate_profile_url( $service, $url ), '', "{$service} must reject {$url}." );
			$out = cradle()->settings()->update( 'social', array( 'profiles' => array( array( 'service' => $service, 'url' => $url, 'enabled' => true ) ) ) );
			assert_true( isset( $out['errors']['profiles'] ), "Settings must report an error for {$url}." );
			assert_same( $baseline, cradle_social_values()['profiles'], "Rejected {$url} must not change the stored profiles." );
		}
		$out = cradle()->settings()->update( 'social', array( 'profiles' => array( array( 'service' => 'evil', 'url' => 'https://evil.example/x', 'enabled' => true ) ) ) );
		assert_true( isset( $out['errors']['profiles'] ), 'Unknown services are refused.' );
		$out = cradle()->settings()->update( 'social', array( 'share_services' => array( 'facebook', 'myspace' ) ) );
		assert_true( isset( $out['errors']['share_services'] ), 'Unknown share services are refused.' );
		$out = cradle()->settings()->update( 'social', array( 'color_text' => 'red;background:url(https://evil.example/x)' ) );
		assert_true( isset( $out['errors']['color_text'] ), 'Colors are hex or preset tokens only.' );
	}
);

cradle_test(
	'social: editor and subscriber are denied (settings API, settings page and options.php)',
	function () {
		cradle_social_ready();
		cradle_social_configure( array( 'share_title' => 'Original {title}' ) );
		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			$user = cradle_test_user( $role );
			wp_set_current_user( $user );
			assert_wp_error( cradle()->settings()->update( 'social', array( 'share_title' => 'Hacked' ) ), 'cradle_forbidden', "{$role} must not update settings." );
			$r = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-social' ), array(), $user );
			assert_true( 200 !== wp_remote_retrieve_response_code( $r ) || false !== strpos( wp_remote_retrieve_body( $r ), 'not allowed' ), "{$role} cannot open the settings page." );
			assert_not_contains( 'cradle_social_settings[profiles]', wp_remote_retrieve_body( $r ) );

			// A valid nonce for the option group does not grant the capability.
			$session  = cradle_test_cookies( $user );
			$nonce    = cradle_test_nonce( $user, $session, 'cradle_social-options' );
			$revision = cradle()->settings()->revision( 'social' );
			cradle_test_http(
				'POST',
				admin_url( 'options.php' ),
				array(
					'body' => array(
						'option_page'            => 'cradle_social',
						'action'                 => 'update',
						'_wpnonce'               => $nonce,
						'cradle_social_settings' => array(
							'_revision'   => $revision,
							'share_title' => 'Hacked by ' . $role,
						),
					),
				),
				$user,
				$session
			);
			assert_same( 'Original {title}', cradle_social_values()['share_title'], "{$role} POST to options.php must not change settings." );
		}

		// Positive control: an administrator with a nonce saves through the same path.
		$admin    = cradle_test_user( 'administrator' );
		$session  = cradle_test_cookies( $admin );
		$nonce    = cradle_test_nonce( $admin, $session, 'cradle_social-options' );
		$revision = cradle()->settings()->revision( 'social' );
		$r        = cradle_test_http(
			'POST',
			admin_url( 'options.php' ),
			array(
				'body' => array(
					'option_page'            => 'cradle_social',
					'action'                 => 'update',
					'_wpnonce'               => $nonce,
					'_wp_http_referer'       => '/cradle/wp/wp-admin/admin.php?page=cradle-social',
					'cradle_social_settings' => array(
						'_revision'      => $revision,
						'share_title'    => 'Saved {title}',
						// Exactly the shapes the settings tables submit (unticked boxes are absent).
						'profiles'       => array(
							'__form'   => '1',
							'facebook' => array( 'order' => '2', 'url' => 'https://www.facebook.com/cradle.example' ),
							'linkedin' => array( 'enabled' => '1', 'order' => '1', 'url' => 'https://www.linkedin.com/company/cradle-example' ),
							'x'        => array( 'order' => '3', 'url' => '' ),
						),
						'share_services' => array(
							'__form' => '1',
							'x'      => array( 'enabled' => '1', 'order' => '2' ),
							'copy'   => array( 'enabled' => '1', 'order' => '1' ),
							'email'  => array( 'order' => '3' ),
						),
					),
				),
			),
			$admin,
			$session
		);
		assert_same( 302, wp_remote_retrieve_response_code( $r ) );
		$values = cradle_social_values();
		assert_same( 'Saved {title}', $values['share_title'], 'Administrator form save works.' );
		assert_same( array( 'linkedin', 'facebook' ), array_column( $values['profiles'], 'service' ), 'Profile table rows saved in order.' );
		assert_same( array( true, false ), array_column( $values['profiles'], 'enabled' ) );
		assert_same( array( 'copy', 'x' ), $values['share_services'], 'Share table saved as an ordered list of ticked services.' );

		// Administrator without a nonce: refused.
		cradle_test_http(
			'POST',
			admin_url( 'options.php' ),
			array(
				'body' => array(
					'option_page'            => 'cradle_social',
					'action'                 => 'update',
					'cradle_social_settings' => array(
						'_revision'   => cradle()->settings()->revision( 'social' ),
						'share_title' => 'No nonce',
					),
				),
			),
			$admin
		);
		assert_same( 'Saved {title}', cradle_social_values()['share_title'], 'A request without a nonce is refused.' );
	}
);

cradle_test(
	'social: settings page renders profile/share tables and theme color tokens for administrators',
	function () {
		cradle_social_ready();
		cradle_social_configure( array( 'profiles' => cradle_social_profiles_fixture() ) );
		$r    = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-social' ), array(), cradle_test_user( 'administrator' ) );
		$body = wp_remote_retrieve_body( $r );
		assert_same( 200, wp_remote_retrieve_response_code( $r ) );
		assert_contains( 'name="cradle_social_settings[profiles][facebook][url]"', $body );
		assert_contains( 'value="https://www.facebook.com/cradle.example"', $body );
		assert_contains( 'name="cradle_social_settings[share_services][copy][enabled]"', $body );
		assert_contains( 'name="cradle_social_settings[share_title]"', $body );
		assert_contains( 'value="preset:', $body, 'Theme palette tokens are offered.' );
		assert_not_contains( '<script src="https://', $body );
	}
);

cradle_test(
	'social: share links for a published post carry correctly encoded title and URL',
	function () {
		cradle_social_ready();
		cradle_social_configure(
			array(
				'share_title'    => '{title}',
				'share_text'     => 'Read: {title} | {site}',
				'share_services' => array( 'facebook', 'x', 'linkedin', 'bluesky', 'whatsapp', 'telegram', 'reddit', 'pinterest', 'email' ),
			)
		);
		$title   = 'Tom & Jerry\'s "Quote" <b>bold</b> ü #1 ?a=1&b=2 100%';
		$post_id = cradle_social_post( array( 'post_title' => wp_slash( $title ), 'post_name' => 'cradle-social-encoding-fixture' ) );
		$url     = get_permalink( $post_id );
		$plain   = 'Tom & Jerry\'s "Quote" bold ü #1 ?a=1&b=2 100%';
		$site    = html_entity_decode( wp_strip_all_tags( get_bloginfo( 'name' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text    = 'Read: ' . $plain . ' | ' . $site;

		$html  = cradle_render_component( 'cradle/share-links', array( 'postId' => $post_id ) );
		$hrefs = cradle_social_attrs( $html, 'a', 'href' );
		$e     = 'rawurlencode';
		$expected = array(
			'https://www.facebook.com/sharer/sharer.php?u=' . $e( $url ),
			'https://x.com/intent/tweet?text=' . $e( $text ) . '&url=' . $e( $url ),
			'https://www.linkedin.com/sharing/share-offsite/?url=' . $e( $url ),
			'https://bsky.app/intent/compose?text=' . $e( $text . ' ' . $url ),
			'https://wa.me/?text=' . $e( $text . ' ' . $url ),
			'https://t.me/share/url?url=' . $e( $url ) . '&text=' . $e( $text ),
			'https://www.reddit.com/submit?url=' . $e( $url ) . '&title=' . $e( $plain ),
			'https://www.pinterest.com/pin/create/button/?url=' . $e( $url ) . '&description=' . $e( $text ),
			'mailto:?subject=' . $e( $plain ) . '&body=' . $e( $text . ' ' . $url ),
		);
		assert_same( $expected, $hrefs, 'Share URLs are fixed templates with rawurlencoded parameters.' );
		assert_contains( '&#038;url=', $html, 'Ampersands in hrefs are HTML-escaped.' );
		assert_not_contains( '"Quote"', $html, 'Quotes never appear raw in markup.' );
		assert_not_contains( '<b>', $html );
		assert_contains( 'Share on X', $html, 'Accessible label.' );
		assert_contains( 'Share by email', $html );
	}
);

cradle_test(
	'social: share placeholders are bounded and cannot inject markup',
	function () {
		cradle_social_ready();
		cradle_social_configure( array( 'share_text' => 'Hello {title}' ) );
		$out = cradle()->settings()->update( 'social', array( 'share_text' => '{title} by {author}' ) );
		assert_true( isset( $out['errors']['share_text'] ), 'Unsupported placeholders are refused.' );
		$out = cradle()->settings()->update( 'social', array( 'share_title' => '{{title}}' ) );
		assert_true( isset( $out['errors']['share_title'] ), 'Nested braces are refused.' );
		assert_same( 'Hello {title}', cradle_social_values()['share_text'] );

		$out = cradle()->settings()->update( 'social', array( 'share_text' => '"><img src=x onerror=alert(1)><script>alert(2)</script>{title} on {site}' ) );
		assert_same( array(), $out['errors'] );
		$stored = cradle_social_values()['share_text'];
		assert_false( str_contains( $stored, '<' ), 'Markup is stripped before the value is stored: ' . $stored );

		$post_id = cradle_social_post( array( 'post_title' => wp_slash( '"><script>alert(3)</script>{url} {site} <img src=x onerror=alert(4)>' ) ) );
		$html    = cradle_render_component( 'cradle/share-links', array( 'postId' => $post_id, 'services' => array( 'x', 'native', 'copy' ) ) );
		foreach ( array( '<script', '<img', 'onerror', '">alert', '"alert' ) as $needle ) {
			assert_not_contains( $needle, $html, 'Rendered share markup must stay inert.' );
		}
		$titles = cradle_social_attrs( $html, 'button', 'data-cradle-social-title' );
		assert_same( array( '">{url} {site}' ), $titles, 'Placeholders inside a title are not expanded again (single pass).' );
		$x = cradle_social_attrs( $html, 'a', 'href' )[0];
		assert_contains( rawurlencode( '{url}' ), $x, 'Literal braces from the title stay encoded text.' );

		// A tampered stored option (invalid placeholder + markup) falls back to {title} at render time.
		$stored_option                = get_option( Config::OPTION );
		$stored_option['share_text']  = '<b>{title}</b>{evil}';
		$stored_option['color_text']  = 'red;background:url(https://evil.example/x)';
		update_option( Config::OPTION, $stored_option );
		$html = cradle_render_component( 'cradle/share-links', array( 'postId' => $post_id, 'services' => array( 'native' ) ) );
		assert_not_contains( '<b>', $html );
		assert_not_contains( '{evil}', $html );
		assert_not_contains( 'evil.example', $html, 'Tampered color values never reach inline styles.' );
		cradle()->settings()->reset( 'social' );
	}
);

cradle_test(
	'social: ordering is respected for profiles, share services and per-block overrides',
	function () {
		cradle_social_ready();
		$out = cradle_social_configure(
			array(
				'profiles'       => array(
					'__form'    => '1',
					'facebook'  => array( 'enabled' => '1', 'order' => '30', 'url' => 'https://www.facebook.com/cradle.example' ),
					'linkedin'  => array( 'enabled' => '1', 'order' => '10', 'url' => 'https://www.linkedin.com/company/cradle-example' ),
					'email'     => array( 'enabled' => '1', 'order' => '20', 'url' => 'hello@example.com' ),
					'instagram' => array( 'order' => '5', 'url' => 'https://www.instagram.com/cradle.example/' ),
				),
				'share_services' => array(
					'__form'   => '1',
					'email'    => array( 'enabled' => '1', 'order' => '1' ),
					'copy'     => array( 'enabled' => '1', 'order' => '2' ),
					'reddit'   => array( 'enabled' => '1', 'order' => '3' ),
					'facebook' => array( 'order' => '0' ),
				),
			)
		);
		assert_same( array(), $out['errors'] );
		assert_same( array( 'email', 'copy', 'reddit' ), cradle_social_values()['share_services'] );
		assert_same( array( 'linkedin', 'email', 'facebook' ), cradle_social_order( cradle_render_component( 'cradle/social-links' ) ), 'Disabled Instagram is hidden; order follows the numbers.' );

		$post_id = cradle_social_post( array( 'post_title' => 'Ordering fixture' ) );
		assert_same( array( 'email', 'copy', 'reddit' ), cradle_social_order( cradle_render_component( 'cradle/share-links', array( 'postId' => $post_id ) ) ) );
		assert_same( array( 'x', 'email' ), cradle_social_order( cradle_render_component( 'cradle/share-links', array( 'postId' => $post_id, 'services' => array( 'x', 'email', 'nonsense' ) ) ) ), 'Block override selects and orders allowlisted services only.' );
		assert_same( array( 'facebook', 'linkedin' ), cradle_social_order( cradle_render_component( 'cradle/social-links', array( 'services' => array( 'facebook', 'youtube', 'linkedin' ) ) ) ), 'Profile override can only pick configured, enabled profiles.' );
	}
);

cradle_test(
	'social: output loads no remote script, iframe or image and invents no share counts',
	function () {
		cradle_social_ready();
		cradle_social_configure(
			array(
				'profiles'       => cradle_social_profiles_fixture(),
				'share_services' => Providers::share_ids(),
				'display'        => 'icon_text',
			)
		);
		$post_id = cradle_social_post( array( 'post_title' => 'Remote-free fixture' ) );
		$html    = cradle_render_component( 'cradle/social-links' ) . cradle_render_component( 'cradle/share-links', array( 'postId' => $post_id ) );
		foreach ( array( '<script', '<iframe', '<img', '<link', '<object', '<embed', '<video', 'src=', 'srcset=', 'url(' ) as $needle ) {
			assert_not_contains( $needle, strtolower( $html ), 'Components must not load remote resources.' );
		}
		assert_false( (bool) preg_match( '/count/i', $html ), 'No share counts are rendered.' );
		$allowed_hosts = array();
		foreach ( Providers::all() as $provider ) {
			if ( $provider['share'] ) {
				$allowed_hosts[] = wp_parse_url( $provider['share']['template'], PHP_URL_HOST );
			}
			if ( $provider['profile'] && 'hosts' === $provider['profile']['mode'] ) {
				$allowed_hosts = array_merge( $allowed_hosts, $provider['profile']['hosts'] );
			}
		}
		$allowed_hosts[] = 'mastodon.example';
		foreach ( cradle_social_attrs( $html, 'a', 'href' ) as $href ) {
			$scheme = wp_parse_url( $href, PHP_URL_SCHEME );
			assert_true( in_array( $scheme, array( 'https', 'mailto' ), true ), 'Only https/mailto links: ' . $href );
			if ( 'https' === $scheme ) {
				assert_true( in_array( wp_parse_url( $href, PHP_URL_HOST ), $allowed_hosts, true ), 'Link host is allowlisted: ' . $href );
			}
		}
		foreach ( array_keys( Icons::GLYPHS ) as $key ) {
			$svg  = Icons::svg( $key );
			$norm = static fn( $s ) => str_replace( ' />', '/>', strtolower( $s ) );
			assert_same( $norm( $svg ), $norm( wp_kses( $svg, Icons::allowed_html() ) ), "Icon {$key} uses only the reviewed SVG vocabulary (kses removes nothing)." );
			assert_false( (bool) preg_match( '/href|style|script|on[a-z]+=|url\(/i', $svg ), "Icon {$key} is inert." );
		}
	}
);

cradle_test(
	'social: copy-link button with aria-live status; Web Share button hidden until supported',
	function () {
		cradle_social_ready();
		cradle_social_configure( array() );
		$post_id = cradle_social_post( array( 'post_title' => 'Copy fixture' ) );
		$html    = cradle_render_component( 'cradle/share-links', array( 'postId' => $post_id, 'services' => array( 'x', 'copy', 'native' ) ) );
		$p       = new WP_HTML_Tag_Processor( $html );
		assert_true( $p->next_tag( array( 'tag_name' => 'div' ) ) );
		assert_same( 'share', $p->get_attribute( 'data-cradle-social' ), 'Root is marked for the view script.' );
		$found = array();
		while ( $p->next_tag() ) {
			if ( 'LI' === $p->get_tag() && $p->has_class( 'cradle-social__item--copy' ) ) {
				$found['copy_hidden'] = null !== $p->get_attribute( 'hidden' );
			}
			if ( 'LI' === $p->get_tag() && $p->has_class( 'cradle-social__item--native' ) ) {
				$found['native_hidden'] = null !== $p->get_attribute( 'hidden' );
			}
			if ( 'BUTTON' === $p->get_tag() && 'copy' === $p->get_attribute( 'data-cradle-social-action' ) ) {
				$found['copy_type'] = $p->get_attribute( 'type' );
				$found['copy_url']  = $p->get_attribute( 'data-cradle-social-url' );
			}
			if ( 'P' === $p->get_tag() && $p->has_class( 'cradle-social__status' ) ) {
				$found['status'] = array( $p->get_attribute( 'role' ), $p->get_attribute( 'aria-live' ) );
			}
		}
		assert_same( true, $found['copy_hidden'] ?? null, 'Copy button is hidden without JavaScript (revealed by the view script).' );
		assert_same( true, $found['native_hidden'] ?? null, 'Web Share button is hidden unless navigator.share exists.' );
		assert_same( 'button', $found['copy_type'] ?? null );
		assert_same( get_permalink( $post_id ), $found['copy_url'] ?? null );
		assert_same( array( 'status', 'polite' ), $found['status'] ?? null, 'aria-live status region present.' );
		assert_contains( '>Copy link<', $html, 'Copy button has a visible text label.' );

		$plain = cradle_render_component( 'cradle/share-links', array( 'postId' => $post_id, 'services' => array( 'x' ) ) );
		assert_not_contains( 'cradle-social__status', $plain, 'No live region without interactive actions.' );
		assert_not_contains( 'data-cradle-social=', $plain );
	}
);

cradle_test(
	'social: view script is enqueued only when interactive share links render',
	function () {
		cradle_social_ready();
		cradle_social_configure( array( 'profiles' => cradle_social_profiles_fixture() ) );
		$post_id = cradle_social_post( array( 'post_title' => 'Script fixture' ) );
		wp_dequeue_script( 'cradle-social-view' );
		assert_true( wp_script_is( 'cradle-social-view', 'registered' ) );
		assert_false( wp_script_is( 'cradle-social-view', 'enqueued' ) );
		cradle_render_component( 'cradle/social-links' );
		assert_false( wp_script_is( 'cradle-social-view', 'enqueued' ), 'Profile links need no script.' );
		cradle_render_component( 'cradle/share-links', array( 'postId' => $post_id, 'services' => array( 'x', 'email' ) ) );
		assert_false( wp_script_is( 'cradle-social-view', 'enqueued' ), 'Plain share links need no script.' );
		cradle_render_component( 'cradle/share-links', array( 'postId' => $post_id, 'services' => array( 'copy' ) ) );
		assert_true( wp_script_is( 'cradle-social-view', 'enqueued' ), 'Copy link enqueues the view script.' );
		wp_dequeue_script( 'cradle-social-view' );

		$profiles_page = cradle_social_post( array( 'post_type' => 'page', 'post_title' => 'Profiles only fixture', 'post_content' => '<!-- wp:cradle/social-links /-->' ) );
		$share_page    = cradle_social_post( array( 'post_type' => 'page', 'post_title' => 'Share fixture', 'post_content' => '<!-- wp:cradle/share-links /-->' ) );
		$plain_page    = cradle_social_post( array( 'post_type' => 'page', 'post_title' => 'Plain fixture', 'post_content' => '<!-- wp:paragraph --><p>Nothing social here.</p><!-- /wp:paragraph -->' ) );

		$body = wp_remote_retrieve_body( cradle_test_http( 'GET', get_permalink( $profiles_page ) ) );
		assert_contains( 'cradle-social--profiles', $body );
		assert_not_contains( 'cradle-social/assets/js/social.js', $body, 'No view script on a page with profile links only.' );
		assert_true( (bool) preg_match( '/id=["\x27]cradle-social-(inline-)?css["\x27]/', $body ), 'Stylesheet is loaded where rendered.' );

		$body = wp_remote_retrieve_body( cradle_test_http( 'GET', get_permalink( $share_page ) ) );
		assert_contains( 'cradle-social--share', $body );
		assert_contains( 'cradle-social/assets/js/social.js', $body, 'View script on a page with copy/Web Share actions.' );

		$body = wp_remote_retrieve_body( cradle_test_http( 'GET', get_permalink( $plain_page ) ) );
		assert_not_contains( 'cradle-social/assets/js/social.js', $body );
		assert_false( (bool) preg_match( '/id=["\x27]cradle-social-(inline-)?css["\x27]/', $body ), 'No stylesheet where nothing is rendered.' );
	}
);

cradle_test(
	'social: blocks and the shortcode render through the component service',
	function () {
		cradle_social_ready();
		cradle_social_configure( array( 'profiles' => cradle_social_profiles_fixture() ) );
		$post_id = cradle_social_post( array( 'post_title' => 'Block boundary fixture' ) );
		$seen    = array();
		$spy     = static function ( $html, $id ) use ( &$seen ) {
			$seen[] = $id;
			return $html;
		};
		add_filter( 'cradle/component_html', $spy, 10, 2 );
		$previous        = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		try {
			$strip = static function ( $html ) {
				return preg_replace( array( '/ class="[^"]*"/', '/ (id|aria-labelledby)="[^"]*"/' ), '', trim( $html ) );
			};
			$block = do_blocks( '<!-- wp:cradle/share-links {"services":["x","email","copy"],"display":"icon"} /-->' );
			$php   = cradle_render_component( 'cradle/share-links', array( 'services' => array( 'x', 'email', 'copy' ), 'display' => 'icon' ), array( 'post_id' => $post_id ) );
			assert_contains( 'wp-block-cradle-share-links', $block, 'Block wrapper classes are applied by the Gutenberg adapter.' );
			assert_contains( 'is-display-icon', $block );
			assert_same( $strip( $php ), $strip( $block ), 'Block output equals the PHP render service output.' );
			assert_contains( rawurlencode( get_permalink( $post_id ) ), $block, 'Block shares the post from block context.' );

			$block = do_blocks( '<!-- wp:cradle/social-links {"services":["linkedin","facebook"],"labelStyle":"action"} /-->' );
			$short = do_shortcode( '[cradle_component id="cradle/social-links" label-style="action"]' );
			assert_same( array( 'linkedin', 'facebook' ), cradle_social_order( $block ) );
			assert_contains( 'Follow us on LinkedIn', $block, 'Accessible, translatable action label.' );
			assert_contains( 'Follow us on Facebook', $short, 'Shortcode uses the same render service.' );
			assert_contains( 'rel="me"', $block );
			assert_not_contains( 'target="_blank"', $block, 'No new tab unless configured.' );
			assert_same( array( 'cradle/share-links', 'cradle/share-links', 'cradle/social-links', 'cradle/social-links' ), $seen );

			// Removal path: the cradle-blocks adapter can snapshot an instance to static HTML.
			$adapter = cradle()->components()->adapter( 'gutenberg' );
			assert_true( isset( $adapter->registered_blocks()['cradle/share-links'] ), 'Registered through the cradle-blocks Gutenberg adapter.' );
			$snapshot = $adapter->export_or_convert_instance(
				array(
					'block'   => parse_blocks( '<!-- wp:cradle/share-links {"services":["x"]} /-->' )[0],
					'post_id' => $post_id,
				)
			);
			assert_true( is_array( $snapshot ) && str_contains( $snapshot['html'], 'x.com/intent/tweet' ), 'Conversion preview produces the rendered links.' );
		} finally {
			remove_filter( 'cradle/component_html', $spy, 10 );
			$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		cradle()->settings()->update( 'social', array( 'profiles_new_tab' => true ) );
		$html = cradle_render_component( 'cradle/social-links', array( 'services' => array( 'x', 'email' ) ) );
		assert_contains( 'target="_blank" rel="me noopener noreferrer"', $html, 'New tab only when configured, with noopener noreferrer.' );
		assert_contains( 'Follow us on X (opens in a new tab)', $html, 'New tab is announced.' );
		assert_same( 1, substr_count( $html, 'target="_blank"' ), 'mailto links never open a new tab.' );
	}
);

cradle_test(
	'social: drafts, private and trashed entries are never shared',
	function () {
		cradle_social_ready();
		cradle_social_configure( array() );
		foreach ( array( 'draft', 'private', 'pending', 'future' ) as $status ) {
			$args = array( 'post_title' => 'Hidden ' . $status, 'post_status' => $status );
			if ( 'future' === $status ) {
				$args['post_date'] = gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS );
			}
			$id = cradle_social_post( $args );
			assert_same( '', cradle_render_component( 'cradle/share-links', array( 'postId' => $id ) ), "{$status} entries render nothing." );
		}
		$id = cradle_social_post( array( 'post_title' => 'Visible' ) );
		assert_contains( 'cradle-social--share', cradle_render_component( 'cradle/share-links', array( 'postId' => $id ) ) );
		wp_trash_post( $id );
		assert_same( '', cradle_render_component( 'cradle/share-links', array( 'postId' => $id ) ), 'Trashed entries render nothing.' );
	}
);

cradle_test(
	'social: automatic placement is off by default and adds share links only to configured single entries',
	function () {
		cradle_social_ready();
		cradle_social_configure( array() );
		assert_same( 'none', cradle_social_values()['placement'] );
		$post_id  = cradle_social_post( array( 'post_title' => 'Placement post fixture', 'post_content' => '<!-- wp:paragraph --><p>PLACEMENT-BODY-MARKER</p><!-- /wp:paragraph -->' ) );
		$page_id  = cradle_social_post( array( 'post_type' => 'page', 'post_title' => 'Placement page fixture', 'post_content' => '<!-- wp:paragraph --><p>PLACEMENT-PAGE-MARKER</p><!-- /wp:paragraph -->' ) );
		$with_blk = cradle_social_post( array( 'post_title' => 'Placement block fixture', 'post_content' => '<!-- wp:paragraph --><p>PLACEMENT-BLOCK-MARKER</p><!-- /wp:paragraph --><!-- wp:cradle/share-links /-->' ) );

		$body = wp_remote_retrieve_body( cradle_test_http( 'GET', get_permalink( $post_id ) ) );
		assert_contains( 'PLACEMENT-BODY-MARKER', $body );
		assert_not_contains( 'cradle-social--share', $body, 'Nothing is added while placement is off.' );

		cradle_social_configure( array( 'placement' => 'after', 'placement_post_types' => array( 'post' ) ) );
		$body = wp_remote_retrieve_body( cradle_test_http( 'GET', get_permalink( $post_id ) ) );
		assert_same( 1, substr_count( $body, 'cradle-social--share' ), 'Exactly one share group is added.' );
		assert_true( strpos( $body, 'cradle-social--share' ) > strpos( $body, 'PLACEMENT-BODY-MARKER' ), 'Placed after the content.' );
		assert_contains( rawurlencode( get_permalink( $post_id ) ), $body );

		$body = wp_remote_retrieve_body( cradle_test_http( 'GET', get_permalink( $page_id ) ) );
		assert_contains( 'PLACEMENT-PAGE-MARKER', $body );
		assert_not_contains( 'cradle-social--share', $body, 'Pages are not configured.' );

		$body = wp_remote_retrieve_body( cradle_test_http( 'GET', get_permalink( $with_blk ) ) );
		assert_same( 1, substr_count( $body, 'cradle-social--share' ), 'An entry with its own Share links block is not duplicated.' );

		$body = wp_remote_retrieve_body( cradle_test_http( 'GET', get_feed_link() ) );
		assert_contains( 'PLACEMENT-BODY-MARKER', $body, 'The fixture is in the feed.' );
		assert_not_contains( 'cradle-social-placement', $body, 'Automatic placement never touches feeds.' );
		assert_not_contains( 'data-cradle-social-action', $body, 'Script-only actions are dropped from feeds (a placed block still shows plain links).' );

		$out = cradle()->settings()->update( 'social', array( 'placement_post_types' => array( 'post', 'attachment' ) ) );
		assert_true( isset( $out['errors']['placement_post_types'] ), 'Only viewable public types are allowed.' );
		cradle()->settings()->reset( 'social' );
	}
);

cradle_test(
	'social: frontend page with both blocks returns 200 without PHP notices',
	function () {
		cradle_social_ready();
		cradle_social_configure(
			array(
				'profiles'         => cradle_social_profiles_fixture(),
				'color_text'       => 'preset:accent',
				'color_background' => '#f2f2f2',
			)
		);
		$page_id = cradle_social_post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Social frontend fixture',
				'post_content' => '<!-- wp:cradle/social-links {"display":"icon","justify":"center"} /-->' . "\n\n" . '<!-- wp:cradle/share-links {"labelStyle":"action","showLabel":"show"} /-->',
			)
		);
		$offset = cradle_social_debug_log_size();
		$r      = cradle_test_http( 'GET', get_permalink( $page_id ) );
		$body   = wp_remote_retrieve_body( $r );
		assert_same( 200, wp_remote_retrieve_response_code( $r ) );
		assert_contains( 'cradle-social--profiles', $body );
		assert_contains( 'cradle-social--share', $body );
		assert_contains( 'Follow us on LinkedIn', $body );
		assert_contains( '--cradle-social-color:var(--wp--preset--color--accent)', $body );
		assert_contains( '--cradle-social-background:#f2f2f2', $body );
		foreach ( array( 'Fatal error', 'Warning:', 'Notice:', 'Deprecated:' ) as $needle ) {
			assert_not_contains( $needle, $body );
		}
		$log = cradle_social_debug_log_since( $offset );
		assert_false( (bool) preg_match( '/cradle-social|Cradle\\\\Social/', $log ), 'No PHP notices from cradle-social in debug.log: ' . substr( $log, 0, 500 ) );
		// Third-party hosts from the provider registry never appear as resource sources.
		foreach ( array( 'script', 'iframe', 'img' ) as $tag ) {
			foreach ( cradle_social_attrs( $body, $tag, 'src' ) as $src ) {
				$host = (string) wp_parse_url( $src, PHP_URL_HOST );
				assert_false( (bool) preg_match( '/(^|\.)(facebook|x|twitter|linkedin|bsky|instagram|youtube|whatsapp|wa|t|telegram|reddit|pinterest)\.(com|app|me)$/', $host ), "No third-party {$tag}: {$src}" );
			}
		}
	}
);

cradle_test(
	'social: provider registry filter is re-validated (no arbitrary templates or hosts)',
	function () {
		cradle_social_ready();
		$filter = static function ( $providers ) {
			unset( $providers['facebook'] );
			$providers['evil-js']    = array( 'label' => 'Evil', 'share' => array( 'template' => 'javascript:alert(1)?u={url}' ) );
			$providers['evil-host']  = array( 'label' => 'Evil', 'share' => array( 'template' => 'https://{title}.example/?u={url}' ) );
			$providers['evil-param'] = array( 'label' => 'Evil', 'share' => array( 'template' => 'https://share.example/?u={url}&x={secret}' ) );
			$providers['evil-http']  = array( 'label' => 'Evil', 'share' => array( 'template' => 'http://share.example/?u={url}' ) );
			$providers['evil-nourl'] = array( 'label' => 'Evil', 'share' => array( 'template' => 'https://share.example/?t={title}' ) );
			$providers['evil-fed']   = array( 'label' => 'Evil', 'profile' => array( 'federated' => true ) );
			$providers['good']       = array( 'label' => 'Good <b>net</b>', 'icon' => 'nope', 'profile' => array( 'hosts' => array( 'good.example' ) ), 'share' => array( 'template' => 'https://share.good.example/post?u={url}&t={text}' ) );
			return $providers;
		};
		add_filter( 'cradle_social/providers', $filter );
		Providers::flush();
		try {
			$all = Providers::all();
			assert_false( isset( $all['facebook'] ), 'Trusted code can remove a provider.' );
			foreach ( array( 'evil-js', 'evil-host', 'evil-param', 'evil-http', 'evil-nourl', 'evil-fed' ) as $id ) {
				assert_false( isset( $all[ $id ] ), "{$id} must be rejected." );
				assert_true( isset( Providers::problems()[ $id ] ) );
			}
			assert_true( isset( $all['good'] ) );
			assert_same( 'Good net', $all['good']['label'] );
			assert_same( 'generic', $all['good']['icon'], 'Unknown icons fall back to the bundled generic glyph.' );
			assert_same( 'https://share.good.example/post?u=' . rawurlencode( 'https://site.example/a b' ), Providers::share_url( 'good', array( 'url' => 'https://site.example/a b', 'text' => '' ) ), 'Empty parameters are dropped.' );
			assert_same( '', Providers::share_url( 'good', array( 'url' => 'javascript:alert(1)' ) ), 'Only http(s) page URLs are shared.' );
		} finally {
			remove_filter( 'cradle_social/providers', $filter );
			Providers::flush();
		}
	}
);

cradle_test(
	'social: uninstall retains settings by default and purges only its own data under the purge policy',
	function () {
		cradle_social_ready();
		$runner = CRADLE_TEST_ROOT . '/tests/fixtures/social/run-uninstall.php';
		if ( ! is_file( $runner ) ) {
			skip_test( 'Uninstall runner fixture missing.' );
		}
		$php = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
		cradle_social_configure( array( 'share_title' => 'Retained {title}' ) );
		$sentinel = get_option( 'blogname' );

		exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $runner ) . ' 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		assert_same( 0, $code, 'Uninstall runner failed: ' . implode( "\n", $output ) );
		assert_same( 'Retained {title}', cradle_social_values()['share_title'], 'Retain (default) keeps settings.' );

		$policies           = get_option( 'cradle_uninstall_policy', array() );
		$policies           = is_array( $policies ) ? $policies : array();
		$policies['social'] = 'purge';
		update_option( 'cradle_uninstall_policy', $policies, false );
		$output = array();
		try {
			exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $runner ) . ' 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			assert_same( 0, $code, 'Uninstall runner failed: ' . implode( "\n", $output ) );
			wp_cache_flush();
			wp_roles()->for_site( get_current_blog_id() ); // Reload roles changed by the child process.
			assert_false( get_option( Config::OPTION ), 'Purge removes the settings option.' );
			assert_false( get_option( 'cradle_social_installed_version' ), 'Purge removes the version marker.' );
			$admin_role = get_role( 'administrator' );
			assert_false( $admin_role->has_cap( 'cradle_manage_social' ), 'Purge revokes the capability.' );
			assert_same( $sentinel, get_option( 'blogname' ), 'Unrelated options are untouched.' );
			assert_true( $admin_role->has_cap( 'cradle_manage_settings' ), 'Core capabilities are untouched.' );
			$after = get_option( 'cradle_uninstall_policy', array() );
			assert_false( isset( $after['social'] ), 'The consumed purge policy is cleared.' );
		} finally {
			// Restore the active plugin's state (capability grant record + version marker).
			wp_cache_flush();
			wp_roles()->for_site( get_current_blog_id() );
			$after = get_option( 'cradle_uninstall_policy', array() );
			if ( is_array( $after ) && isset( $after['social'] ) ) {
				unset( $after['social'] );
				update_option( 'cradle_uninstall_policy', $after, false );
			}
			get_role( 'administrator' )->add_cap( 'cradle_manage_social' );
			$granted = get_option( 'cradle_granted_capabilities', array() );
			$granted = is_array( $granted ) ? $granted : array();
			$granted['cradle_manage_social'] = 'social';
			update_option( 'cradle_granted_capabilities', $granted, false );
			update_option( 'cradle_social_installed_version', CRADLE_SOCIAL_VERSION, false );
		}
	}
);

cradle_test(
	'social: core-missing guard and missing blocks dependency degrade safely (isolated processes)',
	function () {
		cradle_social_ready();
		$runner = CRADLE_TEST_ROOT . '/tests/fixtures/social/run-guard.php';
		$php    = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
		$run    = static function ( string $mode ) use ( $php, $runner ) {
			$output = array();
			exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $runner ) . ' ' . escapeshellarg( $mode ) . ' 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			$data = json_decode( (string) end( $output ), true );
			if ( 0 !== $code || ! is_array( $data ) ) {
				throw new Cradle_Test_Failure( "Guard runner ({$mode}) failed: " . substr( implode( "\n", $output ), 0, 500 ) );
			}
			return $data;
		};
		$no_core = $run( 'no-core' );
		assert_true( $no_core['socialLoaded'] && ! $no_core['coreLoaded'], 'Plugin loads without core.' );
		assert_true( $no_core['notice'], 'Administrators see the core-missing notice.' );
		assert_false( $no_core['blockRegistered'] || $no_core['contentFilter'], 'Nothing is booted without core.' );
		assert_same( '', $no_core['renderedBlock'], 'Saved blocks render nothing (dynamic, no fallback).' );
		assert_same( array(), $no_core['errors'], 'No PHP errors from cradle-social.' );

		$no_blocks = $run( 'no-blocks' );
		assert_same( 'failed', $no_blocks['state'], 'Module is not ready without its blocks dependency.' );
		assert_contains( 'blocks', implode( ' ', $no_blocks['problems'] ) );
		assert_false( $no_blocks['section'] || $no_blocks['contentFilter'] || $no_blocks['blockRegistered'], 'Nothing is booted.' );
		assert_true( $no_blocks['coreNotice'], 'Core reports the failed dependency on the Plugins screen.' );
		assert_same( array(), $no_blocks['errors'] );
	}
);

cradle_test(
	'social: cleanup resets settings to defaults',
	function () {
		cradle_social_ready();
		cradle_social_admin();
		cradle()->settings()->reset( 'social' );
		$values = cradle_social_values();
		assert_same( cradle()->settings()->defaults( 'social' ), $values );
		assert_false( get_option( Config::OPTION ), 'No stored settings row remains.' );
	}
);
