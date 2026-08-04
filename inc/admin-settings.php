<?php
/**
 * Locations → Settings: appearance overrides, and the CSS they produce.
 *
 * @package LocationFinder
 */

defined( 'ABSPATH' ) || exit;

const LFNDR_SETTINGS_PAGE = 'lfndr-settings';

add_action( 'admin_menu', 'lfndr_settings_menu' );
add_action( 'admin_init', 'lfndr_register_settings' );

/* ── Why this is a plain settings screen and Fields is not ───────────────────
 * The Fields screen hand-rolls its own admin_post_ handlers because it is
 * really five different actions — add, edit, reorder, retire, restore, erase —
 * each with its own shape and its own confirmation. This screen is the
 * opposite case: a flat bag of independent values, which is exactly what the
 * Settings API is built for. Reaching for a custom form here would just be
 * reimplementing register_setting() by hand.
 *
 * Leaving a field blank is not "unset" in some separate sense — it is the
 * value that reproduces the plugin's shipped default, because
 * lfndr_appearance_css() only ever emits something for a field that has
 * content in it. A site that never opens this screen is byte-for-byte the
 * site before this screen existed.
 *
 * ── Two different kinds of field, and why ────────────────────────────────
 * Map & spacing / Panels & surfaces set a custom property on .lfndr — the same
 * ones location-finder.css already reads via var(), the same ones the README
 * tells a theme it can set for itself. Leaving one blank costs nothing to
 * express: the var() in the stylesheet simply falls through to its own
 * built-in default.
 *
 * Buttons, chips and cards cannot work that way. They are native elements the
 * plugin deliberately never puts a background-color or color on — that
 * omission is the entire mechanism by which "respects the theme" happens. So
 * there is no shipped custom property for a theme, or this screen, to lean on:
 * setting one here means printing an actual background-color / color
 * declaration against the theme's own button styling, and it needs !important
 * to reliably win against a theme selector this plugin cannot predict the
 * specificity of. That is a real, if narrow, exception to "never fight the
 * theme" — but it is no longer this plugin imposing a look; it is what an
 * admin explicitly typed into a field, on a field left blank by default.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Register the Settings submenu.
 */
function lfndr_settings_menu(): void {
	add_submenu_page(
		'edit.php?post_type=' . LFNDR_POST_TYPE,
		__( 'Location Finder Settings', 'location-finder' ),
		__( 'Settings', 'location-finder' ),
		'manage_options',
		LFNDR_SETTINGS_PAGE,
		'lfndr_settings_screen'
	);
}

/**
 * The settings screen's sections, in display order.
 *
 * @return array<string, string>
 */
function lfndr_appearance_sections(): array {
	return array(
		'lfndr_section_map'      => __( 'Map & spacing', 'location-finder' ),
		'lfndr_section_surfaces' => __( 'Panels & surfaces', 'location-finder' ),
		'lfndr_section_controls' => __( 'Buttons, chips & cards', 'location-finder' ),
	);
}

/**
 * Ready-made appearance sets.
 *
 * Each one is a bundle of values for the fields on this screen — nothing more.
 * Choosing one writes those values in and they stay editable afterwards, so a
 * preset is a starting point rather than a mode the site is locked into, and
 * "which preset am I on" is never a question the plugin has to answer.
 *
 * Every set has been checked to clear WCAG AA (4.5:1) on every text-on-fill
 * pair it defines, including the closure notice against the finder background.
 *
 * The dark sets are the reason --lfndr-bg and --lfndr-fg exist. Coloring the
 * cards and chips dark without a canvas underneath does not give a dark finder;
 * it gives a light one with dark parts.
 *
 * One honest cost, the same for all of them: --lfndr-surface and
 * --lfndr-on-surface default to the CSS system colors Canvas and CanvasText,
 * which follow the visitor's own light/dark setting. A preset replaces those
 * with fixed values, so a site on one of the light sets stays light for a
 * visitor in dark mode. That is why none of these ship as a default.
 *
 * @return array<string, array>
 */
