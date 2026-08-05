<?php
/**
 * The vendored Leaflet and the version WordPress is told about must agree.
 *
 * The file inc/enqueue.php passes a literal version string to wp_register_script() and
 * wp_register_style(). That string is the cache buster: it is what appends
 * ?ver=1.9.4 to the URL, and it is the only thing that makes a browser holding
 * the previous bundle go and fetch the new one.
 *
 * Nothing derives it from the file, so updating Leaflet without updating the
 * string leaves every returning visitor on the old cached copy — the update
 * appears to have worked for whoever tested it in a fresh browser, and silently
 * did nothing for everyone else. That is the failure this test exists for, and
 * it is the step most easily forgotten in a hand update with no build step.
 *
 * @package LocationFinder
 */

class LeafletVersionTest extends PHPUnit\Framework\TestCase {

	/**
	 * What the bundle says about itself at runtime.
	 */
	private function bundled_version(): string {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/leaflet/leaflet.js' );

		// Leaflet's own exported version, not the banner comment — this is the
		// value the library reports, so it cannot disagree with what ships.
		preg_match( '/t\.version="(\d+\.\d+\.\d+)"/', $js, $m );

		return $m[1] ?? '';
	}

	public function test_the_bundle_declares_a_version(): void {
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $this->bundled_version() );
	}

	public function test_enqueue_registers_the_version_that_is_actually_bundled(): void {
		$bundled = $this->bundled_version();
		$enqueue = (string) file_get_contents( dirname( __DIR__ ) . '/inc/enqueue.php' );

		preg_match_all( "/'leaflet',\s*LFNDR_URL \. 'assets\/leaflet\/leaflet\.(?:css|js)',\s*array\([^)]*\),\s*'([^']+)'/", $enqueue, $m );

		$this->assertCount( 2, $m[1], 'Expected both the style and the script to register a version.' );

		foreach ( $m[1] as $registered ) {
			$this->assertSame(
				$bundled,
				$registered,
				"inc/enqueue.php registers Leaflet as {$registered} but assets/leaflet/leaflet.js is {$bundled}. "
					. 'Returning visitors would keep the cached copy.'
			);
		}
	}

	public function test_the_readme_names_the_version_that_ships(): void {
		// The readme tells reviewers and users which release this is, and points
		// at its source. A stale number there is a provenance claim that is false.
		$readme = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );

		$this->assertStringContainsString( 'Leaflet ' . $this->bundled_version(), $readme );
	}

	public function test_the_license_ships_alongside_the_code(): void {
		// BSD-2 requires the notice travel with the redistribution. Losing this
		// file is a licensing failure, not a tidy-up.
		$this->assertFileExists( dirname( __DIR__ ) . '/assets/leaflet/LICENSE' );
	}
}
