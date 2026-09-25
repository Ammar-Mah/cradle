# Cradle Blocks: components, adapters and provisioning

## Layers (northstar §7.2)

The hero slider has its own page: [hero-slider.md](hero-slider.md).

| Layer | cradle/feature-card | cradle/post-cards |
| --- | --- | --- |
| Domain contract | `components/feature-card/component.json` | `components/post-cards/component.json` |
| Data access | attachment reference (validated by core) | `Post_Cards::prepare()` + `query()` (bounded, public-only, primed thumbnails) |
| Render service | `Feature_Card::render()` — contract `feature-card-v1` | `Post_Cards::render()` — contract `post-cards-v1`, items rendered through `cradle/feature-card` |
| Presentation | `blocks/feature-card/style.css` | `blocks/post-cards/style.css` |
| Editor adapter | `blocks/feature-card/editor.js` (inline editing, inspector controls, saved fallback) | `blocks/post-cards/editor.js` (inspector + ServerSideRender) |
| Fallback when inactive | saved semantic HTML (`saved-semantic-html`) | nothing (`none`) — convert before removal |

## Surfaces using the same render service

- Block editor: `<!-- wp:cradle/feature-card {...} -->` (render_callback → core component service).
- Shortcode: `[cradle_component id="cradle/feature-card" title="Hello" body="Text" link="https://…" link-text="More"]`.
- PHP: `cradle_render_component( 'cradle/post-cards', array( 'postType' => 'page', 'count' => 2 ) )`.

## Child-theme presentation overrides

Copy nothing from this plugin. Create `<child>/cradle/components/feature-card/render.php` whose header
contains `Render contract: feature-card-v1`. The file receives `$props` (normalized), `$context` and
`$component`, must escape output, and must keep the root/element classes documented in `Feature_Card`.
When the contract version changes, a stale override is ignored and reported in the status report (Cradle → Overview → Generate status report).

## Saved markup and deprecations

`feature-card` stores attributes in the block comment and semantic markup produced by `save()`.
Programmatic provisioning must use `Cradle\Blocks\Feature_Card::block_markup( $attrs )`, which mirrors
`save()` exactly, so pages created in PHP open without invalid-block warnings. Any change to `save()`
requires: a `deprecated` entry in editor.js with the previous save, an update of `block_markup()`, and a
fixture test proving old content still opens.

## Static HTML export (API only)

`Cradle\Blocks\Gutenberg_Adapter::export_or_convert_instance( array( 'block' => $parsed_block, 'post_id' => $id ) )`
returns `array( 'html' => …, 'lost' => … )` for one stored instance. It is used by migration and
tooling code; there is no admin screen. Dynamic components (`cradle/post-cards`, `cradle/hero-slider`, social) render
nothing while their plugin is inactive, so keep the plugin active for as long as content uses them.
