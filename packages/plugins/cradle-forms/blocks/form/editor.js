/**
 * cradle/form editor registration (plain JS, no build step).
 *
 * Dynamic block: save() is null and the front end comes from the PHP component render
 * (render contract form-v1). The canvas shows a disabled server-side preview of the form.
 * The list of published forms (IDs, address names and titles) comes from window.cradleFormsEditor.
 * A block may select its form by address name (formSlug, used by theme templates where IDs differ
 * per site) while formId is 0; choosing a form in the picker sets formId and clears formSlug.
 */
( function ( wp, data ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var be = wp.blockEditor;
	var c = wp.components;
	var ServerSideRender = wp.serverSideRender;
	data = data || { forms: [] };

	function options() {
		return [ { value: '0', label: __( '— Select a form —', 'cradle-forms' ) } ].concat(
			( data.forms || [] ).map( function ( form ) {
				return { value: String( form.id ), label: form.title || '#' + form.id };
			} )
		);
	}

	function bySlug( slug ) {
		if ( ! slug ) {
			return null;
		}
		return ( data.forms || [] ).filter( function ( form ) {
			return form.slug === slug;
		} )[ 0 ] || null;
	}

	function FormPicker( props ) {
		return el( c.SelectControl, {
			label: __( 'Form', 'cradle-forms' ),
			value: String( props.value || 0 ),
			options: options(),
			onChange: function ( value ) {
				props.onChange( parseInt( value, 10 ) || 0 );
			},
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: true,
		} );
	}

	function Edit( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		var blockProps = be.useBlockProps( { className: 'cradle-form-block' } );
		var slugged = a.formId ? null : bySlug( a.formSlug );
		var selected = a.formId || ( slugged ? slugged.id : 0 );
		var choose = function ( id ) {
			set( { formId: id, formSlug: '' } );
		};
		var links = [];
		if ( data.canManage ) {
			if ( selected ) {
				links.push( el( c.ExternalLink, { key: 'edit', href: 'post.php?post=' + selected + '&action=edit' }, __( 'Edit this form', 'cradle-forms' ) ) );
			}
			links.push( el( c.ExternalLink, { key: 'manage', href: data.manageUrl }, __( 'Manage forms and entries', 'cradle-forms' ) ) );
		}

		var inspector = el(
			be.InspectorControls,
			null,
			el(
				c.PanelBody,
				{ title: __( 'Form', 'cradle-forms' ) },
				el( FormPicker, { value: selected, onChange: choose } ),
				! a.formId && a.formSlug ? el(
					'p',
					{ className: 'cradle-form-block__slug' },
					/* translators: %s: address name (slug) of a form */
					sprintf( __( 'Selected by its address name “%s” (for example in a theme template). Choosing a form here selects it by ID instead.', 'cradle-forms' ), a.formSlug )
				) : null,
				el( c.ToggleControl, {
					label: __( 'Show the form title', 'cradle-forms' ),
					checked: !! a.showTitle,
					onChange: function ( value ) { set( { showTitle: value } ); },
					__nextHasNoMarginBottom: true,
				} ),
				links.length ? el( 'p', { className: 'cradle-form-block__links' }, links ) : null
			)
		);

		if ( ! a.formId && ! a.formSlug ) {
			return el(
				Fragment,
				null,
				inspector,
				el(
					'div',
					blockProps,
					el(
						c.Placeholder,
						{ icon: 'feedback', label: __( 'Form', 'cradle-forms' ), instructions: ( data.forms || [] ).length ? __( 'Choose the form to show here.', 'cradle-forms' ) : __( 'There is no published form yet.', 'cradle-forms' ) },
						( data.forms || [] ).length ? el( FormPicker, { value: 0, onChange: choose } ) : null,
						data.canManage ? el( c.Button, { variant: 'secondary', href: data.newFormUrl, target: '_blank' }, __( 'Create a form', 'cradle-forms' ) ) : null
					)
				)
			);
		}

		return el(
			Fragment,
			null,
			inspector,
			el(
				'div',
				blockProps,
				el( c.Disabled, null, el( ServerSideRender, { block: 'cradle/form', attributes: { formId: a.formId || 0, formSlug: a.formId ? '' : a.formSlug || '', showTitle: !! a.showTitle } } ) )
			)
		);
	}

	wp.blocks.registerBlockType( 'cradle/form', {
		edit: Edit,
		save: function () {
			return null;
		},
	} );
} )( window.wp, window.cradleFormsEditor );
