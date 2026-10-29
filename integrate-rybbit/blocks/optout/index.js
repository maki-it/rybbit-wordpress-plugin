/**
 * Block editor script of the Rybbit opt-out block.
 *
 * It needs no build step. The frontend output comes from PHP.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var defaults = window.integrateRybbitOptout || {};

	wp.blocks.registerBlockType( 'integrate-rybbit/optout', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var blockProps = useBlockProps( { className: 'integrate-rybbit-optout' } );

			function field( name, label ) {
				return el( TextControl, {
					label: label,
					value: attributes[ name ],
					placeholder: defaults[ name ] || '',
					onChange: function ( value ) {
						var change = {};
						change[ name ] = value;
						props.setAttributes( change );
					},
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true
				} );
			}

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Texts', 'integrate-rybbit' ), initialOpen: true },
						field( 'tracked_text', __( 'Text when analytics is on', 'integrate-rybbit' ) ),
						field( 'optout_label', __( 'Button to turn analytics off', 'integrate-rybbit' ) ),
						field( 'untracked_text', __( 'Text when analytics is off', 'integrate-rybbit' ) ),
						field( 'optin_label', __( 'Button to turn analytics on', 'integrate-rybbit' ) )
					)
				),
				el(
					'div',
					blockProps,
					el(
						'p',
						{ className: 'integrate-rybbit-optout__status' },
						attributes.tracked_text || defaults.tracked_text
					),
					el(
						'button',
						{ type: 'button', className: 'integrate-rybbit-optout__button wp-element-button', disabled: true },
						attributes.optout_label || defaults.optout_label
					)
				)
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
