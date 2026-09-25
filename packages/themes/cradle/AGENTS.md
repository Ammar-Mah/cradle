# Cradle parent theme — working instructions

This is the updateable **framework** parent theme. It holds unopinionated, reusable presentation
infrastructure only. Never put a particular website's design, content or brand here.

## Begin every task

1. Decide the mode.
   - **Framework mode** (changing Cradle itself): read `northstar.md` (the Cradle product spec shipped
     beside this file), `skills/INDEX.md`, and `docs/extension-points.md`.
   - **Website mode** (building a site): do not edit this theme. Locate the active child theme
     (the theme whose `style.css` declares `Template: cradle` and that contains `cradle-workspace.json`),
     then follow that child's `AGENTS.md` (it points to this theme's `docs/child-themes.md`), `northstar.md`
     and `docs/project-state.md`.
     If several children exist, ask which project is active instead of guessing.
2. Load `cradle-project` and the task-relevant skills from `skills/INDEX.md`.
3. Inspect current source and the runtime before relying on saved notes.

## Creating and maintaining website child themes

A Cradle website is a child theme of this parent; everything site-specific lives in it and there is no
site plugin (content types are Cradle Content definitions). This theme ships the starter and the tool:

| Path | Purpose |
| --- | --- |
| `starter/child-theme/` | Thin starter copied into every new child: `style.css`, `theme.json`, an empty `northstar.md` (the owner's brief goes there), `docs/` records, `cradle-workspace.json`, and an `AGENTS.md` that points to `docs/child-themes.md`. No PHP or JS. Files carry `{{PLACEHOLDERS}}` filled by the generator (the kit's `cradle-child` is pre-filled). |
| `inc/child-theme.php` | The child contract: loads a child's `assets/css/design.css`, `assets/css/site.css`, `assets/js/site.js` and `components/<slug>/` (+ `blocks/<slug>/`) so children need no PHP. |
| `docs/child-themes.md` | Rules for website work (what a child contains, update safety, working rules); every child's `AGENTS.md` points here. |
| `docs/website-brief.md` | What a website brief covers (checklist and copyable outline); agents check the owner's `northstar.md` against it. |
| `bin/child-theme.php new --slug=<slug> --name="<Site Name>"` | Creates `<themes dir>/<slug>` (or `sites/<slug>/themes/<slug>` in the Cradle repository). Refuses to overwrite. |
| `bin/child-theme.php check --child=<dir> [--add-missing]` | After a parent update, lists starter files a child lacks and optionally adds them; never changes existing files. |

Workflow for a new website: copy the kit (or upload the child ZIP, or run `new`) → write the brief in the
child's `northstar.md` (plus reference material in `design-reference/`) → activate it → build with the
`cradle-design-to-wordpress` skill.

Children must stay thin: never move reusable behavior into the starter; add a convention to
`inc/child-theme.php` instead. The conventions are a contract — renaming or removing one needs an upgrade note
and must keep the old path working for at least one release.

## Rules for this package

- Keep the theme valid and usable with no Cradle plugin active.
- Tokens belong in `theme.json`; CSS in `assets/css/` stays structural and uses logical properties.
- Templates use core blocks; translatable template strings live in PHP patterns (`patterns/`).
- `functions.php` only requires small single-purpose files in `inc/`; customization happens through
  hooks (`cradle_theme/setup`), child templates, child `theme.json` and component overrides.
- Never reset Global Styles or database templates to make file changes appear.
- `skills/` and `northstar.md` in a built/mirrored copy are generated from the repository; edit the canonical sources.
