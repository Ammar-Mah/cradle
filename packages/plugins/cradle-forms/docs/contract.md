# Cradle Forms — feature contract (v0.1.0)

Module `forms` · plugin `cradle-forms/cradle-forms.php` · namespace `Cradle\Forms` · core API `^1.0` ·
module dependency `blocks` `^0.1`. Optional at runtime: Cradle Languages (`languages`).

Origin: based on code from the Complaint Management System by WND, GPL-2.0-or-later (field model,
upload checks, signed timing token, client network hashing, private-file streaming), adapted to Cradle
conventions. Its review findings are fixed here, not copied: privacy tools match only the field marked as
the submitter's own email; IPv4-mapped IPv6 addresses are unwrapped before rate-limit hashing (with a
`cradle_forms/client_ip` filter for trusted proxies); uploads get random stored names, the original name
is kept only as data, and JPEG/PNG metadata is removed by re-encoding when GD is available.

## Forms are content

- Post type `cradle_form`: not public, not queryable, `show_ui` (edited in the block editor; listed on
  Cradle → Forms, the core list redirects there), `show_in_rest` with `rest_base` `cradle-forms`. Every
  capability maps to `cradle_manage_forms`. Reading forms over REST (even published ones) also requires
  `cradle_manage_forms`, because settings hold notification addresses.
- Fields are blocks `cradle-forms/field-{text,email,tel,url,number,textarea,select,radio,checkboxes,date,consent,file,hidden}`
  with core `paragraph`, `heading`, `list`, `list-item`, `separator`, `spacer` between them (the only blocks
  allowed in a form). Field blocks are hidden from the inserter outside forms.
- Common attributes: `name` (`[a-z][a-z0-9_]{0,40}`, unique), `label`, `help`, `placeholder`, `required`,
  `width` (`full`/`half`). Type options: text `maxLength`, `autocomplete`, `defaultValue`; email
  `submitterEmail`; number `min`, `max`, `step`; textarea `maxLength`, `rows`; select/radio/checkboxes
  `choices` (`label`, optional stored `value`), `defaultValue`, checkboxes `minChoices`/`maxChoices`;
  date `min`, `max`; consent (rich label: a, strong, em; required by default); file `accept`
  (extensions), `maxSize` (MB), `maxFiles` (1–10); hidden `defaultValue`, `fromQuery` (query parameter).
- Schema: derived on every save (`wp_after_insert_post`) from the parsed blocks with block.json defaults
  applied; validated (names, uniqueness, labels, choices, ranges, allowed file types, one submitter email,
  no nested form, at most 100 fields). Valid → `_cradle_form_schema` (JSON: `schema_version` 1,
  `source_hash`, `updated`, `fields`). Invalid → the previous schema stays and the problems are stored in
  `_cradle_form_schema_errors`; the editor shows them after saving (REST field `cradle_form_status`).
  While the stored schema does not match the current content, visitors get the stored fields in order.
- Form settings (`_cradle_form_settings`, edited in the document panels): `submitLabel`,
  `confirmationType` (`message`/`page`), `confirmationMessage` (p, br, strong, em, a, ul, ol, li),
  `redirectPage`, `storeEntries` (default on; always on with consent or file fields), `retentionDays`
  (0 = keep), `minSeconds` (default 3), `rateLimit` (per network and form per hour, default 10, 0 = off),
  `notifications` (≤ 10: `enabled`, `to`, `routes` ≤ 20 of `{field, value, to}`, `subject` with
  `{form}`, `{site}`, `{source}` (title of the page the form was sent from; a field named `source` wins),
  `{field_name}`, `replyToField`, `includeValues`, `copyToSubmitter` default off).
