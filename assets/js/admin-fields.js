/**
 * Locations → Fields: reordering, and the type-dependent parts of the form.
 *
 * No build step, no framework, no jQuery. ES5-flavoured so it needs no
 * transpilation to run wherever wp-admin runs.
 */
( function () {
	'use strict';

	/* ── Why arrow buttons and not drag-and-drop ─────────────────────────────
	 * jQuery UI sortable is still bundled with core but has been in maintenance
	 * mode for years, and a plugin whose whole premise is "no dependencies"
	 * should not bet a core feature on it.
	 *
	 * Native HTML5 drag-and-drop is worse: it is unusable on touch without a
	 * polyfill, it has no keyboard story at all, and WCAG 2.5.7 requires a
	 * single-pointer alternative anyway — so you end up writing these buttons
	 * regardless, at which point the dragging is extra surface for no extra
	 * capability.
	 *
	 * Buttons also give the whole accessibility story for about twenty lines:
	 * keep focus on the button that just moved so it can be pressed again, and
	 * say what happened in a live region.
	 * ─────────────────────────────────────────────────────────────────────── */

	var strings =
		( window.GWC_LFNDR_ADMIN && window.GWC_LFNDR_ADMIN.strings ) || {};

	function t( key, fallback ) {
		return strings[ key ] || fallback;
	}

	function initOrderList( root ) {
		var list = root.querySelector( '.lfndr-order__list' );
		var input = root.querySelector( '.lfndr-order__manual input' );
		var manual = root.querySelector( '.lfndr-order__manual' );
		if ( ! list || ! input ) {
			return;
		}

		// The text input is the no-JS fallback and stays as the field that
		// actually posts. We hide it and keep writing to it.
		if ( manual ) {
			manual.hidden = true;
		}

		var status = document.createElement( 'p' );
		status.className = 'screen-reader-text';
		status.setAttribute( 'role', 'status' );
		root.appendChild( status );

		Array.prototype.forEach.call(
			list.querySelectorAll( '.lfndr-order__buttons' ),
			function ( group ) {
				group.hidden = false;
			}
		);

		function sync() {
			var keys = [];
			var above = true;
			Array.prototype.forEach.call( list.children, function ( item ) {
				if ( item.hasAttribute( 'data-divider' ) ) {
					above = false;
					return;
				}
				if ( above && item.hasAttribute( 'data-key' ) ) {
					keys.push( item.getAttribute( 'data-key' ) );
				}
			} );
			input.value = keys.join( ',' );
		}

		function announce( item ) {
			var above = true;
			var position = 0;
			var total = 0;
			var found = 0;

			Array.prototype.forEach.call( list.children, function ( node ) {
				if ( node.hasAttribute( 'data-divider' ) ) {
					above = false;
					return;
				}
				if ( ! above ) {
					return;
				}
				total += 1;
				if ( node === item ) {
					found = total;
				}
			} );
			position = found;

			var label = item.querySelector( '.lfndr-order__label' );
			var name = label ? label.textContent : item.getAttribute( 'data-key' );

			status.textContent = position
				? t( 'moved', '%1$s moved to position %2$s of %3$s.' )
						.replace( '%1$s', name )
						.replace( '%2$s', String( position ) )
						.replace( '%3$s', String( total ) )
				: t( 'hidden', '%s is no longer shown here.' ).replace( '%s', name );
		}

		list.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-move]' );
			if ( ! button ) {
				return;
			}
			event.preventDefault();

			var item = button.closest( '.lfndr-order__item' );
			if ( ! item ) {
				return;
			}

			// Moving past the divider is what "hide this here" means. One
			// gesture, one representation — so the checkboxes on the field form
			// and the position in this list cannot drift apart.
			if ( 'up' === button.getAttribute( 'data-move' ) ) {
				if ( item.previousElementSibling ) {
					list.insertBefore( item, item.previousElementSibling );
				}
			} else if ( item.nextElementSibling ) {
				list.insertBefore( item.nextElementSibling, item );
			}

			sync();
			announce( item );
			button.focus();
		} );

		sync();
	}

	function initFieldForm() {
		var typeSelect = document.getElementById( 'lfndr-type' );
		var optionsRow = document.querySelector( '.lfndr-row-options' );
		if ( ! typeSelect || ! optionsRow ) {
			return;
		}

		var withOptions =
			( window.GWC_LFNDR_ADMIN && window.GWC_LFNDR_ADMIN.typesWithOptions ) || [];

		function toggle() {
			optionsRow.hidden = withOptions.indexOf( typeSelect.value ) === -1;
		}

		typeSelect.addEventListener( 'change', toggle );
		toggle();
	}

	function init() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '.lfndr-order' ),
			initOrderList
		);
		initFieldForm();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
