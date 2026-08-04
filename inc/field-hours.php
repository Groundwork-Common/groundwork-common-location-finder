<?php
/**
 * The recurring-hours field type, and its schedule formatter.
 *
 * @package LocationFinder
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'lfndr_field_types', 'lfndr_register_hours_type' );

/**
 * Register the hours type.
 *
 * @param array $types Registry.
 * @return array
 */
function lfndr_register_hours_type( array $types ): array {
	$types['hours'] = array(
		'label'             => __( 'Opening hours', 'groundwork-common-location-finder' ),
		'group'             => 'composite',
		'multiple'          => true,
		'render_admin'      => 'lfndr_admin_hours',
		'sanitize'          => 'lfndr_sanitize_hours',
		'is_empty'          => 'lfndr_empty_array',
		'to_payload'        => 'lfndr_payload_hours',
		'search_text'       => null,
		'facet_tokens'      => 'lfndr_facet_hours',
		'schema_form'       => 'lfndr_schema_form_hours',
		'sanitize_settings' => 'lfndr_settings_hours',
		'needs_present'     => true,
		'can_be_primary'    => true,
		'js'                => 'hours',
	);
	return $types;
}

/* ── Why a list of slots and not a Monday-to-Sunday grid ─────────────────────
 * A slot is { freq, day, start, end }. A schedule is a list of them.
 *
 * The obvious alternative is a seven-row grid with an open and close time on
 * each. It fails on real data in two ways. Real schedules are sparse — most
 * places in a finder like this are open two or three days — so a grid is mostly
 * empty boxes. And a grid cannot express two windows on one day, which is
 * common enough (a Tuesday morning session and a Tuesday evening one) that
 * "just make two rows" is not an edge case.
 *
 * Frequency lives on the slot rather than on the location because that is the
 * shape of the data: "2nd and 4th Tuesday, 10am to noon" is two slots differing
 * only in frequency, and monthly patterns are a large minority of listings, not
 * an exotic case.
 *
 * What a slot deliberately cannot express: "by appointment", "while supplies
 * last", "closed in August". The first is a separate choice field, the second is
 * a note, and the third is a closure. Trying to encode any of them here turns a
 * time picker into a recurrence-rule editor, and the finder still could not
 * reason about the result.
 * ─────────────────────────────────────────────────────────────────────────── */

const LFNDR_HOUR_STEP_DEFAULT  = 15;
const LFNDR_HOUR_RANGE_DEFAULT = array( '07:00', '21:00' );

/* ── Read, sanitize, sort ───────────────────────────────────────────────── */

/**
 * Snap a time to the configured step, or return '' if it is not a time.
 *
 * @param mixed $raw  Raw value.
 * @param int   $step Step in minutes.
 * @return string
 */
function lfndr_sanitize_hour_time( $raw, int $step = LFNDR_HOUR_STEP_DEFAULT ): string {
	$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
	if ( ! preg_match( '/^([0-9]{1,2}):([0-9]{2})$/', $raw, $m ) ) {
		return '';
	}
	$hours   = (int) $m[1];
	$minutes = (int) $m[2];
	if ( $hours > 23 || $minutes > 59 ) {
		return '';
	}

	$step  = max( 1, min( 60, $step ) );
	$total = $hours * 60 + $minutes;
	$total = (int) ( round( $total / $step ) * $step );
	$total = min( 23 * 60 + 59, max( 0, $total ) );

	return sprintf( '%02d:%02d', intdiv( $total, 60 ), $total % 60 );
}

/**
 * Sanitize a list of hour slots.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return array
 */
