# Cradle Migration — operator recovery guide

For an interrupted restore or a failed database/file cutover. Written for the person who
can run PHP on the server as the site owner (SSH, hosting terminal, or local shell).

> **Tested:** steps 3A (finish after a crash between the database cutover and file
> replacement), 3B (roll back), and 4 (minimal-bootstrap rollback after a crash in the
> middle of file replacement) are exercised by `php tests/run.php --suite=migration`
> against a disposable second WordPress (`wp-target/`, prefix `ct_`), including a real
> process crash (`--fault=after-db-cutover`, `--fault=after-file-op=1`). Step 5 (manual
> table renames) is the documented last resort and is **not** automated or tested.

## 0. What a restore changes, and where the recovery state lives

A restore goes through these journaled stages (see `status` output → "Journal stage"):

| Stage | Live site state |
| --- | --- |
| `not_started` | Nothing changed. Staged tables `cmig_<t>_*` and files under `jobs/<uuid>/tree` exist beside the live site. |
| `db_cutover_in_progress` | One `RENAME TABLE` statement is swapping tables. |
| `db_swapped` | Imported tables are live under your prefix; your previous tables are kept as `cold_<t>_*`. Files not yet replaced. Visitors get **503 maintenance**. |
| `files_in_progress` / `files_replaced` | Top-level upload folders and theme/plugin folders are being moved: old copies go to `jobs/<uuid>/backup/`, imported copies come from `jobs/<uuid>/tree/`. |
| `finalized` | Imported plugins re-activated, maintenance window closed, safety hold active. |
| `verified` / `rolled_back` | Done. |

`<t>` is the first 6 hex characters of the job UUID (without dashes). The recovery state is
**not** in the tables being replaced:

- `<private storage>/migration/jobs/<uuid>/journal.jsonl` — append-only log, each line written
  before the operation it announces.
- `<private storage>/migration/jobs/<uuid>/recovery.json` — table plan, destination identity,
  the CLI command, and the hash of the one-time browser recovery token.
- `<private storage>/migration/jobs/<uuid>/file-plan.json` — every file move, in order.
- The job row in `{prefix}cradle_jobs` (Cradle's own runtime tables are never swapped).

`<private storage>` is `CRADLE_PRIVATE_DIR` when defined in `wp-config.php`, otherwise
`wp-content/uploads/cradle-private-<random>/`. Nothing there is reachable over HTTP.

In every command below, `BIN` is
`php wp-content/plugins/cradle-migration/bin/cradle-migrate.php --path=/path/to/wordpress`
(run from anywhere; `--path` is the WordPress root that contains `wp-config.php`).

## 1. Look before touching anything

```
BIN status                     # recent migration jobs; which one is not finished
BIN status --job=<uuid>        # journal stage, each table (live/staged/old), file operations done/total
```

Do not delete `cmig_*`/`cold_*` tables or the job directory while a restore is unfinished:
they are the way forward and the way back.

## 2. The browser tab closed or the session expired

After cutover the browser session is intentionally invalid (users and sessions come from the
archive). The page that started the cutover keeps going with its one-time token. If that tab
is gone, nothing is lost: continue from the server with step 3A. Browser recovery without a
session is not possible by design (no public recovery script exists).

## 3. Interrupted restore, WordPress can still load (the normal case)

The critical phase runs only the Cradle core and migration plugins, so WordPress normally
boots even when the database is imported and the files are not yet replaced.

### 3A. Finish the restore (roll forward)

```
BIN recover --job=<uuid>
```

It takes over the dead worker's lease (the old worker can no longer commit), resumes at the
phase implied by the journal (`cutover_db`, `replace_files`, `finalize` or `verify`), repeats
any half-done step idempotently, and runs post-restore verification. Success prints
`Verification passed.` and the administrator logins that work now (the **source site's**
administrators with their source passwords).

### 3B. Undo the restore (roll back)

```
BIN recover --job=<uuid> --rollback      # same as: BIN rollback --job=<uuid>
```

Files first (newest operation first): imported copies are moved to `jobs/<uuid>/rolledback/`,
originals come back from `jobs/<uuid>/backup/`. Then one `RENAME TABLE` puts `cold_<t>_*`
back under your prefix and parks the imported tables as `cmig_<t>_*`. The maintenance window
is cleared and the job is marked `rolled_back`. Your original administrator accounts work again.

## 4. Interrupted restore, WordPress cannot load

Use the minimal bootstrap (`SHORTINIT`: database connection only, no plugins or theme):

```
BIN recover --job=<uuid> --raw --rollback
BIN recover --job=<uuid> --raw --complete-db      # alternative: only finish the table swap, then run 3A
```

The job directory is found automatically under `wp-content/uploads/cradle-private-*/` or
`CRADLE_PRIVATE_DIR`; pass `--dir=<.../migration/jobs/<uuid>>` if uploads were relocated.
The command refuses to run when the recovery context names a different table prefix.

## 5. Last resort: manual recovery (not automated, not tested)

Only if the CLI cannot run at all (e.g. PHP is broken). With a MySQL client:

1. Read `recovery.json` → `tables[]`: for each entry you have `live`, `staged`, `old`.
2. To return to the pre-restore site, for each table where `old` exists:
   `RENAME TABLE <live> TO <staged>, <old> TO <live>;` (skip the first half if `<live>` is missing).
   For tables that did not exist before (`liveExisted: false`): `RENAME TABLE <live> TO <staged>;`
3. `DELETE FROM <prefix>options WHERE option_name = 'cradle_migration_quiesce';`
4. For files, read `file-plan.json` and `journal.jsonl`: for every `file_op_begin` index, newest
   first, move `to` back to `from` for `backup` operations, and move `to` out of the way for
   `place` operations.
5. `UPDATE <prefix>cradle_jobs SET state='rolled_back', lease_owner=NULL WHERE uuid='<uuid>';`

## 6. After any recovery

1. Open the site and `wp-admin` (sign in as the administrator named by `recover`/`status`).
2. Review email, payment and webhook settings, then release the safety hold on
   **Cradle → Migration → Jobs & recovery** or `BIN release-safety`. Until then outgoing
   email, WP-Cron and outbound HTTP to other hosts stay paused.
3. If the imported site used a Cradle custom login URL it was disabled; re-enable it on the
   Cradle login settings screen after checking it.
4. Keep the backups until you are satisfied; then `BIN purge --job=<uuid>` (or **Purge
   backups** on the Jobs screen) drops `cold_<t>_*`/`cmig_<t>_*` and deletes the moved files.

## 7. What recovery cannot do

- It cannot undo writes made to the imported site after you released the safety hold and
  resumed traffic, other than by rolling back (which discards them).
- It does not recreate WordPress core files, `wp-config.php`, `.htaccess` or server settings
  (they are never replaced by a restore).
- It does not reverse emails, payments or webhooks triggered by third parties outside
  WordPress (inbound webhooks, server cron invoking other scripts).
- A `RENAME TABLE` of several MyISAM tables is not crash-atomic at the storage level; the
  journal plus per-table existence checks make every recovery step repeatable instead.
