# Restore checklist

Source: northstar §15.4–§15.10. Use a disposable destination. Tick each item with evidence; an
unavailable check is "not run", never "passed".

## A. Before export

- [ ] Operator shown what is excluded (wp-config.php, secrets, caches, logs, prior archives, VCS, files
      outside roots, DNS/email/TLS/server packages) and what sensitive data the DB contains.
- [ ] Consistency strategy chosen and reported: DB snapshot + file strategy, or maintenance window.
- [ ] Unsupported objects (views, triggers, routines, generated columns, unknown table engines) listed.
- [ ] Archive written only to protected storage (`is_protected()` true or `CRADLE_PRIVATE_DIR` outside web root).
- [ ] Manifest: format version, source versions (WordPress, PHP, DB, packages), URLs, paths, table prefix,
      file inventory with sizes and hashes, parts with checksums, scope, timestamp.

## B. Import preflight (no destination change yet)

- [ ] Capability `cradle_import_site`, nonce, POST; job owner recorded.
- [ ] Target identity confirmed (site URL, DB name/prefix shown, not a production target unless explicitly authorized).
- [ ] Format version supported; runtime and extensions compatible (ZipArchive, mysqli, mbstring, JSON).
- [ ] Free disk space ≥ staged size + backup size + margin.
- [ ] Inventory validated before extraction: no absolute paths, `..`, null bytes, duplicate or
      case-conflicting names, device/special files, symlinks; expected top-level layout only.
- [ ] Limits enforced: compressed size, total uncompressed size, file count, depth, per-file ratio.
- [ ] Per-part and per-file checksums verified (integrity only; not publisher authenticity).
- [ ] Replacement preview shown: tables replaced, files replaced, URL/path/prefix map, which administrator
      account will work after restore, login-route implications, external services (email, payment,
      webhooks, cron) affected.
- [ ] Staging directory protected and canonical containment checked for every extracted path.
- [ ] Destination recovery checkpoint created and verified (restorable backup of tables and replaced files).
- [ ] No other import/export/update running (lock held).

## C. Staging and transformation

- [ ] Imported PHP never included or executed during inspection.
- [ ] Rows imported into staging tables (new prefix) in idempotent batches; retries do not duplicate rows.
- [ ] URL/path replacement by structural parser: plain URLs, path prefixes, trailing slashes, JSON-escaped
      (`https:\/\/`), nested arrays/serialized strings with recomputed byte lengths, block attributes,
      Unicode, binary columns untouched, opaque plugin values preserved or preflight-failed.
- [ ] Table prefix change: option names (`<prefix>user_roles`) and usermeta keys (`<prefix>capabilities`,
      `<prefix>user_level`) renamed.
- [ ] GUIDs not rewritten.
- [ ] Staged data validated (row counts, sample decode, `siteurl`/`home` set to destination values).

## D. Cutover

- [ ] Journal written before each step (old→new table map, file moves) and after completion.
- [ ] Recovery context is job-scoped, expiring, stored outside the tables being replaced; no public
      recovery script.
- [ ] Destination DB credentials and `wp-config.php` untouched; destination URLs preserved.
- [ ] Imported custom login route disabled; sessions cleared; imported application passwords handled
      per the documented policy.
- [ ] Safety profile applied: outgoing email suppressed, payment gateways in test/disabled mode,
      webhooks/cron paused until verified.
- [ ] Browser session loss handled: operator told which account works and how to resume.

## E. Post-restore verification (before `completed`)

- [ ] Front page, a content page, single, archive, media, search.
- [ ] Administrator login, REST request, editor save and reopen.
- [ ] Parent/child relationship, active plugins available, content types and navigation registered.
- [ ] Translation catalogs and font URLs resolve.
- [ ] Permalinks flushed; caches invalidated.
- [ ] WooCommerce: catalog, order visibility (HPOS), stock, protected checkout test (no real payment).
- [ ] Scheduled tasks and external services under the destination profile.
- [ ] Staging files and backups not publicly readable (HTTP probe returns 403/404).

## F. Required failure tests (disposable target)

| Test | Expected |
| --- | --- |
| Bad checksum | Preflight fails; destination unchanged. |
| Missing archive part | Preflight fails naming the part. |
| Interrupted upload, resume | Resumes without duplicate parts. |
| Expired lease + duplicate chunk | Old worker's commit rejected (fencing); no duplicate rows/files. |
| Invalid path / symlink escape / decompression bomb | Rejected before extraction. |
| Disk full / DB failure mid-import | Job fails with a recoverable state; checkpoint intact. |
| URL + prefix change with nested serialized values | Values decode; lengths correct; site loads. |
| Unsupported data type | Preflight names the record; no silent omission. |
| Crash after DB cutover, before file replacement | Journal-driven recovery or documented operator steps succeed. |
| Admin session change during import | Operator can resume or recover with documented account. |
| Imported custom login route | Disabled after restore until re-verified. |
| HPOS store | Orders visible and consistent after restore. |
| Rollback | Full rollback, or clearly documented operator recovery when automatic rollback cannot complete. |

## G. Operator recovery guide must cover

Interrupted restore before cutover (discard staging), failure during DB cutover (swap tables back using the
journal), failure between DB and file cutover (restore files from checkpoint), lost browser session
(resume via authenticated runner or host-level recovery), and how to verify the site afterwards.
