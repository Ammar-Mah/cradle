# Cradle Languages — package instructions

Package: `plugins/cradle-languages` · module id `languages` (depends on `blocks ^0.1`) · text domain
`cradle-languages` · capability `cradle_manage_languages` (administrators) · admin page `cradle-languages`.
Spec: framework `docs/decisions.md` 2026-10-31, northstar §14.1 (boundary with Cradle Translations), §20.

Read first: the framework `AGENTS.md`, `docs/architecture/core-api.md`, `docs/architecture/plugin-conventions.md`,
then `docs/contract.md`, `docs/data-model.md` and `docs/security.md` in this package.

## Scope

- Languages with `/xx/` URL prefixes (default language unprefixed by default), request language → locale.
- Content language (hidden taxonomy `cradle_language`) and translation groups (`_cradle_lang_group`)
  for entries and terms; translatable or shared per post type and taxonomy.
- Query filtering (frontend), link prefixes, front page / posts page per language, wrong-prefix
  redirects, hreflang, language-aware sitemaps, menus per language.
- Editor "Language" panel with "Add translation"; blocks `cradle/language-switcher` and
  `cradle/language-section`; Polylang import (admin dry run + job, CLI).
- Not in scope: interface strings (Cradle Translations), domains/subdomains, browser-language
  redirects, machine translation, multisite.

## Layout

| Path | Contents |
| --- | --- |
| `cradle-languages.php` | Bootstrap: headers, autoloader, `src/functions.php`, lifecycle hooks, descriptor + `cradle/ready`, core-missing notice. |
| `src/class-module.php` | Descriptor, `ready()` (routing before `init`), `boot()`. |
| `src/class-languages.php` | Configured languages (raw option, re-validated), current/default, `switch_to()`. |
| `src/class-config.php` | Settings section, validators, controls, typed readers (modes, menus). |
| `src/class-request.php` | Prefix detection/stripping, locale, direction, front page options, redirects. |
| `src/class-links.php` | `localize()`, `home_url` and object link filters. |
| `src/class-content.php` | Taxonomy, entry/term languages, groups, insert/slug hooks, term sync on settings save. |
| `src/class-query.php` | `WP_Query` / `WP_Term_Query` filtering. |
| `src/class-rest.php`, `src/class-translator.php`, `src/class-editor.php`, `assets/js/panel.js` | Editor panel, REST routes, translation copier. |
| `src/class-switcher.php`, `src/class-section.php`, `components/`, `blocks/`, `assets/css/languages.css` | Blocks and components. |
| `src/class-seo.php`, `src/class-menus.php` | hreflang/sitemaps; menus per language. |
| `src/class-tasks.php`, `src/class-polylang.php`, `bin/import-polylang.php` | Jobs; Polylang import. |
| `admin/class-admin-page.php`, `admin/class-lists.php` | Languages screen + admin-post handlers; list columns/filters and term fields. |
| `languages/cradle-languages.json`, `bin/build-languages.php` | German and Arabic translations of this plugin (editable source) and their CLI generator. |
| `languages/*.l10n.php`, `languages/*-<md5>.json` | Generated translation files (PHP strings, block.json metadata, descriptor labels; Jed per editor script). Never edit by hand. |
| `uninstall.php` | Retain by default; purge only when `cradle_uninstall_policy['languages'] === 'purge'`. |

## Rules for changes

- Routing stays rewrite-rule free: never add rewrite rules or flushes; anything that must exist before
  `init` goes in `Request::start()` (no translation calls there).
- Never touch admin, REST, login, cron, CLI or file URLs; add exclusions to `Links::EXCLUDED_PATH` /
  `Request::detect_frontend()` rather than special cases elsewhere.
- Every query filter must keep working with only one language configured (no change at all) and must
  treat untagged entries as the default language — other suites rely on that.
- Never store or edit other packages' interface strings here; never call Cradle Translations internals
  except the read-only catalog check on the Languages screen.
- New REST routes: deliberate `permission_callback` with object capabilities, `args` schema.
- Saved markup of `cradle/language-section` (inner blocks only) is a contract; changing it needs a
  block deprecation. Changing switcher markup bumps `language-switcher-v1`.
- Translations ship with the plugin (docs/contract.md, "Shipped translations"). New or changed interface
  strings (PHP, `wp.i18n` in scripts, block.json title/description/keywords, descriptor labels) need German
  and Arabic entries in `languages/cradle-languages.json`; then run `php bin/build-languages.php` (it refuses
  to write while a string is missing) and commit the generated files. A new script that uses `wp.i18n`
  needs `wp_set_script_translations( $handle, 'cradle-languages', CRADLE_LANGUAGES_DIR . 'languages' )`
  (block editor scripts registered from block.json: `Module::script_translations()`).
- Post type eligibility (`Config::eligible_post_types()`) and the Polylang type mapping
  (`Polylang::post_type_modes()`) decide which content gets filtered per language on every frontend
  query: change them only with tests for public, non-public `show_ui` and excluded types.

## Risks

- A fatal error in `Request` breaks every frontend page: lint, sync and load the home page after each change.
- Query filtering touches every frontend query: run `--suite=blocks,content` after changes to `Query`.
- Plugins that build URLs from `$_SERVER['REQUEST_URI']` during `parse_request` see the unprefixed path
  (restored at the first `parse_request` callback).

## Tests

`php tests/run.php --suite=languages` — own users `cradle_lang_t_<role>`, fixtures marked
`_cradle_test_fixture = languages`, extra languages configured only inside a test and reset afterwards;
Polylang data from `tests/fixtures/languages/polylang.php`; uninstall and dependency guards in separate
processes (`run-uninstall.php`, `run-guard.php`; `run-guard.php no-translations` checks the shipped
translations without Cradle Translations); the non-public `show_ui` type check copies
`tests/fixtures/languages/ui-type.php` into `wp-content/mu-plugins/` for one test and removes it.
