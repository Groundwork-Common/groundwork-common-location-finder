<?php
/**
 * Schema sanitization, ordering and retirement.
 *
 * @package LocationFinder
 */

use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase {

	protected function setUp(): void {
		lfndr_test_reset();
	}

	/** A minimal valid field definition of the given type. */
	private function field( string $key, string $type = 'text', array $extra = array() ): array {
		return array_merge(
			array(
				'key'   => $key,
				'type'  => $type,
				'label' => ucfirst( $key ),
			),
			$extra
		);
	}

	/* ── Keys ───────────────────────────────────────────────────────────── */

	public function test_field_key_normalisation(): void {
		$this->assertSame( 'phone_number', lfndr_sanitize_field_key( 'Phone Number' ) );
		$this->assertSame( 'phone_number', lfndr_sanitize_field_key( '  phone--number  ' ) );
		$this->assertSame( 'a', lfndr_sanitize_field_key( 'a' ) );
	}

	public function test_field_key_rejects_leading_underscore(): void {
		// This is what keeps a real key from ever colliding with a synthetic
		// __name / __distance entry in an order list.
		$this->assertSame( 'name', lfndr_sanitize_field_key( '__name' ) );
		$this->assertSame( '', lfndr_sanitize_field_key( '___' ) );
	}

	public function test_field_key_rejects_leading_digit_and_overlong(): void {
		$this->assertSame( '', lfndr_sanitize_field_key( '2nd_address' ) );
		$this->assertSame( '', lfndr_sanitize_field_key( 'a' . str_repeat( 'b', 60 ) ) );
	}

	/* ── Whole-schema sanitization ──────────────────────────────────────── */

	public function test_non_array_yields_the_default_schema(): void {
		$this->assertSame( lfndr_default_schema(), lfndr_sanitize_schema( 'nonsense' ) );
		$this->assertSame( lfndr_default_schema(), lfndr_sanitize_schema( null ) );
	}

	public function test_unknown_type_drops_the_field(): void {
		$schema = lfndr_sanitize_schema(
			array( 'fields' => array( $this->field( 'mystery', 'not_a_real_type' ) ) )
		);
		$this->assertSame( array(), $schema['fields'] );
	}

	public function test_duplicate_keys_keep_only_the_first(): void {
		$schema = lfndr_sanitize_schema(
			array(
				'fields' => array(
					$this->field( 'phone', 'phone', array( 'label' => 'First' ) ),
					$this->field( 'phone', 'text', array( 'label' => 'Second' ) ),
				),
			)
		);
		$this->assertCount( 1, $schema['fields'] );
		$this->assertSame( 'First', $schema['fields'][0]['label'] );
		$this->assertSame( 'phone', $schema['fields'][0]['type'] );
	}

	public function test_missing_label_falls_back_to_the_key(): void {
		$schema = lfndr_sanitize_schema(
			array( 'fields' => array( array( 'key' => 'contact_email', 'type' => 'email' ) ) )
		);
		$this->assertSame( 'Contact email', $schema['fields'][0]['label'] );
	}

	public function test_filterable_is_forced_off_for_types_that_cannot_filter(): void {
		// The registry says text has no facet_tokens. The Fields screen grays
		// the checkbox from the same value, but a hand-posted form must not win.
		$schema = lfndr_sanitize_schema(
			array( 'fields' => array( $this->field( 'notes', 'text', array( 'filterable' => true ) ) ) )
		);
		$this->assertFalse( $schema['fields'][0]['filterable'] );
	}

	public function test_filterable_survives_for_types_that_can_filter(): void {
		$schema = lfndr_sanitize_schema(
			array(
				'fields' => array(
					$this->field( 'open', 'boolean', array( 'filterable' => true ) ),
				),
			)
		);
		$this->assertTrue( $schema['fields'][0]['filterable'] );
	}

	/* ── Options ────────────────────────────────────────────────────────── */

	public function test_options_are_deduped_by_value_and_slugged(): void {
		$schema = lfndr_sanitize_schema(
			array(
				'fields' => array(
					$this->field(
						'services',
						'multiselect',
						array(
							'options' => array(
								array( 'value' => 'Period Supplies', 'label' => 'Period supplies' ),
								array( 'value' => 'period-supplies', 'label' => 'Duplicate' ),
								array( 'value' => '', 'label' => 'Empty' ),
							),
						)
					),
				),
			)
		);
		$options = $schema['fields'][0]['options'];
		$this->assertCount( 1, $options );
		$this->assertSame( 'period-supplies', $options[0]['value'] );
		$this->assertSame( 'Period supplies', $options[0]['label'] );
	}

	public function test_option_without_a_label_falls_back_to_its_value(): void {
		$schema = lfndr_sanitize_schema(
			array(
				'fields' => array(
					$this->field( 'kind', 'select', array( 'options' => array( array( 'value' => 'church' ) ) ) ),
				),
			)
		);
		$this->assertSame( 'church', $schema['fields'][0]['options'][0]['label'] );
	}

	/* ── Primary instances ──────────────────────────────────────────────── */

	/* A role cannot be claimed twice — that is now structural rather than
	 * reconciled, so what there is to test is that each type has its own slot
	 * and that assigning one never disturbs another. */
	public function test_each_type_has_its_own_role_slot(): void {
		$schema = lfndr_sanitize_schema(
			array(
				'fields'  => array(
					array( 'key' => 'a', 'type' => 'address', 'label' => 'A' ),
					array( 'key' => 'b', 'type' => 'address', 'label' => 'B' ),
					array( 'key' => 'c', 'type' => 'hours', 'label' => 'C' ),
				),
				'primary' => array( 'address' => 'b', 'hours' => 'c' ),
			)
		);
		$this->assertSame( 'b', $schema['primary']['address'], 'Any instance may hold the role, not just the first.' );
		$this->assertSame( 'c', $schema['primary']['hours'], 'A different type has its own slot.' );
		$this->assertSame( '', $schema['primary']['closures'], 'An unassigned role stays empty.' );
	}

	public function test_a_role_may_be_moved_between_fields_of_a_type(): void {
		/* The bug this shape exists to prevent: with a checkbox per field, the
		 * second field to claim the role lost silently, so it could never be
		 * moved in one save. */
		$fields = array(
			array( 'key' => 'a', 'type' => 'address', 'label' => 'A' ),
			array( 'key' => 'b', 'type' => 'address', 'label' => 'B' ),
		);
		$first  = lfndr_sanitize_schema( array( 'fields' => $fields, 'primary' => array( 'address' => 'a' ) ) );
		$moved  = lfndr_sanitize_schema( array( 'fields' => $fields, 'primary' => array( 'address' => 'b' ) ) );
		$this->assertSame( 'a', $first['primary']['address'] );
		$this->assertSame( 'b', $moved['primary']['address'] );
	}

	public function test_primary_field_falls_back_to_the_first_of_its_type(): void {
		// An admin who defines one address and never opens the panel still
		// expects Directions to work.
		$schema = lfndr_sanitize_schema(
			array( 'fields' => array( array( 'key' => 'a', 'type' => 'address', 'label' => 'A' ) ) )
		);
		$this->assertSame( '', $schema['primary']['address'] );
		$this->assertSame( 'a', lfndr_primary_field( 'address', $schema )['key'] );
	}

	public function test_the_v2_migration_lifts_the_old_flags_into_roles(): void {
		$migrated = lfndr_migrate_schema_2(
			array(
				'fields' => array(
					array( 'key' => 'pantry', 'type' => 'hours', 'settings' => array( 'primary' => false ) ),
					array( 'key' => 'office', 'type' => 'hours', 'settings' => array( 'primary' => true ) ),
					array( 'key' => 'cl', 'type' => 'closures', 'settings' => array( 'primary' => true, 'suspends' => 'pantry' ) ),
				),
			)
		);
		$this->assertSame( 'office', $migrated['primary']['hours'], 'A flagged field outranks an old suspends pointer.' );
		$this->assertSame( 'cl', $migrated['primary']['closures'] );
		$this->assertSame( $migrated, lfndr_migrate_schema_2( $migrated ), 'Migrations must be idempotent.' );
	}

	public function test_the_v2_migration_falls_back_to_suspends_then_to_order(): void {
		$from_suspends = lfndr_migrate_schema_2(
			array(
				'fields' => array(
					array( 'key' => 'h1', 'type' => 'hours', 'settings' => array() ),
					array( 'key' => 'h2', 'type' => 'hours', 'settings' => array() ),
					array( 'key' => 'cl', 'type' => 'closures', 'settings' => array( 'suspends' => 'h2' ) ),
				),
			)
		);
		$this->assertSame( 'h2', $from_suspends['primary']['hours'], 'suspends is better evidence of intent than field order.' );

		$from_order = lfndr_migrate_schema_2(
			array( 'fields' => array( array( 'key' => 'h1', 'type' => 'hours', 'settings' => array() ) ) )
		);
		$this->assertSame( 'h1', $from_order['primary']['hours'] );
	}

	/* ── Orders ─────────────────────────────────────────────────────────── */

	public function test_order_drops_unknown_keys_and_dedupes(): void {
		$valid = array( 'phone', 'website', '__name' );
		$this->assertSame(
			array( '__name', 'phone' ),
			lfndr_sanitize_order( array( '__name', 'phone', 'ghost', 'phone' ), $valid )
		);
	}

	public function test_order_accepts_a_comma_string_from_the_hidden_input(): void {
		$this->assertSame(
			array( '__name', 'phone' ),
			lfndr_sanitize_order( '__name,phone', array( 'phone', '__name' ) )
		);
	}

	public function test_resolve_order_appends_flagged_fields_missing_from_the_list(): void {
		// The self-healing property: add a field on the Fields screen and it
		// shows up immediately rather than invisibly.
		$schema = lfndr_sanitize_schema(
			array(
				'fields'       => array(
					$this->field( 'phone', 'phone', array( 'show_detail' => true ) ),
					$this->field( 'website', 'url', array( 'show_detail' => true ) ),
				),
				'detail_order' => array( '__name', 'phone' ),
			)
		);

		$resolved = lfndr_resolve_order( 'detail', $schema );
		$this->assertSame( array( '__name', 'phone', 'website' ), array_column( $resolved, 'key' ) );
	}

	public function test_resolve_order_skips_fields_not_flagged_for_that_surface(): void {
		$schema = lfndr_sanitize_schema(
			array(
				'fields'     => array(
					$this->field( 'phone', 'phone', array( 'show_card' => false, 'show_detail' => true ) ),
					$this->field( 'city', 'text', array( 'show_card' => true, 'show_detail' => false ) ),
				),
				'card_order' => array( '__name', 'phone', 'city' ),
			)
		);

		$this->assertSame( array( '__name', 'city' ), array_column( lfndr_resolve_order( 'card', $schema ), 'key' ) );
		$this->assertSame( array( 'phone' ), array_column( lfndr_resolve_order( 'detail', $schema ), 'key' ) );
	}

	/* ── Retirement ─────────────────────────────────────────────────────── */

	public function test_a_retired_key_that_came_back_live_is_dropped_from_retired(): void {
		// Otherwise the Fields screen would offer to erase the meta the live
		// field is now reading.
		$schema = lfndr_sanitize_schema(
			array(
				'fields'  => array( $this->field( 'phone', 'phone' ) ),
				'retired' => array( $this->field( 'phone', 'text' ), $this->field( 'fax', 'text' ) ),
			)
		);
		$this->assertSame( array( 'fax' ), array_column( $schema['retired'], 'key' ) );
	}

	/* ── Versioning ─────────────────────────────────────────────────────── */

	public function test_saving_a_schema_is_visible_to_the_next_read(): void {
		// Regression: the memo used to live in lfndr_get_schema()'s own static,
		// which no writer could reach. Any caller that saved and then read in
		// one request — WP-CLI, an importer, this test — got the old schema
		// back, and a location save driven by it silently skipped the new field.
		$this->assertSame( array(), lfndr_get_schema()['fields'] );

		lfndr_save_schema(
			lfndr_sanitize_schema(
				array( 'fields' => array( $this->field( 'phone', 'phone' ) ) )
			)
		);

		$this->assertSame( array( 'phone' ), array_column( lfndr_get_schema()['fields'], 'key' ) );
	}

	public function test_a_newer_schema_is_returned_untouched(): void {
		update_option(
			'lfndr_schema',
			array(
				'version'      => LFNDR_SCHEMA_VERSION + 5,
				'fields'       => array(),
				'detail_order' => array( '__future' ),
				'card_order'   => array(),
				'retired'      => array(),
			)
		);
		$schema = lfndr_get_schema();
		$this->assertSame( LFNDR_SCHEMA_VERSION + 5, $schema['version'] );
		$this->assertSame( array( '__future' ), $schema['detail_order'], 'A downgrade must not normalize data it does not understand.' );
	}
}
