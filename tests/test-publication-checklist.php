<?php
/**
 * Tests for publication checklist enforcement.
 */

class Masthead_Publication_Checklist_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Masthead_Settings::set_default_features();
		Masthead_Publication_Checklist::get_instance();
		Masthead_Smart_Checklist::get_instance();
	}

	public function test_rest_publish_requires_checklist() {
		$post_id = self::factory()->post->create( array(
			'post_status' => 'publish',
		) );

		delete_post_meta( $post_id, '_masthead_checklist_bypassed' );

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

	public function test_contextual_checklist_filter_participates_in_validation() {
		$post_id = self::factory()->post->create( array(
			'post_status' => 'publish',
		) );

		$callback = function ( $items, $filtered_post_id ) use ( $post_id ) {
			if ( (int) $filtered_post_id === (int) $post_id ) {
				$items[] = array(
					'label'    => 'AI editorial review has passed',
					'required' => true,
				);
			}

			return $items;
		};

		add_filter( 'masthead_publication_checklist_items', $callback, 10, 2 );

		$checklist = Masthead_Publication_Checklist::get_instance();
		$items = $checklist->get_checklist_items( $post_id );
		$contextual_index = null;
		foreach ( $items as $index => $item ) {
			if ( 'AI editorial review has passed' === $item['label'] ) {
				$contextual_index = $index;
				break;
			}
		}

		$checked = array_diff( array_keys( $items ), array( $contextual_index ) );
		$result = $checklist->validate_checklist( $checked, $post_id );

		remove_filter( 'masthead_publication_checklist_items', $callback, 10 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'AI editorial review has passed', $result['missing_required'][0]['label'] );
	}

	public function test_smart_checklist_adds_contextual_post_items() {
		$post_id = self::factory()->post->create( array(
			'post_status'  => 'publish',
			'post_title'   => 'A Useful Headline',
			'post_excerpt' => '',
			'post_content' => '<p>No internal links yet.</p>',
		) );

		$items = Masthead_Publication_Checklist::get_instance()->get_checklist_items( $post_id );
		$item_ids = wp_list_pluck( $items, 'id' );

		$this->assertContains( 'masthead_title_length', $item_ids );
		$this->assertContains( 'masthead_excerpt_present', $item_ids );
		$this->assertContains( 'masthead_featured_image', $item_ids );
		$this->assertContains( 'masthead_internal_links', $item_ids );
	}

	public function test_required_smart_fail_blocks_even_when_checked() {
		$post_id = self::factory()->post->create( array(
			'post_status'  => 'publish',
			'post_title'   => 'Alt Text Test',
			'post_content' => '<p><img class="wp-image-123" src="example.jpg" /></p>',
		) );

		$checklist = Masthead_Publication_Checklist::get_instance();
		$items = $checklist->get_checklist_items( $post_id );
		$checked = array_keys( $items );
		$result = $checklist->validate_checklist( $checked, $post_id );

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['blocked_required'] );
		$this->assertSame( 'masthead_image_alt_text', $items[ $result['blocked_required'][0]['index'] ]['id'] );
	}

	public function test_required_pass_item_is_auto_satisfied() {
		$post_id = self::factory()->post->create( array(
			'post_status' => 'publish',
		) );

		$callback = function ( $items, $filtered_post_id ) use ( $post_id ) {
			if ( (int) $filtered_post_id === (int) $post_id ) {
				$items[] = array(
					'id'       => 'auto_required_pass',
					'label'    => 'Automatically satisfied requirement',
					'required' => true,
					'status'   => 'pass',
				);
			}

			return $items;
		};

		add_filter( 'masthead_publication_checklist_items', $callback, 10, 2 );

		$result = Masthead_Publication_Checklist::get_instance()->validate_checklist( array(), $post_id );

		remove_filter( 'masthead_publication_checklist_items', $callback, 10 );

		$this->assertTrue( $result['valid'] );
	}
}
