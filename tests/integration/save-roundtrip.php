<?php
/**
 * End-to-end verification of the meta box save path.
 *
 * Run against a live WordPress:
 *
 *     npx @wordpress/env run cli -- \
 *         wp eval-file wp-content/plugins/groundwork-common-location-finder/tests/integration/save-roundtrip.php
 *
 * This lives outside PHPUnit on purpose. The unit suite stubs WordPress so it
 * can run in a second with no stack, which is right for pure logic — but the
 * things most likely to break here are precisely the ones the stubs would fake:
 * how update_post_meta serializes a bool, whether sanitize_email lowercases,
 * what an emptied checkbox actually posts. Asserting those against stubs would
 * only prove the stubs match the assumptions.
 *
 * It leaves no data behind: the schema option and the test location are both
 * removed at the end.
 *
 * @package GroundworkCommonLocationFinder
 */

function vok( string $label, bool $pass ): void {
	printf( "%s %s\n", $pass ? 'PASS' : 'FAIL', $label );
	if ( ! $pass ) {
		$GLOBALS['gwc_lfndr_failed'] = true;
	}
}

wp_set_current_user( 1 );

// ── Build a schema covering every simple type ────────────────────────────────
$schema = gwc_lfndr_sanitize_schema(
	array(
		'fields'       => array(
			array(
				'key'        => 'org',
				'type'       => 'text',
				'label'      => 'Organization',
				'searchable' => true,
				'show_card'  => true,
			),
			array(
				'key'   => 'notes',
				'type'  => 'textarea',
				'label' => 'Notes',
			),
			array(
				'key'   => 'website',
				'type'  => 'url',
				'label' => 'Website',
			),
			array(
				'key'   => 'contact',
				'type'  => 'email',
				'label' => 'Email',
			),
			array(
				'key'   => 'phone',
				'type'  => 'phone',
				'label' => 'Phone',
			),
			array(
				'key'      => 'capacity',
				'type'     => 'number',
				'label'    => 'Capacity',
				'settings' => array(
					'min' => 0,
					'max' => 500,
				),
			),
			array(
				'key'        => 'wheelchair',
				'type'       => 'boolean',
				'label'      => 'Wheelchair accessible',
				'filterable' => true,
			),
			array(
				'key'     => 'access',
				'type'    => 'select',
				'label'   => 'Access',
				'options' => array(
					array(
						'value' => 'open',
						'label' => 'Open to the public',
					),
					array(
						'value' => 'appointment',
						'label' => 'By appointment',
					),
				),
			),
			array(
				'key'     => 'services',
				'type'    => 'multiselect',
				'label'   => 'Services',
				'options' => array(
					array(
						'value' => 'diapers',
						'label' => 'Diapers',
					),
					array(
						'value' => 'period-supplies',
						'label' => 'Period supplies',
					),
				),
			),
		),
		'detail_order' => array( '__name', 'org', 'phone', '__directions' ),
		'card_order'   => array( '__name', 'org', '__distance' ),
	)
);
update_option( 'gwc_lfndr_schema', $schema );

vok( 'schema keeps all 9 simple fields', 9 === count( $schema['fields'] ) );
vok( 'post type registered', post_type_exists( 'gwc_lfndr_location' ) );

$post_id = wp_insert_post(
	array(
		'post_type'   => 'gwc_lfndr_location',
		'post_title'  => 'Eastside Center',
		'post_status' => 'publish',
	)
);
vok( 'location created', $post_id > 0 );

/** Simulate a meta box submission. */
function gwc_lfndr_post( int $post_id, array $fields, array $present = array(), array $extra = array() ): void {
	$_POST = array_merge(
		array(
			'gwc_lfndr_nonce'   => wp_create_nonce( 'gwc_lfndr_location_save' ),
			'gwc_lfndr_f'       => $fields,
			'gwc_lfndr_present' => array_fill_keys( $present, '1' ),
		),
		$extra
	);
	gwc_lfndr_save_location( $post_id );
	$_POST = array();
}

// ── Every type round-trips ───────────────────────────────────────────────────
gwc_lfndr_post(
	$post_id,
	array(
		'org'        => '  Eastside  Center  ',
		'notes'      => "Line one\nLine two",
		'website'    => 'example.org/finder',
		'contact'    => 'Hello@Example.ORG',
		'phone'      => '(205) 555-0100 ext. "4"',
		'capacity'   => '900',
		'wheelchair' => '1',
		'access'     => 'open',
		'services'   => array( 'period-supplies', 'diapers', 'bogus' ),
	),
	array( 'wheelchair', 'services' ),
	array(
		'gwc_lfndr_lat' => '33.5186',
		'gwc_lfndr_lng' => '-86.810400',
	)
);

