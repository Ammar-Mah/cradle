# Cradle Dummy Content — feature contract

Northstar §16, acceptance CR-19: *batch ownership is recorded; cleanup preserves
real/shared/edited content according to policy.*

Screen: **Cradle → Dummy content** (`admin.php?page=cradle-dummy-content`), tabs *Batches &
cleanup*, *Generate* (needs `cradle_generate_dummy`) and *Limits* (needs `manage_options`).
A batch page (`&batch=UUID`) shows the settings used, running jobs, the cleanup preview,
cleanup actions and the operation reports.

## Generation

| Option | Rules |
| --- | --- |
| Post type | Public types with an admin UI, except attachments, WooCommerce products/variations/orders/refunds/coupons/subscriptions and batch records. Filter `cradle_dummy_content/post_types` may remove types. User needs the type's `create_posts`. |
| Count | 1 … `max_items_per_batch` (setting, default 50, hard ceiling 500). |
| Status | draft (default; setting `default_status`), pending, private, publish. private/publish need `publish_posts`. |
| Authors | Existing users who can write the type (multi-select; items rotate deterministically). Others than yourself need `edit_others_posts`. Users are never created. |
| Titles | headline, question, numbered, or a custom template (≤ 120 chars) with tokens `{n} {adjective} {noun} {verb} {word} {batch} {type}` only. |
| Body | article (paragraphs + headings + one list), paragraphs, or one short paragraph; paragraph range 1–20; English sample vocabulary or Latin placeholder words (`data/*.json`). Output is serialized core blocks: `core/paragraph`, `core/heading`, `core/list` + `core/list-item`. Optional excerpt. |
| Dates | Optional range (YYYY-MM-DD, not in the future); each item gets a deterministic time inside it. Empty = now. |
| Terms | Per taxonomy: reuse selected existing terms (referenced, never deleted), generate up to `max_generated_terms` new ones (owned), and assign 1–5 per item. Needs `assign_terms` (+ `edit_terms` to generate). New names never claim an existing term. |
| Custom fields | Up to 5 rows `key` + type (word, sentence, number, date, boolean). Keys must be public (`^[A-Za-z][A-Za-z0-9_-]{0,63}$`, not protected, not `footnotes`). Registered public scalar keys are suggested. |
| Featured images | none; **library** — images already in the Media Library that you select (referenced, never deleted by cleanup); **placeholder** — 1…`max_placeholder_images` PNGs drawn locally with GD (owned by the batch). Nothing is downloaded. |
| Relationships | Hierarchical types: nest ~60 % of items under earlier items of the batch. Any type: store up to 3 earlier item IDs in a named public field. |
| Seed | `[A-Za-z0-9_-]{1,64}`; empty = random, stored with the batch. The same seed and settings reproduce the same titles, bodies, excerpts, field values, dates, term names, author/term/image picks and relationships (by item index). |
| Batch name + note | Required name ≤ 80 chars; note ≤ 500 chars. |
| Production guard | If the environment is production, the form shows the scope and requires typing the site host. |

Submitting creates a batch record and a `dummy-content/generate` job, then opens the batch page
where core's job runner (`data-cradle-job` markup) drives it. Phases: terms → placeholder images
(one per step) → posts (`items_per_step` per step) → finalize (report). Without JavaScript,
"Run next step" posts one runner call. Cancelled/failed generation can be **resumed**: a new
job skips every index that already exists.

Side effects are suppressed: no pingbacks/enclosures/update-service pings (`_publish_post_hook`
is detached during inserts and `_pingme`/`_encloseme` removed), ping status closed.

## Ownership

Each generated post and placeholder attachment: `_cradle_generated=1`, `_cradle_generation_batch`
(UUID), `_cradle_generation_version` (1), `_cradle_generated_at` (Unix time), `_cradle_generated_by`
(user ID), `_cradle_generation_index` (`post:N` / `image:N`) and `_cradle_generation_hash`
(SHA-256 of post ID + title + content + excerpt as stored). Generated terms carry the same markers
(except the hash) as term meta. The batch record also lists created term/attachment IDs and the
pre-existing items it merely referenced.

## Cleanup

Preview (per batch): generated posts by type and status, items edited since generation (hash
mismatch — this also catches copies that inherited markers) and items in use (classic menu items,
front page, posts page); owned terms and how many are used by other content; owned placeholder
images and how many are used elsewhere (featured image, `wp-image-ID`/block `id`, file name in
content, site icon/logo); referenced pre-existing media/terms (always kept).

| Action | Effect |
| --- | --- |
| **Move to trash** (default) | `wp_trash_post()` for unedited, unused generated posts. Terms and images stay so trashed items can be restored intact. If trash is disabled (`EMPTY_TRASH_DAYS` 0) items are kept, never force-deleted. |
| **Purge** (separate form, explicit confirmation) | `wp_delete_post( …, true )` for generated posts (trashed or not; revisions/meta/relationships go with them), then `wp_delete_term()` for owned terms with no remaining relationships, child terms or menu items, then `wp_delete_attachment( …, true )` for owned images nothing uses. |
| Include edited / in-use items | Opt-in checkbox for either action. |

Cleanup runs as a `dummy-content/cleanup` job; its report (counts of trashed, deleted, kept
edited, kept in use, kept shared terms, kept images, failures, first 200 kept IDs) is written to
the batch record after **every** step, so cancelling never loses what was done. Starting cleanup
again resumes from the remaining marked items. A batch whose markers exist without a record
(for example after a database import) appears as "Unrecorded batch" and can be cleaned; a
minimal record is created to hold the report. A record can be removed once it owns nothing.

## PHP API

`Generator::start( array $request )` → `{ batch, job }` | `WP_Error` (field errors in `data['errors']`);
`Generator::resume( array $batch )`; `Cleanup::preview( string $uuid )`;
`Cleanup::start( string $uuid, 'trash'|'purge', bool $include_edited )` → job | `WP_Error`;
`Batches::get( $uuid )`, `Batches::all()`; `Ownership::batch_posts()/batch_terms()`. All mutating
calls check the capabilities of the current user; drive jobs with `cradle()->jobs()->run( $uuid )`.
