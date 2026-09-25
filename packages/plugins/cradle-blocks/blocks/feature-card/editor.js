/**
 * cradle/feature-card editor integration (plain JS, WordPress-bundled packages only).
 *
 * save() stores semantic fallback markup; while Cradle is active the PHP render
 * service (render contract feature-card-v1) is authoritative on the frontend.
 * Changing save() output requires a block deprecation (see docs/blocks.md).
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var c = wp.components;
	var BASE = 'wp-block-cradle-feature-card';
	// Attributes saved by users without unfiltered_html are kses-encoded ("&" → "&amp;").
	// Decoding before display/serialization keeps save() output stable in both cases.
	var decode = wp.htmlEntities.decodeEntities;

	function headingTag( level ) {
		var n = parseInt( level, 10 );
		return 'h' + ( n >= 2 && n <= 6 ? n : 3 );
	}

	function modifierClasses( a ) {
		return 'is-variant-' + a.variant + ' has-media-' + a.mediaPosition;
	}

	function Edit( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		var blockProps = be.useBlockProps( { className: modifierClasses( a ) } );

		var media = el(
			be.MediaUploadCheck,
			null,
			el( be.MediaUpload, {
				allowedTypes: [ 'image' ],
				value: a.imageId,
				onSelect: function ( m ) {
					var url = m.sizes && m.sizes.large ? m.sizes.large.url : m.url;
					set( { imageId: m.id, imageUrl: url, imageAlt: m.alt || '' } );
				},
				render: function ( o ) {
					if ( a.imageUrl ) {
						return el(
							'figure',
							{ className: BASE + '__media' },
							el( 'img', { src: a.imageUrl, alt: a.imageAlt } ),
							el( c.Button, { variant: 'secondary', onClick: o.open, className: 'cradle-feature-card__replace' }, __( 'Replace image', 'cradle-blocks' ) )
						);
					}
					return el( c.Button, { variant: 'secondary', onClick: o.open }, __( 'Select image', 'cradle-blocks' ) );
				},
			} )
		);

		var inspector = el(
			be.InspectorControls,
			null,
			el(
				c.PanelBody,
				{ title: __( 'Card settings', 'cradle-blocks' ) },
				el( c.SelectControl, {
					label: __( 'Style variant', 'cradle-blocks' ),
					value: a.variant,
					options: [
						{ label: __( 'Plain', 'cradle-blocks' ), value: 'plain' },
						{ label: __( 'Outlined', 'cradle-blocks' ), value: 'outlined' },
						{ label: __( 'Elevated', 'cradle-blocks' ), value: 'elevated' },
					],
					onChange: function ( v ) { set( { variant: v } ); },
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
				} ),
				el( c.SelectControl, {
					label: __( 'Image position', 'cradle-blocks' ),
					value: a.mediaPosition,
					options: [
						{ label: __( 'Above text', 'cradle-blocks' ), value: 'top' },
						{ label: __( 'Beside text', 'cradle-blocks' ), value: 'start' },
					],
					onChange: function ( v ) { set( { mediaPosition: v } ); },
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
				} ),
				el( c.SelectControl, {
					label: __( 'Heading level', 'cradle-blocks' ),
					value: String( a.headingLevel ),
					options: [ 2, 3, 4, 5, 6 ].map( function ( n ) {
						return { label: 'H' + n, value: String( n ) };
					} ),
					onChange: function ( v ) { set( { headingLevel: parseInt( v, 10 ) } ); },
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
				} ),
				el( c.TextControl, {
					label: __( 'Image alternative text', 'cradle-blocks' ),
					help: __( 'Describe the image. Leave empty only if it is decorative.', 'cradle-blocks' ),
					value: decode( a.imageAlt || '' ),
					onChange: function ( v ) { set( { imageAlt: v } ); },
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
				} ),
				a.imageId
					? el( c.Button, {
						variant: 'link',
						isDestructive: true,
						onClick: function () { set( { imageId: 0, imageUrl: '', imageAlt: '' } ); },
					}, __( 'Remove image', 'cradle-blocks' ) )
					: null,
				el( c.TextControl, {
					label: __( 'Link URL', 'cradle-blocks' ),
					type: 'url',
					value: decode( a.link || '' ),
					onChange: function ( v ) { set( { link: v } ); },
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
				} ),
				el( c.ToggleControl, {
					label: __( 'Make the heading the link', 'cradle-blocks' ),
					checked: !! a.titleLink,
					onChange: function ( v ) { set( { titleLink: v } ); },
					__nextHasNoMarginBottom: true,
				} )
			)
		);

		return el(
			Fragment,
			null,
			inspector,
			el(
				'article',
				blockProps,
				media,
				el( be.PlainText, {
					className: BASE + '__title ' + BASE + '__title--' + headingTag( a.headingLevel ),
					value: decode( a.title || '' ),
					placeholder: __( 'Card heading', 'cradle-blocks' ),
					'aria-label': __( 'Card heading', 'cradle-blocks' ),
					onChange: function ( v ) { set( { title: v } ); },
				} ),
				el( be.RichText, {
					tagName: 'div',
					className: BASE + '__body',
					value: a.body,
					allowedFormats: [ 'core/bold', 'core/italic', 'core/link' ],
					placeholder: __( 'Short supporting text…', 'cradle-blocks' ),
					'aria-label': __( 'Card text', 'cradle-blocks' ),
					onChange: function ( v ) { set( { body: v } ); },
				} ),
				a.titleLink
					? null
					: el( be.PlainText, {
						className: BASE + '__link',
						value: decode( a.linkText || '' ),
						placeholder: __( 'Link text (optional)', 'cradle-blocks' ),
						'aria-label': __( 'Link text', 'cradle-blocks' ),
						onChange: function ( v ) { set( { linkText: v } ); },
					} )
			)
		);
	}

	function save( props ) {
		var a = props.attributes;
		var blockProps = be.useBlockProps.save( { className: modifierClasses( a ) } );
		var children = [];
		var link = decode( a.link || '' );
		if ( a.imageUrl ) {
			children.push( el( 'figure', { className: BASE + '__media' }, el( 'img', { src: decode( a.imageUrl ), alt: decode( a.imageAlt || '' ) } ) ) );
		}
		if ( a.title ) {
			var title = a.titleLink && link ? el( 'a', { href: link }, decode( a.title ) ) : decode( a.title );
			children.push( el( headingTag( a.headingLevel ), { className: BASE + '__title' }, title ) );
		}
		if ( a.body ) {
			children.push( el( be.RichText.Content, { tagName: 'div', className: BASE + '__body', value: a.body } ) );
		}
		if ( link && a.linkText && ! a.titleLink ) {
			children.push( el( 'a', { className: BASE + '__link', href: link }, decode( a.linkText ) ) );
		}
		return el.apply( null, [ 'article', blockProps ].concat( children ) );
	}

	wp.blocks.registerBlockType( 'cradle/feature-card', {
		edit: Edit,
		save: save,
	} );
} )( window.wp );
