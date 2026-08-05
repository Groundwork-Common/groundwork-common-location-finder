<?php
/**
 * Temporary-closure date validation and sanitization.
 *
 * @package LocationFinder
 */

use PHPUnit\Framework\TestCase;

final class ClosuresTest extends TestCase {

	protected function setUp(): void {
		lfndr_test_reset();
	}

	/** A closures field with the given settings. */
	private function field( array $settings = array() ): array {
		return array(
			'key'      => 'closures',
			'type'     => 'closures',
			'label'    => 'Closures',
			'settings' => lfndr_settings_closures( $settings ),
		);
	}

	/** A date offset from today, in the finder's timezone. */
	private function day( int $offset ): string {
		return ( new DateTimeImmutable( 'now', lfndr_timezone() ) )
			->modify( sprintf( '%+d days', $offset ) )
			->format( 'Y-m-d' );
	}

	/* ── Date validation ────────────────────────────────────────────────── */

	public function test_a_real_date_survives(): void {
		$this->assertSame( '2026-12-24', lfndr_sanitize_closure_date( '2026-12-24' ) );
	}

	public function test_a_date_that_does_not_exist_is_rejected(): void {
		// The whole reason for the round-trip check: DateTime accepts
		// 2026-02-30 and rolls it forward to March 2nd, so a typo would become
		// a real closure on days nobody chose, with nothing to show for it.
		$this->assertSame( '', lfndr_sanitize_closure_date( '2026-02-30' ) );
		$this->assertSame( '', lfndr_sanitize_closure_date( '2026-13-01' ) );
		$this->assertSame( '', lfndr_sanitize_closure_date( '2026-04-31' ) );
	}

	public function test_a_leap_day_is_accepted_only_in_a_leap_year(): void {
		$this->assertSame( '2028-02-29', lfndr_sanitize_closure_date( '2028-02-29' ) );
		$this->assertSame( '', lfndr_sanitize_closure_date( '2027-02-29' ) );
	}

	public function test_other_date_formats_are_rejected(): void {
		$this->assertSame( '', lfndr_sanitize_closure_date( '12/24/2026' ) );
		$this->assertSame( '', lfndr_sanitize_closure_date( '2026-12-24T10:00:00Z' ) );
		$this->assertSame( '', lfndr_sanitize_closure_date( 'tomorrow' ) );
		$this->assertSame( '', lfndr_sanitize_closure_date( '' ) );
	}

	/* ── Row sanitization ───────────────────────────────────────────────── */

