# Cradle Content — lifecycle

| Event | Behavior |
| --- | --- |
| Activation | Refuses network activation (core `guard_multisite`). Grants `cradle_manage_content_types` to administrators once (recorded in `cradle_granted_capabilities`, so a later manual removal is respected). Creates an empty definitions option if missing. Deletes the rewrite signature so the next request flushes once. Creates no content. |
| Every request | Registers enabled, authoritative definitions at `init` priority 0 (module boot); attaches taxonomies registered later by other code at `init` 99; flushes rewrite rules at `wp_loaded` only if the registered signature changed. |
| Deactivation | Unschedules `cradle_content_run_job`, releases `content:*` locks, unregisters its types and flushes rewrite rules so the URLs disappear. Definitions, posts, terms and field values stay. |
| Core missing | No fatal; admin notice for `activate_plugins` users; nothing is registered; all data stays. |
| Theme switch | Nothing changes: types are registered by this plugin, templates come from whatever theme is active (`single`, `archive`, `index` fall back for custom types). |
| Disable a definition | Not registered any more; content untouched; admin explains how to restore access. |
| Delete a definition | Preview with counts and explicit confirmation. Content kept by default; post types may instead be converted to another type (job). Field values kept by default or deleted (job). |
| Rename a key | Preview (records by status, menu items, references, URL impact) → definition renamed (references in database definitions updated) → `content/migrate` job moves posts/terms and menu items in batches. |
| Plugin update | Definitions carry `schema: 1`; future schema changes must add an idempotent migration in `Definitions::store()` and keep old data readable. |
| Uninstall (retain — default) | Nothing is removed. Definitions stay in the database and are picked up again after reinstalling. |
| Uninstall (purge) | Only when the “When deleted” column of Cradle → Overview is set to “Remove its data” for `content`: deletes the module options, all `_cradle_cf_*` post/term meta, the module capability and recorded custom capabilities, scheduled events and `cradle_content_*` transients, and resets rewrite rules. **Posts and terms are never deleted** — they are site content; convert or delete them in WordPress first if unwanted. |

## Migration jobs

Job type `content/migrate` (core Jobs, capability `cradle_manage_content_types`):

- Created by the rename, delete-with-conversion, field-deletion and field-rename handlers; the first bounded run (8 s budget)
  happens in the same request, the rest continues on Cradle → Content types → Migrations (browser runner from core
  `cradle-admin`) and through a WP-Cron fallback (`cradle_content_run_job`, every minute until terminal).
- Each step handles `migration_batch_size` records and re-queries what still carries the old value, so a repeated step
  after an interruption is harmless. Leases and fencing come from core.
- Concurrent migrations touching the same keys are refused.

## Recovery

- Posts of a deleted or renamed-away type: Cradle → Content types → Post types lists "Stored posts without a registered type"
  with a link to create a definition for that key (or import an export).
- Disabled definitions: enable them again.
- Accidental label/setting change: every change is recorded in the activity log (`$core->activity()->recent()`, kept 90 days); export regularly to keep a restorable copy.
