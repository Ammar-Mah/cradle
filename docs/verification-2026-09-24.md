# Verification record — 2026-09-24

Environment: Windows, WAMP (Apache 2.4.65, PHP 8.4.15 CLI and Apache module, MySQL 8.4.7), WordPress 7.1.2,
subdirectory install `/cradle/wp`, table prefix `cw_`, `WP_DEBUG` + `WP_DEBUG_LOG` on, `WP_DEVELOPMENT_MODE=theme`.
Sandbox source and mirror were identical (`php scripts/sync.php --dry-run` reported no differences).

## Full sequential run

`php tests/run.php --suite=core,blocks,login,admin,content,duplicate,dummy-content,translations,fonts,hero,social,migration`

| Suite | Passed | Skipped | Failed |
| --- | --- | --- | --- |
| admin | 21 | 1 (WooCommerce screens — not installed) | 0 |
| blocks | 8 | 0 | 0 |
| content | 19 | 0 | 0 |
| core | 14 | 0 | 0 |
| dummy-content | 12 | 0 | 0 |
| duplicate | 12 | 0 | 0 |
| fonts | 19 | 0 | 0 |
| hero | 14 | 0 | 0 |
| login | 18 | 0 | 0 |
| migration | 20 | 2 (HPOS store — WooCommerce not installed; real disk-full write — not safely reproducible) | 0 |
| social | 19 | 0 | 0 |
| translations | 28 | 0 | 0 |
| **Total** | **204** | **3** | **0** |

`wp-content/debug.log` after the run: empty (0 lines).

## Lifecycle run (alone, because it switches theme and replaces packages)

`php tests/run.php --suite=lifecycle` → 4 passed, 0 failed, 0 skipped; sandbox restored (theme, 13 active Cradle plugins, all modules `ready`); `debug.log` empty — including while the core plugin directory was missing.

## Browser checks (Claude desktop Browser pane)

- Block editor: provisioned content (61 blocks) and all templates/parts/Cradle patterns (176 blocks) parse as valid; feature-card edit → save → reopen persists attributes; kses-encoded (Author-saved) content stays valid.
- Visual fidelity: measured comparison at 1280×800 and 390×844 — `sites/sandbox/themes/cradle-site/design-reference/baseline/comparison-2026-09-24.md`.
- Administrator editing: hero heading changed and saved; navigation entity reordered and relabelled; frontend reflected both; provisioning reported both as conflicts.

## Not run

WooCommerce/HPOS; WP-CLI command paths; Playwright/axe/Lighthouse; screen readers; RTL visual pass; HTTPS; multisite.

## Re-run after the review changes (same day)

Changes: core Settings, “Components & cleanup” and “Block conversion” pages removed; data policy moved to
the “When deleted” column on Cradle → Modules; hero items as a custom post type with the Hero slider block
choosing all or specific items; demo Slider 1–3 on the Studio page.

`php tests/run.php` (all suites): **209 passed, 0 failed, 3 skipped** (the same three environment skips);
`--suite=lifecycle`: 4 passed. `debug.log` stayed empty. Sandbox mirror identical to source.
Browser checks (in-app browser, disposable test administrator via minted session cookies): Hero menu
(Hero items / Add hero item / Slider defaults), Slider 1 edit screen, Cradle menu without the removed pages,
Modules page “When deleted” column, Studio page carousel (3 slides, controls, image priorities), block
editor item picker (all items vs chosen items, reorder, live preview) — edits discarded, nothing saved.

## Re-run after the second review round (same day)

Changes: Overview and Modules merged into one Overview page; Developer page removed; the Cradle Hero plugin
retired and the hero slider rebuilt as the Cradle Blocks component `cradle/hero-slider` (posts as slides,
every style/behavior option on the block). In the sandbox the Cradle Hero plugin was removed through
WordPress (data choice "Remove its data", deactivate, delete): its uninstall routine deleted 11 hero posts
(the 3 demo items and 8 older hero/slide-group posts from earlier sandbox testing), its settings and its
capability. The Studio page was re-provisioned with a slider of the three latest projects.

`php tests/run.php` (all suites): **206 passed, 0 failed, 3 skipped** (the same three environment skips;
the hero suite now has 11 tests); `--suite=lifecycle`: 4 passed. `debug.log` stayed empty.
Browser checks: Cradle menu (Overview, Activity and add-on pages only), merged Overview (theme, modules,
status report), Studio page carousel (3 project slides, controls, image priorities), block editor panels
(Slides, Slide content, Layout, Colors, Behavior), latest-entries preview, choosing an entry (search →
add → preview) — edits discarded, nothing saved.

## Re-run after the third review round (same day)

