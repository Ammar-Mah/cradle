# Cradle Languages — feature contract

Status: implemented in 0.1.0 (core API `^1.0`, module dependency `blocks ^0.1`). Decision record:
`docs/decisions.md` 2026-10-31 (framework repository). Boundary: northstar §14.1 — Cradle Translations
translates interface strings per locale; Cradle Languages adds multilingual **content and routing**.

## Boundary with Cradle Translations

Cradle Languages decides the language of each frontend request and sets the WordPress locale for it
(`locale` filter, priority 5, so `switch_to_locale()` still wins). WordPress language packs and Cradle
Translations catalogs then apply on their own: Cradle Translations reads `determine_locale()`. This
plugin never stores or edits strings. The Languages screen shows, per configured language, whether a
WordPress language pack and a Cradle Translations catalog exist for its locale and links to
Cradle → Translations. Text direction is WordPress' own: the translation of `ltr` (context
`text direction`) — this plugin returns the configured direction of the request language there, so a
right-to-left language without a language pack still renders `dir="rtl"`. In wp-admin the user locale
is unchanged.

## Shipped translations (this plugin's own strings)

German (`de_DE`) and Arabic (`ar`) translations of every string of this plugin ship in `languages/`, so
installing the plugin is enough (no Cradle Translations catalog is needed for them):

| File | Contents |
| --- | --- |
| `languages/cradle-languages.json` | Editable source, format `cradle-translations-examples`: one object per locale (`de_DE`, `ar`) with `entries` of `{ domain, msgid, context?, msgidPlural?, forms }` (Arabic plurals have 6 forms). |
| `languages/cradle-languages-<locale>.l10n.php` | Generated WordPress PHP translation file: PHP strings, block.json `title` / `description` / `keywords` (contexts `block title`, `block description`, `block keyword`) and the descriptor labels. |
| `languages/cradle-languages-<locale>-<md5>.json` | Generated Jed JSON for each script that uses `wp.i18n` (`assets/js/panel.js`, both block `editor.js` files); `<md5>` = md5 of the script path relative to the plugin root. |

- Regenerate after changing or adding strings: `php bin/build-languages.php` (CLI only; a web request
  exits). It refuses to write when a string found in the plugin's PHP, JavaScript or block.json files is
  missing from a locale or has the wrong number of forms; `--check` only compares (exit 3 when out of
  date); `--out=<dir>` writes elsewhere. Commit the generated files.
- Loading: `boot` calls `load_plugin_textdomain()`, which registers `languages/` for WordPress'
  just-in-time loading: the request locale on `/xx/` pages, the user locale in wp-admin and the locale
  inside `switch_to_locale()`. The Language panel and both block editor scripts are registered with
  `wp_set_script_translations( $handle, 'cradle-languages', <plugin>/languages )`. A language pack in
  `wp-content/languages/plugins/` is found first; Cradle Translations overrides still apply on top.
- Descriptor labels in the `cradle` domain (module name — context `module name`, description —
  `module description`, capability label — `capability label`): a `cradle/translate_label` callback at
  priority 20 (after Cradle Translations) returns the translation from this plugin's domain while the
  label is still the English original; an override from Cradle Translations is kept. Works with and
  without Cradle Translations.
- `switch_to_locale()` only switches to locales WordPress offers (an installed language pack, or a Cradle
  Translations catalog while it exposes its locales); this plugin does not add locales to that list.

## Languages (settings section `languages`, option `cradle_languages_settings`)

