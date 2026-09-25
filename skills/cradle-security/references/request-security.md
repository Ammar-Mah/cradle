# Request security patterns

Source: northstar §20.1–§20.3, §20.5, §6.3; WordPress nonce [R14], REST endpoint [R19], plugin security
[R31], sanitizing [R36] and escaping [R37] documentation.

## Decision table per entry point

| Entry point | Authentication | Authorization | CSRF | Method |
| --- | --- | --- | --- | --- |
| `admin_post_<action>` | Logged-in cookie (WordPress) | `current_user_can( $cap )` + object check | `check_admin_referer( '<action>_<object>' )` | POST; `wp_safe_redirect()` + `exit` after |
| `admin_post_nopriv_<action>` | None | Intentionally public only; bounded effect | Nonce is not meaningful for anonymous; use rate/size limits | POST |
| `wp_ajax_<action>` | Logged-in cookie | Capability + object check | `check_ajax_referer( '<action>', 'nonce' )` | POST for mutations |
| `wp_ajax_nopriv_<action>` | None | Public data only | — | Prefer REST with a public permission callback |
| REST route | Cookie + `X-WP-Nonce` (`wp_rest`) or application password | `permission_callback` returning bool/`WP_Error` | Cookie auth requires the REST nonce (handled by core) | Match `methods` to semantics |
| Settings (options.php) | Cookie | Section capability via `option_page_capability_{group}` | Settings API nonce | POST |
| Block/shortcode render | Visitor context | Render only public data unless the viewer is checked | — | GET (no mutation) |
| Cron / jobs | None (system) | Capability checked when the job is created; owner stored | — | — |
| WP-CLI | Shell user | Treated as trusted operator; still validate arguments | — | — |

Never use `is_admin()`, hidden fields, obscure URLs, the Referer header, menu visibility, or a disabled
button as an authorization decision. A nonce proves intent from a session, not permission, and is not a
one-time token.

## admin-post handler

```php
add_action( 'admin_post_cradle_faq_delete', array( __CLASS__, 'handle_delete' ) );

public static function handle_delete() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		wp_die( esc_html__( 'Invalid request method.', 'cradle-faq' ), 405 );
	}
	$id = isset( $_POST['faq_id'] ) ? absint( wp_unslash( $_POST['faq_id'] ) ) : 0;
	check_admin_referer( 'cradle_faq_delete_' . $id );
	if ( ! $id || 'cradle_faq' !== get_post_type( $id ) || ! current_user_can( 'delete_post', $id ) ) {
		wp_die( esc_html__( 'You are not allowed to delete this item.', 'cradle-faq' ), 403 );
	}
	wp_trash_post( $id );
	cradle_log_activity( 'faq', 'trashed', 'FAQ item trashed.', array( 'post' => $id ) );
	wp_safe_redirect( admin_url( 'edit.php?post_type=cradle_faq&trashed=1' ) );
	exit;
}
```

## REST route

```php
register_rest_route( 'cradle-faq/v1', '/items/(?P<id>\d+)', array(
	array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => array( __CLASS__, 'get_item' ),
		'permission_callback' => '__return_true', // Intentionally public: published items only, limited fields.
		'args'                => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) ),
	),
	array(
		'methods'             => WP_REST_Server::EDITABLE,
		'callback'            => array( __CLASS__, 'update_item' ),
		'permission_callback' => static fn( WP_REST_Request $r ) => current_user_can( 'edit_post', (int) $r['id'] ),
		'args'                => array(
			'id'       => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ),
			'question' => array( 'type' => 'string', 'maxLength' => 300, 'sanitize_callback' => 'sanitize_text_field' ),
		),
	),
) );
```

- A public callback must still return only published, public data (check `post_status`, `post_password`,
  `read_post` for the current user).
- REST responses and component previews must not expose private settings, drafts, emails or paths.
- Validate with the `args` schema (`type`, `enum`, `minimum`, `maximum`, `maxLength`, `pattern`,
  `items`); reject invalid input instead of coercing it.

## Object-level checks

Always check the specific target: `edit_post`/`delete_post`/`read_post` with the ID, term capabilities
with the term ID, job owner plus job capability (`Jobs::user_can_access()`), and that the ID belongs to
the expected type. Cross-object tests: user A's nonce and job/post ID used by user B must fail.

## Validation before normalization

| Data | Accept | Then |
| --- | --- | --- |
| Integer ID | `ctype_digit`/`absint` > 0 and entity exists with the expected type | Use the ID |
| Enum | `in_array( $v, $allowed, true )` | Use as-is |
| URL | `wp_http_validate_url()` or `esc_url_raw( $v, array( 'https' ) )` non-empty; host allowlist when fetched | Store raw, escape on output |
| Color | `sanitize_hex_color()` non-null | Store |
| Dimension | number within range + unit in allowlist | `Settings::css_dimension()` |
| Rich text | `wp_kses( $v, $allowed_tags )` with a minimal allowlist | Store |
| JSON | size cap, `json_decode( $s, true, $depth )`, schema check | Use validated array |
| Sort/identifier | strict allowlist map (`array( 'date' => 'post_date' )`) | Interpolate only mapped value |

Always `wp_unslash()` superglobals before validation.

## Output escaping

`esc_html()` text, `esc_attr()` attributes, `esc_url()` links, `wp_kses_post()`/`wp_kses()` for allowed HTML,
`wp_json_encode()` + `wp_add_inline_script()` for script data, `esc_textarea()` in textareas. In JS use
`textContent`, `setAttribute`, `createElement`; never `innerHTML` with untrusted strings.

## SQL

```php
$rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, state FROM {$table} WHERE module = %s ORDER BY {$order_column} DESC LIMIT %d",
	$module,
	$limit
), ARRAY_A );
```

`$table` must come from `$wpdb->prefix` + a constant; `$order_column` from an allowlist map; `IN (…)` lists
from `implode( ',', array_fill( 0, count( $ids ), '%d' ) )`. Placeholders never protect identifiers.

## Redirects

`wp_safe_redirect()` for internal targets; validate `redirect_to` with `wp_validate_redirect( $url, $fallback )`;
never redirect to a raw request value.

## Caching and privacy

- Do not cache user-specific output as public (component `cache: public` only for anonymous-identical HTML).
- Scope transients/object cache keys by locale, site, and user when output varies.
- Failed access responses reveal no account existence or configuration details.
- Treat `X-Forwarded-For`/`Client-IP` headers as untrusted unless a trusted proxy policy is configured.

## Denied-role test sketch (tests/integration/<package>.php)

```php
cradle_test( 'faq: subscriber cannot delete via admin-post', function () {
	$admin  = cradle_test_user( 'administrator' );
	$sub    = cradle_test_user( 'subscriber' );
	$id     = wp_insert_post( array( 'post_type' => 'cradle_faq', 'post_status' => 'publish', 'post_title' => 'T' ) );
	$sess   = cradle_test_cookies( $sub );
	$nonce  = cradle_test_nonce( $sub, $sess, 'cradle_faq_delete_' . $id );
	$res    = cradle_test_http( 'POST', '/wp-admin/admin-post.php', array( 'body' => array( 'action' => 'cradle_faq_delete', 'faq_id' => $id, '_wpnonce' => $nonce ) ), $sub, $sess );
	assert_same( 403, wp_remote_retrieve_response_code( $res ) );
	assert_same( 'publish', get_post_status( $id ) );
	wp_delete_post( $id, true ); // Clean up fixtures.
} );
```
