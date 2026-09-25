# Cradle architecture overview

```
wp-content/
  themes/cradle/            Parent block theme (updateable, site-agnostic) +      ← packages/themes/cradle
                            child starter and bin/child-theme.php (create/check children)
  themes/<site>/            Active child theme: everything site-specific (brief,  ← sites/<site>/themes/<site>
                            state, design, site components, content baseline)
  plugins/cradle/           Core: registry, settings, capabilities, components,   ← packages/plugins/cradle
                            jobs/locks, storage, activity, inventory, diagnostics
  plugins/cradle-blocks/    Generic components (feature card, post cards, hero slider) + adapters
  plugins/cradle-<feature>/ login, admin, content, duplicate, translations, migration,
                            dummy-content, social
```

## Dependency rules

- Parent theme renders a valid site with no Cradle plugin active.
- Core does not require any theme.
- Add-ons declare `Requires Plugins: cradle` and check core at runtime; blocks-based features declare the module dependency `blocks`.
- A website has no site plugin: site content types live in Cradle Content (database definitions, baseline exported to the child theme); everything else site-specific lives in the child theme. If Cradle Content is inactive, its types are paused (URLs 404, content kept).

## Where things go

| Change | Package |
| --- | --- |
| Reusable service, contract, diagnostics | core |
| Generic component usable by any site | cradle-blocks |
| Feature (login, migration, …) | its feature plugin |
| Site design tokens, templates, parts, patterns, CSS, component overrides | child theme |
| Site components (`components/<slug>/`), `assets/css/{design,site}.css`, `assets/js/site.js` — loaded by the parent (`inc/child-theme.php`), no child PHP | child theme |
| Site content types, taxonomies, fields | Cradle Content definitions (baseline `content/content-types.json` in the child) |
| Creating/updating a child theme | parent theme `bin/child-theme.php` + `starter/child-theme/` |
| Administrator overrides (Global Styles, templates, navigation) | WordPress database (never reset silently) |

See `core-api.md` (contracts), `components.md` (component descriptor), `data-model.md` (storage),
`plugin-conventions.md` (package rules) and `../decisions.md`.
