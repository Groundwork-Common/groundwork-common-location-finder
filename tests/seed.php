<?php
/**
 * A demo set of locations, for working on the plugin and taking screenshots.
 *
 * Run it under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-location-finder/tests/seed.php
 *
 * Re-runnable: it removes what a previous run created and builds it again.
 *
 * ── Every place here is invented ─────────────────────────────────────────────
 * The addresses are plausible for the Birmingham, Alabama metro and belong to
 * nobody. That is deliberate for the same reason the sibling plugins' fixtures
 * are: a screenshot of a real address on a public plugin page is a disclosure
 * that deleting the file does not undo. The phone numbers are all in the 555
 * exchange, which is reserved and rings nowhere.
 *
 * The metro matches the volunteer tracker's and the post portal's fixtures on
 * purpose. All three plugins are demonstrated on one beta site, and a food bank
 * whose volunteers are in Birmingham but whose pantries are in Portland reads as
 * three unrelated demos rather than one organisation.
 *
 * ── Why the values go through the plugin's own sanitizers ────────────────────
 * An address and an opening-hours schedule are both stored as arrays whose exact
 * shape is the sanitizer's business, not this file's. Hand-writing those arrays
 * here would produce a fixture that is correct until the day the storage format
 * changes, and then produces a demo site that is subtly wrong in a way no test
 * catches. Calling gwc_lfndr_sanitize_address() and gwc_lfndr_sanitize_hours() means the
 * fixture is shaped by the same code the meta box uses, so it cannot drift.
 *
 * @package GroundworkCommonLocationFinder
 */

defined( 'ABSPATH' ) || exit;

/** Marks everything this script creates, so a re-run removes only its own work. */
const GWC_LFNDR_SEED_MARK = '_gwc_lfndr_seed';

/* ── Refuse to run anywhere that matters ─────────────────────────────────────
 * This script deletes posts. Pointed at a live site it would delete that site's
 * locations. The guard is deliberately conservative: local and development only,
 * whatever anybody types.
 * ─────────────────────────────────────────────────────────────────────────── */
$gwc_lfndr_env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

if ( ! in_array( $gwc_lfndr_env, array( 'local', 'development' ), true ) ) {
	echo "Refusing to seed: WP_ENVIRONMENT_TYPE is '", $gwc_lfndr_env, "'.\n";
	echo "This script deletes records. It runs on local and development only.\n";
	exit( 1 );
}

if ( ! function_exists( 'gwc_lfndr_get_schema' ) ) {
	echo "The plugin is not active. Run: wp plugin activate groundwork-common-location-finder\n";
	exit( 1 );
}

wp_set_current_user( 1 );

/* ── Clear the previous run ──────────────────────────────────────────────── */

$gwc_lfndr_previous = get_posts(
	array(
		'post_type'      => array( GWC_LFNDR_POST_TYPE, 'page' ),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a development fixture, run by hand.
		'meta_key'       => GWC_LFNDR_SEED_MARK,
	)
);

foreach ( $gwc_lfndr_previous as $gwc_lfndr_id ) {
	wp_delete_post( (int) $gwc_lfndr_id, true );
}

printf( "Removed %d records from a previous run.\n", count( $gwc_lfndr_previous ) );

/* ── The schema ──────────────────────────────────────────────────────────────
 * Read, not written. gwc_lfndr_get_schema() installs the three-field default on a
 * site that has none, and leaves an existing configuration alone. Overwriting it
 * here would throw away whatever fields somebody had just built by hand, which
 * on a demo site is exactly the work you most want to keep.
 * ─────────────────────────────────────────────────────────────────────────── */
$gwc_lfndr_schema = gwc_lfndr_get_schema();

if ( ! gwc_lfndr_get_field( 'address', $gwc_lfndr_schema ) || ! gwc_lfndr_get_field( 'hours', $gwc_lfndr_schema ) ) {
	echo "This site's schema has no 'address' or 'hours' field, so the fixture has\n";
	echo "nowhere to put its data. Seeding the default schema is not this script's\n";
	echo "job — delete the gwc_lfndr_schema option to get the defaults back.\n";
	exit( 1 );
}

/**
 * Create one location.
 *
 * @param string $name    Location name.
 * @param array  $address Raw subfields: line1, line2, city, region, postal.
 * @param array  $hours   Raw slots: day (1=Mon..7=Sun), start, end, freq.
 * @param string $phone   Phone number, or '' for none.
 * @param float  $lat     Latitude.
 * @param float  $lng     Longitude.
 * @return int
 */
