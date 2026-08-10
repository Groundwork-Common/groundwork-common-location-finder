<?php
/**
 * Hour slot sanitization and the three-pass schedule formatter.
 *
 * @package LocationFinder
 */

use PHPUnit\Framework\TestCase;

final class HoursTest extends TestCase {

	protected function setUp(): void {
		gwc_lfndr_test_reset();
		// A 24-hour format keeps these assertions readable and independent of
		// the locale the suite happens to run under.
		update_option( 'time_format', 'H:i' );
		update_option( 'start_of_week', 1 );
	}

	/** A field definition with the given hour settings. */
	private function field( array $settings = array() ): array {
		return array(
			'key'      => 'hours',
			'type'     => 'hours',
			'label'    => 'Hours',
			'settings' => gwc_lfndr_settings_hours( $settings ),
		);
	}

	/** Shorthand for a slot. */
	private function slot( int $day, string $start, string $end = '', string $freq = 'weekly' ): array {
		return array(
			'freq'  => $freq,
			'day'   => $day,
			'start' => $start,
			'end'   => $end,
		);
	}

	/** Collapse slots and flatten to "when: times" strings. */
	private function lines( array $slots, array $settings = array() ): array {
		$field = $this->field( $settings );
		$out   = array();
		foreach ( gwc_lfndr_hour_slot_lines( gwc_lfndr_sanitize_hours( $slots, $field ), $field ) as $line ) {
			$out[] = $line['when'] . ': ' . $line['times'];
		}
		return $out;
	}

	/* ── Time sanitization ──────────────────────────────────────────────── */

	public function test_times_snap_to_the_step(): void {
		$this->assertSame( '09:00', gwc_lfndr_sanitize_hour_time( '09:03', 15 ) );
		$this->assertSame( '09:15', gwc_lfndr_sanitize_hour_time( '09:08', 15 ) );
		$this->assertSame( '09:30', gwc_lfndr_sanitize_hour_time( '09:30', 15 ) );
		// Nearest, not floor: 09:20 is ten minutes from 09:30 and twenty from 09:00.
		$this->assertSame( '09:30', gwc_lfndr_sanitize_hour_time( '09:20', 30 ) );
		$this->assertSame( '09:00', gwc_lfndr_sanitize_hour_time( '09:10', 30 ) );
	}

	public function test_times_are_zero_padded_and_range_checked(): void {
		$this->assertSame( '09:00', gwc_lfndr_sanitize_hour_time( '9:00' ) );
		$this->assertSame( '', gwc_lfndr_sanitize_hour_time( '25:00' ) );
		$this->assertSame( '', gwc_lfndr_sanitize_hour_time( '09:75' ) );
		$this->assertSame( '', gwc_lfndr_sanitize_hour_time( 'noon' ) );
		$this->assertSame( '', gwc_lfndr_sanitize_hour_time( '' ) );
	}

	public function test_snapping_never_rolls_past_midnight(): void {
		// 23:58 rounded to the nearest quarter hour is 24:00, which is not a
		// time. It clamps rather than becoming 00:00 on the following day.
		$this->assertSame( '23:59', gwc_lfndr_sanitize_hour_time( '23:58', 15 ) );
	}

	/* ── Slot sanitization ──────────────────────────────────────────────── */

	public function test_slots_without_a_start_are_dropped(): void {
		$slots = gwc_lfndr_sanitize_hours(
			array(
				$this->slot( 2, '', '11:00' ),
				$this->slot( 2, '09:00', '11:00' ),
			),
			$this->field()
		);
		$this->assertCount( 1, $slots );
	}

	public function test_an_end_before_the_start_becomes_open_ended(): void {
		// Rather than guessing at an overnight window, which the display side
		// has no honest way to render.
		$slots = gwc_lfndr_sanitize_hours( array( $this->slot( 2, '21:00', '02:00' ) ), $this->field() );
		$this->assertSame( '', $slots[0]['end'] );
	}

	public function test_invalid_days_are_dropped(): void {
		$slots = gwc_lfndr_sanitize_hours(
			array( $this->slot( 0, '09:00' ), $this->slot( 8, '09:00' ), $this->slot( 7, '09:00' ) ),
			$this->field()
		);
		$this->assertCount( 1, $slots );
		$this->assertSame( 7, $slots[0]['day'] );
	}

