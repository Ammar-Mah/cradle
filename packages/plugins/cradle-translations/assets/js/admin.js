/**
 * Cradle Translations editor enhancements (vanilla JS, progressive: every form works without it).
 * - Live placeholder comparison against the source text (the server check is authoritative).
 * - Confirmation for destructive forms.
 * - Locale selector opens the chosen catalog.
 * Untrusted text is only ever written with textContent.
 */
( function () {
	'use strict';
	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
	var __ = i18n ? i18n.__ : function ( s ) {
		return s;
	};
	var sprintf = i18n ? i18n.sprintf : function ( s, a ) {
		return s.replace( '%s', a );
	};
	var PLACEHOLDER = /%(?:(\d+)\$)?((?:[-+ 0#]|'.)*)(\d+)?(?:\.(\d+))?([bcdeEfFgGosuxX%])/g;

	function tokens( text, strict ) {
		var out = [];
		var match;
		PLACEHOLDER.lastIndex = 0;
		while ( ( match = PLACEHOLDER.exec( text ) ) ) {
			if ( '%' === match[ 5 ] ) {
				continue;
			}
			if ( strict && ( match[ 2 ] || match[ 3 ] || match[ 4 ] ) ) {
				continue;
			}
			out.push( match[ 0 ] );
		}
		return out;
	}

	/**
	 * Maps tokens to { key: "argument:type", token } so %s and %1$s compare equal.
	 */
	function normalize( list ) {
		var position = 0;
		return list.map( function ( token ) {
			var m = /^%(\d+)\$/.exec( token );
			var type = token.slice( -1 ).replace( 'u', 'd' );
			position = m ? parseInt( m[ 1 ], 10 ) : position + 1;
			return { key: position + ':' + type, token: token };
		} );
	}

	function keys( list ) {
		return list.map( function ( item ) {
			return item.key;
		} );
	}

	function tokensOf( list ) {
		return list.map( function ( item ) {
			return item.token;
		} );
	}

	function initEditor( form ) {
		var source = [];
		try {
			source = JSON.parse( form.getAttribute( 'data-ct-placeholders' ) || '[]' );
		} catch ( e ) {
			source = [];
		}
		var expected = normalize( source );
		var fields = form.querySelectorAll( 'textarea.ct-translation' );
		var plural = fields.length > 1;
		fields.forEach( function ( field ) {
			var status = document.getElementById( field.getAttribute( 'aria-describedby' ) );
			if ( ! status ) {
				return;
			}
			function check() {
				if ( '' === field.value ) {
					status.textContent = '';
					status.className = 'ct-check';
					return;
				}
				var found = normalize( tokens( field.value, 0 === source.length ) );
				var foundKeys = keys( found );
				var expectedKeys = keys( expected );
				var missing = plural ? [] : tokensOf( expected.filter( function ( t ) {
					return -1 === foundKeys.indexOf( t.key );
				} ) );
				var extra = tokensOf( found.filter( function ( t ) {
					return -1 === expectedKeys.indexOf( t.key );
				} ) );
				if ( ! missing.length && ! extra.length ) {
					status.textContent = expectedKeys.length ? __( 'Placeholders match the source text.', 'cradle-translations' ) : '';
					status.className = 'ct-check ct-check--ok';
					return;
				}
				var parts = [];
				if ( missing.length ) {
					/* translators: %s: list of placeholders */
					parts.push( sprintf( __( 'Missing placeholders: %s', 'cradle-translations' ), missing.join( ', ' ) ) );
				}
				if ( extra.length ) {
					/* translators: %s: list of placeholders */
					parts.push( sprintf( __( 'Placeholders not in the source: %s', 'cradle-translations' ), extra.join( ', ' ) ) );
				}
				status.textContent = parts.join( ' ' );
				status.className = 'ct-check ct-check--bad';
			}
			field.addEventListener( 'input', check );
			check();
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( 'form.ct-editor' ).forEach( initEditor );
		document.querySelectorAll( 'form[data-ct-confirm]' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				if ( ! window.confirm( form.getAttribute( 'data-ct-confirm' ) ) ) {
					event.preventDefault();
				}
			} );
		} );
		document.querySelectorAll( 'select[data-ct-autosubmit]' ).forEach( function ( select ) {
			select.addEventListener( 'change', function () {
				if ( select.form ) {
					select.form.submit();
				}
			} );
		} );
	} );
}() );
