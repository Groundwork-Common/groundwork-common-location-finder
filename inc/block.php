<?php
/**
 * The block, and the shortcode that will always exist alongside it.
 *
 * @package LocationFinder
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'gwc_lfndr_register_block' );
add_shortcode( 'location_finder', 'gwc_lfndr_shortcode' );

/**
 * Register the finder block.
 */
function gwc_lfndr_register_block(): void {
	if ( function_exists( 'register_block_type_from_metadata' ) ) {
		register_block_type_from_metadata( GWC_LFNDR_DIR . 'blocks/finder' );
	}
}

/**
 * The shortcode.
 *
 * Kept permanently, not as a deprecation path. Page builders, widget areas,
 * classic-editor sites and every "insert this in a template" instruction on the
 * internet need a shortcode, and none of them are going away because the block
 * editor exists.
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function gwc_lfndr_shortcode( $atts ): string {
	return gwc_lfndr_render_finder( is_array( $atts ) ? $atts : array() );
}
