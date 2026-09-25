---
name: cradle-migration
description: Use when working on Cradle Migration or any export, backup, import, restore, clone, or move of a WordPress site — moving to another domain or subdirectory, changing the table prefix, archive format changes, URL/serialized-data replacement, durable migration jobs, preflight validation, cutover, or post-restore recovery. Produces a recoverable job implementation and proof of an independent restore, including failure cases.
---

# Cradle migration and restore

## Required context

- `cradle-project` orientation; `packages/plugins/cradle-migration/` source, `AGENTS.md`, and
  `docs/archive-format.md`; core jobs/locks/storage in `docs/architecture/core-api.md` §7–§8.
- `northstar.md` §15 (modes, contents/exclusions, archive format, job engine, preflight, consistency,
  URL/serialization, cutover/credentials, verification, failure tests), §20.4, §22.
- `references/restore-checklist.md` — preflight, cutover, recovery and verification checklists with the
  required failure tests.
- `cradle-security` with `../cradle-security/references/file-security.md` for archives, paths and
  serialized data.

## Preconditions

- Separate source and destination installations with separate databases. Never restore over a live
  site or the shared sandbox as the first verification. AGENTS.md allows disposable local databases
  for such targets; keep their credentials out of notes.
- Capabilities `cradle_export_site` and `cradle_import_site` are distinct from translation or settings
  capabilities; restoring plugin/theme code is a privileged deployment action.
- Protected storage verified: `$core->storage()->is_protected()` returns true (or `CRADLE_PRIVATE_DIR`
  is outside the web root) before any archive or backup is written.
- WP-CLI is not installed here: the web runner is required; record CLI-runner checks as not run.
- Multisite: refuse with an explanatory error (not supported until a separate milestone).

## Procedure

1. Build the engine before the wizard: job types via `$core->jobs()->register_type()` (`migration/export`,
   `migration/import`, …) with capability, idempotent bounded `step`, retries, lease and time budget;
   states `created → preflight → running ↔ paused → awaiting_cutover → completed | failed | cancelled |
   rolled_back` (rolled_back only when rollback actually completed); atomic claims with fencing
   (already in core Jobs); `$core->locks()` to prevent concurrent import/export/update.
2. Define scope and exclusions per `docs/archive-format.md`: site tables (including plugin, WooCommerce
   HPOS and Action Scheduler tables when selected), uploads, fonts, selected themes/plugins; exclude
   `wp-config.php`, secrets, caches, logs, prior archives, VCS data, unsafe symlinks, files outside
   declared roots. List non-migrated items (DNS, email, TLS, server packages) before export.
3. Export: choose and report the consistency strategy (snapshot or maintenance window); structured
   table metadata plus bounded row batches; file inventory with sizes and hashes; manifest with source
   URLs, paths, prefix, versions, parts and checksums; standard ZIP or documented segments.
4. Import preflight (checklist): permission, nonce, target identity, format version, runtime/extension
   compatibility, disk space, inventory validation (traversal, symlinks, duplicates, special files,
   size/count/depth/ratio limits), checksums, replacement preview (tables, files, URL map, admin login and
   external-service implications), protected staging, verified destination checkpoint, no concurrent job.
5. Stage without executing imported PHP. Transform URLs/paths with the structural serialized parser
   (`packages/plugins/cradle-migration/src/class-serialized.php`, replacer `class-replacer.php`) — never
   SQL `REPLACE()`, never `unserialize()` of archive data; handle JSON-escaped URLs, block attributes,
   trailing slashes, prefix changes in option/usermeta keys; do not rewrite GUIDs.
6. Cutover with a journal: recovery context independent of the tables being replaced (job-scoped,
   expiring); preserve destination credentials and URL configuration; disable imported custom login
   routing; apply the destination safety profile (no emails, payments, webhooks, cron side effects) before
   imported behavior runs; keep old tables/files mapping until verification passes.
7. Post-restore verification (checklist) before `completed`; retention policy governs backup deletion.
8. Operator recovery guide in the package `docs/` for interrupted restore and failed file/DB cutover.

## Verification

- Complete export → restore onto the independent target; front page, content page, single, archive,
  media, search, admin login, REST, editor save/reopen, navigation, fonts, translations all work (CR-18).
- Changed URL/path and table prefix with nested serialized values, JSON-escaped URLs and block attributes.
- Failure tests from the checklist: bad checksum, missing part, interrupted upload/resume, expired lease
  and duplicate chunk, hostile paths/symlinks/decompression bomb, low disk, DB failure, crash between DB
  and file cutover, admin session change, imported login route, HPOS store (CR-18, CR-27).
- Staging files and backups are not publicly readable (HTTP probe).
- Denied roles: editor and a user with only translation capabilities cannot create, read status of,
  download, or cancel migration jobs (CR-26).

## Required output

Job/state design, archive scope and exclusions, consistency strategy, per-test results (passed / failed
/ not run with reason), restore evidence on the independent target, recovery guide location, remaining
gaps. Never "migration complete" without a working restored destination.

## Failure behavior

- Preflight fails: change nothing on the destination; report the exact entries/checks.
- Failure after cutover starts: follow the journal to roll back or stop at a documented recoverable
  state and give the operator recovery steps; keep backups.
- Unsupported data (views, triggers, routines, generated columns, object-bearing serialized values that
  need transformation): fail preflight and name the records; never silently omit.

## References

- `references/restore-checklist.md`, `packages/plugins/cradle-migration/docs/archive-format.md`
- `northstar.md` §15, §20.4; acceptance CR-18, CR-26, CR-27
- WP-CLI search-replace documentation [R20] (optional ops path only), PHP unserialize warning [R35]
