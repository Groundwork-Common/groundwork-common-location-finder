/**
 * The block's editor UI.
 *
 * No JSX, because there is no build step — this is plain createElement against
 * the wp.* globals. That is only tolerable because the editor UI is
 * deliberately a placeholder and a settings panel, not a live preview: it stays
 * under a hundred lines and has no state beyond the block's own attributes.
 *
 * Rendering the real finder here was considered and rejected. ServerSideRender
 * would re-render Leaflet inside the editor iframe on every keystroke — a map
 * library and a tile fetch per change — to preview something nobody edits
 * visually. If this file ever needs to grow past a placeholder, that is the
 * signal to reconsider the no-build constraint, not to write four hundred lines
 * of createElement.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'groundwork-common-location-finder/finder', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el(
				'div',
				blockEditor.useBlockProps(),

				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Accessibility', 'groundwork-common-location-finder' ) },
						/* Deliberately not a visible heading. A Heading block above
						 * the finder does that job better — it lands at the right
						 * level for the page outline and takes the theme's type,
						 * neither of which a plugin can get right from in here.
						 *
						 * What a heading cannot do is survive full screen, where
						 * the finder covers the page and every surrounding cue with
						 * it. This names the region for that, and for the case of
						 * two finders on one page, which are otherwise identical to
						 * anyone navigating by landmark. */
						el( components.TextControl, {
							label: __( 'Accessible name', 'groundwork-common-location-finder' ),
							value: attributes.label || '',
							placeholder: __( 'e.g. Food pantries', 'groundwork-common-location-finder' ),
							help: __( 'Not shown on the page. Names the finder for screen readers, which matters most in full screen and when a page has more than one finder. Leave blank to add no landmark at all.', 'groundwork-common-location-finder' ),
							onChange: function ( value ) {
								setAttributes( { label: value } );
							},
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true
						} )
					),
					el(
						components.PanelBody,
						{ title: __( 'Layout', 'groundwork-common-location-finder' ) },
						el( components.ToggleControl, {
							label: __( 'Show the map', 'groundwork-common-location-finder' ),
							help: attributes.showMap
								? __( 'Locations appear on a map beside the list.', 'groundwork-common-location-finder' )
								: __( 'Only the list is shown. Coordinates still drive distance sorting.', 'groundwork-common-location-finder' ),
							checked: !! attributes.showMap,
							onChange: function ( value ) {
								setAttributes( { showMap: value } );
							},
							__nextHasNoMarginBottom: true
						} ),
						attributes.showMap &&
							el( components.RangeControl, {
								label: __( 'Map height (px)', 'groundwork-common-location-finder' ),
								value: attributes.height || 0,
								min: 0,
								max: 900,
								step: 20,
								help: __( '0 uses the theme default.', 'groundwork-common-location-finder' ),
								onChange: function ( value ) {
									setAttributes( { height: value || 0 } );
								},
								__nextHasNoMarginBottom: true
							} )
					)
				),

				el(
					components.Placeholder,
					{
						icon: 'location-alt',
						label: __( 'Location Finder', 'groundwork-common-location-finder' ),
						instructions: __(
							'The searchable map and list render on the front end. Add and edit locations under Locations, and choose what they record under Locations → Fields.',
							'groundwork-common-location-finder'
						)
					}
				)
			);
		},

		// Server-rendered: nothing is stored in post content, so the markup can
		// change between versions without invalidating every existing page.
		save: function () {
			return null;
		}
	} );
} )( wp.blocks, wp.element, wp.blockEditor, wp.components, wp.i18n );