$get = fn( string $key ) => get_post_meta( $post_id, '_gwc_lfndr_f_' . $key, true );

vok( 'text collapses whitespace', 'Eastside Center' === $get( 'org' ) );
vok( 'textarea keeps newlines', "Line one\nLine two" === $get( 'notes' ) );
vok( 'bare host is promoted to https', 'https://example.org/finder' === $get( 'website' ) );
// Case is preserved: RFC 5321 makes the local part case-sensitive, and WP's
// sanitize_email agrees. Only the invalid case is dropped.
vok( 'valid email is stored as typed', 'Hello@Example.ORG' === $get( 'contact' ) );
vok( 'invalid email is discarded', '' === gwc_lfndr_sanitize_email( 'not an address' ) );
vok( 'phone keeps its formatting, minus quotes', '(205) 555-0100 ext. 4' === $get( 'phone' ) );
vok( 'number is clamped to max', '500' === $get( 'capacity' ) );
// A true bool round-trips through postmeta as the string '1'; that is WP's
// normal serialization and what gwc_lfndr_payload_boolean() normalizes back.
vok( 'boolean stored', '1' === $get( 'wheelchair' ) );
vok( 'boolean reaches the payload as a real bool', true === gwc_lfndr_payload_boolean( $get( 'wheelchair' ) ) );
vok( 'select accepts a valid option', 'open' === $get( 'access' ) );
vok( 'multiselect drops unknown values', array( 'diapers', 'period-supplies' ) === $get( 'services' ) );
vok( 'multiselect stores in option order', array( 'diapers', 'period-supplies' ) === $get( 'services' ) );
vok( 'latitude trimmed to significant digits', '33.5186' === get_post_meta( $post_id, '_gwc_lfndr_lat', true ) );
vok( 'longitude trimmed to significant digits', '-86.8104' === get_post_meta( $post_id, '_gwc_lfndr_lng', true ) );

// ── Out-of-range coordinates are blanked, not clamped ────────────────────────
gwc_lfndr_post(
	$post_id,
	array(),
	array(),
	array(
		'gwc_lfndr_lat' => '91.2',
		'gwc_lfndr_lng' => '-86.8104',
	)
);
vok( 'out-of-range latitude is blanked, not clamped', '' === get_post_meta( $post_id, '_gwc_lfndr_lat', true ) );

// ── THE ABSENT-KEY TRAP, both directions ─────────────────────────────────────

// 1. Unchecking every box must clear the meta. The controls post nothing, so
// only the presence marker distinguishes this from "not on the form".
gwc_lfndr_post( $post_id, array(), array( 'wheelchair', 'services' ) );
vok( 'unchecked boolean clears its meta', '' === $get( 'wheelchair' ) );
vok( 'emptied multiselect clears its meta', '' === $get( 'services' ) );
vok( 'a field absent from that form is untouched', 'Eastside Center' === $get( 'org' ) );

// 2. A partial form (Quick Edit) must not blank the fields it never rendered.
gwc_lfndr_post( $post_id, array( 'phone' => '205-555-0199' ) );
vok( 'partial submit updates only what it sent', '205-555-0199' === $get( 'phone' ) );
vok( 'partial submit leaves other fields alone', 'Eastside Center' === $get( 'org' ) );
vok( 'partial submit leaves notes alone', "Line one\nLine two" === $get( 'notes' ) );

// ── Empty values delete rather than store '' ─────────────────────────────────
gwc_lfndr_post( $post_id, array( 'org' => '' ) );
$rows = get_post_meta( $post_id, '_gwc_lfndr_f_org', false );
vok( 'emptying a text field deletes the row', array() === $rows );

// ── A save with no nonce does nothing ────────────────────────────────────────
$_POST = array( 'gwc_lfndr_f' => array( 'phone' => 'hijacked' ) );
gwc_lfndr_save_location( $post_id );
$_POST = array();
vok( 'a nonce-less submit is ignored', '205-555-0199' === $get( 'phone' ) );

// ── Retirement keeps the data ────────────────────────────────────────────────
vok( 'usage count sees the stored phone', 1 === gwc_lfndr_field_usage_count( 'phone' ) );

wp_delete_post( $post_id, true );
delete_option( 'gwc_lfndr_schema' );

echo empty( $GLOBALS['gwc_lfndr_failed'] ) ? "\nALL PASSED\n" : "\nFAILURES\n";
