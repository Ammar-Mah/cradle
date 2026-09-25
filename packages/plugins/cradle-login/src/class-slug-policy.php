<?php
/**
 * Validates a proposed login route slug: format first, then collisions with
 * WordPress routes, reserved query variables, feeds, sitemaps, the REST prefix,
 * rewrite-rule prefixes (post type and taxonomy bases, endpoints), existing
 * content, real files/directories and WooCommerce account endpoints.
 *
 * Runs only in privileged contexts (settings validation, admin screens).
 *
 * @package Cradle\Login
 */

namespace Cradle\Login;

defined( 'ABSPATH' ) || exit;

final class Slug_Policy {

	/**
	 * Words WordPress (or common hosting/SEO setups) already use at the site root, plus the
	 * WordPress reserved terms that are not public query variables.
	 */
	const RESERVED = array(
		'admin', 'login', 'logout', 'dashboard', 'register', 'signup', 'activate', 'lostpassword', 'retrievepassword',
		'resetpass', 'postpass', 'checkemail', 'confirmaction', 'feed', 'feeds', 'rss', 'rss2', 'rdf', 'atom', 'comments',
		'trackback', 'embed', 'sitemap', 'sitemaps', 'sitemap-index', 'robots', 'favicon', 'xmlrpc', 'index', 'readme',
		'license', 'search', 'author', 'category', 'tag', 'type', 'page', 'paged', 'attachment', 'preview', 'cron',
		'oembed', 'wordpress', 'cgi-bin', 'server-status', 'server-info', 'well-known',
		'attachment_id', 'calendar', 'custom', 'customized', 'day', 'debug', 'error', 'exact', 'fields', 'hour',
		'link_category', 'minute', 'monthnum', 'more', 'name', 'nav_menu', 'nonce', 'nopaging', 'offset', 'order',
		'orderby', 'pagename', 'perm', 'post', 'post_format', 'post_status', 'post_tag', 'post_type', 'posts', 'second',
		'sentence', 'static', 'status', 'subpost', 'subpost_id', 'taxonomy', 'term', 'terms', 'theme', 'title', 'types',
		'withcomments', 'withoutcomments', 'year',
	);

	/**
	 * Full validation of a candidate slug.
	 *
	 * @return true|\WP_Error
	 */
	public static function check( string $slug ) {
		if ( ! Config::is_valid_slug_format( $slug ) ) {
			return new \WP_Error( 'cradle_login_slug_format', __( 'Use 3–64 lowercase letters, digits and hyphens, starting and ending with a letter or digit.', 'cradle-login' ) );
		}
		if ( 0 === strpos( $slug, 'wp-' ) ) {
			return new \WP_Error( 'cradle_login_slug_reserved', __( 'Slugs starting with "wp-" are reserved for WordPress (wp-admin, wp-json, wp-content, wp-sitemap…).', 'cradle-login' ) );
		}
		$reserved = self::reserved_slugs();
		if ( isset( $reserved[ $slug ] ) ) {
			return new \WP_Error(
				'cradle_login_slug_reserved',
				/* translators: 1: slug, 2: what reserves it */
				sprintf( __( '"%1$s" is already used by %2$s.', 'cradle-login' ), $slug, $reserved[ $slug ] )
			);
		}
		$content = self::content_collision( $slug );
		if ( '' !== $content ) {
			return new \WP_Error(
				'cradle_login_slug_content',
				/* translators: 1: slug, 2: content description */
				sprintf( __( '"%1$s" collides with existing content: %2$s.', 'cradle-login' ), $slug, $content )
			);
		}
		if ( self::path_exists( $slug ) ) {
			return new \WP_Error(
				'cradle_login_slug_file',
				/* translators: %s: slug */
				sprintf( __( 'A file or directory named "%s" exists in the site root; the web server would serve it instead of WordPress.', 'cradle-login' ), $slug )
			);
		}
		return true;
	}

