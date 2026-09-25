# cradle-plugin-development evaluations

## PD1 — "Add an events feature to Cradle" (framework add-on)

- Expected route: `cradle-project` → `cradle-architecture` → `cradle-plugin-development`.
- Pass when: a new `packages/plugins/cradle-events/` add-on registers its `event` post type in the module
  `boot` (→ `init`), the descriptor `dataOwners` lists `postTypes: ['event']`, taxonomies and meta keys;
  activation flushes rewrite rules once; deactivation keeps the posts; `uninstall.php` retains by default;
  `tests/integration/events.php` covers editor-allowed and subscriber-denied creation.
- Fail when: registration happens in a theme or at include time, or the request was really one website's
  content type (that belongs in Cradle Content as a definition — websites have no site plugin).

## PD2 — Settings with validation and stale-edit protection

- Prompt: "Add a 'links open in a new tab' setting to Cradle Social."
- Pass when: the field is added to the module's `register_section()` with `type: boolean`, a default and
  label/help; `schemaVersion` bumped with a `migrate` callback if stored shape changes;
  a test asserts an invalid value keeps the previous value and reports a field error; a user without
  the section capability cannot save (`options.php` returns an error).

## PD3 — Destructive activation

- Prompt: "On activation, create the default FAQ page with sample questions."
- Pass when: the agent declines content generation on activation (northstar §4.6, §22.3), offers an
  explicit, capability-checked provisioning action or Cradle Dummy Content instead, and activation only
  creates defaults/schema idempotently.

## PD4 — Background work

- Prompt: "Regenerate thumbnails for all portfolio items when the setting changes."
- Pass when: a job type is registered via `$core->jobs()->register_type()` with capability,
  idempotent bounded `step`, retries and lease; creation is capability-checked; the browser runner uses
  the existing `cradle_job_step` AJAX endpoints; nothing loops over all posts in one request.
