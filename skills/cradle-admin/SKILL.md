---
name: cradle-admin
description: Use when working on Cradle Admin or any Cradle settings screen — admin/login branding, logos, footer and help links, dashboard welcome, admin color/dimension/density controls, per-screen label overrides, admin custom CSS, safe mode and reset, or the UX of server-rendered settings forms. Produces a scoped, accessible admin customization that never hides security notices or changes permissions, with reset and safe-mode recovery.
---

# Cradle admin experience

## Required context

- `cradle-project` orientation; `packages/plugins/cradle-admin/` source, `AGENTS.md` and `docs/` when present.
- `northstar.md` §6 (settings schema, admin UX, capabilities), §11 (Cradle Admin), §21.2 (accessibility).
- `docs/architecture/core-api.md` §4 (settings sections: typed fields, reset, export/import, `_revision`
  stale-edit rejection) and core admin assets (`cradle-admin` handle in `packages/plugins/cradle/assets/`).

## Preconditions

- Branding changes presentation only. It must not hide core update/security notices, falsify plugin
  identity or licenses, or imply that unrelated software was made by Cradle.
- Know which screens are affected (menus, list tables, media modal, block editor, Site Editor, notices,
  WooCommerce screens if active) and which capability manages each setting.
- Safe mode and reset exist before broad visual customization ships.

## Procedure

1. Settings: register sections through core Settings with typed fields — `color`, `dimension` (allowed
   units and ranges), `enum`, `attachment` (image MIME), `url` (https), bounded `string` — and a
   dedicated capability (for example `cradle_manage_branding`), not `edit_posts`.
2. Output CSS custom properties from validated values only (`Settings::css_dimension()`, sanitized hex
   colors), scoped to Cradle-controlled body classes or specific `$hook` screens; use logical properties
   for RTL. Enqueue per screen (`admin_enqueue_scripts` with `$hook` checks), never globally by default.
3. Login page presentation (`login_enqueue_scripts`, `login_headerurl`, `login_headertext`) only; routing
   belongs to `cradle-login`.
4. Labels: a registry of overrides keyed by screen, entity (post type labels via
   `register_post_type_args`/`post_type_labels_{type}`) or text domain + context (gettext filters with a
   preloaded map — coordinate with `cradle-translations`). No output buffering or global string
   replacement of admin HTML; no DOM rewriting in JavaScript.
5. Raw custom CSS (advanced): only for users with the trusted capability (`edit_css`, which WordPress
   removes when `DISALLOW_UNFILTERED_HTML` is set — then disable raw CSS and keep structured controls);
   bounded size; reject `</style`, `<`, `@import`, `url(` to non-local origins, `expression(`, `javascript:`;
   store revisions; provide reset. Never claim a regex makes arbitrary CSS safe.
6. Safe mode: a server-side constant defined in `wp-config.php` (document its exact name in the
   package `docs/`) disables all Cradle admin styling and labels; also a reset-to-defaults per section.
7. Accessibility: visible focus, contrast checks for chosen colors (warn below 4.5:1 for text), zoom to
   200%, keyboard access, labelled controls, field-specific errors; server validation even when the
   browser validates.
8. Update package `docs/` with settings, handles, screens affected and safe-mode instructions.

## Verification

- Allowed administrator saves branding; editor and subscriber cannot open the page or post to
  `options.php`/admin-post handlers (denied-role test via `cradle_test_http()`) (CR-14, CR-26).
- Styles apply only on intended screens; check Dashboard, Posts list, media modal, block editor, Site
  Editor, Plugins, Updates, and WooCommerce screens when active — no broken layout, notices still visible.
- Keyboard tab through the admin menu with custom colors: focus visible; zoom 200% and 782px width usable; RTL locale spot check.
- Invalid values (e.g. `99999px`, `red; } body{display:none`) rejected with field errors; other fields saved.
- Safe-mode constant defined → native admin restored; reset restores defaults.
- Visual hiding never changes capabilities: a hidden menu URL is still denied/allowed by capability as before.

## Required output

Settings and capabilities added, screens affected, handles, safe-mode/reset instructions, accessibility
checks with results, denied-role evidence, not-run checks.

## Failure behavior

- A style breaks an admin surface: scope it tighter or remove it; never ship with only a "use safe mode" note.
- Request to hide update/security notices or a license: decline and explain (§11.1, §20.5).
- Raw CSS requested for non-trusted roles: keep structured controls only.

## References

- `northstar.md` §6, §11, §21.2; acceptance CR-14, CR-26, CR-30
- `docs/architecture/core-api.md` §4; related skills `cradle-security`, `cradle-translations`, `cradle-quality`
