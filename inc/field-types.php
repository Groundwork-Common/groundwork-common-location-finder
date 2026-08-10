<?php
/**
 * The field type registry, and the nine simple types.
 *
 * @package LocationFinder
 */

defined( 'ABSPATH' ) || exit;

/* ── One registry, seven callables, no switch statements ─────────────────────
 * Every type is an array of function names. Nothing in this plugin branches on
 * $field['type'] outside this file; everything looks the type up here and calls
 * what it finds. That is not architecture for its own sake — it is the only way
 * the "admin defines the fields" requirement can also mean "a developer can add
 * a field type", which is the difference between a configurable plugin and a
 * plugin with a fixed list of nine things.
 *
 * The contract:
 *
 *   render_admin  (array $field, mixed $value, string $name): void
 *                 Print the meta box control. $name is the fully-formed
 *                 name="" prefix, e.g. gwc_lfndr_f[phone].
 *   sanitize      (mixed $raw, array $field): mixed
 *                 Raw POST → the value stored in post meta. Never trusts input.
 *   is_empty      (mixed $value, array $field): bool
 *                 True means delete the meta row rather than store the value.
 *   to_payload    (mixed $value, array $field, int $post_id): mixed
 *                 Stored value → the JSON the browser receives. May precompute,
 *                 but see the note about time-dependence in locations.php.
 *   search_text   (mixed $value, array $field): string
 *                 Contribution to the location's search blob. null = not
 *                 searchable, and the Fields screen grays the checkbox.
 *   facet_tokens  (mixed $value, array $field): array
 *                 Filterable values. null = not filterable, same graying.
 *   schema_form   (array $field): void
 *                 Extra controls on the Fields screen for this type.
 *
 * Two optional extras: sanitize_settings for types with a settings bag worth
 * validating, and `js`, the key of the renderer in
 * window.LocationFinder.renderers.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The type registry.
 *
 * @return array<string, array>
 */
function gwc_lfndr_field_types(): array {
	static $types = null;
	if ( null !== $types ) {
		return $types;
	}

	$simple = array(
		'text'     => array(
			'label' => __( 'Text', 'groundwork-common-location-finder' ),
			'js'    => 'text',
		),
		'textarea' => array(
			'label' => __( 'Long text', 'groundwork-common-location-finder' ),
			'js'    => 'textarea',
		),
		'url'      => array(
			'label' => __( 'Website', 'groundwork-common-location-finder' ),
			'js'    => 'url',
		),
		'email'    => array(
			'label' => __( 'Email', 'groundwork-common-location-finder' ),
			'js'    => 'email',
		),
		'phone'    => array(
			'label' => __( 'Phone', 'groundwork-common-location-finder' ),
			'js'    => 'phone',
		),
		'number'   => array(
			'label' => __( 'Number', 'groundwork-common-location-finder' ),
			'js'    => 'number',
		),
	);

	$types = array();
	foreach ( $simple as $key => $meta ) {
		$types[ $key ] = array(
			'label'        => $meta['label'],
			'group'        => 'simple',
			'multiple'     => true,
			'render_admin' => 'gwc_lfndr_admin_' . $key,
			'sanitize'     => 'gwc_lfndr_sanitize_' . $key,
			'is_empty'     => 'gwc_lfndr_empty_scalar',
			'to_payload'   => 'gwc_lfndr_payload_scalar',
			'search_text'  => 'number' === $key ? null : 'gwc_lfndr_search_scalar',
			'facet_tokens' => null,
			'schema_form'  => 'gwc_lfndr_schema_form_' . $key,
			'js'           => $meta['js'],
		);
	}

	$types['boolean'] = array(
		'label'             => __( 'Yes / no', 'groundwork-common-location-finder' ),
		'group'             => 'simple',
		'multiple'          => true,
		'render_admin'      => 'gwc_lfndr_admin_boolean',
		'sanitize'          => 'gwc_lfndr_sanitize_boolean',
		'is_empty'          => 'gwc_lfndr_empty_boolean',
		'to_payload'        => 'gwc_lfndr_payload_boolean',
		'search_text'       => null,
		'facet_tokens'      => 'gwc_lfndr_facet_boolean',
		'schema_form'       => 'gwc_lfndr_schema_form_boolean',
		'sanitize_settings' => 'gwc_lfndr_settings_boolean',
		'needs_present'     => true,
		'js'                => 'boolean',
	);

	$types['select'] = array(
		'label'             => __( 'Choice (one)', 'groundwork-common-location-finder' ),
		'group'             => 'choice',
		'multiple'          => true,
		'render_admin'      => 'gwc_lfndr_admin_select',
		'sanitize'          => 'gwc_lfndr_sanitize_select',
		'is_empty'          => 'gwc_lfndr_empty_scalar',
		'to_payload'        => 'gwc_lfndr_payload_scalar',
		'search_text'       => 'gwc_lfndr_search_choice',
		'facet_tokens'      => 'gwc_lfndr_facet_select',
		'schema_form'       => 'gwc_lfndr_schema_form_select',
		'sanitize_settings' => 'gwc_lfndr_settings_select',
		'has_options'       => true,
		'js'                => 'select',
	);

	$types['multiselect'] = array(
		'label'         => __( 'Choice (many)', 'groundwork-common-location-finder' ),
		'group'         => 'choice',
		'multiple'      => true,
		'render_admin'  => 'gwc_lfndr_admin_multiselect',
		'sanitize'      => 'gwc_lfndr_sanitize_multiselect',
		'is_empty'      => 'gwc_lfndr_empty_array',
		'to_payload'    => 'gwc_lfndr_payload_array',
		'search_text'   => 'gwc_lfndr_search_choice',
		'facet_tokens'  => 'gwc_lfndr_facet_multiselect',
		'schema_form'   => 'gwc_lfndr_schema_form_multiselect',
		'has_options'   => true,
		'needs_present' => true,
		'js'            => 'multiselect',
	);

	/**
	 * Register a custom field type.
	 *
	 * A third party registering a type must also register a front-end renderer:
	 *
	 *     window.LocationFinder.renderers.mytype = function (value, field, ctx) {
	 *         return document.createTextNode(String(value)); // Node or null
	 *     };
	 *
	 * enqueued with 'groundwork-common-location-finder' as a dependency. Without one the field
	 * falls back to plain text rather than breaking the card.
	 *
	 * @param array $types Registry keyed by type slug.
	 */
	$types = apply_filters( 'gwc_lfndr_field_types', $types );

	return $types;
}