function lfndr_style_presets(): array {
	return array(
		'ink' => array(
			'label'  => __( 'Ink', 'location-finder' ),
			'note'   => __( 'Editorial monochrome. Sharp corners, no hue at all.', 'location-finder' ),
			'values' => array(
				'accent_color' => '#111827',
				'badge_bg' => '#f4f4f5',
				'badge_text' => '#3f3f46',
				'card_bg' => '#ffffff',
				'card_selected_bg' => '#f4f4f5',
				'card_selected_text' => '#111827',
				'card_text' => '#111827',
				'closure_color' => '#b91c1c',
				'control_active_bg' => '#111827',
				'control_active_text' => '#ffffff',
				'control_bg' => '#ffffff',
				'control_text' => '#111827',
				'gap' => '1rem',
				'line_color' => '#d4d4d8',
				'map_style' => 'positron',
				'on_surface_color' => '#111827',
				'pin_color' => '#111827',
				'radius' => '4px',
				'surface_color' => '#ffffff',
			),
		),
		'slate' => array(
			'label'  => __( 'Slate', 'location-finder' ),
			'note'   => __( 'Cool gray and understated — the safe choice under a corporate theme.', 'location-finder' ),
			'values' => array(
				'accent_color' => '#334155',
				'badge_bg' => '#e2e8f0',
				'badge_text' => '#334155',
				'card_bg' => '#ffffff',
				'card_selected_bg' => '#e2e8f0',
				'card_selected_text' => '#0f172a',
				'card_text' => '#0f172a',
				'closure_color' => '#b91c1c',
				'control_active_bg' => '#334155',
				'control_active_text' => '#f8fafc',
				'control_bg' => '#ffffff',
				'control_text' => '#0f172a',
				'finder_padding' => '1rem',
				'finder_bg' => '#f8fafc',
				'finder_text' => '#0f172a',
				'gap' => '1rem',
				'line_color' => '#cbd5e1',
				'map_style' => 'positron',
				'on_surface_color' => '#0f172a',
				'pin_color' => '#475569',
				'radius' => '4px',
				'surface_color' => '#ffffff',
			),
		),
		'newsprint' => array(
			'label'  => __( 'Newsprint', 'location-finder' ),
			'note'   => __( 'Warm paper and ink, square corners. Reads as print.', 'location-finder' ),
			'values' => array(
				'accent_color' => '#44403c',
				'badge_bg' => '#ece5d8',
				'badge_text' => '#44403c',
				'card_bg' => '#fffdf9',
				'card_selected_bg' => '#f2ece0',
				'card_selected_text' => '#292524',
				'card_text' => '#1c1917',
				'closure_color' => '#991b1b',
				'control_active_bg' => '#44403c',
				'control_active_text' => '#faf7f2',
				'control_bg' => '#fffdf9',
				'control_text' => '#1c1917',
				'finder_padding' => '1rem',
				'finder_bg' => '#faf7f2',
				'finder_text' => '#1c1917',
				'gap' => '1rem',
				'line_color' => '#ded8cd',
				'map_style' => 'voyager',
				'on_surface_color' => '#1c1917',
				'pin_color' => '#57534e',
				'radius' => '2px',
				'surface_color' => '#fffdf9',
			),
		),
		'clinical' => array(
			'label'  => __( 'Clinical', 'location-finder' ),
			'note'   => __( 'Blue on white, tight corners. Institutional and plain.', 'location-finder' ),
			'values' => array(
				'accent_color' => '#1d4ed8',
				'badge_bg' => '#e2e8f0',
				'badge_text' => '#334155',
				'card_bg' => '#ffffff',
				'card_selected_bg' => '#eff6ff',
				'card_selected_text' => '#1e3a8a',
				'card_text' => '#0f172a',
				'closure_color' => '#b91c1c',
				'control_active_bg' => '#1d4ed8',
				'control_active_text' => '#ffffff',
				'control_bg' => '#ffffff',
				'control_text' => '#0f172a',
				'gap' => '1rem',
				'line_color' => '#cbd5e1',
				'map_style' => 'positron',
				'on_surface_color' => '#0f172a',
				'pin_color' => '#1d4ed8',
				'radius' => '6px',
				'surface_color' => '#ffffff',
			),
		),
		'coast' => array(
			'label'  => __( 'Coast', 'location-finder' ),
			'note'   => __( 'Teal on near-white. Airy, generous corners.', 'location-finder' ),
			'values' => array(
				'accent_color' => '#0e7490',
				'badge_bg' => '#cffafe',
				'badge_text' => '#155e75',
				'card_bg' => '#ffffff',
				'card_selected_bg' => '#cffafe',
				'card_selected_text' => '#155e75',
				'card_text' => '#164e63',
				'closure_color' => '#b91c1c',
				'control_active_bg' => '#0e7490',
				'control_active_text' => '#ecfeff',
				'control_bg' => '#ffffff',
				'control_text' => '#164e63',
				'finder_padding' => '1rem',
				'finder_bg' => '#f7fdfe',
				'finder_text' => '#164e63',
				'gap' => '1.25rem',
				'line_color' => '#c8e4ec',
				'map_style' => 'positron',
				'on_surface_color' => '#164e63',
				'pin_color' => '#0891b2',
				'radius' => '12px',
				'surface_color' => '#ffffff',
			),
		),
		'forest' => array(
			'label'  => __( 'Forest', 'location-finder' ),
			'note'   => __( 'Deep greens. Suits conservation, parks and food growing.', 'location-finder' ),
			'values' => array(
				'accent_color' => '#166534',
				'badge_bg' => '#dcfce7',
				'badge_text' => '#166534',
				'card_bg' => '#ffffff',
				'card_selected_bg' => '#dcfce7',
				'card_selected_text' => '#14532d',
				'card_text' => '#14532d',
				'closure_color' => '#b91c1c',
				'control_active_bg' => '#166534',
				'control_active_text' => '#f0fdf4',
				'control_bg' => '#ffffff',
				'control_text' => '#14532d',
				'finder_padding' => '1rem',
				'finder_bg' => '#f7fbf8',
				'finder_text' => '#14532d',
				'gap' => '1.25rem',
				'line_color' => '#cfe4d5',
				'map_style' => 'voyager',
				'on_surface_color' => '#14532d',
				'pin_color' => '#15803d',
				'radius' => '8px',
				'surface_color' => '#ffffff',
			),
		),
		'civic' => array(
			'label'  => __( 'Civic', 'location-finder' ),
			'note'   => __( 'Terracotta and sand. Built for community organizations.', 'location-finder' ),
			'values' => array(
				'accent_color' => '#9a3412',
				'badge_bg' => '#fde8d7',
				'badge_text' => '#7c2d12',
				'card_bg' => '#fffdfa',
				'card_selected_bg' => '#ffedd5',
				'card_selected_text' => '#7c2d12',
				'card_text' => '#422006',
				'closure_color' => '#b91c1c',
				'control_active_bg' => '#9a3412',
				'control_active_text' => '#fff7ed',
				'control_bg' => '#fffbf5',
				'control_text' => '#7c2d12',
				'finder_padding' => '1rem',
				'finder_bg' => '#fffbf5',
				'finder_text' => '#422006',
				'gap' => '1.25rem',
				'line_color' => '#e7d5c0',
				'map_style' => 'voyager',
				'on_surface_color' => '#422006',
				'pin_color' => '#c2410c',
				'radius' => '10px',
				'surface_color' => '#fffbf5',
			),
		),
		'sunrise' => array(
			'label'  => __( 'Sunrise', 'location-finder' ),
			'note'   => __( 'Amber and cream, very round. Warm and informal.', 'location-finder' ),
			'values' => array(
				'accent_color' => '#b45309',
				'badge_bg' => '#fef3c7',
				'badge_text' => '#92400e',
				'card_bg' => '#ffffff',
				'card_selected_bg' => '#fef3c7',
				'card_selected_text' => '#78350f',
				'card_text' => '#451a03',
				'closure_color' => '#b91c1c',
				'control_active_bg' => '#b45309',
				'control_active_text' => '#fffbeb',
				'control_bg' => '#ffffff',
				'control_text' => '#451a03',
				'finder_padding' => '1rem',
				'finder_bg' => '#fffcf5',
				'finder_text' => '#451a03',
				'gap' => '1.25rem',
				'line_color' => '#eddcc0',
				'map_style' => 'voyager',
				'on_surface_color' => '#451a03',
				'pin_color' => '#d97706',
				'radius' => '14px',
				'surface_color' => '#ffffff',
			),
		),
		'rose' => array(
			'label'  => __( 'Rose', 'location-finder' ),
			'note'   => __( 'Burgundy on blush. Softer than red without losing weight.', 'location-finder' ),
			'values' => array(
				'accent_color' => '#9f1239',
				'badge_bg' => '#ffe4e6',
				'badge_text' => '#9f1239',
				'card_bg' => '#ffffff',
				'card_selected_bg' => '#ffe4e6',
				'card_selected_text' => '#881337',
				'card_text' => '#4c0519',
				'closure_color' => '#9f1239',
				'control_active_bg' => '#9f1239',
				'control_active_text' => '#fff1f2',
				'control_bg' => '#ffffff',
				'control_text' => '#4c0519',
				'finder_padding' => '1rem',
				'finder_bg' => '#fffafb',
				'finder_text' => '#4c0519',
				'gap' => '1.25rem',
				'line_color' => '#f0d2da',
				'map_style' => 'voyager',
				'on_surface_color' => '#4c0519',
				'pin_color' => '#be123c',
				'radius' => '12px',
				'surface_color' => '#ffffff',
			),
		),
		'plum' => array(
			'label'  => __( 'Plum', 'location-finder' ),
			'note'   => __( 'Deep purple on white. Quiet and a little formal.', 'location-finder' ),
			'values' => array(
				'accent_color' => '#6b21a8',
				'badge_bg' => '#f3e8ff',
				'badge_text' => '#6b21a8',
				'card_bg' => '#ffffff',
				'card_selected_bg' => '#f3e8ff',
				'card_selected_text' => '#581c87',
				'card_text' => '#3b0764',
				'closure_color' => '#be123c',
				'control_active_bg' => '#6b21a8',
				'control_active_text' => '#faf5ff',
				'control_bg' => '#ffffff',
				'control_text' => '#3b0764',
				'finder_padding' => '1rem',
				'finder_bg' => '#fdfaff',
				'finder_text' => '#3b0764',
				'gap' => '1rem',
				'line_color' => '#e2d3f0',
				'map_style' => 'positron',
				'on_surface_color' => '#3b0764',
				'pin_color' => '#7e22ce',
				'radius' => '10px',
				'surface_color' => '#ffffff',
			),
		),
		'soft' => array(
			'label'  => __( 'Soft', 'location-finder' ),
			'note'   => __( 'Indigo, big corners, generous spacing. Friendly.', 'location-finder' ),
			'values' => array(
				'accent_color' => '#4f46e5',
				'badge_bg' => '#ede9fe',
				'badge_text' => '#4338ca',
				'card_bg' => '#ffffff',
				'card_selected_bg' => '#eef2ff',
				'card_selected_text' => '#3730a3',
				'card_text' => '#1e1b4b',
				'closure_color' => '#be123c',
				'control_active_bg' => '#4f46e5',
				'control_active_text' => '#ffffff',
				'control_bg' => '#f5f3ff',
				'control_text' => '#3730a3',
				'gap' => '1.5rem',
				'line_color' => '#ddd6fe',
				'map_style' => 'positron',
				'on_surface_color' => '#1e1b4b',
				'pin_color' => '#6366f1',
				'radius' => '16px',
				'surface_color' => '#ffffff',
			),
		),
		'contrast' => array(
			'label'  => __( 'Contrast', 'location-finder' ),
			'note'   => __( 'Maximum contrast throughout, square corners, yellow selection. For sites that must be legible above all else.', 'location-finder' ),
			'values' => array(
				'accent_color' => '#000000',
				'badge_bg' => '#ffffff',
				'badge_text' => '#000000',
				'card_bg' => '#ffffff',
				'card_selected_bg' => '#ffe600',
				'card_selected_text' => '#000000',
				'card_text' => '#000000',
				'closure_color' => '#a30000',
				'control_active_bg' => '#000000',
				'control_active_text' => '#ffffff',
				'control_bg' => '#ffffff',
				'control_text' => '#000000',
				'finder_padding' => '1rem',
				'finder_bg' => '#ffffff',
				'finder_text' => '#000000',
				'gap' => '1rem',
				'line_color' => '#000000',
				'map_style' => 'positron',
				'on_surface_color' => '#000000',
				'pin_color' => '#000000',
				'radius' => '0px',
				'surface_color' => '#ffffff',
			),
		),
		'night' => array(
			'label'  => __( 'Night', 'location-finder' ),
			'note'   => __( 'Full dark in navy, with a bright blue accent.', 'location-finder' ),
			'values' => array(
				'accent_color' => '#38bdf8',
				'badge_bg' => '#334155',
				'badge_text' => '#cbd5e1',
				'card_bg' => '#1e293b',
				'card_selected_bg' => '#334155',
				'card_selected_text' => '#f1f5f9',
				'card_text' => '#e2e8f0',
				'closure_color' => '#fb7185',
				'control_active_bg' => '#38bdf8',
				'control_active_text' => '#0f172a',
				'control_bg' => '#1e293b',
				'control_text' => '#e2e8f0',
				'finder_padding' => '1rem',
				'finder_bg' => '#0f172a',
				'finder_text' => '#e2e8f0',
				'gap' => '1rem',
				'line_color' => '#334155',
				'map_style' => 'dark',
				'on_surface_color' => '#e2e8f0',
				'pin_color' => '#38bdf8',
				'radius' => '10px',
				'surface_color' => '#0f172a',
			),
		),
		'graphite' => array(
			'label'  => __( 'Graphite', 'location-finder' ),
			'note'   => __( 'Full dark in pure grayscale. No hue to clash with anything.', 'location-finder' ),
			'values' => array(
				'accent_color' => '#d4d4d8',
				'badge_bg' => '#3f3f46',
				'badge_text' => '#d4d4d8',
				'card_bg' => '#27272a',
				'card_selected_bg' => '#3f3f46',
				'card_selected_text' => '#fafafa',
				'card_text' => '#e4e4e7',
				'closure_color' => '#fca5a5',
				'control_active_bg' => '#e4e4e7',
				'control_active_text' => '#18181b',
				'control_bg' => '#27272a',
				'control_text' => '#e4e4e7',
				'finder_padding' => '1rem',
				'finder_bg' => '#18181b',
				'finder_text' => '#e4e4e7',
				'gap' => '1rem',
				'line_color' => '#3f3f46',
				'map_style' => 'dark',
				'on_surface_color' => '#e4e4e7',
				'pin_color' => '#e4e4e7',
				'radius' => '6px',
				'surface_color' => '#18181b',
			),
		),
		'lagoon' => array(
			'label'  => __( 'Lagoon', 'location-finder' ),
			'note'   => __( 'Full dark in deep teal.', 'location-finder' ),
			'values' => array(
				'accent_color' => '#2dd4bf',
				'badge_bg' => '#115e59',
				'badge_text' => '#99f6e4',
				'card_bg' => '#134e4a',
				'card_selected_bg' => '#115e59',
				'card_selected_text' => '#f0fdfa',
				'card_text' => '#ccfbf1',
				'closure_color' => '#fda4af',
				'control_active_bg' => '#2dd4bf',
				'control_active_text' => '#042f2e',
				'control_bg' => '#134e4a',
				'control_text' => '#ccfbf1',
				'finder_padding' => '1rem',
				'finder_bg' => '#042f2e',
				'finder_text' => '#ccfbf1',
				'gap' => '1rem',
				'line_color' => '#115e59',
				'map_style' => 'dark',
				'on_surface_color' => '#ccfbf1',
				'pin_color' => '#2dd4bf',
				'radius' => '10px',
				'surface_color' => '#042f2e',
			),
		),
	);
}

