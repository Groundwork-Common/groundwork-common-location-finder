<?php
/**
 * Address composite and geocode-mapper verification.
 *
 * Run it under wp-env:
 *
 *     npx @wordpress/env run cli -- \
 *         wp eval-file wp-content/plugins/groundwork-common-location-finder/tests/integration/address.php
 *
 * @package GroundworkCommonLocationFinder
 */

function vok( string $l, bool $p ): void {
	printf( "%s %s\n", $p ? 'PASS' : 'FAIL', $l );
	if ( ! $p ) {
		$GLOBALS['f'] = true;
	} }
wp_set_current_user( 1 );
$s             = gwc_lfndr_get_schema();
$s['fields'][] = array(
	'key'         => 'address',
	'type'        => 'address',
	'label'       => 'Address',
	'show_card'   => true,
	'show_detail' => true,
	'searchable'  => true,
	'settings'    => array(
		'primary'         => true,
		'default_country' => 'US',
		'labels'          => array(
			'region' => 'State',
			'postal' => 'ZIP',
		),
	),
);
$s             = gwc_lfndr_sanitize_schema( $s );
gwc_lfndr_save_schema( $s );

$addr = gwc_lfndr_get_field( 'address', $s );
vok( 'address type registered', isset( gwc_lfndr_field_types()['address'] ) );
vok( 'address field survives sanitization', null !== $addr );
vok( 'primary flag kept', ! empty( $addr['settings']['primary'] ) );
vok( 'custom labels kept', 'State' === $addr['settings']['labels']['region'] );
vok( 'gwc_lfndr_primary_field finds it', 'address' === ( gwc_lfndr_primary_field( 'address', $s )['key'] ?? '' ) );

$id    = wp_insert_post(
	array(
		'post_type'   => 'gwc_lfndr_location',
		'post_title'  => 'Eastside',
		'post_status' => 'publish',
	)
);
$_POST = array(
	'gwc_lfndr_nonce'   => wp_create_nonce( 'gwc_lfndr_location_save' ),
	'gwc_lfndr_f'       => array(
		'address' => array(
			'line1'   => '1430 Rev Abraham Woods Jr Blvd',
			'line2'   => 'Suite 2',
			'city'    => 'Birmingham',
			'region'  => 'AL',
			'postal'  => '35203',
			'country' => '',
		),
	),
	'gwc_lfndr_present' => array( 'address' => '1' ),
	'gwc_lfndr_lat'     => '33.5186',
	'gwc_lfndr_lng'     => '-86.8104',
);
gwc_lfndr_save_location( $id );
$_POST = array();

$v = get_post_meta( $id, '_gwc_lfndr_f_address', true );
vok( 'address stored as an array', is_array( $v ) && 'Birmingham' === $v['city'] );
vok( 'default country applied', 'US' === $v['country'] );

$p = gwc_lfndr_payload_address( $v, $addr, $id );
vok( 'formatted address reads naturally', '1430 Rev Abraham Woods Jr Blvd, Suite 2, Birmingham, AL 35203, US' === $p['formatted'] ) || print( "   got: {$p['formatted']}\n" );
vok( 'card short form is city + region', 'Birmingham, AL' === $p['short'] ) || print( "   got: {$p['short']}\n" );
vok( 'directions url built from the address', false !== strpos( $p['directionsUrl'] ?? '', 'google.com/maps' ) );
vok( 'search text uses the configured parts', false !== strpos( gwc_lfndr_search_address( $v, $addr ), '35203' ) );

// Empty address: default country alone must not count as content.
vok(
	'country-only address counts as empty',
	gwc_lfndr_empty_address(
		array(
			'line1'   => '',
			'city'    => '',
			'country' => 'US',
		)
	)
);

// Geocoder: shape of the mapper, no network needed.
$mapped = gwc_lfndr_map_geocode_results(
	array(
		array(
			'lat'          => '33.5186',
			'lon'          => '-86.8104',
			'display_name' => 'Birmingham, AL',
			'address'      => array(
				'house_number'   => '1430',
				'road'           => 'Rev Abraham Woods Jr Blvd',
				'town'           => 'Birmingham',
				'state'          => 'Alabama',
				'postcode'       => '35203',
				'country_code'   => 'us',
				'ISO3166-2-lvl4' => 'US-AL',
				'extra'          => 'leak',
			),
		),
	)
);
vok( 'mapper builds line1 from number + road', '1430 Rev Abraham Woods Jr Blvd' === $mapped[0]['line1'] );
vok( 'mapper falls back from city to town', 'Birmingham' === $mapped[0]['city'] );
vok( 'mapper uppercases the country code', 'US' === $mapped[0]['country'] );
vok( 'mapper drops unknown upstream keys', ! isset( $mapped[0]['extra'] ) && 9 === count( $mapped[0] ) );
vok( 'mapper skips results with no coordinates', array() === gwc_lfndr_map_geocode_results( array( array( 'display_name' => 'x' ) ) ) );
// Nominatim's front-end returns a bare 403 for any User-Agent containing an
// email address; the contact goes in the `email` query parameter instead.
vok( 'user agent identifies the app and the site', (bool) preg_match( '/^GroundworkCommonLocationFinder\/\S+ \(\+https:\/\/\S+\)$/', gwc_lfndr_geocode_user_agent() ) );
vok( 'user agent carries no email address', false === strpos( gwc_lfndr_geocode_user_agent(), '@' ) );
vok( 'contact email is available for the query parameter', is_email( gwc_lfndr_geocode_contact_email() ) );
vok( 'mapper extracts an ISO subdivision code', 'AL' === $mapped[0]['regionCode'] );

wp_delete_post( $id, true );
echo empty( $GLOBALS['f'] ) ? "\nALL PASSED\n" : "\nFAILURES\n";
