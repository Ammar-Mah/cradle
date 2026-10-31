# Cradle Languages — security notes (northstar §20)

Surface inventory: `php skills/cradle-security/scripts/check-surface.php packages/plugins/cradle-languages`
(2026-10-31: 3 admin-post, 4 REST routes, 3 request-time hooks, 8 prepared SQL with internal IN lists,
0 uploads, 0 unserialize). Every mutation below is exercised with allowed and denied roles in
`tests/integration/languages.php`.

## Trust boundaries

| Input | Treatment |
| --- | --- |
| Request URI (every visitor) | Compared with configured prefixes only (`[a-z]{2,3}(-[a-z]{2})?`); never output, never used in SQL or file paths. The original URI is restored after WordPress parses. |
| Settings (administrators with `cradle_manage_languages`) | Core settings validation: allowlisted shapes, slug/locale patterns, ≤ 10 rows, uniqueness, reserved words, content collisions, enum modes, existing menu IDs. Stored values are re-validated on read (`Languages::normalize_rows()`). |
| REST bodies (editors) | `args` schema (integer IDs, slug pattern, object of integers), then object capability checks; languages must be configured. |
| Term form fields | Nonce `cradle_languages_term` + `edit_term` on the term and on every linked term; same taxonomy only. |
| Polylang rows and option (site database) | Read with prepared SQL; serialized descriptions decoded by a bounded structural parser (64 KiB, depth 3, scalars and arrays only — objects/references refused); IDs re-checked as existing entries/terms; slugs and locales validated like settings. |
| Block attributes | Component normalization (enum, boolean, maxLength, slug format, maxItems); output escaped. |

## Authorization and request integrity

- `cradle_manage_languages` (administrators only by default; editors never) for the screen, settings
  (options.php enforces it via `option_page_capability_cradle_languages`), both jobs and the three
  `admin-post.php` actions, each with its own nonce (`cradle_languages_assign`,
  `cradle_languages_polylang_check`, `cradle_languages_polylang_import`) and a capability re-check.
  Menu visibility is not authorization. Redirects use `wp_safe_redirect()` to the screen.
- REST (`cradle-languages/v1`): logged-in cookie session with the REST nonce (a cookie without it is
  treated as anonymous → 401). `edit_post` on the entry (404 when the user cannot read it, so existence
  is not revealed); creating a translation also needs the type's `create_posts`; linking needs
  `edit_post` on every entry. The core jobs runner checks the job capability and ownership.
- GET never mutates: the panel data route and the list filter are read-only; "Add translation" is POST.
- wp-admin "Add New" `?cradle_lang=` only preselects the language of the user's own new auto-draft
  (validated against configured languages).
- The Polylang import refuses to start without a dry run whose fingerprint matches the current data and
  that lists no problems; it never writes Polylang data.
- CLI `bin/import-polylang.php`: `PHP_SAPI !== 'cli'` exits before arguments are read (tested over HTTP);
  acts as an existing user with the capability.
- Eligible types include custom types that are not public but have admin screens (`show_ui`). That only
  makes them configurable: nothing changes until an administrator (or the Polylang import, for types
  Polylang translated) sets one translatable, and the editor panel, REST routes and "Add translation"
  keep their `edit_post` / `create_posts` checks for its entries. Built-in internal types stay excluded.

## Routing (request-time hooks)

`cradle/ready` → `Request::start()`, `parse_request`/`wp` → `after_parse()`, `template_redirect` →
`redirect()` run for every frontend visitor by design. They perform no privileged mutation: they read
the request URI, change `$_SERVER['REQUEST_URI']`/`PATH_INFO` only while WordPress parses (restored
right after), set the locale and issue 301 redirects to URLs built by WordPress (`get_permalink()`,
`get_term_link()`) through `wp_safe_redirect()`. wp-admin, admin-ajax, admin-post, REST, login, XML-RPC,
cron and CLI are never routed. A filter (`cradle_languages/request_language`) can only choose a configured
language.

## Output

- Escaping by context in every render path (`esc_html`, `esc_attr`, `esc_url`; the tag processor for
  navigation link `href`s); language names are plain text.
- Interface strings come from translation functions; translated text is escaped after translation.
- Shipped translation files: `languages/*.l10n.php` is PHP that WordPress includes. It is generated only by
  the CLI script `bin/build-languages.php` (a web request exits first; tested over HTTP) from the plugin's
  own `languages/cradle-languages.json`, with every string exported as an escaped PHP literal
  (single-quoted, or double-quoted with `\`, `"`, `$` and control characters escaped), so translation text
  cannot become code. Never feed translations from visitors or site data into that source; site-specific
  wording belongs in Cradle Translations (stored as JSON, never as PHP). Jed files hex-escape `<`, `>` and
  `&` because WordPress prints them inside an inline script.
- The switcher and language sections are dynamic and never cached as public output (`cache: none`).

## SQL

Prepared statements throughout; `IN (…)` lists are built from placeholders (`%s`) or from integer IDs
cast with `intval`; post type / taxonomy identifiers are additionally matched against
`[a-z0-9_-]{1,20|32}` before they reach a query. Table names come from `$wpdb`.

## Copying (Add translation)

Never copied: locks, old slugs, attachment internals, menu internals, `_cradle_lang_*`,
`_cradle_duplicate_of`, `_cradle_source_key`, `_cradle_generat*`, `_cradle_test_fixture`, any key whose
segments look like secrets or payment data (`password`, `token`, `secret`, `auth`, `nonce`, `session`,
`license`, `signature`, `webhook`, `payment`, `transaction`, `billing`, `card`, `iban`, `stripe`,
`paypal`), and other protected keys unless allowed by `cradle_languages/copy_protected_meta`. Terms are
assigned only for taxonomies where the user has `assign_terms`. The copy is always a draft owned by the
translating user.

## Not established

Manual penetration testing and fuzzing of the URL matcher; behaviour behind reverse proxies that rewrite
`REQUEST_URI`; WooCommerce flows; multisite (refused at activation).