function gwc_lfndr_seed_location( string $name, array $address, array $hours, string $phone, float $lat, float $lng ): int {
	/* Looked up here rather than passed in or reached through a global. Under
	 * `wp eval-file` the whole script body runs inside a function, so a
	 * top-level $field assigned above is a local and `global $field` here would
	 * find an unset variable of the same name — silently storing every address
	 * against an empty field definition. gwc_lfndr_get_schema() memoises per
	 * request, so asking six times costs one read. */
	$schema        = gwc_lfndr_get_schema();
	$address_field = gwc_lfndr_get_field( 'address', $schema );
	$hours_field   = gwc_lfndr_get_field( 'hours', $schema );

	$id = (int) wp_insert_post(
		array(
			'post_type'   => GWC_LFNDR_POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	update_post_meta( $id, GWC_LFNDR_SEED_MARK, 1 );

	update_post_meta(
		$id,
		gwc_lfndr_field_meta_key( 'address' ),
		gwc_lfndr_sanitize_address( $address, $address_field )
	);

	update_post_meta(
		$id,
		gwc_lfndr_field_meta_key( 'hours' ),
		gwc_lfndr_sanitize_hours( $hours, $hours_field )
	);

	/* An empty phone is left absent rather than stored blank. That is what the
	 * meta box does via the type's is_empty callback, and a location with a
	 * stored empty string renders a labelled row with nothing in it. */
	if ( '' !== $phone ) {
		update_post_meta( $id, gwc_lfndr_field_meta_key( 'phone' ), $phone );
	}

	update_post_meta( $id, '_gwc_lfndr_lat', (string) $lat );
	update_post_meta( $id, '_gwc_lfndr_lng', (string) $lng );

	return $id;
}

/* ── The locations ───────────────────────────────────────────────────────────
 * Six, chosen to cover the states somebody demonstrating this plugin needs on
 * screen: a straightforward weekday site, a weekend one, a monthly mobile round,
 * an evening site with an open-ended closing time, one with no phone number at
 * all, and one with a second address line. Between them they exercise every
 * branch of the card, the detail pane and the "Open today" filter.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_lfndr_made = array();

$gwc_lfndr_made[] = gwc_lfndr_seed_location(
	'Riverbend Food Bank — Downtown Pantry',
	array(
		'line1'  => '1412 Morris Avenue',
		'line2'  => '',
		'city'   => 'Birmingham',
		'region' => 'AL',
		'postal' => '35203',
	),
	array(
		array(
			'day'   => 1,
			'start' => '09:00',
			'end'   => '16:00',
			'freq'  => 'weekly',
		),
		array(
			'day'   => 2,
			'start' => '09:00',
			'end'   => '16:00',
			'freq'  => 'weekly',
		),
		array(
			'day'   => 3,
			'start' => '09:00',
			'end'   => '16:00',
			'freq'  => 'weekly',
		),
		array(
			'day'   => 4,
			'start' => '09:00',
			'end'   => '16:00',
			'freq'  => 'weekly',
		),
		array(
			'day'   => 5,
			'start' => '09:00',
			'end'   => '13:00',
			'freq'  => 'weekly',
		),
	),
	'(205) 555-0118',
	33.5186,
	-86.8104
);

// A second address line, and a Saturday-only schedule.
$gwc_lfndr_made[] = gwc_lfndr_seed_location(
	'Riverbend Food Bank — Bessemer Pantry',
	array(
		'line1'  => '2200 9th Avenue North',
		'line2'  => 'Rear entrance, off the car park',
		'city'   => 'Bessemer',
		'region' => 'AL',
		'postal' => '35020',
	),
	array(
		array(
			'day'   => 6,
			'start' => '08:00',
			'end'   => '12:00',
			'freq'  => 'weekly',
		),
	),
	'(205) 555-0143',
	33.4018,
	-86.9544
);

// No phone. A volunteer-run site that genuinely has no number to publish, which
// is the case the detail pane has to handle without printing an empty row.
$gwc_lfndr_made[] = gwc_lfndr_seed_location(
	'Fairfield Community Fridge',
	array(
		'line1'  => '6501 Gary Avenue',
		'line2'  => '',
		'city'   => 'Fairfield',
		'region' => 'AL',
		'postal' => '35064',
	),
	array(
		array(
			'day'   => 1,
			'start' => '07:00',
			'end'   => '19:00',
			'freq'  => 'weekly',
		),
		array(
			'day'   => 2,
			'start' => '07:00',
			'end'   => '19:00',
			'freq'  => 'weekly',
		),
		array(
			'day'   => 3,
			'start' => '07:00',
			'end'   => '19:00',
			'freq'  => 'weekly',
		),
		array(
			'day'   => 4,
			'start' => '07:00',
			'end'   => '19:00',
			'freq'  => 'weekly',
		),
		array(
			'day'   => 5,
			'start' => '07:00',
			'end'   => '19:00',
			'freq'  => 'weekly',
		),
		array(
			'day'   => 6,
			'start' => '07:00',
			'end'   => '19:00',
			'freq'  => 'weekly',
		),
		array(
			'day'   => 7,
			'start' => '07:00',
			'end'   => '19:00',
			'freq'  => 'weekly',
		),
	),
	'',
	33.4712,
	-86.9219
);

// A monthly round. The frequency labels ("1st & 3rd Tue") are the least
// guessable part of the hours field, so the fixture has to contain one.
$gwc_lfndr_made[] = gwc_lfndr_seed_location(
	'Irondale Mobile Distribution',
	array(
		'line1'  => 'Irondale Civic Center car park',
		'line2'  => '3521 Ratliff Road',
		'city'   => 'Irondale',
		'region' => 'AL',
		'postal' => '35210',
	),
	array(
		array(
			'day'   => 2,
			'start' => '10:00',
			'end'   => '13:00',
			'freq'  => '1st',
		),
		array(
			'day'   => 2,
			'start' => '10:00',
			'end'   => '13:00',
			'freq'  => '3rd',
		),
	),
	'(205) 555-0177',
	33.5379,
	-86.7086
);

// An open-ended closing time: "from 17:30", stored with an empty end.
$gwc_lfndr_made[] = gwc_lfndr_seed_location(
	'Ensley Evening Pantry',
	array(
		'line1'  => '1809 Avenue E',
		'line2'  => '',
		'city'   => 'Birmingham',
		'region' => 'AL',
		'postal' => '35218',
	),
	array(
		array(
			'day'   => 4,
			'start' => '17:30',
			'end'   => '',
			'freq'  => 'weekly',
		),
	),
	'(205) 555-0192',
	33.4884,
	-86.8697
);

$gwc_lfndr_made[] = gwc_lfndr_seed_location(
	'Homewood Senior Meals',
	array(
		'line1'  => '816 Oxmoor Road',
		'line2'  => '',
		'city'   => 'Homewood',
		'region' => 'AL',
		'postal' => '35209',
	),
	array(
		array(
			'day'   => 1,
			'start' => '11:30',
			'end'   => '13:30',
			'freq'  => 'weekly',
		),
		array(
			'day'   => 3,
			'start' => '11:30',
			'end'   => '13:30',
			'freq'  => 'weekly',
		),
		array(
			'day'   => 5,
			'start' => '11:30',
			'end'   => '13:30',
			'freq'  => 'weekly',
		),
	),
	'(205) 555-0164',
	33.4718,
	-86.8006
);

/* ── A page to see them on ───────────────────────────────────────────────────
 * The finder has no archive of its own, so without a page carrying the block
 * there is nothing to look at on the front end and the fixture is admin-only.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_lfndr_page = (int) wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Find a pantry',
		'post_name'    => 'find-a-pantry',
		'post_content' => '<!-- wp:groundwork-common-location-finder/finder /-->',
	)
);

update_post_meta( $gwc_lfndr_page, GWC_LFNDR_SEED_MARK, 1 );

/* ── What was made ───────────────────────────────────────────────────────── */

printf( "\nRiverbend Food Bank's locations are seeded.\n\n" );
printf( "  Locations              %d\n", count( $gwc_lfndr_made ) );
echo "  Downtown Pantry        weekdays, closes early on Friday\n";
echo "  Bessemer Pantry        Saturday mornings, second address line\n";
echo "  Fairfield Fridge       open every day — and no phone number\n";
echo "  Irondale Mobile        1st & 3rd Tuesday only\n";
echo "  Ensley Evening         Thursdays from 17:30, no closing time\n";
echo "  Homewood Senior Meals  Mon, Wed, Fri over lunch\n";

printf( "\n  Admin   %s\n", admin_url( 'edit.php?post_type=' . GWC_LFNDR_POST_TYPE ) );
printf( "  Fields  %s\n", admin_url( 'edit.php?post_type=' . GWC_LFNDR_POST_TYPE . '&page=' . GWC_LFNDR_FIELDS_PAGE ) );
printf( "  Finder  %s\n", get_permalink( $gwc_lfndr_page ) );

echo "\n  Every address here is invented. See the note at the top of this file.\n";
