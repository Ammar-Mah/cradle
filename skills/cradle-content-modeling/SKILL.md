---
name: cradle-content-modeling
description: Use when a site or feature needs custom post types, taxonomies, custom fields/metadata, relationships between entities, navigation entities, or Cradle Content definitions (DB-managed or code-managed) — for example "create a portfolio type", "add a services section editors can manage", "add a location field", "rename this post type key". Produces a native, migration-aware content model with correct ownership, capabilities and REST exposure.
---

# Cradle content modeling

## Required context

- `cradle-project` orientation; website `northstar.md` (Content model, Required pages, Editing
  expectations) and `docs/content-model.md`.
- `northstar.md` §3.3, §3.6, §9.3, §12 (Cradle Content), §22.3.
- `packages/plugins/cradle-content/` (definitions UI, validator, field types, capability mapping) and its
  `docs/` when present; the child theme's `content/content-types.json` (exported baseline).
- `references/definition-rules.md` — keys, reserved names, registration snippets, meta, migrations.

## Preconditions

- A website's post types, taxonomies and field groups are Cradle Content definitions — created under
  Cradle → Content types (or by importing JSON), exported to the child theme's `content/content-types.json`
  as the reviewable baseline. There is no site plugin, and a website never calls `register_post_type()` /
  `register_taxonomy()` itself (editors would not see such types under Content types). Deactivating Cradle
  Content pauses the types (URLs 404, content kept) — accepted by the owner. One authoritative owner per
  definition; never a DB copy and a file copy that both claim authority.
- Check existing registrations for collisions (`post_type_exists()`, `taxonomy_exists()`, WooCommerce
  `product`, other plugins) before choosing keys.
- Know existing content that uses a key before renaming or removing it.

## Procedure

1. Describe the model in `docs/content-model.md`: entities, fields (type, bounds, required, who edits,
   public or private), taxonomies, relationships, queries/archives, templates that display them, editors'
   responsibilities.
2. Choose native storage: posts for items with their own URL or editorial workflow; terms for
   classification; post/term/user meta for attributes; `wp_navigation` for menus; WooCommerce products for
   anything sold. Relationships store IDs in registered meta (or a taxonomy) — never names in free text.
3. Define internal keys separately from labels: post type ≤ 20 chars, taxonomy ≤ 32 chars, lowercase
   `[a-z0-9_-]`, not reserved (see reference). Keys are stable; labels can change.
4. Register on `init` inside the owning module's `boot` (every request): `register_post_type()` with
   explicit `labels`, `public`, `show_in_rest` (required for the block editor), `supports`, `has_archive`,
   `rewrite`, `capability_type`/`map_meta_cap`, `template` (+ `template_lock` only when intentional);
   `register_taxonomy()` with `show_in_rest`, `hierarchical`, `rewrite`, capabilities.
5. Register fields with `register_post_meta()`/`register_term_meta()`: `type`, `single`, `default`,
   `sanitize_callback`, `auth_callback`, and `show_in_rest` (with `schema` for arrays/objects) only when
   editors or public output need it. Protected keys start with `_`; Cradle internals use `_cradle_`.
   Editing UI: block editor sidebar panel (plain JS, `wp.data` `useEntityProp`) or Cradle Content's
   bounded field editor — no ACF.
6. Capabilities: map to `edit_post`/`read_post` object checks; custom capability types when editors must
   be separated; grant defaults through the module descriptor (administrator unless justified).
7. Templates and queries: child `single-<type>.html`, `archive-<type>.html`, `taxonomy-<tax>.html`; Query
   Loop blocks with bounded `perPage`; add the entity to `docs/component-map.md` usages.
8. Rewrite rules: flush on activation/deactivation or after a definition change (Cradle Content saves),
   never on every request.
9. Changes: label change = safe. Key rename, field removal, taxonomy deletion = migration preview with
   affected-record counts, explicit confirmation, idempotent batch migration, and a rollback note.
   Disabling a type keeps its posts and says how to reactivate or convert them.
10. Export/import: definitions export as JSON without overwriting unrelated definitions (§12.4); list
    owned keys in the module's `dataOwners`.

## Verification

- An editor (or the mapped role) can create, edit, classify and publish an item; a subscriber and a
  logged-out visitor cannot (use `cradle_test_user( 'subscriber' )` and `cradle_test_http()` against
  `/wp-json/wp/v2/<rest_base>`) (CR-11, CR-26).
- Archive, single and taxonomy views show the item; the Query Loop list includes it.
- REST responses expose only intended fields; private meta is absent for anonymous requests.
- Switching the theme (disposable target only) or deactivating the child's templates leaves the content in
  wp-admin because the type is plugin-registered (CR-15).
- Definition export → import on a clean target recreates the model without duplicate ownership.
- Add assertions to `tests/integration/<package>.php`; run `php tests/run.php --suite=<package>`.

## Required output

Model summary (keys, labels, owner, fields with REST exposure, capabilities, templates), files changed,
migration notes, verification results with denied-role evidence, `docs/content-model.md` update.

## Failure behavior

- Key collision or reserved key: choose another key; never overwrite another plugin's registration.
- Key rename or field removal without a migration preview: stop and build the preview first.
- The request asks for `register_post_type()` in the theme: implement it as a Cradle Content definition
  instead and export the baseline to the child's `content/content-types.json`.

## References

- `references/definition-rules.md`
- `northstar.md` §12; acceptance CR-11, CR-12, CR-15, CR-26
- WordPress references [R16], [R17], [R18] (northstar §27)