/* ── Shared sanitizers and payload helpers ──────────────────────────────── */

/**
 * True when a scalar value should not be stored.
 *
 * @param mixed $value Value.
 * @return bool
 */
function gwc_lfndr_empty_scalar( $value ): bool {
	return '' === $value || null === $value;
}

/**
 * True when an array value should not be stored.
 *
 * @param mixed $value Value.
 * @return bool
 */
function gwc_lfndr_empty_array( $value ): bool {
	return ! is_array( $value ) || 0 === count( $value );
}

/**
 * Booleans are stored only when true.
 *
 * Storing a literal false would double the meta rows for no gain: absent and
 * false mean the same thing to every consumer, and "absent" is what an
 * unchecked box has always meant in WordPress.
 *
 * @param mixed $value Value.
 * @return bool
 */
function gwc_lfndr_empty_boolean( $value ): bool {
	return empty( $value );
}

/**
 * Pass a scalar through to the payload unchanged.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function gwc_lfndr_payload_scalar( $value ) {
	return $value;
}

/**
 * Pass a list through to the payload as a re-indexed array.
 *
 * @param mixed $value Value.
 * @return array
 */
function gwc_lfndr_payload_array( $value ): array {
	return is_array( $value ) ? array_values( $value ) : array();
}

/**
 * Booleans reach the browser as booleans, not as '1'.
 *
 * @param mixed $value Value.
 * @return bool
 */
function gwc_lfndr_payload_boolean( $value ): bool {
	return ! empty( $value );
}

/**
 * Default search contribution: the value itself.
 *
 * @param mixed $value Value.
 * @return string
 */
function gwc_lfndr_search_scalar( $value ): string {
	return is_scalar( $value ) ? (string) $value : '';
}

/**
 * Search contribution for a choice field: the labels, not the slugs.
 *
 * Someone typing "period supplies" should match a location whose stored value
 * is `period-supplies`. Including both costs nothing and the slug form is
 * occasionally what an admin remembers.
 *
 * @param mixed $value Value.
 * @param array $field Field definition.
 * @return string
 */
