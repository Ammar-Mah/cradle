/**
 * cradle/share-links editor registration (plain JS).
 *
 * Dynamic block: save() is null and the frontend output comes from the PHP
 * render service (render contract share-links-v1), which shares the entry in
 * block context (postId) or the current page address. Controls are shared with
 * cradle/social-links in assets/js/editor-controls.js (handle cradle-social-editor).
 */
( function ( wp ) {
	'use strict';

	wp.blocks.registerBlockType( 'cradle/share-links', {
		edit: window.cradleSocialBlocks.edit( 'cradle/share-links', 'share' ),
		save: function () {
			return null;
		},
	} );
} )( window.wp );
