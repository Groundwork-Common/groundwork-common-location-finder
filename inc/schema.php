<?php
/**
 * The field schema: storage, sanitization, versioning, and retirement.
 *
 * @package LocationFinder
 */

defined( 'ABSPATH' ) || exit;

/* ── The shape, and why it is three lists and not one ────────────────────────
 * The schema is one autoloaded option:
 *
 *   [ 'version' => 1,
 *     'fields'       => [ …ordered field definitions… ],
 *     'detail_order' => [ '__name', 'address', 'hours', … ],
 *     'card_order'   => [ '__name', 'address', '__distance' ],
 *     'retired'      => [ …definitions whose post meta still exists… ] ]
 *
 * Three orders, deliberately. `fields` is the order of the meta box — you want
 * the address near the top when you are typing one in. `detail_order` and
 * `card_order` are what a visitor sees, and they are different from each other
 * and from the admin form: a phone number belongs in the detail pane and
 * clutters a card; a city belongs on a card and is redundant beside the full
 * address in detail.
 *
 * The rejected alternative was an integer 'order' on each definition. Moving one
 * field then rewrites every other field's row, drag-to-position needs a full
 * renumber, and there is no way to say "in detail but not on the card" without
 * inventing a sentinel value. A flat list of keys is atomic, trivially
 * reorderable, and — the property that actually matters — self-healing: a key
 * that no longer exists is ignored at render time, and a field missing from an
 * order list is appended at the end. That is what makes editing the schema safe
 * rather than a migration.
 *
 * Keys beginning with __ are synthetic: __name, __distance, __directions,
 * __coords. They sit in the order lists beside real field keys so the reorder UI
 * is one list rather than two with different rules. lfndr_sanitize_field_key()
 * forbids a leading underscore on a real key, so they cannot collide.
 * ─────────────────────────────────────────────────────────────────────────── */

const LFNDR_SCHEMA_OPTION = 'lfndr_schema';

/** Synthetic order entries that are rendered by the plugin, not by a field. */
const LFNDR_SYNTHETIC_KEYS = array( '__name', '__coords', '__distance', '__directions' );

/**
 * The schema a fresh install starts with: empty, but structurally valid.
 *
 * No sample fields. A finder that ships with "Diapers" and "Period supplies"
 * pre-filled is the exact mistake this plugin exists to undo, and an admin who
 * has to delete three fields before adding their own reads that as a bug.
 *
 * @return array
 */
function lfndr_default_schema(): array {
	return array(
		'version'      => LFNDR_SCHEMA_VERSION,
		'fields'       => array(),
		'primary'      => lfndr_empty_primary_roles(),
		'detail_order' => array( '__name', '__directions' ),
		'card_order'   => array( '__name', '__distance' ),
		'retired'      => array(),
	);
}

/**
 * The per-request schema memo.
 *
 * The cache lives in its own function rather than as a static inside
 * lfndr_get_schema() because PHP gives no way to reach another function's
 * static, and a writer that cannot invalidate the reader's cache is a stale-read
 * bug waiting for the first caller that saves and then reads in one request —
 * WP-CLI, an importer, a test. Admin screens hide it behind a redirect, which is
 * exactly what makes it the kind of bug that ships.
 *
 * @param array|null $set   Value to store.
 * @param bool       $clear Forget the cached value.
 * @return array|null
 */
function lfndr_schema_cache( ?array $set = null, bool $clear = false ): ?array {
	static $cache = null;
	if ( $clear ) {
		$cache = null;
		return null;
	}
	if ( null !== $set ) {
		$cache = $set;
	}
	return $cache;
}

/**
 * Read the schema, running any pending migrations exactly once.
 *
 * @return array
 */
function lfndr_get_schema(): array {
	$cached = lfndr_schema_cache();
	if ( null !== $cached ) {
		return $cached;
	}

	$stored = get_option( LFNDR_SCHEMA_OPTION );
	if ( ! is_array( $stored ) ) {
		return (array) lfndr_schema_cache( lfndr_default_schema() );
	}

	$stored = array_merge( lfndr_default_schema(), $stored );
	$from   = isset( $stored['version'] ) ? (int) $stored['version'] : 1;

	/* A schema written by a NEWER version of the plugin is left exactly as it
	 * is. Running our older migrations over it, or "normalizing" it against a
	 * schema we do not understand, is how a downgrade turns into data loss. The
	 * Fields screen shows a banner instead. */
	if ( $from > LFNDR_SCHEMA_VERSION ) {
		return (array) lfndr_schema_cache( $stored );
	}

	if ( $from < LFNDR_SCHEMA_VERSION ) {
		foreach ( lfndr_schema_migrations() as $target => $callback ) {
			if ( $target > $from && $target <= LFNDR_SCHEMA_VERSION && is_callable( $callback ) ) {
				$stored = call_user_func( $callback, $stored );
			}
		}
		$stored['version'] = LFNDR_SCHEMA_VERSION;
		update_option( LFNDR_SCHEMA_OPTION, $stored );
	}

	return (array) lfndr_schema_cache( $stored );
}

