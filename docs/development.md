# Developing Cradle (this repository)

This page is for people changing the framework itself: the parent theme, the plugins, the skills and
the docs. To **build a website** with Cradle, see the [README](../README.md). Agents working here follow
[`AGENTS.md`](../AGENTS.md).

## Repository layout

| Path | Contents |
| --- | --- |
| `packages/themes/cradle/` | Parent theme, including the child-theme starter (`starter/child-theme/`) and generator (`bin/child-theme.php`). |
| `packages/plugins/cradle/` | Core plugin. |
| `packages/plugins/cradle-<feature>/` | Feature add-ons. |
| `skills/` | Canonical agent skills and `INDEX.md`. Copied into packages by sync/build; edit only here. |
| `sites/sandbox/` | Example website "Harbor & Pine": child theme `themes/cradle-site`, demo content in `provisioning/`, `bin/provision.php`. |
| `docs/` | Architecture, installation, deployment, recovery, tooling, state, decisions. |
| `scripts/` | `sync.php` (mirror into the sandbox), `build.php` (release ZIPs), `sandbox/` helpers. |
| `tests/` | Integration tests run against the sandbox (`php tests/run.php`). |
| `wp/`, `wp-target/`, `dist/` | Local sandbox installs and build output. **Not in git.** |

## Set up the sandbox after cloning

The sandbox WordPress (`wp/`) is not committed, so create it once. The defaults below match the scripts
(local Apache/MySQL such as WAMP, repository in the web root as `cradle`, for example
`C:\wamp64\www\cradle` or `D:\wamp64\www\cradle`, served as `http://localhost/cradle/`). The drive does
not matter: scripts, tests and the Claude Code hook locate the repository themselves. Adjust `--url` if
the URL differs.

1. **Tools:** PHP 8.3+ CLI (with `zip`, `tokenizer`, `mysqli`), MySQL 8, and Apache with `mod_rewrite`.
   WP-CLI is not needed.
2. **WordPress:** download WordPress 7.1.x and extract it so that `wp/wp-load.php` exists.
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
   `wp-target/` (database `cradle_wp_target`, prefix `ct_`). It needs the WordPress 7.1.2 zip (checked by
   SHA-1): either save it as `wordpress-7.1.2.zip` in the system temp folder (`%TEMP%` on Windows), or
   point `CRADLE_WP_ZIP` at it, and run:
   ```bash
   CRADLE_WP_ZIP=/path/to/wordpress-7.1.2.zip php tests/fixtures/migration/target.php install
   ```
   In PowerShell, set the variable first with `$env:CRADLE_WP_ZIP = "C:\path\wordpress-7.1.2.zip"`.

The sandbox runs at `http://localhost/cradle/wp/` (admin at `/wp-admin/`).

## Development loop

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

## Building release packages

```bash
php scripts/build.php
```

This writes to `dist/`: the kit (`cradle-kit-<date>.zip`), the update bundle
(`cradle-update-<date>.zip`), per-package ZIPs, the child-theme starter, a source bundle,
`manifest.json` and `SHA256SUMS`. `dist/` is not committed. Attach the files to a GitHub Release:

```bash
gh release create v0.1.1 dist/*.zip dist/SHA256SUMS dist/manifest.json --title "Cradle 0.1.1" --notes-file CHANGELOG.md
```

## Documentation map

| Document | Read it for |
| --- | --- |
| [`northstar.md`](../northstar.md) | The full product specification. Where it differs, later entries in `docs/decisions.md` win (for example: no site plugin, the hero slider is a block, Cradle Fonts was replaced by the WordPress Font Library). |
| [`AGENTS.md`](../AGENTS.md) | Rules for agents working on the framework. |
| [`packages/themes/cradle/docs/child-themes.md`](../packages/themes/cradle/docs/child-themes.md) | Rules for building a website: what goes in a child theme, update safety, working rules. |
| [`packages/themes/cradle/docs/website-brief.md`](../packages/themes/cradle/docs/website-brief.md) | Checklist for a website brief. |
| [`skills/INDEX.md`](../skills/INDEX.md) | The agent skills and when each one applies. |
| [`installation.md`](installation.md) · [`deployment.md`](deployment.md) · [`recovery.md`](recovery.md) | Installing, going live, getting out of trouble. |
| [`architecture/`](architecture/) | Core API, component contract, data model, plugin conventions, security surface. |
| `packages/plugins/<plugin>/docs/` | Each plugin's detailed contract, settings and security notes. |
| [`tooling.md`](tooling.md) | Every repository script and the tools that are (and aren't) available. |
| [`project-state.md`](project-state.md) · [`decisions.md`](decisions.md) | Where the framework stands and why it is the way it is. |
| [`CHANGELOG.md`](../CHANGELOG.md) · [`compatibility.json`](../compatibility.json) | Changes per version; tested and supported versions. |

## Status

See [`project-state.md`](project-state.md). As of 2026-09-25: parent and child themes, core, all nine
add-ons, the design-to-website workflow (proven on Harbor & Pine), translations, login, migration and
release packaging are done; the last recorded full test run was 194 passed, 0 failed, 3 skipped. Not done
yet: WooCommerce integration, automated accessibility/visual/performance testing, a screen-reader pass,
multisite support.
