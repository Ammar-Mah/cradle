---
name: cradle-release-cleanup
description: Use when building or releasing Cradle packages, bumping versions, updating the parent theme or a Cradle plugin on an existing site, checking stale child overrides, switching themes, uninstalling or deactivating packages, setting retain/purge policy, or handling "remove Cradle" / cleanup of owned data. Produces verified package artifacts, an update and rollback plan, and a usage preview with export/conversion choices before any scoped removal.
---

# Cradle release, update, and cleanup

## Required context

- `cradle-project` orientation; `scripts/packages.json` (package inventory, skills per package, excludes),
  `scripts/sync.php` and `scripts/lib/packages.php` (injection of skills and briefs); `dist/` is generated only.
- `northstar.md` §4.1, §4.6, §22 (update policy, child customization contract, lifecycle, cleanup
  inventory, removal choices), §26.1–§26.2, §23.3 (provenance).
- `docs/architecture/core-api.md` §8 (inventory) and §10 (uninstall policy).
- `references/update-and-removal.md` — release checklist, update test, removal workflow.

## Preconditions

- Versions: each package has its own `Version` header and constant; the compatibility manifest records
  tested versions only after tests ran.
- Every owned data item is declared in module `dataOwners` so inventory and uninstall can find it.
- Destructive steps (purge, conversion, theme deletion) run only on explicit, scoped administrator
  choice — never as a side effect of deactivation or theme switching.
- Shared sandbox rules: no theme switching, no deactivating other packages; use a disposable target for
  update-over-customized-site, theme-switch and uninstall tests.

## Procedure

1. Release build: build artifacts from source only with `php scripts/build.php` (writes `dist/` ZIPs,
   `manifest.json` and `SHA256SUMS`; never edit `dist/` by hand). Verify each ZIP: correct top folder (`cradle` theme vs `cradle` plugin
   are distinct), headers and versions, included skills match `scripts/packages.json`, `skills/SOURCE.json`
   hashes match the canonical `skills/`, excluded development files absent (tests, design references,
   private briefs, state snapshots, `.git`, `node_modules`), no secrets, checksums written.
2. Attribution: GPL-2.0-or-later headers; third-party notices; `docs/research/skill-sources.md` and
   `skills/upstream-lock.json` (only if upstream material was copied) are current (CR-31).
3. Update plan: list migrations per package, their idempotency, data readable during transition,
   incompatible changes and operator steps; distinguish code rollback (previous ZIP) from data rollback
   (checkpoint/backup) — a code rollback does not reverse a migration.
4. Child protection: a parent update replaces only `themes/cradle/`; child files, Cradle Content definitions, uploads,
   user Global Styles (`wp_global_styles`) and DB templates are untouched. Produce an upgrade report of
   child overrides whose tracked contract or upstream version changed (component overrides with
   `Render contract:`; WooCommerce template `@version`; overridden parent template parts).
5. Removal ("remove Cradle", uninstall a package): run the inventory
   (`$core->inventory()->scan( $module )`, `count_block_usage()`; data policy in the “When deleted” column of Cradle → Overview), including
   drafts, revisions, templates, template parts, synced patterns, navigation; present the §22.5 choices:
   keep and deactivate, export, convert components to native blocks/static HTML (developer step through
   `export_or_convert_instance()`; show what loses dynamic behavior), reassign, remove generated content, purge selected owned
   configuration. Persist the purge choice in `cradle_uninstall_policy`; default retain.
6. Execute only the confirmed scope, re-validating ownership, paths and IDs at deletion time; keep a report.

## Verification

- Clean install from the built ZIPs on a disposable target activates child + core + add-ons (CR-01, CR-03).
- Upgrade fixture: customized child + Cradle Content definitions + administrator Global Styles and an edited template;
  install the new parent/plugins; all customizations and content remain; migrations ran once (CR-02, CR-28).
- Missing dependency after update: add-on shows a notice, no fatal (disposable target).
- Theme switch keeps content and plugin data (disposable target).
- Retain uninstall leaves data discoverable in the inventory; scoped purge removes exactly the selected
  owners; shared media still used elsewhere is kept (CR-29).
- Recovery documentation exists for each incompatible change (CR-32).

## Required output

Artifacts with versions and checksums, inclusion/exclusion check, migration and rollback plan, stale
override report, removal preview (counts per owner and usage), chosen actions, executed scope, test
results and not-run items.

## Failure behavior

- Artifact contains a secret or development-only file: stop the release and fix the build input.
- Upgrade test changes child or user data: block the release.
- Removal preview incomplete (unknown owner, failed scan): do not delete; report the gap.

## References

- `references/update-and-removal.md`
- `northstar.md` §22, §26; acceptance CR-01, CR-02, CR-03, CR-28, CR-29, CR-31, CR-32
- WordPress uninstall methods [R26] (northstar §27)
