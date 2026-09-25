# Cradle

**A WordPress foundation for building real, editable websites with an AI coding agent.**

You install Cradle on a WordPress site, describe the website you want (or hand over screenshots, a
reference site or an HTML/CSS design folder), and a coding agent such as Claude Code or Codex builds it,
using native WordPress pages, blocks, menus and content types. The result looks like the design and
stays fully editable in the WordPress editor. It is not a picture of a page pasted into one HTML block.

Cradle is a **development platform**, not a ready-made theme and not an AI service inside WordPress.
Nothing runs an LLM on your server, and there is no API key, subscription or cloud dashboard. The agent
works on the source files on your machine, guided by the instructions and skills that Cradle ships with.

---

## Contents

1. [How it works](#how-it-works)
2. [What's in the box](#whats-in-the-box)
3. [Requirements](#requirements)
4. [Install Cradle on a WordPress site](#install-cradle-on-a-wordpress-site)
5. [Build a website with an LLM](#build-a-website-with-an-llm)
6. [Update, deploy and move a site](#update-deploy-and-move-a-site)
7. [Working on the framework (this repository)](#working-on-the-framework-this-repository)
8. [Documentation map](#documentation-map)
9. [Status](#status)

---

## How it works

A Cradle website is made of four layers. Only one of them belongs to your site:

```
wp-content/
├── themes/
│   ├── cradle/            Parent theme: shared layout, styles, component loading,
│   │                      agent skills and rules. Updated, never edited.
│   └── my-site/           YOUR child theme: the brief, design tokens, CSS/JS, site
│                          components, templates you override, project notes.
└── plugins/
    ├── cradle/            Core plugin: module registry, settings, components, jobs.
    └── cradle-*/          Feature add-ons (blocks, content types, translations,
                           migration, login, admin branding...). Turn on what you need.
```

- **The child theme is the website.** Everything specific to one site lives there and nowhere else.
  It stays *thin*: it holds data and design, no framework code. The parent and plugins can therefore be
  replaced by newer versions without touching your site.
- **Content lives in WordPress.** Pages are made of real blocks, menus are real navigation menus, and
  repeated items (projects, services, team members) are posts with fields. Custom content types are
  created with **Cradle Content**, not with a custom plugin. A Cradle website has no site plugin.
- **The agent's instructions travel with the code.** Each child theme contains an `AGENTS.md` (and a
  `CLAUDE.md` that imports it). These files point the agent to the parent's rules and a set of
  **skills**: step-by-step procedures for tasks such as "convert this design", "add a content type" and
  "build a reusable component".
- **Progress is written down, not remembered.** The agent keeps the site's state, decisions, component
  map and verification results in the child theme's `docs/` folder. A new agent session, or a different
  agent, can continue from the files alone without the old chat history.

---

## What's in the box

| Package | What it does |
| --- | --- |
| **Cradle** (parent theme) | Minimal block theme. Loads the child's styles, scripts and components by convention; ships the child-theme starter, the child-theme generator and the agent skills. |
| **Cradle** (core plugin) | Shared services: module registry, settings schemas, capabilities, component contract, background jobs, protected storage, diagnostics (Cradle → Overview). |
| **Cradle Blocks** | Generic components as Gutenberg blocks (cards, post lists, hero slider…) plus a shortcode/PHP fallback. Needed by most sites. |
| **Cradle Content** | Custom post types, taxonomies and fields through an admin screen, with export/import. |
| **Cradle Translations** | One editable JSON catalog per language to override interface strings of Cradle, WordPress and WooCommerce. German and Arabic examples included. |
| **Cradle Migration** | Full-site export, import, restore and move (new domain, folder or table prefix), with resumable jobs and recovery. |
| **Cradle Admin** | White-label branding, admin colors, label overrides and admin CSS. Everything is off until configured. |
| **Cradle Login** | Optional custom login URL that keeps the native WordPress login flows, with server-side recovery. |
| **Cradle Duplicate** | Duplicate posts and pages as editable drafts. |
| **Cradle Dummy Content** | Generate tracked sample content and remove exactly what was generated. |
| **Cradle Social** | Social profile and share links, with local icons and no tracking scripts. |

Everything is plain PHP, CSS and vanilla JavaScript. There is no build step, no bundled framework, no
jQuery and no third-party runtime library. The editor code uses only the packages WordPress already ships.

---

## Requirements

| | |
| --- | --- |
| WordPress | 7.1.x, single site (multisite is not supported and activation is refused) |
| PHP | 8.3 or newer, with `json`, `mbstring`, `mysqli`, `hash`; `zip` for Cradle Migration |
| Database | MySQL 8.0+ or MariaDB 10.6+ |
| Server | Apache or Nginx; HTTPS in production |
| Coding agent | Any agent that reads `AGENTS.md` (Claude Code, Codex, Cursor, …) with access to the site's files |

Tested versions are recorded in [`compatibility.json`](compatibility.json).

---

## Install Cradle on a WordPress site

Release packages are built into `dist/` with `php scripts/build.php` (see
[building releases](#building-release-packages)). Check downloads against `SHA256SUMS` before installing.

### Option A: the kit (recommended, no commands)

1. Extract **`cradle-kit-<date>.zip`** into the WordPress folder (the one containing `wp-config.php`) and
   let it merge into `wp-content/`. This adds the parent theme, a blank child theme `cradle-child` and
   every Cradle plugin.
2. *Optional:* rename `wp-content/themes/cradle-child` to your site's name (for example `acme-site`).
   Do this **before** activating it, because WordPress stores theme settings under the folder name.
3. **Plugins:** activate **Cradle** first, then the add-ons you need (almost always **Cradle Blocks** and
   **Cradle Content**).
4. **Appearance → Themes:** activate the child theme, **not** the parent.
5. Open **Cradle → Overview**. It checks the theme and shows the state of every module.

### Option B: separate ZIPs

1. **Plugins → Add New → Upload:** install and activate `cradle-plugin-*.zip`, then the
   `cradle-<feature>-plugin-*.zip` add-ons you need.
2. **Appearance → Themes → Add New → Upload:** install `cradle-theme-*.zip` (parent) and
   `cradle-child-theme-starter-*.zip` (child). Activate the child.

### Option C: generate a named child theme (command line)

```bash
php wp-content/themes/cradle/bin/child-theme.php new --slug=acme-site --name="Acme Studio"
```

It refuses to overwrite an existing folder. Activation of Cradle never changes your existing site title,
menus, homepage, permalinks, users or settings. Full details are in
[`docs/installation.md`](docs/installation.md).

---

## Build a website with an LLM

### 1. Prepare the child theme

Your child theme folder (`wp-content/themes/<your-child>/`) already contains everything the agent needs
to find its way:

```
my-site/
├── AGENTS.md              How to work on this site (points to the parent's rules)
├── CLAUDE.md              Imports AGENTS.md for Claude Code
├── northstar.md           YOUR website brief (starts empty)
├── design-reference/      Put your screenshots / HTML design / logos / fonts here
├── cradle-workspace.json  Tells the agent where the parent theme and plugins are
├── docs/                  State, decisions, component map, asset map, content model, verification
├── theme.json             Design tokens (colors, fonts, spacing)
├── assets/                design.css, site.css, site.js, images, fonts
└── components/ patterns/ templates/ parts/   (added by the agent as needed)
```

**Write the brief.** Put your own description of the site into `northstar.md`, in any structure or
language. Cover the identity, audience, pages, content that editors manage, visual direction, navigation,
features and what counts as "done". A full checklist is in the parent theme's
[`docs/website-brief.md`](packages/themes/cradle/docs/website-brief.md). An incomplete brief is fine:
the agent asks about gaps instead of inventing content.

**Add your references.** Copy screenshots, an exported HTML/CSS/JS design, logos, images and licensed
fonts into `design-reference/` unchanged. Files in the folder are more reliable than descriptions in
chat.

### 2. Open the child theme in your coding agent

Open the **child theme folder** as the project, so its `AGENTS.md`/`CLAUDE.md` is loaded. Also give the
agent access to the parent theme and the plugins, because that is where the rules, skills and components
live. With Claude Code, from inside the child theme folder:

```bash
claude --add-dir ../cradle --add-dir ../../plugins
```

For other agents, open the child folder and allow them to read `../cradle` and `../../plugins`.

The agent works on a **development copy** of the site (local or staging) where it can run PHP and look
at pages in a browser. Do not point it at a live production site.

### 3. Talk to the agent: a proven sequence

You do not need to name skills or files. The instructions tell the agent which ones to load. These
prompts walk a new site from brief to finished pages:

**① First use: name the site.**
> This is a new Cradle website called "Acme Studio". Set up the child theme for first use.

The agent sets the theme name, author and a stable site ID, and records this in `docs/decisions.md`.

**② Check the brief before building.**
> Read northstar.md and everything in design-reference/. Check the brief against the website-brief
> checklist and list what is missing or unclear. Don't build anything yet.

Answer its questions. Your answers are recorded in `docs/decisions.md`, and the brief stays yours.

**③ Plan.**
> Make the component map, content model and asset map for this site and show me the plan.

You get `docs/component-map.md`, which lists each section of the design, whether it is a core block,
pattern or custom component, what editors can change, and who owns it. You also get
`docs/content-model.md` (content types, taxonomies, fields) and `docs/asset-map.md`. Review these before
building. Changes are cheapest at this stage.

**④ Build.**
> Build the website from the brief and the references, following the plan.

The agent works from structure to detail:
design tokens in `theme.json` → header, footer and real menus → layout with core blocks → custom
components → patterns → real pages → single/archive/search/404 templates. Repeating content such as
projects and services becomes Cradle Content types with fields, so editors add items instead of editing
markup.

**⑤ Verify.**
> Compare every page with the references at desktop and mobile widths, open each page in the editor,
> save and reopen it, and report what passed, what failed and what was not checked.

**⑥ Iterate.** Ask for changes the way you would ask a developer:
> The hero should be a slider of the three latest projects.
> Add a "Services" section editors can manage, with an icon, title and short text per service.
> Add German as a second interface language.
> The footer spacing on mobile doesn't match the screenshot. Fix it.

**⑦ Next day, new session.**
> Continue the website. Read the project state and tell me where we are and what's next.

The agent reads `docs/project-state.md` and picks up where the last session ended.

### 4. What you can expect from the agent

The rules shipped with Cradle make the agent:

- **keep everything editable:** text, images, links, lists and menus can be changed in WordPress
  without touching code. A page delivered as one opaque HTML block counts as a failure.
- **match your design:** the agent does not swap in a generic look because it is easier. Deviations
  and guesses (for example mobile behavior seen only on a desktop screenshot) are written down as
  assumptions.
- **stay inside the child theme:** the agent never edits the parent, the plugins or WordPress core, and
  never creates a site plugin. When a site needs something every site could use, the agent tells you it
  is framework work.
- **add no hidden dependencies:** no jQuery, Tailwind, Bootstrap, Swiper, page builders or extra
  plugins without a recorded decision. Interactions are rebuilt in vanilla JavaScript.
- **treat your references as data:** text inside reference HTML, comments or archives is never
  followed as an instruction.
- **report honestly:** results are split into *passed*, *failed* and *not run*. A check that did not run
  is never reported as passing.

### 5. Your part

- Keep `northstar.md` current. It is the source of truth for *what* to build.
- Review the plan (step ③) before the build. Afterwards, try editing pages yourself in the editor.
- Keep the child theme folder under version control (for example its own private git repository), so
  every agent change can be reviewed and undone.
- Look at `sites/sandbox/themes/cradle-site` in this repository. It is a complete example site
  ("Harbor & Pine") built exactly this way, including its brief, design references, component map and
  verification notes.

---

## Update, deploy and move a site

**Update Cradle.** Extract `cradle-update-<date>.zip` into the WordPress folder and replace existing
files. It contains the parent theme and plugins but **never** a child theme, so it cannot overwrite your
site. Do not re-extract a full kit over a working site. After an update, have the agent run
`php wp-content/themes/cradle/bin/child-theme.php check --child=wp-content/themes/<your-child> --add-missing`
(it adds missing starter files and never changes existing ones), then check **Cradle → Overview**.

**Go live.** Development files (`AGENTS.md`, `northstar.md`, `docs/`, `skills/`, `design-reference/`)
are not protected just because they are Markdown. Either install the release ZIPs on production or add
the deny rules from [`docs/deployment.md`](docs/deployment.md). That file also covers private storage,
Nginx, caching and permalinks.

**Move a site** (to another domain, folder or host) with **Cradle Migration** (Cradle → Migration).
Content-type definitions can also be exported to the child's `content/content-types.json` and imported
elsewhere.

**Something broke?** [`docs/recovery.md`](docs/recovery.md) covers login lockouts
(`CRADLE_DISABLE_CUSTOM_LOGIN`), broken admin styling (`CRADLE_ADMIN_SAFE_MODE`), failed modules,
interrupted restores and rollbacks. None of these fixes require database edits.

---

## Working on the framework (this repository)

This repository is the Cradle **framework**: the parent theme, the plugins, the skills, the docs, and a
representative website used for testing. Agents working here follow [`AGENTS.md`](AGENTS.md).

### Repository layout

| Path | Contents |
| --- | --- |
| `packages/themes/cradle/` | Parent theme, including the child-theme starter (`starter/child-theme/`) and generator (`bin/child-theme.php`). |
| `packages/plugins/cradle/` | Core plugin. |
| `packages/plugins/cradle-<feature>/` | Feature add-ons. |
| `skills/` | Canonical agent skills and `INDEX.md`. Copied into packages by sync/build; edit only here. |
| `sites/sandbox/` | Example website: child theme `themes/cradle-site`, demo content in `provisioning/`, `bin/provision.php`. |
| `docs/` | Architecture, installation, deployment, recovery, tooling, state, decisions. |
| `scripts/` | `sync.php` (mirror into the sandbox), `build.php` (release ZIPs), `sandbox/` helpers. |
| `tests/` | Integration tests run against the sandbox (`php tests/run.php`). |
| `wp/`, `wp-target/`, `dist/` | Local sandbox installs and build output. **Not in git.** |

### Set up the sandbox after cloning

The sandbox WordPress (`wp/`) is not committed, so create it once. The defaults below match the scripts
(WAMP-style local server, repository at `C:\wamp64\www\cradle`, served as
`http://localhost/cradle/`). Adjust `--url` if yours differs.

1. **Tools:** PHP 8.3+ CLI (with `zip`, `tokenizer`, `mysqli`), MySQL 8, and Apache with `mod_rewrite`.
   WP-CLI is not needed.
2. **WordPress:** download WordPress 7.1.x from wordpress.org and extract it so that `wp/wp-load.php`
   exists.
3. **Database:** create the database `cradle_wp`.
4. **Config:** copy `wp/wp-config-sample.php` to `wp/wp-config.php`, add fresh salts from
   <https://api.wordpress.org/secret-key/1.1/salt/>, and set:
   ```php
   define( 'DB_NAME', 'cradle_wp' );
   define( 'DB_USER', 'root' );
   define( 'DB_PASSWORD', '' );
   define( 'DB_HOST', '127.0.0.1:3306' );
   $table_prefix = 'cw_';
   define( 'WP_ENVIRONMENT_TYPE', 'development' );
   define( 'WP_DEVELOPMENT_MODE', 'theme' );
   define( 'WP_DEBUG', true );
   define( 'WP_DEBUG_LOG', true );
   define( 'WP_DEBUG_DISPLAY', false );
   ```
5. **Install, sync, activate, add demo content:**
   ```bash
   php scripts/sandbox/install.php
   php scripts/sync.php
   php scripts/sandbox/activate.php plugin cradle cradle-blocks cradle-content cradle-translations cradle-admin cradle-login cradle-migration cradle-duplicate cradle-dummy-content cradle-social
   php scripts/sandbox/activate.php theme cradle-site
   php sites/sandbox/bin/provision.php --path=wp
   ```
   `install.php` prints the admin password **once**. Keep it out of the repository and out of notes.
6. **Migration test target (optional):** the migration suite restores into a second install in
   `wp-target/` (database `cradle_wp_target`, prefix `ct_`). Point `CRADLE_WP_ZIP` at a WordPress 7.1.x
   zip and run:
   ```bash
   CRADLE_WP_ZIP=/path/to/wordpress-7.1.2.zip php tests/fixtures/migration/target.php install
   ```
   In PowerShell, set the variable first with `$env:CRADLE_WP_ZIP = "C:\path\wordpress-7.1.2.zip"`.

The sandbox runs at `http://localhost/cradle/wp/` (admin at `/wp-admin/`).

### Development loop

1. Edit source in `packages/`, `skills/`, `sites/sandbox/`. **Never** edit files under
   `wp/wp-content/{themes,plugins}/cradle*`; they are overwritten.
2. Mirror to the sandbox: `php scripts/sync.php [package]`. The Claude Code hook in
   `.claude/settings.json` runs this automatically after each edit.
3. Check the change in the sandbox as a real site would use it (front end, editor, allowed and denied
   roles).
4. Run the tests:
   ```bash
   php tests/run.php
   ```
   Filter with `php tests/run.php <name>` or `--suite=core,blocks`. A missing precondition is reported as
   SKIP, never as PASS.
5. Record progress in `docs/project-state.md` and durable decisions in `docs/decisions.md`.

New or changed interface strings need German and Arabic entries in
`packages/plugins/cradle-translations/examples/{de_DE,ar}.json`. The translations suite fails without
them.

### Building release packages

```bash
php scripts/build.php
```

This writes to `dist/`: per-package ZIPs, the kit, the update bundle, the child-theme starter, a source
bundle, `manifest.json` and `SHA256SUMS`. `dist/` is not committed. Attach the ZIPs to a GitHub Release
instead:

```bash
gh release create v0.1.1 dist/*.zip dist/SHA256SUMS dist/manifest.json --title "Cradle 0.1.1" --notes-file CHANGELOG.md
```

---

## Documentation map

| Document | Read it for |
| --- | --- |
| [`northstar.md`](northstar.md) | The full product specification. Where it differs, later entries in `docs/decisions.md` win (for example: no site plugin, the hero slider is a block, Cradle Fonts was replaced by the WordPress Font Library). |
| [`AGENTS.md`](AGENTS.md) | Rules for agents working on the framework. |
| [`packages/themes/cradle/docs/child-themes.md`](packages/themes/cradle/docs/child-themes.md) | Rules for building a website: what goes in a child theme, update safety, working rules. |
| [`packages/themes/cradle/docs/website-brief.md`](packages/themes/cradle/docs/website-brief.md) | Checklist for a good website brief. |
| [`skills/INDEX.md`](skills/INDEX.md) | The agent skills and when each one applies. |
| [`docs/installation.md`](docs/installation.md) · [`docs/deployment.md`](docs/deployment.md) · [`docs/recovery.md`](docs/recovery.md) | Installing, going live, getting out of trouble. |
| [`docs/architecture/`](docs/architecture/) | Core API, component contract, data model, plugin conventions, security surface. |
| [`docs/tooling.md`](docs/tooling.md) | Every repository script and the tools that are (and aren't) available. |
| [`docs/project-state.md`](docs/project-state.md) · [`docs/decisions.md`](docs/decisions.md) | Where the framework stands and why it is the way it is. |
| [`CHANGELOG.md`](CHANGELOG.md) · [`compatibility.json`](compatibility.json) | Changes per version; tested and supported versions. |

---

## Status

Version **0.1.x**, in development and not yet published as a release. From `docs/project-state.md`:

- **Done:** parent and child themes, core, all nine add-ons, the design-to-website workflow (proven on
  the Harbor & Pine example), translations, login, migration with an independent restore, and release
  packaging. Last recorded full test run (2026-09-25): 194 passed, 0 failed, 3 skipped.
- **Not done yet:** WooCommerce integration; automated accessibility, visual and performance testing
  (axe, Lighthouse, Playwright); a screen-reader pass; multisite support.

## License

GPL-2.0-or-later, like WordPress.
