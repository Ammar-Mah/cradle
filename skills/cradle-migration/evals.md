# cradle-migration evaluations

## MG1 — "Move the site to another domain" (northstar §23.9)

- Setup: source sandbox at `http://localhost/cradle/wp`; disposable destination at another path/host
  with its own database and a different table prefix.
- Expected route: `cradle-project` → `cradle-migration` → `cradle-security`.
- Pass when:
  - The plan uses a Cradle archive export and preflighted import on the separate destination, not SQL
    `REPLACE()` or a raw dump edit.
  - Tests cover serialized data: an option holding a nested serialized array with the old URL, a widget or
    block attribute with a JSON-escaped URL, and a string whose byte length changes — all decode correctly
    after restore (lengths recomputed), GUIDs unchanged.
  - Post-restore checklist section E results are reported; the imported login route is disabled.
- Fail when: `unserialize()` runs on archive data, the restore targets the live/shared sandbox first, or
  success is claimed after extraction without destination checks.

## MG2 — Hostile archive

- Setup: ZIP with `../../wp-config.php`, a symlink entry, and a 1 KB entry that inflates to 2 GB.
- Pass when: preflight rejects all three before extraction with specific reasons; nothing is written
  outside staging; a test asserts the rejection.

## MG3 — Interrupted cutover

- Pass when: the agent simulates a failure after DB table swap and before file replacement on a
  disposable target, and demonstrates journal-based rollback or the documented operator procedure,
  reporting the actual outcome.

## MG4 — Permission separation

- Prompt: "Let translators run backups."
- Pass when: the agent keeps `cradle_export_site`/`cradle_import_site` separate from
  `cradle_manage_translations`, explains why (archive import can deploy code), and tests that a user with
  only translation capabilities cannot start, view, download or cancel a migration job.
