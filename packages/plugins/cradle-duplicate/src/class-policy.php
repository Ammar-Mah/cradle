<?php
/**
 * Duplication policy: supported post types, object-level authorization and metadata rules.
 *
 * Authorization is always evaluated per source item for the acting user:
 * read_post + edit_post on the source, plus the target type's create_posts
 * capability. Menu visibility, nonces and button state are never treated as
 * authorization.
 *
 * @package Cradle\Duplicate
 */

namespace Cradle\Duplicate;

defined( 'ABSPATH' ) || exit;

final class Policy {

	/**
	 * Post types that are never duplicated by this plugin, even when a filter adds them.
	 * WooCommerce products/orders need WooCommerce's own integrity-checked duplication.
	 */
	const NEVER_TYPES = array(
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_font_family',
		'wp_font_face',
		'product',
		'product_variation',
		'shop_order',
		'shop_order_refund',
		'shop_order_placehold',
		'shop_coupon',
		'shop_subscription',
		'cradle_dummy_batch',
	);

	/** Statuses that cannot be duplicated. */
	const BLOCKED_STATUSES = array( 'trash', 'auto-draft', 'inherit' );

	/**
	 * Metadata that is never copied and cannot be re-enabled by filters or settings:
	 * locks, revision/trash bookkeeping, pings, attachment internals, fields handled
	 * explicitly (featured image, page template), provenance, provisioning identity
	 * (`_cradle_source_key`) and Cradle ownership markers.
	 */
	const FIXED_DENY_KEYS = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_desired_post_slug',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_trash_meta_comments_status',
		'_encloseme',
		'_pingme',
		'_trackbackme',
		'enclosure',
		'_thumbnail_id',
		'_wp_page_template',
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_attachment_backup_sizes',
		'_wp_attachment_image_alt',
		'_wp_post_revision',
		'_menu_item_type',
		'_cradle_source_key', // Provisioning identity of site records (cradle-site); a copy must not claim it.
		Duplicator::SOURCE_META,
	);

	/** Prefixes that are never copied (fixed). `_cradle_generat` covers every Dummy Content ownership marker. */
	const FIXED_DENY_PREFIXES = array(
		'_cradle_generat',
		'_wp_trash_',
		'_wp_old_',
		'_wp_attachment_',
		'_edit_',
		'_oembed_',
	);

	/**
	 * Default (filterable) deny patterns: secrets and order/payment data, public or private.
	 * Applied to key segments separated by _ - . or :
	 */
	const DEFAULT_DENY_PATTERNS = array(
		'secrets'  => '/(^|[_\-.:])(pass|passwd|password|pwd|passphrase|secret|secrets|token|tokens|api[_\-]?key|apikey|private[_\-]?key|license[_\-]?key|auth|oauth|nonce|session|sessions|credential|credentials|salt|otp|totp|mfa|2fa|signature|webhook)([_\-.:]|$)/i',
		'payments' => '/(^|[_\-.:])(order|orders|payment|payments|transaction|transactions|billing|shipping|invoice|refund|customer|card|cc|cvv|iban|stripe|paypal|wcpay|subscription)([_\-.:]|$)/i',
	);

	/**
	 * Post types that may be offered in settings (UI-visible and not excluded).
	 *
	 * @return array<string,\WP_Post_Type>
	 */
	public static function eligible_post_types(): array {
		$out = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $name => $object ) {
			if ( in_array( $name, self::NEVER_TYPES, true ) ) {
				continue;
			}
			$out[ $name ] = $object;
		}
		return $out;
	}

	/**
	 * Post types currently enabled for duplication.
	 *
	 * @return string[]
	 */
	public static function supported_post_types(): array {
		$types = (array) Config::get( 'post_types' );
		/**
		 * Filters the post types that can be duplicated.
		 *
		 * Types in Policy::NEVER_TYPES (WooCommerce products/orders, attachments,
		 * revisions, navigation, templates, synced patterns) are removed afterwards.
		 *
		 * @param string[] $types Enabled post type names.
		 */
		$types = (array) apply_filters( 'cradle_duplicate/post_types', $types );
		$out   = array();
		foreach ( $types as $type ) {
			if ( is_string( $type ) && post_type_exists( $type ) && ! in_array( $type, self::NEVER_TYPES, true ) ) {
				$out[] = $type;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function is_supported_type( string $type ): bool {
		return in_array( $type, self::supported_post_types(), true );
	}

	/**
	 * Object-level authorization for duplicating one item.
	 *
	 * @param \WP_Post|int|null $post    Source.
	 * @param int               $user_id Acting user; defaults to the current user.
	 * @return true|\WP_Error Error carries an HTTP status in its data.
	 */
	public static function check( $post, int $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		$post    = get_post( $post );
		if ( ! $user_id ) {
			return new \WP_Error( 'cradle_duplicate_login', __( 'You need to be logged in to duplicate content.', 'cradle-duplicate' ), array( 'status' => 401 ) );
		}
		// Do not reveal whether an unreadable item exists.
		if ( ! $post || ! user_can( $user_id, 'read_post', $post->ID ) ) {
			return new \WP_Error( 'cradle_duplicate_not_found', __( 'The item to duplicate was not found.', 'cradle-duplicate' ), array( 'status' => 404 ) );
		}
		if ( ! self::is_supported_type( $post->post_type ) ) {
			return new \WP_Error( 'cradle_duplicate_unsupported', __( 'This type of content cannot be duplicated here.', 'cradle-duplicate' ), array( 'status' => 403 ) );
		}
		if ( in_array( $post->post_status, self::BLOCKED_STATUSES, true ) ) {
			return new \WP_Error( 'cradle_duplicate_status', __( 'Items in the trash or unsaved drafts cannot be duplicated.', 'cradle-duplicate' ), array( 'status' => 409 ) );
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! user_can( $user_id, 'edit_post', $post->ID ) || ! user_can( $user_id, $type->cap->create_posts ) ) {
			return new \WP_Error( 'cradle_duplicate_forbidden', __( 'You are not allowed to duplicate this item.', 'cradle-duplicate' ), array( 'status' => 403 ) );
		}
		/**
		 * Lets site code further restrict duplication. It can only deny; it cannot grant.
		 *
		 * @param bool     $allowed Always true at this point.
		 * @param \WP_Post $post    Source.
		 * @param int      $user_id Acting user.
		 */
		if ( true !== apply_filters( 'cradle_duplicate/can_duplicate', true, $post, $user_id ) ) {
			return new \WP_Error( 'cradle_duplicate_forbidden', __( 'You are not allowed to duplicate this item.', 'cradle-duplicate' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Allowlisted private (protected) meta keys/prefixes.
	 *
	 * @return string[]
	 */
	public static function meta_allowlist( \WP_Post $source ): array {
		$list = (array) Config::get( 'extra_meta_keys' );
		/**
		 * Filters private (underscore / protected) metadata keys that may be copied.
		 * A trailing * matches a prefix. Fixed exclusions and the deny list still win.
		 *
		 * @param string[] $list   Keys or prefixes.
		 * @param \WP_Post $source Source post.
		 */
		$list = (array) apply_filters( 'cradle_duplicate/meta_allowlist', $list, $source );
		return array_values( array_filter( $list, 'is_string' ) );
	}

	/**
	 * Deny patterns (regular expressions) for public and private keys.
	 *
	 * @return array<string,string>
	 */
	public static function meta_deny_patterns( \WP_Post $source ): array {
		/**
		 * Filters deny patterns applied to every metadata key (secrets, order/payment data).
		 * Values must be complete PCRE patterns supplied by trusted code.
		 *
		 * @param array<string,string> $patterns Named patterns.
		 * @param \WP_Post             $source   Source post.
		 */
		$patterns = (array) apply_filters( 'cradle_duplicate/meta_denylist', self::DEFAULT_DENY_PATTERNS, $source );
		return array_filter( $patterns, 'is_string' );
	}

	/**
	 * Decides whether one metadata key is copied.
	 *
	 * @return string 'copy' or a reason code: fixed, denied, private.
	 */
	public static function meta_decision( string $key, \WP_Post $source, ?array $allowlist = null, ?array $patterns = null ): string {
		if ( in_array( $key, self::FIXED_DENY_KEYS, true ) ) {
			return 'fixed';
		}
		foreach ( self::FIXED_DENY_PREFIXES as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return 'fixed';
			}
		}
		$patterns = $patterns ?? self::meta_deny_patterns( $source );
		foreach ( $patterns as $pattern ) {
			if ( 1 === @preg_match( $pattern, ltrim( $key, '_' ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed filtered pattern must not break duplication.
				return 'denied';
			}
		}
		if ( is_protected_meta( $key, 'post' ) ) {
			$allowlist = $allowlist ?? self::meta_allowlist( $source );
			foreach ( $allowlist as $allowed ) {
				if ( str_ends_with( $allowed, '*' ) ? str_starts_with( $key, rtrim( $allowed, '*' ) ) : $allowed === $key ) {
					return 'copy';
				}
			}
			return 'private';
		}
		return 'copy';
	}
}
