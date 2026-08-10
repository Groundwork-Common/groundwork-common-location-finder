<?php
/**
 * The admin-side geocoding proxy.
 *
 * @package LocationFinder
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_lfndr_geocode', 'lfndr_handle_geocode' );

/* ── Why this goes through the server ────────────────────────────────────────
 * The browser could call Nominatim directly. It should not:
 *
 *   - Nominatim's usage policy requires an identifying User-Agent with a
 *     contact address. A browser cannot set User-Agent, so a direct call is a
 *     policy violation on every keystroke, and the remedy is a block that lands
 *     on everyone running this plugin, not just the site that caused it.
 *   - A site with a restrictive Content-Security-Policy — increasingly the
 *     default on anything that has had a security review — cannot make the
 *     request at all, and the failure is silent.
 *   - Rate limiting has to happen somewhere that the person being limited does
 *     not control.
 *
 * There is deliberately no `nopriv` handler. Geocoding is an authoring
 * convenience, not a visitor feature; an unauthenticated endpoint here would be
 * an open proxy that spends someone else's rate limit.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Proxy an address lookup to the configured geocoder.
 */
function lfndr_handle_geocode(): void {
	check_ajax_referer( 'lfndr_geocode', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'groundwork-common-location-finder' ) ), 403 );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_ajax_referer above.
	$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	$query = trim( $query );

	/* Under four characters is never a useful address query and is usually a
	 * keystroke that arrived before the debounce settled. Over 200 is not an
	 * address. */
	if ( mb_strlen( $query ) < 4 ) {
		wp_send_json_success( array() );
	}
	$query = mb_substr( $query, 0, 200 );

	$throttle_key = 'lfndr_geo_' . get_current_user_id();
	if ( get_transient( $throttle_key ) ) {
		/* Nominatim allows one request per second. Answering an over-quota
		 * request with an empty list rather than an error keeps the typeahead
		 * quiet — the next keystroke will succeed, and a red banner for
		 * something that self-corrects in a second helps nobody. */
		wp_send_json_success( array() );
	}
	set_transient( $throttle_key, 1, 1 );

	$args = array(
		'format'         => 'jsonv2',
		'addressdetails' => '1',
		'limit'          => '6',
		'q'              => $query,
	);

	/* Nominatim's documented channel for a contact address is this parameter,
	 * not the User-Agent — see lfndr_geocode_user_agent() for what happens when
	 * you put it there instead. */
	$email = lfndr_geocode_contact_email();
	if ( '' !== $email ) {
		$args['email'] = $email;
	}

	$countries = trim( (string) lfndr_setting( 'geo_countries' ) );
	if ( '' !== $countries ) {
		$args['countrycodes'] = $countries;
	}

	/* A viewbox biases results toward the area a site actually covers, which is
	 * the difference between "Springfield" finding the one down the road and
	 * finding the one in Illinois. `bounded` turns the bias into a hard
	 * restriction; it is off by default because a hard restriction silently
	 * returns nothing for the one location that sits outside the box. */
	$viewbox = trim( (string) lfndr_setting( 'geo_viewbox' ) );
	if ( '' !== $viewbox ) {
		$args['viewbox'] = $viewbox;
		$args['bounded'] = lfndr_setting( 'geo_bounded' ) ? '1' : '0';
	}

	$endpoint = (string) lfndr_setting( 'geo_endpoint' );
	$response = wp_remote_get(
		add_query_arg( $args, $endpoint ),
		array(
			'timeout'    => 8,
			'user-agent' => lfndr_geocode_user_agent(),
			'headers'    => array( 'Accept' => 'application/json' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		wp_send_json_error(
			array( 'message' => __( 'Could not reach the address lookup service.', 'groundwork-common-location-finder' ) ),
			502
		);
	}

	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		wp_send_json_error(
			array( 'message' => __( 'The address lookup service refused the request.', 'groundwork-common-location-finder' ) ),
			502
		);
	}

	$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

	wp_send_json_success( lfndr_map_geocode_results( is_array( $decoded ) ? $decoded : array() ) );
}