function lfndr_sanitize_hours( $raw, array $field ): array {
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$settings    = $field['settings'];
	$step        = (int) ( $settings['step_minutes'] ?? LFNDR_HOUR_STEP_DEFAULT );
	$frequencies = (array) ( $settings['frequencies'] ?? array_keys( lfndr_hour_freqs() ) );

	$out = array();
	foreach ( $raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$day = (int) ( $row['day'] ?? 0 );
		if ( $day < 1 || $day > 7 ) {
			continue;
		}

		$start = lfndr_sanitize_hour_time( $row['start'] ?? '', $step );
		if ( '' === $start ) {
			/* A row with no start time is an empty repeater row somebody added
			 * and never filled in. Dropping it is the only sane reading — there
			 * is no schedule in it to preserve. */
			continue;
		}

		$end = lfndr_sanitize_hour_time( $row['end'] ?? '', $step );
		if ( '' !== $end && $end <= $start ) {
			/* An end at or before the start is either a typo or an attempt at
			 * an overnight window. We store '' — "from 9pm", open-ended — rather
			 * than guessing at midnight-crossing, which the display side has no
			 * way to render honestly. */
			$end = '';
		}

		$freq = sanitize_key( (string) ( $row['freq'] ?? 'weekly' ) );
		if ( ! in_array( $freq, $frequencies, true ) ) {
			$freq = 'weekly';
		}

		$slot = array(
			'freq'  => $freq,
			'day'   => $day,
			'start' => $start,
			'end'   => $end,
		);

		// Exact duplicates are silently collapsed; two identical rows are never
		// meaningful and they double every line of the rendered schedule.
		if ( ! in_array( $slot, $out, true ) ) {
			$out[] = $slot;
		}
	}

	return lfndr_sort_hour_slots( $out );
}

/**
 * Sort slots into a stable, readable order: frequency, then day, then time.
 *
 * @param array $slots Slots.
 * @return array
 */
function lfndr_sort_hour_slots( array $slots ): array {
	usort(
		$slots,
		static function ( array $a, array $b ): int {
			$order = LFNDR_HOUR_FREQ_ORDER;
			return array( $order[ $a['freq'] ] ?? 9, $a['day'], $a['start'] )
				<=> array( $order[ $b['freq'] ] ?? 9, $b['day'], $b['start'] );
		}
	);
	return array_values( $slots );
}

/* ── The formatter ──────────────────────────────────────────────────────── */

/**
 * Collapse a slot list into readable schedule lines.
 *
 * Three passes, in this order:
 *
 *   1. Merge frequencies that share a day and a time window, so a location open
 *      on the 2nd and 4th Tuesday reads "2nd & 4th Tue" and not as two lines.
 *   2. Merge days that share a frequency set and a time window, so five weekly
 *      slots read "Mon–Fri". This is why the day indices are canonically
 *      Monday-first: it makes the near-universal working week one consecutive
 *      run, which is what the range detection keys off.
 *   3. Merge time windows that share a resulting label, so a Tuesday with a
 *      morning and an afternoon session reads "Tue: 9–11am, 1–4pm".
 *
 * The order matters. Merging days first would produce "Mon–Fri" groups that can
 * no longer be told apart by frequency, and a monthly slot would silently join
 * a weekly range.
 *
 * @param array $slots Sanitized slots.
 * @param array $field Field definition.
 * @return array<int, array{when: string, times: string}>
 */
