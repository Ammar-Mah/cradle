/**
 * Cradle Social: shared block editor controls (plain JS, WordPress-bundled packages only).
 *
 * Both blocks are dynamic (save() is null). The preview comes from the same PHP
 * render service through ServerSideRender, wrapped in <Disabled> so preview links
 * cannot be followed from the editor. Inspector controls store per-instance
 * overrides; an empty value means "use the site settings" (Cradle → Social).
 * Editors can select and reorder allowlisted services only; they never enter URLs.
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
	var ServerSideRender = wp.serverSideRender;
	var data = window.cradleSocialEditor || { profiles: [], share: [], shareDefaults: [], settingsUrl: '' };
	var common = { __nextHasNoMarginBottom: true, __next40pxDefaultSize: true };

	function siteDefault() {
		return { label: __( 'Site default', 'cradle-social' ), value: '' };
	}

	function choices( kind ) {
		return kind === 'share' ? data.share : data.profiles;
	}

	function defaults( kind ) {
		if ( kind === 'share' ) {
			return data.shareDefaults.slice();
		}
		return data.profiles.map( function ( p ) {
			return p.id;
		} );
	}

	function labelFor( kind, id ) {
		var match = choices( kind ).filter( function ( p ) {
			return p.id === id;
		} )[ 0 ];
		return match ? match.label : id;
	}

	function settingsLink() {
		return data.settingsUrl
			? el( c.ExternalLink, { href: data.settingsUrl }, __( 'Open Cradle → Social settings', 'cradle-social' ) )
			: null;
	}

	function ServicesPanel( props ) {
		var kind = props.kind;
		var a = props.attributes;
		var set = props.setAttributes;
		var addingState = useState( '' );
		var adding = addingState[ 0 ];
		var setAdding = addingState[ 1 ];
		var known = choices( kind ).map( function ( p ) {
			return p.id;
		} );
		var selected = ( a.services || [] ).filter( function ( id ) {
			return known.indexOf( id ) !== -1;
		} );
		var custom = ( a.services || [] ).length > 0;
		var remaining = known.filter( function ( id ) {
			return selected.indexOf( id ) === -1;
		} );

		function move( index, delta ) {
			var next = selected.slice();
			var target = index + delta;
			if ( target < 0 || target >= next.length ) {
				return;
			}
			var item = next[ index ];
			next[ index ] = next[ target ];
			next[ target ] = item;
			set( { services: next } );
		}

		function remove( index ) {
			var next = selected.slice();
			next.splice( index, 1 );
			set( { services: next } );
		}

		var children = [];
		if ( kind === 'profiles' && known.length === 0 ) {
			children.push(
				el(
					c.Notice,
					{ key: 'none', status: 'warning', isDismissible: false },
					__( 'No profile links are configured yet.', 'cradle-social' ),
					' ',
					settingsLink()
				)
			);
		}
		children.push(
			el( c.ToggleControl, {
				key: 'site',
				label: __( 'Use the site settings', 'cradle-social' ),
				help: custom
					? __( 'This block shows the services below, in this order. Removing all of them returns to the site settings.', 'cradle-social' )
					: __( 'Services and their order come from Cradle → Social.', 'cradle-social' ),
				checked: ! custom,
				onChange: function ( useSite ) {
					set( { services: useSite ? [] : defaults( kind ) } );
				},
				__nextHasNoMarginBottom: true,
			} )
		);
		if ( custom ) {
			children.push(
				el(
					'ul',
					{ key: 'list', className: 'cradle-social-editor__list' },
					selected.map( function ( id, index ) {
						var label = labelFor( kind, id );
						return el(
							'li',
							{ key: id, className: 'cradle-social-editor__item', style: { display: 'flex', alignItems: 'center', gap: '4px' } },
							el( 'span', { style: { flex: '1 1 auto' } }, label ),
							el( c.Button, {
								icon: 'arrow-up-alt2',
								size: 'small',
								label: sprintf( /* translators: %s: service name */ __( 'Move %s up', 'cradle-social' ), label ),
								disabled: index === 0,
								accessibleWhenDisabled: true,
								onClick: function () {
									move( index, -1 );
								},
							} ),
							el( c.Button, {
								icon: 'arrow-down-alt2',
								size: 'small',
								label: sprintf( /* translators: %s: service name */ __( 'Move %s down', 'cradle-social' ), label ),
								disabled: index === selected.length - 1,
								accessibleWhenDisabled: true,
								onClick: function () {
									move( index, 1 );
								},
							} ),
							el( c.Button, {
								icon: 'no-alt',
								size: 'small',
								isDestructive: true,
								label: sprintf( /* translators: %s: service name */ __( 'Remove %s', 'cradle-social' ), label ),
								onClick: function () {
									remove( index );
								},
							} )
						);
					} )
				)
			);
			if ( remaining.length ) {
				children.push(
					el(
						'div',
						{ key: 'add', style: { display: 'flex', alignItems: 'flex-end', gap: '8px' } },
						el(
							'div',
							{ style: { flex: '1 1 auto' } },
							el( c.SelectControl, Object.assign( {
								label: __( 'Add a service', 'cradle-social' ),
								value: adding,
								options: [ { label: __( 'Choose…', 'cradle-social' ), value: '' } ].concat(
									remaining.map( function ( id ) {
										return { label: labelFor( kind, id ), value: id };
									} )
								),
								onChange: setAdding,
							}, common ) )
						),
						el( c.Button, {
							variant: 'secondary',
							__next40pxDefaultSize: true,
							disabled: ! adding,
							accessibleWhenDisabled: true,
							onClick: function () {
								if ( adding ) {
									set( { services: selected.concat( [ adding ] ) } );
									setAdding( '' );
								}
							},
						}, __( 'Add', 'cradle-social' ) )
					)
				);
			}
		}
		if ( kind === 'profiles' && known.length ) {
			children.push( el( 'p', { key: 'where', className: 'components-base-control__help' }, __( 'Profile addresses are managed by administrators.', 'cradle-social' ), ' ', settingsLink() ) );
		}
		return el( c.PanelBody, { title: __( 'Services', 'cradle-social' ) }, children );
	}

	function select( label, value, options, onChange ) {
		return el( c.SelectControl, Object.assign( {
			label: label,
			value: value,
			options: options,
			onChange: onChange,
		}, common ) );
	}

	function AppearancePanel( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		function bind( key ) {
			return function ( v ) {
				var next = {};
				next[ key ] = v;
				set( next );
			};
		}
		return el(
			c.PanelBody,
			{ title: __( 'Appearance', 'cradle-social' ), initialOpen: false },
			select( __( 'Display', 'cradle-social' ), a.display, [
				siteDefault(),
				{ label: __( 'Icon and text', 'cradle-social' ), value: 'icon_text' },
				{ label: __( 'Icon only', 'cradle-social' ), value: 'icon' },
				{ label: __( 'Text only', 'cradle-social' ), value: 'text' },
			], bind( 'display' ) ),
			select( __( 'Visible text', 'cradle-social' ), a.labelStyle, [
				siteDefault(),
				{ label: __( 'Service name', 'cradle-social' ), value: 'service' },
				{ label: __( 'Action (for example “Share on X”)', 'cradle-social' ), value: 'action' },
			], bind( 'labelStyle' ) ),
			select( __( 'Size', 'cradle-social' ), a.size, [
				siteDefault(),
				{ label: __( 'Small', 'cradle-social' ), value: 'small' },
				{ label: __( 'Medium', 'cradle-social' ), value: 'medium' },
				{ label: __( 'Large', 'cradle-social' ), value: 'large' },
			], bind( 'size' ) ),
			select( __( 'Spacing', 'cradle-social' ), a.spacing, [
				siteDefault(),
				{ label: __( 'Compact', 'cradle-social' ), value: 'compact' },
				{ label: __( 'Normal', 'cradle-social' ), value: 'normal' },
				{ label: __( 'Relaxed', 'cradle-social' ), value: 'relaxed' },
			], bind( 'spacing' ) ),
			select( __( 'Shape', 'cradle-social' ), a.shape, [
				siteDefault(),
				{ label: __( 'Plain links', 'cradle-social' ), value: 'none' },
				{ label: __( 'Square buttons', 'cradle-social' ), value: 'square' },
				{ label: __( 'Rounded buttons', 'cradle-social' ), value: 'rounded' },
				{ label: __( 'Round / pill buttons', 'cradle-social' ), value: 'circle' },
			], bind( 'shape' ) ),
			select( __( 'Alignment', 'cradle-social' ), a.justify, [
				{ label: __( 'Start', 'cradle-social' ), value: 'start' },
				{ label: __( 'Center', 'cradle-social' ), value: 'center' },
				{ label: __( 'End', 'cradle-social' ), value: 'end' },
				{ label: __( 'Spread out', 'cradle-social' ), value: 'space-between' },
			], bind( 'justify' ) )
		);
	}

	function LabelPanel( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		var kind = props.kind;
		return el(
			c.PanelBody,
			{ title: __( 'Label and links', 'cradle-social' ), initialOpen: false },
			el( c.TextControl, Object.assign( {
				label: __( 'Group label', 'cradle-social' ),
				help: __( 'Names this group for screen readers (and is shown when enabled). Empty uses the site setting.', 'cradle-social' ),
				placeholder: kind === 'share' ? __( 'Share this page', 'cradle-social' ) : __( 'Follow us', 'cradle-social' ),
				value: a.label || '',
				onChange: function ( v ) {
					set( { label: v } );
				},
			}, common ) ),
			select( __( 'Show the label', 'cradle-social' ), a.showLabel, [
				siteDefault(),
				{ label: __( 'Show', 'cradle-social' ), value: 'show' },
				{ label: __( 'Hide visually (still announced)', 'cradle-social' ), value: 'hide' },
			], function ( v ) {
				set( { showLabel: v } );
			} ),
			select( __( 'Open links in a new tab', 'cradle-social' ), a.newTab, [
				siteDefault(),
				{ label: __( 'Yes (announced to screen readers)', 'cradle-social' ), value: 'on' },
				{ label: __( 'No', 'cradle-social' ), value: 'off' },
			], function ( v ) {
				set( { newTab: v } );
			} ),
			kind === 'share'
				? select( __( 'What to share', 'cradle-social' ), a.target, [
					{ label: __( 'This entry (the post or page, or the Query Loop item)', 'cradle-social' ), value: 'entry' },
					{ label: __( 'The current page address (archives and home too)', 'cradle-social' ), value: 'page' },
				], function ( v ) {
					set( { target: v } );
				} )
				: null
		);
	}

	function edit( blockName, kind ) {
		function Empty() {
			return el( c.Placeholder, {
				icon: kind === 'share' ? 'share' : 'groups',
				label: kind === 'share' ? __( 'Share links', 'cradle-social' ) : __( 'Social profile links', 'cradle-social' ),
				instructions: kind === 'share'
					? __( 'Nothing to share here yet: no share services are selected, or there is no public entry or page to share in this context.', 'cradle-social' )
					: __( 'No profile links to show. Administrators configure profile addresses under Cradle → Social.', 'cradle-social' ),
			} );
		}
		return function Edit( props ) {
			var blockProps = be.useBlockProps();
			var postId = props.context && props.context.postId ? props.context.postId : 0;
			return el(
				Fragment,
				null,
				el(
					be.InspectorControls,
					null,
					el( ServicesPanel, { kind: kind, attributes: props.attributes, setAttributes: props.setAttributes } ),
					el( AppearancePanel, { attributes: props.attributes, setAttributes: props.setAttributes } ),
					el( LabelPanel, { kind: kind, attributes: props.attributes, setAttributes: props.setAttributes } )
				),
				el(
					'div',
					blockProps,
					el(
						c.Disabled,
						null,
						el( ServerSideRender, {
							block: blockName,
							attributes: props.attributes,
							urlQueryArgs: postId ? { post_id: postId } : {},
							EmptyResponsePlaceholder: Empty,
						} )
					)
				)
			);
		};
	}

	window.cradleSocialBlocks = { edit: edit };
} )( window.wp );