| Field | Type | Default | Meaning |
| --- | --- | --- | --- |
| `languages` | list (≤ 10) of `{slug, locale, name, native, dir, default}` | `[]` → the site language only | Address prefix `[a-z]{2,3}(-[a-z]{2})?`, unique, not a WordPress route/feed/query var/permalink base/REST prefix part, not the slug of an existing top-level entry and not a file in the site folder (checked for new/changed prefixes). Locale: installed language, cached WordPress translation list entry, `en_US`, or `[a-z]{2,3}(_[A-Z]{2})?`; unique. Exactly one default; the default cannot be removed. |
| `hide_default_prefix` | boolean | true | Default language without `/slug/` (existing URLs unchanged). Off: default links get the prefix; unprefixed GET requests 301 to it. |
| `post_types` | `{type: translatable\|shared}` | public viewable types translatable, attachments / `wp_navigation` / `wp_block` shared; non-public types shared unless their `cradle-languages` support asks for translatable | Eligible: public viewable types, attachments, Navigation menus, synced patterns, every custom type (`_builtin` false) with admin screens (`show_ui`, for example non-public Cradle Content types shown through theme lists; built-in internal types stay excluded), and any type with the post type support `cradle-languages` (`add_post_type_support( $type, 'cradle-languages' )`, optionally `array( 'default' => 'translatable' )`; used by Cradle Forms for `cradle_form`). Unknown (currently unregistered) names are kept. The list of translatable types is only cached once `init` has finished, so types registered during `init` are never missed. |
| `taxonomies` | `{taxonomy: translatable\|shared}` | translatable when the taxonomy belongs to a translatable type | Public taxonomies (not `post_format`). |
| `menu_locations` | `{lang: {location: menu_id}}` | `{}` | Classic menu locations for non-default languages (missing = same as default). |
| `hreflang` | boolean | true | Output hreflang alternates. |

Capability `cradle_manage_languages` (administrators). Changing a type's mode never deletes data;
entries of a translatable type without a language count as the default language everywhere (queries,
links, switcher). The screen offers "Assign the default language" (job `languages/assign-default`) to
store that explicitly. Removing a language keeps its entries tagged; they are hidden on the frontend
until the language is added again. Changing a language's prefix while keeping its locale renames its
term (and term meta) on save.

## URLs and routing (directory prefix)

- `/<slug>/…` for each language that needs a prefix; default unprefixed while `hide_default_prefix`.
  Plain or `index.php` permalinks use `/index.php/<slug>/…`.
- **No rewrite rules.** On `cradle/ready` the raw `REQUEST_URI` is matched against the configured
  prefixes. `do_parse_request` (last) hands WordPress the path without the prefix and keeps
  `home_url()` unprefixed while it parses; `parse_request` (first) restores the original URI so
  `redirect_canonical()`, pagination and other code see the real URL. An unknown prefix is not stripped
  and gets WordPress' normal handling (404, or WordPress' own 404 slug guess).
- Routed: frontend requests through `index.php` only. Never wp-admin, admin-ajax, admin-post, REST
  (`/wp-json/`, `?rest_route=`), wp-login.php, XML-RPC, cron, CLI.
- `template_redirect` priority 1: a translatable entry, posts page or translatable term requested under
  another language's prefix → 301 to its own URL; the front page is never redirected (an untranslated
  front page is shown under every prefix). With the default prefix visible, unprefixed URLs → 301.
- Static front page / posts page per language: `option_page_on_front` / `option_page_for_posts`
  return the published translation in the request language (frontend only).

## Links

- `home_url` (routed frontend requests): the current language's prefix — archives, search, feeds,
  pagination, the home link. Never for the `rest` scheme, the REST prefix, `wp-*` paths, `*.php`,
  sitemaps, robots.txt, favicon.ico.
- `post_link`, `post_type_link`, `page_link`, `attachment_link`, `term_link` (everywhere, including
  wp-admin "View" links and REST `link` fields): the object's language; shared objects: the current
  language. The front page and its translations link to their language's home URL.
- `url_to_postid` accepts URLs of any language. Conversion replaces an existing prefix (idempotent)
  and leaves other hosts untouched.

## Content language and translation groups

- Language of an entry: hidden taxonomy `cradle_language` (term slug = language slug; not public, no
  UI, no REST, no query var; `assign_terms` = `edit_posts`). Queries may use
  `tax_query => [ [ 'taxonomy' => 'cradle_language', 'field' => 'slug', 'terms' => 'ar' ] ]`.
- New entries of translatable types get a language on insert: the pending language (translation copy),
  `?cradle_lang=<slug>` on wp-admin "Add New", or the default.
- Translation group: post meta `_cradle_lang_group` (UUID v4); at most one entry per language per group
  (`set_language()` refuses `cradle_languages_taken`; linking replaces a colliding member, which keeps
  its language and leaves the group). All members are of one type.
- Terms of translatable taxonomies: term meta `_cradle_lang` (slug; missing = default) and
  `_cradle_lang_group`. Language and translations are edited on the term screens.
- A top-level entry cannot take a language prefix as its slug (`wp_unique_post_slug` adds `-2`).

## Query filtering

