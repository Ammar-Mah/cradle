<?php
/**
 * Core plugin contract tests: registry, settings, components, jobs, locks, storage, permissions.
 */

use Cradle\Core\Module_Registry;
use Cradle\Core\Version_Range;
use Cradle\Core\Components;
use Cradle\Core\Settings;

cradle_test(
	'core: plugin is active and services boot',
	function () {
		assert_true( is_plugin_active( 'cradle/cradle.php' ), 'Core plugin must be active in the sandbox.' );
		assert_true( cradle() instanceof Cradle\Core\Plugin );
		assert_same( '1.0.0', cradle()->api_version() );
		assert_true( did_action( 'cradle/booted' ) > 0, 'cradle/booted should have fired on init.' );
	}
);

cradle_test(
	'core: version ranges',
	function () {
		assert_true( Version_Range::satisfies( '1.2.0', '^1.0' ) );
		assert_false( Version_Range::satisfies( '2.0.0', '^1.0' ) );
		assert_true( Version_Range::satisfies( '0.1.5', '^0.1' ) );
		assert_false( Version_Range::satisfies( '0.2.0', '^0.1' ) );
		assert_true( Version_Range::satisfies( '1.0.0', '>=1.0 <2.0' ) );
		assert_same( null, Version_Range::satisfies( '1.0.0', 'garbage range' ) );
	}
);

cradle_test(
	'core: registry rejects invalid, duplicate, incompatible, missing-dependency and cyclic modules',
	function () {
		$registry = new Module_Registry();
		$file     = WP_PLUGIN_DIR . '/cradle/cradle.php';
		$base     = array(
			'name'         => 'X',
			'version'      => '1.0.0',
			'coreApiRange' => '^1.0',
			'pluginFile'   => $file,
		);
		assert_true( true === $registry->register( $base + array( 'id' => 'alpha' ) ) );
		assert_wp_error( $registry->register( $base + array( 'id' => 'alpha' ) ), 'cradle_duplicate_module' );
		assert_wp_error( $registry->register( $base + array( 'id' => 'Bad ID' ) ) );
		assert_wp_error( $registry->register( array( 'id' => 'extra', 'surprise' => 1 ) + $base ) );
		assert_wp_error( $registry->register( array( 'id' => 'outside', 'pluginFile' => ABSPATH . 'wp-load.php' ) + $base ) );
		assert_true( true === $registry->register( array( 'id' => 'future', 'coreApiRange' => '^2.0' ) + $base ) );
		assert_true( true === $registry->register( array( 'id' => 'needs-missing', 'dependencies' => array( 'ghost' ) ) + $base ) );
		assert_true( true === $registry->register( array( 'id' => 'needs-future', 'dependencies' => array( 'future' ) ) + $base ) );
		assert_true( true === $registry->register( array( 'id' => 'cyc-a', 'dependencies' => array( 'cyc-b' ) ) + $base ) );
		assert_true( true === $registry->register( array( 'id' => 'cyc-b', 'dependencies' => array( 'cyc-a' ) ) + $base ) );
		assert_true( true === $registry->register( array( 'id' => 'child', 'dependencies' => array( 'alpha' => '^1.0' ) ) + $base ) );
		$registry->resolve( '1.0.0' );
		assert_same( 'ready', $registry->state( 'alpha' ) );
		assert_same( 'ready', $registry->state( 'child' ) );
		assert_same( 'failed', $registry->state( 'future' ) );
		assert_same( 'failed', $registry->state( 'needs-missing' ) );
		assert_same( 'failed', $registry->state( 'needs-future' ) );
		assert_same( 'failed', $registry->state( 'cyc-a' ) );
		assert_same( 'failed', $registry->state( 'cyc-b' ) );
		$order = array_keys( $registry->ready_descriptors() );
		assert_true( array_search( 'alpha', $order, true ) < array_search( 'child', $order, true ), 'Dependencies boot first.' );
		assert_wp_error( $registry->register( $base + array( 'id' => 'late' ) ), 'cradle_registry_closed' );
	}
);