function gwc_lfndr_search_choice( $value, array $field ): string {
	$values = is_array( $value ) ? $value : array( $value );
	$labels = array();
	foreach ( $field['options'] as $option ) {
		$labels[ $option['value'] ] = $option['label'];
	}
	$parts = array();
	foreach ( $values as $one ) {
		$one = (string) $one;
		if ( '' === $one ) {
			continue;
		}
		$parts[] = $one;
		if ( isset( $labels[ $one ] ) ) {
			$parts[] = $labels[ $one ];
		}
	}
	return implode( ' ', $parts );
}

/* ── text ───────────────────────────────────────────────────────────────── */

/**
 * Render the admin control for a text field.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Input name.
 */
function gwc_lfndr_admin_text( array $field, $value, string $name ): void {
	$max = (int) ( $field['settings']['maxlength'] ?? 0 );
	printf(
		'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s"%5$s />',
		esc_attr( 'lfndr-f-' . $field['key'] ),
		esc_attr( $name ),
		esc_attr( (string) $value ),
		esc_attr( $field['placeholder'] ),
		$max > 0 ? ' maxlength="' . esc_attr( (string) $max ) . '"' : ''
	);
}

/**
 * Sanitize a text value.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return string
 */
function gwc_lfndr_sanitize_text( $raw, array $field ): string {
	$value = sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );
	$max   = (int) ( $field['settings']['maxlength'] ?? 0 );
	return $max > 0 ? mb_substr( $value, 0, $max ) : $value;
}

/**
 * Extra Fields-screen controls for a text field.
 *
 * @param array $field Field definition.
 */
function gwc_lfndr_schema_form_text( array $field ): void {
	gwc_lfndr_schema_number_control(
		$field,
		'maxlength',
		__( 'Maximum length', 'groundwork-common-location-finder' ),
		__( 'Leave at 0 for no limit.', 'groundwork-common-location-finder' )
	);
}

/* ── textarea ───────────────────────────────────────────────────────────── */

/**
 * Render the admin control for a long-text field.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Input name.
 */
function gwc_lfndr_admin_textarea( array $field, $value, string $name ): void {
	printf(
		'<textarea class="large-text" rows="%1$d" id="%2$s" name="%3$s" placeholder="%4$s">%5$s</textarea>',
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an int cast printed through %d; the sniff cannot see through max().
		max( 2, (int) ( $field['settings']['rows'] ?? 4 ) ),
		esc_attr( 'lfndr-f-' . $field['key'] ),
		esc_attr( $name ),
		esc_attr( $field['placeholder'] ),
		esc_textarea( (string) $value )
	);
}

/**
 * Sanitize a long-text value.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return string
 */
function gwc_lfndr_sanitize_textarea( $raw, array $field ): string {
	$value = sanitize_textarea_field( is_scalar( $raw ) ? (string) $raw : '' );
	$max   = (int) ( $field['settings']['maxlength'] ?? 0 );
	return $max > 0 ? mb_substr( $value, 0, $max ) : $value;
}

/**
 * Extra Fields-screen controls for a long-text field.
 *
 * @param array $field Field definition.
 */
function gwc_lfndr_schema_form_textarea( array $field ): void {
	gwc_lfndr_schema_number_control( $field, 'rows', __( 'Rows', 'groundwork-common-location-finder' ), '' );
	gwc_lfndr_schema_number_control(
		$field,
		'maxlength',
		__( 'Maximum length', 'groundwork-common-location-finder' ),
		__( 'Leave at 0 for no limit.', 'groundwork-common-location-finder' )
	);
}

/* ── url ────────────────────────────────────────────────────────────────── */

/**
 * Render the admin control for a website field.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Input name.
 */
function gwc_lfndr_admin_url( array $field, $value, string $name ): void {
	printf(
		'<input type="url" class="regular-text code" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s" inputmode="url" />',
		esc_attr( 'lfndr-f-' . $field['key'] ),
		esc_attr( $name ),
		esc_attr( (string) $value ),
		esc_attr( '' !== $field['placeholder'] ? $field['placeholder'] : 'https://' )
	);
}

/**
 * Sanitize a URL.
 *
 * A bare "example.org" is promoted to https rather than rejected: it is what
 * people type, and esc_url_raw would otherwise return '' and look like the save
 * silently failed.
 *
 * @param mixed $raw Raw value.
 * @return string
 */
