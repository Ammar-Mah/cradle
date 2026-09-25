# Cradle Content — package instructions

Scope: `packages/plugins/cradle-content/` (module id `content`, text domain `cradle-content`,
capability `cradle_manage_content_types`). Implements northstar §12 (post types, taxonomies,
fields, definition lifecycle). Read the repository `AGENTS.md`, `docs/architecture/core-api.md`
and this package's `docs/` before changing anything here.

## What lives where

| Path | Responsibility |
| --- | --- |
| `cradle-content.php` | Bootstrap only: headers, constants, autoloader, lifecycle hooks, module registration, core-missing guard. |
| `src/class-module.php` | Descriptor (`dataOwners`, capability, handlers), `boot()`, module export/import/cleanup. |
| `src/class-definitions.php` | Definition store (option `cradle_content_definitions`), code definitions filter, ownership, lock + revisions, save/enable/delete/rename/fork. |
| `src/class-validator.php` | The only place keys, slugs, reserved names and definition settings are validated. |
| `src/class-field-types.php` | Bounded field types: definition rules, value validation, REST schema, display. |
| `src/class-registry.php` | Per-request registration of post types/taxonomies, conflicts, rewrite flush, ordering, admin columns. |
| `src/class-meta.php` | register_post_meta/register_term_meta, REST pre-validation and output filtering, refusal of invalid low-level writes. |
| `src/class-migrations.php` | Affected-record counts and the `content/migrate` batch job (core Jobs). |
| `src/class-transfer.php` | JSON export and previewed, non-destructive import. |
| `src/class-rest.php` | `GET /cradle-content/v1/lookup` for reference pickers. |
| `src/class-bindings.php` | Block Bindings source `cradle-content/field`. |
| `admin/` | Cradle → Content types screens, admin-post handlers, classic meta boxes/term forms, block editor assets. |
| `assets/js/editor.js` | Block editor panels (wp.plugins + wp.editor + wp.data, no JSX, no build). |
| `uninstall.php` | Retain by default; purge only with `cradle_uninstall_policy['content'] === 'purge'`. |

## Supported extension points

- Filter `cradle_content/code_definitions` — code-managed definitions (see `docs/code-definitions.md`).
- Block binding source `cradle-content/field` with args `{ "key": "<field key>" }`.
- REST: registered meta `_cradle_cf_<field>` on targeted post types/taxonomies; `cradle-content/v1/lookup`.
- Job type `content/migrate` (created only by this module's handlers).

## Rules for changes

- Every definition input (form, import, code) goes through `Validator`; never add a second validation path.
- Keys have one owner: code definitions win; a database copy with the same key is "shadowed" and never registered.
- A key change is never a normal save: use `Definitions::rename()` + a migration job with a preview.
- Disabling or deleting a definition must never delete posts, terms or field values. Content removal is
  only (a) conversion to another post type, (b) explicit field-value deletion after a preview, (c) uninstall purge of field values.
- Never add PHP, SQL, callbacks, expressions or raw HTML templates as field or definition settings.
- Mutations: admin-post POST + per-action nonce + `cradle_manage_content_types`; REST writes rely on core
  object capabilities plus the meta `auth_callback`. GET screens only read.
- Registration must stay cheap: no queries on ordinary requests besides WordPress's own (see docs/data-model.md).
- Do not register or overwrite keys owned by WordPress, WooCommerce or any other code.

## Risks to keep in mind

- Renaming keys changes URLs and REST bases unless "keep URLs" pins them; code definitions referencing a renamed key are not updated automatically.
- Converting posts to an existing type can create duplicate slugs in the target type (preview warns).
- Changing a field between single and multiple values changes storage shape; existing values are not converted.
- Renaming a sub-field of a repeated group is not migrated (values stay under the old sub-key until the row is re-saved).

## Verification

`php tests/run.php --suite=content` (tests/integration/content.php) exercises allowed and denied roles,
REST fields, front-end URLs, disable/enable, export/import, rename migrations, code definitions and uninstall.
