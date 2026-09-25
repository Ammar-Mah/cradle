# Cradle Admin — feature contract

Module `admin` (core API `^1.0`). Admin screen: **Cradle → Branding & admin** (`admin.php?page=cradle-admin`),
tabs *Branding*, *Appearance*, *Labels*, *Custom CSS*. The first three embed core settings sections
(options.php, stale-edit detection, per-section **Reset to defaults**, JSON export/import).

All defaults are neutral: nothing changes in the administration until a value is configured.

## Capabilities

| Capability | Default roles | Grants |
| --- | --- | --- |
| `cradle_manage_branding` | administrator | Open the page; change branding, appearance and label sections; reset/export/import them. |
| raw CSS | — | `cradle_manage_branding` **and** `manage_options` **and** `unfiltered_html`, on an environment that allows unfiltered HTML. |

Profile choice: any user who can `edit_user` the profile (normally themselves) when the site allows the choice.

## Branding (`admin-branding`, option `cradle_admin_branding_settings`)

| Field | Effect | Hook |
| --- | --- | --- |
| `brand_name` | Replaces the trailing "WordPress" in admin (and branded login) document titles; brand menu label; login header text. | `admin_title`, `login_title`, `login_headertext` |
| `brand_url` | Logo link on the login page and the admin bar brand menu. | `login_headerurl` |
| `admin_logo` | Menu logo (CSS only) and/or admin bar brand menu image (see `logo_placement`). | `admin_body_class`, `admin_bar_menu` |
| `footer_text` | Plain text replacing the left footer; the WordPress version on the right is kept. | `admin_footer_text` |
| `support_links` | Up to 5 http(s) links in the brand menu and welcome panel. | — |
| `admin_bar_branding` | Replaces the `wp-logo` node with `cradle-brand` (children: support links, **About WordPress**). Updates, comments and account nodes are untouched. | `admin_bar_menu` @11 |
| `welcome_enabled`, `welcome_title`, `welcome_content` | Dashboard widget `cradle_admin_welcome` (high priority; the PHP update nag stays above it). Content is limited rich text rendered through `wp_kses`. | `wp_dashboard_setup` |
| `login_branding`, `login_logo`, `login_logo_width/height`, `login_background`, `login_text`, `login_accent` | Login page background, link colors, button color (`--wp-admin-theme-color`), logo or brand-name text. | `login_enqueue_scripts`, `login_body_class` |

## Appearance (`admin-visual`, option `cradle_admin_visual_settings`)

Native controls: color inputs, number + unit (px only) selectors, radio groups, checkboxes.

- `enabled` — apply the skin (default off). `allow_user_choice` — profile opt-out (default on).
- `screens` — approved screen groups: dashboard, content, block-editor, media, comments, users, appearance,
  plugins, tools, settings, cradle, other (default: all but *other*). Cradle Admin's own page, the site editor
  and the customizer are always excluded.
- Colors: accent, background, text, border, focus, menu background, menu text. Accent drives
  `--wp-admin-theme-color` (+ `--rgb`, `-darker-10`, `-darker-20`), the native mechanism used by WordPress
  buttons, forms, list tables, media views and the block editor.
- Layout (never on block editor screens): sidebar width (wide screens only; folded/auto-fold/mobile keep WordPress
  behavior), content spacing, density (default/compact/comfortable), control size (default 40px / compact 32px),
  corner radius.
- Typography: permitted local font stacks (`cradle_admin/font_stacks`) and base font size 12–16px.
- Logo: placement (menu / admin bar / both / none), menu logo width/height.

Accessibility validation (server side; both fields of a failing pair keep their previous values):
text ≥ 4.5:1 on the background **and** on white panels; accent ≥ 4.5:1 on the background and white;
focus ≥ 3:1 on the background and white; menu text ≥ 4.5:1 on the menu background; login text ≥ 4.5:1 on the
login background; login button ≥ 4.5:1 with white. Dark admin themes are out of scope (panels stay white).

