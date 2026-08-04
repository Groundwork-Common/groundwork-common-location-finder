<?php
/**
 * Which filters are worth showing, given the data that actually exists.
 *
 * @package LocationFinder
 */

defined( 'ABSPATH' ) || exit;

/* ── Never render a filter that cannot change the results ────────────────────
 * This is one function because it has to be. The PHP that draws the filter rail
 * and the JavaScript that evaluates a click are two implementations of the same
 * question, and when they disagree the symptom is a chip that visibly returns
 * nothing — which reads as a broken site, not as an empty category.
 *
 * So the rail is built from this function's output, and every location's
 * `facets` map is built from the same token callbacks. They agree by
 * construction rather than by being written carefully twice.
 *
 * The rules below are about usefulness, not correctness. A chip for a value no
 * location has is not wrong, it is just guaranteed to disappoint. A yes/no
 * toggle where every location answers yes filters nothing; where every location
 * answers no it returns an empty list. Both are worth not drawing.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Build the filter groups for a set of locations.
 *
 * @param array $locations Payload locations.
 * @param array $schema    Schema.
 * @return array<int, array>
 */
function lfndr_available_facets( array $locations, array $schema ): array {
	$types  = lfndr_field_types();
	$groups = array();

	foreach ( $schema['fields'] as $field ) {
		if ( empty( $field['filterable'] ) ) {
			continue;
		}
		$type = $types[ $field['type'] ] ?? null;
		if ( null === $type || empty( $type['facet_tokens'] ) ) {
			continue;
		}

		$counts = lfndr_facet_counts( $locations, $field['key'] );
		if ( ! $counts ) {
			continue;
		}

		$group = lfndr_build_facet_group( $field, $type, $counts, count( $locations ) );
		if ( null !== $group ) {
			$groups[] = $group;
		}
	}

	/**
	 * Filter the rendered filter groups.
	 *
	 * @param array $groups    Filter groups.
	 * @param array $locations Payload locations.
	 * @param array $schema    Schema.
	 */
	return apply_filters( 'lfndr_available_facets', $groups, $locations, $schema );
}

/**
 * Count how many locations carry each token of one field.
 *
 * @param array  $locations Payload locations.
 * @param string $key       Field key.
 * @return array<string, int>
 */
function lfndr_facet_counts( array $locations, string $key ): array {
	$counts = array();
	foreach ( $locations as $location ) {
		foreach ( (array) ( $location['facets'][ $key ] ?? array() ) as $token ) {
			$token            = (string) $token;
			$counts[ $token ] = ( $counts[ $token ] ?? 0 ) + 1;
		}
	}
	return $counts;
}

/**
 * Turn one field's token counts into a renderable filter group, or null.
 *
 * @param array $field  Field definition.
 * @param array $type   Type registry entry.
 * @param array $counts token => count.
 * @param int   $total  Total locations.
 * @return array|null
 */
function lfndr_build_facet_group( array $field, array $type, array $counts, int $total ): ?array {
	$label = '' !== $field['filter_label'] ? $field['filter_label'] : $field['label'];

	if ( 'boolean' === $field['type'] ) {
		$yes = $counts['1'] ?? 0;
		/* Both extremes are useless: a toggle everything satisfies filters
		 * nothing, and one nothing satisfies always empties the list. */
		if ( 0 === $yes || $yes >= $total ) {
			return null;
		}
		return array(
			'key'    => $field['key'],
			'type'   => 'boolean',
			'widget' => 'toggle',
			'label'  => $label,
			'match'  => 'any',
			'values' => array(
				array(
					'value' => '1',
					'label' => '' !== ( $field['settings']['true_label'] ?? '' ) ? $field['settings']['true_label'] : $label,
					'count' => $yes,
				),
			),
		);
	}

	if ( 'hours' === $field['type'] ) {
		if ( empty( $field['settings']['open_today'] ) || empty( $counts['has-hours'] ) ) {
			return null;
		}
		return array(
			'key'    => $field['key'],
			'type'   => 'hours',
			'widget' => 'toggle',
			'label'  => $label,
			'match'  => 'any',
			/* The browser decides what "open today" means against its own
			 * clock; all this says is that somebody has a schedule to check.
			 *
			 * And so this one carries no count. The number the server could
			 * offer is "locations with any schedule at all", which on a Monday
			 * evening might be five while the filter returns one — a count that
			 * confidently contradicts the result is worse than no count. */
			'values' => array(
				array(
					'value' => 'open-today',
					'label' => __( 'Open today', 'groundwork-common-location-finder' ),
					'count' => null,
				),
			),
		);
	}

	if ( in_array( $field['type'], array( 'select', 'multiselect' ), true ) ) {
		$labels = wp_list_pluck( $field['options'], 'label', 'value' );

		$values = array();
		foreach ( $field['options'] as $option ) {
			$count = $counts[ $option['value'] ] ?? 0;
			if ( $count < 1 ) {
				continue;
			}
			$values[] = array(
				'value' => $option['value'],
				'label' => $labels[ $option['value'] ] ?? $option['value'],
				'count' => $count,
			);
		}

		/* A single-choice field where every location gives the same answer
		 * separates nothing. A multi-choice field with one present value still
		 * separates the locations that have it from those that do not, so one
		 * is enough there. */
		$minimum = 'select' === $field['type'] ? 2 : 1;
		if ( count( $values ) < $minimum ) {
			return null;
		}

		$widget = $field['filter_widget'];
		if ( '' === $widget ) {
			/* Past about five options a row of chips stops being scannable and
			 * a native select is both smaller and better on a phone. */
			$widget = count( $values ) > 5 ? 'select' : 'chips';
		}

		return array(
			'key'    => $field['key'],
			'type'   => $field['type'],
			'widget' => $widget,
			'label'  => $label,
			/* AND within a multi-choice field: picking Diapers and Formula means
			 * locations offering both. OR within a single-choice field, because
			 * its values are mutually exclusive and AND would always be empty —
			 * a trap worth naming, since the two look identical in the UI. */
			'match'  => 'multiselect' === $field['type'] ? 'all' : 'any',
			'values' => $values,
		);
	}

	unset( $type );
	return null;
}
