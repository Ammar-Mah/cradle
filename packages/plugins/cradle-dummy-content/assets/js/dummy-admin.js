/**
 * Cradle Dummy Content admin enhancements (vanilla JS, progressive).
 *
 * - The post type selector reloads the form for that type (GET, read-only).
 * - Job boxes are driven by core's cradle-admin job runner; when a job reaches a
 *   terminal state this reloads the page to show the stored report.
 * - Cancel uses core's authenticated cradle_job_cancel AJAX endpoint.
 * Every control also works without JavaScript through normal form posts.
 */
( function () {
	'use strict';

	var wp = window.wp || {};
	var __ = wp.i18n ? wp.i18n.__ : function ( s ) {
		return s;
	};

	function init() {
		document.documentElement.classList.add( 'cradle-dummy-js' );

		document.querySelectorAll( 'select[data-cradle-autosubmit]' ).forEach( function ( select ) {
			select.addEventListener( 'change', function () {
				if ( select.form ) {
					select.form.submit();
				}
			} );
		} );

		document.querySelectorAll( '.cradle-dummy-job' ).forEach( function ( card ) {
			var runner = card.querySelector( '[data-cradle-job]' );
			if ( ! runner ) {
				return;
			}
			runner.addEventListener( 'cradle:job', function ( event ) {
				var job = event.detail || {};
				if ( job.terminal || 'paused' === job.state ) {
					var status = runner.querySelector( '[aria-live]' );
					if ( status ) {
						status.textContent = __( 'Finished. Loading the report…', 'cradle-dummy-content' );
					}
					window.setTimeout( function () {
						window.location.reload();
					}, 600 );
				}
			} );

			var cancelForm = null;
			card.querySelectorAll( 'form' ).forEach( function ( form ) {
				var op = form.querySelector( 'input[name="op"]' );
				if ( op && 'cancel' === op.value ) {
					cancelForm = form;
				}
			} );
			if ( ! cancelForm || ! window.cradleAdmin ) {
				return;
			}
			cancelForm.addEventListener( 'submit', function ( event ) {
				if ( event.defaultPrevented ) {
					return; // The confirmation dialog was declined.
				}
				event.preventDefault();
				var body = new window.FormData();
				body.append( 'action', 'cradle_job_cancel' );
				body.append( 'nonce', window.cradleAdmin.jobNonce );
				body.append( 'job', runner.getAttribute( 'data-cradle-job' ) );
				window.fetch( window.cradleAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function () {
						window.location.reload();
					} )
					.catch( function () {
						cancelForm.submit(); // Fall back to the nonce-protected form post.
					} );
			} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