	public function test_a_missing_end_date_makes_it_a_single_day(): void {
		$rows = lfndr_sanitize_closures(
			array(
				array(
					'start'  => $this->day( 3 ),
					'reason' => 'Holiday',
				),
			),
			$this->field()
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( $rows[0]['start'], $rows[0]['end'] );
	}

	public function test_an_end_before_the_start_is_dropped(): void {
		$rows = lfndr_sanitize_closures(
			array(
				array(
					'start' => $this->day( 10 ),
					'end'   => $this->day( 5 ),
				),
			),
			$this->field()
		);
		$this->assertSame( array(), $rows );
	}

	public function test_a_closure_that_has_already_ended_is_dropped(): void {
		// They cannot affect anything, they accumulate forever, and each one is
		// a row the browser re-checks on every render.
		$rows = lfndr_sanitize_closures(
			array(
				array(
					'start' => $this->day( -30 ),
					'end'   => $this->day( -20 ),
				),
				array(
					'start' => $this->day( 1 ),
					'end'   => $this->day( 2 ),
				),
			),
			$this->field()
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( $this->day( 1 ), $rows[0]['start'] );
	}

	public function test_a_closure_ending_today_is_kept(): void {
		// It is still in force for the rest of the day.
		$rows = lfndr_sanitize_closures(
			array(
				array(
					'start' => $this->day( -3 ),
					'end'   => $this->day( 0 ),
				),
			),
			$this->field()
		);
		$this->assertCount( 1, $rows );
	}

	public function test_rows_without_a_start_are_dropped(): void {
		$rows = lfndr_sanitize_closures(
			array(
				array(
					'end'    => $this->day( 5 ),
					'reason' => 'Orphan',
				),
				'not an array',
			),
			$this->field()
		);
		$this->assertSame( array(), $rows );
	}

	public function test_rows_are_sorted_by_date(): void {
		$rows = lfndr_sanitize_closures(
			array(
				array( 'start' => $this->day( 20 ) ),
				array( 'start' => $this->day( 5 ) ),
				array( 'start' => $this->day( 12 ) ),
			),
			$this->field()
		);
		$this->assertSame(
			array( $this->day( 5 ), $this->day( 12 ), $this->day( 20 ) ),
			array_column( $rows, 'start' )
		);
	}

	public function test_the_reason_is_truncated_to_the_configured_length(): void {
		$rows = lfndr_sanitize_closures(
			array(
				array(
					'start'  => $this->day( 1 ),
					'reason' => str_repeat( 'x', 500 ),
				),
			),
			$this->field( array( 'reason_max' => 40 ) )
		);
		$this->assertSame( 40, mb_strlen( $rows[0]['reason'] ) );
	}

	public function test_max_rows_caps_the_list(): void {
		$rows = lfndr_sanitize_closures(
			array(
				array( 'start' => $this->day( 1 ) ),
				array( 'start' => $this->day( 2 ) ),
				array( 'start' => $this->day( 3 ) ),
			),
			$this->field( array( 'max_rows' => 2 ) )
		);
		$this->assertCount( 2, $rows );
		// The soonest survive, because those are the ones a visitor needs.
		$this->assertSame( $this->day( 1 ), $rows[0]['start'] );
	}

	/* ── Payload ────────────────────────────────────────────────────────── */

	public function test_the_payload_precomputes_nothing_time_dependent(): void {
		$rows    = array(
			array(
				'start'  => $this->day( 0 ),
				'end'    => $this->day( 1 ),
				'reason' => 'Pipes',
			),
		);
		$payload = lfndr_payload_closures( $rows );

		$this->assertSame( $rows, $payload );
		// An "active" flag baked in here would still be asserting yesterday's
		// answer for up to an hour after midnight, because the payload cache
		// boundary has nothing to do with the date changing.
		$this->assertArrayNotHasKey( 'active', $payload[0] );
	}

	/* ── Settings ───────────────────────────────────────────────────────── */

	public function test_settings_are_clamped_to_sane_ranges(): void {
		$settings = lfndr_settings_closures(
			array(
				'reason_max'     => 5,
				'lookahead_days' => 9999,
				'max_rows'       => -3,
			)
		);
		$this->assertSame( 20, $settings['reason_max'] );
		$this->assertSame( 365, $settings['lookahead_days'] );
		$this->assertSame( 0, $settings['max_rows'] );
	}

	/* ── The closures role ────────────────────────────────────────────────
	 * `suspends` used to live on the closures field, naming the hours it
	 * overrode. It is now one of three schema roles assigned together, so these
	 * cover the same ground against the new shape: the reference resolves, and
	 * it is cleared rather than left dangling when it cannot.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_the_closures_role_holds_a_field_key(): void {
		$schema = lfndr_sanitize_schema(
			array(
				'fields'  => array(
					array(
						'key'   => 'closures',
						'type'  => 'closures',
						'label' => 'Closures',
					),
				),
				'primary' => array( 'closures' => 'closures' ),
			)
		);
		$this->assertSame( 'closures', $schema['primary']['closures'] );
	}

	public function test_a_role_survives_when_it_names_a_field_of_the_right_type(): void {
		$schema = lfndr_sanitize_schema(
			array(
				'fields'  => array(
					array(
						'key'   => 'hours',
						'type'  => 'hours',
						'label' => 'Hours',
					),
					array(
						'key'   => 'closures',
						'type'  => 'closures',
						'label' => 'Closures',
					),
				),
				'primary' => array(
					'hours'    => 'hours',
					'closures' => 'closures',
				),
			)
		);
		$this->assertSame( 'hours', $schema['primary']['hours'] );
		$this->assertSame( 'closures', $schema['primary']['closures'] );
	}

	public function test_a_role_is_cleared_when_its_field_is_gone(): void {
		// Retire the hours field and the role has to go with it, or behavior
		// is driven off a field the Fields screen no longer lists.
		$schema = lfndr_sanitize_schema(
			array(
				'fields'  => array(
					array(
						'key'   => 'closures',
						'type'  => 'closures',
						'label' => 'Closures',
					),
				),
				'primary' => array( 'hours' => 'hours' ),
			)
		);
		$this->assertSame( '', $schema['primary']['hours'] );
	}

	public function test_a_role_will_not_point_at_a_field_of_the_wrong_type(): void {
		$schema = lfndr_sanitize_schema(
			array(
				'fields'  => array(
					array(
						'key'   => 'hours',
						'type'  => 'text',
						'label' => 'Hours, as text',
					),
				),
				'primary' => array( 'hours' => 'hours' ),
			)
		);
		$this->assertSame( '', $schema['primary']['hours'] );
	}

	public function test_closures_settings_no_longer_carry_suspends(): void {
		// The pairing moved to the schema's roles; a stale stored value must not
		// travel back in through the settings sanitizer.
		$settings = lfndr_settings_closures(
			array(
				'suspends' => 'hours',
				'primary'  => true,
			)
		);
		$this->assertArrayNotHasKey( 'suspends', $settings );
		$this->assertArrayNotHasKey( 'primary', $settings );
	}
}
