<?php
/**
 * Tests for word-level diffs guard.
 */

class Masthead_Word_Level_Diffs_Test extends WP_UnitTestCase {

	public function test_large_diff_short_circuits() {
		$word_count = 2100;
		$from = $this->make_word_string( $word_count, 'alpha' );
		$to   = $this->make_word_string( $word_count, 'beta' );

		$diff = Masthead_Word_Level_Diffs::generate_text_diff( $from, $to );

		$this->assertArrayHasKey( 'too_large', $diff );
		$this->assertTrue( (bool) $diff['too_large'] );
		$this->assertTrue( $diff['has_changes'] );
	}

	private function make_word_string( $count, $word ) {
		return trim( str_repeat( $word . ' ', $count ) );
	}
}