function gwc_lfndr_sanitize_url( $raw ): string {
	$value = trim( is_scalar( $raw ) ? (string) $raw : '' );
	if ( '' === $value ) {
		return '';
	}
	if ( ! preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $value ) ) {
		$value = 'https://' . ltrim( $value, '/' );
	}
	return (string) esc_url_raw( $value, array( 'http', 'https' ) );
}

/**
 * Extra Fields-screen controls for a website field.
 *
 * @param array $field Field definition.
 */
function gwc_lfndr_schema_form_url( array $field ): void {
	gwc_lfndr_schema_select_control(
		$field,
		'link_text',
		__( 'Link text', 'groundwork-common-location-finder' ),
		array(
			'host'  => __( 'Domain only (example.org)', 'groundwork-common-location-finder' ),
			'full'  => __( 'Full URL', 'groundwork-common-location-finder' ),
			'label' => __( 'The field label', 'groundwork-common-location-finder' ),
		),
		'host'
	);
}

/* ── email ──────────────────────────────────────────────────────────────── */

/**
 * Render the admin control for an email field.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Input name.
 */
function gwc_lfndr_admin_email( array $field, $value, string $name ): void {
	printf(
		'<input type="email" class="regular-text" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s" inputmode="email" />',
		esc_attr( 'lfndr-f-' . $field['key'] ),
		esc_attr( $name ),
		esc_attr( (string) $value ),
		esc_attr( $field['placeholder'] )
	);
}

/**
 * Sanitize an email address.
 *
 * An address that does not validate is discarded rather than stored as typed.
 * Unlike a phone number, a malformed email has exactly one use — a mailto: link
 * that goes nowhere — so keeping it helps no one.
 *
 * @param mixed $raw Raw value.
 * @return string
 */
function gwc_lfndr_sanitize_email( $raw ): string {
	$value = sanitize_email( is_scalar( $raw ) ? (string) $raw : '' );
	return is_email( $value ) ? $value : '';
}

/**
 * Extra Fields-screen controls for an email field.
 *
 * @param array $field Field definition.
 */
function gwc_lfndr_schema_form_email( array $field ): void {
	gwc_lfndr_schema_checkbox_control(
		$field,
		'mailto',
		__( 'Render as a clickable mailto: link', 'groundwork-common-location-finder' ),
		true
	);
}

/* ── phone ──────────────────────────────────────────────────────────────── */

/**
 * Render the admin control for a phone field.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Input name.
 */
function gwc_lfndr_admin_phone( array $field, $value, string $name ): void {
	printf(
		'<input type="tel" class="regular-text" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s" inputmode="tel" />',
		esc_attr( 'lfndr-f-' . $field['key'] ),
		esc_attr( $name ),
		esc_attr( (string) $value ),
		esc_attr( $field['placeholder'] )
	);
}

/**
 * Sanitize a phone number.
 *
 * Stored as typed, minus quote characters. Formats vary far too much across
 * countries to normalize, and an admin who wrote "(205) 555-0100 ext. 4" meant
 * all of it. The tel: href is derived separately at payload time.
 *
 * @param mixed $raw Raw value.
 * @return string
 */
function gwc_lfndr_sanitize_phone( $raw ): string {
	$value = sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );
	return str_replace( array( '"', "'" ), '', $value );
}

/**
 * Extra Fields-screen controls for a phone field.
 *
 * @param array $field Field definition.
 */
function gwc_lfndr_schema_form_phone( array $field ): void {
	gwc_lfndr_schema_checkbox_control( $field, 'tel_link', __( 'Render as a clickable tel: link', 'groundwork-common-location-finder' ), true );
	gwc_lfndr_schema_checkbox_control( $field, 'mobile_action', __( 'Show a "Call" button on small screens', 'groundwork-common-location-finder' ), false );
}

/* ── number ─────────────────────────────────────────────────────────────── */

/**
 * Render the admin control for a number field.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Input name.
 */
function gwc_lfndr_admin_number( array $field, $value, string $name ): void {
	$settings = $field['settings'];
	$attrs    = '';
	foreach ( array( 'min', 'max', 'step' ) as $attr ) {
		if ( isset( $settings[ $attr ] ) && '' !== $settings[ $attr ] ) {
			$attrs .= sprintf( ' %s="%s"', $attr, esc_attr( (string) $settings[ $attr ] ) );
		}
	}
	printf(
		'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$s"%4$s />',
		esc_attr( 'lfndr-f-' . $field['key'] ),
		esc_attr( $name ),
		esc_attr( (string) $value ),
		$attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr'd parts above.
	);
}

