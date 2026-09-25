# Installing Cradle

## Requirements

WordPress 7.1.x single site (multisite network activation is refused), PHP 8.3+, MySQL 8.0+/MariaDB 10.6+,
HTTPS in production. PHP `zip` is needed for Cradle Migration. See `compatibility.json`.

## Packages (from `dist/`, built with `php scripts/build.php`)

| ZIP | Install as |
| --- | --- |
| `cradle-kit-*.zip` | Everything at once, no commands: extract it into the WordPress folder (the one with `wp-config.php`); it merges into `wp-content/` (parent theme, blank child `cradle-child`, every Cradle plugin) |
| `cradle-update-*.zip` | Updating an existing site, no commands: the same parent and plugins **without** any child theme, extracted the same way; it never touches the website's child |
| `cradle-theme-*.zip` | Theme (parent; do not activate directly on a real site) |
| `cradle-child-theme-starter-*.zip` (blank child `cradle-child`) or a child generated with the parent's `bin/child-theme.php` | Theme (activate this) |
| `cradle-plugin-*.zip` | Plugin (core) |
| `cradle-<feature>-plugin-*.zip` | Plugins (add-ons; each needs core) |

Verify downloads against `SHA256SUMS` before installing. Checksums detect corruption, not authorship.

## Fresh site with the kit (no commands)

1. Extract `cradle-kit-<date>.zip` into the WordPress folder; answer "merge" for `wp-content`. Optionally
   rename `wp-content/themes/cradle-child` to your site's name before activating it (the folder name is free;
   theme settings are stored under it, so do not rename it later).
2. Plugins: activate **Cradle** first, then the add-ons you need (Cradle Blocks is needed by most sites).
3. Appearance → Themes: activate **Cradle Child** (not the parent).
4. Continue with step 3 below. On first use a coding agent asks for the site name and fills it in
   (parent `docs/child-themes.md`).

The child stays thin — only site data; the parent loads its styles, script and components — so the parent
and plugins can be replaced by newer versions without editing it. **To update**, extract
`cradle-update-<date>.zip` the same way (answer "replace" for existing files); it contains no child theme.
Never re-extract a full kit over a working site: its blank `cradle-child` would replace a child still using that name.

## Fresh site from separate ZIPs

1. Plugins → Add New → Upload: install and activate **Cradle** (core) first, then the add-ons you need
   (Cradle Blocks provides the generic blocks, including the Hero slider, and is required by Social and most sites).
2. Appearance → Themes → Add New → Upload: install the **Cradle** parent, then your site child theme; activate the child.
3. Open **Cradle → Overview**: it checks the theme and lists every Cradle module (not installed, inactive, ready or failed) with activation links and the "When deleted" data choice.
4. Leave content generation, custom login routing and white-labeling off until they are configured deliberately.
5. Record the installed/active package versions in the site's `docs/project-state.md`.

Recommended `wp-config.php` constants (all optional):

```php
define( 'CRADLE_PRIVATE_DIR', '/home/example/cradle-private' ); // outside the web root; protects archives and catalogs
// Emergency switches (see recovery.md):
// define( 'CRADLE_DISABLE_CUSTOM_LOGIN', true );
// define( 'CRADLE_ADMIN_SAFE_MODE', true );
```

## Existing site

Cradle does not overwrite site titles, menus, homepages, permalinks, users or settings on activation.
Install core + add-ons and the parent, add a child (the kit's `cradle-child`, the starter ZIP, or
`php wp-content/themes/cradle/bin/child-theme.php new --slug=<slug> --name="<Site Name>"`), and review
each module's settings before enabling it.

## New website (developers and coding agents)

The parent theme creates and maintains child themes (there is no site plugin). Without commands, copy the
kit's `cradle-child`; with commands:

```
php wp-content/themes/cradle/bin/child-theme.php new --slug=acme-site --name="Acme Studio"
php wp-content/themes/cradle/bin/child-theme.php check --child=wp-content/themes/acme-site --add-missing
```

In the Cradle repository use `php packages/themes/cradle/bin/child-theme.php new …` (writes to
`sites/<slug>/themes/<slug>`). Put the brief in the child's (empty) `northstar.md` — checklist in the parent's `docs/website-brief.md` — and reference material in its
`design-reference/`, then follow `skills/cradle-design-to-wordpress`.
