# Block authoring without a build step

Conventions follow `packages/plugins/cradle-blocks/blocks/feature-card/`. Example names use a website child theme
(`acme-site`, namespace `acme`). Verify API details against the running WordPress version.

## Files

```
blocks/quote-card/
  block.json          Metadata (apiVersion 3), attributes, supports, asset declarations.
  editor.js           Plain JS using window.wp globals; registers edit/save/deprecated.
  editor.asset.php    Hand-maintained dependency list + version (read by WordPress for editorScript).
  style.css           Frontend + editor styles (declared as "style").
  editor.css          Editor-only adjustments (declared as "editorStyle"), optional.
  view.js             Frontend progressive enhancement (declared as "viewScript"), optional.
```

## block.json

```json
{
	"$schema": "https://schemas.wp.org/wp/7.1/block.json",
	"apiVersion": 3,
	"name": "acme/quote-card",
	"version": "1.1.0",
	"title": "Quote card",
	"category": "text",
	"icon": "format-quote",
	"description": "A quotation with an optional citation.",
	"textdomain": "acme-site",
	"attributes": {
		"quote": { "type": "string", "source": "html", "selector": ".acme-quote-card__text", "default": "" },
		"citation": { "type": "string", "source": "html", "selector": ".acme-quote-card__cite", "default": "" },
		"variant": { "type": "string", "enum": [ "plain", "accent" ], "default": "plain" }
	},
	"supports": {
		"html": false,
		"anchor": true,
		"color": { "background": true, "text": true },
		"spacing": { "padding": true }
	},
	"example": { "attributes": { "quote": "Short sample quotation.", "citation": "Name" } },
	"editorScript": "file:./editor.js",
	"style": "file:./style.css",
	"editorStyle": "file:./editor.css"
}
```

## editor.asset.php

```php
<?php
return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
	'version'      => (string) filemtime( __DIR__ . '/editor.js' ),
);
```

List every `wp-*` package the script uses. Nothing else is bundled.

## editor.js (static block with deprecation)

```js
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var c = wp.components;

	var FORMATS = [ 'core/bold', 'core/italic', 'core/link' ];

	function Edit( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		var blockProps = be.useBlockProps( { className: 'is-variant-' + a.variant } );

		return el(
			Fragment,
			null,
			el(
				be.InspectorControls,
				null,
				el(
					c.PanelBody,
					{ title: __( 'Appearance', 'acme-site' ) },
					el( c.SelectControl, {
						label: __( 'Variant', 'acme-site' ),
						value: a.variant,
						options: [
							{ label: __( 'Plain', 'acme-site' ), value: 'plain' },
							{ label: __( 'Accent', 'acme-site' ), value: 'accent' }
						],
						onChange: function ( value ) { set( { variant: value } ); }
					} )
				)
			),
			el(
				'figure',
				blockProps,
				el( be.RichText, {
					tagName: 'blockquote',
					className: 'acme-quote-card__text',
					value: a.quote,
					allowedFormats: FORMATS,
					placeholder: __( 'Add a quotation…', 'acme-site' ),
					onChange: function ( value ) { set( { quote: value } ); }
				} ),
				el( be.RichText, {
					tagName: 'figcaption',
					className: 'acme-quote-card__cite',
					value: a.citation,
					allowedFormats: [],
					placeholder: __( 'Citation', 'acme-site' ),
					onChange: function ( value ) { set( { citation: value } ); }
				} )
			)
		);
	}

	function save( props ) {
		var a = props.attributes;
		return el(
			'figure',
			be.useBlockProps.save( { className: 'is-variant-' + a.variant } ),
			el( be.RichText.Content, { tagName: 'blockquote', className: 'acme-quote-card__text', value: a.quote } ),
			a.citation ? el( be.RichText.Content, { tagName: 'figcaption', className: 'acme-quote-card__cite', value: a.citation } ) : null
		);
	}

	// Version 1.0.0 saved the quotation in <p>. Keep it so published content stays valid.
	var v1_0 = {
		attributes: {
			quote: { type: 'string', source: 'html', selector: '.acme-quote-card__text', default: '' },
			citation: { type: 'string', source: 'html', selector: '.acme-quote-card__cite', default: '' },
			variant: { type: 'string', enum: [ 'plain', 'accent' ], default: 'plain' }
		},
		supports: { html: false, anchor: true, color: { background: true, text: true }, spacing: { padding: true } },
		save: function ( props ) {
			var a = props.attributes;
			return el(
				'figure',
				be.useBlockProps.save( { className: 'is-variant-' + a.variant } ),
				el( be.RichText.Content, { tagName: 'p', className: 'acme-quote-card__text', value: a.quote } ),
				el( be.RichText.Content, { tagName: 'figcaption', className: 'acme-quote-card__cite', value: a.citation } )
			);
		}
	};

	wp.blocks.registerBlockType( 'acme/quote-card', {
		edit: Edit,
		save: save,
		deprecated: [ v1_0 ]
	} );
} )( window.wp );
```

