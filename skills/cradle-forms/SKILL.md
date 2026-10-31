---
name: cradle-forms
description: Use when a website needs public forms (contact, newsletter sign-up, application with CV upload, registration-style requests) or when changing Cradle Forms — field blocks, form schema, submissions without JavaScript, the REST enhancement, file uploads, consent records, notifications and routing, spam protection, entries screens, CSV export, retention, privacy export/erasure. Produces block-built forms that validate on the server, store entries and files privately, and pass allowed/denied-role tests.
---

# Cradle forms

## Required context

- `cradle-project` orientation; for package changes also `cradle-plugin-development` and `cradle-security`
  (every change to input handling, uploads, permissions or emails), `cradle-gutenberg` for editor/block work.
- `packages/plugins/cradle-forms/AGENTS.md`, `docs/contract.md` (field types, settings, hooks),
  `docs/data-model.md`, `docs/security.md`.
- Website mode: the child's `northstar.md` (which forms, fields, recipients, consent wording, languages,
  retention) and `docs/content-model.md` / `docs/component-map.md` for where forms are placed.

## Preconditions

- Cradle Forms is active and `ready` (Cradle → Overview); it needs core and Cradle Blocks. Cradle Languages
  is optional: with it, each language has its own form post (a translation) and its own entries.
- Before accepting files: Cradle → Forms → Settings says private storage is protected (or
  `CRADLE_PRIVATE_DIR` is defined outside the web root). Otherwise file fields refuse uploads.
- Know for each form: purpose, fields and which are required, who receives notifications (static addresses
  and routing by a choice field), whether a copy goes to the submitter (off by default: anyone can type any
  address), confirmation message or page, retention days, and the exact consent text agreed with the owner.
- Never invent recipients, consent wording or legal text: ask the owner (or leave a clearly marked draft).

## Procedure

1. Build each form in Cradle → Forms → Add form (block editor). Use one field block per input; set a short,
   stable `name` (lowercase, underscores) because entries, CSV columns and routing use it. Use labels, not
   placeholders, for meaning; add help text where the format matters; `half` width only for short pairs.
2. Mark the email field that belongs to the person submitting as "submitter's own email" (privacy tools and
   the submitter copy use only that field). Exactly one per form when it exists.
3. Consent: one consent block per purpose with the exact wording (links allowed). Changing the text later
   creates a new text version (hash); old entries keep the text they agreed to.
4. Files: list only the needed extensions (for a CV: `pdf`, perhaps `doc,docx`), a size limit and the number of
   files. The site-wide allowlist and the server limit always apply.
5. Document panels: confirmation, submit label, storage (forced on with consent/file fields), retention,
   minimum fill time (default 3 s), rate limit per hour, notifications with routing rules
   ("if Topic equals press → press@…"), subject placeholders `{form}`, `{site}`, `{source}` (page the form
   was sent from), `{field_name}`.
6. Save and read the editor notice: an invalid schema (duplicate names, missing choices, disallowed file
   types) keeps the previous version live until fixed. Publish.
7. Place the form with the Form block (or `[cradle_form id="…"]`). In theme template files, template parts and
   patterns, where database IDs differ per site, select the form by its address name instead:
   `<!-- wp:cradle/form {"formSlug":"contact"} /-->` or `[cradle_form slug="contact"]` (lowercase letters,
   digits and hyphens; the form's slug under Cradle → Forms; create the form with exactly that slug on every
   site and record it in the child's `docs/component-map.md`). `formId` wins when both are set; choosing a
   form in the block's picker replaces the slug by the ID. An unknown slug shows nothing to visitors (form
   managers see a notice). With Cradle Languages use the default-language form's slug; each language page
   then shows that language's translation. One form can serve many pages (for example
   one application form on every opportunity page): each entry records the singular page it was sent from
   ("Sent from" in the entries list, entry detail, CSV and notifications), so do not build a form per page
   only to know the origin; add a hidden field only for data that is not the page itself.
   Multilingual sites (Cradle Languages): forms are translatable by default. In the form editor use "Add
   translation" for each language; the draft copy keeps the fields, settings and routing. Translate labels,
   choice labels (keep the stored values identical, routing rules match them), consent text, help texts,
   the submit label and the confirmation message, then publish. Pages keep embedding the original form:
   each page translation renders its language's published form translation. Entries are stored per
   language form with the language and the translated page they came from.
8. Integrations (newsletter providers, CRMs): use `cradle_forms/entry_saved` in trusted site or package code;
   adjust validation with `cradle_forms/validate`; emails with `cradle_forms/notification_args`. Never add a
   remote request inside the submission without an allowlist, timeouts and an error path.
9. Package changes: keep the schema authoritative (render and validate from `_cradle_form_schema`), add new
   field types in `Field_Types` (config normalization, `check()`, `render()`, `display()`) plus a block.json
   and the shared editor script; bump `Form_Schema::VERSION` with a migration when the stored shape changes.
   Translations ship with the plugin: every new or changed string (PHP, `wp.i18n`, block.json title,
   description and keywords) needs German and Arabic entries in `languages/cradle-forms.json`; then run
   `php bin/build-languages.php` in the plugin folder (refuses to write while a string is missing; `--check`
   compares) and commit the generated `languages/` files.

## Verification

- `php tests/run.php --suite=forms` in the framework repository (real HTTP: no-JS PRG and REST submissions,
  spam checks, uploads, notifications, admin roles, retention, privacy, uninstall).
- Website: submit each form once without JavaScript (disable it in the browser) and once with it: errors
  appear in the summary with links and next to the fields, values are kept, the confirmation appears; the
  entry, its consent record (text, hash, language, time) and any file appear under Cradle → Forms; the
  notification reaches the routed recipient (use a staging mailbox or a mail-capture plugin, never a real
  customer address).
- A file's direct URL in private storage is refused; the download link works only for entry viewers.
- An editor (without the Forms capabilities) cannot open entries, export or download.
- Accessibility: every field has a visible label, errors are announced and linked, focus moves to the
  summary or confirmation, the form works in right-to-left languages.
- German/Arabic pages: the form's own interface texts (submit button default, "(required)", error summary,
  editor panels) come from the shipped `languages/` files without any Cradle Translations catalog; labels,
  help texts and messages you typed are content and are translated in the form translation. Error messages
  of a submission switch to the page's locale only when WordPress offers it (a WordPress language pack or a
  Cradle Translations catalog for that locale).

## Required output

Forms built (fields, required ones, recipients/routing, consent text source, retention), where they are
placed, test or manual check results (passed/failed/not run), storage protection status, open questions for
the owner (recipients, consent wording, retention period).

## Failure behavior

- Storage not protected: do not enable file fields; report the fix (`CRADLE_PRIVATE_DIR` or server deny rules).
- Recipients or consent wording unknown: stop and ask; do not publish a form that sends to guessed addresses.
- A requested feature is out of scope (payments, multi-step, conditional logic, CAPTCHA services, cloud
  uploads): say so and propose the closest supported setup or a framework change.
- Tests fail or cannot run: report them as failed/not run; never claim delivery works without a captured email.

## References

- `packages/plugins/cradle-forms/docs/contract.md`, `docs/data-model.md`, `docs/security.md`
- Related skills: `cradle-security`, `cradle-gutenberg`, `cradle-components`, `cradle-languages`, `cradle-plugin-development`
