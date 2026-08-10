/**
 * The location finder.
 *
 * One IIFE, no framework, no build step. Boots from an inert JSON island the
 * server printed, renders cards and a detail pane from a schema it was handed
 * rather than any field names baked in here, and talks to Leaflet for the map.
 */
( function () {
	'use strict';

	/* wp-i18n is a declared dependency, so these are normally the real thing. The
	 * fallbacks stay because the cost of being wrong is asymmetric: if anything
	 * ever keeps that script from loading — an aggressive optimizer plugin,
	 * a botched concatenation — returning the untranslated source string leaves
	 * a working finder, while a TypeError here kills the whole boot. */
	var __ = ( window.wp && window.wp.i18n && window.wp.i18n.__ ) || function ( s ) { return s; };

	/* Every __() call below repeats the text domain as a STRING LITERAL. That
	 * looks like something a constant should fix, and it was a constant until
	 * `wp i18n make-pot` extracted 459 strings from the PHP and zero from here:
	 * the extractor parses the source statically and cannot know what a variable
	 * holds, so every call with a non-literal domain is skipped in silence. The
	 * plugin still ran and still looked correct — the strings simply never
	 * reached the .pot, so no translation could ever exist for them.
	 *
	 * So: do not hoist 'groundwork-common-location-finder' into a variable here. The repetition is
	 * what makes the front end translatable at all. */
	var BREAKPOINT = 860;
	var DETAIL_ZOOM = 14;
	var SUGGEST_LIMIT = 6;
	var SUGGEST_MIN_CHARS = 2;
	var CLOSURE_LOOKAHEAD_FALLBACK = 7;

	// A geolocation fix coarser than this is an IP or cell-tower guess, not a
	// GPS one. Zooming to street level on it shows a confident pin in the wrong
	// neighbourhood, so we frame the accuracy circle instead.
	var COARSE_FIX_METRES = 5000;

	var EARTH_RADIUS_KM = 6371;
	var KM_PER_MILE = 1.609344;

	/* ── DOM building ────────────────────────────────────────────────────────
	 * Everything is createElement and textContent. Never innerHTML, never a
	 * hand-rolled escaping helper.
	 *
	 * With an admin-defined schema, the string-concatenation approach would put
	 * an escaping decision at something like a hundred call sites, every one of
	 * them a place where a missed call is stored XSS injectable by anybody who
	 * can edit a location. textContent makes that class of bug unrepresentable
	 * rather than merely avoided, and costs about twenty lines.
	 *
	 * The two things createElement does not make safe on its own — an href, and
	 * Leaflet's divIcon html — get explicit handling below.
	 * ─────────────────────────────────────────────────────────────────────── */

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );

		Object.keys( attrs || {} ).forEach( function ( key ) {
			var value = attrs[ key ];
			if ( null === value || false === value || undefined === value ) {
				return;
			}
			if ( 'class' === key ) {
				node.className = value;
			} else if ( 'text' === key ) {
				node.textContent = value;
			} else if ( true === value ) {
				node.setAttribute( key, '' );
			} else {
				node.setAttribute( key, String( value ) );
			}
		} );

		( children || [] ).forEach( function ( child ) {
			if ( null === child || undefined === child || false === child ) {
				return;
			}
			node.appendChild( 'string' === typeof child ? document.createTextNode( child ) : child );
		} );

		return node;
	}

	/* The icon set: a closed table of source literals, never anything derived
	 * from a location, a field label or a URL. Built with createElementNS
	 * rather than innerHTML anyway — the string would be safe here, but leaving
	 * one innerHTML in the file is how the next person concludes it is the
	 * house style and reaches for it somewhere it is not safe. */
	var ICON_PATHS = {
		filter: 'M3 5h18l-7 8.5V19l-4 2v-7.5L3 5z',
		expand: 'M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z',
		collapse: 'M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z',
		back: 'M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z',
		alert: 'M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z',
		locate: 'M12 8c-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4zm8.94 3c-.46-4.17-3.77-7.48-7.94-7.94V1h-2v2.06C6.83 3.52 3.52 6.83 3.06 11H1v2h2.06c.46 4.17 3.77 7.48 7.94 7.94V23h2v-2.06c4.17-.46 7.48-3.77 7.94-7.94H23v-2h-2.06zM12 19c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7z'
	};

	var SVG_NS = 'http://www.w3.org/2000/svg';

	function icon( name ) {
		if ( ! ICON_PATHS[ name ] ) {
			return null;
		}
		var svg = document.createElementNS( SVG_NS, 'svg' );
		svg.setAttribute( 'class', 'lfndr__icon' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );

		var path = document.createElementNS( SVG_NS, 'path' );
		path.setAttribute( 'd', ICON_PATHS[ name ] );
		svg.appendChild( path );

		return svg;
	}

	// javascript: and data: URLs in an href are script execution. Anything not
	// on this list becomes plain text rather than a link.
	var SAFE_SCHEMES = [ 'http:', 'https:', 'tel:', 'mailto:' ];

	function safeHref( url ) {
		if ( ! url ) {
			return null;
		}
		try {
			var parsed = new URL( String( url ), window.location.href );
			return SAFE_SCHEMES.indexOf( parsed.protocol ) === -1 ? null : parsed.href;
		} catch ( e ) {
			return null;
		}
	}

	function link( url, text, className ) {
		var href = safeHref( url );
		if ( ! href ) {
			return el( 'span', { class: className }, [ String( text ) ] );
		}
		var attrs = { class: className, href: href };
		if ( 0 === href.indexOf( 'http' ) ) {
			attrs.rel = 'noopener';
		}
		return el( 'a', attrs, [ String( text ) ] );
	}

	/* ── Time ────────────────────────────────────────────────────────────────
	 * Everything about "open now" is decided here, against the site's timezone
	 * rather than the visitor's. Somebody checking a Birmingham diaper bank from
	 * a phone still set to Pacific time wants Birmingham's clock.
	 * ─────────────────────────────────────────────────────────────────────── */

	var IANA_RE = /^(?:UTC|[A-Za-z]+\/[A-Za-z0-9_+\-/]+)$/;
	var tzWarned = false;

	/**
	 * The current moment in the finder's timezone.
	 *
	 * Three tiers, in order of fidelity:
	 *
	 *   1. Intl with the configured IANA name. Correct, including DST.
	 *   2. Plain arithmetic against the offset the server computed this
	 *      request. WordPress lets a site store its timezone as a raw UTC
	 *      offset ("+05:30"), which Intl rejects with a RangeError — and the
	 *      obvious catch block would then silently fall through to the
	 *      visitor's own clock, computing every badge in the wrong timezone
	 *      with nothing anywhere to say so.
	 *   3. The visitor's clock, with a warning. Only when both of the above are
	 *      unavailable.
	 */
	function siteNow( config ) {
		var now = new Date();

		if ( window.Intl && IANA_RE.test( String( config.tz || '' ) ) ) {
			try {
				return fromParts(
					new Intl.DateTimeFormat( 'en-US', {
						timeZone: config.tz,
						year: 'numeric', month: '2-digit', day: '2-digit',
						hour: '2-digit', minute: '2-digit', hour12: false,
						weekday: 'short'
					} ).formatToParts( now )
				);
			} catch ( e ) {
				// Fall through to tier 2.
			}
		}

		if ( 'number' === typeof config.tzOffset ) {
			var shifted = new Date( now.getTime() + ( config.tzOffset + now.getTimezoneOffset() ) * 60000 );
			return describe( shifted );
		}

		if ( ! tzWarned && window.console ) {
			tzWarned = true;
			window.console.warn(
				'Location Finder: falling back to the visitor timezone; opening-hours badges may be wrong.'
			);
		}
		return describe( now );
	}

	function fromParts( parts ) {
		var map = {};
		parts.forEach( function ( part ) {
			map[ part.type ] = part.value;
		} );
		var stamp = new Date(
			Number( map.year ),
			Number( map.month ) - 1,
			Number( map.day ),
			Number( map.hour ) % 24,
			Number( map.minute )
		);
		return describe( stamp );
	}

	function describe( date ) {
		var day = date.getDate();
		var dow = date.getDay(); // 0 = Sunday
		var lastOfMonth = new Date( date.getFullYear(), date.getMonth() + 1, 0 ).getDate();

		return {
			// Canonical 1-7 with Monday first, matching how slots are stored.
			dow: 0 === dow ? 7 : dow,
			nth: Math.floor( ( day - 1 ) / 7 ) + 1,
			isLast: day + 7 > lastOfMonth,
			minutes: date.getHours() * 60 + date.getMinutes(),
			ymd:
				date.getFullYear() +
				'-' + String( date.getMonth() + 1 ).padStart( 2, '0' ) +
				'-' + String( date.getDate() ).padStart( 2, '0' )
		};
	}

	function minutesOf( time ) {
		var match = /^(\d{2}):(\d{2})$/.exec( String( time || '' ) );
		return match ? Number( match[ 1 ] ) * 60 + Number( match[ 2 ] ) : null;
	}

	function slotIsToday( slot, now ) {
		if ( Number( slot.day ) !== now.dow ) {
			return false;
		}
		if ( 'weekly' === slot.freq ) {
			return true;
		}
		if ( 'last' === slot.freq ) {
			return now.isLast;
		}
		return parseInt( slot.freq, 10 ) === now.nth;
	}

	/* ── Distance ────────────────────────────────────────────────────────── */

	function haversine( a, b ) {
		var toRad = Math.PI / 180;
		var dLat = ( b.lat - a.lat ) * toRad;
		var dLng = ( b.lng - a.lng ) * toRad;
		var h =
			Math.sin( dLat / 2 ) * Math.sin( dLat / 2 ) +
			Math.cos( a.lat * toRad ) * Math.cos( b.lat * toRad ) *
			Math.sin( dLng / 2 ) * Math.sin( dLng / 2 );
		return 2 * EARTH_RADIUS_KM * Math.asin( Math.min( 1, Math.sqrt( h ) ) );
	}

	/* ── One finder instance ─────────────────────────────────────────────── */

	function Finder( root, data ) {
		this.root = root;
		this.schema = data.schema;
		this.config = data.config;
		this.facets = data.facets || [];
		this.locations = data.locations || [];

		this.state = {
			query: '',
			filters: {},
			selected: null,
			sort: 'name'
		};

		/* ── THE SEAM ────────────────────────────────────────────────────────
		 * Everything about distance — the chip on a card, the sort order, the
		 * "you are here" dot, the button's label — reads this one object and
		 * nothing else.
		 *
		 * Version one only ever fills it from navigator.geolocation. A typed ZIP
		 * or address search forward-geocodes through the same admin-ajax proxy
		 * the editor already uses and calls setOrigin(lat, lng, '35203',
		 * 'query'). Nothing downstream changes.
		 * ─────────────────────────────────────────────────────────────────── */
		this.origin = null;

		this.map = null;
		this.markers = [];
		this.originLayer = null;

		this.init();
	}

	Finder.prototype.init = function () {
		this.root.classList.remove( 'lfndr--no-js' );
		this.root.classList.add( 'lfndr--ready' );

		this.panel = this.root.querySelector( '.lfndr__panel' );
		this.countEl = this.root.querySelector( '.lfndr__count' );
		this.results = this.root.querySelector( '.lfndr__results' );
		this.searchInput = this.root.querySelector( '.lfndr__search-input' );
		this.suggestList = this.root.querySelector( '.lfndr__suggest' );
		this.filtersToggle = this.root.querySelector( '.lfndr__filters-toggle' );
		this.filtersBody = this.root.querySelector( '.lfndr__filters-body' );
		this.resetButton = this.root.querySelector( '.lfndr__reset' );

		this.bindSearch();
		this.bindFilters();
		this.bindFiltersToggle();
		this.buildFilterActions();
		this.bindResults();
		this.buildLocateStatus();
		this.buildLocateButton();
		this.buildMaximizeControl();

		if ( this.config.map ) {
			this.initMap();
		}

		/* The filter panel is rendered open, which is correct with no JavaScript
		 * and correct in the wide layout. In the narrow one it pushes the
		 * results off the screen, so it starts closed there.
		 *
		 * Measured from the finder's own width, not the viewport's — the same
		 * thing the container query in the stylesheet measures. A finder in a
		 * 600px column on a 1400px screen is in its narrow layout, and deciding
		 * that from window.innerWidth gets it exactly backwards.
		 *
		 * Decided once, at boot, and never revisited: reacting to resize would
		 * mean reopening a panel the visitor deliberately closed. */
		if ( this.isNarrow() ) {
			this.setFiltersOpen( false );
		}

		this.update();

		var self = this;
		window.addEventListener( 'resize', function () {
			self.syncFilterActions();
			self.measureScrollbar();
		} );

		if ( this.config.nearMe && this.config.autoLocate ) {
			this.locate();
		}
	};

	/* ── Filters disclosure ──────────────────────────────────────────────── */

	/**
	 * A real button rather than <details>/<summary> — see the comment beside
	 * its markup in render.php for why. That trades away the browser's free
	 * toggle behavior, so this and bindFiltersToggle() below put it back by
	 * hand: aria-expanded, the hidden attribute, and Escape-to-close.
	 *
	 * @param {boolean} open Whether the panel should be visible.
	 */
	Finder.prototype.setFiltersOpen = function ( open ) {
		if ( ! this.filtersToggle || ! this.filtersBody ) {
			return;
		}
		this.filtersToggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		this.filtersBody.hidden = ! open;
	};

	/**
	 * The Apply / Clear row at the foot of the filter panel.
	 *
	 * Apply applies nothing — filtering is live, and every chip has already
	 * taken effect by the time this is pressed. It is here because on a narrow
	 * screen the panel covers the results, so the useful action at the end of
	 * choosing filters is "show me", and the control that does that is a close
	 * button wearing the name of the thing the visitor thinks they are doing.
	 * The reference finder this one is modelled on shipped exactly that, and
	 * for the same reason.
	 *
	 * Wide screens do not get it: the panel sits above the results rather than
	 * over them, so there is nothing to dismiss and a button that only closed a
	 * panel nobody needed closed would be a puzzle.
	 */
	Finder.prototype.buildFilterActions = function () {
		var self = this;
		if ( ! this.filtersBody || ! this.filtersToggle ) {
			return;
		}

		this.applyButton = el( 'button', {
			type: 'button',
			class: 'lfndr__apply',
			text: __( 'Apply filters', 'groundwork-common-location-finder' )
		} );
		this.applyButton.addEventListener( 'click', function () {
			self.setFiltersOpen( false );
			// Focus goes back to the control that opened the panel, never left on
			// a button that has just been hidden.
			self.filtersToggle.focus();
		} );

		this.filterActions = el( 'div', { class: 'lfndr__filters-actions' }, [ this.applyButton ] );

		// Clear filters moves in beside it as the secondary action rather than
		// staying loose among the facet groups.
		if ( this.resetButton ) {
			this.filterActions.appendChild( this.resetButton );
		}
		this.filtersBody.appendChild( this.filterActions );
	};

	/**
	 * Apply only exists on a narrow finder, and the row itself is only worth
	 * showing when something is in it — on a wide screen with no filters chosen,
	 * both children are gone and an empty row would still take its share of the
	 * panel's gap.
	 */
	Finder.prototype.syncFilterActions = function () {
		if ( ! this.filterActions ) {
			return;
		}
		var narrow = this.isNarrow();
		this.applyButton.hidden = ! narrow;
		this.filterActions.hidden = ! narrow
			&& ( ! this.resetButton || this.resetButton.hidden );
	};

	Finder.prototype.bindFiltersToggle = function () {
		var self = this;
		if ( ! this.filtersToggle || ! this.filtersBody ) {
			return;
		}

		this.filtersToggle.addEventListener( 'click', function () {
			self.setFiltersOpen( 'true' !== self.filtersToggle.getAttribute( 'aria-expanded' ) );
		} );

		this.filtersBody.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				self.setFiltersOpen( false );
				self.filtersToggle.focus();
			}
		} );

		/* Narrow, the panel is a popover floating over the results, and a popover
		 * that only closes from its own controls is a trap — dismissing by
		 * clicking away is the behavior every one of them has. Wide it is an
		 * inline region with no toggle at all, so there is nothing to dismiss and
		 * this stands down.
		 *
		 * pointerdown rather than click: a click that begins inside the panel and
		 * ends outside it — a drag off a chip, a text selection — fires click on
		 * the common ancestor and would shut the panel on someone who never left
		 * it. Focus is not returned to the toggle here, deliberately: the visitor
		 * is pointing at something else and moving their focus somewhere they did
		 * not ask for is its own rudeness. */
		this._dismissFilters = function ( event ) {
			if ( ! self.isNarrow() ) {
				return;
			}
			if ( 'true' !== self.filtersToggle.getAttribute( 'aria-expanded' ) ) {
				return;
			}
			if ( self.filtersBody.contains( event.target ) || self.filtersToggle.contains( event.target ) ) {
				return;
			}
			self.setFiltersOpen( false );
		};
		document.addEventListener( 'pointerdown', this._dismissFilters );
	};

	/* ── Locate status ────────────────────────────────────────────────────────
	 * There is no "sort by distance" button any more. The origin seam below is
	 * still live and still the only thing distance reads from — it is now
	 * populated at boot, when Locations → Settings has auto-locate on, rather
	 * than by a control in the filter panel.
	 *
	 * The status line outlives the button on purpose. Geolocation fails in ways
	 * worth saying out loud — permission denied, position unavailable, an
	 * insecure origin — and with auto-locate on, that failure happens with no
	 * control anywhere for the message to hang off. A live region is what keeps
	 * it from being silent.
	 * ─────────────────────────────────────────────────────────────────────── */

	Finder.prototype.buildLocateStatus = function () {
		if ( ! this.config.nearMe || ! navigator.geolocation ) {
			return;
		}
		this.locateStatus = el( 'p', { class: 'lfndr__locate-status', role: 'status' } );
		this.panel.insertBefore( this.locateStatus, this.countEl );
	};

	/* ── Full screen ──────────────────────────────────────────────────────────
	 * On a desktop-width finder, maximizing gives the existing two-column
	 * layout a whole viewport instead of a content column — the container query
	 * in the stylesheet already knows how to lay that out, so nothing else has
	 * to change.
	 *
	 * On a phone it is the stylesheet's job too: the controls row and a short
	 * map pin themselves to the top of the viewport and the result list scrolls
	 * underneath them. See the maximized block in location-finder.css.
	 *
	 * "Narrow" is measured from the finder's own box, matching the container
	 * query in the CSS and the filter-panel decision above — never from the
	 * viewport, which gets it backwards for a finder embedded in a narrow
	 * column on an otherwise wide screen.
	 * ─────────────────────────────────────────────────────────────────────── */

	Finder.prototype.isNarrow = function () {
		return this.root.getBoundingClientRect().width < BREAKPOINT;
	};

	/* ── Locate, when there is no map to put it on ────────────────────────────
	 * The locate control normally lives in the map's top-right corner, which is
	 * where people look for it. With show_map="no" there is no map, so near-me
	 * could be switched on and have no affordance anywhere — the setting said
	 * yes, the finder offered nothing, and the only clue was a sentence in the
	 * help text.
	 *
	 * So the control falls back to the controls row, beside Filters and Full
	 * screen. Sorting by distance is if anything more useful without a map: a
	 * plain list has no other way to show what is closest.
	 *
	 * Only one is ever built. addLocateControl() is called from initMap(), which
	 * does not run without a map, and this returns early when there is one.
	 * ─────────────────────────────────────────────────────────────────────── */

	Finder.prototype.buildLocateButton = function () {
		var self = this;

		if ( this.config.map || ! this.config.nearMe || ! navigator.geolocation ) {
			return;
		}

		var button = el(
			'button',
			{
				type: 'button',
				class: 'lfndr__locate-inline',
				'aria-label': __( 'Show my location', 'groundwork-common-location-finder' )
			},
			[
				icon( 'locate' ),
				el( 'span', {
					class: 'lfndr__button-label',
					text: __( 'Near me', 'groundwork-common-location-finder' )
				} )
			]
		);

		button.addEventListener( 'click', function () {
			self.locate();
		} );

		/* Same element setLocateBusy() disables while a fix is awaited, so a
		 * second press cannot start a second request. */
		this.locateButton = button;

		var controls = this.root.querySelector( '.lfndr__controls' );
		if ( controls ) {
			controls.appendChild( button );
		} else {
			this.root.insertBefore(
				el( 'div', { class: 'lfndr__controls lfndr__controls--bare' }, [ button ] ),
				this.root.firstChild
			);
		}
	};

	Finder.prototype.buildMaximizeControl = function () {
		var self = this;

		this.maximizeIcon = icon( 'expand' );
		this.maximizeLabel = el( 'span', {
			class: 'lfndr__button-label',
			text: __( 'Full screen', 'groundwork-common-location-finder' )
		} );

		this.maximizeButton = el(
			'button',
			{ type: 'button', class: 'lfndr__maximize', 'aria-pressed': 'false' },
			[ this.maximizeIcon, this.maximizeLabel ]
		);
		this.maximizeButton.addEventListener( 'click', function () {
			if ( self.root.classList.contains( 'lfndr--maximized' ) ) {
				self.exitMaximize();
			} else {
				self.enterMaximize();
			}
		} );

		/* Last in the controls row, after the search box and the Filters
		 * button — on a narrow finder those three are the whole header, and
		 * they need to be siblings for the stylesheet to lay them out as one
		 * line. A finder with neither a search box nor filters renders no
		 * controls row at all, so it falls back to a row of its own rather
		 * than losing the only way to expand. */
		var controls = this.root.querySelector( '.lfndr__controls' );
		if ( controls ) {
			controls.appendChild( this.maximizeButton );
		} else {
			this.root.insertBefore(
				el( 'div', { class: 'lfndr__controls lfndr__controls--bare' }, [ this.maximizeButton ] ),
				this.root.firstChild
			);
		}
	};

	/**
	 * Swap the button between its two states. The icon and the label change
	 * together — on a narrow screen only the icon is visible and on a wide one
	 * only the label carries meaning, so neither may be left saying the wrong
	 * thing.
	 *
	 * @param {boolean} maximized Whether the finder is now full screen.
	 */
	/* Everything inside `root` that a Tab can reach right now.
	 *
	 * Queried on every Tab rather than cached, because the finder rebuilds its
	 * results, chips and detail pane constantly — a list captured on entering
	 * full screen would be stale by the first keystroke and would trap focus on
	 * buttons that no longer exist. */
	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]),'
		+ ' select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	function focusableWithin( root ) {
		return Array.prototype.filter.call(
			root.querySelectorAll( FOCUSABLE ),
			function ( node ) {
				/* Hidden things still match the selector. offsetParent misses
				 * position:fixed elements, so fall back to client rects. */
				return node.offsetWidth || node.offsetHeight || node.getClientRects().length;
			}
		);
	}

	Finder.prototype.setMaximizeButton = function ( maximized ) {
		var next = icon( maximized ? 'collapse' : 'expand' );

		this.maximizeButton.setAttribute( 'aria-pressed', maximized ? 'true' : 'false' );
		this.maximizeLabel.textContent = maximized
			? __( 'Exit full screen', 'groundwork-common-location-finder' )
			: __( 'Full screen', 'groundwork-common-location-finder' );

		if ( next && this.maximizeIcon ) {
			this.maximizeButton.replaceChild( next, this.maximizeIcon );
			this.maximizeIcon = next;
		}
	};

	Finder.prototype.enterMaximize = function () {
		var self = this;

		this.root.classList.add( 'lfndr--maximized' );
		// Locking the whole document, not just the finder, is the one place
		// this plugin reaches outside its own root element — deliberately: a
		// full-screen takeover that still lets the page scroll behind it reads
		// as broken, the same way any lightbox or modal would.
		document.documentElement.classList.add( 'lfndr-scroll-lock' );

		this.setMaximizeButton( true );

		/* Full screen is a modal in every respect that matters: it covers the
		 * page, the page cannot be scrolled behind it, and Escape closes it. So
		 * it is announced as one. Without aria-modal a screen reader keeps
		 * offering the whole document underneath, and the user browses a page
		 * they cannot see or reach.
		 *
		 * The role is swapped rather than set once, because outside full screen
		 * this element is a region (or nothing at all) — a permanent dialog role
		 * on an inline widget would be a lie the rest of the time. */
		this._prevRole = this.root.getAttribute( 'role' );
		this.root.setAttribute( 'role', 'dialog' );
		this.root.setAttribute( 'aria-modal', 'true' );

		/* A dialog with no accessible name announces as just "dialog". If the
		 * site gave the finder a name it is already on the element and is the
		 * better one; this only fills the gap. */
		if ( ! this.root.getAttribute( 'aria-label' ) && ! this.root.getAttribute( 'aria-labelledby' ) ) {
			this.root.setAttribute( 'aria-label', __( 'Location finder', 'groundwork-common-location-finder' ) );
			this._borrowedLabel = true;
		}

		this._escHandler = this._escHandler || function ( event ) {
			if ( 'Escape' === event.key ) {
				self.exitMaximize();
			}
		};
		document.addEventListener( 'keydown', this._escHandler );

		/* The trap. aria-modal tells assistive tech to stay inside; it does
		 * nothing about the Tab key, so without this a keyboard user tabs
		 * straight out of the overlay and into a page hidden behind it, with no
		 * visible focus ring and no way to tell where they have gone.
		 *
		 * Capture phase, so it runs before anything inside the finder can act
		 * on the same Tab. */
		this._trapHandler = this._trapHandler || function ( event ) {
			if ( 'Tab' !== event.key ) {
				return;
			}

			var items = focusableWithin( self.root );
			if ( ! items.length ) {
				return;
			}

			var first = items[ 0 ];
			var last = items[ items.length - 1 ];
			var active = document.activeElement;

			/* Focus escaped the overlay some other way — a click on the page
			 * behind, or a control that removed itself. Pull it back rather
			 * than letting the next Tab continue from out there. */
			if ( ! self.root.contains( active ) ) {
				event.preventDefault();
				first.focus();
				return;
			}

			if ( event.shiftKey && active === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && active === last ) {
				event.preventDefault();
				first.focus();
			}
		};
		document.addEventListener( 'keydown', this._trapHandler, true );

		/* Entering and leaving full screen is the one layout change that is not
		 * a resize and not a re-render, so neither of the other two callers
		 * covers it — and on a narrow screen it is the change that matters
		 * most, because the result list only becomes a scroll container at all
		 * once the finder owns the viewport. Left out, the scroll fade dissolves
		 * a scrollbar the panel did not have when it was last measured. */
		this.measureScrollbar();
		this.invalidateMapSize();
	};

	Finder.prototype.exitMaximize = function () {
		this.root.classList.remove( 'lfndr--maximized' );
		document.documentElement.classList.remove( 'lfndr-scroll-lock' );

		this.setMaximizeButton( false );

		if ( this._escHandler ) {
			document.removeEventListener( 'keydown', this._escHandler );
		}

		if ( this._trapHandler ) {
			document.removeEventListener( 'keydown', this._trapHandler, true );
		}

		/* Put the element back exactly as it was found. Leaving role="dialog"
		 * on an inline widget would misreport it for the rest of the page's
		 * life, and leaving the borrowed label would overwrite a name the site
		 * never asked for. */
		if ( this._prevRole ) {
			this.root.setAttribute( 'role', this._prevRole );
		} else {
			this.root.removeAttribute( 'role' );
		}
		this.root.removeAttribute( 'aria-modal' );

		if ( this._borrowedLabel ) {
			this.root.removeAttribute( 'aria-label' );
			this._borrowedLabel = false;
		}

		this.measureScrollbar();
		this.invalidateMapSize();

		// Focus stays with the control that triggered the change, same as
		// every chip and toggle elsewhere in the finder — never left to fall
		// back to <body>.
		this.maximizeButton.focus();
	};

	/**
	 * Leaflet lays out tiles for the container size at the moment it was
	 * created or last told to look again; without this call the map keeps the
	 * old (wrong) size until the next pan or zoom, which on entering or leaving
	 * full screen is a map that is visibly cut off or full of gray tiles.
	 */
	Finder.prototype.invalidateMapSize = function () {
		var map = this.map;
		if ( ! map ) {
			return;
		}
		window.setTimeout( function () {
			map.invalidateSize();
		}, 0 );
	};

	/* The map button, while a fix is being waited for.
	 *
	 * getCurrentPosition can sit for the full ten-second timeout with nothing
	 * on screen changing, and a button that looks idle invites a second press
	 * that starts a second request. The status line says so too, but it lives
	 * beside the results and the press happened on the map.
	 *
	 * Guarded because the control only exists when there is a map and near-me
	 * is on; auto-locate at boot runs this path with no button at all.
	 *
	 * @param {boolean} busy Whether a fix is currently being awaited.
	 */
	Finder.prototype.setLocateBusy = function ( busy ) {
		if ( ! this.locateButton ) {
			return;
		}
		this.locateButton.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		this.locateButton.disabled = !! busy;
	};

	Finder.prototype.locate = function () {
		var self = this;

		// Chrome and Firefox expose navigator.geolocation over plain http but
		// fail every call, so feature detection alone reports the wrong reason
		// and the user is left retrying a permission they never denied.
		if ( window.isSecureContext === false ) {
			this.say( __( 'Location needs a secure (https) connection.', 'groundwork-common-location-finder' ) );
			return;
		}

		this.say( __( 'Finding your location…', 'groundwork-common-location-finder' ) );
		this.setLocateBusy( true );

		navigator.geolocation.getCurrentPosition(
			function ( position ) {
				self.setLocateBusy( false );
				self.setOrigin(
					position.coords.latitude,
					position.coords.longitude,
					__( 'your location', 'groundwork-common-location-finder' ),
					'geolocation',
					position.coords.accuracy
				);
			},
			function ( error ) {
				self.setLocateBusy( false );

				// Three different problems with three different next steps.
				// "Geolocation error" helps nobody.
				var message;
				switch ( error.code ) {
					case error.PERMISSION_DENIED:
						message = __( 'Location access was blocked. You can still search by name.', 'groundwork-common-location-finder' );
						break;
					case error.POSITION_UNAVAILABLE:
						message = __( 'Your location could not be determined right now.', 'groundwork-common-location-finder' );
						break;
					case error.TIMEOUT:
						message = __( 'Finding your location took too long. Try again.', 'groundwork-common-location-finder' );
						break;
					default:
						message = __( 'Your location is unavailable.', 'groundwork-common-location-finder' );
				}
				self.say( message );
			},
			{ enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 }
		);
	};

	Finder.prototype.setOrigin = function ( lat, lng, label, source, accuracy ) {
		this.origin = { lat: lat, lng: lng, label: label, source: source, accuracy: accuracy || 0 };
		this.state.sort = 'distance';

		this.say( __( 'Sorted by distance from you.', 'groundwork-common-location-finder' ) );

		this.drawOrigin();
		this.update();
	};

	Finder.prototype.clearOrigin = function () {
		this.origin = null;
		this.state.sort = 'name';

		this.say( '' );

		if ( this.originLayer && this.map ) {
			this.map.removeLayer( this.originLayer );
			this.originLayer = null;
		}
		this.update();
	};

	Finder.prototype.say = function ( message ) {
		if ( this.locateStatus ) {
			this.locateStatus.textContent = message;
		}
	};

	Finder.prototype.drawOrigin = function () {
		if ( ! this.map || ! this.origin ) {
			return;
		}

		if ( this.originLayer ) {
			this.map.removeLayer( this.originLayer );
		}

		// Its own layer, never a member of this.markers — renderMap() clears
		// that array on every filter change, which would take the "you are
		// here" dot with it the first time somebody touched a chip.
		this.originLayer = window.L.layerGroup().addTo( this.map );

		var here = [ this.origin.lat, this.origin.lng ];
		window.L.circleMarker( here, {
			radius: 6,
			className: 'lfndr-origin',
			interactive: false
		} ).addTo( this.originLayer );

		if ( this.origin.accuracy > 0 ) {
			window.L.circle( here, {
				radius: this.origin.accuracy,
				className: 'lfndr-origin__accuracy',
				interactive: false
			} ).addTo( this.originLayer );
		}

		if ( this.origin.accuracy > COARSE_FIX_METRES ) {
			this.map.fitBounds(
				window.L.circle( here, { radius: this.origin.accuracy } ).getBounds(),
				{ padding: [ 20, 20 ] }
			);
		} else {
			this.map.setView( here, Math.min( this.config.maxZoom || 19, DETAIL_ZOOM ) );
		}
	};

	/* ── Search ──────────────────────────────────────────────────────────── */

	Finder.prototype.bindSearch = function () {
		if ( ! this.searchInput ) {
			return;
		}
		var self = this;
		var active = -1;

		this.searchInput.addEventListener( 'input', function () {
			self.state.query = self.searchInput.value.trim().toLowerCase();
			active = -1;
			self.renderSuggestions();
			self.update();
		} );

		this.searchInput.addEventListener( 'keydown', function ( event ) {
			var options = self.suggestList
				? self.suggestList.querySelectorAll( '[role="option"]' )
				: [];
			if ( ! options.length ) {
				if ( 'Escape' === event.key ) {
					self.searchInput.value = '';
					self.state.query = '';
					self.update();
				}
				return;
			}

			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				active = ( active + 1 ) % options.length;
			} else if ( 'ArrowUp' === event.key ) {
				event.preventDefault();
				active = active <= 0 ? options.length - 1 : active - 1;
			} else if ( 'Enter' === event.key && active > -1 ) {
				event.preventDefault();
				options[ active ].click();
				return;
			} else if ( 'Escape' === event.key ) {
				self.closeSuggestions();
				return;
			} else {
				return;
			}

			Array.prototype.forEach.call( options, function ( option, i ) {
				option.setAttribute( 'aria-selected', i === active ? 'true' : 'false' );
				option.classList.toggle( 'is-active', i === active );
			} );
			if ( options[ active ] ) {
				self.searchInput.setAttribute( 'aria-activedescendant', options[ active ].id );
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( ! self.root.contains( event.target ) ) {
				self.closeSuggestions();
			}
		} );
	};

	Finder.prototype.closeSuggestions = function () {
		if ( ! this.suggestList ) {
			return;
		}
		this.suggestList.hidden = true;
		this.suggestList.textContent = '';
		this.searchInput.setAttribute( 'aria-expanded', 'false' );
		this.searchInput.removeAttribute( 'aria-activedescendant' );
	};

	/**
	 * Suggestions come from location names and from the distinct values of
	 * whichever choice fields the site marked searchable — nothing is hardcoded
	 * about what a suggestion can be.
	 */
	Finder.prototype.suggestionPool = function () {
		if ( this.pool ) {
			return this.pool;
		}

		var seen = {};
		var pool = [];
		var fields = this.schema.fields;
		var self = this;

		this.locations.forEach( function ( location ) {
			var nameKey = 'n:' + location.name.toLowerCase();
			if ( ! seen[ nameKey ] ) {
				seen[ nameKey ] = true;
				pool.push( { label: location.name, kind: __( 'Location', 'groundwork-common-location-finder' ), id: location.id } );
			}

			Object.keys( location.f || {} ).forEach( function ( key ) {
				var field = fields[ key ];
				if ( ! field ) {
					return;
				}

				var values = [];
				if ( 'select' === field.type || 'multiselect' === field.type ) {
					values = [].concat( location.f[ key ] );
				} else if ( 'address' === field.type && location.f[ key ].city ) {
					values = [ location.f[ key ].city ];
				}

				values.forEach( function ( value ) {
					var label = ( field.options && field.options[ value ] ) || value;
					var poolKey = key + ':' + label;
					if ( label && ! seen[ poolKey ] ) {
						seen[ poolKey ] = true;
						pool.push( { label: String( label ), kind: field.label } );
					}
				} );
			} );
		} );

		this.pool = pool;
		return self.pool;
	};

	Finder.prototype.renderSuggestions = function () {
		if ( ! this.suggestList ) {
			return;
		}

		var query = this.state.query;
		if ( query.length < SUGGEST_MIN_CHARS ) {
			this.closeSuggestions();
			return;
		}

		var matches = this.suggestionPool()
			.filter( function ( entry ) {
				return entry.label.toLowerCase().indexOf( query ) !== -1;
			} )
			.slice( 0, SUGGEST_LIMIT );

		if ( ! matches.length ) {
			this.closeSuggestions();
			return;
		}

		var self = this;
		this.suggestList.textContent = '';

		matches.forEach( function ( entry, i ) {
			var option = el(
				'li',
				{
					role: 'option',
					id: self.root.id + '-suggest-' + i,
					'aria-selected': 'false',
					class: 'lfndr__suggest-item'
				},
				[
					el( 'span', { class: 'lfndr__suggest-label', text: entry.label } ),
					el( 'span', { class: 'lfndr__suggest-kind', text: entry.kind } )
				]
			);
			option.addEventListener( 'click', function () {
				self.searchInput.value = entry.label;
				self.state.query = entry.label.toLowerCase();
				self.closeSuggestions();
				self.update();
				if ( entry.id ) {
					self.select( entry.id );
				}
			} );
			self.suggestList.appendChild( option );
		} );

		this.suggestList.hidden = false;
		this.searchInput.setAttribute( 'aria-expanded', 'true' );
	};

	/* ── Filters ─────────────────────────────────────────────────────────── */

	Finder.prototype.bindFilters = function () {
		var self = this;

		this.root.querySelectorAll( '.lfndr__facet' ).forEach( function ( facet ) {
			var key = facet.getAttribute( 'data-facet' );

			facet.querySelectorAll( '.lfndr__chip' ).forEach( function ( chip ) {
				chip.addEventListener( 'click', function () {
					var value = chip.getAttribute( 'data-value' );
					var pressed = 'true' === chip.getAttribute( 'aria-pressed' );
					chip.setAttribute( 'aria-pressed', pressed ? 'false' : 'true' );

					var current = self.state.filters[ key ] || [];
					self.state.filters[ key ] = pressed
						? current.filter( function ( v ) { return v !== value; } )
						: current.concat( [ value ] );

					self.update();
				} );
			} );

			var select = facet.querySelector( '.lfndr__facet-select' );
			if ( select ) {
				select.addEventListener( 'change', function () {
					self.state.filters[ key ] = select.value ? [ select.value ] : [];
					self.update();
				} );
			}
		} );

		if ( this.resetButton ) {
			this.resetButton.addEventListener( 'click', function () {
				self.state.filters = {};
				self.root.querySelectorAll( '.lfndr__chip' ).forEach( function ( chip ) {
					chip.setAttribute( 'aria-pressed', 'false' );
				} );
				self.root.querySelectorAll( '.lfndr__facet-select' ).forEach( function ( select ) {
					select.value = '';
				} );
				self.update();

				/* Clearing is finishing with the panel, the same as applying, so on a
				 * narrow finder it closes too — there the panel is covering the results
				 * it just changed, and leaving it open hides the only evidence the
				 * button did anything. A wide finder keeps it open: the panel sits above
				 * the results rather than over them, and closing it would take away the
				 * filters someone is plainly still working with. */
				if ( self.isNarrow() ) {
					self.setFiltersOpen( false );
				}

				/* Focus has to move regardless of which of those happened: update() has
				 * just hidden this button — there are no filters left to clear — and a
				 * focused element that disappears drops the caret to <body>, which for a
				 * keyboard user means starting again from the top of the page. */
				if ( self.filtersToggle ) {
					self.filtersToggle.focus();
				}
			} );
		}
	};

	Finder.prototype.matchesFilters = function ( location ) {
		var self = this;

		return this.facets.every( function ( group ) {
			var chosen = self.state.filters[ group.key ] || [];
			if ( ! chosen.length ) {
				return true;
			}

			if ( 'hours' === group.type ) {
				// 'today' or 'now'. Neither '' (no schedule today, or gated out
				// by an appointment-only value) nor 'closed' (a closure is in
				// force) counts as open.
				var status = self.openStatus( location );
				return 'today' === status || 'now' === status;
			}

			var tokens = ( location.facets && location.facets[ group.key ] ) || [];

			// AND within a multi-choice field ("offers both of these"); OR
			// within a single-choice one, whose values are mutually exclusive
			// and where AND would always return nothing.
			return 'all' === group.match
				? chosen.every( function ( value ) { return tokens.indexOf( value ) !== -1; } )
				: chosen.some( function ( value ) { return tokens.indexOf( value ) !== -1; } );
		} );
	};

	/* ── Open now / closures ─────────────────────────────────────────────── */

	Finder.prototype.primaryHours = function ( location ) {
		var key = this.config.primary && this.config.primary.hours;
		return key && location.f ? location.f[ key ] : null;
	};

	/* ── Closures: the rows, and the role ─────────────────────────────────────
	 * Two questions that look like one. "Is this location closed right now" is
	 * about the closures field holding the site's role, because that is what the
	 * badge and the hours are keyed to. "What does this closures field say" is
	 * about the field being rendered, whichever it is — a location may carry
	 * more than one list, and every one of them displays.
	 *
	 * They used to be the same function, which meant a closures field that did
	 * not hold the role rendered nothing at all: the renderer asked what the
	 * primary field said, got rows belonging to a different field or none, and
	 * returned null. Display follows the field; behavior follows the role.
	 * ─────────────────────────────────────────────────────────────────────── */

	/**
	 * The closure in force in a given set of rows, if any.
	 *
	 * @param {Array} rows Closure rows.
	 * @return {Object|null}
	 */
	Finder.prototype.activeClosureIn = function ( rows ) {
		if ( ! rows || ! rows.length ) {
			return null;
		}
		var today = this.now.ymd;
		for ( var i = 0; i < rows.length; i++ ) {
			if ( rows[ i ].start <= today && today <= rows[ i ].end ) {
				return rows[ i ];
			}
		}
		return null;
	};

	/**
	 * The next closure inside a field's warning window, if any.
	 *
	 * @param {Array}  rows  Closure rows.
	 * @param {Object} field The field those rows belong to.
	 * @return {Object|null}
	 */
	Finder.prototype.upcomingClosureIn = function ( rows, field ) {
		if ( ! rows || ! rows.length ) {
			return null;
		}
		var days = field && field.settings && 'number' === typeof field.settings.lookahead_days
			? field.settings.lookahead_days
			: CLOSURE_LOOKAHEAD_FALLBACK;
		if ( days < 1 ) {
			return null;
		}

		var limit = new Date( this.now.ymd + 'T00:00:00' );
		limit.setDate( limit.getDate() + days );
		var limitYmd = limit.toISOString().slice( 0, 10 );

		for ( var i = 0; i < rows.length; i++ ) {
			if ( rows[ i ].start > this.now.ymd && rows[ i ].start <= limitYmd ) {
				return rows[ i ];
			}
		}
		return null;
	};

	/**
	 * The closure in force on the field holding the site's closures role.
	 *
	 * @param {Object} location Payload location.
	 * @return {Object|null}
	 */
	Finder.prototype.activeClosure = function ( location ) {
		var key = this.config.primary && this.config.primary.closures;
		return this.activeClosureIn( key && location.f ? location.f[ key ] : null );
	};

	/**
	 * Does an active closure suspend the schedule the status is read from?
	 *
	 * The closures field holding the site's closures role suspends the hours
	 * field holding the hours role — that pairing is assigned once, on the
	 * Fields screen, instead of each closures field naming its own target. A
	 * closure that pointed at some other schedule struck it through while the
	 * badge went on reading from a schedule nothing had closed, which is not a
	 * configuration worth being able to express.
	 *
	 * @param {Object} location Payload location.
	 * @return {boolean}
	 */
	Finder.prototype.hoursAreSuspended = function ( location ) {
		var primary = this.config.primary || {};
		if ( ! primary.hours || ! primary.closures ) {
			return false;
		}
		return !! this.activeClosure( location );
	};

	/**
	 * '' | 'today' | 'now' | 'closed'.
	 */
	Finder.prototype.openStatus = function ( location ) {
		/* Closed, but only when a closure suspends the schedule this status is
		 * read from. A site that lists closures without assigning them the role
		 * shows the notice and lets the hours go on meaning what they say. */
		if ( this.hoursAreSuspended( location ) ) {
			return 'closed';
		}

		// A listing that is appointment-only can have a perfect schedule and
		// still never be open to walk in. The gate names the field and value
		// that decide it, and it is why the "Open today" filter counts gated
		// locations rather than every location with hours.
		var gate = this.config.openGate;
		if ( gate && gate.field ) {
			var value = location.f ? location.f[ gate.field ] : null;
			if ( value !== gate.value ) {
				return '';
			}
		}

		var hours = this.primaryHours( location );
		if ( ! hours || ! hours.slots || ! hours.slots.length ) {
			return '';
		}

		var status = '';
		for ( var i = 0; i < hours.slots.length; i++ ) {
			var slot = hours.slots[ i ];
			if ( ! slotIsToday( slot, this.now ) ) {
				continue;
			}
			status = 'today';

			var from = minutesOf( slot.start );
			var to = minutesOf( slot.end );
			if ( null !== from && null !== to && this.now.minutes >= from && this.now.minutes < to ) {
				return 'now';
			}
		}
		return status;
	};

	/* ── Rendering ───────────────────────────────────────────────────────── */

	Finder.prototype.visible = function () {
		var self = this;
		var query = this.state.query;

		var list = this.locations.filter( function ( location ) {
			if ( query && location.search.indexOf( query ) === -1 ) {
				return false;
			}
			return self.matchesFilters( location );
		} );

		// Filter, then sort, then slice. Always in that order — a radius that
		// filters would turn "open today" plus "near me" into an empty list and
		// read as a broken page, which is why near-me is a sort here and never
		// a filter.
		var origin = this.origin;
		list.sort( function ( a, b ) {
			if ( origin ) {
				var da = self.distance( a );
				var db = self.distance( b );
				// A location with no coordinates sorts last rather than
				// appearing to be at 0°,0° in the Gulf of Guinea.
				if ( null === da && null === db ) {
					return a.name.localeCompare( b.name );
				}
				if ( null === da ) {
					return 1;
				}
				if ( null === db ) {
					return -1;
				}
				if ( da !== db ) {
					return da - db;
				}
			}
			return a.name.localeCompare( b.name );
		} );

		return list;
	};

	Finder.prototype.distance = function ( location ) {
		if ( ! this.origin || null === location.lat || null === location.lng ) {
			return null;
		}
		var km = haversine( this.origin, { lat: location.lat, lng: location.lng } );
		return 'mi' === this.config.units ? km / KM_PER_MILE : km;
	};

	Finder.prototype.update = function () {
		this.now = siteNow( this.config );

		var list = this.visible();
		var limit = this.config.pageSize > 0 ? this.config.pageSize : list.length;

		this.renderCount( list.length );
		this.renderList( list.slice( 0, limit ), list.length > limit );
		this.renderMap( list );

		if ( this.resetButton ) {
			var anyFilters = Object.keys( this.state.filters ).some( function ( key ) {
				return this.state.filters[ key ].length > 0;
			}, this );
			this.resetButton.hidden = ! anyFilters;
		}

		this.syncFilterActions();
		this.measureScrollbar();
	};

	/**
	 * Publish the width of the result panel's scrollbar as --lfndr-scrollbar.
	 *
	 * The stylesheet fades the top and bottom of the scrolling list with a
	 * mask, and a mask paints over the whole element box — the scrollbar
	 * included, which left it dissolving at both ends. CSS has no way to ask
	 * how wide a scrollbar is, so the mask cannot reserve a strip for it
	 * without being told, and guessing gets it wrong in both directions: a
	 * classic scrollbar is 15–17px depending on platform, and an overlay one
	 * (the macOS default, and any browser with them switched on) occupies no
	 * width at all, so a hardcoded strip would carve a permanent unfaded band
	 * out of the list for a scrollbar that was never there.
	 *
	 * Measured rather than assumed, and re-measured on every update, because
	 * filtering changes whether the list overflows at all.
	 */
	Finder.prototype.measureScrollbar = function () {
		if ( ! this.panel ) {
			return;
		}
		var width = this.panel.offsetWidth - this.panel.clientWidth;
		this.root.style.setProperty( '--lfndr-scrollbar', width + 'px' );
	};

	Finder.prototype.renderCount = function ( count ) {
		if ( ! this.countEl ) {
			return;
		}
		var template = 1 === count ? this.config.strings.countOne : this.config.strings.countMany;
		this.countEl.textContent = template.replace( '%s', String( count ) );
	};

	Finder.prototype.renderList = function ( list, truncated ) {
		if ( ! this.results ) {
			return;
		}

		this.results.textContent = '';

		if ( ! list.length ) {
			this.results.appendChild(
				el( 'li', { class: 'lfndr__empty', text: __( 'No locations match. Try removing a filter.', 'groundwork-common-location-finder' ) } )
			);
			return;
		}

		var self = this;
		list.forEach( function ( location ) {
			self.results.appendChild( self.card( location ) );
		} );

		if ( truncated ) {
			var more = el( 'button', {
				type: 'button',
				class: 'lfndr__more',
				text: __( 'Show all results', 'groundwork-common-location-finder' )
			} );
			more.addEventListener( 'click', function () {
				self.config.pageSize = 0;
				self.update();
			} );
			this.results.appendChild( el( 'li', { class: 'lfndr__more-row' }, [ more ] ) );
		}
	};

	Finder.prototype.card = function ( location ) {
		var self = this;

		var button = el( 'button', { type: 'button', class: 'lfndr-card__button' } );
		button.addEventListener( 'click', function () {
			self.select( location.id );
		} );

		// A card is an activator, so it is a real button: focus, Enter and Space
		// all come free, and the theme's own button styles apply.
		//
		// The name and the status share a line, the status pushed to the end of
		// it. Stacked, the status read as the first item of the card's content;
		// beside the name it reads as being about the name, which is what it is.
		button.appendChild(
			el( 'div', { class: 'lfndr-head' }, [
				el( 'h3', { class: 'lfndr-card__name', text: location.name } ),
				this.statusBadge( this.openStatus( location ), location, 'card' )
			] )
		);

		var distance = this.distance( location );
		if ( null !== distance ) {
			button.appendChild(
				el( 'p', {
					class: 'lfndr-card__distance',
					text: distance.toFixed( distance < 10 ? 1 : 0 ) + ' ' + this.config.strings.unit
				} )
			);
		}

		var fields = el( 'dl', { class: 'lfndr-card__fields' } );
		this.schema.cardOrder.forEach( function ( key ) {
			var row = self.fieldRow( location, key, 'card' );
			if ( row ) {
				fields.appendChild( row );
			}
		} );
		if ( fields.children.length ) {
			button.appendChild( fields );
		}

		var article = el( 'article', {
			class: 'lfndr-card' + ( this.state.selected === location.id ? ' is-selected' : '' )
		}, [ button ] );

		return el( 'li', { class: 'lfndr__result', 'data-id': location.id }, [ article ] );
	};

	/**
	 * Will a closure banner render for this location on this surface?
	 *
	 * The "Temporarily closed" pill is redundant wherever the banner appears —
	 * the banner says the same words and adds the dates and the reason. But the
	 * banner only exists if the site actually shows its closures field, and a
	 * site that hides it would otherwise lose every trace that a location is
	 * shut. So the pill is not deleted, it is stood down where something better
	 * is already saying it.
	 *
	 * @param {Object} location Payload location.
	 * @param {string} surface  'card' or 'detail'.
	 * @return {boolean} True when the banner covers it.
	 */
	Finder.prototype.showsClosureBanner = function ( location, surface ) {
		var key = this.config.primary && this.config.primary.closures;
		var field = key && this.schema.fields ? this.schema.fields[ key ] : null;
		if ( ! field ) {
			return false;
		}
		if ( ! ( 'card' === surface ? field.card : field.detail ) ) {
			return false;
		}
		var order = 'card' === surface ? this.schema.cardOrder : this.schema.detailOrder;
		if ( -1 === order.indexOf( key ) ) {
			return false;
		}
		return !! this.activeClosure( location );
	};

	/**
	 * The open/closed pill, or null when something else is already saying it.
	 *
	 * @param {string} status   'now', 'today' or 'closed'.
	 * @param {Object} location Payload location.
	 * @param {string} surface  'card' or 'detail'.
	 */
	Finder.prototype.statusBadge = function ( status, location, surface ) {
		if ( ! status ) {
			return null;
		}
		if ( 'closed' === status && this.showsClosureBanner( location, surface ) ) {
			return null;
		}
		return this.badge( status );
	};

	Finder.prototype.badge = function ( status ) {
		var labels = {
			now: __( 'Open now', 'groundwork-common-location-finder' ),
			today: __( 'Open today', 'groundwork-common-location-finder' ),
			closed: __( 'Temporarily closed', 'groundwork-common-location-finder' )
		};
		return el( 'p', {
			class: 'lfndr-badge lfndr-badge--' + status,
			text: labels[ status ] || ''
		} );
	};

	/**
	 * Render one entry of an order list — a real field or a synthetic one.
	 *
	 * Returns null when there is nothing to show, which is how both orders skip
	 * empty values without every caller having to check.
	 */
	Finder.prototype.fieldRow = function ( location, key, surface ) {
		if ( '__name' === key ) {
			// Rendered as the heading, not as a row.
			return null;
		}
		if ( '__distance' === key ) {
			return null;
		}
		if ( '__coords' === key ) {
			if ( null === location.lat ) {
				return null;
			}
			return this.row(
				__( 'Coordinates', 'groundwork-common-location-finder' ),
				document.createTextNode( location.lat.toFixed( 5 ) + ', ' + location.lng.toFixed( 5 ) ),
				'card' === surface
			);
		}
		if ( '__directions' === key ) {
			var url = this.directionsUrl( location );
			if ( ! url ) {
				return null;
			}
			return this.row(
				__( 'Directions', 'groundwork-common-location-finder' ),
				link( url, __( 'Get directions', 'groundwork-common-location-finder' ), 'lfndr-action' ),
				'card' === surface
			);
		}

		var field = this.schema.fields[ key ];
		if ( ! field ) {
			return null;
		}
		if ( 'card' === surface && ! field.card ) {
			return null;
		}
		if ( 'detail' === surface && ! field.detail ) {
			return null;
		}

		var value = location.f ? location.f[ key ] : null;
		if ( null === value || undefined === value ) {
			return null;
		}

		var rendered;
		try {
			var renderer = Finder.renderers[ field.js ] || Finder.renderers.text;
			rendered = renderer.call( this, value, field, { surface: surface, location: location } );
		} catch ( error ) {
			// One misbehaving field must not take the whole card with it.
			if ( window.console ) {
				window.console.error( 'Location Finder: renderer failed for field "' + key + '"', error );
			}
			return null;
		}

		if ( ! rendered ) {
			return null;
		}

		return this.row( field.label, rendered, 'card' === surface );
	};

	/**
	 * One label/value row.
	 *
	 * The label is always real. Cards hide it visually — "Address:" on every one
	 * of forty rows is noise to someone scanning — but hiding it is a styling
	 * decision, not a reason to discard it. What this used to emit was a <dl>
	 * whose every <dt> held a non-breaking space: a description list that
	 * describes nothing, where a screen reader reached "Bessemer, AL" with no
	 * hint of what it was, and the markup claimed a structure it did not have.
	 *
	 * It also leaned on .screen-reader-text, which is a theme's class, not this
	 * plugin's — present in the bundled themes and absent the moment one is not.
	 *
	 * @param {string}  label  Field label. Required.
	 * @param {Node}    node   The rendered value.
	 * @param {boolean} hidden Hide the label visually, keeping it for assistive tech.
	 */
	Finder.prototype.row = function ( label, node, hidden ) {
		var dt = el( 'dt', {
			class: hidden ? 'lfndr__visually-hidden' : null,
			text: label
		} );
		return el( 'div', { class: 'lfndr-row' }, [ dt, el( 'dd', {}, [ node ] ) ] );
	};

	Finder.prototype.directionsUrl = function ( location ) {
		var key = this.config.primary && this.config.primary.address;
		var address = key && location.f ? location.f[ key ] : null;
		return address && address.directionsUrl ? address.directionsUrl : null;
	};

	/* ── The renderer table ──────────────────────────────────────────────────
	 * Keyed by the type's `js` value. A third-party field type registers here:
	 *
	 *   window.GwcLocationFinder.renderers.mytype = function (value, field, ctx) {
	 *       return document.createTextNode(String(value)); // Node or null
	 *   };
	 *
	 * Returning null means "nothing to show", and the row is skipped.
	 * ─────────────────────────────────────────────────────────────────────── */

	Finder.renderers = {
		text: function ( value ) {
			return value ? document.createTextNode( String( value ) ) : null;
		},

		textarea: function ( value, field, ctx ) {
			if ( ! value ) {
				return null;
			}
			// Long prose belongs in the detail pane; on a card it would push
			// everything else off the screen.
			if ( 'card' === ctx.surface ) {
				return null;
			}
			var wrap = el( 'div', { class: 'lfndr-prose' } );
			String( value ).split( /\n{2,}/ ).forEach( function ( paragraph ) {
				wrap.appendChild( el( 'p', { text: paragraph } ) );
			} );
			return wrap;
		},

		url: function ( value, field ) {
			if ( ! value ) {
				return null;
			}
			var style = ( field.settings && field.settings.link_text ) || 'host';
			var label = String( value );
			if ( 'host' === style ) {
				try {
					label = new URL( String( value ) ).host.replace( /^www\./, '' );
				} catch ( e ) {
					label = String( value );
				}
			} else if ( 'label' === style ) {
				label = field.label;
			}
			return link( value, label, 'lfndr-link' );
		},

		email: function ( value, field ) {
			if ( ! value ) {
				return null;
			}
			var mailto = ! field.settings || false !== field.settings.mailto;
			return mailto ? link( 'mailto:' + value, value, 'lfndr-link' ) : document.createTextNode( String( value ) );
		},

		phone: function ( value, field ) {
			if ( ! value ) {
				return null;
			}
			var tel = ! field.settings || false !== field.settings.tel_link;
			if ( ! tel ) {
				return document.createTextNode( String( value ) );
			}
			// Strip everything a dialler cannot use, but display what was typed.
			var dial = String( value ).replace( /[^\d+]/g, '' );
			return link( 'tel:' + dial, String( value ), 'lfndr-link' );
		},

		number: function ( value, field ) {
			if ( '' === value || null === value || undefined === value ) {
				return null;
			}
			var suffix = ( field.settings && field.settings.suffix ) || '';
			return document.createTextNode( String( value ) + suffix );
		},

		/* ── Fields with exactly one answer ───────────────────────────────────
		   A yes/no and a single choice each resolve to one value, and a tag is the
		   wrong shape for that: a tag is a member of a set, which is what makes it
		   right for the several services a location offers and wrong for the one
		   access type it has.

		   So the surface decides. A card hides its labels, so the value has to name
		   itself — "Open to the public" — and a tag is what marks it as an
		   attribute rather than another line of address. The detail pane shows the
		   label above the value, and there the tag is doing nothing the heading has
		   not already done, so the answer is plain text.
		   ──────────────────────────────────────────────────────────────────── */
		boolean: function ( value, field, ctx ) {
			var settings = field.settings || {};
			var card = 'card' === ctx.surface;

			if ( ! value ) {
				// An empty false label means "say nothing when false", which is
				// almost always what you want — a card announcing "Not wheelchair
				// accessible" on every listing rarely is.
				var no = settings.false_label || '';
				if ( ! no ) {
					return null;
				}
				return card
					? el( 'span', { class: 'lfndr-tag', text: no } )
					: document.createTextNode( no );
			}

			var yes = settings.true_label || '';
			if ( card ) {
				return el( 'span', { class: 'lfndr-tag', text: yes || field.label } );
			}

			/* "Step-free access: Step-free access" is what the obvious version
			 * prints, because a true label is usually written to stand alone on a
			 * card and so repeats the field name. Under a visible heading the
			 * answer to a yes/no is Yes; a true label only earns its place here
			 * when it says something the heading did not. */
			var restates = yes.toLowerCase() === String( field.label ).toLowerCase();
			return document.createTextNode(
				! yes || restates ? __( 'Yes', 'groundwork-common-location-finder' ) : yes
			);
		},

		select: function ( value, field, ctx ) {
			if ( ! value ) {
				return null;
			}
			var label = ( field.options && field.options[ value ] ) || value;
			// One answer, not a set — see the note on boolean above.
			return 'card' === ctx.surface
				? el( 'span', { class: 'lfndr-tag', text: label } )
				: document.createTextNode( label );
		},

		multiselect: function ( value, field ) {
			if ( ! value || ! value.length ) {
				return null;
			}
			var wrap = el( 'span', { class: 'lfndr-tags' } );
			value.forEach( function ( one ) {
				wrap.appendChild(
					el( 'span', { class: 'lfndr-tag', text: ( field.options && field.options[ one ] ) || one } )
				);
			} );
			return wrap;
		},

		address: function ( value, field, ctx ) {
			if ( ! value ) {
				return null;
			}
			var text = 'card' === ctx.surface ? value.short || value.formatted : value.formatted;
			if ( ! text ) {
				return null;
			}
			return el( 'span', { class: 'lfndr-address-text', text: text } );
		},

		hours: function ( value, field, ctx ) {
			if ( ! value || ! value.lines || ! value.lines.length ) {
				return null;
			}

			var lines = value.lines;
			var cap = 'card' === ctx.surface
				? ( field.settings && field.settings.card_rows ) || 0
				: 0;
			var shown = cap > 0 ? lines.slice( 0, cap ) : lines;

			var table = el( 'div', { class: 'lfndr-hours' } );
			shown.forEach( function ( line ) {
				table.appendChild(
					el( 'div', { class: 'lfndr-hours__line' }, [
						el( 'span', { class: 'lfndr-hours__when', text: line.when } ),
						el( 'span', { class: 'lfndr-hours__times', text: line.times } )
					] )
				);
			} );

			if ( shown.length < lines.length ) {
				table.appendChild(
					el( 'p', {
						class: 'lfndr-hours__more',
						text: __( 'More hours in the details', 'groundwork-common-location-finder' )
					} )
				);
			}

			/* A struck-through schedule beside a closure notice says "these are the
			 * normal hours and they do not apply right now" in a way that hiding
			 * them cannot — but only for the schedule the closure actually suspends.
			 * A location's other hours fields are not closed by it. */
			if ( field.key === ( this.config.primary || {} ).hours
				&& this.hoursAreSuspended( ctx.location ) ) {
				table.classList.add( 'is-suspended' );
			}

			return table;
		},

		closures: function ( value, field, ctx ) {
			if ( ! value || ! value.length ) {
				return null;
			}

			/* This field's own rows, not the role's — see the note above. */
			var active = this.activeClosureIn( value );
			var upcoming = active ? null : this.upcomingClosureIn( value, field );
			var closure = active || upcoming;
			if ( ! closure ) {
				return null;
			}

			var range = closure.start === closure.end
				? closure.start
				: closure.start + ' – ' + closure.end;

			/* The banner is the whole statement — the phrase, the dates and the
			 * reason in one place. The pill that used to sit above it said the same
			 * two words with none of the substance, and two objects announcing one
			 * fact is worse than either alone. See showsClosureBanner(): the pill is
			 * suppressed exactly where this renders, and kept where it does not. */
			var text = active
				? __( 'Temporarily closed', 'groundwork-common-location-finder' ) + ': ' + range
				: __( 'Closing soon', 'groundwork-common-location-finder' ) + ': ' + range;

			if ( closure.reason ) {
				text += ' (' + closure.reason + ')';
			}

			return el(
				'p',
				{ class: 'lfndr-closure' + ( active ? ' lfndr-closure--active' : ' lfndr-closure--upcoming' ) },
				[ active ? icon( 'alert' ) : null, el( 'span', { text: text } ) ]
			);
		}
	};

	/* ── Detail pane ─────────────────────────────────────────────────────── */

	Finder.prototype.bindResults = function () {
		var self = this;
		if ( ! this.panel ) {
			return;
		}
		this.panel.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && null !== self.state.selected ) {
				self.deselect();
			}
		} );
	};

	Finder.prototype.select = function ( id ) {
		var location = null;
		for ( var i = 0; i < this.locations.length; i++ ) {
			if ( this.locations[ i ].id === id ) {
				location = this.locations[ i ];
				break;
			}
		}
		if ( ! location ) {
			return;
		}

		this.state.selected = id;
		this.root.classList.add( 'lfndr--detail' );

		/* The detail pane lives inside .lfndr__panel, which is on screen in
		 * every layout — including narrow full screen, where it scrolls under
		 * the pinned map rather than taking turns with it. renderDetail()
		 * focuses the pane's heading, so tapping a pin scrolls the pane into
		 * view without anything here having to arrange it. */
		this.renderDetail( location );

		if ( this.map && null !== location.lat ) {
			this.map.setView(
				[ location.lat, location.lng ],
				Math.min( this.config.maxZoom || 19, DETAIL_ZOOM )
			);
		}
		this.renderMap( this.visible() );
	};

	Finder.prototype.deselect = function () {
		this.state.selected = null;
		this.root.classList.remove( 'lfndr--detail' );
		if ( this.detailEl ) {
			this.detailEl.remove();
			this.detailEl = null;
		}
		this.update();
		if ( this.searchInput ) {
			this.searchInput.focus();
		}
	};

	Finder.prototype.renderDetail = function ( location ) {
		var self = this;

		if ( this.detailEl ) {
			this.detailEl.remove();
		}

		var back = el(
			'button',
			{ type: 'button', class: 'lfndr-detail__back' },
			[ icon( 'back' ), el( 'span', { text: __( 'Back to all results', 'groundwork-common-location-finder' ) } ) ]
		);
		back.addEventListener( 'click', function () {
			self.deselect();
		} );

		/* The region is the location's data and nothing else. Back is chrome —
		 * it belongs to the finder, not to this location — and wrapping it in a
		 * region announced as "Bessemer Outreach" filed a navigation control
		 * under the name of the thing it navigates away from. It now sits in its
		 * own bar above the region rather than inside it. */
		var body = el( 'div', {
			class: 'lfndr-detail__body',
			role: 'region',
			'aria-label': location.name
		} );
		var heading = el( 'h3', { class: 'lfndr-detail__name', tabindex: '-1', text: location.name } );

		var status = this.statusBadge( this.openStatus( location ), location, 'detail' );
		var fields = el( 'dl', { class: 'lfndr-detail__fields' } );

		this.schema.detailOrder.forEach( function ( key ) {
			var row = self.fieldRow( location, key, 'detail' );
			if ( row ) {
				fields.appendChild( row );
			}
		} );

		body.appendChild( el( 'div', { class: 'lfndr-head' }, [ heading, status ] ) );
		body.appendChild( fields );

		// Back sits above the pane's content as a plain control. It stays outside
		// .lfndr-detail__body — the region announced as this location — because it
		// belongs to the finder rather than to the location being shown.
		this.detailEl = el( 'div', { class: 'lfndr-detail' }, [ back, body ] );

		this.panel.appendChild( this.detailEl );

		/* Focus the heading rather than the back button: a screen reader then
		 * announces which location opened before offering the way out of it.
		 *
		 * preventScroll, because focusing an element scrolls it into view and the
		 * heading is inside an overlay pinned at inset:0 — already wholly visible,
		 * with nothing to scroll to. The browser scrolled the panel underneath to
		 * the top anyway, so closing the detail dropped the visitor back at result
		 * one instead of the one they had opened. */
		heading.focus( { preventScroll: true } );
	};

	/* ── Map ─────────────────────────────────────────────────────────────── */

	/* ── The tile consent gate ───────────────────────────────────────────────
	 * Tiles are fetched by the visitor's browser straight from the provider, so
	 * by the time a map is on screen that provider already has the visitor's IP
	 * address — no request from this site is involved and nothing server-side
	 * can prevent it. The only place the decision can be made is here, before
	 * the first tile is requested.
	 *
	 * Leaflet itself is served from this site, so the map, its controls and its
	 * pins all load with no third party involved. Only the tile layer waits.
	 * That is why the gate is not a blank box: the finder is fully usable behind
	 * it, and someone who never loads the map still sees where things are.
	 *
	 * The answer is kept in sessionStorage, not a cookie — it is a UI preference
	 * for this browsing session, it is never sent to the server, and it is gone
	 * when the tab closes. */
	var TILE_CONSENT_KEY = 'lfndr-tiles-ok';

	function tilesAllowed() {
		try {
			return '1' === window.sessionStorage.getItem( TILE_CONSENT_KEY );
		} catch ( e ) {
			/* Private mode, or storage disabled entirely. Failing closed is the
			 * only safe direction: assume no consent and ask again. */
			return false;
		}
	}

	function rememberTilesAllowed() {
		try {
			window.sessionStorage.setItem( TILE_CONSENT_KEY, '1' );
		} catch ( e ) { /* Not fatal — the map still loads, we just re-ask later. */ }
	}

	Finder.prototype.initMap = function () {
		var node = this.root.querySelector( '.lfndr__map' );
		if ( ! node || ! window.L ) {
			return;
		}

		this.map = window.L.map( node, { zoomControl: true } ).setView(
			this.config.center,
			this.config.zoom
		);

		this.addLocateControl();

		if ( this.config.tileConsent && ! tilesAllowed() ) {
			this.showTileGate( node );
			return;
		}

		this.addTiles();
	};

	Finder.prototype.addTiles = function () {
		if ( ! this.map || this.tileLayer ) {
			return;
		}

		this.tileLayer = window.L.tileLayer( this.config.tileUrl, {
			attribution: this.config.attribution,
			maxZoom: this.config.maxZoom
		} ).addTo( this.map );
	};

	/* ── Locate, as a map control ─────────────────────────────────────────────
	 * Leaflet ships map.locate() but no control for it — the crosshair button
	 * people recognise is L.Control.Locate, a third-party plugin. This is that
	 * button, written against the control API directly, because vendoring a
	 * second library to render one <button> would cost another 30KB and another
	 * license for something the finder already knows how to do.
	 *
	 * It calls the finder's own locate() rather than map.locate(). That one
	 * already discriminates the three geolocation error codes, handles the
	 * insecure-origin case where the API exists but every call fails, and
	 * applies the coarse-fix rule — and it drives the result list, not just the
	 * map, which map.locate() has no way to do.
	 *
	 * Placed top-right so it does not crowd the zoom cluster.
	 *
	 * It is hidden along with every other map control while the tile consent
	 * gate is up. Locating would technically work without tiles, but a control
	 * showing through a translucent overlay reads as a rendering fault, and the
	 * gate's whole job is to be the only thing on the map until someone asks
	 * for one. See .lfndr__map--gated in the stylesheet.
	 * ─────────────────────────────────────────────────────────────────────── */

	Finder.prototype.addLocateControl = function () {
		var self = this;

		if ( ! this.map || ! this.config.nearMe || ! navigator.geolocation || ! window.L.Control ) {
			return;
		}

		var Locate = window.L.Control.extend( {
			options: { position: 'topright' },

			onAdd: function () {
				var wrap = window.L.DomUtil.create( 'div', 'leaflet-bar lfndr__locate-control' );

				/* A real <button>. Leaflet's own controls are anchors with
				 * href="#", which announce as links and add a history entry —
				 * this is an action, so it gets the element for actions. */
				var button = el( 'button', {
					type: 'button',
					class: 'lfndr__locate-btn',
					'aria-label': __( 'Show my location', 'groundwork-common-location-finder' ),
					title: __( 'Show my location', 'groundwork-common-location-finder' )
				}, [ icon( 'locate' ) ] );

				button.addEventListener( 'click', function () {
					self.locate();
				} );

				/* Without this the click also reaches the map and starts a drag. */
				window.L.DomEvent.disableClickPropagation( wrap );

				wrap.appendChild( button );
				self.locateButton = button;

				return wrap;
			}
		} );

		this.map.addControl( new Locate() );
	};

	Finder.prototype.showTileGate = function ( node ) {
		var self = this;
		var host = this.config.tileHost || '';

		/* Named explicitly. "A third party" is not something a person can weigh;
		 * "tile.openstreetmap.org" is. */
		/* Written as a statement rather than a ternary so the translators comment
		 * is a leading comment of the call. Attached to a ternary branch, the
		 * extractor does not associate it and make-pot warns. */
		var explain;
		if ( host ) {
			/* translators: %s: hostname of the map tile provider, e.g. tile.openstreetmap.org. */
			explain = __( 'The map is loaded from %s. Showing it will share your IP address with them.', 'groundwork-common-location-finder' ).replace( '%s', host );
		} else {
			explain = __( 'The map is loaded from another website. Showing it will share your IP address with them.', 'groundwork-common-location-finder' );
		}

		var button = el( 'button', {
			type: 'button',
			class: 'lfndr__tilegate-btn',
			text: __( 'Show map', 'groundwork-common-location-finder' )
		} );

		var gate = el( 'div', { class: 'lfndr__tilegate' }, [
			el( 'p', { class: 'lfndr__tilegate-text', text: explain } ),
			button,
			el( 'p', {
				class: 'lfndr__tilegate-note',
				text: __( 'The list, search and filters work without it.', 'groundwork-common-location-finder' )
			} )
		] );

		/* Leaflet reads pointer events on its container for panning; without this
		 * a click meant for the button also drags the map underneath. */
		if ( window.L.DomEvent ) {
			window.L.DomEvent.disableClickPropagation( gate );
			window.L.DomEvent.disableScrollPropagation( gate );
		}

		button.addEventListener( 'click', function () {
			rememberTilesAllowed();
			self.addTiles();
			node.classList.remove( 'lfndr__map--gated' );
			if ( gate.parentNode ) {
				gate.parentNode.removeChild( gate );
			}
			/* Focus would otherwise be left on a button that no longer exists,
			 * which drops a keyboard user back at the top of the document. */
			var map = self.root.querySelector( '.lfndr__map' );
			if ( map ) {
				map.setAttribute( 'tabindex', '-1' );
				map.focus( { preventScroll: true } );
			}
		} );

		node.appendChild( gate );
		node.classList.add( 'lfndr__map--gated' );
	};

	Finder.prototype.renderMap = function ( list ) {
		if ( ! this.map ) {
			return;
		}

		var self = this;

		this.markers.forEach( function ( marker ) {
			self.map.removeLayer( marker );
		} );
		this.markers = [];

		var points = [];

		list.forEach( function ( location ) {
			if ( null === location.lat || null === location.lng ) {
				return;
			}

			var selected = self.state.selected === location.id;

			// divIcon rather than an image, so the selected state is one CSS
			// class instead of a second sprite to ship and keep in sync. The
			// html here is a fixed source literal — nothing from the payload
			// ever reaches it.
			var marker = window.L.marker( [ location.lat, location.lng ], {
				icon: window.L.divIcon( {
					className: 'lfndr-pin' + ( selected ? ' is-selected' : '' ),
					html: '<span class="lfndr-pin__dot"></span>',
					iconSize: [ 22, 22 ],
					iconAnchor: [ 11, 11 ]
				} ),
				keyboard: true,
				title: location.name,
				alt: location.name
			} );

			marker.on( 'click', function () {
				self.select( location.id );
			} );

			marker.addTo( self.map );
			self.markers.push( marker );
			points.push( [ location.lat, location.lng ] );
		} );

		if ( this.config.fitToMarkers && points.length && ! this.state.selected && ! this.origin ) {
			this.map.fitBounds( points, { padding: [ 30, 30 ], maxZoom: DETAIL_ZOOM } );
		}
	};

	/* ── Boot ────────────────────────────────────────────────────────────── */

	/**
	 * Start a finder from its data.
	 *
	 * Written to take the data as an argument rather than reading the island
	 * itself, so that swapping the inline payload for a fetched one later is a
	 * change to how boot() is called and to nothing else. The inline path is the
	 * only one that exists today; the REST path slots in exactly here.
	 */
	var instances = [];

	function start( root, data ) {
		var finder = new Finder( root, data );
		// Published so a theme can reach a running finder — to call setOrigin()
		// from its own "near me" control, or to register a renderer for a field
		// type it added.
		root.lfndrFinder = finder;
		instances.push( finder );
		return finder;
	}

	function boot( root, data ) {
		if ( ! data ) {
			return null;
		}
		if ( ! data.locations && data.restUrl ) {
			return window
				.fetch( data.restUrl, { credentials: 'same-origin' } )
				.then( function ( response ) { return response.json(); } )
				.then( function ( fetched ) {
					data.locations = fetched;
					return start( root, data );
				} );
		}
		return start( root, data );
	}

	function init() {
		document.querySelectorAll( '[data-lfndr-finder]' ).forEach( function ( root ) {
			var island = document.querySelector(
				'.lfndr-data[data-lfndr-for="' + root.id + '"]'
			);
			if ( ! island ) {
				return;
			}
			try {
				boot( root, JSON.parse( island.textContent ) );
			} catch ( error ) {
				// The server-rendered list is already on the page and readable,
				// so a payload we cannot parse degrades to that rather than to
				// an empty box.
				if ( window.console ) {
					window.console.error( 'Location Finder: could not read its data.', error );
				}
			}
		} );
	}

	window.GwcLocationFinder = {
		Finder: Finder,
		renderers: Finder.renderers,
		boot: boot,
		instances: instances,
		helpers: { el: el, link: link, haversine: haversine, siteNow: siteNow, slotIsToday: slotIsToday }
	};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
