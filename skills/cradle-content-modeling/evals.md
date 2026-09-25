# cradle-content-modeling evaluations

## M1 — "Create a portfolio type" (northstar §23.9)

- Setup: website mode, sandbox child `sites/sandbox/themes/cradle-site` (content model in Cradle Content;
  Cradle sites have no site plugin).
- Expected route: `cradle-project` → `cradle-content-modeling` (+ `cradle-gutenberg` for templates).
- Pass when:
  - `portfolio` (and any taxonomy and field group) is a Cradle Content definition created through
    `Definitions::save()`/the Content types screen or a JSON import, with REST, labels, rewrite, supports and
    capability model set; the export is saved to the child's `content/content-types.json`.
  - No `register_post_type`/`register_taxonomy`/`register_post_meta` in any theme file and no site plugin.
  - Fields are a Cradle Content field group (validated types; exposure `public` only where templates bind them).
  - Child templates `single-portfolio.html`/`archive-portfolio.html` bound to the current query.
  - `docs/content-model.md` documents the model; rewrite rules flushed on activation, not per request.
  - Evidence: editor creates an item that appears on the archive; a subscriber gets 403 creating one via
    `POST /wp-json/wp/v2/portfolio`.
- Fail when: the type is registered in code by the theme, or a site plugin is created for it.

## M2 — Rename a post type key

- Prompt: "Rename the `project` post type to `case_study`."
- Pass when: the agent produces a preview (post, meta, term relationship counts; templates and Query
  Loop blocks referencing `project`), asks for confirmation, and proposes an idempotent batch migration
  with a checkpoint; labels-only alternative is offered; nothing is renamed silently.

## M3 — Relationship stored as text

- Prompt: "Add a field listing the related services by name."
- Pass when: the relationship is stored as validated post IDs (registered array meta with item schema,
  allowed type check, bound) or a taxonomy, not free text; the render path resolves IDs and tolerates
  deleted posts.

## M4 — Collision with WooCommerce

- Prompt: "Create a post type called product for our catalog."
- Pass when: the agent refuses to register `product` (WooCommerce owns it), explains the conflict, and
  proposes either WooCommerce products (`cradle-woocommerce`) or a distinct key such as `catalog_item`.
