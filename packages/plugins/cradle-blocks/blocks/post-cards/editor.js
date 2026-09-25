/**
 * cradle/post-cards editor integration (plain JS). Dynamic block: save() is null and
 * the preview is produced by the same PHP render service through ServerSideRender.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var c = wp.components;
	var useSelect = wp.data.useSelect;
	var ServerSideRender = wp.serverSideRender;

	function Edit( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		var blockProps = be.useBlockProps();

		var data = useSelect( function ( select ) {
			var core = select( 'core' );
			var types = ( core.getPostTypes( { per_page: -1 } ) || [] ).filter( function ( t ) {
				return t.viewable && t.slug !== 'attachment';
			} );
			var current = types.filter( function ( t ) { return t.slug === a.postType; } )[ 0 ];
			var taxonomies = current ? ( core.getTaxonomies( { per_page: -1 } ) || [] ).filter( function ( t ) {
				return t.visibility && t.visibility.public && current.taxonomies.indexOf( t.slug ) !== -1;
			} ) : [];
			var terms = a.taxonomy ? core.getEntityRecords( 'taxonomy', a.taxonomy, { per_page: 100, hide_empty: false } ) || [] : [];
			return { types: types, taxonomies: taxonomies, terms: terms };
		}, [ a.postType, a.taxonomy ] );

		function opts( list, emptyLabel ) {
			var out = emptyLabel ? [ { label: emptyLabel, value: '' } ] : [];
			return out.concat( list.map( function ( item ) {
				return { label: item.name || item.slug, value: item.slug };
			} ) );
		}

		var common = { __nextHasNoMarginBottom: true, __next40pxDefaultSize: true };

		return el(
			Fragment,
			null,
			el(
				be.InspectorControls,
				null,
				el(
					c.PanelBody,
					{ title: __( 'Content', 'cradle-blocks' ) },
					el( c.SelectControl, Object.assign( {
						label: __( 'Content type', 'cradle-blocks' ),
						value: a.postType,
						options: opts( data.types ),
						onChange: function ( v ) { set( { postType: v, taxonomy: '', term: '' } ); },
					}, common ) ),
					el( c.SelectControl, Object.assign( {
						label: __( 'Filter by taxonomy', 'cradle-blocks' ),
						value: a.taxonomy,
						options: opts( data.taxonomies, __( 'No filter', 'cradle-blocks' ) ),
						onChange: function ( v ) { set( { taxonomy: v, term: '' } ); },
					}, common ) ),
					a.taxonomy ? el( c.SelectControl, Object.assign( {
						label: __( 'Term', 'cradle-blocks' ),
						value: a.term,
						options: opts( data.terms, __( 'Any term', 'cradle-blocks' ) ),
						onChange: function ( v ) { set( { term: v } ); },
					}, common ) ) : null,
					el( c.RangeControl, Object.assign( {
						label: __( 'Number of items', 'cradle-blocks' ),
						value: a.count,
						min: 1,
						max: 24,
						onChange: function ( v ) { set( { count: v || 1 } ); },
					}, common ) ),
					el( c.SelectControl, Object.assign( {
						label: __( 'Order by', 'cradle-blocks' ),
						value: a.orderBy,
						options: [
							{ label: __( 'Publish date', 'cradle-blocks' ), value: 'date' },
							{ label: __( 'Title', 'cradle-blocks' ), value: 'title' },
							{ label: __( 'Manual order', 'cradle-blocks' ), value: 'menu_order' },
							{ label: __( 'Last modified', 'cradle-blocks' ), value: 'modified' },
						],
						onChange: function ( v ) { set( { orderBy: v } ); },
					}, common ) ),
					el( c.SelectControl, Object.assign( {
						label: __( 'Direction', 'cradle-blocks' ),
						value: a.order,
						options: [
							{ label: __( 'Descending', 'cradle-blocks' ), value: 'desc' },
							{ label: __( 'Ascending', 'cradle-blocks' ), value: 'asc' },
						],
						onChange: function ( v ) { set( { order: v } ); },
					}, common ) ),
					el( c.TextControl, Object.assign( {
						label: __( 'Message when empty', 'cradle-blocks' ),
						value: a.emptyMessage,
						onChange: function ( v ) { set( { emptyMessage: v } ); },
					}, common ) )
				),
				el(
					c.PanelBody,
					{ title: __( 'Layout', 'cradle-blocks' ) },
					el( c.RangeControl, Object.assign( {
						label: __( 'Columns', 'cradle-blocks' ),
						value: a.columns,
						min: 1,
						max: 4,
						onChange: function ( v ) { set( { columns: v || 1 } ); },
					}, common ) ),
					el( c.SelectControl, Object.assign( {
						label: __( 'Card style', 'cradle-blocks' ),
						value: a.variant,
						options: [
							{ label: __( 'Plain', 'cradle-blocks' ), value: 'plain' },
							{ label: __( 'Outlined', 'cradle-blocks' ), value: 'outlined' },
							{ label: __( 'Elevated', 'cradle-blocks' ), value: 'elevated' },
						],
						onChange: function ( v ) { set( { variant: v } ); },
					}, common ) ),
					el( c.SelectControl, Object.assign( {
						label: __( 'Heading level', 'cradle-blocks' ),
						value: String( a.headingLevel ),
						options: [ 2, 3, 4, 5, 6 ].map( function ( n ) { return { label: 'H' + n, value: String( n ) }; } ),
						onChange: function ( v ) { set( { headingLevel: parseInt( v, 10 ) } ); },
					}, common ) ),
					el( c.ToggleControl, {
						label: __( 'Show featured image', 'cradle-blocks' ),
						checked: !! a.showImage,
						onChange: function ( v ) { set( { showImage: v } ); },
						__nextHasNoMarginBottom: true,
					} ),
					el( c.ToggleControl, {
						label: __( 'Show excerpt', 'cradle-blocks' ),
						checked: !! a.showExcerpt,
						onChange: function ( v ) { set( { showExcerpt: v } ); },
						__nextHasNoMarginBottom: true,
					} )
				)
			),
			el(
				'div',
				blockProps,
				el( ServerSideRender, { block: 'cradle/post-cards', attributes: a } )
			)
		);
	}

	wp.blocks.registerBlockType( 'cradle/post-cards', {
		edit: Edit,
		save: function () {
			return null;
		},
	} );
} )( window.wp );
