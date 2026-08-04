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
		'fields'     => __( 'Fields', 'groundwork-common-location-finder' ),
		'behavior'   => __( 'Behavior', 'groundwork-common-location-finder' ),
		'appearance' => __( 'Appearance', 'groundwork-common-location-finder' ),
		'advanced'   => __( 'Advanced', 'groundwork-common-location-finder' ),
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
		__( 'Location Finder', 'groundwork-common-location-finder' ),
		__( 'Settings', 'groundwork-common-location-finder' ),
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
		wp_die( esc_html__( 'You do not have permission to change these settings.', 'groundwork-common-location-finder' ) );
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
		<h1><?php esc_html_e( 'Location Finder', 'groundwork-common-location-finder' ); ?></h1>

		<?php lfndr_render_colophon(); ?>

		<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Setup sections', 'groundwork-common-location-finder' ); ?>">
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

add_filter( 'admin_footer_text', 'lfndr_admin_footer_text' );

/**
 * Replace the admin footer line on this plugin's own screens.
 *
 * Where WordPress already says "Thank you for creating with WordPress" — the
 * bottom of the page, quiet, expected, and out of the way of the work. That is
 * the whole reason it goes here and not above the locations list: somebody
 * scanning a list of records to find one is not the person to interrupt, and
 * they would see a panel there dozens of times a week.
 *
 * Scoped to our screens: the locations list, the location editor, and the
 * settings screen. Rewriting core's footer on somebody else's page would be
 * exactly the kind of reach the directory guidelines are about, and it is a
 * one-line change away from being that, so the check is deliberately narrow —
 * an unrecognised screen returns the text untouched.
 *
 * @param string $text The existing footer text.
 * @return string
 */
function lfndr_admin_footer_text( $text ) {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen ) {
		return $text;
	}

	$ours = LFNDR_POST_TYPE === $screen->post_type
		|| false !== strpos( (string) $screen->id, LFNDR_FIELDS_PAGE );

	if ( ! $ours ) {
		return $text;
	}

	return sprintf(
		/* translators: %s: Groundwork Common, linked to the company site. */
		esc_html__( 'Built by %s — technology leadership and support for nonprofits.', 'groundwork-common-location-finder' ),
		sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( LFNDR_GWC_URL ),
			esc_html__( 'Groundwork Common', 'groundwork-common-location-finder' )
		)
	);
}

/* ── Collapsing the colophon ──────────────────────────────────────────────────
 * Collapsible, never dismissible. A permanent "never show again" turns the
 * panel into something to be got rid of once and then forgotten, which is both
 * worse for us and worse for whoever inherits the site and never learns who
 * maintains the plugin. Folding it away for a month is the honest middle: it
 * respects somebody who is trying to work, and it comes back.
 *
 * Stored per user rather than per site. One administrator collapsing it should
 * not decide for their colleagues, and user meta is where a personal display
 * preference belongs.
 *
 * The stored value is WHEN it was collapsed, not THAT it was — which is what
 * makes the thirty days fall out of a comparison instead of needing a scheduled
 * event to come round and clear a flag.
 * ─────────────────────────────────────────────────────────────────────────── */

const LFNDR_COLOPHON_META   = 'lfndr_colophon_collapsed_at';
const LFNDR_COLOPHON_SNOOZE = 30 * DAY_IN_SECONDS;

add_action( 'admin_init', 'lfndr_handle_colophon_toggle' );

/**
 * Is a collapse from $collapsed_at still in force at $now?
 *
 * Split out from the user-meta read so the only part with a decision in it can
 * be tested without WordPress. Zero means never collapsed. A timestamp in the
 * future — a clock correction, a bad import — reads as collapsed rather than
 * throwing, and expires on its own once the clock catches up.
 *
 * @param int $collapsed_at Unix time the user collapsed it, or 0.
 * @param int $now          Unix time now.
 * @return bool
 */
