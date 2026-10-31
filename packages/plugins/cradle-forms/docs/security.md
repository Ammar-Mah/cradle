# Cradle Forms — security notes

Surface inventory: `php skills/cradle-security/scripts/check-surface.php packages/plugins/cradle-forms`.
Every entry point below is exercised with an allowed and a denied caller in `tests/integration/forms.php`.

## Entry points

| Entry point | Caller | Authorization | CSRF / integrity | Method |
| --- | --- | --- | --- | --- |
| `admin_post_nopriv_cradle_forms_submit` / `admin_post_cradle_forms_submit` | anyone | Intentionally public; published form only; bounded by rate limit, sizes and field limits | Proven cross-site origin refused (403); signed render token; honeypot; logged-in users need `_cf_nonce` | POST (GET → 405) |
| REST `POST cradle-forms/v1/submit/<form>` | anyone | `permission_callback` public but requires same-origin proof (`Sec-Fetch-Site: same-origin` or own `Origin`) | Same pipeline; cookie-authenticated users need `X-WP-Nonce` (WordPress) | POST only |
| `admin_post_cradle_forms_entries` | logged in | `cradle_view_form_entries` (status) / `cradle_delete_form_entries` (delete); IDs limited to the given form | `check_admin_referer( 'cradle_forms_entries' )` | POST (GET → 405) |
| `admin_post_cradle_forms_export` | logged in | `cradle_export_form_entries` | nonce `cradle_forms_export_<form>` | POST (GET → 405) |
| `admin_post_cradle_forms_download` | logged in | `cradle_view_form_entries`; file row and entry must exist | nonce `cradle_forms_download_<file>` | GET (read only) |
| Cradle → Forms screens | logged in | `cradle_view_form_entries` or `cradle_manage_forms`; each view checks its own capability | read only | GET |
| REST `wp/v2/cradle-forms` | logged in | `cradle_manage_forms` to read or write (custom controller) | REST nonce | standard |
| Block `cradle/form`, shortcode `[cradle_form]` | visitor context | Published forms only; manager notices only for `cradle_manage_forms` | — | render |
| `template_redirect` (`no_cache`) | everyone | none needed: only sends no-cache headers when a result token is present | — | — |
| `load-edit.php` (`redirect_list`) | logged in | `Caps::can_open()`; redirects the unfiltered core form list to Cradle → Forms (trash and language views stay) | — | GET, no mutation |
| Cradle Languages `POST cradle-languages/v1/posts/<form>/translations` (owned by Cradle Languages) | logged in | `edit_post` on the form + `create_posts` of `cradle_form`, both mapped to `cradle_manage_forms`; editors refused (tested) | REST nonce | POST |

Translations: Cradle Forms allows Cradle Languages to copy only `_cradle_form_settings` into a new
translation (notifications, routing, spam limits). The schema is never copied: it is derived from the
copied content on insert, so a translation cannot carry a schema that does not match its content.

## Why anonymous submissions carry no nonce

Pages with forms are often served from page caches for hours or days. A nonce in cached HTML is shared by
every anonymous visitor and expires after 12–24 hours, so it would break legitimate submissions without
proving anything about the visitor. Anonymous requests are instead protected by: the browser origin check
(cross-site posts are refused; REST requires positive same-origin proof), the signed render token (form,
instance, time, source page, language, locale; forged, foreign or tampered tokens are rejected — the source
page stored on entries comes only from this signature, never from a request field), the honeypot, the
minimum fill time and the rate limit. The older token without a source page (pages cached before it was
added) is still accepted and recorded as source 0. Logged-in users' pages are not cached, so they must present a nonce (form nonce for admin-post, the
REST nonce for fetch); a missing or stale nonce returns the form with their values and a message.

Limits of these checks: a script that sends crafted headers can always post (there is no CAPTCHA, by
design: no remote services); on long-cached pages the render time is the cache time, so the minimum-time
check only stops instant replays of fresh renders. The rate limit uses transients, which are not atomic: a
burst of simultaneous requests from one network can exceed the limit slightly.

## Input validation

- Field values are validated against the stored schema, never against the posted structure: unknown
  fields are ignored, arrays are refused for scalar fields, choice fields accept only listed values,
  email/url/tel/number/date are checked on the server (url: http/https only, no DNS lookup), lengths are
  bounded, text is sanitized with `sanitize_text_field()`/`sanitize_textarea_field()`.