- Site-wide settings section `forms` (Cradle → Forms → Settings, `cradle_manage_forms`):
  `allowed_extensions` (file fields can only narrow it; executables, scripts, HTML and SVG are always
  refused), `max_upload_mb` (the server's own limit always applies too), `fallback_recipient`.

## Embedding

- Block and component `cradle/form` (render contract `form-v1`, no theme override, dynamic, `cache: none`,
  version 1.1.0): properties `formId`, `formSlug`, `showTitle`. Shortcodes `[cradle_form id="12" title="yes"]`,
  `[cradle_form slug="contact"]` and the generic `[cradle_component id="cradle/form" form-id="12"]`
  (or `form-slug="contact"`).
- Form by address name (`formSlug`, for theme template files and patterns, where database IDs differ per
  site): a string matching `^[a-z0-9-]{1,200}$`, default `''`; any other value selects nothing. `formId > 0`
  always wins. The slug resolves to the published `cradle_form` with that `post_name` (every language is
  searched; slugs are unique per type). With Cradle Languages ready, the default-language version of that
  form is used (`cradle_default_language()` + `cradle_get_translation()`), then the usual swap to the
  current-language translation below — a slug therefore behaves like the original form's ID, even when it
  names a translation. An unknown slug renders nothing for visitors and a notice naming the slug for users
  with `cradle_manage_forms`. Example: `<!-- wp:cradle/form {"formSlug":"contact"} /-->`.
- Editor: while `formId` is 0 the block shows the slug-selected form (server-side preview with
  `formSlug`; the form picker shows the matching published form from `cradleFormsEditor.forms`, which now
  carries each form's `slug`, and a note naming the address name). Choosing a form in the picker sets
  `formId` and clears `formSlug`.
- With Cradle Languages ready, the published translation of the selected form in the current language is
  rendered (`cradle_get_translation()`), each translation being its own form post with its own entries.
  The entry language is the Cradle Languages slug, otherwise the WordPress locale; the source page is the
  translation actually rendered. `cradle_form` declares the post type support `cradle-languages` with
  `array( 'default' => 'translatable' )`, so forms are translatable unless an administrator sets them to
  shared under Cradle → Languages. "Add translation" (Cradle Languages, `POST cradle-languages/v1/posts/<form>/translations`,
  which needs `edit_post` on the form and `create_posts` of the type, i.e. `cradle_manage_forms`) creates a
  draft copy: `_cradle_form_settings` is copied (filter `cradle_languages/copy_protected_meta`, this key
  only); `_cradle_form_schema` and `_cradle_form_schema_errors` are never copied but derived again from the
  copied content, so the schema always comes from the content. Translate labels, choice labels (keep the
  stored values), consent text and messages, then publish. A page keeps embedding the original form ID;
  its translation in another language renders that language's published form translation, and the
  notification's "Sent from" link carries the language prefix (`/ar/…`). Pages may also embed a
  language's form directly.

## Submission

- Without JavaScript: `POST admin-post.php?action=cradle_forms_submit&cradle_form=<id>` (nopriv and
  logged-in actions), then 303 back to the page with `?cradle_form_result=<token>#cradle-form-<id>-summary`
  (or `-confirmation`); the one-time result (transient, 10 min) shows the confirmation or the errors with
  the submitted values. Files are never kept across an error. A post larger than `post_max_size` returns a
  clear error. Confirmation type "page" redirects there.
- With JavaScript (`view.js`): `fetch` with `FormData` to `POST /wp-json/cradle-forms/v1/submit/<id>`;
  `200 {status: "sent", message, confirmation, redirect}` or a `WP_Error` (`400` rejected, `403` origin,
  `404` unavailable, `422` invalid with `data.field_errors` and `data.form_errors`, `429` rate limit,
  `500` storage). Falls back to the normal submission when the route is unreachable.
- Checks in order: published form with fields → rate limit → honeypot (`cradle_website`) and signed token
  (`_cf_ts`: render time, form, instance, source page, language, locale; HMAC with `wp_salt()`; the older
  token without a source page is still accepted as source 0; at least `minSeconds`,
  at most 30 days old) → fields and uploads → `cradle_forms/validate` → store → notifications →
  `cradle_forms/entry_saved`. Origin: REST needs `Sec-Fetch-Site: same-origin` or an `Origin` of this
  site; admin-post refuses proven cross-site requests. Logged-in users need a nonce (form nonce `_cf_nonce`
  or the REST nonce); anonymous visitors do not (see security.md).
- Accessible errors: error summary (heading, links to the fields, focused), `aria-invalid`,
  `aria-describedby` (help, hint, error), values kept.

## Entries

- Tables (see data-model.md): entries (form, title snapshot, language, locale, status `new`/`read`/`spam`,
  UTC time, IP network HMAC, user-agent HMAC, submitter email, source page ID and title snapshot, consent JSON), values (name, label snapshot,
  type, value), files (stored name, original name, MIME, size, metadata stripped).
- Consent record per ticked consent field: `field`, `text` (exact label HTML shown), `plain`, `hash`
  (SHA-256 of `text`, the text version), `language`, `locale`, `given_at` (ISO 8601 UTC). A submission
  whose page showed another text version is refused for that field.
- Files: allowed extension (field ∩ site), every extension segment against a blocklist, real type via
  `wp_check_filetype_and_ext()` plus leading-byte signatures for common types, size ≤ field/site/server
  limits, ≤ `maxFiles`; stored as 32 hex characters without extension in `module_dir( 'forms', 'uploads' )`
  only when `is_protected()` (otherwise refused, with warnings in the admin and the editor).
- Source page ("Sent from"): the singular post the form was rendered on is signed into the render token, so
  one form shared by many pages (for example one application form on every opportunity page) tells which
  page each entry came from. Shown in the entries list, the entry detail (linked to the post's edit screen
  for users who may edit it, plain text otherwise), the CSV (`Source ID`, `Sent from`) and admin notifications.
- Admin (Cradle → Forms): forms with new/total counts; entries per form (status and date filters, bulk mark
  read/new/spam, delete, CSV export with `= + - @` neutralised); entry detail (values, file downloads,
  consent records, source page). Retention: daily cron `cradle_forms_retention`. Privacy: exporter and eraser
  `cradle-forms`, keyed only on the submitter-email field.

## Capabilities (administrators by default)

| Capability | Allows |
| --- | --- |
| `cradle_manage_forms` | Create/edit/delete forms and their settings; site-wide form settings. |
| `cradle_view_form_entries` | Read entries and consent records, download files, mark entries read/new/spam. |
| `cradle_export_form_entries` | CSV export. |
| `cradle_delete_form_entries` | Delete entries and their files. |

## Public API and hooks

| Hook / API | Signature | Purpose |
| --- | --- | --- |
| filter `cradle_forms/validate` | `( array $errors, array $values, WP_Post $form, array $fields )` → errors | Add or remove field errors (`name => message`; other keys become form errors). |
| action `cradle_forms/entry_saved` | `( array $entry, WP_Post $form )` | After a successful submission (`$entry['id']` 0 when not stored): `values`, `fields`, `files`, `consent`, `language`, `locale`, `source_id`, `source_title`. For newsletter-provider integrations (none built in). |
| filter `cradle_forms/notification_args` | `( array $args, array $context )` → args | `to`, `subject`, `message`, `headers`; context `kind` (`notification`/`copy`), `form`, `entry`, `notification`. Empty `to` skips the email. |
| filter `cradle_forms/client_ip` | `( string $ip )` → ip | Client IP for rate limiting/hashing behind a trusted proxy. |
| filter `cradle_forms/disk_reserve_bytes` | `( int $bytes )` | Free space that must remain after storing an upload (100 MB). |
| `cradle_render_component( 'cradle/form', array( 'formId' => 12 ) )` | | PHP rendering (or `array( 'formSlug' => 'contact' )`). |
| REST `POST cradle-forms/v1/submit/<form>` | multipart/urlencoded | Submission (see above). |
| filter `cradle/translate_label` (priority 20) | `( $text, $context, $domain )` | This module's descriptor labels in the `cradle` domain (see "Shipped translations"). |

## Shipped translations (this plugin's own strings)

German (`de_DE`) and Arabic (`ar`) translations of every string of this plugin ship in `languages/`, so
installing the plugin is enough (no Cradle Translations catalog is needed for them):

| File | Contents |
| --- | --- |
| `languages/cradle-forms.json` | Editable source, format `cradle-translations-examples`: one object per locale (`de_DE`, `ar`) with `entries` of `{ domain, msgid, context?, msgidPlural?, forms }` (Arabic plurals have 6 forms). |
| `languages/cradle-forms-<locale>.l10n.php` | Generated WordPress PHP translation file: PHP strings, block.json `title` / `description` / `keywords` of the form and field blocks (contexts `block title`, `block description`, `block keyword`) and the descriptor labels. |
| `languages/cradle-forms-<locale>-<md5>.json` | Generated Jed JSON for each script that uses `wp.i18n` (`assets/js/fields-editor.js`, `assets/js/form-editor.js`, `blocks/form/editor.js`); `<md5>` = md5 of the script path relative to the plugin root. |

- Regenerate after changing or adding strings: `php bin/build-languages.php` (CLI only; a web request
  exits). It refuses to write when a string found in the plugin's PHP, JavaScript or block.json files is
  missing from a locale or has the wrong number of forms; `--check` only compares (exit 3 when out of
  date); `--out=<dir>` writes elsewhere. Commit the generated files.
- Loading: `boot` calls `load_plugin_textdomain()`, which registers `languages/` for WordPress'
  just-in-time loading (the request locale set by Cradle Languages on `/xx/` pages, the user locale in
  wp-admin, the locale inside `switch_to_locale()`). `cradle-forms-fields` and `cradle-forms-form-editor`
  are registered with `wp_set_script_translations( $handle, 'cradle-forms', <plugin>/languages )`; the
  `cradle/form` editor script (registered from block.json) gets the same path at `init` priority 100. A
  language pack in `wp-content/languages/plugins/` is found first; Cradle Translations overrides still
  apply on top.
- Descriptor labels in the `cradle` domain (module name — context `module name`, description —
  `module description`, the four capability labels — `capability label`): a `cradle/translate_label`
  callback at priority 20 (after Cradle Translations) returns the translation from this plugin's domain
  while the label is still the English original; an override from Cradle Translations is kept. Works with
  and without Cradle Translations.
- Submissions (admin-post and REST requests are not routed by Cradle Languages) switch to the locale signed
  into the form token with `switch_to_locale()` for validation messages, the confirmation and the submitter
  copy. WordPress only switches to locales it offers (an installed WordPress language pack, or a Cradle
  Translations catalog while it exposes its locales); without either, those texts stay in the site language
  even though the shipped files contain them.

## Acceptance (sandbox tests in `tests/integration/forms.php`)

Contact form (text, email, select with routing, textarea, consent) and application form (PDF ≤ 5 MB) as block
content; rendering on a page; no-JS PRG and REST submissions; required/invalid values rejected with field
errors; honeypot, too-fast, forged and foreign tokens; rate limit; wrong type, spoofed MIME, random bytes,
double extension, oversize and too-large posts refused; stored files unreachable by URL and downloadable
only with the capability; JPEG metadata removed; consent record with hash and language; routing and
fallback recipients (captured with `pre_wp_mail`); entries list/detail/status/export/delete with allowed and
denied roles and bad nonces; retention; privacy tools only for the marked field; uninstall retain and purge;
core missing; Cradle Languages absent (fallback) and present (form translation created over REST by an administrator, editor refused, copy keeps fields, settings and routing, Arabic page under `/ar/` renders it, entry language `ar`, "Sent from" link with `/ar/`); source page
recorded from the signed token (title snapshot, tampered source refused, older tokens accepted as source 0, CSV,
detail, list, notifications, `entry_saved`); schema update 1 → 2 run twice; form by address name (block
`formSlug`, `[cradle_form slug]`, `formId` precedence, editor preview and picker data, unknown and invalid
slugs render nothing without PHP notices, slug + translation on `/ar/`); shipped translations (every
extracted string in both `.l10n.php` files and valid, generated files match the source, `switch_to_locale()`
for `ar` and `de_DE`, script translations, labels, Cradle Translations absent in a separate process, Arabic
submit label and required marker on an `/ar/` page over HTTP, Arabic editor script translations).