cradle_test(
	'core: settings validation keeps valid fields and rejects invalid ones',
	function () {
		$section = array(
			'fields'   => array(
				'count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'label' => 'Count' ),
				'color' => array( 'type' => 'color', 'label' => 'Color' ),
				'mode'  => array( 'type' => 'enum', 'enum' => array( 'a', 'b' ), 'label' => 'Mode' ),
				'link'  => array( 'type' => 'url', 'label' => 'Link' ),
				'size'  => array( 'type' => 'dimension', 'units' => array( 'px', 'rem' ), 'label' => 'Size' ),
				'on'    => array( 'type' => 'boolean', 'label' => 'On' ),
			),
			'validate' => null,
		);
		$settings = cradle()->settings();
		$current  = array(
			'count' => 2,
			'color' => '#000000',
			'mode'  => 'a',
			'link'  => '',
			'size'  => array( 'value' => 1, 'unit' => 'px' ),
			'on'    => false,
		);
		$result   = $settings->validate_values(
			$section,
			array(
				'count'   => '9',
				'color'   => '#ABCDEF',
				'mode'    => 'c',
				'link'    => 'javascript:alert(1)',
				'size'    => array( 'value' => '2.5', 'unit' => 'rem' ),
				'on'      => '1',
				'unknown' => 'x',
			),
			$current
		);
		assert_same( 2, $result['values']['count'], 'Out-of-range integer keeps the old value.' );
		assert_same( '#abcdef', $result['values']['color'] );
		assert_same( 'a', $result['values']['mode'] );
		assert_same( '', $result['values']['link'] );
		assert_same( array( 'value' => 2.5, 'unit' => 'rem' ), $result['values']['size'] );
		assert_same( true, $result['values']['on'] );
		assert_false( isset( $result['values']['unknown'] ), 'Unknown keys are dropped.' );
		assert_same( array( 'count', 'mode', 'link' ), array_keys( $result['errors'] ) );
		assert_wp_error( Settings::validate_field( array( 'type' => 'dimension', 'units' => array( 'px' ) ), array( 'value' => 1, 'unit' => 'vh' ) ) );
		assert_wp_error( Settings::validate_field( array( 'type' => 'attachment' ), 999999 ) );
	}
);

