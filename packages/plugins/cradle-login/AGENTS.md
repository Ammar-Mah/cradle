# Cradle Login — package instructions

Scope: an optional custom login route that serves WordPress' own `wp-login.php` at
`home_url('/<slug>/')`, conceals the native entry points from signed-out visitors, and can
always be switched off from the server. Module id `login`, text domain `cradle-login`,
capability `cradle_manage_login` (administrators only). Northstar §10 (acceptance CR-13), §20, §21.3.

Follow the repository `AGENTS.md`, `docs/architecture/core-api.md`, `docs/architecture/plugin-conventions.md`,
and in this package `docs/login.md` (contract, flow matrix, data) and `docs/recovery.md` (runbook).

## Owns

- Option `cradle_login_settings` (core Settings section `login`: `enabled`, `slug`, `blocked_response`, `recovery_note`).
- Option `cradle_login_health` (last health-check result; no secrets).
- Transients `cradle_login_probe_*` (2-minute pending probe: slug + token hash), `cradle_login_check_<user>`, `cradle_login_resumed_notice`.
- Capability `cradle_manage_login`; screen Cradle → Login (`admin.php?page=cradle-login`); admin-post action `cradle_login_health_check`.
- Public hooks: filters `cradle_login_disable_routing` (bool) and `cradle_login_reserved_slugs` (slug => owner).

## Non-negotiable rules

- Never edit, copy or re-implement `wp-login.php`, password checking, cookies, nonces or reset keys. The router
  includes the unmodified core file; everything else is URL rewriting and the signed-out safe response.
- Never block or redirect `admin-ajax.php`, `admin-post.php`, REST, XML-RPC, `wp-cron.php` or CLI (`PHP_SAPI === 'cli'`
  is exempt even with `WP_ADMIN` defined — the test harness depends on it).
- Only signed-out visitors are affected. A valid auth cookie **or** logged-in cookie always passes.
- Every safe response must be indistinguishable from "not found" and must not contain the slug (tests grep for it).
- No query-string bypass, no unauthenticated recovery endpoint. Recovery = `CRADLE_DISABLE_CUSTOM_LOGIN`, the
  `cradle_login_disable_routing` filter, or deactivating/renaming the plugin. Keep `docs/recovery.md` accurate.
- Enabling or moving the route must pass the loopback health check (`Health_Check::probe()`); never add a
  "skip health check" switch. The probe token is random, hashed at rest, single-use and expires in 2 minutes.
- Routing is off by default and after reset. Leave the sandbox with routing **disabled**.
- No rewrite rules are registered, so nothing is ever flushed. If rewrites are ever introduced, flush only on
  enable/disable/slug change and on activation/deactivation, and document it.
- Keep work in the `cradle/ready` hook minimal (one autoloaded option read); everything else runs only on the
  route, on native entry points while active, or in admin.

## Where things live

| File | Responsibility |
| --- | --- |
| `cradle-login.php` | Bootstrap: headers, autoloader, lifecycle hooks, descriptor + `cradle/ready` registration, core-missing notice. |
| `src/class-module.php` | Descriptor, `ready()` (starts router), `boot()` (settings section, logging, admin), cross-field validation incl. health check. |
| `src/class-config.php` | Direct, type-checked option read; status (`active`, `disabled`, `no_slug`, `constant`, `filter`, `multisite`); route URL/path helpers and request matching. |
| `src/class-router.php` | Request detection, `$pagenow`/`is_login()` parity, URL filters, dispatch into `wp-login.php`, guard/decide/handoff, safe responses. |
| `src/class-slug-policy.php` | Slug format + collision checks. |
| `src/class-health-check.php` | Loopback probe and result record. |
| `src/class-frontend-screen.php` | `current_screen` stand-in so a signed-out `/wp-admin/` request renders the theme 404 in frontend context. |
| `src/class-diagnostics.php` | Compatibility rows for the admin screen. |
| `admin/class-admin-page.php` | Cradle → Login screen and the manual route test. |
| `uninstall.php` | Retain by default; purge options/transients/capability only on explicit policy. |

## Verification

`php tests/run.php --suite=login` (tests/integration/login.php; constant fixture in tests/fixtures/login/).
It enables routing with a random slug, runs the HTTP flow matrix, and restores defaults (plus a shutdown
safety net). After changes also check `wp/wp-content/debug.log` for new notices from this package.
