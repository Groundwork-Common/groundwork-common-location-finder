/**
 * The shared repeater driver: add and remove rows for any field type that
 * stores a list.
 *
 * One script for hours and closures alike. It knows nothing about either — it
 * clones a <template> the server rendered, swaps the index token, and appends.
 * That is what keeps the row markup in exactly one place (PHP) instead of two
 * that drift the first time a field gains a control.
 */
( function () {
	'use strict';

	var INDEX_TOKEN = '__i__';

	function nextIndex( rows ) {
		// The highest index in use plus one, rather than a count. Removing the
		// middle row of three and then adding one must not reuse an index that
		// is still on the page, or the two rows collide in $_POST and one of
		// them silently disappears on save.
		var highest = -1;
		Array.prototype.forEach.call(
			rows.querySelectorAll( '[name]' ),
			function ( input ) {
				var match = input.name.match( /\[(\d+)\]/ );
				if ( match ) {
					highest = Math.max( highest, parseInt( match[ 1 ], 10 ) );
				}
			}
		);
		return highest + 1;
	}

	function initRepeater( root ) {
		var rows = root.querySelector( '.lfndr-repeater__rows' );
		var template = root.querySelector( '.lfndr-repeater__template' );
		var addButton = root.querySelector( '.lfndr-repeater__add' );
		if ( ! rows || ! template || ! addButton ) {
			return;
		}

		var maxRows = parseInt( root.getAttribute( 'data-max-rows' ) || '0', 10 );

		var status = document.createElement( 'span' );
		status.className = 'screen-reader-text';
		status.setAttribute( 'role', 'status' );
		root.appendChild( status );

		function count() {
			return rows.querySelectorAll( '.lfndr-repeater__row' ).length;
		}

		function refresh() {
			if ( maxRows > 0 ) {
				addButton.disabled = count() >= maxRows;
			}
		}

		addButton.addEventListener( 'click', function () {
			if ( maxRows > 0 && count() >= maxRows ) {
				return;
			}

			var index = nextIndex( rows );
			var clone = template.content.cloneNode( true );

			Array.prototype.forEach.call(
				clone.querySelectorAll( '[name]' ),
				function ( input ) {
					input.name = input.name.split( INDEX_TOKEN ).join( String( index ) );
				}
			);

			rows.appendChild( clone );
			refresh();

			// Move focus into the row that was just created. Without this the
			// focus stays on Add and a keyboard user has to tab back through
			// every existing row to reach the new one.
			var added = rows.lastElementChild;
			var first = added && added.querySelector( 'select, input' );
			if ( first ) {
				first.focus();
			}
			status.textContent = ( window.LFNDR_REPEATER && window.LFNDR_REPEATER.added ) || '';
		} );

		rows.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.lfndr-repeater__remove' );
			if ( ! button ) {
				return;
			}
			event.preventDefault();

			var row = button.closest( '.lfndr-repeater__row' );
			if ( ! row ) {
				return;
			}

			// Focus has to leave the row before it is removed, or it falls back
			// to <body> and the keyboard position is lost entirely.
			var next = row.nextElementSibling || row.previousElementSibling;
			var target = next ? next.querySelector( 'select, input' ) : addButton;

			row.remove();
			refresh();

			if ( target ) {
				target.focus();
			}
			status.textContent = ( window.LFNDR_REPEATER && window.LFNDR_REPEATER.removed ) || '';
		} );

		refresh();
	}

	function init() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-lfndr-repeater]' ),
			initRepeater
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