cradle_test(
	'core: settings section save, stale revision rejection, capability and reset',
	function () {
		// Core has no settings page of its own; a test-only section (sandbox mu-plugin for this test) exercises the machinery.
		$dir     = WP_CONTENT_DIR . '/mu-plugins';
		$had_dir = is_dir( $dir );
		$mu      = $dir . '/cradle-test-settings-fixture.php';
		if ( ! $had_dir ) {
			wp_mkdir_p( $dir );
		}
		copy( CRADLE_TEST_ROOT . '/tests/fixtures/core/settings-section.php', $mu );
		require_once $mu;
		$admin  = cradle_test_user( 'administrator' );
		$editor = cradle_test_user( 'editor' );
		$fresh  = static function () {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'cradle_fixture_settings', 'options' );
		};
		try {
			assert_true( (bool) cradle()->settings()->section( 'fixture' ), 'Fixture section registered.' );
			wp_set_current_user( $editor );
			assert_wp_error( cradle()->settings()->update( 'fixture', array( 'retention_days' => 30 ) ), 'cradle_forbidden' );
			wp_set_current_user( $admin );
			cradle()->settings()->reset( 'fixture' );
			assert_same( 90, cradle_get_setting( 'fixture', 'retention_days' ) );
			$out = cradle()->settings()->update( 'fixture', array( 'retention_days' => 30, 'max_rows' => 1 ) );
			assert_same( 30, cradle_get_setting( 'fixture', 'retention_days' ) );
			assert_same( 5000, cradle_get_setting( 'fixture', 'max_rows' ), 'Invalid field keeps previous value.' );
			assert_true( isset( $out['errors']['max_rows'] ) );
			$revision = cradle()->settings()->revision( 'fixture' );

			// The section page is listed in the Cradle menu for administrators.
			$session = cradle_test_cookies( $admin );
			$r       = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle-fixture' ), array(), $admin, $session );
			assert_same( 200, wp_remote_retrieve_response_code( $r ) );
			assert_contains( 'cradle_fixture_settings[retention_days]', wp_remote_retrieve_body( $r ) );

			// Real options.php submissions over HTTP: stale revision is refused, current revision saves.
			$nonce = cradle_test_nonce( $admin, $session, 'cradle_fixture-options' );
			$post  = function ( $rev, $days ) use ( $admin, $session, $nonce ) {
				return cradle_test_http(
					'POST',
					admin_url( 'options.php' ),
					array(
						'body' => array(
							'option_page'              => 'cradle_fixture',
							'action'                   => 'update',
							'_wpnonce'                 => $nonce,
							'_wp_http_referer'         => '/cradle/wp/wp-admin/admin.php?page=cradle-fixture',
							'cradle_fixture_settings'  => array(
								'_revision'      => $rev,
								'retention_days' => $days,
								'max_rows'       => 5000,
							),
						),
					),
					$admin,
					$session
				);
			};
			$post( $revision - 1, 45 );
			$fresh();
			assert_same( 30, cradle_get_setting( 'fixture', 'retention_days' ), 'Stale revision must not save.' );
			$post( $revision, 45 );
			$fresh();
			assert_same( 45, cradle_get_setting( 'fixture', 'retention_days' ), 'Current revision saves.' );

			// Editor cannot save through options.php even with a valid nonce of their own.
			$esession = cradle_test_cookies( $editor );
			$enonce   = cradle_test_nonce( $editor, $esession, 'cradle_fixture-options' );
			$response = cradle_test_http(
				'POST',
				admin_url( 'options.php' ),
				array(
					'body' => array(
						'option_page'             => 'cradle_fixture',
						'action'                  => 'update',
						'_wpnonce'                => $enonce,
						'cradle_fixture_settings' => array(
							'_revision'      => cradle()->settings()->revision( 'fixture' ),
							'retention_days' => 7,
						),
					),
				),
				$editor,
				$esession
			);
			assert_true( in_array( wp_remote_retrieve_response_code( $response ), array( 403, 500 ), true ) || false !== strpos( wp_remote_retrieve_body( $response ), 'not allowed' ), 'Editor must be refused.' );
			$fresh();
			assert_same( 45, cradle_get_setting( 'fixture', 'retention_days' ) );
		} finally {
			@unlink( $mu ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			if ( ! $had_dir ) {
				@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			}
			delete_option( 'cradle_fixture_settings' );
		}
		assert_false( is_file( $mu ), 'Fixture mu-plugin removed.' );
	}
);

cradle_test(
	'core: component descriptor validation and normalization',
	function () {
		$bad = Components::validate_descriptor( array( 'schemaVersion' => 1, 'id' => 'Nope' ) );
		assert_wp_error( $bad );
		$bad_override = Components::validate_descriptor(
			array(
				'schemaVersion'  => 1,
				'id'             => 'test/x',
				'version'        => '1.0.0',
				'owner'          => 'test',
				'renderContract' => 'x-v1',
				'themeOverride'  => '../../wp-config.php',
			)
		);
		assert_wp_error( $bad_override, '', 'Override paths are allowlisted.' );
		$components = new Components();
		$ok         = $components->register(
			array(
				'schemaVersion'  => 1,
				'id'             => 'test/card',
				'version'        => '1.0.0',
				'owner'          => 'tests',
				'renderContract' => 'card-v1',
				'properties'     => array(
					'title'   => array( 'type' => 'string', 'default' => '' ),
					'body'    => array( 'type' => 'string', 'format' => 'limited-rich-text' ),
					'imageId' => array( 'type' => 'integer', 'format' => 'attachment', 'minimum' => 0 ),
					'variant' => array( 'type' => 'string', 'enum' => array( 'plain', 'outlined' ), 'default' => 'plain' ),
					'items'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 2 ),
				),
			),
			array(
				'render' => function ( $props, $context ) use ( &$components ) {
					$nested = $components->render( 'test/card', $props, $context + array( 'entity_key' => 'self' ) );
					return '<div class="card" id="' . esc_attr( $context['instance_id'] ) . '">' . esc_html( $props['title'] ) . $nested . '</div>';
				},
			)
		);
		assert_true( true === $ok );
		$props = $components->normalize(
			'test/card',
			array(
				'title'   => '<b>Hi</b>',
				'body'    => '<a href="https://example.test" onclick="x()">x</a><script>bad()</script>',
				'imageId' => 99999999,
				'variant' => 'weird',
				'items'   => array( 'a', 'b', 'c' ),
				'extra'   => 1,
			)
		);
		assert_same( 'Hi', $props['title'] );
		assert_not_contains( 'onclick', $props['body'] );
		assert_not_contains( '<script', $props['body'] );
		assert_same( 0, $props['imageId'], 'Missing attachment reference becomes 0.' );
		assert_same( 'plain', $props['variant'] );
		assert_same( array( 'a', 'b' ), $props['items'] );
		assert_false( isset( $props['extra'] ) );
		$html = $components->render( 'test/card', array( 'title' => 'A' ), array( 'entity_key' => 'self' ) );
		assert_same( 1, substr_count( $html, 'class="card"' ), 'Cyclic self-render is stopped.' );
		$second = $components->render( 'test/card', array( 'title' => 'B' ) );
		assert_true( false !== strpos( $second, 'test-card-' ), 'Instances get unique IDs.' );
		assert_true( $html !== $second );
	}
);

