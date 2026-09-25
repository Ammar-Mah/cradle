# Cradle Admin — data model and lifecycle

## Owned data (`dataOwners`)

| Data | Type | Autoload | Notes |
| --- | --- | --- | --- |
| `cradle_admin_branding_settings` | option (core section `admin-branding`, schema 1) | yes | Declared fields + `_revision` + `_schema`. Absent until first save (reading never writes). |
| `cradle_admin_visual_settings` | option (core section `admin-visual`, schema 1) | yes | Same. |
| `cradle_admin_labels_settings` | option (core section `admin-labels`, schema 1) | yes | Same; bounded lists. |
| `cradle_admin_custom_css` | option | no | `{ css, revision, modified, author, signature }`; css ≤ 64 KB; signature = HMAC-SHA256 (auth salt) of policy version + CSS. |
| `cradle_admin_custom_css_revisions` | option | no | Newest-first list of ≤ 10 `{ revision, css, saved, author }`. |
| `cradle_admin_version` | option | no | Installed version, for future migrations. |
| `cradle_admin_native_appearance` | user meta | — | `'1'` when the user chose the default WordPress appearance. |
| `cradle_admin_css_draft_{user_id}` | transient (10 min) | — | Rejected/stale raw CSS draft + problems, shown once to its author. |
| `cradle_manage_branding` | capability | — | Granted to administrators once; recorded in core's `cradle_granted_capabilities`. |

No posts, terms, tables, files, uploads or cron events are created.

## Lifecycle

| Event | Behavior |
| --- | --- |
| Activation | Refuses network activation (multisite is a separate milestone). Grants `cradle_manage_branding` to administrators unless a grant was already recorded (so an administrator's later removal is respected). Adds the two CSS options with autoload off. Enables nothing. Idempotent. |
| Core missing | Plugin stays active; an admin notice for `activate_plugins` users explains that features are paused; the native admin is shown; no data changes. |
| Module not ready | Never booted; nothing applied; data untouched. |
| Deactivation | Keeps all data; nothing to unschedule, release or flush. |
| Safe mode | Settings stay editable and stored; nothing is applied. |
| Reset | Per section (core handler) deletes the section option → defaults. Custom CSS reset clears active CSS and keeps history. |
| Uninstall (default) | Retain everything. |
| Uninstall with `cradle_uninstall_policy['admin'] = 'purge'` | Deletes the options above, the user meta, draft transients, revokes `cradle_manage_branding` from every role, removes it from `cradle_granted_capabilities`, and clears the `admin` policy entry. Nothing outside `dataOwners` is touched. |

## Export / import

Core section export/import (JSON, per section) is available on each tab. The module `export` handler returns
all three sections plus the active custom CSS; `import` validates everything again (see `contract.md`).
