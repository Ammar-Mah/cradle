/**
 * Cradle Migration admin (vanilla JS, no build step).
 *
 * - Chunked, resumable archive upload (re-selecting the same files resumes; a chunk
 *   is retried on network errors and a duplicate submission is harmless).
 * - Reloads the page when a job reaches a state that needs an operator decision
 *   (the server renders the preflight report and confirmation screens).
 * - Cutover: obtains the one-time job recovery token, then drives the remaining
 *   steps through the token-authenticated continuation endpoint, because the
 *   restore replaces users and sessions and this browser session stops being valid.
 * All text is inserted with textContent.
 */
( function () {
	'use strict';
	var cfg = window.cradleMigration;
	if ( ! cfg ) {
		return;
	}
	var __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : function ( s ) { return s; };

	function post( data, file ) {
		var body = new window.FormData();
		Object.keys( data ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		if ( file ) {
			body.append( 'chunk', file, 'chunk.bin' );
		}
		return window.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
			return r.json().catch( function () {
				return { success: false, data: { message: __( 'Unexpected server response (HTTP ', 'cradle-migration' ) + r.status + ').' } };
			} );
		} );
	}

	function jobUrl( tab, job ) {
		return cfg.pageUrl + '&tab=' + encodeURIComponent( tab ) + ( job ? '&job=' + encodeURIComponent( job ) : '' );
	}

	/* ------------------------------ upload ------------------------------ */

	function initUpload() {
		var form = document.getElementById( 'cradle-migration-upload' );
		if ( ! form ) {
			return;
		}
		var input = form.querySelector( 'input[type="file"]' );
		var box = form.querySelector( '.cradle-migration-upload-status' );
		var bar = box.querySelector( 'progress' );
		var msg = box.querySelector( '[aria-live]' );
		var button = form.querySelector( 'button[type="submit"]' );

		function say( text ) {
			msg.textContent = text;
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var files = Array.prototype.slice.call( input.files || [] );
			if ( ! files.length ) {
				say( __( 'Select the manifest and all part files.', 'cradle-migration' ) );
				box.hidden = false;
				return;
			}
			button.disabled = true;
			box.hidden = false;
			say( __( 'Preparing upload…', 'cradle-migration' ) );
			var byName = {};
			files.forEach( function ( f ) {
				byName[ f.name ] = f;
			} );
			post( {
				action: 'cradle_migration_upload_begin',
				nonce: cfg.nonce,
				files: JSON.stringify( files.map( function ( f ) { return { name: f.name, size: f.size }; } ) )
			} ).then( function ( res ) {
				if ( ! res.success ) {
					throw new Error( res.data && res.data.message );
				}
				var session = res.data;
				var total = 0;
				var done = 0;
				var queue = [];
				session.files.forEach( function ( f, fi ) {
					var got = {};
					f.received.forEach( function ( i ) { got[ i ] = true; } );
					for ( var i = 0; i < f.chunks; i++ ) {
						total++;
						if ( got[ i ] ) {
							done++;
						} else {
							queue.push( [ fi, i ] );
						}
					}
				} );
				if ( session.resumed && done ) {
					say( __( 'Resuming an interrupted upload…', 'cradle-migration' ) );
				}
				function next( attempt ) {
					bar.max = total;
					bar.value = done;
					if ( ! queue.length ) {
						return finish( session.session );
					}
					var item = queue[ 0 ];
					var meta = session.files[ item[ 0 ] ];
					var start = item[ 1 ] * session.chunkSize;
					var blob = byName[ meta.name ].slice( start, Math.min( meta.size, start + session.chunkSize ) );
					return post( { action: 'cradle_migration_upload_chunk', nonce: cfg.nonce, session: session.session, file: item[ 0 ], index: item[ 1 ] }, blob ).then( function ( r ) {
						if ( ! r.success ) {
							throw new Error( r.data && r.data.message );
						}
						queue.shift();
						done++;
						say( done + ' / ' + total + ' ' + __( 'chunks uploaded', 'cradle-migration' ) );
						return next( 0 );
					}, function () {
						if ( attempt >= 5 ) {
							throw new Error( __( 'Connection lost. Select the same files again to resume the upload.', 'cradle-migration' ) );
						}
						return new Promise( function ( resolve ) { window.setTimeout( resolve, 1000 * ( attempt + 1 ) ); } ).then( function () { return next( attempt + 1 ); } );
					} );
				}
				return next( 0 );
			} ).catch( function ( err ) {
				button.disabled = false;
				say( ( err && err.message ) || __( 'The upload failed.', 'cradle-migration' ) );
			} );

			function finish( session ) {
				say( __( 'Upload complete. Starting the preflight check…', 'cradle-migration' ) );
				var data = { action: 'cradle_migration_upload_finish', nonce: cfg.nonce, session: session };
				form.querySelectorAll( 'input[name^="opt_"]' ).forEach( function ( o ) {
					data[ o.name ] = o.checked ? '1' : '0';
				} );
				return post( data ).then( function ( r ) {
					if ( ! r.success ) {
						throw new Error( r.data && r.data.message );
					}
					window.location.href = jobUrl( 'import', r.data.uuid );
				} );
			}
		} );
	}

	/* ------------------------ runner state changes ---------------------- */

	function initRunnerReload() {
		document.querySelectorAll( '.cradle-migration-runner' ).forEach( function ( box ) {
			box.addEventListener( 'cradle:job', function ( event ) {
				var job = event.detail || {};
				if ( job.terminal || 'paused' === job.state || 'awaiting_cutover' === job.state ) {
					window.setTimeout( function () {
						window.location.href = jobUrl( 'migration/export' === box.getAttribute( 'data-type' ) ? 'export' : 'import', job.uuid );
					}, 600 );
				}
			} );
		} );
	}

	/* ------------------------------ cutover ----------------------------- */

	function initCutover() {
		var root = document.querySelector( '.cradle-migration-cutover' );
		if ( ! root ) {
			return;
		}
		var job = root.getAttribute( 'data-job' );
		var button = root.querySelector( '.cradle-migration-cutover-button' );
		var status = root.querySelector( '.cradle-migration-cutover-status' );
		var bar = status.querySelector( 'progress' );
		var msg = status.querySelector( '[aria-live]' );
		var list = status.querySelector( '.cradle-migration-checks' );
		var key = 'cradleMigrationToken:' + job;

		function say( text ) {
			msg.textContent = text;
		}

		function done( view ) {
			bar.max = 1;
			bar.value = 1;
			var v = view.verification;
			if ( 'completed' === view.state ) {
				say( __( 'Restore completed and verified. Sign in again as a source administrator.', 'cradle-migration' ) );
			} else {
				say( view.state + ( view.error ? ' — ' + view.error : '' ) );
			}
			list.textContent = '';
			if ( v ) {
				v.checks.forEach( function ( c ) {
					var li = document.createElement( 'li' );
					li.textContent = c.status.toUpperCase() + ' — ' + c.label + ': ' + c.detail;
					list.appendChild( li );
				} );
				if ( v.admins && v.admins.length ) {
					var p = document.createElement( 'li' );
					p.textContent = __( 'Administrator accounts:', 'cradle-migration' ) + ' ' + v.admins.join( ', ' );
					list.appendChild( p );
				}
			}
			if ( view.loginUrl ) {
				var a = document.createElement( 'a' );
				a.href = view.loginUrl;
				a.className = 'button button-primary';
				a.textContent = __( 'Sign in', 'cradle-migration' );
				status.appendChild( a );
			}
			try {
				window.sessionStorage.removeItem( key );
			} catch ( e ) {}
		}

		function step( token, failures ) {
			post( { action: 'cradle_migration_continue', job: job, token: token } ).then( function ( res ) {
				if ( ! res.success ) {
					say( ( res.data && res.data.message ) || __( 'The restore could not continue. Use the CLI recovery command shown below.', 'cradle-migration' ) );
					return;
				}
				var view = res.data;
				if ( view.progressTotal ) {
					bar.max = view.progressTotal;
					bar.value = view.progressDone;
				}
				say( view.state + ( view.phase ? ' — ' + view.phase : '' ) );
				if ( view.terminal ) {
					done( view );
				} else {
					window.setTimeout( function () { step( token, 0 ); }, 300 );
				}
			}, function () {
				if ( failures > 20 ) {
					say( __( 'Connection lost during the switch. Finish with the CLI recovery command shown below.', 'cradle-migration' ) );
					return;
				}
				window.setTimeout( function () { step( token, failures + 1 ); }, 1500 );
			} );
		}

		button.addEventListener( 'click', function () {
			if ( ! window.confirm( __( 'Switch the site over to the imported copy now?', 'cradle-migration' ) ) ) {
				return;
			}
			button.disabled = true;
			status.hidden = false;
			say( __( 'Starting cutover…', 'cradle-migration' ) );
			post( { action: 'cradle_migration_cutover', nonce: cfg.nonce, job: job } ).then( function ( res ) {
				if ( ! res.success ) {
					button.disabled = false;
					say( ( res.data && res.data.message ) || __( 'Cutover could not start.', 'cradle-migration' ) );
					return;
				}
				try {
					window.sessionStorage.setItem( key, res.data.token );
				} catch ( e ) {}
				step( res.data.token, 0 );
			}, function () {
				button.disabled = false;
				say( __( 'Connection problem; nothing was changed. Try again.', 'cradle-migration' ) );
			} );
		} );

		var saved = null;
		try {
			saved = window.sessionStorage.getItem( key );
		} catch ( e ) {}
		if ( saved ) {
			button.disabled = true;
			status.hidden = false;
			step( saved, 0 );
		}
	}

	function init() {
		initUpload();
		initRunnerReload();
		initCutover();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
