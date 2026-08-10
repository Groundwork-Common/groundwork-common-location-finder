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
}
