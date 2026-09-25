# Component contract reference

Authoritative implementation: `packages/plugins/cradle/src/class-components.php`. If this file and the
validator disagree, the validator wins; update this reference.

## Descriptor (schemaVersion 1)

| Key | Rule |
| --- | --- |
| `schemaVersion` | Must be `1`. |
| `id` | `^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$`, stable forever (e.g. `cradle/feature-card`, `acme/service-list`). |
| `version` | Semantic version of the component. |
| `owner` | Owning package slug (`cradle-blocks`, `cradle-social`, the child theme slug for site components…). |
| `title`, `description`, `category` | Human metadata (plain strings; no translation calls in JSON). |
| `properties` | Map of camelCase names (`^[a-z][a-zA-Z0-9]{0,40}$`) to typed schemas. |
| `slots` | List of `{ name (camelCase), description, allowed: [block names] }` for InnerBlocks content. |
| `adapters` | e.g. `{ "gutenberg": { "blockName": "cradle/feature-card" }, "shortcode": true }`. |
| `assets` | `styleHandles`, `viewScriptHandles`, `editorStyleHandles`: lowercase slug handles registered in PHP. |
| `renderContract` | `^[a-z0-9-]+-v\d+$`; the version child overrides declare. |
| `themeOverride` | Empty or exactly `cradle/components/<slug>/render.php`. |
| `fallbackPolicy` | `saved-semantic-html`, `static-snapshot`, or `none`. |
| `cache` | `none` or `public`. Never `public` for user-specific output. |

Property types: `string`, `integer`, `number`, `boolean`, `array`. String formats: `plain`,
`limited-rich-text`, `url`, `slug`, `color`, `multiline`. Integer formats (entity references validated at
render time): `attachment`, `post`, `term`, `user`. Arrays need `items.type`
(`string|integer|number|boolean|object`); object items may declare `properties` but must not nest arrays.
Use `enum` (a list), `default`, `minimum`, `maximum`, `maxLength` to bound values.

Unknown top-level keys are not part of the contract. Callbacks, PHP paths and template names are never
accepted from JSON or settings — render callables are passed in trusted PHP.

## Render callable

```php
final class Service_List {
	public static function render( array $p, array $context = array(), array $component = array() ): string {
		$items = get_posts( array(
			'post_type'      => 'service',
			'posts_per_page' => min( 12, max( 1, (int) $p['count'] ) ), // Bounded query.
			'no_found_rows'  => true,
		) );
		if ( ! $items ) {
			return '';
		}
		$out = '';
		foreach ( $items as $item ) {
			$out .= '<li class="acme-service-list__item"><a href="' . esc_url( get_permalink( $item ) ) . '">'
				. esc_html( get_the_title( $item ) ) . '</a></li>';
		}
		return '<ul class="acme-service-list" id="' . esc_attr( $context['instance_id'] ) . '">' . $out . '</ul>';
	}
}
```

Rules: return escaped HTML; never echo; never read `$_GET`/`$_POST`; no `WP_Block` dependency; batch
queries rather than one query per card; tolerate deleted entities; no remote requests at render time.

Context keys: `adapter` (`gutenberg` | `shortcode` | `php`), `post_id`, `inner_html` (already-rendered
slot content — output as-is only because it came from the block renderer), `instance_id` (unique per
page), `entity_key` (set it when rendering entity-driven nested content to enable cycle protection).
Nesting is limited to 8 levels.

Filter available to integrators: `cradle/component_html` (`$html, $id, $props, $context`); returned
markup must remain escaped.

## Child-theme override

`<child>/cradle/components/<slug>/render.php`:

```php
<?php
/**
 * Render contract: service-list-v1
 */
defined( 'ABSPATH' ) || exit;
?>
<ul class="acme-service-list acme-service-list--compact">
	<?php /* Use $props, $context, $component; escape every value. */ ?>
</ul>
```

Resolution checks the child, then the parent theme, only at the declared path, with canonical containment.
A different contract string is ignored and reported. Overrides change presentation only; business
rules stay in the render service (framework package or the child theme's `components/<slug>/render.php`).

## Versioning

| Change | Action |
| --- | --- |
| New optional property with a default | Bump `version` (minor). |
| Property removed/renamed or type changed | Bump `version` (major); map old attributes (block deprecation or migration). |
| Markup structure overrides rely on | New `renderContract` (`-v2`); keep `-v1` overrides reported as stale. |
| Saved block markup changes | Block deprecation in the Gutenberg adapter (`cradle-gutenberg`). |

## Lifecycle

- List handles, blocks and stored option/meta keys in the owning module's `dataOwners`.
- Dynamic components document what visitors see when the owning plugin is inactive; prefer saved semantic
  fallback markup or offer conversion (`packages/plugins/cradle-blocks/admin/class-conversion.php`).
