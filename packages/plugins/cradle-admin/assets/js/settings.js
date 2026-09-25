/**
 * Cradle Admin settings page enhancements (vanilla JS, progressive):
 * live preview via CSS custom properties, contrast readout and a CSS byte counter.
 * Every form works without this script; the server validates everything again.
 */
( function () {
	'use strict';

	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
	var __ = i18n ? i18n.__ : function ( s ) { return s; };
	var sprintf = i18n ? i18n.sprintf : function ( s ) { return s; };

	var PREFIX = 'cradle_admin_visual_settings';
	var COLOR_VARS = {
		accent_color: '--preview-accent',
		background_color: '--preview-bg',
		text_color: '--preview-text',
		border_color: '--preview-border',
		focus_color: '--preview-focus',
		menu_background: '--preview-menu-bg',
		menu_text: '--preview-menu-text'
	};
	var PAIRS = {
		text: [ 'text_color', 'background_color', 4.5 ],
		panel: [ 'text_color', '#ffffff', 4.5 ],
		accent: [ 'accent_color', 'background_color', 4.5 ],
		button: [ 'accent_color', '#ffffff', 4.5 ],
		focus: [ 'focus_color', 'background_color', 3 ],
		menu: [ 'menu_text', 'menu_background', 4.5 ]
	};

	function channel( v ) {
		v = v / 255;
		return v <= 0.04045 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
	}

	function luminance( hex ) {
		var m = /^#([0-9a-f]{6})$/i.exec( hex || '' );
		if ( ! m ) {
			return null;
		}
		var n = parseInt( m[ 1 ], 16 );
		return 0.2126 * channel( ( n >> 16 ) & 255 ) + 0.7152 * channel( ( n >> 8 ) & 255 ) + 0.0722 * channel( n & 255 );
	}

	function contrast( a, b ) {
		var la = luminance( a );
		var lb = luminance( b );
		if ( null === la || null === lb ) {
			return null;
		}
		return ( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 );
	}

	function field( form, key ) {
		return form.querySelector( '[name="' + PREFIX + '[' + key + ']"]' );
	}

	function initPreview() {
		var preview = document.querySelector( '.cradle-admin-preview' );
		var form = document.querySelector( 'form.cradle-settings-form' );
		if ( ! preview || ! form || ! field( form, 'accent_color' ) ) {
			return;
		}
		var table = document.getElementById( 'cradle-admin-contrast' );

		function value( key ) {
			if ( '#' === key.charAt( 0 ) ) {
				return key;
			}
			var input = field( form, key );
			return input ? String( input.value ).toLowerCase() : '';
		}

		function update() {
			Object.keys( COLOR_VARS ).forEach( function ( key ) {
				var v = value( key );
				if ( /^#[0-9a-f]{6}$/.test( v ) ) {
					preview.style.setProperty( COLOR_VARS[ key ], v );
				}
			} );
			var radius = form.querySelector( '[name="' + PREFIX + '[border_radius][value]"]' );
			if ( radius && /^\d+(\.\d+)?$/.test( radius.value ) ) {
				preview.style.setProperty( '--preview-radius', Math.min( 16, parseFloat( radius.value ) ) + 'px' );
			}
			if ( ! table ) {
				return;
			}
			Object.keys( PAIRS ).forEach( function ( pair ) {
				var row = table.querySelector( 'tr[data-pair="' + pair + '"] .cradle-admin-contrast__value' );
				var ratio = contrast( value( PAIRS[ pair ][ 0 ] ), value( PAIRS[ pair ][ 1 ] ) );
				if ( ! row || null === ratio ) {
					return;
				}
				var ok = ratio >= PAIRS[ pair ][ 2 ];
				row.textContent = sprintf(
					/* translators: 1: contrast ratio, 2: pass/fail word */
					__( '%1$s:1 %2$s', 'cradle-admin' ),
					ratio.toFixed( 2 ),
					ok ? __( '(passes)', 'cradle-admin' ) : __( '(too low)', 'cradle-admin' )
				);
				row.classList.toggle( 'is-failing', ! ok );
			} );
		}

		form.addEventListener( 'input', update );
		form.addEventListener( 'change', update );
		update();
	}

	function initCssCounter() {
		var editor = document.getElementById( 'cradle-admin-css-editor' );
		var count = document.getElementById( 'cradle-admin-css-count' );
		if ( ! editor || ! count || ! window.TextEncoder ) {
			return;
		}
		var max = parseInt( count.getAttribute( 'data-max' ), 10 ) || 65536;
		var encoder = new window.TextEncoder();
		function update() {
			var bytes = encoder.encode( editor.value ).length;
			count.textContent = sprintf(
				/* translators: 1: bytes used, 2: byte limit */
				__( '%1$s of %2$s bytes', 'cradle-admin' ),
				bytes.toLocaleString(),
				max.toLocaleString()
			);
			count.classList.toggle( 'cradle-field-error', bytes > max );
		}
		editor.addEventListener( 'input', update );
		update();
	}

	function init() {
		initPreview();
		initCssCounter();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