cradle_test(
	'core: locks are exclusive and fenced',
	function () {
		$locks = cradle()->locks();
		$a     = $locks->acquire( 'tests:lock', 30 );
		assert_true( is_array( $a ) );
		assert_same( null, $locks->acquire( 'tests:lock', 30 ), 'Second acquire fails while held.' );
		assert_true( $locks->holds( 'tests:lock', $a ) );
		assert_true( $locks->release( 'tests:lock', $a ) );
		$b = $locks->acquire( 'tests:lock', 1 );
		assert_true( is_array( $b ) );
		global $wpdb;
		$table = Cradle\Core\Schema::tables()['locks'];
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET expires_at = %s WHERE name = %s", gmdate( 'Y-m-d H:i:s', time() - 5 ), 'tests:lock' ) ); // phpcs:ignore
		$c = $locks->acquire( 'tests:lock', 30 );
		assert_true( is_array( $c ) && $c['fencing'] > $b['fencing'], 'Expired lock is taken over with a higher fencing token.' );
		assert_false( $locks->release( 'tests:lock', $b ), 'Old holder cannot release.' );
		$locks->release( 'tests:lock', $c );
	}
);

cradle_test(
	'core: jobs claim atomically, commit with fencing, retry and cancel',
	function () {
		$admin = cradle_test_user( 'administrator' );
		wp_set_current_user( $admin );
		$jobs  = cradle()->jobs();
		$calls = 0;
		$jobs->register_type(
			'tests/count',
			array(
				'capability'  => 'manage_options',
				'time_budget' => 5,
				'step'        => function ( $job ) use ( &$calls ) {
					$calls++;
					$done = $job['progress_done'] + 1;
					return array(
						'progress_done' => $done,
						'state'         => $done >= 3 ? 'completed' : 'running',
					);
				},
			)
		);
		$job = $jobs->create( 'tests/count', array(), array( 'total' => 3 ) );
		assert_true( is_array( $job ) );
		$claim = $jobs->claim( $job['uuid'] );
		assert_true( is_array( $claim ) );
		assert_same( null, $jobs->claim( $job['uuid'] ), 'A leased job cannot be claimed twice.' );
		assert_true( $jobs->commit( $claim, array( 'phase' => 'x' ), true ) );
		assert_false( $jobs->commit( $claim, array( 'phase' => 'late' ) ), 'Stale lease holder cannot commit after release.' );
		$final = $jobs->run( $job['uuid'] );
		assert_same( 'completed', $final['state'] );
		assert_same( 3, $final['progress_done'] );

		$jobs->register_type(
			'tests/fail',
			array(
				'capability'  => 'manage_options',
				'max_retries' => 1,
				'step'        => fn() => new WP_Error( 'boom', 'failure with password=hunter2 inside' ),
			)
		);
		$f = $jobs->create( 'tests/fail' );
		$jobs->run( $f['uuid'] );
		$f = $jobs->run( $f['uuid'] );
		assert_same( 'failed', $f['state'] );
		assert_not_contains( 'hunter2', (string) $f['error_message'], 'Errors are redacted.' );

		$c = $jobs->create( 'tests/count' );
		assert_true( $jobs->cancel( $c['uuid'] ) );
		assert_same( 'cancelled', $jobs->get( $c['uuid'] )['state'] );

		wp_set_current_user( cradle_test_user( 'editor' ) );
		assert_wp_error( $jobs->create( 'tests/count' ), 'cradle_job_forbidden' );
	}
);

