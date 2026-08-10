<?php
/**
 * Contextual help, in WordPress's own Help panel.
 *
 * ── Why here and not a fifth tab ─────────────────────────────────────────────
 * A Help tab beside Fields, Behavior, Appearance and Advanced would read as a
 * fifth thing to configure, and it would be the only one that configures
 * nothing. The Help button at the top right is where WordPress has kept this
 * since 3.3, it costs no space, and it can differ per tab — which matters,
 * because the useful thing to say about the Fields screen has nothing to do
 * with the useful thing to say about Appearance.
 *
 * The honest cost: hardly anyone notices that button. So this is deliberately
 * not where anything essential lives. Everything a person must know to use the
 * plugin is on the screen itself, in labels and descriptions. What goes here is
 * the second layer — the things that are true, non-obvious, and only wanted
 * once somebody has hit them.
 *
 * ── What earns a place ───────────────────────────────────────────────────────
 * Each tab answers the same two questions: what does this screen decide, and
 * what does everybody get wrong about it? Nothing here restates a label. If the
 * only way to learn something is to lose data or file a bug, it belongs here;
 * if it is already legible from the form, it does not.
 *
 * @package GroundworkCommonLocationFinder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wrap paragraphs of already-escaped text.
 *
 * WordPress prints help content as HTML, so the escaping has to happen before
 * it arrives. Callers pass text through esc_html__() and this only supplies the
 * tags, which keeps every string in this file plainly a string and leaves no
 * place for markup to sneak in from a translation.
 *
 * @param string ...$paragraphs Escaped paragraph text.
 * @return string
 */
function gwc_lfndr_help_paragraphs( string ...$paragraphs ): string {
	$out = '';
	foreach ( $paragraphs as $paragraph ) {
		$out .= '<p>' . $paragraph . '</p>';
	}
	return $out;
}

/**
 * The help tabs for one settings tab.
 *
 * Keyed by the tab slug so an unknown tab simply gets the overview, rather than
 * this having to know every tab that will ever exist.
 *
 * @param string $tab Current tab slug.
 * @return array<int, array{title: string, content: string}>
 */
function gwc_lfndr_help_tabs_for( string $tab ): array {
	$overview = array(
		'title'   => __( 'Overview', 'groundwork-common-location-finder' ),
		'content' => gwc_lfndr_help_paragraphs(
			esc_html__( 'Three things about a location are built in: its name, and its latitude and longitude. Everything else — an address, opening hours, services offered, a phone number — is a field you define here, and the same definition drives the editor, the search, the filters and what visitors see.', 'groundwork-common-location-finder' ),
			esc_html__( 'That is the trade this plugin makes. Nothing is assumed about what a location is, so nothing has to be worked around when your locations are not shops.', 'groundwork-common-location-finder' )
		),
	);

	$tabs = array(
		'fields'     => array(
			array(
				'title'   => __( 'Fields', 'groundwork-common-location-finder' ),
				'content' => gwc_lfndr_help_paragraphs(
					esc_html__( 'A field’s key is permanent; its label is not. Rename “Phone” to “Contact number” whenever you like — the key underneath never moves, which is why existing locations keep their data.', 'groundwork-common-location-finder' ),
					esc_html__( 'Changing a key is therefore not offered. It would be a delete followed by a create, and every location’s value for that field would be stranded. If you need a different key, add the new field, move the values across, then retire the old one.', 'groundwork-common-location-finder' )
				),
			),
			array(
				'title'   => __( 'Deleting a field', 'groundwork-common-location-finder' ),
				'content' => gwc_lfndr_help_paragraphs(
					esc_html__( 'Deleting a field does not delete anything a location holds. The definition moves to Retired and the values stay exactly where they are, so restoring it brings everything back.', 'groundwork-common-location-finder' ),
					esc_html__( 'Erasing the data is a separate action, listed with a count of how many locations it would affect, and it asks you to type a confirmation. It is the only thing on this screen that cannot be undone.', 'groundwork-common-location-finder' )
				),
			),
			array(
				'title'   => __( 'Where a field appears', 'groundwork-common-location-finder' ),
				'content' => gwc_lfndr_help_paragraphs(
					esc_html__( 'The result card and the detail pane are separate choices, and the two order lists below let you arrange each independently. A phone number belongs in the detail pane and clutters a card; a city belongs on a card and is redundant beside the full address in detail.', 'groundwork-common-location-finder' ),
					esc_html__( 'Moving a field below the divider in either list is the same as unchecking it for that view. It stays defined, and it stays searchable if you marked it so.', 'groundwork-common-location-finder' ),
					esc_html__( 'Searching runs in the visitor’s browser, which means anything marked searchable is present in the page source even when it is shown nowhere. That is fine for an internal reference code and wrong for a private note.', 'groundwork-common-location-finder' )
				),
			),
		),
		'behavior'   => array(
			array(
				'title'   => __( 'Which field drives what', 'groundwork-common-location-finder' ),
				'content' => gwc_lfndr_help_paragraphs(
					esc_html__( 'You can define more than one address, schedule or closure list — a mailing address and a visiting one, pantry hours and office hours. All of them display. These settings pick which single one the plugin acts on.', 'groundwork-common-location-finder' ),
					esc_html__( 'Only the chosen address places the map pin and feeds the Directions link. Only the chosen schedule decides “Open now”. Only the chosen closure list suspends those hours. The others are shown and searched like any other field, and drive nothing.', 'groundwork-common-location-finder' )
				),
			),
			array(
				'title'   => __( 'Finding what is nearest', 'groundwork-common-location-finder' ),
				'content' => gwc_lfndr_help_paragraphs(
					esc_html__( 'When this is on, the map carries a locate button. Pressing it asks the browser for the visitor’s position, sorts the results by distance and shows how far away each one is. The coordinates are used in the browser and never sent anywhere.', 'groundwork-common-location-finder' ),
					esc_html__( 'It is a sort, not a filter: the nearest location is always shown, even if it is forty miles away. And it lives on the map, so it has no effect where the finder is set to show the list only.', 'groundwork-common-location-finder' )
				),
			),
			array(
				'title'   => __( 'Results shown', 'groundwork-common-location-finder' ),
				'content' => gwc_lfndr_help_paragraphs(
					esc_html__( 'The results limit caps how many cards are drawn at once, not how many locations reach the page. Every location is always available to search and filter, which is why a search can turn up something that was not in the initial list.', 'groundwork-common-location-finder' )
				),
			),
		),
		'appearance' => array(
			array(
				'title'   => __( 'Presets', 'groundwork-common-location-finder' ),
				'content' => gwc_lfndr_help_paragraphs(
					esc_html__( 'A preset is a bundle of the values below and nothing more. Choosing one writes them in, and they stay editable afterwards — so a preset is a starting point, not a mode you are locked into.', 'groundwork-common-location-finder' ),
					esc_html__( 'Which preset is highlighted is worked out by comparing the current values, not remembered. Change any one of them and the selection becomes Custom by itself, because that is what it now is.', 'groundwork-common-location-finder' )
				),
			),
			array(
				'title'   => __( 'Leaving a value blank', 'groundwork-common-location-finder' ),
				'content' => gwc_lfndr_help_paragraphs(
					esc_html__( 'Blank is not broken. A field left empty reproduces the plugin’s own default, so a site that never opens this tab looks exactly as it did before the tab existed.', 'groundwork-common-location-finder' ),
					esc_html__( 'Colours accept anything CSS does, including currentColor and a custom property from your theme — which is often a better answer than a hex value, because it follows the theme when the theme changes.', 'groundwork-common-location-finder' )
				),
			),
			array(
				'title'   => __( 'When your theme wins', 'groundwork-common-location-finder' ),
				'content' => gwc_lfndr_help_paragraphs(
					esc_html__( 'Most settings here set a custom property and do not force anything, so a theme that deliberately styles the finder still wins. That is intentional: the finder is meant to belong to your site rather than to this plugin.', 'groundwork-common-location-finder' ),
					esc_html__( 'The button, chip and card colours are the exception. Those are native elements the plugin otherwise leaves alone, so there is nothing for a value to attach to unless it overrides the theme outright — which is what those settings do, and why they are worth setting only if the defaults genuinely clash.', 'groundwork-common-location-finder' )
				),
			),
		),
		'advanced'   => array(
			array(
				'title'   => __( 'Map tiles', 'groundwork-common-location-finder' ),
				'content' => gwc_lfndr_help_paragraphs(
					esc_html__( 'Tiles are fetched by the visitor’s browser directly from whichever provider you choose, so that provider sees the visitor’s IP address. Nothing on your server can change that once a map is on screen, which is why the finder asks before loading one.', 'groundwork-common-location-finder' ),
					esc_html__( 'CARTO’s free basemaps are for non-commercial use. Commercial sites should use OpenStreetMap, or Custom with a provider they have an account with. Attribution is a licence condition rather than decoration and must not be removed.', 'groundwork-common-location-finder' ),
					esc_html__( 'Serving tiles from your own site removes the third party altogether. Point the Custom tile URL at them and the consent prompt disappears on its own, because there is no longer anyone to warn about.', 'groundwork-common-location-finder' )
				),
			),
			array(
				'title'   => __( 'Address lookup', 'groundwork-common-location-finder' ),
				'content' => gwc_lfndr_help_paragraphs(
					esc_html__( 'The lookup runs only in the editor, only for signed-in users who can edit locations, and only when somebody types into the address search. Visitors never trigger it.', 'groundwork-common-location-finder' ),
					esc_html__( 'The contact address is sent so the lookup service knows which site is asking; leave it blank and your admin email is used. Requests are limited to one a second per user. Without a contact address the service can refuse them, and the editor stops filling in coordinates.', 'groundwork-common-location-finder' )
				),
			),
		),
	);

	$found = $tabs[ $tab ] ?? array();

	return array_merge( array( $overview ), $found );
}

/**
 * Attach the help tabs to the settings screen.
 *
 * Registered against load-{$hook} so it runs before the screen renders, which
 * is the only point at which WP_Screen will accept them.
 */
function gwc_lfndr_add_help_tabs(): void {
	$screen = get_current_screen();
	if ( ! $screen ) {
		return;
	}

	foreach ( gwc_lfndr_help_tabs_for( gwc_lfndr_current_tab() ) as $i => $tab ) {
		$screen->add_help_tab(
			array(
				'id'      => 'lfndr-help-' . $i,
				'title'   => $tab['title'],
				'content' => $tab['content'],
			)
		);
	}

	$screen->set_help_sidebar(
		'<p><strong>' . esc_html__( 'More', 'groundwork-common-location-finder' ) . '</strong></p>'
		. '<p><a href="' . esc_url( GWC_LFNDR_GWC_URL ) . '" target="_blank" rel="noopener noreferrer">'
		. esc_html__( 'Groundwork Common', 'groundwork-common-location-finder' ) . '</a></p>'
		. '<p><a href="https://github.com/Groundwork-Common/groundwork-common-location-finder/issues" target="_blank" rel="noopener noreferrer">'
		. esc_html__( 'Report a problem', 'groundwork-common-location-finder' ) . '</a></p>'
	);
}