/**
 * Reduce a geocoder response to the fields the editor actually uses.
 *
 * The plugin this one descends from forwarded the third-party JSON straight
 * through. Don't: it hands an upstream service a channel into an authenticated
 * admin page, it ships fields nobody reads, and it makes the client depend on
 * one provider's response shape. Mapping here means a different geocoder is a
 * new mapper rather than a rewrite of the front end.
 *
 * @param array $results Raw decoded response.
 * @return array
 */
function lfndr_map_geocode_results( array $results ): array {
	$out = array();

	foreach ( $results as $result ) {
		if ( ! is_array( $result ) || ! isset( $result['lat'], $result['lon'] ) ) {
			continue;
		}

		$address = is_array( $result['address'] ?? null ) ? $result['address'] : array();

		/* Nominatim reports the settlement under whichever of these keys fits
		 * the place: a village is not a city and neither is a hamlet, and a
		 * result with only `town` set would otherwise land with an empty City
		 * box. */
		$city = '';
		foreach ( array( 'city', 'town', 'village', 'municipality', 'hamlet', 'suburb' ) as $key ) {
			if ( ! empty( $address[ $key ] ) ) {
				$city = (string) $address[ $key ];
				break;
			}
		}

		$line1 = trim(
			( isset( $address['house_number'] ) ? $address['house_number'] . ' ' : '' )
			. ( $address['road'] ?? '' )
		);

		/* Both forms of the region, so the field can choose. Nominatim reports
		 * an ISO 3166-2 code at whichever administrative level fits the country
		 * — US-AL, FR-IDF, GB-ENG — which is how a site can display "AL" rather
		 * than "Alabama" without this plugin shipping a table of US states it
		 * would then owe the rest of the world an equivalent of. */
		$region_code = '';
		foreach ( array( 'ISO3166-2-lvl4', 'ISO3166-2-lvl6', 'ISO3166-2-lvl3' ) as $key ) {
			if ( ! empty( $address[ $key ] ) ) {
				$parts       = explode( '-', (string) $address[ $key ], 2 );
				$region_code = strtoupper( sanitize_text_field( $parts[1] ?? '' ) );
				break;
			}
		}

		$out[] = array(
			'label'      => sanitize_text_field( (string) ( $result['display_name'] ?? '' ) ),
			'lat'        => (string) lfndr_sanitize_coordinate( $result['lat'], 90.0 ),
			'lng'        => (string) lfndr_sanitize_coordinate( $result['lon'], 180.0 ),
			'line1'      => sanitize_text_field( $line1 ),
			'city'       => sanitize_text_field( $city ),
			'region'     => sanitize_text_field( (string) ( $address['state'] ?? $address['province'] ?? '' ) ),
			'regionCode' => $region_code,
			'postal'     => sanitize_text_field( (string) ( $address['postcode'] ?? '' ) ),
			'country'    => strtoupper( sanitize_text_field( (string) ( $address['country_code'] ?? '' ) ) ),
		);
	}

	return $out;
}

/**
 * The contact address sent with geocoding requests.
 *
 * @return string
 */
function lfndr_geocode_contact_email(): string {
	$email = (string) lfndr_setting( 'geo_email' );
	if ( ! is_email( $email ) ) {
		$email = (string) get_option( 'admin_email' );
	}
	return is_email( $email ) ? $email : '';
}

/**
 * The User-Agent sent to the geocoder.
 *
 * Nominatim requires a User-Agent that identifies the application — a stock
 * library default is explicitly disallowed, and a shared generic one across
 * thousands of installs is how an application gets blocked wholesale. So this
 * names the plugin, its version, and the site making the request.
 *
 * What it deliberately does NOT contain is an email address. Nominatim's
 * front-end filter returns a bare 403 for a User-Agent with an email in it —
 * verified against the live service, where a product token followed by
 * `(admin@example.org)` is refused while the same string with a URL instead is
 * served. Their documented place for a contact address is the `email` query
 * parameter, which lfndr_handle_geocode() sends. Putting it here instead looks
 * like following the policy and silently breaks every lookup.
 *
 * @return string
 */
function lfndr_geocode_user_agent(): string {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );

	return sprintf(
		'GroundworkCommonLocationFinder/%s (+%s)',
		LFNDR_VERSION,
		$host ? 'https://' . $host : 'https://wordpress.org/plugins/groundwork-common-location-finder/'
	);
}