	public function test_duplicate_slots_collapse(): void {
		$slots = gwc_lfndr_sanitize_hours(
			array( $this->slot( 2, '09:00', '11:00' ), $this->slot( 2, '09:00', '11:00' ) ),
			$this->field()
		);
		$this->assertCount( 1, $slots );
	}

	public function test_a_frequency_the_field_does_not_offer_falls_back_to_weekly(): void {
		$slots = gwc_lfndr_sanitize_hours(
			array( $this->slot( 2, '09:00', '11:00', 'last' ) ),
			$this->field( array( 'frequencies' => array( 'weekly' ) ) )
		);
		$this->assertSame( 'weekly', $slots[0]['freq'] );
	}

	public function test_weekly_cannot_be_removed_from_a_field(): void {
		// It is the sanitizer's fallback, so a field that disallowed it could
		// still end up storing it.
		$settings = gwc_lfndr_settings_hours( array( 'frequencies' => array( '2nd', '4th' ) ) );
		$this->assertContains( 'weekly', $settings['frequencies'] );
	}

	/* ── Pass 2: day ranges ─────────────────────────────────────────────── */

	public function test_five_weekday_slots_collapse_to_one_range(): void {
		$slots = array();
		for ( $day = 1; $day <= 5; $day++ ) {
			$slots[] = $this->slot( $day, '09:00', '16:30' );
		}
		$this->assertSame( array( 'Mon–Fri: 09:00–16:30' ), $this->lines( $slots ) );
	}

	public function test_two_consecutive_days_stay_listed_not_ranged(): void {
		// "Mon–Tue" is longer than "Mon, Tue" and reads worse.
		$this->assertSame(
			array( 'Mon, Tue: 09:00–16:30' ),
			$this->lines( array( $this->slot( 1, '09:00', '16:30' ), $this->slot( 2, '09:00', '16:30' ) ) )
		);
	}

	public function test_non_consecutive_days_are_listed(): void {
		$this->assertSame(
			array( 'Mon, Wed, Fri: 09:00–12:00' ),
			$this->lines(
				array(
					$this->slot( 1, '09:00', '12:00' ),
					$this->slot( 3, '09:00', '12:00' ),
					$this->slot( 5, '09:00', '12:00' ),
				)
			)
		);
	}

	public function test_a_run_and_a_stray_day_split_correctly(): void {
		$slots = array();
		for ( $day = 1; $day <= 3; $day++ ) {
			$slots[] = $this->slot( $day, '09:00', '12:00' );
		}
		$slots[] = $this->slot( 6, '09:00', '12:00' );
		$this->assertSame( array( 'Mon–Wed, Sat: 09:00–12:00' ), $this->lines( $slots ) );
	}

	public function test_different_windows_do_not_merge_across_days(): void {
		$this->assertSame(
			array(
				'Mon: 09:00–12:00',
				'Tue: 13:00–17:00',
			),
			$this->lines( array( $this->slot( 1, '09:00', '12:00' ), $this->slot( 2, '13:00', '17:00' ) ) )
		);
	}

	/* ── Pass 1: frequency merging ──────────────────────────────────────── */

	public function test_two_monthly_occurrences_merge_onto_one_line(): void {
		$this->assertSame(
			array( '2nd & 4th Tue: 10:00–12:00' ),
			$this->lines(
				array(
					$this->slot( 2, '10:00', '12:00', '2nd' ),
					$this->slot( 2, '10:00', '12:00', '4th' ),
				)
			)
		);
	}

	public function test_three_consecutive_occurrences_become_a_range(): void {
		$this->assertSame(
			array( '1st–3rd Fri: 08:00–16:30' ),
			$this->lines(
				array(
					$this->slot( 5, '08:00', '16:30', '1st' ),
					$this->slot( 5, '08:00', '16:30', '2nd' ),
					$this->slot( 5, '08:00', '16:30', '3rd' ),
				)
			)
		);
	}

