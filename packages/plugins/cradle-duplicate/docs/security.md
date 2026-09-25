# Cradle Duplicate — security notes (northstar §20)

## Trust boundaries

| Input | Handling |
| --- | --- |
| REST `POST /cradle-duplicate/v1/posts/{id}` | `id` is a schema-validated integer ≥ 1. Cookie auth requires the `wp_rest` nonce (otherwise WordPress treats the request as anonymous → 401). `permission_callback` runs the full object-level check. |
| `admin-post.php?action=cradle_duplicate` | POST only; `check_admin_referer( 'cradle_duplicate_post_{ID}' )` binds the nonce to the item; then the same object-level check inside `Duplicator::duplicate()`. GET redirects to the read-only confirmation screen. |
| Confirmation screen (`admin.php?page=cradle-duplicate-confirm&post=ID`) | Read-only. Menu capability is `read`; the page itself calls `Policy::check()` and dies with 403/404 otherwise. |
| Stored source content and metadata | Treated as data. Content is re-saved through `wp_insert_post()` as the acting user (kses applies to users without `unfiltered_html`). Metadata values are copied with WordPress meta APIs; nothing is evaluated or unserialized by this plugin beyond WordPress' own meta API behavior. |
| Settings | Registered through the Cradle settings API (`cradle_manage_duplicate`, stale-edit protection). `post_types` must be eligible types; `extra_meta_keys` must match `^[A-Za-z0-9_\-.:]{1,190}\*?$` (≤ 50). |
| Third-party filters | Filters can restrict (`can_duplicate`), but fixed exclusions, draft status, post type, dates and author re-validation are enforced after filters. Product/order types cannot be added. |

## Authorization summary

Object-level: `read_post` + `edit_post` on the source, `create_posts` of the type; terms only for
taxonomies where the user has `assign_terms`; a different author only with `edit_others_posts`.
No custom capability is needed to duplicate (WordPress object capabilities decide). The settings
capability `cradle_manage_duplicate` is administrators-only.

## Data exposure

- Unreadable items answer 404 (existence is not revealed).
- The confirmation screen shows counts of copied/skipped metadata, never values or private key names.
- The REST response contains only the new draft's ID, status, title, edit link and counts.
- Provenance meta is protected and not exposed in REST.
- Activity records contain IDs and counts only.

## Abuse and integrity

- A per-user, per-item lock prevents concurrent double submissions (409).
- Attachments are never duplicated, so duplication cannot be used to multiply files on disk.
- Ownership markers of Cradle Dummy Content are never inherited, so a duplicate can never be
  removed by dummy-content cleanup (and Dummy Content also binds its content hash to the post ID).
