<?php
/**
 * The tile consent gate: when it applies, and who it names.
 *
 * Tiles are requested by the visitor's browser, not by the site, so once a map
 * renders the provider already holds that visitor's IP address and nothing
 * server-side can undo it. The gate is the only point of control, which makes
 * "does the gate apply here" a correctness question rather than a cosmetic one.
 *
 * The failure that matters most is the quiet one: showing the gate in front of
 * self-hosted tiles. It protects nobody, and it teaches people to click through
 * the gate that does matter.
 *
 * @package GroundworkCommonLocationFinder
 */

class TileConsentTest extends PHPUnit\Framework\TestCase {

	/**
	 * Apply settings for the duration of one assertion.
	 *
	 * @param array<string, mixed> $settings Settings to apply.
	 */
	private function with_settings( array $settings ): void {
		update_option( 'gwc_lfndr_settings', $settings );
		gwc_lfndr_settings_cache( null, true );
	}

	public function test_the_shipped_default_is_a_third_party(): void {
		// If this ever flips, the gate silently stops applying to a fresh install.
		$this->with_settings( array( 'map_style' => 'osm' ) );
		$this->assertTrue( gwc_lfndr_tiles_are_third_party() );
	}

	public function test_every_bundled_remote_style_counts_as_third_party(): void {
		foreach ( gwc_lfndr_map_styles() as $key => $style ) {
			if ( '' === $style['url'] ) {
				continue; // 'custom' resolves from settings, covered separately.
			}
			$this->with_settings( array( 'map_style' => $key ) );
			$this->assertTrue(
				gwc_lfndr_tiles_are_third_party(),
				sprintf( 'Style "%s" points off-site but is not treated as a third party.', $key )
			);
		}
	}

	public function test_self_hosted_tiles_are_not_a_third_party(): void {
		$this->with_settings(
			array(
				'map_style' => 'custom',
				'tile_url'  => 'https://example.test/wp-content/uploads/tiles/{z}/{x}/{y}.png',
			)
		);
		$this->assertFalse( gwc_lfndr_tiles_are_third_party() );
	}

	public function test_a_relative_tile_path_is_not_a_third_party(): void {
		$this->with_settings(
			array(
				'map_style' => 'custom',
				'tile_url'  => '/wp-content/uploads/tiles/{z}/{x}/{y}.png',
			)
		);
		$this->assertFalse( gwc_lfndr_tiles_are_third_party() );
	}

	public function test_another_vendor_is_a_third_party_even_when_custom(): void {
		$this->with_settings(
			array(
				'map_style' => 'custom',
				'tile_url'  => 'https://tiles.vendor.io/{z}/{x}/{y}.png',
			)
		);
		$this->assertTrue( gwc_lfndr_tiles_are_third_party() );
	}

	/**
	 * Tile URLs carry an {s} subdomain placeholder. Left in, the gate would name
	 * "{s}.basemaps.cartocdn.com" — a host that does not resolve, in a sentence
	 * asking someone to make a decision about it.
	 */
	public function test_the_named_host_is_a_real_hostname(): void {
		$this->with_settings( array( 'map_style' => 'dark' ) );
		$host = gwc_lfndr_tile_host();

		$this->assertSame( 'basemaps.cartocdn.com', $host );
		$this->assertStringNotContainsString( '{', $host );
	}

	public function test_the_setting_defaults_to_on(): void {
		$this->with_settings( array() );
		$this->assertTrue( (bool) gwc_lfndr_setting( 'tile_consent' ) );
	}
}
