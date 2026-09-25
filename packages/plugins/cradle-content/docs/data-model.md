# Cradle Content — data model

## Options

| Option | Autoload | Contents | Written by |
| --- | --- | --- | --- |
| `cradle_content_definitions` | **yes** | `{ schema: 1, post_type: {key: def}, taxonomy: {…}, field_group: {…} }` — normalized definitions plus `revision`, `modified`, `forked_from`, `renamed_from`. | Admin saves/imports/renames (under lock `content:definitions`). |
| `cradle_content_settings` | yes | Core settings section `content` (`_revision`, `_schema`, fields below). | Settings API form, core import/reset. |
| `cradle_content_rewrite` | yes | md5 of the rewrite-relevant signature last flushed. | First request after a meaningful change. |
| `cradle_content_caps` | no | `"kind:key" => { caps: [], roles: [] }` — capabilities this module granted for custom capability models. | Definition saves/deletes/renames. |
| `default_term_<taxonomy>` | (core) | Default term ID; created by WordPress for taxonomies with a default term. Renamed by taxonomy key migrations. | WordPress core. |

**Autoload decision.** The definitions option is read on every request during `init` to register post types,
taxonomies and meta, so it is autoloaded (served from the `alloptions` cache; no extra query). It is bounded:
at most 50 definitions per kind, 50 fields per group, 300 fields in total, 20 template rows, 100 choices per select.
A typical site stores a few KB. Code definitions are not stored at all.

Per-request cost: the autoloaded read, the code definitions filter, structural validation of code definitions,
`register_post_type`/`register_taxonomy`/`register_meta` calls and one md5 over the rewrite signature. No
database queries of its own on ordinary requests. (WordPress core itself runs one `term_exists()` per request
for each taxonomy that declares a default term.)

## Settings (section `content`)

| Field | Type | Default | Meaning |
| --- | --- | --- | --- |
| `migration_batch_size` | integer 10–500 | 100 | Records per job step. |
| `lookup_limit` | integer 5–50 | 20 | Results per reference search (REST lookup). |
| `select_limit` | integer 20–500 | 200 | Options listed in classic reference selects. |
| `rest_filter_unreadable_refs` | boolean | true | Strip post references the viewer cannot read from non-edit REST responses. |
| `show_retained_notice` | boolean | true | Explain disabled definitions that still hold content. |

## Content (native entities)

- Posts of Cradle post types: `wp_posts.post_type = <key>`; terms: `wp_term_taxonomy.taxonomy = <key>`.
- Field values: native metadata with the protected prefix **`_cradle_cf_<field key>`** (hidden from the legacy
  Custom Fields box; editable through REST only via the registered `auth_callback`).
  - Single-value fields: one meta row. Booleans store `1`/empty, numbers as numeric strings.
  - `multiselect` and multiple references: **one meta row per value** (`single => false`), so relationships are
    queryable with `meta_query` (`'key' => '_cradle_cf_related', 'value' => $id`).
  - Repeated groups: one row holding a serialized list of objects (written by WordPress' own meta API after validation;
    never unserialized from untrusted input by this plugin).
- Menu items keep referencing content through core's `_menu_item_object`, updated by key migrations.

## Definition schema (export/import format)

```json
{
  "format": "cradle-content-definitions",
  "formatVersion": 1,
  "schemaVersion": 1,
  "generator": "cradle-content 0.1.0",
  "exported": "2026-09-24T10:00:00+00:00",
  "definitions": {
    "post_type":   { "book":  { "key": "book", "singular": "Book", "plural": "Books", "rewrite_slug": "books", "…": "…" } },
    "taxonomy":    { "genre": { "key": "genre", "singular": "Genre", "plural": "Genres", "hierarchical": true, "…": "…" } },
    "field_group": { "book_details": { "key": "book_details", "title": "Book details", "object": "post", "targets": ["book"], "fields": [ { "key": "isbn", "label": "ISBN", "type": "text", "exposure": "public", "max_length": 20 } ] } }
  }
}
```

Every property is optional except `key` and labels/title; omitted properties take the defaults in
`Validator::defaults()`. Unknown properties are dropped. Import limit: 1 MB, 150 definitions, JSON depth 16.

## Ownership inventory (`dataOwners`)

`options` (the four module options), `postMeta`/`termMeta` `_cradle_cf_*`, `cron` `cradle_content_run_job`, and
`postTypes`/`taxonomies` listing database-defined keys so the core inventory can count their content.
Core jobs rows of type `content/migrate` live in core's `cradle_jobs` table (module `content`).
