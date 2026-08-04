<?php
/**
 * Plugin settings: defaults and the single accessor.
 *
 * @package LocationFinder
 */

defined( 'ABSPATH' ) || exit;

const LFNDR_SETTINGS_OPTION = 'lfndr_settings';

/**
 * Every setting, with the value a fresh install behaves as.
 *
 * Several of these default to '' meaning "derive it", rather than to a concrete
 * value. That is deliberate: a site's timezone and its preferred units are
 * already recorded in WordPress, and copying them into our option at activation
 * would fork them the first time someone changed the original.
 *
 * @return array
 */
function lfndr_setting_defaults(): array {
	return array(
		// Map.
		'center_lat'         => 0.0,
		'center_lng'         => 0.0,
		'zoom'               => 4,
		'fit_to_markers'     => true,
		/* Chosen from a named list on the Settings screen; the three below are
		   the fallback for 'custom' and for sites predating that list. */
		'map_style'          => 'osm',
		'tile_url'           => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
		'tile_attr'          => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
		'tile_maxzoom'       => 19,

		// Time.
		'timezone'           => '',

		// Results.
		'units'              => '',
		'page_size'          => 0,

		// Near me.
		'near_me'            => true,
		'tile_consent'       => true,
		'auto_locate'        => false,

		// Links.
		'directions'         => 'google',
		'directions_pattern' => '',

		// Geocoding, admin-side only.
		'geo_endpoint'       => 'https://nominatim.openstreetmap.org/search',
		'geo_countries'      => '',
		'geo_viewbox'        => '',
		'geo_bounded'        => false,
		/* Pre-filled with the site's admin email rather than left blank, so the
		 * field shows the address that will be sent instead of making somebody
		 * work out that an empty box still identifies them.
		 *
		 * This is a starting value, not a mirror. Once saved it is an ordinary
		 * setting and stops following Settings → General — which is the point:
		 * the address the lookup service sees should change when somebody
		 * decides it should, not because an unrelated field moved. Clearing it
		 * falls back to the admin email again, so there is no way to end up
		 * sending nothing. */
		'geo_email'          => (string) get_option( 'admin_email' ),

		/* Appearance, set from Locations → Settings (see inc/admin-settings.php
		 * for how each is used — some are CSS custom-property overrides, some
		 * are direct style overrides on native buttons). Empty means "inherit
		 * the theme-matched default already built into location-finder.css" —
		 * nothing here is required for the finder to look reasonable out of
		 * the box, and a site that never opens that screen behaves exactly as
		 * if these did not exist. */
		'accent_color'        => '',
		'pin_color'           => '',
		'open_color'          => '',
		'closure_color'       => '',
		'finder_bg'           => '',
		'finder_text'         => '',
		'finder_padding'      => '',
		'surface_color'       => '',
		'on_surface_color'    => '',
		'line_color'          => '',
		'radius'              => '',
		'gap'                 => '',
		'map_height'          => '',
		'panel_height'        => '',
		'control_bg'          => '',
		'control_text'        => '',
		'control_active_bg'   => '',
		'control_active_text' => '',
		'card_bg'             => '',
		'card_text'           => '',
		'card_selected_bg'    => '',
		'card_selected_text'  => '',
		'badge_bg'            => '',
		'badge_text'          => '',
	);
}

/**
 * The per-request settings memo.
 *
 * Its own function rather than a static inside lfndr_setting(), for the same
 * reason lfndr_schema_cache() exists: a writer needs a way to invalidate a
 * reader's cache, and PHP has no way to reach another function's static
 * variable. Without this, a script that calls update_option() and then reads
 * lfndr_setting() in the same request — a migration, WP-CLI, another plugin —
 * would silently see the value from before the write.
 *
 * @param array|null $set   Value to store.
 * @param bool       $clear Forget the cached value.
 * @return array|null
 */
function lfndr_settings_cache( ?array $set = null, bool $clear = false ): ?array {
	static $cache = null;
	if ( $clear ) {
		$cache = null;
		return null;
	}
	if ( null !== $set ) {
		$cache = $set;
	}
	return $cache;
}

add_action( 'update_option_' . LFNDR_SETTINGS_OPTION, 'lfndr_reset_settings_cache' );
add_action( 'add_option_' . LFNDR_SETTINGS_OPTION, 'lfndr_reset_settings_cache' );

/**
 * Clear the settings memo. Hooked to both add_option_* and update_option_* —
 * WordPress fires the former only on an option's first write and the latter
 * on every write after, so a site's very first Settings save needs the same
 * invalidation as every one after it.
 */
function lfndr_reset_settings_cache(): void {
	lfndr_settings_cache( null, true );
}

/**
 * Read one setting.
 *
 * @param string $key Setting key.
 * @return mixed
 */
function lfndr_setting( string $key ) {
	$settings = lfndr_settings_cache();
	if ( null === $settings ) {
		$stored   = get_option( LFNDR_SETTINGS_OPTION );
		$settings = lfndr_settings_cache( array_merge( lfndr_setting_defaults(), is_array( $stored ) ? $stored : array() ) );
	}
	return $settings[ $key ] ?? null;
}

/**
 * The timezone "Open now" is computed in.
 *
 * Falls back to the site's own timezone, which is almost always what a
 * single-region finder wants and is one fewer thing to configure.
 *
 * @return DateTimeZone
 */