	/**
	 * Reserved words mapped to a human description of their owner.
	 *
	 * @return array<string,string>
	 */
	public static function reserved_slugs(): array {
		$out = array();
		foreach ( self::RESERVED as $word ) {
			$out[ $word ] = __( 'WordPress (reserved route or term)', 'cradle-login' );
		}

		$rest = function_exists( 'rest_get_url_prefix' ) ? trim( (string) rest_get_url_prefix(), '/' ) : 'wp-json';
		if ( '' !== $rest ) {
			$out[ $rest ] = __( 'the REST API prefix', 'cradle-login' );
		}

		global $wp, $wp_rewrite;
		if ( $wp instanceof \WP ) {
			/** This filter is documented in wp-includes/class-wp.php */
			$public = (array) apply_filters( 'query_vars', $wp->public_query_vars );
			foreach ( array_merge( $public, $wp->private_query_vars ) as $var ) {
				if ( is_string( $var ) ) {
					$out[ strtolower( $var ) ] = __( 'a WordPress query variable', 'cradle-login' );
				}
			}
		}

		if ( $wp_rewrite instanceof \WP_Rewrite ) {
			foreach ( (array) $wp_rewrite->feeds as $feed ) {
				if ( is_string( $feed ) ) {
					$out[ $feed ] = __( 'a WordPress feed', 'cradle-login' );
				}
			}
			foreach ( array( 'author_base', 'search_base', 'comments_base', 'pagination_base', 'feed_base', 'comments_pagination_base' ) as $base ) {
				if ( isset( $wp_rewrite->$base ) && is_string( $wp_rewrite->$base ) && '' !== $wp_rewrite->$base ) {
					$out[ trim( $wp_rewrite->$base, '/' ) ] = __( 'a WordPress permalink base', 'cradle-login' );
				}
			}
			foreach ( self::rewrite_prefixes( $wp_rewrite ) as $prefix ) {
				if ( ! isset( $out[ $prefix ] ) ) {
					$out[ $prefix ] = __( 'an existing rewrite rule (post type, taxonomy, endpoint or plugin route)', 'cradle-login' );
				}
			}
		}

		foreach ( array( 'category_base', 'tag_base' ) as $option ) {
			$base = trim( (string) get_option( $option ), '/' );
			if ( '' !== $base ) {
				$out[ strtok( $base, '/' ) ] = __( 'a WordPress permalink base', 'cradle-login' );
			}
		}

		foreach ( self::woocommerce_endpoints() as $endpoint ) {
			$out[ $endpoint ] = __( 'a WooCommerce account or checkout endpoint', 'cradle-login' );
		}

		/**
		 * Filters additional reserved login route slugs (slug => owner description).
		 *
		 * @param array<string,string> $extra Extra reserved slugs.
		 */
		$extra = apply_filters( 'cradle_login_reserved_slugs', array() );
		if ( is_array( $extra ) ) {
			foreach ( $extra as $word => $owner ) {
				if ( is_string( $word ) && '' !== $word ) {
					$out[ strtolower( $word ) ] = is_string( $owner ) && '' !== $owner ? $owner : __( 'another component', 'cradle-login' );
				}
			}
		}
		return $out;
	}

	/**
	 * Static leading path segments of the current rewrite rules (for example "category",
	 * "product", "wp-json", "orders" from an endpoint rule).
	 *
	 * @return string[]
	 */
	public static function rewrite_prefixes( \WP_Rewrite $wp_rewrite ): array {
		$rules = $wp_rewrite->wp_rewrite_rules();
		if ( ! is_array( $rules ) ) {
			return array();
		}
		$out = array();
		foreach ( array_keys( $rules ) as $regex ) {
			$regex = ltrim( (string) $regex, '^' );
			if ( ! preg_match( '/^([a-z0-9_-]+)(?=$|[\/\\\\$?(.])/i', $regex, $match ) ) {
				continue;
			}
			$out[ strtolower( $match[1] ) ] = true;
		}
		return array_keys( $out );
	}

	/**
	 * Describes existing content whose URL would be shadowed by the route, or ''.
	 */
	public static function content_collision( string $slug ): string {
		global $wpdb;
		$types = array_values( array_unique( array_merge( array( 'post', 'page', 'attachment' ), get_post_types( array( 'public' => true ) ) ) ) );
		$types = array_values( array_filter( $types, static fn( $type ) => is_string( $type ) && preg_match( '/^[a-z0-9_-]{1,20}$/', $type ) ) );
		if ( $types ) {
			$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from a fixed count.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_type, post_status FROM {$wpdb->posts} WHERE post_name = %s AND post_type IN ( {$placeholders} ) AND post_status NOT IN ( 'auto-draft', 'trash' ) LIMIT 1", array_merge( array( $slug ), $types ) ) );
			if ( $row ) {
				/* translators: 1: post type, 2: post ID, 3: post status */
				return sprintf( __( '%1$s #%2$d (%3$s)', 'cradle-login' ), $row->post_type, (int) $row->ID, $row->post_status );
			}
		}
		$post_id = url_to_postid( home_url( '/' . $slug . '/' ) );
		if ( $post_id > 0 ) {
			/* translators: %d: post ID */
			return sprintf( __( 'the permalink of post #%d', 'cradle-login' ), $post_id );
		}
		return '';
	}

	/**
	 * Whether a real file or directory with this name exists where the web server would find it.
	 */
	public static function path_exists( string $slug ): bool {
		$roots = array( ABSPATH );
		if ( ! function_exists( 'get_home_path' ) && is_file( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( function_exists( 'get_home_path' ) ) {
			$roots[] = get_home_path();
		}
		foreach ( array_unique( $roots ) as $root ) {
			if ( file_exists( trailingslashit( $root ) . $slug ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * WooCommerce account and checkout endpoint slugs, only when WooCommerce is active.
	 *
	 * @return string[]
	 */
	public static function woocommerce_endpoints(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}
		$out = array();
		if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->query ) && is_object( WC()->query ) && method_exists( WC()->query, 'get_query_vars' ) ) {
			foreach ( (array) WC()->query->get_query_vars() as $var ) {
				if ( is_string( $var ) && '' !== $var ) {
					$out[] = strtolower( trim( $var, '/' ) );
				}
			}
		}
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'woocommerce_myaccount_' ) . '%' . $wpdb->esc_like( '_endpoint' ), $wpdb->esc_like( 'woocommerce_checkout_' ) . '%' . $wpdb->esc_like( '_endpoint' ) ) );
		foreach ( (array) $rows as $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				$out[] = strtolower( trim( $value, '/' ) );
			}
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}
}
