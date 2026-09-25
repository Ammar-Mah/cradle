# Deployment guidance (Apache and Nginx)

## Private storage

Preferred: define `CRADLE_PRIVATE_DIR` in `wp-config.php` as an absolute path **outside** the web root.
Otherwise Cradle stores private files under `wp-content/uploads/cradle-private-<random>/` and writes
Apache (`.htaccess`) and IIS (`web.config`) deny rules. Cradle treats that fallback as protected only
after its live HTTP probe (Site Health → Cradle private storage and the status report on Cradle → Overview; the probe result is cached for a day, `cradle()->storage()->verify_http_denial( true )` re-runs it) proves
direct requests are refused. Nginx ignores `.htaccess`; add:

```nginx
location ~* /wp-content/uploads/cradle-private-[a-z0-9]+/ {
    deny all;
    return 404;
}
```

## Development files inside packages

Development packages contain Markdown/JSON instruction and state files (`AGENTS.md`, `northstar.md`,
`docs/`, `skills/`, `cradle-workspace.json`, `design-reference/`). A dot-prefix, an `index.php` or an
`ABSPATH` guard does **not** protect them. Production sites should either install the release ZIPs
(which exclude a site's private brief, state and design references) or deny these paths:

Apache (in the site root `.htaccess`, before the WordPress block):

```apache
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^wp-content/(themes|plugins)/[^/]+/(docs|skills|design-reference)/ - [F,L]
RewriteRule ^wp-content/(themes|plugins)/[^/]+/(AGENTS|CLAUDE|northstar)\.md$ - [F,L]
RewriteRule ^wp-content/themes/[^/]+/cradle-workspace\.json$ - [F,L]
</IfModule>
```

Nginx:

```nginx
location ~* ^/wp-content/(themes|plugins)/[^/]+/(docs|skills|design-reference)/ { return 404; }
location ~* ^/wp-content/(themes|plugins)/[^/]+/(AGENTS|CLAUDE|northstar)\.md$ { return 404; }
location ~* ^/wp-content/themes/[^/]+/cradle-workspace\.json$ { return 404; }
```

## Pretty permalinks

Apache: the standard WordPress `.htaccess` block (subdirectory installs use `RewriteBase /<dir>/`).
Nginx: `try_files $uri $uri/ /index.php?$args;` in the WordPress location (adjust for subdirectories).
Custom login routing (Cradle Login) and post-type archives rely on working permalinks.

## HTTPS and proxies

Serve authenticated traffic over HTTPS. Cradle does not trust `X-Forwarded-*` headers for security
decisions; configure the proxy at the server/WordPress level if one is used.

## Caching

Do not cache logged-in pages, admin, REST responses for authenticated users, cart/checkout/account pages,
or Cradle admin/job endpoints. Component HTML marked `cache: public` contains no user-specific data.

## Theme releases

WordPress caches theme pattern/theme.json data keyed by theme version: bump the child/parent `Version`
header whenever templates, parts, patterns or theme.json change in a deployment.
