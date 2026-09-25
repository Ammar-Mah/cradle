/**
 * Cradle Content — repeated group rows in classic meta boxes and term forms (vanilla JS).
 * Without this script one row is available and the rest of the form still works.
 */
( function () {
	'use strict';

	var __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : function ( s ) {
		return s;
	};
	var counter = Date.now();
	var ATTRS = [ 'name', 'id', 'for', 'aria-describedby', 'aria-labelledby' ];

	function replaceToken( root, token, value ) {
		Array.prototype.forEach.call( root.querySelectorAll( '*' ), function ( node ) {
			ATTRS.forEach( function ( attr ) {
				var current = node.getAttribute( attr );
				if ( current && current.indexOf( token ) !== -1 ) {
					node.setAttribute( attr, current.split( token ).join( value ) );
				}
			} );
		} );
	}

	function rows( list ) {
		return Array.prototype.filter.call( list.children, function ( child ) {
			return child.hasAttribute( 'data-cradle-row' );
		} );
	}

	function announce( status, message ) {
		if ( status ) {
			status.textContent = '';
			window.setTimeout( function () {
				status.textContent = message;
			}, 50 );
		}
	}

	function init( group ) {
		if ( group.dataset.cradleBound ) {
			return;
		}
		group.dataset.cradleBound = '1';
		var list = group.querySelector( '[data-cradle-rows]' );
		var template = group.querySelector( 'template[data-cradle-template]' );
		var add = group.querySelector( '[data-cradle-add]' );
		var status = group.querySelector( '[data-cradle-status]' );
		var max = parseInt( group.getAttribute( 'data-max' ), 10 ) || 50;
		if ( ! list || ! template || ! add ) {
			return;
		}

		function sync() {
			var all = rows( list );
			all.forEach( function ( row, index ) {
				var number = row.querySelector( '[data-cradle-row-number]' );
				if ( number ) {
					number.textContent = String( index + 1 );
				}
			} );
			add.disabled = all.length >= max;
		}

		add.addEventListener( 'click', function () {
			if ( rows( list ).length >= max ) {
				return;
			}
			var clone = template.content.cloneNode( true );
			replaceToken( clone, '__r__', String( counter++ ) );
			list.appendChild( clone );
			var row = list.lastElementChild;
			if ( window.cradleAdminInit ) {
				window.cradleAdminInit();
			}
			sync();
			var input = row.querySelector( 'input:not([type="hidden"]), select, textarea, button' );
			if ( input ) {
				input.focus();
			}
			announce( status, __( 'Row added.', 'cradle-content' ) );
		} );

		list.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( 'button' );
			var row = button && button.closest( '[data-cradle-row]' );
			if ( ! row || row.parentNode !== list ) {
				return;
			}
			if ( button.hasAttribute( 'data-cradle-remove' ) ) {
				list.removeChild( row );
				sync();
				add.focus();
				announce( status, __( 'Row removed.', 'cradle-content' ) );
			} else if ( button.hasAttribute( 'data-cradle-up' ) && row.previousElementSibling ) {
				list.insertBefore( row, row.previousElementSibling );
				sync();
				button.focus();
			} else if ( button.hasAttribute( 'data-cradle-down' ) && row.nextElementSibling ) {
				list.insertBefore( row.nextElementSibling, row );
				sync();
				button.focus();
			}
		} );
		sync();
	}

	function initAll() {
		document.querySelectorAll( '[data-cradle-group]' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}
} )();
