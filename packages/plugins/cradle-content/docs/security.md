# Cradle Content — security notes (northstar §20)

## Trust boundaries

| Input | Treatment |
| --- | --- |
| Admin definition forms | POST to `admin-post.php`, per-action nonce (`cradle_content_<action>_<kind>`), `cradle_manage_content_types`, GET refused (405). Validated by `Validator` (types, lengths, allowlists) before normalization. |
| Imported JSON | ≤1 MB, UTF-8, depth 16, ≤150 definitions; same validator; preview stored per user in a transient with a random token compared with `hash_equals`; nothing written until "Import" is confirmed. |
| Code definitions (`cradle_content/code_definitions` filter from another plugin) | Trusted PHP, but still validated structurally; invalid ones are reported and not registered. |
| REST meta writes | Core route permissions + meta `auth_callback` (`edit_post`/`edit_term` on the object) + full value validation before the controller runs. |
| Classic meta box / term forms | Nonce per object + `edit_post`/`edit_term`; per-field validation. |
| Reference lookup REST | `permission_callback`: logged in and edit capability of the subtype; results filtered by `read_post`, `list_users`, `assign_terms`. |
| Stored values rendered | Admin columns, bindings and forms escape by context (`esc_html`, `esc_attr`, `esc_url`, `esc_textarea`, `wp_kses` allowlist for rich text). |

## Authorization summary

- Only administrators receive `cradle_manage_content_types` by default. Editors, authors and subscribers get 403 on every
  definition handler even with a valid nonce of their own (tested).
- Custom capability models grant caps to administrators and only explicitly ticked editorial roles; grants are recorded
  and revoked precisely.
- Posts of Cradle types use `map_meta_cap`, so object-level checks (`edit_post`, `read_post`, …) always apply.
- Migration jobs require the module capability; job AJAX endpoints enforce core's owner/capability object check.

## Data handling

- SQL: prepared statements; `IN()` lists use placeholders only after keys pass a strict pattern; table names come from `$wpdb`.
- No `eval`, no `unserialize` of request data, no remote requests, no file writes (export is streamed; import reads the
  uploaded temp file only after `is_uploaded_file` and size checks).
- Public REST output never includes "editor"/"none" fields and strips references to posts the viewer cannot read.
- Activity records (core, redacted) for creates, updates, enable/disable, deletes, renames, forks, exports, imports and migrations.

## Not provided

No rate limiting, no per-field role restrictions beyond object capabilities, no encryption of field values.
