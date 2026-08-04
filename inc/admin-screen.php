<?php
/**
 * The one admin screen, and the settings that had nowhere to live.
 *
 * ── Why one screen ───────────────────────────────────────────────────────────
 * Fields and Settings were two menu items describing one thing, and the line
 * between them was not a line anybody could have predicted: "Settings" meant
 * appearance and nothing else, while Fields carried the schema, the display
 * order, the retired-field lifecycle, and the panel deciding which hours drive
 * "Open now". So the answer to "where do I change X" depended on knowing which
 * screen an unrelated decision had landed on.
 *
 * Four tabs on one page, in the order a site is actually set up: what a
 * location records, how the finder behaves, what it looks like, and the rare
 * things underneath.
 *
 * ── The settings that had no UI ──────────────────────────────────────────────
 * Of 44 settings the plugin reads at runtime, 19 could only be reached with a
 * filter or a direct update_option(). Not obscure ones either — how many
 * results to show, whether "Near me" exists, miles or kilometers, which
 * directions service, where the map opens, and the contact address Nominatim's
 * usage policy requires. This file is where they are declared, sanitized and
 * rendered, on the same registry-drives-everything pattern the appearance
 * fields already use.
 *
 * @package LocationFinder
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'lfndr_admin_menu', 9 );

/**
 * The tabs, in setup order.
 *
 * @return array<string, string>
 */
function lfndr_admin_tabs(): array {
	return array(
		'fields'     => __( 'Fields', 'location-finder' ),
		'behavior'   => __( 'Behavior', 'location-finder' ),
		'appearance' => __( 'Appearance', 'location-finder' ),
		'advanced'   => __( 'Advanced', 'location-finder' ),
	);
}

/**
 * The tab being viewed.
 *
 * @return string
 */
function lfndr_current_tab(): string {
	$tabs = lfndr_admin_tabs();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
	return isset( $tabs[ $tab ] ) ? $tab : 'fields';
}

/**
 * One submenu entry, replacing the two that described the same thing.
 */
function lfndr_admin_menu(): void {
	add_submenu_page(
		'edit.php?post_type=' . LFNDR_POST_TYPE,
		__( 'Location Finder', 'location-finder' ),
		__( 'Settings', 'location-finder' ),
		'manage_options',
		LFNDR_FIELDS_PAGE,
		'lfndr_admin_screen'
	);
}

/**
 * Render the tab bar and the tab.
 */
function lfndr_admin_screen(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to change these settings.', 'location-finder' ) );
	}

	$current = lfndr_current_tab();

	/* Add and edit are full-page views of the Fields tab rather than tabs of
	 * their own — they are one field, not a section of the site's setup. The
	 * tab bar comes off so the page is unambiguously about the thing being
	 * edited, and the screen provides its own way back. */
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
	if ( 'fields' === $current && in_array( $action, array( 'add', 'edit' ), true ) ) {
		lfndr_fields_screen();
		return;
	}

	?>
	<div class="wrap lfndr-admin">
		<h1><?php esc_html_e( 'Location Finder', 'location-finder' ); ?></h1>

		<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Setup sections', 'location-finder' ); ?>">
			<?php foreach ( lfndr_admin_tabs() as $slug => $label ) : ?>
				<a href="<?php echo esc_url( lfndr_fields_url( array( 'tab' => $slug ) ) ); ?>"
					class="nav-tab<?php echo $slug === $current ? ' nav-tab-active' : ''; ?>"
					<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>>
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php
		switch ( $current ) {
			case 'behavior':
				lfndr_render_option_tab( 'behavior' );
				break;
			case 'appearance':
				lfndr_settings_screen();
				break;
			case 'advanced':
				lfndr_render_option_tab( 'advanced' );
				break;
			default:
				lfndr_fields_screen();
		}
		?>
	</div>
	<?php
}

/* ── The settings that are not about appearance ─────────────────────────── */

/**
 * Every non-appearance setting, with the tab and section it belongs to.
 *
 * Same shape as lfndr_appearance_fields(): one registry driving the form, the
 * sanitizer and the defaults, so the three cannot disagree. The 'type' decides
 * both how it renders and how it is sanitized, which is what stops a select
 * ever accepting a value that is not one of its options.
 *
 * @return array<string, array>
 */
