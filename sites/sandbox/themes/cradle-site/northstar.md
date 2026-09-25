# Website north star

> This sandbox site is Cradle's representative demonstration (northstar §24 Phase 3). The studio is a
> fictional test fixture; do not treat it as a real business.

## Identity
- Site name: Harbor & Pine
- Business or organization: Harbor & Pine Studio (fictional architecture and interiors practice)
- Primary audience: prospective residential, workplace and restoration clients
- Primary language and additional interface languages: English (en_US); no additional languages yet
- Primary conversion or task: start a conversation via the Contact page

## Purpose
Show the studio's work and approach and make it easy to start a project conversation.

## Required pages
- Home: hero (heading, lede, two calls to action, image), services (3 cards), selected work (live list of 3 projects), client quote, latest journal articles, call-to-action band.
- Projects archive (/projects/) and project single (facts: client, location, completion year; more projects).
- Services, Studio, Journal (posts index), Contact.
- Search, 404 and taxonomy archives (project type).

## Content model
- Project (`project`, Cradle Content post type): title, excerpt, content, featured image, project type terms, "Project facts" fields `client`, `location`, `year`. Editors: administrators, editors.
- Project type (`project_type`, Cradle Content taxonomy, hierarchical): Residential, Workplace, Restoration.
- Posts in category Journal.
- Navigation entities: Primary, Footer.

## Visual direction
- Reference: `design-reference/harbor-pine/` (HTML/CSS/JS fixture) and `design-reference/baseline/`.
- Viewports: desktop 1280×800, mobile 390×844.
- Typography: Georgia serif headings (weight 400), system sans body 17px/1.65.
- Colors: paper #f7f4ee, ink #1b1f1d, sand #e8dfd0, pine #2f5d50, harbor blue #1f4e79, moss grey #5d6b66.
- Spacing and layout: 1132px wide content, 820px text measure, generous section padding (≈6rem).
- Motion and interaction: none beyond the mobile menu.
- Must match precisely: header, hero proportions, card and project grids, color bands.
- Permitted deviations: native mobile navigation overlay; social icon links in the footer.

## Editing expectations
Editors change all text, images, buttons, cards, quotes, navigation and project facts without code.
Project and journal lists update automatically from content.

## Navigation
Primary: Work, Services, Studio, Journal, Contact (pill). Footer: About, Services, Journal, Contact.

## Functional requirements
No forms, accounts or commerce. Contact is by email link.

## Content and assets
All copy and images are generated fixture content (see design-reference/harbor-pine/SOURCE.md).

## Technical constraints
Cradle packages only (core, blocks, content, child theme; no site plugin); no third-party plugins.

## Acceptance criteria
- Measured comparison within the documented tolerance (design-reference/baseline/comparison-2026-09-24.md).
- All provisioned content and templates open in the block editor without invalid blocks.
- Administrator can change hero text/image, navigation and projects; provisioning never overwrites those edits.

## Non-goals
Real business content, commerce, multilingual content.

## Open decisions
None.
