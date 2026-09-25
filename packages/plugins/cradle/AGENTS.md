# Cradle core plugin — package instructions

Scope: shared runtime services and contracts used by every Cradle add-on. No feature UI beyond the
Cradle administration shell: Overview (theme check, modules with state, activation and the "When
deleted" data choice, status report). Activity records are kept (90 days) for support and APIs but have no screen. Keep it that lean: no page without a clear
administrator use (developer information belongs in the status report, Site Health or APIs). Framework mode only; never add site-specific behavior.

Read `docs/architecture/core-api.md` before any change (the repository copy is canonical; packages receive a generated copy).

## Services (src/)

Module_Registry, Settings (+ Field_Renderer), Capabilities, Components (+ Editor_Adapter interface),
Jobs, Locks, Activity, Storage, Inventory, Assets, Schema, Health, Lifecycle, Version_Range.

## Rules

- API changes are versioned (`CRADLE_API_VERSION`); breaking changes need a new major version and migration notes.
- Boot stays small: no filesystem scans or unbounded queries on ordinary requests; schema upgrades and
  capability grants run only in admin/CLI contexts.
- Every admin action checks its capability server-side and uses a nonce; menus are not authorization.
- Tables are additive (`dbDelta`); uninstall retains data unless the policy for `core` is `purge`.
- Redact secrets/emails/paths in activity and job errors.
- Tests: `php tests/run.php --suite=core`.
