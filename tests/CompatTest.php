<?php
/**
 * The declared PHP floor, enforced.
 *
 * "Requires PHP: 7.4" in the plugin header is a promise a host reads when it
 * decides whether this plugin blocks an upgrade — and one WordPress itself
 * enforces before activating. Breaking it does not degrade anything: the site
 * white-screens on the first page that reaches the call.
 *
 * php -l cannot catch this. These are functions, not syntax, so a file using
 * str_contains() parses perfectly on 7.4 and fatals only when the line runs —
 * which for the asset gate means any page containing a shortcode block. That
 * exact bug shipped in inc/enqueue.php and was found by reading, not by tooling,
 * so this scans for it instead.
 *
 * @package GroundworkCommonLocationFinder
 */

class CompatTest extends PHPUnit\Framework\TestCase {

	/**
	 * Functions and syntax newer than the declared floor.
	 *
	 * @return array<string, string>
	 */
	private function too_new(): array {
		return array(
			'str_contains('    => 'PHP 8.0',
			'str_starts_with(' => 'PHP 8.0',
			'str_ends_with('   => 'PHP 8.0',
			'array_is_list('   => 'PHP 8.1',
			'enum '            => 'PHP 8.1',
			'readonly '        => 'PHP 8.1',
			'?->'              => 'PHP 8.0',
		);
	}

	/**
	 * Every PHP file that ships, which is what the floor applies to.
	 *
	 * @return array<int, string>
	 */
	private function shipped_files(): array {
		$root = dirname( __DIR__ );

		/* uninstall.php belongs here as much as anything in inc/. WordPress runs
		 * it in a bare process with the plugin unloaded, so a fatal there is
		 * both likelier to go unnoticed and worse when it happens: the cleanup
		 * simply does not occur. */
		$files = array( $root . '/groundwork-common-location-finder.php', $root . '/uninstall.php' );

		foreach ( array( '/inc', '/blocks' ) as $dir ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . $dir ) );
			foreach ( $iterator as $file ) {
				if ( 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}

		return $files;
	}

	public function test_shipping_code_stays_within_the_declared_php_floor(): void {
		$offences = array();

		foreach ( $this->shipped_files() as $path ) {
			$source = (string) file_get_contents( $path );

			/* Comments would otherwise trip this — several explain why these are
			 * avoided, and a rule that fires on its own documentation gets
			 * switched off rather than fixed. */
			$code = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $source );

			foreach ( $this->too_new() as $needle => $version ) {
				if ( false !== strpos( $code, $needle ) ) {
					$offences[] = sprintf( '%s uses %s (%s)', basename( $path ), rtrim( $needle, '(' ), $version );
				}
			}
		}

		$this->assertSame( array(), $offences, implode( '; ', $offences ) );
	}

	public function test_the_header_still_declares_the_floor_this_test_enforces(): void {
		// If the floor is raised deliberately, this is the reminder to raise it
		// in too_new() as well rather than leaving the scan checking nothing.
		$header = (string) file_get_contents( dirname( __DIR__ ) . '/groundwork-common-location-finder.php' );
		$this->assertMatchesRegularExpression( '/Requires PHP:\s*7\.4/', $header );
	}
}
