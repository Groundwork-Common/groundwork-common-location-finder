<?php
/**
 * Block render callback.
 *
 * @package LocationFinder
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

/* get_block_wrapper_attributes() is what makes the block's color, spacing and
 * typography controls actually do something: WordPress writes the chosen preset
 * classes onto this wrapper, and because the finder's own styles are built on
 * currentColor, the palette the site owner picked in the editor is simply
 * inherited. It is a better answer to "which theme color should this use" than
 * any guess the plugin could make. */
printf(
	'<div %s>%s</div>',
	get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core escapes this.
	gwc_lfndr_render_finder( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaping happens per value inside.
		array(
			'show_map' => empty( $attributes['showMap'] ) ? 'no' : 'yes',
			'height'   => (string) ( $attributes['height'] ?? 0 ),
			'label'    => (string) ( $attributes['label'] ?? '' ),
		)
	)
);
