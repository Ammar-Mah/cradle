# File, path, archive, and restore security

Source: northstar §20.4, §15.2–§15.8, §18.2, §26.1; OWASP File Upload Cheat Sheet [R34]; PHP unserialize
manual [R35]; WordPress safe HTTP [R33]. Core helpers: `packages/plugins/cradle/src/class-storage.php`.

## Paths

- Build paths from a known root plus validated segments (`sanitize_file_name()`, `^[a-z0-9._-]+$`).
- Canonicalize and contain: `Cradle\Core\Storage::path_within( $path, $root )` resolves the nearest
  existing parent with `realpath()`, rejects `..` in the remainder, and compares case-insensitively on
  Windows. Re-check at use time (TOCTOU), not only at input time.
- Reject absolute paths, `..`, null bytes, drive letters, stream wrappers (`phar://`, `php://`, `data://`,
  `zip://`), and backslashes in archive entry names.
- Never `include`/`require` a path influenced by users, settings, block attributes or archives.
  Component overrides resolve only the allowlisted `cradle/components/<slug>/render.php` in child/parent.
- Delete only inside a module directory: `$core->storage()->remove_tree( $dir, $module )`.

## Uploads

| Check | Rule |
| --- | --- |
| Capability | Specific capability (e.g. `cradle_manage_fonts`, `cradle_import_site`), not `upload_files` alone. |
| Nonce + method | POST with nonce. |
| Size | Enforce a maximum before reading (`$_FILES[..]['size']` and actual `filesize()`). |
| Extension | Allowlist (`woff2`, `woff`, `ttf`, `otf` for fonts; `json` for catalogs; `zip` for archives). |
| Signature | Read magic bytes (`wOF2`, `wOFF`, `\x00\x01\x00\x00`/`OTTO`, `PK\x03\x04`); never trust the Content-Type header. |
| Content | Parse with bounds (JSON depth/size; font tables; ZIP inventory) and reject SVG fonts, PHP, `.phtml`, `.phar`, `.htaccess`. |
| Handling | `wp_handle_upload()` with `test_form => false` and an explicit `mimes` map, or move into a private module dir; never widen `upload_mimes` globally. |
| Storage | Managed media or `$core->storage()->module_dir()`; never inside the updateable parent theme or a plugin folder. |

## Protected storage

- Prefer `CRADLE_PRIVATE_DIR` outside the web root. Otherwise `module_dir()` writes deny rules and
  `$core->storage()->is_protected()` performs a real HTTP probe; call it before storing archives,
  backups or catalogs with private data, and refuse when it is not `protected`.
- An Apache `.htaccess` rule does not protect Nginx. A random directory name is not protection.
- Markdown/JSON in packages is not protected by `index.php` placeholders or `ABSPATH` guards (§26.1);
  never put secrets in them.
- Downloads: capability-checked, nonce-protected, short-lived handler streaming the file
  (`readfile` after containment check); no public URLs to archives.

## Archives (import, restore, font packs)

Before extracting (northstar §15.5):

1. Verify permission, nonce, target identity, format version, free disk space.
2. Read the inventory (`ZipArchive::statIndex`) without extracting.
3. Reject absolute paths, traversal, null bytes, duplicate/case-conflicting entries, device/special files,
   symlinks (check external attributes), and names outside the expected top-level layout.
4. Enforce limits: compressed size, total uncompressed size, file count, path depth, per-file ratio
   (decompression bomb).
5. Verify per-part and per-file checksums from the manifest (integrity only — not authenticity).
6. Extract one entry at a time with `getStream()` into a staging directory, re-checking containment of the
   canonical destination including existing symlinked parents.
7. Never load, include or execute PHP from staging during inspection; restoring plugin/theme code is a
   privileged deployment step requiring explicit confirmation.

## Serialized data

- Never call `unserialize()`/`maybe_unserialize()` on archive, import or request data. `allowed_classes`
  set to `false` is not a complete policy.
- Use a bounded structural parser that never instantiates classes (Cradle Migration:
  `packages/plugins/cradle-migration/src/class-serialized.php`) and preserves string byte lengths when
  replacing URLs; unsupported object/reference shapes fail preflight with the record identified.

## Remote fetches

- Avoid them; prefer local uploads for fonts and design assets.
- When required: `wp_safe_remote_get()` with `timeout`, `limit_response_size`, `redirection` 0–2,
  scheme allowlist (`https`), host allowlist; re-validate every redirect target; reject private, loopback
  and link-local addresses; never follow URLs taken from reference material automatically.

## Execution

No `eval`, `assert` with strings, `create_function`, shell functions, backticks, `extract()` on external
data, dynamic class names from input, or generated PHP files from user/translator input. Translation
plural rules come from a known table, never from catalog expressions.

## Logs and diagnostics

Redact secrets, tokens, cookies, emails and paths outside the site; bound retention (core activity log is
pruned daily). Progress responses never include archive paths, credentials or table contents.
