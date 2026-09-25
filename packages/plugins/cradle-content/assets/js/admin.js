/**
 * Cradle Content — definition forms (vanilla JS, progressive enhancement).
 * Every form works without this script; it only adds row management and hides irrelevant settings.
 */
( function () {
	'use strict';

	var counter = Date.now();
	var ATTRS = [ 'name', 'id', 'for', 'aria-describedby', 'aria-labelledby' ];

	function replaceToken( root, token, value ) {
		var nodes = root.querySelectorAll( '*' );
		Array.prototype.forEach.call( nodes, function ( node ) {
			ATTRS.forEach( function ( attr ) {
				var current = node.getAttribute( attr );
				if ( current && current.indexOf( token ) !== -1 ) {
					node.setAttribute( attr, current.split( token ).join( value ) );
				}
			} );
		} );
	}

	function focusFirst( row ) {
		var input = row && row.querySelector( 'input:not([type="hidden"]), select, textarea' );
		if ( input ) {
			input.focus();
		}
	}

	function toggleTypes( row ) {
		var select = row.querySelector( '[data-cradle-type]' );
		if ( ! select ) {
			return;
		}
		var type = select.value;
		row.querySelectorAll( '[data-types]' ).forEach( function ( el ) {
			el.hidden = el.getAttribute( 'data-types' ).split( ' ' ).indexOf( type ) === -1;
		} );
	}

	function initRepeater( container ) {
		var list = container.querySelector( '[data-cradle-rows]' );
		var template = container.querySelector( 'template[data-cradle-template]' );
		var add = container.querySelector( '[data-cradle-add]' );
		if ( ! list || ! template || ! add ) {
			return;
		}
		add.addEventListener( 'click', function () {
			var clone = template.content.cloneNode( true );
			replaceToken( clone, '__i__', String( counter++ ) );
			list.appendChild( clone );
			var row = list.lastElementChild;
			toggleTypes( row );
			focusFirst( row );
		} );
		list.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( 'button' );
			if ( ! button ) {
				return;
			}
			var row = button.closest( '[data-cradle-row]' );
			if ( ! row || row.parentNode !== list ) {
				return;
			}
			if ( button.hasAttribute( 'data-cradle-remove' ) ) {
				var next = row.nextElementSibling || row.previousElementSibling;
				row.parentNode.removeChild( row );
				if ( next ) {
					focusFirst( next );
				} else {
					add.focus();
				}
			} else if ( button.hasAttribute( 'data-cradle-up' ) && row.previousElementSibling ) {
				list.insertBefore( row, row.previousElementSibling );
				button.focus();
			} else if ( button.hasAttribute( 'data-cradle-down' ) && row.nextElementSibling ) {
				list.insertBefore( row.nextElementSibling, row );
				button.focus();
			}
		} );
		list.addEventListener( 'change', function ( event ) {
			if ( event.target.hasAttribute( 'data-cradle-type' ) ) {
				toggleTypes( event.target.closest( '[data-cradle-row]' ) );
			}
		} );
		list.querySelectorAll( '[data-cradle-row]' ).forEach( toggleTypes );
	}

	function initObjectToggle( form ) {
		var select = form.querySelector( '[data-cradle-object]' );
		if ( ! select ) {
			return;
		}
		function sync() {
			form.querySelectorAll( '[data-cradle-object-for]' ).forEach( function ( row ) {
				row.hidden = row.getAttribute( 'data-cradle-object-for' ) !== select.value;
			} );
		}
		select.addEventListener( 'change', sync );
		sync();
	}

	function initCapabilityToggle( form ) {
		var select = form.querySelector( '[data-cradle-capmodel]' );
		var custom = form.querySelector( '[data-cradle-custom-caps]' );
		if ( ! select || ! custom ) {
			return;
		}
		function sync() {
			custom.hidden = select.value !== 'custom';
		}
		select.addEventListener( 'change', sync );
		sync();
	}

	function initIcon( form ) {
		var select = form.querySelector( '[data-cradle-icon]' );
		if ( ! select ) {
			return;
		}
		var preview = select.parentNode.querySelector( '.cradle-content-icon-preview' );
		select.addEventListener( 'change', function () {
			if ( preview ) {
				preview.className = 'dashicons dashicons-' + select.value + ' cradle-content-icon-preview';
			}
		} );
	}

	function init() {
		document.querySelectorAll( '.cradle-content-form' ).forEach( function ( form ) {
			form.querySelectorAll( '[data-cradle-repeater]' ).forEach( initRepeater );
			initObjectToggle( form );
			initCapabilityToggle( form );
			initIcon( form );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
