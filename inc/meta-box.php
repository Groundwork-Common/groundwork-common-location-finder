<?php
/**
 * The schema-driven location meta box, and the save handler.
 *
 * @package LocationFinder
 */

defined( 'ABSPATH' ) || exit;

add_action( 'add_meta_boxes', 'lfndr_add_meta_boxes' );
add_action( 'save_post_' . LFNDR_POST_TYPE, 'lfndr_save_location', 10, 1 );

/**
 * Register the single Location Details meta box.
 */
function lfndr_add_meta_boxes(): void {
	add_meta_box(
		'lfndr_location_details',
		__( 'Location Details', 'groundwork-common-location-finder' ),
		'lfndr_render_meta_box',
		LFNDR_POST_TYPE,
		'normal',
		'high'
	);
}

/**
 * Render the meta box: the built-in coordinates, then every schema field.
 *
 * @param WP_Post $post Post being edited.
 */
function lfndr_render_meta_box( WP_Post $post ): void {
	$schema = lfndr_get_schema();
	$types  = lfndr_field_types();

	wp_nonce_field( 'lfndr_location_save', 'lfndr_nonce' );

	echo '<div class="lfndr-metabox">';

	lfndr_render_coordinate_fields( $post );

	/* Fields are grouped into <details> by their `section`. A twenty-field
	 * schema is otherwise an undifferentiated wall of inputs, and <details> gets
	 * us the grouping, the keyboard handling and the theme-independent styling
	 * for free. The first section is open; the rest are not. */
	$sections = array();
	foreach ( $schema['fields'] as $field ) {
		$sections[ $field['section'] ][] = $field;
	}

	$first = true;
	foreach ( $sections as $section => $fields ) {
		$grouped = '' !== $section || count( $sections ) > 1;

		if ( $grouped ) {
			printf(
				'<details class="lfndr-section"%s><summary>%s</summary>',
				$first ? ' open' : '',
				esc_html( '' !== $section ? $section : __( 'Details', 'groundwork-common-location-finder' ) )
			);
		}

		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $fields as $field ) {
			lfndr_render_meta_field( $post->ID, $field, $types );
		}
		echo '</tbody></table>';

		if ( $grouped ) {
			echo '</details>';
		}
		$first = false;
	}

	if ( empty( $schema['fields'] ) ) {
		printf(
			'<p class="description">%s</p>',
			wp_kses(
				sprintf(
					/* translators: %s: URL of the Fields screen. */
					__( 'No fields are defined yet. <a href="%s">Add some on the Fields screen</a> and they will appear here.', 'groundwork-common-location-finder' ),
					esc_url( admin_url( 'edit.php?post_type=' . LFNDR_POST_TYPE . '&page=lfndr-fields' ) )
				),
				array( 'a' => array( 'href' => array() ) )
			)
		);
	}

	echo '</div>';
}

/**
 * Render the latitude and longitude inputs.
 *
 * These two are not schema fields and never will be. The map needs them, the
 * distance sort needs them, and there is no configuration under which a
 * location finder does not want coordinates.
 *
 * @param WP_Post $post Post being edited.
 */
function lfndr_render_coordinate_fields( WP_Post $post ): void {
	$lat = get_post_meta( $post->ID, '_lfndr_lat', true );
	$lng = get_post_meta( $post->ID, '_lfndr_lng', true );

	/* When an address field exists, its own search box fills the coordinates as
	 * a side effect and a second one here would be two controls doing one job.
	 * When there is no address field at all — a schema that records only names
	 * and points — this is the only way to avoid typing coordinates by hand. */
	$has_address = null !== lfndr_primary_field( 'address' );
	if ( ! $has_address ) {
		?>
		<div class="lfndr-address" data-lfndr-geocode-target="1">
			<p class="lfndr-address__search">
				<label for="lfndr-geo-coords"><?php esc_html_e( 'Find coordinates', 'groundwork-common-location-finder' ); ?></label><br />
				<input type="search" class="regular-text" id="lfndr-geo-coords" autocomplete="off"
					role="combobox" aria-expanded="false" aria-autocomplete="list"
					aria-controls="lfndr-geo-results-coords" data-lfndr-geocode="1"
					placeholder="<?php esc_attr_e( 'Search for a place or address…', 'groundwork-common-location-finder' ); ?>" />
				<span class="lfndr-address__status" role="status"></span>
			</p>
			<ul class="lfndr-address__results" id="lfndr-geo-results-coords" role="listbox" hidden></ul>
		</div>
		<?php
	}
	?>
	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><label for="lfndr-lat"><?php esc_html_e( 'Latitude', 'groundwork-common-location-finder' ); ?></label></th>
				<td>
					<input type="text" class="regular-text code" id="lfndr-lat" name="lfndr_lat"
						value="<?php echo esc_attr( (string) $lat ); ?>" inputmode="decimal"
						placeholder="<?php esc_attr_e( 'e.g. 33.518600', 'groundwork-common-location-finder' ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lfndr-lng"><?php esc_html_e( 'Longitude', 'groundwork-common-location-finder' ); ?></label></th>
				<td>
					<input type="text" class="regular-text code" id="lfndr-lng" name="lfndr_lng"
						value="<?php echo esc_attr( (string) $lng ); ?>" inputmode="decimal"
						placeholder="<?php esc_attr_e( 'e.g. -86.810400', 'groundwork-common-location-finder' ); ?>" />
					<p class="description">
						<?php esc_html_e( 'Without coordinates a location is searchable but never appears on the map.', 'groundwork-common-location-finder' ); ?>
					</p>
				</td>
			</tr>
		</tbody>
	</table>
	<?php
}

