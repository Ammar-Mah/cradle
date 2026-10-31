# Cradle Languages — data model

All data is native WordPress data; nothing is stored in files or custom tables.

## Options

| Option | Autoload | Contents | Written by |
| --- | --- | --- | --- |
| `cradle_languages_settings` | yes | Core settings section `languages`: `languages` (≤ 10 rows `{slug, locale, name, native, dir, default}`), `hide_default_prefix`, `post_types` `{type: mode}`, `taxonomies` `{taxonomy: mode}`, `menu_locations` `{lang: {location: menu_id}}`, `hreflang`, plus core `_revision` / `_schema`. Read raw (and re-validated) at `cradle/ready` because routing starts before `init`. | Settings form (options.php), core import/reset, the Polylang import job. |
| `cradle_languages_installed_version` | no | Plugin version of the last update routine. | Activation / first admin request after an update. |
| `_transient_cradle_languages_polylang_<user>` | no | That user's last Polylang dry-run report (30 minutes). | Dry run action. |

Not stored: the derived default language (the site language while no languages are saved).

## Taxonomy `cradle_language`

Hidden (no UI, no REST, no query var, no rewrite). One term per configured language, slug = language
prefix, name = language name, created on settings save or on first use. Registered for every translatable
post type (`register_taxonomy_for_object_type` on `init` 99). An entry has at most one term
(`wp_set_object_terms( …, false )`). Untagged entries of translatable types count as the default
language. Terms of removed languages are kept (their entries are hidden on the frontend).

## Post meta

| Key | Value | Notes |
| --- | --- | --- |
| `_cradle_lang_group` | UUID v4 | Shared by all translations of an entry (one per language). Protected (underscore), not in REST. Groups imported from Polylang get a deterministic UUID derived from the Polylang group's ID. |

## Term meta

| Key | Value |
| --- | --- |
| `_cradle_lang` | Language slug of a term of a translatable taxonomy (missing = default). |
| `_cradle_lang_group` | UUID v4 shared by the term's translations. |

## Jobs (core `cradle_jobs` table, module `languages`)

| Type | Phases | Cursor |
| --- | --- | --- |
| `languages/assign-default` | `posts` → `terms` → `done` | last ID |
| `languages/polylang-import` | `settings` → `posts` → `groups` → `terms` → `term_groups` → `done` | last object / term_taxonomy ID; counts in `payload.counts` |

Capability `cradle_manage_languages`; steps are idempotent (re-running a batch sets the same values).

## Locks

`languages:translate:<source ID>` (30 s) while a translation copy is created; released on deactivation.

## Ownership (`dataOwners`)

`options` (`cradle_languages_settings`, `cradle_languages_installed_version`), `transients`
(`cradle_languages_*`), `taxonomies` (`cradle_language`), `postMeta` (`_cradle_lang_*`), `termMeta`
(`_cradle_lang*`), `blocks` (`cradle/language-switcher`, `cradle/language-section`). Job rows live in
core's jobs table (module `languages`).

## Lifecycle

| Event | Effect |
| --- | --- |
| Activation | Multisite network activation refused; capability granted once to administrators (recorded); version option. No settings row, no content, no rewrite rules. |
| Update | `Lifecycle::maybe_update()` on admin/CLI requests, versioned and idempotent (0.1.0: nothing to migrate). |
| Deactivation | Releases `languages:*` locks. Keeps every setting, term, meta and block. All URLs are the plain WordPress URLs again; `/xx/` URLs get WordPress' normal handling; saved switcher blocks render nothing, language sections show their content in every language. |
| Uninstall (retain, default) | Nothing removed. |
| Uninstall (purge: `cradle_uninstall_policy['languages'] === 'purge'`) | Deletes both options, `cradle_languages_*` transients, every `cradle_language` term with its relationships, `_cradle_lang_*` post meta, `_cradle_lang*` term meta and the capability (and its grant record). Never deletes entries, terms of other taxonomies or saved blocks. |

## Export / migration

Cradle Migration exports the database, so languages, assignments and groups move with the site. Settings
export/import (core) carries the language list, modes, menu assignments (menu IDs are site-specific) and
hreflang choice.
