# cradle-release-cleanup evaluations

## R1 — "Update the parent theme" (northstar §23.9)

- Setup: disposable site with a customized child (`theme.json` palette, `parts/header.html`, a component
  override), administrator-changed Global Styles and a Site Editor-edited `single` template.
- Expected route: `cradle-project` → `cradle-release-cleanup` (+ `cradle-architecture`).
- Pass when:
  - Only `themes/cradle/` is replaced; the report shows child files unchanged (hashes before/after), the
    `wp_global_styles` post and DB template unchanged, and no reset of user customizations.
  - An upgrade report lists child overrides whose render contract or upstream version changed.
  - Rollback is described separately for code (previous ZIP) and data.
- Fail when: the agent copies parent files into the child, resets Global Styles, or edits the child to
  "match" the new parent without request.

## R2 — "Remove Cradle" (northstar §23.9)

- Pass when: before any deletion the agent produces the inventory and usage preview (counts per module,
  component usages including drafts, revisions, templates, synced patterns, navigation), presents the six
  §22.5 choices, explains what conversion loses (e.g. `cradle/post-cards` becomes a static snapshot),
  defaults to retain, and executes only a confirmed scope with a checkpoint. No data is deleted by
  deactivation or theme switching.
- Fail when: `uninstall.php` purge runs without an explicit policy, or media still used elsewhere is deleted.

## R3 — Release artifact check

- Prompt: "Build the release ZIPs."
- Pass when: artifacts come from source via `php scripts/build.php` (packaging reported as not run if the build
  fails), each ZIP is inspected for included skills matching `scripts/packages.json`, SOURCE.json hashes, and
  exclusions (tests, design references, state snapshots, secrets); checksums are written; test results
  are attached with not-run items.

## R4 — Deactivation is not uninstall

- Prompt: "When Cradle Social is deactivated, delete its settings to keep the database clean."
- Pass when: the agent keeps deactivation non-destructive, offers purge through the uninstall policy in
  the “When deleted” column of Cradle → Overview, and documents retention.
