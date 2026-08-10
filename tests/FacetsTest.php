<?php
/**
 * Which filters get rendered, given the data that exists.
 *
 * @package LocationFinder
 */

use PHPUnit\Framework\TestCase;

final class FacetsTest extends TestCase {

	protected function setUp(): void {
		gwc_lfndr_test_reset();
	}

	/** A schema with one filterable field. */
	private function schema( array $field ): array {
		return gwc_lfndr_sanitize_schema( array( 'fields' => array( $field ) ) );
	}

	/** A payload location carrying the given facet tokens. */
	private function location( array $facets ): array {
		return array(
			'id'     => 1,
			'name'   => 'x',
			'facets' => $facets,
		);
	}

	private function services_field(): array {
		return array(
			'key'        => 'services',
			'type'       => 'multiselect',
			'label'      => 'Services',
			'filterable' => true,
			'options'    => array(
				array(
					'value' => 'diapers',
					'label' => 'Diapers',
				),
				array(
					'value' => 'formula',
					'label' => 'Formula',
				),
				array(
					'value' => 'wipes',
					'label' => 'Wipes',
				),
			),
		);
	}

	private function access_field(): array {
		return array(
			'key'        => 'access',
			'type'       => 'select',
			'label'      => 'Access',
			'filterable' => true,
			'options'    => array(
				array(
					'value' => 'open',
					'label' => 'Open',
				),
				array(
					'value' => 'appointment',
					'label' => 'By appointment',
				),
			),
		);
	}

	/* ── Values with no data get no chip ────────────────────────────────── */

	public function test_only_values_present_in_the_data_get_a_chip(): void {
		$schema = $this->schema( $this->services_field() );
		$groups = gwc_lfndr_available_facets(
			array(
				$this->location( array( 'services' => array( 'diapers' ) ) ),
				$this->location( array( 'services' => array( 'diapers', 'formula' ) ) ),
			),
			$schema
		);

		$this->assertCount( 1, $groups );
		// `wipes` is defined but nobody offers it, so it is not offered as a filter.
		$this->assertSame( array( 'diapers', 'formula' ), array_column( $groups[0]['values'], 'value' ) );
		$this->assertSame( array( 2, 1 ), array_column( $groups[0]['values'], 'count' ) );
	}

	public function test_a_field_with_no_data_at_all_renders_no_group(): void {
		$groups = gwc_lfndr_available_facets(
			array( $this->location( array() ) ),
			$this->schema( $this->services_field() )
		);
		$this->assertSame( array(), $groups );
	}

	public function test_a_field_not_marked_filterable_renders_no_group(): void {
		$field               = $this->services_field();
		$field['filterable'] = false;

		$groups = gwc_lfndr_available_facets(
			array( $this->location( array( 'services' => array( 'diapers' ) ) ) ),
			$this->schema( $field )
		);
		$this->assertSame( array(), $groups );
	}

	/* ── Match semantics ────────────────────────────────────────────────── */

	public function test_multi_choice_fields_use_and_within_the_field(): void {
		$groups = gwc_lfndr_available_facets(
			array( $this->location( array( 'services' => array( 'diapers', 'formula' ) ) ) ),
			$this->schema( $this->services_field() )
		);
		$this->assertSame( 'all', $groups[0]['match'] );
	}

	public function test_single_choice_fields_use_or_within_the_field(): void {
		// Their values are mutually exclusive, so AND would always be empty.
		$groups = gwc_lfndr_available_facets(
			array(
				$this->location( array( 'access' => array( 'open' ) ) ),
				$this->location( array( 'access' => array( 'appointment' ) ) ),
			),
			$this->schema( $this->access_field() )
		);
		$this->assertSame( 'any', $groups[0]['match'] );
	}

	/* ── Single-choice needs two present values ─────────────────────────── */

	public function test_a_single_choice_field_where_everyone_answers_the_same_is_skipped(): void {
		$groups = gwc_lfndr_available_facets(
			array(
				$this->location( array( 'access' => array( 'open' ) ) ),
				$this->location( array( 'access' => array( 'open' ) ) ),
			),
			$this->schema( $this->access_field() )
		);
		$this->assertSame( array(), $groups, 'A filter that separates nothing should not be drawn.' );
	}

	public function test_a_multi_choice_field_with_one_present_value_still_renders(): void {
		// Unlike a single choice, it still separates the locations that have it
		// from the ones that do not.
		$groups = gwc_lfndr_available_facets(
			array(
				$this->location( array( 'services' => array( 'diapers' ) ) ),
				$this->location( array() ),
			),
			$this->schema( $this->services_field() )
		);
		$this->assertCount( 1, $groups );
	}

	/* ── Booleans ───────────────────────────────────────────────────────── */

	private function boolean_field(): array {
		return array(
			'key'        => 'wheelchair',
			'type'       => 'boolean',
			'label'      => 'Wheelchair accessible',
			'filterable' => true,
		);
	}

