# Cradle Duplicate — data model and lifecycle

## Owned data (`dataOwners`)

| Kind | Key | Notes |
| --- | --- | --- |
| Option | `cradle_duplicate_settings` | Settings section `duplicate` (`_revision`, `_schema` included). Autoloaded. |
| Option | `cradle_duplicate_installed_version` | Written on activation. |
| Post meta | `_cradle_duplicate_of` | Provenance on drafts created by this plugin (source post ID). Registered protected, `show_in_rest: false`, `auth_callback` false. |
| Capability | `cradle_manage_duplicate` | Administrators only, granted once (core grant policy). |
| Locks | `duplicate:{post}:{user}` | 30 s, only while one duplication runs; released immediately. |
| Activity | `duplicate / post_duplicated` | Source ID, copy ID, copied/skipped meta counts, synced-pattern count. |

Drafts created by duplication are ordinary user content. They are **not** owned by this plugin and
are never deleted by it.

## Lifecycle

| Event | Behavior |
| --- | --- |
| Activation | Refuses network activation on multisite. Grants `cradle_manage_duplicate` to administrators once and records the grant in `cradle_granted_capabilities`. Never creates content. |
| Boot (core ready) | Registers the settings section, provenance meta, REST route and admin integration. |
| Core missing | Admin notice for `activate_plugins` users; no duplication UI; no data changes. |
| Deactivation | Releases `duplicate:*` locks. Keeps settings, provenance meta and drafts. |
| Uninstall, policy `retain` (default) | Nothing is removed. |
| Uninstall, policy `purge` (the “When deleted” column of Cradle → Overview) | Deletes both options, deletes `_cradle_duplicate_of` meta (the posts stay), removes the capability from all roles and from the grant record, and removes the module's policy entry. |

Schema version 1; no migrations yet.
