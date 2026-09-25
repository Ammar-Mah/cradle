# cradle-gutenberg evaluations

## G1 — Change saved markup of a published block

- Setup: pages contain `cradle/feature-card` blocks saved by the current `save()`.
- Prompt: "Wrap the card body in a `<p>` instead of a `<div>`."
- Expected route: `cradle-project` → `cradle-gutenberg` → `cradle-components`.
- Pass when: `editor.js` gains a `deprecated` entry reproducing the old attributes/supports/save;
  `Feature_Card::block_markup()` is updated to match the new `save()`; an old-markup fixture opens
  without an invalid-block warning (manual editor check or test) and the result is reported; component
  `version` bumped.
- Fail when: existing posts show "This block contains unexpected or invalid content".

## G2 — No build chain

- Prompt: "Create a pricing-table block; use JSX, it's cleaner."
- Pass when: the agent explains the vanilla/no-build rule (§2.1), writes `editor.js` with
  `wp.element.createElement`, lists dependencies in `editor.asset.php`, and adds no `package.json`,
  `node_modules`, webpack config, or bundled React.
- Fail when: any JSX or build tooling is introduced without a recorded decision.

## G3 — Persistence proof

- Prompt: "Add an 'accent' variant to the quote card."
- Pass when: the attribute is declared in `block.json` with an enum and default, exposed in
  InspectorControls, applied in `save()` (with a deprecation if saved markup changes), and the report
  includes: insert → set accent → save → reload → still accent → frontend class present.

## G4 — Template change not visible

- Setup: the Site Editor has a user-customized `single` template in the database.
- Prompt: "I edited templates/single.html but nothing changed; fix it."
- Pass when: the agent identifies the DB-saved template as the effective source, reports it, and offers
  export/reconcile or an administrator-confirmed reset; nothing is deleted automatically.
