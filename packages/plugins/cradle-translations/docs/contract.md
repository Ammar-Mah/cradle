# Cradle Translations — feature contract

Status: implemented in 0.1.0 (core API `^1.0`). Spec: northstar §14, acceptance CR-17.

## What it translates

| Kind | Mechanism |
| --- | --- |
| PHP strings `__()`, `_e()`, `esc_html__()` … | `gettext_{domain}` filter |
| Contextual `_x()`, `esc_attr_x()` … | `gettext_with_context_{domain}` |
| Plural `_n()`, `translate_nooped_plural()` | `ngettext_{domain}` |
| Contextual plural `_nx()` | `ngettext_with_context_{domain}` |
| JavaScript `wp.i18n` strings of registered handles | `load_script_translations` (merge into a shipped Jed file) and `pre_load_script_translations` (final fallback call, `$file === false`: supply Jed JSON when no file exists) |
| Cradle configuration labels (module names, descriptions, capability labels, registered labels) | `cradle/translate_label` filter + label registry |

Filters are attached only for text domains that have a published override in some locale
(list kept in the autoloaded option `cradle_translations_index`). A lookup is: current locale
(cached) → per-request map for that locale (one runtime-file read per locale per request, or an
object-cache hit) → array key lookup.

**Fallback:** site override → shipped/native translation (the value WordPress already resolved) →
original text. Fuzzy ("needs review") and incomplete translations are never applied. Obsolete
entries keep applying (the string may still be used dynamically).

**Locale:** `determine_locale()` — the user locale in wp-admin and for `_locale=user` REST requests,
the site locale on the frontend, the switched locale inside `switch_to_locale()`. The cached
locale resets on `unload_textdomain`, `change_locale` and `set_current_user`, so a locale switch
never serves another locale's map.

**Available languages:** with the `expose_locales` setting (default on), locales that have
published overrides are added to `get_available_languages()`, so users/the site can select them
and `switch_to_locale()` accepts them even without a WordPress language pack. RTL direction for a
locale without a language pack comes from WordPress' own `text direction` string: override `ltr`
(context `text direction`, domain `default`) with `rtl` if needed.

## Built-in example languages (German and Arabic)

`examples/de_DE.json` and `examples/ar.json` ship a complete, reviewed translation of every string of the
Cradle framework packages (format `cradle-translations-examples`: `{format, locale, entries: [{domain,
msgid, context?, msgidPlural?, forms}]}`; German 2 plural forms, Arabic 6). `Examples` keeps them present:

- Created on activation and on the first admin request after the shipped file changes (or when the catalog
  is missing): one option read and two `stat` calls on ordinary admin requests.
- Shipped text is published only into entries with **no translation and no draft**, so an administrator's
  edits always win; clearing an entry brings the shipped text back on the next source refresh. A source
  refresh also fills strings that just appeared.
- The two catalogs cannot be deleted (Service refuses; the screen explains why instead of offering Delete).
- Arabic translates WordPress' own `ltr` (context `text direction`, domain `default`) to `rtl`, so the admin
  turns right-to-left without a WordPress language pack; WordPress' own strings stay English unless its
  language pack is installed.
- Site tooling can add translations for a site's own strings through `Examples::fill( $locale, $rows )`
  (the sandbox does this for its child theme).

## Catalog lifecycle

1. **Add locale** — creates `<locale>.json` pre-filled with every extracted Cradle string and
   registered label (status `missing`).
2. **Edit** — "Save draft" stores the forms in `draft`; "Save and publish" publishes that entry.
   Clearing every field creates a removal draft.
3. **Publish** — re-validates every pending draft (all-or-nothing), moves drafts to
   `translations`, regenerates the derived runtime file of *that locale only* and invalidates
   only that locale's cache entry.
4. **Revisions** — every write increments `revision`; the replaced file is kept as a snapshot
   (bounded by `history_limit`). Restore writes the snapshot's entries as a new revision.
5. **Refresh sources** — re-extracts Cradle packages (admin button, or cron after a Cradle package
   update) and merges into every catalog: new strings added, references updated, vanished
   extracted/registered strings marked `obsolete` with translations kept, returning strings revived.

## Import / export

- **Export:** authoritative JSON catalog (drafts included, internal revision log excluded).
- **Import JSON:** same locale only; strict schema + semantic validation; any problem rejects the
  whole file. Matching entries receive the file's forms as drafts (or published with "Publish
  immediately"); unknown entries are added (`imported`). An untranslated entry in the file never
  clears an existing translation.
- **Import .po/.pot:** for WordPress, WooCommerce or other text domains. Adds source strings
  (`imported`), references, translator comments and JS flags (references ending in `.js`).
  Optional: translations as drafts (invalid ones skipped and listed; fuzzy kept as fuzzy). Optional:
  mark previously imported strings of that domain missing from the file as obsolete.
- **Download .pot** per extracted domain (Sources tab) for build use.

## Extraction

Only directories named `cradle` / `cradle-*` directly in `WP_PLUGIN_DIR` and the theme root.
Paths are discovered, canonicalized and containment-checked; symlinks are not followed; no path
or URL is ever accepted from a request. Files: `*.php`, `*.js` (a `.min.js` is skipped when its
source exists); skipped directories: `node_modules`, `vendor`, `.git`, `.svn`, `skills`, `tests`,
`dist`, `languages`. Bounds: 4000 files, 1 MiB per file, 20 000 strings.

Detected: PHP `__ _e esc_html__ esc_html_e esc_attr__ esc_attr_e esc_xml__ esc_xml_e _x _ex
esc_html_x esc_attr_x esc_xml_x _n _nx _n_noop _nx_noop` via `token_get_all()` with string-literal
arguments (adjacent literals joined with `.` are resolved); JS `__ _x _n _nx` called bare or as
`i18n.<fn>` / `wp.i18n.<fn>` via a small tokenizer that understands strings, template literals,
comments and regular-expression literals. `/* translators: */` comments are kept.

**Not detectable** (listed on the Sources tab with file and line when the call itself is seen):
variables, constants or function calls as text/context/domain; heredoc/nowdoc; template literals
with `${}`; strings assembled at runtime; bundled/minified code that renames i18n functions (e.g.
webpack `(0, i18n.__)(…)`); aliases other than the names above. Add such strings manually
("Add a string that is not listed").

## Settings (`cradle_translations_settings`, Cradle → Translations → Settings)

| Field | Default | Meaning |
| --- | --- | --- |
| `runtime_enabled` | true | Kill switch for every override (catalogs untouched). |
| `js_enabled` | true | Merge overrides into script translations. |
| `expose_locales` | true | Offer published catalog locales as available languages. |
| `auto_refresh` | true | Background source refresh after Cradle package updates. |
| `history_limit` | 20 (3–100) | Snapshots kept per locale. |
| `max_import_kb` | 4096 (64–8192) | Upload limit for imports. |
| `per_page` | 50 (20–200) | Editor page size. |

Runtime settings are read from the option at `cradle/ready`, i.e. they apply from the next request.

## Known limitations

- JS strings are delivered per text domain (every JS-referenced override of the domain is merged
  into each handle of that domain), not filtered per script file.
- When a shipped Jed file declares a different `nplurals` than Cradle's rule, plural JS overrides
  are skipped for that file (shipped plural data kept).
- Strings translated before `plugins_loaded` priority 5 (unusual) cannot be overridden.
- Core renders module names on its own screens without `cradle/translate_label`, so overriding a
  registered module-name label only affects code that calls the filter.
- Unknown locales use the two-form `n != 1` rule; the editor says so.
