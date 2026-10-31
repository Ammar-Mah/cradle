/**
 * Cradle Forms — document panels of the cradle_form editor (plain JS, no build step).
 *
 * "Form settings", "Spam protection" and "Notifications" edit the post meta object
 * `_cradle_form_settings` through the post entity (saved, undone and autosave-guarded with the
 * post). The server sanitizes it again on save. After each save the schema status returned by
 * the REST field `cradle_form_status` is shown as an editor notice.
 */
( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! config || ! wp.plugins || ! wp.data || ! wp.components ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var c = wp.components;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	var KEY = '_cradle_form_settings';
	var PREFIX = 'cradle-forms/field-';

	if ( ! Panel ) {
		return;
	}

	function collect( blocks, out ) {
		blocks.forEach( function ( block ) {
			if ( block.name.indexOf( PREFIX ) === 0 && block.attributes.name ) {
				out.push( {
					name: block.attributes.name,
					type: block.name.slice( PREFIX.length ),
					label: block.attributes.label || block.attributes.name,
					choices: Array.isArray( block.attributes.choices ) ? block.attributes.choices : [],
				} );
			}
			if ( block.innerBlocks && block.innerBlocks.length ) {
				collect( block.innerBlocks, out );
			}
		} );
		return out;
	}

	function plain( html ) {
		var doc = new window.DOMParser().parseFromString( String( html || '' ), 'text/html' );
		return ( ( doc.body && doc.body.textContent ) || '' ).trim();
	}

	function useForm() {
		var state = useSelect( function ( select ) {
			var meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
			return {
				settings: Object.assign( {}, config.defaults, meta[ KEY ] || {} ),
				fields: collect( select( 'core/block-editor' ).getBlocks(), [] ),
				postId: select( 'core/editor' ).getCurrentPostId(),
			};
		}, [] );
		var editPost = useDispatch( 'core/editor' ).editPost;
		state.update = function ( patch ) {
			var meta = {};
			meta[ KEY ] = Object.assign( {}, state.settings, patch );
			editPost( { meta: meta } );
		};
		return state;
	}

	function text( label, value, onChange, extra ) {
		return el( c.TextControl, Object.assign( { label: label, value: value || '', onChange: onChange, __nextHasNoMarginBottom: true, __next40pxDefaultSize: true }, extra || {} ) );
	}

	function number( label, value, onChange, extra ) {
		return el(
			c.TextControl,
			Object.assign(
				{
					label: label,
					type: 'number',
					value: String( value === undefined || value === null ? '' : value ),
					onChange: function ( next ) {
						var n = parseInt( next, 10 );
						onChange( isNaN( n ) ? 0 : n );
					},
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
				},
				extra || {}
			)
		);
	}

	function toggle( label, checked, onChange, extra ) {
		return el( c.ToggleControl, Object.assign( { label: label, checked: !! checked, onChange: onChange, __nextHasNoMarginBottom: true }, extra || {} ) );
	}

	function SettingsPanel() {
		var form = useForm();
		var s = form.settings;
		var forced = form.fields.some( function ( field ) {
			return field.type === 'consent' || field.type === 'file';
		} );
		var pages = [ { value: '0', label: __( '— Select a page —', 'cradle-forms' ) } ].concat(
			( config.pages || [] ).map( function ( page ) {
				return { value: String( page.id ), label: page.title || '#' + page.id };
			} )
		);
		return el(
			Panel,
			{ name: 'cradle-forms-settings', title: __( 'Form settings', 'cradle-forms' ), className: 'cradle-forms-panel' },
			text( __( 'Submit button label', 'cradle-forms' ), s.submitLabel, function ( v ) {
				form.update( { submitLabel: v } );
			}, { placeholder: __( 'Submit', 'cradle-forms' ) } ),
			el( c.SelectControl, {
				label: __( 'After submitting', 'cradle-forms' ),
				value: s.confirmationType,
				options: [
					{ value: 'message', label: __( 'Show a message', 'cradle-forms' ) },
					{ value: 'page', label: __( 'Go to a page', 'cradle-forms' ) },
				],
				onChange: function ( v ) {
					form.update( { confirmationType: v } );
				},
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			} ),
			s.confirmationType === 'page'
				? el( c.SelectControl, {
						label: __( 'Confirmation page', 'cradle-forms' ),
						value: String( s.redirectPage || 0 ),
						options: pages,
						onChange: function ( v ) {
							form.update( { redirectPage: parseInt( v, 10 ) || 0 } );
						},
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
				  } )
				: el( c.TextareaControl, {
						label: __( 'Confirmation message', 'cradle-forms' ),
						help: __( 'Allowed HTML: p, br, strong, em, a, ul, ol, li. Empty: a short default thank-you message.', 'cradle-forms' ),
						value: s.confirmationMessage || '',
						onChange: function ( v ) {
							form.update( { confirmationMessage: v } );
						},
						__nextHasNoMarginBottom: true,
				  } ),
			toggle( __( 'Store entries', 'cradle-forms' ), forced || s.storeEntries, function ( v ) {
				form.update( { storeEntries: v } );
			}, {
				disabled: forced,
				help: forced ? __( 'Always on: forms with consent or file fields store their entries.', 'cradle-forms' ) : __( 'Off: entries are only sent as notifications.', 'cradle-forms' ),
			} ),
			number( __( 'Delete entries after (days)', 'cradle-forms' ), s.retentionDays, function ( v ) {
				form.update( { retentionDays: Math.max( 0, Math.min( 3650, v ) ) } );
			}, { min: 0, max: 3650, help: __( '0 keeps entries until you delete them. Older entries and their files are deleted daily.', 'cradle-forms' ) } ),
			form.postId ? el( 'p', {}, el( c.ExternalLink, { href: config.entriesUrl + form.postId }, __( 'View entries', 'cradle-forms' ) ) ) : null
		);
	}

	function SpamPanel() {
		var form = useForm();
		var s = form.settings;
		return el(
			Panel,
			{ name: 'cradle-forms-spam', title: __( 'Spam protection', 'cradle-forms' ), className: 'cradle-forms-panel' },
			el( 'p', { className: 'cradle-forms-panel__note' }, __( 'Every form has a hidden honeypot field that people never see.', 'cradle-forms' ) ),
			number( __( 'Minimum time to fill in (seconds)', 'cradle-forms' ), s.minSeconds, function ( v ) {
				form.update( { minSeconds: Math.max( 0, Math.min( 600, v ) ) } );
			}, { min: 0, max: 600, help: __( 'Faster submissions are refused, because people need a few seconds. 0 turns this off.', 'cradle-forms' ) } ),
			number( __( 'Submissions per hour from one network', 'cradle-forms' ), s.rateLimit, function ( v ) {
				form.update( { rateLimit: Math.max( 0, Math.min( 1000, v ) ) } );
			}, { min: 0, max: 1000, help: __( '0 turns the limit off.', 'cradle-forms' ) } )
		);
	}

	function RouteRow( props ) {
		var route = props.route;
		var fields = props.fields.filter( function ( field ) {
			return [ 'select', 'radio', 'checkboxes', 'text', 'hidden', 'email' ].indexOf( field.type ) !== -1;
		} );
		var selected = fields.filter( function ( field ) {
			return field.name === route.field;
		} )[ 0 ];
		var valueControl =
			selected && selected.choices.length
				? el( c.SelectControl, {
						label: __( 'equals', 'cradle-forms' ),
						value: route.value || '',
						options: [ { value: '', label: __( '— Select —', 'cradle-forms' ) } ].concat(
							selected.choices.map( function ( choice ) {
								var value = choice.value || choice.label;
								return { value: value, label: choice.label || value };
							} )
						),
						onChange: function ( v ) {
							props.onChange( { value: v } );
						},
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
				  } )
				: text( __( 'equals', 'cradle-forms' ), route.value, function ( v ) {
						props.onChange( { value: v } );
				  } );
		return el(
			'fieldset',
			{ className: 'cradle-forms-route' },
			el( 'legend', {}, sprintf( __( 'Rule %d', 'cradle-forms' ), props.index + 1 ) ),
			el( c.SelectControl, {
				label: __( 'If field', 'cradle-forms' ),
				value: route.field || '',
				options: [ { value: '', label: __( '— Select —', 'cradle-forms' ) } ].concat(
					fields.map( function ( field ) {
						return { value: field.name, label: plain( field.label ) + ' (' + field.name + ')' };
					} )
				),
				onChange: function ( v ) {
					props.onChange( { field: v, value: '' } );
				},
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			} ),
			valueControl,
			text( __( 'send to', 'cradle-forms' ), route.to, function ( v ) {
				props.onChange( { to: v } );
			}, { type: 'email', help: __( 'One or more addresses, separated by commas.', 'cradle-forms' ) } ),
			el( c.Button, { variant: 'tertiary', isDestructive: true, size: 'small', onClick: props.onRemove }, __( 'Remove rule', 'cradle-forms' ) )
		);
	}

	function NotificationsPanel() {
		var form = useForm();
		var list = Array.isArray( form.settings.notifications ) ? form.settings.notifications : [];
		var emailFields = form.fields.filter( function ( field ) {
			return field.type === 'email';
		} );
		function commit( next ) {
			form.update( { notifications: next } );
		}
		function change( index, patch ) {
			commit(
				list.map( function ( row, i ) {
					return i === index ? Object.assign( {}, row, patch ) : row;
				} )
			);
		}
		return el(
			Panel,
			{ name: 'cradle-forms-notifications', title: __( 'Notifications', 'cradle-forms' ), className: 'cradle-forms-panel' },
			list.length ? null : el( 'p', {}, __( 'No email is sent for new entries.', 'cradle-forms' ) ),
			list.map( function ( row, index ) {
				var routes = Array.isArray( row.routes ) ? row.routes : [];
				return el(
					'fieldset',
					{ key: index, className: 'cradle-forms-notification' },
					el( 'legend', {}, sprintf( __( 'Notification %d', 'cradle-forms' ), index + 1 ) ),
					toggle( __( 'Send this notification', 'cradle-forms' ), row.enabled, function ( v ) {
						change( index, { enabled: v } );
					} ),
					text( __( 'Send to', 'cradle-forms' ), row.to, function ( v ) {
						change( index, { to: v } );
					}, { help: __( 'Addresses separated by commas. Routing rules below add more. If nobody matches, the fallback recipient from Cradle → Forms → Settings receives it.', 'cradle-forms' ) } ),
					routes.map( function ( route, r ) {
						return el( RouteRow, {
							key: r,
							index: r,
							route: route,
							fields: form.fields,
							onChange: function ( patch ) {
								change( index, {
									routes: routes.map( function ( other, i ) {
										return i === r ? Object.assign( {}, other, patch ) : other;
									} ),
								} );
							},
							onRemove: function () {
								change( index, {
									routes: routes.filter( function ( other, i ) {
										return i !== r;
									} ),
								} );
							},
						} );
					} ),
					el( c.Button, {
						variant: 'secondary',
						size: 'small',
						disabled: routes.length >= 20,
						onClick: function () {
							change( index, { routes: routes.concat( [ { field: '', value: '', to: '' } ] ) } );
						},
					}, __( 'Add routing rule', 'cradle-forms' ) ),
					text( __( 'Subject', 'cradle-forms' ), row.subject, function ( v ) {
						change( index, { subject: v } );
					}, { help: __( 'Placeholders: {form}, {site}, {source} (title of the page the form was sent from) and {field_name} for a field value. Empty: “New entry: form title”.', 'cradle-forms' ) } ),
					el( c.SelectControl, {
						label: __( 'Reply-to address from', 'cradle-forms' ),
						value: row.replyToField || '',
						options: [ { value: '', label: __( 'None', 'cradle-forms' ) } ].concat(
							emailFields.map( function ( field ) {
								return { value: field.name, label: plain( field.label ) };
							} )
						),
						onChange: function ( v ) {
							change( index, { replyToField: v } );
						},
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
					} ),
					toggle( __( 'Include the submitted values', 'cradle-forms' ), row.includeValues, function ( v ) {
						change( index, { includeValues: v } );
					}, { help: __( 'Files are never attached; the email links to the entry instead.', 'cradle-forms' ) } ),
					toggle( __( 'Send a copy to the submitter', 'cradle-forms' ), row.copyToSubmitter, function ( v ) {
						change( index, { copyToSubmitter: v } );
					}, { help: __( 'Uses the email field marked as the submitter’s own address. Anyone can type any address, so keep it off unless you need it.', 'cradle-forms' ) } ),
					el( c.Button, {
						variant: 'tertiary',
						isDestructive: true,
						onClick: function () {
							commit( list.filter( function ( other, i ) {
								return i !== index;
							} ) );
						},
					}, __( 'Remove notification', 'cradle-forms' ) )
				);
			} ),
			el( c.Button, {
				variant: 'secondary',
				disabled: list.length >= 10,
				onClick: function () {
					commit( list.concat( [ Object.assign( {}, config.notice ) ] ) );
				},
			}, __( 'Add notification', 'cradle-forms' ) )
		);
	}

	// Schema status after load and after every save.
	var NOTICE_ID = 'cradle-forms-schema';
	var STORAGE_ID = 'cradle-forms-storage';
	function showStatus() {
		var post = wp.data.select( 'core/editor' ).getCurrentPost() || {};
		var status = post.cradle_form_status;
		var notices = wp.data.dispatch( 'core/notices' );
		notices.removeNotice( NOTICE_ID );
		notices.removeNotice( STORAGE_ID );
		if ( ! status ) {
			return;
		}
		if ( status.errors && status.errors.length ) {
			notices.createWarningNotice(
				( status.hasSchema
					? __( 'The form was saved, but visitors still see the previous version of its fields until these problems are fixed:', 'cradle-forms' )
					: __( 'The form was saved, but visitors cannot use it until these problems are fixed:', 'cradle-forms' ) ) +
					' ' +
					status.errors.join( ' ' ),
				{ id: NOTICE_ID, isDismissible: true }
			);
		}
		if ( status.hasFileField && status.storageProtected === false ) {
			notices.createWarningNotice( __( 'File uploads are refused: the private storage of this site is not protected from direct web access. Define CRADLE_PRIVATE_DIR outside the web root or add server deny rules.', 'cradle-forms' ), {
				id: STORAGE_ID,
				isDismissible: true,
			} );
		}
	}
	var wasSaving = false;
	var started = false;
	wp.data.subscribe( function () {
		var editor = wp.data.select( 'core/editor' );
		if ( ! editor || ! editor.getCurrentPostId() ) {
			return;
		}
		if ( ! started ) {
			started = true;
			window.setTimeout( showStatus, 0 );
		}
		var saving = editor.isSavingPost() && ! editor.isAutosavingPost();
		if ( wasSaving && ! saving ) {
			window.setTimeout( showStatus, 0 );
		}
		wasSaving = saving;
	} );

	wp.plugins.registerPlugin( 'cradle-forms-document', {
		render: function () {
			return el( Fragment, {}, el( SettingsPanel ), el( SpamPanel ), el( NotificationsPanel ) );
		},
	} );
} )( window.wp, window.cradleFormsDocument );
