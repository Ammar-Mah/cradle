# Cradle Translations — security notes (northstar §20)

## Trust boundaries

| Input | Treatment |
| --- | --- |
| Editor submissions (translators with `cradle_manage_translations`) | Untrusted data: validated, never executed. |
| Uploaded JSON / `.po` / `.pot` catalogs | Size-bounded before parsing, UTF-8 checked, depth-limited JSON, strict schema, per-entry validation. |
| Stored catalogs and runtime files | Re-validated on read (schema) and before publishing (semantics); runtime generation re-validates each translation. |
| Cradle package source files | Read as text and tokenized for extraction; never included or executed. |
| `Plural-Forms` headers | Never evaluated; only `nplurals` (an integer) is read for comparison. |
| Module descriptors / registered labels | Trusted PHP callers; labels are still length/UTF-8 checked. |

## Authorization and request integrity

- Capability `cradle_manage_translations` (administrators by default) on every operation, checked
  in `Service` and again in each `admin-post.php` handler; the admin page checks it too.
  Menu visibility is not authorization.
- Every browser mutation: POST only (GET → 405), nonce bound to the action **and** locale
  (`cradle_translations_<action>_<locale>`), redirects via `wp_safe_redirect()` to the screen.
- The capability grants nothing else: no mapping to `cradle_import_site`, `cradle_export_site`,
  `upload_plugins`, `unfiltered_html` or `manage_options` (tested with a translator-only role).
- Module `export()`/`import()` handlers require this capability or the site export/import capability.
- No REST routes and no public endpoints.

## Validation (validate, then normalize)

- Text: string type, valid UTF-8, ≤ 10 000 characters, no C0 control characters except tab/newline/CR.
- Placeholders (`%s`, `%d`, `%1$s`, flags/width/precision, `%%`): non-plural translations must use
  exactly the source arguments (reordering with numbered placeholders allowed); plural forms may
  omit but never add or retype an argument; numbered and unnumbered placeholders cannot be mixed;
  a printf-style source requires `%%` for a literal percent; a non-printf source must not gain a
  placeholder.
- HTML: only an approved subset (`a abbr b bdi bdo br cite code del em i ins kbd mark p q s samp
  small span strong sub sup time u var`) and only tags the source already uses (plus `br`, `bdi`,
  `bdo`); attributes allowlisted per tag (`on*` never), link targets must equal a source `href`
  (usually a placeholder); comments, `<?…?>` and declarations rejected; malformed tags rejected.
  Untrusted text is only written with `textContent` in the editor JS.
- Plural forms: exactly `nplurals` forms of the locale or none.
- Catalog schema: allowlisted keys, types, locale/domain patterns, duplicate identities, entry and
  domain counts, JSON depth, file size.

## Output

Every admin value is escaped for its context (`esc_html`, `esc_attr`, `esc_url`, `esc_textarea`).
Runtime overrides are returned to WordPress translation functions, which the calling code escapes
(`esc_html__()`, `esc_attr__()`, …). Script translation JSON is encoded with `JSON_HEX_TAG |
JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`, so it is inert inside the inline `<script>`.

## Files and execution

- No `eval`, no `include` of user-controlled paths, no `unserialize` of untrusted data, no shell,
  no remote requests (the storage probe is core's).
- Storage paths are built from validated locale codes only; writes are JSON only; the test suite
  asserts that the only PHP file in storage is core's unchanged `index.php` guard and that the
  catalog URL is not downloadable.
- Extraction roots are discovered (`cradle*` in the plugin and theme roots), canonicalized with
  containment checks; symlinks are not followed; request data never selects a path or URL.
- Uninstall re-derives and re-validates paths and deletes only file names this package writes.

## Operational

- Locks prevent concurrent writes; stale edits are refused (per entry for single edits, exact
  revision for whole-catalog operations). History is bounded. Activity records (`cradle_log_activity`)
  contain locale, revision and a short summary — no translation payloads or user data.
- Cache entries are scoped per locale and validated against the published hash.
