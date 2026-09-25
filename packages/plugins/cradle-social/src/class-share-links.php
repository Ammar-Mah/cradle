<?php
/**
 * Data access and render service for cradle/share-links (render contract
 * share-links-v1): links that share the current entry or page.
 *
 * Target resolution:
 * - `entry` (default): the explicit postId property, else the post in the
 *   render context (current post/page, or the Query Loop item). Only publicly
 *   viewable entries are shared; drafts, private and trashed entries render
 *   nothing (the editor preview of an editable entry is the one exception).
 * - `page`: the current request's public address (singular entry, front
 *   page, posts page, term/post-type/author archive). Search, date and 404
 *   views render nothing.
 *
 * Share title/text come from the site settings with the bounded placeholders
 * {title}, {url}, {site} expanded in ONE pass (a title containing "{url}" is
 * not expanded again). Values are plain text and reach markup only through
 * rawurlencode() inside fixed provider templates and esc_url()/esc_attr().
 *
 * @package Cradle\Social
 */

namespace Cradle\Social;

defined( 'ABSPATH' ) || exit;

final class Share_Links {

	const VIEW_SCRIPT = 'cradle-social-view';

	public static function render( array $p, array $context = array(), array $component = array() ): string {
		// An instance override may select and order any allowlisted share service (never a URL or template).
		$override = array_values( array_unique( array_filter( (array) ( $p['services'] ?? array() ), 'is_string' ) ) );
		$ids      = $override ? array_values( array_intersect( $override, Providers::share_ids() ) ) : Config::enabled_share_services();
		if ( is_feed() ) {
			// Feed readers run no scripts: keep plain links, drop the script-only actions.
			$ids = array_values( array_diff( $ids, Providers::ACTIONS ) );
		}
		if ( ! $ids ) {
			return '';
		}
		$target = self::target( $p, $context );
		if ( ! $target ) {
			return '';
		}
		$data = self::share_data( $target );
		$o    = Style::options( $p, 'share' );

		$items = array();
		foreach ( $ids as $id ) {
			if ( 'copy' === $id ) {
				$items[] = array(
					'id'      => 'copy',
					'icon'    => 'link',
					'action'  => 'copy',
					'data'    => array( 'url' => $data['url'] ),
					'visible' => __( 'Copy link', 'cradle-social' ),
					'name'    => __( 'Copy link', 'cradle-social' ),
				);
				continue;
			}
			if ( 'native' === $id ) {
				$items[] = array(
					'id'      => 'native',
					'icon'    => 'share',
					'action'  => 'native',
					'data'    => array(
						'url'   => $data['url'],
						'title' => $data['title'],
						'text'  => $data['text_only'],
					),
					'visible' => __( 'More', 'cradle-social' ),
					'name'    => __( 'More sharing options', 'cradle-social' ),
				);
				continue;
			}
			$href = Providers::share_url( $id, $data );
			if ( '' === $href ) {
				continue;
			}
			$label   = Providers::label( $id );
			$items[] = array(
				'id'      => $id,
				'icon'    => Providers::get( $id )['icon'],
				'href'    => $href,
				'new_tab' => $o['new_tab'],
				'visible' => $label,
				/* translators: %s: service name, for example X */
				'name'    => 'email' === $id ? __( 'Share by email', 'cradle-social' ) : sprintf( __( 'Share on %s', 'cradle-social' ), $label ),
			);
		}
		$interactive = (bool) array_filter( $items, static fn( $item ) => isset( $item['action'] ) );
		$html        = Markup::render(
			'share',
			$items,
			$o,
			$context,
			$interactive ? array(
				'data-cradle-social'        => 'share',
				'data-cradle-social-copied' => __( 'Link copied to the clipboard.', 'cradle-social' ),
				'data-cradle-social-failed' => __( 'Copying failed. Copy this address:', 'cradle-social' ),
			) : array()
		);
		// The progressive-enhancement script loads only where an interactive action is rendered.
		if ( '' !== $html && $interactive && wp_script_is( self::VIEW_SCRIPT, 'registered' ) ) {
			wp_enqueue_script( self::VIEW_SCRIPT );
		}
		return $html;
	}

	/**
	 * Resolves what is shared.
	 *
	 * @return array{url:string,title:string,media:string}|null
	 */
	public static function target( array $p, array $context ) {
		$post_id = (int) ( $p['postId'] ?? 0 );
		if ( ! $post_id && 'page' !== ( $p['target'] ?? 'entry' ) ) {
			$post_id = (int) ( $context['post_id'] ?? 0 );
		}
		if ( $post_id ) {
			return self::entry_target( $post_id );
		}
		return self::request_target( $context );
	}

