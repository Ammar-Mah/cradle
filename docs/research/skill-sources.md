# Skill sources and upstream review record

Record for northstar §23.1–§23.3 and acceptance CR-31. Date of this review: 2026-09-24.
Reviewer: the implementing coding agent (Claude) in the session that wrote `skills/`.

## Summary

- The Cradle skills in `skills/` are **original procedures** written from `northstar.md` §23.5–§23.9 and
  the current repository code (`packages/`, `templates/`, `scripts/`, `tests/`, `docs/architecture/`).
- **No upstream file was acquired, copied, or adapted verbatim.** Upstream material was read for
  orientation only. Therefore `skills/upstream-lock.json` is intentionally **not** created; create it only
  when a future change actually copies or adapts upstream files (with exact revisions and content hashes).
- Revisions below were obtained in this session with `git ls-remote --symref <repo> HEAD` and the GitHub
  REST API (`/repos/<owner>/<repo>/commits/<sha>`, `/contents/<path>?ref=<sha>`, `/license?ref=<sha>`).
  File hashes are the **git blob SHA-1** values reported by the contents API at the pinned commit; no
  local copies exist, so no SHA-256 content hashes were computed.
- Review method and depth: pinned `raw.githubusercontent.com/<owner>/<repo>/<sha>/<path>` URLs were read
  through a fetch tool that returns a condensed rendering of the page, not a byte-exact copy. This is an
  informational, summary-level review of the listed `SKILL.md`/README files. Referenced `references/` and
  `scripts/` folders were **not** reviewed, and no upstream script was downloaded or executed. This is not
  a security audit of any upstream repository.

## Upstream repositories

| Ref | Repository | Branch → revision (HEAD at review) | Commit date (UTC) | License at revision | What was reviewed |
| --- | --- | --- | --- | --- | --- |
| R1 | https://github.com/WordPress/agent-skills | `trunk` → `9f4a603340bc7a474037967f66459391087e9165` | 2026-09-23T05:37:15Z | `LICENSE` (blob `d3fc07ba4bd24a1f5c2d9f3a39f9e0ed4442dba3`) states GNU GPL version 2 "or (at your option) any later version"; the GitHub API license detector reports `NOASSERTION` | `skills/` directory listing; seven `SKILL.md` files below; `LICENSE` |
| R7 | https://github.com/jorgerosal/wordpress-skills | `main` → `8c964424d05ba34b3ea5641f7181d4c13829e06f` | 2026-06-07T06:49:54Z | MIT per GitHub API (`LICENSE` text not read) | `README.md` (blob `6ac086e1828b49bcf402c45ce1e51e5dfbac2ad7`), top-level listing |
| R39 | https://github.com/wpacademy/wordpress-dev-skills | `main` → `5bb36a5ccab2acc62284025b85df8cb4bea2befb` | 2026-02-10T19:21:50Z | GPL-2.0 per GitHub API ("or later" not verified; `LICENSE` text not read) | `README.md` (blob `2de37676ee0ab7c7b81a49a728f633c8f5fa3d2e`), top-level listing |
| R40 | https://github.com/Automattic/wordpress-agent-skills | `trunk` → `ea902bd8301564fa33e336c34114ab121f24c800` | 2026-02-28T09:15:14Z | No license file detected by the GitHub API; none stated in the README → nothing may be copied | `README.md` (blob `bd8761625b1beeaa61e3972b46a6ad0ccfbf6a41`), top-level listing |
| R41 | https://github.com/elvismdev/claude-wordpress-skills | `master` → `0ac0bbd5fd7c2a91f45af8ec3f5282537e52b075` | 2025-11-29T03:56:01Z | MIT per GitHub API (`LICENSE` text not read) | `README.md` (blob `1deb69e46706bc58b168e3b5ce39ce8b98a5f178`), top-level listing |

A branch URL is a research pointer, not a pin. Use the commit hashes above for any future re-review.

## Official skill files reviewed at `WordPress/agent-skills@9f4a603340bc7a474037967f66459391087e9165`

| Ref | Path | Git blob SHA-1 | Size (bytes) |
| --- | --- | --- | --- |
| R2 | `skills/wp-plugin-development/SKILL.md` | `9cec1d056a20f55fa30704d08d0f7067c55392a2` | 3996 |
| R3 | `skills/wp-block-themes/SKILL.md` | `bb3dfca39016b6c40af74e07a17908228c630c47` | 3967 |
| R4 | `skills/wp-project-triage/SKILL.md` | `f5ea34e5c1c85f22402a295735f561c045050042` | 1484 |
| R5 | `skills/wp-wpcli-and-ops/SKILL.md` | `738aed64fefe28710a8c762ae23ef24f0284a167` | 3975 |
| R6 | `skills/wp-performance/SKILL.md` | `37bf84ff1a05129759793894c118efed0722ecb6` | 5847 |
| — | `skills/wp-block-development/SKILL.md` (additional) | `df198b748c6337908f341f98600f428b9cb015b7` | 6491 |
| — | `skills/wp-rest-api/SKILL.md` (additional) | `b6cfbf65ae50b59cdd05096d8ec933ef8e62adea` | 4876 |

