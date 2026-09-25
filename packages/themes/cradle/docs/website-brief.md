# Website brief (a child theme's `northstar.md`)

A website child theme ships an **empty** `northstar.md`. It belongs to the site owner: put your own brief
there (paste it in or replace the file), in any structure and language. Nothing in Cradle overwrites it.

Coding agents treat `northstar.md` as the source of truth for what to build. They read it as written,
compare it with the checklist below, and **ask** about anything missing or unclear; they do not rewrite
or restructure it unless the owner asks, and never invent a business, brand or content to fill a gap.
Answers and decisions are recorded in the child's `docs/decisions.md` and `docs/project-state.md`.

Supplied material (screenshots, exported HTML/CSS, logos, images, fonts with their licences) goes in the
child's `design-reference/` folder, unchanged; agents copy what the site uses into `assets/` and record it
in `docs/asset-map.md`.

## Checklist (what a complete brief covers)

Use it to check a brief, or copy it into an empty `northstar.md` as a starting point.

```markdown
# Website north star

## Identity
- Site name:
- Business or organization:
- Primary audience:
- Primary language and additional interface languages:
- Primary conversion or task:

## Purpose
Describe what this particular website must help visitors accomplish.

## Required pages
For each page: purpose, content sections, data sources, and desired action.

## Content model
List post types, taxonomies, fields, relationships, and who edits them.

## Visual direction
- Reference URLs or local reference folders:
- Screenshots and required viewport sizes:
- Typography:
- Colors:
- Spacing and layout:
- Motion and interaction:
- Elements that must match precisely:
- Permitted deviations:

## Editing expectations
State what editors must be able to change without source edits.
Identify locked structural parts and configurable component options.

## Navigation
List primary, footer, utility, and mobile navigation requirements.

## Functional requirements
List forms, search, accounts, commerce, integrations, and special behavior.
Do not assume unlisted business features are already provided by Cradle.

## Commerce when applicable
Product types, catalog structure, account flows, cart/checkout behavior,
shipping/payment requirements, and approved integrations.

## Content and assets
Approved copy, media folders, font sources, licenses, and missing materials.

## Technical constraints
Approved plugins and dependencies, hosting capabilities, environments,
supported versions, browser targets, and deployment policy.

## Acceptance criteria
Observable outcomes for design fidelity, editing, behavior, accessibility,
performance, security, and handover.

## Non-goals
Explicit exclusions and deferred requirements.

## Open decisions
Only unresolved choices that affect implementation.
```
