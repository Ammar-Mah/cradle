# Development tooling

Production code depends only on WordPress and PHP. Everything below is development-only.

## Available and used

| Tool | Version | Use |
| --- | --- | --- |
| PHP CLI | 8.4.15 (WAMP) | `php -l` syntax checks, `scripts/*.php`, `tests/run.php` |
| Apache | 2.4.65 (WAMP) | Sandbox web server (mod_rewrite, .htaccess honored) |
| MySQL | 8.4.7 (WAMP, port 3306) | Sandbox databases `cradle_wp` (prefix `cw_`) and disposable targets |
| curl | Git for Windows build | HTTP smoke checks |
| Claude desktop Browser pane | — | Block editor save/reopen checks, visual inspection |

## Repository scripts

| Command | Purpose |
| --- | --- |
| `php scripts/sync.php [package…] [--type=plugin\|theme] [--dry-run] [--verbose]` | Mirror source packages (plus injected skills) into `wp/wp-content`. Website child themes under `sites/<site>/themes/<slug>` are picked up without editing `scripts/packages.json`. |
| `php scripts/build.php [--out=dist] [--no-source]` | Build installable ZIPs, starters, source bundle, manifest.json and SHA256SUMS into `dist/`. |
| `php packages/themes/cradle/bin/child-theme.php new --slug=… --name="…" [--site-id=…] [--target=…]` | Generate a website child theme from the parent theme's starter (refuses to overwrite; in the repository defaults to `sites/<slug>/themes`). |
| `php packages/themes/cradle/bin/child-theme.php check --child=<dir> [--add-missing]` | List (or add) starter files a child lacks after a parent update; never changes existing files. |
| `php scripts/sandbox/install.php` | Install the sandbox WordPress (prints the generated admin password once). |
| `php scripts/sandbox/activate.php plugin\|deactivate\|theme\|status …` | Activate packages through WordPress APIs (runs activation hooks). |
| `php sites/sandbox/bin/provision.php --path=wp [--dry-run] [--replace=keys] [--set-identity] [--no-reading]` | Demo site: import its Cradle Content model, provision pages/projects/posts/navigation idempotently, add German/Arabic for the theme's strings. |
| `php tests/run.php [filter] [--suite=a,b]` | Integration tests against the sandbox. |
| `php skills/cradle-security/scripts/check-surface.php <dir>` | Read-only entry-point inventory (see the security skill). |

## Not installed (validation gaps)

| Tool | Gap |
| --- | --- |
| WP-CLI | No `wp` command; CLI operations use repository PHP scripts that bootstrap WordPress. Migration ships its own CLI runner. |
| PHP_CodeSniffer + WordPress Coding Standards | Coding-standard checks not run; code follows WPCS conventions by hand. |
| PHPUnit | Tests use the lightweight harness in `tests/lib/bootstrap.php` instead. |
| PHPStan | Static analysis not run. |
| Playwright | Browser checks are manual/scripted through the Browser pane; no automated visual regression suite. |
| axe / Lighthouse | Automated accessibility/performance scoring not run; manual checks are recorded where performed. |
