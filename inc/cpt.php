<?php
/**
 * The location post type and its admin list table.
 *
 * @package LocationFinder
 */

defined( 'ABSPATH' ) || exit;

const LFNDR_POST_TYPE = 'lfndr_location';

/* ── Why this post type is not public ────────────────────────────────────────
 * public => false, has_archive => false, rewrite => false, show_in_rest =>
 * false. A location has no single view and no permalink.
 *
 * That is a deliberate limitation, not an oversight. A location is a row in a
 * finder, and the finder is the page people arrive on. Giving every location a
 * URL would mean every install ships hundreds of thin pages that compete with
 * the finder in search results, that inherit whatever the theme does with an
 * unknown post type, and that nobody has designed. Sites that genuinely want
 * per-location pages want them to look like something specific, which is a
 * theme's job — and `lfndr_post_type_args` is there for them.
 *
 * show_in_rest => false follows from the same reasoning plus one more: with a
 * dynamic schema there is no fixed REST shape to expose. When that changes it
 * will be a purpose-built read-only route, not the auto-generated one.
 * ─────────────────────────────────────────────────────────────────────────── */

add_action( 'init', 'lfndr_register_post_type', 10 );

/**
 * Register the location post type.
 */
function lfndr_register_post_type(): void {
	$labels = array(
		'name'               => _x( 'Locations', 'post type general name', 'groundwork-common-location-finder' ),
		'singular_name'      => _x( 'Location', 'post type singular name', 'groundwork-common-location-finder' ),
		'menu_name'          => _x( 'Locations', 'admin menu', 'groundwork-common-location-finder' ),
		'add_new'            => __( 'Add New', 'groundwork-common-location-finder' ),
		'add_new_item'       => __( 'Add New Location', 'groundwork-common-location-finder' ),
		'edit_item'          => __( 'Edit Location', 'groundwork-common-location-finder' ),
		'new_item'           => __( 'New Location', 'groundwork-common-location-finder' ),
		'view_item'          => __( 'View Location', 'groundwork-common-location-finder' ),
		'search_items'       => __( 'Search Locations', 'groundwork-common-location-finder' ),
		'not_found'          => __( 'No locations found.', 'groundwork-common-location-finder' ),
		'not_found_in_trash' => __( 'No locations found in Trash.', 'groundwork-common-location-finder' ),
		'all_items'          => __( 'All Locations', 'groundwork-common-location-finder' ),
	);

	$args = array(
		'labels'          => $labels,
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => true,
		'menu_position'   => 25,
		'menu_icon'       => 'dashicons-location-alt',
		'supports'        => array( 'title' ),
		'capability_type' => 'post',
		'map_meta_cap'    => true,
		'has_archive'     => false,
		'rewrite'         => false,
		'query_var'       => false,
		'show_in_rest'    => false,
	);

	/**
	 * Filter the location post type registration arguments.
	 *
	 * The intended use is a theme that wants per-location pages: set `public`,
	 * `rewrite` and `has_archive`, then provide the templates. Everything else
	 * in the plugin keeps working, because nothing reads a location by URL.
	 *
	 * @param array $args Arguments for register_post_type().
	 */
	register_post_type( LFNDR_POST_TYPE, apply_filters( 'lfndr_post_type_args', $args ) );
}

/* ── Admin list table ───────────────────────────────────────────────────── */

add_filter( 'manage_' . LFNDR_POST_TYPE . '_posts_columns', 'lfndr_admin_columns' );
add_action( 'manage_' . LFNDR_POST_TYPE . '_posts_custom_column', 'lfndr_admin_column_content', 10, 2 );

/**
 * Build the list table columns from the schema.
 *
 * The columns are whatever the admin flagged `show_card`, capped at four. That
 * cap is not arbitrary: the list table has no horizontal scroll worth using,
 * and the columns an admin chose for a visitor-facing card are reliably the
 * ones that identify a location at a glance. A site that wants different
 * columns has `lfndr_admin_columns` — but nobody should have to configure this
 * twice to get something sensible.
 *
 * @param array $columns Core columns.
 * @return array
 */
function lfndr_admin_columns( array $columns ): array {
	$date = $columns['date'] ?? null;
	unset( $columns['date'] );

	$shown = 0;
	foreach ( lfndr_get_schema()['fields'] as $field ) {
		if ( empty( $field['show_card'] ) || $shown >= 4 ) {
			continue;
		}
		$columns[ 'lfndr_' . $field['key'] ] = $field['label'];
		++$shown;
	}

	$columns['lfndr_coords'] = __( 'Coordinates', 'groundwork-common-location-finder' );

	if ( null !== $date ) {
		$columns['date'] = $date;
	}
	return $columns;
}

/**
 * Print one list table cell.
 *
 * @param string $column  Column key.
 * @param int    $post_id Post ID.
 */
function lfndr_admin_column_content( string $column, int $post_id ): void {
	if ( 'lfndr_coords' === $column ) {
		$lat = get_post_meta( $post_id, '_lfndr_lat', true );
		$lng = get_post_meta( $post_id, '_lfndr_lng', true );
		if ( '' === $lat || '' === $lng ) {
			/* Called out rather than left blank: a location without coordinates
			 * is invisible on the map and sorts last under "near me", which is
			 * the single most confusing way for this plugin to look broken. */
			printf(
				'<span class="lfndr-warn" aria-label="%s">%s</span>',
				esc_attr__( 'This location has no coordinates and will not appear on the map.', 'groundwork-common-location-finder' ),
				esc_html__( 'Not on the map', 'groundwork-common-location-finder' )
			);
			return;
		}
		printf( '<code>%s, %s</code>', esc_html( $lat ), esc_html( $lng ) );
		return;
	}

	if ( 0 !== strpos( $column, 'lfndr_' ) ) {
		return;
	}

	$field = lfndr_get_field( substr( $column, 6 ) );
	if ( null === $field ) {
		return;
	}

	echo esc_html( lfndr_field_plain_text( $post_id, $field ) );
}

/**
 * A one-line plain-text rendering of a field's value, for admin contexts.
 *
 * Type-aware but deliberately dumb — it exists for list tables and debugging,
 * not for the front end, which renders through the JS renderer table.
 *
 * @param int   $post_id Post ID.
 * @param array $field   Field definition.
 * @return string
 */
function lfndr_field_plain_text( int $post_id, array $field ): string {
	$value = get_post_meta( $post_id, lfndr_field_meta_key( $field['key'] ), true );

	if ( '' === $value || array() === $value ) {
		return '—';
	}

	if ( 'boolean' === $field['type'] ) {
		return __( 'Yes', 'groundwork-common-location-finder' );
	}

	if ( in_array( $field['type'], array( 'select', 'multiselect' ), true ) ) {
		$labels = wp_list_pluck( $field['options'], 'label', 'value' );
		$values = is_array( $value ) ? $value : array( $value );
		$out    = array();
		foreach ( $values as $one ) {
			$out[] = $labels[ $one ] ?? $one;
		}
		return implode( ', ', $out );
	}

	if ( is_array( $value ) ) {
		/* Composites (address, hours, closures) get their own summarisers when
		 * those types land; until then, say how much there is rather than
		 * printing "Array". */
		return sprintf(
			/* translators: %d: number of entries. */
			_n( '%d entry', '%d entries', count( $value ), 'groundwork-common-location-finder' ),
			count( $value )
		);
	}

	return (string) $value;
}