function lfndr_colophon_snoozed( int $collapsed_at, int $now ): bool {
	if ( $collapsed_at <= 0 ) {
		return false;
	}
	return ( $now - $collapsed_at ) < LFNDR_COLOPHON_SNOOZE;
}

/**
 * Whether to render the colophon collapsed for the current user.
 *
 * @return bool
 */
function lfndr_colophon_is_collapsed(): bool {
	$at = (int) get_user_meta( get_current_user_id(), LFNDR_COLOPHON_META, true );
	return lfndr_colophon_snoozed( $at, time() );
}

/**
 * Collapse or expand, then send the browser back where it was.
 *
 * A nonced link handled server-side rather than a script and an AJAX route.
 * This runs at most twice a month per person, so a page load costs nothing —
 * and the alternative would add an endpoint, a nonce to ship to the browser and
 * a script, all to avoid a reload nobody will notice.
 */
function lfndr_handle_colophon_toggle(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only; the nonce is verified below before anything is written.
	if ( ! isset( $_GET['lfndr_colophon'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	check_admin_referer( 'lfndr_colophon' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified directly above.
	$wanted = sanitize_key( wp_unslash( $_GET['lfndr_colophon'] ) );

	if ( 'collapse' === $wanted ) {
		update_user_meta( get_current_user_id(), LFNDR_COLOPHON_META, time() );
	} else {
		delete_user_meta( get_current_user_id(), LFNDR_COLOPHON_META );
	}

	/* Back to the same tab, minus the toggle. Without stripping the arguments a
	 * refresh would re-fire the toggle, and the nonce in the URL would outlive
	 * its usefulness in the address bar. */
	wp_safe_redirect( remove_query_arg( array( 'lfndr_colophon', '_wpnonce' ) ) );
	exit;
}

/**
 * The collapse/expand link, nonced.
 *
 * @param string $action 'collapse' or 'expand'.
 * @return string
 */
function lfndr_colophon_toggle_url( string $action ): string {
	return wp_nonce_url( add_query_arg( 'lfndr_colophon', $action ), 'lfndr_colophon' );
}

/**
 * Who made this, and the one thing worth asking of someone using it.
 *
 * Between the page title and the tab bar, and on this screen only. Above the
 * tabs because it introduces the whole screen rather than any one section of
 * it, and because a colophon below four tabs of settings is one nobody reaches.
 *
 * Still not a notice. A plugin that interrupts an unrelated admin page to talk
 * about its author is the behaviour the directory guidelines exist to stop, and
 * it earns the dismissal it gets. Somebody who has opened this screen has
 * chosen to be here; that is the whole difference.
 *
 * Two asks, in the order they are actually worth: a referral, then ongoing
 * support for the work itself.
 *
 * Neither is called a donation. Groundwork Common is a services practice, not
 * a charity, so "donate" would imply a tax status that does not exist — and
 * asking a nonprofit to donate to its vendor points the arrow the wrong way.
 * Sponsorship is the honest word for paying to keep freely released software
 * maintained, and it describes the exchange accurately: the money buys
 * continued work, not goodwill.
 */
function lfndr_render_colophon(): void {
	?>
	<?php if ( lfndr_colophon_is_collapsed() ) : ?>
		<div class="lfndr-colophon lfndr-colophon--collapsed">
			<span class="lfndr-colophon__logo" aria-hidden="true"></span>
			<span class="screen-reader-text"><?php esc_html_e( 'Groundwork Common', 'groundwork-common-location-finder' ); ?></span>
			<a class="lfndr-colophon__toggle" href="<?php echo esc_url( lfndr_colophon_toggle_url( 'expand' ) ); ?>">
				<?php esc_html_e( 'Show', 'groundwork-common-location-finder' ); ?>
			</a>
		</div>
		<?php return; ?>
	<?php endif; ?>

	<div class="lfndr-colophon">
		<a class="lfndr-colophon__toggle" href="<?php echo esc_url( lfndr_colophon_toggle_url( 'collapse' ) ); ?>">
			<?php esc_html_e( 'Hide for 30 days', 'groundwork-common-location-finder' ); ?>
		</a>

		<div class="lfndr-colophon__main">
		<h2 class="lfndr-colophon__brand">
			<?php
			/*
			 * The wordmark carries the name visually and the heading carries it
			 * to everything else. Marked aria-hidden and paired with real text
			 * rather than given alt text, because an <img alt="Groundwork
			 * Common"> immediately after a heading saying the same words is read
			 * out twice.
			 *
			 * Two files, swapped by colour scheme in the stylesheet: the logo is
			 * ink on transparent, so one version or the other disappears
			 * depending on what it is sitting on. Naming is by BACKGROUND, not
			 * by ink — "-light" is the one for light backgrounds.
			 */
			?>
			<a href="<?php echo esc_url( LFNDR_GWC_URL ); ?>" target="_blank" rel="noopener noreferrer">
				<span class="screen-reader-text"><?php esc_html_e( 'Groundwork Common', 'groundwork-common-location-finder' ); ?></span>
				<span class="lfndr-colophon__logo" aria-hidden="true"></span>
			</a>
		</h2>

		<p>
			<?php
			/* The anchor is built here rather than carried inside the
			 * translatable string, so a translator is never handed markup they
			 * can break and no HTML has to survive a round trip through
			 * translate.wordpress.org. */
			$lfndr_gwc_link = sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( LFNDR_GWC_URL ),
				esc_html__( 'Groundwork Common', 'groundwork-common-location-finder' )
			);

			printf(
				/* translators: %s: Groundwork Common, linked to the company site. */
				esc_html__( '%s provides technology leadership and support for nonprofits — fractional, by the project, or alongside an in-house team. We release tools like this one because good technology work should leave an organization more capable, not more dependent on whoever built it.', 'groundwork-common-location-finder' ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled directly above from esc_url() and esc_html__().
				$lfndr_gwc_link
			);
			?>
		</p>

		<p>
			<?php esc_html_e( 'If you find this plugin useful, the most valuable thing you can do for us is mention us to a nonprofit who might benefit from our services. Referrals are how our business continues to grow its impact and reach.', 'groundwork-common-location-finder' ); ?>
		</p>

		<?php /* Directly under the referral ask, which is what it answers. */ ?>
		<p>
			<a class="button" href="<?php echo esc_url( LFNDR_GWC_URL ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Learn about Groundwork Common', 'groundwork-common-location-finder' ); ?>
			</a>
		</p>

	</div>

		<?php /* Second column: the two things a reader can act on. */ ?>
		<div class="lfndr-colophon__aside">
			<?php if ( '' !== LFNDR_SPONSOR_URL ) : ?>
				<p>
					<?php esc_html_e( 'You can also support our WordPress plugins directly. While we offer the plugin free to you, it costs us to maintain it — the security updates, the compatibility testing against each new WordPress release, the bug nobody but you has hit. We can’t do it without your support, and we appreciate whatever support you can give.', 'groundwork-common-location-finder' ); ?>
				</p>

				<p>
					<a class="button button-primary" href="<?php echo esc_url( LFNDR_SPONSOR_URL ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Support our work', 'groundwork-common-location-finder' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<p>
				<a href="https://github.com/Groundwork-Common/groundwork-common-location-finder/issues" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Report a problem', 'groundwork-common-location-finder' ); ?>
				</a>
			</p>
		</div>
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
			'label'   => __( 'Results shown at once', 'groundwork-common-location-finder' ),
			'help'    => __( '0 shows every match. Any other number shows that many with a “Show all results” button after them. This limits what is drawn, never what is searched — a location beyond the cut is still found by typing its name.', 'groundwork-common-location-finder' ),
		),
		'near_me'            => array(
			'tab'     => 'behavior',
			'section' => 'results',
			'type'    => 'bool',
			'label'   => __( 'Let visitors find what is nearest', 'groundwork-common-location-finder' ),
			'help'    => __( 'Adds a locate button to the map. Pressing it asks the browser for the visitor’s location, sorts the results by distance and shows how far each one is. Nothing is sent anywhere — the coordinates are used in the browser and never leave it. Needs the map, so it has no effect where the finder is set to show the list only.', 'groundwork-common-location-finder' ),
		),
		'tile_consent'       => array(
			'tab'     => 'behavior',
			'section' => 'map',
			'type'    => 'bool',
			'label'   => __( 'Ask before loading the map', 'groundwork-common-location-finder' ),
			'help'    => __( 'Show a placeholder with a "Show map" button instead of loading map tiles straight away. Tiles are fetched by the visitor\'s browser, so until they choose to load them their IP address is never sent to the tile provider. The search, filters and location list all work without the map. Has no effect when the tiles are served from this site, since there is no third party involved.', 'groundwork-common-location-finder' ),
		),
		'auto_locate'        => array(
			'tab'     => 'behavior',
			'section' => 'results',
			'type'    => 'bool',
			'label'   => __( 'Ask for location on arrival', 'groundwork-common-location-finder' ),
			'help'    => __( 'Off by default, deliberately: an unprompted permission prompt is easy to refuse and hard to ask for twice, and browsers penalize sites that do it.', 'groundwork-common-location-finder' ),
		),
		'units'              => array(
			'tab'     => 'behavior',
			'section' => 'results',
			'type'    => 'select',
			'choices' => 'lfndr_unit_choices',
			'label'   => __( 'Distance units', 'groundwork-common-location-finder' ),
			'help'    => __( 'Left automatic, this follows the site language — miles for US and UK English, kilometers everywhere else.', 'groundwork-common-location-finder' ),
		),

		// ── Behavior: opening hours ──────────────────────────────────────
		'timezone'           => array(
			'tab'         => 'behavior',
			'section'     => 'hours',
			'type'        => 'text',
			'label'       => __( 'Timezone for “Open now”', 'groundwork-common-location-finder' ),
			'placeholder' => __( 'the site timezone', 'groundwork-common-location-finder' ),
			'help'        => __( 'An IANA name such as America/Chicago. Left blank this follows Settings → General, which is almost always right: somebody checking a Birmingham food bank from a phone still set to Pacific time wants Birmingham’s clock.', 'groundwork-common-location-finder' ),
		),

		// ── Behavior: where the map opens ────────────────────────────────
		'fit_to_markers'     => array(
			'tab'     => 'behavior',
			'section' => 'start',
			'type'    => 'bool',
			'label'   => __( 'Zoom to fit the results', 'groundwork-common-location-finder' ),
			'help'    => __( 'On, the map frames whatever is currently showing. The starting point below is the fallback for when nothing matches, so it is worth setting even with this on.', 'groundwork-common-location-finder' ),
		),
		'center_lat'         => array(
			'tab'     => 'behavior',
			'section' => 'start',
			'type'    => 'float',
			'min'     => -90,
			'max'     => 90,
			'label'   => __( 'Starting latitude', 'groundwork-common-location-finder' ),
			'help'    => __( 'Defaults to 0, which is a point in the Gulf of Guinea — fine while results are showing, and an ocean the moment a filter matches nothing.', 'groundwork-common-location-finder' ),
		),
		'center_lng'         => array(
			'tab'     => 'behavior',
			'section' => 'start',
			'type'    => 'float',
			'min'     => -180,
			'max'     => 180,
			'label'   => __( 'Starting longitude', 'groundwork-common-location-finder' ),
			'help'    => '',
		),
		'zoom'               => array(
			'tab'     => 'behavior',
			'section' => 'start',
			'type'    => 'int',
			'min'     => 0,
			'max'     => 19,
			'label'   => __( 'Starting zoom', 'groundwork-common-location-finder' ),
			'help'    => __( '0 is the whole world, 12 a city, 16 a few streets.', 'groundwork-common-location-finder' ),
		),

		// ── Behavior: directions ─────────────────────────────────────────
		'directions'         => array(
			'tab'     => 'behavior',
			'section' => 'directions',
			'type'    => 'select',
			'choices' => 'lfndr_directions_choices',
			'label'   => __( 'Directions service', 'groundwork-common-location-finder' ),
			'help'    => __( 'Where the Directions link sends people. The address is used in preference to the coordinates, because a street address resolves to a door and a coordinate resolves to a point — which on a large site is regularly the wrong side of the building.', 'groundwork-common-location-finder' ),
		),
		'directions_pattern' => array(
			'tab'         => 'behavior',
			'section'     => 'directions',
			'type'        => 'text',
			'label'       => __( 'Custom directions URL', 'groundwork-common-location-finder' ),
			'placeholder' => 'https://example.com/?to={query}',
			'help'        => __( 'Only used when the service above is Custom. {query} is the formatted address, {lat} and {lng} the coordinates.', 'groundwork-common-location-finder' ),
		),

		// ── Advanced: address lookup ─────────────────────────────────────
		'geo_email'          => array(
			'tab'         => 'advanced',
			'section'     => 'geocode',
			'type'        => 'email',
			'label'       => __( 'Contact address for lookups', 'groundwork-common-location-finder' ),
			/* The real fallback, not a stand-in. lfndr_geocode_contact_email()
			 * already uses the admin email when this is blank, so showing
			 * nobody@example.com described a behaviour the plugin does not have
			 * and invited people to type in something it did not need.
			 *
			 * Shown rather than stored: copying the address into the option
			 * would go stale the moment somebody changes it under Settings →
			 * General, and there would be nothing to indicate which of the two
			 * was being sent. */
			'placeholder' => (string) get_option( 'admin_email' ),
			'help'        => __( 'Sent with each lookup so the address service knows which site is asking. Leave it blank to use your admin email. Without a contact address the service can refuse the request, and the address finder in the location editor stops filling in coordinates.', 'groundwork-common-location-finder' ),
		),
		'geo_endpoint'       => array(
			'tab'         => 'advanced',
			'section'     => 'geocode',
			'type'        => 'url',
			'label'       => __( 'Lookup endpoint', 'groundwork-common-location-finder' ),
			'placeholder' => 'https://nominatim.openstreetmap.org/search',
			'help'        => __( 'Point this at your own Nominatim instance, or a paid provider with a compatible response.', 'groundwork-common-location-finder' ),
		),
		'geo_countries'      => array(
			'tab'         => 'advanced',
			'section'     => 'geocode',
			'type'        => 'text',
			'label'       => __( 'Limit lookups to countries', 'groundwork-common-location-finder' ),
			'placeholder' => 'us, ca',
			'help'        => __( 'Two-letter codes, comma separated. Leave blank to search everywhere.', 'groundwork-common-location-finder' ),
		),
		'geo_viewbox'        => array(
			'tab'         => 'advanced',
			'section'     => 'geocode',
			'type'        => 'text',
			'label'       => __( 'Prefer results in this box', 'groundwork-common-location-finder' ),
			'placeholder' => '-88.0,33.0,-85.0,35.0',
			'help'        => __( 'Four numbers: west, south, east, north. Nudges results toward your region without excluding the rest.', 'groundwork-common-location-finder' ),
		),
		'geo_bounded'        => array(
			'tab'     => 'advanced',
			'section' => 'geocode',
			'type'    => 'bool',
			'label'   => __( 'Restrict strictly to that box', 'groundwork-common-location-finder' ),
			'help'    => __( 'Turns the preference above into a hard limit. An address outside the box then returns nothing at all, which looks like a broken lookup rather than a deliberate one — leave this off unless you mean it.', 'groundwork-common-location-finder' ),
		),

		// ── Advanced: custom tiles ───────────────────────────────────────
		'tile_url'           => array(
			'tab'         => 'advanced',
			'section'     => 'tiles',
			'type'        => 'text',
			'label'       => __( 'Tile URL', 'groundwork-common-location-finder' ),
			'placeholder' => 'https://tile.example.com/{z}/{x}/{y}.png',
			'help'        => __( 'Only used when Map style on the Appearance tab is set to Custom. Must contain {z}, {x} and {y}.', 'groundwork-common-location-finder' ),
		),
		'tile_attr'          => array(
			'tab'     => 'advanced',
			'section' => 'tiles',
			'type'    => 'text',
			'label'   => __( 'Tile attribution', 'groundwork-common-location-finder' ),
			'help'    => __( 'Printed on the map. Nearly every tile provider requires this, and removing it breaks their terms rather than merely being impolite. Links are allowed.', 'groundwork-common-location-finder' ),
		),
		'tile_maxzoom'       => array(
			'tab'     => 'advanced',
			'section' => 'tiles',
			'type'    => 'int',
			'min'     => 1,
			'max'     => 22,
			'label'   => __( 'Deepest zoom offered', 'groundwork-common-location-finder' ),
			'help'    => __( 'Zooming past what a provider actually has produces blank tiles.', 'groundwork-common-location-finder' ),
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
				'title' => __( 'Which field drives what', 'groundwork-common-location-finder' ),
				'intro' => __( 'A location can carry more than one address, schedule or closure list — a mailing address and a visiting one, pantry hours and office hours. They all display; these choose which of them the finder acts on.', 'groundwork-common-location-finder' ),
			),
			'results'    => array(
				'title' => __( 'Results', 'groundwork-common-location-finder' ),
				'intro' => '',
			),
			'hours'      => array(
				'title' => __( 'Opening hours', 'groundwork-common-location-finder' ),
				'intro' => '',
			),
			'start'      => array(
				'title' => __( 'Where the map opens', 'groundwork-common-location-finder' ),
				'intro' => '',
			),
			'directions' => array(
				'title' => __( 'Directions', 'groundwork-common-location-finder' ),
				'intro' => '',
			),
		),
		'advanced' => array(
			'geocode' => array(
				'title' => __( 'Address lookup', 'groundwork-common-location-finder' ),
				'intro' => __( 'The editor’s “find this address” box calls an outside service. These control which one and how it behaves. Visitors never trigger it — only somebody editing a location does.', 'groundwork-common-location-finder' ),
			),
			'tiles'   => array(
				'title' => __( 'Custom map tiles', 'groundwork-common-location-finder' ),
				'intro' => __( 'Ignored unless Map style on the Appearance tab is set to Custom.', 'groundwork-common-location-finder' ),
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
		''   => __( 'Automatic — follow the site language', 'groundwork-common-location-finder' ),
		'mi' => __( 'Miles', 'groundwork-common-location-finder' ),
		'km' => __( 'Kilometers', 'groundwork-common-location-finder' ),
	);
}

/**
 * Directions services offered.
 *
 * @return array<string, string>
 */
function lfndr_directions_choices(): array {
	return array(
		'google' => __( 'Google Maps', 'groundwork-common-location-finder' ),
		'apple'  => __( 'Apple Maps', 'groundwork-common-location-finder' ),
		'osm'    => __( 'OpenStreetMap', 'groundwork-common-location-finder' ),
		'custom' => __( 'Custom URL', 'groundwork-common-location-finder' ),
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
	?>

	<form method="post" action="options.php">
		<?php settings_fields( 'lfndr_settings_group' ); ?>

		<?php
		/* The roles live on the schema rather than in lfndr_settings, and used
		 * to have their own form and their own Save because of it. That put two
		 * save buttons on one tab with nothing saying which covered what — the
		 * storage layout showing through the UI. They now ride this form under
		 * a _roles key and lfndr_sanitize_settings() writes them where they
		 * belong, the same way _apply_preset already worked. */
		if ( isset( $sections['roles'] ) ) :
			?>
			<h2><?php echo esc_html( $sections['roles']['title'] ); ?></h2>
			<p class="description" style="max-width:44em"><?php echo esc_html( $sections['roles']['intro'] ); ?></p>
			<?php
			lfndr_render_roles_fields();
			unset( $sections['roles'] );
		endif;
		?>
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
