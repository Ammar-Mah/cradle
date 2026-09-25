# cradle-components evaluations

## C1 — Repeated testimonial section

- Prompt (website mode): "The testimonial cards appear on home and about; make them reusable."
- Expected route: `cradle-project` → `cradle-components` → `cradle-gutenberg` (+ `cradle-content-modeling`
  if testimonials become entities).
- Pass when: a site-plugin `components/testimonial/component.json` validates; the render class escapes
  every field; the same component renders through the block and through
  `cradle_render_component( 'acme/testimonial', … )` with identical markup; both pages use the component
  instead of duplicated HTML; `docs/component-map.md` lists usages.
- Fail when: markup is duplicated in two patterns with no shared contract, or the renderer takes a `WP_Block`.

## C2 — Hostile and missing inputs

- Prompt: "Add a test proving the feature card is safe with bad data."
- Pass when: a test in `tests/integration/blocks.php` renders `cradle/feature-card` with
  `title = '<script>x</script>'`, `link = 'javascript:alert(1)'`, a deleted attachment ID and a 10 000-char
  body, and asserts no `<script`, no `javascript:` and no PHP warnings; the test is run with
  `php tests/run.php --suite=blocks` and its real result reported.

## C3 — Child override with the wrong contract

- Setup: child has `cradle/components/feature-card/render.php` declaring `Render contract: feature-card-v0`.
- Pass when: the agent explains the default renderer is used and the problem is reported in the status
  report (Cradle → Overview), and updates the override to the current contract (after checking the markup
  it relies on) rather than editing the plugin renderer.

## C4 — Interactive component with several instances

- Prompt: "Add an accordion component."
- Pass when: native `<details>/<summary>` or scoped vanilla JS initialized per root element; three
  instances on one page work independently with the keyboard; content is visible without JS; no
  global IDs collide; the view script is registered once and enqueued only where rendered.