/**
 * Sanitize a number, clamping to the field's configured range.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return string
 */
function gwc_lfndr_sanitize_number( $raw, array $field ): string {
	$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
	if ( '' === $raw || ! is_numeric( $raw ) ) {
		return '';
	}
	$value    = (float) $raw;
	$settings = $field['settings'];
	if ( isset( $settings['min'] ) && '' !== $settings['min'] ) {
		$value = max( (float) $settings['min'], $value );
	}
	if ( isset( $settings['max'] ) && '' !== $settings['max'] ) {
		$value = min( (float) $settings['max'], $value );
	}
	$decimals = max( 0, (int) ( $settings['decimals'] ?? 0 ) );
	return number_format( $value, $decimals, '.', '' );
}

/**
 * Extra Fields-screen controls for a number field.
 *
 * @param array $field Field definition.
 */
function gwc_lfndr_schema_form_number( array $field ): void {
	gwc_lfndr_schema_number_control( $field, 'min', __( 'Minimum', 'groundwork-common-location-finder' ), '' );
	gwc_lfndr_schema_number_control( $field, 'max', __( 'Maximum', 'groundwork-common-location-finder' ), '' );
	gwc_lfndr_schema_number_control( $field, 'decimals', __( 'Decimal places', 'groundwork-common-location-finder' ), '' );
	gwc_lfndr_schema_text_control( $field, 'suffix', __( 'Suffix', 'groundwork-common-location-finder' ), __( 'Shown after the number, e.g. " seats".', 'groundwork-common-location-finder' ) );
}

/* ── boolean ────────────────────────────────────────────────────────────── */

/**
 * Render the admin control for a yes/no field.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Input name.
 */
function gwc_lfndr_admin_boolean( array $field, $value, string $name ): void {
	printf(
		'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s /> %4$s</label>',
		esc_attr( 'lfndr-f-' . $field['key'] ),
		esc_attr( $name ),
		checked( ! empty( $value ), true, false ),
		esc_html( '' !== ( $field['settings']['true_label'] ?? '' ) ? $field['settings']['true_label'] : $field['label'] )
	);
}

/**
 * Sanitize a yes/no value.
 *
 * @param mixed $raw Raw value.
 * @return bool
 */
function gwc_lfndr_sanitize_boolean( $raw ): bool {
	return ! empty( $raw ) && '0' !== $raw;
}

/**
 * Facet tokens for a yes/no field.
 *
 * Only the true state produces a token. Absence means false throughout the
 * plugin — a false boolean is never stored, so it never reaches this function
 * anyway, and emitting a '0' here would be dead code that reads like a
 * guarantee. The "is this toggle worth drawing" question is answered by
 * comparing the true count against the total number of locations, which does
 * not need a token for the ones that say no.
 *
 * @param mixed $value Value.
 * @return array
 */
function gwc_lfndr_facet_boolean( $value ): array {
	return empty( $value ) ? array() : array( '1' );
}

/**
 * Settings sanitizer for a yes/no field.
 *
 * @param array $raw Raw settings.
 * @return array
 */
function gwc_lfndr_settings_boolean( array $raw ): array {
	return array(
		'true_label'  => sanitize_text_field( (string) ( $raw['true_label'] ?? '' ) ),
		'false_label' => sanitize_text_field( (string) ( $raw['false_label'] ?? '' ) ),
	);
}

/**
 * Extra Fields-screen controls for a yes/no field.
 *
 * @param array $field Field definition.
 */
function gwc_lfndr_schema_form_boolean( array $field ): void {
	gwc_lfndr_schema_text_control( $field, 'true_label', __( 'Label when true', 'groundwork-common-location-finder' ), __( 'Defaults to the field label.', 'groundwork-common-location-finder' ) );
	gwc_lfndr_schema_text_control(
		$field,
		'false_label',
		__( 'Label when false', 'groundwork-common-location-finder' ),
		__( 'Leave empty to show nothing when false — which is usually what you want. A card that announces "Not wheelchair accessible" on every listing is rarely the goal.', 'groundwork-common-location-finder' )
	);
}

/* ── select ─────────────────────────────────────────────────────────────── */

/**
 * Render the admin control for a single-choice field.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Input name.
 */
