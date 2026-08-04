<?php
/**
 * Dependencies for edit.js.
 *
 * Normally @wordpress/scripts generates this at build time. This plugin has no
 * build step, so it is written by hand — which is the documented escape hatch,
 * and works exactly the same: register_block_type_from_metadata() reads it when
 * it resolves the `editorScript` file: reference.
 *
 * Keep it in sync with the globals edit.js destructures. Adding a wp.* import
 * without adding it here is the one way this file goes wrong, and the symptom
 * is a block that fails to register with a console error about an undefined
 * property.
 *
 * @package LocationFinder
 */

return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
	'version'      => LFNDR_VERSION,
);
