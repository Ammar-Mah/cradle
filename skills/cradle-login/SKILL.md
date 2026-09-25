---
name: cradle-login
description: Use when implementing or changing Cradle Login's custom login URL, its behavior for wp-login.php and /wp-admin/ requests, login/logout/lost-password/reset/registration/reauth/post-password flows, redirects after authentication, login-page compatibility with WooCommerce accounts or REST application passwords, or emergency recovery from a broken login route. Produces a native-flow compatibility matrix with results and a tested recovery path.
---

# Cradle login routing

## Required context

- `cradle-project` orientation; `packages/plugins/cradle-login/` source, `AGENTS.md`, `docs/`.
- `northstar.md` §10 (configuration, authentication contract, recovery), §20.2, §15.8 (imported routes
  disabled after restore); WordPress hardening guidance [R13].
- `references/flow-matrix.md` — every flow, its default URL, expected behavior, and how to check it.

## Preconditions

- The custom route is a convenience, not security. Never describe it as making the site secure; never
  use URL secrecy as authorization.
- Emergency disabling exists and is tested before any route concealment ships:
  `define( 'CRADLE_DISABLE_CUSTOM_LOGIN', true );` in `wp-config.php` (trusted configuration), and
  deactivating/renaming only `wp-content/plugins/cradle-login/`.
- Shared sandbox rule: leave custom routing disabled when you finish, and restore any setting you changed.
- Sandbox is a subdirectory install (`http://localhost/cradle/wp/`): every URL must work under a path prefix.

## Procedure

1. Trace current native behavior first: run each flow in `references/flow-matrix.md` with routing off
   and record the observed URLs and status codes.
2. Settings (core Settings section, capability `cradle_manage_login`, administrators only): enabled flag
   (default off), slug, response for blocked default entry points (generic 404 by default). Validate the
   slug: `^[a-z0-9-]{3,50}$`, not a reserved/admin/REST path (`wp-admin`, `wp-login`, `wp-json`, `login`,
   `admin`, `dashboard`, `feed`, `xmlrpc`…), no existing page/post/term slug, no WooCommerce account
   endpoint, no rewrite collision.
3. Route with native code: serve the custom slug by loading the core login handler for that request
   (without editing `wp-login.php`), and rewrite generated URLs through `login_url`, `logout_url`,
   `lostpassword_url`, `register_url`, `site_url`/`network_site_url` (for `wp-login.php` targets only).
   Keep WordPress authentication, cookies, logout nonces and reset keys untouched.
4. Blocking default entry points: only unauthenticated, non-exempt requests to `wp-login.php` and
   `/wp-admin/` (non-AJAX, non-admin-post) get the configured response. Never block `admin-ajax.php`,
   `admin-post.php`, REST, `wp-cron.php`, CLI, recovery-mode links, or action-specific flows listed as
   pass-through in the matrix.
5. Redirects: validate `redirect_to` with `wp_validate_redirect()`; same-site only.
6. Enabling workflow: pre-enable health check (slug reachable via a loopback request, permalinks
   active, no collision), then save; preserve the current administrator session; show the new URL once
   and on the settings screen. Flush rewrite rules only on enable/disable/slug change and on
   activation/deactivation.
7. Recovery: honor `CRADLE_DISABLE_CUSTOM_LOGIN`; provide no query-string bypass secret and no permanent
   unauthenticated recovery endpoint. After a migration restore the imported route stays disabled until an
   administrator re-verifies it.
8. Document settings, recovery steps and limitations in the package `docs/`.

## Verification

- Full flow matrix passes with routing enabled on the subdirectory sandbox; record each row's result
  (CR-13). Where a flow is unavailable (registration disabled, WooCommerce inactive), mark not applicable.
- Low-privilege users cannot change routing: editor/subscriber POST to the settings handler → denied (CR-26).
- `admin-ajax.php`, `admin-post.php`, `/wp-json/` (cookie + nonce and application password), and
  `wp-cron.php` behave as before.
- Recovery: with a deliberately broken route, `CRADLE_DISABLE_CUSTOM_LOGIN` restores `wp-login.php`
  without a database edit; deactivating only cradle-login restores it too (disposable target or careful
  sandbox run with immediate restore).
- Checkout and account pages work when WooCommerce is active.
- Integration tests in `tests/integration/login.php` using `cradle_test_http()` (never typing passwords).

## Required output

Flow matrix with pass/fail/not applicable per row, collision checks, recovery evidence, settings and
capability, final state of the sandbox setting (disabled), limitations.

## Failure behavior

- Any matrix row fails: keep routing disabled by default and report; do not ship "partially works".
- Recovery untested: do not enable routing in any environment.
- A request to hide the login via a secret query parameter or to edit `wp-login.php`: decline and explain.

## References

- `references/flow-matrix.md`
- `northstar.md` §10, §15.8, §20; acceptance CR-13, CR-26
- Related skills: `cradle-security`, `cradle-woocommerce`, `cradle-migration`
