<?php
/**
 * Choosing a preset, through a real submission.
 *
 * The bug these exist for: selecting a preset and saving left the settings
 * exactly as they were. It survived because the sanitizer was only ever tested
 * with the payload `array( '_apply_preset' => 'ink' )`, which passes and proves
 * nothing — the browser never sends that. It sends the chosen radio AND all 25
 * appearance boxes, each holding the value it was rendered with, and those
 * stale values overwrote the preset the moment it was applied.
 *
 * So every test here submits what the form actually posts. A test that hand-
 * builds a tidier payload is testing a form that does not exist.
 *
 * @package LocationFinder
 */

class PresetSaveTest extends PHPUnit\Framework\TestCase {

	/**
	 * One save, shaped like the browser's.
	 *
	 * @param string               $radio Preset key the radio carries ('' = Custom).
	 * @param array<string,string> $edits Boxes the user actually changed.
	 * @return string The preset the stored values now amount to.
	 */
	private function save( string $radio, array $edits = array() ): string {
		$post = array( '_apply_preset' => $radio );

		// Every box submits, holding what it was rendered with. This is the part
		// that broke it.
		foreach ( array_keys( lfndr_appearance_fields() ) as $key ) {
			$post[ $key ] = (string) lfndr_setting( $key );
		}
		foreach ( $edits as $key => $value ) {
			$post[ $key ] = $value;
		}

		update_option( 'lfndr_settings', lfndr_sanitize_settings( $post ) );
		lfndr_settings_cache( null, true );

		return lfndr_current_preset();
	}

	public function test_choosing_a_preset_actually_applies_it(): void {
		$this->save( 'night' );
		$this->assertSame( 'ink', $this->save( 'ink' ), 'Picking Ink from Night did not switch to Ink.' );
	}

	public function test_every_preset_can_be_selected_from_every_other(): void {
		$keys = array_keys( lfndr_style_presets() );
		$this->save( $keys[0] );

		foreach ( $keys as $key ) {
			$this->assertSame( $key, $this->save( $key ), sprintf( 'Could not switch to "%s".', $key ) );
		}
	}

	public function test_the_applied_values_are_the_presets_own(): void {
		$this->save( 'night' );
		$this->save( 'ink' );

		foreach ( lfndr_style_presets()['ink']['values'] as $key => $expected ) {
			$this->assertSame( (string) $expected, (string) lfndr_setting( $key ), sprintf( 'Field "%s" did not take Ink\'s value.', $key ) );
		}
	}

	/**
	 * Fields a preset does not mention must be cleared, not inherited. Several
	 * sets leave the finder background blank deliberately, and a previous set's
	 * dark canvas showing through is the exact bug the reset-then-apply order
	 * exists to prevent.
	 */
	public function test_switching_does_not_leave_the_previous_set_underneath(): void {
		$this->save( 'night' );
		$this->assertNotSame( '', (string) lfndr_setting( 'finder_bg' ), 'Precondition: Night sets a background.' );

		$this->save( 'ink' );
		$this->assertSame( '', (string) lfndr_setting( 'finder_bg' ), 'Ink leaves the background blank; Night\'s survived.' );
	}

	/**
	 * The other half of the intent. With the radio left where it is, an edited
	 * box must win — that is how a preset gets tuned into Custom.
	 */
	public function test_editing_a_value_without_moving_the_radio_gives_custom(): void {
		$this->save( 'coast' );

		$this->assertSame( '', $this->save( 'coast', array( 'accent_color' => '#ff0000' ) ), 'Editing a value should read as Custom.' );
		$this->assertSame( '#ff0000', (string) lfndr_setting( 'accent_color' ), 'The edit was discarded.' );
	}

	public function test_editing_while_already_custom_keeps_the_edit(): void {
		$this->save( 'ink' );
		$this->save( 'ink', array( 'radius' => '9px' ) );

		$this->assertSame( '', lfndr_current_preset() );
		$this->assertSame( '9px', (string) lfndr_setting( 'radius' ) );
	}

	public function test_resaving_the_same_preset_is_stable(): void {
		$this->save( 'ink' );
		$this->assertSame( 'ink', $this->save( 'ink' ) );
		$this->assertSame( 'ink', $this->save( 'ink' ) );
	}
}
