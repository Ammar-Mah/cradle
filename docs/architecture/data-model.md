# Core data model

## Custom tables (created on activation and on admin/CLI schema check; `cradle_core_schema` = 1)

| Table | Columns (summary) | Indexes | Retention | Owner |
| --- | --- | --- | --- | --- |
| `{prefix}cradle_jobs` | id, uuid, type, module, owner_id, required_cap, state, phase, cursor_data, completed_units, progress_done/total, bytes_done/total, lease_owner, lease_expires, fencing, retries, max_retries, error_code, error_message (redacted), payload, manifest_digest, created_at, updated_at | uuid (unique), type+state, module+state, owner_id | Modules clean their finished jobs; purge on core uninstall with purge policy | core |
| `{prefix}cradle_locks` | name (PK), owner, fencing, expires_at | PK | Rows removed on release; expired rows are reclaimed | core |
| `{prefix}cradle_activity` | id, created_at, user_id, module, action, message (redacted, ≤500), context (redacted JSON, ≤4 KB) | created_at, module+action | Daily prune: older than 90 days and beyond the newest 5000 rows (developer filters `cradle/activity_retention_days`, `cradle/activity_max_rows`; there is no settings screen) | core |

Migration steps: additive `dbDelta` only; bump `Schema::VERSION`; never drop columns during a normal request.

## Options owned by core

| Option | Autoload | Export | Uninstall |
| --- | --- | --- | --- |
| `cradle_core_schema` | no | no | purge |
| `cradle_granted_capabilities` | no | no | purge (core entries) |
| `cradle_private_dir_suffix` | no | no | kept while other modules retain private data |
| `cradle_storage_probe` | no | no | purge |
| `cradle_uninstall_policy` | no | no | kept (other modules read it) |
| `cradle_installed_version` | no | no | purge |

Module-owned data is declared in each module descriptor's `dataOwners` (counted by `$core->inventory()->scan( $module )`); what happens to it on deletion is chosen in the “When deleted” column of Cradle → Overview.
