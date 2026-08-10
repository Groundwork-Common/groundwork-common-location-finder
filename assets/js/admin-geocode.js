/**
 * Address autocomplete in the location editor.
 *
 * Types into the search box, asks our proxy, and on selection fills the address
 * subfields and the two built-in coordinate inputs.
 */
( function () {
	'use strict';

	var cfg = window.GWC_LFNDR_GEOCODE;
	if ( ! cfg || ! cfg.ajaxUrl ) {
		return;
	}

	// 450ms. Nominatim's usage policy is one request per second and the server
	// throttles to match; anything tighter just burns the budget on prefixes of
	// what the user was going to type anyway.
	var DEBOUNCE_MS = 450;
	var MIN_CHARS = 4;

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			node.setAttribute( key, attrs[ key ] );
		} );
		if ( undefined !== text && null !== text ) {
			node.textContent = text;
		}
		return node;
	}

	function initAddress( wrap ) {
		var input = wrap.querySelector( '[data-lfndr-geocode]' );
		var list = wrap.querySelector( '.lfndr-address__results' );
		var status = wrap.querySelector( '.lfndr-address__status' );
		if ( ! input || ! list ) {
			return;
		}

		var timer = null;
		var results = [];
		var active = -1;
		var request = 0;

		function partInput( key ) {
			return wrap.querySelector( '[data-lfndr-address-part="' + key + '"]' );
		}

		function close() {
			list.hidden = true;
			list.textContent = '';
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
			active = -1;
		}

		function say( message ) {
			if ( status ) {
				status.textContent = message || '';
			}
		}

		function highlight( index ) {
			var items = list.querySelectorAll( '[role="option"]' );
			Array.prototype.forEach.call( items, function ( item, i ) {
				var on = i === index;
				item.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				item.classList.toggle( 'is-active', on );
				if ( on ) {
					input.setAttribute( 'aria-activedescendant', item.id );
					item.scrollIntoView( { block: 'nearest' } );
				}
			} );
			active = index;
		}

		function choose( index ) {
			var result = results[ index ];
			if ( ! result ) {
				return;
			}

			// "AL" or "Alabama", per the field's setting — falling back to the
			// full name when the geocoder has no code for that country.
			var wantsCode = 'code' === wrap.getAttribute( 'data-lfndr-region-format' );
			var region = wantsCode && result.regionCode ? result.regionCode : result.region;

			[ 'line1', 'city', 'region', 'postal', 'country' ].forEach( function ( key ) {
				var field = partInput( key );
				var value = 'region' === key ? region : result[ key ];
				// Only fill what the geocoder actually returned. Blanking a
				// suite number somebody typed by hand because the result had no
				// house number is a worse outcome than leaving it alone.
				if ( field && value ) {
					field.value = value;
				}
			} );

			var lat = document.getElementById( 'lfndr-lat' );
			var lng = document.getElementById( 'lfndr-lng' );
			if ( lat && result.lat ) {
				lat.value = result.lat;
			}
			if ( lng && result.lng ) {
				lng.value = result.lng;
			}

			input.value = '';
			close();
			say( cfg.strings.filled || '' );
		}

		function render() {
			list.textContent = '';

			if ( ! results.length ) {
				close();
				say( cfg.strings.none || '' );
				return;
			}

			results.forEach( function ( result, i ) {
				var item = el(
					'li',
					{
						role: 'option',
						id: 'lfndr-geo-option-' + i,
						'aria-selected': 'false',
						'data-index': String( i )
					},
					result.label
				);
				list.appendChild( item );
			} );

			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
			say( '' );
		}

		function search( query ) {
			var mine = ++request;
			var url =
				cfg.ajaxUrl +
				'?action=gwc_lfndr_geocode&nonce=' +
				encodeURIComponent( cfg.nonce ) +
				'&q=' +
				encodeURIComponent( query );

			say( cfg.strings.searching || '' );

			fetch( url, { credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( body ) {
					// A slow early request must not overwrite a fast later one.
					if ( mine !== request ) {
						return;
					}

					// "The service failed" and "the service found nothing" need
					// different words. Reporting an upstream 403 as "no matches"
					// sends someone off to retype a perfectly good address.
					if ( ! body || ! body.success ) {
						results = [];
						close();
						say( ( body && body.data && body.data.message ) || cfg.strings.error || '' );
						return;
					}

					results = Array.isArray( body.data ) ? body.data : [];
					render();
				} )
				.catch( function () {
					if ( mine !== request ) {
						return;
					}
					results = [];
					close();
					say( cfg.strings.error || '' );
				} );
		}

		input.addEventListener( 'input', function () {
			window.clearTimeout( timer );
			var query = input.value.trim();
			if ( query.length < MIN_CHARS ) {
				close();
				say( '' );
				return;
			}
			timer = window.setTimeout( function () {
				search( query );
			}, DEBOUNCE_MS );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( list.hidden ) {
				return;
			}
			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				highlight( ( active + 1 ) % results.length );
			} else if ( 'ArrowUp' === event.key ) {
				event.preventDefault();
				highlight( active <= 0 ? results.length - 1 : active - 1 );
			} else if ( 'Enter' === event.key && active > -1 ) {
				event.preventDefault();
				choose( active );
			} else if ( 'Escape' === event.key ) {
				close();
			}
		} );

		// The search box sits inside the post form; Enter must never submit it.
		input.addEventListener( 'keypress', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
			}
		} );

		list.addEventListener( 'click', function ( event ) {
			var item = event.target.closest( '[data-index]' );
			if ( item ) {
				choose( parseInt( item.getAttribute( 'data-index' ), 10 ) );
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( ! wrap.contains( event.target ) ) {
				close();
			}
		} );
	}

	function init() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-lfndr-geocode-target]' ),
			initAddress
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
