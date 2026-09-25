# Cradle Dummy Content — data model and lifecycle

## Owned data (`dataOwners`)

| Kind | Key | Notes |
| --- | --- | --- |
| Option | `cradle_dummy_content_settings` | Section `dummy-content`: `max_items_per_batch` (1–500, 50), `items_per_step` (1–25, 5), `default_status` (draft), `max_generated_terms` (0–20, 10), `max_placeholder_images` (0–20, 5), `keep_reports` (1–50, 20). Not autoloaded. |
| Option | `cradle_dummy_content_installed_version` | Written on activation. |
| Post type | `cradle_dummy_batch` | One private record per batch: `post_title` = batch name, `post_name` = batch UUID, `post_author` = creator. Not public, no UI, not in REST, not exportable; primitive caps mapped to the module capabilities (`create_posts` = do_not_allow). |
| Post meta on records | `_cradle_dummy_config` (validated config JSON incl. seed), `_cradle_dummy_created` (owned term `{id,taxonomy}` and attachment IDs), `_cradle_dummy_referenced` (selected pre-existing media/terms), `_cradle_dummy_reports` (JSON list, newest `keep_reports`), `_cradle_dummy_job` (latest generation job), `_cradle_dummy_state`, `_cradle_dummy_note` | JSON strings, never PHP-serialized structures. |
| Post meta on generated posts/attachments | `_cradle_generated`, `_cradle_generation_batch`, `_cradle_generation_version`, `_cradle_generated_at`, `_cradle_generated_by`, `_cradle_generation_index`, `_cradle_generation_hash` | Registered for all types: protected (underscore), `show_in_rest: false`, `auth_callback` false. |
| Term meta on generated terms | same keys except the hash | Registered likewise. |
| Capabilities | `cradle_generate_dummy`, `cradle_cleanup_dummy` | Administrators only, granted once. |
| Jobs (core table) | types `dummy-content/generate`, `dummy-content/cleanup` | Payload holds only the batch UUID and cleanup options. |
| Locks | `dummy-content:cleanup-start:{uuid}` | Prevents two cleanup jobs starting at once. |
| Activity | `batch_created`, `batch_generated`, `cleanup_started`, `cleanup_completed`, `job_cancelled`, `batch_forgotten` | IDs, names, counts; no content. |

Generated posts, terms and images are site content owned *by a batch*, not configuration of the
plugin; they are only removed through the previewed cleanup.

## Lifecycle

| Event | Behavior |
| --- | --- |
| Activation | Refuses network activation; grants both capabilities to administrators once (recorded in `cradle_granted_capabilities`); never generates content. |
| Boot | Registers settings, the batch record type, marker meta, both job types and the admin screen. |
| Core missing | Admin notice for `activate_plugins` users; nothing runs; no data changes. |
| Deactivation | Releases `dummy-content:*` locks. Keeps records, markers and content (jobs whose type is unregistered simply cannot run until reactivation). |
| Uninstall, policy `retain` (default) | Nothing is removed. |
| Uninstall, policy `purge` | Deletes batch records (and reports), both options and both capabilities (roles + grant record) and the policy entry. **Generated content and its markers are deliberately kept** — run cleanup first if the content should go; the markers let a reinstall find it again. |

Schema version 1; `_cradle_generation_version` = 1 identifies the marker format.
