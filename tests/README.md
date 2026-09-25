# Cradle integration tests

Run against the disposable sandbox in `../wp`:

```
php tests/run.php                       # every suite, sequentially, in one process
php tests/run.php --suite=core,blocks   # selected suites
php tests/run.php "settings"            # tests whose name contains a filter
php tests/run.php --suite=lifecycle     # temporarily switches theme / replaces packages; run alone
```

Results are PASS / FAIL / SKIP. A skipped test names the missing precondition; it is never a pass.

## Harness (tests/lib/bootstrap.php)

- `cradle_test_user( $role )` creates or reuses a disposable `cradle_test_<role>` user.
- `cradle_test_cookies( $user_id )` mints a real session and auth cookies (no password involved).
- `cradle_test_nonce( $user_id, $session, $action )` creates a nonce bound to that session.
- `cradle_test_http( $method, $url, $args, $user_id, $session )` performs a real HTTP request to the sandbox.

## Do not run suites in parallel processes

WordPress keeps all of a user's session tokens in one `session_tokens` user-meta value. Two processes
minting sessions for the same shared test user at the same time race on that value and one session is
lost ("cookie check failed"). `tests/run.php` runs suites sequentially in one process, which is safe.
Suites that must run concurrently with other work should create their own users (see translations.php, `ct_user()`).

## Separate processes

Some suites run helper scripts from `tests/fixtures/<suite>/` in a separate PHP process to exercise
constants, uninstall routines or a missing dependency without affecting the test process.
Migration restores run only against the separate `wp-target/` install (database `cradle_wp_target`).

## Temporary sandbox fixtures

The core suite copies `tests/fixtures/core/settings-section.php` into `wp/wp-content/mu-plugins/` for
one test (core has no settings page of its own, so a test-only section exercises options.php saving,
stale-revision rejection and capability checks) and removes it, with its option, in a `finally` block.
