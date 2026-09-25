# Cradle Content — fields and relationships

Field groups attach a bounded set of fields to post types (`object: post`) or taxonomies (`object: term`).
A field's storage key is `_cradle_cf_<key>`; keys are 1–40 chars `[a-z][a-z0-9_]*`, unique within a group and
unique per target across enabled groups (they share the meta namespace). No setting accepts PHP, SQL, callbacks,
expressions or templates.

## Types

| Type | Stored as | Settings | Validation |
| --- | --- | --- | --- |
| `text` | string | max length (≤1000, default 200), default | UTF-8, length, `sanitize_text_field`. |
| `textarea` | string | max length (≤10000, default 2000), default | UTF-8, length, `sanitize_textarea_field`. |
| `rich_text` | string | max length (≤20000, default 5000) | Allowlist: `a[href,rel] strong em b i br p ul ol li code`; protocols http, https, mailto. |
| `number` | number / integer | min, max, step, whole numbers only, default | Numeric, finite, range, integer check. |
| `boolean` | boolean | default | on/off values only. |
| `url` | string | default | http/https with host, ≤2000 chars. |
| `email` | string | default | `is_email`, ≤254 chars. |
| `date` | string `YYYY-MM-DD` | default | Pattern + `checkdate`. |
| `select` | string | choices (≤100, `value|Label` lines; value `[A-Za-z0-9_.-]{1,64}`), default | Must be a listed value. |
| `multiselect` | one row per value | choices, max selections | Listed values, unique, max count. |
| `attachment` | attachment ID | allowed media types (image, video, audio, PDF, text) | Exists, is an attachment, MIME prefix, `read_post` for new values. |
| `post_ref` | post ID(s) | allowed post types (required), multiple, max selections | Exists, allowed type, not trashed/auto-draft, current user can `read_post` it. |
| `user_ref` | user ID(s) | allowed roles (empty = any), multiple, max selections | Exists, role allowed, current user has `list_users` or references themself. |
| `term_ref` | term ID(s) | allowed taxonomies (required), multiple, max selections | Exists in an allowed taxonomy, current user can `assign_terms` there. |
| `group` | list of row objects | max rows (≤50, default 10); sub-fields | Rows validated per sub-field; empty rows dropped; unknown keys dropped. |

**Repeated groups.** Sub-fields are separate field rows whose "Parent group key" is the group's key. Allowed sub-field
types: text, textarea, number, boolean, url, email, date, select, attachment, post_ref, term_ref (single values only);
one level of nesting. Stored JSON-schema shape:
`[{ "<sub>": value, … }]` with empty values `""` (text-like), `null` (number), `false` (boolean), `0` (references).

Permission checks on references apply to **new** values: a value that is already stored is accepted unchanged, so an
editor saving a post does not lose references another user was allowed to set.

## Exposure

| Exposure | REST | Where edited |
| --- | --- | --- |
| `public` | `show_in_rest` in view and edit context; unreadable post references are stripped for viewers who cannot read them. Usable in the `cradle-content/field` block binding. | Block editor panel (or classic box for non-block-editor types). |
| `editor` (default) | Registered with schema context `edit`; removed from non-edit responses by `rest_prepare_<subtype>`. | Block editor panel. |
| `none` | Not in REST. | Classic meta box / term forms only. |

When any REST-exposed field targets a post type, `custom-fields` support is added automatically (WordPress only
includes `meta` in the posts endpoint schema for types that support it).

## Enforcement

- `register_post_meta()` / `register_term_meta()` with `type`, `single`, `default`, `sanitize_callback`,
  `auth_callback` (`edit_post` / `edit_term` on the object) and `show_in_rest` schema.
- REST writes: `rest_request_before_callbacks` validates every Cradle meta value (including reference permissions)
  and returns HTTP 400 before the post/term is touched.
- Low-level writes (`update_post_meta()` from other code): the sanitize callback re-validates structurally and returns a
  rejection marker for invalid values; `add/update_{post,term}_metadata` short-circuit filters refuse the write instead of
  storing a coerced value.
- Classic forms: nonce `cradle_content_fields_<post ID>` / `cradle_content_term_<term ID>` plus `edit_post`/`edit_term`;
  invalid fields keep their previous value and are reported in an admin notice.

## Block editor UI

`assets/js/editor.js` registers one `PluginDocumentSettingPanel` per group (plain `wp.element.createElement`):
TextControl, TextareaControl, ToggleControl, SelectControl, CheckboxControl lists, `MediaUpload` for media,
`ComboboxControl` + `/cradle-content/v1/lookup` for references, and a row editor (add, move, remove, max rows)
for repeated groups. Values are written with `editPost( { meta } )`, so they persist with the post's normal save.
Validation errors from the server appear as the editor's "Updating failed" notice.

## Front end

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"cradle-content/field","args":{"key":"isbn"}}}}} -->
<p></p>
<!-- /wp:paragraph -->
```

Only `public` fields of posts the viewer may read are output. Images/buttons can bind `url` to an attachment or
post reference field.

## Known limits

- Changing a field between single and multiple storage does not convert existing rows.
- Renaming a sub-field inside a repeated group is not migrated.
- Meta revisions (`revisions_enabled`) are not enabled; field values are not part of post revisions.
- Classic reference selects list at most `select_limit` choices (plus current values).
