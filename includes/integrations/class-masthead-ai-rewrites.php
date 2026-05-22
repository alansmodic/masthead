<?php
/**
 * Masthead AI + Rewrites Integration
 *
 * Bridges the WP AI Client with the Rewrites publishing workflow:
 * - Optional AI review gate before publishing
 * - AI review status in the publication checklist
 * - Auto-summarize revisions on submission
 *
 * Replaces the old Redline integration (class-masthead-redline-rewrites.php).
 *
 * @package Masthead
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Masthead_AI_Rewrites {

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$settings = Masthead_Settings::get_instance();

		// AI review gate: optionally block publishing if AI review hasn't been run.
		if ( $settings->get( 'require_ai_review_before_publish' ) ) {
			add_filter( 'rewrites_can_publish', array( $this, 'gate_on_ai_review' ), 10, 2 );
		}

		// Checklist item: show AI review status.
		if ( $settings->get( 'ai_review_in_checklist' ) ) {
			add_filter( 'rewrites_checklist_items', array( $this, 'add_ai_review_checklist_item' ), 10, 2 );
		}

		// Auto-summarize: generate revision summary when a staged revision is submitted.
		if ( $settings->get( 'auto_summarize_on_submission' ) ) {
			add_action( 'masthead_staged_revision_submitted', array( $this, 'auto_summarize_revision' ), 10, 2 );
		}
	}

	/**
	 * Gate publishing on AI review completion.
	 *
	 * @param bool $can_publish Whether the post can be published.
	 * @param int  $post_id    The post ID.
	 * @return bool
	 */
	public function gate_on_ai_review( bool $can_publish, int $post_id ): bool {
		$last_review = get_post_meta( $post_id, '_masthead_ai_review_date', true );
		$open_issues = (int) get_post_meta( $post_id, '_masthead_ai_review_issues', true );

		// Block if never reviewed or has unresolved errors.
		if ( ! $last_review || $open_issues > 0 ) {
			return false;
		}

		return $can_publish;
	}

	/**
	 * Add AI review status to the publication checklist.
	 *
	 * @param array $items   Existing checklist items.
	 * @param int   $post_id The post ID.
	 * @return array
	 */
	public function add_ai_review_checklist_item( array $items, int $post_id ): array {
		$ai = Masthead_AI::get_instance();

		if ( ! $ai->is_available() ) {
			$items[] = array(
				'id'       => 'masthead_ai_review',
				'label'    => __( 'AI Review', 'masthead' ),
				'status'   => 'skipped',
				'message'  => __( 'No AI provider configured', 'masthead' ),
				'required' => false,
			);
			return $items;
		}

		$last_review  = get_post_meta( $post_id, '_masthead_ai_review_date', true );
		$open_issues  = (int) get_post_meta( $post_id, '_masthead_ai_review_issues', true );
		$is_required  = Masthead_Settings::get_instance()->get( 'require_ai_review_before_publish' );

		if ( ! $last_review ) {
			$status  = 'pending';
			$message = __( 'AI review not yet run', 'masthead' );
		} elseif ( $open_issues > 0 ) {
			$status  = 'warning';
			$message = sprintf(
				/* translators: %d: number of issues */
				_n( '%d issue found', '%d issues found', $open_issues, 'masthead' ),
				$open_issues
			);
		} else {
			$status  = 'complete';
			$message = __( 'Passed', 'masthead' );
		}

		$items[] = array(
			'id'       => 'masthead_ai_review',
			'label'    => __( 'AI Review', 'masthead' ),
			'status'   => $status,
			'message'  => $message,
			'required' => (bool) $is_required,
		);

		return $items;
	}

	/**
	 * Auto-summarize a revision when it's submitted for review.
	 *
	 * @param int     $revision_id The revision ID.
	 * @param WP_Post $revision    The revision post object.
	 */
	public function auto_summarize_revision( int $revision_id, $revision ): void {
		$ai = Masthead_AI::get_instance();

		if ( ! $ai->is_available() ) {
			return;
		}

		$parent = get_post( $revision->post_parent );
		if ( ! $parent ) {
			return;
		}

		// Detect changed fields.
		$changes = array();
		if ( $revision->post_title !== $parent->post_title )     { $changes[] = 'title'; }
		if ( $revision->post_content !== $parent->post_content ) { $changes[] = 'body content'; }
		if ( $revision->post_excerpt !== $parent->post_excerpt ) { $changes[] = 'excerpt'; }

		if ( empty( $changes ) ) {
			return;
		}

		$summary = $ai->summarize_revision(
			implode( ', ', $changes ),
			$revision->post_title ?: $parent->post_title,
			$parent->post_content,
			$revision->post_content
		);

		if ( ! is_wp_error( $summary ) ) {
			update_metadata( 'post', $revision_id, '_masthead_revision_summary', $summary );
		}
	}
}