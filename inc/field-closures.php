<?php
/**
 * The temporary-closures field type.
 *
 * @package LocationFinder
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'lfndr_field_types', 'lfndr_register_closures_type' );

/**
 * Register the closures type.
 *
 * @param array $types Registry.
 * @return array
 */
function lfndr_register_closures_type( array $types ): array {
	$types['closures'] = array(
		'label'             => __( 'Temporary closures', 'groundwork-common-location-finder' ),
		'group'             => 'composite',
		'multiple'          => true,
		'render_admin'      => 'lfndr_admin_closures',
		'sanitize'          => 'lfndr_sanitize_closures',
		'is_empty'          => 'lfndr_empty_array',
		'to_payload'        => 'lfndr_payload_closures',
		'search_text'       => null,
		'facet_tokens'      => null,
		'schema_form'       => 'lfndr_schema_form_closures',
		'sanitize_settings' => 'lfndr_settings_closures',
		'needs_present'     => true,
		'can_be_primary'    => true,
		'js'                => 'closures',
	);
	return $types;
}

/* ── Closures are not "hours, but negative" ──────────────────────────────────
 * A closure is a date range with a reason: closed for a holiday, a renovation,
 * a burst pipe. It suspends whatever the schedule says, which is why it is a
 * separate field rather than a slot with a flag — a slot describes a repeating
 * pattern, and a closure is by definition an exception to one.
 *
 * Dates are stored as plain Y-m-d strings, not timestamps. A closure is a
 * statement about calendar days in the location's own locale — "closed the 24th
 * through the 26th" — and a timestamp forces a decision about what time of day
 * that starts, in which timezone, that nobody making the statement intended.
 * ─────────────────────────────────────────────────────────────────────────── */

const LFNDR_CLOSURE_REASON_MAX = 140;

/**
 * Validate a Y-m-d date, rejecting ones that do not exist.
 *
 * The round trip is the whole point: DateTime happily accepts 2026-02-30 and
 * rolls it to March 2nd, so a typo becomes a real closure on the wrong days
 * with nothing to indicate it. Formatting the result back and comparing is what
 * catches it.
 *
 * @param mixed $raw Raw value.
 * @return string
 */
function lfndr_sanitize_closure_date( $raw ): string {
	$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
		return '';
	}
	$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $raw, new DateTimeZone( 'UTC' ) );
	if ( false === $date || $date->format( 'Y-m-d' ) !== $raw ) {
		return '';
	}
	return $raw;
}

/**
 * Sanitize a list of closures.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return array
 */
function lfndr_sanitize_closures( $raw, array $field ): array {
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$max_reason = max( 20, (int) ( $field['settings']['reason_max'] ?? LFNDR_CLOSURE_REASON_MAX ) );
	$max_rows   = max( 0, (int) ( $field['settings']['max_rows'] ?? 0 ) );
	$today      = lfndr_today();

	$out = array();
	foreach ( $raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$start = lfndr_sanitize_closure_date( $row['start'] ?? '' );
		if ( '' === $start ) {
			continue;
		}

		// A closure with no end date is a single day, which is the common case
		// and worth not making people type twice.
		$end = lfndr_sanitize_closure_date( $row['end'] ?? '' );
		if ( '' === $end ) {
			$end = $start;
		}
		if ( $end < $start ) {
			continue;
		}

		/* Closures that ended before today are dropped on save rather than kept
		 * as history. They cannot affect anything, they accumulate forever, and
		 * every one of them is a row the browser has to check on every render.
		 * Anyone wanting a record of past closures wants an audit log, which is
		 * a different feature. */
		if ( $end < $today ) {
			continue;
		}

		$out[] = array(
			'start'  => $start,
			'end'    => $end,
			'reason' => mb_substr( sanitize_text_field( (string) ( $row['reason'] ?? '' ) ), 0, $max_reason ),
		);
	}

	usort(
		$out,
		static fn( array $a, array $b ): int => array( $a['start'], $a['end'] ) <=> array( $b['start'], $b['end'] )
	);

	if ( $max_rows > 0 && count( $out ) > $max_rows ) {
		$out = array_slice( $out, 0, $max_rows );
	}

	return array_values( $out );
}

/**
 * Today's date in the finder's timezone, as Y-m-d.
 *
 * @return string
 */
function lfndr_today(): string {
	return ( new DateTimeImmutable( 'now', lfndr_timezone() ) )->format( 'Y-m-d' );
}

/**
 * Build the closures payload.
 *
 * @param mixed $value Stored value.
 * @return array
 */
function lfndr_payload_closures( $value ): array {
	/* Shipped raw, with no "is this active" flag computed here — see the note in
	 * lfndr_payload_hours(). The payload is cached for an hour on a boundary
	 * unrelated to midnight, so a flag baked in at 11:40pm would still be
	 * asserting yesterday's answer at 12:30am. */
	return is_array( $value ) ? array_values( $value ) : array();
}

/* ── Admin ──────────────────────────────────────────────────────────────── */

