# Reference vs WordPress build — measured comparison

Conditions: Claude desktop Browser pane (Chromium), emulated viewport 1280×800 (and 390×844 for mobile),
system fonts, anonymous visitor (no admin bar), same machine. Reference served from
`design-reference/harbor-pine/index.html`; build served from `http://localhost/cradle/wp/`.
Metrics are element bounding boxes (CSS px) and computed styles captured with the same script.

## Desktop 1280×800

| Element | Reference | WordPress | Result |
| --- | --- | --- | --- |
| Site title (x, w, font) | 67, 159, 24.8px Georgia | 67, 159, 24.8px Georgia | match |
| First nav link (x, w) | 763, 41 | 763, 41 | match |
| Header height | 77 | 74 | −3px (block padding) |
| Eyebrow (x, size, color) | 67, 12.8px, #2f5d50 | 67, 12.8px, #2f5d50 | match |
| H1 (x, w, h, size) | 67, 551, 243, 70.4px | 67, 551, 243, 70.4px | match |
| Lede size / color | 19.2px, #5d6b66 | 19.2px, #5d6b66 | match (measure 34ch) |
| Primary button (w×h, bg, radius) | 152×57, #2f5d50, pill | 152×55, #2f5d50, pill | −2px height |
| Hero image (x, w×h, radius) | 674, 525×394, 14px | 673, 525×394, 14px | match |
| Section title (x, w, size) | 67, 1132, 43.52px | 67, 1132, 43.52px | match |
| Service card (w, h, bg, radius) | 359, 240, #fff, 14px | 359, 231, #fff, 14px | −9px height |
| Card title (x, w, size) | 100, 293, 23.2px Georgia | 100, 293, 23.2px Georgia | match |
| Project image (w×h, radius) | 359×239, 14px | 359×239, 14px | match |
| Projects band (h, bg) | 617, #e8dfd0 | 602, #e8dfd0 | −15px |
| Quote (w, size) | 772, 36.8px Georgia | 772, 36.8px Georgia | match (after contentSize 772px) |
| CTA band (w, h, bg) | 1265, 363, #2f5d50 | 1265, 349, #2f5d50 | −14px |
| Footer (h, bg) | 365, #1b1f1d | 336, #1b1f1d | −29px (block spacing) |
| Document height | 3423 | 3351 | −2.1% |

## Mobile 390×844

No horizontal overflow (document width 390). Hero columns, service cards, project cards (342px wide)
and footer columns stack to one column. The core Navigation block shows its overlay menu button.

## Intentional deviations

- Mobile menu uses the core Navigation overlay (icon button, full-screen overlay) instead of the
  reference's text "Menu" dropdown: native, editable, keyboard accessible (§8.5 permits recording deviations).
- Footer "Follow" links use the core Social Links block (icons + labels) instead of plain text links.
- Journal list shows the three latest posts dynamically; reference text was static.
- Small vertical-rhythm differences (≤ 29px per section) come from block spacing defaults.

## Defects found and fixed during comparison

- Parent theme page/single templates constrained `post-content`, clamping full-width sections to 820px → framework fix (`align: full`).
- `cradle/post-cards` mobile breakpoint lost to `.has-3-columns` specificity → framework fix in cradle-blocks.
- Theme pattern cache hid new patterns in the sandbox → `WP_DEVELOPMENT_MODE=theme` in the sandbox; production releases bump the theme version.
