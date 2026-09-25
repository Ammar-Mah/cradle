# Cradle Social: contract, security and lifecycle

Module `social` (text domain `cradle-social`), northstar §19, acceptance CR-22. Depends on core
(API `^1.0`) and the `blocks` module (`^0.1`, provides the Gutenberg adapter). Capability
`cradle_manage_social` (administrators by default).

Two separate concepts:

- **Profile links** (`cradle/social-links`): the organization's own accounts. URLs are entered only by
  users with `cradle_manage_social`, validated per provider, and re-validated on render.
- **Share links** (`cradle/share-links`): share the current entry/page through fixed provider
  templates, plus local copy-link and Web Share actions.

No remote SDKs, widgets, trackers, iframes, remote images, icon fonts or CDN assets are loaded, and no
share counts exist anywhere in the code. Links are plain `<a href>` elements that work without JavaScript.

## Component layers (northstar §7.2)

| Layer | cradle/social-links | cradle/share-links |
| --- | --- | --- |
| Domain contract | `components/social-links/component.json` | `components/share-links/component.json` |
| Data access | `Config::enabled_profiles()` (settings, re-validated) | `Share_Links::target()` (public entry or request address) + `share_data()` |
| Provider rules | `Providers` (allowlisted registry, see `providers.md`) | same |
| Render service | `Social_Links::render()` — contract `social-links-v1` | `Share_Links::render()` — contract `share-links-v1` |
| Shared markup | `Markup::render()` (escaped; contract documented in the class) | same |
| Presentation | `assets/css/social.css` (handle `cradle-social`) | same + `assets/js/social.js` (handle `cradle-social-view`) |
| Editor adapter | `blocks/social-links/` + shared `assets/js/editor-controls.js` (handle `cradle-social-editor`) | `blocks/share-links/` + same controls |
| Fallback when inactive | none (`fallbackPolicy: none`, dynamic, `save()` = null) | none |

Surfaces using the same render service:

- Blocks: `<!-- wp:cradle/social-links /-->`, `<!-- wp:cradle/share-links {"services":["x","copy"]} /-->`.
- Shortcode (cradle-blocks adapter): `[cradle_component id="cradle/social-links" label-style="action"]` (scalar properties only).
- PHP: `cradle_render_component( 'cradle/share-links', array( 'postId' => 12 ) )`.
- Automatic placement (optional, off by default) calls the PHP surface from `the_content`.

### Properties (both components unless noted)

| Property | Values | Meaning |
| --- | --- | --- |
| `services` | list of provider/action IDs (≤ 20) | Per-instance subset + order. Empty = site settings. Profiles: only configured, enabled profiles can be picked. Share: any allowlisted share ID. |
| `display` | `''` \| `icon_text` \| `icon` \| `text` | `''` = site setting. |
| `labelStyle` | `''` \| `service` \| `action` | Visible text: "LinkedIn" vs "Follow us on LinkedIn". |
| `size`, `spacing`, `shape` | `''` or the setting's values | Presentation overrides. |
| `justify` | `start` \| `center` \| `end` \| `space-between` | Alignment of the list. |
| `label` | plain text ≤ 120 | Group name (accessible name; visible when shown). |
| `showLabel`, `newTab` | `''` \| `show`/`hide`, `''` \| `on`/`off` | Overrides of the site settings. |
| `target` (share) | `entry` \| `page` | `entry`: explicit `postId`, else the post in block context (current entry or Query Loop item). `page`: the current request address (singular, front page, posts page, term/post type/author archives; search/date/404 render nothing). |
| `postId` (share, PHP/shortcode) | post ID | Explicit entry to share (validated; only publicly viewable entries). |

Only publicly viewable entries are shared (`is_post_publicly_viewable()`); drafts, pending, scheduled,
private and trashed entries render nothing. Exception: the block editor preview (REST
ServerSideRender) of an entry the current user may edit, so authors can see the block before publishing.

## Settings (Cradle → Social, section `social`, option `cradle_social_settings`)

