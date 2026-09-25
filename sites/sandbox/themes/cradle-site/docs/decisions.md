# Decisions — Cradle Sandbox

Record durable decisions only: date, decision, reason, alternatives that mattered, affected packages.

| Date | Decision | Reason | Alternatives considered | Packages |
| --- | --- | --- | --- | --- |
| 2026-09-24 | Generated child theme `cradle-site` and site plugin `cradle-site` from the Cradle starters (site plugin retired the same day, see below). | Every Cradle site activates its own child and keeps lasting functionality in its site plugin. | — | theme, site plugin |
| 2026-09-24 | Retired the site plugin. Projects, Project types and Project facts are Cradle Content definitions (`project`, `project_type`, `project_facts`); provisioning moved to `sites/sandbox/bin/provision.php`; the navigation helper moved to the child theme. Existing projects were converted in place (IDs, terms, images kept). | Owner: avoid an extra plugin install and show every content type under Content types. | Keep the plugin and list its types read-only; move site code into core (rejected: core is shared by all sites and replaced on update). | child theme, sites/sandbox tooling |