function lfndr_hour_slot_lines( array $slots, array $field = array() ): array {
	if ( ! $slots ) {
		return array();
	}

	$slots = lfndr_sort_hour_slots( $slots );

	// Pass 1: day + window -> set of frequencies.
	$by_day_window = array();
	foreach ( $slots as $slot ) {
		$key = $slot['day'] . '|' . $slot['start'] . '|' . $slot['end'];
		if ( ! isset( $by_day_window[ $key ] ) ) {
			$by_day_window[ $key ] = array(
				'day'    => $slot['day'],
				'start'  => $slot['start'],
				'end'    => $slot['end'],
				'freqs'  => array(),
			);
		}
		if ( ! in_array( $slot['freq'], $by_day_window[ $key ]['freqs'], true ) ) {
			$by_day_window[ $key ]['freqs'][] = $slot['freq'];
		}
	}

	// Pass 2: frequency set + window -> set of days.
	$by_freq_window = array();
	foreach ( $by_day_window as $entry ) {
		$freqs = $entry['freqs'];
		usort(
			$freqs,
			static fn( string $a, string $b ): int =>
				( LFNDR_HOUR_FREQ_ORDER[ $a ] ?? 9 ) <=> ( LFNDR_HOUR_FREQ_ORDER[ $b ] ?? 9 )
		);
		$key = implode( ',', $freqs ) . '|' . $entry['start'] . '|' . $entry['end'];
		if ( ! isset( $by_freq_window[ $key ] ) ) {
			$by_freq_window[ $key ] = array(
				'freqs' => $freqs,
				'start' => $entry['start'],
				'end'   => $entry['end'],
				'days'  => array(),
			);
		}
		$by_freq_window[ $key ]['days'][] = $entry['day'];
	}

	// Pass 3: identical "when" label -> merged list of time windows.
	$lines = array();
	foreach ( $by_freq_window as $entry ) {
		$days = array_values( array_unique( $entry['days'] ) );
		sort( $days );

		$when = lfndr_hour_when_label( $entry['freqs'], $days );
		$time = lfndr_hour_window_label( $entry['start'], $entry['end'] );

		if ( ! isset( $lines[ $when ] ) ) {
			$lines[ $when ] = array(
				'when'  => $when,
				'times' => array(),
				'sort'  => array( LFNDR_HOUR_FREQ_ORDER[ $entry['freqs'][0] ] ?? 9, lfndr_hour_display_index( $days[0], $field ) ),
			);
		}
		/* Keyed by start AND end: two windows can share a start (9–11 and 9–1
		 * on the same day, when a location runs a short session and a long one)
		 * and keying on start alone would silently drop one of them. The key
		 * still sorts by start, which is the order they should print in. */
		$lines[ $when ]['times'][ $entry['start'] . '|' . $entry['end'] ] = $time;
	}

	usort(
		$lines,
		static fn( array $a, array $b ): int => $a['sort'] <=> $b['sort']
	);

	$out = array();
	foreach ( $lines as $line ) {
		ksort( $line['times'] );
		$out[] = array(
			'when'  => $line['when'],
			'times' => implode( ', ', $line['times'] ),
		);
	}

	return $out;
}

/**
 * Where a day sits in this site's week, for ordering output lines.
 *
 * Range detection always runs on the canonical Monday-first indices so that
 * Mon–Fri stays one run everywhere. This only decides which line is printed
 * first, which is where a site's start_of_week genuinely belongs.
 *
 * @param int   $day   Canonical day index, 1-7.
 * @param array $field Field definition.
 * @return int
 */
function lfndr_hour_display_index( int $day, array $field ): int {
	$start = isset( $field['settings']['week_start'] )
		? (int) $field['settings']['week_start']
		: (int) get_option( 'start_of_week', 1 );

	// get_option('start_of_week') is 0-6 with 0 = Sunday; our days are 1-7 with
	// 7 = Sunday.
	$first = 0 === $start ? 7 : min( 7, max( 1, $start ) );

	return ( $day - $first + 7 ) % 7;
}

/**
 * The "when" half of a schedule line: "Mon–Fri", "2nd & 4th Tue".
 *
 * @param array $freqs Frequency slugs, already ordered.
 * @param array $days  Canonical day indices, ascending.
 * @return string
 */
function lfndr_hour_when_label( array $freqs, array $days ): string {
	$day_label = lfndr_hour_days_label( $days );

	if ( array( 'weekly' ) === $freqs ) {
		return $day_label;
	}

	$freq_label = lfndr_hour_freqs_label( $freqs );

	return '' === $freq_label
		? $day_label
		/* translators: 1: recurrence such as "2nd & 4th", 2: day or day range such as "Tue". */
		: sprintf( _x( '%1$s %2$s', 'recurrence and day', 'groundwork-common-location-finder' ), $freq_label, $day_label );
}

/**
 * Collapse day indices into a label, using ranges for consecutive runs.
 *
 * @param array $days Canonical day indices, ascending.
 * @return string
 */
function lfndr_hour_days_label( array $days ): string {
	$names = lfndr_hour_days();
	$runs  = lfndr_consecutive_runs( $days );

	$parts = array();
	foreach ( $runs as $run ) {
		/* Only three or more consecutive days becomes a range. "Mon–Tue" is
		 * longer than "Mon, Tue" and reads worse. */
		if ( count( $run ) >= 3 ) {
			$parts[] = sprintf(
				/* translators: 1: first day, 2: last day. Forms a range like "Mon – Fri". */
				_x( '%1$s–%2$s', 'day range', 'groundwork-common-location-finder' ),
				$names[ $run[0] ][1],
				$names[ end( $run ) ][1]
			);
			continue;
		}
		foreach ( $run as $day ) {
			$parts[] = $names[ $day ][1];
		}
	}

	return implode( ', ', $parts );
}