cradle_test(
	'core: job cancellation policy, resume and retry reset',
	function () {
		wp_set_current_user( cradle_test_user( 'administrator' ) );
		$jobs  = cradle()->jobs();
		$calls = 0;
		$jobs->register_type(
			'tests/flaky',
			array(
				'capability'  => 'manage_options',
				'max_retries' => 1,
				'time_budget' => 0,
				'cancellable' => fn( $job ) => 'cutover' !== $job['phase'],
				'step'        => function ( $job ) use ( &$calls ) {
					$calls++;
					if ( 1 === $calls || 3 === $calls ) {
						return new WP_Error( 'flaky', 'transient failure' );
					}
					return array( 'progress_done' => $job['progress_done'] + 1, 'state' => 'running' );
				},
			)
		);
		$job = $jobs->create( 'tests/flaky' );
		$jobs->run( $job['uuid'] );                      // failure 1 → retries 1
		$after = $jobs->run( $job['uuid'] );             // success → retries reset
		assert_same( 0, $after['retries'], 'A successful step resets the failure streak.' );
		assert_same( 1, $after['retries_total'], 'The cumulative retry count keeps the history.' );
		assert_same( '', (string) $after['error_message'], 'A recovered error no longer describes the job.' );
		$after = $jobs->run( $job['uuid'] );             // failure again → retries 1, not failed
		assert_same( 'running', $after['state'], 'Non-consecutive failures do not exhaust max_retries.' );

		$claim = $jobs->claim( $job['uuid'] );
		$jobs->commit( $claim, array( 'phase' => 'cutover' ), true );
		assert_false( $jobs->cancel( $job['uuid'] ), 'The type refuses cancellation during cutover.' );
		$claim = $jobs->claim( $job['uuid'] );
		$jobs->commit( $claim, array( 'phase' => 'load', 'state' => 'paused' ), true );
		assert_true( $jobs->resume( $job['uuid'] ), 'A paused job can be resumed.' );
		assert_same( 'running', $jobs->get( $job['uuid'] )['state'] );
		assert_false( $jobs->resume( $job['uuid'] ), 'Only paused/awaiting jobs resume.' );
		assert_true( $jobs->cancel( $job['uuid'] ) );
		assert_false( $jobs->is_cancellable( $jobs->get( $job['uuid'] ) ), 'Terminal jobs are not cancellable.' );
	}
);

cradle_test(
	'core: job AJAX endpoints enforce nonce and object access',
	function () {
		$admin = cradle_test_user( 'administrator' );
		wp_set_current_user( $admin );
		cradle()->jobs()->register_type(
			'tests/noop',
			array(
				'capability' => 'manage_options',
				'step'       => fn() => array( 'state' => 'completed' ),
			)
		);
		$job      = cradle()->jobs()->create( 'tests/noop' );
		$subscr   = cradle_test_user( 'subscriber' );
		$ssession = cradle_test_cookies( $subscr );
		$snonce   = cradle_test_nonce( $subscr, $ssession, 'cradle_jobs' );
		$r        = cradle_test_http( 'POST', admin_url( 'admin-ajax.php' ), array( 'body' => array( 'action' => 'cradle_job_status', 'nonce' => $snonce, 'job' => $job['uuid'] ) ), $subscr, $ssession );
		assert_same( 404, wp_remote_retrieve_response_code( $r ), 'Subscriber cannot see an administrator job.' );
		$r = cradle_test_http( 'POST', admin_url( 'admin-ajax.php' ), array( 'body' => array( 'action' => 'cradle_job_status', 'nonce' => 'bad', 'job' => $job['uuid'] ) ), $admin );
		assert_same( 403, wp_remote_retrieve_response_code( $r ), 'Invalid nonce is refused.' );
	}
);