	private static function entry_target( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		if ( ! is_post_publicly_viewable( $post ) && ! self::is_editor_preview( $post ) ) {
			return null;
		}
		$url = (string) get_permalink( $post );
		if ( '' === $url ) {
			return null;
		}
		$title = Providers::plain( (string) $post->post_title, 200 );
		$thumb = has_post_thumbnail( $post ) ? (string) wp_get_attachment_image_url( (int) get_post_thumbnail_id( $post ), 'large' ) : '';
		return array(
			'url'   => $url,
			'title' => '' !== $title ? $title : self::site_name(),
			'media' => $thumb,
		);
	}

	/**
	 * The current request's public address (target `page`, or no entry in context).
	 */
	private static function request_target( array $context ) {
		if ( self::is_rest() ) {
			$post_id = (int) ( $context['post_id'] ?? 0 );
			return $post_id ? self::entry_target( $post_id ) : array(
				'url'   => home_url( '/' ),
				'title' => self::site_name(),
				'media' => '',
			);
		}
		if ( is_singular() ) {
			return self::entry_target( (int) get_queried_object_id() );
		}
		if ( is_front_page() ) {
			return array(
				'url'   => home_url( '/' ),
				'title' => self::site_name(),
				'media' => '',
			);
		}
		if ( is_home() ) {
			$page = (int) get_option( 'page_for_posts' );
			return $page ? self::entry_target( $page ) : array(
				'url'   => home_url( '/' ),
				'title' => self::site_name(),
				'media' => '',
			);
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$taxonomy = get_taxonomy( $term->taxonomy );
				$link     = get_term_link( $term );
				if ( $taxonomy && $taxonomy->public && ! is_wp_error( $link ) ) {
					return array(
						'url'   => $link,
						'title' => Providers::plain( $term->name, 200 ),
						'media' => '',
					);
				}
			}
			return null;
		}
		if ( is_post_type_archive() ) {
			$type = get_queried_object();
			$link = $type instanceof \WP_Post_Type ? get_post_type_archive_link( $type->name ) : false;
			return $link ? array(
				'url'   => $link,
				'title' => Providers::plain( (string) $type->labels->name, 200 ),
				'media' => '',
			) : null;
		}
		if ( is_author() ) {
			$user = get_queried_object();
			return $user instanceof \WP_User ? array(
				'url'   => get_author_posts_url( $user->ID ),
				'title' => Providers::plain( $user->display_name, 200 ),
				'media' => '',
			) : null;
		}
		return null;
	}

	/**
	 * Expands the configured title/text for a target.
	 *
	 * @param array $target url, title, media.
	 */
	public static function share_data( array $target ): array {
		$vars  = array(
			'{title}' => $target['title'],
			'{url}'   => $target['url'],
			'{site}'  => self::site_name(),
		);
		$title = self::expand( (string) Config::get( 'share_title' ), $vars, 200 );
		$text  = self::expand( (string) Config::get( 'share_text' ), $vars, 500 );
		$title = '' !== $title ? $title : $target['title'];
		return array(
			'url'       => $target['url'],
			'title'     => $title,
			'text'      => $text,
			'text_only' => trim( preg_replace( '/\s+/u', ' ', str_replace( $target['url'], '', $text ) ) ),
			'media'     => $target['media'],
		);
	}

	/**
	 * Single-pass placeholder expansion on plain text (never HTML).
	 *
	 * @param string $template Stored title/text (validated on save; re-checked here).
	 * @param array  $vars     Placeholder => plain value.
	 * @param int    $max      Maximum length.
	 */
	public static function expand( string $template, array $vars, int $max ): string {
		if ( ! preg_match( Config::PLACEHOLDER_PATTERN, $template ) ) {
			$template = '{title}';
		}
		$template = Providers::plain( $template, $max );
		return Providers::plain( strtr( $template, array_intersect_key( $vars, array_flip( Config::PLACEHOLDERS ) ) ), $max );
	}

	private static function site_name(): string {
		$name = Providers::plain( (string) get_bloginfo( 'name' ), 200 );
		return '' !== $name ? $name : (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	private static function is_rest(): bool {
		return function_exists( 'wp_is_serving_rest_request' ) ? wp_is_serving_rest_request() : ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * The block editor preview (ServerSideRender) of an entry the user may edit.
	 */
	private static function is_editor_preview( \WP_Post $post ): bool {
		return self::is_rest() && current_user_can( 'edit_post', $post->ID );
	}
}