| Field | Type | Default | Validation |
| --- | --- | --- | --- |
| `profiles` | object (ordered list of `{service,url,enabled}`) | `[]` | Known profile provider; provider URL policy (`providers.md`); enabling without URL refused; order 0–999. Form shape (table) and list shape (JSON export/import) accepted. |
| `profiles_label`, `profiles_show_label`, `profiles_new_tab` | string ≤ 120, boolean, boolean | `''`, off, off | `''` label = "Follow us" (translated at render). |
| `share_services` | object (ordered list of IDs) | facebook, x, linkedin, bluesky, email, copy, native | Allowlisted share IDs only. |
| `share_title` | string ≤ 200 | `{title}` | Tags stripped; only `{title}`, `{url}`, `{site}` placeholders. |
| `share_text` | string ≤ 280 (textarea) | `{title}` | Same. |
| `share_label`, `share_show_label`, `share_new_tab` | string, boolean, boolean | `''`, on, off | `''` label = "Share this page". |
| `display` | enum | `icon_text` | icon_text \| icon \| text |
| `label_style` | enum | `service` | service \| action |
| `size` | enum | `medium` | small \| medium \| large |
| `spacing` | enum | `normal` | compact \| normal \| relaxed |
| `shape` | enum | `rounded` | none \| square \| rounded \| circle |
| `color_text`, `color_background` | string | `''` | `#rgb`, `#rrggbb` or `preset:<slug>` (theme palette token → `var(--wp--preset--color--slug)`); re-validated before reaching an inline style. |
| `placement` | enum | `none` | none \| after \| before \| both |
| `placement_post_types` | object (list) | `[post]` | Public, viewable post types (attachments excluded), checked when saving. |

Core supplies reset-to-defaults, JSON export/import, stale-edit protection (`_revision`), per-field
errors (an invalid field keeps its previous value), and activity logging for saves.

## Markup and accessibility

See the contract in `src/class-markup.php`. Key points:

- Profiles use a labelled `<nav>`; share links a labelled `<div role="group">`; items are a `<ul role="list">`.
- Every link's accessible name is the full action: "Follow us on LinkedIn", "Email us", "Share on X",
  "Share by email", "Copy link", "More sharing options" (all translatable, `%s` = provider label). With
  `labelStyle: service` the visible short label is contained in the accessible name (WCAG 2.5.3).
  Icon-only display keeps the name in visually hidden text.
- `target="_blank"` is added only when configured (`*_new_tab` / `newTab: on`), always with
  `rel="noopener noreferrer"` and a hidden "(opens in a new tab)" suffix. `mailto:` links never open a tab.
- Profile links carry `rel="me"` (identity verification, e.g. Mastodon).
- Copy-link and Web Share buttons are real `<button type="button">` elements rendered `hidden`; the view
  script reveals copy-link, and reveals Web Share only when `navigator.share` exists. Results are announced
  in `<p class="cradle-social__status" role="status" aria-live="polite" aria-atomic="true">`.
- Visible focus outline, ≥ 24 px targets, logical CSS properties (RTL), `forced-colors` support,
  transitions only under `prefers-reduced-motion: no-preference`. Colors are the administrator's
  responsibility: check contrast when choosing `color_text`/`color_background`.

## Assets

| Handle | File | Loaded |
| --- | --- | --- |
| `cradle-social` | `assets/css/social.css` | Where a component renders (block.json `style` + component `styleHandles`); in the editor iframe. |
| `cradle-social-view` | `assets/js/social.js` (no dependencies, defer, footer) | Enqueued by `Share_Links::render()` only when a copy-link or Web Share action is rendered. Not listed in `viewScriptHandles` on purpose (core would enqueue it for every share render). |
| `cradle-social-editor` | `assets/js/editor-controls.js` | Block editor only, as a dependency of both blocks' `editorScript`; receives provider IDs/labels and which profiles are configured (never profile URLs). |

In feeds, share links keep only plain links (script-only actions are dropped).

## Automatic placement

Off by default. When enabled, `Placement::filter()` (`the_content`, priority 20) adds share links
before/after the content of the main query's singular entry, inside the loop, for the configured post
types. It never acts in admin, feeds, embeds, REST responses or excerpt generation, on locked
password-protected entries, or on entries that already contain a Share links block.

## Social metadata (Open Graph)

