/**
 * Cradle Languages: "Language" document panel in the block editor.
 *
 * Plain JavaScript with wp.element.createElement (no JSX, no build step). Reads and changes the
 * entry's language and translations through /wp-json/cradle-languages/v1 with wp.apiFetch
 * (REST nonce); the server re-checks edit_post (and create_posts for new translations).
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data || ! wp.apiFetch ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var c = wp.components;
	var slots = wp.editor && wp.editor.PluginDocumentSettingPanel ? wp.editor : ( wp.editPost || {} );
	var Panel = slots.PluginDocumentSettingPanel;

	if ( ! Panel ) {
		return;
	}

	function notices() {
		return wp.data.dispatch( 'core/notices' );
	}

	function statusLabel( status ) {
		switch ( status ) {
			case 'publish':
				return __( 'published', 'cradle-languages' );
			case 'draft':
				return __( 'draft', 'cradle-languages' );
			case 'pending':
				return __( 'pending review', 'cradle-languages' );
			case 'private':
				return __( 'private', 'cradle-languages' );
			case 'future':
				return __( 'scheduled', 'cradle-languages' );
		}
		return status;
	}

	function LanguagePanel() {
		var state = wp.data.useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			return {
				postId: editor.getCurrentPostId(),
				isNew: editor.isEditedPostNew(),
				isDirty: editor.isEditedPostDirty(),
				isSaving: editor.isSavingPost() || editor.isAutosavingPost()
			};
		}, [] );
		var dataState = useState( null );
		var data = dataState[ 0 ];
		var setData = dataState[ 1 ];
		var busyState = useState( '' );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];

		useEffect( function () {
			if ( ! state.postId ) {
				return;
			}
			wp.apiFetch( { path: '/cradle-languages/v1/posts/' + parseInt( state.postId, 10 ) } )
				.then( setData )
				.catch( function ( error ) {
					notices().createErrorNotice( ( error && error.message ) || __( 'The languages of this entry could not be loaded.', 'cradle-languages' ), { id: 'cradle-languages-error' } );
				} );
		}, [ state.postId, state.isSaving ] );

		if ( ! data ) {
			return el( Panel, { name: 'cradle-languages', title: __( 'Language', 'cradle-languages' ) }, el( c.Spinner ) );
		}

		function changeLanguage( slug ) {
			setBusy( 'language' );
			wp.apiFetch( { path: '/cradle-languages/v1/posts/' + parseInt( state.postId, 10 ) + '/language', method: 'POST', data: { lang: slug } } )
				.then( function ( response ) {
					setData( response );
					setBusy( '' );
					notices().createSuccessNotice( __( 'Language changed.', 'cradle-languages' ), { id: 'cradle-languages-saved', type: 'snackbar' } );
				} )
				.catch( function ( error ) {
					setBusy( '' );
					notices().createErrorNotice( ( error && error.message ) || __( 'The language could not be changed.', 'cradle-languages' ), { id: 'cradle-languages-error' } );
				} );
		}

		function addTranslation( slug ) {
			setBusy( slug );
			wp.apiFetch( { path: '/cradle-languages/v1/posts/' + parseInt( state.postId, 10 ) + '/translations', method: 'POST', data: { lang: slug } } )
				.then( function ( response ) {
					notices().createSuccessNotice( __( 'Draft translation created. Opening it…', 'cradle-languages' ), { id: 'cradle-languages-saved', type: 'snackbar' } );
					window.location.assign( response.editLink );
				} )
				.catch( function ( error ) {
					setBusy( '' );
					notices().createErrorNotice( ( error && error.message ) || __( 'The translation could not be created.', 'cradle-languages' ), { id: 'cradle-languages-error' } );
				} );
		}

		var blocked = state.isNew || state.isDirty || state.isSaving;
		var own = null;
		var others = [];
		data.languages.forEach( function ( lang ) {
			if ( lang.slug === data.lang ) {
				own = lang;
			} else {
				others.push( lang );
			}
		} );

		var select = el( c.SelectControl, {
			label: __( 'Language of this entry', 'cradle-languages' ),
			value: data.lang,
			disabled: data.languageLocked || !! busy,
			help: data.languageLocked ? __( 'This entry has translations, so its language is fixed.', 'cradle-languages' ) : '',
			options: data.languages.map( function ( lang ) {
				return { value: lang.slug, label: lang.name };
			} ),
			onChange: changeLanguage,
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: true
		} );

		var rows = others.map( function ( lang ) {
			var t = lang.translation;
			var content;
			if ( t ) {
				var text = sprintf(
					/* translators: 1: entry title, 2: status such as draft */
					__( '%1$s (%2$s)', 'cradle-languages' ),
					t.title || __( '(no title)', 'cradle-languages' ),
					statusLabel( t.status )
				);
				content = t.editLink
					? el( 'a', { href: t.editLink, lang: lang.slug, dir: lang.dir }, text )
					: el( 'span', { lang: lang.slug, dir: lang.dir }, text );
			} else if ( lang.canCreate ) {
				content = el(
					c.Button,
					{
						variant: 'secondary',
						size: 'compact',
						isBusy: busy === lang.slug,
						disabled: blocked || !! busy,
						accessibleWhenDisabled: true,
						'aria-describedby': blocked ? 'cradle-languages-blocked' : undefined,
						onClick: function () {
							addTranslation( lang.slug );
						}
					},
					/* translators: %s: language name */
					sprintf( __( 'Add %s translation', 'cradle-languages' ), lang.name )
				);
			} else {
				content = el( 'span', null, __( 'No translation', 'cradle-languages' ) );
			}
			return el( 'li', { key: lang.slug, style: { marginBlockEnd: '8px' } }, el( 'strong', null, lang.name + ': ' ), content );
		} );

		return el(
			Panel,
			{ name: 'cradle-languages', title: own ? sprintf( /* translators: %s: language name */ __( 'Language: %s', 'cradle-languages' ), own.name ) : __( 'Language', 'cradle-languages' ) },
			select,
			others.length ? el( 'h3', { style: { fontSize: '13px', marginBlock: '16px 8px' } }, __( 'Translations', 'cradle-languages' ) ) : null,
			others.length ? el( 'ul', { style: { margin: 0 } }, rows ) : null,
			blocked && others.length ? el( 'p', { id: 'cradle-languages-blocked', className: 'components-base-control__help' }, __( 'Save the entry first: a translation starts as a copy of the saved version.', 'cradle-languages' ) ) : null
		);
	}

	wp.plugins.registerPlugin( 'cradle-languages', { render: LanguagePanel } );
} )( window.wp );
