<?php
/**
 * Collapsing the colophon, and its thirty-day expiry.
 *
 * The decision is split out from the user-meta read so it can be tested at all:
 * everything interesting is a comparison between two timestamps, and none of it
 * needs WordPress.
 *
 * What these protect is the "never dismissible" part. A collapse that quietly
 * became permanent — an off-by-one in the comparison, a stored flag instead of
 * a timestamp — would look identical in the admin on the day it was set and
 * only be wrong a month later, which is exactly the kind of bug nobody reports.
 *
 * @package GroundworkCommonLocationFinder
 */

class ColophonSnoozeTest extends PHPUnit\Framework\TestCase {

	public function test_never_collapsed_shows_the_panel(): void {
		$this->assertFalse( gwc_lfndr_colophon_snoozed( 0, 1_800_000_000 ) );
	}

	public function test_collapsing_hides_it_immediately(): void {
		$now = 1_800_000_000;
		$this->assertTrue( gwc_lfndr_colophon_snoozed( $now, $now ) );
	}

	public function test_it_stays_hidden_for_the_month(): void {
		$now = 1_800_000_000;

		foreach ( array( 1, 7, 29 ) as $days ) {
			$this->assertTrue(
				gwc_lfndr_colophon_snoozed( $now, $now + ( $days * DAY_IN_SECONDS ) ),
				sprintf( 'Should still be collapsed %d days in.', $days )
			);
		}
	}

	public function test_it_comes_back_after_thirty_days(): void {
		$now = 1_800_000_000;

		// The boundary itself: exactly 30 days is expired, not the last moment
		// of hidden. Worth pinning, because "< 30 days" and "<= 30 days" both
		// look right and differ by a day of visibility.
		$this->assertFalse( gwc_lfndr_colophon_snoozed( $now, $now + ( 30 * DAY_IN_SECONDS ) ) );
		$this->assertFalse( gwc_lfndr_colophon_snoozed( $now, $now + ( 31 * DAY_IN_SECONDS ) ) );
		$this->assertFalse( gwc_lfndr_colophon_snoozed( $now, $now + YEAR_IN_SECONDS ) );
	}

	/**
	 * A clock correction or a bad import can leave a timestamp in the future.
	 * Reading that as collapsed is the harmless answer, and it expires by itself
	 * once the clock catches up — as opposed to a negative interval sending it
	 * somewhere unexpected.
	 */
	public function test_a_future_timestamp_does_not_misbehave(): void {
		$now = 1_800_000_000;
		$this->assertTrue( gwc_lfndr_colophon_snoozed( $now + DAY_IN_SECONDS, $now ) );
	}

	public function test_the_window_is_actually_thirty_days(): void {
		// Guards the constant itself: the tests above would all still pass if
		// the window were changed to a year.
		$this->assertSame( 30 * DAY_IN_SECONDS, GWC_LFNDR_COLOPHON_SNOOZE );
	}
}
