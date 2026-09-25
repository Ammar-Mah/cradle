# Cradle Translations — data model and lifecycle

## Files (Cradle private storage: `cradle()->storage()->module_dir( 'translations', … )`)

Outside updateable package code. With `CRADLE_PRIVATE_DIR` it lives outside the web root;
otherwise under `uploads/cradle-private-<random>/translations/` with core's deny rules
(`.htaccess`, `web.config`, guard `index.php`), verified by core's HTTP probe.

| Path | Content | Written by |
| --- | --- | --- |
| `catalogs/<locale>.json` | Authoritative catalog (pretty JSON). | `Store::mutate()` |
| `history/<locale>.r<N>.json` | Snapshot of revision N, taken before it was replaced. Bounded by `history_limit`. | `Store::mutate()` |
| `runtime/<locale>.json` | Derived lookup map; exists only while the locale has published overrides. | `Store::publish_runtime()` |
| `manifest/manifest.json` | Extracted source manifest of Cradle packages + registered labels. | `Service::refresh_sources()` |

Writes: temp file `.<name>.<random>.tmp` in the same directory → `rename()` (retried briefly on
Windows). Every catalog write holds the core lock `translations:catalog:<locale>` (30 s TTL,
fencing checked before the rename). Only `.json` files are written.

## Catalog schema (schemaVersion 1)

```json
{
  "schemaVersion": 1,
  "locale": "de_DE",
  "revision": 12,
  "publishedRevision": 11,
  "updated": "2026-09-24T10:00:00+00:00",
  "pluralRule": "one_other",
  "domains": {
    "cradle": [
      {
        "id": "e1a2b3c4d5e6f7a8b",
        "msgid": "%d instance",
        "context": null,
        "msgidPlural": "%d instances",
        "translations": ["%d Instanz", "%d Instanzen"],
        "draft": null,
        "draftFuzzy": null,
        "status": "translated",
        "fuzzy": false,
        "obsolete": false,
        "source": "extracted",
        "references": ["plugins/cradle/admin/class-admin.php:320"],
        "comments": "translators: %d: count",
        "js": false,
        "changedRevision": 9
      }
    ]
  },
  "log": [ { "revision": 12, "time": "…", "user": 1, "action": "publish", "summary": "Published 1 draft." } ]
}
```

- Identity = text domain + `msgid` + `context` + `msgidPlural`. `id` = `e` + 16 hex of its MD5
  (UI key only; recomputed on read/import). Empty context is normalized to `null`.
- `translations`: published forms (1 for singular, `nplurals` for plural, `[]` = none).
- `draft`: `null` = no pending change, `[]` = pending removal, list = pending forms; `draftFuzzy`
  carries the pending "needs review" flag.
- `status`: `obsolete` > `missing` (incomplete) > `fuzzy` > `translated` (recomputed on write).
- `source`: `extracted` (Cradle code), `registered` (label registry), `imported` (.po/.pot or JSON),
  `manual` (added in the editor). Refresh obsoletes only `extracted`/`registered`; .po import with
  "mark obsolete" only `imported` entries of that domain; `manual` entries are never auto-obsoleted.
- `changedRevision`: revision of the entry's last change, used for per-entry stale checks.
- `log`: last 100 writes (not exported).

Bounds: 20 000 entries, 200 domains, 10 000 characters per string, 6 plural forms, 30 references
(300 chars, relative, no `..`), 2 000-character comments, JSON depth 8, 24 MiB stored, 8 MiB
import ceiling (setting `max_import_kb`).

## Runtime file

```json
{ "schemaVersion": 1, "locale": "de_DE", "revision": 11, "rule": "one_other",
  "header": "nplurals=2; plural=(n != 1);", "hash": "…",
  "domains": { "cradle": { "s": { "ctx\u0004msgid": "…" }, "p": { "msgid\u0000plural": ["…","…"] }, "j": { "msgid": ["…"] } } } }
```

Built from published, complete, non-fuzzy entries that re-pass validation. `s` singular map, `p`
plural map, `j` Jed entries for strings referenced from JavaScript.

## Options

| Option | Autoload | Content |
| --- | --- | --- |
| `cradle_translations_settings` | yes | Settings section values + `_revision`, `_schema` (core Settings API). |
| `cradle_translations_index` | yes | `locales[<locale>] = {hash, revision, domains{domain: count}, js{domain: count}}`, `domains` union. Small. |
| `cradle_translations_state` | no | Last refresh time and string count. |
| `cradle_translations_version` | no | Installed version. |

Transients `cradle_translations_notice_<user id>` (120 s) carry admin notices. Cron hook:
`cradle_translations_refresh` (single event after Cradle package updates). Object cache group
`cradle_translations`, key `map:<locale>` (validated against the index hash).

## Lifecycle

| Event | Behavior |
| --- | --- |
| Activation | Refuses network activation. Grants `cradle_manage_translations` to administrators once (recorded in `cradle_granted_capabilities`; a later removal is respected). Creates no catalogs. |
| Deactivation | Clears the refresh cron, releases `translations:*` locks. Catalogs, history, settings kept. Overrides stop (no filters). |
| Package update | Catalogs untouched (stored outside package code). Source refresh keeps translations and marks vanished strings obsolete. |
| Core missing | Admin notice for `activate_plugins` users; no filters, no data changes; WordPress shows shipped translations. |
| Uninstall (default) | Nothing removed. |
| Uninstall with policy `purge` | Deletes the files listed above (only names this package writes, plus the core guard files in its own directories), the four options, notice transients, the cron event, the capability from every role and its `cradle_granted_capabilities` record, and the policy entry. Core storage root and other modules are untouched. |

Module handlers: `export()` returns `{format: "cradle-translations", formatVersion: 1, catalogs}`;
`import()` validates every catalog first, then replaces each as a new revision; `cleanup()`
reports locales/snapshot counts and deletes listed locales when `dryRun` is false.
