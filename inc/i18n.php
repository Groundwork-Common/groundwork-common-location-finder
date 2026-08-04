<?php
/**
 * Text domain loading and the lazy translated label tables.
 *
 * @package LocationFinder
 */

defined( 'ABSPATH' ) || exit;

/* ── Why these are functions and not constants ───────────────────────────────
 * The obvious way to write a weekday table is:
 *
 *     const LFNDR_HOUR_DAYS = [ 1 => [ 'Monday', 'Mon' ], … ];
 *
 * which is what the plugin this one descends from did, and which is wrong twice
 * over. A const cannot hold the result of __(), so the table is permanently
 * English; and if you reach for a workaround that calls __() at file scope, WP
 * 6.7+ answers with a _doing_it_wrong() notice, because translations are not
 * loaded until `init` and an early call can poison the string cache with the
 * untranslated value for the rest of the request.
 *
 * A function with a static memo is the whole fix: nothing is translated until
 * something asks, by which time `init` has long since fired, and the cost of the
 * lookup is paid once per request.
 *
 * Tables that carry no strings — sort orders, numeric maps — stay const.
 * ─────────────────────────────────────────────────────────────────────────── */

add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'location-finder', false, dirname( plugin_basename( LFNDR_FILE ) ) . '/languages' );
	}
);

/**
 * Weekday labels, keyed 1 (Monday) through 7 (Sunday).
 *
 * Monday-first is canonical storage, always, regardless of the site's
 * start_of_week. The hours formatter collapses runs of consecutive days into
 * ranges, and Monday-first is what makes the overwhelmingly common Mon–Fri fall
 * out as a single run. A site that starts its week on Sunday changes the
 * *display* order; it never changes these indices.
 *
 * @return array<int, array{0: string, 1: string}> [ full, abbreviated ]
 */
function lfndr_hour_days(): array {
	static $days = null;
	if ( null !== $days ) {
		return $days;
	}
	$days = array(
		1 => array( __( 'Monday', 'location-finder' ), _x( 'Mon', 'abbreviated weekday', 'location-finder' ) ),
		2 => array( __( 'Tuesday', 'location-finder' ), _x( 'Tue', 'abbreviated weekday', 'location-finder' ) ),
		3 => array( __( 'Wednesday', 'location-finder' ), _x( 'Wed', 'abbreviated weekday', 'location-finder' ) ),
		4 => array( __( 'Thursday', 'location-finder' ), _x( 'Thu', 'abbreviated weekday', 'location-finder' ) ),
		5 => array( __( 'Friday', 'location-finder' ), _x( 'Fri', 'abbreviated weekday', 'location-finder' ) ),
		6 => array( __( 'Saturday', 'location-finder' ), _x( 'Sat', 'abbreviated weekday', 'location-finder' ) ),
		7 => array( __( 'Sunday', 'location-finder' ), _x( 'Sun', 'abbreviated weekday', 'location-finder' ) ),
	);
	return $days;
}

/**
 * Recurrence labels for an hours slot.
 *
 * Frequency lives on the slot rather than on the location because that is what
 * real schedules look like: "2nd & 4th Tuesday, 10am–12pm" is two slots that
 * differ only in frequency.
 *
 * @return array<string, string>
 */
function lfndr_hour_freqs(): array {
	static $freqs = null;
	if ( null !== $freqs ) {
		return $freqs;
	}
	$freqs = array(
		'weekly' => __( 'Every week', 'location-finder' ),
		'1st'    => __( '1st of the month', 'location-finder' ),
		'2nd'    => __( '2nd of the month', 'location-finder' ),
		'3rd'    => __( '3rd of the month', 'location-finder' ),
		'4th'    => __( '4th of the month', 'location-finder' ),
		'last'   => __( 'Last of the month', 'location-finder' ),
	);
	return $freqs;
}

/** Sort order for frequencies: weekly first, then through the month. */
const LFNDR_HOUR_FREQ_ORDER = array(
	'weekly' => 0,
	'1st'    => 1,
	'2nd'    => 2,
	'3rd'    => 3,
	'4th'    => 4,
	'last'   => 5,
);

/**
 * Labels for the address subfields.
 *
 * Deliberately neutral: "Region" and "Postal code", not "State" and "ZIP". A
 * US site relabels them on the Fields screen via the field's settings.labels;
 * everyone else already has the right words.
 *
 * @return array<string, string>
 */
function lfndr_address_subfields(): array {
	static $subfields = null;
	if ( null !== $subfields ) {
		return $subfields;
	}
	$subfields = array(
		'line1'   => __( 'Street address', 'location-finder' ),
		'line2'   => __( 'Suite, unit, floor', 'location-finder' ),
		'city'    => __( 'City', 'location-finder' ),
		'region'  => __( 'Region', 'location-finder' ),
		'postal'  => __( 'Postal code', 'location-finder' ),
		'country' => __( 'Country', 'location-finder' ),
	);
	return $subfields;
}
