---
name: cradle-architecture
description: Use when deciding where Cradle code or data belongs, adding a shared core service, creating a new add-on or module, changing a core contract or API version, declaring dependencies between packages, or evaluating whether a new library, plugin, or upstream skill fits the dependency policy. Produces an ownership and contract decision, recorded in docs/decisions.md, with an implementation path that keeps site-specific work out of the parent theme and core.
---

# Cradle architecture and ownership

## Required context

- `cradle-project` orientation (mode, packages, state).
- `northstar.md` §2 (decisions, §2.1 vanilla-code rule), §3 (packages, dependencies, ownership, core
  services, module contract, data identity), §4.1–§4.4, §22.2–§22.3.
- `docs/architecture/core-api.md` (boot sequence, descriptor, services, settings, capabilities,
  components, jobs/locks, storage, uninstall) and `docs/architecture/plugin-conventions.md`.
- `scripts/packages.json` for the package inventory and which skills each package carries.

## Preconditions

- Read the existing contract and grep existing packages before inventing an abstraction:
  `packages/plugins/cradle/src/` provides Module_Registry, Settings, Capabilities, Components,
  Editor_Adapter, Jobs, Locks, Storage, Inventory, Activity, Health and Schema.
- Know the core API version (`CRADLE_API_VERSION` in `packages/plugins/cradle/cradle.php`) and each
  add-on's `coreApiRange`.
- Identify the data that already exists for the feature (options, post types, meta, tables, files).

## Procedure

1. Assign ownership (northstar §3.3):

   | Need | Owner (framework repository path) |
   | --- | --- |
   | Generic presentation defaults, fallback templates | Parent theme `packages/themes/cradle/` |
   | Everything site-specific, as data: brief, records, `theme.json`, templates/parts/patterns it must override, render overrides, `assets/`, site components (`components/<slug>/`) | Thin child theme (`sites/<site>/themes/<slug>/`, from the parent's starter) — no framework code, no site plugin; the parent loads its assets and components (parent `docs/child-themes.md`) |
   | Loading conventions for child files (styles, script, components) | Parent theme `inc/child-theme.php` — a contract; changing it needs an upgrade note |
   | Site content types, taxonomies, fields | Cradle Content definitions (Cradle → Content types; baseline `content/content-types.json` in the child) |
   | Generic reusable components and the Gutenberg adapter | `packages/plugins/cradle-blocks/` |
   | A reusable feature (login, admin, content, duplicate, translations, migration, dummy-content, social) | `packages/plugins/cradle-<feature>/` |
   | Cross-cutting service used by several add-ons | Core `packages/plugins/cradle/` |
   | Administrator overrides | Native Global Styles / DB templates (never overwritten from files) |

   Content types never live in a theme. Site-specific code never lives in the parent, core or a
   shared add-on.
2. Prefer native WordPress APIs and existing core services. Add a core service only when at least two
   packages need it; keep it a small class, not a framework (no DI container, ORM, template language
   or service bus).
3. For a new add-on, define the descriptor (core-api §2): stable `id`, `version`, `coreApiRange`,
   `pluginFile`, `dependencies` (other module IDs with ranges), `settingsSections`, `capabilities`
   (default roles minimal), `schemaVersion`, `dataOwners` (every option, post type, meta key, table,
   cron hook, upload dir, transient and file it owns), `boot`, and optional `export`/`import`/`cleanup`.
4. Dependencies: plugin header `Requires Plugins: cradle` (plus other add-on slugs when required),
   runtime guard when core is missing, registration only on `cradle/register_modules`, feature hooks
   only in `boot`. Never rely on plugin load order. The parent theme must work with no Cradle plugin
   active; core must not require a specific theme.
5. Extension points: namespaced hooks under `cradle/` (existing: `cradle/register_modules`,
   `cradle/ready`, `cradle/booted`, `cradle/component_html`; parent theme: `cradle_theme/setup`).
   Document payloads in the package `docs/`. Settings go through core Settings sections, not raw options.
6. Versioning: a stored-data change needs a `schemaVersion` bump and an idempotent migration; a core
   contract change needs an API version change and a migration note in `core-api.md`. Keep old data
   readable during the transition; no irreversible migration on a frontend request.
7. Dependency policy (§2.1): no new runtime library, jQuery-based code, CSS framework, Composer/npm
   runtime package, builder, ACF, translation or migration plugin. If a request or an upstream skill
   requires one, stop and record the decision needed; do not add it silently.
8. Record the decision in `docs/decisions.md` (date, reason, alternatives, affected packages) and update
   `docs/architecture/` when a contract changes.

## Verification

- Optional add-on removal: deactivate only the add-on being changed
  (`php scripts/sandbox/activate.php deactivate <slug>`), load `http://localhost/cradle/wp/` and
  `/wp-admin/` with no fatal, reactivate it (`… plugin <slug>`). The shared sandbox rules forbid
  deactivating core or other packages; run "core missing" and "no Cradle plugins" checks only on a
  disposable separate WordPress target, otherwise report them as not run.
- Static checks, runnable anywhere:
  - No `register_post_type`/`register_taxonomy` in `packages/themes/cradle/` or any child theme.
  - Parent templates, parts and patterns contain no `wp:cradle/` blocks and no unguarded `cradle_*()` calls.
  - `grep -rniE "jquery|bootstrap|tailwind|alpine|vue|swiper|gsap" packages/*/*/assets` finds no runtime use (CR-24).
- Module state on Cradle → Overview shows the add-on `ready`, and `failed` with a reason when a
  dependency is missing (CR-03).

## Required output

Decision summary (owner, contracts touched, hooks, settings, capabilities, data owners, schema/API
version, lifecycle), files changed, verification results with not-run items, and the
`docs/decisions.md` entry.

## Failure behavior

- Ownership unclear or a site-specific need would enter the parent/core: stop and propose the site
  plugin or child alternative.
- A dependency outside the policy is required: record the specific dependency and the decision needed;
  implement the vanilla alternative where reasonable; never install it silently.
- A contract change would break existing add-ons: keep the old contract working, add the new version
  alongside, and document the migration.

## References

- `docs/architecture/core-api.md`, `docs/architecture/plugin-conventions.md`, `scripts/packages.json`
- `northstar.md` §2–§4, §22; acceptance CR-01, CR-02, CR-03, CR-24, CR-25, CR-28
- Related skills: `cradle-plugin-development`, `cradle-components`, `cradle-release-cleanup`
