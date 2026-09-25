/**
 * Cradle Social view script (vanilla JS, no dependencies, no network requests).
 *
 * Progressive enhancement for share links rendered with [data-cradle-social]:
 * - "Copy link" buttons are revealed and copy the page address with the
 *   asynchronous Clipboard API, falling back to a temporary selection +
 *   execCommand('copy'), and finally to showing the address in the status text.
 * - "More sharing options" buttons are revealed only when navigator.share exists
 *   and call the Web Share API with the rendered title/text/url.
 * - Results are announced through the instance's role="status" (aria-live) region.
 * Messages come from data attributes rendered by PHP (translated there) and are
 * written with textContent only. Each instance initializes once.
 */
( function () {
	'use strict';

	function announce( root, message ) {
		var region = root.querySelector( '.cradle-social__status' );
		if ( ! region ) {
			return;
		}
		region.textContent = '';
		// Clearing first lets screen readers announce a repeated identical message.
		window.setTimeout( function () {
			region.textContent = message;
		}, 60 );
	}

	function legacyCopy( text ) {
		var area = document.createElement( 'textarea' );
		var ok = false;
		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.setAttribute( 'aria-hidden', 'true' );
		area.style.position = 'fixed';
		area.style.insetBlockStart = '0';
		area.style.insetInlineStart = '0';
		area.style.opacity = '0';
		document.body.appendChild( area );
		area.select();
		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		}
		document.body.removeChild( area );
		return ok;
	}

	function copy( root, button ) {
		var url = button.getAttribute( 'data-cradle-social-url' ) || '';
		var copied = root.getAttribute( 'data-cradle-social-copied' ) || '';
		var failed = root.getAttribute( 'data-cradle-social-failed' ) || '';
		function fallback() {
			if ( legacyCopy( url ) ) {
				announce( root, copied );
			} else {
				announce( root, failed + ' ' + url );
			}
		}
		if ( navigator.clipboard && window.isSecureContext && typeof navigator.clipboard.writeText === 'function' ) {
			navigator.clipboard.writeText( url ).then( function () {
				announce( root, copied );
			}, fallback );
		} else {
			fallback();
		}
	}

	function shareData( button ) {
		var data = { url: button.getAttribute( 'data-cradle-social-url' ) || '' };
		var title = button.getAttribute( 'data-cradle-social-title' );
		var text = button.getAttribute( 'data-cradle-social-text' );
		if ( title ) {
			data.title = title;
		}
		if ( text ) {
			data.text = text;
		}
		return data;
	}

	function share( button ) {
		navigator.share( shareData( button ) ).catch( function () {
			// Cancelled or refused by the visitor/browser: nothing to report.
		} );
	}

	function init( root ) {
		if ( root.hasAttribute( 'data-cradle-social-ready' ) ) {
			return;
		}
		root.setAttribute( 'data-cradle-social-ready', '' );
		Array.prototype.forEach.call( root.querySelectorAll( '[data-cradle-social-action]' ), function ( button ) {
			var action = button.getAttribute( 'data-cradle-social-action' );
			var item = button.closest( '.cradle-social__item' );
			if ( action === 'native' ) {
				if ( typeof navigator.share !== 'function' || ( typeof navigator.canShare === 'function' && ! navigator.canShare( shareData( button ) ) ) ) {
					return; // Stays hidden.
				}
				button.addEventListener( 'click', function () {
					share( button );
				} );
			} else if ( action === 'copy' ) {
				button.addEventListener( 'click', function () {
					copy( root, button );
				} );
			} else {
				return;
			}
			if ( item ) {
				item.hidden = false;
			}
		} );
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-cradle-social]' ), init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
