# Cradle Admin — package instructions

Package: `packages/plugins/cradle-admin` · module id `admin` · text domain `cradle-admin` ·
spec: `northstar.md` §11 (acceptance CR-14), §6.2, §20, §21.2 · core contract: `docs/architecture/core-api.md`.

## Scope

White labeling of the WordPress administration **without changing how it works**:

- Branding (logos, brand name, footer text, support links, dashboard welcome panel, admin bar brand menu, login page).
- Structured visual controls output as CSS custom properties on approved screens only.
- A label override registry (post types, taxonomies, admin menu items, supported text domain + context strings).
- A trusted-administrator raw CSS field with bounded size, policy checks, revision history, restore and reset.
- Server-side safe mode (`CRADLE_ADMIN_SAFE_MODE`) that disables every styling and labeling change.

Everything is off/neutral by default. Presentation only: never grant or remove capabilities through
visual hiding, never hide update/security notices, never remove the WordPress version or license information.

## Layout

| Path | Purpose |
| --- | --- |
| `cradle-admin.php` | Bootstrap only: header, constants, autoloader, lifecycle hooks, descriptor registration, core-missing guard. |
| `src/class-module.php` | Descriptor, `boot`, `safe_mode()`, export/import handlers. |
| `src/class-sections.php` | Settings schemas (`admin-branding`, `admin-visual`, `admin-labels`) and every validator. |
| `src/class-screens.php` | Screen → approval group classification (`cradle_admin/screen_group`). |
| `src/class-skin.php` | Body classes, skin stylesheet, CSS custom properties, per-user choice. |
| `src/class-branding.php` | Footer, admin title, admin bar brand menu, welcome panel, login page. |
| `src/class-labels.php` | Label override registry runtime. |
| `src/class-css-policy.php`, `src/class-custom-css.php` | Raw CSS acceptance policy; storage, revisions, output gating. |
| `src/class-color.php` | WCAG contrast and color math. |
| `admin/class-page.php` | Cradle → Branding & admin (tabs) and raw CSS handlers. |
| `admin/class-fields.php` | Renderers for structured fields. `admin/class-profile.php` — per-user choice. |
| `assets/css/skin.css` | Scoped skin (`:where(body.cradle-admin-*)` prefixes). `login.css`, `settings.css`, `js/settings.js`. |
| `docs/` | `contract.md` (features, hooks), `security.md` (trust boundaries, CSS policy), `data-and-lifecycle.md`. |

## Rules for changes

- Hooks attach only in `Module::boot()` (module ready). Every runtime callback checks `Module::safe_mode()` at call time.
- Settings go through core sections (`$core->settings()`); validators reject invalid input first, then normalize.
- Colors must keep the WCAG checks in `Sections::validate_visual()` / `validate_branding()`.
- Skin CSS: scope every rule to a Cradle body class; prefer `:where()` so WordPress' own precedence wins where it should;
  logical properties only; never change layout on block editor screens; never rewrite the admin DOM with JavaScript.
- Cradle Admin's own page is never styled (`Screens::EXCLUDED`) — it is the recovery screen.
- Labels: use the WordPress API for each label type. No output buffering, no global string replacement.
- Raw CSS: keep `Css_Policy` conservative, re-validate on restore, keep the HMAC-or-revalidate output gate,
  and keep the trusted-administrator check (`cradle_manage_branding` + `manage_options` + `unfiltered_html`).
  Never claim the policy makes arbitrary CSS safe.
- Mutations: capability + nonce + POST (`admin-post.php` handlers use `Page::guard()`). GET never mutates.
- Assets load only where used (approved screens, this page, login page when enabled).

## Lifecycle

- Activation: refuses network activation, grants `cradle_manage_branding` to administrators once (recorded in
  `cradle_granted_capabilities`), creates the non-autoloaded CSS options. Never switches a feature on.
- Deactivation: keeps all data; the native admin returns immediately (all changes are runtime hooks).
- Uninstall: retain by default; purge only when `cradle_uninstall_policy['admin'] === 'purge'` and only `dataOwners`.

## Testing

`php tests/run.php --suite=admin` (tests/integration/admin.php, fixtures in tests/fixtures/admin/).
Exercise allowed (administrator) and denied (editor, subscriber, administrator without `unfiltered_html`) roles
for every mutation. Reset to defaults (white-label off) when finished; leave the plugin active.