function lfndr_option_fields(): array {
	return array(
		// ── Behavior: results ────────────────────────────────────────────
		'page_size'          => array(
			'tab'     => 'behavior',
			'section' => 'results',
			'type'    => 'int',
			'min'     => 0,
			'max'     => 500,
			'label'   => __( 'Results shown at once', 'location-finder' ),
			'help'    => __( '0 shows every match. Any other number shows that many with a “Show all results” button after them. This limits what is drawn, never what is searched — a location beyond the cut is still found by typing its name.', 'location-finder' ),
		),
		'near_me'            => array(
			'tab'     => 'behavior',
			'section' => 'results',
			'type'    => 'bool',
			'label'   => __( 'Offer sorting by distance', 'location-finder' ),
			'help'    => __( 'Lets a visitor share their location to sort the results by how near they are. Off removes it entirely.', 'location-finder' ),
		),
		'tile_consent'       => array(
			'tab'     => 'behavior',
			'section' => 'map',
			'type'    => 'bool',
			'label'   => __( 'Ask before loading the map', 'location-finder' ),
			'help'    => __( 'Show a placeholder with a "Show map" button instead of loading map tiles straight away. Tiles are fetched by the visitor\'s browser, so until they choose to load them their IP address is never sent to the tile provider. The search, filters and location list all work without the map. Has no effect when the tiles are served from this site, since there is no third party involved.', 'location-finder' ),
		),
		'auto_locate'        => array(
			'tab'     => 'behavior',
			'section' => 'results',
			'type'    => 'bool',
			'label'   => __( 'Ask for location on arrival', 'location-finder' ),
			'help'    => __( 'Off by default, deliberately: an unprompted permission prompt is easy to refuse and hard to ask for twice, and browsers penalize sites that do it.', 'location-finder' ),
		),
		'units'              => array(
			'tab'     => 'behavior',
			'section' => 'results',
			'type'    => 'select',
			'choices' => 'lfndr_unit_choices',
			'label'   => __( 'Distance units', 'location-finder' ),
			'help'    => __( 'Left automatic, this follows the site language — miles for US and UK English, kilometers everywhere else.', 'location-finder' ),
		),

		// ── Behavior: opening hours ──────────────────────────────────────
		'timezone'           => array(
			'tab'         => 'behavior',
			'section'     => 'hours',
			'type'        => 'text',
			'label'       => __( 'Timezone for “Open now”', 'location-finder' ),
			'placeholder' => __( 'the site timezone', 'location-finder' ),
			'help'        => __( 'An IANA name such as America/Chicago. Left blank this follows Settings → General, which is almost always right: somebody checking a Birmingham food bank from a phone still set to Pacific time wants Birmingham’s clock.', 'location-finder' ),
		),

		// ── Behavior: where the map opens ────────────────────────────────
		'fit_to_markers'     => array(
			'tab'     => 'behavior',
			'section' => 'start',
			'type'    => 'bool',
			'label'   => __( 'Zoom to fit the results', 'location-finder' ),
			'help'    => __( 'On, the map frames whatever is currently showing. The starting point below is the fallback for when nothing matches, so it is worth setting even with this on.', 'location-finder' ),
		),
		'center_lat'         => array(
			'tab'     => 'behavior',
			'section' => 'start',
			'type'    => 'float',
			'min'     => -90,
			'max'     => 90,
			'label'   => __( 'Starting latitude', 'location-finder' ),
			'help'    => __( 'Defaults to 0, which is a point in the Gulf of Guinea — fine while results are showing, and an ocean the moment a filter matches nothing.', 'location-finder' ),
		),
		'center_lng'         => array(
			'tab'     => 'behavior',
			'section' => 'start',
			'type'    => 'float',
			'min'     => -180,
			'max'     => 180,
			'label'   => __( 'Starting longitude', 'location-finder' ),
			'help'    => '',
		),
		'zoom'               => array(
			'tab'     => 'behavior',
			'section' => 'start',
			'type'    => 'int',
			'min'     => 0,
			'max'     => 19,
			'label'   => __( 'Starting zoom', 'location-finder' ),
			'help'    => __( '0 is the whole world, 12 a city, 16 a few streets.', 'location-finder' ),
		),

		// ── Behavior: directions ─────────────────────────────────────────
		'directions'         => array(
			'tab'     => 'behavior',
			'section' => 'directions',
			'type'    => 'select',
			'choices' => 'lfndr_directions_choices',
			'label'   => __( 'Directions service', 'location-finder' ),
			'help'    => __( 'Where the Directions link sends people. The address is used in preference to the coordinates, because a street address resolves to a door and a coordinate resolves to a point — which on a large site is regularly the wrong side of the building.', 'location-finder' ),
		),
		'directions_pattern' => array(
			'tab'         => 'behavior',
			'section'     => 'directions',
			'type'        => 'text',
			'label'       => __( 'Custom directions URL', 'location-finder' ),
			'placeholder' => 'https://example.com/?to={query}',
			'help'        => __( 'Only used when the service above is Custom. {query} is the formatted address, {lat} and {lng} the coordinates.', 'location-finder' ),
		),

		// ── Advanced: address lookup ─────────────────────────────────────
		'geo_email'          => array(
			'tab'         => 'advanced',
			'section'     => 'geocode',
			'type'        => 'email',
			'label'       => __( 'Contact address for lookups', 'location-finder' ),
			'placeholder' => __( 'nobody@example.com', 'location-finder' ),
			'help'        => __( 'Nominatim’s usage policy requires a contact for whoever is making the requests. Without one the address lookup in the editor may be blocked — and because the policy applies per operator, an unidentified install risks the block landing on everyone using this plugin.', 'location-finder' ),
		),
		'geo_endpoint'       => array(
			'tab'         => 'advanced',
			'section'     => 'geocode',
			'type'        => 'url',
			'label'       => __( 'Lookup endpoint', 'location-finder' ),
			'placeholder' => 'https://nominatim.openstreetmap.org/search',
			'help'        => __( 'Point this at your own Nominatim instance, or a paid provider with a compatible response.', 'location-finder' ),
		),
		'geo_countries'      => array(
			'tab'         => 'advanced',
			'section'     => 'geocode',
			'type'        => 'text',
			'label'       => __( 'Limit lookups to countries', 'location-finder' ),
			'placeholder' => 'us, ca',
			'help'        => __( 'Two-letter codes, comma separated. Leave blank to search everywhere.', 'location-finder' ),
		),
		'geo_viewbox'        => array(
			'tab'         => 'advanced',
			'section'     => 'geocode',
			'type'        => 'text',
			'label'       => __( 'Prefer results in this box', 'location-finder' ),
			'placeholder' => '-88.0,33.0,-85.0,35.0',
			'help'        => __( 'Four numbers: west, south, east, north. Nudges results toward your region without excluding the rest.', 'location-finder' ),
		),
		'geo_bounded'        => array(
			'tab'     => 'advanced',
			'section' => 'geocode',
			'type'    => 'bool',
			'label'   => __( 'Restrict strictly to that box', 'location-finder' ),
			'help'    => __( 'Turns the preference above into a hard limit. An address outside the box then returns nothing at all, which looks like a broken lookup rather than a deliberate one — leave this off unless you mean it.', 'location-finder' ),
		),

		// ── Advanced: custom tiles ───────────────────────────────────────
		'tile_url'           => array(
			'tab'         => 'advanced',
			'section'     => 'tiles',
			'type'        => 'text',
			'label'       => __( 'Tile URL', 'location-finder' ),
			'placeholder' => 'https://tile.example.com/{z}/{x}/{y}.png',
			'help'        => __( 'Only used when Map style on the Appearance tab is set to Custom. Must contain {z}, {x} and {y}.', 'location-finder' ),
		),
		'tile_attr'          => array(
			'tab'     => 'advanced',
			'section' => 'tiles',
			'type'    => 'text',
			'label'   => __( 'Tile attribution', 'location-finder' ),
			'help'    => __( 'Printed on the map. Nearly every tile provider requires this, and removing it breaks their terms rather than merely being impolite. Links are allowed.', 'location-finder' ),
		),
		'tile_maxzoom'       => array(
			'tab'     => 'advanced',
			'section' => 'tiles',
			'type'    => 'int',
			'min'     => 1,
			'max'     => 22,
			'label'   => __( 'Deepest zoom offered', 'location-finder' ),
			'help'    => __( 'Zooming past what a provider actually has produces blank tiles.', 'location-finder' ),
		),
	);
}