Server registration (the parent theme registers a child's `components/<slug>/` + `blocks/<slug>/` automatically on `cradle/booted` — the child writes no PHP; framework packages do it in their module `boot`):

```php
register_block_type( CRADLE_SITE_DIR . 'blocks/quote-card' );
```

The client call only supplies `edit`, `save` and `deprecated`; metadata comes from the server-registered
`block.json`.

## Deprecation rules

- Add a deprecation for every published `save()` output or attribute-source change. Newest first in the
  `deprecated` array.
- Each entry reproduces the old `attributes`, `supports` and `save` exactly as they were published.
- Add `migrate( attributes, innerBlocks )` when attribute names/types changed; return the new shape.
- Keep an old-markup fixture (for example in `tests/fixtures/` or inside the integration test) and verify
  it opens without an invalid-block warning.
- If PHP generates block markup (provisioning), keep a helper that mirrors `save()` exactly (see
  `Cradle\Blocks\Feature_Card::block_markup()`), and update it with every `save()` change.

## Dynamic and component-backed blocks

- Component-backed: register the component with `'blockDir' => __DIR__ . '/blocks/<slug>'`; the
  cradle-blocks Gutenberg adapter calls `register_block_type()` with a render callback that maps block
  attributes to component props and adds block-supports wrapper attributes.
- `save()` may return `null` (pure dynamic) or semantic fallback markup that WordPress shows when the
  plugin is inactive; the PHP render is authoritative while active. Document which in the component's
  `fallbackPolicy`.
- Live previews in the editor: `wp.serverSideRender` (declare `wp-server-side-render`) for query-driven
  output; keep editable text inline where possible.
- Render callbacks treat attributes as untrusted: normalize via the component, escape output.

## Container blocks

```js
el( 'div', be.useBlockProps(), el( be.InnerBlocks, {
	allowedBlocks: [ 'acme/quote-card', 'core/heading', 'core/paragraph' ],
	template: [ [ 'core/heading', { level: 2 } ], [ 'acme/quote-card' ] ],
	templateLock: false
} ) );
// save:
el( 'div', be.useBlockProps.save(), el( be.InnerBlocks.Content ) );
```

Use InnerBlocks for genuinely editable children; never store child content as an HTML string attribute.

## Variations and styles

- Styles: `register_block_style( 'core/group', array( 'name' => 'acme-panel', 'label' => __( 'Panel', 'acme-site' ) ) );`
  plus scoped CSS for `.is-style-acme-panel`.
- Variations: `wp.blocks.registerBlockVariation( 'core/group', { name: 'acme-band', title: …, attributes: {…} } )`
  in a plain-JS editor script enqueued on `enqueue_block_editor_assets` with declared dependencies.

## Patterns

```php
<?php
/**
 * Title: Services band
 * Slug: acme-site/services-band
 * Categories: featured
 */
?>
<!-- wp:group {"tagName":"section","className":"acme-services"} -->
<section class="wp-block-group acme-services">
<!-- wp:heading --><h2 class="wp-block-heading"><?php esc_html_e( 'Our services', 'acme-site' ); ?></h2><!-- /wp:heading -->
</section>
<!-- /wp:group -->
```

Pattern markup must be valid block serialization (the same markup `save()` produces), or the inserted
blocks will be invalid.

## Templates and parts

- Child `templates/front-page.html`, `home.html`, `page.html`, `single.html`, `archive.html`,
  `single-<type>.html`, `archive-<type>.html`, `taxonomy-<tax>.html`, `search.html`, `404.html` as needed.
- `parts/header.html`, `parts/footer.html` — no subdirectories.
- Bind to the current query (`core/post-title`, `core/post-content`, `core/query` with `inherit: true`).
- DB-saved templates/parts and user Global Styles override files. Diagnose the effective source; offer
  export or reconcile. Never delete user customizations to make a file edit visible.
