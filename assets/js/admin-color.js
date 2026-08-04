/**
 * Keeps the native color swatches beside the appearance fields in step with the
 * text boxes they belong to.
 *
 * The text box stays the source of truth, because it is the only one of the two
 * that can hold what these fields actually accept — currentColor, Canvas, or a
 * var() pointing at a theme's own palette. The swatch is a way to type a hex
 * without knowing its digits, and nothing more: it never rewrites a value it
 * cannot represent, it only writes when somebody uses it.
 */
( function () {
	'use strict';

	var HEX = /^#[0-9a-f]{6}$/i;

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.lfndr-color-field__swatch' ).forEach( function ( swatch ) {
			var field = document.getElementById( swatch.getAttribute( 'data-for' ) );
			if ( ! field ) {
				return;
			}

			swatch.addEventListener( 'input', function () {
				field.value = swatch.value;
			} );

			/* Typed values flow the other way only when they are a hex the
			 * swatch can show. A field reading "currentColor" leaves the swatch
			 * where it was rather than snapping it to black, which would
			 * suggest the field said something it does not. */
			field.addEventListener( 'input', function () {
				if ( HEX.test( field.value.trim() ) ) {
					swatch.value = field.value.trim();
				}
			} );
		} );
	} );
}() );