	public function test_weekly_and_monthly_never_merge_into_one_range(): void {
		// The reason pass 1 runs before pass 2: merge days first and a monthly
		// slot would silently join a weekly range.
		$slots = array();
		for ( $day = 1; $day <= 4; $day++ ) {
			$slots[] = $this->slot( $day, '08:00', '16:30' );
		}
		$slots[] = $this->slot( 5, '08:00', '16:30', '1st' );

		$this->assertSame(
			array(
				'Mon–Thu: 08:00–16:30',
				'1st Fri: 08:00–16:30',
			),
			$this->lines( $slots )
		);
	}

	/* ── Pass 3: two windows on one day ─────────────────────────────────── */

	public function test_two_windows_on_one_day_share_a_line(): void {
		// The case a Monday-to-Sunday grid cannot express at all.
		$this->assertSame(
			array( 'Tue: 09:00–11:00, 13:00–16:00' ),
			$this->lines( array( $this->slot( 2, '13:00', '16:00' ), $this->slot( 2, '09:00', '11:00' ) ) )
		);
	}

	public function test_two_windows_sharing_a_start_both_survive(): void {
		// Keyed on start alone, one of these used to overwrite the other.
		$this->assertSame(
			array( 'Tue: 09:00–11:00, 09:00–13:00' ),
			$this->lines( array( $this->slot( 2, '09:00', '11:00' ), $this->slot( 2, '09:00', '13:00' ) ) )
		);
	}

	public function test_an_open_ended_window_says_so(): void {
		$this->assertSame( array( 'Sat: from 09:00' ), $this->lines( array( $this->slot( 6, '09:00' ) ) ) );
	}

	/* ── The full real-world schedule ───────────────────────────────────── */

	public function test_a_complete_real_schedule(): void {
		$slots = array();
		for ( $day = 1; $day <= 4; $day++ ) {
			$slots[] = $this->slot( $day, '08:00', '16:30' );
		}
		foreach ( array( '1st', '2nd', '3rd' ) as $freq ) {
			$slots[] = $this->slot( 5, '08:00', '16:30', $freq );
		}
		$slots[] = $this->slot( 6, '09:00', '14:00', '4th' );

		$this->assertSame(
			array(
				'Mon–Thu: 08:00–16:30',
				'1st–3rd Fri: 08:00–16:30',
				'4th Sat: 09:00–14:00',
			),
			$this->lines( $slots )
		);
	}

	public function test_an_empty_schedule_produces_no_lines(): void {
		$this->assertSame( array(), gwc_lfndr_hour_slot_lines( array(), $this->field() ) );
	}

	/* ── start_of_week ──────────────────────────────────────────────────── */

	public function test_a_sunday_start_week_reorders_lines_but_keeps_mon_fri_intact(): void {
		// Range detection stays on canonical Monday-first indices so the working
		// week is always one run; start_of_week decides only which line prints
		// first. Collapsing "Sun–Thu" and splitting Mon–Fri in two would be
		// technically defensible and wildly unexpected.
		$slots = array( $this->slot( 7, '10:00', '12:00' ) );
		for ( $day = 1; $day <= 5; $day++ ) {
			$slots[] = $this->slot( $day, '09:00', '17:00' );
		}

		$this->assertSame(
			array( 'Mon–Fri: 09:00–17:00', 'Sun: 10:00–12:00' ),
			$this->lines( $slots, array( 'week_start' => 1 ) )
		);

		$this->assertSame(
			array( 'Sun: 10:00–12:00', 'Mon–Fri: 09:00–17:00' ),
			$this->lines( $slots, array( 'week_start' => 0 ) )
		);
	}

	/* ── Payload ────────────────────────────────────────────────────────── */

	public function test_the_payload_ships_raw_slots_alongside_the_lines(): void {
		// Raw slots are what lets the browser decide "open now" against its own
		// clock; the payload is cached for an hour on a boundary that has
		// nothing to do with midnight.
		$field   = $this->field();
		$slots   = gwc_lfndr_sanitize_hours( array( $this->slot( 2, '09:00', '11:00' ) ), $field );
		$payload = gwc_lfndr_payload_hours( $slots, $field );

		$this->assertSame( $slots, $payload['slots'] );
		$this->assertSame( 'Tue', $payload['lines'][0]['when'] );
		$this->assertArrayNotHasKey( 'openNow', $payload, 'Nothing time-dependent may be precomputed here.' );
	}
}
