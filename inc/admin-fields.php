<?php
/**
 * Locations → Fields: the schema builder.
 *
 * @package LocationFinder
 */

defined( 'ABSPATH' ) || exit;

const LFNDR_FIELDS_PAGE = 'lfndr-fields';

add_action( 'admin_post_lfndr_save_field', 'lfndr_handle_save_field' );
add_action( 'admin_post_lfndr_save_orders', 'lfndr_handle_save_orders' );
add_action( 'admin_post_lfndr_save_roles', 'lfndr_handle_save_roles' );
add_action( 'admin_post_lfndr_retire_field', 'lfndr_handle_retire_field' );
add_action( 'admin_post_lfndr_restore_field', 'lfndr_handle_restore_field' );
add_action( 'admin_post_lfndr_erase_field', 'lfndr_handle_erase_field' );

/**
 * The URL of the Fields screen, optionally with extra query args.
 *
 * @param array $args Extra query args.
 * @return string
 */
function lfndr_fields_url( array $args = array() ): string {
	return add_query_arg(
		array_merge(
			array(
				'post_type' => LFNDR_POST_TYPE,
				'page'      => LFNDR_FIELDS_PAGE,
			),
			$args
		),
		admin_url( 'edit.php' )
	);
}

/**
 * Route the Fields screen: list, add, or edit.
 */
function lfndr_fields_screen(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to configure location fields.', 'location-finder' ) );
	}

	$schema = lfndr_get_schema();

	if ( $schema['version'] > LFNDR_SCHEMA_VERSION ) {
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'This field configuration was written by a newer version of Location Finder. It is being left untouched to avoid losing settings this version does not understand. Update the plugin to edit it.', 'location-finder' )
		);
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view routing.
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view routing.
	$key = isset( $_GET['field'] ) ? lfndr_sanitize_field_key( wp_unslash( $_GET['field'] ) ) : '';

	if ( 'add' === $action ) {
		lfndr_fields_form_screen( $schema, null );
		return;
	}

	if ( 'edit' === $action && '' !== $key ) {
		$field = lfndr_get_field( $key, $schema );
		if ( null !== $field ) {
			lfndr_fields_form_screen( $schema, $field );
			return;
		}
	}

	lfndr_fields_list_screen( $schema );
}

/* ── List screen ────────────────────────────────────────────────────────── */

/**
 * The field list, the two order lists, and the retired section.
 *
 * @param array $schema Current schema.
 */