/**
 * The map styles offered on the Settings screen.
 *
 * A named list rather than a URL box. The URL is the wrong thing to ask an
 * admin for: it carries {z}/{x}/{y} placeholders, sometimes {s} and {r} too, it
 * has to be paired with the right attribution to be used lawfully, and a typo
 * produces a blank gray map with nothing to say why. A list makes the legal
 * pairing automatic — picking a style brings its attribution with it — and the
 * only way to get a broken map is to choose "Custom" deliberately.
 *
 * Attribution is a license term, not a credit. Every entry ships the wording
 * its provider requires, and the plugin prints it on the map because removing
 * it would put the site in breach.
 *
 * Leaflet 1.9.4, vendored here, already resolves {s} against its default
 * subdomains and {r} to "@2x" on retina screens, so these work unmodified.
 *
 * @return array<string, array>
 */
function lfndr_map_styles(): array {
	$osm   = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
	$carto = $osm . ' &copy; <a href="https://carto.com/attributions">CARTO</a>';

	/* The same sentence on all three CARTO styles, written once. It is a term of
	 * use rather than a license — nothing stops the tiles loading on a commercial
	 * site, which is exactly why it has to be said at the point of choosing
	 * instead of only in readme.txt, where nobody is looking while picking from
	 * a dropdown. Any CARTO-backed style added later must carry it too. */
	$carto_terms = __( 'CARTO’s free basemaps are for non-commercial use. On a commercial site choose OpenStreetMap, or Custom with a provider you have an account with.', 'location-finder' );

	return array(
		'osm'      => array(
			'label'       => __( 'OpenStreetMap (standard)', 'location-finder' ),
			'url'         => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
			'attribution' => $osm,
			'max_zoom'    => 19,
			'note'        => __( 'The default. No account and no cost.', 'location-finder' ),
			'terms'       => __( 'Served by donated infrastructure under a usage policy meant for modest traffic. A busy site should self-host its tiles or move to a paid provider via Custom.', 'location-finder' ),
			'terms_url'   => 'https://operations.osmfoundation.org/policies/tiles/',
		),
		'positron' => array(
			'label'       => __( 'Light — muted grays', 'location-finder' ),
			'url'         => 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
			'attribution' => $carto,
			'max_zoom'    => 20,
			'note'        => __( 'Pale and low-contrast, so pins and the results carry the color. Suits most light palettes.', 'location-finder' ),
			'terms'       => $carto_terms,
			'terms_url'   => 'https://carto.com/legal/',
		),
		'voyager'  => array(
			'label'       => __( 'Light — warm, more detail', 'location-finder' ),
			'url'         => 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
			'attribution' => $carto,
			'max_zoom'    => 20,
			'note'        => __( 'Keeps road and place names legible. The one to pick when people navigate by the map itself.', 'location-finder' ),
			'terms'       => $carto_terms,
			'terms_url'   => 'https://carto.com/legal/',
		),
		'dark'     => array(
			'label'       => __( 'Dark', 'location-finder' ),
			'url'         => 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
			'attribution' => $carto,
			'max_zoom'    => 20,
			'note'        => __( 'For a dark finder background. On a light one it reads as a hole in the page.', 'location-finder' ),
			'terms'       => $carto_terms,
			'terms_url'   => 'https://carto.com/legal/',
		),
		'custom'   => array(
			'label'       => __( 'Custom — set by a filter', 'location-finder' ),
			'url'         => '',
			'attribution' => '',
			'max_zoom'    => 19,
			'note'        => __( 'Use the lfndr_tile_url and lfndr_tile_attribution filters. Choose this for a paid provider or self-hosted tiles.', 'location-finder' ),
		),
	);
}