function lfndr_timezone(): DateTimeZone {
	$configured = (string) lfndr_setting( 'timezone' );
	if ( '' !== $configured ) {
		try {
			return new DateTimeZone( $configured );
		} catch ( Exception $e ) {
			// Fall through to the site timezone rather than fataling on a bad
			// stored value.
			unset( $e );
		}
	}
	return wp_timezone();
}

/**
 * Distance units: 'mi' or 'km'.
 *
 * Derived from the site locale when unset, because the set of places that use
 * miles is small, well known, and not worth asking about.
 *
 * @return string
 */
function lfndr_units(): string {
	$configured = (string) lfndr_setting( 'units' );
	if ( in_array( $configured, array( 'mi', 'km' ), true ) ) {
		return $configured;
	}
	$imperial = array( 'en_US', 'en_GB', 'en_LR', 'my_MM' );
	return in_array( get_locale(), $imperial, true ) ? 'mi' : 'km';
}

/**
 * The tile URL, attribution and max zoom for the chosen map style.
 *
 * The style setting stores a key, never a URL — so the URL and the attribution
 * that license it are always looked up together and cannot drift apart. A site
 * that has never opened the Settings screen, or that picked "Custom" and left
 * the filters unhooked, falls back to the stored tile_url/tile_attr, which is
 * where those values lived before this screen existed.
 *
 * @return array{url:string, attribution:string, max_zoom:int}
 */
function lfndr_resolve_map_style(): array {
	$styles = lfndr_map_styles();
	$key    = (string) lfndr_setting( 'map_style' );
	$style  = $styles[ $key ] ?? null;

	if ( null === $style || '' === $style['url'] ) {
		return array(
			'url'         => (string) lfndr_setting( 'tile_url' ),
			'attribution' => (string) lfndr_setting( 'tile_attr' ),
			'max_zoom'    => (int) lfndr_setting( 'tile_maxzoom' ),
		);
	}

	return array(
		'url'         => $style['url'],
		'attribution' => $style['attribution'],
		'max_zoom'    => (int) $style['max_zoom'],
	);
}

/**
 * Whether the map tiles come from somewhere other than this site.
 *
 * This is what decides if the consent gate is worth showing. A gate in front of
 * self-hosted tiles protects nobody and trains people to click through the one
 * that matters, so the question is not "is the gate switched on" but "is there
 * actually a third party to warn about".
 *
 * A relative or protocol-relative URL, or one on this site's own host, is not a
 * third party. Anything else is — including a subdomain, since a different host
 * still sees the visitor's address.
 *
 * @return bool
 */
function lfndr_tiles_are_third_party(): bool {
	$url = (string) lfndr_resolve_map_style()['url'];

	if ( '' === $url ) {
		return false;
	}

	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! is_string( $host ) || '' === $host ) {
		return false; // Relative path — served from this site.
	}

	$home = wp_parse_url( home_url(), PHP_URL_HOST );

	return strtolower( $host ) !== strtolower( (string) $home );
}

/**
 * The hostname to name in the consent gate.
 *
 * Shown to the visitor verbatim, because "a third party" is not something a
 * person can make a decision about and "tile.openstreetmap.org" is.
 *
 * @return string
 */
function lfndr_tile_host(): string {
	$host = wp_parse_url( (string) lfndr_resolve_map_style()['url'], PHP_URL_HOST );

	/* Tile URLs routinely carry a {s} subdomain placeholder. Left in, the gate
	 * would name a host that does not exist. */
	return is_string( $host ) ? (string) preg_replace( '/^\{s\}\./', '', $host ) : '';
}

/**
 * Build a directions URL for a formatted address or a coordinate pair.
 *
 * @param string $query Formatted address; may be empty.
 * @param string $lat   Latitude.
 * @param string $lng   Longitude.
 * @return string
 */
function lfndr_directions_url( string $query, string $lat, string $lng ): string {
	/* Prefer the written address over the coordinates. Turn-by-turn services
	 * resolve a street address to a door; a lat/lng resolves to a point, which
	 * on a large campus or a building with one entrance is regularly the wrong
	 * side of it. */
	$target = '' !== $query ? $query : trim( $lat . ',' . $lng, ',' );
	if ( '' === $target ) {
		return '';
	}

	switch ( (string) lfndr_setting( 'directions' ) ) {
		case 'apple':
			$url = 'https://maps.apple.com/?daddr=' . rawurlencode( $target );
			break;
		case 'osm':
			$url = ( '' !== $lat && '' !== $lng )
				? 'https://www.openstreetmap.org/directions?to=' . rawurlencode( $lat . ',' . $lng )
				: 'https://www.openstreetmap.org/search?query=' . rawurlencode( $target );
			break;
		case 'custom':
			$pattern = (string) lfndr_setting( 'directions_pattern' );
			if ( '' === $pattern ) {
				return '';
			}
			$url = strtr(
				$pattern,
				array(
					'{query}' => rawurlencode( $target ),
					'{lat}'   => rawurlencode( $lat ),
					'{lng}'   => rawurlencode( $lng ),
				)
			);
			break;
		case 'google':
		default:
			$url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $target );
			break;
	}

	return (string) esc_url_raw( $url, array( 'http', 'https' ) );
}
