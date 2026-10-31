# Cradle Forms — data model

All data is owned by module `forms` (see the descriptor's `dataOwners`). Times are UTC (`Y-m-d H:i:s`).

## Post type and meta

| Key | Where | Shape |
| --- | --- | --- |
| `cradle_form` | post type | Title = form title; `post_content` = field blocks + static blocks; status `publish` makes it usable. |
| `_cradle_form_schema` | post meta (string) | JSON `{ "schema_version": 1, "source_hash": sha256(post_content), "updated": ISO-8601, "fields": [field, …] }`. Written only from a valid derivation. |
| `_cradle_form_schema_errors` | post meta (array) | Problems of the last save; absent when the last save was valid. |
| `_cradle_form_settings` | post meta (object, REST-editable by form managers) | See contract.md "Form settings"; sanitized on every write (`Form_Settings::sanitize()`). |

Schema field (common keys): `name`, `type`, `label` (consent: limited HTML), `help`, `placeholder`,
`required`, `width`, `default`. Type keys: `max_length`, `autocomplete` (text); `max_length`, `rows`
(textarea); `submitter_email` (email); `min`, `max`, `step` (number; null when unset); `min`, `max` (date,
`Y-m-d` or ''); `choices` (list of `{value, label}`), `min_choices`, `max_choices` (choice types);
`label_text` (consent, plain); `accept` (extensions), `max_bytes` (requested size; the effective limit is
also capped by the site setting and the server), `max_files` (file); `from_query` (hidden).

## Tables

`{prefix}cradle_form_entries`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned PK | |
| `form_id` | bigint unsigned | The rendered form post. With Cradle Languages each language's form is its own post (linked by Cradle Languages' `_cradle_lang_group` meta and `cradle_language` term, which that module owns), so entries are per language form. |
| `form_title` | varchar(255) | Snapshot at submission. |
| `language` | varchar(20) | Cradle Languages slug, else the WordPress locale of the page. |
| `locale` | varchar(20) | WordPress locale of the page. |
| `status` | varchar(10) | `new`, `read`, `spam`. |
| `created_at` | datetime | UTC. |
| `ip_hash` | char(64) | HMAC-SHA256 (key from `wp_salt()`) of the client network: IPv4 address or IPv6 /64; never the raw IP. |
| `ua_hash` | char(64) | HMAC of the user agent, or ''. |
| `submitter_email` | varchar(191) | Lowercased value of the field marked "submitter's own email", or ''. Privacy tools use only this. |
| `source_id` | bigint unsigned | The singular post the form was sent from (with Cradle Languages: the translation actually rendered), 0 when rendered elsewhere (archives, search, PHP render without a singular query) or from a page cached before schema 2. Taken from the signed render token. |
| `source_title` | varchar(255) | Title snapshot of that post at submission time (kept when the post is renamed or deleted). |
| `consent` | longtext NULL | JSON list of consent records (`field`, `text`, `plain`, `hash`, `language`, `locale`, `given_at`). |

Indexes: `(form_id, status)`, `(form_id, created_at)`, `(form_id, source_id)`, `submitter_email`, `created_at`.

`{prefix}cradle_form_values` — `id`, `entry_id`, `field_name` varchar(41), `label` (snapshot), `type`,
`value` longtext (scalars as text; checkboxes and file names as a JSON list; consent `1`). Index `entry_id`,
`(entry_id, field_name)`.

`{prefix}cradle_form_files` — `id`, `entry_id`, `field_name`, `stored_name` char(32) unique (random hex,
no extension), `original_name` (display data only), `mime` (from the validated extension), `size`,
`metadata_stripped` (1 when JPEG/PNG was re-encoded), `created_at`.

Schema version: `Schema::VERSION` = 2, stored in `cradle_forms_db_version`; `dbDelta` on activation and on
admin/CLI requests when older (front-end requests never upgrade). Version 2 added `entries.source_id`,
`entries.source_title` and the `(form_id, source_id)` index; until an admin/CLI request has run the
update, new entries are stored without those columns (`Schema::has_source()`).

## Files

`<core private root>/forms/uploads/<stored_name>` via `cradle()->storage()->module_dir( 'forms', 'uploads' )`
(outside the web root with `CRADLE_PRIVATE_DIR`, otherwise an uploads subfolder with deny rules proven by
core's HTTP probe). Deleted together with their entry (admin delete, retention, privacy eraser, purge).

## Options, transients, cron

| Key | Kind | Notes |
| --- | --- | --- |
| `cradle_forms_settings` | option (settings section `forms`) | `allowed_extensions`, `max_upload_mb`, `fallback_recipient` (+ `_revision`, `_schema`). |
| `cradle_forms_installed_version`, `cradle_forms_db_version` | options | Lifecycle markers. |
| `cradle_forms_rl_<hash>` | transient (1 h) | Rate-limit counter per form, client network and hour (key is an HMAC). |
| `cradle_forms_r_<token>` | transient (10 min) | One-time no-JavaScript result: status, field/form errors, submitted values (no files), confirmation. Deleted when shown. |
| `cradle_forms_retention` | daily cron | Deletes entries older than each form's `retentionDays` (≤ 5000 per run). |

## Lifecycle of an entry

submitted → stored with status `new` (always when the form has consent or file fields) → read/spam in the
admin → deleted by an administrator, the retention cron, the privacy eraser, or the purge on uninstall.
Exports never change entries.
