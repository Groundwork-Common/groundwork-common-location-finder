<?php
/**
 * Payload shape, caching, and the N+1 guarantee.
 *
 *     npx @wordpress/env run cli -- \
 *         wp eval-file wp-content/plugins/location-finder/tests/integration/payload.php
 *
 * The query-count assertion is the one that matters here and the one that
 * cannot be unit tested: it is the difference between a finder that serves 200
 * locations in three queries and one that serves them in two thousand, and it
 * regresses silently the moment somebody adds a get_post_meta() outside the
 * primed set.
 *
 * @package LocationFinder
 */

function vok( string $label, bool $pass, string $detail = '' ): void {
	printf( "%s %s%s\n", $pass ? 'PASS' : 'FAIL', $label, '' !== $detail ? "\n   $detail" : '' );
	if ( ! $pass ) {
		$GLOBALS['lfndr_failed'] = true;
	}
}

wp_set_current_user( 1 );

/* Park any locations that already exist. This script has to reason about exact
 * counts and ordering, and a site with real data in it would fail every one of
 * those assertions for reasons that have nothing to do with the code. */
$pre_existing = get_posts(
	array(
		'post_type'   => 'lfndr_location',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
);
foreach ( $pre_existing as $id ) {
	wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
}
$saved_schema = get_option( 'lfndr_schema' );

// ── A schema exercising every shipping decision ──────────────────────────────
lfndr_save_schema(
	lfndr_sanitize_schema(
		array(
			'fields'       => array(
				array( 'key' => 'org', 'type' => 'text', 'label' => 'Org', 'show_card' => true, 'searchable' => true ),
				array( 'key' => 'secret_code', 'type' => 'text', 'label' => 'Code', 'show_card' => false, 'show_detail' => false, 'searchable' => true ),
				array( 'key' => 'internal_note', 'type' => 'text', 'label' => 'Note', 'show_card' => false, 'show_detail' => false ),
				array(
					'key'        => 'services',
					'type'       => 'multiselect',
					'label'      => 'Services',
					'show_card'  => true,
					'filterable' => true,
					'searchable' => true,
					'options'    => array(
						array( 'value' => 'diapers', 'label' => 'Diapers' ),
						array( 'value' => 'formula', 'label' => 'Formula' ),
						array( 'value' => 'wipes', 'label' => 'Wipes' ),
					),
				),
				array( 'key' => 'wheelchair', 'type' => 'boolean', 'label' => 'Step-free', 'show_card' => true, 'filterable' => true ),
			),
			'detail_order' => array( '__name', 'org', 'services' ),
			'card_order'   => array( '__name', 'org', '__distance' ),
		)
	)
);

// ── Fixtures ─────────────────────────────────────────────────────────────────
$made = array();
foreach ( array(
	array( 'Alpha Center', '33.5', '-86.8', 'Alpha Org', array( 'diapers', 'formula' ), true ),
	array( 'Beta Center', '33.6', '-86.9', 'Beta Org', array( 'diapers' ), false ),
	array( 'Gamma Center', '', '', 'Gamma Org', array(), false ),
) as $row ) {
	$id     = wp_insert_post(
		array(
			'post_type'   => 'lfndr_location',
			'post_title'  => $row[0],
			'post_status' => 'publish',
		)
	);
	$made[] = $id;

	$_POST = array(
		'lfndr_nonce'   => wp_create_nonce( 'lfndr_location_save' ),
		'lfndr_lat'     => $row[1],
		'lfndr_lng'     => $row[2],
		'lfndr_f'       => array(
			'org'           => $row[3],
			'secret_code'   => 'CODE-' . $row[0],
			'internal_note' => 'never shipped',
			'services'      => $row[4],
			'wheelchair'    => $row[5] ? '1' : '',
		),
		'lfndr_present' => array( 'services' => '1', 'wheelchair' => '1' ),
	);
	lfndr_save_location( $id );
	$_POST = array();
}

lfndr_flush_locations();
$payload = lfndr_get_locations();
$by_name = array_column( $payload, null, 'name' );

// ── Shape ────────────────────────────────────────────────────────────────────
vok( 'every published location is present', 3 === count( $payload ) );
vok( 'ordered by title', array( 'Alpha Center', 'Beta Center', 'Gamma Center' ) === array_column( $payload, 'name' ) );
vok( 'field values live under `f`', 'Alpha Org' === ( $by_name['Alpha Center']['f']['org'] ?? null ) );
vok( 'coordinates are floats', is_float( $by_name['Alpha Center']['lat'] ) );

// null, not 0,0 — which is a real place in the Gulf of Guinea, and a location
// quietly sorting as "4,000 miles away" is far harder to spot than one the map
// simply never draws.
vok( 'a location with no coordinates gets null', null === $by_name['Gamma Center']['lat'] );

// ── What is deliberately NOT shipped ─────────────────────────────────────────
vok( 'a hidden, unsearchable field is not shipped', ! isset( $by_name['Alpha Center']['f']['internal_note'] ) );
vok( 'a hidden but searchable field is not shipped as a value', ! isset( $by_name['Alpha Center']['f']['secret_code'] ) );
vok(
	'…but it is still searchable',
	false !== strpos( $by_name['Alpha Center']['search'], 'code-alpha center' ),
	'search blob: ' . $by_name['Alpha Center']['search']
);
vok( 'empty fields are omitted rather than sent as null', ! isset( $by_name['Gamma Center']['f']['services'] ) );

// ── Search blob ──────────────────────────────────────────────────────────────
vok( 'the blob is lowercased', $by_name['Alpha Center']['search'] === strtolower( $by_name['Alpha Center']['search'] ) );
vok( 'the blob includes the title', false !== strpos( $by_name['Alpha Center']['search'], 'alpha center' ) );
vok( 'choice fields contribute their labels, not just slugs', false !== strpos( $by_name['Alpha Center']['search'], 'diapers' ) );

// ── Facets ───────────────────────────────────────────────────────────────────
vok( 'facet tokens are recorded', array( 'diapers', 'formula' ) === ( $by_name['Alpha Center']['facets']['services'] ?? null ) );
// False is absence: no meta row, so no token. The group builder detects
// "true everywhere" by comparing the true count to the total instead.
vok( 'a false boolean records no token', ! isset( $by_name['Beta Center']['facets']['wheelchair'] ) );
vok( 'a true boolean records one', array( '1' ) === ( $by_name['Alpha Center']['facets']['wheelchair'] ?? null ) );

$groups = lfndr_available_facets( $payload, lfndr_get_schema() );
$by_key = array_column( $groups, null, 'key' );
vok( 'services renders a filter group', isset( $by_key['services'] ) );
vok( 'only present values get chips', array( 'diapers', 'formula' ) === array_column( $by_key['services']['values'], 'value' ), 'wipes is defined but unused' );
vok( 'the mixed boolean renders a toggle', isset( $by_key['wheelchair'] ) );

// ── Caching ──────────────────────────────────────────────────────────────────
vok( 'the payload is cached', false !== get_transient( 'lfndr_locations' ) );

$before = get_transient( 'lfndr_locations' );
wp_update_post( array( 'ID' => $made[0], 'post_title' => 'Alpha Center Renamed' ) );
vok( 'saving a location busts the cache', false === get_transient( 'lfndr_locations' ) );

lfndr_get_locations();
lfndr_save_schema( lfndr_get_schema() );
vok( 'saving the schema busts the cache', false === get_transient( 'lfndr_locations' ) );

// Both the first write and later ones. WordPress fires add_option_* for the
// former and update_option_* for the latter, and hooking only one leaves an
// hour of stale payload after exactly the save someone is watching for.
delete_option( 'lfndr_settings' );
lfndr_get_locations();
add_option( 'lfndr_settings', array( 'zoom' => 7 ) );
vok( 'creating the settings option busts the cache', false === get_transient( 'lfndr_locations' ) );

lfndr_get_locations();
update_option( 'lfndr_settings', array( 'zoom' => 9 ) );
vok( 'updating the settings option busts the cache', false === get_transient( 'lfndr_locations' ) );
delete_option( 'lfndr_settings' );

// ── The N+1 guarantee ────────────────────────────────────────────────────────
// Query count must not grow with the number of locations. Measured on a cold
// cache both times, with the second run holding ten times the data.
$queries = array();
add_filter(
	'query',
	static function ( $sql ) use ( &$queries ) {
		$queries[] = $sql;
		return $sql;
	}
);

/** Count the queries one cold call to lfndr_get_locations() costs. */
$measure = static function () use ( &$queries ): int {
	lfndr_flush_locations();
	// Without this the second measurement runs against options and posts the
	// first one already warmed, and the comparison measures cache state rather
	// than query count.
	wp_cache_flush();
	$queries = array();
	lfndr_get_locations();
	return count( $queries );
};

$queries_for_3 = $measure();

for ( $i = 0; $i < 27; $i++ ) {
	$id     = wp_insert_post(
		array(
			'post_type'   => 'lfndr_location',
			'post_title'  => sprintf( 'Bulk %02d', $i ),
			'post_status' => 'publish',
		)
	);
	$made[] = $id;
	update_post_meta( $id, '_lfndr_lat', '33.5' );
	update_post_meta( $id, '_lfndr_lng', '-86.8' );
	update_post_meta( $id, '_lfndr_f_org', 'Bulk Org ' . $i );
	update_post_meta( $id, '_lfndr_f_services', array( 'diapers' ) );
}

$queries_for_30 = $measure();
$all            = lfndr_get_locations();

vok( '30 locations are all returned', 30 === count( $all ) );
vok(
	'query count does not grow with locations',
	$queries_for_30 === $queries_for_3,
	sprintf( '3 locations: %d queries; 30 locations: %d queries', $queries_for_3, $queries_for_30 )
);

// ── Clean up ─────────────────────────────────────────────────────────────────
foreach ( $made as $id ) {
	wp_delete_post( $id, true );
}
if ( false !== $saved_schema ) {
	update_option( 'lfndr_schema', $saved_schema );
} else {
	delete_option( 'lfndr_schema' );
}
foreach ( $pre_existing as $id ) {
	wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
}
lfndr_flush_locations();

echo empty( $GLOBALS['lfndr_failed'] ) ? "\nALL PASSED\n" : "\nFAILURES\n";
