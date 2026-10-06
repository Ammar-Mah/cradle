# Changelog

All Cradle packages share this changelog; each package keeps its own version header.

## Unreleased — 2026-09-29

- `cradle-migration` 0.3.0: **the site language and right-to-left layout survive a move.** Exports now carry
  the WordPress language files (`wp-content/languages`: core, theme and plugin translations — `.mo`, `.po`,
  `.json`) and the Cradle Translations catalogs, still inside the one `.cradle` file. Before, a site in Arabic
  or Hebrew was imported with its language setting but without the files that make it right-to-left, so it
  showed left-to-right (and in English). New export and import option **Language files** (on by default);
  Cradle Translations data is restored together with the database. After an import, three new checks report
  the language files, the Cradle Translations catalogs and whether the front page has the same text direction
  as the source. PHP translation caches (`*.l10n.php`) are never exported or accepted; WordPress uses the
  `.mo` files. Importing a `.cradle` file made by 0.3.0 needs 0.3.0 on the destination (older versions refuse
  it before changing anything); `.cradle` files from 0.2.0 still import (they contain no language files — see
  below for sites already imported that way).
  Sites imported with 0.2.0 or earlier: release the safety hold, then choose the language again under
  Settings → General so WordPress downloads its files.
- `cradle-migration` 0.2.0: **one file, one click.** An export is now a single `.cradle` file (a standard ZIP
  that holds the archive manifest and all parts unchanged; rename it to `.zip` to look inside). **Export site**
  downloads it automatically when it is ready; Archives shows one download per archive. **Import** takes one
  `.cradle` file: after one confirmation it uploads, unpacks, checks, prepares the new site beside the current
  one, switches over and opens the login page (after signing in you see the import's outcome). Blocking
  problems still stop the import before anything changes; the journal, backups, rollback and the post-restore
  safety hold are unchanged. Archives → Restore… works the same way for archives stored on the site.
  The command line accepts `import --archive=<file.cradle>` (manifest paths still work) and keeps its review
  and switch-over stops unless `--yes`; `export --out` copies the `.cradle` file. Archives exported by 0.1.0
  stay listed with their separate files and can still be restored; to upload them, zip the manifest and parts
  together and rename the file to `.cradle`. Update running sites by replacing the `cradle-migration` plugin
  folder only (no other package changes are needed).

## Unreleased — 2026-09-24 (review changes; not yet published)

- `cradle` 0.1.1: the Cradle menu is now **Overview** and **Activity** (plus add-on pages). Overview combines
  the former onboarding checklist and Modules page: theme check, every Cradle module (not installed,
  inactive, ready, failed) with activation links and the “When deleted” data choice (keep data / remove its
  data; `delete_plugins`), and the status report. Removed: the core Settings page (activity retention is
  fixed at 90 days / 5000 rows; filters `cradle/activity_retention_days`, `cradle/activity_max_rows`), the
  “Components & cleanup” page and `cradle_manage_components`, the separate Modules page and the Developer
  page (template/style origins, capability table and the storage re-probe button; storage status and
  component overrides now appear in the status report and Site Health). Settings sections accept
  `menu_parent`. Schema 3 cleans up the removed option and capability.
- `cradle-blocks` 0.1.1: new **Hero slider** block (`cradle/hero-slider` 3.0.0, render contract
  `hero-slider-v2`): slides are published entries of any content type (latest entries with count/order/term
  filter, or entries chosen and ordered in the block), each showing the featured image, title, excerpt and a
  link; every style and behavior option (heights, colors, overlay, alignment, image focus, transition,
  autoplay, controls) is a block setting. Removed the “Block conversion” page and `cradle_convert_components`;
  the usage scan and `Gutenberg_Adapter::export_or_convert_instance()` remain as APIs. The shortcode adapter
  accepts comma-separated lists for array properties (`post-ids="12,14"`).
- `cradle-hero` **retired**: the hero slider is a normal Cradle Blocks component, not a plugin. No hero post
  type, hero menu, slider-defaults settings or `cradle_manage_hero` capability remain.
- `cradle` 0.1.1 (continued): the Activity page was removed (records are still kept 90 days for support and APIs);
  the Cradle menu has a fixed order — Overview, Branding & admin, Content types, Migration, Translations,
  Dummy content, Social, Duplicate, Login — whatever order add-ons load in.
- `cradle-content` 0.1.1: the Post types and Taxonomies tabs also list, read-only, the custom types registered
  by other plugins or the theme; rewrite rules are re-flushed once if another request dropped the routes of a
  Cradle Content type (for example while core was missing).
- `cradle-translations` 0.1.1: **built-in German (de_DE) and Arabic (ar) example languages** with a complete
  translation of every framework string (`examples/*.json`); always present, cannot be deleted, shipped text
  only fills entries without a translation or draft; Arabic switches the admin to right-to-left.
- `cradle-fonts` **retired**: custom fonts use the WordPress Font Library (Site Editor → Styles → Typography).
- `cradle-admin` 0.1.1: menu placement no longer depends on removed pages.
- **Websites have no site plugin** (not even an optional one). Everything site-specific lives in the child
  theme: the brief (`northstar.md`), state and decisions (`docs/`), design, site components
  (`components/<slug>/` + optional `blocks/<slug>/`, registered with Cradle by the child's `functions.php`),
  small site code (`inc/`) and the content-model baseline (`content/content-types.json`); content types are
  Cradle Content definitions. If Cradle Content is inactive their URLs 404 (content kept).
- Theme `cradle` 0.1.1: the parent theme now **creates and maintains website child themes** — it ships the
  starter (`starter/child-theme/`, including the northstar brief template and agent instructions) and the CLI
  generator `bin/child-theme.php` (`new --slug --name [--site-id] [--target]`; `check --child [--add-missing]`
  after a parent update, never overwriting). CLI only; a web request exits without output. Replaces
  `templates/cradle-site-theme/`, `templates/cradle-site-plugin/` and `scripts/new-site.php`.
- `cradle` core: `Components::register_from_file()` also accepts descriptors inside the active theme (child or
  parent), so site components live in the child theme.
- `cradle-content`: screen texts about code definitions no longer mention a site plugin ("the code that supplies them").
- Sandbox site: Projects, Project types and Project facts are Cradle Content definitions whose baseline is
  `themes/cradle-site/content/content-types.json`; provisioning is the CLI `sites/sandbox/bin/provision.php`,
  which imports that baseline and converts projects created by the old site plugin (`cradle_project` →
  `project`, `project_*` → `_cradle_cf_*`). The Studio page shows a Hero slider of the three latest projects.
- `scripts/sync.php` / `build.php` pick up child themes generated under `sites/<site>/themes/<slug>` automatically.
- **Thin child themes**: the parent now loads a child's `assets/css/design.css` (`cradle-child-design`),
  `assets/css/site.css` (`cradle-child-site`), `assets/js/site.js` and `components/<slug>/` (+ `blocks/<slug>/`)
  by convention (`inc/child-theme.php`), so a child needs no PHP. The starter holds only site data (`style.css`,
  `theme.json`, brief, records, workspace file) and its `AGENTS.md` points to the parent's
  `docs/child-themes.md`, which carries the website rules and the update-safety contract. The sandbox child
  keeps only its demo entity lookup in `functions.php`.
- Build: `cradle-child-theme-starter-0.1.0.zip` (blank child `cradle-child`, generated by the parent's
  generator) replaces the two starter ZIPs, and the new **copy-in kit** `cradle-kit-<date>.zip`
  (`wp-content/themes/{cradle,cradle-child}` + every Cradle plugin) starts a website without commands;
  `cradle-update-<date>.zip` is the same without any child theme, for updating a site by copying.
- A new child's `northstar.md` is **empty**: it is the owner's brief (paste or drop in your own, any
  structure). What a brief covers moved to the parent's `docs/website-brief.md`, which agents use as a
  checklist to ask about gaps instead of rewriting the owner's file. Skills `cradle-project` and
  `cradle-design-to-wordpress` updated accordingly (plugin ZIPs change only in their bundled skill copies).
- Tests: new `child-theme` suite (generator new/check/refusals/web access, child-theme components rendered as blocks).

Upgrade notes: children made from the earlier starter should delete the enqueue and component-registration
functions from their `functions.php` (the parent now does both; otherwise design styles load twice under two
handles). A site plugin made from the old starter should have its content types recreated under Cradle →
Content types (or imported) and its presentation/components moved into the child theme before it is deleted.
Delete Cradle Fonts if installed (fonts stay in the WordPress Font Library). Sites with the Cradle Hero plugin should set “Remove its data” for it on Cradle → Overview
if its slides are no longer needed, delete the plugin, and place Hero slider blocks (Cradle Blocks) that
show posts or other entries instead. Existing `cradle/hero-slider` blocks saved by the plugin keep their
comment but use different attributes; re-insert them.

## 0.1.0 — 2026-09-24 (initial development release; not yet published)

- Parent block theme `cradle` with minimal valid templates, parts and translatable patterns.
- Core plugin `cradle`: module registry (API 1.0.0), settings schema service on the Settings API,
  capabilities, builder-neutral component registry with child render-contract overrides, jobs and locks
  with fencing, redacted activity, protected private storage with HTTP probe, ownership inventory,
  Site Health checks, onboarding/modules/components/activity/developer screens.
- `cradle-blocks`: Gutenberg and shortcode adapters, `cradle/feature-card`, `cradle/post-cards`, block conversion (removed in 0.1.1).
- Feature plugins: login, admin, content, duplicate, translations, migration, dummy-content, hero (retired in the next release), fonts, social
  (see each package's docs for status).
- Child-theme and site-plugin starters, `scripts/new-site.php`, representative sandbox site.
- Canonical skill pack (17 skills) with generated Claude Code wrappers.
- Tooling: `scripts/sync.php`, `scripts/build.php`, sandbox helpers, CLI integration tests.

Upgrade notes: first release; no migrations yet.
