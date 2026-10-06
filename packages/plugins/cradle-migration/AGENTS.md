# Cradle Migration — package instructions

Module id `migration`, text domain `cradle-migration`, capabilities `cradle_export_site` and
`cradle_import_site` (administrators only; never grant to editors or shop managers).
Spec: northstar §15, §20.4, §22; acceptance CR-18 and CR-27. Read `docs/archive-format.md`
and `docs/recovery-guide.md` before changing behavior.

## Layout

| Path | Contents |
| --- | --- |
| `src/class-serialized.php` | Bounded structural parser/writer for PHP serialized data. Archive data never reaches `unserialize()`. |
| `src/class-replacer.php`, `class-transformer.php` | Boundary-aware URL/path replacement (plain, JSON-escaped, backslash, `\`, URL-encoded), serialized-aware; prefix renames; never-transform rules (GUIDs, binary, `user_pass`, opaque keys). |
| `src/class-path-guard.php` | Archive path validation and canonical containment. |
| `src/class-db.php` | Schema introspection, allowlisted DDL built from structured metadata, bounded row I/O. No SQL from archives. |
| `src/class-manifest.php` | Format constants, limits, strict manifest validation and expected-entry set. |
| `src/class-container.php` | The single-file `.cradle` container (archive-format §1.1): resumable stored-ZIP64 writer, strict reader/extractor. |
| `src/class-exporter.php` | `migration/export` job: prepare → scan_files → dump_db → pack → finalize → bundle (writes `<id>.cradle`, removes the separate parts). |
| `src/class-importer.php` | `migration/import` job: [unpack →] preflight → review → staging → safety profile → checkpoint → cutover → files → finalize → verify. A one-step import (payload `auto`, created by the browser upload and Archives → Restore) skips the review stop when the report has no blocking problems. |
| `src/class-recovery.php`, `class-journal.php` | Journal-driven roll forward / rollback; recovery context in private files. |
| `src/class-guard.php` | Write-quiescence windows and the post-restore safety hold (mail, WP-Cron, outbound HTTP). |
| `src/class-upload.php`, `class-ajax.php`, `class-archives.php` | Chunked resumable upload of one `.cradle` file, operator endpoints (incl. one-click restore), token continuation, protected downloads. |
| `src/class-verifier.php` | Post-restore verification (§15.9). |
| `src/class-cli.php`, `bin/cradle-migrate.php` | CLI runner (also `wp cradle-migrate` under WP-CLI); `--raw` SHORTINIT recovery. |
| `admin/class-page.php`, `assets/` | Cradle → Migration screen. |

## Data owned

- Options: `cradle_migration_quiesce` (runtime window), `cradle_migration_safety_hold`, `cradle_migration_last_restore`.
- Private files: `<private root>/migration/{archives,incoming,jobs}` (never public; `is_protected()` is required before writing).
  `archives/<id>/` holds `<id>.cradle` and its manifest (archives from 0.1.0: manifest and separate parts);
  `incoming/` holds uploads (`up-*`), CLI copies (`cli-*`) and unpacked containers (`unpack-*`), removed when the job ends.
- Restore backups: unprefixed tables `cmig_<6 hex>_<table>` (staged/imported copies) and `cold_<6 hex>_<table>`
  (the destination's replaced tables), plus `jobs/<uuid>/backup/` files. Removed only by "Purge backups" or a purge uninstall.
- Core tables it uses but never replaces on import: `{prefix}cradle_jobs`, `{prefix}cradle_locks`, `{prefix}cradle_activity`.

## Rules for changes

- Never call `unserialize()` on archive data; never execute archive-provided SQL; never `include` staged files.
- Every new ZIP entry kind or manifest key must be documented in `docs/archive-format.md` and validated in `Manifest::validate()`.
- Import must stay journaled: write the journal line before the operation it announces.
- Only the browser tab that started a one-step import may request the switch-over by itself (sessionStorage
  flag set after its own confirmed POST); every other view of that job shows the switch-over button.
- Keep the destination's Cradle runtime tables, `siteurl`/`home`, private-storage option and the running engine
  (`cradle`, `cradle-migration` plugins) out of every replacement.
- Language files (`WP_LANG_DIR`, category `languages`) and Cradle Translations data (private `translations/`,
  category `translations`, tied to the database) travel with every export (archive-format §4.4): without them a
  right-to-left site comes back left-to-right. Only translation files are allowed; never archive or accept PHP
  there (`*.l10n.php` caches are skipped). The verifier compares the restored front page's `<html dir>` with
  `source.textDirection`.
- Tests: `php tests/run.php --suite=migration` (creates/reset the disposable target in `wp-target/`). Never restore into `wp/`.
