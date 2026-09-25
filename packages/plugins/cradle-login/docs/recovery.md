# Cradle Login recovery runbook

Use this when the custom login route is lost, blocked by the server, cached, or otherwise unusable.
None of these steps needs a database edit, and none of them logs anyone out. Cradle Login has **no**
secret query string and **no** unauthenticated recovery URL.

## 1. Server-side constant (preferred)

Add to `wp-config.php` above the line `/* That's all, stop editing! */`:

```php
define( 'CRADLE_DISABLE_CUSTOM_LOGIN', true );
```

Effect, immediately on the next request:

- Custom routing is off: `wp-login.php` and `/wp-admin/` behave exactly as in plain WordPress and every
  generated login/logout/lost-password link points at `wp-login.php` again.
- The saved settings are untouched; Cradle → Login shows "switched off by CRADLE_DISABLE_CUSTOM_LOGIN".
- Enabling or moving the route is refused while the constant is set (the health check cannot run).

Then sign in at `/wp-login.php`, open **Cradle → Login**, fix the slug (use **Test a route without
saving**) or untick the route, save, and finally remove the line from `wp-config.php`.

The constant can also be defined in an mu-plugin or any trusted configuration loaded before plugins.
Any truthy value counts. Code that needs a conditional switch can use the equivalent filter:

```php
add_filter( 'cradle_login_disable_routing', '__return_true' );
```

## 2. Deactivate or rename the add-on

Any of these restores the native login page because none of the routing code runs:

- Plugins screen → deactivate **Cradle Login** (possible while you are still signed in — signed-in users
  always reach `/wp-admin/`).
- WP-CLI: `wp plugin deactivate cradle-login` (CLI is never blocked).
- FTP/SSH/file manager: rename `wp-content/plugins/cradle-login` (for example to `cradle-login.off`).
  WordPress stops loading it on the next request and removes it from the active list when the Plugins
  screen is opened.

Settings are kept. **Reactivating resumes the saved route** (administrators see a one-time notice with the
URL), so fix or disable the route on Cradle → Login right after reactivating, or keep the constant from
step 1 in place until you have.

If the Cradle core plugin is missing or broken, Cradle Login pauses itself and WordPress serves
`wp-login.php` natively.

## 3. Things that keep working during an incident

- Existing signed-in sessions (changing, disabling or breaking the route never ends them).
- `admin-ajax.php`, `admin-post.php`, the REST API, XML-RPC, `wp-cron.php` and WP-CLI.
- Emailed password-reset and privacy confirmation links that point at `wp-login.php` and carry a valid
  secret: they are forwarded to the current route.
- Recovery-mode (fatal error) email links: they always address `wp-login.php`, WordPress validates them
  before plugins load, and the follow-up "Recovery Mode Initialized" page is forwarded to the route for the
  browser holding the valid recovery cookie.

## 4. Why a route can fail and how to prevent it

| Cause | Prevention |
| --- | --- |
| Server rule, directory or file with the slug's name | Collision checks reject existing files; the health check proves the path reaches WordPress. |
| Full-page cache serving a stale page at the route | Health check requires the plugin's probe response header. Exclude the route from caching. |
| Permalinks switched to plain on a server without PATH_INFO | The route moves to `/index.php/<slug>/`; run **Test a route without saving** after permalink changes. |
| A page or post later created with the same slug | Cradle → Login diagnostics flag the collision ("Saved slug … now collides"). |
| Loopback requests blocked by the host | Enabling is refused with the transport error; fix loopback access (Site Health reports it too). |

## 5. Verifying recovery (framework tests)

`php tests/run.php --suite=login` checks the constant in a separate PHP process
(`tests/fixtures/login/recovery-constant.php`, which defines the real constant before WordPress loads),
the filter in-process, and deactivation/reactivation over HTTP.
