# Cradle parent theme: extension points and contracts

## Files

| Path | Contract |
| --- | --- |
| `theme.json` (version 3) | Minimal neutral presets: palette `base`, `contrast`, `muted`, `subtle`, `accent`; spacing `20`–`60`; font sizes `small`–`xx-large`; layout 720px / 1200px. Children override tokens in their own `theme.json`. |
| `templates/` | `index`, `single`, `page`, `page-no-title`, `archive`, `search`, `404`. All use `header`/`footer` parts and query the current request (`inherit: true`). |
| `parts/` | `header` (site title + core Navigation), `footer` (site title + credit pattern). |
| `patterns/` | Non-inserter patterns holding translatable strings: `cradle/no-results`, `cradle/search-form`, `cradle/404`, `cradle/footer-credit`. |
| `assets/css/base.css` | Structural defaults (focus-visible, media sizing, reduced motion, `.cradle-visually-hidden`). Handle `cradle-base`. |

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `cradle_theme/setup` | action (inside `after_setup_theme`) | Add theme supports, e.g. `add_theme_support( 'woocommerce' )` for a commerce child. |

## Stylesheet load order

1. `cradle-base` (parent, `wp_enqueue_scripts` priority 5)
2. `cradle-child-design` — child `assets/css/design.css` (depends on `cradle-base`)
3. component styles (enqueued when components render)
4. `cradle-child-site` — child `assets/css/site.css` (depends on the design style when present)

## Child theme contract

`inc/child-theme.php` loads, from the active child only and only when the files exist: the two stylesheets
above (front end and editor), `assets/js/site.js` (script `cradle-child-site`, deferred) and every
`components/<slug>/` (+ `blocks/<slug>/`) as a Cradle component while core is active. Full rules:
`docs/child-themes.md`.

## Front-end classes

`html.cradle-js` is added in `<head>` so components can progressively enhance while remaining usable without JavaScript.

## Overrides and precedence

Database-saved templates/parts and user Global Styles override these files (WordPress behavior).
The Site Editor marks templates and parts that were customized in the database; those win over theme files.