/**
 * Section headings, keyed by tab.
 *
 * @return array<string, array<string, array{title:string, intro:string}>>
 */
function lfndr_option_sections(): array {
	return array(
		'behavior' => array(
			'roles'      => array(
				'title' => __( 'Which field drives what', 'location-finder' ),
				'intro' => __( 'A location can carry more than one address, schedule or closure list — a mailing address and a visiting one, pantry hours and office hours. They all display; these choose which of them the finder acts on.', 'location-finder' ),
			),
			'results'    => array(
				'title' => __( 'Results', 'location-finder' ),
				'intro' => '',
			),
			'hours'      => array(
				'title' => __( 'Opening hours', 'location-finder' ),
				'intro' => '',
			),
			'start'      => array(
				'title' => __( 'Where the map opens', 'location-finder' ),
				'intro' => '',
			),
			'directions' => array(
				'title' => __( 'Directions', 'location-finder' ),
				'intro' => '',
			),
		),
		'advanced' => array(
			'geocode' => array(
				'title' => __( 'Address lookup', 'location-finder' ),
				'intro' => __( 'The editor’s “find this address” box calls an outside service. These control which one and how it behaves. Visitors never trigger it — only somebody editing a location does.', 'location-finder' ),
			),
			'tiles'   => array(
				'title' => __( 'Custom map tiles', 'location-finder' ),
				'intro' => __( 'Ignored unless Map style on the Appearance tab is set to Custom.', 'location-finder' ),
			),
		),
	);
}