/**
 * Migrations, keyed by the schema version they produce.
 *
 * Each callback takes the whole schema array and returns it. Two rules, both
 * load-bearing: a migration must be idempotent (it can run against data a
 * previous partial run already touched), and a migration may never delete a
 * field — retire it instead, so the post meta survives and an admin can see
 * what happened.
 *
 * @return array<int, callable-string>
 */
function lfndr_schema_migrations(): array {
	return apply_filters(
		'lfndr_schema_migrations',
		array(
			2 => 'lfndr_migrate_schema_2',
		)
	);
}

/**
 * v2: lift the per-field `primary` flags and `suspends` into one wiring block.
 *
 * Before this, each address/hours/closures field carried its own `primary`
 * checkbox and a closures field carried `suspends`, naming the hours it
 * overrode. Two flags for one relationship, spread over three screens, and a
 * checkbox cannot express "exactly one" — ticking it on a second field of the
 * same type silently lost, because the reconciler resolved ties by document
 * order and had no way to know which field the admin had just acted on.
 *
 * Idempotent, as every migration here must be: a schema that already has a
 * populated wiring block keeps it, and the flags it reads are simply absent the
 * second time through.
 *
 * `suspends` is not carried across. In the new shape the closures that hold the
 * role suspend the hours that hold the role, which is the only pairing that was
 * ever coherent — a closure pointing at some other hours field produced a
 * struck-through schedule with no closed state to go with it. Where `suspends`
 * named an hours field and nothing else claimed the role, it is taken as the
 * intended answer, since it is better evidence of intent than field order.
 *
 * @param array $schema Stored schema.
 * @return array
 */
function lfndr_migrate_schema_2( array $schema ): array {
	$fields  = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : array();
	$primary = is_array( $schema['primary'] ?? null ) ? $schema['primary'] : array();

	foreach ( lfndr_primary_roles() as $type ) {
		if ( ! empty( $primary[ $type ] ) ) {
			continue; // Already migrated.
		}

		$flagged = '';
		$first   = '';
		foreach ( $fields as $field ) {
			if ( ( $field['type'] ?? '' ) !== $type ) {
				continue;
			}
			$first = '' !== $first ? $first : (string) ( $field['key'] ?? '' );
			if ( '' === $flagged && ! empty( $field['settings']['primary'] ) ) {
				$flagged = (string) ( $field['key'] ?? '' );
			}
		}

		$primary[ $type ] = '' !== $flagged ? $flagged : $first;
	}

	/* An explicit `suspends` outranks "first hours field we found", but never a
	 * field the admin actually flagged. */
	if ( '' === ( $primary['hours'] ?? '' ) || ! lfndr_role_was_flagged( $fields, 'hours' ) ) {
		foreach ( $fields as $field ) {
			if ( 'closures' === ( $field['type'] ?? '' ) && ! empty( $field['settings']['suspends'] ) ) {
				$primary['hours'] = (string) $field['settings']['suspends'];
				break;
			}
		}
	}

	$schema['primary'] = $primary;
	return $schema;
}

/**
 * Did any field of this type carry the old `primary` flag?
 *
 * @param array  $fields Raw stored fields.
 * @param string $type   Field type.
 * @return bool
 */
