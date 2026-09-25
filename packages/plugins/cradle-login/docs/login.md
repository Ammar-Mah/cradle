# Cradle Login: contract, flows and data

Northstar §10 (acceptance CR-13), §20 security, §21.3 behavior preservation.

A custom login URL reduces automated requests to `wp-login.php`. It is **not** a security control:
authorization, updates, strong passwords, two-factor authentication, HTTPS and rate limiting belong to
WordPress, hosting or other tools. Anything that prints a login link on public pages (Meta widget,
Login/out block, "log in to comment" links, WooCommerce) shows the custom URL — by design.

## Settings (core Settings API section `login`, option `cradle_login_settings`)

| Field | Type | Default | Rules |
| --- | --- | --- | --- |
| `enabled` | boolean | `false` | Turning on (or moving the route while on) runs the loopback health check first and is refused on failure, while `CRADLE_DISABLE_CUSTOM_LOGIN` or the disable filter is set, on multisite, or without a slug. |
| `slug` | string (3–64, `^[a-z0-9][a-z0-9-]{1,62}[a-z0-9]$`) | `''` | Validated for collisions (below). Cannot be cleared while enabled. Exported, never included in diagnostics or activity logs. |
| `blocked_response` | enum | `not_found` | `not_found` = theme 404 template with HTTP 404; `plain_not_found` = minimal HTTP 404 page; `redirect_home` = 302 to the home page. |
| `recovery_note` | string (textarea, ≤500) | `''` | Sensitive (excluded from export). Shown only on Cradle → Login to `cradle_manage_login` holders. |

Capability: `cradle_manage_login` (administrators; granted once, removal respected). Saving goes through
`options.php` with the section's group nonce and `option_page_capability_cradle_login`. Stale revisions are
refused by core. Reset/export/import are core's; import runs the same validation, including the health check.

Slug collisions rejected: WordPress reserved routes and terms (`admin`, `login`, `dashboard`, `register`,
`logout`, feeds, `sitemap`, `robots`, `favicon`, `xmlrpc`, `embed`, `search`, `author`, `category`, `page`, …),
anything starting with `wp-` (`wp-admin`, `wp-json`, `wp-content`, `wp-sitemap`, `wp-login`), the configured REST
prefix (`rest_get_url_prefix()`), public and private query variables (after the `query_vars` filter), permalink
bases, the static first segment of every current rewrite rule (post type/taxonomy bases, endpoints, plugin routes),
posts/pages/attachments/public CPT items with that `post_name` (any status except auto-draft/trash) or resolving via
`url_to_postid()`, a real file or directory of that name in the site root, WooCommerce account/checkout endpoint
slugs when WooCommerce is active, and anything added through `cradle_login_reserved_slugs`.

## How routing works (no rewrite rules)

1. `cradle/ready` (plugins_loaded priority 5) — if the module is ready, `Router::start()` reads the autoloaded
   option once. When routing is **off** nothing else is attached (native behavior).
2. The route is matched against `REQUEST_URI` on the front controller (`index.php`) only: `/<slug>`, `/<slug>/` and
   `/index.php/<slug>/` under the home path. Subdirectory installs work because the home path is stripped first.
   With plain or PATHINFO permalinks the generated URL is `/index.php/<slug>/` (the health check proves the server
   passes PATH_INFO).
3. On a route request the router sets `$pagenow = 'wp-login.php'` and `SCRIPT_NAME` to the route path (so
   `is_login()`, locale selection via `wp_lang`, admin-bar suppression and fatal-error protection behave as on
   `wp-login.php`), then at `wp_loaded` (last priority — where a direct `wp-login.php` request starts running)
   includes the **unmodified** `ABSPATH . 'wp-login.php'`, binding its top-level variables to globals (found with
   the PHP tokenizer) so its functions and plugins see the same `$action`, `$errors`, `$interim_login`,
   `$user_login`… as on a direct request. `error_reporting` is restored at `login_init`.
4. URL filters (`site_url`, `network_site_url`, `login_url`, `logout_url`, `lostpassword_url`, `register_url`,
   `wp_redirect`) rewrite this site's `wp-login.php` URLs — absolute, root-relative or the bare relative
   `wp-login.php?…` used by core redirects — to the route, keeping query and fragment. Other hosts/paths are
   untouched; the rewrite is idempotent. WooCommerce's own lost-password URL is left alone.
5. `recovery_mode_begin_url` is mapped back to `wp-login.php`, because WordPress validates recovery links before
   plugins load.

Because nothing is added to the rewrite rules, nothing is flushed on save, activation or deactivation.

## Flow matrix (all verified over HTTP by `tests/integration/login.php` unless noted)

| Flow | At the route | Notes |
| --- | --- | --- |
| Login form, valid credentials | 200 form posting to the route; valid POST → 302 + WordPress auth cookies | Disposable test user with a random password. |
| Invalid credentials | 200 with `#login_error`, no session cookie | Native errors and `wp_login_failed`. |
| Logout (nonce) | `?action=logout&_wpnonce=` → session destroyed → route `?loggedout=true` | Bad nonce → native "Do you really want to log out?" (403). |
| Lost password | form + POST handled by WordPress; `?checkemail=confirm` links back to the route | Tested with an unknown account (no email sent). |
| Reset (`rp`/`resetpass`) | real key from `get_password_reset_key()` → reset cookie scoped to the route path → reset form → POST reaches the key-validated handler | Completed reset **not** submitted: core would email the admin. Invalid keys → route `?action=lostpassword&error=invalidkey`. |
| Registration | only when `users_can_register`; otherwise route `?registration=disabled` | Form rendered with registration temporarily open; no account created. |
| Post password (`postpass`) | password forms post to the route; cookie set; content unlocked | Cached forms posting to `wp-login.php?action=postpass` still work. |
| Reauthentication | `wp_login_url( $r, true )` → form | |
| Interim login (auth-check modal) | `?interim-login=1` form | Heartbeat stays on admin-ajax. |
| Admin email confirmation | `?action=confirm_admin_email` posts to the route | Signed-in admin. |
| Privacy request confirmation (`confirmaction`) | confirmed through the route | Test pre-marks the request as notified (no email). |
| Recovery mode | real begin link (`WP_Recovery_Mode_Link_Service::generate_url()`) → `wp-login.php` validates → `entered_recovery_mode` forwarded to the route | The exit link is built from `wp_login_url()` and handled by core's `login_form_exit_recovery_mode` hook on the route — not exercised by the tests. |
| Signed-in users | `/wp-admin/` and `wp-login.php` work normally | Valid auth **or** logged-in cookie counts as signed in. |