/**
 * Units offered.
 *
 * @return array<string, string>
 */
function lfndr_unit_choices(): array {
	return array(
		''   => __( 'Automatic — follow the site language', 'location-finder' ),
		'mi' => __( 'Miles', 'location-finder' ),
		'km' => __( 'Kilometers', 'location-finder' ),
	);
}

/**
 * Directions services offered.
 *
 * @return array<string, string>
 */
function lfndr_directions_choices(): array {
	return array(
		'google' => __( 'Google Maps', 'location-finder' ),
		'apple'  => __( 'Apple Maps', 'location-finder' ),
		'osm'    => __( 'OpenStreetMap', 'location-finder' ),
		'custom' => __( 'Custom URL', 'location-finder' ),
	);
}

/**
 * Render one settings tab.
 *
 * @param string $tab Tab slug.
 */
function lfndr_render_option_tab( string $tab ): void {
	$sections = lfndr_option_sections()[ $tab ] ?? array();
	$fields   = array_filter(
		lfndr_option_fields(),
		static function ( $field ) use ( $tab ) {
			return $tab === $field['tab'];
		}
	);
	?>
	<?php
	/* The roles live on the schema, not in the settings option, so they cannot
	 * ride this form — the Settings API would post them to options.php and drop
	 * them on the floor. Their own form with its own Save is the honest
	 * arrangement: two different things are being written, and the screen says
	 * so rather than implying one button saves both. */
	if ( isset( $sections['roles'] ) ) :
		?>
		<h2><?php echo esc_html( $sections['roles']['title'] ); ?></h2>
		<p class="description" style="max-width:44em"><?php echo esc_html( $sections['roles']['intro'] ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lfndr_save_roles" />
			<?php
			wp_nonce_field( 'lfndr_save_roles' );
			lfndr_render_roles_fields();
			submit_button( __( 'Save', 'location-finder' ), 'secondary' );
			?>
		</form>
		<hr />
		<?php
		unset( $sections['roles'] );
	endif;
	?>

	<form method="post" action="options.php">
		<?php settings_fields( 'lfndr_settings_group' ); ?>
		<?php /* Marks the tab as submitted — the only way to tell an unchecked
		         box from a field that was never on screen. */ ?>
		<input type="hidden" name="<?php echo esc_attr( LFNDR_SETTINGS_OPTION ); ?>[_tab_<?php echo esc_attr( $tab ); ?>]" value="1" />

		<?php foreach ( $sections as $section_key => $section ) : ?>
			<h2><?php echo esc_html( $section['title'] ); ?></h2>
			<?php if ( '' !== $section['intro'] ) : ?>
				<p class="description" style="max-width:44em"><?php echo esc_html( $section['intro'] ); ?></p>
			<?php endif; ?>

			<?php if ( true ) : ?>
				<table class="form-table" role="presentation">
					<tbody>
					<?php foreach ( $fields as $key => $field ) : ?>
						<?php if ( $section_key !== $field['section'] ) { continue; } ?>
						<tr>
							<th scope="row">
								<label for="lfndr-opt-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
							</th>
							<td><?php lfndr_render_option_field( $key, $field ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endforeach; ?>

		<?php submit_button(); ?>
	</form>
	<?php
}

/**
 * Render one control, by type.
 *
 * @param string $key   Setting key.
 * @param array  $field Field definition.
 */
function lfndr_render_option_field( string $key, array $field ): void {
	$value = lfndr_setting( $key );
	$name  = sprintf( '%s[%s]', LFNDR_SETTINGS_OPTION, $key );
	$id    = 'lfndr-opt-' . $key;

	switch ( $field['type'] ) {
		case 'bool':
			printf(
				'<label><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s /> %4$s</label>',
				esc_attr( $id ),
				esc_attr( $name ),
				checked( (bool) $value, true, false ),
				esc_html( $field['label'] )
			);
			break;

		case 'select':
			printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
			foreach ( call_user_func( $field['choices'] ) as $choice => $label ) {
				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $choice ),
					selected( (string) $value, (string) $choice, false ),
					esc_html( $label )
				);
			}
			echo '</select>';
			break;

		case 'int':
		case 'float':
			printf(
				'<input type="number" id="%1$s" name="%2$s" value="%3$s" class="small-text"%4$s%5$s%6$s />',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( (string) $value ),
				isset( $field['min'] ) ? ' min="' . esc_attr( (string) $field['min'] ) . '"' : '',
				isset( $field['max'] ) ? ' max="' . esc_attr( (string) $field['max'] ) . '"' : '',
				'float' === $field['type'] ? ' step="any"' : ' step="1"'
			);
			break;

		default:
			printf(
				'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" placeholder="%5$s" class="regular-text%6$s" />',
				'email' === $field['type'] ? 'email' : ( 'url' === $field['type'] ? 'url' : 'text' ),
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( (string) $value ),
				esc_attr( (string) ( $field['placeholder'] ?? '' ) ),
				'text' === $field['type'] ? ' code' : ''
			);
	}

	if ( '' !== ( $field['help'] ?? '' ) ) {
		printf( '<p class="description" style="max-width:44em">%s</p>', esc_html( $field['help'] ) );
	}
}

