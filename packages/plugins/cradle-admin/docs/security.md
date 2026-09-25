# Cradle Admin — security notes

## Trust boundaries

| Input | Who | Handling |
| --- | --- | --- |
| Settings sections (options.php) | `cradle_manage_branding` (administrators) | Core Settings API: `option_page_capability_*`, group nonce, stale `_revision` check; each field validated by type/range/allowlist before normalization; invalid fields keep previous values. |
| Section reset/export/import | same | Core handlers: section nonce + capability. |
| Raw CSS save/restore/reset | `cradle_manage_branding` + `manage_options` + `unfiltered_html` | POST only (405 otherwise), `check_admin_referer`, trusted-admin check, `Css_Policy`, stale-revision check. |
| Profile choice | `edit_user` on that user | WordPress' own profile nonce and check, plus a dedicated nonce and a second `edit_user` check in the handler. |
| Stored options | database | Not trusted blindly: colors re-checked as hex, dimensions re-clamped, enums mapped to fixed strings at output; raw CSS printed only with a valid HMAC signature from a policy-checked save, otherwise re-validated and skipped on failure. |
| Welcome content | administrators | `wp_kses` allowlist on save **and** on output; links limited to https/http/mailto. |
| Labels | administrators | Plain text only (no `<`, `>`, control characters), bounded length, escaped at output (`esc_html` for menu labels; WordPress escapes post type labels in its screens). Text overrides must keep sprintf placeholders exactly (prevents `ArgumentCountError`). |
| Filters from site code | trusted PHP | Values from `cradle_admin/*` filters are validated with the same rules; safe mode can only be switched on. |

Visual changes never grant or remove capabilities; menu renames do not change menu access. No REST endpoints are added.

## Raw CSS policy (guardrail, not a sanitizer)

Regular expressions and a small scanner cannot make arbitrary CSS safe. The field exists only for trusted
administrators who can already add unfiltered HTML; the policy reduces accidents and obvious abuse:

- ≤ 65,536 bytes, valid UTF-8, no control characters.
- No `<` anywhere (prevents `</style>` container escapes and markup).
- Comments must be closed, strings closed on their line, braces balanced, nesting ≤ 8.
- After removing comments and resolving CSS escapes (`\69`, `\\`, line continuations), reject: `@import`,
  `expression(`, `javascript:`/`vbscript:`/`livescript:`, `behavior:`/`behaviour:`, `-moz-binding`, `progid:`,
  `image-set()`/`-webkit-image-set()`, `src()`, `image()` (functions that accept bare URL strings).
- Every `url()` must parse; allowed targets: relative paths, `#fragments`, http(s) URLs whose host is this site's
  host (home/site/admin/content/includes URLs), and `data:image/(png|gif|jpeg|webp|avif|svg+xml)`. Backslashes and
  tabs/newlines inside URLs are normalized the way browsers do before the host check (`/\evil` → `//evil`).
  SVG data URIs must be percent-encoded (they otherwise contain `<`). External fonts are not allowed.
- A rule whose selector targets update/security notice classes (`update-nag`, `update-message`, `update-plugins`,
  `plugin-update`, `wp-admin-bar-updates`, `core-updates`, `notice-error`, `notice-warning`, `site-health`,
  `recovery-mode`) must not hide them (`display:none`, `visibility:hidden`, `opacity:0`, zero height/font-size,
  `clip-path`). This is a heuristic.
- When `DISALLOW_UNFILTERED_HTML` is true or `cradle_admin/raw_css_available` returns false, raw CSS cannot be
  edited and stored CSS is not printed; structured controls remain.

## Output

CSS custom properties are generated only from validated values; logo URLs are `esc_url_raw` + percent-encoding of
`" ' ( ) \ < >` and whitespace inside `url("…")`. Inline styles are added with `wp_add_inline_style` (never contain `<`).
Admin bar titles and dashboard widget titles are escaped by this plugin (WordPress prints them raw);
`login_headertext` is escaped here because `wp-login.php` prints it unescaped.

## Operational

- White labeling does not disable updates, remove the update count bubbles, the `updates` admin bar node, the
  WordPress version in the footer, or core notices; the brand menu keeps an **About WordPress** link.
- Privileged changes to raw CSS are recorded with `cradle_log_activity()` (sizes and revision numbers only);
  settings saves/resets are logged by core.
- No remote requests, no files written, no cron, no custom tables.