/**
 * The appearance fields: one registry driving the settings form, the
 * sanitizer, and the CSS output, so the three cannot drift out of sync with
 * each other the way three separate lists would.
 *
 * Every field is one of two modes:
 *
 *   'var'  (the default when 'mode' is omitted) writes into the single
 *          .lfndr{--custom-property:value} block, consumed by a var() the
 *          stylesheet already has. Falls through cleanly to the file's own
 *          default when blank, because the var() itself still resolves.
 *
 *   'rule' writes a standalone `selector{property:value !important}` block —
 *          for the native buttons, chips and cards the plugin deliberately
 *          never puts a color declaration on. See the file header for why
 *          these two cannot share a mechanism.
 *
 * @return array<string, array>
 */
function lfndr_appearance_fields(): array {
	$chips  = ".lfndr__chip, .lfndr__maximize, .lfndr__reset, .lfndr__more, .lfndr__filters-toggle, .lfndr__apply, .lfndr__search-input, .lfndr__facet-select";
	$active = ".lfndr__chip[aria-pressed='true'], .lfndr__maximize[aria-pressed='true'], .lfndr__filters-toggle[aria-expanded='true']";
	$card   = '.lfndr-card__button';
	$card_s = '.lfndr-card.is-selected .lfndr-card__button';
	/* Tags only. A status badge is a dot and a word with no container, so there
	   is no fill to set — and painting one put it back to looking like the tags
	   it is meant to be distinguishable from. Its color follows the text
	   around it, which is what a status should do. */
	$badge  = '.lfndr-tag';

	return array(
		// ── Map & spacing ────────────────────────────────────────────────
		'map_style'           => array(
			'section'     => 'lfndr_section_map',
			'mode'        => 'choice',
			'type'        => 'choice',
			'choices'     => 'lfndr_map_styles',
			'label'       => __( 'Map style', 'location-finder' ),
			'placeholder' => '',
			'help'        => __( 'The basemap the pins sit on. Each option brings the attribution its provider requires.', 'location-finder' ),
		),
		'accent_color'        => array(
			'section'     => 'lfndr_section_map',
			'css_var'     => '--lfndr-accent',
			'type'        => 'color',
			'label'       => __( 'Accent color', 'location-finder' ),
			'placeholder' => 'currentColor',
			'help'        => __( 'Map pins and focus rings. Leave blank to match the surrounding text color — the right default in almost every theme. Accepts a hex code, a named color, or a theme variable such as var(--wp--preset--color--accent-1).', 'location-finder' ),
		),
		'pin_color'           => array(
			'section'     => 'lfndr_section_map',
			'css_var'     => '--lfndr-pin',
			'type'        => 'color',
			'label'       => __( 'Map pin color', 'location-finder' ),
			'placeholder' => __( 'the accent color above', 'location-finder' ),
			'help'        => __( 'Set this when the pins want a different color from focus rings and chip borders — a brand color that reads well on a map is often not the one that reads well as text.', 'location-finder' ),
		),
		'radius'              => array(
			'section'     => 'lfndr_section_map',
			'css_var'     => '--lfndr-radius',
			'type'        => 'length',
			'label'       => __( 'Corner radius', 'location-finder' ),
			'placeholder' => '14px',
			'help'        => __( 'Rounding on the map, the search suggestions and the detail pane. Filter chips, badges and buttons are always fully rounded regardless of this setting. A number with a unit, such as 8px or 0.5rem.', 'location-finder' ),
		),
		'gap'                 => array(
			'section'     => 'lfndr_section_map',
			'css_var'     => '--lfndr-gap',
			'type'        => 'length',
			'label'       => __( 'Spacing', 'location-finder' ),
			'placeholder' => '1rem',
			'help'        => __( 'The base gap between the search box, filters, results and map.', 'location-finder' ),
		),
		'map_height'          => array(
			'section'     => 'lfndr_section_map',
			'css_var'     => '--lfndr-map-height',
			'type'        => 'length',
			'label'       => __( 'Map height', 'location-finder' ),
			'placeholder' => '480px',
			'help'        => __( 'Map height where the map sits above the results — a phone, or a finder in a narrow column. The map is never taller than this, and never taller than a third of the screen. On a wide screen the map fills the finder instead, so use Panel height there. A single finder can still override this from its own block settings.', 'location-finder' ),
		),
		'panel_height'        => array(
			'section'     => 'lfndr_section_map',
			'css_var'     => '--lfndr-panel-height',
			'type'        => 'length',
			'label'       => __( 'Panel height', 'location-finder' ),
			'placeholder' => '480px',
			'help'        => __( 'How tall the finder is on a wide screen, where the results and the map sit side by side inside one bordered frame. Both columns are this height; the result list scrolls within it.', 'location-finder' ),
		),

		// ── Panels & surfaces ────────────────────────────────────────────
		'finder_bg'           => array(
			'section'     => 'lfndr_section_surfaces',
			'css_var'     => '--lfndr-bg',
			'type'        => 'color',
			'label'       => __( 'Finder background', 'location-finder' ),
			'placeholder' => __( 'the page background', 'location-finder' ),
			'help'        => __( 'The ground the whole finder sits on. Left blank it shows the page through, which is right for a finder embedded in a normal layout. Set it when the other colors here have moved away from the page — dark cards on a light page read as a light finder with dark parts rather than a dark finder.', 'location-finder' ),
		),
		'finder_text'         => array(
			'section'     => 'lfndr_section_surfaces',
			'css_var'     => '--lfndr-fg',
			'type'        => 'color',
			'label'       => __( 'Finder text', 'location-finder' ),
			'placeholder' => __( 'inherited from the page', 'location-finder' ),
			'help'        => __( 'Set this whenever you set a finder background. Text inside the finder otherwise keeps the color the page gives it, which on a background that has moved away from the page means dark on dark.', 'location-finder' ),
		),
		'finder_padding'      => array(
			'section'     => 'lfndr_section_surfaces',
			'css_var'     => '--lfndr-pad',
			'type'        => 'length',
			'label'       => __( 'Finder padding', 'location-finder' ),
			'placeholder' => '0',
			'help'        => __( 'Space between the finder background and what sits on it. Set it whenever you set a background — without it the search box and cards run flat into the edge of the color. Leave blank when there is no background, so the finder stays aligned with the rest of the page.', 'location-finder' ),
		),
		'surface_color'       => array(
			'section'     => 'lfndr_section_surfaces',
			'css_var'     => '--lfndr-surface',
			'type'        => 'color',
			'label'       => __( 'Surface color', 'location-finder' ),
			'placeholder' => 'Canvas',
			'help'        => __( 'Background of the search suggestions and the detail pane, and the ring drawn around each map pin. Leave blank to use the system background color, which already adapts to dark mode.', 'location-finder' ),
		),
		'on_surface_color'    => array(
			'section'     => 'lfndr_section_surfaces',
			'css_var'     => '--lfndr-on-surface',
			'type'        => 'color',
			'label'       => __( 'Text on surface', 'location-finder' ),
			'placeholder' => 'CanvasText',
			'help'        => __( 'Text color inside the detail pane and search suggestions. Leave blank to use the system text color.', 'location-finder' ),
		),
		'open_color'          => array(
			'section'     => 'lfndr_section_surfaces',
			'css_var'     => '--lfndr-open',
			'type'        => 'color',
			'label'       => __( 'Open indicator', 'location-finder' ),
			'placeholder' => '#22c55e',
			'help'        => __( 'The lamp beside "Open now" and "Open today". Green by default, and one of only two places this plugin ships a color of its own — on a status light the color is the information rather than decoration.', 'location-finder' ),
		),
		'closure_color'       => array(
			'section'     => 'lfndr_section_surfaces',
			'css_var'     => '--lfndr-closure',
			'type'        => 'color',
			'label'       => __( 'Closure notice color', 'location-finder' ),
			'placeholder' => __( 'the surrounding text color', 'location-finder' ),
			'help'        => __( 'The rule, tint, icon and text of the "Temporarily closed" notice, together. The plugin ships it in the text color rather than red, so that it cannot clash with a theme it has never seen — set a color here if you would rather it shouted.', 'location-finder' ),
		),
		'line_color'          => array(
			'section'     => 'lfndr_section_surfaces',
			'css_var'     => '--lfndr-line',
			'type'        => 'color',
			'label'       => __( 'Border color', 'location-finder' ),
			'placeholder' => __( 'a soft tint of the text color', 'location-finder' ),
			'help'        => __( 'Badge outlines and dividers.', 'location-finder' ),
		),

		// ── Buttons, chips & cards ───────────────────────────────────────
		// These are native <button>s the plugin never colors itself, so
		// there is no var() fallback to lean on — see the file header.
		'control_bg'          => array(
			'section'     => 'lfndr_section_controls',
			'mode'        => 'rule',
			'selector'    => $chips,
			'property'    => 'background-color',
			'type'        => 'color',
			'label'       => __( 'Button & chip background', 'location-finder' ),
			'placeholder' => __( "the theme's own button color", 'location-finder' ),
			'help'        => __( 'The search box, the filter chips, and every button — Filters, Full screen, Apply, Clear filters, Show all results. Leave blank to use the theme\'s own button styling.', 'location-finder' ),
		),
		'control_text'        => array(
			'section'     => 'lfndr_section_controls',
			'mode'        => 'rule',
			'selector'    => $chips,
			'property'    => 'color',
			'type'        => 'color',
			'label'       => __( 'Button & chip text', 'location-finder' ),
			'placeholder' => __( "the theme's own button text color", 'location-finder' ),
			'help'        => __( 'Set alongside the background above — a background with no matching text color can end up unreadable.', 'location-finder' ),
		),
		'control_active_bg'   => array(
			'section'     => 'lfndr_section_controls',
			'mode'        => 'rule',
			'selector'    => $active,
			'property'    => 'background-color',
			'type'        => 'color',
			'label'       => __( 'Pressed button & chip background', 'location-finder' ),
			'placeholder' => '',
			'help'        => __( 'A selected filter chip, the Filters button while its panel is open, or Full screen while it is open.', 'location-finder' ),
		),
		'control_active_text' => array(
			'section'     => 'lfndr_section_controls',
			'mode'        => 'rule',
			'selector'    => $active,
			'property'    => 'color',
			'type'        => 'color',
			'label'       => __( 'Pressed button & chip text', 'location-finder' ),
			'placeholder' => '',
			'help'        => '',
		),
		'card_bg'             => array(
			'section'     => 'lfndr_section_controls',
			'mode'        => 'rule',
			'selector'    => $card,
			'property'    => 'background-color',
			'type'        => 'color',
			'label'       => __( 'Card background', 'location-finder' ),
			'placeholder' => __( 'a neutral system background', 'location-finder' ),
			'help'        => __( 'Each location in the result list. A card is a native button for keyboard and screen-reader purposes, but reads as content, not as an action — left blank it uses a neutral panel background rather than the theme\'s button color.', 'location-finder' ),
		),
		'card_text'           => array(
			'section'     => 'lfndr_section_controls',
			'mode'        => 'rule',
			'selector'    => $card,
			'property'    => 'color',
			'type'        => 'color',
			'label'       => __( 'Card text', 'location-finder' ),
			'placeholder' => __( 'a neutral system text color', 'location-finder' ),
			'help'        => '',
		),
		'card_selected_bg'    => array(
			'section'     => 'lfndr_section_controls',
			'mode'        => 'rule',
			'selector'    => $card_s,
			'property'    => 'background-color',
			'type'        => 'color',
			'label'       => __( 'Selected card background', 'location-finder' ),
			'placeholder' => '',
			'help'        => __( 'The card currently open in the detail pane.', 'location-finder' ),
		),
		'card_selected_text'  => array(
			'section'     => 'lfndr_section_controls',
			'mode'        => 'rule',
			'selector'    => $card_s,
			'property'    => 'color',
			'type'        => 'color',
			'label'       => __( 'Selected card text', 'location-finder' ),
			'placeholder' => '',
			'help'        => '',
		),
		'badge_bg'            => array(
			'section'     => 'lfndr_section_controls',
			'mode'        => 'rule',
			'selector'    => $badge,
			'property'    => 'background-color',
			'type'        => 'color',
			'label'       => __( 'Tag background', 'location-finder' ),
			'placeholder' => '',
			'help'        => __( 'The small service, access and status pills shown on cards and in the detail pane.', 'location-finder' ),
		),
		'badge_text'          => array(
			'section'     => 'lfndr_section_controls',
			'mode'        => 'rule',
			'selector'    => $badge,
			'property'    => 'color',
			'type'        => 'color',
			'label'       => __( 'Tag text', 'location-finder' ),
			'placeholder' => '',
			'help'        => '',
		),
	);
}

/**
 * Register the settings option, sections and fields.
 */
function lfndr_register_settings(): void {
	register_setting(
		'lfndr_settings_group',
		LFNDR_SETTINGS_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'lfndr_sanitize_settings',
			'default'           => lfndr_setting_defaults(),
		)
	);

	$intros = array(
		'lfndr_section_map'      => 'lfndr_section_intro_map',
		'lfndr_section_surfaces' => 'lfndr_section_intro_surfaces',
		'lfndr_section_controls' => 'lfndr_section_intro_controls',
	);

	foreach ( lfndr_appearance_sections() as $section_id => $title ) {
		add_settings_section( $section_id, $title, $intros[ $section_id ] ?? '__return_null', LFNDR_SETTINGS_PAGE );
	}

	foreach ( lfndr_appearance_fields() as $key => $field ) {
		add_settings_field(
			'lfndr_' . $key,
			$field['label'],
			'lfndr_render_appearance_field',
			LFNDR_SETTINGS_PAGE,
			$field['section'],
			array(
				'key'   => $key,
				'field' => $field,
			)
		);
	}
}

/**
 * "Map & spacing" section intro.
 */
function lfndr_section_intro_map(): void {
	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Leave any field blank to keep the theme-matched default. Nothing on this screen is required — these exist for the times a theme\'s own colors or sizing are not quite right, without needing to edit CSS.', 'location-finder' )
	);
}