Output: body classes `cradle-admin-skin`, `cradle-admin-layout`, `cradle-admin-density-*`,
`cradle-admin-controls-compact`, `cradle-admin-font-family`, `cradle-admin-font-size`, `cradle-admin-logo`,
`cradle-admin-custom-css` (only those that apply), stylesheet `cradle-admin-skin` (after `colors`) and one inline
block of custom properties generated from validated values. No JavaScript touches the admin DOM.

## Labels (`admin-labels`, option `cradle_admin_labels_settings`)

| Registry | Keys | Mechanism |
| --- | --- | --- |
| `post_type_labels` | post type → name, singular_name, menu_name, all_items, add_new, add_new_item, edit_item, search_items, name_admin_bar | Patches registered `WP_Post_Type::$labels` at boot and `init`@999; `registered_post_type` for later registrations. Visible in REST (`wp/v2/types`) and the block editor. |
| `taxonomy_labels` | taxonomy → name, singular_name, menu_name, all_items, add_new_item, edit_item, search_items | Same, with `registered_taxonomy`. |
| `menu_labels` | top-level slug, or `parent|child` for submenu items | `admin_menu`@9999; count bubbles after the text are kept. Access is unchanged. |
| `text_overrides` | domain (allowlist: `default` + `cradle_admin/label_text_domains`), optional context, exact original, replacement, optional screen ID | `gettext` / `gettext_with_context` in admin only. Placeholders must match exactly. Max 50. |

Overrides for entities or menu items that are not present right now (plugin inactive) are kept.
Label values are plain text (no markup, ≤ 80/60 characters).

## Custom CSS

Tab *Custom CSS*; handlers `admin-post.php?action=cradle_admin_css_save|cradle_admin_css_restore|cradle_admin_css_reset`
(POST, nonce, trusted administrator). Bounded to 64 KB; last 10 versions kept; any revision can be restored
(re-validated against the current policy); reset clears the active CSS but keeps it in history; stale edits are refused
and a rejected draft is kept for the author for 10 minutes. Printed as `<style id="cradle-admin-custom-css-inline-css">`
after the skin on approved screens only; scope selectors with `body.cradle-admin-custom-css`. See `security.md` for the policy.

## Per-user choice

Profile section *Admin appearance* → "Use the default WordPress appearance…" (user meta
`cradle_admin_native_appearance`). Affects skin, menu logo and custom CSS for that account; labels and branding text stay.
Ignored when `allow_user_choice` is off.

## Safe mode and recovery

- `define( 'CRADLE_ADMIN_SAFE_MODE', true );` in `wp-config.php` disables every styling, branding and labeling change
  (settings stay editable and are kept).
- `cradle_admin/safe_mode` filter (bool) can switch safe mode on for a request (tests, must-use recovery code);
  it cannot switch off the constant.
- The settings page is never styled; each section has **Reset to defaults**; custom CSS has **Reset**.
- Deactivating the plugin restores the native administration immediately.

## Filters

| Filter | Purpose |
| --- | --- |
| `cradle_admin/safe_mode` | `bool` — switch safe mode on. |
| `cradle_admin/raw_css_available` | `bool` — return `false` to disable raw CSS (editing and output). |
| `cradle_admin/screen_group` | `( string $group, WP_Screen $screen )` — classify custom screens (one of the documented groups or `excluded`). |
| `cradle_admin/font_stacks` | Add permitted local font stacks (`key => [label, stack]`, restricted characters). |
| `cradle_admin/label_text_domains` | Text domains allowed in text overrides (default `['default']`). |
| `cradle_admin/label_overrides` | Code-registered overrides; validated with the same rules as the screen. |

## Module handlers

`export()` returns non-sensitive section values plus the active custom CSS; `import()` re-validates everything,
requires `cradle_manage_branding`, and imports raw CSS only for trusted administrators.
