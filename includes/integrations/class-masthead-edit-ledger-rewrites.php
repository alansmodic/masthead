<?php
/**
 * Integration: Edit Ledger + Rewrites
 *
 * When a staged revision is submitted via Rewrites, automatically
 * call Edit Ledger's summarize-revision ability and attach the
 * summary to the approval workflow.
 *
 * @package Masthead
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Masthead_Edit_Ledger_Rewrites {

	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function init(): void {
		$settings = Masthead_Settings::get_instance();

		if ( $settings->is_integration_enabled( 'auto_summarize_on_submission' ) ) {
			// Canonical Masthead hook plus legacy Rewrites hook for compatibility.
			add_action( 'masthead_staged_revision_submitted', [ $this, 'mirror_summary_on_submission' ], 20, 4 );
			add_action( 'rewrites_after_submit', [ $this, 'summarize_on_submission' ], 10, 2 );
		}

		// Attach the summary to the approval panel.
		add_filter( 'rewrites_approval_panel_data', [ $this, 'attach_summary_to_panel' ], 10, 2 );
		add_filter( 'masthead_staged_revision_response', [ $this, 'attach_summary_to_response' ], 10, 2 );
	}

	/**
	 * Keep canonical staged revision summaries available to integration surfaces.
	 *
	 * @param int     $revision_id   Revision ID.
	 * @param WP_Post $revision_post Revision post object.
	 * @param int     $post_id       Parent post ID.
	 * @param array   $meta_data     Submission metadata.
	 */
	public function mirror_summary_on_submission( int $revision_id, $revision_post, int $post_id, array $meta_data = array() ): void {
		$summary = get_post_meta( $revision_id, '_masthead_revision_summary', true );

		if ( $summary ) {
			update_post_meta( $post_id, '_masthead_latest_revision_summary', sanitize_textarea_field( $summary ) );
		}
	}

	/**
	 * Call Edit Ledger's summarize-revision ability when a staged revision is submitted.
	 */
	public function summarize_on_submission( int $rewrite_id, int $revision_id ): void {
		$settings = Masthead_Settings::get_instance();
		if ( ! $settings->is_integration_enabled( 'auto_summarize_on_submission' ) ) {
			return;
		}

		// Invoke via Edit Ledger's Abilities API endpoint.
		$response = wp_remote_post( rest_url( 'wp-abilities/v1/abilities/edit-ledger/summarize-revision/run' ), [
			'body'    => wp_json_encode( [ 'input' => [ 'revision_id' => $revision_id ] ] ),
			'headers' => [
				'Content-Type'  => 'application/json',
				'X-WP-Nonce'    => wp_create_nonce( 'wp_rest' ),
			],
		] );

		if ( is_wp_error( $response ) ) {
			return;
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$summary = $body['output']['summary'] ?? '';

		if ( $summary ) {
			update_post_meta( $rewrite_id, '_masthead_revision_summary', sanitize_textarea_field( $summary ) );
		}
	}

	/**
	 * Attach the AI summary to the Rewrites approval panel data.
	 */
	public function attach_summary_to_panel( array $data, int $rewrite_id ): array {
		$summary = get_post_meta( $rewrite_id, '_masthead_revision_summary', true );
		if ( $summary ) {
			$data['masthead_summary'] = $summary;
		}
		return $data;
	}

	/**
	 * Attach summary data to Masthead's staged revision REST response.
	 *
	 * @param array  $response Formatted response.
	 * @param object $revision Formatted revision object.
	 * @return array
	 */
	public function attach_summary_to_response( array $response, object $revision ): array {
		if ( ! empty( $response['summary'] ) ) {
			return $response;
		}

		$summary = get_post_meta( (int) $revision->revision_id, '_masthead_revision_summary', true );
		if ( $summary ) {
			$response['summary'] = $summary;
		}

		return $response;
	}
}
