<?php
/**
 * Tests for publication checklist enforcement.
 */

class Masthead_Publication_Checklist_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Masthead_Settings::set_default_features();
		Masthead_Publication_Checklist::get_instance();
	}

	public function test_rest_publish_requires_checklist() {
		$post_id = self::factory()->post->create( array(
			'post_status' => 'publish',
		) );

		delete_post_meta( $post_id, '_editorial_checklist_bypassed' );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'id', $post_id );
		$request->set_param( 'status', 'publish' );

		$prepared = get_post( $post_id );
		$prepared->post_status = 'publish';

		$checklist = Masthead_Publication_Checklist::get_instance();
		$result = $checklist->enforce_checklist_on_rest( $prepared, $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'masthead_checklist_required', $result->get_error_code() );
	}
}
