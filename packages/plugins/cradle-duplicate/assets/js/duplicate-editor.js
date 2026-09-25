/**
 * Cradle Duplicate: block editor entry points (post summary panel + Options menu).
 *
 * Plain JavaScript with wp.element.createElement (no JSX, no build step).
 * The mutation is a REST POST through wp.apiFetch, which sends the REST nonce;
 * the server re-checks object-level permissions for every request.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data || ! wp.apiFetch ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var config = window.cradleDuplicateEditor || {};
	var slots = wp.editor && wp.editor.PluginPostStatusInfo ? wp.editor : ( wp.editPost || {} );
	var PluginPostStatusInfo = slots.PluginPostStatusInfo;
	var PluginMoreMenuItem = slots.PluginMoreMenuItem;
	var Button = wp.components.Button;
	var running = false;

	function notices() {
		return wp.data.dispatch( 'core/notices' );
	}

	function editorState( select ) {
		var editor = select( 'core/editor' );
		return {
			postId: editor.getCurrentPostId(),
			isNew: editor.isEditedPostNew(),
			isDirty: editor.isEditedPostDirty(),
			isSaving: editor.isSavingPost() || editor.isAutosavingPost()
		};
	}

	function blockedReason( state ) {
		if ( state.isNew ) {
			return __( 'Save this item first; then it can be duplicated.', 'cradle-duplicate' );
		}
		if ( state.isDirty || state.isSaving ) {
			return __( 'Save your changes first: duplication copies the saved version.', 'cradle-duplicate' );
		}
		return '';
	}

	function duplicate( postId, onDone ) {
		if ( running ) {
			return;
		}
		running = true;
		notices().createInfoNotice( __( 'Duplicating…', 'cradle-duplicate' ), { id: 'cradle-duplicate-progress', type: 'snackbar' } );
		wp.apiFetch( { path: '/cradle-duplicate/v1/posts/' + parseInt( postId, 10 ), method: 'POST' } )
			.then( function ( response ) {
				if ( ! response || ! response.editLink ) {
					throw new Error( __( 'The item could not be duplicated.', 'cradle-duplicate' ) );
				}
				notices().createSuccessNotice( __( 'Draft created. Opening it…', 'cradle-duplicate' ), { id: 'cradle-duplicate-progress', type: 'snackbar' } );
				window.location.assign( response.editLink );
			} )
			.catch( function ( error ) {
				running = false;
				notices().removeNotice( 'cradle-duplicate-progress' );
				notices().createErrorNotice( ( error && error.message ) || __( 'The item could not be duplicated.', 'cradle-duplicate' ), { id: 'cradle-duplicate-error' } );
				if ( onDone ) {
					onDone();
				}
			} );
	}

	function StatusInfo() {
		var state = wp.data.useSelect( editorState, [] );
		var busyState = wp.element.useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];
		var reason = blockedReason( state );
		var help = reason || ( config.syncedPatterns ? config.syncedText : __( 'Creates a new draft with the same content, terms, featured image and allowed fields. The original is not changed.', 'cradle-duplicate' ) );

		return el(
			PluginPostStatusInfo,
			{ className: 'cradle-duplicate-status-info' },
			el(
				'div',
				{ style: { width: '100%' } },
				el(
					Button,
					{
						variant: 'secondary',
						__next40pxDefaultSize: true,
						isBusy: busy,
						disabled: !! reason || busy,
						accessibleWhenDisabled: true,
						'aria-describedby': 'cradle-duplicate-help',
						onClick: function () {
							setBusy( true );
							duplicate( state.postId, function () {
								setBusy( false );
							} );
						}
					},
					busy ? __( 'Duplicating…', 'cradle-duplicate' ) : __( 'Duplicate as new draft', 'cradle-duplicate' )
				),
				el( 'p', { id: 'cradle-duplicate-help', className: 'components-base-control__help', style: { marginBlockStart: '8px' } }, help )
			)
		);
	}

	function MoreMenu() {
		var state = wp.data.useSelect( editorState, [] );
		if ( ! PluginMoreMenuItem ) {
			return null;
		}
		return el(
			PluginMoreMenuItem,
			{
				disabled: !! blockedReason( state ),
				onClick: function () {
					duplicate( state.postId );
				}
			},
			__( 'Duplicate as new draft', 'cradle-duplicate' )
		);
	}

	function Plugin() {
		return el( wp.element.Fragment, null, PluginPostStatusInfo ? el( StatusInfo ) : null, el( MoreMenu ) );
	}

	wp.plugins.registerPlugin( 'cradle-duplicate', { render: Plugin } );

	// One-time notice right after duplication (display only; the flag is removed from the URL).
	wp.domReady( function () {
		if ( ! config.justDuplicated ) {
			return;
		}
		var message = sprintf(
			/* translators: %s: source title */
			__( 'Draft created. This is a copy of “%s”; the original was not changed.', 'cradle-duplicate' ),
			config.sourceTitle || __( '(no title)', 'cradle-duplicate' )
		);
		if ( config.syncedPatterns ) {
			message += ' ' + config.syncedText;
		}
		var actions = config.sourceLink ? [ { label: __( 'Open the original', 'cradle-duplicate' ), url: config.sourceLink } ] : [];
		notices().createInfoNotice( message, { id: 'cradle-duplicate-created', isDismissible: true, actions: actions } );
		try {
			var url = new window.URL( window.location.href );
			url.searchParams.delete( 'cradle_duplicated' );
			window.history.replaceState( window.history.state, '', url.toString() );
		} catch ( e ) {}
	} );
} )( window.wp );
