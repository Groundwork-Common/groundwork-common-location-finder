<?php
/**
 * Appearance settings: the CSS-value sanitizers and the CSS they produce.
 *
 * These are the one place in the plugin where admin-entered text is printed
 * directly into a <style> tag rather than escaped as HTML — esc_html() would
 * be the wrong tool entirely here, and there is no core WordPress helper for
 * "sanitize a CSS custom-property value". So this is hand-rolled, and it is
 * exactly the kind of hand-rolled security logic that most needs a test.
 *
 * @package GroundworkCommonLocationFinder
 */

use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	protected function setUp(): void {
		gwc_lfndr_test_reset();
	}

	/* ── Color sanitizer ───────────────────────────────────────────────── */

	public function test_a_hex_colour_is_kept(): void {
		$this->assertSame( '#1a2b3c', gwc_lfndr_sanitize_css_color( '#1a2b3c' ) );
	}

	public function test_a_named_colour_is_kept(): void {
		$this->assertSame( 'rebeccapurple', gwc_lfndr_sanitize_css_color( 'rebeccapurple' ) );
	}

	public function test_a_system_colour_keyword_is_kept(): void {
		$this->assertSame( 'CanvasText', gwc_lfndr_sanitize_css_color( 'CanvasText' ) );
	}

	public function test_an_rgba_function_is_kept(): void {
		$this->assertSame( 'rgba(10, 20, 30, .5)', gwc_lfndr_sanitize_css_color( 'rgba(10, 20, 30, .5)' ) );
	}

	public function test_a_color_mix_function_is_kept(): void {
		$this->assertSame(
			'color-mix(in srgb, currentColor 20%, transparent)',
			gwc_lfndr_sanitize_css_color( 'color-mix(in srgb, currentColor 20%, transparent)' )
		);
	}

	public function test_a_theme_preset_variable_reference_is_kept(): void {
		// This is the specific case a native <input type="color"> could never
		// support, and the reason this field is plain text.
		$this->assertSame(
			'var(--wp--preset--color--accent-1)',
			gwc_lfndr_sanitize_css_color( 'var(--wp--preset--color--accent-1)' )
		);
	}

	public function test_whitespace_is_trimmed(): void {
		$this->assertSame( '#fff', gwc_lfndr_sanitize_css_color( '  #fff  ' ) );
	}

	public function test_empty_input_is_empty_output(): void {
		$this->assertSame( '', gwc_lfndr_sanitize_css_color( '' ) );
		$this->assertSame( '', gwc_lfndr_sanitize_css_color( '   ' ) );
	}

	public function test_an_overlong_value_is_rejected(): void {
		$this->assertSame( '', gwc_lfndr_sanitize_css_color( str_repeat( 'a', 201 ) ) );
	}

	/* ── The actual injection attempts ────────────────────────────────────
	 * This value is printed as .lfndr{--lfndr-accent:<value>}, unescaped,
	 * inside a <style> tag. Everything below is a way that could go wrong. */

	public function test_a_style_tag_breakout_is_rejected(): void {
		$this->assertSame( '', gwc_lfndr_sanitize_css_color( 'red</style><script>alert(1)</script>' ) );
	}

	public function test_a_rule_injection_via_braces_is_rejected(): void {
		$this->assertSame( '', gwc_lfndr_sanitize_css_color( 'red}body{display:none' ) );
	}

	public function test_a_declaration_injection_via_semicolon_is_rejected(): void {
		$this->assertSame( '', gwc_lfndr_sanitize_css_color( 'red;background:url(https://evil.example/x.png)' ) );
	}

	public function test_url_is_rejected_even_though_its_characters_are_all_allowed(): void {
		// Parens and letters are legitimately needed for var()/rgba()/
		// color-mix(), so the character allow-list alone cannot stop this —
		// it needs an explicit name check.
		$this->assertSame( '', gwc_lfndr_sanitize_css_color( 'url(https://evil.example/x.png)' ) );
		$this->assertSame( '', gwc_lfndr_sanitize_css_color( 'URL(evil.example)' ) );
	}

	public function test_css_expression_is_rejected(): void {
		$this->assertSame( '', gwc_lfndr_sanitize_css_color( 'expression(alert(1))' ) );
	}

	public function test_an_at_rule_is_rejected(): void {
		$this->assertSame( '', gwc_lfndr_sanitize_css_color( '#fff}@import "https://evil.example/x.css"' ) );
	}

	public function test_a_colon_is_rejected(): void {
		// No legitimate color value in this plugin's vocabulary needs one —
		// var(), rgba(), color-mix() and hex codes all get by without it — so
		// excluding it closes off javascript: and data: as a side effect.
		$this->assertSame( '', gwc_lfndr_sanitize_css_color( 'javascript:alert(1)' ) );
	}

	/* ── Length sanitizer ───────────────────────────────────────────────── */

	public function test_each_supported_unit_is_kept(): void {
		foreach ( array( '8px', '0.5rem', '1em', '50%', '60vh', '100vw', '2ch' ) as $value ) {
			$this->assertSame( $value, gwc_lfndr_sanitize_css_length( $value ) );
		}
	}

	public function test_a_negative_length_is_kept(): void {
		// Legitimate for something like a margin, even if none of this
		// plugin's own length fields currently use one.
		$this->assertSame( '-4px', gwc_lfndr_sanitize_css_length( '-4px' ) );
	}

	public function test_a_bare_number_with_no_unit_is_rejected(): void {
		$this->assertSame( '', gwc_lfndr_sanitize_css_length( '8' ) );
	}

	public function test_an_unrecognised_unit_is_rejected(): void {
		$this->assertSame( '', gwc_lfndr_sanitize_css_length( '8pt' ) );
		$this->assertSame( '', gwc_lfndr_sanitize_css_length( '8in' ) );
	}

	public function test_a_length_cannot_smuggle_a_function_call(): void {
		$this->assertSame( '', gwc_lfndr_sanitize_css_length( 'calc(100% - 8px)' ) );
		$this->assertSame( '', gwc_lfndr_sanitize_css_length( 'var(--evil)' ) );
	}

	public function test_empty_length_is_empty(): void {
		$this->assertSame( '', gwc_lfndr_sanitize_css_length( '' ) );
		$this->assertSame( '', gwc_lfndr_sanitize_css_length( '   ' ) );
	}

	/* ── The settings sanitizer ─────────────────────────────────────────── */

	public function test_only_submitted_keys_are_touched(): void {
		update_option( GWC_LFNDR_SETTINGS_OPTION, array( 'units' => 'km' ) );

		$saved = gwc_lfndr_sanitize_settings( array( 'accent_color' => '#123456' ) );

		$this->assertSame( '#123456', $saved['accent_color'] );
		$this->assertSame( 'km', $saved['units'], 'A field this form does not own must survive untouched.' );
	}

	public function test_an_invalid_submitted_value_is_dropped_not_kept_as_typed(): void {
		$saved = gwc_lfndr_sanitize_settings( array( 'radius' => '8px}body{display:none' ) );
		$this->assertSame( '', $saved['radius'] );
	}

	public function test_a_non_array_submission_does_not_fatal(): void {
		$saved = gwc_lfndr_sanitize_settings( 'not an array' );
		$this->assertIsArray( $saved );
	}

	/* ── The CSS builder ───────────────────────────────────────────────── */

	public function test_no_settings_produce_no_css(): void {
		$this->assertSame( '', gwc_lfndr_appearance_css() );
	}

	public function test_one_setting_produces_one_declaration(): void {
		update_option( GWC_LFNDR_SETTINGS_OPTION, array( 'accent_color' => '#123456' ) );
		$this->assertSame( '.lfndr{--lfndr-accent:#123456}', gwc_lfndr_appearance_css() );
	}

	public function test_settings_are_re_validated_at_output_even_if_never_sanitized_on_save(): void {
		// Simulates a value that reached the option without going through
		// gwc_lfndr_sanitize_settings() — WP-CLI, a migration, another plugin.
		update_option(
			GWC_LFNDR_SETTINGS_OPTION,
			array(
				'accent_color' => 'red}body{display:none',
				'radius'       => '8px',
			)
		);
		$this->assertSame( '.lfndr{--lfndr-radius:8px}', gwc_lfndr_appearance_css() );
	}

	public function test_field_order_in_the_registry_is_the_order_in_the_output(): void {
		update_option(
			GWC_LFNDR_SETTINGS_OPTION,
			array(
				'gap'          => '2rem',
				'accent_color' => '#000',
			)
		);
		$this->assertSame( '.lfndr{--lfndr-accent:#000;--lfndr-gap:2rem}', gwc_lfndr_appearance_css() );
	}

	/* ── Rule-mode fields: buttons, chips & cards ─────────────────────────
	 * These are native elements the plugin never colors itself, so setting
	 * one prints a standalone selector{...} block rather than joining the
	 * shared .lfndr{} custom-property block the var-mode fields use. */

	public function test_a_rule_mode_field_prints_its_own_selector_block(): void {
		update_option( GWC_LFNDR_SETTINGS_OPTION, array( 'badge_bg' => '#eee' ) );
		$fields = gwc_lfndr_appearance_fields();
		$this->assertSame(
			$fields['badge_bg']['selector'] . '{background-color:#eee !important}',
			gwc_lfndr_appearance_css()
		);
	}

	public function test_background_and_text_for_the_same_selector_share_one_block(): void {
		update_option(
			GWC_LFNDR_SETTINGS_OPTION,
			array(
				'badge_bg'   => '#eee',
				'badge_text' => '#111',
			)
		);
		// One block, not two — printing background-color and color as two
		// separate selector{...} statements would still work, but this is
		// smaller and it is what makes "only one of the pair was set" (the
		// next test) look right rather than leaving a stray empty rule.
		$fields = gwc_lfndr_appearance_fields();
		$this->assertSame(
			$fields['badge_bg']['selector'] . '{background-color:#eee !important;color:#111 !important}',
			gwc_lfndr_appearance_css()
		);
	}

	public function test_setting_only_the_background_does_not_print_an_empty_text_declaration(): void {
		update_option( GWC_LFNDR_SETTINGS_OPTION, array( 'card_bg' => '#fafafa' ) );
		$css = gwc_lfndr_appearance_css();
		$this->assertStringContainsString( 'background-color:#fafafa', $css );
		$this->assertStringNotContainsString( 'color:;', $css );
		$this->assertStringNotContainsString( 'color: !important', $css );
	}

	public function test_rule_mode_declarations_carry_important(): void {
		update_option( GWC_LFNDR_SETTINGS_OPTION, array( 'control_bg' => '#123' ) );
		$this->assertStringContainsString( '#123 !important', gwc_lfndr_appearance_css() );
	}

	public function test_var_mode_declarations_never_carry_important(): void {
		// A theme that sets --lfndr-accent for itself, per the README's own
		// example, is meant to win — !important here would break that.
		update_option( GWC_LFNDR_SETTINGS_OPTION, array( 'accent_color' => '#123' ) );
		$this->assertStringNotContainsString( 'important', gwc_lfndr_appearance_css() );
	}

	public function test_control_and_control_active_use_different_selectors(): void {
		update_option(
			GWC_LFNDR_SETTINGS_OPTION,
			array(
				'control_bg'        => '#aaa',
				'control_active_bg' => '#bbb',
			)
		);
		$fields = gwc_lfndr_appearance_fields();
		$this->assertNotSame( $fields['control_bg']['selector'], $fields['control_active_bg']['selector'] );

		$css = gwc_lfndr_appearance_css();
		$this->assertStringContainsString( $fields['control_bg']['selector'] . '{background-color:#aaa !important}', $css );
		$this->assertStringContainsString( $fields['control_active_bg']['selector'] . '{background-color:#bbb !important}', $css );
	}

	public function test_a_selector_never_contains_unsanitized_input(): void {
		/* The selector strings come from the registry, not from the option, so an
		 * attacker who controls stored settings can only reach a value inside an
		 * already-fixed selector. Asserting a literal selector here would only
		 * restate the registry — and would fail the next time a selector legitimately
		 * gained a class — so this feeds a hostile value in and checks what escapes. */
		update_option(
			GWC_LFNDR_SETTINGS_OPTION,
			array( 'badge_bg' => '#fff}body{display:none' )
		);
		// Rejected outright rather than escaped into the block: a value that is
		// not a color never reaches the stylesheet at all.
		$this->assertSame( '', gwc_lfndr_appearance_css() );
	}

	public function test_a_rule_mode_value_lands_inside_the_registrys_own_selector(): void {
		// One update_option per test, deliberately: gwc_lfndr_setting() memoizes for
		// the request and the test stub does not fire the invalidation hook a
		// real update_option() would, so a second write in the same test is not
		// seen. The memo is covered on its own in gwc_lfndr_settings_cache()'s tests.
		update_option( GWC_LFNDR_SETTINGS_OPTION, array( 'badge_bg' => '#fff' ) );
		$fields = gwc_lfndr_appearance_fields();
		$this->assertSame(
			$fields['badge_bg']['selector'] . '{background-color:#fff !important}',
			gwc_lfndr_appearance_css()
		);
	}

	public function test_var_and_rule_mode_settings_can_coexist(): void {
		update_option(
			GWC_LFNDR_SETTINGS_OPTION,
			array(
				'accent_color' => '#000',
				'badge_bg'     => '#eee',
			)
		);
		$css    = gwc_lfndr_appearance_css();
		$fields = gwc_lfndr_appearance_fields();
		$this->assertStringStartsWith( '.lfndr{--lfndr-accent:#000}', $css );
		$this->assertStringEndsWith(
			$fields['badge_bg']['selector'] . '{background-color:#eee !important}',
			$css
		);
	}

	/* ── Every new field is wired the same way as the originals ──────────── */

	public function test_every_rule_mode_field_sanitizes_as_a_colour(): void {
		$rule_mode_keys = array(
			'control_bg',
			'control_text',
			'control_active_bg',
			'control_active_text',
			'card_bg',
			'card_text',
			'card_selected_bg',
			'card_selected_text',
			'badge_bg',
			'badge_text',
		);
		foreach ( gwc_lfndr_appearance_fields() as $key => $field ) {
			if ( 'rule' === ( $field['mode'] ?? 'var' ) ) {
				$this->assertContains( $key, $rule_mode_keys, "Unexpected rule-mode field: $key" );
				$this->assertSame( 'color', $field['type'] );
			}
		}
	}

	public function test_every_field_belongs_to_a_registered_section(): void {
		$sections = array_keys( gwc_lfndr_appearance_sections() );
		foreach ( gwc_lfndr_appearance_fields() as $key => $field ) {
			$this->assertContains( $field['section'], $sections, "Field \"$key\" has no matching section." );
		}
	}

	/* ── Presets ──────────────────────────────────────────────────────────── */

	public function test_applying_a_preset_writes_its_values(): void {
		$out      = gwc_lfndr_sanitize_settings( array( '_apply_preset' => 'night' ) );
		$expected = gwc_lfndr_style_presets()['night']['values'];
		foreach ( $expected as $key => $value ) {
			$this->assertSame( $value, $out[ $key ], "preset key {$key}" );
		}
	}

	public function test_a_preset_clears_fields_it_does_not_set(): void {
		/* The light sets leave the finder background blank on purpose. Merging
		 * rather than resetting would strand a previous dark canvas under one
		 * of them, which is a broken finder produced by two valid choices. */
		update_option( GWC_LFNDR_SETTINGS_OPTION, array( 'finder_bg' => '#0f172a' ) );
		$out = gwc_lfndr_sanitize_settings( array( '_apply_preset' => 'ink' ) );
		$this->assertSame( '', $out['finder_bg'] );
	}

	public function test_the_preset_action_is_never_stored(): void {
		$out = gwc_lfndr_sanitize_settings( array( '_apply_preset' => 'ink' ) );
		$this->assertArrayNotHasKey( '_apply_preset', $out );
	}

	public function test_an_unknown_preset_changes_nothing(): void {
		update_option( GWC_LFNDR_SETTINGS_OPTION, array( 'accent_color' => '#123456' ) );
		$out = gwc_lfndr_sanitize_settings( array( '_apply_preset' => 'not-a-preset' ) );
		$this->assertSame( '#123456', $out['accent_color'] );
	}

	/**
	 * This replaces a test asserting that a typed value beat the preset saved
	 * alongside it. That contract could not be honored and should never have
	 * been written: the form submits every appearance box on every save, holding
	 * whatever it was rendered with, so "typed" and "left alone" are identical
	 * on the wire. Letting boxes win meant the stale ones won, which silently
	 * cancelled every preset the moment it was chosen.
	 *
	 * The test passed anyway, because it submitted a preset key and exactly one
	 * field — a payload no browser produces. See PresetSaveTest, which submits
	 * what the form really sends.
	 *
	 * The workable contract is that moving the radio picks a set, and leaving it
	 * put while editing a box tunes that set into Custom.
	 */
	public function test_choosing_a_different_preset_overrides_the_submitted_boxes(): void {
		update_option( GWC_LFNDR_SETTINGS_OPTION, array( 'accent_color' => '#123456' ) );
		gwc_lfndr_settings_cache( null, true );

		$out = gwc_lfndr_sanitize_settings(
			array(
				'_apply_preset' => 'night',
				'accent_color'  => '#abcdef',
			)
		);

		$this->assertSame( '#38bdf8', $out['accent_color'], 'Night was chosen, so Night decides the accent.' );
		$this->assertSame( '#0f172a', $out['finder_bg'], 'the rest of the preset still applies' );
	}

	public function test_editing_a_box_without_moving_the_radio_wins(): void {
		// Land on Night, so the radio in the next save is not a change.
		update_option( GWC_LFNDR_SETTINGS_OPTION, gwc_lfndr_sanitize_settings( array( '_apply_preset' => 'night' ) ) );
		gwc_lfndr_settings_cache( null, true );

		$out = gwc_lfndr_sanitize_settings(
			array(
				'_apply_preset' => 'night',
				'accent_color'  => '#abcdef',
			)
		);

		$this->assertSame( '#abcdef', $out['accent_color'], 'An edit with the radio unmoved must stick.' );
	}

	public function test_every_preset_only_names_real_fields(): void {
		$known = array_keys( gwc_lfndr_appearance_fields() );
		foreach ( gwc_lfndr_style_presets() as $key => $preset ) {
			foreach ( array_keys( $preset['values'] ) as $field ) {
				$this->assertContains( $field, $known, "preset {$key} names unknown field {$field}" );
			}
		}
	}

	public function test_every_preset_survives_its_own_sanitizer(): void {
		// A preset that stored a value the sanitizer then rejected would apply
		// as blank and look like the preset simply did nothing.
		foreach ( gwc_lfndr_style_presets() as $key => $preset ) {
			$out = gwc_lfndr_sanitize_settings( array( '_apply_preset' => $key ) );
			foreach ( $preset['values'] as $field => $value ) {
				$this->assertSame( $value, $out[ $field ], "preset {$key}, field {$field}" );
			}
		}
	}

	public function test_every_preset_with_a_background_also_sets_an_inset(): void {
		/* A canvas without padding runs the search box and the cards flat into
		 * the edge of the color, which makes a deliberate background look like
		 * a mistake. The two are one decision, so they travel together. */
		foreach ( gwc_lfndr_style_presets() as $key => $preset ) {
			$bg = $preset['values']['finder_bg'] ?? '';
			if ( '' === $bg ) {
				continue;
			}
			$this->assertNotSame(
				'',
				$preset['values']['finder_padding'] ?? '',
				"preset {$key} sets a background but no padding"
			);
		}
	}

	public function test_every_preset_with_a_background_also_sets_its_text(): void {
		// Same pairing: text inside the finder inherits the page's color, so a
		// canvas without ink is dark-on-dark waiting to happen.
		foreach ( gwc_lfndr_style_presets() as $key => $preset ) {
			$bg = $preset['values']['finder_bg'] ?? '';
			if ( '' === $bg ) {
				continue;
			}
			$this->assertNotSame(
				'',
				$preset['values']['finder_text'] ?? '',
				"preset {$key} sets a background but no text color"
			);
		}
	}

	public function test_current_preset_is_derived_from_the_values(): void {
		update_option( GWC_LFNDR_SETTINGS_OPTION, gwc_lfndr_sanitize_settings( array( '_apply_preset' => 'night' ) ) );
		gwc_lfndr_reset_settings_cache();
		$this->assertSame( 'night', gwc_lfndr_current_preset() );
	}

	public function test_editing_one_value_makes_it_custom(): void {
		/* The reason the chosen key is not stored: it would go on claiming the
		 * site was on a preset it no longer matches. */
		$values                 = gwc_lfndr_sanitize_settings( array( '_apply_preset' => 'night' ) );
		$values['accent_color'] = '#abcdef';
		update_option( GWC_LFNDR_SETTINGS_OPTION, $values );
		gwc_lfndr_reset_settings_cache();
		$this->assertSame( '', gwc_lfndr_current_preset() );
	}

	public function test_a_stray_value_from_an_earlier_set_is_not_a_match(): void {
		// Ink leaves the canvas blank; a dark canvas left over from Night means
		// the site is not on Ink however close the rest looks.
		$values              = gwc_lfndr_sanitize_settings( array( '_apply_preset' => 'ink' ) );
		$values['finder_bg'] = '#0f172a';
		update_option( GWC_LFNDR_SETTINGS_OPTION, $values );
		gwc_lfndr_reset_settings_cache();
		$this->assertSame( '', gwc_lfndr_current_preset() );
	}

	public function test_defaults_are_custom_rather_than_a_preset(): void {
		update_option( GWC_LFNDR_SETTINGS_OPTION, array() );
		gwc_lfndr_reset_settings_cache();
		$this->assertSame( '', gwc_lfndr_current_preset() );
	}

	public function test_every_preset_round_trips_through_derivation(): void {
		// Apply each one and confirm the screen would report it back. Catches a
		// preset whose values the sanitizer alters on the way in.
		foreach ( array_keys( gwc_lfndr_style_presets() ) as $key ) {
			update_option( GWC_LFNDR_SETTINGS_OPTION, gwc_lfndr_sanitize_settings( array( '_apply_preset' => $key ) ) );
			gwc_lfndr_reset_settings_cache();
			$this->assertSame( $key, gwc_lfndr_current_preset(), "preset {$key}" );
		}
	}

	/* ── The settings that had no UI ──────────────────────────────────────── */

	public function test_option_fields_cover_every_setting_without_an_appearance_field(): void {
		/* The point of the Behavior and Advanced tabs: 19 settings the plugin
		 * read at runtime that nothing in wp-admin could set. If this fails,
		 * a setting has been added with nowhere to change it. */
		$known   = array_merge( array_keys( gwc_lfndr_appearance_fields() ), array_keys( gwc_lfndr_option_fields() ) );
		$missing = array_diff( array_keys( gwc_lfndr_setting_defaults() ), $known );
		$this->assertSame( array(), array_values( $missing ), 'settings with no UI: ' . implode( ', ', $missing ) );
	}

	public function test_numbers_are_clamped_to_their_range(): void {
		$out = gwc_lfndr_sanitize_settings(
			array(
				'_tab_behavior' => '1',
				'zoom'          => '999',
				'center_lat'    => '500',
				'page_size'     => '-4',
			)
		);
		$this->assertSame( 19, $out['zoom'] );
		$this->assertSame( 90.0, $out['center_lat'] );
		$this->assertSame( 0, $out['page_size'] );
	}

	public function test_a_select_never_accepts_a_value_off_its_list(): void {
		$out = gwc_lfndr_sanitize_settings(
			array(
				'_tab_behavior' => '1',
				'units'         => 'parsecs',
			)
		);
		$this->assertSame( '', $out['units'] );
	}

	public function test_an_unchecked_box_is_saved_as_off(): void {
		/* A checkbox posts nothing when unchecked, so the tab marker is what
		 * separates "turned off" from "not on this screen". Without it,
		 * switching anything off would silently fail. */
		update_option( GWC_LFNDR_SETTINGS_OPTION, array( 'near_me' => true ) );
		$out = gwc_lfndr_sanitize_settings( array( '_tab_behavior' => '1' ) );
		$this->assertFalse( $out['near_me'] );
	}

	/* ── The lookup contact address ─────────────────────────────────────── */

	public function test_the_lookup_contact_defaults_to_the_admin_email(): void {
		// Pre-filled rather than blank, so the field shows the address that will
		// actually be sent instead of an empty box that still identifies the
		// site to the lookup service.
		update_option( 'admin_email', 'admin@charity.org' );

		$this->assertSame( 'admin@charity.org', gwc_lfndr_setting_defaults()['geo_email'] );
	}

	public function test_a_saved_contact_address_does_not_follow_the_admin_email(): void {
		// The default is a starting value, not a mirror. Once somebody has
		// chosen an address, changing the site's admin email under Settings →
		// General must not quietly change who the lookup service is told is
		// asking.
		update_option( 'admin_email', 'admin@charity.org' );
		update_option(
			GWC_LFNDR_SETTINGS_OPTION,
			gwc_lfndr_sanitize_settings(
				array(
					'_tab_advanced' => '1',
					'geo_email'     => 'locations@charity.org',
				)
			)
		);
		gwc_lfndr_settings_cache( null, true );

		update_option( 'admin_email', 'somebody-else@charity.org' );
		gwc_lfndr_settings_cache( null, true );

		$this->assertSame( 'locations@charity.org', gwc_lfndr_setting( 'geo_email' ) );
	}

	public function test_saving_one_tab_leaves_another_tabs_values_alone(): void {
		update_option(
			GWC_LFNDR_SETTINGS_OPTION,
			array(
				'near_me'   => true,
				'geo_email' => 'a@example.com',
			)
		);
		$out = gwc_lfndr_sanitize_settings(
			array(
				'_tab_advanced' => '1',
				'geo_email'     => 'b@example.com',
			)
		);
		$this->assertSame( 'b@example.com', $out['geo_email'] );
		$this->assertTrue( $out['near_me'], 'Behavior was not on screen, so its checkbox must not be cleared.' );
	}

	public function test_tile_attribution_keeps_its_link_but_nothing_that_acts(): void {
		// Attribution is a license term and is nearly always a link, so it
		// cannot be stripped — but it is still author input.
		$out = gwc_lfndr_sanitize_settings(
			array(
				'_tab_advanced' => '1',
				'tile_attr'     => '<a href="https://x.test">Tiles</a><script>alert(1)</script>',
			)
		);
		$this->assertStringContainsString( '<a href="https://x.test">Tiles</a>', $out['tile_attr'] );
		$this->assertStringNotContainsString( '<script', $out['tile_attr'] );
	}

	public function test_the_tab_markers_are_never_stored(): void {
		$out = gwc_lfndr_sanitize_settings(
			array(
				'_tab_behavior' => '1',
				'page_size'     => '10',
			)
		);
		foreach ( array_keys( gwc_lfndr_admin_tabs() ) as $tab ) {
			$this->assertArrayNotHasKey( '_tab_' . $tab, $out );
		}
	}
}
