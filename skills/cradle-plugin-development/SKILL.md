---
name: cradle-plugin-development
description: Use when creating or changing a Cradle feature plugin (packages/plugins/cradle-<feature>) — websites have no site plugin — bootstrap and headers, module descriptor, settings sections, capabilities, admin handlers, REST/AJAX endpoints, background jobs and locks, schema migrations, activation, deactivation, update and uninstall behavior. Produces a modular implementation with distinct, safe lifecycle behavior and tests in the sandbox.
---

# Cradle plugin development

## Required context

- `cradle-project` orientation; `cradle-architecture` if ownership or contracts are new.
- `docs/architecture/core-api.md` (boot sequence, descriptor, services, settings, capabilities, jobs and
  locks, storage, add-on bootstrap template §9, uninstall §10) and `docs/architecture/plugin-conventions.md`.
- The package's `AGENTS.md` and `docs/`; `northstar.md` §3.2, §3.5, §4.4, §6, §22.1, §22.3.
- Test harness: `tests/lib/bootstrap.php`, `tests/run.php`, existing `tests/integration/*.php`.
- `references/lifecycle-matrix.md` — what each lifecycle event must and must not do.

## Preconditions

- The package source lives in `packages/plugins/cradle-<feature>/` (framework only: websites have no site
  plugin; their code goes in the child theme). Never edit `wp/wp-content/…` copies.
- Core API version and the add-on's `coreApiRange` are known; required core services exist.
- Shared sandbox rules: never deactivate core or other packages, never switch the theme, clean up your
  fixtures, leave risky features disabled when done.

## Procedure

1. Bootstrap `cradle-<feature>.php` (core-api §9): headers `Requires at least: 7.1`, `Requires PHP: 8.3`,
   `Requires Plugins: cradle`, `Update URI: false`, `Text Domain: cradle-<feature>`,
   `Cradle Module: <id>`; `defined( 'ABSPATH' ) || exit;`; constants; explicit namespace-to-file
   autoloader (`src/`, `admin/` only); activation/deactivation hooks; registration on
   `cradle/register_modules`; core-missing admin notice for `activate_plugins` users. No other work at
   include time.
2. Descriptor with complete `dataOwners`; feature hooks only inside `boot( $core, $descriptor )`;
   explicit `version_compare( $core->api_version(), '1.0.0', '>=' )`.
3. Settings: `$core->settings()->register_section()` with typed fields, bounds, capability, schemaVersion,
   `validate`/`migrate` callbacks; no catch-all JSON blob; large data out of autoloaded options.
4. Capabilities: declare in the descriptor with minimal default roles; check `current_user_can()` plus
   object capabilities (`edit_post`, `read_post`) at every handler.
5. Handlers: browser mutations use POST + nonce (`check_admin_referer`/`check_ajax_referer`) + capability,
   `wp_safe_redirect()` after admin-post; REST routes always declare a deliberate `permission_callback`
   and an `args` schema; GET never mutates. Validate by type/range/allowlist, escape by context, prepare SQL.
6. Background work: `$core->jobs()->register_type()` with capability, idempotent `step`, `max_retries`,
   `lease_seconds`, `time_budget`; `$core->locks()` for exclusive sections; never finish long work
   inside a normal page request or module discovery.
7. Assets: register with namespaced handles; enqueue only on the screens/routes that use them
   (`admin_enqueue_scripts` with `$hook` check); vanilla JS, no jQuery-based code.
8. Lifecycle per `references/lifecycle-matrix.md`: activation (multisite guard via
   `Cradle\Core\Lifecycle::guard_multisite()`, defaults, schema, caps — never content), deactivation
   (unschedule, release locks, flush rewrites if routes were added — keep data), update (versioned
   idempotent migrations on admin/CLI requests), `uninstall.php` (runs without core; retain unless
   `get_option( 'cradle_uninstall_policy' )[ '<id>' ] === 'purge'`; purge only `dataOwners`).
9. Log privileged operations with `cradle_log_activity()` without secrets.
10. Package docs: update `docs/` (feature contract, data model, hooks) and `AGENTS.md` scope/risks.
11. Sync (`php scripts/sync.php <package>` — automatic via the hook), activate
    (`php scripts/sandbox/activate.php plugin <slug>`), write tests in `tests/integration/<feature>.php`.

## Verification

- `php -l` on changed PHP files; `php tests/run.php --suite=<feature>` with allowed-role and denied-role
  cases for every mutation (`cradle_test_user()`, `cradle_test_nonce()`, `cradle_test_http()`) (CR-26).
- Fresh activation creates defaults once; re-activation is idempotent; module state `ready` on Cradle → Overview (CR-03).
- Setting validation: invalid field keeps its old value with a field error; stale `_revision` rejected.
- Update from an older fixture: seed the previous schema/option shape, run the migration twice, same result.
- Deactivate only this package and reactivate: no fatal, data retained (CR-28).
- Uninstall retain and purge: only on a disposable target or with fixtures you created; otherwise not run.
- Core-missing behavior: disposable target only (shared sandbox forbids deactivating core).
- `php skills/cradle-security/scripts/check-surface.php packages/plugins/cradle-<feature>` reviewed.

## Required output

Files changed per package, descriptor/settings/capabilities summary, lifecycle behavior, test command and
real results (pass/fail/skip), not-run checks with reasons, state updates.

## Failure behavior

- A test fails or cannot run: report it as failed/not run; do not mark the feature complete.
- Migration not idempotent or irreversible on a frontend request: stop, redesign as a batch job.
- A needed core service is missing: propose it through `cradle-architecture`; do not fork core logic into the add-on.

## References

- `references/lifecycle-matrix.md`
- `docs/architecture/core-api.md`, `docs/architecture/plugin-conventions.md`
- `northstar.md` §3, §6, §22; acceptance CR-03, CR-25, CR-26, CR-28
- Official plugin-development skill [R2] (reviewed, not copied — `docs/research/skill-sources.md`)