/**
 * Collapse frequency slugs into a label, using ranges for consecutive runs.
 *
 * @param array $freqs Frequency slugs.
 * @return string
 */
function lfndr_hour_freqs_label( array $freqs ): string {
	$freqs = array_values( array_diff( $freqs, array( 'weekly' ) ) );
	if ( ! $freqs ) {
		return '';
	}

	/* Short forms, not the settings-screen sentences: "2nd & 4th Tue" is the
	 * goal, never "2nd of the month & 4th of the month Tue". */
	$short = array(
		'1st'  => _x( '1st', 'first occurrence in a month', 'groundwork-common-location-finder' ),
		'2nd'  => _x( '2nd', 'second occurrence in a month', 'groundwork-common-location-finder' ),
		'3rd'  => _x( '3rd', 'third occurrence in a month', 'groundwork-common-location-finder' ),
		'4th'  => _x( '4th', 'fourth occurrence in a month', 'groundwork-common-location-finder' ),
		'last' => _x( 'last', 'final occurrence in a month', 'groundwork-common-location-finder' ),
	);

	$indices = array();
	foreach ( $freqs as $freq ) {
		$indices[] = LFNDR_HOUR_FREQ_ORDER[ $freq ] ?? 9;
	}
	sort( $indices );

	$by_index = array_flip( LFNDR_HOUR_FREQ_ORDER );
	$parts    = array();

	foreach ( lfndr_consecutive_runs( $indices ) as $run ) {
		if ( count( $run ) >= 3 ) {
			$parts[] = sprintf(
				/* translators: 1: first occurrence, 2: last occurrence. Forms a range like "1st – 3rd". */
				_x( '%1$s–%2$s', 'occurrence range', 'groundwork-common-location-finder' ),
				$short[ $by_index[ $run[0] ] ] ?? '',
				$short[ $by_index[ end( $run ) ] ] ?? ''
			);
			continue;
		}
		foreach ( $run as $index ) {
			$parts[] = $short[ $by_index[ $index ] ] ?? '';
		}
	}

	return implode( _x( ' & ', 'joins recurrences, as in "2nd & 4th"', 'groundwork-common-location-finder' ), $parts );
}

/**
 * Split an ascending list of integers into runs of consecutive values.
 *
 * @param array $values Ascending integers.
 * @return array<int, array<int, int>>
 */
function lfndr_consecutive_runs( array $values ): array {
	$runs    = array();
	$current = array();

	foreach ( $values as $value ) {
		if ( $current && $value === end( $current ) + 1 ) {
			$current[] = $value;
			continue;
		}
		if ( $current ) {
			$runs[] = $current;
		}
		$current = array( $value );
	}
	if ( $current ) {
		$runs[] = $current;
	}

	return $runs;
}

/**
 * Format one time window.
 *
 * @param string $start 'HH:MM'.
 * @param string $end   'HH:MM' or ''.
 * @return string
 */
function lfndr_hour_window_label( string $start, string $end ): string {
	if ( '' === $end ) {
		return sprintf(
			/* translators: %s: a time of day. Used when a closing time is unknown. */
			__( 'from %s', 'groundwork-common-location-finder' ),
			lfndr_format_time( $start )
		);
	}
	return sprintf(
		/* translators: 1: opening time, 2: closing time. */
		_x( '%1$s–%2$s', 'time range', 'groundwork-common-location-finder' ),
		lfndr_format_time( $start ),
		lfndr_format_time( $end )
	);
}

/**
 * Render 'HH:MM' using the site's own time format.
 *
 * Deliberately not a hardcoded "9am". The site already recorded how it wants
 * times written, in Settings → General, and a finder that ignores that is a
 * finder that looks foreign on its own site. A site wanting the compact form
 * sets its time format to `ga`, which is the WordPress answer to this question.
 *
 * @param string $time 'HH:MM'.
 * @return string
 */