- Hidden fields store their configured value; only fields with `fromQuery` accept a (plain-text, ≤ 200
  characters) value from the request.
- Consent labels and the confirmation message are limited HTML (`wp_kses` allowlists); a consent ticked
  under another text version is refused.
- Form settings: `Form_Settings::sanitize()` on every write (unknown keys dropped, bounded numbers, validated
  addresses, routing rules with valid field names only). Field names in settings are re-checked against
  the schema when used.
- `formSlug` (block attribute, `[cradle_form slug]`, component property): plain text ≤ 200 characters, then
  `^[a-z0-9-]{1,200}$` or nothing is looked up; the lookup (`post_name`, `post_status = publish`,
  `cradle_form` only) uses WordPress' query API, so a slug can only select a published form, exactly like
  `formId`. The notice for an unknown slug is shown only to `cradle_manage_forms` users and escaped.

## Uploads

1. Upload error codes, `is_uploaded_file()`, size > 0 and ≤ min(field, site setting, server limit), count ≤ `maxFiles`.
2. Raw file name: no control characters; every extension segment against a blocklist (php, phtml, phar,
   cgi, scripts, executables, html, svg, xml, …), final extension in the field's and the site's allowlist.
3. `wp_check_filetype_and_ext()` with a MIME map restricted to the allowed extensions (real content via
   fileinfo; `proper_filename` corrections are refused) plus leading-byte signatures for PDF, images,
   Office/OpenDocument, RTF (WordPress accepts generic binary content for application types).
4. Stored only when `cradle()->storage()->is_protected()` is true, under a random 32-hex name without
   extension, inside `module_dir( 'forms', 'uploads' )` (containment checked with `Storage::path_within()`);
   never in the media library. The original name is display data only.
5. JPEG/PNG are re-encoded with GD (EXIF, XMP, comments and text chunks dropped; JPEG orientation applied
   first when the exif extension exists). Not stripped: images above 25 megapixels, sites without GD, and
   all other types (PDF and Office documents keep their metadata).
6. Downloads: capability + nonce, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`,
   `Content-Security-Policy: default-src 'none'; sandbox`, `Cache-Control: no-store`.
7. Uploaded files are never kept across a failed submission (the visitor chooses them again).

## Output and email

- Rendering escapes by context (`esc_html`, `esc_attr`, `esc_url`, `wp_kses` allowlists); front-end JS writes
  error texts with `textContent` and inserts the server-sanitized confirmation through `DOMParser`.
- Admin screens escape stored values; CSV cells starting with `= + - @`, tab or CR are prefixed with `'`.
- Shipped translation files: `languages/*.l10n.php` is PHP that WordPress includes. It is generated only by
  the CLI script `bin/build-languages.php` (a web request exits first; tested over HTTP) from the plugin's
  own `languages/cradle-forms.json`, with every string exported as an escaped PHP literal (single-quoted,
  or double-quoted with `\`, `"`, `$` and control characters escaped), so translation text cannot become
  code. Never feed translations from visitors or site data into that source; site-specific wording belongs
  in Cradle Translations (stored as JSON, never as PHP). Jed files hex-escape `<`, `>` and `&` because
  WordPress prints them inside an inline script.
- Notifications: plain text; recipients validated with `is_email()`; subject without line breaks; headers
  built from validated values only (`Reply-To` from a validated email field); files never attached (link to
  the entry); "copy to submitter" is off by default because anyone can enter any address.

## Privacy

- No raw IP address is stored; `ip_hash` is an HMAC (key from `wp_salt()`, not from the database) of the
  IPv4 address or IPv6 /64. IPv4-mapped IPv6 (`::ffff:a.b.c.d`) is unwrapped first. Behind a trusted proxy,
  use the `cradle_forms/client_ip` filter; never read forwarding headers without one.
- Privacy exporter/eraser match only `submitter_email` (the field marked as the submitter's own address).
- One-time result transients hold submitted values for at most 10 minutes and are deleted when shown.
- Privacy-policy suggestion text is registered with `wp_add_privacy_policy_content()`.

## Logged privileged operations

`cradle_log_activity( 'forms', … )`: entries deleted, entries exported, file downloaded, retention runs,
privacy erasure. Submissions themselves are not logged.

## Not established

No penetration test or fuzzing; the editor scripts were checked in a browser (field blocks, panels, form
block preview) but not across browsers; rate limiting is not atomic (see above).
