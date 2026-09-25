---
name: cradle-translations
description: Use when changing interface labels or strings per locale, building or editing Cradle Translations JSON catalogs, overriding WordPress/WooCommerce/Cradle translatable strings in PHP or editor JavaScript, handling contexts, plurals, placeholders or RTL locales, importing/exporting catalogs, or extracting Cradle source strings. Produces a validated catalog and PHP/JS runtime integration that keeps domain, context and plural identity intact.
---

# Cradle translations

## Required context

- `cradle-project` orientation; `packages/plugins/cradle-translations/` source, `AGENTS.md`, `docs/`.
- `northstar.md` §14 (scope, catalog model, storage/runtime, plurals/placeholders/safety, extraction),
  §20.4; WordPress i18n references [R21]–[R24].
- `references/catalog-rules.md` — entry identity, schema limits, placeholder and plural rules, runtime
  JSON shape, fallback order.

## Preconditions

- Scope is interface strings, not multilingual content: page copy, per-language URLs, hreflang and
  translated menus need a separate design — say so when asked.
- Identify for each string: text domain, original, context, plural original, and where it is used
  (PHP gettext, JS script handle, Cradle-registered label). Strings not exposed through a translation
  mechanism or a Cradle registry cannot be overridden reliably; report them as unsupported.
- Know the locale in play: `determine_locale()` (admin/user locale) versus the site locale on the frontend.

## Procedure

1. Inventory: search the source for the string with its domain (`__( 'Add to cart', 'woocommerce' )`,
   `_x()`, `_n()`, `_nx()`, JS `wp.i18n.__()`), record every occurrence with context and whether it is
   PHP or JS (which script handle). Do not assume identical text means identical entry.
2. Catalog entry: one authoritative JSON catalog per locale (for example `de_DE.json`) grouped by
   domain; identity = domain + msgid + context + msgidPlural. Keep `id` only as a UI key.
3. Validate on save and import: schema, UTF-8, size/depth/entry/string limits, placeholder
   compatibility, allowed HTML, plural count for the locale. Reject invalid entries with field errors;
   never execute or `eval` anything from a catalog.
4. Storage: managed storage outside updateable code (`$core->storage()->module_dir( 'translations', … )`
   or registered data), atomic write (temp file + rename), `revision` compare-and-swap, revision history.
   Save draft separately from publish when useful.
5. PHP runtime: preload a locale/domain lookup map once per request (no file reads per string);
   hook the domain-specific filters (`gettext_{domain}`, `gettext_with_context_{domain}`,
   `ngettext_{domain}`, `ngettext_with_context_{domain}`) for domains that have overrides; return the
   original when no override exists.
6. JS runtime: generate per-domain/per-handle runtime JSON in WordPress's expected Jed shape from the
   published catalog and supply it through `wp_set_script_translations()` plus the
   `load_script_translation_file`/`pre_load_script_translations` filters. Generated files are data (JSON),
   never PHP.
7. Cache per locale and domain; invalidate only affected entries on publish; never leak one locale's
   cache into another request.
8. Extraction for Cradle packages: token-aware (`token_get_all()`) over known source files to a build-time
   manifest; never execute sources; mark dynamic strings as not discoverable; keep obsolete entries for review.
9. Output is escaped by context after translation (`esc_html__()` patterns stay in source).

## Verification

- Two contrasting locales plus a plural-rich or RTL locale (for example `de_DE`, `pl_PL` or `ar`):
  save → publish → reload shows the override in PHP output and in the editor JS string (CR-17).
- Same text with different contexts stays distinct; `_n()` shows the right form for 1, 2, 5, 22.
- Placeholder mismatch (`%s` → `%d`, missing `%1$s`) is rejected with an error.
- Concurrent edits: a stale `revision` is refused without data loss.
- Two requests in different locales do not share cached translations.
- Updating a package keeps overrides; obsolete strings are flagged, not deleted.
- Tests in `tests/integration/translations.php`; run `php tests/run.php --suite=translations`.

## Required output

Strings affected with domain/context/plural identity and PHP/JS location, catalog changes and
revision, runtime integration used, unsupported strings, verification results per locale.

## Failure behavior

- A string is hardcoded or not exposed to gettext/JS translations: report it as unsupported; propose a
  source change (Cradle) or an upstream filter (third-party) instead of output rewriting.
- Invalid catalog import: reject the whole file or the invalid entries with reasons; never partially
  apply silently.
- Request to translate page content: explain the boundary and point to a multilingual-content decision.

## References

- `references/catalog-rules.md`
- `northstar.md` §14; acceptance CR-17
- Related skills: `cradle-security` (import safety), `cradle-woocommerce`, `cradle-admin` (label overrides)
