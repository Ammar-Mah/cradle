# Cradle Social — package instructions

Scope: social **profile links** (the organization's accounts) and **share links** (share the current
entry/page) as builder-neutral components with Gutenberg blocks. Follow the repository `AGENTS.md`,
`docs/architecture/core-api.md`, and this package's `docs/social.md` and `docs/providers.md`.

## Owns

- Module `social` (depends on core `^1.0` and module `blocks` `^0.1`), capability `cradle_manage_social`.
- Settings section `social` (option `cradle_social_settings`, page Cradle → Social); option `cradle_social_installed_version`.
- Components/blocks `cradle/social-links` (contract `social-links-v1`) and `cradle/share-links` (`share-links-v1`); both dynamic, no saved fallback.
- `Cradle\Social\Providers` — the ONLY place provider hosts and share templates exist; filter `cradle_social/providers` (trusted PHP only).
- `Cradle\Social\Icons` — small reviewed inline SVG set (original generic glyphs).
- Handles `cradle-social` (CSS), `cradle-social-view` (frontend JS), `cradle-social-editor` (editor controls).

## Rules

- Never load remote SDKs, widgets, trackers, iframes, remote images, icon fonts or CDN assets; never add share counts.
- Never let settings, block/shortcode attributes or requests supply a URL template, host, protocol, CSS or HTML.
  Profile URLs: https only (mailto for email), provider host allowlist (Mastodon: federated host + `/@user` path).
- Share URLs: fixed template + single-pass `rawurlencode()` placeholders; output with `esc_url()` (https, mailto).
- Changing a provider template or host: update `Providers::builtin()` AND `docs/providers.md` (with an honest
  "verified on" entry) and run the `social` test suite.
- Icons: add only original generic glyphs using `path`/`circle`/`rect`; never copy brand artwork; never accept SVG uploads.
- The view script is enqueued by `Share_Links::render()` only when copy-link/Web Share is rendered; keep it
  dependency-free and use `textContent` only.
- Accessible names are full actions ("Share on X", "Follow us on LinkedIn"); new tabs only when configured,
  always `rel="noopener noreferrer"` and announced.
- No Open Graph/meta output here unless implemented as a single-owner, filterable, off-by-default feature.
- Uninstall retains data unless `cradle_uninstall_policy['social'] === 'purge'`; never touch post content.

## Tests

`php tests/run.php --suite=social` — fixtures are marked `_cradle_test_fixture = social`, settings are reset at the end.
