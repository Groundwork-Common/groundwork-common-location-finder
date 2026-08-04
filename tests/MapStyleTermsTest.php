<?php
/**
 * Third-party tile providers must state their terms.
 *
 * CARTO's free basemap tier is non-commercial. Nothing enforces that at the
 * network level — the tiles load perfectly on a commercial site — so the only
 * thing standing between a site owner and using them outside those terms is
 * whether the settings screen told them. That makes the disclosure a feature,
 * and features that live only in a registry entry get dropped the next time
 * somebody adds a style by copying the one above it.
 *
 * @package LocationFinder
 */

class MapStyleTermsTest extends PHPUnit\Framework\TestCase {

	/**
	 * Hosts that impose conditions worth surfacing, and why.
	 *
	 * @return array<string, string>
	 */
	private function restricted_hosts(): array {
		return array(
			'cartocdn.com'         => 'CARTO free basemaps are non-commercial',
			'tile.openstreetmap.org' => 'OSMF tile usage policy limits heavy use',
		);
	}

	public function test_every_third_party_tile_style_states_its_terms(): void {
		$missing = array();

		foreach ( lfndr_map_styles() as $key => $style ) {
			foreach ( $this->restricted_hosts() as $host => $why ) {
				if ( false === strpos( $style['url'], $host ) ) {
					continue;
				}
				if ( '' === trim( (string) ( $style['terms'] ?? '' ) ) ) {
					$missing[] = sprintf( '"%s" points at %s but states no terms (%s)', $key, $host, $why );
				}
				if ( '' === trim( (string) ( $style['terms_url'] ?? '' ) ) ) {
					$missing[] = sprintf( '"%s" states terms but links nowhere to read them', $key );
				}
			}
		}

		$this->assertSame( array(), $missing, implode( '; ', $missing ) );
	}

	/**
	 * The notice is only useful if it names the restriction. A style that said
	 * "see the provider's website" would pass the test above while telling the
	 * reader nothing they could act on.
	 */
	public function test_the_carto_notice_actually_says_non_commercial(): void {
		foreach ( lfndr_map_styles() as $key => $style ) {
			if ( false === strpos( $style['url'], 'cartocdn.com' ) ) {
				continue;
			}
			$this->assertMatchesRegularExpression(
				'/non-commercial/i',
				(string) $style['terms'],
				sprintf( 'Style "%s" uses CARTO but its terms never mention the non-commercial limit.', $key )
			);
		}
	}

	/**
	 * Self-hosted and paid providers must NOT inherit a warning that does not
	 * apply to them — a notice that shows up everywhere is one people stop
	 * reading.
	 */
	public function test_custom_carries_no_third_party_terms(): void {
		$styles = lfndr_map_styles();
		$this->assertSame( '', (string) ( $styles['custom']['terms'] ?? '' ) );
	}
}
