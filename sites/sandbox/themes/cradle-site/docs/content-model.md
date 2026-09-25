# Content model — Harbor & Pine (Cradle sandbox)

| Entity | Kind | Key | Owner package | Fields | Relationships | Editors | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Project | post type (public, REST, archive `/projects/`, single `/projects/<slug>/`) | `project` | Cradle Content (database definition; baseline in `sites/sandbox/provisioning/content-types.json`) | title, content, excerpt, featured image, revisions | `project_type` terms | administrators, editors (post capabilities) | Edited under Cradle → Content types. Paused (not deleted) while Cradle core or Content is inactive |
| Project facts | Cradle Content field group `project_facts` (public exposure) | fields `client`, `location` (text ≤120), `year` (integer 1900–2100); meta `_cradle_cf_<key>` | Cradle Content | — | — | same as project | Block editor sidebar panel; bound in the `project-facts` pattern via `cradle-content/field` |
| Project type | taxonomy (hierarchical, public, REST, `/project-type/<slug>/`) | `project_type` | Cradle Content (database definition) | name, slug, description | projects | editors | Terms Residential, Workplace, Restoration |
| Journal article | post | `post` in category `journal` | WordPress core | — | category | editors, authors | — |
| Primary / Footer menus | navigation entities | `wp_navigation` "Primary", "Footer" | database (created by provisioning) | items, order, labels | pages, archive | administrators | Located by source keys `navigation:primary`, `navigation:footer` |
| Provisioning registry | option | `cradle_site_provisioning` | demo tooling (`sites/sandbox/bin/provision.php`) | source key → ID, content hash | all provisioned records | CLI only | Records carry `_cradle_source_key`; the child theme's navigation patterns read it |
