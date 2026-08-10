<?php
/**
 * Uninstall cleanup.
 *
 * ── What this never does ─────────────────────────────────────────────────────
 * It never deletes location posts, and it never deletes their field data, even
 * when the destructive option below is armed. Those are the site's records — a
 * list of food banks, clinics, drop-off points — typed in by hand and in many
 * cases not written down anywhere else. A plugin that disposes of them because
 * somebody clicked Delete on a plugin screen has destroyed work it did not
 * create. Removing the plugin removes the plugin.
 *
 * The consequence is deliberate: reinstalling restores everything. The posts
 * are still there, their meta is still attached, and the schema (if it was
 * kept) still describes how to read it.
 *
 * ── What it does ─────────────────────────────────────────────────────────────
 * Always: the payload transient, which is a cache and worthless once the code
 * that built it is gone.
 *
 * Only when armed: the two options holding the field schema and the settings.
 * Arming is a separate option rather than a setting inside gwc_lfndr_settings,
 * precisely so a future migration of that array can never resurrect it — a
 * merge with defaults is exactly the kind of thing that would flip a buried
 * boolean back on, and the blast radius here is somebody's entire field
 * configuration.
 *
 *     update_option( 'gwc_lfndr_allow_destructive_uninstall', true );
 *
 * Even armed, the field VALUES on each location survive. Only the description
 * of them goes. That asymmetry is on purpose: a schema can be rebuilt from the
 * Fields screen in an afternoon; two hundred locations' opening hours cannot.
 *
 * @package GroundworkCommonLocationFinder
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site.
 *
 * The transient goes unconditionally — it is derived data with a one-hour life
 * and nothing left can read it. The options go only when armed, and the arming
 * flag is read per site, because on a network one site's decision to keep its
 * field schema is not another's to overrule.
 */
function gwc_lfndr_uninstall_site() {
	delete_transient( 'gwc_lfndr_locations' );

	if ( ! get_option( 'gwc_lfndr_allow_destructive_uninstall' ) ) {
		return;
	}

	delete_option( 'gwc_lfndr_schema' );
	delete_option( 'gwc_lfndr_settings' );
	delete_option( 'gwc_lfndr_allow_destructive_uninstall' );
}

/* Multisite: options and transients are per site, so cleaning only the current
 * one leaves every other site in the network holding rows nothing can read.
 * Bounded by a batch — a network with thousands of sites should not have its
 * uninstall time out halfway through, leaving the job part-done with no record
 * of where it stopped. The cap is generous enough that no realistic network
 * reaches it, and the failure mode if one does is leftover rows, not damage. */
if ( is_multisite() ) {
	$gwc_lfndr_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 1000,
		)
	);

	foreach ( $gwc_lfndr_sites as $gwc_lfndr_site_id ) {
		switch_to_blog( $gwc_lfndr_site_id );
		gwc_lfndr_uninstall_site();
		restore_current_blog();
	}
} else {
	gwc_lfndr_uninstall_site();
}
