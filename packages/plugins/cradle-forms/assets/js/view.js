/**
 * Cradle Forms — progressive enhancement (vanilla JS, no dependencies).
 *
 * Without this script the form posts to admin-post.php and the page reloads with the result
 * (Post/Redirect/Get). With it, the form is sent with fetch() to the REST route and the result is
 * shown in place: an error summary with links to the fields (focused), aria-invalid and the
 * message next to each field, or the confirmation (focused). If the REST route is unreachable
 * the form falls back to the normal submission. Messages come from the server, in the page
 * language; error texts are inserted with textContent only. The confirmation is HTML that the
 * server sanitized with wp_kses (parsed with DOMParser, which never runs scripts).
 */
( function () {
	'use strict';

	function messages( form ) {
		try {
			return JSON.parse( form.getAttribute( 'data-messages' ) || '{}' );
		} catch ( e ) {
			return {};
		}
	}

	function fieldElements( form, name ) {
		return form.querySelector( '[data-cradle-field="' + name.replace( /[^a-z0-9_]/g, '' ) + '"]' );
	}

	function clearErrors( form, summary ) {
		Array.prototype.forEach.call( form.querySelectorAll( '.cradle-form__field.has-error' ), function ( wrapper ) {
			wrapper.classList.remove( 'has-error' );
			wrapper.removeAttribute( 'aria-invalid' );
			Array.prototype.forEach.call( wrapper.querySelectorAll( '[aria-invalid]' ), function ( input ) {
				input.removeAttribute( 'aria-invalid' );
			} );
			var error = wrapper.querySelector( '.cradle-form__error' );
			if ( error ) {
				error.textContent = '';
				error.hidden = true;
			}
		} );
		if ( summary ) {
			var list = summary.querySelector( '.cradle-form__summary-list' );
			if ( list ) {
				while ( list.firstChild ) {
					list.removeChild( list.firstChild );
				}
			}
			summary.hidden = true;
		}
		form.removeAttribute( 'aria-describedby' );
	}

	function showErrors( form, summary, payload, text ) {
		var data = ( payload && payload.data ) || {};
		var fieldErrors = data.field_errors || {};
		var formErrors = Array.isArray( data.form_errors ) ? data.form_errors.slice() : [];
		var list = summary ? summary.querySelector( '.cradle-form__summary-list' ) : null;
		var names = Object.keys( fieldErrors );

		names.forEach( function ( name ) {
			var wrapper = fieldElements( form, name );
			var message = String( fieldErrors[ name ] );
			var target = null;
			if ( wrapper ) {
				wrapper.classList.add( 'has-error' );
				if ( wrapper.tagName === 'FIELDSET' ) {
					wrapper.setAttribute( 'aria-invalid', 'true' );
				}
				Array.prototype.forEach.call( wrapper.querySelectorAll( 'input:not([type="hidden"]), select, textarea' ), function ( input ) {
					input.setAttribute( 'aria-invalid', 'true' );
					target = target || input;
				} );
				var error = wrapper.querySelector( '.cradle-form__error' );
				if ( error ) {
					error.textContent = message;
					error.hidden = false;
				}
			}
			if ( list ) {
				var item = document.createElement( 'li' );
				var label = wrapper ? wrapper.querySelector( '.cradle-form__label, .cradle-form__consent-label' ) : null;
				var labelText = label ? label.textContent.replace( /\*/g, '' ).replace( /\s+/g, ' ' ).trim() : '';
				var srText = label ? label.querySelector( '.cradle-form__sr' ) : null;
				if ( srText ) {
					labelText = labelText.replace( srText.textContent, '' ).trim();
				}
				if ( target && target.id ) {
					var link = document.createElement( 'a' );
					link.href = '#' + target.id;
					link.textContent = ( labelText ? labelText + ': ' : '' ) + message;
					item.appendChild( link );
				} else {
					item.textContent = ( labelText ? labelText + ': ' : '' ) + message;
				}
				list.appendChild( item );
			}
		} );
		if ( ! names.length && ! formErrors.length && payload && payload.message ) {
			formErrors.push( String( payload.message ) );
		}
		if ( ! names.length && ! formErrors.length && text ) {
			formErrors.push( text );
		}
		formErrors.forEach( function ( message ) {
			if ( list ) {
				var item = document.createElement( 'li' );
				item.textContent = String( message );
				list.appendChild( item );
			}
		} );
		if ( summary ) {
			summary.hidden = false;
			form.setAttribute( 'aria-describedby', summary.id );
			summary.focus();
		}
	}

	function showConfirmation( root, form, payload ) {
		var box = document.createElement( 'div' );
		box.className = 'cradle-form__confirmation';
		box.id = root.id + '-confirmation';
		box.setAttribute( 'tabindex', '-1' );
		box.setAttribute( 'role', 'status' );
		var html = payload && payload.confirmation ? String( payload.confirmation ) : '';
		if ( html ) {
			var doc = new window.DOMParser().parseFromString( html, 'text/html' );
			Array.prototype.forEach.call( doc.body.childNodes, function ( node ) {
				box.appendChild( document.importNode( node, true ) );
			} );
		} else {
			box.textContent = ( payload && payload.message ) || '';
		}
		var summary = root.querySelector( '.cradle-form__summary' );
		if ( summary ) {
			summary.parentNode.removeChild( summary );
		}
		form.parentNode.replaceChild( box, form );
		root.classList.add( 'cradle-form--sent' );
		box.focus();
	}

	function init( form ) {
		if ( form.getAttribute( 'data-cradle-ready' ) ) {
			return;
		}
		form.setAttribute( 'data-cradle-ready', '1' );
		if ( ! window.fetch || ! window.FormData || ! form.getAttribute( 'data-rest' ) ) {
			return;
		}
		form.setAttribute( 'novalidate', 'novalidate' );
		var root = form.closest( '.cradle-form' ) || form.parentNode;
		var summary = document.getElementById( form.getAttribute( 'data-cradle-summary' ) || '' );
		var status = form.querySelector( '.cradle-form__status' );
		var button = form.querySelector( '.cradle-form__submit' );
		var text = messages( form );
		var busy = false;

		form.addEventListener( 'submit', function ( event ) {
			if ( form.getAttribute( 'data-cradle-native' ) ) {
				return; // Fallback submission in progress.
			}
			event.preventDefault();
			if ( busy ) {
				return;
			}
			busy = true;
			clearErrors( form, summary );
			form.setAttribute( 'aria-busy', 'true' );
			if ( button ) {
				button.disabled = true;
			}
			if ( status ) {
				status.textContent = text.sending || '';
			}
			var headers = { Accept: 'application/json' };
			var nonce = form.getAttribute( 'data-rest-nonce' );
			if ( nonce ) {
				headers[ 'X-WP-Nonce' ] = nonce;
			}
			window
				.fetch( form.getAttribute( 'data-rest' ), {
					method: 'POST',
					body: new window.FormData( form ),
					headers: headers,
					credentials: 'same-origin',
				} )
				.then( function ( response ) {
					var type = response.headers.get( 'Content-Type' ) || '';
					if ( type.indexOf( 'json' ) === -1 || response.status === 404 ) {
						throw new Error( 'fallback' );
					}
					return response.json().then( function ( payload ) {
						return { ok: response.ok, payload: payload };
					} );
				} )
				.then( function ( result ) {
					busy = false;
					form.removeAttribute( 'aria-busy' );
					if ( button ) {
						button.disabled = false;
					}
					if ( status ) {
						status.textContent = '';
					}
					if ( result.ok && result.payload && result.payload.status === 'sent' ) {
						if ( result.payload.redirect ) {
							window.location.assign( result.payload.redirect );
							return;
						}
						showConfirmation( root, form, result.payload );
						return;
					}
					if ( result.payload && ( result.payload.code === 'rest_cookie_invalid_nonce' || result.payload.code === 'rest_forbidden' ) ) {
						throw new Error( 'fallback' );
					}
					showErrors( form, summary, result.payload, text.failed );
				} )
				.catch( function ( error ) {
					busy = false;
					form.removeAttribute( 'aria-busy' );
					if ( button ) {
						button.disabled = false;
					}
					if ( status ) {
						status.textContent = '';
					}
					if ( error && error.message === 'fallback' ) {
						// REST is unavailable here (disabled, blocked or missing): submit normally.
						form.setAttribute( 'data-cradle-native', '1' );
						form.submit();
						return;
					}
					showErrors( form, summary, null, text.failed );
				} );
		} );
	}

	function start() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-cradle-form]' ), init );
		// A no-JavaScript result page: move focus to the summary or confirmation.
		var target = window.location.hash ? document.getElementById( window.location.hash.slice( 1 ) ) : null;
		if ( target && ( target.classList.contains( 'cradle-form__summary' ) || target.classList.contains( 'cradle-form__confirmation' ) ) && ! target.hidden ) {
			target.focus();
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
