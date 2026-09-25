---
name: cradle-components
description: Use when creating or changing a reusable Cradle component — cards, lists, sliders, hero, social links, interactive sections — its component.json descriptor, PHP render service, frontend assets, child-theme render override, or its shortcode/PHP rendering path. Also use when a section appears more than once or must render outside Gutenberg. Produces a builder-neutral contract and an escaped render implementation that Gutenberg and plain PHP share.
---

# Cradle components (builder-neutral)

## Required context

- `cradle-project` orientation; `docs/component-map.md` row(s) for the section (website mode).
- `docs/architecture/core-api.md` §6 (Components), `docs/architecture/components.md` (descriptor schema) and the validator in
  `packages/plugins/cradle/src/class-components.php` (`validate_descriptor()`, `normalize_value()`,
  `render()`, `resolve_override()`) — the validator is the source of truth for the descriptor schema.
- `northstar.md` §7 (layers, descriptor, rendering and assets, adapter, discovery).
- Examples: `packages/plugins/cradle-blocks/components/feature-card/component.json` and
  `packages/plugins/cradle-blocks/src/class-feature-card.php`; shortcode adapter
  `packages/plugins/cradle-blocks/src/class-shortcode-adapter.php` (`[cradle_component id="…"]`).
- `references/component-contract.md` — descriptor fields, render rules, overrides, versioning.

## Preconditions

- Confirm a core block, style variation or pattern cannot meet the need (§7.1); record why a component is needed.
- Owner decided: generic → `packages/plugins/cradle-blocks/`; site-specific → the child theme's
  `components/<slug>/` (`component.json` + `render.php`, optional `blocks/<slug>/`; registered automatically by
  the parent theme, so the child needs no PHP); feature-specific → that add-on (e.g. `cradle-social`). Generic sections such as the hero slider belong in `cradle-blocks`, never in a plugin of their own.
- Existing stored usages identified before changing an existing component
  (`$core->components()->find_usages( $id )`).

## Procedure

1. Write `components/<slug>/component.json` (schemaVersion 1): `id` (`namespace/name`), semver `version`,
   `owner`, typed `properties` with defaults and bounds (`maxLength`, `minimum`, `maximum`, `enum`,
   formats `limited-rich-text`, `url`, `attachment`, `post`, `term`…), `slots`, `adapters`
   (`gutenberg.blockName`, `shortcode`), `assets` handles, `renderContract` (`<slug>-v1`),
   `themeOverride` (`cradle/components/<slug>/render.php`), `fallbackPolicy`, `cache`.
2. Implement the render callable `fn( array $props, array $context, array $component ): string` in
   `src/class-<slug>.php`. It receives normalized props (unknown keys dropped, deleted entities → 0)
   and a plain context (`adapter`, `post_id`, `inner_html`, `instance_id`, `entity_key`). No `WP_Block`,
   no globals from the editor. Resolve media with `wp_get_attachment_image()`; bounded queries only.
3. Escape by context: `esc_html`, `esc_attr`, `esc_url`, `wp_kses( …, Components::limited_rich_text_tags() )`,
   `wp_json_encode` for data attributes. Handle a missing image/post/term by omitting that part.
4. Register inside the module's `boot`:
   `$core->components()->register_from_file( __DIR__ . '/../components/<slug>/component.json', array( 'render' => array( Slug::class, 'render' ) ) );`
   (descriptor files must be inside a plugin package).
5. Assets: register style/view-script handles with `wp_register_style/script` (namespaced, versioned);
   list them in `assets` so `render()` enqueues them only where the component renders. View scripts:
   vanilla JS, initialize per root element (`[data-cradle-…]`) once, support several instances, keep
   content useful without JS, honor `prefers-reduced-motion`.
6. Editor adapter: add the block via `cradle-gutenberg`. For interactive or dynamic output decide the
   saved fallback (`saved-semantic-html`, `static-snapshot`, or `none`) and document plugin-deactivated behavior.
7. Child override (presentation only): `<child>/cradle/components/<slug>/render.php` with header
   `Render contract: <slug>-v1`; it receives `$props`, `$context`, `$component` and escapes everything.
   A mismatched contract is ignored and reported. Record overrides in `docs/component-map.md`.
8. Contract changes: bump `version`; a markup/prop change that breaks overrides bumps `renderContract`
   (`-v2`) and keeps stored attributes readable (migration or block deprecation).

## Verification

- Same props render identically through `cradle_render_component( '<id>', $props )`, the shortcode
  `[cradle_component id="<id>" …]` (when `adapters.shortcode` is true) and the block (CR-10).
- Two or more instances on one page: unique `instance_id`s, no duplicate DOM IDs, one script
  initialization each (CR-09).
- Missing/deleted attachment or post ID renders without warnings or broken markup.
- Hostile props (`<script>`, `javascript:` URL, over-long strings, wrong types) are dropped/escaped.
- Override with a wrong `Render contract:` is ignored and reported in the status report (Cradle → Overview → Generate status report).
- The usage inventory finds the component's stored instances.
- Add a test in `tests/integration/<package>.php` for normalization and escaping; run
  `php tests/run.php --suite=<package>`.

## Required output

Descriptor path, render class, handles, adapters, fallback policy, override contract, verification
results (including not run), component-map update.

## Failure behavior

- Validator rejects the descriptor: fix the descriptor; never bypass `register()`.
- A change would alter saved markup of existing instances: stop, add a deprecation/migration first.
- A needed interaction requires an unapproved library: implement vanilla or record the decision.

## References

- `references/component-contract.md`
- `docs/architecture/core-api.md` §6; `northstar.md` §7; acceptance CR-09, CR-10, CR-20, CR-22
- Related skills: `cradle-gutenberg`, `cradle-assets`, `cradle-security`, `cradle-quality`