cradle_test(
	'core: private storage is denied over HTTP',
	function () {
		$probe = cradle()->storage()->verify_http_denial( true );
		assert_same( 'protected', $probe['status'], $probe['detail'] );
		$dir = cradle()->storage()->module_dir( 'tests' );
		assert_true( is_string( $dir ) && is_dir( $dir ) );
		assert_false( Cradle\Core\Storage::path_within( $dir . '/../../escape.txt', $dir ), 'Traversal is not contained.' );
		assert_true( Cradle\Core\Storage::path_within( $dir . '/a/b.txt', $dir ) );
		assert_wp_error( cradle()->storage()->module_dir( '../evil' ) );
	}
);

cradle_test(
	'core: admin screens load for administrators and refuse subscribers',
	function () {
		$admin = cradle_test_user( 'administrator' );
		foreach ( array( 'cradle' ) as $page ) {
			$r = cradle_test_http( 'GET', admin_url( 'admin.php?page=' . $page ), array(), $admin );
			assert_same( 200, wp_remote_retrieve_response_code( $r ), "Admin page {$page} should load." );
			assert_not_contains( 'Fatal error', wp_remote_retrieve_body( $r ) );
		}
		$sub = cradle_test_user( 'subscriber' );
		$r   = cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle' ), array(), $sub );
		assert_true( 200 !== wp_remote_retrieve_response_code( $r ) || false !== strpos( wp_remote_retrieve_body( $r ), 'not allowed' ), 'Subscriber is refused.' );

		// Retired screens are gone from the menu.
		$overview = wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle' ), array(), $admin ) );
		foreach ( array( 'page=cradle-core', 'page=cradle-components', 'Components &amp; cleanup', 'page=cradle-modules', 'page=cradle-developer', 'Developer diagnostics', 'page=cradle-activity', 'page=cradle-fonts' ) as $gone ) {
			assert_not_contains( $gone, $overview, "Retired screen still linked: {$gone}" );
		}
		// The Cradle menu follows the owner's fixed order, whatever order add-ons registered in.
		preg_match( '#<li[^>]*id="toplevel_page_cradle".*?</ul>#s', $overview, $menu );
		preg_match_all( '#href=[\'"]admin\.php\?page=([a-z0-9-]+)[\'"]#', $menu[0] ?? '', $slugs );
		$slugs[1] = array_values( array_unique( $slugs[1] ) ); // The top-level link and "Overview" share a slug.
		$expected = array_values( array_intersect( Cradle\Core\Admin\Admin::MENU_ORDER, $slugs[1] ) );
		assert_same( $expected, array_values( array_intersect( $slugs[1], Cradle\Core\Admin\Admin::MENU_ORDER ) ), 'Menu order: ' . implode( ', ', $slugs[1] ) );
		assert_true( count( $expected ) >= 8, 'All Cradle pages present in the menu: ' . implode( ', ', $slugs[1] ) );
	}
);

