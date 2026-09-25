<?php
/**
 * Lifecycle contracts (CR-01, CR-02, CR-28): parent updates preserve child/site/admin state,
 * theme switches keep content, missing core does not fatal.
 *
 * These tests temporarily change the shared sandbox (theme switch, package replacement,
 * core deactivation) and always restore it. Run them when no other work uses the sandbox:
 *   php tests/run.php --suite=lifecycle
 */

function cradle_lifecycle_tree_hashes( string $dir ): array {
	$out = array();
	if ( ! is_dir( $dir ) ) {
		return $out;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( $file->isFile() ) {
			$out[ str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) + 1 ) ) ] = hash_file( 'sha1', $file->getPathname() );
		}
	}
	ksort( $out );
	return $out;
}

function cradle_lifecycle_copy_tree( string $src, string $dest ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
	if ( ! is_dir( $dest ) ) {
		mkdir( $dest, 0775, true );
	}
	foreach ( $it as $item ) {
		$target = $dest . '/' . substr( $item->getPathname(), strlen( $src ) + 1 );
		if ( $item->isDir() ) {
			if ( ! is_dir( $target ) ) {
				mkdir( $target, 0775, true );
			}
		} else {
			copy( $item->getPathname(), $target );
		}
	}
}

function cradle_lifecycle_remove_tree( string $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $dir );
}

function cradle_lifecycle_sync( string $args ) {
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( CRADLE_TEST_ROOT . '/scripts/sync.php' ) . ' ' . $args . ' --quiet', $out, $code );
	return $code;
}

cradle_test(
	'lifecycle: fresh foundation has valid templates and no invented content from Cradle packages',
	function () {
		$parent = wp_get_theme( 'cradle' );
		assert_true( $parent->exists() && ! $parent->errors(), 'Parent theme is valid.' );
		assert_true( wp_is_block_theme() || $parent->is_block_theme() );
		foreach ( array( 'index', 'single', 'page', 'archive', 'search', '404' ) as $slug ) {
			assert_true( is_file( $parent->get_template_directory() . "/templates/{$slug}.html" ), "Parent template {$slug} exists." );
		}
		// Core and framework add-ons never create posts on activation: nothing framework-owned is published
		// except content that carries an explicit provisioning or generation marker.
		global $wpdb;
		$unmarked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type IN ('post','page') AND p.post_status = 'publish' AND p.post_content LIKE '%wp:cradle/%' AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key IN ('_cradle_source_key','_cradle_generated'))" ); // phpcs:ignore
		assert_same( 0, $unmarked, 'Published Cradle-block content without ownership markers would indicate invented content.' );
	}
);

cradle_test(
	'lifecycle: parent theme update preserves child files, the site content model, user Global Styles and DB templates',
	function () {
		if ( 'cradle-site' !== get_stylesheet() ) {
			skip_test( 'The sandbox child theme is not active.' );
		}
		$admin = cradle_test_user( 'administrator' );
		wp_set_current_user( $admin );
		$themes      = get_theme_root();
		$child_dir   = $themes . '/cradle-site';
		$parent_dir  = $themes . '/cradle';

		// Administrator overrides: user Global Styles and a DB-customized header part.
		$gs_id     = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$gs_before = get_post( $gs_id )->post_content;
		$gs_data   = json_decode( $gs_before, true ) ?: array( 'version' => 3, 'isGlobalStylesUserThemeJSON' => true );
		$gs_data['styles']['color']['background'] = '#fafafa';
		wp_update_post( wp_slash( array( 'ID' => $gs_id, 'post_content' => wp_json_encode( $gs_data ) ) ) );
		$part_id = wp_insert_post(
			array(
				'post_type'    => 'wp_template_part',
				'post_name'    => 'header',
				'post_title'   => 'Header',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Customized header (lifecycle test)</p><!-- /wp:paragraph -->',
				'tax_input'    => array(
					'wp_theme'              => array( 'cradle-site' ),
					'wp_template_part_area' => array( 'header' ),
				),
			)
		);
		wp_set_object_terms( $part_id, 'cradle-site', 'wp_theme' );
		wp_set_object_terms( $part_id, 'header', 'wp_template_part_area' );

		$child_before  = cradle_lifecycle_tree_hashes( $child_dir );
		$model_before  = get_option( 'cradle_content_definitions' );
		$backup        = $parent_dir . '-lifecycle-backup';
		cradle_lifecycle_copy_tree( $parent_dir, $backup );
		try {
			// Simulate a package update the way the WordPress upgrader does: remove the old
			// package directory and install the new version's files in its place.
			$new = sys_get_temp_dir() . '/cradle-parent-next-' . wp_generate_password( 6, false );
			cradle_lifecycle_copy_tree( $parent_dir, $new );
			$style = file_get_contents( $new . '/style.css' );
			file_put_contents( $new . '/style.css', preg_replace( '/^Version:\s*\S+/m', 'Version: 9.9.9-test', $style ) );
			cradle_lifecycle_remove_tree( $parent_dir );
			cradle_lifecycle_copy_tree( $new, $parent_dir );
			cradle_lifecycle_remove_tree( $new );
			wp_clean_themes_cache();

			assert_same( '9.9.9-test', wp_get_theme( 'cradle' )->get( 'Version' ), 'Parent was replaced.' );
			assert_same( $child_before, cradle_lifecycle_tree_hashes( $child_dir ), 'Child theme files unchanged.' );
			assert_same( $model_before, get_option( 'cradle_content_definitions' ), 'Site content model (Cradle Content definitions) unchanged.' );
			clean_post_cache( $gs_id );
			assert_same( '#fafafa', json_decode( get_post( $gs_id )->post_content, true )['styles']['color']['background'] ?? null, 'User Global Styles unchanged.' );
			assert_true( 'publish' === get_post_status( $part_id ), 'DB template part unchanged.' );
			$home = cradle_test_http( 'GET', home_url( '/' ) );
			assert_same( 200, wp_remote_retrieve_response_code( $home ) );
			assert_contains( 'Customized header (lifecycle test)', wp_remote_retrieve_body( $home ), 'Frontend uses the administrator template override after the update.' );
		} finally {
			cradle_lifecycle_remove_tree( $parent_dir );
			rename( $backup, $parent_dir );
			wp_clean_themes_cache();
			wp_delete_post( $part_id, true );
			wp_update_post( wp_slash( array( 'ID' => $gs_id, 'post_content' => $gs_before ) ) );
		}
	}
);

