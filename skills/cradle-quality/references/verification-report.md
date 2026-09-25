# Verification report template and checklists

## Report template

```markdown
## 2026-09-24 — Services section and archive (website: cradle-site)

Scope: component acme/service-card, template archive-service.html, pages Home and Services.
Environment: http://localhost/cradle/wp (subdirectory), WordPress <version from status>, PHP 8.4 CLI,
browser <name/version>, logged-out visitor + editor + subscriber test users.

| # | Check | Method | Result | Evidence |
| --- | --- | --- | --- | --- |
| 1 | PHP syntax | `php -l` on 6 changed files | passed | terminal output |
| 2 | Integration tests | `php tests/run.php --suite=site` | passed (14 pass, 0 fail, 1 skip) | skip: WooCommerce inactive |
| 3 | Desktop fidelity 1440×900 | side-by-side vs baseline/home-1440.png | passed with 2 documented differences | compare/home-1440.png |
| 4 | Mobile fidelity 390×844 | same | failed: card spacing 24px vs 16px | compare/home-390.png |
| 5 | Editor save/reload | manual: edit title, image, link → save → reload | passed | steps below |
| 6 | Denied role | subscriber POST to REST `/wp/v2/service` | passed (403) | test `site: subscriber cannot create service` |
| 7 | Keyboard | tab through header, cards, footer | passed | notes |
| 8 | Contrast | accent on base 4.9:1 | passed | calculator values |
| 9 | Screen reader | — | not run (no screen reader available) | — |
| 10 | Performance | requests/bytes before → after | recorded | table below |

Differences: (1) heading letter-spacing −0.01em (font hinting); (2) testimonial carousel autoplay removed
(accessibility; pause control required).
Not run: #9 screen reader; WP-CLI checks (not installed).
Next: fix mobile card spacing (component-map row 7).
```

## Viewports

| Name | Size | Use |
| --- | --- | --- |
| Desktop | 1440×900 | Default comparison |
| Laptop | 1280×800 | When the design has a breakpoint near 1280 |
| Tablet | 768×1024 | Layout changes between mobile and desktop |
| Mobile | 390×844 | Default mobile comparison |
| Narrow | 320×640 | Reflow at 400% zoom equivalent |

Keep content, fonts and logged-in state identical between baseline and comparison.

## Accessibility checklist (select relevant items)

- Landmarks (`header`, `nav` with label, `main`, `footer`), one `h1`, no skipped heading levels in content.
- Keyboard: every interactive element reachable, visible focus (≥ 2px, sufficient contrast), logical order,
  no keyboard trap, Escape closes overlays, focus returns to the trigger.
- Menus: Navigation overlay operable by keyboard; `aria-expanded` state correct.
- Forms: labels, instructions, errors tied with `aria-describedby`, error summary for multi-field forms.
- Contrast: text 4.5:1 (3:1 large), UI components/focus 3:1; information not conveyed by color alone.
- Images: meaningful alt; decorative `alt=""`; icons with accessible names on icon-only buttons.
- Motion: autoplay off by default; pause control when autoplay exists; `prefers-reduced-motion` honored.
- Zoom/reflow: 200% zoom and 320px width without horizontal scrolling for text content.
- RTL: switch a test user or site to an RTL locale on a disposable target; logical CSS properties hold.
- Live regions only for meaningful async feedback (job progress uses `aria-live="polite"`).
- Automated tools help but do not establish conformance; record manual checks.

## Performance checklist

- Requests and transferred bytes by type (CSS, JS, fonts, images) on the front page before and after.
- Component assets load only where used; no duplicate script tags.
- Fonts: only used weights; at most the critical face preloaded.
- Hero/LCP image: correct size, not lazy-loaded, dimensions reserved.
- Queries bounded; no per-card query when a batch works; no filesystem scans on page requests.
- Large data not in autoloaded options (check the owning module's options).
- Record numbers with environment details; compare only like with like.

## Status report (northstar §26.2)

Packages and capabilities completed; actual verification evidence; remaining requirements by acceptance ID;
compatibility tested; known limitations; next concrete task. Never mark the platform complete when only
part of it is verified.
