# Cradle Social provider registry

Source of truth: `Cradle\Social\Providers::builtin()` (`src/class-providers.php`). This table must be
updated in the same change as that method.

## Verification status

**None of the share or profile formats below was live-verified in this sandbox.** Implementation
policy forbids external requests from the sandbox, so no provider endpoint was opened, fetched or
submitted. Each format is the provider's publicly documented (or long-standing, widely used) share
endpoint as known on **2026-09-24** (implementation date). Before a release, open each share link
from a real published page in a normal browser session and record the date and result in the
"Verified on" column. Providers change these endpoints without notice; that is why every template is
centralized here and nowhere else.

## Share and profile formats

Placeholders are substituted in a single pass with `rawurlencode()` values; empty parameters are
dropped; the result is printed with `esc_url()` (protocols `https`, `mailto`).

| ID | Label | Profile URL policy (https only) | Share template | Verified on |
| --- | --- | --- | --- | --- |
| `facebook` | Facebook | hosts `facebook.com`, `www.facebook.com`, `m.facebook.com` | `https://www.facebook.com/sharer/sharer.php?u={url}` | not live-verified (sandbox, 2026-09-24) |
| `x` | X | hosts `x.com`, `www.x.com`, `twitter.com`, `www.twitter.com` | `https://x.com/intent/tweet?text={text}&url={url}` (Web Intent; the long-documented form is `https://twitter.com/intent/tweet`) | not live-verified (sandbox, 2026-09-24) |
| `linkedin` | LinkedIn | hosts `linkedin.com`, `www.linkedin.com` | `https://www.linkedin.com/sharing/share-offsite/?url={url}` (LinkedIn reads title/description from the page itself) | not live-verified (sandbox, 2026-09-24) |
| `bluesky` | Bluesky | host `bsky.app`, path `/profile/<handle-or-did>` | `https://bsky.app/intent/compose?text={text_url}` | not live-verified (sandbox, 2026-09-24) |
| `mastodon` | Mastodon | **federated**: any public https host name (no IP, no port, no credentials), path `/@user` or `/@user@domain` only | — profile only (sharing needs the visitor's own instance; no instance-safe share flow is offered) | not live-verified (sandbox, 2026-09-24) |
| `instagram` | Instagram | hosts `instagram.com`, `www.instagram.com` | — profile only (no web share endpoint) | not live-verified (sandbox, 2026-09-24) |
| `youtube` | YouTube | hosts `youtube.com`, `www.youtube.com`, `m.youtube.com` | — profile only | not live-verified (sandbox, 2026-09-24) |
| `whatsapp` | WhatsApp | hosts `wa.me`, `whatsapp.com`, `www.whatsapp.com` | `https://wa.me/?text={text_url}` ("click to chat" with prefilled text) | not live-verified (sandbox, 2026-09-24) |
| `telegram` | Telegram | hosts `t.me`, `telegram.me` | `https://t.me/share/url?url={url}&text={text}` | not live-verified (sandbox, 2026-09-24) |
| `reddit` | Reddit | hosts `reddit.com`, `www.reddit.com` | `https://www.reddit.com/submit?url={url}&title={title}` | not live-verified (sandbox, 2026-09-24) |
| `pinterest` | Pinterest | hosts `pinterest.com`, `www.pinterest.com` | `https://www.pinterest.com/pin/create/button/?url={url}&media={media}&description={text}` (`media` = the entry's featured image on this site, dropped when absent) | not live-verified (sandbox, 2026-09-24) |
| `email` | Email | `mailto:` + one plain address (`is_email()`; no `?`, `&`, `,`, `;`, `%` — no extra headers or recipients). A bare address is accepted and normalized to `mailto:` | `mailto:?subject={title}&body={text_url}` | RFC 6068 form; mail-client behavior not verified |

Local actions (not providers, no network use):

| ID | Label | Behavior |
| --- | --- | --- |
| `copy` | Copy link | `navigator.clipboard.writeText()` in secure contexts, else a temporary selection + `execCommand('copy')`, else the address is shown in the status text. Result announced in a `role="status"` live region. |
| `native` | More sharing options | Web Share API (`navigator.share({title,text,url})`). The button stays `hidden` unless `navigator.share` exists (and `navigator.canShare()` accepts the data). |

## Placeholders

| In templates (fixed PHP) | Value |
| --- | --- |
| `{url}` | Absolute http(s) address of the shared entry/page. |
| `{title}` | Expanded "Default share title" setting (plain text). |
| `{text}` | Expanded "Default share text" with the page address removed (the URL travels in its own parameter). |
| `{text_url}` | Expanded share text with the page address appended once (providers without a URL parameter). |
| `{media}` | The entry's featured image (`large`) URL on this site, or empty. |

Settings placeholders (administrator text) are a separate, smaller set: `{title}`, `{url}`, `{site}`.
Anything else in braces is refused on save; markup is stripped; expansion is single-pass.

## Profile URL validation (all providers)

1. Trim; reject empty, > 500 bytes, invalid UTF-8, whitespace/control characters, quotes, `<`, `>`, `\`, backticks.
2. Parse; scheme must be exactly `https` (mailto only for `email`).
3. Reject credentials (`user:pass@`), explicit ports and `#fragments`.
4. Host must be a syntactically valid DNS name (not an IP address); lowercased; must be in the provider's
   host allowlist (exact match — `facebook.com.evil.example` and `evilfacebook.com` fail).
5. Path must be present (not just `/`), use URL-safe characters, and match the provider's path rule when it has one.
6. Query (if any) must use URL-safe characters. The normalized URL must survive `esc_url_raw()` unchanged.

Stored URLs are re-validated on every render; a stored value that no longer passes is skipped.

## Adding or changing a provider (trusted code only)

```php
add_filter( 'cradle_social/providers', function ( array $providers ) {
	$providers['example'] = array(
		'label'   => 'Example',
		'icon'    => 'generic',                                   // key from Icons::GLYPHS; unknown keys fall back to 'generic'
		'profile' => array( 'hosts' => array( 'example.com' ), 'path' => '#^/[A-Za-z0-9_]+/?$#' ),
		'share'   => array( 'template' => 'https://example.com/share?u={url}&t={text}' ),
	);
	unset( $providers['pinterest'] );                             // remove a provider
	return $providers;
} );
```

Filtered entries are re-validated (`Providers::validate_definition()`); invalid ones are dropped and
listed by `Providers::problems()`. A template must be `https://<fixed host>/<fixed path>?…` (or `mailto:?…`),
may contain placeholders only in the query string, may use only the placeholders above, and must carry
`{url}` or `{text_url}`. The filter is PHP-only: settings, blocks, shortcodes and requests can never add a
template, host or protocol.
