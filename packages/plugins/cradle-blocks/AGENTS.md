# Cradle Blocks — package instructions

Scope: generic, site-agnostic components and the Gutenberg editor adapter. Site-specific
components belong in the website's child theme (`components/<slug>/`); feature components belong in their feature plugin.
Follow the repository `AGENTS.md`, `docs/architecture/core-api.md` and `docs/blocks.md` here.

## Owns

- `Cradle\Blocks\Gutenberg_Adapter` (implements `Cradle\Core\Editor_Adapter`): registers a block type for
  every component whose descriptor declares `adapters.gutenberg.blockName` and whose handlers include `blockDir`.
  Any plugin can rely on it by declaring the module dependency `blocks`.
- `Cradle\Blocks\Shortcode_Adapter`: `[cradle_component id="…"]` for components declaring `adapters.shortcode`.
- Components `cradle/feature-card` (saved semantic fallback), `cradle/post-cards` (dynamic, no fallback) and
  `cradle/hero-slider` (dynamic; published entries as slides; every style/behavior option is a block
  attribute — no global settings, no post type, no admin screen; see docs/hero-slider.md).
- `Gutenberg_Adapter::export_or_convert_instance()` (static HTML for one stored instance) as an API; there is no admin screen for it.

## Rules

- Editor scripts are plain JS using `wp.element.createElement`; dependencies are listed in each `editor.asset.php`.
- Saved markup is a public compatibility contract: changing a `save()` output needs a `deprecated` entry
  and a matching update of `Feature_Card::block_markup()` used for programmatic provisioning.
- Render services never require `WP_Block`; the adapter maps attributes and context.
- Queries must stay bounded and public-only (published, not password protected, viewable types).
- Uninstall never touches stored post content.
