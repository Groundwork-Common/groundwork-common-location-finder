<?php
/**
 * Test bootstrap: enough of WordPress to run the plugin's pure logic.
 *
 * @package LocationFinder
 */

/* ── Why stubs and not the WordPress test suite ──────────────────────────────
 * The logic worth testing here is pure: schema sanitization, the hours
 * formatter's day/frequency collapse, closure date validation, facet
 * availability. None of it touches the database, and none of it depends on
 * WordPress behaving like WordPress — it depends on about a dozen string
 * helpers whose semantics fit on one screen.
 *
 * So we stub those, and the suite runs in under a second with no database, no
 * WP checkout, and no `wp-env start`. That matters more than fidelity: a test
 * suite that needs a running stack is a test suite that gets run before a
 * release, and one that needs nothing is a test suite that gets run before a
 * commit.
 *
 * Integration behavior — meta actually round-tripping, query counts, the admin
 * screens — is verified under wp-env instead. Different layer, different tool.
 * ─────────────────────────────────────────────────────────────────────────── */

define( 'ABSPATH', __DIR__ . '/' );
define( 'LFNDR_VERSION', 'test' );
define( 'LFNDR_SCHEMA_VERSION', 1 );
define( 'LFNDR_FILE', dirname( __DIR__ ) . '/groundwork-common-location-finder.php' );
define( 'LFNDR_DIR', dirname( __DIR__ ) . '/' );
define( 'LFNDR_URL', 'https://example.test/wp-content/plugins/location-finder/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

/** In-memory option store, reset between tests by lfndr_test_reset(). */
$GLOBALS['lfndr_test_options'] = array();

/**
 * Reset all test state.
 */
function lfndr_test_reset(): void {
	$GLOBALS['lfndr_test_options'] = array();
	if ( function_exists( 'lfndr_schema_cache' ) ) {
		lfndr_schema_cache( null, true );
	}
	if ( function_exists( 'lfndr_settings_cache' ) ) {
		lfndr_settings_cache( null, true );
	}
}

// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Universal.Files.SeparateFunctionsFromOO

function sanitize_text_field( $str ) {
	$str = (string) $str;
	$str = wp_strip_all_tags( $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
	return trim( (string) $str );
}

function sanitize_textarea_field( $str ) {
	$str = wp_strip_all_tags( (string) $str );
	return trim( (string) preg_replace( "/[ \t]+/", ' ', $str ) );
}

function wp_strip_all_tags( $str ) {
	$str = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $str );
	return trim( (string) wp_strip_tags( (string) $str ) );
}

function wp_strip_tags( $str ) {
	return strip_tags( (string) $str );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_title( $title ) {
	$title = strtolower( (string) $title );
	$title = preg_replace( '/[^a-z0-9_\-\s]/', '', $title );
	$title = preg_replace( '/[\s_]+/', '-', (string) $title );
	return trim( (string) preg_replace( '/-+/', '-', (string) $title ), '-' );
}

function sanitize_email( $email ) {
	return (string) filter_var( trim( (string) $email ), FILTER_SANITIZE_EMAIL );
}

function is_email( $email ) {
	return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
}

function esc_url_raw( $url, $protocols = null ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	$scheme = strtolower( (string) wp_parse_url_scheme( $url ) );
	if ( is_array( $protocols ) && ! in_array( $scheme, $protocols, true ) ) {
		return '';
	}
	return $url;
}

function wp_parse_url_scheme( $url ) {
	$parts = wp_parse_url( $url );
	return $parts['scheme'] ?? '';
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
}

function absint( $value ) {
	return abs( (int) $value );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return esc_attr( $url );
}

function esc_attr__( $text ) {
	return $text;
}

function esc_html__( $text ) {
	return $text;
}

function __( $text ) {
	return $text;
}

function _x( $text ) {
	return $text;
}

function _n( $single, $plural, $number ) {
	return 1 === (int) $number ? $single : $plural;
}

function checked( $checked, $current = true, $echo = true ) {
	$out = (string) $checked === (string) $current ? ' checked="checked"' : '';
	if ( $echo ) {
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	return $out;
}

function selected( $selected, $current = true, $echo = true ) {
	$out = (string) $selected === (string) $current ? ' selected="selected"' : '';
	if ( $echo ) {
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	return $out;
}

function wp_list_pluck( $list, $field, $index_key = null ) {
	$out = array();
	foreach ( (array) $list as $item ) {
		$item = (array) $item;
		if ( null === $index_key ) {
			$out[] = $item[ $field ] ?? null;
		} else {
			$out[ $item[ $index_key ] ] = $item[ $field ] ?? null;
		}
	}
	return $out;
}

/* A real filter registry, small but honest. The field types register through
 * lfndr_field_types, so a no-op add_filter() would leave the composites out of
 * the registry the tests see — which is exactly the difference between testing
 * the plugin and testing a subset of it that happens to be easy. */
$GLOBALS['lfndr_test_filters'] = array();

function add_filter( $hook, $callback, $priority = 10 ) {
	$GLOBALS['lfndr_test_filters'][ $hook ][] = array( $priority, $callback );
	usort(
		$GLOBALS['lfndr_test_filters'][ $hook ],
		static fn( array $a, array $b ): int => $a[0] <=> $b[0]
	);
	return true;
}

function apply_filters( $hook, $value ) {
	$extra = array_slice( func_get_args(), 2 );
	foreach ( $GLOBALS['lfndr_test_filters'][ $hook ] ?? array() as $entry ) {
		$value = call_user_func_array( $entry[1], array_merge( array( $value ), $extra ) );
	}
	return $value;
}

function do_action() {}

/**
 * A deliberately thin stand-in for wp_kses().
 *
 * strip_tags() with an allow-list is not what WordPress does — the real one
 * also filters attributes, which is most of its value. It is enough to prove
 * the one thing the caller cares about here: that a tile attribution keeps its
 * link and loses anything that can execute. Anything relying on attribute
 * filtering needs an integration test, not this.
 *
 * @param string $string  Input.
 * @param array  $allowed Allowed tags, keyed by tag name.
 * @return string
 */
function wp_kses( $string, $allowed ) {
	$tags = '';
	foreach ( array_keys( (array) $allowed ) as $tag ) {
		$tags .= '<' . $tag . '>';
	}
	return strip_tags( (string) $string, $tags );
}

function add_action() {}

function get_option( $name, $default_value = false ) {
	return $GLOBALS['lfndr_test_options'][ $name ] ?? $default_value;
}

function update_option( $name, $value ) {
	$GLOBALS['lfndr_test_options'][ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['lfndr_test_options'][ $name ] );
	return true;
}

function wp_timezone() {
	return new DateTimeZone( 'UTC' );
}

// phpcs:enable

require_once LFNDR_DIR . 'inc/i18n.php';
require_once LFNDR_DIR . 'inc/field-types.php';
require_once LFNDR_DIR . 'inc/schema.php';

/* Hours and closures need a few more stubs. */
// phpcs:disable Squiz.Commenting.FunctionComment.Missing
function esc_html_x( $text ) {
	return $text;
}
function esc_attr_x( $text ) {
	return $text;
}
function wp_date( $format, $timestamp = null, $timezone = null ) {
	$date = new DateTimeImmutable( '@' . (int) $timestamp );
	return $date->setTimezone( $timezone instanceof DateTimeZone ? $timezone : new DateTimeZone( 'UTC' ) )->format( $format );
}
function get_locale() {
	return 'en_US';
}
function home_url() {
	return 'https://example.test';
}
function sanitize_title_with_dashes( $t ) {
	return sanitize_title( $t );
}
// phpcs:enable

/* Loaded after the filter registry exists, so their add_filter() calls at file
 * scope land the same way they do under WordPress. */
require_once LFNDR_DIR . 'inc/settings.php';
require_once LFNDR_DIR . 'inc/field-address.php';
require_once LFNDR_DIR . 'inc/field-hours.php';
require_once LFNDR_DIR . 'inc/field-closures.php';

/* facets.php reads the post type constant that cpt.php would normally define;
 * cpt.php itself is all hook registration and has nothing to unit test. */
if ( ! defined( 'LFNDR_POST_TYPE' ) ) {
	define( 'LFNDR_POST_TYPE', 'lfndr_location' );
}

require_once LFNDR_DIR . 'inc/facets.php';
require_once LFNDR_DIR . 'inc/admin-settings.php';
require_once LFNDR_DIR . 'inc/admin-fields.php';
require_once LFNDR_DIR . 'inc/admin-screen.php';

/*
 * The front-end shell, for the wrapper-markup tests.
 *
 * Loaded last and with two of its collaborators stubbed rather than required.
 * lfndr_get_locations() reaches for WP_Query and the transient API, and
 * lfndr_enqueue_finder() for the whole script registry — neither has anything
 * to do with the markup under test, and pulling them in would trade a real test
 * for a large pile of stubs that exist only to be walked past. An empty result
 * set still renders the wrapper, which is the part being asserted.
 */
// phpcs:disable Squiz.Commenting.FunctionComment.Missing
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $pairs as $name => $default_value ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default_value;
	}
	return $out;
}
function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals );
}
function esc_attr_e( $text ) {
	echo esc_attr( $text );
}
function esc_html_e( $text ) {
	echo esc_html( $text );
}
function wp_add_inline_script() {
	return true;
}
function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, (int) $options, (int) $depth );
}
function lfndr_get_locations() {
	return array();
}
function lfndr_enqueue_finder() {}
// phpcs:enable

require_once LFNDR_DIR . 'inc/render.php';
