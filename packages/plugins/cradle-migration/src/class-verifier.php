<?php
/**
 * Post-restore verification (northstar §15.9). A restore is only `completed` when
 * every critical check passes; extraction alone is never success.
 *
 * Checks run against the live destination through loopback HTTP (fresh requests that
 * load the restored plugins and theme) plus direct inspection of restored data.
 *
 * @package Cradle\Migration
 */

namespace Cradle\Migration;

defined( 'ABSPATH' ) || exit;

final class Verifier {

	/**
	 * @return array{passed:bool,checks:array,failures:string[],admins:string[]}
	 */
	public static function run( array $job, array $m, string $dir ): array {
		global $wpdb;
		wp_cache_flush();
		$checks   = array();
		$failures = array();
		$add      = static function ( string $id, string $label, string $status, string $detail, bool $critical = true ) use ( &$checks, &$failures ) {
			$checks[] = array(
				'id'       => $id,
				'label'    => $label,
				'status'   => $status,
				'detail'   => $detail,
				'critical' => $critical,
			);
			if ( 'fail' === $status && $critical ) {
				$failures[] = $label . ': ' . $detail;
			}
		};
		$home = untrailingslashit( (string) get_option( 'home' ) );
		$get  = static function ( string $url, int $redirects = 3 ) {
			return wp_remote_get(
				$url,
				array(
					'timeout'     => 30,
					'redirection' => $redirects,
					'sslverify'   => false,
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);
		};
		$code = static function ( $response ): int {
			return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		};
		$why  = static function ( $response ): string {
			return is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code( $response );
		};

		// Front page.
		$r = $get( $home . '/' );
		$add( 'front', 'Front page', 200 === $code( $r ) ? 'pass' : 'fail', $why( $r ) );

		$posts = $wpdb->posts;
		// A content page.
		$page = $wpdb->get_row( "SELECT ID, post_title FROM {$posts} WHERE post_type = 'page' AND post_status = 'publish' ORDER BY ID DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $page ) {
			$r = $get( $home . '/?page_id=' . (int) $page->ID );
			$add( 'page', 'Content page', 200 === $code( $r ) ? 'pass' : 'fail', $why( $r ) . ' (page ' . (int) $page->ID . ')' );
		} else {
			$add( 'page', 'Content page', 'skip', 'No published page in the restored data.', false );
		}
		// A single post.
		$post = $wpdb->get_row( "SELECT ID, post_title FROM {$posts} WHERE post_type = 'post' AND post_status = 'publish' ORDER BY ID DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $post ) {
			$r = $get( $home . '/?p=' . (int) $post->ID );
			$add( 'single', 'Single post', 200 === $code( $r ) ? 'pass' : 'fail', $why( $r ) . ' (post ' . (int) $post->ID . ')' );
			// Search for a word from its title.
			$words = preg_split( '/\s+/', trim( (string) $post->post_title ) );
			$word  = (string) ( $words[0] ?? '' );
			if ( '' !== $word ) {
				$r  = $get( $home . '/?s=' . rawurlencode( $word ) );
				$ok = 200 === $code( $r ) && false !== stripos( (string) wp_remote_retrieve_body( $r ), esc_html( (string) $post->post_title ) );
				$add( 'search', 'Search', $ok ? 'pass' : 'fail', $why( $r ) . ( $ok ? ' and the post is listed' : ' (post title not found in results)' ) );
			}
		} else {
			$add( 'single', 'Single post', 'skip', 'No published post in the restored data.', false );
		}
		// Archive.
		$term = $wpdb->get_var( "SELECT t.term_id FROM {$wpdb->term_taxonomy} t WHERE t.taxonomy = 'category' AND t.count > 0 ORDER BY t.term_id LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $term ) {
			$r = $get( $home . '/?cat=' . (int) $term );
			$add( 'archive', 'Category archive', 200 === $code( $r ) ? 'pass' : 'fail', $why( $r ) );
		}
		// Media.
		$att = $wpdb->get_row( "SELECT p.ID, m.meta_value AS file FROM {$posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file' WHERE p.post_type = 'attachment' ORDER BY p.ID DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $att ) {
			$uploads = wp_upload_dir( null, false );
			$path    = $uploads['basedir'] . '/' . $att->file;
			$r       = $get( $uploads['baseurl'] . '/' . str_replace( '%2F', '/', rawurlencode( (string) $att->file ) ), 0 );
			$ok      = 200 === $code( $r ) && is_file( $path ) && strlen( (string) wp_remote_retrieve_body( $r ) ) === (int) filesize( $path );
			$add( 'media', 'Media file', $ok ? 'pass' : 'fail', $why( $r ) . ' for ' . basename( (string) $att->file ) );
		} else {
			$add( 'media', 'Media file', 'skip', 'No attachments in the restored data.', false );
		}
		// REST.
		$r    = $get( $home . '/wp-json/' );
		$body = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		$ok   = 200 === $code( $r ) && is_array( $body ) && untrailingslashit( (string) ( $body['home'] ?? $body['url'] ?? '' ) ) === $home;
		$add( 'rest', 'REST API index', $ok ? 'pass' : 'fail', $why( $r ) . ( $ok ? ', reports the destination URL' : ', unexpected index' ) );
		$r = $get( $home . '/wp-json/wp/v2/types' );
		$types = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		$add( 'types', 'Content type registrations', 200 === $code( $r ) && is_array( $types ) && isset( $types['post'], $types['page'] ) ? 'pass' : 'fail', is_array( $types ) ? 'Public types: ' . implode( ', ', array_keys( $types ) ) : $why( $r ), false );
		// Login routes.
		$r = $get( site_url( 'wp-login.php' ), 0 );
		$add( 'login', 'Standard login screen reachable', 200 === $code( $r ) ? 'pass' : 'fail', $why( $r ) );
		$r = $get( admin_url( '/' ), 0 );
		$add( 'admin', 'Admin area requires sign-in', in_array( $code( $r ), array( 301, 302 ), true ) ? 'pass' : 'fail', $why( $r ), false );
		// Theme.
		$stylesheet = (string) get_option( 'stylesheet' );
		$theme      = wp_get_theme( $stylesheet );
		$ok         = $theme->exists() && ! $theme->errors();
		$detail     = $stylesheet . ( $theme->parent() ? ' (child of ' . $theme->get_template() . ')' : '' );
		if ( $ok && (string) get_option( 'template' ) !== $stylesheet ) {
			$ok = wp_get_theme( (string) get_option( 'template' ) )->exists();
		}
		$add( 'theme', 'Active theme and parent present', $ok ? 'pass' : 'fail', $detail );
		// Plugins.
		$missing = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $basename ) {
			if ( ! is_file( WP_PLUGIN_DIR . '/' . $basename ) ) {
				$missing[] = $basename;
			}
		}
		$add( 'plugins', 'Active plugins available', $missing ? 'fail' : 'pass', $missing ? 'Missing: ' . implode( ', ', $missing ) : count( (array) get_option( 'active_plugins', array() ) ) . ' active' );
		// Administrators.
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => array( 'user_login' ),
				'number' => 20,
			)
		);
		$logins = array_map( static fn( $u ) => (string) $u->user_login, $admins );
		$add( 'admins', 'Administrator accounts', $logins ? 'pass' : 'fail', $logins ? 'Sign in with: ' . implode( ', ', $logins ) . ' (source passwords).' : 'No administrator account exists.' );
		// Prefix transformation.
		$src_prefix = (string) ( $m['source']['tablePrefix'] ?? $wpdb->prefix );
		$roles_ok   = is_array( get_option( $wpdb->prefix . 'user_roles' ) );
		$leftover   = $src_prefix !== $wpdb->prefix ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", $src_prefix . 'capabilities' ) ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$add( 'prefix', 'Table prefix change', $roles_ok && 0 === $leftover ? 'pass' : 'fail', sprintf( '%1$suser_roles readable; %2$d user capability keys left with the old prefix.', $wpdb->prefix, $leftover ) );
		// Old URL residue in content (GUIDs are intentionally untouched).
		$src_home = untrailingslashit( (string) ( $m['source']['homeUrl'] ?? '' ) );
		if ( '' !== $src_home && $src_home !== $home ) {
			$like = '%' . $wpdb->esc_like( $src_home ) . '/%';
			$n    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts} WHERE post_content LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$add( 'residue', 'Old URL left in post content', 0 === $n ? 'pass' : 'warn', $n . ' post(s) still contain ' . $src_home . '/', false );
		}
		// Serialized option integrity.
		$bad  = 0;
		$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_value LIKE 'a:%' LIMIT 5000" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $row ) {
			if ( Serialized::looks_serialized( (string) $row->option_value ) && null === Serialized::parse( (string) $row->option_value ) ) {
				++$bad;
			}
		}
		$add( 'serialized', 'Serialized options parse', 0 === $bad ? 'pass' : 'fail', $bad . ' malformed of ' . count( (array) $rows ) . ' checked' );
		// Private storage (staging and backups) is not readable over HTTP.
		$add( 'private', 'Staging and backups not publicly readable', ...self::probe_private( $dir ) );
		// Safety profile.
		$hold  = Guard::hold();
		$login = get_option( 'cradle_login_settings' );
		$add( 'safety', 'Destination safety hold', $hold ? 'pass' : 'fail', $hold ? 'Email, WP-Cron and outbound HTTP are paused until an administrator releases the hold.' : 'The safety hold is not active.' );
		if ( is_array( $login ) ) {
			$add( 'login_route', 'Imported custom login route disabled', empty( $login['enabled'] ) ? 'pass' : 'fail', empty( $login['enabled'] ) ? 'Standard wp-login.php is active until an administrator re-enables the custom route.' : 'The imported custom login route is still enabled.' );
		}
		$cron   = _get_cron_array();
		$events = 0;
		foreach ( (array) $cron as $hooks ) {
			$events += is_array( $hooks ) ? count( $hooks ) : 0;
		}
		$add( 'cron', 'Scheduled tasks under the destination profile', 'pass', $events . ' scheduled hook(s) held until release.', false );
		// Fonts (Font Library) files present.
		$fonts   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$posts} WHERE post_type = 'wp_font_face'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$missing = 0;
		if ( $fonts ) {
			$files = $wpdb->get_col( "SELECT m.meta_value FROM {$wpdb->postmeta} m JOIN {$posts} p ON p.ID = m.post_id WHERE p.post_type = 'wp_font_face' AND m.meta_key = '_wp_font_face_file'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$fdir  = function_exists( 'wp_get_font_dir' ) ? wp_get_font_dir()['path'] : WP_CONTENT_DIR . '/uploads/fonts';
			foreach ( (array) $files as $file ) {
				if ( ! is_file( $fdir . '/' . $file ) ) {
					++$missing;
				}
			}
		}
		$add( 'fonts', 'Font Library files', 0 === $missing ? 'pass' : 'fail', $fonts . ' font face(s), ' . $missing . ' missing file(s)', false );
		$add( 'translations', 'Translation catalogs', 'skip', 'Language files are part of wp-content/languages, which Cradle does not transplant; the Cradle translation catalogs are database content and were restored with it.', false );

		return array(
			'passed'   => ! $failures,
			'checks'   => $checks,
			'failures' => $failures,
			'admins'   => $logins,
			'time'     => gmdate( 'c' ),
		);
	}

	/**
	 * @return array{0:string,1:string} status and detail
	 */
	private static function probe_private( string $dir ): array {
		if ( ! function_exists( 'cradle' ) || ! cradle() ) {
			return array( 'fail', 'Cradle core is not running.' );
		}
		$root = cradle()->storage()->private_root();
		if ( is_wp_error( $root ) ) {
			return array( 'fail', $root->get_error_message() );
		}
		if ( 'outside-webroot' === $root['mode'] ) {
			return array( 'pass', 'Private storage is outside the web root.' );
		}
		$token = wp_generate_password( 24, false, false );
		$name  = 'probe-' . strtolower( wp_generate_password( 10, false, false ) ) . '.txt';
		file_put_contents( $dir . '/' . $name, $token ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$rel = ltrim( substr( wp_normalize_path( $dir ), strlen( wp_normalize_path( $root['path'] ) ) ), '/' );
		$url = $root['url'] . '/' . $rel . '/' . $name;
		$r   = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'sslverify'   => false,
			)
		);
		wp_delete_file( $dir . '/' . $name );
		$leak = ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) && false !== strpos( (string) wp_remote_retrieve_body( $r ), $token );
		$code = is_wp_error( $r ) ? $r->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code( $r );
		$core = cradle()->storage()->verify_http_denial( true );
		if ( $leak || 'protected' !== $core['status'] ) {
			return array( 'fail', 'A probe file in the job directory was readable over HTTP (' . $code . ').' );
		}
		return array( 'pass', 'Direct request to a probe file in the job directory returned ' . $code . '.' );
	}
}
