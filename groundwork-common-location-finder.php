<?php
/**
 * Plugin Name:       Groundwork Common Location Finder
 * Plugin URI:        https://github.com/Groundwork-Common/groundwork-common-location-finder
 * Description:       A map-and-list location finder whose fields you define yourself. Only the name and coordinates are built in; everything else is configured in wp-admin.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Groundwork Common LLC
 * Author URI:        https://www.groundworkcommon.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       groundwork-common-location-finder
 * Domain Path:       /languages
 *
 * @package GroundworkCommonLocationFinder
 */

defined( 'ABSPATH' ) || exit;

/* ── One copy only ───────────────────────────────────────────────────────────
 * Two directories can hold this plugin at once — an old slug sitting beside the
 * new one after a rename, or a manual install beside the directory one. Both are
 * activatable, and WordPress identifies plugins by path, so it sees two. The
 * second one to load then redeclares every function the first declared, which is
 * a fatal on every request, wp-admin included, with no way back in through the
 * browser. Activating through the admin screen would be caught by
 * plugin_sandbox_scrape(); `wp plugin activate` is not.
 *
 * So: whoever gets here first wins, and the second copy returns having done
 * nothing. This check has to sit above the constants, not below them. The
 * constants are the first thing that redeclares, and while PHP currently only
 * warns about that, the warning says "this will be an error in PHP 9" — which
 * would put the fatal back, ahead of anything placed further down.
 * ─────────────────────────────────────────────────────────────────────────── */
if ( defined( 'GWC_LFNDR_VERSION' ) ) {
	return;
}

/* ── Why only three fields are built in ──────────────────────────────────────
 * name, latitude, longitude. Nothing else.
 *
 * This plugin grew out of one built for a diaper bank, where the data model was
 * the domain: services offered was a taxonomy of "diapers" and "period
 * supplies", access was an enum of open/appointment/referral, and the state
 * defaulted to 'AL' in six separate files. All of that is correct for one site
 * and worthless for the next one.
 *
 * So the only fields with hardcoded meaning are the ones the plugin itself has
 * to reason about: the title (what you search and sort by) and the coordinates
 * (what the map and the distance math need). Everything else — address, hours,
 * phone, whatever this particular site calls its categories — is defined by an
 * admin on the Fields screen and flows through generically: rendered from a
 * registry, sanitized by a registry, shipped to the browser with a schema
 * describing itself.
 *
 * The cost is that nothing can be assumed. There is no `$location['city']`
 * anywhere in this codebase. The benefit is that a library, a food pantry and a
 * dealer network can all install it without a fork.
 * ─────────────────────────────────────────────────────────────────────────── */

const GWC_LFNDR_VERSION        = '1.0.0';
const GWC_LFNDR_SCHEMA_VERSION = 2;

/*
 * Where "Support this work" points. Every reference is guarded, so setting this
 * to '' removes the link and the paragraph asking for one together — a support
 * ask with nowhere to go is worse than none.
 */
const GWC_LFNDR_SPONSOR_URL = 'https://www.groundworkcommon.com/support/';

/* The company site. Named once because the colophon links it from three
 * places — the wordmark, the company name in the opening line, and the
 * "See what we do" link — and two of those agreeing while the third drifts
 * is the kind of thing nobody notices for a year. */
const GWC_LFNDR_GWC_URL = 'https://www.groundworkcommon.com/';

define( 'GWC_LFNDR_FILE', __FILE__ );
define( 'GWC_LFNDR_DIR', plugin_dir_path( __FILE__ ) );
define( 'GWC_LFNDR_URL', plugin_dir_url( __FILE__ ) );

/* ── The requires ────────────────────────────────────────────────────────────
 * The order is not alphabetical and must not be sorted: schema.php precedes
 * everything that reads a schema, and field-types.php precedes meta-box.php.
 *
 * These used to carry one `if ( ! function_exists( … ) )` guard apiece against
 * a double-load. The single `defined()` check at the top of this file does that
 * job instead, and better: it stops before the constants rather than after them,
 * and it does not couple seventeen guard conditions to seventeen function names
 * — a coupling where getting one name wrong skipped a whole file silently, and
 * where the guard below covered three files at once.
 *
 * Nothing here is wrapped in is_admin(). It is tempting — the Fields screen and
 * the geocode proxy are admin-only features — but is_admin() answers "is this a
 * wp-admin request", and WP-CLI, cron and the REST API are none of those. Every
 * one of these files only *registers* hooks that fire in admin contexts anyway,
 * so the saving is a few microseconds of include, and the cost is a class of bug
 * where a function exists on the screen you tested and is fatally undefined
 * under `wp eval`. That trade is not close.
 * ─────────────────────────────────────────────────────────────────────────── */
require GWC_LFNDR_DIR . 'inc/i18n.php';
require GWC_LFNDR_DIR . 'inc/settings.php';
require GWC_LFNDR_DIR . 'inc/field-types.php';
require GWC_LFNDR_DIR . 'inc/field-address.php';
require GWC_LFNDR_DIR . 'inc/field-hours.php';
require GWC_LFNDR_DIR . 'inc/field-closures.php';
require GWC_LFNDR_DIR . 'inc/schema.php';
require GWC_LFNDR_DIR . 'inc/cpt.php';
require GWC_LFNDR_DIR . 'inc/meta-box.php';
require GWC_LFNDR_DIR . 'inc/locations.php';
require GWC_LFNDR_DIR . 'inc/facets.php';
require GWC_LFNDR_DIR . 'inc/render.php';
require GWC_LFNDR_DIR . 'inc/admin-settings.php';
require GWC_LFNDR_DIR . 'inc/enqueue.php';
require GWC_LFNDR_DIR . 'inc/block.php';
require GWC_LFNDR_DIR . 'inc/geocode.php';
require GWC_LFNDR_DIR . 'inc/admin-fields.php';

// The tab shell and the settings that were not reachable before it.
require GWC_LFNDR_DIR . 'inc/admin-screen.php';

/* Contextual help for the settings screen. Loaded after admin-screen.php
 * because it is that screen's help, and after admin-fields.php because it
 * describes what those screens do. */
require GWC_LFNDR_DIR . 'inc/admin-help.php';

/* ── Activation ──────────────────────────────────────────────────────────────
 * Deliberately not flush_rewrite_rules(). On the activating request the post
 * type has not been registered yet — `init` already fired — so a flush here
 * writes rules that do not include ours, and the 404s that follow look like a
 * permalink bug for as long as it takes someone to re-save Settings. Instead we
 * leave a flag and consume it on the next `init`, after registration.
 *
 * The option is explicitly non-autoloaded: it is read once, ever.
 * ─────────────────────────────────────────────────────────────────────────── */
register_activation_hook(
	__FILE__,
	static function (): void {
		update_option( 'gwc_lfndr_needs_rewrite_flush', 1, false );
	}
);

add_action(
	'init',
	static function (): void {
		if ( get_option( 'gwc_lfndr_needs_rewrite_flush' ) ) {
			delete_option( 'gwc_lfndr_needs_rewrite_flush' );
			flush_rewrite_rules( false );
		}
	},
	99
);

/* Deactivation drops the cache and the rewrite rules, and nothing else. It does
 * not touch the schema or any location — deactivating is not uninstalling, and
 * a plugin that eats data when you toggle it off is a plugin nobody toggles. */
register_deactivation_hook(
	__FILE__,
	static function (): void {
		delete_transient( 'gwc_lfndr_locations' );
		flush_rewrite_rules( false );
	}
);
