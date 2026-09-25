<?php
/**
 * Allowlisted provider adapter registry.
 *
 * Every provider is declared in trusted PHP: an ID, a label, an icon key from
 * the bundled icon set, an optional profile-URL policy and an optional share
 * builder made of a FIXED https (or mailto) template whose only variable parts
 * are the placeholders in PLACEHOLDERS, each substituted with a rawurlencode()d
 * value. Editors and administrators choose among providers and supply profile
 * URLs; they can never supply a template, host or protocol.
 *
 * Trusted code may adjust the registry with the `cradle_social/providers`
 * filter. Filtered definitions pass the same validation as the built-in ones;
 * invalid entries are dropped and reported by problems().
 *
 * Provider share formats change over time. docs/providers.md records each
 * template and states that none was live-verified from this sandbox.
 *
 * @package Cradle\Social
 */

namespace Cradle\Social;

defined( 'ABSPATH' ) || exit;

final class Providers {

	const ID_PATTERN = '/^[a-z][a-z0-9-]{0,30}$/';

	/** Placeholders allowed in share templates (query string only). */
	const PLACEHOLDERS = array( '{url}', '{title}', '{text}', '{text_url}', '{media}' );

	/** Share actions handled locally by the view script (not providers). */
	const ACTIONS = array( 'copy', 'native' );

	const HOST_PATTERN = '/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

	const MAX_URL_LENGTH = 500;

	/** @var array<string,array>|null */
	private static $resolved = null;

	/** @var array<string,string> */
	private static $problems = array();

	/**
	 * Built-in, reviewed provider definitions (see docs/providers.md).
	 */
	public static function builtin(): array {
		return array(
			'facebook'  => array(
				'label'   => 'Facebook',
				'icon'    => 'facebook',
				'profile' => array( 'hosts' => array( 'facebook.com', 'www.facebook.com', 'm.facebook.com' ) ),
				'share'   => array( 'template' => 'https://www.facebook.com/sharer/sharer.php?u={url}' ),
			),
			'x'         => array(
				'label'   => 'X',
				'icon'    => 'x',
				'profile' => array( 'hosts' => array( 'x.com', 'www.x.com', 'twitter.com', 'www.twitter.com' ) ),
				'share'   => array( 'template' => 'https://x.com/intent/tweet?text={text}&url={url}' ),
			),
			'linkedin'  => array(
				'label'   => 'LinkedIn',
				'icon'    => 'linkedin',
				'profile' => array( 'hosts' => array( 'linkedin.com', 'www.linkedin.com' ) ),
				'share'   => array( 'template' => 'https://www.linkedin.com/sharing/share-offsite/?url={url}' ),
			),
			'bluesky'   => array(
				'label'   => 'Bluesky',
				'icon'    => 'bluesky',
				'profile' => array(
					'hosts' => array( 'bsky.app' ),
					'path'  => '#^/profile/[A-Za-z0-9.:_-]{3,253}/?$#',
				),
				'share'   => array( 'template' => 'https://bsky.app/intent/compose?text={text_url}' ),
			),
			'mastodon'  => array(
				'label'   => 'Mastodon',
				'icon'    => 'mastodon',
				// Federated: any public https instance, but only the /@user profile path.
				// Sharing needs the visitor's own instance; no instance-safe share flow is offered.
				'profile' => array(
					'federated' => true,
					'path'      => '#^/@[A-Za-z0-9_]{1,30}(?:@[A-Za-z0-9.-]{1,253})?/?$#',
				),
				'share'   => null,
			),
			'instagram' => array(
				'label'   => 'Instagram',
				'icon'    => 'instagram',
				'profile' => array( 'hosts' => array( 'instagram.com', 'www.instagram.com' ) ),
				'share'   => null,
			),
			'youtube'   => array(
				'label'   => 'YouTube',
				'icon'    => 'youtube',
				'profile' => array( 'hosts' => array( 'youtube.com', 'www.youtube.com', 'm.youtube.com' ) ),
				'share'   => null,
			),
			'whatsapp'  => array(
				'label'   => 'WhatsApp',
				'icon'    => 'whatsapp',
				'profile' => array( 'hosts' => array( 'wa.me', 'whatsapp.com', 'www.whatsapp.com' ) ),
				'share'   => array( 'template' => 'https://wa.me/?text={text_url}' ),
			),
			'telegram'  => array(
				'label'   => 'Telegram',
				'icon'    => 'telegram',
				'profile' => array( 'hosts' => array( 't.me', 'telegram.me' ) ),
				'share'   => array( 'template' => 'https://t.me/share/url?url={url}&text={text}' ),
			),
			'reddit'    => array(
				'label'   => 'Reddit',
				'icon'    => 'reddit',
				'profile' => array( 'hosts' => array( 'reddit.com', 'www.reddit.com' ) ),
				'share'   => array( 'template' => 'https://www.reddit.com/submit?url={url}&title={title}' ),
			),
			'pinterest' => array(
				'label'   => 'Pinterest',
				'icon'    => 'pinterest',
				'profile' => array( 'hosts' => array( 'pinterest.com', 'www.pinterest.com' ) ),
				'share'   => array( 'template' => 'https://www.pinterest.com/pin/create/button/?url={url}&media={media}&description={text}' ),
			),
			'email'     => array(
				'label'   => 'Email',
				'icon'    => 'email',
				'profile' => array( 'mailto' => true ),
				'share'   => array( 'template' => 'mailto:?subject={title}&body={text_url}' ),
			),
		);
	}