function lfndr_format_time( string $time ): string {
	if ( ! preg_match( '/^([0-9]{2}):([0-9]{2})$/', $time, $m ) ) {
		return $time;
	}
	/* A fixed date, because only the time matters and wp_date() would otherwise
	 * apply today's DST offset to a wall-clock time that has none. */
	$stamp = mktime( (int) $m[1], (int) $m[2], 0, 1, 1, 2001 );
	return (string) wp_date( (string) get_option( 'time_format', 'g:i a' ), $stamp, new DateTimeZone( 'UTC' ) );
}

/* ── Payload ────────────────────────────────────────────────────────────── */

/**
 * Build the hours payload.
 *
 * @param mixed $value Stored value.
 * @param array $field Field definition.
 * @return array
 */
function lfndr_payload_hours( $value, array $field ): array {
	$slots = is_array( $value ) ? $value : array();

	return array(
		/* The raw slots ship alongside the rendered lines, and that is not
		 * redundancy. The lines are time-independent, so they are safe to
		 * compute here and cache for an hour. Whether a location is open *right
		 * now* is not — and the cache boundary has nothing to do with midnight,
		 * so a precomputed "open today" flag would keep asserting yesterday's
		 * answer for up to an hour after the day changed. The browser
		 * recomputes that from the slots against its own clock. */
		'slots' => array_values( $slots ),
		'lines' => lfndr_hour_slot_lines( $slots, $field ),
	);
}

/**
 * Facet tokens for an hours field.
 *
 * Only whether the location has any schedule at all. Whether it is open today
 * is decided in the browser, for the reason above.
 *
 * @param mixed $value Value.
 * @return array
 */
function lfndr_facet_hours( $value ): array {
	return is_array( $value ) && $value ? array( 'has-hours' ) : array();
}

/* ── Admin ──────────────────────────────────────────────────────────────── */

/**
 * Render the hours repeater.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Input name prefix.
 */
function lfndr_admin_hours( array $field, $value, string $name ): void {
	$slots       = is_array( $value ) ? lfndr_sort_hour_slots( $value ) : array();
	$settings    = $field['settings'];
	$frequencies = (array) ( $settings['frequencies'] ?? array_keys( lfndr_hour_freqs() ) );
	$times       = lfndr_hour_time_choices( $settings );

	printf(
		'<div class="lfndr-repeater" data-lfndr-repeater="%s" data-empty-text="%s">',
		esc_attr( $field['key'] ),
		esc_attr__( 'No hours set. This location will not be shown as open at any time.', 'groundwork-common-location-finder' )
	);

	echo '<div class="lfndr-repeater__rows">';
	foreach ( $slots as $index => $slot ) {
		lfndr_render_hour_row( $name, (string) $index, $slot, $frequencies, $times );
	}
	echo '</div>';

	/* The blank row lives in a <template> and carries the literal token __i__
	 * where the index goes. The script clones it and swaps the token, so the
	 * markup exists in exactly one place — PHP — and the two cannot drift as
	 * fields are added. */
	echo '<template class="lfndr-repeater__template">';
	lfndr_render_hour_row( $name, '__i__', array(), $frequencies, $times );
	echo '</template>';

	printf(
		'<p><button type="button" class="button lfndr-repeater__add">%s</button></p>',
		esc_html__( 'Add hours', 'groundwork-common-location-finder' )
	);

	echo '</div>';
}

/**
 * Render one hours row.
 *
 * @param string $name        Input name prefix.
 * @param string $index       Row index, or the template token.
 * @param array  $slot        Slot values.
 * @param array  $frequencies Allowed frequency slugs.
 * @param array  $times       'HH:MM' => label.
 */