function lfndr_role_was_flagged( array $fields, string $type ): bool {
	foreach ( $fields as $field ) {
		if ( ( $field['type'] ?? '' ) === $type && ! empty( $field['settings']['primary'] ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Persist a schema. The only sanctioned writer.
 *
 * @param array $schema Already sanitized.
 * @return bool
 */
function lfndr_save_schema( array $schema ): bool {
	$schema['version'] = LFNDR_SCHEMA_VERSION;
	$saved             = update_option( LFNDR_SCHEMA_OPTION, $schema );

	/* Update the memo rather than clearing it: every caller that saves is about
	 * to read, and re-reading the option would only re-run the merge we just
	 * did. Subscribers to lfndr_schema_saved therefore see the new schema, which
	 * is what the payload cache invalidation depends on. */
	lfndr_schema_cache( $schema );

	do_action( 'lfndr_schema_saved', $schema );

	return $saved;
}

/* ── Lookup helpers ─────────────────────────────────────────────────────── */

/**
 * The post meta key a field's value is stored under.
 *
 * Prefixed with _lfndr_f_ so that (a) it is hidden from the Custom Fields box,
 * and (b) an uninstall can find every field value with one
 * delete_post_meta_by_key() pattern without knowing the schema.
 *
 * @param string $key Field key.
 * @return string
 */
function lfndr_field_meta_key( string $key ): string {
	return '_lfndr_f_' . $key;
}

/**
 * Find one field definition by key.
 *
 * @param string     $key    Field key.
 * @param array|null $schema Optional schema; read if omitted.
 * @return array|null
 */
function lfndr_get_field( string $key, ?array $schema = null ): ?array {
	$schema = $schema ?? lfndr_get_schema();
	foreach ( $schema['fields'] as $field ) {
		if ( $field['key'] === $key ) {
			return $field;
		}
	}
	return null;
}

/**
 * The composite types that carry a site-wide role, and nothing else does.
 *
 * Address, hours and closures may each exist more than once — "Mailing address"
 * and "Visiting address" are a real thing, as are "Pantry hours" and "Office
 * hours". Four behaviors have to know which instance they mean: the admin
 * geocoder's fill target, the Directions link, the Open-now badge, and the
 * closure banner that suspends it.
 *
 * @return array<int, string>
 */
function lfndr_primary_roles(): array {
	return array( 'address', 'hours', 'closures' );
}

/**
 * The wiring block with every role unassigned.
 *
 * @return array<string, string>
 */
function lfndr_empty_primary_roles(): array {
	return array_fill_keys( lfndr_primary_roles(), '' );
}

/**
 * Sanitize the wiring block: each role names a field of its own type, or ''.
 *
 * A name that does not resolve is cleared rather than kept, for the same reason
 * lfndr_resolve_field_references() clears a dangling setting — a role pointing
 * at a retired field would drive behavior off a field the Fields screen no
 * longer lists, which is a worse thing to debug than an unassigned role.
 *
 * @param mixed $raw    Stored wiring block.
 * @param array $fields Sanitized fields.
 * @return array<string, string>
 */
function lfndr_sanitize_primary_roles( $raw, array $fields ): array {
	$by_type = array();
	foreach ( $fields as $field ) {
		$by_type[ $field['type'] ][] = $field['key'];
	}

	$out = lfndr_empty_primary_roles();
	if ( ! is_array( $raw ) ) {
		return $out;
	}

	foreach ( array_keys( $out ) as $type ) {
		$key = lfndr_sanitize_field_key( (string) ( $raw[ $type ] ?? '' ) );
		if ( '' !== $key && in_array( $key, $by_type[ $type ] ?? array(), true ) ) {
			$out[ $type ] = $key;
		}
	}

	return $out;
}

/**
 * The primary instance of a composite type, if one is defined.
 *
 * The role is named once, on the schema, rather than by a flag on each field.
 * A flag per field cannot express "exactly one" — it can only be checked twice
 * and then reconciled, which is a state the admin can reach and the UI has to
 * apologize for. A single key per role makes the invariant structural.
 *
 * @param string     $type   Field type.
 * @param array|null $schema Optional schema.
 * @return array|null
 */
function lfndr_primary_field( string $type, ?array $schema = null ): ?array {
	$schema = $schema ?? lfndr_get_schema();

	$assigned = $schema['primary'][ $type ] ?? '';
	if ( '' !== $assigned ) {
		$field = lfndr_get_field( $assigned, $schema );
		if ( null !== $field && $field['type'] === $type ) {
			return $field;
		}
	}

	/* Unassigned — fall back to the first of that type rather than to nothing.
	 * An admin who defines one address and never opens the wiring panel still
	 * expects Directions to work. */
	foreach ( $schema['fields'] as $field ) {
		if ( $field['type'] === $type ) {
			return $field;
		}
	}
	return null;
}

/**
 * Resolve an order list into renderable entries.
 *
 * Self-healing, and this is the whole point of storing orders as flat key
 * lists. Unknown keys (a retired field, a typo, a field from a schema import
 * that did not come with it) are dropped. Fields that exist and are flagged for
 * this surface but are absent from the list are appended, so adding a field on
 * the Fields screen makes it appear immediately rather than invisibly.
 *
 * @param string $surface 'detail' or 'card'.
 * @param array  $schema  Schema.
 * @return array<int, array{key: string, field: array|null}>
 */
function lfndr_resolve_order( string $surface, array $schema ): array {
	$flag  = 'card' === $surface ? 'show_card' : 'show_detail';
	$order = 'card' === $surface ? $schema['card_order'] : $schema['detail_order'];

	$by_key = array();
	foreach ( $schema['fields'] as $field ) {
		$by_key[ $field['key'] ] = $field;
	}

	$out  = array();
	$seen = array();
	foreach ( $order as $key ) {
		if ( in_array( $key, LFNDR_SYNTHETIC_KEYS, true ) ) {
			$out[]        = array(
				'key'   => $key,
				'field' => null,
			);
			$seen[ $key ] = true;
			continue;
		}
		if ( isset( $by_key[ $key ] ) && ! empty( $by_key[ $key ][ $flag ] ) ) {
			$out[]        = array(
				'key'   => $key,
				'field' => $by_key[ $key ],
			);
			$seen[ $key ] = true;
		}
	}

	foreach ( $schema['fields'] as $field ) {
		if ( ! empty( $field[ $flag ] ) && ! isset( $seen[ $field['key'] ] ) ) {
			$out[] = array(
				'key'   => $field['key'],
				'field' => $field,
			);
		}
	}

	return $out;
}

/* ── Sanitization ───────────────────────────────────────────────────────── */

/**
 * Sanitize a field key, or return '' if it cannot be one.
 *
 * Lowercase, starts with a letter, letters/digits/underscore, 40 chars. The
 * leading-letter rule is what keeps a real key from ever colliding with a
 * synthetic __ entry in an order list.
 *
 * @param string $raw Raw key.
 * @return string
 */
function lfndr_sanitize_field_key( string $raw ): string {
	$key = strtolower( trim( $raw ) );
	$key = preg_replace( '/[^a-z0-9_]/', '_', $key );
	$key = preg_replace( '/_+/', '_', (string) $key );
	$key = trim( (string) $key, '_' );
	if ( '' === $key || ! preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $key ) ) {
		return '';
	}
	return $key;
}

/**
 * The canonical shape of a field definition. Every field is merged onto this,
 * so no consumer ever has to test for a missing key.
 *
 * @return array
 */
function lfndr_field_defaults(): array {
	return array(
		'key'           => '',
		'type'          => 'text',
		'label'         => '',
		'help'          => '',
		'section'       => '',
		'placeholder'   => '',
		'required'      => false,
		'show_card'     => false,
		'show_detail'   => true,
		'searchable'    => false,
		'filterable'    => false,
		'filter_widget' => '',
		'filter_label'  => '',
		'icon'          => '',
		'options'       => array(),
		'settings'      => array(),
	);
}

/**
 * Sanitize a whole schema, from any source: the Fields screen, a JSON import,
 * or a filter. Never trusts its input.
 *
 * @param mixed $raw Candidate schema.
 * @return array
 */
function lfndr_sanitize_schema( $raw ): array {
	$out = lfndr_default_schema();
	if ( ! is_array( $raw ) ) {
		return $out;
	}

	$types = function_exists( 'lfndr_field_types' ) ? lfndr_field_types() : array();

	$fields = array();
	$keys   = array();
	if ( isset( $raw['fields'] ) && is_array( $raw['fields'] ) ) {
		foreach ( $raw['fields'] as $candidate ) {
			$field = lfndr_sanitize_field( $candidate, $types );
			if ( null === $field ) {
				continue;
			}
			/* Duplicate keys are dropped rather than renamed. A silent rename
			 * would point the survivor at the loser's post meta. */
			if ( isset( $keys[ $field['key'] ] ) ) {
				continue;
			}
			$keys[ $field['key'] ] = true;
			$fields[]              = $field;
		}
	}

	$fields         = lfndr_resolve_field_references( $fields );
	$out['fields']  = $fields;
	$out['primary'] = lfndr_sanitize_primary_roles( $raw['primary'] ?? array(), $fields );
	$out['retired'] = lfndr_sanitize_retired( $raw['retired'] ?? array(), $types, $keys );

	$valid = array_merge( array_keys( $keys ), LFNDR_SYNTHETIC_KEYS );
	$out['detail_order'] = lfndr_sanitize_order( $raw['detail_order'] ?? array(), $valid );
	$out['card_order']   = lfndr_sanitize_order( $raw['card_order'] ?? array(), $valid );

	return $out;
}

/**
 * Sanitize one field definition. Returns null if it is unsalvageable.
 *
 * @param mixed $raw   Candidate definition.
 * @param array $types Type registry.
 * @return array|null
 */
function lfndr_sanitize_field( $raw, array $types ): ?array {
	if ( ! is_array( $raw ) ) {
		return null;
	}

	$field = array_merge( lfndr_field_defaults(), array_intersect_key( $raw, lfndr_field_defaults() ) );

	$field['key'] = lfndr_sanitize_field_key( (string) ( $raw['key'] ?? '' ) );
	if ( '' === $field['key'] ) {
		return null;
	}

	/* An unknown type means the plugin that registered it is deactivated. Drop
	 * the field from `fields` — rendering it with a guessed type would show
	 * wrong data and could save over the real value. The post meta is
	 * untouched, so reactivating that plugin restores everything. */
	$type = sanitize_key( (string) ( $raw['type'] ?? '' ) );
	if ( ! isset( $types[ $type ] ) ) {
		return null;
	}
	$field['type'] = $type;

	$field['label']       = sanitize_text_field( (string) ( $raw['label'] ?? '' ) );
	$field['help']        = sanitize_text_field( (string) ( $raw['help'] ?? '' ) );
	$field['section']     = sanitize_text_field( (string) ( $raw['section'] ?? '' ) );
	$field['placeholder'] = sanitize_text_field( (string) ( $raw['placeholder'] ?? '' ) );
	$field['icon']        = sanitize_key( (string) ( $raw['icon'] ?? '' ) );

	if ( '' === $field['label'] ) {
		$field['label'] = ucfirst( str_replace( '_', ' ', $field['key'] ) );
	}

	foreach ( array( 'required', 'show_card', 'show_detail', 'searchable', 'filterable' ) as $flag ) {
		$field[ $flag ] = ! empty( $raw[ $flag ] );
	}

	/* A field the registry says cannot be filtered is never filterable, whatever
	 * the form posted. The Fields screen grays the checkbox from the same
	 * registry value, so the two agree by construction rather than by care. */
	if ( empty( $types[ $type ]['facet_tokens'] ) ) {
		$field['filterable'] = false;
	}
	if ( empty( $types[ $type ]['search_text'] ) ) {
		$field['searchable'] = false;
	}

	$widget                 = sanitize_key( (string) ( $raw['filter_widget'] ?? '' ) );
	$field['filter_widget'] = in_array( $widget, array( 'chips', 'select', 'toggle' ), true ) ? $widget : '';
	$field['filter_label']  = sanitize_text_field( (string) ( $raw['filter_label'] ?? '' ) );

	$field['options']  = lfndr_sanitize_field_options( $raw['options'] ?? array() );
	$field['settings'] = lfndr_sanitize_field_settings( $raw['settings'] ?? array(), $field, $types );

	return $field;
}

/**
 * Sanitize a choice field's options list, deduping by value.
 *
 * @param mixed $raw Options.
 * @return array<int, array{value: string, label: string}>
 */
function lfndr_sanitize_field_options( $raw ): array {
	if ( ! is_array( $raw ) ) {
		return array();
	}
	$out  = array();
	$seen = array();
	foreach ( $raw as $option ) {
		if ( is_string( $option ) ) {
			$option = array(
				'value' => $option,
				'label' => $option,
			);
		}
		if ( ! is_array( $option ) ) {
			continue;
		}
		$value = sanitize_title( (string) ( $option['value'] ?? '' ) );
		if ( '' === $value || isset( $seen[ $value ] ) ) {
			continue;
		}
		$seen[ $value ] = true;
		$label          = sanitize_text_field( (string) ( $option['label'] ?? '' ) );
		$out[]          = array(
			'value' => $value,
			'label' => '' !== $label ? $label : $value,
		);
	}
	return $out;
}

/**
 * Sanitize a field's type-specific settings bag by delegating to the type.
 *
 * @param mixed $raw   Settings.
 * @param array $field Field so far.
 * @param array $types Type registry.
 * @return array
 */
function lfndr_sanitize_field_settings( $raw, array $field, array $types ): array {
	$raw      = is_array( $raw ) ? $raw : array();
	$callback = $types[ $field['type'] ]['sanitize_settings'] ?? null;
	if ( $callback && is_callable( $callback ) ) {
		return (array) call_user_func( $callback, $raw, $field );
	}
	/* No type-specific sanitizer: keep only scalars, one level deep. Enough for
	 * the simple types, and it cannot smuggle an object or a callable through. */
	$out = array();
	foreach ( $raw as $key => $value ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			continue;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			$out[ $key ] = $value;
		} elseif ( is_string( $value ) ) {
			$out[ $key ] = sanitize_text_field( $value );
		}
	}
	return $out;
}

/**
 * Clear settings that point at a field which is not there.
 *
 * A setting can name another field — an hours field names its note field. That
 * cannot be validated when the field itself is sanitized, because the rest of
 * the schema does not exist yet at that point. So it is checked here, once,
 * when every field is known.
 *
 * A reference that does not resolve is cleared rather than kept. Keeping it
 * means a setting quietly does nothing while the Fields screen shows a field
 * name that is no longer in the list, which is a worse thing to debug than an
 * empty setting. lfndr_sanitize_primary_roles() does the same for the roles,
 * which are references of the same kind held at the top of the schema.
 *
 * @param array $fields Sanitized fields.
 * @return array
 */
function lfndr_resolve_field_references( array $fields ): array {
	$by_type = array();
	foreach ( $fields as $field ) {
		$by_type[ $field['type'] ][] = $field['key'];
	}

	$references = array(
		// setting name => the type the referenced field must be.
		'note_field' => 'text',
	);

	foreach ( $fields as $i => $field ) {
		foreach ( $references as $setting => $required_type ) {
			if ( ! isset( $field['settings'][ $setting ] ) ) {
				continue;
			}
			$target = (string) $field['settings'][ $setting ];
			if ( '' !== $target && ! in_array( $target, $by_type[ $required_type ] ?? array(), true ) ) {
				$fields[ $i ]['settings'][ $setting ] = '';
			}
		}
	}

	return $fields;
}

/**
 * Sanitize an order list against the set of keys that may appear in it.
 *
 * @param mixed $raw   Order list.
 * @param array $valid Allowed keys.
 * @return array<int, string>
 */
function lfndr_sanitize_order( $raw, array $valid ): array {
	if ( is_string( $raw ) ) {
		$raw = explode( ',', $raw );
	}
	if ( ! is_array( $raw ) ) {
		return array();
	}
	$out  = array();
	$seen = array();
	foreach ( $raw as $key ) {
		$key = trim( (string) $key );
		if ( '' === $key || isset( $seen[ $key ] ) || ! in_array( $key, $valid, true ) ) {
			continue;
		}
		$seen[ $key ] = true;
		$out[]        = $key;
	}
	return $out;
}

/**
 * Sanitize the retired list.
 *
 * Retired definitions are kept whole so a Restore is lossless, but a key that
 * has come back as a live field is dropped from `retired` — otherwise the
 * Fields screen would offer to erase the meta the live field is now using.
 *
 * @param mixed $raw   Retired list.
 * @param array $types Type registry.
 * @param array $live  Map of live keys.
 * @return array
 */
function lfndr_sanitize_retired( $raw, array $types, array $live ): array {
	if ( ! is_array( $raw ) ) {
		return array();
	}
	$out  = array();
	$seen = array();
	foreach ( $raw as $candidate ) {
		if ( ! is_array( $candidate ) ) {
			continue;
		}
		$field = lfndr_sanitize_field( $candidate, $types );
		if ( null === $field || isset( $live[ $field['key'] ] ) || isset( $seen[ $field['key'] ] ) ) {
			continue;
		}
		$seen[ $field['key'] ]  = true;
		$field['retired_at']    = sanitize_text_field( (string) ( $candidate['retired_at'] ?? '' ) );
		$field['retired_by']    = absint( $candidate['retired_by'] ?? 0 );
		$out[]                  = $field;
	}
	return $out;
}

/**
 * How many published-or-not locations still hold a value for a given field key.
 *
 * Used only on the Fields screen, to put a number next to "Delete permanently
 * and erase data" so that decision is made with the count in front of you
 * rather than after the fact.
 *
 * @param string $key Field key.
 * @return int
 */
function lfndr_field_usage_count( string $key ): int {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin-screen-only count; no core API exposes it.
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
			lfndr_field_meta_key( $key )
		)
	);
}
