<?php
/**
 * The version number, in the four places that must agree.
 *
 * They serve different masters, which is why they drift:
 *
 *   Version:      the plugin header. What WordPress shows on the Plugins
 *                 screen and compares to decide an update is available.
 *   LFNDR_VERSION the cache buster on every enqueued script and style. Left
 *                 behind, returning visitors keep the previous CSS and JS —
 *                 the release looks correct to anyone testing in a fresh
 *                 browser and does nothing for everyone else.
 *   Stable tag:   what WordPress.org actually serves. Point it at a version
 *                 that was never tagged and the directory serves trunk, or
 *                 nothing.
 *   Changelog:    the only one a human reads, and the one that quietly stops
 *                 matching what shipped.
 *
 * A release touches all four by hand, so this exists to make forgetting one
 * fail loudly instead of shipping.
 *
 * @package LocationFinder
 */

class VersionTest extends PHPUnit\Framework\TestCase {

	private function plugin_file(): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/groundwork-common-location-finder.php' );
	}

	private function readme(): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
	}

	private function header_version(): string {
		preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $this->plugin_file(), $m );
		return $m[1] ?? '';
	}

	public function test_the_header_declares_a_semver_version(): void {
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $this->header_version() );
	}

	public function test_the_constant_matches_the_header(): void {
		preg_match( "/const LFNDR_VERSION\s*=\s*'([^']+)'/", $this->plugin_file(), $m );

		$this->assertSame(
			$this->header_version(),
			$m[1] ?? '',
			'LFNDR_VERSION is the asset cache buster. Behind the header, returning visitors keep the old CSS and JS.'
		);
	}

	public function test_the_stable_tag_matches_the_header(): void {
		preg_match( '/^Stable tag:\s*(\S+)/m', $this->readme(), $m );

		$this->assertSame(
			$this->header_version(),
			$m[1] ?? '',
			'Stable tag is what WordPress.org serves. It must name a version that exists.'
		);
	}

	public function test_the_changelog_has_an_entry_for_this_version(): void {
		$version = $this->header_version();
		$readme  = $this->readme();

		$changelog = (string) strstr( $readme, '== Changelog ==' );
		$this->assertNotSame( '', $changelog, 'readme.txt has no Changelog section.' );

		$this->assertStringContainsString(
			'= ' . $version . ' =',
			$changelog,
			sprintf( 'The changelog never mentions %s, so the release notes describe something else.', $version )
		);
	}

	public function test_the_upgrade_notice_has_an_entry_for_this_version(): void {
		$notice = (string) strstr( $this->readme(), '== Upgrade Notice ==' );

		$this->assertStringContainsString( '= ' . $this->header_version() . ' =', $notice );
	}

	/**
	 * The schema version is deliberately NOT tied to the plugin version — it
	 * tracks the stored data shape and only moves when a migration is written.
	 * This asserts the decoupling on purpose, so nobody "helpfully" syncs them
	 * and triggers a migration run on every unrelated release.
	 */
	public function test_the_schema_version_is_independent_of_the_plugin_version(): void {
		preg_match( '/const LFNDR_SCHEMA_VERSION\s*=\s*(\d+)/', $this->plugin_file(), $m );

		$this->assertMatchesRegularExpression( '/^\d+$/', $m[1] ?? '', 'Schema version should be a plain integer, not a semver string.' );
	}
}