## Native entry points while active (signed-out visitors)

| Request | Answer |
| --- | --- |
| `wp-login.php` (any action), `/wp-admin/…` (non-AJAX), `wp-signup.php` (single site) | configured safe response (default theme 404) |
| `/login`, `/wp-login`, `/admin`, `/dashboard` | ordinary 404 (core's redirect to `wp_login_url()` is skipped for signed-out visitors) |
| `wp-activate.php` | core redirects to native `wp-login.php` (it loads no plugins), which then answers as above |
| `wp-login.php?action=rp&key=…&login=…` with a **valid** key, `?action=confirmaction` with a **valid** request key, `?action=entered_recovery_mode` with a **valid** recovery cookie | 302 to the route with the same query (secret holders only — keeps emails sent before enabling working) |
| `POST wp-login.php?action=postpass` | passed to WordPress (only sets the post-password cookie) |
| `admin-ajax.php`, `admin-post.php`, REST (incl. application passwords), XML-RPC, `wp-cron.php`, WP-CLI | never touched |

Signed-out `/wp-admin/` requests are rendered in frontend context (a `current_screen` stand-in makes
`is_admin()` false) so the theme 404 has no admin bar; rendering is buffered and falls back to the minimal
404 if anything throws. A signed-out visitor with an expired session therefore sees the 404 instead of a
login redirect — they must use the custom URL (documented trade-off).

## Pre-enable health check

`Health_Check::probe( $slug )` stores `{slug, sha256(token)}` in `cradle_login_probe_<hash prefix>` for 120 s and
sends an anonymous loopback `GET` of the candidate URL with `X-Cradle-Login-Probe: <token>` (basic-auth
credentials of the current request are forwarded like Site Health does; `sslverify` follows
`https_local_ssl_verify`). The router serves the login screen at the candidate slug only for a matching,
unexpired token. Pass criteria: HTTP 200, `X-Cradle-Login-Probe: ok` response header (proves this plugin
answered, not a cache), `id="loginform"`, and a form action pointing at the candidate route. The transient is
deleted immediately. Results (no token) go to `cradle_login_health` and the activity log. The same check is
available on the screen as "Test a route without saving".

Changing the route never touches auth cookies (they are scoped to the site/admin paths, not to the login
URL), so the administrator's session survives enabling, moving and disabling — verified in the tests.

## Data and lifecycle

| Event | Behavior |
| --- | --- |
| Activation | Refuses network activation; grants `cradle_manage_login` to administrators once (recorded in `cradle_granted_capabilities`); creates the autoloaded default option (routing off). If the stored settings were enabled, routing resumes and administrators get a one-time notice. |
| Deactivation / folder rename | Nothing to unschedule, release or flush. Settings kept. WordPress serves `wp-login.php` natively. |
| Uninstall | Retain by default. With `cradle_uninstall_policy['login'] === 'purge'`: delete `cradle_login_settings`, `cradle_login_health`, `cradle_login_*` transients, revoke the capability. |
| Core missing | No routing (native login), admin notice for `activate_plugins` users, no data changes. |

Activity log entries: `route_enabled`, `route_disabled`, `route_changed`, `response_changed`, `health_check`
(ok/fail + HTTP code), `activated`, `deactivated`. The slug is never logged.

## Trust boundaries (§20.1)

- Anonymous visitors: control the path, query and headers. Only exact slug matches are routed; probe tokens are
  format-checked and compared by hash; handoffs require a secret WordPress itself validates
  (`check_password_reset_key()`, `wp_validate_user_request_key()`, recovery cookie validation).
- Subscribers/editors: cannot read or change routing (capability on the screen, `options.php`, admin-post and
  `Settings::update()`), verified with real HTTP requests.
- Administrators: slug input is format-validated before collision checks; output is escaped per context.
- Stored option: re-validated on every read (`Config::values()`); invalid data means "routing off".
- Loopback response: only inspected, never rendered or stored beyond status and message.

## Known limitations

- Multisite: routing is refused (network signup/activation flows are not covered).
- WooCommerce: account endpoints are reserved and WooCommerce's own login/lost-password forms are untouched by
  design, but no WooCommerce install exists in the sandbox, so checkout/My Account were **not** exercised here.
- Application-password authorization (`wp-admin/authorize-application.php`) needs a signed-in user; signed-out
  users see the safe response and must first sign in at the custom URL. Application-password REST
  authentication itself is unaffected (not exercised in the sandbox: application passwords are unavailable on
  a non-HTTPS `development` environment).
- Password-reset emails, privacy confirmations and recovery links sent before the route existed keep working
  via the valid-secret handoff; other old bookmarks of `wp-login.php` see the safe response.
- `wp-signup.php`/`wp-activate.php` handling targets single-site installs.
- The admin-context theme 404 is visually equivalent but loads block CSS as one stylesheet instead of per-block
  inline styles.
