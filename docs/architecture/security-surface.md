# Security surface inventory

Generated 2026-09-24 (core, cradle-blocks, cradle-content and cradle-translations recounted after the admin simplification, the hero slider move and the example languages; cradle-hero and cradle-fonts were retired; the theme recounted after the child-theme generator moved into it) with `php skills/cradle-security/scripts/check-surface.php <package>` (read-only
token scan) and reviewed by hand. A pattern scan does not establish safety; each entry point below is
also exercised by allowed-role and denied-role tests in `tests/integration/<suite>.php`.

## Counts per package (scanner categories)

| Package | AJAX | admin-post | REST | Uploads | SQL (prepared/internal) | File ops | Priority (!) | Review (?) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| theme `cradle` | 0 | 0 | 0 | 0 | 0 | 5 (CLI generator) | 0 | 5 |
| `cradle` (core) | 3 | 4 | 0 | settings import (JSON) | 24 | 11 | 1 | 43 |
| `cradle-blocks` | 0 | 0 | 0 | 0 | 1 | 0 | 0 | 3 |
| `cradle-login` | 0 | 1 | 0 | 0 | 1 | 1 | 2 | 14 |
| `cradle-admin` | 0 | 3 | 0 | 0 | 0 | 0 | 3 | 4 |
| `cradle-content` | 0 | 1 | 1 | 0 | 10 | 1 | 0 | 17 |
| `cradle-duplicate` | 0 | 1 | 1 | 0 | 0 | 0 | 2 | 4 |
| `cradle-dummy-content` | 0 | 4 | 0 | 0 | 3 | 2 | 1 | 9 |
| `cradle-translations` | 0 | 1 | 0 | catalog/.po import; shipped example catalogs (read-only package files) | 0 | 18 | 0 | 23 |
| `cradle-migration` | 3 + 1 nopriv | 2 | 0 | chunked archive upload | 17 | 74 | 4 | 91 |
| `cradle-social` | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 3 |

## Priority items — reviewed

| Item | Verdict | Evidence |
| --- | --- | --- |
| core `Settings::admin_init` reads `$_POST['option_page']` | Intended: options.php verifies the group nonce and capability before sanitize callbacks run; the value is only compared. | core suite: stale revision + editor refusal over HTTP |
| migration `wp_ajax_nopriv_cradle_migration_continue` | Intended post-cutover recovery context (§15.8): POST only; 256-bit job token checked against a SHA-256 hash stored in private storage independent of replaced tables; expiry; lockout after 20 failures; only advances jobs already in cutover phases; returns progress/verification only. | migration suite: session change during import, recovery, tampered token |
| migration `wp_ajax_cradle_migration_continue` / `guard_cancel` | Same handler for logged-in users; `guard_cancel` only refuses cancellation (core then checks nonce and access). | migration suite |
| migration `Guard::enforce` on `init` | Maintenance/safety hold: blocks writes during an export window or cutover; performs no privileged mutation. | migration suite |
| login `Router::guard` (`wp_loaded`), `suppress_admin_location_redirects` | Routing for every visitor by design; no mutation; never touches ajax/admin-post/REST/cron/CLI. | login suite (18 tests, flow matrix) |
| duplicate `admin_post_*`, REST `permission` | Capability/object checks delegated to `Policy` (read + edit source, create target type). | duplicate suite: contributor/subscriber/author-on-others denied, GET no-op |
| admin CSS save/restore/reset | Delegated check (`manage_options` + `unfiltered_html`) + nonce; GET → 405. | admin suite: editor/subscriber/admin-without-unfiltered_html → 403 |
| dummy-content `admin_post_cradle_dummy_job` | Delegated `cradle_generate_dummy` / `cradle_cleanup_dummy` checks + nonce. | dummy-content suite: editor/subscriber denied |
| theme `bin/child-theme.php` file writes | CLI only (`PHP_SAPI` check exits before reading `$argv`, so a web request cannot pass arguments); writes only `<target>/<slug>` with a regex-restricted slug (no separators, framework names refused), refuses an existing folder and never overwrites files; `check` writes only missing starter files inside a folder whose `style.css` declares `Template: cradle`. Reads only the starter and the named child. | child-theme suite: refusals, overwrite protection, foreign theme refused, web request creates nothing |

## Review items (?)

Mostly `$wpdb->prepare()` with internally built table names/`IN` lists (allowlisted identifiers), file
operations on paths canonicalized under private storage/uploads/fonts roots, variable `require` in
package autoloaders (regex-restricted class names, fixed directories), and redirects through
`wp_safe_redirect`. No `eval`, no shell execution, no `unserialize()` of archive/catalog data
(migration uses a bounded structural parser), no remote requests except core's own storage probe,
Cradle Login's self health check and migration's own loopback verification.

## Not established by this review

Manual penetration testing, fuzzing, dependency scanning (there are no third-party runtime
dependencies) and a review of WordPress core itself are out of scope.
