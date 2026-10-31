/**
 * cradle/language-switcher editor registration (plain JS, no build step).
 *
 * Dynamic block: save() is null and the frontend output comes from the PHP render service
 * (render contract language-switcher-v1). The editor preview is rendered by the server for the
 * default language.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var c = wp.components;
	var config = window.cradleLanguagesEditor || { languages: [] };

	wp.blocks.registerBlockType( 'cradle/language-switcher', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var blockProps = be.useBlockProps();
			var preview = ( config.languages || [] ).length < 2
				? el( 'p', { className: 'cradle-language-switcher__placeholder' }, __( 'Add a second language under Cradle → Languages to show the switcher.', 'cradle-languages' ) )
				: el( wp.serverSideRender, { block: 'cradle/language-switcher', attributes: a, skipBlockSupportAttributes: true } );
			return el(
				'div',
				blockProps,
				el(
					be.InspectorControls,
					null,
					el(
						c.PanelBody,
						{ title: __( 'Language switcher', 'cradle-languages' ), initialOpen: true },
						el( c.SelectControl, {
							label: __( 'Layout', 'cradle-languages' ),
							value: a.layout,
							options: [
								{ value: 'list', label: __( 'List of links', 'cradle-languages' ) },
								{ value: 'dropdown', label: __( 'Dropdown (opens on click, works without JavaScript)', 'cradle-languages' ) }
							],
							onChange: function ( v ) { set( { layout: v } ); },
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true
						} ),
						el( c.SelectControl, {
							label: __( 'Show', 'cradle-languages' ),
							value: a.display,
							options: [
								{ value: 'native', label: __( 'Native names (Deutsch, العربية)', 'cradle-languages' ) },
								{ value: 'name', label: __( 'Names (German, Arabic)', 'cradle-languages' ) },
								{ value: 'slug', label: __( 'Codes (DE, AR)', 'cradle-languages' ) },
								{ value: 'native_slug', label: __( 'Native names and codes', 'cradle-languages' ) }
							],
							onChange: function ( v ) { set( { display: v } ); },
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true
						} ),
						el( c.ToggleControl, {
							label: __( 'Hide languages without a translation', 'cradle-languages' ),
							help: __( 'Off: such languages link to their home page.', 'cradle-languages' ),
							checked: !! a.hideMissing,
							onChange: function ( v ) { set( { hideMissing: v } ); },
							__nextHasNoMarginBottom: true
						} ),
						el( c.ToggleControl, {
							label: __( 'Hide the current language', 'cradle-languages' ),
							checked: !! a.hideCurrent,
							onChange: function ( v ) { set( { hideCurrent: v } ); },
							__nextHasNoMarginBottom: true
						} ),
						el( c.TextControl, {
							label: __( 'Accessible name', 'cradle-languages' ),
							help: __( 'Read by screen readers. Empty uses “Languages”.', 'cradle-languages' ),
							value: a.label,
							maxLength: 80,
							onChange: function ( v ) { set( { label: v } ); },
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true
						} )
					)
				),
				el( c.Disabled, null, preview )
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp );
