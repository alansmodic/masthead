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

	public function test_create_fires_staged_revision_submitted_hook() {
		$post_id = self::factory()->post->create( array(
			'post_status' => 'publish',
		) );

		$seen = array();
		$callback = function ( $revision_id, $revision_post, $parent_id, $meta_data ) use ( &$seen ) {
			$seen = compact( 'revision_id', 'revision_post', 'parent_id', 'meta_data' );
		};

		add_action( 'masthead_staged_revision_submitted', $callback, 10, 4 );

		$revision_id = Masthead_Staged_Revisions::create(
			$post_id,
			array(
				'title'   => 'Submitted title',
				'content' => 'Submitted content',
			),
			array(
				'notes' => 'Ready for review',
			)
		);

		remove_action( 'masthead_staged_revision_submitted', $callback, 10 );

		$this->assertIsInt( $revision_id );
		$this->assertSame( $revision_id, $seen['revision_id'] );
		$this->assertSame( $post_id, $seen['parent_id'] );
		$this->assertSame( 'Ready for review', $seen['meta_data']['notes'] );
		$this->assertInstanceOf( WP_Post::class, $seen['revision_post'] );
	}

	public function test_publish_can_be_blocked_by_integration_filter() {
		$post_id = self::factory()->post->create( array(
			'post_status'  => 'publish',
			'post_title'   => 'Original title',
			'post_content' => 'Original content',
		) );

		$revision_id = Masthead_Staged_Revisions::create(
			$post_id,
			array(
				'title'   => 'Blocked title',
				'content' => 'Blocked content',
			)
		);

		$callback = function () {
			return new WP_Error( 'blocked_for_test', 'Blocked for test.' );
		};

		add_filter( 'masthead_can_publish_staged_revision', $callback );
		$result = Masthead_Staged_Revisions::publish( $revision_id );
		remove_filter( 'masthead_can_publish_staged_revision', $callback );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'blocked_for_test', $result->get_error_code() );
		$this->assertSame( 'Original title', get_post( $post_id )->post_title );
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