/**
 * "Panels & surfaces" section intro.
 */
function lfndr_section_intro_surfaces(): void {
	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Backgrounds and text for the parts of the finder that sit above the map or the result list, rather than inside them.', 'location-finder' )
	);
}

/**
 * "Buttons, chips & cards" section intro.
 */
function lfndr_section_intro_controls(): void {
	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Every clickable control in the finder is a native button, colored by your theme by default — the same principle as the rest of this plugin. Setting one of these overrides that styling just for the finder, and takes priority over the theme so the color you pick is the one you get.', 'location-finder' )
	);
}

/**
 * Render one appearance field.
 *
 * A plain text input rather than a color-picker widget, deliberately: a
 * native color picker forces its value to a 6-digit hex, which would make it
 * impossible to type the one thing the README recommends most — a reference to
 * the theme's own variable, such as var(--wp--preset--color--accent-1).
 *
 * @param array $args {
 *     @type string $key   Setting key.
 *     @type array  $field Field definition from lfndr_appearance_fields().
 * }
 */
function lfndr_render_appearance_field( array $args ): void {
	$key   = $args['key'];
	$field = $args['field'];
	$value = (string) lfndr_setting( $key );

	if ( 'choice' === $field['type'] ) {
		lfndr_render_choice_field( $key, $field, $value );
		return;
	}

	/* A swatch beside the box, not instead of it.
	 *
	 * These fields accept more than a hex code, and that is the whole point of
	 * them: currentColor, the system keywords Canvas and CanvasText, and
	 * var(--wp--preset--color--accent-1), which is the reference the README
	 * tells themes to use. WordPress's own wp-color-picker replaces the input
	 * with a picker that only understands colors it can parse, so any of those
	 * would be silently rewritten or dropped the first time the field was
	 * touched.
	 *
	 * A native swatch alongside writes a hex in when somebody wants to pick
	 * one, and otherwise stays out of the way. */
	printf(
		'<span class="lfndr-color-field">
			<input type="text" class="regular-text code" id="lfndr-setting-%1$s" name="%2$s[%1$s]" value="%3$s" placeholder="%4$s" />%5$s
		</span>',
		esc_attr( $key ),
		esc_attr( LFNDR_SETTINGS_OPTION ),
		esc_attr( $value ),
		esc_attr( $field['placeholder'] ),
		'color' === $field['type']
			? sprintf(
				'<input type="color" class="lfndr-color-field__swatch" value="%1$s" data-for="lfndr-setting-%2$s" aria-label="%3$s" />',
				esc_attr( preg_match( '/^#[0-9a-f]{6}$/i', $value ) ? $value : '#ffffff' ),
				esc_attr( $key ),
				esc_attr( sprintf( /* translators: %s: field label. */ __( 'Pick a color for %s', 'location-finder' ), $field['label'] ) )
			)
			: ''
	);

	if ( '' !== $field['help'] ) {
		printf( '<p class="description">%s</p>', esc_html( $field['help'] ) );
	}
}

/**
 * A <select> for a choice field, with each option's note under it.
 *
 * @param string $key   Setting key.
 * @param array  $field Field definition.
 * @param string $value Stored value.
 */
function lfndr_render_choice_field( string $key, array $field, string $value ): void {
	$choices = call_user_func( $field['choices'] );

	printf(
		'<select id="lfndr-setting-%1$s" name="%2$s[%1$s]">',
		esc_attr( $key ),
		esc_attr( LFNDR_SETTINGS_OPTION )
	);
	foreach ( $choices as $choice_key => $choice ) {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $choice_key ),
			selected( $value, $choice_key, false ),
			esc_html( $choice['label'] )
		);
	}
	echo '</select>';

	if ( '' !== $field['help'] ) {
		printf( '<p class="description">%s</p>', esc_html( $field['help'] ) );
	}

	/* The terms for whatever is selected right now, stated plainly and up front.
	 * The list below covers every option so a choice can be made between them,
	 * but a condition attached to the CURRENT setting is not a thing to go
	 * looking for — if the site is already outside those terms, that belongs on
	 * screen without being hunted for. */
	$current = $choices[ $value ] ?? null;
	if ( $current && '' !== ( $current['terms'] ?? '' ) ) {
		printf(
			'<p class="lfndr-terms lfndr-terms--active"><strong>%1$s</strong> %2$s %3$s</p>',
			esc_html__( 'Terms of use:', 'location-finder' ),
			esc_html( $current['terms'] ),
			'' === ( $current['terms_url'] ?? '' ) ? '' : sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( $current['terms_url'] ),
				esc_html__( 'Read the terms', 'location-finder' )
			)
		);
	}

	/* Every note, not just the selected one — the point of the list is to let
	 * somebody choose, and they cannot choose between labels alone. */
	echo '<ul class="description" style="margin-top:.5em">';
	foreach ( $choices as $choice ) {
		if ( '' === ( $choice['note'] ?? '' ) ) {
			continue;
		}
		printf(
			'<li><strong>%1$s</strong> — %2$s%3$s</li>',
			esc_html( $choice['label'] ),
			esc_html( $choice['note'] ),
			'' === ( $choice['terms'] ?? '' ) ? '' : sprintf(
				' <span class="lfndr-terms">%s</span>',
				esc_html( $choice['terms'] )
			)
		);
	}
	echo '</ul>';
}

/**
 * Which preset the stored values currently amount to, if any.
 *
 * Derived by comparison rather than stored. Storing the chosen key would be
 * less work and would immediately start lying: edit one color afterwards and
 * the site still claims to be that preset. Comparing means the answer is always
 * true — change anything and it becomes Custom by itself, because it genuinely
 * is custom now.
 *
 * A match is exact in both directions: every value the preset sets matches, and
 * every field it does not set is empty. That second half matters because
 * applying a preset clears the fields it leaves alone, so a site carrying a
 * stray value from an earlier set is not on the later one however similar it
 * looks.
 *
 * @return string Preset key, or '' when the values match none of them.
 */
function lfndr_current_preset(): string {
	$fields = array_keys( lfndr_appearance_fields() );

	foreach ( lfndr_style_presets() as $key => $preset ) {
		$matches = true;
		foreach ( $fields as $field ) {
			$expected = (string) ( $preset['values'][ $field ] ?? '' );
			if ( (string) lfndr_setting( $field ) !== $expected ) {
				$matches = false;
				break;
			}
		}
		if ( $matches ) {
			return $key;
		}
	}

	return '';
}

/**
 * A wireframe of what a preset looks like.
 *
 * Built from the preset's own stored values rather than drawn by hand or
 * screenshotted, so it cannot show something the preset would not produce —
 * change a color in the registry and every thumbnail follows. It is the same
 * argument that runs through this whole file: a second description
 * of the same thing is a second thing to keep true.
 *
 * Deliberately not a picture of the finder. It is six rectangles standing for
 * canvas, header, search field, two cards and a selected chip — enough to tell
 * the sets apart at a glance, which is the only job a thumbnail has.
 *
 * Inline styles because each one is unique to its preset and every value came
 * out of lfndr_sanitize_css_color() before it was stored.
 *
 * @param array $values The preset's field values.
 * @return string
 */