function gwc_lfndr_admin_select( array $field, $value, string $name ): void {
	$allow_empty = ! isset( $field['settings']['allow_empty'] ) || ! empty( $field['settings']['allow_empty'] );
	printf(
		'<select id="%1$s" name="%2$s">',
		esc_attr( 'lfndr-f-' . $field['key'] ),
		esc_attr( $name )
	);
	if ( $allow_empty ) {
		printf( '<option value="">%s</option>', esc_html__( '— none —', 'groundwork-common-location-finder' ) );
	}
	foreach ( $field['options'] as $option ) {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $option['value'] ),
			selected( (string) $value, $option['value'], false ),
			esc_html( $option['label'] )
		);
	}
	echo '</select>';
}

/**
 * Sanitize a single-choice value against the field's own options.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return string
 */
function gwc_lfndr_sanitize_select( $raw, array $field ): string {
	$value = is_scalar( $raw ) ? (string) $raw : '';
	$valid = wp_list_pluck( $field['options'], 'value' );
	if ( in_array( $value, $valid, true ) ) {
		return $value;
	}
	$default = (string) ( $field['settings']['default'] ?? '' );
	return in_array( $default, $valid, true ) ? $default : '';
}

/**
 * Facet tokens for a single-choice field.
 *
 * @param mixed $value Value.
 * @return array
 */
function gwc_lfndr_facet_select( $value ): array {
	$value = (string) $value;
	return '' === $value ? array() : array( $value );
}

/**
 * Settings sanitizer for a single-choice field.
 *
 * @param array $raw   Raw settings.
 * @param array $field Field so far.
 * @return array
 */
function gwc_lfndr_settings_select( array $raw, array $field ): array {
	$valid = wp_list_pluck( $field['options'], 'value' );
	$gate  = sanitize_title( (string) ( $raw['open_now_gate'] ?? '' ) );
	return array(
		'default'       => in_array( (string) ( $raw['default'] ?? '' ), $valid, true ) ? (string) $raw['default'] : '',
		'allow_empty'   => ! isset( $raw['allow_empty'] ) || ! empty( $raw['allow_empty'] ),
		'open_now_gate' => in_array( $gate, $valid, true ) ? $gate : '',
	);
}

/**
 * Extra Fields-screen controls for a single-choice field.
 *
 * @param array $field Field definition.
 */
function gwc_lfndr_schema_form_select( array $field ): void {
	$choices = array( '' => __( '— none —', 'groundwork-common-location-finder' ) );
	foreach ( $field['options'] as $option ) {
		$choices[ $option['value'] ] = $option['label'];
	}
	gwc_lfndr_schema_select_control( $field, 'default', __( 'Default value', 'groundwork-common-location-finder' ), $choices, '' );
	gwc_lfndr_schema_checkbox_control( $field, 'allow_empty', __( 'Allow no value', 'groundwork-common-location-finder' ), true );
	gwc_lfndr_schema_select_control(
		$field,
		'open_now_gate',
		__( 'Only badge "Open now" when this value is set', 'groundwork-common-location-finder' ),
		$choices,
		'',
		__( 'For an access field: a listing that is appointment-only should never show as open, however its hours read.', 'groundwork-common-location-finder' )
	);
}

/* ── multiselect ────────────────────────────────────────────────────────── */

/**
 * Render the admin control for a multi-choice field.
 *
 * Checkboxes rather than a multiple <select>: ctrl-click to deselect is a
 * genuinely obscure interaction, and the option counts here are small.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Input name.
 */
function gwc_lfndr_admin_multiselect( array $field, $value, string $name ): void {
	$current = is_array( $value ) ? $value : array();
	echo '<span class="lfndr-checkboxes">';
	foreach ( $field['options'] as $option ) {
		printf(
			'<label><input type="checkbox" name="%1$s[]" value="%2$s"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $option['value'] ),
			checked( in_array( $option['value'], $current, true ), true, false ),
			esc_html( $option['label'] )
		);
	}
	echo '</span>';
}

/**
 * Sanitize a multi-choice value against the field's own options.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return array
 */
function gwc_lfndr_sanitize_multiselect( $raw, array $field ): array {
	if ( ! is_array( $raw ) ) {
		return array();
	}
	$valid = wp_list_pluck( $field['options'], 'value' );
	$out   = array();
	foreach ( $raw as $candidate ) {
		$candidate = is_scalar( $candidate ) ? (string) $candidate : '';
		if ( in_array( $candidate, $valid, true ) && ! in_array( $candidate, $out, true ) ) {
			$out[] = $candidate;
		}
	}
	/* Stored in the field's own option order, not the order the checkboxes
	 * happened to post in, so the front end renders badges consistently. */
	return array_values( array_intersect( $valid, $out ) );
}

