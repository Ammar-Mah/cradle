# Website child theme — working instructions

This folder is one website built on the Cradle parent theme. It holds only this site's data; the rules for
working on it ship with the parent and are updated with it:

1. Read `cradle-workspace.json` and resolve the parent theme path (`parentTheme`; inside the Cradle
   repository use `sourceLayout.parentTheme` and edit here, never the mirrored copy under `wp/wp-content`).
2. Read the parent's `docs/child-themes.md` and follow it (first use of a copied starter, what belongs in
   this folder, update safety, working rules).
3. Read `northstar.md` — the owner's website brief, in any structure. If it is empty, ask the owner for their
   brief; the parent's `docs/website-brief.md` lists what a brief covers. Do not rewrite the owner's brief.
4. Read `docs/project-state.md` and `docs/decisions.md`; supplied material is in `design-reference/`.
5. Read the parent's `skills/INDEX.md`; load `cradle-project` and the task-relevant skills.

Keep this child thin: no framework code, no copies of parent files, no site plugin.
