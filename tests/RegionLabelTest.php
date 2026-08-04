<?php
/**
 * The optional accessible name on the finder wrapper.
 *
 * The rule worth protecting is the negative one: no label means no landmark.
 * `role="region"` without an accessible name adds an entry to the landmark list
 * that announces as "region" and conveys nothing, and a page with two finders
 * gets two of them — strictly worse for the people the role exists to help than
 * leaving it a plain div. So the role and the label are one decision and must
 * never be able to drift apart.
 *
 * @package LocationFinder
 */

class RegionLabelTest extends PHPUnit\Framework\TestCase {

	private function wrapper( array $atts ): string {
		$html = lfndr_render_finder( $atts );
		preg_match( '/<div id="[^"]*"[^>]*>/', $html, $m );
		return isset( $m[0] ) ? (string) preg_replace( '/\s+/', ' ', $m[0] ) : '';
	}

	public function test_a_label_produces_a_named_region(): void {
		$tag = $this->wrapper( array( 'label' => 'Food pantries' ) );

		$this->assertStringContainsString( 'role="region"', $tag );
		$this->assertStringContainsString( 'aria-label="Food pantries"', $tag );
	}

	public function test_no_label_produces_no_landmark_at_all(): void {
		$tag = $this->wrapper( array() );

		$this->assertStringNotContainsString( 'role=', $tag );
		$this->assertStringNotContainsString( 'aria-label', $tag );
	}

	public function test_a_whitespace_label_is_not_a_label(): void {
		// Trimmed, so a stray space cannot produce a nameless landmark.
		$tag = $this->wrapper( array( 'label' => '   ' ) );

		$this->assertStringNotContainsString( 'role=', $tag );
	}

	public function test_the_label_is_escaped(): void {
		$tag = $this->wrapper( array( 'label' => '" onmouseover="alert(1)' ) );

		$this->assertStringNotContainsString( 'onmouseover="alert', $tag );
		$this->assertStringContainsString( '&quot;', $tag );
	}

	public function test_the_block_exposes_the_same_attribute(): void {
		// The shortcode and the block must offer the same capability; a block
		// user cannot edit shortcode attributes to get at it.
		$json = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/blocks/finder/block.json' ), true );

		$this->assertArrayHasKey( 'label', $json['attributes'] );
		$this->assertSame( 'string', $json['attributes']['label']['type'] );
	}
}