function lfndr_render_hour_row( string $name, string $index, array $slot, array $frequencies, array $times ): void {
	$freqs = lfndr_hour_freqs();
	$days  = lfndr_hour_days();
	$base  = $name . '[' . $index . ']';

	echo '<div class="lfndr-repeater__row">';

	printf( '<label><span class="screen-reader-text">%s</span><select name="%s[freq]">', esc_html__( 'Repeats', 'groundwork-common-location-finder' ), esc_attr( $base ) );
	foreach ( $frequencies as $slug ) {
		if ( ! isset( $freqs[ $slug ] ) ) {
			continue;
		}
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $slug ),
			selected( $slot['freq'] ?? 'weekly', $slug, false ),
			esc_html( $freqs[ $slug ] )
		);
	}
	echo '</select></label>';

	printf( '<label><span class="screen-reader-text">%s</span><select name="%s[day]">', esc_html__( 'Day', 'groundwork-common-location-finder' ), esc_attr( $base ) );
	foreach ( $days as $number => $labels ) {
		printf(
			'<option value="%1$d"%2$s>%3$s</option>',
			(int) $number,
			selected( (int) ( $slot['day'] ?? 1 ), $number, false ),
			esc_html( $labels[0] )
		);
	}
	echo '</select></label>';

	foreach ( array( 'start', 'end' ) as $which ) {
		printf(
			'<label><span class="screen-reader-text">%s</span><select name="%s[%s]">',
			'start' === $which ? esc_html__( 'Opens', 'groundwork-common-location-finder' ) : esc_html__( 'Closes', 'groundwork-common-location-finder' ),
			esc_attr( $base ),
			esc_attr( $which )
		);
		printf(
			'<option value="">%s</option>',
			'start' === $which ? esc_html__( '— time —', 'groundwork-common-location-finder' ) : esc_html__( '— no closing time —', 'groundwork-common-location-finder' )
		);
		foreach ( $times as $time => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $time ),
				selected( (string) ( $slot[ $which ] ?? '' ), $time, false ),
				esc_html( $label )
			);
		}
		echo '</select></label>';
	}

	printf(
		'<button type="button" class="button-link lfndr-repeater__remove">%s</button>',
		esc_html__( 'Remove', 'groundwork-common-location-finder' )
	);

	echo '</div>';
}

/**
 * The selectable times, as 'HH:MM' => localised label.
 *
 * A <select> rather than <input type="time">, for three reasons the reference
 * implementation found the hard way: `step="900"` only reports a violation
 * after the fact rather than constraining the picker, Firefox ignores it
 * entirely, and a free time input happily accepts 4:03pm — which no location has
 * ever opened at, and which then has to be snapped on save, silently changing
 * what somebody typed. A select cannot express it, needs no JavaScript, and
 * renders as a native wheel on a phone.
 *
 * @param array $settings Field settings.
 * @return array<string, string>
 */
function lfndr_hour_time_choices( array $settings ): array {
	$step  = max( 5, min( 60, (int) ( $settings['step_minutes'] ?? LFNDR_HOUR_STEP_DEFAULT ) ) );
	$first = lfndr_sanitize_hour_time( $settings['range_start'] ?? LFNDR_HOUR_RANGE_DEFAULT[0], $step );
	$last  = lfndr_sanitize_hour_time( $settings['range_end'] ?? LFNDR_HOUR_RANGE_DEFAULT[1], $step );

	$from = '' !== $first ? lfndr_minutes_of( $first ) : 7 * 60;
	$to   = '' !== $last ? lfndr_minutes_of( $last ) : 21 * 60;
	if ( $to <= $from ) {
		$to = 24 * 60 - $step;
	}

	$out = array();
	for ( $minutes = $from; $minutes <= $to; $minutes += $step ) {
		$time         = sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
		$out[ $time ] = lfndr_format_time( $time );
	}
	return $out;
}

/**
 * Minutes past midnight for an 'HH:MM' string.
 *
 * @param string $time Time.
 * @return int
 */
function lfndr_minutes_of( string $time ): int {
	if ( ! preg_match( '/^([0-9]{2}):([0-9]{2})$/', $time, $m ) ) {
		return 0;
	}
	return (int) $m[1] * 60 + (int) $m[2];
}

/**
 * Settings sanitizer for an hours field.
 *
 * @param array $raw Raw settings.
 * @return array
 */
