# Cradle Forms — package instructions

Package: `plugins/cradle-forms` · module id `forms` · text domain `cradle-forms` · admin page `cradle-forms`
(Cradle → Forms) · module dependency `blocks` · optional runtime integration: Cradle Languages (`languages`).

Read first: the framework `AGENTS.md`, `docs/architecture/core-api.md`, `docs/architecture/plugin-conventions.md`,
then `docs/contract.md`, `docs/data-model.md` and `docs/security.md` in this package. Skill: `cradle-forms`
(plus `cradle-security` for any change to input handling, uploads or permissions).

## Scope

- Forms are `cradle_form` posts edited in the block editor; fields are the 13 `cradle-forms/field-*` blocks
  (text, email, tel, url, number, textarea, select, radio, checkboxes, date, consent, file, hidden) with
  core paragraphs/headings/lists between them. The schema is derived and validated on every save.
- Embedding: block/component `cradle/form` (by `formId`, or by `formSlug` — the form's address name — for
  theme templates), shortcode `[cradle_form id="…"]` / `[cradle_form slug="…"]`,
  `[cradle_component id="cradle/form" form-id="…"]`.
- Translations: German and Arabic ship in `languages/` (source `languages/cradle-forms.json`, generated
  `.l10n.php` and Jed files); see docs/contract.md, "Shipped translations".
- Multilingual (optional Cradle Languages): `cradle_form` supports `cradle-languages` (translatable by default);
  translations copy `_cradle_form_settings` only and derive their schema from their content.
- Submissions: no-JavaScript `admin-post.php` + Post/Redirect/Get, enhanced by `view.js` → REST
  `cradle-forms/v1/submit/<form>`. Honeypot, signed minimum-time token, rate limit, same-origin checks.
- Entries, values, consent records and uploaded files in own tables and core private storage; admin
  screens (list, detail, status, delete, CSV), retention cron, WordPress privacy exporter/eraser,
  notifications with routing rules.
- Not in scope (v1): payments, multi-step or conditional forms, cloud uploads, newsletter-provider
  integrations (hook only), CAPTCHA services, a builder outside the block editor.

## Layout

| Path | Contents |
| --- | --- |
| `cradle-forms.php` | Bootstrap: header, autoloader, lifecycle hooks, descriptor registration, core-missing guard. |
| `src/class-module.php` | Descriptor (data owners, capabilities) and `boot()`. |
| `src/class-lifecycle.php`, `src/class-schema.php` | Activation/deactivation/update; table definitions (`dbDelta`, versioned). |
| `src/class-post-type.php`, `src/class-rest-forms-controller.php` | `cradle_form` post type, allowed blocks, schema derivation on save, REST read restriction. |
| `src/class-field-types.php` | Field blocks: config normalization, value validation, rendering, display. |
| `src/class-form-schema.php`, `src/class-form-settings.php` | Stored schema (`_cradle_form_schema`) and per-form settings (`_cradle_form_settings`). |
| `src/class-renderer.php` | `cradle/form` component render, field block render callback, shortcode. |
| `src/class-submission.php`, `src/class-handler.php`, `src/class-rest.php` | Pipeline; admin-post PRG handler; REST route. |
| `src/class-security.php`, `src/class-rate-limit.php` | HMAC, client network hash, signed token, origin check; rate limit. |
| `src/class-uploads.php`, `src/class-entries.php` | File checks/storage/streaming; entry storage and queries. |
| `src/class-notifications.php`, `src/class-privacy.php`, `src/class-retention.php` | Email, privacy tools, daily retention. |
| `src/class-language.php`, `src/class-editor.php`, `src/class-settings-section.php` | Cradle Languages integration; editor assets/data; site-wide settings. |
| `admin/class-admin-page.php`, `admin/class-csv.php` | Cradle → Forms screens and handlers; CSV export. |
| `blocks/`, `components/form/` | `block.json` per field block (shared editor script) and `cradle/form`. |
| `assets/js/fields-editor.js`, `assets/js/form-editor.js`, `blocks/form/editor.js` | Editor (plain JS, WordPress globals only). |
| `assets/js/view.js`, `assets/css/*.css` | Front-end enhancement and styles (logical properties). |
| `languages/cradle-forms.json`, `bin/build-languages.php` | German and Arabic translations of this plugin (editable source) and their CLI generator. |
| `languages/*.l10n.php`, `languages/*-<md5>.json` | Generated translation files (PHP strings, block.json metadata, descriptor labels; Jed per editor script). Never edit by hand. |

## Risks (keep these true)

- Public input everywhere: every value is validated on the server against the stored schema; never trust
  block attributes at render time (the renderer reads the schema), never trust browser validation.
- Files: only into `module_dir( 'forms', 'uploads' )`, only when `is_protected()`; random names without
  extension; never the media library; downloads only through the capability + nonce handler.
- Consent records are evidence: never edit stored consent JSON; a changed consent text must produce a new hash.
- Privacy tools match only the field marked "submitter's own email" (`entries.submitter_email`).
- Notifications: no file contents, validated addresses only, no line breaks in headers or subjects.
- Do not add a nonce requirement for anonymous visitors (cached pages); see `docs/security.md`.
- Translations ship with the plugin. New or changed interface strings (PHP, `wp.i18n` in scripts, block.json
  title/description/keywords, descriptor labels) need German and Arabic entries in
  `languages/cradle-forms.json`; then run `php bin/build-languages.php` (it refuses to write while a string
  is missing; `--check` compares) and commit the generated files. A new script that uses `wp.i18n` needs
  `wp_set_script_translations( $handle, 'cradle-forms', CRADLE_FORMS_DIR . 'languages' )` (block editor
  scripts registered from block.json: `Module::script_translations()`).
- `formSlug` must stay a strict address-name lookup of published forms (`Post_Type::published_by_slug()`),
  with `formId` winning; never resolve it to drafts, other post types or by title.

## Lifecycle

- Activation: multisite guard, tables, four capabilities granted once to administrators, retention cron.
  Never creates forms or pages.
- Deactivation: unschedules `cradle_forms_retention`, releases `forms:*` locks; forms, entries and files stay.
  Embedded form blocks then render nothing (dynamic, no saved fallback).
- Update: `Schema::VERSION` (2) / `cradle_forms_db_version`; `Lifecycle::maybe_upgrade()` on admin/CLI requests
  (front-end inserts work on an older table until then: `Schema::has_source()`).
  A stored schema with another `schema_version` is ignored until the form is saved again (bump with a migration).
- Uninstall: retain by default; purge only when `cradle_uninstall_policy['forms'] === 'purge'` (forms, meta,
  tables, `forms/` private files, `cradle_forms_*` options/transients, cron, capabilities). Pages are never edited.

## Tests

`php tests/run.php --suite=forms` (sandbox, own users `cradle_forms_t_*`, fixtures removed at shutdown;
a test-only mu-plugin captures "CF Test" emails while the suite runs). Uninstall and the core-missing/
no-Languages/no-Translations checks run in separate processes from `tests/fixtures/forms/`. Tests that
must prove the shipped files alone switch Cradle Translations' runtime overrides off and restore them.
