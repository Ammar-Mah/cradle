# Update and removal reference

Source: northstar §22.1–§22.5, §26.1, §4.6; `docs/architecture/core-api.md` §8, §10.

## Release checklist

- [ ] Version bumped in header and constant for each changed package; changelog and upgrade notes written.
- [ ] `compatibility.json` (when present) lists supported ranges; tested versions only after tests ran.
- [ ] Artifacts built from source into `dist/` (never edited by hand); one ZIP per package; checksums.
- [ ] ZIP top-level folder names: `cradle` (theme), `cradle` (plugin), `cradle-<feature>`, starters.
- [ ] Skills injected per `scripts/packages.json`; `skills/SOURCE.json` hashes equal canonical files.
- [ ] Excluded: tests, `design-reference/`, private briefs and state snapshots unless intentionally
      public-safe, `.git`, `node_modules`, logs, IDE folders.
- [ ] No secrets, sandbox URLs with credentials, or customer data.
- [ ] License headers and third-party notices; upstream skill provenance recorded.
- [ ] `php -l` over all PHP; `php tests/run.php` results attached; security surface reviewed
      (`php skills/cradle-security/scripts/check-surface.php packages/plugins/<pkg>`).
- [ ] No unresolved critical/high issue in the release scope.

## Update over a customized site (disposable target)

1. Install the previous release: parent, child (customized: `theme.json` tokens, a template part, a
   component override), core, add-ons, Cradle Content definitions with content.
2. As administrator: change Global Styles, edit a template in the Site Editor, edit a page, set plugin options.
3. Snapshot: file hashes of the child theme, the `cradle_content_definitions` option, `wp_global_styles`/`wp_template`/`wp_template_part`
   posts, options owned by Cradle.
4. Install the new packages through the normal plugin/theme update or upload flow.
5. Compare snapshot: child/site-plugin files identical; user styles/templates identical; options migrated
   only as documented; module states `ready`.
6. Run migrations twice (reload admin) → no change the second time.
7. Upgrade report: stale child overrides (render contract mismatch listed on Cradle → Components &
   cleanup), WooCommerce outdated templates, overridden parent parts whose parent version changed.

## Lifecycle expectations

| Event | Expected |
| --- | --- |
| Plugin deactivation | Stops scheduled work, releases locks; data retained. |
| Theme switch | Content, plugins and data preserved; presentation dependencies reported. |
| Theme deletion | No inferred permission to delete posts, media or plugin data. |
| Plugin deletion | Follows `cradle_uninstall_policy[<id>]`; default retain; retained data stays visible in inventory. |
| Explicit cleanup | Preview owned objects and usages → export/convert as chosen → remove only the selected scope. |

## Removal workflow ("remove Cradle")

1. Inventory per module (`$core->inventory()->scan( $id )`): options, post types and counts, taxonomies,
   meta keys, tables, cron hooks, upload dirs, transients, files, capabilities, rewrite rules.
2. Usage scan for each component/block (`count_block_usage( 'cradle/<name>' )`) across posts, pages,
   drafts, revisions, templates, template parts, synced patterns (`wp_block`), navigation.
3. Present choices (§22.5):
   1. Keep data, deactivate presentation/functionality.
   2. Export definitions and content (module `export` handlers, settings JSON export, content export).
   3. Convert components to native blocks or static semantic HTML (developer step through the cradle-blocks
      `export_or_convert_instance()` API; list what loses dynamic behavior — a live query becomes a snapshot).
   4. Reassign entities or fields (e.g. move a CPT's posts to `post` with terms).
   5. Remove selected generated/demo content (Dummy Content cleanup by batch).
   6. Purge selected owned configuration and files (sets `cradle_uninstall_policy[<id>] = 'purge'`).
4. Confirm scope with the administrator; create a checkpoint (Cradle Migration pre-change backup when available).
5. Execute; re-check ownership and paths at deletion time; keep shared media referenced elsewhere.
6. Report what remains (retained data, converted content, manual steps).

## Rollback

- Code: reinstall the previous package ZIP.
- Data: restore the pre-change checkpoint; migrations are not reversed by code rollback.
- Document each incompatible change's recovery steps in the package `docs/` and changelog.
