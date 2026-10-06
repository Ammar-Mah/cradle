/**
 * Cradle Migration admin (vanilla JS, no build step).
 *
 * - Export: readable progress; when the export is finished the page reloads with
 *   `download=1` and the signed download link of the .cradle file is followed once.
 * - Import: one .cradle file and one confirmation. The upload is chunked and resumable
 *   (choosing the same file again resumes; a chunk is retried on network errors and a
 *   duplicate submission is harmless). The page then drives the one-step import job:
 *   checks and staging through the core job endpoint, then the switch-over with a
 *   one-time recovery token (the restore replaces users and sessions, so this browser
 *   session stops being valid), then the login page.
 * - Archives → Restore: the same one-step import for an archive stored on this site.
 * Only the tab that started an import switches the site over by itself (sessionStorage
 * flag); any other tab shows the switch-over button. All text is inserted with textContent.
 */
( function () {
	'use strict';
	var cfg = window.cradleMigration;
	if ( ! cfg ) {
		return;
	}
	var __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : function ( s ) { return s; };
	var AUTO = 'cradleMigrationAuto:';
	var TOKEN = 'cradleMigrationToken:';

	var CHECKING = __( 'Checking the file…', 'cradle-migration' );
	var PREPARING = __( 'Preparing the imported site beside this one…', 'cradle-migration' );
	var SWITCHING = __( 'Switching the site over…', 'cradle-migration' );
	var PHASES = {
		'migration/export': {
			prepare: __( 'Preparing the export…', 'cradle-migration' ),
			scan_files: __( 'Collecting files…', 'cradle-migration' ),
			dump_db: __( 'Exporting the database…', 'cradle-migration' ),
			pack: __( 'Packing files…', 'cradle-migration' ),
			finalize: __( 'Checking the export…', 'cradle-migration' ),
			bundle: __( 'Creating the .cradle file…', 'cradle-migration' )
		},
		'migration/import': {
			unpack: __( 'Unpacking the .cradle file…', 'cradle-migration' ),
			verify_manifest: CHECKING,
			hash_parts: CHECKING,
			inspect_parts: CHECKING,
			scan_db: CHECKING,
			report: CHECKING,
			stage_files: PREPARING,
			load_db: PREPARING,
			validate_staged: PREPARING,
			safety: PREPARING,
			checkpoint: PREPARING,
			cutover_ready: SWITCHING,
			cutover_db: SWITCHING,
			replace_files: SWITCHING,
			finalize: SWITCHING,
			verify: __( 'Checking the imported site…', 'cradle-migration' )
		}
	};

	function label( type, view ) {
		var text = ( PHASES[ type ] && PHASES[ type ][ view.phase ] ) || view.phase || view.state;
		return view.error && ! view.terminal ? text + ' (' + view.error + ')' : text;
	}

	function remember( key, value ) {
		try {
			if ( null === value ) {
				window.sessionStorage.removeItem( key );
			} else {
				window.sessionStorage.setItem( key, value );
			}
		} catch ( e ) {}
	}

	function recall( key ) {
		try {
			return window.sessionStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}

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

	function message( res, fallback ) {
		return ( res && res.data && res.data.message ) || fallback;
	}

	function jobUrl( tab, job, extra ) {
		return cfg.pageUrl + '&tab=' + encodeURIComponent( tab ) + ( job ? '&job=' + encodeURIComponent( job ) : '' ) + ( extra || '' );
	}

	/**
	 * Status output of one progress area.
	 */
	function makeUi( status, onFail ) {
		var bar = status.querySelector( 'progress' );
		var text = status.querySelector( '[aria-live]' );
		return {
			list: status.querySelector( '.cradle-migration-checks' ),
			status: status,
			say: function ( value ) {
				status.hidden = false;
				text.textContent = value;
			},
			progress: function ( view ) {
				if ( view.progressTotal ) {
					bar.max = view.progressTotal;
					bar.value = view.progressDone;
				}
			},
			full: function () {
				bar.max = 1;
				bar.value = 1;
			},
			fail: function ( value ) {
				this.say( value );
				if ( onFail ) {
					onFail();
				}
			}
		};
	}

	/* ------------------------------ export ------------------------------ */

	function initRunners() {
		document.querySelectorAll( '.cradle-migration-runner' ).forEach( function ( box ) {
			var type = box.getAttribute( 'data-type' );
			var text = box.querySelector( '[aria-live]' );
			box.addEventListener( 'cradle:job', function ( event ) {
				var job = event.detail || {};
				if ( ! job.terminal ) {
					text.textContent = label( type, job );
				}
				if ( job.terminal || 'paused' === job.state || 'awaiting_cutover' === job.state ) {
					var isExport = 'migration/export' === type;
					window.setTimeout( function () {
						window.location.href = jobUrl( isExport ? 'export' : 'import', job.uuid, isExport && 'completed' === job.state ? '&download=1' : '' );
					}, 600 );
				}
			} );
		} );
	}

	function initAutoDownload() {
		var link = document.querySelector( 'a[data-cradle-autodownload]' );
		if ( ! link ) {
			return;
		}
		// Download once; a reload of this page must not start it again.
		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', window.location.href.replace( /&download=1/, '' ) );
		}
		window.setTimeout( function () {
			window.location.href = link.href;
		}, 400 );
	}

	/* ------------------------------ upload ------------------------------ */

	function initUpload() {
		var form = document.getElementById( 'cradle-migration-upload' );
		if ( ! form ) {
			return;
		}
		var input = form.querySelector( 'input[type="file"]' );
		var button = form.querySelector( 'button[type="submit"]' );
		var ui = makeUi( form.querySelector( '.cradle-migration-upload-status' ), function () {
			button.disabled = false;
			input.disabled = false;
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var file = input.files && input.files[ 0 ];
			if ( ! file ) {
				ui.say( __( 'Choose the .cradle file to import.', 'cradle-migration' ) );
				return;
			}
			if ( ! /\.cradle$/i.test( file.name ) ) {
				ui.say( __( 'This is not a .cradle file. Export the other site with Cradle Migration and choose the .cradle file it downloads.', 'cradle-migration' ) );
				return;
			}
			if ( ! window.confirm( form.getAttribute( 'data-confirm' ) ) ) {
				return;
			}
			button.disabled = true;
			input.disabled = true;
			ui.say( __( 'Preparing the upload…', 'cradle-migration' ) );
			post( {
				action: 'cradle_migration_upload_begin',
				nonce: cfg.nonce,
				files: JSON.stringify( [ { name: file.name, size: file.size, modified: Math.floor( ( file.lastModified || 0 ) / 1000 ) } ] )
			} ).then( function ( res ) {
				if ( ! res.success ) {
					throw new Error( message( res, '' ) );
				}
				var session = res.data;
				var meta = session.files[ 0 ];
				var got = {};
				meta.received.forEach( function ( i ) {
					got[ i ] = true;
				} );
				var queue = [];
				for ( var i = 0; i < meta.chunks; i++ ) {
					if ( ! got[ i ] ) {
						queue.push( i );
					}
				}
				var done = meta.chunks - queue.length;
				if ( session.resumed && done ) {
					ui.say( __( 'Resuming an interrupted upload…', 'cradle-migration' ) );
				}
				function next( attempt ) {
					ui.progress( { progressTotal: meta.chunks, progressDone: done } );
					if ( ! queue.length ) {
						return finish( session.session );
					}
					var index = queue[ 0 ];
					var start = index * session.chunkSize;
					var blob = file.slice( start, Math.min( meta.size, start + session.chunkSize ) );
					return post( { action: 'cradle_migration_upload_chunk', nonce: cfg.nonce, session: session.session, file: 0, index: index }, blob ).then( function ( r ) {
						if ( ! r.success ) {
							throw new Error( message( r, '' ) );
						}
						queue.shift();
						done++;
						ui.say( __( 'Uploading…', 'cradle-migration' ) + ' ' + Math.floor( done * 100 / meta.chunks ) + '%' );
						return next( 0 );
					}, function () {
						if ( attempt >= 5 ) {
							throw new Error( __( 'Connection lost. Choose the same file again to resume the upload.', 'cradle-migration' ) );
						}
						return new Promise( function ( resolve ) {
							window.setTimeout( resolve, 1000 * ( attempt + 1 ) );
						} ).then( function () {
							return next( attempt + 1 );
						} );
					} );
				}
				return next( 0 );
			} ).catch( function ( err ) {
				ui.fail( ( err && err.message ) || __( 'The upload failed.', 'cradle-migration' ) );
			} );

			function finish( session ) {
				ui.say( __( 'Upload complete. Starting the import…', 'cradle-migration' ) );
				var data = { action: 'cradle_migration_upload_finish', nonce: cfg.nonce, session: session };
				form.querySelectorAll( 'input[name^="opt_"]' ).forEach( function ( o ) {
					data[ o.name ] = o.checked ? '1' : '0';
				} );
				return post( data ).then( function ( r ) {
					if ( ! r.success ) {
						throw new Error( message( r, '' ) );
					}
					remember( AUTO + r.data.uuid, '1' );
					window.location.href = jobUrl( 'import', r.data.uuid );
				} );
			}
		} );
	}

	/* ----------------------- Archives → Restore… ------------------------ */

	function initRestore() {
		document.querySelectorAll( 'input[data-cradle-restore]' ).forEach( function ( field ) {
			var form = field.form;
			form.addEventListener( 'submit', function ( event ) {
				// The core confirmation handler runs first and cancels the submit when declined.
				if ( event.defaultPrevented ) {
					return;
				}
				event.preventDefault();
				var button = form.querySelector( '[type="submit"]' );
				if ( button ) {
					button.disabled = true;
				}
				post( { action: 'cradle_migration_restore', nonce: cfg.nonce, archive: field.value } ).then( function ( res ) {
					if ( ! res.success ) {
						if ( button ) {
							button.disabled = false;
						}
						window.alert( message( res, __( 'The import could not start.', 'cradle-migration' ) ) );
						return;
					}
					remember( AUTO + res.data.uuid, '1' );
					window.location.href = jobUrl( 'import', res.data.uuid );
				}, function () {
					if ( button ) {
						button.disabled = false;
					}
					window.alert( __( 'Connection problem; nothing was changed. Try again.', 'cradle-migration' ) );
				} );
			} );
		} );
	}

	/* ------------------------- one-step import -------------------------- */

	function finished( job, view, ui ) {
		remember( TOKEN + job, null );
		ui.full();
		if ( 'completed' === view.state && view.loginUrl ) {
			ui.say( __( 'Import complete. Taking you to the login page…', 'cradle-migration' ) );
			window.setTimeout( function () {
				window.location.href = view.loginUrl;
			}, 1500 );
			return;
		}
		ui.say( __( 'The import finished, but some checks failed. Sign in to review them; the import can be rolled back under Jobs & recovery.', 'cradle-migration' ) + ( view.error ? ' ' + view.error : '' ) );
		var v = view.verification;
		if ( ui.list && v ) {
			ui.list.textContent = '';
			v.checks.forEach( function ( c ) {
				var li = document.createElement( 'li' );
				li.textContent = c.status.toUpperCase() + ' — ' + c.label + ': ' + c.detail;
				ui.list.appendChild( li );
			} );
			if ( v.admins && v.admins.length ) {
				var p = document.createElement( 'li' );
				p.textContent = __( 'Administrator accounts:', 'cradle-migration' ) + ' ' + v.admins.join( ', ' );
				ui.list.appendChild( p );
			}
		}
		if ( view.loginUrl ) {
			var a = document.createElement( 'a' );
			a.href = view.loginUrl;
			a.className = 'button button-primary';
			a.textContent = __( 'Sign in', 'cradle-migration' );
			ui.status.appendChild( a );
		}
	}

	/**
	 * After the switch-over began, only the job-scoped token authenticates the remaining steps.
	 */
	function follow( job, token, ui, failures ) {
		post( { action: 'cradle_migration_continue', job: job, token: token } ).then( function ( res ) {
			if ( ! res.success ) {
				ui.say( message( res, __( 'The import could not continue. Finish it with the command-line recovery described under Jobs & recovery.', 'cradle-migration' ) ) );
				return;
			}
			var view = res.data;
			ui.progress( view );
			ui.say( label( 'migration/import', view ) );
			if ( view.terminal ) {
				finished( job, view, ui );
			} else {
				window.setTimeout( function () {
					follow( job, token, ui, 0 );
				}, 300 );
			}
		}, function () {
			if ( failures > 20 ) {
				ui.say( __( 'Connection lost during the switch. Finish with the CLI recovery command shown below.', 'cradle-migration' ) );
				return;
			}
			window.setTimeout( function () {
				follow( job, token, ui, failures + 1 );
			}, 1500 );
		} );
	}

	function switchOver( job, ui ) {
		ui.say( SWITCHING );
		post( { action: 'cradle_migration_cutover', nonce: cfg.nonce, job: job } ).then( function ( res ) {
			if ( ! res.success ) {
				ui.fail( message( res, __( 'Cutover could not start.', 'cradle-migration' ) ) );
				return;
			}
			remember( TOKEN + job, res.data.token );
			remember( AUTO + job, null );
			follow( job, res.data.token, ui, 0 );
		}, function () {
			ui.fail( __( 'Connection problem; nothing was changed. Try again.', 'cradle-migration' ) );
		} );
	}

	function initAuto() {
		var box = document.querySelector( '.cradle-migration-auto' );
		if ( ! box ) {
			return;
		}
		var job = box.getAttribute( 'data-job' );
		var ui = makeUi( box );
		var failures = 0;
		function step() {
			post( { action: 'cradle_job_step', nonce: cfg.jobNonce, job: job } ).then( function ( res ) {
				if ( ! res.success ) {
					ui.say( message( res, __( 'The import request failed. Reload the page to continue.', 'cradle-migration' ) ) );
					return;
				}
				failures = 0;
				var view = res.data;
				ui.progress( view );
				ui.say( label( 'migration/import', view ) );
				if ( 'awaiting_cutover' === view.state && recall( AUTO + job ) ) {
					switchOver( job, ui );
					return;
				}
				if ( view.terminal || 'paused' === view.state || 'awaiting_cutover' === view.state ) {
					if ( view.terminal ) {
						remember( AUTO + job, null );
					}
					window.location.href = jobUrl( 'import', job );
					return;
				}
				window.setTimeout( step, 250 );
			}, function () {
				if ( ++failures > 10 ) {
					ui.say( __( 'Connection lost. The import keeps its progress; reload the page to continue.', 'cradle-migration' ) );
					return;
				}
				window.setTimeout( step, 1500 );
			} );
		}
		step();
	}

	/* ------------------- switch-over screen (button) -------------------- */

	function initCutover() {
		var root = document.querySelector( '.cradle-migration-cutover' );
		if ( ! root ) {
			return;
		}
		var job = root.getAttribute( 'data-job' );
		var button = root.querySelector( '.cradle-migration-cutover-button' );
		var ui = makeUi( root.querySelector( '.cradle-migration-cutover-status' ), function () {
			button.disabled = false;
		} );

		button.addEventListener( 'click', function () {
			if ( ! window.confirm( __( 'Switch the site over to the imported copy now?', 'cradle-migration' ) ) ) {
				return;
			}
			button.disabled = true;
			switchOver( job, ui );
		} );

		var saved = recall( TOKEN + job );
		if ( saved ) {
			button.disabled = true;
			follow( job, saved, ui, 0 );
		} else if ( root.hasAttribute( 'data-auto' ) && recall( AUTO + job ) ) {
			button.disabled = true;
			switchOver( job, ui );
		}
	}

	function init() {
		initRunners();
		initAutoDownload();
		initUpload();
		initRestore();
		initAuto();
		initCutover();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