Frontend (routed) `WP_Query` — main and secondary, `get_posts()`, Query Loop, Cradle Blocks post cards
and hero slider — for translatable types is limited to the request language unless the query sets
`cradle_lang` (`'all'` or a slug). Elsewhere (wp-admin, REST, cron, CLI) only an explicit slug filters.
Details: the clause is appended to the parsed tax query on `parse_tax_query` (term archive queried
objects unchanged); queries mixing translatable and shared types (search, `post_type=any`) get a WHERE
clause limited to the translatable types (needs `suppress_filters` false). The default language matches
untagged entries. Singular lookups are never filtered. Queries with explicit `post__in` are not filtered;
each chosen ID is replaced by its published translation in the request language when one exists. Search
results are limited to the current language. Terms (`WP_Term_Query`) of translatable taxonomies are
filtered likewise on the frontend, except lookups by slug, name, include, term_taxonomy_id or object.

REST: core collection endpoints of translatable types accept `cradle_lang` (`all` or a slug; omitted =
all, which the block editor uses) and expose a read-only `cradle_lang` field. wp-admin lists: "Languages"
column (language + links to translations) and a language filter (default all).

## Editor

Document panel "Language" (block editor, translatable types, `edit_post`): language select (fixed once
the entry has translations), the translations with edit links and status, and "Add <language>
translation" per missing language (disabled until saved: the copy is of the saved version).

REST `cradle-languages/v1` (cookie + REST nonce):

| Route | Method | Permission | Result |
| --- | --- | --- | --- |
| `/posts/{id}` | GET | `edit_post` (404 when not readable) | `{ id, lang, group, languageLocked, languages: [ {slug, name, native, dir, isDefault, canCreate, translation: {id, title, status, editLink, viewLink} \| null} ] }` |
| `/posts/{id}/language` | POST `{lang}` | `edit_post` | Same shape; 409 when the group already has that language. |
| `/posts/{id}/translations` | POST `{lang}` | `edit_post` on the source + type `create_posts` | 201 `{ id, source, lang, status: draft, editLink, report }`; 409 when it exists / is being created (lock `languages:translate:<id>`). |
| `/posts/{id}/link` | POST `{translations: {lang: id}}` | `edit_post` on every entry | Same shape as GET. |

"Add translation" copies (internal copier, see `Translator`): title, content, excerpt, featured image,
page template, parent (mapped to its translation), menu order, comment/ping status, shared terms, terms
of translatable taxonomies mapped to their translation (others skipped and reported), public meta and
`_cradle_cf_*`; never locks, old slugs, provenance/ownership markers (`_cradle_duplicate_of`,
`_cradle_source_key`, `_cradle_generat*`), this module's keys or secret/payment-like keys. Slug:
`<source-slug>-<lang>`. Cradle Duplicate is not used (title suffix, its own per-type enablement and
private-meta allowlist do not fit translations).

## Blocks and components

| Block / component | Model | Properties | Output |
| --- | --- | --- | --- |
| `cradle/language-switcher` (contract `language-switcher-v1`, shortcode `[cradle_component id="cradle/language-switcher"]`) | dynamic, no saved fallback | `layout` list\|dropdown, `display` native\|name\|slug\|native_slug, `hideMissing`, `hideCurrent`, `label` | `<nav aria-label>` with links (`hreflang`, `lang`, `dir`, `aria-current="page"`); dropdown = `<details>` (no JavaScript). Missing translation → language home (`is-missing`, announced) unless hidden. Nothing while only one language exists. |
| `cradle/language-section` (contract `language-section-v1`) | container; saved markup = inner blocks | `languages` (slugs; empty = every language) | The inner blocks only in the selected languages, no wrapper. Without the plugin every section shows. |

## SEO and output

- `<html lang>` from the request locale (WordPress), `dir` from the language direction.
- `hreflang` alternates (`wp_head` priority 2) for every known version: published translations of an
  entry or term; the front page, archives and shared entries in every language; plus `x-default` (default
  language). Only when at least two exist; never on 404 or search.
- Canonical URLs come from `get_permalink()` (prefix included).
- Core sitemaps list entries and terms of every language (`cradle_lang = all`), each with its own prefix;
  drafts are never listed (core sitemaps list published content only).

## Menus

Classic locations: `theme_mod_nav_menu_locations` (frontend) returns the menus configured for the
current language. Block themes: a Navigation block (`core/navigation` `ref`) or synced pattern
(`core/block` `ref`) renders the published translation of its entity in the current language when
Navigation menus / synced patterns are translatable; navigation links to entries or terms (`kind`
post-type / taxonomy with `id`) point to the published translation. Custom links are left as written.

