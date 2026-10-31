/**
 * cradle/language-section editor registration (plain JS, no build step).
 *
 * Container block: the saved markup is the inner blocks only (no wrapper), so without the plugin
 * every language's section is shown. While active, the PHP render service outputs the inner blocks
 * only in the selected languages. The editor always shows the content with a label.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var be = wp.blockEditor;
	var c = wp.components;
	var config = window.cradleLanguagesEditor || { languages: [] };

	function names( slugs ) {
		var out = [];
		( config.languages || [] ).forEach( function ( lang ) {
			if ( slugs.indexOf( lang.slug ) !== -1 ) {
				out.push( lang.name );
			}
		} );
		return out;
	}

	wp.blocks.registerBlockType( 'cradle/language-section', {
		edit: function ( props ) {
			var selected = Array.isArray( props.attributes.languages ) ? props.attributes.languages : [];
			var blockProps = be.useBlockProps( { className: 'cradle-language-section-editor' } );
			var shown = names( selected );
			var label = shown.length
				/* translators: %s: comma-separated language names */
				? sprintf( __( 'Shown in: %s', 'cradle-languages' ), shown.join( ', ' ) )
				: __( 'Shown in every language (choose languages in the block settings)', 'cradle-languages' );
			return el(
				'div',
				blockProps,
				el(
					be.InspectorControls,
					null,
					el(
						c.PanelBody,
						{ title: __( 'Languages', 'cradle-languages' ), initialOpen: true },
						( config.languages || [] ).map( function ( lang ) {
							return el( c.CheckboxControl, {
								key: lang.slug,
								label: lang.name + ' (' + lang.slug + ')',
								checked: selected.indexOf( lang.slug ) !== -1,
								onChange: function ( on ) {
									var next = selected.filter( function ( s ) { return s !== lang.slug; } );
									if ( on ) {
										next.push( lang.slug );
									}
									props.setAttributes( { languages: next } );
								},
								__nextHasNoMarginBottom: true
							} );
						} )
					)
				),
				el( 'p', { className: 'cradle-language-section-editor__label' }, label ),
				el( be.InnerBlocks, { templateLock: false } )
			);
		},
		save: function () {
			return el( be.InnerBlocks.Content );
		}
	} );
} )( window.wp );
