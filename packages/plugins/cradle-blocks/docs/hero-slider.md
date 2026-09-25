# Hero slider (`cradle/hero-slider`)

A generic Cradle Blocks component (not a separate plugin and not part of any theme). Place the **Hero
slider** block on any page, template or pattern. Its slides are **published entries** of one content
type; every style and behavior option is a block setting chosen by the page editor. There are no global
hero settings, no hero post type and no hero admin screen.

## Slides

| Setting | Behavior |
| --- | --- |
| Content type | Any viewable post type (posts, pages, projects, …); attachments excluded. |
| Slides: *Latest entries* | The newest (or by title / manual order / last modified, ascending or descending) published entries, 1–12, optionally only one term of a public taxonomy of that type. |
| Slides: *Entries I choose* | Search the content type and add entries; move them earlier/later or remove them. Up to 12, shown in the chosen order. If every chosen entry is later unpublished, trashed or deleted, the slider renders nothing — it never falls back to the latest entries. |
| Slide content | Featured image (media-library alternative text; empty alt when none, the title carries the meaning), title as heading (H1–H4, default H2), optional excerpt (trimmed to 30 words), optional link button to the entry (text default “Read more”; screen readers also hear the title). |

Only published, not password-protected entries are shown; queries are bounded and prime thumbnails in one batch.

## Style and behavior (block settings)

| Group | Attributes (defaults) |
| --- | --- |
| Layout | `minHeight` (`70vh`), `mobileMinHeight` (`60svh`, screens ≤781px) — `<number><px|rem|vh|svh|dvh>`, vh-family ≤100, rem ≤125, px ≤2000, anything else falls back to the default; `textPlacement` top/center/bottom (center); `contentAlign` start/center/end (center); `contentWidth` narrow/medium/wide (medium); image focus `focalX`/`focalY` 0–100 % (50/50). |
| Colors (theme palette tokens) | `overlayColor` (contrast, or none), `overlayOpacity` 0–90 % (40), `textColor` (base), `backgroundColor` for slides without an image (contrast), `controlsColor` (base). Tokens must match `^[a-z0-9][a-z0-9-]{0,59}$` and are output as `var(--wp--preset--color--<token>)`. |
| Behavior | `transition` none/fade/slide (fade), `duration` 0–1200 ms (500), `autoplay` (off), `interval` 3000–20000 ms (7000), `pauseOnHover` (on), `arrows` (on), `indicators` (on), `loop` (on), `swipe` (on). If both arrows and indicators are off, arrows are still rendered. |
| Other | `label` (accessible name; default “Featured content”), block supports `align` wide/full (default full), `anchor`, margin. |

## Surfaces

- Block: `<!-- wp:cradle/hero-slider {"postType":"cradle_project","count":3} /-->`
- Shortcode: `[cradle_component id="cradle/hero-slider" post-type="page" post-ids="12,14"]`
- PHP: `cradle_render_component( 'cradle/hero-slider', array( 'postType' => 'post', 'count' => 4 ) )`
- Provisioning: `Cradle\Blocks\Hero_Slider::block_markup( $attrs )` (dynamic, self-closing; defaults omitted).

## Layers

| Layer | Where |
| --- | --- |
| Domain contract | `components/hero-slider/component.json` (render contract `hero-slider-v2`, `fallbackPolicy: none`) |
| Data access | `Hero_Slider::prepare()` (viewable type, public taxonomy, chosen IDs of that type, lengths, tokens) + `query()` |
| Render service | `Hero_Slider::render()`; markup contract in the class docblock |
| Presentation | `blocks/hero-slider/style.css` (`cradle-hero-slider-style`), `blocks/hero-slider/view.js` (`cradle-hero-slider-view`, enqueued only when a slider renders) |
| Editor adapter | `blocks/hero-slider/block.json` + `editor.js` (plain JS, inspector panels Slides / Slide content / Layout / Colors / Behavior, ServerSideRender preview) |

The block is dynamic: while Cradle Blocks is inactive it outputs nothing (the block comment stays and renders
again on reactivation).

## Frontend behavior and accessibility

- 2+ slides → `<section aria-roledescription="carousel" aria-label>` with slides `role="group"
  aria-roledescription="slide" aria-label="n of N"`; one slide renders a plain hero without carousel
  semantics or script config.
- Without JavaScript the first slide is visible; other slides carry `hidden`; controls are `hidden`.
- IDs come from the core per-request instance counter, so several sliders on a page never share IDs
  (a random suffix is added inside editor previews); every `aria-controls` points into its own instance.
- Each `[data-cradle-hero]` instance is initialized once and keeps its own state and timer.
- Controls come first in the DOM and sit visually at the bottom: pause/play (only with autoplay), previous,
  position buttons (`aria-current`, labelled “Show slide n of N: title”), next. Left/Right keys on any
  control change slides (mirrored in RTL). Without loop the end buttons get `aria-disabled="true"`.
- Autoplay never starts under `prefers-reduced-motion: reduce`, pauses on hover (setting) or hidden tab,
  stops when keyboard focus enters or the visitor navigates; the slides region is `aria-live="polite"`
  only while not rotating.
- Reduced motion: instant transitions. Touch/pen swipe via pointer events (`touch-action: pan-y`).
- Images: `width`/`height` always emitted, focus → `object-position`; the first slide is never lazy, only
  the very first slider of the request gets `fetchpriority="high"`, later slides are lazy.

## Child-theme override

`<child>/cradle/components/hero-slider/render.php` with header `Render contract: hero-slider-v2`; keep
the classes and IDs documented in `Cradle\Blocks\Hero_Slider` (the view script depends on them). A stale
override is ignored and reported in the status report (Cradle → Overview → Generate status report).

## History

Before 2026-09-24 the slider lived in a separate plugin, Cradle Hero (hero items post type, global slider
defaults). That plugin was retired at the owner's request; sites that had it should delete it (choosing
“Remove its data” first removes its hero items) and place this block instead.