function lfndr_preset_thumbnail( array $values ): string {
	$canvas  = $values['finder_bg'] ?? '';
	$canvas  = '' !== $canvas ? $canvas : '#ffffff';
	$surface = $values['surface_color'] ?? '#ffffff';
	$card    = $values['card_bg'] ?? $surface;
	$ink     = $values['card_text'] ?? '#111111';
	$line    = $values['line_color'] ?? '#dddddd';
	$control = $values['control_bg'] ?? $surface;
	$active  = $values['control_active_bg'] ?? $ink;
	$radius  = $values['radius'] ?? '6px';

	$bar = sprintf(
		'<span style="display:block;height:9px;border-radius:99px;background:%1$s;border:1px solid %2$s"></span>',
		esc_attr( $control ),
		esc_attr( $line )
	);

	$pill = sprintf(
		'<span style="display:inline-block;width:26px;height:8px;border-radius:99px;background:%1$s"></span>',
		esc_attr( $active )
	);

	/* Two cards, each a block with a title rule and a shorter body rule. */
	$rows = '';
	foreach ( array( 26, 18 ) as $title_width ) {
		$rows .= sprintf(
			'<span style="display:block;padding:4px;border-radius:%1$s;background:%2$s;border:1px solid %3$s">
				<span style="display:block;width:%4$dpx;height:4px;border-radius:2px;background:%5$s"></span>
				<span style="display:block;width:14px;height:3px;margin-top:3px;border-radius:2px;background:%5$s;opacity:.45"></span>
			</span>',
			esc_attr( $radius ),
			esc_attr( $card ),
			esc_attr( $line ),
			$title_width,
			esc_attr( $ink )
		);
	}

	return sprintf(
		'<span class="lfndr-preset__thumb" style="background:%1$s;border-radius:%2$s" aria-hidden="true">
			%3$s
			<span style="display:flex;gap:3px;align-items:center">%4$s</span>
			%5$s
		</span>',
		esc_attr( $canvas ),
		esc_attr( $radius ),
		$bar,
		$pill . $pill,
		$rows
	);
}

/**
 * The preset picker.
 *
 * Deliberately an action rather than a stored value. Choosing a set and saving
 * writes its values into the fields below, and then it is done — the site is
 * not "on" a preset afterwards, it simply has those values, and every one of
 * them stays editable. That avoids the whole category of question a stored
 * preset creates: what happens when you edit one field, is it still that
 * preset, does upgrading the plugin change your colors.
 *
 * It is also why the select resets to "no change" on every load: it is a verb.
 */
function lfndr_render_preset_picker(): void {
	$presets = lfndr_style_presets();
	$current = lfndr_current_preset();
	?>
	<h2><?php esc_html_e( 'Appearance', 'location-finder' ); ?></h2>
	<p class="description" style="max-width:44em">
		<?php esc_html_e( 'Pick a set and save. Every one clears WCAG AA for text contrast, and every value it writes stays editable underneath.', 'location-finder' ); ?>
	</p>

	<fieldset class="lfndr-presets">
		<legend class="screen-reader-text"><?php esc_html_e( 'Appearance preset', 'location-finder' ); ?></legend>

		<?php foreach ( $presets as $key => $preset ) : ?>
			<label class="lfndr-preset">
				<input type="radio" name="<?php echo esc_attr( LFNDR_SETTINGS_OPTION ); ?>[_apply_preset]"
					value="<?php echo esc_attr( $key ); ?>" <?php checked( $current, $key ); ?> />
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from sanitized colors; see lfndr_preset_thumbnail().
				echo lfndr_preset_thumbnail( $preset['values'] );
				?>
				<span class="lfndr-preset__name"><?php echo esc_html( $preset['label'] ); ?></span>
				<span class="lfndr-preset__note"><?php echo esc_html( $preset['note'] ); ?></span>
			</label>
		<?php endforeach; ?>

		<?php /* Last, because it is where you end up rather than something you set out to choose. */ ?>
		<label class="lfndr-preset">
			<input type="radio" name="<?php echo esc_attr( LFNDR_SETTINGS_OPTION ); ?>[_apply_preset]"
				value="" <?php checked( $current, '' ); ?> />
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from sanitized colors; see lfndr_preset_thumbnail().
			echo lfndr_preset_thumbnail( lfndr_current_values() );
			?>
			<span class="lfndr-preset__name"><?php esc_html_e( 'Custom', 'location-finder' ); ?></span>
			<span class="lfndr-preset__note">
				<?php
				echo '' === $current
					? esc_html__( 'Your own values, set below. Selected because they match no preset.', 'location-finder' )
					: esc_html__( 'Edit any value below and it becomes Custom.', 'location-finder' );
				?>
			</span>
		</label>
	</fieldset>
	<?php
}

/**
 * The stored appearance values, for the Custom thumbnail.
 *
 * @return array<string, string>
 */
function lfndr_current_values(): array {
	$out = array();
	foreach ( array_keys( lfndr_appearance_fields() ) as $field ) {
		$value = (string) lfndr_setting( $field );
		if ( '' !== $value ) {
			$out[ $field ] = $value;
		}
	}
	return $out;
}

/**
 * Render the Settings screen.
 */
function lfndr_settings_screen(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to change these settings.', 'location-finder' ) );
	}
	?>
	<div class="wrap lfndr-admin">
		<h1><?php esc_html_e( 'Location Finder Settings', 'location-finder' ); ?></h1>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'lfndr_settings_group' );
			lfndr_render_preset_picker();

			/* The individual fields are the exception, not the route in: almost
			 * everyone wants a set, and twenty-five text boxes above the Save
			 * button makes choosing one look like the hard way round. Buffered
			 * so they can go inside a disclosure — open already when the values
			 * are custom, because then they are the answer to "what is this
			 * site actually set to". */
			ob_start();
			do_settings_sections( LFNDR_SETTINGS_PAGE );
			$sections = ob_get_clean();
			$custom   = '' === lfndr_current_preset();
			?>
			<details class="lfndr-fine-tune"<?php echo $custom ? ' open' : ''; ?>>
				<summary><?php esc_html_e( 'Fine-tune individual values', 'location-finder' ); ?></summary>
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Settings API output, escaped at source.
				echo $sections;
				?>
			</details>
			<?php
			submit_button();
			?>
		</form>
	</div>
	<?php
}

/**
 * Sanitize the settings option.
 *
 * Merges onto whatever is already stored, not onto lfndr_setting_defaults(),
 * and only overwrites a key this form actually submitted — the same
 * only-write-what-was-submitted rule the location save handler uses, and for
 * the same reason. This screen currently owns only the appearance fields; a
 * later version that adds a Map or Geocoding section to the same option must
 * not have its fresh code defaults permanently overwritten by whatever was
 * baked into the database the first time someone saved Appearance, before
 * that section existed to be touched.
 *
 * @param mixed $raw Raw POSTed value.
 * @return array
 */
