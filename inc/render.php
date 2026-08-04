<?php
/**
 * Front-end rendering: the shell, the filter rail, the payload, and the
 * no-JavaScript list.
 *
 * @package LocationFinder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render one finder.
 *
 * @param array $atts Attributes from the block or shortcode.
 * @return string
 */
function lfndr_render_finder( array $atts = array() ): string {
	/* First statement, deliberately. This is layer two of the asset gate: layer
	 * one guesses at head time so the CSS is in place before paint, and this
	 * catches every case the guess misses — a template part, a widget, a page
	 * builder. wp_enqueue_* is idempotent, so when layer one fired this does
	 * nothing. */
	lfndr_enqueue_finder();

	static $instance = 0;
	++$instance;

	$atts = shortcode_atts(
		array(
			'show_map' => 'yes',
			'height'   => '',
			'class'    => '',
			'label'    => '',
		),
		$atts,
		'location_finder'
	);

	$locations = lfndr_get_locations();
	$schema    = lfndr_get_schema();
	$facets    = lfndr_available_facets( $locations, $schema );

	$id       = 'lfndr-' . $instance;
	$show_map = ! in_array( strtolower( (string) $atts['show_map'] ), array( 'no', 'false', '0' ), true );
	$height   = absint( $atts['height'] );

	$style = $height > 0 ? sprintf( ' style="--lfndr-map-height:%dpx"', $height ) : '';

	ob_start();
	?>
	<?php
	/*
	 * A landmark only when it has a name. `role="region"` with no accessible
	 * name is worse than a plain div: it adds an entry to the landmark list
	 * that announces as "region" and tells the user nothing, and with two
	 * finders on one page it produces two of them. So the role and the label
	 * are one decision, never separable.
	 *
	 * This matters most full screen, where the finder covers the page and the
	 * heading that introduced it is no longer on screen — see enterMaximize().
	 */
	$label = trim( (string) $atts['label'] );
	$named = '' !== $label
		? sprintf( ' role="region" aria-label="%s"', esc_attr( $label ) )
		: '';
	?>
	<div id="<?php echo esc_attr( $id ); ?>"
		class="lfndr <?php echo esc_attr( trim( 'lfndr--no-js ' . $atts['class'] ) ); ?>"
		<?php echo $named; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr applied above. ?>
		data-lfndr-finder<?php echo $style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from absint above. ?>>

		<?php lfndr_render_controls( $id, $facets, $locations ); ?>

		<div class="lfndr__body">
			<div class="lfndr__panel">
				<p class="lfndr__count" role="status" aria-live="polite">
					<?php
					printf(
						esc_html(
							/* translators: %s: number of locations. */
							_n( '%s location', '%s locations', count( $locations ), 'location-finder' )
						),
						esc_html( number_format_i18n( count( $locations ) ) )
					);
					?>
				</p>

				<?php lfndr_render_static_list( $locations, $schema ); ?>
			</div>

			<?php if ( $show_map ) : ?>
				<div class="lfndr__map" id="<?php echo esc_attr( $id ); ?>-map" role="application"
					aria-label="<?php esc_attr_e( 'Map of locations', 'location-finder' ); ?>"></div>
			<?php endif; ?>
		</div>

		<?php lfndr_render_payload( $id, $locations, $schema, $facets, $show_map ); ?>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * The search box and the filter rail.
 *
 * @param string $id        Container id.
 * @param array  $facets    Filter groups.
 * @param array  $locations Locations.
 */
function lfndr_render_controls( string $id, array $facets, array $locations ): void {
	$searchable = '' !== lfndr_searchable_summary();
	if ( ! $searchable && ! $facets ) {
		return;
	}
	?>
	<div class="lfndr__controls">
		<?php if ( $searchable ) : ?>
			<div class="lfndr__search">
				<?php /* The label still names the field for assistive tech — it just no
				         longer takes up a visible line. The placeholder below says the same
				         thing to a sighted user, and keeping both around would only ever say
				         it twice. */ ?>
				<label class="lfndr__search-label lfndr__visually-hidden" for="<?php echo esc_attr( $id ); ?>-q">
					<?php echo esc_html( lfndr_searchable_summary() ); ?>
				</label>
				<input type="search" id="<?php echo esc_attr( $id ); ?>-q" class="lfndr__search-input"
					autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list"
					aria-controls="<?php echo esc_attr( $id ); ?>-suggest"
					placeholder="<?php esc_attr_e( 'Search…', 'location-finder' ); ?>" />
				<ul class="lfndr__suggest" id="<?php echo esc_attr( $id ); ?>-suggest" role="listbox" hidden></ul>
			</div>
		<?php endif; ?>

		<?php if ( $facets ) : ?>
			<?php /* A real button rather than <details>/<summary>: the panel it opens
			         needs to span the full width of the row below rather than being
			         squeezed into the button's own column, which a disclosure element's
			         box cannot do without its content escaping it. Rendered open — no
			         `hidden` attribute — because that is correct with no JavaScript and
			         on a wide screen; the script closes it below the breakpoint at boot,
			         same as the old <details> did. */ ?>
			<button type="button" class="lfndr__filters-toggle" aria-expanded="true"
				aria-controls="<?php echo esc_attr( $id ); ?>-filters">
				<?php echo lfndr_icon( 'filter' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, literal markup; see lfndr_icon(). ?>
				<span class="lfndr__button-label"><?php esc_html_e( 'Filters', 'location-finder' ); ?></span>
			</button>
			<div class="lfndr__filters-body" id="<?php echo esc_attr( $id ); ?>-filters">
				<?php foreach ( $facets as $group ) : ?>
					<?php lfndr_render_facet_group( $id, $group ); ?>
				<?php endforeach; ?>
				<button type="button" class="lfndr__reset" hidden>
					<?php esc_html_e( 'Clear filters', 'location-finder' ); ?>
				</button>
			</div>
		<?php endif; ?>
	</div>
	<?php
	unset( $locations );
}

/**
 * The checkbox drawn inside every filter chip.
 *
 * A box that is always there, and a tick that appears inside it when the chip is
 * pressed. Two things follow from drawing the box even when unchecked.
 *
 * It holds the tick's place, so selecting a chip cannot change its width and
 * rewrap the row underneath. An earlier version reserved that space and left it
 * empty, which held the width steady but pushed the label off center by the
 * width of nothing — and then needed a matching empty slot on the other side to
 * look balanced, costing every chip the same space twice over. A glyph in the
 * slot is content rather than padding, so the chip reads as a checkbox beside a
 * label, which is a shape people already know, and no trailing slot is needed.
 *
 * It also says the chip is selectable before anyone selects it. A bare pill only
 * reveals that it toggles once you have already clicked it.
 *
 * aria-hidden: aria-pressed on the button already carries the state, and
 * announcing it twice is worse than not at all.
 *
 * @return string
 */
function lfndr_chip_box(): string {
	/* Stroked geometry — a real rounded rect and a two-segment tick — rather
	 * than filled paths tracing those shapes. A filled outline is the usual way
	 * icon fonts draw a checkbox, and it is why this looked scratchy at first:
	 * at the ~16px a chip gives it, the two edges of a traced outline land on
	 * different sides of the pixel grid and the box comes out thin and uneven.
	 * A stroke has one centerline and stays even at any size, which is why
	 * every current icon set draws checkboxes this way. */
	return '<svg class="lfndr__chip-box" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
		. '<rect class="lfndr__chip-box-frame" x="3.5" y="3.5" width="17" height="17" rx="4.5"></rect>'
		. '<path class="lfndr__chip-box-tick" d="m7.8 12.4 2.9 2.9 5.5-6.1"></path>'
		. '</svg>';
}

/**
 * A fixed, inline SVG glyph.
 *
 * Every call site passes a literal key, never a variable built from user or
 * database input, so this is the one place in the file that is safe to print
 * without further escaping — the same exception the front-end script
 * documents for its own icon set.
 *
 * @param string $name  Icon key.
 * @param string $class CSS class for the <svg>.
 * @return string
 */
function lfndr_icon( string $name, string $class = 'lfndr__icon' ): string {
	$paths = array(
		'filter' => 'M3 5h18l-7 8.5V19l-4 2v-7.5L3 5z',
	);
	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}
	return sprintf(
		'<svg class="%1$s" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="%2$s"></path></svg>',
		esc_attr( $class ),
		esc_attr( $paths[ $name ] )
	);
}

/**
 * One filter group.
 *
 * @param string $id    Container id.
 * @param array  $group Filter group.
 */
function lfndr_render_facet_group( string $id, array $group ): void {
	$group_id = $id . '-f-' . $group['key'];

	if ( 'select' === $group['widget'] ) {
		printf(
			'<div class="lfndr__facet lfndr__facet--select" data-facet="%1$s" data-match="%2$s">
				<label class="lfndr__facet-label" for="%3$s">%4$s</label>
				<select id="%3$s" class="lfndr__facet-select"><option value="">%5$s</option>',
			esc_attr( $group['key'] ),
			esc_attr( $group['match'] ),
			esc_attr( $group_id ),
			esc_html( $group['label'] ),
			esc_html__( 'Any', 'location-finder' )
		);
		foreach ( $group['values'] as $value ) {
			printf(
				'<option value="%1$s">%2$s%3$s</option>',
				esc_attr( $value['value'] ),
				esc_html( $value['label'] ),
				null === $value['count'] ? '' : ' (' . esc_html( number_format_i18n( $value['count'] ) ) . ')'
			);
		}
		echo '</select></div>';
		return;
	}

	/* Chips and toggles are both <button aria-pressed>. A pressed button is what
	 * this control actually is, and it means the theme's own button styling
	 * applies rather than the plugin inventing a look for a div. */
	printf(
		'<div class="lfndr__facet lfndr__facet--%1$s" data-facet="%2$s" data-match="%3$s" role="group" aria-labelledby="%4$s">
			<span class="lfndr__facet-label" id="%4$s">%5$s</span>
			<div class="lfndr__chips">',
		esc_attr( $group['widget'] ),
		esc_attr( $group['key'] ),
		esc_attr( $group['match'] ),
		esc_attr( $group_id ),
		esc_html( $group['label'] )
	);

	foreach ( $group['values'] as $value ) {
		/* Every chip carries a checkbox; pressing one fills in the tick. See
		 * lfndr_chip_box() for why it is a box rather than a bare tick. */
		printf(
			'<button type="button" class="lfndr__chip" aria-pressed="false" data-value="%1$s">
				%2$s<span class="lfndr__chip-label">%3$s</span>%4$s
			</button>',
			esc_attr( $value['value'] ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- lfndr_chip_box() takes no arguments and returns a fixed SVG literal.
			lfndr_chip_box(),
			esc_html( $value['label'] ),
			null === $value['count']
				? ''
				: '<span class="lfndr__chip-count">' . esc_html( number_format_i18n( $value['count'] ) ) . '</span>'
		);
	}

	echo '</div></div>';
}

/**
 * A human description of what the search box covers.
 *
 * @return string
 */
function lfndr_searchable_summary(): string {
	$schema = lfndr_get_schema();
	$labels = array();
	foreach ( $schema['fields'] as $field ) {
		if ( ! empty( $field['searchable'] ) ) {
			$labels[] = $field['label'];
		}
	}

	/* The name is always searchable, so the box always has a purpose. */
	if ( ! $labels ) {
		return __( 'Search by name', 'location-finder' );
	}

	return sprintf(
		/* translators: %s: comma-separated list of field labels. */
		__( 'Search by name, %s', 'location-finder' ),
		implode( ', ', array_map( 'strtolower', array_slice( $labels, 0, 3 ) ) )
	);
}

/**
 * The server-rendered list, replaced wholesale by the script on boot.
 *
 * Fifty lines that buy three things. A finder page currently indexes as an
 * empty div, so this is the difference between the locations being findable in
 * a search engine and not existing. It is the whole experience for anyone
 * without JavaScript. And it means the page does not visibly reflow between
 * first paint and boot.
 *
 * It cannot meaningfully drift from the interactive version because it is the
 * degenerate case of it: the same order, the same fields, no interaction.
 *
 * @param array $locations Locations.
 * @param array $schema    Schema.
 */
function lfndr_render_static_list( array $locations, array $schema ): void {
	if ( ! $locations ) {
		printf(
			'<p class="lfndr__empty">%s</p>',
			esc_html__( 'No locations have been added yet.', 'location-finder' )
		);
		return;
	}

	$order = lfndr_resolve_order( 'card', $schema );

	echo '<ol class="lfndr__results">';
	foreach ( $locations as $location ) {
		echo '<li class="lfndr__result"><article class="lfndr-card">';
		printf( '<h3 class="lfndr-card__name">%s</h3>', esc_html( $location['name'] ) );

		echo '<dl class="lfndr-card__fields">';
		foreach ( $order as $entry ) {
			if ( null === $entry['field'] ) {
				continue;
			}
			$text = lfndr_static_field_text( $location, $entry['field'] );
			if ( '' === $text ) {
				continue;
			}
			printf(
				'<div class="lfndr-card__row"><dt>%1$s</dt><dd>%2$s</dd></div>',
				esc_html( $entry['field']['label'] ),
				esc_html( $text )
			);
		}
		echo '</dl></article></li>';
	}
	echo '</ol>';
}

/**
 * A plain-text rendering of one field for the no-JavaScript list.
 *
 * @param array $location Payload location.
 * @param array $field    Field definition.
 * @return string
 */
function lfndr_static_field_text( array $location, array $field ): string {
	$value = $location['f'][ $field['key'] ] ?? null;
	if ( null === $value || '' === $value || array() === $value ) {
		return '';
	}

	switch ( $field['type'] ) {
		case 'address':
			return (string) ( $value['formatted'] ?? '' );

		case 'hours':
			$lines = array();
			foreach ( (array) ( $value['lines'] ?? array() ) as $line ) {
				$lines[] = $line['when'] . ' ' . $line['times'];
			}
			return implode( '; ', $lines );

		case 'closures':
			$parts = array();
			foreach ( (array) $value as $closure ) {
				$parts[] = $closure['start'] === $closure['end']
					? $closure['start']
					: $closure['start'] . '–' . $closure['end'];
			}
			return implode( '; ', $parts );

		case 'boolean':
			/* This list renders its labels, so a true label that restates the
			 * field name — the common case, since true labels are written to
			 * stand alone on a card — would print "Step-free access:
			 * Step-free access". Matches the script's boolean renderer. */
			$true_label = (string) ( $field['settings']['true_label'] ?? '' );
			return ( '' === $true_label || 0 === strcasecmp( $true_label, (string) $field['label'] ) )
				? __( 'Yes', 'location-finder' )
				: $true_label;

		case 'select':
		case 'multiselect':
			$labels = wp_list_pluck( $field['options'], 'label', 'value' );
			$out    = array();
			foreach ( (array) $value as $one ) {
				$out[] = $labels[ $one ] ?? $one;
			}
			return implode( ', ', $out );

		default:
			return is_scalar( $value ) ? (string) $value : '';
	}
}

/* ── The payload ────────────────────────────────────────────────────────── */

/**
 * Emit the data island the script boots from.
 *
 * @param string $id        Container id.
 * @param array  $locations Locations.
 * @param array  $schema    Schema.
 * @param array  $facets    Filter groups.
 * @param bool   $show_map  Whether a map is rendered.
 */
function lfndr_render_payload( string $id, array $locations, array $schema, array $facets, bool $show_map ): void {
	/* ── An inert JSON island, not wp_add_inline_script() ────────────────────
	 * wp_add_inline_script() on a handle that was never registered throws the
	 * payload away and says nothing — no error, no console warning, just a
	 * finder with no data in it. That is the failure the two-layer asset gate
	 * exists to prevent, and it is a poor thing to build a gate around when the
	 * alternative has no handle at all.
	 *
	 * It also depends on the script being registered for the footer: the
	 * 'before' position only lands ahead of the script when the script is late,
	 * so flipping one boolean in an enqueue call empties the finder. A booby
	 * trap in a single argument is worth designing out rather than commenting.
	 *
	 * type="application/json" is never executed, so a bug here is a parse error
	 * rather than script injection, and two finders on one page each carry
	 * their own island instead of fighting over a global.
	 * ─────────────────────────────────────────────────────────────────────── */
	$payload = array(
		'id'        => $id,
		'schema'    => lfndr_public_schema( $schema ),
		'config'    => lfndr_finder_config( $show_map, count( $locations ) ),
		'facets'    => $facets,
		'locations' => $locations,
	);

	printf(
		'<script type="application/json" class="lfndr-data" data-lfndr-for="%1$s">%2$s</script>',
		esc_attr( $id ),
		wp_json_encode(
			$payload,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		)
	);
}

/**
 * The subset of the schema the browser needs.
 *
 * Admin-only properties are stripped: they make the payload bigger for no
 * benefit and there is no reason for a visitor to receive an editor hint.
 *
 * @param array $schema Schema.
 * @return array
 */
function lfndr_public_schema( array $schema ): array {
	$types  = lfndr_field_types();
	$fields = array();

	foreach ( $schema['fields'] as $field ) {
		if ( empty( $field['show_card'] ) && empty( $field['show_detail'] ) ) {
			continue;
		}

		$options = array();
		foreach ( $field['options'] as $option ) {
			$options[ $option['value'] ] = $option['label'];
		}

		$fields[ $field['key'] ] = array(
			'key'      => $field['key'],
			'type'     => $field['type'],
			'js'       => $types[ $field['type'] ]['js'] ?? 'text',
			/**
			 * Filter a field's display label.
			 *
			 * Labels are entered by the site owner, so they are data rather
			 * than plugin strings and cannot ship in the .pot. This is the hook
			 * a multilingual plugin uses to translate them.
			 *
			 * @param string $label Field label.
			 * @param array  $field Field definition.
			 */
			'label'    => (string) apply_filters( 'lfndr_field_label', $field['label'], $field ),
			'icon'     => $field['icon'],
			'card'     => (bool) $field['show_card'],
			'detail'   => (bool) $field['show_detail'],
			'options'  => $options,
			'settings' => lfndr_public_field_settings( $field ),
		);
	}

	return array(
		'fields'      => $fields,
		'detailOrder' => array_column( lfndr_resolve_order( 'detail', $schema ), 'key' ),
		'cardOrder'   => array_column( lfndr_resolve_order( 'card', $schema ), 'key' ),
	);
}

/**
 * The subset of a field's settings the browser needs.
 *
 * @param array $field Field definition.
 * @return array
 */
function lfndr_public_field_settings( array $field ): array {
	$public = array(
		/* No 'primary' or 'suspends' here: which field drives what is a schema
		 * role now, shipped once in config.primary rather than repeated as a
		 * flag on every field the browser receives. */
		'address'     => array( 'directions', 'card_parts' ),
		'hours'       => array( 'card_rows', 'open_now', 'open_today' ),
		'closures'    => array( 'lookahead_days' ),
		'boolean'     => array( 'true_label', 'false_label' ),
		'url'         => array( 'link_text' ),
		'email'       => array( 'mailto' ),
		'phone'       => array( 'tel_link', 'mobile_action' ),
		'number'      => array( 'suffix', 'decimals' ),
		'select'      => array( 'open_now_gate' ),
		'multiselect' => array(),
		'text'        => array(),
		'textarea'    => array(),
	);

	$keys = $public[ $field['type'] ] ?? array();
	$out  = array();
	foreach ( $keys as $key ) {
		if ( isset( $field['settings'][ $key ] ) ) {
			$out[ $key ] = $field['settings'][ $key ];
		}
	}
	return $out;
}

/**
 * Runtime configuration for the script.
 *
 * Built per request, outside the payload transient. See the timezone note.
 *
 * @param bool $show_map Whether a map is rendered.
 * @param int  $count    Number of locations.
 * @return array
 */
function lfndr_finder_config( bool $show_map, int $count ): array {
	$timezone = lfndr_timezone();
	$units    = lfndr_units();
	$tiles    = lfndr_resolve_map_style();

	$primary = array();
	foreach ( array( 'address', 'hours', 'closures' ) as $type ) {
		$field = lfndr_primary_field( $type );
		if ( null !== $field ) {
			$primary[ $type ] = $field['key'];
		}
	}

	$gate = array();
	foreach ( lfndr_get_schema()['fields'] as $field ) {
		if ( 'select' === $field['type'] && '' !== ( $field['settings']['open_now_gate'] ?? '' ) ) {
			$gate = array(
				'field' => $field['key'],
				'value' => $field['settings']['open_now_gate'],
			);
			break;
		}
	}

	return array(
		'map'         => $show_map,
		'center'      => array( (float) lfndr_setting( 'center_lat' ), (float) lfndr_setting( 'center_lng' ) ),
		'zoom'        => (int) lfndr_setting( 'zoom' ),
		'fitToMarkers' => (bool) lfndr_setting( 'fit_to_markers' ),
		/**
		 * Filter the map tile URL template.
		 *
		 * Runs last, so a filter still wins over the chosen style — which is what
		 * the "Custom" option expects, and what a paid or self-hosted provider
		 * needs.
		 *
		 * @param string $url Tile URL with {z}/{x}/{y} placeholders.
		 */
		'tileUrl'     => (string) apply_filters( 'lfndr_tile_url', $tiles['url'] ),
		/**
		 * Filter the map attribution. Removing it is a license violation for
		 * OpenStreetMap tiles and for most commercial providers.
		 *
		 * @param string $attribution Attribution HTML.
		 */
		'attribution' => (string) apply_filters( 'lfndr_tile_attribution', $tiles['attribution'] ),
		'maxZoom'     => (int) $tiles['max_zoom'],

		/* The gate is only meaningful against a third party, so the setting alone
		 * does not turn it on — a site serving its own tiles gets no placeholder
		 * however this is configured. Resolved here rather than in JS because the
		 * browser cannot compare the tile host to the site host without being told
		 * what the site host is. */
		'tileConsent' => (bool) lfndr_setting( 'tile_consent' ) && lfndr_tiles_are_third_party(),
		'tileHost'    => lfndr_tile_host(),

		/* Both forms of the timezone, because neither alone is enough. An IANA
		 * name is what Intl needs, but WordPress lets a site record its
		 * timezone as a raw UTC offset ("+05:30"), which Intl rejects outright.
		 * The offset is the fallback for exactly that case.
		 *
		 * It lives here, in the per-request config, and never in the cached
		 * payload — baked in there it would survive a DST transition and shift
		 * every "open now" answer by an hour until the cache expired. */
		'tz'          => $timezone->getName(),
		'tzOffset'    => (int) ( ( new DateTimeImmutable( 'now', $timezone ) )->getOffset() / 60 ),

		'units'       => $units,
		'nearMe'      => (bool) lfndr_setting( 'near_me' ),
		'autoLocate'  => (bool) lfndr_setting( 'auto_locate' ),
		'pageSize'    => (int) lfndr_setting( 'page_size' ),
		'primary'     => $primary,
		'openGate'    => $gate,
		'total'       => $count,
		'strings'     => lfndr_front_strings(),
	);
}

/**
 * Strings the server owns.
 *
 * Almost everything the script says goes through wp.i18n, which `wp i18n
 * make-pot` reads straight out of the .js file. These are the exceptions:
 * anything needing a plural, where PHP knows the locale's plural rules and
 * shipping them to JavaScript would be a worse version of the same thing.
 *
 * @return array<string, string>
 */
function lfndr_front_strings(): array {
	return array(
		/* translators: %s: number of locations. */
		'countOne'  => __( '%s location', 'location-finder' ),
		/* translators: %s: number of locations. */
		'countMany' => __( '%s locations', 'location-finder' ),
		'unit'      => 'mi' === lfndr_units()
			? _x( 'mi', 'miles, abbreviated', 'location-finder' )
			: _x( 'km', 'kilometers, abbreviated', 'location-finder' ),
	);
}
