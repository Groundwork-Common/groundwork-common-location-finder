<?php
/**
 * Asset registration and the gates that decide when to enqueue.
 *
 * @package LocationFinder
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'lfndr_register_front_assets', 5 );
add_action( 'admin_enqueue_scripts', 'lfndr_admin_assets' );

/* ── Two layers, and why the asymmetry is the design ─────────────────────────
 * Layer one runs at head time and guesses whether this page contains a finder.
 * It is cheap and it gets the stylesheet into <head>, so there is no flash of
 * unstyled list.
 *
 * Layer two is lfndr_render_finder() calling lfndr_enqueue_finder() as its very
 * first statement. It cannot be missed, because it runs from inside the thing
 * being rendered — but by then <head> is gone.
 *
 * So: layer one is fast and fallible, layer two is late and certain, and
 * wp_enqueue_* being idempotent means running both costs nothing. When layer one
 * guesses wrong the assets print from wp_footer instead: a visible reflow, and a
 * working finder.
 *
 * There is one case layer one simply cannot see. In a block theme the finder can
 * live in a template part, where the queried post's content is empty and there
 * is nothing to match against. Resolving the template hierarchy this early is
 * both fragile and expensive, so it is accepted rather than fought — the
 * consequence is a reflow, and `lfndr_load_assets` is there for anyone who wants
 * to fix it for their own template.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Register the front-end handles, and run layer one of the gate.
 */
function lfndr_register_front_assets(): void {
	wp_register_style( 'leaflet', LFNDR_URL . 'assets/leaflet/leaflet.css', array(), '1.9.4' );
	wp_register_script( 'leaflet', LFNDR_URL . 'assets/leaflet/leaflet.js', array(), '1.9.4', true );

	wp_register_style( 'lfndr-finder', LFNDR_URL . 'assets/css/location-finder.css', array( 'leaflet' ), LFNDR_VERSION );
	wp_register_script(
		'lfndr-finder',
		LFNDR_URL . 'assets/js/location-finder.js',
		array( 'leaflet', 'wp-i18n' ),
		LFNDR_VERSION,
		true
	);

	wp_set_script_translations( 'lfndr-finder', 'groundwork-common-location-finder', LFNDR_DIR . 'languages' );

	/* Attached to the handle, not printed directly: wp_add_inline_style() only
	 * ever outputs alongside a style that actually gets enqueued, so a site
	 * that has customized Appearance but has no finder on the current page
	 * prints nothing — the same gate that protects the base stylesheet
	 * protects this override without any extra code here. */
	$overrides = lfndr_appearance_css();
	if ( '' !== $overrides ) {
		wp_add_inline_style( 'lfndr-finder', $overrides );
	}

	if ( lfndr_page_may_have_finder() ) {
		lfndr_enqueue_finder();
	}
}

/**
 * Enqueue everything a finder needs. Idempotent, and safe to call twice.
 */
function lfndr_enqueue_finder(): void {
	wp_enqueue_style( 'leaflet' );
	wp_enqueue_script( 'leaflet' );
	wp_enqueue_style( 'lfndr-finder' );
	wp_enqueue_script( 'lfndr-finder' );
}

/**
 * Layer one: a cheap guess at whether this page renders a finder.
 *
 * Wrong only in the direction of loading assets a page did not need, which is
 * the harmless direction.
 *
 * @return bool
 */
function lfndr_page_may_have_finder(): bool {
	$post = get_post();

	if ( $post instanceof WP_Post ) {
		if ( has_shortcode( $post->post_content, 'location_finder' ) ) {
			return true;
		}
		if ( has_block( 'groundwork-common-location-finder/finder', $post ) ) {
			return true;
		}
		if ( has_block( 'core/shortcode', $post ) && false !== strpos( $post->post_content, 'location_finder' ) ) {
			return true;
		}
		/* A synced pattern keeps its content in a different post entirely, so
		 * there is nothing here to match. Over-loading is cheap. */
		if ( has_block( 'core/block', $post ) ) {
			return true;
		}
	}

	/**
	 * Force the finder's assets to load on a page the gate cannot detect —
	 * a block theme template part, a widget, a hand-built template.
	 *
	 * @param bool         $load Whether to load.
	 * @param WP_Post|null $post Current post, if any.
	 */
	return (bool) apply_filters( 'lfndr_load_assets', false, $post instanceof WP_Post ? $post : null );
}

