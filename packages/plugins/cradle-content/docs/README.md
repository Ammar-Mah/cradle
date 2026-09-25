# Cradle Content — feature contract

Status: implemented in 0.1.0 (requires Cradle core API `^1.0`). Northstar §12, acceptance CR-15 (and CR-11 in part).

## What administrators get

Cradle → **Content types** (capability `cradle_manage_content_types`, granted to administrators only):

| Tab | Purpose |
| --- | --- |
| Post types | Create/edit/disable/rename/delete post type definitions; see stored post counts, conflicts and code-managed definitions; re-create definitions for stranded posts. Custom post types registered by other plugins or the theme are listed read-only (name, key, posts, taxonomies) so the screen shows every content type editors meet. |
| Taxonomies | Same for taxonomies (term counts), including a read-only list of custom taxonomies registered by other code. |
| Field groups | Bounded fields attached to post types (post meta) or taxonomies (term meta). |
| Export / import | JSON export of selected definitions; import with a preview (new / identical / differs / blocked / invalid). |
| Migrations | Progress of key renames, conversions and field storage changes (core Jobs, resumable). |
| Settings | Operational limits (core settings section `content`, option `cradle_content_settings`). |

### Post type settings (§12.1)

Key (1–20 chars, `[a-z][a-z0-9_-]*`), singular/plural/menu labels (full WordPress label set is generated),
description, Dashicon (allowlist), menu position, enabled; public, publicly queryable, exclude from search,
show UI/menu/admin bar/nav menus, REST (+ optional REST base); hierarchical, archive (+ archive slug),
pretty permalinks (+ slug up to three segments, with_front), query var; supports (title, editor, excerpt,
featured image, author, revisions, comments, custom fields, page attributes); attached taxonomies;
capability model — `post`, `page`, or `custom` (capability_type singular/plural with `map_meta_cap`,
granted to administrators plus explicitly ticked editorial roles); starting block template (up to 20 rows
from an allowlist of core blocks with placeholders) and template lock (`insert`, `contentOnly`, `all`);
default ordering (admin list and archive main query when no explicit order is requested); extra admin
columns (featured image, author, last modified — sortable, menu order — sortable, excerpt, any field).

### Taxonomy settings (§12.2)

Key (1–32 chars), labels, description, enabled, hierarchical, attached post types, public/queryable/UI/menu/
nav menus/REST (+ REST base)/tag cloud/quick edit/admin column/query var, rewrite (+ slug, with_front,
hierarchical URLs), term order (`sort`), capabilities (`default` or custom base → `manage_/edit_/delete_/assign_<base>`),
default term (name/slug/description; WordPress creates and assigns it). Term metadata comes from field groups
that target the taxonomy.

### Validation of keys and slugs

Rejected with a field-specific message, before anything is stored:

- WordPress core post types and taxonomies, reserved terms and query variables (static list plus the live
  `$wp->public_query_vars`/`private_query_vars`), `wp_`/`wp-` prefixes, `cradle_`/`cradle-` prefixes (Cradle modules).
- WooCommerce and other well-known third-party keys (`product`, `shop_order`, `product_cat`, `pa_*`, …) even when those plugins are inactive.
- Any key already registered by other code (plugins or themes).
- Duplicate keys; a post type key equal to a taxonomy key (shared query-var space) and vice versa.
- Rewrite/archive slugs used by another definition or by types registered by other code; reserved first segments
  (`wp-admin`, `wp-json`/REST prefix, `feed`, `page`, `category`, `tag`, `author`, `search`, …). A page with the
  same path only produces a warning.
- Duplicate REST bases among Cradle post types.

## Definition lifecycle (§12.4)

- **Database definitions** are edited here. Every save is validated, protected by a per-definition revision
  (stale edits are refused, nothing is lost silently) and by a core lock (`content:definitions`).
- **Code definitions** come from another Cradle plugin through `cradle_content/code_definitions` (docs/code-definitions.md). Websites use database definitions and keep the exported baseline in their child theme (`content/content-types.json`).
  They are read-only here, always authoritative for their key, and can be **forked**: same key → a database copy that
  stays inactive ("shadowed") until the code definition is removed; new key → an independent copy (URL slugs, REST
  base and custom capability names are reset so it cannot claim the original's).
- **Label vs key**: labels are normal edits. The key field is read-only; "Rename key…" shows a preview with affected
  posts/terms by status, menu items, database definitions updated automatically, code definitions that must be
  updated by hand, and URL impact ("keep current URLs" pins slug and REST base to the old key). Confirming renames
  the definition and starts a batch migration job.
- **Disable** hides the type everywhere (front end, REST, admin) and deletes nothing. The list shows how many posts are
  retained and how to restore access (enable again) or move them (Delete… → convert).
- **Delete** shows counts first and requires confirmation. Post types: keep posts (default; they become reachable again
  when a definition with that key exists — the list offers "Create a definition for this key") or convert them to another
  post type. Taxonomies: terms and assignments are kept. Field groups: keep values (default) or delete them in batches.
- **Field storage**: renaming or removing a field that holds values opens a confirmation screen with counts; renamed values
  can be moved to the new key, removed values kept (default) or deleted.
- **Export/import**: JSON (`format: cradle-content-definitions`, `formatVersion: 1`); import never touches definitions that
  are not in the file, never overwrites without an explicit tick, and never takes over code-managed or foreign keys.
- **Rewrite rules** are flushed once, on the first request after the rewrite-relevant signature of registered types changes
  (see docs/data-model.md), or when the stored rules have lost the routes of a registered type (for example because
  other code regenerated them while Cradle Content was paused with core missing) — detected by one scan of the
  autoloaded rules array, never by flushing on ordinary requests.

## Editing surfaces

- Block editor: one document settings panel per field group (`assets/js/editor.js`), editing registered REST meta through
  `core/editor` so values save/undo with the post. Reference fields search `cradle-content/v1/lookup`.
- Classic meta box: fields with exposure "Admin forms only", and all fields of post types without the block editor.
- Term add/edit forms: all fields of groups targeting the taxonomy.
- Front end: block binding source `cradle-content/field` (public fields only), REST responses, and ordinary
  `get_post_meta( $id, '_cradle_cf_<key>', true )` in theme/site code.

## Hooks and APIs

| Name | Kind | Contract |
| --- | --- | --- |
| `cradle_content/code_definitions` | filter | Return `array( 'post_type' => [key => def], 'taxonomy' => [...], 'field_group' => [...] )`. |
| `cradle-content/field` | block binding source | Args `{ key }`; returns escaped plain text (rich text through the allowlist) or a URL for `url`/`href` attributes. |
| `GET /cradle-content/v1/lookup` | REST | `object`, `subtype`, `field`, `sub`, `search`, `include[]`; permission = edit capability of the subtype; candidates filtered per user. |
| `content/migrate` | core job type | Capability `cradle_manage_content_types`; created by the admin handlers only. |
| Module `export`/`import`/`cleanup` | core module handlers | Database definitions + settings; import overwrites differing database definitions; cleanup reports/deletes orphaned `_cradle_cf_*` values. |

See also: `data-model.md`, `fields.md`, `lifecycle.md`, `security.md`, `code-definitions.md`.