Catalog at that revision (`skills/`): `blueprint`, `wordpress-router`, `wp-abilities-api`,
`wp-abilities-audit`, `wp-abilities-verify`, `wp-block-development`, `wp-block-themes`, `wp-env`,
`wp-interactivity-api`, `wp-patterns`, `wp-performance`, `wp-phpstan`, `wp-playground`,
`wp-plugin-development`, `wp-plugin-directory-guidelines`, `wp-project-triage`, `wp-rest-api`,
`wp-wpcli-and-ops`, `wpds`. There is no standalone security skill in this listing, consistent with
northstar §23.2; `cradle-security` is therefore a Cradle-defined skill and does not reference a
nonexistent upstream `wp-security` file.

## What informed which Cradle procedure

| Upstream | Informed (ideas only, rewritten) | Removed or changed assumptions |
| --- | --- | --- |
| R4 wp-project-triage | `cradle-project` step 2: inspect files, versions and tooling before choosing a path; re-inspect after structural changes. | Node-based detector script and JSON schema not used; Cradle uses file inspection, package headers and `php scripts/sandbox/activate.php status`. |
| R2 wp-plugin-development | `cradle-plugin-development`: single small bootstrap, lifecycle hooks at top level, Settings API with sanitization, explicit uninstall, idempotent migrations/cron. | Upstream WordPress/PHP floors (7.0+/7.4+) replaced by Cradle's 7.1/8.3 headers; Node scripts, PHPUnit/PHPCS expectations treated as optional tools (not installed → "not run"); `is_admin()` may gate loading but is never an authorization decision (northstar §20.2). |
| R3 wp-block-themes | `cradle-gutenberg` steps 7–8, `cradle-design-to-wordpress`: templates in `templates/`, parts in `parts/` without subfolders, patterns in `patterns/`, user/DB customizations override files. | No scaffolding tool or Node workflow; Cradle forbids resetting user Global Styles/DB templates to make file edits visible (§9.2). |
| (additional) wp-block-development | `cradle-gutenberg`: block API version 3, `useBlockProps`/`useBlockProps.save`, declare styles in `block.json` for the iframed editor, static vs dynamic choice, deprecations before changing saved markup. | `@wordpress/scripts`, `wp-env`, JSX and any build chain removed (§2.1 editor exception: plain JS with `wp.element.createElement`); Interactivity API/script modules not adopted by default. |
| (additional) wp-rest-api | `cradle-security/references/request-security.md`, `cradle-content-modeling`: always declare `permission_callback`, use `args` schemas, cookie+nonce vs application-password authentication, REST meta schemas. | Upstream's `__return_true` for public endpoints is kept only for intentionally public, data-limited routes and is flagged by `check-surface.php` for review. |
| R5 wp-wpcli-and-ops | `cradle-migration`: confirm environment and target, checkpoint before risky writes, preview before replacement, re-inspect after changes. | WP-CLI is not installed in this environment and the web runner must not depend on it (§15.4, §15.7); WP-CLI search-replace is only an optional operations path; the `wpcli_inspect.mjs` script is not used. |
| R6 wp-performance | `cradle-quality`: measure first, compare baseline and result under identical conditions, avoid debug overhead in production. | `wp profile`, `wp doctor`, Query Monitor and `perf_inspect.mjs` not assumed installed; no unconditional score promises (§21.1). |
| R7 jorgerosal | Coverage checklist: security, WooCommerce (HPOS, CRUD), migrations/upgrades, accessibility, release review — reflected in `cradle-security`, `cradle-woocommerce`, `cradle-migration`, `cradle-quality`, `cradle-release-cleanup`. | ACF and WPGraphQL requirements excluded; repository scripts (for example its validator) not obtained or run. |
| R39 wpacademy | Review coverage for uploads, request handling, lifecycle and native admin — reflected in `cradle-security` references and `cradle-admin`. | Its `setup_tools.sh` installer (PHPCS/WPCS, PHPStan, PHPUnit) is not run or recommended; checklist coverage is not treated as proof of security. |
| R40 Automattic | Background only: example of theme/site-building agent workflows. | Experimental/beta and requires WordPress Studio; not a dependency; no license detected, so nothing is reused. |
| R41 elvismdev | Confirms only a performance-review skill is implemented; security, Gutenberg, theme and plugin skills are listed as in development. | Not cited as implemented security guidance. |
| R29 Agent Skills specification (https://agentskills.io/specification, web page, read 2026-09-24; no revision identifier available) | Frontmatter rules used by every `SKILL.md`: `name` lowercase-hyphenated and equal to the folder name, `description` ≤ 1024 characters stating what and when; concise `SKILL.md` with `references/`/`scripts/` loaded on demand, one level deep. | — |

Native WordPress, WooCommerce, OWASP, PHP and W3C references cited inside skills ([R8]–[R38], [R42]–[R44])
come from the northstar source register; they were not re-fetched in this session except R29.

## Supply-chain notes (northstar §20.6)

- Publisher, repository and license are recorded above for each source; community licenses other than
  the official repository's were taken from GitHub's license detection and should be read from the
  `LICENSE` file before any future copying.
- No upstream command, installer, download or network call was executed; no upstream script was fetched.
- Upstream material can only change Cradle through a reviewed change that pins the revision, preserves
  required notices, records content hashes in `skills/upstream-lock.json`, and adds an evaluation case.
- Re-verification commands:
  - `git ls-remote --symref https://github.com/WordPress/agent-skills HEAD`
  - `curl -s "https://api.github.com/repos/WordPress/agent-skills/contents/skills/wp-plugin-development/SKILL.md?ref=9f4a603340bc7a474037967f66459391087e9165"` (field `sha`)
