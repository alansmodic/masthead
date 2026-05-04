<?php
/**
 * Tests for scheduled publishing behavior.
 */

class Masthead_Scheduled_Publishing_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Masthead_Settings::set_default_features();
		Masthead_Staged_Revisions::get_instance();
	}

	public function test_reschedule_clears_prior_event() {
		$post_id = self::factory()->post->create( array(
			'post_status' => 'publish',
		) );

		$revision_id = Masthead_Staged_Revisions::create(
			$post_id,
			array(
				'title'   => 'First',
				'content' => 'First content',
			)
		);

		$this->assertIsInt( $revision_id );

		$first_date  = gmdate( 'c', time() + 3600 );
		$second_date = gmdate( 'c', time() + 7200 );

		Masthead_Staged_Revisions::schedule( $revision_id, $first_date );
		Masthead_Staged_Revisions::schedule( $revision_id, $second_date );

		$events = $this->get_events_for_revision( $revision_id );
		$this->assertCount( 1, $events, 'Expected only one scheduled publish event for revision.' );
	}

	private function get_events_for_revision( $revision_id ) {
		$events = array();
		$all = function_exists( 'wp_get_scheduled_events' ) ? wp_get_scheduled_events() : array();

		foreach ( $all as $timestamp => $hooks ) {
			if ( empty( $hooks['masthead_publish_staged'] ) ) {
				continue;
			}

			foreach ( $hooks['masthead_publish_staged'] as $event ) {
				if ( isset( $event['args'][0] ) && (int) $event['args'][0] === (int) $revision_id ) {
					$events[] = array(
						'timestamp' => $timestamp,
						'event'     => $event,
					);
				}
			}
		}

		return $events;
	}
}
