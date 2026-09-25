# Cradle Duplicate — package instructions

Feature plugin for northstar §13 (acceptance CR-16). Module id `duplicate`, text domain
`cradle-duplicate`, requires the Cradle core plugin (API `^1.0`). Read the repository
`AGENTS.md`, `docs/architecture/core-api.md` and `docs/architecture/plugin-conventions.md`
before changing this package. The feature contract is in `docs/feature-contract.md`;
data, lifecycle and security notes are in `docs/data-and-lifecycle.md` and `docs/security.md`.

## Scope

- Duplicate one post/page (or another enabled type) into a new **draft** owned by the author policy.
- Entry points: list row action, classic editor Publish box link, block editor summary panel
  button + Options menu item, and a read-only confirmation screen for the no-JavaScript path.
- Nothing else: no bulk cloning, no attachment cloning, no WooCommerce product/order support.

## Layout

| Path | Purpose |
| --- | --- |
| `cradle-duplicate.php` | Bootstrap: header, autoloader, lifecycle hooks, descriptor, core-missing guard. |
| `src/class-module.php` | Descriptor + `boot()` (settings section, provenance meta, REST, admin). |
| `src/class-policy.php` | Supported types, object-level authorization, metadata rules. |
| `src/class-duplicator.php` | The copy itself (`duplicate()`, `preview()`), author policy, slug, terms, meta. |
| `src/class-rest.php` | `POST /cradle-duplicate/v1/posts/{id}` with a deliberate `permission_callback`. |
| `src/class-config.php` | Settings section `duplicate` (`cradle_duplicate_settings`). |
| `src/class-lifecycle.php` | Activation (multisite guard, capability grant) and deactivation (lock release). |
| `admin/class-admin.php` | Row actions, confirmation screen, admin-post handler, editor assets, notices. |
| `assets/js/duplicate-list.js` | One-click enhancement for list rows / classic editor (wp.apiFetch). |
| `assets/js/duplicate-editor.js` | Block editor plugin (wp.plugins + wp.editor slots, no JSX). |
| `uninstall.php` | Retain by default; purge only owned config/provenance when policy says so. |

## Rules for changes

- Every duplication path must call `Policy::check()` (read + edit source, create target type) as the
  acting user. Never authorize from menu visibility, a nonce, or a button state.
- Mutations are POST only: REST (REST nonce) or admin-post (`cradle_duplicate_post_{ID}` nonce).
  GET must never duplicate; `admin-post.php?action=cradle_duplicate` via GET only redirects to the
  confirmation screen.
- Keep `Policy::FIXED_DENY_*` non-filterable. `_cradle_generat*` (Cradle Dummy Content ownership),
  `_cradle_source_key` (site provisioning identity),
  locks, revision/trash bookkeeping and attachment internals must never be copied, whatever the filters say.
- Never add `product`/`shop_order` support by filter. Product duplication belongs to WooCommerce.
- Do not clone attachments; reuse references. Do not change the source item.
- Keep block content byte-identical (it still passes through `wp_insert_post()` content filters, so users
  without `unfiltered_html` get the same kses treatment as if they had saved it themselves).

## Public hooks

`cradle_duplicate/post_types`, `cradle_duplicate/can_duplicate` (can only deny),
`cradle_duplicate/meta_allowlist`, `cradle_duplicate/meta_denylist`, `cradle_duplicate/taxonomies`,
`cradle_duplicate/title`, `cradle_duplicate/author`, `cradle_duplicate/post_data`,
action `cradle_duplicate/duplicated`. Documented in `docs/feature-contract.md`.

## Verification

`php tests/run.php --suite=duplicate` (tests/integration/duplicate.php) covers the acceptance
criteria, allowed and denied roles, GET/nonce refusal and product exclusion. Fixtures are removed
at shutdown; run it after `php scripts/sync.php cradle-duplicate`.
