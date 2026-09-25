# Cradle Dummy Content — security notes (northstar §20)

## Trust boundaries and request integrity

| Request | Protection |
| --- | --- |
| `admin-post.php?action=cradle_dummy_generate` | POST only (405 otherwise), nonce `cradle_dummy_generate`, `cradle_generate_dummy`, then per-field validation in `Request::validate()` including object capabilities (type `create_posts`/`publish_posts`, `edit_others_posts` for other authors, `assign_terms`/`edit_terms`, `upload_files` for placeholders, `read_post` for selected media). |
| `admin-post.php?action=cradle_dummy_cleanup` | POST only, nonce `cradle_dummy_cleanup_{uuid}`, `cradle_cleanup_dummy`, UUID validated, purge needs an explicit confirmation field. |
| `admin-post.php?action=cradle_dummy_job` (run / cancel / resume, no-JS) | POST only, nonce `cradle_dummy_job_{op}_{job}`, job must belong to this module and to the posted batch, `Jobs::user_can_access()` (job capability + owner or `manage_options`). |
| `admin-post.php?action=cradle_dummy_forget` | POST only, nonce per batch, `cradle_cleanup_dummy`, refused while the batch owns anything. |
| Core job AJAX (`cradle_job_step/status/cancel`) | Core: `cradle_jobs` nonce + job capability + ownership. Steps re-check the module capability. |
| Screen and GET parameters | Render only. `tab`, `batch`, `dc_type` are sanitized; unknown batches show an error. |

Editors, authors and subscribers hold neither capability by default; the generation and cleanup
actions refuse them even with a valid nonce of their own (tested).

## Validation before normalization

Every generation field is type-checked (scalar vs list), range/allowlist-checked, then
normalized (see `docs/feature-contract.md`). Custom title templates accept only known tokens.
Custom field keys must be public and non-reserved, so generation can never write protected keys
(such as ownership markers) or `footnotes`. The seed is restricted to `[A-Za-z0-9_-]`.

## Ownership and deletion safety

- Cleanup selects records only through protected markers joined on batch UUID, never titles/slugs/term names.
- Marker meta is protected and has a false `auth_callback`: it cannot be edited through the custom
  fields UI, XML-RPC or REST, and is not exposed in REST responses.
- The content hash binds to the post ID, so a copy that inherited markers counts as edited and is kept.
- Deletion uses `wp_trash_post()`, `wp_delete_post()`, `wp_delete_term()`, `wp_delete_attachment()`.
- Shared terms/images (used by anything that remains) and referenced pre-existing items are kept.
- Trash mode never force-deletes (when trash is disabled items are kept and reported).

## No incidental side effects

No HTTP requests, no downloaded media (placeholders are drawn with GD), no users created, no
emails, no WooCommerce types, no pings/enclosures. Vocabulary files are fixed JSON data files
loaded from an allowlisted path. Nothing is evaluated; structured data is stored as JSON.

## Operational controls

Batch size and step size are bounded by settings with hard ceilings; jobs retry at most 3 times;
cleanup progress and reports are persisted per step; activity is logged without content.
Production generation requires typing the site host (`cradle_dummy_content/is_production` can
extend the guard to staging-like environments).
