<?php
/**
 * The address field type: a composite of named subfields.
 *
 * @package LocationFinder
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'gwc_lfndr_field_types', 'gwc_lfndr_register_address_type' );

/**
 * Register the address type.
 *
 * @param array $types Registry.
 * @return array
 */
function gwc_lfndr_register_address_type( array $types ): array {
	$types['address'] = array(
		'label'             => __( 'Address', 'groundwork-common-location-finder' ),
		'group'             => 'composite',
		'multiple'          => true,
		'render_admin'      => 'gwc_lfndr_admin_address',
		'sanitize'          => 'gwc_lfndr_sanitize_address',
		'is_empty'          => 'gwc_lfndr_empty_address',
		'to_payload'        => 'gwc_lfndr_payload_address',
		'search_text'       => 'gwc_lfndr_search_address',
		'facet_tokens'      => null,
		'schema_form'       => 'gwc_lfndr_schema_form_address',
		'sanitize_settings' => 'gwc_lfndr_settings_address',
		'needs_present'     => true,
		'can_be_primary'    => true,
		'js'                => 'address',
	);
	return $types;
}

/* ── Why an address is one field and not six ─────────────────────────────────
 * It could have been six text fields, and an admin who wanted them separately
 * could still have that. But four behaviors need to know that a group of
 * strings is *an address* rather than six unrelated strings:
 *
 *   - the geocoder, which needs to hand a single line to Nominatim and write
 *     the parsed result back into the right boxes;
 *   - the Directions link, which needs a formatted address rather than a
 *     coordinate pair (see gwc_lfndr_directions_url() for why that matters);
 *   - the card, which shows "Birmingham, AL" while the detail pane shows all
 *     of it;
 *   - search, which should match a postal code without also matching every
 *     number that happens to appear in a phone field.
 *
 * Six loose text fields can express the data but not any of those. Hence one
 * composite, with the subfields configurable — because "Region" and "Postal
 * code" are a state and a ZIP in one country and something else in the next.
 * ─────────────────────────────────────────────────────────────────────────── */

/** The subfields an address may have, in rendering order. */
const GWC_LFNDR_ADDRESS_SUBFIELD_KEYS = array( 'line1', 'line2', 'city', 'region', 'postal', 'country' );

/**
 * The subfields this particular address field uses, with their labels.
 *
 * @param array $field Field definition.
 * @return array<string, string>
 */
function gwc_lfndr_address_parts( array $field ): array {
	$enabled = $field['settings']['subfields'] ?? GWC_LFNDR_ADDRESS_SUBFIELD_KEYS;
	$enabled = array_values( array_intersect( GWC_LFNDR_ADDRESS_SUBFIELD_KEYS, (array) $enabled ) );
	$labels  = gwc_lfndr_address_subfields();
	$custom  = (array) ( $field['settings']['labels'] ?? array() );

	$out = array();
	foreach ( $enabled as $key ) {
		$out[ $key ] = '' !== ( $custom[ $key ] ?? '' ) ? (string) $custom[ $key ] : $labels[ $key ];
	}
	return $out;
}

/**
 * Render the address subfield inputs.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Input name prefix.
 */
