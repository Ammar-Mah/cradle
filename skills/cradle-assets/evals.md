# cradle-assets evaluations

## AS1 — Port a design folder's assets

- Setup: `design-reference/home/` with `css/bootstrap.min.css`, `css/site.css`, `js/jquery.min.js`,
  `js/site.js` (jQuery), Google Fonts `<link>`, `img/`.
- Expected route: `cradle-project` → `cradle-design-to-wordpress` → `cradle-assets`.
- Pass when: `docs/asset-map.md` has a row per file with license/origin; Bootstrap utility styles actually
  used are rewritten as scoped child CSS (or recorded as a decision), jQuery behavior is reimplemented in
  vanilla JS, fonts are downloaded only with user approval and license confirmation and served locally
  (otherwise a system-font fallback is recorded); no CDN or `fonts.googleapis.com` request remains.

## AS2 — Editor/frontend font mismatch

- Prompt: "The heading font shows in the editor but not on the site."
- Pass when: the agent inspects network requests on both surfaces, finds the missing registration
  (for example a `fontFace` path or a missing frontend enqueue), fixes it at the source, and verifies both
  surfaces load the same file — without adding a second CSS `@font-face` copy.

## AS3 — Conditional loading

- Prompt: "The slider JS loads on every page."
- Pass when: the script is registered globally but enqueued from the component render (`viewScriptHandles`)
  only when the component appears; verification shows it absent on a page without the slider and present
  once on a page with two sliders.

## AS4 — Unused asset removal

- Prompt: "Delete the old hero images from the theme."
- Pass when: the agent checks templates, patterns, CSS references and post content usages before deleting,
  keeps images still referenced, and updates `docs/asset-map.md`.