/**
 * Facet tokens for a multi-choice field.
 *
 * @param mixed $value Value.
 * @return array
 */
function gwc_lfndr_facet_multiselect( $value ): array {
	return is_array( $value ) ? array_values( $value ) : array();
}

/**
 * Extra Fields-screen controls for a multi-choice field.
 *
 * @param array $field Field definition.
 */
function gwc_lfndr_schema_form_multiselect( array $field ): void {
	unset( $field );
	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Filter chips for this field use AND: a visitor selecting two values sees only locations offering both.', 'groundwork-common-location-finder' )
	);
}

/* ── Small shared controls for the Fields screen ────────────────────────── */

/**
 * A text control bound to one key of a field's settings bag.
 *
 * @param array  $field Field definition.
 * @param string $key   Settings key.
 * @param string $label Label.
 * @param string $help  Description.
 */
function gwc_lfndr_schema_text_control( array $field, string $key, string $label, string $help ): void {
	printf(
		'<p class="lfndr-setting"><label for="%1$s">%2$s</label><br /><input type="text" class="regular-text" id="%1$s" name="settings[%3$s]" value="%4$s" />%5$s</p>',
		esc_attr( 'lfndr-set-' . $key ),
		esc_html( $label ),
		esc_attr( $key ),
		esc_attr( (string) ( $field['settings'][ $key ] ?? '' ) ),
		'' !== $help ? '<br /><span class="description">' . esc_html( $help ) . '</span>' : ''
	);
}

/**
 * A number control bound to one key of a field's settings bag.
 *
 * @param array  $field Field definition.
 * @param string $key   Settings key.
 * @param string $label Label.
 * @param string $help  Description.
 */
function gwc_lfndr_schema_number_control( array $field, string $key, string $label, string $help ): void {
	printf(
		'<p class="lfndr-setting"><label for="%1$s">%2$s</label><br /><input type="number" class="small-text" id="%1$s" name="settings[%3$s]" value="%4$s" />%5$s</p>',
		esc_attr( 'lfndr-set-' . $key ),
		esc_html( $label ),
		esc_attr( $key ),
		esc_attr( (string) ( $field['settings'][ $key ] ?? '' ) ),
		'' !== $help ? '<br /><span class="description">' . esc_html( $help ) . '</span>' : ''
	);
}

/**
 * A checkbox control bound to one key of a field's settings bag.
 *
 * @param array  $field    Field definition.
 * @param string $key      Settings key.
 * @param string $label    Label.
 * @param bool   $fallback Default when unset.
 */
function gwc_lfndr_schema_checkbox_control( array $field, string $key, string $label, bool $fallback ): void {
	$value = array_key_exists( $key, $field['settings'] ) ? ! empty( $field['settings'][ $key ] ) : $fallback;
	printf(
		'<p class="lfndr-setting"><label><input type="checkbox" name="settings[%1$s]" value="1"%2$s /> %3$s</label></p>',
		esc_attr( $key ),
		checked( $value, true, false ),
		esc_html( $label )
	);
}

/**
 * A select control bound to one key of a field's settings bag.
 *
 * @param array  $field    Field definition.
 * @param string $key      Settings key.
 * @param string $label    Label.
 * @param array  $choices  value => label.
 * @param string $fallback Default when unset.
 * @param string $help     Description.
 */
function gwc_lfndr_schema_select_control( array $field, string $key, string $label, array $choices, string $fallback, string $help = '' ): void {
	$value = (string) ( $field['settings'][ $key ] ?? $fallback );
	printf(
		'<p class="lfndr-setting"><label for="%1$s">%2$s</label><br /><select id="%1$s" name="settings[%3$s]">',
		esc_attr( 'lfndr-set-' . $key ),
		esc_html( $label ),
		esc_attr( $key )
	);
	foreach ( $choices as $choice_value => $choice_label ) {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( (string) $choice_value ),
			selected( $value, (string) $choice_value, false ),
			esc_html( $choice_label )
		);
	}
	echo '</select>';
	if ( '' !== $help ) {
		printf( '<br /><span class="description">%s</span>', esc_html( $help ) );
	}
	echo '</p>';
}