function gwc_lfndr_admin_address( array $field, $value, string $name ): void {
	$value = is_array( $value ) ? $value : array();
	$parts = gwc_lfndr_address_parts( $field );

	/* The geocoder fills one address, and which one is a schema-level role
		now rather than a flag on this field. */
	$role    = gwc_lfndr_primary_field( 'address' );
	$primary = null !== $role && $role['key'] === $field['key'];

	printf(
		'<div class="lfndr-address"%1$s data-lfndr-region-format="%2$s">',
		$primary ? ' data-lfndr-geocode-target="1"' : '',
		esc_attr( (string) ( $field['settings']['region_format'] ?? 'name' ) )
	);

	if ( $primary ) {
		/* The search box lives inside the primary address so the script has an
		 * unambiguous set of inputs to write into. A second address field is
		 * typed by hand — guessing which one a geocoded result belongs to is a
		 * question with no right answer. */
		printf(
			'<p class="lfndr-address__search">
				<label for="%1$s">%2$s</label><br />
				<input type="search" class="regular-text" id="%1$s" autocomplete="off" role="combobox"
					aria-expanded="false" aria-autocomplete="list" aria-controls="%3$s"
					data-lfndr-geocode="1" placeholder="%4$s" />
				<span class="lfndr-address__status" role="status"></span>
			</p>
			<ul class="lfndr-address__results" id="%3$s" role="listbox" hidden></ul>',
			esc_attr( 'lfndr-geo-' . $field['key'] ),
			esc_html__( 'Find an address', 'groundwork-common-location-finder' ),
			esc_attr( 'lfndr-geo-results-' . $field['key'] ),
			esc_attr__( 'Start typing an address…', 'groundwork-common-location-finder' )
		);
	}

	echo '<div class="lfndr-address__grid">';
	foreach ( $parts as $key => $label ) {
		printf(
			'<p class="lfndr-address__part lfndr-address__part--%1$s">
				<label for="%2$s">%3$s</label><br />
				<input type="text" id="%2$s" name="%4$s[%1$s]" value="%5$s" data-lfndr-address-part="%1$s" class="regular-text" />
			</p>',
			esc_attr( $key ),
			esc_attr( 'lfndr-f-' . $field['key'] . '-' . $key ),
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( (string) ( $value[ $key ] ?? '' ) )
		);
	}
	echo '</div></div>';
}

/**
 * Sanitize an address value.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return array
 */
function gwc_lfndr_sanitize_address( $raw, array $field ): array {
	$raw   = is_array( $raw ) ? $raw : array();
	$parts = gwc_lfndr_address_parts( $field );
	$out   = array();

	foreach ( array_keys( $parts ) as $key ) {
		$value = sanitize_text_field( is_scalar( $raw[ $key ] ?? null ) ? (string) $raw[ $key ] : '' );
		if ( 'country' === $key ) {
			$value = strtoupper( $value );
		}
		$out[ $key ] = $value;
	}

	if ( '' === ( $out['country'] ?? '' ) && isset( $parts['country'] ) ) {
		$out['country'] = strtoupper( (string) ( $field['settings']['default_country'] ?? '' ) );
	}

	return $out;
}

/**
 * True when every subfield is blank.
 *
 * The default country does not count as content. Otherwise a schema with a
 * default country would give every location a non-empty address it never had,
 * which would then render an empty address block on every card.
 *
 * @param mixed $value Value.
 * @return bool
 */