/**
 * Admin assets, on our screens only.
 *
 * @param string $hook Current admin page hook.
 */
function lfndr_admin_assets( string $hook ): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	$is_editor = in_array( $hook, array( 'post.php', 'post-new.php' ), true )
		&& $screen && LFNDR_POST_TYPE === $screen->post_type;

	$is_fields = $screen && false !== strpos( (string) $screen->id, LFNDR_FIELDS_PAGE );

	/* Which TAB, not which page. Appearance used to be its own submenu, and this
	 * gate still matched on that page's slug after the tabs replaced it — so on
	 * the Appearance tab the colour swatches silently had no script behind them,
	 * while the orphaned page nobody was meant to reach still worked. Read the
	 * tab, which is where the answer actually lives now. */
	$is_settings = $is_fields && 'appearance' === lfndr_current_tab();

	if ( ! $is_editor && ! $is_fields ) {
		return;
	}

	wp_enqueue_style( 'lfndr-admin', LFNDR_URL . 'assets/css/admin.css', array(), LFNDR_VERSION );

	if ( $is_settings ) {
		/* Not wp-color-picker: these fields accept currentColor and var(), which
		 * it cannot represent and would overwrite. See the note in
		 * lfndr_render_appearance_field(). */
		wp_enqueue_script( 'lfndr-admin-color', LFNDR_URL . 'assets/js/admin-color.js', array(), LFNDR_VERSION, true );
		return;
	}

	if ( $is_editor ) {
		wp_enqueue_script( 'lfndr-admin-repeater', LFNDR_URL . 'assets/js/admin-repeater.js', array(), LFNDR_VERSION, true );
		wp_localize_script(
			'lfndr-admin-repeater',
			'LFNDR_REPEATER',
			array(
				'added'   => __( 'Row added.', 'groundwork-common-location-finder' ),
				'removed' => __( 'Row removed.', 'groundwork-common-location-finder' ),
			)
		);

		wp_enqueue_script( 'lfndr-admin-geocode', LFNDR_URL . 'assets/js/admin-geocode.js', array(), LFNDR_VERSION, true );
		wp_localize_script(
			'lfndr-admin-geocode',
			'LFNDR_GEOCODE',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'lfndr_geocode' ),
				'strings' => array(
					'searching' => __( 'Searching…', 'groundwork-common-location-finder' ),
					'none'      => __( 'No matches. Try a fuller address.', 'groundwork-common-location-finder' ),
					'error'     => __( 'The address lookup failed. Enter the coordinates by hand.', 'groundwork-common-location-finder' ),
					'filled'    => __( 'Address and coordinates filled in.', 'groundwork-common-location-finder' ),
				),
			)
		);
		return;
	}

	if ( ! $is_fields ) {
		return;
	}

	wp_enqueue_script( 'lfndr-admin-fields', LFNDR_URL . 'assets/js/admin-fields.js', array(), LFNDR_VERSION, true );

	$with_options = array();
	foreach ( lfndr_field_types() as $slug => $meta ) {
		if ( ! empty( $meta['has_options'] ) ) {
			$with_options[] = $slug;
		}
	}

	/* These two strings go through wp_localize_script rather than wp.i18n
	 * because they are positional-placeholder sentences that translators need
	 * to reorder. Everything else in the admin JS is structural. */
	wp_localize_script(
		'lfndr-admin-fields',
		'LFNDR_ADMIN',
		array(
			'typesWithOptions' => $with_options,
			'strings'          => array(
				/* translators: 1: field label, 2: new position, 3: total number of shown fields. */
				'moved'  => __( '%1$s moved to position %2$s of %3$s.', 'groundwork-common-location-finder' ),
				/* translators: %s: field label. */
				'hidden' => __( '%s is no longer shown here.', 'groundwork-common-location-finder' ),
			),
		)
	);
}
