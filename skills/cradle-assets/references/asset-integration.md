# Asset integration reference

## Load order (parent `inc/assets.php` + `inc/child-theme.php`)

1. `cradle-base` — parent defaults (`packages/themes/cradle/assets/css/base.css`, priority 5 on `wp_enqueue_scripts`).
2. `cradle-child-design` — the child's `assets/css/design.css` (ported source design), after `cradle-base`.
3. Component styles — enqueued on demand by the component render service (`assets.styleHandles`) or by
   block.json `style`.
4. `cradle-child-site` — the child's `assets/css/site.css` (adjustments), after the design styles.
5. Script `cradle-child-site` — the child's `assets/js/site.js`, deferred in the footer.

## Child assets need no PHP

The parent theme loads those three files from the active child (front end, and both stylesheets as editor
styles) when they exist, so a website child has no enqueue code that could break on a parent update
(parent `docs/child-themes.md`). Porting a design folder therefore means:

- Merge the source's global CSS into `assets/css/design.css`, scoped with section/component classes
  (for example `.hp-hero ...`) instead of page-conditional files; tokens stay in `theme.json`.
- Put site adjustments in `assets/css/site.css`.
- Put small site behavior in `assets/js/site.js` (vanilla, idempotent, scoped to data attributes).
- Styles and scripts that belong to one component ship with it (`blocks/<slug>/style.css`, `viewScript`
  in block.json, or component `assets` handles) so they load only where the component is used.
- Images and fonts under `assets/images/`, `assets/fonts/` (fonts via `theme.json` `fontFace` or the Font Library).

Only when none of this fits (for example a large page-only bundle) add a child `functions.php`, record
why in `docs/decisions.md`, and consider whether the need is really a parent convention (framework work).
Never deregister WordPress or WooCommerce assets wholesale to match a screenshot.

## Vanilla JS pattern

```js
( function () {
	'use strict';
	var reduce = window.matchMedia( '(prefers-reduced-motion: reduce)' );

	function init( root ) {
		if ( root.dataset.acmeReady ) {
			return; // Idempotent.
		}
		root.dataset.acmeReady = '1';
		root.querySelectorAll( '[data-acme-toggle]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var panel = document.getElementById( button.getAttribute( 'aria-controls' ) );
				var open = button.getAttribute( 'aria-expanded' ) === 'true';
				button.setAttribute( 'aria-expanded', String( ! open ) );
				panel.hidden = open;
			} );
		} );
		root.classList.toggle( 'is-reduced-motion', reduce.matches );
	}

	document.querySelectorAll( '[data-acme-accordion]' ).forEach( init );
} )();
```

IDs referenced by `aria-controls` come from the component's `instance_id`, so several instances coexist.

## Fonts

| Step | Rule |
| --- | --- |
| Source | Local files with license; no Google Fonts/remote services fetched automatically. |
| Format | WOFF2 preferred; WOFF/TTF/OTF only when needed; never SVG fonts. |
| Validation | Extension + size + signature (`wOF2`, `wOFF`, `\x00\x01\x00\x00`, `OTTO`); Content-Type ignored. |
| Registration | The WordPress Font Library (Site Editor → Styles → Typography → Fonts; `wp_font_family`, `wp_font_face`) for administrator-managed fonts; child `theme.json` `settings.typography.fontFamilies[].fontFace` with `file:./assets/fonts/…` for theme-owned fonts. |
| Loading | `font-display: swap`; preload only the above-the-fold face; only the weights actually used. |
| Proof | Editor and frontend computed `font-family` resolve to the same loaded file (network panel). |
| Removal | Check Global Styles and component usage first; offer reassignment. |

## Images and icons

- Editable content images → Media Library attachments referenced by ID; alt text set per use.
- Decorative theme images → child `assets/images/`, referenced in CSS or patterns with empty alt.
- Always pass width/height (or aspect-ratio) to avoid layout shift; the hero image is not lazy-loaded.
- Icons: a small reviewed inline SVG set in PHP (escaped attributes), or dashicons in admin; no remote icon fonts.

## Third-party request audit

1. Load the front page, a single, the editor and the login page with the browser network panel.
2. List every non-local host. Each must be in an approved list in `docs/decisions.md`, otherwise remove it.
3. Check for tracking pixels, font CDNs, embed iframes, analytics snippets carried over from references.
4. Record the result in `docs/verification.md` (date, pages, hosts found).