function lfndr_settings_hours( array $raw ): array {
	$all   = array_keys( lfndr_hour_freqs() );
	$freqs = array_values( array_intersect( $all, (array) ( $raw['frequencies'] ?? $all ) ) );
	if ( ! in_array( 'weekly', $freqs, true ) ) {
		/* Weekly is not optional. It is the fallback the sanitizer assigns to
		 * any slot with an unrecognized frequency, so removing it would let a
		 * slot be saved with a frequency the field claims not to allow. */
		array_unshift( $freqs, 'weekly' );
	}

	$step = (int) ( $raw['step_minutes'] ?? LFNDR_HOUR_STEP_DEFAULT );
	$step = in_array( $step, array( 5, 10, 15, 30, 60 ), true ) ? $step : LFNDR_HOUR_STEP_DEFAULT;

	return array(
		'step_minutes' => $step,
		'range_start'  => lfndr_sanitize_hour_time( $raw['range_start'] ?? LFNDR_HOUR_RANGE_DEFAULT[0], $step ),
		'range_end'    => lfndr_sanitize_hour_time( $raw['range_end'] ?? LFNDR_HOUR_RANGE_DEFAULT[1], $step ),
		'frequencies'  => $freqs,
		'week_start'   => isset( $raw['week_start'] ) ? max( 0, min( 7, (int) $raw['week_start'] ) ) : (int) get_option( 'start_of_week', 1 ),
		'card_rows'    => max( 0, (int) ( $raw['card_rows'] ?? 3 ) ),
		'open_now'     => ! isset( $raw['open_now'] ) || ! empty( $raw['open_now'] ),
		'open_today'   => ! isset( $raw['open_today'] ) || ! empty( $raw['open_today'] ),
	);
}

/**
 * Extra Fields-screen controls for an hours field.
 *
 * @param array $field Field definition.
 */
function lfndr_schema_form_hours( array $field ): void {
	lfndr_schema_select_control(
		$field,
		'step_minutes',
		__( 'Time increments', 'groundwork-common-location-finder' ),
		array(
			'5'  => __( '5 minutes', 'groundwork-common-location-finder' ),
			'10' => __( '10 minutes', 'groundwork-common-location-finder' ),
			'15' => __( '15 minutes', 'groundwork-common-location-finder' ),
			'30' => __( '30 minutes', 'groundwork-common-location-finder' ),
			'60' => __( 'On the hour', 'groundwork-common-location-finder' ),
		),
		(string) LFNDR_HOUR_STEP_DEFAULT
	);

	lfndr_schema_text_control( $field, 'range_start', __( 'Earliest selectable time', 'groundwork-common-location-finder' ), __( '24-hour, such as 07:00.', 'groundwork-common-location-finder' ) );
	lfndr_schema_text_control( $field, 'range_end', __( 'Latest selectable time', 'groundwork-common-location-finder' ), __( '24-hour, such as 21:00.', 'groundwork-common-location-finder' ) );

	$freqs   = lfndr_hour_freqs();
	$enabled = (array) ( $field['settings']['frequencies'] ?? array_keys( $freqs ) );
	echo '<fieldset class="lfndr-setting"><legend>' . esc_html__( 'Recurrence options offered', 'groundwork-common-location-finder' ) . '</legend>';
	foreach ( $freqs as $slug => $label ) {
		printf(
			'<label class="lfndr-inline"><input type="checkbox" name="settings[frequencies][]" value="%1$s"%2$s%3$s /> %4$s</label>',
			esc_attr( $slug ),
			checked( in_array( $slug, $enabled, true ), true, false ),
			'weekly' === $slug ? ' disabled checked' : '',
			esc_html( $label )
		);
	}
	printf( '<p class="description">%s</p></fieldset>', esc_html__( 'Weekly is always available.', 'groundwork-common-location-finder' ) );

	lfndr_schema_number_control( $field, 'card_rows', __( 'Schedule lines to show on a card', 'groundwork-common-location-finder' ), __( '0 shows all of them.', 'groundwork-common-location-finder' ) );
	lfndr_schema_checkbox_control( $field, 'open_now', __( 'Badge locations that are open right now', 'groundwork-common-location-finder' ), true );
	lfndr_schema_checkbox_control( $field, 'open_today', __( 'Offer an "Open today" filter', 'groundwork-common-location-finder' ), true );
}
