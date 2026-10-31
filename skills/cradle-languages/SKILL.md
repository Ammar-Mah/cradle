---
name: cradle-languages
description: Use when a Cradle site needs content in more than one language — adding languages, /xx/ URL prefixes, translating pages, posts, terms, menus or synced patterns, a language switcher, per-language sections in templates, hreflang, language-aware queries or sitemaps, right-to-left languages, or importing an existing Polylang site. Produces a configured Cradle Languages setup (routing, translation groups, menus, switcher) that works with Cradle Translations' locale catalogs, with evidence over real HTTP.
---

# Cradle languages (multilingual content and routing)

## Required context

- `cradle-project` orientation; `packages/plugins/cradle-languages/` `AGENTS.md` and `docs/contract.md`,
  `docs/data-model.md`, `docs/security.md`.
- `northstar.md` §14.1 (boundary: Cradle Translations = interface strings per locale; Cradle Languages =
  content and routing). Use `cradle-translations` for labels/strings, `cradle-gutenberg` for block or
  template work, `cradle-security` for any new input or endpoint.
- Website mode: the child's `northstar.md` (which languages, which is the default, address scheme,
  which content is translated and which is shared), `docs/content-model.md`, `docs/component-map.md`.

## Preconditions

- Cradle core, Cradle Blocks and Cradle Languages active and `ready` (Cradle → Overview). Cradle
  Translations active when interface texts must change per language.
- Pretty permalinks (plain permalinks work through `/index.php/<prefix>/`).
- Decide per content type and taxonomy: **translatable** (one entry per language, linked) or **shared**
  (one entry for all languages). Attachments, Navigation menus and synced patterns are shared by default.
- Know the locale of each language (`ar`, `de_DE`, `en_US` …) and its direction; the default language keeps
  the existing unprefixed URLs unless the brief asks otherwise.
- Never configure languages by editing the option directly; use the Languages screen or
  `cradle()->settings()->update( 'languages', … )` as an administrator.

## Procedure

1. **Languages** — Cradle → Languages: one row per language (locale, address prefix, name, native name,
   direction, order), exactly one default; keep "Serve the default language without an address prefix"
   on unless required. Fix any refused prefix (reserved word, existing top-level slug, file) instead of
   renaming content blindly. The status table shows whether each locale has a WordPress language pack
   and a Cradle Translations catalog; add missing catalogs under Cradle → Translations (the built-in
   `de_DE` and `ar` examples cover the core Cradle packages; Cradle Languages and Cradle Forms ship their
   own German and Arabic translations in their `languages/` folders and need no catalog for them).
2. **Types** — mark content types and taxonomies translatable or shared on the same screen. Entries
   without a language count as the default language; run "Assign the default language" to store it
   explicitly before translating many entries. Configurable: public types, attachments, Navigation menus,
   synced patterns, every custom type that is not public but has admin screens (for example non-public
   Cradle Content types shown through theme lists; default **shared**, set translatable only when each
   entry exists once per language), and any type declaring the post type support `cradle-languages`
   (Cradle Forms does this for forms, default translatable).
3. **Front page and posts page** — translate the static front page and the posts page ("Add translation"
   in the editor's Language panel, then publish); `/xx/` then shows the translated front page and the
   translated posts page lists that language's posts.
4. **Content** — for each entry: editor → Language panel → "Add <language> translation" (creates a linked
   draft copy with terms mapped to their translations and Cradle Content fields copied); translate and
   publish. Translate terms on the term screens (language + translation links).
5. **Navigation** — block themes: make *Navigation menus* translatable, add a translation of each
   Navigation entity (Site Editor content can also be wrapped in a **Language section** block per
   language); links to translated pages switch automatically. Classic themes: assign menus per language
   under "Menus per language".
6. **Switcher** — place the **Language switcher** block (list or dropdown; native names, names or codes;
   hide untranslated languages or link them to the language home) in the header template part or a
   pattern. Use the **Language section** block for template text that differs per language (footer
   address, legal notes); synced patterns can carry one section per language.
7. **Interface strings** — anything printed by theme/plugin code through translation functions follows
   the request locale: translate it in Cradle Translations for each language's locale, never with
   language sections.
8. **Polylang sites** — Cradle → Languages → Import from Polylang: run the dry run, resolve every listed
   problem, read the warnings, then import (job; repeatable; Polylang data untouched). On a server shell:
   `php wp-content/plugins/cradle-languages/bin/import-polylang.php` (dry run) then `--run`. Deactivate
   Polylang afterwards (both plugins filtering URLs at once is unsupported). Type mapping: the import
   writes an explicit mode for every configurable type and taxonomy — translatable when Polylang
   translated it (post, page, category, post_tag, the custom types/taxonomies in Polylang's settings,
   media when media support was on), otherwise **shared**, as Polylang treated them (types that declare
   the `cradle-languages` support and that Polylang did not list, such as forms, keep their own default).
   Check the mapping in the dry run before importing. A warning about a type or taxonomy Polylang
   translates that Cradle Languages cannot configure usually means its plugin or theme is not active:
   activate it and run the import again (the import is idempotent).
9. Record the language setup (languages, prefixes, translatable/shared types, navigation approach) in the
   child's `docs/content-model.md` and `docs/decisions.md`.

## Verification

- Over HTTP (not only in the editor): `/` and `/<prefix>/` return 200 with `<html lang>` of each locale
  and `dir="rtl"` for right-to-left languages; an entry requested under another language's prefix
  answers 301 to its own URL; `/<unknown>/…` follows WordPress' normal 404 handling.
- Lists, Query Loops, post cards and search on a `/xx/` page show only that language's entries; shared
  types appear in every language.
- The switcher links each language to the published translation or to that language's home; hreflang
  links (with `x-default`) appear on translated entries; core sitemaps list each language's URLs.
- An interface string from a Cradle package renders in the language's catalog on `/xx/` pages; Cradle
  Languages' own texts (switcher label, editor panel) render in German or Arabic from its shipped files.
- Navigation shows the right menu per language; language sections show only in their languages.
- Framework: `php tests/run.php --suite=languages` (and `--suite=blocks,content` after query changes).
- Package strings changed: add German and Arabic entries to
  `packages/plugins/cradle-languages/languages/cradle-languages.json`, run
  `php packages/plugins/cradle-languages/bin/build-languages.php` (refuses to write while a string is
  missing; `--check` compares) and commit the generated `languages/` files.

## Required output

Languages (locale, prefix, direction, default), translatable/shared types and taxonomies, translated
front page/posts page, navigation approach, switcher placement, Polylang import report (if any), HTTP
checks with results, interface strings still missing per locale.

## Failure behavior

- A prefix is refused: report what reserves it (route, query var, top-level entry, file) and ask before
  renaming existing content.
- A locale has no catalog or language pack: interface texts stay in the source language — add a Cradle
  Translations catalog; do not fake it with language sections.
- Polylang dry run lists problems: stop; resolve them (invalid codes, collisions, more than 10 languages)
  before importing. Non-directory Polylang URL modes change addresses: confirm with the owner first.
- Requests for domains/subdomains per language, browser-language redirects or machine translation: out of
  scope for v1 — record the need as an open decision.

## References

- `packages/plugins/cradle-languages/docs/contract.md` (routing, links, queries, REST, hooks, API)
- `packages/plugins/cradle-languages/docs/data-model.md`, `docs/security.md`
- `northstar.md` §14.1; `skills/cradle-translations/SKILL.md`