cradle_test(
	'core: the Overview page lists modules and sets what happens to their data on deletion (administrators only, nonce-protected)',
	function () {
		$admin   = cradle_test_user( 'administrator' );
		$editor  = cradle_test_user( 'editor' );
		$before  = get_option( 'cradle_uninstall_policy', null );
		$module  = 'social';
		$session = cradle_test_cookies( $admin );
		$policy  = static function () {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'cradle_uninstall_policy', 'options' );
			$value = get_option( 'cradle_uninstall_policy', array() );
			return is_array( $value ) ? ( $value['social'] ?? 'retain' ) : 'retain';
		};
		try {
			$page = wp_remote_retrieve_body( cradle_test_http( 'GET', admin_url( 'admin.php?page=cradle' ), array(), $admin, $session ) );
			assert_contains( 'When deleted', $page );
			assert_contains( 'name="action" value="cradle_uninstall_policy"', $page );
			assert_contains( 'Remove its data', $page );
			assert_contains( 'Cradle parent theme installed', $page, 'Theme check on the same page.' );
			assert_contains( '<code>blocks</code>', $page, 'Module rows with their IDs.' );
			assert_contains( 'Generate status report', $page, 'Status report on the same page.' );
			assert_same( 1, substr_count( $page, 'Cradle Blocks' ), 'Each package is listed once (no separate checklist).' );

			$send = static function ( int $user, $user_session, string $value, string $mod, bool $nonce = true ) {
				$body = array(
					'action' => 'cradle_uninstall_policy',
					'module' => $mod,
					'policy' => $value,
				);
				if ( $nonce ) {
					$body['_wpnonce'] = cradle_test_nonce( $user, $user_session, 'cradle_uninstall_policy_' . $mod );
				}
				return cradle_test_http( 'POST', admin_url( 'admin-post.php' ), array( 'body' => $body ), $user, $user_session );
			};

			update_option( 'cradle_uninstall_policy', array(), false );
			$send( $admin, $session, 'purge', $module, false );
			assert_same( 'retain', $policy(), 'Missing nonce: nothing changes.' );
			$esession = cradle_test_cookies( $editor );
			$send( $editor, $esession, 'purge', $module );
			assert_same( 'retain', $policy(), 'Editors cannot choose to remove data.' );
			$r = $send( $admin, $session, 'purge', 'no-such-module' );
			assert_same( 400, wp_remote_retrieve_response_code( $r ), 'Unknown modules are refused.' );

			$r = $send( $admin, $session, 'purge', $module );
			assert_same( 302, wp_remote_retrieve_response_code( $r ) );
			assert_same( 'purge', $policy(), 'Administrator sets "Remove its data".' );
			$r = $send( $admin, $session, 'anything-else', $module );
			assert_same( 'retain', $policy(), 'Any other value means keep data.' );
		} finally {
			if ( null === $before ) {
				delete_option( 'cradle_uninstall_policy' );
			} else {
				update_option( 'cradle_uninstall_policy', $before, false );
			}
		}
	}
);

cradle_test(
	'core: capability grants happen once; an administrator removal survives reactivation',
	function () {
		$cap  = 'cradle_test_grant_once';
		$role = get_role( 'administrator' );
		Cradle\Core\Capabilities::revoke_everywhere( array( $cap ) );
		try {
			Cradle\Core\Capabilities::grant( $cap, array( 'administrator' ), 'tests' );
			assert_true( get_role( 'administrator' )->has_cap( $cap ), 'First grant adds the capability.' );
			$role->remove_cap( $cap );
			Cradle\Core\Capabilities::grant( $cap, array( 'administrator' ), 'tests' );
			assert_false( get_role( 'administrator' )->has_cap( $cap ), 'A deliberate removal is respected on reactivation.' );
			$granted = get_option( Cradle\Core\Capabilities::GRANTED_OPTION );
			assert_same( 'tests', $granted[ $cap ] ?? null, 'The grant is recorded with its owner.' );
		} finally {
			Cradle\Core\Capabilities::revoke_everywhere( array( $cap ) );
		}
		assert_false( isset( get_option( Cradle\Core\Capabilities::GRANTED_OPTION )[ $cap ] ), 'Purge forgets the grant so a reinstall grants again.' );
	}
);

cradle_test(
	'core: status report is redacted',
	function () {
		$report = Cradle\Core\Health::status_report();
		assert_contains( 'Cradle status report', $report );
		assert_contains( 'Theme overrides in use:', $report, 'Component overrides are reported (there is no developer screen).' );
		assert_contains( 'Private storage:', $report );
		assert_not_contains( DB_PASSWORD ?: '__no_password__', $report );
		assert_not_contains( wp_normalize_path( ABSPATH ), $report );
		assert_not_contains( get_option( 'admin_email' ), $report );
	}
);
