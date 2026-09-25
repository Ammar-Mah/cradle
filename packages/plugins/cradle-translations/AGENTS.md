# Cradle Translations — package instructions

Package: `plugins/cradle-translations` · module id `translations` · text domain `cradle-translations`
· capability `cradle_manage_translations` · spec: northstar §14 (acceptance CR-17) and §20.

Read first: the framework `AGENTS.md`, `docs/architecture/core-api.md`, `docs/architecture/plugin-conventions.md`,
then `docs/contract.md`, `docs/data-model.md` and `docs/security.md` in this package.

## Scope

- One authoritative JSON catalog per locale in Cradle private storage (never inside package code).
- Runtime overrides for PHP gettext, contextual and plural strings (domain-scoped filters),
  JavaScript translations of registered script handles, and Cradle-registered labels.
- Loco-like editor under Cradle → Translations: locales, list/search/filter/paginate, edit with plural
  forms, draft/publish, JSON import/export, `.po/.pot` source import, revisions/restore, source refresh.
- Not in scope: page content, menus, permalinks, hreflang or any multilingual-content feature.
  Only strings exposed through WordPress translation functions, script translations or the Cradle
  label registry can be translated.

## Layout

| Path | Contents |
| --- | --- |
| `cradle-translations.php` | Bootstrap: header, autoloader, lifecycle hooks, descriptor + `cradle/ready` hooks, core-missing guard. |
| `src/class-module.php` | Descriptor, `ready()` (attaches runtime before `init`), `boot()`, settings section, export/import/cleanup handlers. |
| `src/class-runtime.php` | gettext/ngettext filters, script-translation merge, available-languages filter, label filter, locale cache. |
| `src/class-store.php` | Private-storage I/O: lock → read → mutate → history snapshot → atomic write → runtime + index. |
| `src/class-catalog.php` | Pure catalog operations (identity, sync, drafts, publish, runtime map, query, stats). |
| `src/class-validator.php` | UTF-8/length/control checks, placeholders, approved HTML subset, catalog schema, bounded JSON. |
| `src/class-plural-rules.php` | Structured plural rules (PHP arithmetic per rule id) + trusted Jed header constants. |
| `src/class-extractor.php` | Token-based PHP and JS extraction for Cradle packages only; POT generation. |
| `src/class-po-parser.php` | Bounded `.po/.pot` state-machine parser (UTF-8 only). |
| `src/class-labels.php` | Registry for Cradle configuration labels. |
| `src/class-service.php` | All operations with capability and stale-revision checks. |
| `admin/class-admin-page.php` | Admin screen and `admin-post.php` handlers (POST + nonce + capability). |
| `assets/` | Editor CSS/JS (vanilla, loaded only on the Translations screen). |
| `uninstall.php` | Retain by default; purge only when `cradle_uninstall_policy['translations'] === 'purge'`. |

## Supported hooks and APIs

- `apply_filters( 'cradle/translate_label', $text, $context, $domain )` — resolve a Cradle label
  (override → shipped → original). Safe to call when this plugin is inactive (returns `$text`).
- `do_action( 'cradle/translations/register_labels', Labels $labels )` — register labels with
  `$labels->add( $domain, $text, $context, $origin )`; they appear in catalogs after a source refresh.
- Module `export()` / `import()` / `cleanup()` handlers per core-api §2.

## Rules for changes

- Never evaluate imported expressions, never include/execute scanned files, never write PHP into storage.
- Every catalog write goes through `Store::mutate()`; never write catalog files directly.
- Keep the gettext filters O(1) per string: no file reads, scans or option reads per call.
- New user operations: add them to `Service` with `authorize()` and a stale-revision check, and to the
  admin screen as POST + per-locale nonce + capability. Add allowed **and** denied tests.
- `cradle_manage_translations` must never be mapped to migration, import-site or code-upload rights.
- Keep strings translatable with literal arguments (the extractor reports dynamic calls).

## Lifecycle

- Activation: refuses network activation; grants the capability to administrators once (recorded).
- Deactivation: clears `cradle_translations_refresh` cron, releases `translations:*` locks; data kept.
- Package updates of `cradle*` plugins/themes schedule a background source refresh (setting `auto_refresh`).
- Uninstall: see `docs/data-model.md` for the exact purge list.

## Testing

`php tests/run.php --suite=translations` (fixtures in `tests/fixtures/translations/`). The suite creates
de_DE/fr_FR/ar catalogs, a temporary `cradle-zztrfixture` package directory, users and a test role, and
removes them at the end. It changes the shared sandbox only transiently.
- `examples/de_DE.json` and `examples/ar.json` must cover every framework string (German 2 plural forms, Arabic 6);
  the `translations: built-in German and Arabic examples…` test lists anything missing or invalid. Shipped text only fills
  entries without a translation or draft — never overwrite an administrator's edits.
