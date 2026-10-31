# Security surface inventory

Generated 2026-09-24, with cradle-languages and cradle-forms added 2026-10-31 (core, cradle-blocks, cradle-content and cradle-translations recounted after the admin simplification, the hero slider move and the example languages; cradle-hero and cradle-fonts were retired; the theme recounted after the child-theme generator moved into it) with `php skills/cradle-security/scripts/check-surface.php <package>` (read-only
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
| `cradle-languages` | 0 | 3 | 4 | 0 | 8 | 16 (CLI only: STDERR writes, `bin/build-languages.php` reading sources and writing `languages/`) | 3 | 20 |
| `cradle-forms` | 0 | 4 + 1 nopriv (public submit) | 1 (public submit) | file fields into core private storage | 18 | 22 (uploads, stream, uninstall purge; CLI `bin/build-languages.php`) | 6 | 40 |

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
| languages `Request::start` / `after_parse` / `redirect` (`do_parse_request`, `parse_request`, `wp`, `template_redirect`) | Routing for every visitor by design: strips a configured language prefix from the path while WordPress parses it and restores the original URI at the first `parse_request` callback; GET/HEAD-only 301 to an entry's own language URL through `wp_safe_redirect`. No mutation; never runs for wp-admin, admin-ajax, admin-post, REST, login, XML-RPC, cron or CLI. | languages suite: prefixed URLs, wrong-prefix redirects, unknown prefix 404, deactivation restores original URLs (over HTTP) |
| languages `bin/import-polylang.php` variable `require` | CLI only (`PHP_SAPI` check first); the path is the operator's own `--wp` argument plus `wp-load.php`. | languages suite: Polylang dry run and import on fixture data |
| languages/forms `bin/build-languages.php` file reads/writes | CLI only (`PHP_SAPI` check first; a web request gets an empty response). Reads the package's own PHP, JS and block.json sources and `languages/<domain>.json`; writes only generated `languages/<domain>-<locale>*.l10n.php`/`.json` (or `--out`); translator text is written as escaped PHP string literals (single-quoted with `\` and `'` escaped, or double-quoted with `\`, `"`, `$` and control characters escaped), so input never becomes code. Refuses to write when a locale is incomplete. | languages and forms suites: generator rerun into a temp folder equals the committed files; web request returns nothing |
| forms `admin_post_nopriv_cradle_forms_submit` / `admin_post_cradle_forms_submit` | Public form submission by design: POST only (GET → 405); cross-site requests refused (`Sec-Fetch-Site`/`Origin`/`Referer`); HMAC-signed render token (form, time, language; max 30 days); honeypot; minimum fill time; rate limit per keyed network hash and form; nonce for logged-in users only (anonymous pages may be cached). Only published `cradle_form` posts accept entries; values validated against the stored schema; PRG result kept under a one-time random token. | forms suite: honeypot, too fast, forged/foreign/expired tokens, cross-site posts, rate limit, logged-in nonce, field errors |
| forms REST `cradle-forms/v1/submit/<form>` (`permission_callback` public) | Same checks as the no-JS handler, plus a required same-origin proof (an `unknown` origin is refused on this route). | forms suite: REST origin checks and submission |
| forms uploads (`move_uploaded_file`) | `is_uploaded_file`; per-field size and count limits; every extension segment against a blocklist, final extension against the field and site allowlists, `wp_check_filetype_and_ext()` plus leading-byte signatures; stored under a random 32-hex name in core private storage only when `is_protected()`; JPEG/PNG re-encoded to drop metadata. Download only through `admin_post_cradle_forms_download` (`cradle_view_form_entries` + nonce) with `attachment`, `nosniff` and a sandbox CSP. | forms suite: wrong type, spoofed MIME, random bytes, double extension, oversize, too many files, unprotected storage, file not reachable by URL, download allowed/denied |
| forms `template_redirect` `no_cache`, `load-edit.php` `redirect_list` | `no_cache` only sends no-store headers on a PRG result page; `redirect_list` sends the core list of `cradle_form` to the Cradle → Forms screen. Neither mutates. | forms suite: PRG headers; admin screens |

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
