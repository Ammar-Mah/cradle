/**
 * Cradle Content — block editor field panels.
 *
 * One PluginDocumentSettingPanel per field group. Values are edited through the post
 * entity's registered REST meta (core/editor editPost({ meta })), so they save, autosave-guard
 * and undo together with the post. Plain JavaScript with wp.element.createElement; no build step.
 * The server validates every value again on save.
 */
( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! config || ! wp.plugins || ! wp.element || ! wp.data || ! wp.components ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var C = wp.components;
	var PluginDocumentSettingPanel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	var MediaUpload = wp.blockEditor && wp.blockEditor.MediaUpload;
	var MediaUploadCheck = wp.blockEditor && wp.blockEditor.MediaUploadCheck;

	if ( ! PluginDocumentSettingPanel ) {
		return;
	}

	var MIME_TO_TYPE = {
		'image/': 'image',
		'video/': 'video',
		'audio/': 'audio',
		'application/pdf': 'application/pdf',
		'text/': 'text',
	};

	function emptyValue( field ) {
		switch ( field.type ) {
			case 'number':
				return null;
			case 'boolean':
				return false;
			case 'attachment':
			case 'post_ref':
			case 'term_ref':
				return 0;
		}
		return '';
	}

	function labelOf( field ) {
		return field.required ? field.label + ' *' : field.label;
	}

	function lookup( field, parentKey, params ) {
		var query = {
			object: 'post',
			subtype: config.postType,
			field: parentKey || field.key,
		};
		if ( parentKey ) {
			query.sub = field.key;
		}
		if ( params.search ) {
			query.search = params.search;
		}
		if ( params.include && params.include.length ) {
			query.include = params.include;
		}
		return wp.apiFetch( { path: wp.url.addQueryArgs( config.lookupPath, query ) } );
	}

	/* ------------------------------------------------------------------ */

	function ReferenceControl( props ) {
		var field = props.field;
		var parentKey = props.parentKey;
		var multiple = !! field.multiple && ! parentKey;
		var raw = props.value;
		var ids = ( Array.isArray( raw ) ? raw : raw ? [ raw ] : [] )
			.map( Number )
			.filter( function ( id ) {
				return id > 0;
			} );
		var labelsState = useState( {} );
		var labels = labelsState[ 0 ];
		var setLabels = labelsState[ 1 ];
		var optionsState = useState( [] );
		var options = optionsState[ 0 ];
		var setOptions = optionsState[ 1 ];
		var timer = useRef( null );

		function remember( rows ) {
			setLabels( function ( previous ) {
				var next = Object.assign( {}, previous );
				rows.forEach( function ( row ) {
					next[ row.id ] = row.label + ( row.detail ? ' — ' + row.detail : '' );
				} );
				return next;
			} );
		}

		useEffect(
			function () {
				var missing = ids.filter( function ( id ) {
					return ! labels[ id ];
				} );
				if ( ! missing.length ) {
					return;
				}
				lookup( field, parentKey, { include: missing } )
					.then( function ( rows ) {
						remember( rows );
					} )
					.catch( function () {} );
			},
			[ ids.join( ',' ) ]
		);

		function search( term ) {
			lookup( field, parentKey, { search: term } )
				.then( function ( rows ) {
					setOptions( rows );
					remember( rows );
				} )
				.catch( function () {
					setOptions( [] );
				} );
		}

		useEffect( function () {
			search( '' );
		}, [] );

		function onFilter( term ) {
			window.clearTimeout( timer.current );
			timer.current = window.setTimeout( function () {
				search( term );
			}, 300 );
		}

		var comboOptions = options.map( function ( row ) {
			return { value: String( row.id ), label: row.label + ( row.detail ? ' — ' + row.detail : '' ) };
		} );
		ids.forEach( function ( id ) {
			if ( ! comboOptions.some( function ( o ) {
				return o.value === String( id );
			} ) ) {
				comboOptions.unshift( { value: String( id ), label: labels[ id ] || sprintf( __( 'Item #%d', 'cradle-content' ), id ) } );
			}
		} );

		if ( ! multiple ) {
			return el( C.ComboboxControl, {
				label: labelOf( field ),
				help: field.help,
				value: ids.length ? String( ids[ 0 ] ) : null,
				options: comboOptions,
				onFilterValueChange: onFilter,
				onChange: function ( value ) {
					props.onChange( value ? Number( value ) : parentKey ? 0 : null );
				},
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			} );
		}

		var full = field.maxItems > 0 && ids.length >= field.maxItems;
		return el(
			'div',
			{ className: 'cradle-content-ref' },
			el( 'p', { className: 'cradle-content-label' }, labelOf( field ) ),
			ids.length
				? el(
						'ul',
						{ className: 'cradle-content-ref__list' },
						ids.map( function ( id ) {
							var text = labels[ id ] || sprintf( __( 'Item #%d', 'cradle-content' ), id );
							return el(
								'li',
								{ key: id },
								el( 'span', {}, text ),
								el(
									C.Button,
									{
										variant: 'link',
										isDestructive: true,
										label: sprintf( __( 'Remove %s', 'cradle-content' ), text ),
										onClick: function () {
											var next = ids.filter( function ( other ) {
												return other !== id;
											} );
											props.onChange( next.length ? next : null );
										},
									},
									__( 'Remove', 'cradle-content' )
								)
							);
						} )
				  )
				: el( 'p', { className: 'cradle-content-empty' }, __( 'Nothing selected.', 'cradle-content' ) ),
			full
				? el( 'p', { className: 'cradle-content-empty' }, sprintf( __( 'Maximum of %d reached.', 'cradle-content' ), field.maxItems ) )
				: el( C.ComboboxControl, {
						label: __( 'Add', 'cradle-content' ),
						help: field.help,
						value: null,
						options: comboOptions.filter( function ( option ) {
							return ids.indexOf( Number( option.value ) ) === -1;
						} ),
						onFilterValueChange: onFilter,
						onChange: function ( value ) {
							if ( value ) {
								props.onChange( ids.concat( [ Number( value ) ] ) );
							}
						},
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
				  } )
		);
	}

	function MediaControl( props ) {
		var field = props.field;
		var id = Number( props.value ) || 0;
		var media = useSelect(
			function ( select ) {
				return id ? select( 'core' ).getMedia( id ) : null;
			},
			[ id ]
		);
		if ( ! MediaUpload || ! MediaUploadCheck ) {
			return el( 'p', {}, __( 'The media library is not available here.', 'cradle-content' ) );
		}
		var allowed = ( field.mime || [] ).map( function ( mime ) {
			return MIME_TO_TYPE[ mime ] || mime;
		} );
		var preview = null;
		if ( media ) {
			var sizes = media.media_details && media.media_details.sizes;
			var thumb = sizes && sizes.thumbnail ? sizes.thumbnail.source_url : media.source_url;
			preview =
				media.media_type === 'image'
					? el( 'img', { src: thumb, alt: media.alt_text || '', className: 'cradle-content-media__img' } )
					: el( 'p', {}, ( media.title && media.title.raw ) || media.source_url );
		}
		return el(
			'div',
			{ className: 'cradle-content-media' },
			el( 'p', { className: 'cradle-content-label' }, labelOf( field ) ),
			preview,
			el(
				MediaUploadCheck,
				{},
				el( MediaUpload, {
					onSelect: function ( item ) {
						props.onChange( item && item.id ? item.id : null );
					},
					allowedTypes: allowed.length ? allowed : undefined,
					value: id || undefined,
					render: function ( upload ) {
						return el( C.Button, { variant: 'secondary', onClick: upload.open }, id ? __( 'Replace', 'cradle-content' ) : __( 'Select media', 'cradle-content' ) );
					},
				} )
			),
			id
				? el(
						C.Button,
						{
							variant: 'link',
							isDestructive: true,
							onClick: function () {
								props.onChange( props.parentKey ? 0 : null );
							},
						},
						__( 'Remove', 'cradle-content' )
				  )
				: null,
			field.help ? el( 'p', { className: 'components-base-control__help' }, field.help ) : null
		);
	}

	function GroupControl( props ) {
		var field = props.field;
		var rows = Array.isArray( props.value ) ? props.value : [];
		var max = field.maxRows || 10;

		function commit( next ) {
			props.onChange( next.length ? next : null );
		}
		function update( index, key, value ) {
			var next = rows.map( function ( row ) {
				return Object.assign( {}, row );
			} );
			next[ index ][ key ] = value;
			commit( next );
		}
		function add() {
			if ( rows.length >= max ) {
				return;
			}
			var row = {};
			field.subfields.forEach( function ( sub ) {
				row[ sub.key ] = emptyValue( sub );
			} );
			props.onChange( rows.concat( [ row ] ) );
		}
		function move( index, delta ) {
			var target = index + delta;
			if ( target < 0 || target >= rows.length ) {
				return;
			}
			var next = rows.slice();
			var item = next[ index ];
			next[ index ] = next[ target ];
			next[ target ] = item;
			commit( next );
		}

		return el(
			'div',
			{ className: 'cradle-content-group' },
			el( 'p', { className: 'cradle-content-label' }, labelOf( field ) ),
			field.help ? el( 'p', { className: 'components-base-control__help' }, field.help ) : null,
			rows.map( function ( row, index ) {
				return el(
					'fieldset',
					{ key: index, className: 'cradle-content-group__row' },
					el( 'legend', {}, sprintf( __( 'Row %d', 'cradle-content' ), index + 1 ) ),
					field.subfields.map( function ( sub ) {
						return el( FieldControl, {
							key: sub.key,
							field: sub,
							parentKey: field.key,
							value: row[ sub.key ],
							onChange: function ( value ) {
								update( index, sub.key, value );
							},
						} );
					} ),
					el(
						'div',
						{ className: 'cradle-content-row-tools' },
						el( C.Button, { variant: 'tertiary', size: 'small', disabled: index === 0, onClick: function () {
							move( index, -1 );
						} }, __( 'Move up', 'cradle-content' ) ),
						el( C.Button, { variant: 'tertiary', size: 'small', disabled: index === rows.length - 1, onClick: function () {
							move( index, 1 );
						} }, __( 'Move down', 'cradle-content' ) ),
						el( C.Button, { variant: 'tertiary', size: 'small', isDestructive: true, onClick: function () {
							commit( rows.filter( function ( other, i ) {
								return i !== index;
							} ) );
						} }, __( 'Remove row', 'cradle-content' ) )
					)
				);
			} ),
			el( C.Button, { variant: 'secondary', disabled: rows.length >= max, onClick: add }, __( 'Add row', 'cradle-content' ) )
		);
	}

	function FieldControl( props ) {
		var field = props.field;
		var value = props.value;
		var onChange = props.onChange;
		var common = { label: labelOf( field ), help: field.help, __nextHasNoMarginBottom: true };

		switch ( field.type ) {
			case 'textarea':
			case 'rich_text':
				return el(
					C.TextareaControl,
					Object.assign( {}, common, {
						value: value || '',
						maxLength: field.maxLength || undefined,
						help: field.type === 'rich_text' ? ( field.help ? field.help + ' ' : '' ) + __( 'Allowed HTML: a, strong, em, b, i, br, p, ul, ol, li, code.', 'cradle-content' ) : field.help,
						onChange: onChange,
					} )
				);
			case 'number':
				return el(
					C.TextControl,
					Object.assign( {}, common, {
						type: 'number',
						value: value === null || value === undefined ? '' : String( value ),
						min: field.min === null ? undefined : field.min,
						max: field.max === null ? undefined : field.max,
						step: field.step || ( field.integer ? 1 : 'any' ),
						__next40pxDefaultSize: true,
						onChange: function ( next ) {
							if ( next === '' ) {
								onChange( null );
								return;
							}
							var num = field.integer ? parseInt( next, 10 ) : parseFloat( next );
							onChange( isNaN( num ) ? null : num );
						},
					} )
				);
			case 'boolean':
				return el( C.ToggleControl, Object.assign( {}, common, { checked: !! value, onChange: onChange } ) );
			case 'url':
			case 'email':
			case 'date':
				return el( C.TextControl, Object.assign( {}, common, { type: field.type, value: value || '', onChange: onChange, __next40pxDefaultSize: true } ) );
			case 'select':
				return el(
					C.SelectControl,
					Object.assign( {}, common, {
						value: value || '',
						options: [ { value: '', label: __( '— None —', 'cradle-content' ) } ].concat( field.options ),
						onChange: onChange,
						__next40pxDefaultSize: true,
					} )
				);
			case 'multiselect':
				var selected = Array.isArray( value ) ? value : [];
				return el(
					'fieldset',
					{ className: 'cradle-content-multi' },
					el( 'legend', { className: 'cradle-content-label' }, labelOf( field ) ),
					field.options.map( function ( option ) {
						return el( C.CheckboxControl, {
							key: option.value,
							label: option.label,
							checked: selected.indexOf( option.value ) !== -1,
							__nextHasNoMarginBottom: true,
							onChange: function ( checked ) {
								var next = selected.filter( function ( item ) {
									return item !== option.value;
								} );
								if ( checked ) {
									next.push( option.value );
								}
								onChange( next.length ? next : null );
							},
						} );
					} ),
					field.help ? el( 'p', { className: 'components-base-control__help' }, field.help ) : null
				);
			case 'attachment':
				return el( MediaControl, props );
			case 'post_ref':
			case 'user_ref':
			case 'term_ref':
				return el( ReferenceControl, props );
			case 'group':
				return el( GroupControl, props );
		}
		return el(
			C.TextControl,
			Object.assign( {}, common, {
				value: value || '',
				maxLength: field.maxLength || undefined,
				onChange: onChange,
				__next40pxDefaultSize: true,
			} )
		);
	}

	function GroupPanel( props ) {
		var group = props.group;
		var meta = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );
		var editPost = useDispatch( 'core/editor' ).editPost;

		return el(
			PluginDocumentSettingPanel,
			{ name: 'cradle-content-' + group.key, title: group.title, className: 'cradle-content-panel' },
			group.description ? el( 'p', { className: 'cradle-content-description' }, group.description ) : null,
			group.fields.map( function ( field ) {
				return el(
					'div',
					{ key: field.key, className: 'cradle-content-field' },
					el( FieldControl, {
						field: field,
						value: meta[ field.metaKey ],
						onChange: function ( value ) {
							var patch = {};
							patch[ field.metaKey ] = value;
							editPost( { meta: patch } );
						},
					} )
				);
			} )
		);
	}

	wp.plugins.registerPlugin( 'cradle-content-fields', {
		render: function () {
			return el(
				Fragment,
				{},
				config.groups.map( function ( group ) {
					return el( GroupPanel, { key: group.key, group: group } );
				} )
			);
		},
	} );
} )( window.wp, window.cradleContentEditor );
