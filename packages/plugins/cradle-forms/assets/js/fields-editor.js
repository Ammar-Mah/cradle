/**
 * Cradle Forms — shared editor script for every cradle-forms/field-* block (plain JS, no build).
 *
 * Field blocks are dynamic (save() returns null): their attributes live in the block comment
 * and the server derives and validates the form schema from them on every save. Outside a
 * cradle_form post the field blocks are hidden from the inserter. Strings use the
 * cradle-forms text domain.
 */
( function ( wp, data ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var be = wp.blockEditor;
	var c = wp.components;
	var useSelect = wp.data.useSelect;

	data = data || {};
	var PREFIX = 'cradle-forms/field-';
	var TYPES = [ 'text', 'email', 'tel', 'url', 'number', 'textarea', 'select', 'radio', 'checkboxes', 'date', 'consent', 'file', 'hidden' ];
	var CHOICE_TYPES = [ 'select', 'radio', 'checkboxes' ];
	var PLACEHOLDER_TYPES = [ 'text', 'email', 'tel', 'url', 'number', 'textarea', 'select' ];
	var NAME_RE = /^[a-z][a-z0-9_]{0,40}$/;

	wp.hooks.addFilter( 'blocks.registerBlockType', 'cradle-forms/field-inserter', function ( settings, name ) {
		if ( name.indexOf( PREFIX ) === 0 && ! data.isForm ) {
			return Object.assign( {}, settings, { supports: Object.assign( {}, settings.supports || {}, { inserter: false } ) } );
		}
		return settings;
	} );

	function stripTags( html ) {
		// DOMParser documents never run scripts or load resources.
		var doc = new window.DOMParser().parseFromString( String( html || '' ), 'text/html' );
		return ( ( doc.body && doc.body.textContent ) || '' ).replace( /\s+/g, ' ' ).trim();
	}

	function slug( text ) {
		var value = String( text || '' )
			.toLowerCase()
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]/g, '' )
			.replace( /[^a-z0-9]+/g, '_' )
			.replace( /^_+|_+$/g, '' );
		if ( value && ! /^[a-z]/.test( value ) ) {
			value = 'f_' + value;
		}
		return value.slice( 0, 41 ).replace( /_+$/, '' );
	}

	function unique( base, taken ) {
		if ( taken.indexOf( base ) === -1 ) {
			return base;
		}
		var i = 2;
		while ( taken.indexOf( base + '_' + i ) !== -1 ) {
			i++;
		}
		return base + '_' + i;
	}

	function collectNames( blocks, skip, out ) {
		blocks.forEach( function ( block ) {
			if ( block.name.indexOf( PREFIX ) === 0 && block.clientId !== skip && block.attributes.name ) {
				out.push( block.attributes.name );
			}
			if ( block.innerBlocks && block.innerBlocks.length ) {
				collectNames( block.innerBlocks, skip, out );
			}
		} );
		return out;
	}

	function numberControl( label, value, onChange, extra ) {
		return el(
			c.TextControl,
			Object.assign(
				{
					label: label,
					type: 'number',
					value: value === undefined || value === null ? '' : String( value ),
					onChange: function ( next ) {
						onChange( next === '' ? undefined : Number( next ) );
					},
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
				},
				extra || {}
			)
		);
	}

	function textControl( label, value, onChange, extra ) {
		return el(
			c.TextControl,
			Object.assign(
				{
					label: label,
					value: value || '',
					onChange: onChange,
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
				},
				extra || {}
			)
		);
	}

	function ChoicesControl( props ) {
		var choices = Array.isArray( props.value ) ? props.value : [];
		function commit( next ) {
			props.onChange( next );
		}
		function update( index, key, value ) {
			commit(
				choices.map( function ( choice, i ) {
					if ( i !== index ) {
						return choice;
					}
					var copy = Object.assign( {}, choice );
					copy[ key ] = value;
					return copy;
				} )
			);
		}
		function move( index, delta ) {
			var target = index + delta;
			if ( target < 0 || target >= choices.length ) {
				return;
			}
			var next = choices.slice();
			var item = next[ index ];
			next[ index ] = next[ target ];
			next[ target ] = item;
			commit( next );
		}
		return el(
			'div',
			{ className: 'cradle-forms-choices' },
			choices.length ? null : el( 'p', { className: 'cradle-forms-choices__empty' }, __( 'Add at least one choice.', 'cradle-forms' ) ),
			choices.map( function ( choice, index ) {
				return el(
					'fieldset',
					{ key: index, className: 'cradle-forms-choices__row' },
					el( 'legend', {}, sprintf( __( 'Choice %d', 'cradle-forms' ), index + 1 ) ),
					textControl( __( 'Label', 'cradle-forms' ), choice.label, function ( value ) {
						update( index, 'label', value );
					} ),
					textControl( __( 'Stored value', 'cradle-forms' ), choice.value, function ( value ) {
						update( index, 'value', value );
					}, { help: __( 'Optional. Used in entries, exports and routing rules. Empty: the label.', 'cradle-forms' ) } ),
					el(
						'div',
						{ className: 'cradle-forms-choices__tools' },
						el( c.Button, { variant: 'tertiary', size: 'small', disabled: index === 0, onClick: function () {
							move( index, -1 );
						} }, __( 'Move up', 'cradle-forms' ) ),
						el( c.Button, { variant: 'tertiary', size: 'small', disabled: index === choices.length - 1, onClick: function () {
							move( index, 1 );
						} }, __( 'Move down', 'cradle-forms' ) ),
						el( c.Button, { variant: 'tertiary', size: 'small', isDestructive: true, onClick: function () {
							commit( choices.filter( function ( other, i ) {
								return i !== index;
							} ) );
						} }, __( 'Remove', 'cradle-forms' ) )
					)
				);
			} ),
			el( c.Button, {
				variant: 'secondary',
				disabled: choices.length >= 100,
				onClick: function () {
					commit( choices.concat( [ { label: sprintf( __( 'Choice %d', 'cradle-forms' ), choices.length + 1 ), value: '' } ] ) );
				},
			}, __( 'Add choice', 'cradle-forms' ) )
		);
	}

	function choiceValue( choice ) {
		return choice.value ? choice.value : choice.label;
	}

	function Preview( props ) {
		var type = props.type;
		var a = props.attributes;
		var choices = Array.isArray( a.choices ) ? a.choices : [];
		switch ( type ) {
			case 'textarea':
				return el( 'textarea', { className: 'cradle-forms-field-preview__control', rows: a.rows || 5, placeholder: a.placeholder, disabled: true, value: a.defaultValue || '', readOnly: true } );
			case 'select':
				return el(
					'select',
					{ className: 'cradle-forms-field-preview__control', disabled: true, value: a.defaultValue || '' },
					el( 'option', { value: '' }, a.placeholder || __( 'Select an option', 'cradle-forms' ) ),
					choices.map( function ( choice, i ) {
						return el( 'option', { key: i, value: choiceValue( choice ) }, choice.label );
					} )
				);
			case 'radio':
			case 'checkboxes':
				return el(
					'div',
					{ className: 'cradle-forms-field-preview__choices' },
					choices.map( function ( choice, i ) {
						return el(
							'label',
							{ key: i, className: 'cradle-forms-field-preview__choice' },
							el( 'input', { type: type === 'radio' ? 'radio' : 'checkbox', disabled: true, checked: type === 'radio' && a.defaultValue === choiceValue( choice ), readOnly: true } ),
							' ',
							choice.label
						);
					} )
				);
			case 'file':
				return el(
					'div',
					{ className: 'cradle-forms-field-preview__file' },
					el( 'input', { type: 'file', disabled: true } ),
					el( 'p', { className: 'cradle-forms-field-preview__hint' }, sprintf( __( 'Allowed: %1$s. Up to %2$s MB.', 'cradle-forms' ), String( a.accept || '' ).toUpperCase(), a.maxSize || 5 ) )
				);
		}
		var inputType = { email: 'email', tel: 'tel', url: 'url', number: 'number', date: 'date' }[ type ] || 'text';
		return el( 'input', { className: 'cradle-forms-field-preview__control', type: inputType, placeholder: a.placeholder, disabled: true, value: a.defaultValue || '', readOnly: true } );
	}

	function makeEdit( type ) {
		return function Edit( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var others = useSelect(
				function ( select ) {
					return collectNames( select( 'core/block-editor' ).getBlocks(), props.clientId, [] );
				},
				[ props.clientId ]
			);
			var duplicate = !! a.name && others.indexOf( a.name ) !== -1;
			var invalid = !! a.name && ! NAME_RE.test( a.name );
			var blockProps = be.useBlockProps( {
				className: 'cradle-forms-field-preview is-' + type + ' is-' + ( a.width || 'full' ) + ( duplicate || invalid || ! a.name ? ' has-problem' : '' ),
			} );

			useEffect( function () {
				if ( ! a.name ) {
					set( { name: unique( slug( stripTags( a.label ) ) || type, others ) } );
				}
			}, [] );

			function onLabel( value ) {
				var patch = { label: value };
				var previous = slug( stripTags( a.label ) ) || type;
				if ( ! a.name || a.name === previous || a.name.indexOf( previous + '_' ) === 0 || a.name === type ) {
					patch.name = unique( slug( stripTags( value ) ) || type, others );
				}
				set( patch );
			}

			var problems = [];
			if ( ! a.name || invalid ) {
				problems.push( __( 'The name must start with a letter and use only lowercase letters, digits and underscores.', 'cradle-forms' ) );
			}
			if ( duplicate ) {
				problems.push( __( 'Another field already uses this name. Each field needs its own name.', 'cradle-forms' ) );
			}

			var main = [
				problems.length
					? el( c.Notice, { key: 'problems', status: 'warning', isDismissible: false }, problems.join( ' ' ) )
					: null,
				type !== 'consent'
					? textControl( __( 'Label', 'cradle-forms' ), a.label, onLabel, { key: 'label' } )
					: null,
				textControl( __( 'Name', 'cradle-forms' ), a.name, function ( value ) {
					set( { name: String( value || '' ).toLowerCase().replace( /[^a-z0-9_]/g, '_' ).slice( 0, 41 ) } );
				}, { key: 'name', help: __( 'Key used in entries, exports and routing rules. Lowercase letters, digits and underscores; unique in the form.', 'cradle-forms' ) } ),
			];
			if ( type !== 'hidden' ) {
				main.push(
					el( c.TextareaControl, {
						key: 'help',
						label: __( 'Help text', 'cradle-forms' ),
						value: a.help || '',
						onChange: function ( value ) {
							set( { help: value } );
						},
						__nextHasNoMarginBottom: true,
					} )
				);
			}
			if ( PLACEHOLDER_TYPES.indexOf( type ) !== -1 ) {
				main.push( textControl( __( 'Placeholder', 'cradle-forms' ), a.placeholder, function ( value ) {
					set( { placeholder: value } );
				}, { key: 'placeholder', help: __( 'Example shown inside the empty field. Not a replacement for the label.', 'cradle-forms' ) } ) );
			}
			if ( type !== 'hidden' ) {
				main.push(
					el( c.ToggleControl, {
						key: 'required',
						label: __( 'Required', 'cradle-forms' ),
						checked: !! a.required,
						onChange: function ( value ) {
							set( { required: value } );
						},
						__nextHasNoMarginBottom: true,
					} ),
					el( c.SelectControl, {
						key: 'width',
						label: __( 'Width', 'cradle-forms' ),
						value: a.width || 'full',
						options: [
							{ value: 'full', label: __( 'Full width', 'cradle-forms' ) },
							{ value: 'half', label: __( 'Half width', 'cradle-forms' ) },
						],
						onChange: function ( value ) {
							set( { width: value } );
						},
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
					} )
				);
			}

			var options = [];
			switch ( type ) {
				case 'text':
					options.push(
						numberControl( __( 'Maximum length', 'cradle-forms' ), a.maxLength, function ( v ) {
							set( { maxLength: v } );
						}, { key: 'max', min: 1, max: 5000 } ),
						el( c.SelectControl, {
							key: 'autocomplete',
							label: __( 'Autocomplete', 'cradle-forms' ),
							help: __( 'Lets browsers fill in known personal data, which helps many visitors.', 'cradle-forms' ),
							value: a.autocomplete || '',
							options: [ { value: '', label: __( 'None', 'cradle-forms' ) } ].concat(
								( data.autocomplete || [] ).map( function ( token ) {
									return { value: token, label: token };
								} )
							),
							onChange: function ( value ) {
								set( { autocomplete: value } );
							},
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
						} )
					);
					break;
				case 'email':
					options.push(
						el( c.ToggleControl, {
							key: 'submitter',
							label: __( 'This is the submitter’s own email address', 'cradle-forms' ),
							help: __( 'Used by the privacy export and erase tools and for the copy sent to the submitter. Mark at most one email field.', 'cradle-forms' ),
							checked: !! a.submitterEmail,
							onChange: function ( value ) {
								set( { submitterEmail: value } );
							},
							__nextHasNoMarginBottom: true,
						} )
					);
					break;
				case 'number':
					options.push(
						numberControl( __( 'Minimum', 'cradle-forms' ), a.min, function ( v ) {
							set( { min: v } );
						}, { key: 'min' } ),
						numberControl( __( 'Maximum', 'cradle-forms' ), a.max, function ( v ) {
							set( { max: v } );
						}, { key: 'max' } ),
						numberControl( __( 'Step', 'cradle-forms' ), a.step, function ( v ) {
							set( { step: v } );
						}, { key: 'step', help: __( '1 for whole numbers, 0.01 for two decimals. Empty: any value.', 'cradle-forms' ) } )
					);
					break;
				case 'textarea':
					options.push(
						numberControl( __( 'Maximum length', 'cradle-forms' ), a.maxLength, function ( v ) {
							set( { maxLength: v } );
						}, { key: 'max', min: 1, max: 20000 } ),
						numberControl( __( 'Rows', 'cradle-forms' ), a.rows, function ( v ) {
							set( { rows: v } );
						}, { key: 'rows', min: 2, max: 20 } )
					);
					break;
				case 'date':
					options.push(
						textControl( __( 'Earliest date', 'cradle-forms' ), a.min, function ( v ) {
							set( { min: v } );
						}, { key: 'min', type: 'date' } ),
						textControl( __( 'Latest date', 'cradle-forms' ), a.max, function ( v ) {
							set( { max: v } );
						}, { key: 'max', type: 'date' } )
					);
					break;
				case 'select':
				case 'radio':
				case 'checkboxes':
					options.push(
						el( ChoicesControl, {
							key: 'choices',
							value: a.choices,
							onChange: function ( value ) {
								set( { choices: value } );
							},
						} )
					);
					if ( type === 'checkboxes' ) {
						options.push(
							numberControl( __( 'Minimum selections', 'cradle-forms' ), a.minChoices || undefined, function ( v ) {
								set( { minChoices: v } );
							}, { key: 'minc', min: 0 } ),
							numberControl( __( 'Maximum selections', 'cradle-forms' ), a.maxChoices || undefined, function ( v ) {
								set( { maxChoices: v } );
							}, { key: 'maxc', min: 0 } )
						);
					}
					break;
				case 'file':
					options.push(
						textControl( __( 'Allowed file types', 'cradle-forms' ), a.accept, function ( v ) {
							set( { accept: v } );
						}, { key: 'accept', help: sprintf( __( 'Comma-separated extensions. This site allows: %s.', 'cradle-forms' ), ( data.extensions || [] ).join( ', ' ) ) } ),
						numberControl( __( 'Maximum size per file (MB)', 'cradle-forms' ), a.maxSize, function ( v ) {
							set( { maxSize: v } );
						}, { key: 'size', min: 0.1, step: 0.1, max: data.maxUploadMb || 5, help: sprintf( __( 'At most %s MB on this site.', 'cradle-forms' ), data.maxUploadMb || 5 ) } ),
						numberControl( __( 'Maximum number of files', 'cradle-forms' ), a.maxFiles, function ( v ) {
							set( { maxFiles: v } );
						}, { key: 'files', min: 1, max: 10 } )
					);
					break;
				case 'hidden':
					options.push(
						textControl( __( 'Query parameter', 'cradle-forms' ), a.fromQuery, function ( v ) {
							set( { fromQuery: String( v || '' ).toLowerCase().replace( /[^a-z0-9_]/g, '' ).slice( 0, 40 ) } );
						}, { key: 'query', help: __( 'Optional. Take the value from this parameter of the page address, for example "position" in ?position=coordinator. Empty: always store the value below.', 'cradle-forms' ) } )
					);
					break;
			}
			if ( CHOICE_TYPES.indexOf( type ) !== -1 && type !== 'checkboxes' ) {
				options.push(
					el( c.SelectControl, {
						key: 'default',
						label: __( 'Selected by default', 'cradle-forms' ),
						value: a.defaultValue || '',
						options: [ { value: '', label: __( 'None', 'cradle-forms' ) } ].concat(
							( a.choices || [] ).map( function ( choice ) {
								return { value: choiceValue( choice ), label: choice.label || choiceValue( choice ) };
							} )
						),
						onChange: function ( value ) {
							set( { defaultValue: value } );
						},
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
					} )
				);
			} else if ( [ 'checkboxes', 'consent', 'file' ].indexOf( type ) === -1 ) {
				options.push( textControl( type === 'hidden' ? __( 'Value', 'cradle-forms' ) : __( 'Default value', 'cradle-forms' ), a.defaultValue, function ( v ) {
					set( { defaultValue: v } );
				}, { key: 'default', type: type === 'date' ? 'date' : 'text' } ) );
			}

			var inspector = el(
				be.InspectorControls,
				null,
				el( c.PanelBody, { title: __( 'Field', 'cradle-forms' ) }, main ),
				options.length ? el( c.PanelBody, { title: __( 'Options', 'cradle-forms' ) }, options ) : null
			);

			var marker = a.required ? el( 'span', { className: 'cradle-forms-field-preview__required', 'aria-hidden': 'true' }, ' *' ) : null;
			var body;
			if ( type === 'hidden' ) {
				body = el(
					'p',
					{ className: 'cradle-forms-field-preview__hidden' },
					sprintf( __( 'Hidden field “%1$s”: %2$s', 'cradle-forms' ), a.name || '—', a.fromQuery ? sprintf( __( 'value from ?%s=', 'cradle-forms' ), a.fromQuery ) : a.defaultValue || '—' )
				);
			} else if ( type === 'consent' ) {
				body = el(
					'div',
					{ className: 'cradle-forms-field-preview__consent' },
					el( 'input', { type: 'checkbox', disabled: true } ),
					el( be.RichText, {
						tagName: 'span',
						className: 'cradle-forms-field-preview__label',
						value: a.label,
						allowedFormats: [ 'core/bold', 'core/italic', 'core/link' ],
						placeholder: __( 'I agree that my details are stored to answer my request.', 'cradle-forms' ),
						onChange: function ( value ) {
							set( { label: value } );
						},
					} ),
					marker
				);
			} else {
				body = [
					el(
						'div',
						{ key: 'label', className: 'cradle-forms-field-preview__label-row' },
						el( be.RichText, {
							tagName: 'span',
							className: 'cradle-forms-field-preview__label',
							value: a.label,
							allowedFormats: [],
							withoutInteractiveFormatting: true,
							placeholder: __( 'Field label', 'cradle-forms' ),
							onChange: onLabel,
						} ),
						marker
					),
					a.help ? el( 'p', { key: 'help', className: 'cradle-forms-field-preview__help' }, a.help ) : null,
					el( Preview, { key: 'control', type: type, attributes: a } ),
				];
			}

			return el( Fragment, null, inspector, el( 'div', blockProps, body ) );
		};
	}

	TYPES.forEach( function ( type ) {
		wp.blocks.registerBlockType( PREFIX + type, {
			edit: makeEdit( type ),
			save: function () {
				return null;
			},
		} );
	} );
} )( window.wp, window.cradleFormsEditor );
