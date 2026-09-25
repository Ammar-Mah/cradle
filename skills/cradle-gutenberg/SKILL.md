---
name: cradle-gutenberg
description: Use when adding or changing Gutenberg blocks (block.json, plain-JS editor scripts, inspector controls, RichText, InnerBlocks, save/render behavior), block styles or variations, block patterns, block templates and template parts, or when changing saved block markup (deprecations, migrations). Also use for "invalid block" errors, editor/front-end mismatch, or editor iframe styling. Produces native editable integration with save/reload/persistence evidence.
---

# Cradle Gutenberg adapter

## Required context

- `cradle-project` orientation; the component contract if the block is component-backed (`cradle-components`).
- `northstar.md` §2.1 (vanilla rule and editor exception), §7.5, §9.1–§9.2, §21.3.
- Existing conventions: `packages/plugins/cradle-blocks/blocks/feature-card/` (`block.json` apiVersion 3,
  `editor.js`, hand-written `editor.asset.php`, `style.css`), `packages/plugins/cradle-blocks/docs/blocks.md`,
  `packages/plugins/cradle-blocks/src/class-gutenberg-adapter.php`.
- `references/block-authoring.md` — plain-JS edit/save pattern, dynamic blocks, deprecations, patterns, templates.

## Preconditions

- Supported runtime confirmed (sandbox WordPress; `Requires at least: 7.1`); block API version 3 is used
  by current blocks — verify against the runtime before changing it.
- No JSX, no build step, no bundled React, no npm runtime packages. Use `window.wp.*` globals
  (`wp.blocks`, `wp.element`, `wp.blockEditor`, `wp.components`, `wp.data`, `wp.i18n`, `wp.serverSideRender`)
  declared as dependencies in `editor.asset.php`.
- Before changing an existing block: collect stored usages (`$core->components()->find_usages( $id )` or
  `$core->inventory()->count_block_usage( '<name>' )`) and keep a fixture of the current saved markup.

## Procedure

1. Pick the block model: static (markup saved in `post_content`), dynamic (PHP render; saved fallback
   optional), or container (`InnerBlocks` for genuine nested editable content). Component-backed blocks
   are dynamic through the component render service.
2. Write `blocks/<slug>/block.json`: `$schema`, `apiVersion: 3`, namespaced `name` (immutable once
   published), `version`, `title`, `category`, `textdomain`, typed `attributes` with defaults/enums,
   `supports` (native color/spacing/typography/anchor/align instead of custom controls where possible),
   `editorScript: "file:./editor.js"`, `style`/`editorStyle`/`viewScript` handles or files, `example`.
3. Register server-side from metadata inside the module's `boot`:
   - component-backed: pass `'blockDir' => __DIR__ . '/blocks/<slug>'` to `register_from_file()`; the
     cradle-blocks adapter registers the block with a render callback that maps attributes to props;
   - otherwise `register_block_type( __DIR__ . '/blocks/<slug>' )` (+ `render` file or `render_callback`).
4. Write `editor.js` in plain JS with `wp.element.createElement`: `useBlockProps()` in edit,
   `useBlockProps.save()` in save, RichText with limited `allowedFormats`, MediaUpload storing attachment
   IDs, InspectorControls for variants/options, `__()` strings in the block text domain.
5. Styles: frontend CSS via `style`, editor-only via `editorStyle`; declare every style in block.json so it
   loads inside the iframed editor. Scope classes to `wp-block-<namespace>-<slug>`; use theme.json presets.
6. Saved content is a public contract. Before changing `save()` output or attribute sources, add a
   `deprecated` entry reproducing the previous attributes/supports/save (with `migrate` when attributes
   change). Update any PHP provisioning helper that mirrors `save()` (for example
   `Cradle\Blocks\Feature_Card::block_markup()`).
7. Patterns: child `patterns/<slug>.php` (header: Title, Slug, Categories, optional Block Types/Inserter);
   use `esc_html_e()`/`esc_attr_e()` for copy; reference media by attachment ID where practical.
8. Templates/parts: child `templates/*.html`, `parts/*.html` (no subfolders in `parts/`). File edits do not
   override user-customized DB templates or Global Styles; diagnose the effective source and never reset
   user data to make a file change appear (§9.2).
9. Sync and test in the sandbox: `php scripts/sync.php <package>` (automatic via the hook), then
   `http://localhost/cradle/wp/wp-admin/`.

## Verification

- Insert, fill every control, save, reload the editor: no "unexpected or invalid content" warning; all
  claimed attributes persist; frontend matches the editor closely (CR-08).
- Undo/redo, duplicate, move up/down/drag, copy/paste, keyboard navigation, revisions and preview behave natively.
- Old-fixture content (previous `save()` markup) opens without a warning and upgrades on save.
- Several instances on one page; multiple blocks in one post (CR-09).
- Dynamic block with its plugin deactivated (own package only in the shared sandbox): saved fallback or
  documented dependency.
- Console free of block validation errors (browser devtools/console reader).
- Where editor automation is unavailable, record these checks as manual steps with results, or not run.

## Required output

Block names and files, model (static/dynamic/container), attributes and supports, deprecations added,
pattern/template changes, persistence evidence, not-run checks.

## Failure behavior

- Invalid-block warning after a change: stop; add the missing deprecation or restore the previous
  `save()`; never "resolve" by converting user content to HTML.
- A required editor package is unavailable in the runtime: document and choose a supported alternative.
- Template changes invisible because a DB-saved template exists: report the override source and offer
  export/reconcile; do not delete it.

## References

- `references/block-authoring.md`
- `packages/plugins/cradle-blocks/docs/blocks.md`; `northstar.md` §7.5, §9; acceptance CR-08, CR-09, CR-10
- WordPress block metadata [R9], deprecation [R11] references (northstar §27)
