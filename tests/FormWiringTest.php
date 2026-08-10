<?php
/**
 * Every input the admin renders must reach the code that saves it.
 *
 * This exists because of a bug that got all the way to a commit. The role
 * selects were renamed to ride the settings form, the rename was lost when the
 * script that made it aborted before writing, and a later commit deleted the
 * handler that read the old names. The result parsed, linted, and passed the
 * entire suite with those three controls saving precisely nowhere.
 *
 * Nothing caught it because every test in this suite calls the sanitizers with
 * arrays it builds itself. That proves the sanitizer works on the payload the
 * TEST invents, and says nothing about whether the form sends that payload.
 * The gap between the two is exactly where the bug lived.
 *
 * So these tests read the rendered HTML, take the input names out of it, and
 * push those names through the real sanitizer. A field that is renamed on one
 * side only now fails here, immediately, instead of looking correct until
 * somebody notices their settings do not stick.
 *
 * @package GroundworkCommonLocationFinder
 */

class FormWiringTest extends PHPUnit\Framework\TestCase {

	/**
	 * Every name="..." in a chunk of rendered markup.
	 *
	 * @param string $html Rendered HTML.
	 * @return array<int, string>
	 */
	private function input_names( string $html ): array {
		preg_match_all( '/\sname="([^"]+)"/', $html, $m );
		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * `gwc_lfndr_settings[_roles][hours]` → `array( '_roles', 'hours' )`.
	 *
	 * @param string $name An input name.
	 * @return array<int, string>
	 */
	private function name_path( string $name ): array {
		preg_match_all( '/\[([^\]]*)\]/', $name, $m );
		$root = strstr( $name, '[', true );
		return array_merge( array( false === $root ? $name : $root ), $m[1] );
	}

	private function render_roles(): string {
		ob_start();
		gwc_lfndr_render_roles_fields();
		return (string) ob_get_clean();
	}

	public function test_the_roles_form_renders_at_all(): void {
		// The starter schema has an address and an hours field, so there is
		// something to wire up. If this ever renders nothing the assertions
		// below would pass vacuously.
		update_option( 'gwc_lfndr_schema', gwc_lfndr_default_schema() );
		gwc_lfndr_schema_cache( null, true );

		$this->assertStringContainsString( '<select', $this->render_roles() );
	}

	public function test_every_role_select_is_read_by_the_settings_sanitizer(): void {
		update_option( 'gwc_lfndr_schema', gwc_lfndr_default_schema() );
		gwc_lfndr_schema_cache( null, true );

		$names = array_filter(
			$this->input_names( $this->render_roles() ),
			function ( $name ) {
				return false !== strpos( $name, '[' );
			}
		);

		$this->assertNotEmpty( $names, 'The roles form rendered no inputs to check.' );

		foreach ( $names as $name ) {
			$path = $this->name_path( $name );

			$this->assertSame(
				GWC_LFNDR_SETTINGS_OPTION,
				$path[0],
				sprintf( 'Input "%s" is not part of the settings form, so nothing will save it.', $name )
			);

			// Build the POST exactly as the browser would for this one input,
			// then check it changed what it claims to change.
			$type  = end( $path );
			$value = 'address' === $type ? 'address' : ( 'hours' === $type ? 'hours' : '' );

			gwc_lfndr_sanitize_settings(
				array(
					'_tab_behavior' => '1',
					'_roles'        => array( $type => $value ),
				)
			);
			gwc_lfndr_schema_cache( null, true );

			$this->assertSame(
				$value,
				(string) ( gwc_lfndr_get_schema()['primary'][ $type ] ?? '__unset__' ),
				sprintf( 'Submitting "%s" did not reach the schema. The form and the sanitizer disagree.', $name )
			);
		}
	}

	/**
	 * The transient keys must not survive into the stored option. They are
	 * instructions to the sanitizer, not settings, and one left behind would be
	 * written back out on the next save forever.
	 */
	public function test_transient_keys_are_stripped_from_what_is_stored(): void {
		update_option( 'gwc_lfndr_schema', gwc_lfndr_default_schema() );
		gwc_lfndr_schema_cache( null, true );

		$out = gwc_lfndr_sanitize_settings(
			array(
				'_tab_behavior' => '1',
				'_roles'        => array( 'hours' => 'hours' ),
				'_apply_preset' => 'ink',
			)
		);

		foreach ( array( '_roles', '_apply_preset', '_tab_behavior' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $out, sprintf( '"%s" is transport, not a setting.', $key ) );
		}
	}

	/**
	 * The other half: every registered option field must round-trip through the
	 * sanitizer under its own key. A field added to the registry but spelled
	 * differently in the sanitizer would be silently unsaveable.
	 */
	public function test_every_registered_option_field_round_trips(): void {
		$samples = array(
			'int'   => '7',
			'float' => '1.5',
			'bool'  => '1',
			'text'  => 'probe',
			'url'   => 'https://example.org/probe',
			'email' => 'probe@example.org',
		);

		$checked = 0;

		foreach ( gwc_lfndr_option_fields() as $key => $field ) {
			// Selects are constrained to their own choices and are covered by
			// their own test; free-form types are what this is about.
			if ( ! isset( $samples[ $field['type'] ] ) ) {
				continue;
			}

			$value = $samples[ $field['type'] ];
			$out   = gwc_lfndr_sanitize_settings(
				array(
					'_tab_' . $field['tab'] => '1',
					$key                    => $value,
				)
			);

			$this->assertArrayHasKey(
				$key,
				$out,
				sprintf( 'Field "%s" renders on the %s tab but the sanitizer never writes it.', $key, $field['tab'] )
			);

			++$checked;
		}

		$this->assertGreaterThan( 10, $checked, 'Too few fields checked — the sample map has drifted from the registry.' );
	}

	/**
	 * Every PHP file that ships and could hold a settings form or its
	 * registration. Scanned as a directory rather than as the two files that
	 * happen to matter today, so a third form added tomorrow is covered without
	 * anybody remembering to come back here.
	 *
	 * @return array<string, string> Repo-relative path => contents.
	 */
	private function form_sources(): array {
		$root  = dirname( __DIR__ );
		$found = glob( $root . '/inc/*.php' );
		$files = array_merge(
			is_array( $found ) ? $found : array(),
			array( $root . '/groundwork-common-location-finder.php' )
		);

		$sources = array();

		foreach ( $files as $file ) {
			$sources[ substr( $file, strlen( $root ) + 1 ) ] = (string) file_get_contents( $file );
		}

		return $sources;
	}

	/**
	 * Each place an option group is named, and what named it.
	 *
	 * @return array<int, array{group: string, call: string, path: string}>
	 */
	private function option_group_sites(): array {
		$patterns = array(
			'register_setting' => "/register_setting\(\s*'([^']+)'/",
			'settings_fields'  => "/settings_fields\(\s*'([^']+)'\s*\)/",
		);

		$sites = array();

		foreach ( $this->form_sources() as $path => $php ) {
			foreach ( $patterns as $call => $pattern ) {
				preg_match_all( $pattern, $php, $m );

				foreach ( $m[1] as $group ) {
					$sites[] = array(
						'group' => $group,
						'call'  => $call,
						'path'  => $path,
					);
				}
			}
		}

		return $sites;
	}

	/**
	 * @param array<int, array{group: string, call: string, path: string}> $sites Call sites.
	 * @param string                                                       $call  Function name to keep.
	 * @return array<int, array{group: string, call: string, path: string}>
	 */
	private function sites_calling( array $sites, string $call ): array {
		return array_values(
			array_filter(
				$sites,
				static function ( array $site ) use ( $call ) {
					return $call === $site['call'];
				}
			)
		);
	}

	/**
	 * The option group is the same wiring as the input names above, one level up.
	 *
	 * options.php does not save what it is posted. It looks the posted
	 * option_page up in the allow-list that register_setting() writes, and when
	 * the group named on the form is not a key in there it rejects the request.
	 * That rejection is a redirect back to the settings screen: the form submits,
	 * the page reloads, nothing turns red, and every Appearance setting has
	 * quietly stopped saving.
	 *
	 * The string is hand-written in three places — once registering, twice on
	 * forms — with no constant tying them together, so the drift is one typo or
	 * one half-applied rename away, and it fails exactly as silently as the three
	 * role selects this file was written for.
	 */
	public function test_every_settings_form_posts_the_group_that_is_registered(): void {
		$sites = $this->option_group_sites();

		/* Vacuity guards. A rename that deleted a call rather than changing it
		 * would leave the agreement below holding over whatever survived. */
		$this->assertCount(
			1,
			$this->sites_calling( $sites, 'register_setting' ),
			'Expected exactly one register_setting() call for the settings option.'
		);
		$this->assertGreaterThanOrEqual(
			2,
			count( $this->sites_calling( $sites, 'settings_fields' ) ),
			'Expected both settings forms — the tabbed screen and the settings page — to call settings_fields().'
		);

		$named = array();

		foreach ( $sites as $site ) {
			$named[ $site['group'] ][] = sprintf( '%s (%s)', $site['path'], $site['call'] );
		}

		$report = array();

		foreach ( $named as $group => $where ) {
			$report[] = sprintf( '  %s — %s', $group, implode( ', ', $where ) );
		}

		$this->assertCount(
			1,
			$named,
			sprintf(
				"The settings forms and register_setting() name different option groups. options.php will reject the POST from the odd one out and every setting on it will silently stop saving:\n%s",
				implode( "\n", $report )
			)
		);
	}

	/**
	 * And the group must be registered against the option the inputs actually
	 * write to. The allow-list is keyed by group, but its value is the option
	 * name, and that is what options.php saves — not whatever the fields happen
	 * to be called. Register a different option and the form posts into the void
	 * just as quietly.
	 */
	public function test_the_registered_option_is_the_one_the_inputs_write_to(): void {
		$settings = $this->form_sources()['inc/admin-settings.php'];

		preg_match( "/register_setting\(\s*'[^']+',\s*([^,\s]+)/", $settings, $m );

		$this->assertSame(
			'GWC_LFNDR_SETTINGS_OPTION',
			$m[1] ?? '',
			'register_setting() must register the same option constant the form inputs are namespaced under.'
		);
	}
}
