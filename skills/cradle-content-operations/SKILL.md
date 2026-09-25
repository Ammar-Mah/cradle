---
name: cradle-content-operations
description: Use when duplicating posts or pages (Cradle Duplicate), generating development or fixture content (Cradle Dummy Content), cleaning up or deleting generated data, adding bulk content actions, or any operation that creates, copies or removes many records — including requests like "add a setting to delete generated posts". Produces a scoped, ownership-tracked operation with preview, authorization and evidence that only the intended records changed.
---

# Cradle content operations

## Required context

- `cradle-project` orientation; `packages/plugins/cradle-duplicate/` and
  `packages/plugins/cradle-dummy-content/` source, `AGENTS.md`, `docs/`.
- `northstar.md` §13 (Duplicate), §16 (Dummy Content), §8.7 (idempotent provisioning), §22.4–§22.5.
- `cradle-security` for every new mutation (`../cradle-security/references/request-security.md`).

## Preconditions

- Define the target set and ownership before touching anything: which post types, statuses, terms,
  attachments, meta and batches; how membership is proven.
- Ownership is proven by protected meta, never by titles, slugs or a public category:
  `_cradle_generated`, `_cradle_generation_batch`, `_cradle_generation_version`, creation time and user,
  optional original-content hash. Created terms and attachments are tracked independently.
- Financial and account records (orders, payments, customers, users, emails, webhooks) are excluded unless
  a deliberate WooCommerce-aware mode exists with live effects suppressed.
- Development environment by default; production generation requires an explicit, visible action.

## Procedure

1. Authorization: object capabilities for per-item actions (`read_post` on the source and the create
   capability of the target type for duplication; `edit_post`/`delete_post` per item for cleanup) plus
   feature capabilities `cradle_generate_dummy` and `cradle_cleanup_dummy`. POST + action-specific nonce
   (admin-post) or REST with `permission_callback`; never a plain GET link.
2. Duplicate (Cradle Duplicate): new draft, unique slug, current timestamps, explicit author policy;
   copy title, content (block markup intact), excerpt, featured-image reference, template, allowed terms
   (only if the user can `assign_terms`), allowlisted meta; exclude locks, revision bookkeeping, secrets,
   `_cradle_generat*` ownership, order/payment meta, unrelated plugin state; reuse attachments; record
   `_cradle_duplicate_of`; extend via the documented filter only for safe fields. Synced pattern
   references stay shared.
3. Generate (Cradle Dummy Content): bounded count, seed for reproducibility, local vocabularies and
   approved local media only, drafts by default, batch name/note; write ownership meta on every created
   post, term and attachment; no real users, orders, emails or external requests.
4. Cleanup: preview counts by entity type and batch; flag items modified since creation (hash or
   `post_modified` vs creation) and items referenced elsewhere (attachments used by non-generated
   content, terms assigned to real posts); default to trash, permanent purge as a separate action; delete
   through WordPress APIs (`wp_trash_post`, `wp_delete_post`, `wp_delete_term`, `wp_delete_attachment`)
   in resumable bounded batches (`$core->jobs()`); keep an operation report; never delete pre-existing
   assets that were merely selected during generation.
5. Log each operation with `cradle_log_activity()` (counts and batch IDs, no content dumps).
6. Update `docs/project-state.md` with batches created or removed in the sandbox; remove your fixtures.

## Verification

- Duplicate a page with nested blocks, terms, featured image and allowed meta: result is a draft, opens
  without invalid blocks, source unchanged, `_cradle_generated*` not copied; a contributor/subscriber
  without rights gets 403 and no post is created (CR-16, CR-26).
- A duplicated generated post is not swept by the next cleanup of its source batch.
- Generate a batch, edit one item, attach one generated image to a real page, then clean up: only
  unmodified, unshared batch items are trashed; the edited and shared items are reported (CR-19).
- Cleanup of batch A leaves batch B and real content untouched (count before/after by meta query).
- Interrupted cleanup resumes without errors or double deletes.
- Tests in `tests/integration/duplicate.php` / `dummy-content.php`; `php tests/run.php --suite=<name>`.

## Required output

Target-set definition, ownership markers, preview numbers, affected IDs or counts, authorization and
denied-role evidence, flagged items, report location, not-run checks.

## Failure behavior

- Ownership cannot be proven for an item: exclude it and report it.
- Preview and actual counts differ at execution: stop and re-preview.
- A request for automatic deletion on setting save or on deactivation: replace with an explicit,
  previewed action (deactivation never deletes data).

## References

- `northstar.md` §13, §16, §22.4–§22.5; acceptance CR-16, CR-19, CR-26, CR-29
- Related skills: `cradle-security`, `cradle-plugin-development`, `cradle-woocommerce` (product fixtures)
