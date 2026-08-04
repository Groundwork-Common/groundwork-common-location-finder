<?php
/**
 * Building the location payload the browser receives.
 *
 * @package LocationFinder
 */

defined( 'ABSPATH' ) || exit;

const LFNDR_LOCATIONS_TRANSIENT = 'lfndr_locations';

/**
 * Every published location, shaped for the front end.
 *
 * @return array
 */
function lfndr_get_locations(): array {
	$cached = get_transient( LFNDR_LOCATIONS_TRANSIENT );
	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	$posts = get_posts(
		array(
			'post_type'        => LFNDR_POST_TYPE,
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		)
	);

	if ( ! $posts ) {
		/* Cache the empty answer too. A site mid-setup would otherwise run the
		 * full query on every page view of the finder, which is the one moment
		 * somebody is reloading it repeatedly. */
		set_transient( LFNDR_LOCATIONS_TRANSIENT, array(), lfndr_cache_ttl() );
		return array();
	}

	/* One query for every meta row on every post. Without it each field on each
	 * location is its own SELECT, so the query count grows with locations ×
	 * fields — invisible with three test locations and fatal with two hundred.
	 * This is the single line standing between this function and an N+1. */
	update_postmeta_cache( wp_list_pluck( $posts, 'ID' ) );

	$schema = lfndr_get_schema();
	$types  = lfndr_field_types();
	$out    = array();

	foreach ( $posts as $post ) {
		$out[] = lfndr_build_location( $post, $schema, $types );
	}

	set_transient( LFNDR_LOCATIONS_TRANSIENT, $out, lfndr_cache_ttl() );

	return $out;
}

/**
 * How long the payload stays cached.
 *
 * @return int
 */
function lfndr_cache_ttl(): int {
	/**
	 * Filter the location payload cache lifetime, in seconds.
	 *
	 * @param int $ttl Seconds.
	 */
	return (int) apply_filters( 'lfndr_cache_ttl', HOUR_IN_SECONDS );
}

/**
 * Shape one location.
 *
 * @param WP_Post $post   Location post.
 * @param array   $schema Schema.
 * @param array   $types  Type registry.
 * @return array
 */
function lfndr_build_location( WP_Post $post, array $schema, array $types ): array {
	$fields = array();
	$facets = array();
	$search = array( $post->post_title );

	foreach ( $schema['fields'] as $field ) {
		$type = $types[ $field['type'] ] ?? null;
		if ( null === $type ) {
			continue;
		}

		$value = get_post_meta( $post->ID, lfndr_field_meta_key( $field['key'] ), true );
		if ( call_user_func( $type['is_empty'], $value, $field ) ) {
			continue;
		}

		/* Only fields somebody can actually see or use are shipped. A field
		 * that is neither displayed, searchable nor filterable is internal
		 * bookkeeping, and putting it in a payload every visitor downloads is
		 * both wasteful and a small disclosure nobody asked for. */
		$visible = ! empty( $field['show_card'] ) || ! empty( $field['show_detail'] );

		if ( $visible && ! empty( $type['to_payload'] ) ) {
			$fields[ $field['key'] ] = call_user_func( $type['to_payload'], $value, $field, $post->ID );
		}

		if ( ! empty( $field['searchable'] ) && ! empty( $type['search_text'] ) ) {
			$search[] = call_user_func( $type['search_text'], $value, $field );
		}

		if ( ! empty( $field['filterable'] ) && ! empty( $type['facet_tokens'] ) ) {
			$tokens = (array) call_user_func( $type['facet_tokens'], $value, $field );
			if ( $tokens ) {
				$facets[ $field['key'] ] = array_values( $tokens );
			}
		}
	}

	$lat = get_post_meta( $post->ID, '_lfndr_lat', true );
	$lng = get_post_meta( $post->ID, '_lfndr_lng', true );

	return array(
		'id'   => (int) $post->ID,
		'name' => $post->post_title,
		/* Null rather than 0 when a location has never been geocoded. 0,0 is a
		 * real place in the Gulf of Guinea, and a location that quietly sorts
		 * as "4,000 miles away" is much harder to notice than one the map
		 * simply does not draw. */
		'lat'  => '' !== $lat ? (float) $lat : null,
		'lng'  => '' !== $lng ? (float) $lng : null,

		/* Namespaced under `f` so a field keyed `name`, `id` or `lat` cannot
		 * shadow a built-in. With admin-defined keys that is not hypothetical —
		 * `name` is the first thing somebody types. */
		'f'    => $fields,

		/* One lowercased blob, precomputed. The alternative is walking the
		 * schema for every location on every keystroke; this makes searching a
		 * single indexOf per location and costs about twenty lines here. */
		'search' => lfndr_search_blob( $search ),
		'facets' => $facets,
	);
}

/**
 * Fold a location's searchable text into one normalized blob.
 *
 * @param array $parts Text fragments.
 * @return string
 */
function lfndr_search_blob( array $parts ): string {
	$text = implode( ' ', array_filter( array_map( 'strval', $parts ) ) );
	$text = wp_strip_all_tags( $text );
	$text = preg_replace( '/\s+/u', ' ', mb_strtolower( $text ) );

	return trim( (string) $text );
}

/* ── Invalidation ───────────────────────────────────────────────────────── */

add_action( 'save_post_' . LFNDR_POST_TYPE, 'lfndr_flush_locations', 20 );
add_action( 'deleted_post', 'lfndr_flush_locations_for_post', 20, 2 );
add_action( 'lfndr_schema_saved', 'lfndr_flush_locations' );

/* Both hooks, not just update_option_*. WordPress fires add_option_{$name} the
 * first time an option is written and update_option_{$name} every time after —
 * so hooking only the latter means the very first save of the settings, on a
 * site that has never opened the settings screen, silently leaves an hour of
 * stale payload behind. That is the save most likely to be followed by someone
 * reloading the front end to see whether it worked. */
add_action( 'update_option_' . LFNDR_SETTINGS_OPTION, 'lfndr_flush_locations' );
add_action( 'add_option_' . LFNDR_SETTINGS_OPTION, 'lfndr_flush_locations' );

/**
 * Drop the cached payload.
 */
function lfndr_flush_locations(): void {
	delete_transient( LFNDR_LOCATIONS_TRANSIENT );
}

/**
 * Drop the cached payload when a location is deleted.
 *
 * `deleted_post` fires for every post type, so this checks before flushing —
 * otherwise every deleted revision, attachment and draft on the site would
 * throw away a cache that has nothing to do with it.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 */
function lfndr_flush_locations_for_post( int $post_id, $post = null ): void {
	if ( $post instanceof WP_Post && LFNDR_POST_TYPE === $post->post_type ) {
		lfndr_flush_locations();
	}
}