**Not implemented.** Cradle Social outputs no `og:*`/`twitter:*` meta tags, so it cannot duplicate
tags from an SEO plugin. Basic link sharing works without them: providers fetch the page and use
whatever metadata the site already provides; remote preview cards are not promised. If metadata output
is added later it must have a single owner, be filterable and be off by default (northstar §19).

## Icons

`src/class-icons.php` holds a small set of **original**, deliberately generic monochrome stroke glyphs
written for Cradle: neutral letter-marks (f, x, in, b, m, r, p) and generic pictograms (camera, play
button, speech bubble, paper plane, envelope, chain link, share nodes, globe). They are not copies of
provider logos or of any third-party icon library, so no trademark artwork is redistributed. The SVG is
static PHP data (`path`/`circle`/`rect` geometry only, `aria-hidden`, `focusable="false"`,
`currentColor`); there is no icon upload, and SVG uploads are never accepted. Sites that need official
brand marks must obtain them under the provider's brand terms and provide them through a child render
override.

## Trust boundaries and security (northstar §20)

| Input | Who | Control |
| --- | --- | --- |
| Settings form (`options.php`) | users with `cradle_manage_social` | Core: `option_page_capability_cradle_social` = `cradle_manage_social`, group nonce, `_revision`; module validators reject before normalizing. Tested: editor/subscriber refused even with their own nonce. |
| Settings import (JSON) | same | Core import handler (nonce + capability) → same validators. |
| Profile URLs | administrators | https only (mailto for email), host allowlist or federated path rule, no credentials/ports/fragments, re-validated on render, `esc_url()` on output. |
| Share title/text | administrators | Tags stripped, placeholder allowlist, single-pass expansion on plain text, `rawurlencode()` in fixed templates, `esc_attr()`/`esc_url()` on output. |
| Block attributes / shortcode attributes | editors/authors | Normalized by the core component schema (enums, lengths, slug IDs); can only select allowlisted services and presentation values; never URLs, hosts, templates, CSS or HTML. |
| Entry titles | authors | `wp_strip_all_tags()` + entity decode → plain text → encoded/escaped. |
| Provider registry filter | trusted PHP only | Re-validated; invalid definitions dropped (`Providers::problems()`). |
| Stored option (database) | — | Enums, colors, placeholders and URLs are re-checked at render time. |

No REST routes, AJAX handlers, admin-post handlers, uploads, file writes, cron jobs or remote requests
are added by this package.

## Lifecycle

- **Activation**: refuses network activation; grants `cradle_manage_social` to administrators once
  (recorded in `cradle_granted_capabilities`); stores `cradle_social_installed_version`. No content or
  settings rows are created (defaults are computed on read).
- **Deactivation**: nothing scheduled or locked; settings kept. Saved blocks then render nothing
  (dynamic, no saved fallback) — the same happens if core or cradle-blocks is missing. Keep the plugin
  active while content uses these blocks (a static snapshot of one instance can be produced with the
  cradle-blocks export API).
- **Core missing**: the bootstrap loads, registers nothing, and shows an admin notice to users who can
  activate plugins. **Blocks module missing**: the module state is `failed` (dependency) and nothing boots.
- **Uninstall** (`uninstall.php`): retain by default. Only when `cradle_uninstall_policy['social'] === 'purge'`
  (the “When deleted” column of Cradle → Overview) are `cradle_social_settings`, `cradle_social_installed_version` and the
  capability removed. Post content containing the blocks is never modified.

## Hooks

- `cradle_social/providers` (filter, trusted PHP): adjust the provider registry (see `providers.md`).
- `cradle/component_html` (core filter): post-process rendered component HTML (must stay escaped).
- Child overrides: `<child>/cradle/components/social-links/render.php` with header
  `Render contract: social-links-v1` (and `share-links/render.php` with `share-links-v1`). An override
  receives `$props`, `$context`, `$component`, must escape output and keep the documented classes and
  accessibility behavior; it should build items from `Config`, `Providers` and `Share_Links` rather than
  hard-coding URLs.

## Verification

`php tests/run.php --suite=social` (tests/integration/social.php). Helpers in `tests/fixtures/social/`
run uninstall and the missing-core/missing-blocks checks in isolated PHP processes.
Not automated: a real browser session inside the block editor (inserting blocks, inspector controls),
screen-reader passes, and live provider endpoints (see `providers.md`).