/**
 * Render one schema field's row.
 *
 * @param int   $post_id Post ID.
 * @param array $field   Field definition.
 * @param array $types   Type registry.
 */
function lfndr_render_meta_field( int $post_id, array $field, array $types ): void {
	$type = $types[ $field['type'] ] ?? null;
	if ( null === $type || ! is_callable( $type['render_admin'] ) ) {
		return;
	}

	$value = get_post_meta( $post_id, lfndr_field_meta_key( $field['key'] ), true );
	$name  = 'lfndr_f[' . $field['key'] . ']';

	echo '<tr>';
	printf(
		'<th scope="row"><label for="%1$s">%2$s</label>%3$s</th>',
		esc_attr( 'lfndr-f-' . $field['key'] ),
		esc_html( $field['label'] ),
		! empty( $field['required'] ) ? ' <span class="lfndr-req" aria-hidden="true">*</span>' : ''
	);
	echo '<td>';

	/* The presence marker. See the note on lfndr_save_location(). */
	if ( ! empty( $type['needs_present'] ) ) {
		printf( '<input type="hidden" name="lfndr_present[%s]" value="1" />', esc_attr( $field['key'] ) );
	}

	call_user_func( $type['render_admin'], $field, $value, $name );

	if ( '' !== $field['help'] ) {
		printf( '<p class="description">%s</p>', esc_html( $field['help'] ) );
	}

	echo '</td></tr>';
}

/* ── Save ───────────────────────────────────────────────────────────────── */

/**
 * Save a location's coordinates and every submitted schema field.
 *
 * @param int $post_id Post ID.
 */
function lfndr_save_location( int $post_id ): void {
	if ( ! isset( $_POST['lfndr_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lfndr_nonce'] ) ), 'lfndr_location_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( isset( $_POST['lfndr_lat'] ) ) {
		update_post_meta( $post_id, '_lfndr_lat', lfndr_sanitize_coordinate( wp_unslash( $_POST['lfndr_lat'] ), 90.0 ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by lfndr_sanitize_coordinate().
	}
	if ( isset( $_POST['lfndr_lng'] ) ) {
		update_post_meta( $post_id, '_lfndr_lng', lfndr_sanitize_coordinate( wp_unslash( $_POST['lfndr_lng'] ), 180.0 ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by lfndr_sanitize_coordinate().
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized by its type's registered sanitizer below.
	$raw     = isset( $_POST['lfndr_f'] ) && is_array( $_POST['lfndr_f'] ) ? wp_unslash( $_POST['lfndr_f'] ) : array();
	$present = array();
	if ( isset( $_POST['lfndr_present'] ) && is_array( $_POST['lfndr_present'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- keys only, sanitized on the next line.
		foreach ( array_keys( wp_unslash( $_POST['lfndr_present'] ) ) as $key ) {
			$present[ sanitize_key( $key ) ] = true;
		}
	}

	$types = lfndr_field_types();

	foreach ( lfndr_get_schema()['fields'] as $field ) {
		$key  = $field['key'];
		$type = $types[ $field['type'] ] ?? null;
		if ( null === $type ) {
			continue;
		}

		/* ── The rule that prevents two opposite data-loss bugs ──────────────
		 * Only write a field the form actually submitted.
		 *
		 * Write everything unconditionally and Quick Edit — which renders a
		 * *subset* of the fields — blanks every field it did not show. That bug
		 * is invisible until someone notices a month of edits quietly emptied
		 * half the data.
		 *
		 * But "submitted" cannot just mean array_key_exists(), because four of
		 * our controls post nothing at all when they are empty: an unchecked
		 * checkbox, a multiselect with no boxes ticked, and either repeater
		 * with no rows. For those, absent-because-empty and
		 * absent-because-not-rendered are the same bytes on the wire — so the
		 * field could never be cleared once set.
		 *
		 * Hence the hidden lfndr_present[<key>] marker those controls emit. It
		 * says "this field was on the form", which is exactly the fact the two
		 * cases differ on.
		 * ─────────────────────────────────────────────────────────────────── */
		$was_submitted = array_key_exists( $key, $raw ) || isset( $present[ $key ] );
		if ( ! $was_submitted ) {
			continue;
		}

		$value = call_user_func( $type['sanitize'], $raw[ $key ] ?? null, $field );

		if ( call_user_func( $type['is_empty'], $value, $field ) ) {
			delete_post_meta( $post_id, lfndr_field_meta_key( $key ) );
		} else {
			update_post_meta( $post_id, lfndr_field_meta_key( $key ), $value );
		}
	}
}

/**
 * Sanitize a coordinate, returning '' for anything out of range.
 *
 * Empty rather than clamped. A latitude of 91 is a typo or a swapped pair, and
 * clamping it to 90 would silently place the location at the north pole — a
 * plausible-looking value is much harder to notice than a blank one, and the
 * list table calls blanks out.
 *
 * @param mixed $raw   Raw value.
 * @param float $limit Absolute bound (90 or 180).
 * @return string
 */
function lfndr_sanitize_coordinate( $raw, float $limit ): string {
	$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
	if ( '' === $raw || ! is_numeric( $raw ) ) {
		return '';
	}
	$value = (float) $raw;
	if ( $value < -$limit || $value > $limit ) {
		return '';
	}
	/* Six decimal places is ~11cm. Anything beyond it is noise from a geocoder
	 * and inflates every payload it appears in. */
	return rtrim( rtrim( number_format( $value, 6, '.', '' ), '0' ), '.' );
}