cradle_test(
	'lifecycle: theme switch keeps Cradle Content types (Projects) reachable',
	function () {
		$previous = get_stylesheet();
		$project  = get_posts( array( 'post_type' => 'project', 'numberposts' => 1 ) );
		if ( ! $project ) {
			skip_test( 'No project content provisioned.' );
		}
		switch_theme( 'twentytwentyfive' );
		try {
			$r = cradle_test_http( 'GET', get_permalink( $project[0] ) );
			assert_same( 200, wp_remote_retrieve_response_code( $r ), 'Project single renders under another theme (' . get_permalink( $project[0] ) . ' → ' . wp_remote_retrieve_header( $r, 'location' ) . ' by ' . wp_remote_retrieve_header( $r, 'x-redirect-by' ) . ').' );
			assert_contains( get_the_title( $project[0] ), wp_remote_retrieve_body( $r ) );
			assert_same( 200, wp_remote_retrieve_response_code( cradle_test_http( 'GET', home_url( '/projects/' ) ) ) );
		} finally {
			switch_theme( $previous );
		}
		assert_same( $previous, get_stylesheet() );
	}
);

cradle_test(
	'lifecycle: missing core never fatals; add-ons pause, content types pause without data loss and return',
	function () {
		$core = 'cradle/cradle.php';
		if ( ! is_plugin_active( $core ) ) {
			skip_test( 'Core is not active.' );
		}
		$dir    = WP_PLUGIN_DIR . '/cradle';
		$hidden = WP_PLUGIN_DIR . '/cradle-lifecycle-missing';
		$active = get_option( 'active_plugins' );
		// Simulate an FTP deletion (not a deactivation): the directory disappears while still listed active.
		rename( $dir, $hidden );
		try {
			$admin = cradle_test_user( 'administrator' );
			foreach ( array( home_url( '/' ), home_url( '/projects/' ), admin_url( 'plugins.php' ), admin_url( 'index.php' ) ) as $url ) {
				$r    = cradle_test_http( 'GET', $url, array(), $admin );
				$body = wp_remote_retrieve_body( $r );
				// Projects are a Cradle Content type: with core missing they are paused (404), never broken.
				$ok = home_url( '/projects/' ) === $url ? array( 200, 302, 404 ) : array( 200, 302 );
				assert_true( in_array( wp_remote_retrieve_response_code( $r ), $ok, true ), "{$url} responds." );
				assert_not_contains( 'Fatal error', $body, "{$url} has no fatal error." );
				assert_not_contains( 'critical error', $body, "{$url} has no critical error screen." );
			}
		} finally {
			rename( $hidden, $dir );
		}
		// WordPress deactivates plugins whose files vanished when plugins.php loads; restore the exact list.
		wp_cache_delete( 'alloptions', 'options' );
		update_option( 'active_plugins', $active );
		wp_cache_delete( 'alloptions', 'options' );
		assert_same( $active, get_option( 'active_plugins' ), 'Active plugins restored after the simulation.' );
		if ( get_posts( array( 'post_type' => 'project', 'numberposts' => 1 ) ) ) {
			assert_same( 200, wp_remote_retrieve_response_code( cradle_test_http( 'GET', home_url( '/projects/' ) ) ), 'Projects are reachable again once core is back.' );
		}
	}
);
