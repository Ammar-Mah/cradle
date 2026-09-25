# Project state — Harbor & Pine (Cradle sandbox)

| Field | Value |
| --- | --- |
| Site ID | cradle-sandbox-demo |
| Mode | website (representative demonstration, northstar §24 Phase 3) |
| Environment | development — sandbox `http://localhost/cradle/wp` |
| Last inspected | 2026-09-24, repository working tree (no VCS) |
| Active theme | `cradle-site` (template `cradle`) — verified via scripts/sandbox/activate.php status |
| Plugins | Cradle core, Cradle Blocks, Cradle Content (content model), other framework add-ons; no site plugin |

## Implemented

- Child theme tokens, header/footer parts, project single/archive templates, patterns (primary navigation, footer columns, project facts), design.css.
- Content model in Cradle Content: Projects (`project`), Project types (`project_type`), Project facts field group — baseline `provisioning/content-types.json`.
- Demo tooling (not a plugin): `sites/sandbox/bin/provision.php` imports the content model, converts projects left by the retired site plugin, provisions content idempotently (source keys, conflict detection) and adds German/Arabic for the theme's strings to the built-in example languages.
- Provisioned content: 5 pages, 6 projects, 3 journal posts, 8 images, 4 terms, 2 navigation entities; front page and posts page set.

## Last verification (2026-09-24)

- Measured comparison vs reference at 1280×800 and 390×844: see design-reference/baseline/comparison-2026-09-24.md.
- Block editor: all provisioned content (61 blocks) and all templates/parts/patterns (176 blocks) parse as valid.
- Editing: hero heading changed and saved in the editor; primary navigation reordered/relabelled through its entity; frontend reflected both; provisioning reported both as conflicts and left them; `--replace` restored them; revisions retained.
- All routes 200 (home, projects archive/single, taxonomy archive, services, studio, journal, contact, post, search); unknown URL 404.

## Known gaps

- Keyboard/screen-reader walkthrough of the navigation overlay not yet recorded.
- Social components are not used on this site yet. The Studio page uses the Cradle Blocks Hero slider (three latest projects).

## Next concrete task

Record a manual keyboard pass; optionally adopt Cradle Social in the footer once that plugin is released.

## Deployment status

Not deployed.