## Public API

| Function | Returns |
| --- | --- |
| `cradle_languages_ready()` | Whether the module runs for this request. |
| `cradle_languages()` | Configured language rows (`slug, locale, name, native, dir, default`), ordered. |
| `cradle_current_language()` / `cradle_default_language()` | Row or null. Current = request language on the frontend, default elsewhere. |
| `cradle_language_of( $post_or_term )` | Row or null (shared types/taxonomies). |
| `cradle_get_translation( $post_id, $lang )` | Entry ID or 0. |
| `cradle_get_translations( $post_id )` | `lang => ID` including the entry. |
| `cradle_get_term_translation( $term_id, $lang )` | Term ID or 0 (shared taxonomies: 0). |
| `cradle_language_home_url( $lang )` | Home URL of a language or ''. |
| `cradle_language_switcher_items( $context )` | Items `{slug, name, native, locale, dir, hreflang, url, current, missing}`. |
| `cradle_set_language( $post_id, $lang )` | `true\|WP_Error` (no capability check). |
| `cradle_link_translations( array $lang_to_id )` | Group UUID or `WP_Error` (no capability check). |

Static classes: `Cradle\Languages\Languages` (`all()`, `get()`, `current()`, `switch_to()`/`restore()`,
`bcp47()`), `Content` (languages, groups, terms), `Links::localize( $url, $lang )`.

## Hooks

| Hook | Type | Payload |
| --- | --- | --- |
| `cradle_languages/request_language` | filter | `( string $slug, string $request_uri )` — must return a configured slug; runs at `plugins_loaded` priority 5 (attach from a must-use plugin). |
| `cradle_languages/translation_created` | action | `( int $new_id, WP_Post $source, string $lang, array $report )` |
| `cradle_languages/switcher_items` | filter | `( array $items, array $context )` — invalid items are dropped. |
| `cradle_languages/copy_protected_meta` | filter | `( bool $copy, string $key )` — copy another protected meta key into translations. |

## Import from Polylang

Cradle → Languages → Import from Polylang, or `php wp-content/plugins/cradle-languages/bin/import-polylang.php
[--run] [--json] [--user=<login>] [--wp=<root>]` (CLI only; a web request exits before reading
arguments). Reads Polylang's `language`, `term_language`, `post_translations` and `term_translations`
rows and the `polylang` option with prepared SQL — Polylang may be inactive — through a bounded
structural parser (never `unserialize()`). **Dry run first:** the report (languages, entries and terms per
language, groups, menu locations, the type and taxonomy mapping, problems, warnings) is stored per user for
30 minutes; the import requires a report with no problems whose fingerprint still matches the data. The
import is the job `languages/polylang-import`: settings (languages, default, prefix choice, types,
taxonomies, menus merged) → entry languages → groups → term languages → term groups. Idempotent: groups get
a UUID derived from the Polylang group, so a second run gives the same result. Polylang data is never
changed or deleted. Directory-prefix sites keep their URLs; other Polylang URL modes are reported as a warning.

Type and taxonomy mapping. Polylang translates `post`, `page`, `category` and `post_tag`, the custom types and
taxonomies listed in its option (`post_types`, `taxonomies`) and media when media support is on; every
other type and taxonomy is untranslated in Polylang, i.e. shared. The import writes an explicit mode for
**every** type and taxonomy Cradle Languages can configure — translatable when Polylang translates it,
otherwise shared — so Cradle's own defaults (public types translatable) never apply to imported content.
Exception: a type that declares the `cradle-languages` post type support and that Polylang does not list
(Polylang never managed it, for example `cradle_form`) keeps its declared default. The dry run lists the
mapping (report keys `postTypes` and `taxonomies`: name → mode; the screen lists translatable and shared
types and taxonomies; the CLI prints one line each). Types and taxonomies that Polylang translates but
Cradle Languages cannot configure (not registered right now, or not eligible) are reported as warnings;
they are stored as translatable anyway, so they behave as in Polylang once their plugin or theme is active
again — run the import again then to give their entries and terms a language.

## Out of scope (v1)

Domains/subdomains per language, browser-language redirects, machine translation, string translation
(Cradle Translations), multisite, shared slugs across languages, WooCommerce specifics (products are a
translatable public type; cart/checkout/account flows are untested).