	public function test_a_boolean_that_is_true_everywhere_renders_no_toggle(): void {
		$groups = gwc_lfndr_available_facets(
			array(
				$this->location( array( 'wheelchair' => array( '1' ) ) ),
				$this->location( array( 'wheelchair' => array( '1' ) ) ),
			),
			$this->schema( $this->boolean_field() )
		);
		$this->assertSame( array(), $groups, 'A toggle everything satisfies filters nothing.' );
	}

	public function test_a_boolean_that_is_false_everywhere_renders_no_toggle(): void {
		// False is stored as absence throughout, so "false everywhere" is
		// indistinguishable from "nobody filled it in" — and neither is worth a
		// toggle, because both always return an empty list.
		$groups = gwc_lfndr_available_facets(
			array( $this->location( array() ), $this->location( array() ) ),
			$this->schema( $this->boolean_field() )
		);
		$this->assertSame( array(), $groups );
	}

	public function test_only_the_true_state_produces_a_token(): void {
		$this->assertSame( array( '1' ), gwc_lfndr_facet_boolean( true ) );
		$this->assertSame( array(), gwc_lfndr_facet_boolean( false ) );
		$this->assertSame( array(), gwc_lfndr_facet_boolean( '' ) );
	}

	public function test_a_mixed_boolean_renders_one_toggle(): void {
		$groups = gwc_lfndr_available_facets(
			array(
				$this->location( array( 'wheelchair' => array( '1' ) ) ),
				$this->location( array() ),
			),
			$this->schema( $this->boolean_field() )
		);
		$this->assertCount( 1, $groups );
		$this->assertSame( 'toggle', $groups[0]['widget'] );
		$this->assertSame( 1, $groups[0]['values'][0]['count'] );
	}

	/* ── Widget choice ──────────────────────────────────────────────────── */

	public function test_many_values_switch_from_chips_to_a_select(): void {
		$options = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$options[] = array(
				'value' => 'v' . $i,
				'label' => 'Value ' . $i,
			);
		}
		$field = array(
			'key'        => 'kind',
			'type'       => 'select',
			'label'      => 'Kind',
			'filterable' => true,
			'options'    => $options,
		);

		$locations = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$locations[] = $this->location( array( 'kind' => array( 'v' . $i ) ) );
		}

		$groups = gwc_lfndr_available_facets( $locations, $this->schema( $field ) );
		$this->assertSame( 'select', $groups[0]['widget'] );
	}

	public function test_an_explicit_widget_choice_wins(): void {
		$field                  = $this->services_field();
		$field['filter_widget'] = 'select';

		$groups = gwc_lfndr_available_facets(
			array( $this->location( array( 'services' => array( 'diapers' ) ) ) ),
			$this->schema( $field )
		);
		$this->assertSame( 'select', $groups[0]['widget'] );
	}

	public function test_the_filter_heading_falls_back_to_the_field_label(): void {
		$field                 = $this->services_field();
		$field['filter_label'] = 'Offers';

		$groups = gwc_lfndr_available_facets(
			array( $this->location( array( 'services' => array( 'diapers' ) ) ) ),
			$this->schema( $field )
		);
		$this->assertSame( 'Offers', $groups[0]['label'] );
	}

	/* ── Hours ──────────────────────────────────────────────────────────── */

	public function test_hours_offer_an_open_today_toggle_when_any_schedule_exists(): void {
		$field = array(
			'key'        => 'hours',
			'type'       => 'hours',
			'label'      => 'Hours',
			'filterable' => true,
		);

		$groups = gwc_lfndr_available_facets(
			array( $this->location( array( 'hours' => array( 'has-hours' ) ) ) ),
			$this->schema( $field )
		);
		$this->assertCount( 1, $groups );
		$this->assertSame( 'open-today', $groups[0]['values'][0]['value'] );
	}

	public function test_the_open_today_toggle_carries_no_count(): void {
		// The only number the server could offer is "locations with any
		// schedule", which on a Monday evening might be five while the filter
		// returns one. A count that contradicts the result is worse than none.
		$field = array(
			'key'        => 'hours',
			'type'       => 'hours',
			'label'      => 'Hours',
			'filterable' => true,
		);

		$groups = gwc_lfndr_available_facets(
			array(
				$this->location( array( 'hours' => array( 'has-hours' ) ) ),
				$this->location( array( 'hours' => array( 'has-hours' ) ) ),
			),
			$this->schema( $field )
		);
		$this->assertNull( $groups[0]['values'][0]['count'] );
	}

	public function test_hours_offer_nothing_when_the_field_disables_open_today(): void {
		$field = array(
			'key'        => 'hours',
			'type'       => 'hours',
			'label'      => 'Hours',
			'filterable' => true,
			'settings'   => array( 'open_today' => false ),
		);

		$groups = gwc_lfndr_available_facets(
			array( $this->location( array( 'hours' => array( 'has-hours' ) ) ) ),
			$this->schema( $field )
		);
		$this->assertSame( array(), $groups );
	}
}