/**
 * Sanitize the non-appearance settings.
 *
 * Absent keys are left alone rather than reset. Each tab posts only its own
 * fields, so a blanket reset would wipe the Behavior tab every time somebody
 * saved Advanced — and a checkbox that is off posts nothing at all, which is
 * why unchecking one has to be inferred from the tab's presence rather than
 * from the key being missing.
 *
 * @param array $raw Submitted values.
 * @param array $out Values accumulated so far.
 * @return array
 */
function lfndr_sanitize_option_fields( array $raw, array $out ): array {
	foreach ( lfndr_option_fields() as $key => $field ) {
		$posted = array_key_exists( $key, $raw );

		/* An unchecked box posts nothing. The hidden marker each tab prints
		 * says "this tab was submitted", which is the only way to tell an
		 * unchecked box from a field that was never on screen. */
		if ( 'bool' === $field['type'] ) {
			if ( ! empty( $raw[ '_tab_' . $field['tab'] ] ) ) {
				$out[ $key ] = $posted && ! empty( $raw[ $key ] );
			}
			continue;
		}

		if ( ! $posted ) {
			continue;
		}

		$value = is_scalar( $raw[ $key ] ) ? (string) $raw[ $key ] : '';

		switch ( $field['type'] ) {
			case 'int':
				$out[ $key ] = max( (int) $field['min'], min( (int) $field['max'], (int) $value ) );
				break;

			case 'float':
				$out[ $key ] = max( (float) $field['min'], min( (float) $field['max'], (float) $value ) );
				break;

			case 'select':
				$choices     = call_user_func( $field['choices'] );
				$out[ $key ] = isset( $choices[ $value ] ) ? $value : (string) array_key_first( $choices );
				break;

			case 'url':
				$out[ $key ] = (string) esc_url_raw( trim( $value ), array( 'http', 'https' ) );
				break;

			case 'email':
				$out[ $key ] = (string) sanitize_email( trim( $value ) );
				break;

			default:
				/* tile_attr is the one field here that legitimately holds
				 * markup — a tile provider's attribution is a link, and
				 * stripping it would put the site in breach of the terms it
				 * exists to satisfy. Anchors only, nothing that can act. */
				$out[ $key ] = 'tile_attr' === $key
					? (string) wp_kses( $value, array( 'a' => array( 'href' => array(), 'rel' => array(), 'target' => array(), 'title' => array() ) ) )
					: sanitize_text_field( $value );
		}
	}

	return $out;
}
