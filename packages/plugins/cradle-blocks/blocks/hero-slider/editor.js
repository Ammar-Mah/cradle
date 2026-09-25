/**
 * cradle/hero-slider editor integration (plain JS, WordPress-bundled packages only).
 *
 * Dynamic block: save() returns null and the preview comes from the same PHP render
 * service through ServerSideRender. Slides are published entries: the latest entries of
 * a content type (optionally one term) or entries chosen here, in the chosen order.
 * Every style and behavior option is a block attribute set by the page editor.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var be = wp.blockEditor;
	var c = wp.components;
	var useSelect = wp.data.useSelect;
	var ServerSideRender = wp.serverSideRender;
	var decode = wp.htmlEntities.decodeEntities;
	var common = { __nextHasNoMarginBottom: true, __next40pxDefaultSize: true };
	var MAX = 12;

	function setter( set, key ) {
		return function ( value ) {
			var next = {};
			next[ key ] = value;
			set( next );
		};
	}

	function toggle( a, set, key, label, help ) {
		return el( c.ToggleControl, { label: label, help: help, checked: !! a[ key ], onChange: setter( set, key ), __nextHasNoMarginBottom: true } );
	}

	function select( a, set, key, label, options, help ) {
		return el( c.SelectControl, Object.assign( { label: label, help: help, value: a[ key ], options: options, onChange: setter( set, key ) }, common ) );
	}

	function range( a, set, key, label, min, max, step, help ) {
		return el( c.RangeControl, Object.assign( {
			label: label,
			help: help,
			value: a[ key ],
			min: min,
			max: max,
			step: step || 1,
			onChange: function ( v ) {
				var next = {};
				next[ key ] = typeof v === 'number' ? v : min;
				set( next );
			},
		}, common ) );
	}

	function colorSelect( a, set, key, label, palette, allowNone ) {
		var current = a[ key ] || '';
		var options = allowNone ? [ { label: __( 'None', 'cradle-blocks' ), value: '' } ] : [];
		palette.forEach( function ( p ) {
			options.push( { label: p.name + ' (' + p.color + ')', value: p.slug } );
		} );
		if ( current && ! palette.some( function ( p ) { return p.slug === current; } ) ) {
			options.push( { label: current, value: current } );
		}
		return select( a, set, key, label, options );
	}

	function lengthControl( a, set, key, label, help ) {
		var Control = c.__experimentalUnitControl || c.UnitControl;
		var units = [ 'px', 'rem', 'vh', 'svh', 'dvh' ].map( function ( u ) {
			return { value: u, label: u, default: u === 'px' ? 600 : ( u === 'rem' ? 40 : 70 ) };
		} );
		if ( ! Control ) {
			return el( c.TextControl, Object.assign( { label: label, help: help, value: a[ key ], onChange: setter( set, key ) }, common ) );
		}
		return el( Control, Object.assign( { label: label, help: help, value: a[ key ], units: units, min: 0, onChange: function ( v ) { setter( set, key )( v || '' ); } }, common ) );
	}

	function Edit( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		var blockProps = be.useBlockProps( { className: 'cradle-hero-editor' } );
		var palette = ( be.useSettings ? be.useSettings( 'color.palette' )[ 0 ] : be.useSetting( 'color.palette' ) ) || [];
		var modeState = useState( a.postIds && a.postIds.length ? 'chosen' : 'latest' );
		var mode = modeState[ 0 ];
		var setMode = modeState[ 1 ];
		var searchState = useState( '' );
		var search = searchState[ 0 ];
		var setSearch = searchState[ 1 ];
		var chosen = ( a.postIds || [] ).filter( function ( id ) { return id > 0; } );

		var data = useSelect( function ( sel ) {
			var core = sel( 'core' );
			var types = ( core.getPostTypes( { per_page: -1 } ) || [] ).filter( function ( t ) {
				return t.viewable && t.slug !== 'attachment';
			} );
			var current = types.filter( function ( t ) { return t.slug === a.postType; } )[ 0 ];
			var taxonomies = current ? ( core.getTaxonomies( { per_page: -1 } ) || [] ).filter( function ( t ) {
				return t.visibility && t.visibility.public && current.taxonomies.indexOf( t.slug ) !== -1;
			} ) : [];
			var terms = a.taxonomy ? core.getEntityRecords( 'taxonomy', a.taxonomy, { per_page: 100, hide_empty: false } ) || [] : [];
			var picked = current && chosen.length ? core.getEntityRecords( 'postType', a.postType, { include: chosen, per_page: MAX, _fields: 'id,title' } ) : [];
			var found = current && 'chosen' === mode ? core.getEntityRecords( 'postType', a.postType, { search: search, status: 'publish', per_page: 20, _fields: 'id,title' } ) : [];
			return { types: types, current: current, taxonomies: taxonomies, terms: terms, picked: picked || [], found: found || [] };
		}, [ a.postType, a.taxonomy, chosen.join( ',' ), mode, search ] );

		function opts( list, emptyLabel ) {
			var out = emptyLabel ? [ { label: emptyLabel, value: '' } ] : [];
			return out.concat( list.map( function ( item ) {
				return { label: item.name || item.slug, value: item.slug };
			} ) );
		}

		function title( id ) {
			var match = data.picked.filter( function ( p ) { return p.id === id; } )[ 0 ];
			/* translators: %d: entry ID */
			return match ? ( decode( ( match.title && match.title.rendered ) || '' ) || sprintf( __( 'Entry #%d', 'cradle-blocks' ), id ) ) : sprintf( __( 'Entry #%d (not published or not available)', 'cradle-blocks' ), id );
		}

		function setChosen( list ) {
			set( { postIds: list.slice( 0, MAX ) } );
		}

		function move( index, delta ) {
			var next = chosen.slice();
			var target = index + delta;
			if ( target < 0 || target >= next.length ) {
				return;
			}
			next.splice( target, 0, next.splice( index, 1 )[ 0 ] );
			setChosen( next );
		}

		var source = [
			el( c.SelectControl, Object.assign( {
				key: 'type',
				label: __( 'Content type', 'cradle-blocks' ),
				help: __( 'Each published entry becomes a slide: its featured image, title, excerpt and a link to it.', 'cradle-blocks' ),
				value: a.postType,
				options: opts( data.types ),
				onChange: function ( v ) { set( { postType: v, taxonomy: '', term: '', postIds: [] } ); },
			}, common ) ),
			el( c.RadioControl, {
				key: 'mode',
				label: __( 'Slides', 'cradle-blocks' ),
				selected: mode,
				options: [
					{ label: __( 'Latest entries', 'cradle-blocks' ), value: 'latest' },
					{ label: __( 'Entries I choose', 'cradle-blocks' ), value: 'chosen' },
				],
				onChange: function ( v ) {
					setMode( v );
					if ( 'latest' === v ) {
						set( { postIds: [] } );
					}
				},
			} ),
		];

		if ( 'latest' === mode ) {
			source.push(
				el( c.SelectControl, Object.assign( {
					key: 'tax',
					label: __( 'Filter by taxonomy', 'cradle-blocks' ),
					value: a.taxonomy,
					options: opts( data.taxonomies, __( 'No filter', 'cradle-blocks' ) ),
					onChange: function ( v ) { set( { taxonomy: v, term: '' } ); },
				}, common ) ),
				a.taxonomy ? el( c.SelectControl, Object.assign( {
					key: 'term',
					label: __( 'Term', 'cradle-blocks' ),
					value: a.term,
					options: opts( data.terms, __( 'Any term', 'cradle-blocks' ) ),
					onChange: setter( set, 'term' ),
				}, common ) ) : null,
				el( 'div', { key: 'count' }, range( a, set, 'count', __( 'Number of slides', 'cradle-blocks' ), 1, MAX ) ),
				el( 'div', { key: 'orderby' }, select( a, set, 'orderBy', __( 'Order by', 'cradle-blocks' ), [
					{ label: __( 'Publish date', 'cradle-blocks' ), value: 'date' },
					{ label: __( 'Title', 'cradle-blocks' ), value: 'title' },
					{ label: __( 'Manual order', 'cradle-blocks' ), value: 'menu_order' },
					{ label: __( 'Last modified', 'cradle-blocks' ), value: 'modified' },
				] ) ),
				el( 'div', { key: 'order' }, select( a, set, 'order', __( 'Direction', 'cradle-blocks' ), [
					{ label: __( 'Descending', 'cradle-blocks' ), value: 'desc' },
					{ label: __( 'Ascending', 'cradle-blocks' ), value: 'asc' },
				] ) )
			);
		} else {
			var rows = chosen.map( function ( id, index ) {
				return el(
					'li',
					{ key: id, className: 'cradle-hero-editor__item' },
					el( 'span', { className: 'cradle-hero-editor__item-name' }, ( index + 1 ) + '. ' + title( id ) ),
					el( c.Button, {
						icon: 'arrow-up-alt2',
						size: 'small',
						/* translators: %s: entry title */
						label: sprintf( __( 'Move %s earlier', 'cradle-blocks' ), title( id ) ),
						disabled: index === 0,
						accessibleWhenDisabled: true,
						onClick: function () { move( index, -1 ); },
					} ),
					el( c.Button, {
						icon: 'arrow-down-alt2',
						size: 'small',
						/* translators: %s: entry title */
						label: sprintf( __( 'Move %s later', 'cradle-blocks' ), title( id ) ),
						disabled: index === chosen.length - 1,
						accessibleWhenDisabled: true,
						onClick: function () { move( index, 1 ); },
					} ),
					el( c.Button, {
						icon: 'no-alt',
						size: 'small',
						isDestructive: true,
						/* translators: %s: entry title */
						label: sprintf( __( 'Remove %s from the slider', 'cradle-blocks' ), title( id ) ),
						onClick: function () { setChosen( chosen.filter( function ( other ) { return other !== id; } ) ); },
					} )
				);
			} );
			var addable = data.found.filter( function ( p ) { return chosen.indexOf( p.id ) === -1; } );
			source.push(
				rows.length
					? el( 'ol', { key: 'list', className: 'cradle-hero-editor__items' }, rows )
					: el( 'p', { key: 'none', className: 'components-base-control__help' }, __( 'No entry chosen yet. Search below and add entries; until then the latest entries are shown.', 'cradle-blocks' ) ),
				chosen.length < MAX ? el( c.TextControl, Object.assign( {
					key: 'search',
					label: __( 'Find entries', 'cradle-blocks' ),
					value: search,
					onChange: setSearch,
				}, common ) ) : null,
				chosen.length < MAX && addable.length ? el( c.SelectControl, Object.assign( {
					key: 'add',
					label: __( 'Add an entry', 'cradle-blocks' ),
					value: '',
					options: [ { label: __( '— Select —', 'cradle-blocks' ), value: '' } ].concat( addable.map( function ( p ) {
						/* translators: %d: entry ID */
						return { label: decode( ( p.title && p.title.rendered ) || '' ) || sprintf( __( 'Entry #%d', 'cradle-blocks' ), p.id ), value: String( p.id ) };
					} ) ),
					onChange: function ( v ) {
						var id = parseInt( v, 10 );
						if ( id ) {
							setChosen( chosen.concat( [ id ] ) );
						}
					},
				}, common ) ) : null
			);
		}

		var inspector = el(
			be.InspectorControls,
			null,
			el( c.PanelBody, { title: __( 'Slides', 'cradle-blocks' ) }, source ),
			el(
				c.PanelBody,
				{ title: __( 'Slide content', 'cradle-blocks' ), initialOpen: false },
				toggle( a, set, 'showExcerpt', __( 'Show the excerpt', 'cradle-blocks' ) ),
				toggle( a, set, 'showButton', __( 'Show a link button', 'cradle-blocks' ) ),
				a.showButton ? el( c.TextControl, Object.assign( {
					label: __( 'Button text', 'cradle-blocks' ),
					help: __( 'Empty uses “Read more”. Screen readers also hear the entry title.', 'cradle-blocks' ),
					value: a.buttonText,
					onChange: setter( set, 'buttonText' ),
				}, common ) ) : null,
				el( c.SelectControl, Object.assign( {
					label: __( 'Title heading level', 'cradle-blocks' ),
					help: __( 'Use H1 only when the slider holds the page title and the page has no other H1.', 'cradle-blocks' ),
					value: String( a.headingLevel ),
					options: [ 1, 2, 3, 4 ].map( function ( n ) { return { label: 'H' + n, value: String( n ) }; } ),
					onChange: function ( v ) { set( { headingLevel: parseInt( v, 10 ) || 2 } ); },
				}, common ) ),
				el( c.TextControl, Object.assign( {
					label: __( 'Accessible name', 'cradle-blocks' ),
					help: __( 'Announced to screen reader users for the slideshow. Empty uses “Featured content”.', 'cradle-blocks' ),
					value: decode( a.label || '' ),
					onChange: setter( set, 'label' ),
				}, common ) )
			),
			el(
				c.PanelBody,
				{ title: __( 'Layout', 'cradle-blocks' ), initialOpen: false },
				lengthControl( a, set, 'minHeight', __( 'Minimum height', 'cradle-blocks' ), __( 'vh/svh/dvh up to 100, rem up to 125, px up to 2000.', 'cradle-blocks' ) ),
				lengthControl( a, set, 'mobileMinHeight', __( 'Minimum height on small screens', 'cradle-blocks' ), __( 'Screens up to 781px wide.', 'cradle-blocks' ) ),
				select( a, set, 'textPlacement', __( 'Text placement', 'cradle-blocks' ), [
					{ label: __( 'Top', 'cradle-blocks' ), value: 'top' },
					{ label: __( 'Middle', 'cradle-blocks' ), value: 'center' },
					{ label: __( 'Bottom', 'cradle-blocks' ), value: 'bottom' },
				] ),
				select( a, set, 'contentAlign', __( 'Text alignment', 'cradle-blocks' ), [
					{ label: __( 'Start', 'cradle-blocks' ), value: 'start' },
					{ label: __( 'Center', 'cradle-blocks' ), value: 'center' },
					{ label: __( 'End', 'cradle-blocks' ), value: 'end' },
				] ),
				select( a, set, 'contentWidth', __( 'Text width', 'cradle-blocks' ), [
					{ label: __( 'Narrow', 'cradle-blocks' ), value: 'narrow' },
					{ label: __( 'Medium', 'cradle-blocks' ), value: 'medium' },
					{ label: __( 'Wide', 'cradle-blocks' ), value: 'wide' },
				] ),
				range( a, set, 'focalX', __( 'Image focus across (%)', 'cradle-blocks' ), 0, 100, 5, __( 'The part of each image that stays visible when it is cropped.', 'cradle-blocks' ) ),
				range( a, set, 'focalY', __( 'Image focus down (%)', 'cradle-blocks' ), 0, 100, 5 )
			),
			el(
				c.PanelBody,
				{ title: __( 'Colors', 'cradle-blocks' ), initialOpen: false },
				colorSelect( a, set, 'overlayColor', __( 'Image overlay', 'cradle-blocks' ), palette, true ),
				range( a, set, 'overlayOpacity', __( 'Overlay strength (%)', 'cradle-blocks' ), 0, 90, 5, __( 'Check that the text stays readable on every image.', 'cradle-blocks' ) ),
				colorSelect( a, set, 'textColor', __( 'Text', 'cradle-blocks' ), palette, false ),
				colorSelect( a, set, 'backgroundColor', __( 'Background (slides without an image)', 'cradle-blocks' ), palette, false ),
				colorSelect( a, set, 'controlsColor', __( 'Controls', 'cradle-blocks' ), palette, false )
			),
			el(
				c.PanelBody,
				{ title: __( 'Behavior', 'cradle-blocks' ), initialOpen: false },
				select( a, set, 'transition', __( 'Transition', 'cradle-blocks' ), [
					{ label: __( 'None (instant)', 'cradle-blocks' ), value: 'none' },
					{ label: __( 'Fade', 'cradle-blocks' ), value: 'fade' },
					{ label: __( 'Slide', 'cradle-blocks' ), value: 'slide' },
				], __( 'Visitors who prefer reduced motion always get instant changes.', 'cradle-blocks' ) ),
				range( a, set, 'duration', __( 'Transition duration (ms)', 'cradle-blocks' ), 0, 1200, 50 ),
				toggle( a, set, 'autoplay', __( 'Autoplay', 'cradle-blocks' ), __( 'Adds a visible pause/play button. Rotation stops when keyboard focus enters or the visitor navigates, and never starts for visitors who prefer reduced motion.', 'cradle-blocks' ) ),
				a.autoplay ? range( a, set, 'interval', __( 'Time per slide (ms)', 'cradle-blocks' ), 3000, 20000, 500 ) : null,
				a.autoplay ? toggle( a, set, 'pauseOnHover', __( 'Pause while hovered', 'cradle-blocks' ) ) : null,
				toggle( a, set, 'arrows', __( 'Previous/next buttons', 'cradle-blocks' ) ),
				toggle( a, set, 'indicators', __( 'Slide position buttons', 'cradle-blocks' ), a.arrows || a.indicators ? undefined : __( 'Previous/next buttons stay visible so everyone can change slides.', 'cradle-blocks' ) ),
				toggle( a, set, 'loop', __( 'Loop', 'cradle-blocks' ) ),
				toggle( a, set, 'swipe', __( 'Touch swipe', 'cradle-blocks' ) )
			)
		);

		var body = data.current ? el(
			c.Disabled,
			null,
			el( ServerSideRender, { block: 'cradle/hero-slider', attributes: a, skipBlockSupportAttributes: true } )
		) : el( c.Placeholder, {
			icon: 'slides',
			label: __( 'Hero slider', 'cradle-blocks' ),
			instructions: __( 'Choose a content type in the block settings. Its published entries become the slides.', 'cradle-blocks' ),
		} );

		return el( Fragment, null, inspector, el( 'div', blockProps, body ) );
	}

	wp.blocks.registerBlockType( 'cradle/hero-slider', {
		edit: Edit,
		save: function () {
			return null;
		},
	} );
} )( window.wp );
