/* Edytor bloku aktio/faq — bez bundlera, na globalnych wp.*. Render po stronie serwera. */
( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var Button = wp.components.Button;

	wp.blocks.registerBlockType( 'aktio/faq', {
		edit: function ( props ) {
			var items = props.attributes.items || [];
			var blockProps = wp.blockEditor.useBlockProps();

			function update( index, key, value ) {
				var next = items.map( function ( item, i ) {
					if ( i !== index ) {
						return item;
					}
					var copy = { q: item.q, a: item.a };
					copy[ key ] = value;
					return copy;
				} );
				props.setAttributes( { items: next } );
			}

			function remove( index ) {
				props.setAttributes( {
					items: items.filter( function ( item, i ) {
						return i !== index;
					} ),
				} );
			}

			function add() {
				props.setAttributes( { items: items.concat( [ { q: '', a: '' } ] ) } );
			}

			return el(
				'div',
				blockProps,
				items.map( function ( item, index ) {
					return el(
						'div',
						{ key: index, className: 'aktio-faq-edit-item' },
						el( TextControl, {
							label: __( 'Question', 'aktio-connector' ),
							value: item.q || '',
							onChange: function ( v ) {
								update( index, 'q', v );
							},
						} ),
						el( TextareaControl, {
							label: __( 'Answer', 'aktio-connector' ),
							value: item.a || '',
							onChange: function ( v ) {
								update( index, 'a', v );
							},
						} ),
						el(
							Button,
							{
								isDestructive: true,
								variant: 'link',
								onClick: function () {
									remove( index );
								},
							},
							__( 'Remove', 'aktio-connector' )
						)
					);
				} ),
				el(
					Button,
					{ variant: 'secondary', onClick: add },
					__( 'Add question', 'aktio-connector' )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
