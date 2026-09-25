# Component descriptor (schemaVersion 1)

A Cradle-specific manifest, separate from `block.json`, validated by `Cradle\Core\Components::validate_descriptor()`.
Registered only from trusted package code (`register()` / `register_from_file()` for JSON inside a plugin).

| Key | Required | Rules |
| --- | --- | --- |
| `schemaVersion` | yes | `1` |
| `id` | yes | `namespace/name`, lowercase; stable forever |
| `version` | yes | semver of the component contract |
| `owner` | yes | owning package slug |
| `title`, `description`, `category` | no | display only |
| `properties` | no | map of camelCase names → property schema (below) |
| `slots` | no | list of `{ name (camelCase), description, allowed[] }` — rendered slot HTML arrives as `context.inner_html` |
| `adapters` | no | e.g. `{ "gutenberg": { "blockName": "cradle/x" }, "shortcode": true }` |
| `assets` | no | `styleHandles`, `viewScriptHandles`, `editorStyleHandles` (registered handles, enqueued on render) |
| `renderContract` | yes | `name-vN`; overrides must declare the same value |
| `themeOverride` | no | exactly `cradle/components/<slug>/render.php` |
| `fallbackPolicy` | no | `saved-semantic-html` \| `static-snapshot` \| `none` (default) |
| `cache` | no | `none` (default) \| `public` — never mark user-specific output public |

## Property schema

| Type | Options |
| --- | --- |
| `string` | `default`, `maxLength` (default 5000), `enum`, `format`: `plain` (default), `limited-rich-text` (kses allowlist), `url` (http/https/mailto/tel), `slug`, `color` (hex or preset slug), `multiline` |
| `integer` | `default`, `minimum`, `maximum`, `format`: `attachment` \| `post` \| `term` \| `user` (reference must exist; otherwise normalized to 0) |
| `number` | `default`, `minimum`, `maximum` |
| `boolean` | `default` |
| `array` | `items` (`string`/`integer`/`number`/`boolean`/`object` with one level of `properties`), `maxItems` (default 50) |

Normalization drops unknown keys, rejects invalid values to defaults, entity-decodes plain strings
(block attributes saved by users without `unfiltered_html` are kses-encoded) and bounds arrays.

## Versioning relationship

- Component `version` changes when properties or semantics change.
- `renderContract` changes when markup/classes that overrides or CSS rely on change; stale overrides are then ignored and reported.
- Block attribute schema (`block.json`) mirrors `properties` plus editor-only attributes (e.g. `imageUrl` for saved fallback).
- Any change to a block's saved markup requires a block `deprecated` entry and a fixture test; property renames need an attribute migration.