function gwc_lfndr_empty_address( $value ): bool {
	if ( ! is_array( $value ) ) {
		return true;
	}
	foreach ( $value as $key => $part ) {
		if ( 'country' !== $key && '' !== trim( (string) $part ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Build the payload for an address: its parts, a formatted line, directions.
 *
 * @param mixed $value   Stored value.
 * @param array $field   Field definition.
 * @param int   $post_id Post ID.
 * @return array
 */
function gwc_lfndr_payload_address( $value, array $field, int $post_id ): array {
	$value = is_array( $value ) ? $value : array();
	$out   = array();

	foreach ( array_keys( gwc_lfndr_address_parts( $field ) ) as $key ) {
		$out[ $key ] = (string) ( $value[ $key ] ?? '' );
	}

	$out['formatted'] = gwc_lfndr_format_address( $value, $field );
	$out['short']     = gwc_lfndr_format_address( $value, $field, (array) ( $field['settings']['card_parts'] ?? array( 'city', 'region' ) ) );

	$role = gwc_lfndr_primary_field( 'address' );
	if ( null !== $role && $role['key'] === $field['key'] && ! empty( $field['settings']['directions'] ) ) {
		$out['directionsUrl'] = gwc_lfndr_directions_url(
			$out['formatted'],
			(string) get_post_meta( $post_id, '_gwc_lfndr_lat', true ),
			(string) get_post_meta( $post_id, '_gwc_lfndr_lng', true )
		);
	}

	return $out;
}

/**
 * Join address parts into one line.
 *
 * @param array      $value Stored value.
 * @param array      $field Field definition.
 * @param array|null $only  Restrict to these subfields.
 * @return string
 */
function gwc_lfndr_format_address( array $value, array $field, ?array $only = null ): string {
	$parts = array_keys( gwc_lfndr_address_parts( $field ) );
	if ( null !== $only ) {
		$parts = array_values( array_intersect( $parts, $only ) );
	}

	/** Read one part, or '' if it is not in use here. */
	$part = static function ( string $key ) use ( $parts, $value ): string {
		return in_array( $key, $parts, true ) ? trim( (string) ( $value[ $key ] ?? '' ) ) : '';
	};

	/* Assembled in a fixed order rather than in whatever order the subfields
	 * happen to be configured, because address order is a convention, not a
	 * preference: street, then locality, then region and postal code together,
	 * then country. Region and postal are joined by a space — "Birmingham, AL,
	 * 35203" is not how anybody writes it, and geocoders parse it worse. */
	$region_postal = implode( ' ', array_filter( array( $part( 'region' ), $part( 'postal' ) ) ) );

	$segments = array(
		$part( 'line1' ),
		$part( 'line2' ),
		$part( 'city' ),
		$region_postal,
		$part( 'country' ),
	);

	return implode( ', ', array_filter( $segments, static fn( string $s ): bool => '' !== $s ) );
}

/**
 * Search contribution: only the subfields the admin marked searchable.
 *
 * @param mixed $value Value.
 * @param array $field Field definition.
 * @return string
 */
function gwc_lfndr_search_address( $value, array $field ): string {
	if ( ! is_array( $value ) ) {
		return '';
	}
	$search = (array) ( $field['settings']['search_parts'] ?? array( 'line1', 'city', 'region', 'postal' ) );
	$out    = array();
	foreach ( $search as $key ) {
		$piece = trim( (string) ( $value[ $key ] ?? '' ) );
		if ( '' !== $piece ) {
			$out[] = $piece;
		}
	}
	return implode( ' ', $out );
}

/**
 * Settings sanitizer for an address field.
 *
 * @param array $raw Raw settings.
 * @return array
 */
function gwc_lfndr_settings_address( array $raw ): array {
	$subfields = array_values( array_intersect( GWC_LFNDR_ADDRESS_SUBFIELD_KEYS, (array) ( $raw['subfields'] ?? GWC_LFNDR_ADDRESS_SUBFIELD_KEYS ) ) );
	if ( ! $subfields ) {
		$subfields = GWC_LFNDR_ADDRESS_SUBFIELD_KEYS;
	}

	$labels = array();
	foreach ( (array) ( $raw['labels'] ?? array() ) as $key => $label ) {
		$key = sanitize_key( (string) $key );
		if ( in_array( $key, GWC_LFNDR_ADDRESS_SUBFIELD_KEYS, true ) ) {
			$labels[ $key ] = sanitize_text_field( (string) $label );
		}
	}

	return array(
		'subfields'       => $subfields,
		'labels'          => $labels,
		'default_country' => strtoupper( sanitize_text_field( (string) ( $raw['default_country'] ?? '' ) ) ),
		'region_format'   => 'code' === ( $raw['region_format'] ?? '' ) ? 'code' : 'name',
		'card_parts'      => array_values( array_intersect( $subfields, (array) ( $raw['card_parts'] ?? array( 'city', 'region' ) ) ) ),
		'search_parts'    => array_values( array_intersect( $subfields, (array) ( $raw['search_parts'] ?? array( 'line1', 'city', 'region', 'postal' ) ) ) ),
		'directions'      => ! isset( $raw['directions'] ) || ! empty( $raw['directions'] ),
	);
}

/**
 * Extra Fields-screen controls for an address field.
 *
 * @param array $field Field definition.
 */
function gwc_lfndr_schema_form_address( array $field ): void {
	$settings  = $field['settings'];
	$enabled   = (array) ( $settings['subfields'] ?? GWC_LFNDR_ADDRESS_SUBFIELD_KEYS );
	$card      = (array) ( $settings['card_parts'] ?? array( 'city', 'region' ) );
	$searchset = (array) ( $settings['search_parts'] ?? array( 'line1', 'city', 'region', 'postal' ) );
	$labels    = gwc_lfndr_address_subfields();
	$custom    = (array) ( $settings['labels'] ?? array() );

	echo '<table class="widefat striped lfndr-subfields"><thead><tr>';
	printf( '<th scope="col">%s</th>', esc_html__( 'Part', 'groundwork-common-location-finder' ) );
	printf( '<th scope="col">%s</th>', esc_html__( 'Use', 'groundwork-common-location-finder' ) );
	printf( '<th scope="col">%s</th>', esc_html__( 'Label', 'groundwork-common-location-finder' ) );
	printf( '<th scope="col">%s</th>', esc_html__( 'On cards', 'groundwork-common-location-finder' ) );
	printf( '<th scope="col">%s</th>', esc_html__( 'Searchable', 'groundwork-common-location-finder' ) );
	echo '</tr></thead><tbody>';

	foreach ( GWC_LFNDR_ADDRESS_SUBFIELD_KEYS as $key ) {
		printf( '<tr><td><code>%s</code></td>', esc_html( $key ) );
		printf(
			'<td><input type="checkbox" name="settings[subfields][]" value="%1$s"%2$s aria-label="%3$s" /></td>',
			esc_attr( $key ),
			checked( in_array( $key, $enabled, true ), true, false ),
			/* translators: %s: address part name. */
			esc_attr( sprintf( __( 'Use the %s part', 'groundwork-common-location-finder' ), $labels[ $key ] ) )
		);
		printf(
			'<td><input type="text" name="settings[labels][%1$s]" value="%2$s" placeholder="%3$s" aria-label="%4$s" /></td>',
			esc_attr( $key ),
			esc_attr( (string) ( $custom[ $key ] ?? '' ) ),
			esc_attr( $labels[ $key ] ),
			/* translators: %s: address part name. */
			esc_attr( sprintf( __( 'Label for the %s part', 'groundwork-common-location-finder' ), $labels[ $key ] ) )
		);
		printf(
			'<td><input type="checkbox" name="settings[card_parts][]" value="%1$s"%2$s aria-label="%3$s" /></td>',
			esc_attr( $key ),
			checked( in_array( $key, $card, true ), true, false ),
			/* translators: %s: address part name. */
			esc_attr( sprintf( __( 'Show %s on cards', 'groundwork-common-location-finder' ), $labels[ $key ] ) )
		);
		printf(
			'<td><input type="checkbox" name="settings[search_parts][]" value="%1$s"%2$s aria-label="%3$s" /></td>',
			esc_attr( $key ),
			checked( in_array( $key, $searchset, true ), true, false ),
			/* translators: %s: address part name. */
			esc_attr( sprintf( __( 'Search the %s part', 'groundwork-common-location-finder' ), $labels[ $key ] ) )
		);
		echo '</tr>';
	}
	echo '</tbody></table>';

	gwc_lfndr_schema_text_control(
		$field,
		'default_country',
		__( 'Default country', 'groundwork-common-location-finder' ),
		__( 'Pre-filled on new locations. A two-letter code such as US or CA.', 'groundwork-common-location-finder' )
	);
	gwc_lfndr_schema_select_control(
		$field,
		'region_format',
		__( 'When looking up an address, fill the region with', 'groundwork-common-location-finder' ),
		array(
			'name' => __( 'Its full name (Alabama, Île-de-France)', 'groundwork-common-location-finder' ),
			'code' => __( 'Its short code (AL, IDF)', 'groundwork-common-location-finder' ),
		),
		'name',
		__( 'Only affects what the lookup fills in — you can always edit the box afterwards.', 'groundwork-common-location-finder' )
	);
	gwc_lfndr_schema_checkbox_control( $field, 'directions', __( 'Offer a Directions link', 'groundwork-common-location-finder' ), true );
}
