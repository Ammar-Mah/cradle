---
name: cradle-quality
description: Use when a component, page, template, or feature is finished or claimed complete; when checking visual fidelity against a reference, accessibility (keyboard, focus, contrast, screen reader, RTL, zoom), responsive behavior, or performance; or when writing a status report or docs/verification.md entry. Produces measured functional, visual, accessibility and performance results separated into passed, failed, not run and not applicable, with reproducible evidence.
---

# Cradle quality verification

## Required context

- `cradle-project` orientation; the brief's acceptance criteria (website `northstar.md`) or the framework
  acceptance matrix (`northstar.md` §25.2) for the changed scope.
- `northstar.md` §21 (performance, accessibility, native behavior), §25.3 (test types), §26.2 (status reporting).
- `references/verification-report.md` — report template, viewport set, accessibility and performance checklists.

## Preconditions

- Know which checks the change requires (risk profile): a visual-only change needs visual and
  responsive checks; a new mutation needs authorization tests; a component needs editor persistence and
  multi-instance checks.
- Tools: PHP CLI and `php tests/run.php` are available; a browser pane may be available for screenshots,
  console and network inspection; WP-CLI, PHPUnit, PHPCS, Playwright and Lighthouse-type tools are not
  installed unless verified — missing tools mean "not run", never "passed".
- Comparable conditions for comparisons: same viewport, content, fonts loaded, logged-out state.

## Procedure

1. List the checks from the acceptance criteria and the affected skills' Verification sections.
2. Automated: `php -l` on changed PHP; `php tests/run.php [--suite=<name>]`; the security surface
   inventory when inputs changed. Record the exact command and pass/fail/skip counts.
3. Frontend: desktop (1440×900), tablet (768×1024) and mobile (390×844 or the brief's sizes); compare with
   baselines side by side; record differences with reasons.
4. Editor: insert/edit/save/reload for changed blocks; no invalid-block warnings; undo, duplicate, move.
5. Permissions: allowed role succeeds; at least one denied role and a logged-out request fail safely.
6. Accessibility (WCAG 2.2 AA target, verify the relevant criteria): keyboard-only pass (tab order,
   visible focus, no traps, menus and dialogs operable, Escape closes), landmarks and heading order,
   labels and error association, contrast of text and UI states, non-color cues, alt text, 200% zoom and
   320px reflow, reduced motion, RTL spot check with an RTL locale, screen-reader spot check where available.
7. Performance: record before/after on the same environment — number and size of CSS/JS/font requests,
   largest image, query count if measurable, layout shift sources (hero image, fonts); verify conditional
   loading; no unconditional score promises.
8. Native behavior (§21.3): revisions/autosave, preview, permalinks, media selection, REST used by the
   editor, plugin activation/deactivation still work.
9. Write the report (template in the reference) to `docs/verification.md` (website) or the package/state
   docs (framework), and update `docs/project-state.md` "Last verification".

## Verification

This skill's own check: every claim in the report maps to evidence (command output, screenshot path,
test name, manual step list), and every requested check appears as passed, failed, not run (with the
reason) or not applicable. Acceptance CR-30 and CR-32.

## Required output

Report with: scope and environment; results table (check, method, result, evidence); failures with
reproduction steps; not-run checks with reasons; measurements; remaining work by acceptance ID.
No credentials, cookies, or customer data in evidence.

## Failure behavior

- A check fails: report it; do not mark the feature complete; propose the fix or next step.
- A check cannot run (tool missing, environment unavailable): mark not run with the reason and the
  command that would run it.
- Evidence contradicts an earlier claim in state documents: correct the documents.

## References

- `references/verification-report.md`
- `northstar.md` §21, §25, §26.2; acceptance CR-07, CR-08, CR-26, CR-30, CR-32
- WCAG 2.2 [R38]; official performance skill [R6] (reviewed for measure-first discipline, not copied)