/**
 * Render the closures repeater.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Input name prefix.
 */
function lfndr_admin_closures( array $field, $value, string $name ): void {
	$rows     = is_array( $value ) ? $value : array();
	$max_rows = max( 0, (int) ( $field['settings']['max_rows'] ?? 0 ) );

	printf(
		'<div class="lfndr-repeater" data-lfndr-repeater="%1$s"%2$s>',
		esc_attr( $field['key'] ),
		$max_rows > 0 ? ' data-max-rows="' . esc_attr( (string) $max_rows ) . '"' : ''
	);

	echo '<div class="lfndr-repeater__rows">';
	foreach ( $rows as $index => $row ) {
		lfndr_render_closure_row( $name, (string) $index, $row, $field );
	}
	echo '</div>';

	echo '<template class="lfndr-repeater__template">';
	lfndr_render_closure_row( $name, '__i__', array(), $field );
	echo '</template>';

	printf(
		'<p><button type="button" class="button lfndr-repeater__add">%s</button>
		<span class="description">%s</span></p>',
		esc_html__( 'Add a closure', 'groundwork-common-location-finder' ),
		esc_html__( 'Closures that have already ended are removed when you save.', 'groundwork-common-location-finder' )
	);

	echo '</div>';
}

/**
 * Render one closure row.
 *
 * @param string $name  Input name prefix.
 * @param string $index Row index, or the template token.
 * @param array  $row   Row values.
 * @param array  $field Field definition.
 */
function lfndr_render_closure_row( string $name, string $index, array $row, array $field ): void {
	$base = $name . '[' . $index . ']';
	$max  = max( 20, (int) ( $field['settings']['reason_max'] ?? LFNDR_CLOSURE_REASON_MAX ) );

	echo '<div class="lfndr-repeater__row">';

	printf(
		'<label><span class="screen-reader-text">%1$s</span>
			<input type="date" name="%2$s[start]" value="%3$s" /></label>
		<span class="lfndr-repeater__sep">%4$s</span>
		<label><span class="screen-reader-text">%5$s</span>
			<input type="date" name="%2$s[end]" value="%6$s" /></label>
		<label class="lfndr-repeater__grow"><span class="screen-reader-text">%7$s</span>
			<input type="text" name="%2$s[reason]" value="%8$s" maxlength="%9$d" placeholder="%10$s" /></label>
		<button type="button" class="button-link lfndr-repeater__remove">%11$s</button>',
		esc_html__( 'Closed from', 'groundwork-common-location-finder' ),
		esc_attr( $base ),
		esc_attr( (string) ( $row['start'] ?? '' ) ),
		esc_html_x( 'to', 'between two dates', 'groundwork-common-location-finder' ),
		esc_html__( 'Closed through', 'groundwork-common-location-finder' ),
		esc_attr( (string) ( $row['end'] ?? '' ) ),
		esc_html__( 'Reason', 'groundwork-common-location-finder' ),
		esc_attr( (string) ( $row['reason'] ?? '' ) ),
		(int) $max,
		esc_attr__( 'Reason (optional, shown to visitors)', 'groundwork-common-location-finder' ),
		esc_html__( 'Remove', 'groundwork-common-location-finder' )
	);

	echo '</div>';
}

/**
 * Settings sanitizer for a closures field.
 *
 * @param array $raw Raw settings.
 * @return array
 */
function lfndr_settings_closures( array $raw ): array {
	return array(
		'reason_max'     => max( 20, min( 500, (int) ( $raw['reason_max'] ?? LFNDR_CLOSURE_REASON_MAX ) ) ),
		'lookahead_days' => max( 0, min( 365, (int) ( $raw['lookahead_days'] ?? 7 ) ) ),
		'max_rows'       => max( 0, (int) ( $raw['max_rows'] ?? 0 ) ),
	);
}

/**
 * Extra Fields-screen controls for a closures field.
 *
 * @param array $field Field definition.
 */
function lfndr_schema_form_closures( array $field ): void {
	/* Which hours a closure overrides is no longer asked here. The closures
	 * field holding the site's closures role suspends the hours field holding
	 * the hours role, which is the only pairing that was ever coherent — a
	 * closure pointing at some other schedule struck it through while the badge
	 * kept reading from a schedule nothing had closed. Both roles are assigned
	 * together on the Fields screen; see lfndr_render_roles_panel(). */
	lfndr_schema_number_control(
		$field,
		'lookahead_days',
		__( 'Warn this many days ahead', 'groundwork-common-location-finder' ),
		__( 'Shows "Closing on the 24th" before it happens. 0 turns the warning off.', 'groundwork-common-location-finder' )
	);
	lfndr_schema_number_control( $field, 'reason_max', __( 'Longest reason', 'groundwork-common-location-finder' ), '' );
	lfndr_schema_number_control( $field, 'max_rows', __( 'Most closures at once', 'groundwork-common-location-finder' ), __( '0 for no limit.', 'groundwork-common-location-finder' ) );
}
