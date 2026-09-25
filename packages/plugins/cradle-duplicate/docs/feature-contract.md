# Cradle Duplicate — feature contract

Northstar §13, acceptance CR-16: *a protected one-click operation produces a correct draft and
excludes unsafe metadata.*

## Who may duplicate

For the acting user, all of the following must hold (`Policy::check()`):

1. Logged in.
2. `read_post` on the source (otherwise the answer is "not found", so existence is not revealed).
3. The source type is enabled (settings + `cradle_duplicate/post_types`) and is not in
   `Policy::NEVER_TYPES` (attachments, revisions, menus, templates, synced patterns, global
   styles, fonts, navigation, WooCommerce products/variations/orders/refunds/coupons/subscriptions).
4. The source is not in the trash, not an auto-draft and not a revision.
5. `edit_post` on the source **and** the type's `create_posts` capability.
6. `cradle_duplicate/can_duplicate` did not return anything other than `true` (it can only deny).

Examples with default roles: administrators and editors may duplicate any post or page; authors
may duplicate their own posts but not others' posts or pages; contributors may duplicate only
their own unpublished posts (which they can already edit); subscribers never.

## Entry points

| Where | No JavaScript | With JavaScript |
| --- | --- | --- |
| Posts/Pages list row action "Duplicate" | Link to the confirmation screen (GET, read-only) whose form POSTs to `admin-post.php` with nonce `cradle_duplicate_post_{ID}` | Click POSTs to `/wp-json/cradle-duplicate/v1/posts/{ID}` via `wp.apiFetch` (REST nonce), then opens the new draft |
| Classic editor Publish box "Duplicate as new draft" | same | same |
| Block editor: Summary panel button + Options (⋮) menu item | — | REST POST, then opens the draft; disabled while the post is new, dirty or saving (the saved version is copied) |

After duplication the user lands on the new draft's edit screen (`…&cradle_duplicated=1`), which
shows a notice naming the source. When the content references synced patterns (`core/block` with
`ref`), the confirmation screen, the editor panel and the post-duplication notice explain that the
copy keeps sharing those patterns and how to detach one.

REST response (201): `{ id, source, status, title, editLink, syncedPatterns, metaCopied, metaSkipped }`.
Errors: 401 (not logged in / no REST nonce), 403 (not allowed / unsupported type), 404 (not found
or not readable), 409 (trash/auto-draft or a concurrent duplicate of the same item by the same user).

## What the copy contains

| Field | Behavior |
| --- | --- |
| Status | Always `draft` (enforced after filters). |
| Dates | Current (`post_date` now, draft date floating as WordPress does for drafts). |
| Slug | Source slug made unique for the type/parent as if published (`about` → `about-2`). |
| Title | Source title + configured suffix (default translated "(Copy)"), filter `cradle_duplicate/title`. |
| Content | Source `post_content` unchanged (block serialization preserved; synced pattern refs stay shared). Passes normal `wp_insert_post()` content filters for the acting user. |
| Excerpt, comment/ping status, post password, parent, menu order | Copied. |
| Author | Author policy (below). |
| Featured image | Same attachment ID referenced; files/attachments are never cloned. |
| Page template | `_wp_page_template` copied as-is. |
| Terms | Every taxonomy of the type (filter `cradle_duplicate/taxonomies`) where the user has `assign_terms`; others are skipped and reported. |
| Metadata | See the metadata policy. |
| Provenance | `_cradle_duplicate_of` = source ID (protected, not in REST). |

### Author policy

Setting `author_policy`: `current` (default — the person duplicating) or `original` (keep the
source author, only when the acting user has the type's `edit_others_posts`; otherwise the acting
user). Filter `cradle_duplicate/author( $author, $source, $user_id )`; a filtered value is accepted only
if it is the acting user, or an existing user and the acting user has `edit_others_posts`.

### Metadata policy (evaluated per key, in this order)

1. **Fixed exclusions (never copied, not filterable):** `_edit_lock`, `_edit_last`, `_wp_old_slug`,
   `_wp_old_date`, `_wp_desired_post_slug`, `_wp_trash_*`, `_encloseme`, `_pingme`, `_trackbackme`,
   `enclosure`, `_oembed_*`, attachment internals (`_wp_attached_file`, `_wp_attachment_*`),
   `_menu_item_type`, keys handled explicitly (`_thumbnail_id`, `_wp_page_template`),
   `_cradle_duplicate_of`, `_cradle_source_key` (provisioning identity of site records — a copy must
   never claim the source's identity), and every `_cradle_generat*` key (Cradle Dummy Content ownership markers).
2. **Deny patterns (filter `cradle_duplicate/meta_denylist`):** keys whose `_`/`-`/`.`/`:`-separated
   segments look like secrets (`password`, `secret`, `token`, `api_key`, `auth`, `nonce`, `session`,
   `license_key`, `signature`, `webhook`, …) or order/payment data (`order`, `payment`, `transaction`,
   `billing`, `shipping`, `invoice`, `refund`, `customer`, `card`, `iban`, `stripe`, `paypal`, …).
   Matching is deliberately conservative (for example `shipping_note` is not copied).
3. **Private/protected keys** (`is_protected_meta()`, normally underscore-prefixed, including other
   `_cradle_*` keys): copied only when allowlisted — settings field "Extra private metadata to copy"
   or filter `cradle_duplicate/meta_allowlist` (exact key or `prefix*`).
4. Everything else (public custom fields such as `footnotes` or ACF-style public keys) is copied with
   all values, byte-exact (strings are re-slashed for the meta API).

Keys that another plugin already added to the new draft during insert are not stacked.

## Settings (Cradle → Duplicate, capability `cradle_manage_duplicate`)

`post_types` (default post + page; only eligible types are offered), `title_suffix` (≤ 60 chars),
`author_policy`, `extra_meta_keys` (≤ 50 keys/prefixes). Standard Cradle section behavior applies
(stale-edit protection, reset, JSON export/import).

## Hooks reference

| Hook | Type | Purpose |
| --- | --- | --- |
| `cradle_duplicate/post_types` | filter `string[]` | Enabled types (NEVER_TYPES removed afterwards). |
| `cradle_duplicate/can_duplicate` | filter `bool, WP_Post, int $user_id` | Further restrict; only `true` allows. |
| `cradle_duplicate/meta_allowlist` | filter `string[], WP_Post` | Private keys/prefixes to copy. |
| `cradle_duplicate/meta_denylist` | filter `array<string,regex>, WP_Post` | Deny patterns (trusted code only). |
| `cradle_duplicate/taxonomies` | filter `string[], WP_Post` | Taxonomies whose terms are copied. |
| `cradle_duplicate/title` | filter `string, WP_Post` | New title. |
| `cradle_duplicate/author` | filter `int, WP_Post, int` | New author (re-validated). |
| `cradle_duplicate/post_data` | filter `array, WP_Post` | `wp_insert_post()` data; status/type/dates/ID/meta_input/tax_input enforced afterwards. |
| `cradle_duplicate/duplicated` | action `int $new_id, WP_Post $source, array $report` | After the draft, terms and meta exist. |

PHP API: `Cradle\Duplicate\Duplicator::duplicate( int $post_id )` (acts as the current user, full checks)
returns `{ id, source, edit_link, report }` or `WP_Error`; `Duplicator::preview( WP_Post )` is read-only.