	/**
	 * The validated registry: built-in providers plus trusted filter changes.
	 *
	 * @return array<string,array>
	 */
	public static function all(): array {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}
		self::$problems = array();
		/**
		 * Filters the provider registry. Trusted PHP only (plugins/themes); never
		 * exposed to settings, blocks or requests. Entries are re-validated.
		 *
		 * @param array $providers id => definition (see Providers::builtin()).
		 */
		$filtered = apply_filters( 'cradle_social/providers', self::builtin() );
		$out      = array();
		foreach ( is_array( $filtered ) ? $filtered : array() as $id => $definition ) {
			$valid = self::validate_definition( $id, $definition );
			if ( is_wp_error( $valid ) ) {
				self::$problems[ is_string( $id ) ? $id : '#' ] = $valid->get_error_message();
				continue;
			}
			$out[ $id ] = $valid;
		}
		self::$resolved = $out;
		return $out;
	}

	/**
	 * Drops the per-request cache (after changing the filter, and in tests).
	 */
	public static function flush() {
		self::$resolved = null;
	}

	public static function problems(): array {
		self::all();
		return self::$problems;
	}

	public static function get( string $id ) {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * Translatable display label. Brand names stay as declared; Email is translated.
	 */
	public static function label( string $id ): string {
		if ( 'email' === $id ) {
			return __( 'Email', 'cradle-social' );
		}
		if ( 'copy' === $id ) {
			return __( 'Copy link', 'cradle-social' );
		}
		if ( 'native' === $id ) {
			return __( 'More sharing options', 'cradle-social' );
		}
		$provider = self::get( $id );
		return $provider ? $provider['label'] : $id;
	}

	/** @return string[] Provider IDs that support profile links, in registry order. */
	public static function profile_ids(): array {
		return array_keys( array_filter( self::all(), static fn( $p ) => null !== $p['profile'] ) );
	}

	/** @return string[] Provider IDs with a share builder plus the local actions, in registry order. */
	public static function share_ids(): array {
		return array_merge( array_keys( array_filter( self::all(), static fn( $p ) => null !== $p['share'] ) ), self::ACTIONS );
	}

	/**
	 * Validates one provider definition from trusted code.
	 *
	 * @param mixed $id         Provider ID.
	 * @param mixed $definition Definition array.
	 * @return array|\WP_Error Normalized definition.
	 */
	public static function validate_definition( $id, $definition ) {
		if ( ! is_string( $id ) || ! preg_match( self::ID_PATTERN, $id ) || in_array( $id, self::ACTIONS, true ) ) {
			return new \WP_Error( 'cradle_social_provider_id', 'Provider IDs must match ' . self::ID_PATTERN . ' and not be a reserved action.' );
		}
		if ( ! is_array( $definition ) ) {
			return new \WP_Error( 'cradle_social_provider', 'Provider definitions must be arrays.' );
		}
		$label = $definition['label'] ?? '';
		if ( ! is_string( $label ) || '' === trim( $label ) || mb_strlen( $label ) > 60 ) {
			return new \WP_Error( 'cradle_social_provider_label', 'A provider needs a label of at most 60 characters.' );
		}
		$icon = isset( $definition['icon'] ) && is_string( $definition['icon'] ) && Icons::has( $definition['icon'] ) ? $definition['icon'] : 'generic';

		$profile = $definition['profile'] ?? null;
		if ( null !== $profile ) {
			$profile = self::validate_profile_policy( $profile );
			if ( is_wp_error( $profile ) ) {
				return $profile;
			}
		}
		$share = $definition['share'] ?? null;
		if ( null !== $share ) {
			if ( ! is_array( $share ) || ! isset( $share['template'] ) || ! self::is_valid_template( $share['template'] ) ) {
				return new \WP_Error( 'cradle_social_provider_template', 'Share templates must be fixed https:// (or mailto:?) URLs with placeholders only in the query string.' );
			}
			$share = array( 'template' => $share['template'] );
		}
		if ( null === $profile && null === $share ) {
			return new \WP_Error( 'cradle_social_provider_empty', 'A provider needs a profile policy, a share template, or both.' );
		}
		return array(
			'id'      => $id,
			'label'   => trim( wp_strip_all_tags( $label ) ),
			'icon'    => $icon,
			'profile' => $profile,
			'share'   => $share,
		);
	}

	/**
	 * @param mixed $policy Profile policy: hosts (+ optional path), federated + path, or mailto.
	 * @return array|\WP_Error
	 */
	private static function validate_profile_policy( $policy ) {
		if ( ! is_array( $policy ) ) {
			return new \WP_Error( 'cradle_social_provider_profile', 'Profile policies must be arrays.' );
		}
		$path = $policy['path'] ?? null;
		if ( null !== $path && ( ! is_string( $path ) || false === @preg_match( $path, '' ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- validating a trusted regex.
			return new \WP_Error( 'cradle_social_provider_path', 'Profile path rules must be valid regular expressions.' );
		}
		if ( ! empty( $policy['mailto'] ) ) {
			return array( 'mode' => 'mailto' );
		}
		if ( ! empty( $policy['federated'] ) ) {
			if ( null === $path ) {
				return new \WP_Error( 'cradle_social_provider_federated', 'Federated providers must restrict the profile path.' );
			}
			return array(
				'mode' => 'federated',
				'path' => $path,
			);
		}
		$hosts = array();
		foreach ( (array) ( $policy['hosts'] ?? array() ) as $host ) {
			if ( ! is_string( $host ) || ! preg_match( self::HOST_PATTERN, $host ) ) {
				return new \WP_Error( 'cradle_social_provider_hosts', 'Profile hosts must be lowercase host names.' );
			}
			$hosts[] = $host;
		}
		if ( ! $hosts ) {
			return new \WP_Error( 'cradle_social_provider_hosts', 'Host-restricted providers need at least one host.' );
		}
		return array(
			'mode'  => 'hosts',
			'hosts' => array_values( array_unique( $hosts ) ),
			'path'  => $path,
		);
	}

	/**
	 * A template is a fixed https URL (or mailto:?) whose scheme, host and path
	 * contain no placeholders, whose query contains only known placeholders,
	 * and which carries the page URL.
	 *
	 * @param mixed $template Template string.
	 */
	public static function is_valid_template( $template ): bool {
		if ( ! is_string( $template ) || strlen( $template ) > 300 || preg_match( '/[\s"\'<>\\\\`]/', $template ) ) {
			return false;
		}
		$query_at = strpos( $template, '?' );
		if ( false === $query_at ) {
			return false;
		}
		$base  = substr( $template, 0, $query_at );
		$query = substr( $template, $query_at + 1 );
		if ( false !== strpbrk( $base, '{}' ) ) {
			return false;
		}
		if ( 'mailto:' !== $base ) {
			$parts = wp_parse_url( $base );
			if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || ! preg_match( self::HOST_PATTERN, (string) ( $parts['host'] ?? '' ) ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
				return false;
			}
		}
		$without = str_replace( self::PLACEHOLDERS, '', $query );
		if ( false !== strpbrk( $without, '{}' ) || ! preg_match( '/^[A-Za-z0-9_.~=&-]*$/', $without ) ) {
			return false;
		}
		return str_contains( $query, '{url}' ) || str_contains( $query, '{text_url}' );
	}

	/**
	 * Builds a share URL: fixed template, single-pass substitution of
	 * rawurlencode()d values, empty parameters dropped.
	 *
	 * @param string $id   Provider ID.
	 * @param array  $data url (absolute http/https), title, text, media (all plain text).
	 * @return string Raw URL (escape with esc_url() on output), or '' when unavailable.
	 */
	public static function share_url( string $id, array $data ): string {
		$provider = self::get( $id );
		if ( ! $provider || null === $provider['share'] ) {
			return '';
		}
		$url = (string) ( $data['url'] ?? '' );
		if ( ! in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		$title = self::plain( (string) ( $data['title'] ?? '' ) );
		$text  = self::plain( (string) ( $data['text'] ?? '' ) );
		// {text} travels next to a separate {url} parameter, so the URL is removed from it;
		// {text_url} is for providers without a URL parameter and always carries the URL once.
		$text_only = trim( preg_replace( '/\s+/u', ' ', str_replace( $url, '', $text ) ) );
		$text_url  = str_contains( $text, $url ) ? $text : trim( $text . ' ' . $url );
		$media     = (string) ( $data['media'] ?? '' );
		if ( '' !== $media && ! in_array( strtolower( (string) wp_parse_url( $media, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) ) {
			$media = '';
		}
		$built = strtr(
			$provider['share']['template'],
			array(
				'{url}'      => rawurlencode( $url ),
				'{title}'    => rawurlencode( $title ),
				'{text}'     => rawurlencode( $text_only ),
				'{text_url}' => rawurlencode( $text_url ),
				'{media}'    => rawurlencode( $media ),
			)
		);
		list( $base, $query ) = explode( '?', $built, 2 );
		$pairs                = array_filter(
			explode( '&', $query ),
			static function ( $pair ) {
				$value = explode( '=', $pair, 2 )[1] ?? '';
				return '' !== $value;
			}
		);
		return $base . ( $pairs ? '?' . implode( '&', $pairs ) : '' );
	}

	/**
	 * Validates and normalizes an organization profile URL for a provider.
	 *
	 * @param string $id  Provider ID.
	 * @param mixed  $raw Submitted URL.
	 * @return string|\WP_Error Normalized URL.
	 */
	public static function validate_profile_url( string $id, $raw ) {
		$provider = self::get( $id );
		if ( ! $provider || null === $provider['profile'] ) {
			return new \WP_Error( 'cradle_social_profile_provider', __( 'This service does not support profile links.', 'cradle-social' ) );
		}
		$label = self::label( $id );
		if ( ! is_string( $raw ) ) {
			return new \WP_Error( 'cradle_social_profile_type', __( 'Must be a URL.', 'cradle-social' ) );
		}
		$raw = trim( $raw );
		if ( '' === $raw || strlen( $raw ) > self::MAX_URL_LENGTH || ! mb_check_encoding( $raw, 'UTF-8' ) || preg_match( '/[\s\x00-\x1f\x7f\\\\<>"\'`]/', $raw ) ) {
			/* translators: %s: service name */
			return new \WP_Error( 'cradle_social_profile_format', sprintf( __( '%s: enter a complete address without spaces or quotes.', 'cradle-social' ), $label ) );
		}
		$policy = $provider['profile'];

		if ( 'mailto' === $policy['mode'] ) {
			$address = preg_replace( '/^mailto:/i', '', $raw );
			if ( ! is_string( $address ) || preg_match( '/[?&,;%]/', $address ) || ! is_email( $address ) || sanitize_email( $address ) !== $address ) {
				/* translators: %s: service name */
				return new \WP_Error( 'cradle_social_profile_email', sprintf( __( '%s: enter one plain email address (no extra headers).', 'cradle-social' ), $label ) );
			}
			return 'mailto:' . $address;
		}

		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			/* translators: %s: service name */
			return new \WP_Error( 'cradle_social_profile_scheme', sprintf( __( '%s: the address must start with https://.', 'cradle-social' ), $label ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) || isset( $parts['fragment'] ) ) {
			/* translators: %s: service name */
			return new \WP_Error( 'cradle_social_profile_parts', sprintf( __( '%s: remove credentials, port numbers and #fragments from the address.', 'cradle-social' ), $label ) );
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path = (string) ( $parts['path'] ?? '' );
		if ( ! preg_match( self::HOST_PATTERN, $host ) || filter_var( $host, FILTER_VALIDATE_IP ) ) {
			/* translators: %s: service name */
			return new \WP_Error( 'cradle_social_profile_host', sprintf( __( '%s: the address has an invalid host name.', 'cradle-social' ), $label ) );
		}
		if ( 'hosts' === $policy['mode'] && ! in_array( $host, $policy['hosts'], true ) ) {
			return new \WP_Error(
				'cradle_social_profile_host',
				/* translators: 1: service name, 2: list of allowed host names */
				sprintf( __( '%1$s: the address must be on %2$s.', 'cradle-social' ), $label, implode( ', ', $policy['hosts'] ) )
			);
		}
		if ( '' === $path || '/' === $path || ! preg_match( '#^/[A-Za-z0-9\-._~!$&()*+,;=:@%/]*$#', $path ) ) {
			/* translators: %s: service name */
			return new \WP_Error( 'cradle_social_profile_path', sprintf( __( '%s: enter the full profile address, not only the site.', 'cradle-social' ), $label ) );
		}
		if ( null !== ( $policy['path'] ?? null ) && ! preg_match( $policy['path'], $path ) ) {
			/* translators: %s: service name */
			return new \WP_Error( 'cradle_social_profile_path', sprintf( __( '%s: this is not a profile address for the service.', 'cradle-social' ), $label ) );
		}
		$query = (string) ( $parts['query'] ?? '' );
		if ( '' !== $query && ! preg_match( '#^[A-Za-z0-9\-._~!$&()*+,;=:@%/?]*$#', $query ) ) {
			/* translators: %s: service name */
			return new \WP_Error( 'cradle_social_profile_query', sprintf( __( '%s: the address contains unsupported characters.', 'cradle-social' ), $label ) );
		}
		$normalized = 'https://' . $host . $path . ( '' !== $query ? '?' . $query : '' );
		if ( esc_url_raw( $normalized, array( 'https' ) ) !== $normalized ) {
			/* translators: %s: service name */
			return new \WP_Error( 'cradle_social_profile_format', sprintf( __( '%s: the address could not be validated.', 'cradle-social' ), $label ) );
		}
		return $normalized;
	}

	/**
	 * Plain single-line text: tags removed, entities decoded, whitespace collapsed, bounded.
	 */
	public static function plain( string $value, int $max = 500 ): string {
		$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = trim( (string) preg_replace( '/[\s\x00-\x1f\x7f]+/u', ' ', $value ) );
		return mb_substr( $value, 0, $max );
	}
}