Changes: Activity page removed and the Cradle menu fixed to Overview, Branding & admin, Content types, Migration,
Translations, Dummy content, Social, Duplicate, Login; Cradle Fonts retired (removed from the sandbox through
WordPress with "Remove its data": no fonts existed, only its settings and capability were deleted); the sandbox site
plugin retired (deleted through WordPress keeping data) — Projects, Project types and Project facts are now Cradle
Content definitions and the 6 existing projects were converted in place (IDs, terms, images and facts kept);
Content types lists types registered by other code; built-in German and Arabic example languages (2,122 / 2,123
shipped entries, all validated with the Translations rules) plus the site's own 16 strings via provisioning.

`php tests/run.php` (all suites, lifecycle included): **188 passed, 0 failed, 3 skipped** (same three environment
skips; the fonts suite was removed with the plugin). `debug.log` stayed empty.
Browser checks: Cradle menu order; Content types lists Projects (6 posts), Project types (3 terms) and Project facts
(3 fields); frontend project archive, a project page with facts, a Project type archive, home and Studio; admin in
Arabic (right-to-left, Cradle strings and state badges translated) and German (Cradle strings and "Projekte").
The test administrator's language was restored to English afterwards.

## Re-run after the fourth review round (same day)

Changes: websites have no site plugin at all — everything site-specific lives in the child theme (site
components in `components/<slug>/`, registered by the child's `functions.php`; core accepts component descriptors
inside the active theme); the parent theme ships the child-theme starter (`starter/child-theme/`) and the CLI
generator `bin/child-theme.php` (`new`, `check [--add-missing]`), replacing `templates/` and `scripts/new-site.php`;
the sandbox content-model baseline moved to `themes/cradle-site/content/content-types.json`; five reworded Cradle
Content strings translated in the German and Arabic examples (2,122 / 2,123 shipped entries after pruning
strings that no longer exist).

`php tests/run.php` (all suites, lifecycle included): **194 passed, 0 failed, 3 skipped** (the same three
environment skips). New `child-theme` suite 6/6: generator from the installed parent (complete child, no
placeholders, valid PHP, no site plugin), refusals (existing folder, framework slugs, traversal, unsafe name/ID),
`check` list/add-missing without changing edited files and refusing a foreign theme, repository-source layout
with resolvable `sourceLayout` paths, no output or files from a web request to `bin/child-theme.php`, and a
component folder in the active child registered, rendered as a block on a real page request with escaped text.
`debug.log` stayed empty. Sandbox afterwards: `cradle-site` active, 10 Cradle plugins active, all modules ready.
`php scripts/build.php` → 14 artifacts (one child-theme starter ZIP generated by the parent's generator; the
theme ZIP contains `bin/` and `starter/`).
Not re-run this round: browser checks (no screen changed besides reworded Content types help texts).

## Re-run after the thin-child and copy-in kit changes (2026-09-25)

Changes: the parent theme loads a child's `assets/css/design.css`, `assets/css/site.css`, `assets/js/site.js`
and `components/<slug>/` (+ `blocks/<slug>/`) by convention (`inc/child-theme.php`); the starter holds site data
only (no PHP/JS) and its `AGENTS.md` points to the parent's `docs/child-themes.md`; the sandbox child keeps only
its demo entity lookup in `functions.php`; builds add `cradle-kit-<date>.zip` (parent + blank `cradle-child` +
every plugin) and `cradle-update-<date>.zip` (parent + plugins, no child).

- `php tests/run.php` (all suites, lifecycle included): **194 passed, 0 failed, 3 skipped** (same environment
  skips). The child-theme suite now also checks that a new child has no PHP/JS, that the parent loads child
  design.css once after `cradle-base` and site.js deferred, and that the parent registers child components.
  `debug.log` empty.
- Sandbox front end: `cradle-child-design` loaded once (formerly the child's own `cradle-site-design`).
- Copy-in kit, end to end on a disposable WordPress 7.1.2 (core copied from the sandbox, database
  `cradle_kit`, removed afterwards): extracted the kit into the WordPress folder; activated all 10 plugins
  through `activate_plugin()` and the `cradle-child` theme (valid, parent `cradle`); home 200; Cradle →
  Overview 200 with 10 ready badges and 0 failed; Content types, Translations and Branding screens 200;
  every module ready. Then simulated site work inside the child only (theme.json accent token, design.css, a
  `components/kit-note` component with a block, a page using it), extracted `cradle-update-*.zip` over the
  site (0 child entries; the old parent file was replaced): the child's 16 files were byte-identical, the page
  returned 200 with the component rendered by the new parent, design.css loaded once and the token applied;
  `debug.log` absent (no notices).
- Not run: browser/editor checks of the kit site; an update to a genuinely newer parent version in the kit
  install (the lifecycle suite covers a parent version bump in the sandbox).
