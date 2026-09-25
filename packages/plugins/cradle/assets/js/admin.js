/**
 * Cradle admin enhancements (vanilla JS). Progressive: every form works without it.
 */
( function () {
	'use strict';
	var __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : function ( s ) { return s; };

	function initConfirm( root ) {
		root.querySelectorAll( 'form[data-cradle-confirm]' ).forEach( function ( form ) {
			if ( form.dataset.cradleConfirmBound ) {
				return;
			}
			form.dataset.cradleConfirmBound = '1';
			form.addEventListener( 'submit', function ( event ) {
				if ( ! window.confirm( form.getAttribute( 'data-cradle-confirm' ) ) ) {
					event.preventDefault();
				}
			} );
		} );
	}

	function initColor( root ) {
		root.querySelectorAll( '.cradle-field--color input[type="color"]' ).forEach( function ( input ) {
			var out = input.parentNode.querySelector( '.cradle-color-value' );
			if ( out && ! input.dataset.cradleBound ) {
				input.dataset.cradleBound = '1';
				input.addEventListener( 'input', function () {
					out.textContent = input.value;
				} );
			}
		} );
		root.querySelectorAll( 'input[type="range"]' ).forEach( function ( input ) {
			var out = input.parentNode.querySelector( 'output[for="' + input.id + '"]' );
			if ( out && ! input.dataset.cradleBound ) {
				input.dataset.cradleBound = '1';
				input.addEventListener( 'input', function () {
					out.textContent = input.value;
				} );
			}
		} );
	}

	function initMedia( root ) {
		root.querySelectorAll( '.cradle-media-field' ).forEach( function ( field ) {
			if ( field.dataset.cradleBound ) {
				return;
			}
			field.dataset.cradleBound = '1';
			var input = field.querySelector( 'input[type="hidden"]' );
			var preview = field.querySelector( '.cradle-media-preview' );
			var remove = field.querySelector( '.cradle-media-remove' );
			var config = {};
			try {
				config = JSON.parse( field.getAttribute( 'data-cradle-media' ) || '{}' );
			} catch ( e ) {}
			var frame = null;
			field.querySelector( '.cradle-media-select' ).addEventListener( 'click', function () {
				if ( ! window.wp || ! window.wp.media ) {
					return;
				}
				if ( ! frame ) {
					var library = config.type && config.type.length ? { type: config.type } : {};
					frame = window.wp.media( { title: __( 'Select media', 'cradle' ), multiple: false, library: library } );
					frame.on( 'select', function () {
						var attachment = frame.state().get( 'selection' ).first().toJSON();
						input.value = String( attachment.id );
						preview.textContent = '';
						var url = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.icon || attachment.url;
						var img = document.createElement( 'img' );
						img.src = url;
						img.alt = attachment.alt || '';
						preview.appendChild( img );
						remove.hidden = false;
					} );
				}
				frame.open();
			} );
			remove.addEventListener( 'click', function () {
				input.value = '0';
				preview.textContent = '';
				remove.hidden = true;
			} );
		} );
	}

	/**
	 * Drives a Cradle job with authenticated AJAX steps until it reaches a terminal or waiting state.
	 * Usage: <div data-cradle-job="uuid"><progress class="cradle-progress"></progress><p aria-live="polite"></p></div>
	 */
	function initJobs( root ) {
		root.querySelectorAll( '[data-cradle-job]' ).forEach( function ( box ) {
			if ( box.dataset.cradleBound || ! window.cradleAdmin ) {
				return;
			}
			box.dataset.cradleBound = '1';
			var progress = box.querySelector( 'progress' );
			var status = box.querySelector( '[aria-live]' );
			function step() {
				var body = new window.FormData();
				body.append( 'action', 'cradle_job_step' );
				body.append( 'nonce', window.cradleAdmin.jobNonce );
				body.append( 'job', box.getAttribute( 'data-cradle-job' ) );
				window.fetch( window.cradleAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( ! res || ! res.success ) {
							status.textContent = ( res && res.data && res.data.message ) || __( 'The job request failed.', 'cradle' );
							return;
						}
						var job = res.data;
						if ( progress && job.progressTotal ) {
							progress.max = job.progressTotal;
							progress.value = job.progressDone;
						}
						status.textContent = job.state + ( job.phase ? ' — ' + job.phase : '' ) + ( job.error ? ' — ' + job.error : '' );
						box.dispatchEvent( new window.CustomEvent( 'cradle:job', { detail: job } ) );
						if ( ! job.terminal && 'paused' !== job.state && 'awaiting_cutover' !== job.state ) {
							window.setTimeout( step, 250 );
						}
					} )
					.catch( function () {
						status.textContent = __( 'Connection lost. The job keeps its progress; reload to resume.', 'cradle' );
					} );
			}
			step();
		} );
	}

	function init() {
		initConfirm( document );
		initColor( document );
		initMedia( document );
		initJobs( document );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
	window.cradleAdminInit = init;
} )();
