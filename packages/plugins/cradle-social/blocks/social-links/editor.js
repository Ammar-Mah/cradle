/**
 * cradle/social-links editor registration (plain JS).
 *
 * Dynamic block: save() is null and the frontend output comes from the PHP
 * render service (render contract social-links-v1). Controls are shared with
 * cradle/share-links in assets/js/editor-controls.js (handle cradle-social-editor).
 */
( function ( wp ) {
	'use strict';

	wp.blocks.registerBlockType( 'cradle/social-links', {
		edit: window.cradleSocialBlocks.edit( 'cradle/social-links', 'profiles' ),
		save: function () {
			return null;
		},
	} );
} )( window.wp );
