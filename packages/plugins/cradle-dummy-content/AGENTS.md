# Cradle Dummy Content — package instructions

Feature plugin for northstar §16 (acceptance CR-19). Module id `dummy-content`, text domain
`cradle-dummy-content`, capabilities `cradle_generate_dummy` and `cradle_cleanup_dummy`
(administrators only). Requires the Cradle core plugin (API `^1.0`). Read the repository
`AGENTS.md`, `docs/architecture/core-api.md` and `docs/architecture/plugin-conventions.md` first.
Feature contract: `docs/feature-contract.md`; data model and lifecycle: `docs/data-model.md`;
security: `docs/security.md`.

## Scope

Generate reproducible sample content in tracked **batches** and remove exactly what a batch
generated. Generation and cleanup run as bounded, resumable core jobs. No network access, no
faker/AI packages, no remote media, never creates users, orders, payments, emails or pings.

## Layout

| Path | Purpose |
| --- | --- |
| `cradle-dummy-content.php` | Bootstrap (header, autoloader, lifecycle, descriptor, core-missing guard). |
| `src/class-module.php` | Descriptor + `boot()`: settings, batch record type, marker meta, job types, admin. |
| `src/class-request.php` | Validates a generation request field by field for the current user. |
| `src/class-generator.php` | `start()`, `resume()` and the `dummy-content/generate` job step. |
| `src/class-cleanup.php` | `preview()`, `start()`, usage checks and the `dummy-content/cleanup` job step. |
| `src/class-ownership.php` | Marker keys, content hash, marker queries. |
| `src/class-batches.php` | Batch records (`cradle_dummy_batch` posts) and operation reports. |
| `src/class-content-builder.php`, `src/class-vocabulary.php`, `src/class-random.php` | Deterministic text + block markup from bundled vocabularies (`data/*.json`). |
| `src/class-images.php` | Local placeholder PNGs drawn with GD. |
| `src/class-config.php` | Settings section `dummy-content` (limits; `manage_options`). |
| `admin/class-admin.php` | Cradle → Dummy content screen and its POST handlers. |
| `assets/js/dummy-admin.js`, `assets/css/dummy-admin.css` | Progressive enhancement for the screen. |
| `uninstall.php` | Retain by default; purge removes records/settings/caps, never generated content. |

## Rules for changes

- Ownership is proven **only** by the protected markers (`_cradle_generated`, `_cradle_generation_*`,
  `_cradle_generated_at/_by`). Never select content for cleanup by title, slug or term name.
- Every created record gets its markers in the same `wp_insert_*` call (`meta_input` / term meta
  right after insert) and is findable by batch + index so retried steps stay idempotent.
- The content hash includes the post ID: copies that inherit markers are treated as edited.
- Cleanup defaults to trash; purge is a separate explicit action; edited or in-use items are kept
  unless explicitly included; terms/attachments are deleted only when nothing else uses them;
  items that were merely selected (existing terms/media) carry no markers and are never deleted.
- Generation must never write protected meta chosen by the user (`Request::validate_meta_key()`),
  create users, or trigger pings/enclosures (`_publish_post_hook` is suspended during inserts).
- Production (`wp_get_environment_type() === 'production'`, filter `cradle_dummy_content/is_production`)
  requires typing the site host.
- Job steps must stay bounded (`items_per_step`) and re-check capabilities.

## Public hooks

`cradle_dummy_content/post_types` (restrict offered types), `cradle_dummy_content/is_production`.

## Verification

`php tests/run.php --suite=dummy-content` (tests/integration/dummy-content.php). The suite purges
every batch it creates at shutdown, including edited items, and removes its fixtures.