function lfndr_fields_list_screen( array $schema ): void {
	$types = lfndr_field_types();
	?>
	<div class="lfndr-fields">
		<h2 class="wp-heading-inline"><?php esc_html_e( 'Fields', 'location-finder' ); ?></h2>
		<a href="<?php echo esc_url( lfndr_fields_url( array( 'action' => 'add' ) ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Add Field', 'location-finder' ); ?>
		</a>
		<hr class="wp-header-end" />

		<?php lfndr_admin_notices(); ?>

		<p class="description">
			<?php esc_html_e( 'Location name, latitude and longitude are always present. Everything else a location records is defined here.', 'location-finder' ); ?>
		</p>

		<?php if ( empty( $schema['fields'] ) ) : ?>
			<div class="notice notice-info inline"><p>
				<?php esc_html_e( 'No fields yet. Add one to start describing your locations — an address is usually the first.', 'location-finder' ); ?>
			</p></div>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Label', 'location-finder' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Key', 'location-finder' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Type', 'location-finder' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Shown', 'location-finder' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Used for', 'location-finder' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $schema['fields'] as $field ) : ?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( lfndr_fields_url( array( 'action' => 'edit', 'field' => $field['key'] ) ) ); ?>"><?php echo esc_html( $field['label'] ); ?></a></strong>
							<?php if ( ! empty( $field['settings']['primary'] ) ) : ?>
								<span class="lfndr-pill"><?php esc_html_e( 'primary', 'location-finder' ); ?></span>
							<?php endif; ?>
							<div class="row-actions">
								<span class="edit"><a href="<?php echo esc_url( lfndr_fields_url( array( 'action' => 'edit', 'field' => $field['key'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'location-finder' ); ?></a> | </span>
								<span class="trash">
									<?php
									$retire = wp_nonce_url(
										admin_url( 'admin-post.php?action=lfndr_retire_field&field=' . rawurlencode( $field['key'] ) ),
										'lfndr_retire_' . $field['key']
									);
									?>
									<a href="<?php echo esc_url( $retire ); ?>"><?php esc_html_e( 'Retire', 'location-finder' ); ?></a>
								</span>
							</div>
						</td>
						<td><code><?php echo esc_html( $field['key'] ); ?></code></td>
						<td><?php echo esc_html( $types[ $field['type'] ]['label'] ?? $field['type'] ); ?></td>
						<td><?php echo esc_html( lfndr_describe_visibility( $field ) ); ?></td>
						<td><?php echo esc_html( lfndr_describe_usage( $field ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php lfndr_render_order_form( $schema ); ?>
		<?php endif; ?>

		<?php lfndr_render_retired_section( $schema ); ?>
	</div>
	<?php
}

/**
 * A short human phrase for where a field appears.
 *
 * @param array $field Field definition.
 * @return string
 */
function lfndr_describe_visibility( array $field ): string {
	if ( ! empty( $field['show_card'] ) && ! empty( $field['show_detail'] ) ) {
		return __( 'Card and detail', 'location-finder' );
	}
	if ( ! empty( $field['show_card'] ) ) {
		return __( 'Card only', 'location-finder' );
	}
	if ( ! empty( $field['show_detail'] ) ) {
		return __( 'Detail only', 'location-finder' );
	}
	return __( 'Hidden', 'location-finder' );
}

/**
 * A short human phrase for what a field contributes to search and filtering.
 *
 * @param array $field Field definition.
 * @return string
 */
function lfndr_describe_usage( array $field ): string {
	$parts = array();
	if ( ! empty( $field['searchable'] ) ) {
		$parts[] = __( 'Search', 'location-finder' );
	}
	if ( ! empty( $field['filterable'] ) ) {
		$parts[] = __( 'Filters', 'location-finder' );
	}
	return $parts ? implode( ', ', $parts ) : '—';
}

/* ── Roles ──────────────────────────────────────────────────────────────── */

/**
 * Which field each site-wide behavior reads.
 *
 * One panel of selects rather than a checkbox on each field, and the difference
 * is not tidiness. A checkbox per field cannot express "exactly one": it can be
 * ticked on two fields of the same type, and something downstream then has to
 * pick a winner by document order — which meant ticking the box on a second
 * field appeared to work, saved, and silently reverted, with the losing field
 * offering no clue why. A select holds one value, so the invariant is a
 * property of the control instead of a rule applied after the fact.
 *
 * It also puts the whole wiring on one screen. Previously this was four
 * controls across three field-edit forms, none of which showed what the others
 * held, so the only way to learn which address fed Directions was to open each
 * address field in turn.
 *
 * @param array $schema Current schema.
 */
function lfndr_render_roles_fields( array $schema = array() ): void {
	$schema = $schema ? $schema : lfndr_get_schema();
	$roles = array(
		'address'  => array(
			'label' => __( 'Address used for Directions', 'location-finder' ),
			'help'  => __( 'Also the address the editor\'s lookup fills in when you search for a place.', 'location-finder' ),
		),
		'hours'    => array(
			'label' => __( 'Hours used for "Open now"', 'location-finder' ),
			'help'  => __( 'The schedule the open/closed badge and the "Open today" filter are worked out from.', 'location-finder' ),
		),
		'closures' => array(
			'label' => __( 'Closures that suspend those hours', 'location-finder' ),
			'help'  => __( 'While one of these is active the location never reads as open, and the hours above are shown struck through.', 'location-finder' ),
		),
	);

	$by_type = array();
	foreach ( $schema['fields'] as $field ) {
		$by_type[ $field['type'] ][] = $field;
	}

	// Nothing to wire up until at least one composite field exists.
	$any = false;
	foreach ( array_keys( $roles ) as $type ) {
		$any = $any || ! empty( $by_type[ $type ] );
	}
	if ( ! $any ) {
		return;
	}
	?>
	<table class="form-table" role="presentation">
		<tbody>
		<?php foreach ( $roles as $type => $role ) : ?>
			<?php
			$candidates = $by_type[ $type ] ?? array();
			if ( ! $candidates ) {
				continue;
			}
			$current = (string) ( $schema['primary'][ $type ] ?? '' );
			$id      = 'lfndr-role-' . $type;
			?>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $role['label'] ); ?></label></th>
				<td>
					<select id="<?php echo esc_attr( $id ); ?>" name="roles[<?php echo esc_attr( $type ); ?>]">
						<option value=""><?php esc_html_e( '— none —', 'location-finder' ); ?></option>
						<?php foreach ( $candidates as $candidate ) : ?>
							<option value="<?php echo esc_attr( $candidate['key'] ); ?>" <?php selected( $current, $candidate['key'] ); ?>>
								<?php echo esc_html( $candidate['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description" style="max-width:44em"><?php echo esc_html( $role['help'] ); ?></p>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Save the roles panel.
 */
function lfndr_handle_save_roles(): void {
	lfndr_guard_admin_post( 'lfndr_save_roles' );

	$schema = lfndr_get_schema();
	$raw    = isset( $_POST['roles'] ) && is_array( $_POST['roles'] ) ? wp_unslash( $_POST['roles'] ) : array();

	/* Only the roles the form actually rendered are touched. A type with no
	 * fields yet renders no row, and a blanket overwrite would clear a role
	 * that is simply not on screen. */
	$primary = $schema['primary'];
	foreach ( lfndr_primary_roles() as $type ) {
		if ( array_key_exists( $type, $raw ) ) {
			$primary[ $type ] = sanitize_key( (string) $raw[ $type ] );
		}
	}
	$schema['primary'] = $primary;

	// lfndr_sanitize_primary_roles() clears anything that does not resolve.
	lfndr_save_schema( lfndr_sanitize_schema( $schema ) );
	lfndr_fields_redirect( 'roles_saved', array( 'tab' => 'behavior' ) );
}

/* ── Order form ─────────────────────────────────────────────────────────── */

/**
 * The two reorder lists.
 *
 * @param array $schema Current schema.
 */
function lfndr_render_order_form( array $schema ): void {
	?>
	<h2><?php esc_html_e( 'Display order', 'location-finder' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Drag order is set with the arrow buttons. Move a field below the divider to hide it from that view — it stays defined, and stays searchable if you marked it so.', 'location-finder' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lfndr-orders">
		<input type="hidden" name="action" value="lfndr_save_orders" />
		<?php wp_nonce_field( 'lfndr_save_orders' ); ?>

		<div class="lfndr-orders__cols">
			<?php
			lfndr_render_order_list( 'detail', $schema, __( 'Detail pane', 'location-finder' ) );
			lfndr_render_order_list( 'card', $schema, __( 'Result card', 'location-finder' ) );
			?>
		</div>

		<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save display order', 'location-finder' ); ?></button></p>
	</form>
	<?php
}

/**
 * One reorder list: shown entries, a divider, then hidden entries.
 *
 * The list is always rendered and always readable. The arrow buttons carry a
 * `hidden` attribute that the script removes, and the comma-separated text
 * input below is visible until the script hides it — so with JavaScript off the
 * order is still both visible and editable, by hand, through the same
 * sanitizer.
 *
 * @param string $surface 'detail' or 'card'.
 * @param array  $schema  Current schema.
 * @param string $heading Column heading.
 */
function lfndr_render_order_list( string $surface, array $schema, string $heading ): void {
	$flag    = 'card' === $surface ? 'show_card' : 'show_detail';
	$shown   = lfndr_resolve_order( $surface, $schema );
	$visible = array_column( $shown, 'key' );

	$hidden = array();
	foreach ( $schema['fields'] as $field ) {
		if ( empty( $field[ $flag ] ) ) {
			$hidden[] = $field;
		}
	}
	foreach ( LFNDR_SYNTHETIC_KEYS as $synthetic ) {
		if ( ! in_array( $synthetic, $visible, true ) ) {
			$hidden[] = array(
				'key'   => $synthetic,
				'label' => lfndr_synthetic_label( $synthetic ),
				'type'  => '',
			);
		}
	}
	?>
	<div class="lfndr-order" data-surface="<?php echo esc_attr( $surface ); ?>">
		<h3><?php echo esc_html( $heading ); ?></h3>
		<ol class="lfndr-order__list">
			<?php foreach ( $shown as $entry ) : ?>
				<?php
				lfndr_render_order_item(
					$entry['key'],
					null !== $entry['field'] ? $entry['field']['label'] : lfndr_synthetic_label( $entry['key'] ),
					null !== $entry['field'] ? $entry['field']['type'] : '',
					true
				);
				?>
			<?php endforeach; ?>

			<li class="lfndr-order__divider" data-divider="1">
				<span><?php esc_html_e( 'Not shown here', 'location-finder' ); ?></span>
			</li>

			<?php foreach ( $hidden as $field ) : ?>
				<?php lfndr_render_order_item( $field['key'], $field['label'], $field['type'], false ); ?>
			<?php endforeach; ?>
		</ol>

		<p class="lfndr-order__manual">
			<label for="lfndr-order-<?php echo esc_attr( $surface ); ?>"><?php esc_html_e( 'Order, as a comma-separated list of keys:', 'location-finder' ); ?></label><br />
			<input type="text" class="large-text code" id="lfndr-order-<?php echo esc_attr( $surface ); ?>"
				name="<?php echo esc_attr( $surface ); ?>_order"
				value="<?php echo esc_attr( implode( ',', $visible ) ); ?>" />
		</p>
	</div>
	<?php
}

/**
 * One row of a reorder list.
 *
 * @param string $key   Field or synthetic key.
 * @param string $label Display label.
 * @param string $type  Field type, or '' for synthetic.
 * @param bool   $shown Whether it sits above the divider.
 */
function lfndr_render_order_item( string $key, string $label, string $type, bool $shown ): void {
	?>
	<li class="lfndr-order__item" data-key="<?php echo esc_attr( $key ); ?>">
		<span class="lfndr-order__grip" aria-hidden="true"></span>
		<span class="lfndr-order__label"><?php echo esc_html( $label ); ?></span>
		<?php if ( '' === $type ) : ?>
			<span class="lfndr-pill"><?php esc_html_e( 'built in', 'location-finder' ); ?></span>
		<?php else : ?>
			<code class="lfndr-order__key"><?php echo esc_html( $key ); ?></code>
		<?php endif; ?>
		<span class="lfndr-order__buttons" hidden>
			<button type="button" class="button button-small" data-move="up"
				aria-label="<?php /* translators: %s: field label. */ echo esc_attr( sprintf( __( 'Move %s up', 'location-finder' ), $label ) ); ?>">&uarr;</button>
			<button type="button" class="button button-small" data-move="down"
				aria-label="<?php /* translators: %s: field label. */ echo esc_attr( sprintf( __( 'Move %s down', 'location-finder' ), $label ) ); ?>">&darr;</button>
		</span>
		<?php if ( ! $shown ) : ?>
			<span class="screen-reader-text"><?php esc_html_e( '(not shown)', 'location-finder' ); ?></span>
		<?php endif; ?>
	</li>
	<?php
}

/**
 * Human labels for the synthetic order entries.
 *
 * @param string $key Synthetic key.
 * @return string
 */
function lfndr_synthetic_label( string $key ): string {
	switch ( $key ) {
		case '__name':
			return __( 'Location name', 'location-finder' );
		case '__coords':
			return __( 'Coordinates', 'location-finder' );
		case '__distance':
			return __( 'Distance from the visitor', 'location-finder' );
		case '__directions':
			return __( 'Directions link', 'location-finder' );
		default:
			return $key;
	}
}

/* ── Retired section ────────────────────────────────────────────────────── */

/**
 * Retired fields, with a live count of the data each still holds.
 *
 * @param array $schema Current schema.
 */
function lfndr_render_retired_section( array $schema ): void {
	if ( empty( $schema['retired'] ) ) {
		return;
	}
	?>
	<h2><?php esc_html_e( 'Retired fields', 'location-finder' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Retiring a field removes it from the editor and the front end but keeps everything already recorded. Restore it and the data comes back exactly as it was.', 'location-finder' ); ?>
	</p>
	<table class="wp-list-table widefat fixed striped">
		<tbody>
		<?php foreach ( $schema['retired'] as $field ) : ?>
			<?php $count = lfndr_field_usage_count( $field['key'] ); ?>
			<tr>
				<td>
					<strong><?php echo esc_html( $field['label'] ); ?></strong>
					<code><?php echo esc_html( $field['key'] ); ?></code>
					<?php if ( '' !== ( $field['retired_at'] ?? '' ) ) : ?>
						<span class="description">
							<?php
							printf(
								/* translators: %s: date. */
								esc_html__( 'retired %s', 'location-finder' ),
								esc_html( $field['retired_at'] )
							);
							?>
						</span>
					<?php endif; ?>
				</td>
				<td>
					<?php
					printf(
						esc_html(
							/* translators: %d: number of locations. */
							_n( 'Holds data on %d location', 'Holds data on %d locations', $count, 'location-finder' )
						),
						(int) $count
					);
					?>
				</td>
				<td class="lfndr-retired__actions">
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lfndr_restore_field&field=' . rawurlencode( $field['key'] ) ), 'lfndr_restore_' . $field['key'] ) ); ?>">
						<?php esc_html_e( 'Restore', 'location-finder' ); ?>
					</a>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lfndr-erase">
						<input type="hidden" name="action" value="lfndr_erase_field" />
						<input type="hidden" name="field" value="<?php echo esc_attr( $field['key'] ); ?>" />
						<?php wp_nonce_field( 'lfndr_erase_' . $field['key'] ); ?>
						<label class="screen-reader-text" for="lfndr-erase-<?php echo esc_attr( $field['key'] ); ?>">
							<?php esc_html_e( 'Type DELETE to confirm', 'location-finder' ); ?>
						</label>
						<input type="text" id="lfndr-erase-<?php echo esc_attr( $field['key'] ); ?>" name="confirm"
							placeholder="<?php esc_attr_e( 'Type DELETE', 'location-finder' ); ?>" size="12" autocomplete="off" />
						<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Erase data', 'location-finder' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/* ── Add / edit form ────────────────────────────────────────────────────── */

/**
 * The add-or-edit form for one field.
 *
 * @param array      $schema Current schema.
 * @param array|null $field  Field being edited, or null when adding.
 */
function lfndr_fields_form_screen( array $schema, ?array $field ): void {
	$types     = lfndr_field_types();
	$editing   = null !== $field;
	$field     = $field ?? lfndr_field_defaults();
	$type_meta = $types[ $field['type'] ] ?? array();
	?>
	<div class="wrap lfndr-admin">
		<h1><?php echo esc_html( $editing ? __( 'Edit Field', 'location-finder' ) : __( 'Add Field', 'location-finder' ) ); ?></h1>

		<?php lfndr_admin_notices(); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lfndr-field-form">
			<input type="hidden" name="action" value="lfndr_save_field" />
			<?php wp_nonce_field( 'lfndr_save_field' ); ?>
			<?php if ( $editing ) : ?>
				<input type="hidden" name="original_key" value="<?php echo esc_attr( $field['key'] ); ?>" />
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="lfndr-label"><?php esc_html_e( 'Label', 'location-finder' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="lfndr-label" name="label" required
								value="<?php echo esc_attr( $field['label'] ); ?>" />
							<p class="description"><?php esc_html_e( 'What editors and visitors see. Safe to change at any time.', 'location-finder' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lfndr-key"><?php esc_html_e( 'Key', 'location-finder' ); ?></label></th>
						<td>
							<?php if ( $editing ) : ?>
								<input type="text" class="regular-text code" id="lfndr-key" value="<?php echo esc_attr( $field['key'] ); ?>" disabled />
								<p class="description">
									<?php esc_html_e( 'Keys cannot be changed. This is what already-saved locations use to find their data — renaming it would orphan every value. To change it, retire this field and add a new one.', 'location-finder' ); ?>
								</p>
							<?php else : ?>
								<input type="text" class="regular-text code" id="lfndr-key" name="key"
									placeholder="<?php esc_attr_e( 'auto-generated from the label', 'location-finder' ); ?>" />
								<p class="description"><?php esc_html_e( 'Lowercase letters, numbers and underscores. Permanent once saved — leave it blank to generate one from the label.', 'location-finder' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lfndr-type"><?php esc_html_e( 'Type', 'location-finder' ); ?></label></th>
						<td>
							<select id="lfndr-type" name="type"<?php disabled( $editing ); ?>>
								<?php foreach ( $types as $slug => $meta ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>"<?php selected( $field['type'], $slug ); ?>>
										<?php echo esc_html( $meta['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<?php if ( $editing ) : ?>
								<input type="hidden" name="type" value="<?php echo esc_attr( $field['type'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Types cannot be changed either — the stored values would no longer make sense.', 'location-finder' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lfndr-help"><?php esc_html_e( 'Editor hint', 'location-finder' ); ?></label></th>
						<td><input type="text" class="large-text" id="lfndr-help" name="help" value="<?php echo esc_attr( $field['help'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="lfndr-section"><?php esc_html_e( 'Group', 'location-finder' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="lfndr-section" name="section" value="<?php echo esc_attr( $field['section'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Fields sharing a group name are collapsed together in the location editor.', 'location-finder' ); ?></p>
						</td>
					</tr>

					<?php if ( ! empty( $type_meta['has_options'] ) || ! $editing ) : ?>
					<tr class="lfndr-row-options">
						<th scope="row"><label for="lfndr-options"><?php esc_html_e( 'Choices', 'location-finder' ); ?></label></th>
						<td>
							<textarea id="lfndr-options" name="options" rows="6" class="large-text code"><?php echo esc_textarea( lfndr_options_to_text( $field['options'] ) ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'One per line. Write "Diapers" for a simple choice, or "diapers | Diapers" to set the stored value separately from the label. Stored values are permanent; labels can change.', 'location-finder' ); ?>
							</p>
						</td>
					</tr>
					<?php endif; ?>

					<tr>
						<th scope="row"><?php esc_html_e( 'Where it appears', 'location-finder' ); ?></th>
						<td>
							<fieldset>
								<label><input type="checkbox" name="show_detail" value="1"<?php checked( ! empty( $field['show_detail'] ) ); ?> /> <?php esc_html_e( 'In the detail pane', 'location-finder' ); ?></label><br />
								<label><input type="checkbox" name="show_card" value="1"<?php checked( ! empty( $field['show_card'] ) ); ?> /> <?php esc_html_e( 'On result cards', 'location-finder' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'How visitors find it', 'location-finder' ); ?></th>
						<td>
							<fieldset>
								<?php $can_search = ! empty( $type_meta['search_text'] ); ?>
								<?php $can_filter = ! empty( $type_meta['facet_tokens'] ); ?>
								<label>
									<input type="checkbox" name="searchable" value="1"<?php checked( ! empty( $field['searchable'] ) ); ?><?php disabled( ! $can_search ); ?> />
									<?php esc_html_e( 'Include in the search box', 'location-finder' ); ?>
								</label>
								<?php if ( ! $can_search ) : ?>
									<span class="description"><?php esc_html_e( '— not available for this type', 'location-finder' ); ?></span>
								<?php endif; ?>
								<br />
								<label>
									<input type="checkbox" name="filterable" value="1"<?php checked( ! empty( $field['filterable'] ) ); ?><?php disabled( ! $can_filter ); ?> />
									<?php esc_html_e( 'Offer as a filter', 'location-finder' ); ?>
								</label>
								<?php if ( ! $can_filter ) : ?>
									<span class="description"><?php esc_html_e( '— only choice and yes/no fields can be filtered', 'location-finder' ); ?></span>
								<?php endif; ?>
								<p class="description">
									<?php esc_html_e( 'A field can be searchable without being displayed — useful for an internal code visitors type but never see.', 'location-finder' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lfndr-filter-label"><?php esc_html_e( 'Filter heading', 'location-finder' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="lfndr-filter-label" name="filter_label" value="<?php echo esc_attr( $field['filter_label'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Shown above this field\'s filters. Defaults to the label.', 'location-finder' ); ?></p>
						</td>
					</tr>

					<?php if ( ! empty( $type_meta['schema_form'] ) && is_callable( $type_meta['schema_form'] ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Options for this type', 'location-finder' ); ?></th>
						<td><?php call_user_func( $type_meta['schema_form'], $field ); ?></td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo esc_html( $editing ? __( 'Save field', 'location-finder' ) : __( 'Add field', 'location-finder' ) ); ?></button>
				<a class="button button-secondary" href="<?php echo esc_url( lfndr_fields_url() ); ?>"><?php esc_html_e( 'Cancel', 'location-finder' ); ?></a>
			</p>
		</form>
	</div>
	<?php
	unset( $schema );
}

/**
 * Render a choice field's options as editable text.
 *
 * @param array $options Options list.
 * @return string
 */
function lfndr_options_to_text( array $options ): string {
	$lines = array();
	foreach ( $options as $option ) {
		$lines[] = $option['value'] === $option['label']
			? $option['label']
			: $option['value'] . ' | ' . $option['label'];
	}
	return implode( "\n", $lines );
}

/**
 * Parse the options textarea back into an options list.
 *
 * @param string $text Raw textarea content.
 * @return array
 */
function lfndr_options_from_text( string $text ): array {
	$out = array();
	foreach ( preg_split( '/\R/', $text ) ?: array() as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		if ( false !== strpos( $line, '|' ) ) {
			[ $value, $label ] = array_map( 'trim', explode( '|', $line, 2 ) );
		} else {
			$value = $line;
			$label = $line;
		}
		$out[] = array(
			'value' => $value,
			'label' => $label,
		);
	}
	return $out;
}

/* ── Handlers ───────────────────────────────────────────────────────────── */

/**
 * Guard every admin-post handler identically.
 *
 * @param string $nonce_action Nonce action.
 * @param string $nonce_field  Nonce field name.
 */
/*
 * Note for anyone reading a static-analysis report of this file:
 *
 * Every admin_post_ handler below opens with a call to this function, and this
 * function is where the capability check and the nonce check live. PHPCS and
 * Plugin Check cannot follow a nonce check through a helper — the sniff only
 * recognises check_admin_referer() written literally inside the same function —
 * so they report "Processing form data without nonce verification" against
 * roughly thirty $_POST reads in this file. Every one of those is a false
 * positive, and the guard below is the thing to check rather than take on
 * trust.
 *
 * The guard is shared rather than inlined because a nonce check that is
 * copy-pasted into a dozen handlers is a nonce check that will eventually be
 * pasted into eleven.
 */
function lfndr_guard_admin_post( string $nonce_action, string $nonce_field = '_wpnonce' ): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to configure location fields.', 'location-finder' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( $nonce_action, $nonce_field );
}

/**
 * Redirect back to the Fields screen with a message code.
 *
 * Post/Redirect/Get, so a refresh never re-submits a schema change.
 *
 * @param string $message Message code.
 * @param array  $args    Extra query args.
 */
function lfndr_fields_redirect( string $message, array $args = array() ): void {
	wp_safe_redirect( lfndr_fields_url( array_merge( array( 'lfndr_msg' => $message ), $args ) ) );
	exit;
}

/**
 * Add or update one field.
 */
function lfndr_handle_save_field(): void {
	lfndr_guard_admin_post( 'lfndr_save_field' );

	$schema   = lfndr_get_schema();
	$original = isset( $_POST['original_key'] ) ? lfndr_sanitize_field_key( wp_unslash( $_POST['original_key'] ) ) : '';
	$editing  = '' !== $original && null !== lfndr_get_field( $original, $schema );

	$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
	if ( '' === $label ) {
		lfndr_fields_redirect( 'label_required', $editing ? array( 'action' => 'edit', 'field' => $original ) : array( 'action' => 'add' ) );
	}

	if ( $editing ) {
		$key = $original;
	} else {
		$requested = isset( $_POST['key'] ) ? (string) wp_unslash( $_POST['key'] ) : '';
		$key       = lfndr_sanitize_field_key( '' !== trim( $requested ) ? $requested : $label );
		if ( '' === $key ) {
			lfndr_fields_redirect( 'bad_key', array( 'action' => 'add' ) );
		}
		if ( null !== lfndr_get_field( $key, $schema ) ) {
			lfndr_fields_redirect( 'duplicate_key', array( 'action' => 'add' ) );
		}
		/* A key that matches something in the retired list is a re-creation, not
		 * a new field. Refuse it and point at Restore: silently creating a live
		 * field over retired meta would resurrect old values with no warning,
		 * and that data may be exactly what somebody retired it to hide. */
		foreach ( $schema['retired'] as $retired ) {
			if ( $retired['key'] === $key ) {
				lfndr_fields_redirect( 'retired_key', array( 'action' => 'add' ) );
			}
		}
	}

	$candidate = array(
		'key'          => $key,
		'type'         => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'text',
		'label'        => $label,
		'help'         => isset( $_POST['help'] ) ? sanitize_text_field( wp_unslash( $_POST['help'] ) ) : '',
		'section'      => isset( $_POST['section'] ) ? sanitize_text_field( wp_unslash( $_POST['section'] ) ) : '',
		'filter_label' => isset( $_POST['filter_label'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_label'] ) ) : '',
		'show_detail'  => ! empty( $_POST['show_detail'] ),
		'show_card'    => ! empty( $_POST['show_card'] ),
		'searchable'   => ! empty( $_POST['searchable'] ),
		'filterable'   => ! empty( $_POST['filterable'] ),
		'options'      => isset( $_POST['options'] ) ? lfndr_options_from_text( sanitize_textarea_field( wp_unslash( $_POST['options'] ) ) ) : array(),
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-key by the type's settings sanitizer.
		'settings'     => isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(),
	);

	/* Preserve settings the form did not render. A type's schema_form shows only
	 * the keys it knows about; `primary` in particular is set elsewhere, and a
	 * round-trip through this form must not quietly clear it. */
	if ( $editing ) {
		$existing              = lfndr_get_field( $key, $schema );
		$candidate['settings'] = array_merge( $existing['settings'], $candidate['settings'] );
	}

	$replaced = false;
	foreach ( $schema['fields'] as $i => $existing_field ) {
		if ( $existing_field['key'] === $key ) {
			$schema['fields'][ $i ] = $candidate;
			$replaced               = true;
			break;
		}
	}
	if ( ! $replaced ) {
		$schema['fields'][] = $candidate;
	}

	$sanitized = lfndr_sanitize_schema( $schema );

	/* If the field vanished in sanitization the type was unknown — almost
	 * always a deactivated plugin that registered it. Say so rather than
	 * redirecting to a list that silently lacks the field just saved. */
	if ( null === lfndr_get_field( $key, $sanitized ) ) {
		lfndr_fields_redirect( 'bad_type', array( 'action' => $editing ? 'edit' : 'add', 'field' => $key ) );
	}

	lfndr_save_schema( $sanitized );
	lfndr_fields_redirect( $editing ? 'field_saved' : 'field_added' );
}

/**
 * Save both display orders, and the show flags they imply.
 */
function lfndr_handle_save_orders(): void {
	lfndr_guard_admin_post( 'lfndr_save_orders' );

	$schema = lfndr_get_schema();
	$valid  = array_merge( wp_list_pluck( $schema['fields'], 'key' ), LFNDR_SYNTHETIC_KEYS );

	foreach ( array( 'detail', 'card' ) as $surface ) {
		$raw   = isset( $_POST[ $surface . '_order' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $surface . '_order' ] ) ) : '';
		$order = lfndr_sanitize_order( $raw, $valid );

		$schema[ $surface . '_order' ] = $order;

		/* The order list and the show_* checkboxes are two views of one fact.
		 * Position above the divider IS visibility, so saving the order writes
		 * the flags — otherwise moving a field down would reorder a list nobody
		 * can see and look like nothing happened. */
		$flag = 'card' === $surface ? 'show_card' : 'show_detail';
		foreach ( $schema['fields'] as $i => $field ) {
			$schema['fields'][ $i ][ $flag ] = in_array( $field['key'], $order, true );
		}
	}

	lfndr_save_schema( lfndr_sanitize_schema( $schema ) );
	lfndr_fields_redirect( 'orders_saved' );
}

/**
 * Retire a field: out of `fields`, into `retired`, post meta untouched.
 */
function lfndr_handle_retire_field(): void {
	/* Read before the guard on purpose: the nonce action is per-field, so the
	 * key is needed to know which nonce to demand. Tampering with it therefore
	 * fails the check on the next line rather than bypassing it — and the value
	 * is reduced to a field key before it is used for anything at all. */
	$key = isset( $_GET['field'] ) ? lfndr_sanitize_field_key( wp_unslash( $_GET['field'] ) ) : '';
	lfndr_guard_admin_post( 'lfndr_retire_' . $key );

	$schema = lfndr_get_schema();
	$field  = lfndr_get_field( $key, $schema );
	if ( null === $field ) {
		lfndr_fields_redirect( 'not_found' );
	}

	$field['retired_at'] = gmdate( 'Y-m-d' );
	$field['retired_by'] = get_current_user_id();

	$schema['fields']  = array_values(
		array_filter(
			$schema['fields'],
			static fn( array $candidate ): bool => $candidate['key'] !== $key
		)
	);
	$schema['retired'][] = $field;

	lfndr_save_schema( lfndr_sanitize_schema( $schema ) );
	lfndr_fields_redirect( 'field_retired' );
}

/**
 * Restore a retired field, with its data intact.
 */
function lfndr_handle_restore_field(): void {
	$key = isset( $_GET['field'] ) ? lfndr_sanitize_field_key( wp_unslash( $_GET['field'] ) ) : '';
	lfndr_guard_admin_post( 'lfndr_restore_' . $key );

	$schema  = lfndr_get_schema();
	$restore = null;
	foreach ( $schema['retired'] as $field ) {
		if ( $field['key'] === $key ) {
			$restore = $field;
			break;
		}
	}
	if ( null === $restore ) {
		lfndr_fields_redirect( 'not_found' );
	}

	unset( $restore['retired_at'], $restore['retired_by'] );
	$schema['fields'][]  = $restore;
	$schema['retired']   = array_values(
		array_filter(
			$schema['retired'],
			static fn( array $candidate ): bool => $candidate['key'] !== $key
		)
	);

	lfndr_save_schema( lfndr_sanitize_schema( $schema ) );
	lfndr_fields_redirect( 'field_restored' );
}

/**
 * Permanently delete a retired field and every value it holds.
 *
 * The only code path in the plugin that destroys location data, which is why it
 * needs a nonce, manage_options, a retired-only precondition, and the word
 * DELETE typed out.
 */
function lfndr_handle_erase_field(): void {
	$key = isset( $_POST['field'] ) ? lfndr_sanitize_field_key( wp_unslash( $_POST['field'] ) ) : '';
	lfndr_guard_admin_post( 'lfndr_erase_' . $key );

	$confirm = isset( $_POST['confirm'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) ) : '';
	if ( 'DELETE' !== strtoupper( $confirm ) ) {
		lfndr_fields_redirect( 'confirm_required' );
	}

	$schema  = lfndr_get_schema();
	$retired = wp_list_pluck( $schema['retired'], 'key' );
	if ( ! in_array( $key, $retired, true ) ) {
		lfndr_fields_redirect( 'not_found' );
	}

	delete_post_meta_by_key( lfndr_field_meta_key( $key ) );

	$schema['retired'] = array_values(
		array_filter(
			$schema['retired'],
			static fn( array $candidate ): bool => $candidate['key'] !== $key
		)
	);

	lfndr_save_schema( lfndr_sanitize_schema( $schema ) );
	lfndr_fields_redirect( 'field_erased' );
}

/* ── Notices ────────────────────────────────────────────────────────────── */

/**
 * Print the notice for the message code in the query string.
 */
function lfndr_admin_notices(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only message code from our own redirect.
	$code = isset( $_GET['lfndr_msg'] ) ? sanitize_key( wp_unslash( $_GET['lfndr_msg'] ) ) : '';
	if ( '' === $code ) {
		return;
	}

	$messages = array(
		'field_added'      => array( 'success', __( 'Field added.', 'location-finder' ) ),
		'field_saved'      => array( 'success', __( 'Field saved.', 'location-finder' ) ),
		'field_retired'    => array( 'success', __( 'Field retired. Its data is still there — restore it any time.', 'location-finder' ) ),
		'field_restored'   => array( 'success', __( 'Field restored, with everything it had recorded.', 'location-finder' ) ),
		'field_erased'     => array( 'success', __( 'Field and all of its data permanently deleted.', 'location-finder' ) ),
		'orders_saved'     => array( 'success', __( 'Display order saved.', 'location-finder' ) ),
		'roles_saved'      => array( 'success', __( 'Behavior saved.', 'location-finder' ) ),
		'label_required'   => array( 'error', __( 'A field needs a label.', 'location-finder' ) ),
		'bad_key'          => array( 'error', __( 'That key cannot be used. Keys start with a letter and contain only lowercase letters, numbers and underscores.', 'location-finder' ) ),
		'duplicate_key'    => array( 'error', __( 'A field with that key already exists.', 'location-finder' ) ),
		'retired_key'      => array( 'error', __( 'A retired field already uses that key. Restore it instead — creating a new one would silently pick up the old data.', 'location-finder' ) ),
		'bad_type'         => array( 'error', __( 'That field type is not available. If it came from another plugin, that plugin may be deactivated.', 'location-finder' ) ),
		'confirm_required' => array( 'error', __( 'Nothing was deleted: type DELETE in the box to confirm.', 'location-finder' ) ),
		'not_found'        => array( 'error', __( 'That field no longer exists.', 'location-finder' ) ),
	);

	if ( ! isset( $messages[ $code ] ) ) {
		return;
	}

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $messages[ $code ][0] ),
		esc_html( $messages[ $code ][1] )
	);
}
