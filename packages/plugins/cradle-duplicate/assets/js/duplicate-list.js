/**
 * Cradle Duplicate: one-click "Duplicate" for list rows and the classic editor.
 *
 * Progressive enhancement: each link points to a confirmation screen whose form
 * POSTs with a nonce. With JavaScript, the click POSTs to the REST endpoint
 * (wp.apiFetch adds the REST nonce) and then opens the new draft.
 * Vanilla JS; no jQuery.
 */
( function () {
	'use strict';

	var wp = window.wp || {};
	var __ = wp.i18n ? wp.i18n.__ : function ( s ) {
		return s;
	};

	function speak( message ) {
		if ( wp.a11y && wp.a11y.speak ) {
			wp.a11y.speak( message, 'assertive' );
		}
	}

	function showError( link, message ) {
		var existing = link.parentNode ? link.parentNode.querySelector( '.cradle-duplicate-error' ) : null;
		var note = existing || document.createElement( 'span' );
		note.className = 'cradle-duplicate-error';
		note.setAttribute( 'role', 'alert' );
		note.textContent = ' ' + message;
		if ( ! existing && link.parentNode ) {
			link.parentNode.insertBefore( note, link.nextSibling );
		}
		speak( message );
	}

	document.addEventListener( 'click', function ( event ) {
		var link = event.target && event.target.closest ? event.target.closest( 'a[data-cradle-duplicate]' ) : null;
		if ( ! link || ! wp.apiFetch ) {
			return; // No enhancement available: follow the link to the confirmation screen.
		}
		if ( event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return; // Let modified clicks open the confirmation screen normally.
		}
		event.preventDefault();
		if ( 'true' === link.getAttribute( 'aria-disabled' ) ) {
			return;
		}
		var id = parseInt( link.getAttribute( 'data-cradle-duplicate' ), 10 );
		if ( ! id ) {
			return;
		}
		var label = link.getAttribute( 'data-cradle-label' ) || link.textContent;
		link.setAttribute( 'data-cradle-label', label );
		link.setAttribute( 'aria-disabled', 'true' );
		link.setAttribute( 'aria-busy', 'true' );
		link.textContent = __( 'Duplicating…', 'cradle-duplicate' );
		speak( __( 'Duplicating…', 'cradle-duplicate' ) );

		wp.apiFetch( { path: '/cradle-duplicate/v1/posts/' + id, method: 'POST' } )
			.then( function ( response ) {
				if ( response && response.editLink ) {
					speak( __( 'Draft created. Opening it…', 'cradle-duplicate' ) );
					window.location.assign( response.editLink );
					return;
				}
				throw new Error( __( 'The item could not be duplicated.', 'cradle-duplicate' ) );
			} )
			.catch( function ( error ) {
				link.textContent = label;
				link.removeAttribute( 'aria-disabled' );
				link.removeAttribute( 'aria-busy' );
				showError( link, ( error && error.message ) || __( 'The item could not be duplicated.', 'cradle-duplicate' ) );
			} );
	} );
} )();