function lfndr_sanitize_settings( $raw ): array {
	$raw    = is_array( $raw ) ? $raw : array();
	$stored = get_option( LFNDR_SETTINGS_OPTION );
	$out    = is_array( $stored ) ? $stored : array();

	/* ── Preset, or edited values? ────────────────────────────────────────────
	 * Both arrive in the same POST and the form gives no way to tell them apart.
	 * Every appearance box is submitted on every save, holding whatever it was
	 * rendered with, so "the user typed #111827" and "the box already contained
	 * #111827" are byte-identical. An earlier version tried to let typed values
	 * win over the chosen set; because the boxes always carry the OLD values,
	 * that meant they always won, the preset was overwritten the instant it was
	 * applied, and picking one appeared to do nothing at all.
	 *
	 * What does distinguish the two is whether the RADIO moved. Comparing the
	 * submitted key against what the stored values currently amount to gives the
	 * intent directly:
	 *
	 *   chose a different preset  → they want that set; the boxes are stale, ignore them
	 *   same preset, edited a box → they are tuning it; the edit wins and it becomes Custom
	 *
	 * lfndr_current_preset() has to be read here, before anything is written,
	 * because it derives from the stored values this save is about to replace.
	 *
	 * Every appearance field is cleared before applying rather than merged over.
	 * Two of the sets leave the finder background blank on purpose — it is the
	 * right answer for a light finder on a light page — and merging would leave
	 * a previous set's dark canvas underneath them. Reset then apply is the only
	 * version where the result depends on the preset alone. */
	$preset_key = isset( $raw['_apply_preset'] ) ? sanitize_key( (string) $raw['_apply_preset'] ) : '';
	$presets    = lfndr_style_presets();

	$applying_preset = isset( $presets[ $preset_key ] ) && $preset_key !== lfndr_current_preset();

	if ( $applying_preset ) {
		foreach ( array_keys( lfndr_appearance_fields() ) as $field_key ) {
			$out[ $field_key ] = '';
		}
		foreach ( $presets[ $preset_key ]['values'] as $field_key => $value ) {
			$out[ $field_key ] = $value;
		}
	}

	/* Never stored: it is a verb, not a setting. */
	unset( $out['_apply_preset'] );

	/* Everything that is not about appearance — see inc/admin-screen.php. */
	$out = lfndr_sanitize_option_fields( $raw, $out );

	/* The per-tab markers are transport, not settings. */
	foreach ( array_keys( lfndr_admin_tabs() ) as $tab ) {
		unset( $out[ '_tab_' . $tab ] );
	}

	foreach ( lfndr_appearance_fields() as $key => $field ) {
		/* A preset was just applied, so every box in this submission holds a
		 * value from the set being replaced. Reading them back would undo the
		 * apply field by field. */
		if ( $applying_preset ) {
			break;
		}
		if ( ! array_key_exists( $key, $raw ) ) {
			continue;
		}
		$value = is_scalar( $raw[ $key ] ) ? (string) $raw[ $key ] : '';

		if ( 'choice' === $field['type'] ) {
			/* A key from a fixed list, never free text — which is what makes it
			 * safe to look the URL and the attribution up from that list later
			 * rather than storing either. */
			$choices     = call_user_func( $field['choices'] );
			$out[ $key ] = isset( $choices[ $value ] ) ? $value : '';
			continue;
		}

		$out[ $key ] = 'length' === $field['type']
			? lfndr_sanitize_css_length( $value )
			: lfndr_sanitize_css_color( $value );
	}

	return $out;
}

/**
 * Sanitize a CSS custom-property value meant to hold a color.
 *
 * Deliberately permissive on syntax: a hex code, a named color, a system
 * color keyword (Canvas, CanvasText), and a reference to a theme's own
 * variable (var(--wp--preset--color--accent-1)) are all legitimate — the last
 * of those is the one this plugin's own README recommends. What it refuses is
 * anything that could act on the page rather than merely describe a color:
 * angle brackets and semicolons are never part of a real value here, so the
 * allow-list below excludes them outright, and url()/expression() are blocked
 * by name since a color property never legitimately needs either.
 *
 * @param string $raw Raw value.
 * @return string
 */
function lfndr_sanitize_css_color( string $raw ): string {
	$value = trim( $raw );
	if ( '' === $value || mb_strlen( $value ) > 200 ) {
		return '';
	}
	if ( ! preg_match( '/^[a-zA-Z0-9#%.,()\-_ \/]+$/', $value ) ) {
		return '';
	}
	if ( preg_match( '/url\s*\(|expression\s*\(|@/i', $value ) ) {
		return '';
	}
	return $value;
}

/**
 * Sanitize a CSS custom-property value meant to hold a length.
 *
 * A plain number and a real CSS length unit, nothing else. Radius, spacing and
 * the map/panel heights all draw from this, and none of them has a legitimate
 * reason to be anything more expressive than that — in particular, none of
 * them is a color, so the broader allow-list above is not reused here.
 *
 * @param string $raw Raw value.
 * @return string
 */
function lfndr_sanitize_css_length( string $raw ): string {
	$value = trim( $raw );
	if ( '' === $value ) {
		return '';
	}
	return preg_match( '/^-?[0-9]*\.?[0-9]+(px|em|rem|%|vh|vw|ch)$/', $value ) ? $value : '';
}

/**
 * Build the front-end CSS for whichever appearance settings are actually set.
 *
 * Empty when nothing has been — which is every site that never opens this
 * screen — so the defaults already shipped in location-finder.css are the only
 * thing that ever renders for them.
 *
 * Two very different outputs come out of the same loop. 'var' fields collect
 * into one shared .lfndr{--x:value} block, exactly as before. 'rule' fields
 * group by selector — control_bg and control_text share one, for instance —
 * so that setting only the background does not print an empty `color:;`
 * alongside it, and each becomes its own selector{...} block with !important
 * on every declaration (see the file header for why only these need it).
 *
 * @return string
 */
function lfndr_appearance_css(): string {
	$declarations = array();
	$rules        = array();

	foreach ( lfndr_appearance_fields() as $key => $field ) {
		$value = (string) lfndr_setting( $key );

		/* Re-validated here, not only on save: this is printed into a <style>
		 * tag on every front-end page a finder appears on, and a value could
		 * have reached the option without passing through
		 * lfndr_sanitize_settings() at all — a direct update_option() call from
		 * WP-CLI, a migration, another plugin. */
		$value = 'length' === $field['type']
			? lfndr_sanitize_css_length( $value )
			: lfndr_sanitize_css_color( $value );

		if ( '' === $value ) {
			continue;
		}

		/* Not every field on this screen describes CSS. A choice is a stored
		 * preference the front end reads directly, so it never reaches the
		 * stylesheet. */
		if ( 'choice' === ( $field['mode'] ?? 'var' ) ) {
			continue;
		}

		if ( 'rule' === ( $field['mode'] ?? 'var' ) ) {
			$rules[ $field['selector'] ][ $field['property'] ] = $value;
			continue;
		}

		$declarations[] = $field['css_var'] . ':' . $value;
	}

	$css = $declarations ? '.lfndr{' . implode( ';', $declarations ) . '}' : '';

	foreach ( $rules as $selector => $properties ) {
		$body = array();
		foreach ( $properties as $property => $value ) {
			/* !important, and only here. The custom-property block above never
			 * gets it: a theme that deliberately sets --lfndr-accent for
			 * itself, per the README's own example, is meant to win. These
			 * selectors are different — the plugin never declares
			 * background-color or color on a native button at all, so there
			 * is no existing plugin rule for a theme to out-specify; the only
			 * competition is the theme's OWN generic button styling, of
			 * unpredictable specificity, and this is an explicit admin
			 * override of it, not the plugin imposing a look. */
			$body[] = $property . ':' . $value . ' !important';
		}
		$css .= $selector . '{' . implode( ';', $body ) . '}';
	}

	return $css;
}
