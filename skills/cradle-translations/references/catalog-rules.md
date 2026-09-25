# Catalog rules

Source: northstar §14.2–§14.5, WordPress i18n guides [R21], [R22], script translations [R23], gettext
hooks [R24].

## Catalog schema (one per locale)

```json
{
	"schemaVersion": 1,
	"locale": "de_DE",
	"revision": 4,
	"domains": {
		"woocommerce": [
			{
				"id": "loop.add-to-cart",
				"msgid": "Add to cart",
				"context": null,
				"msgidPlural": null,
				"translations": [ "In den Warenkorb" ],
				"status": "translated",
				"sources": [ "php", "js:wc-blocks-product-button" ]
			}
		],
		"cradle": [
			{
				"id": "items.count",
				"msgid": "%d item",
				"context": "cart summary",
				"msgidPlural": "%d items",
				"translations": [ "%d Artikel", "%d Artikel" ],
				"status": "translated"
			}
		]
	}
}
```

- Identity: `domain` + `msgid` + `context` + `msgidPlural`. Two entries with the same `msgid` and different
  contexts are different strings. `id` is a UI convenience only.
- `status`: `untranslated`, `draft`, `translated`, `obsolete`.
- `locale`: `^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$` and must match the file.

## Limits (reject beyond)

| Limit | Value (adjust in package docs, never unbounded) |
| --- | --- |
| File size | 5 MB |
| JSON depth | 8 |
| Entries per catalog | 20 000 |
| String length | 10 000 bytes |
| Domains | `^[a-z0-9_-]{1,100}$` |
| Encoding | Valid UTF-8 only (`mb_check_encoding`) |

## Placeholders

Extract with `/%(?:\d+\$)?[-+ 0'#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX%]/`. Rules:

- The translation's placeholder multiset must equal the original's (ignoring `%%`).
- Unnumbered placeholders keep their order; to reorder, both sides must use numbered forms (`%1$s`).
- Each plural form must contain the placeholders of its original (`msgid` for singular, `msgidPlural` for
  others), except locales where one form covers the singular (document per locale).
- HTML: only tags present in the original and allowed by `wp_kses_allowed_html( 'data' )`-style
  allowlists; attributes unchanged.

## Plurals

Use a known table keyed by language, never an expression supplied by a catalog and never `eval`:

| Locales (examples) | nplurals | Rule |
| --- | --- | --- |
| en, de, nl, sv, it, es | 2 | `n != 1` |
| fr, pt_BR | 2 | `n > 1` |
| ja, zh_CN, ko | 1 | `0` |
| ru, uk | 3 | Slavic one/few/many |
| pl | 3 | one / few (2–4 not 12–14) / many |
| ar | 6 | zero, one, two, few, many, other |

Confirm each locale's form count against the shipped WordPress translation header before adding it to
the table. Store the rule as a known identifier (`two_forms_n_ne_1`, `slavic_3`, `arabic_6`), implemented in PHP
and emitted as the matching `Plural-Forms` header string for JS.

## PHP runtime

```php
// Built once per request for the current locale; small array lookups only.
add_filter( "gettext_with_context_{$domain}", static function ( $translation, $text, $context ) use ( $map ) {
	$key = $context . "\4" . $text;
	return $map[ $key ][0] ?? $translation;
}, 10, 3 );
```

Register the four domain-specific filters only for domains with overrides; keep a per-locale in-memory
map; no file reads, database queries, or broad scans per string.

## JS runtime (Jed shape expected by `wp.i18n`)

```json
{
	"translation-revision-date": "2026-09-24 10:00+0000",
	"generator": "Cradle Translations",
	"domain": "messages",
	"locale_data": {
		"messages": {
			"": { "domain": "messages", "lang": "de_DE", "plural-forms": "nplurals=2; plural=(n != 1);" },
			"Add to cart": [ "In den Warenkorb" ],
			"cart summary\u0004%d item": [ "%d Artikel", "%d Artikel" ]
		}
	}
}
```

Merge overrides into the shipped script translations for that handle and domain (filter
`load_script_translations`/`pre_load_script_translations`), keyed by script handle. An arbitrary label
JSON is not a translation bundle.

## Fallback order

Site override (published catalog) → shipped/native translation → original source string.

## Import and extraction safety

- Imports accept only the documented JSON (and optionally a supported gettext source file parsed as
  data); no PHP, no MO compilation from untrusted input into executable caches.
- Extraction reads known package files with `token_get_all()`; no execution, no arbitrary paths, no
  remote URLs.
- Obsolete entries stay with status `obsolete` until a translator reviews them.
