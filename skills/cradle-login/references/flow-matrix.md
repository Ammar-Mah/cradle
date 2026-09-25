# Login flow matrix

Base URL in the sandbox: `http://localhost/cradle/wp` (subdirectory install). `<slug>` is the configured
custom route. Run every row with routing off (baseline) and on. Record status code, final URL and result.

| # | Flow | Default entry | Expected with custom route | How to check |
| --- | --- | --- | --- | --- |
| 1 | Login form | `wp-login.php` | Form served at `/<slug>/`; `wp-login.php` unauthenticated GET → configured response (404) | GET both; form posts to the custom URL |
| 2 | Successful login | POST login form | Auth cookies set; redirect to `redirect_to` or admin | Test mints cookies directly; UI check manual without typing stored passwords |
| 3 | Invalid credentials | POST login form | Generic error on the custom route; no username enumeration beyond core behavior | POST wrong values to the custom route |
| 4 | Unauthenticated `/wp-admin/` | `auth_redirect()` → `wp-login.php` | Configured response or redirect to custom route per setting; never reveals the slug when concealment is chosen | GET `/wp-admin/` logged out |
| 5 | Expired session / reauth | `wp-login.php?reauth=1` | Works on the custom route | Expire a session token, open `/wp-admin/` |
| 6 | Interim login (auth-check modal) | `wp-login.php?interim-login=1` | Modal loads the custom route | Block editor open, expire session, observe modal |
| 7 | Logout | `wp-login.php?action=logout&_wpnonce=…` | `wp_logout_url()` points to custom route; nonce still required | Click Log Out; also GET without nonce → confirmation screen |
| 8 | Lost password | `?action=lostpassword` | Form on the custom route; POST sends the reset email | Submit for a test user (mail captured, not sent externally) |
| 9 | Reset link | `?action=rp&key=…&login=…` → `resetpass` | Email link uses the custom route; key validation by core | Generate key with `get_password_reset_key()`, open link |
| 10 | Expired/invalid reset key | `?action=lostpassword&error=invalidkey` | Error shown on the custom route | Open a link with a wrong key |
| 11 | Registration | `?action=register` | Only when `users_can_register` is on; otherwise core behavior | Toggle setting on a disposable target |
| 12 | Post password form | `?action=postpass` (POST) | Password-protected posts still unlock | Create protected post fixture, submit form |
| 13 | Personal-data request confirmation | `?action=confirmaction` | Link in email works | Create a user request fixture |
| 14 | Admin email confirmation | `?action=confirm_admin_email` | Still reachable for administrators | Trigger by setting the check option on a disposable target |
| 15 | Recovery mode | `?action=enter_recovery_mode&rm_token=…&rm_key=…` | Must keep working (fatal-error recovery emails link here) | Generate a recovery link on a disposable target |
| 16 | `redirect_to` | Any login | Only same-site targets (`wp_validate_redirect`) | `redirect_to=https://evil.example/` → falls back to admin |
| 17 | WooCommerce account login/lost password | `/my-account/`, `/my-account/lost-password/` | Unaffected; lost-password emails use the correct route | Only when WooCommerce is active |
| 18 | REST cookie auth | `/wp-json/…` with `X-WP-Nonce` | Unaffected | Authenticated editor request |
| 19 | Application passwords | `/wp-admin/authorize-application.php`, Basic auth to REST | Unaffected (HTTPS required outside local development) | Create a test application password on a disposable target |
| 20 | `admin-ajax.php` / `admin-post.php` | Direct | Never blocked by routing | Logged-out and logged-in requests return normal responses |
| 21 | `wp-cron.php`, WP-CLI | Direct / shell | Unaffected (WP-CLI not installed here: record not run) | GET `wp-cron.php` returns 200 |
| 22 | Emergency disable | `CRADLE_DISABLE_CUSTOM_LOGIN` | `wp-login.php` works again; no DB edit | Define constant on a disposable target |
| 23 | Plugin removed | Deactivate/rename `cradle-login` | Native routes return; rewrite rules flushed | Disposable target or immediate restore in the sandbox |
| 24 | Route change | Change slug while logged in | Current admin session preserved; old slug stops working | Change slug, reload admin |
| 25 | Imported route after restore | Migration restore | Route disabled until re-verified by a destination administrator | With `cradle-migration` tests |

## Slug collision checks

Reject when the slug equals or prefixes: an existing page/post/term slug (`get_page_by_path()`,
`term_exists()`), a rewrite rule match, `wp-admin`, `wp-login`, `wp-json`, `wp-content`, `wp-includes`,
`xmlrpc`, `feed`, `login`, `admin`, `dashboard`, `register`, `lostpassword`, WooCommerce account endpoints
(`my-account`, `lost-password`, `customer-logout`), or the REST prefix (`rest_get_url_prefix()`).

## What not to do

- No custom password checks, password tables or cookie formats.
- No edits to `wp-login.php` or core files.
- No public bypass parameter, no permanent unauthenticated recovery endpoint.
- No blanket block of `/wp-admin/` that breaks AJAX, admin-post, or action-specific flows.
