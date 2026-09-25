---
name: cradle-assets
description: Use when adding, porting, or removing fonts, images, icons, media, CSS, or JavaScript — integrating a design folder's stylesheets and scripts into a child theme, registering enqueue handles, managing local fonts with Cradle Fonts or the native Font Library, maintaining docs/asset-map.md, fixing editor/frontend style mismatches, or checking for unexpected third-party requests. Produces an asset map and consistent, conditional editor/frontend loading with no unapproved dependencies.
---

# Cradle assets

## Required context

- `cradle-project` orientation; website `docs/asset-map.md`, `design-reference/`, child `theme.json`.
- Parent load-order contract in `packages/themes/cradle/inc/assets.php` and `inc/child-theme.php`: handle
  `cradle-base`, then the child's `assets/css/design.css` (`cradle-child-design`), component styles on demand,
  then `assets/css/site.css` (`cradle-child-site`) and `assets/js/site.js` — loaded by the parent, so the child
  needs no enqueue code — plus `cradle_theme_js_class()` (`cradle-js` class on `<html>`).
- `northstar.md` §2.1, §8.6, §21.1. Fonts use the WordPress Font Library (the Cradle Fonts plugin of §18 was retired by the owner as redundant).
- `references/asset-integration.md` — enqueue patterns, font workflow, media rules, request audit.

## Preconditions

- License and origin known for every font, image, icon set and script; unknown licenses are recorded as
  a missing material, not assumed.
- No runtime library, CDN, remote font service, tracker or iframe unless a recorded decision allows it.
- Source files in `design-reference/` stay unchanged; ported copies live in the child theme.

## Procedure

1. Inventory source CSS, JS, fonts, images and icons; add one row per file to `docs/asset-map.md`
   (source file, license/origin, original path, new path, handle, loaded where, notes).
2. Tokens first: colors, font families/sizes, spacing and layout into child `theme.json`; ported CSS uses
   `var(--wp--preset--…)` instead of a parallel token system.
3. CSS: source design in the child's `assets/css/design.css`, adjustments in `assets/css/site.css` (both
   loaded by the parent on the front end and in the editor); scope broad selectors to the component or a
   section class; logical properties for RTL; block/component styles through block.json or component
   `assets` handles. A child `functions.php` for extra handles only with a recorded reason.
4. JS: vanilla, in the child's `assets/js/site.js` (parent loads it deferred) or a component/block view
   script; scoped to root elements, idempotent initialization per instance,
   keyboard support, `prefers-reduced-motion`; no jQuery-based code; no inline `<script>` in templates;
   data via `wp_add_inline_script()`/`wp_json_encode()` or data attributes.
5. Fonts: local only. Use the WordPress Font Library (`wp_font_family`/`wp_font_face`) or child
   `theme.json` `fontFace` with files in `assets/fonts/`; WOFF2 preferred; `font-display: swap`; preload only
   the critical face; confirm the editor and frontend load the same files.
6. Media: import into the Media Library (attachment IDs) for editable content; theme-shipped decorative
   assets stay in the child; responsive images via `wp_get_attachment_image()`; reserve dimensions;
   lazy-load except the main above-the-fold image; SVG icons only from a reviewed inline set (no
   unrestricted SVG uploads).
7. Removal: only after checking handles, dependencies, content usages (inventory / `count_block_usage`)
   and templates.

## Verification

- Frontend and editor show the same fonts, colors and spacing for the ported sections (CR-07).
- Network panel (browser devtools or `read_network_requests`) shows no requests to third-party hosts on
  the frontend, editor and login page; no duplicate script tags; each view script loads only on pages
  using its component (CR-24).
- Two instances of an interactive component initialize once each; with reduced motion enabled
  animations stop; with JS disabled content remains usable.
- After a URL/path change (migration test) font and image URLs resolve.
- `grep -rniE "cdn\.|googleapis|fonts\.gstatic|jquery|bootstrap|tailwind|gsap|swiper"` over changed
  packages finds no runtime use.

## Required output

Updated `docs/asset-map.md`, handles and dependencies, load conditions, font files and licenses, request
audit result, performance notes (sizes, preloads), not-run checks.

## Failure behavior

- License missing: keep a placeholder/system font or omit the asset and record the missing material.
- A source script depends on an unapproved library: reimplement in vanilla code or record the decision
  needed; do not keep it silently or drop the behavior silently.
- Editor and frontend disagree: fix registration (editor styles/iframe) rather than duplicating CSS.

## References

- `references/asset-integration.md`
- `packages/themes/cradle/inc/assets.php`; `northstar.md` §8.6, §18, §21.1; acceptance CR-07, CR-21, CR-24, CR-30
- Font collection registration [R25] (northstar §27)
