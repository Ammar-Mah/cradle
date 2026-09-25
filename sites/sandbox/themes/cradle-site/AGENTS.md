# Website child theme — working instructions

This folder is one website (the Harbor & Pine demo, Cradle's representative site) built on the Cradle
parent theme. It holds only this site's data; the rules for working on it ship with the parent and are
updated with it:

1. Read `cradle-workspace.json` and resolve the parent theme path (`parentTheme`; inside the Cradle
   repository use `sourceLayout.parentTheme` and edit here, never the mirrored copy under `wp/wp-content`).
2. Read the parent's `docs/child-themes.md` and follow it (what belongs in this folder, update safety,
   working rules).
3. Read `northstar.md` (the website brief), `docs/project-state.md` and `docs/decisions.md`.
4. Read the parent's `skills/INDEX.md`; load `cradle-project` and the task-relevant skills.

Keep this child thin: no framework code, no copies of parent files, no site plugin. Demo pages, projects,
menus and translations are provisioned by `sites/sandbox/bin/provision.php` (repository only);
`functions.php` holds only the demo's entity lookup used by its navigation patterns.
