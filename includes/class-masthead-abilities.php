<?php
/**
 * WordPress Abilities API integration for Masthead
 *
 * Registers editorial workflow actions as WordPress Abilities (WP 6.9+),
 * making them discoverable via REST, the command palette, and AI agents.
 *
 * @package Masthead
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Masthead_Abilities
 *
 * Registers Masthead abilities with the WordPress Abilities API.
 * Only registers abilities for currently enabled features.
 */
class Masthead_Abilities {

	/**
	 * Singleton instance.
	 *
	 * @var Masthead_Abilities|null
	 */
	private static $instance = null;

	/**
	 * Settings instance.
	 *
	 * @var Masthead_Settings
	 */
	private $settings;

	/**
	 * Get singleton instance.
	 *
	 * @return Masthead_Abilities
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings = Masthead_Settings::get_instance();

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register the Masthead ability category.
	 */
	public function register_category() {
		wp_register_ability_category( 'masthead', array(
			'label'       => __( 'Masthead', 'masthead' ),
			'description' => __( 'Editorial workflow abilities for content staging, review, and publishing.', 'masthead' ),
		) );
	}

	/**
	 * Register all abilities for enabled features.
	 */
	public function register_abilities() {
		$this->register_cross_cutting_abilities();

		if ( $this->settings->is_feature_enabled( 'staged_revisions' ) ) {
			$this->register_staged_revision_abilities();
		}

		if ( $this->settings->is_feature_enabled( 'publication_checklist' ) ) {
			$this->register_checklist_abilities();
		}

		if ( $this->settings->is_feature_enabled( 'scheduled_publishing' ) ) {
			$this->register_scheduling_abilities();
		}

		if ( $this->settings->is_feature_enabled( 'revision_timeline' ) ) {
			$this->register_timeline_abilities();
		}

		// AI-powered editorial abilities (available when WP AI Client is configured).
		$this->register_ai_abilities();
	}

	/**
	 * Register cross-cutting abilities (always available).
	 */
	private function register_cross_cutting_abilities() {
		wp_register_ability( 'masthead/list-features', array(
			'label'               => __( 'List Editorial Features', 'masthead' ),
			'description'         => __( 'Retrieve the status of all editorial workflow features.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback'    => array( $this, 'ability_features_list' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'additionalProperties' => array(
					'type'       => 'object',
					'properties' => array(
						'enabled' => array( 'type' => 'boolean' ),
						'label'   => array( 'type' => 'string' ),
					),
				),
			),
			'meta'                => array(
				'show_in_rest' => true,
				'readonly'     => true,
			),
		) );

		wp_register_ability( 'masthead/update-settings', array(
			'label'               => __( 'Update Editorial Settings', 'masthead' ),
			'description'         => __( 'Update Masthead plugin settings including feature toggles.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback'    => array( $this, 'ability_settings_update' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'features' => array(
						'type'                 => 'object',
						'description'          => __( 'Feature toggle map. Keys are feature IDs, values are booleans.', 'masthead' ),
						'additionalProperties' => array( 'type' => 'boolean' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'features' => array(
						'type'                 => 'object',
						'additionalProperties' => array( 'type' => 'boolean' ),
					),
					'warnings' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );
	}

	/**
	 * Register staged revision abilities.
	 */
	private function register_staged_revision_abilities() {
		$staged_revision_schema = array(
			'type'       => 'object',
			'properties' => array(
				'revision_id' => array( 'type' => 'integer' ),
				'post_id'     => array( 'type' => 'integer' ),
				'title'       => array( 'type' => 'string' ),
				'status'      => array( 'type' => 'string' ),
				'author'      => array(
					'type'       => 'object',
					'properties' => array(
						'id'   => array( 'type' => 'integer' ),
						'name' => array( 'type' => 'string' ),
					),
				),
				'modified'    => array( 'type' => 'string' ),
			),
		);

		wp_register_ability( 'masthead/create-staged-revision', array(
			'label'               => __( 'Create Staged Revision', 'masthead' ),
			'description'         => __( 'Save changes to a published post as a staged revision without publishing immediately.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback' => array( $this, 'ability_revision_create' ),
			'permission_callback' => function ( $input ) {
				$post_id = $input['post_id'] ?? 0;
				return $post_id && current_user_can( 'edit_post', $post_id );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'The parent post ID.', 'masthead' ),
					),
					'title'   => array(
						'type'        => 'string',
						'description' => __( 'Revised title.', 'masthead' ),
					),
					'content' => array(
						'type'        => 'string',
						'description' => __( 'Revised content.', 'masthead' ),
					),
					'excerpt' => array(
						'type'        => 'string',
						'description' => __( 'Revised excerpt.', 'masthead' ),
					),
					'notes'   => array(
						'type'        => 'string',
						'description' => __( 'Revision notes for editors.', 'masthead' ),
					),
				),
			),
			'output_schema'       => $staged_revision_schema,
			'meta'                => array( 'show_in_rest' => true ),
		) );

		wp_register_ability( 'masthead/get-staged-revision', array(
			'label'               => __( 'Get Staged Revision', 'masthead' ),
			'description'         => __( 'Retrieve the staged revision for a specific post.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback' => array( $this, 'ability_revision_get' ),
			'permission_callback' => function ( $input ) {
				$post_id = $input['post_id'] ?? 0;
				return $post_id && current_user_can( 'edit_post', $post_id );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'The post ID to get the staged revision for.', 'masthead' ),
					),
				),
			),
			'output_schema'       => $staged_revision_schema,
			'meta'                => array(
				'show_in_rest' => true,
				'readonly'     => true,
			),
		) );

		wp_register_ability( 'masthead/approve-staged-revision', array(
			'label'               => __( 'Approve Staged Revision', 'masthead' ),
			'description'         => __( 'Approve a staged revision, marking it ready for publishing.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback' => array( $this, 'ability_revision_approve' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'revision_id' ),
				'properties' => array(
					'revision_id' => array(
						'type'        => 'integer',
						'description' => __( 'The staged revision ID to approve.', 'masthead' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'revision' => $staged_revision_schema,
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );

		wp_register_ability( 'masthead/reject-staged-revision', array(
			'label'               => __( 'Reject Staged Revision', 'masthead' ),
			'description'         => __( 'Reject a staged revision, sending it back for further changes.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback' => array( $this, 'ability_revision_reject' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'revision_id' ),
				'properties' => array(
					'revision_id' => array(
						'type'        => 'integer',
						'description' => __( 'The staged revision ID to reject.', 'masthead' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'revision' => $staged_revision_schema,
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );

		wp_register_ability( 'masthead/publish-staged-revision', array(
			'label'               => __( 'Publish Staged Revision', 'masthead' ),
			'description'         => __( 'Publish a staged revision, replacing the live post content immediately.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback' => array( $this, 'ability_revision_publish' ),
			'permission_callback' => function ( $input ) {
				if ( ! current_user_can( 'publish_posts' ) ) {
					return false;
				}
				$revision_id = $input['revision_id'] ?? 0;
				if ( ! $revision_id ) {
					return false;
				}
				$revision = get_post( $revision_id );
				return $revision && current_user_can( 'edit_post', $revision->post_parent );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'revision_id' ),
				'properties' => array(
					'revision_id' => array(
						'type'        => 'integer',
						'description' => __( 'The staged revision ID to publish.', 'masthead' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'published' => array( 'type' => 'boolean' ),
					'post_id'   => array( 'type' => 'integer' ),
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );

		wp_register_ability( 'masthead/discard-staged-revision', array(
			'label'               => __( 'Discard Staged Revision', 'masthead' ),
			'description'         => __( 'Permanently delete a staged revision.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback' => array( $this, 'ability_revision_discard' ),
			'permission_callback' => function ( $input ) {
				$revision_id = $input['revision_id'] ?? 0;
				if ( ! $revision_id ) {
					return false;
				}
				$revision = get_post( $revision_id );
				return $revision && current_user_can( 'edit_post', $revision->post_parent );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'revision_id' ),
				'properties' => array(
					'revision_id' => array(
						'type'        => 'integer',
						'description' => __( 'The staged revision ID to discard.', 'masthead' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'deleted' => array( 'type' => 'boolean' ),
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );
	}

	/**
	 * Register publication checklist abilities.
	 */
	private function register_checklist_abilities() {
		wp_register_ability( 'masthead/get-checklist', array(
			'label'               => __( 'Get Checklist Items', 'masthead' ),
			'description'         => __( 'Retrieve the publication checklist items and their configuration.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback'    => array( $this, 'ability_checklist_get' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'Optional post ID for contextual checklist items.', 'masthead' ),
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'label'    => array( 'type' => 'string' ),
						'required' => array( 'type' => 'boolean' ),
						'status'   => array( 'type' => 'string' ),
						'message'  => array( 'type' => 'string' ),
						'source'   => array( 'type' => 'string' ),
					),
				),
			),
			'meta'                => array(
				'show_in_rest' => true,
				'readonly'     => true,
			),
		) );

		wp_register_ability( 'masthead/validate-checklist', array(
			'label'               => __( 'Validate Publication Checklist', 'masthead' ),
			'description'         => __( 'Validate that all required checklist items are checked before publishing.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback' => array( $this, 'ability_checklist_validate' ),
			'permission_callback' => function ( $input ) {
				$post_id = $input['post_id'] ?? 0;
				return $post_id && current_user_can( 'edit_post', $post_id );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'checked_items' ),
				'properties' => array(
					'post_id'       => array(
						'type'        => 'integer',
						'description' => __( 'The post ID being published.', 'masthead' ),
					),
					'checked_items' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => __( 'Array of checked item indices (0-based).', 'masthead' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'valid'            => array( 'type' => 'boolean' ),
					'missing_required' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'index' => array( 'type' => 'integer' ),
								'label' => array( 'type' => 'string' ),
							),
						),
					),
					'blocked_required' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'completed_items'  => array( 'type' => 'integer' ),
					'required_items'   => array( 'type' => 'integer' ),
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );

		wp_register_ability( 'masthead/generate-checklist', array(
			'label'               => __( 'Generate Smart Checklist', 'masthead' ),
			'description'         => __( 'Generate contextual publication checklist items for a post.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback'    => array( $this, 'ability_checklist_generate' ),
			'permission_callback' => function ( $input ) {
				$post_id = $input['post_id'] ?? 0;
				return $post_id && current_user_can( 'edit_post', $post_id );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'The post ID to analyze.', 'masthead' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'items'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'summary' => array( 'type' => 'object' ),
				),
			),
			'meta'                => array(
				'show_in_rest' => true,
				'readonly'     => true,
			),
		) );
	}

	/**
	 * Register scheduling abilities.
	 */
	private function register_scheduling_abilities() {
		wp_register_ability( 'masthead/schedule-staged-revision', array(
			'label'               => __( 'Schedule Staged Revision', 'masthead' ),
			'description'         => __( 'Schedule a staged revision to be published at a specific future date and time.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback' => array( $this, 'ability_revision_schedule' ),
			'permission_callback' => function ( $input ) {
				if ( ! current_user_can( 'publish_posts' ) ) {
					return false;
				}
				$revision_id = $input['revision_id'] ?? 0;
				if ( ! $revision_id ) {
					return false;
				}
				$revision = get_post( $revision_id );
				return $revision && current_user_can( 'edit_post', $revision->post_parent );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'revision_id', 'publish_date' ),
				'properties' => array(
					'revision_id'  => array(
						'type'        => 'integer',
						'description' => __( 'The staged revision ID to schedule.', 'masthead' ),
					),
					'publish_date' => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Future date/time for publication (Y-m-d H:i:s format).', 'masthead' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'scheduled'    => array( 'type' => 'boolean' ),
					'revision_id'  => array( 'type' => 'integer' ),
					'publish_date' => array( 'type' => 'string' ),
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );
	}

	/**
	 * Register AI-powered editorial abilities.
	 */
	private function register_ai_abilities() {
		wp_register_ability( 'masthead/review-content', array(
			'label'               => __( 'AI Editorial Review', 'masthead' ),
			'description'         => __( 'Run an AI-powered editorial review on post content, checking grammar, style, and tone.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback' => array( $this, 'ability_content_review' ),
			'permission_callback' => function ( $input ) {
				$post_id = $input['post_id'] ?? 0;
				return $post_id && current_user_can( 'edit_post', $post_id );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'The post ID to review.', 'masthead' ),
					),
					'checks'  => array(
						'type'        => 'array',
						'description' => __( 'Which checks to run: grammar, style, factual, tone.', 'masthead' ),
						'items'       => array( 'type' => 'string' ),
						'default'     => array( 'grammar', 'style', 'tone' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'issues'  => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'type'     => array( 'type' => 'string' ),
								'severity' => array( 'type' => 'string' ),
								'excerpt'  => array( 'type' => 'string' ),
								'note'     => array( 'type' => 'string' ),
							),
						),
					),
					'post_id' => array( 'type' => 'integer' ),
					'ai_available' => array( 'type' => 'boolean' ),
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );

		wp_register_ability( 'masthead/suggest-headlines', array(
			'label'               => __( 'Suggest Headlines', 'masthead' ),
			'description'         => __( 'Generate AI-powered headline suggestions for a post.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback' => array( $this, 'ability_headline_suggest' ),
			'permission_callback' => function ( $input ) {
				$post_id = $input['post_id'] ?? 0;
				return $post_id && current_user_can( 'edit_post', $post_id );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'The post ID to generate headlines for.', 'masthead' ),
					),
					'count'   => array(
						'type'        => 'integer',
						'description' => __( 'Number of suggestions (1-5).', 'masthead' ),
						'default'     => 3,
						'minimum'     => 1,
						'maximum'     => 5,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'headlines'    => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'post_id'      => array( 'type' => 'integer' ),
					'ai_available' => array( 'type' => 'boolean' ),
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );

		wp_register_ability( 'masthead/get-ai-status', array(
			'label'               => __( 'AI Status', 'masthead' ),
			'description'         => __( 'Check if AI editorial features are available and which provider is active.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback'    => array( $this, 'ability_ai_status' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'available' => array( 'type' => 'boolean' ),
					'provider'  => array( 'type' => array( 'string', 'null' ) ),
					'message'   => array( 'type' => 'string' ),
				),
			),
			'meta'                => array(
				'show_in_rest' => true,
				'readonly'     => true,
			),
		) );

		wp_register_ability( 'masthead/generate-alt-text', array(
			'label'               => __( 'Generate Alt Text', 'masthead' ),
			'description'         => __( 'Generate accessible alt text for an image attachment using AI.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback' => array( $this, 'ability_generate_alt_text' ),
			'permission_callback' => function ( $input ) {
				$attachment_id = $input['attachment_id'] ?? 0;
				return $attachment_id && current_user_can( 'edit_post', $attachment_id );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'attachment_id' ),
				'properties' => array(
					'attachment_id' => array(
						'type'        => 'integer',
						'description' => __( 'The image attachment ID.', 'masthead' ),
					),
					'post_context' => array(
						'type'        => 'string',
						'description' => __( 'Optional article context for relevance.', 'masthead' ),
					),
					'apply' => array(
						'type'        => 'boolean',
						'description' => __( 'If true, also save the generated alt text to the attachment.', 'masthead' ),
						'default'     => false,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'alt_text'      => array( 'type' => 'string' ),
					'attachment_id' => array( 'type' => 'integer' ),
					'applied'       => array( 'type' => 'boolean' ),
					'ai_available'  => array( 'type' => 'boolean' ),
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );

		wp_register_ability( 'masthead/scan-missing-alt-text', array(
			'label'               => __( 'Scan for Missing Alt Text', 'masthead' ),
			'description'         => __( 'Find images in a post that are missing alt text.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback' => array( $this, 'ability_scan_missing_alt' ),
			'permission_callback' => function ( $input ) {
				$post_id = $input['post_id'] ?? 0;
				return $post_id && current_user_can( 'edit_post', $post_id );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'The post ID to scan for missing alt text.', 'masthead' ),
					),
					'auto_generate' => array(
						'type'        => 'boolean',
						'description' => __( 'If true, auto-generate and apply alt text for all missing images.', 'masthead' ),
						'default'     => false,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'missing_count' => array( 'type' => 'integer' ),
					'images'        => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'attachment_id' => array( 'type' => 'integer' ),
								'filename'      => array( 'type' => 'string' ),
								'generated_alt' => array( 'type' => 'string' ),
								'applied'       => array( 'type' => 'boolean' ),
							),
						),
					),
					'post_id'       => array( 'type' => 'integer' ),
					'ai_available'  => array( 'type' => 'boolean' ),
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );

		wp_register_ability( 'masthead/analyze-tone', array(
			'label'               => __( 'Analyze Tone & Readability', 'masthead' ),
			'description'         => __( 'Analyze content tone, reading level, audience fit, and provide improvement suggestions.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback' => array( $this, 'ability_analyze_tone' ),
			'permission_callback' => function ( $input ) {
				$post_id = $input['post_id'] ?? 0;
				return $post_id && current_user_can( 'edit_post', $post_id );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'The post ID to analyze.', 'masthead' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'tone'                    => array( 'type' => 'string' ),
					'reading_level'           => array( 'type' => 'string' ),
					'grade_level'             => array( 'type' => 'number' ),
					'audience'                => array( 'type' => 'string' ),
					'clarity_score'           => array( 'type' => 'number' ),
					'engagement_score'        => array( 'type' => 'number' ),
					'suggestions'             => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'word_count'              => array( 'type' => 'integer' ),
					'sentence_count'          => array( 'type' => 'integer' ),
					'paragraph_count'         => array( 'type' => 'integer' ),
					'avg_words_per_sentence'  => array( 'type' => 'number' ),
					'post_id'                 => array( 'type' => 'integer' ),
					'ai_available'            => array( 'type' => 'boolean' ),
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );
	}

	/**
	 * Register revision timeline abilities.
	 */
	private function register_timeline_abilities() {
		wp_register_ability( 'masthead/get-timeline', array(
			'label'               => __( 'Get Revision Timeline', 'masthead' ),
			'description'         => __( 'Retrieve the revision timeline for a post, including change metadata and author information.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback' => array( $this, 'ability_timeline_get' ),
			'permission_callback' => function ( $input ) {
				$post_id = $input['post_id'] ?? 0;
				return $post_id && current_user_can( 'edit_post', $post_id );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id'           => array(
						'type'        => 'integer',
						'description' => __( 'The post ID to get the timeline for.', 'masthead' ),
					),
					'per_page'          => array(
						'type'        => 'integer',
						'default'     => 50,
						'description' => __( 'Number of revisions to return.', 'masthead' ),
					),
					'include_autosaves' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Whether to include autosave revisions.', 'masthead' ),
					),
				),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array( 'type' => 'integer' ),
						'parent_id'    => array( 'type' => 'integer' ),
						'date'         => array( 'type' => 'string' ),
						'author'       => array(
							'type'       => 'object',
							'properties' => array(
								'id'   => array( 'type' => 'integer' ),
								'name' => array( 'type' => 'string' ),
							),
						),
						'type'         => array( 'type' => 'string' ),
						'changes'      => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'change_count' => array( 'type' => 'integer' ),
					),
				),
			),
			'meta'                => array(
				'show_in_rest' => true,
				'readonly'     => true,
			),
		) );

		wp_register_ability( 'masthead/diff-revision', array(
			'label'               => __( 'Get Revision Diff', 'masthead' ),
			'description'         => __( 'Get a detailed diff between a revision and its predecessor, including word-level changes and media changes when enabled.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback' => array( $this, 'ability_revision_diff' ),
			'permission_callback' => function ( $input ) {
				$revision_id = $input['revision_id'] ?? 0;
				if ( ! $revision_id ) {
					return false;
				}
				$revision = get_post( $revision_id );
				return $revision && current_user_can( 'edit_post', $revision->post_parent );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'revision_id' ),
				'properties' => array(
					'revision_id' => array(
						'type'        => 'integer',
						'description' => __( 'The revision ID to generate a diff for.', 'masthead' ),
					),
					'compare_to'  => array(
						'type'        => 'integer',
						'description' => __( 'Revision ID to compare against. Defaults to the previous revision.', 'masthead' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'revision_id' => array( 'type' => 'integer' ),
					'compare_to'  => array( 'type' => 'integer' ),
					'fields'      => array( 'type' => 'object' ),
				),
			),
			'meta'                => array(
				'show_in_rest' => true,
				'readonly'     => true,
			),
		) );

		wp_register_ability( 'masthead/restore-revision', array(
			'label'               => __( 'Restore Revision', 'masthead' ),
			'description'         => __( 'Restore a post to a previous revision, replacing its current content.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback' => array( $this, 'ability_revision_restore' ),
			'permission_callback' => function ( $input ) {
				$revision_id = $input['revision_id'] ?? 0;
				if ( ! $revision_id ) {
					return false;
				}
				$revision = get_post( $revision_id );
				return $revision && current_user_can( 'edit_post', $revision->post_parent );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'revision_id' ),
				'properties' => array(
					'revision_id' => array(
						'type'        => 'integer',
						'description' => __( 'The revision ID to restore the post to.', 'masthead' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'post_id' => array( 'type' => 'integer' ),
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );

		wp_register_ability( 'masthead/summarize-revision', array(
			'label'               => __( 'Summarize Revision Changes', 'masthead' ),
			'description'         => __( 'Generate a plain-English AI summary of what changed in a revision. Uses WP AI Client (7.0+) when available.', 'masthead' ),
			'category'            => 'masthead',
			'execute_callback' => array( $this, 'ability_revision_summarize' ),
			'permission_callback' => function ( $input ) {
				$revision_id = $input['revision_id'] ?? 0;
				if ( ! $revision_id ) {
					return false;
				}
				$revision = get_post( $revision_id );
				return $revision && current_user_can( 'edit_post', $revision->post_parent );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'revision_id' ),
				'properties' => array(
					'revision_id' => array(
						'type'        => 'integer',
						'description' => __( 'The revision ID to summarize.', 'masthead' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'summary'     => array( 'type' => 'string' ),
					'revision_id' => array( 'type' => 'integer' ),
					'cached'      => array( 'type' => 'boolean' ),
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );
	}

	// -------------------------------------------------------------------------
	// Ability callbacks
	// -------------------------------------------------------------------------

	/**
	 * Callback: List editorial features.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_features_list( $input ) {
		$features  = $this->settings->get_enabled_features();
		$available = $this->settings->get_available_features();

		$response = array();
		foreach ( $features as $key => $enabled ) {
			$response[ $key ] = array(
				'enabled' => (bool) $enabled,
				'label'   => $available[ $key ]['label'] ?? $key,
			);
		}

		return $response;
	}

	/**
	 * Callback: Update editorial settings.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_settings_update( $input ) {
		$warnings = array();

		if ( isset( $input['features'] ) && is_array( $input['features'] ) ) {
			$available = $this->settings->get_available_features();

			foreach ( $input['features'] as $key => $enabled ) {
				if ( ! isset( $available[ $key ] ) ) {
					continue;
				}

				if ( $enabled ) {
					if ( ! $this->settings->check_feature_dependencies( $key ) ) {
						$warnings[] = sprintf(
							/* translators: %s: feature name */
							__( 'Cannot enable %s because its dependencies are not met.', 'masthead' ),
							$available[ $key ]['label']
						);
						continue;
					}
					$this->settings->enable_feature( $key );
				} else {
					$dependents = $this->settings->get_dependent_features( $key );
					foreach ( $dependents as $dependent ) {
						if ( $this->settings->is_feature_enabled( $dependent ) ) {
							$this->settings->disable_feature( $dependent );
							$warnings[] = sprintf(
								/* translators: %1$s and %2$s are feature names */
								__( 'Disabled %1$s because it requires %2$s.', 'masthead' ),
								$available[ $dependent ]['label'],
								$available[ $key ]['label']
							);
						}
					}
					$this->settings->disable_feature( $key );
				}
			}
		}

		return array(
			'success'  => true,
			'features' => $this->settings->get_enabled_features(),
			'warnings' => $warnings,
		);
	}

	/**
	 * Callback: Create staged revision.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_revision_create( $input ) {
		if ( ! $this->settings->is_feature_enabled( 'staged_revisions' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is disabled.', 'masthead' ) );
		}

		$post_data = array(
			'title'   => $input['title'] ?? null,
			'content' => $input['content'] ?? null,
			'excerpt' => $input['excerpt'] ?? null,
		);

		$meta_data = array(
			'notes' => $input['notes'] ?? '',
		);

		$revision_id = Masthead_Staged_Revisions::create( $input['post_id'], $post_data, $meta_data );

		if ( is_wp_error( $revision_id ) ) {
			return $revision_id;
		}

		$revision = Masthead_Staged_Revisions::get_by_id( $revision_id );
		return Masthead_Staged_Revisions::format_for_response( $revision );
	}

	/**
	 * Callback: Get staged revision.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_revision_get( $input ) {
		if ( ! $this->settings->is_feature_enabled( 'staged_revisions' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is disabled.', 'masthead' ) );
		}

		$revision = Masthead_Staged_Revisions::get( $input['post_id'] );

		if ( ! $revision ) {
			return new WP_Error( 'not_found', __( 'No staged revision found for this post.', 'masthead' ) );
		}

		return Masthead_Staged_Revisions::format_for_response( $revision );
	}

	/**
	 * Callback: Approve staged revision.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_revision_approve( $input ) {
		if ( ! $this->settings->is_feature_enabled( 'staged_revisions' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is disabled.', 'masthead' ) );
		}

		$result = Masthead_Staged_Revisions::approve( $input['revision_id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$revision = Masthead_Staged_Revisions::get_by_id( $input['revision_id'] );
		return array(
			'success'  => true,
			'revision' => Masthead_Staged_Revisions::format_for_response( $revision ),
		);
	}

	/**
	 * Callback: Reject staged revision.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_revision_reject( $input ) {
		if ( ! $this->settings->is_feature_enabled( 'staged_revisions' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is disabled.', 'masthead' ) );
		}

		$result = Masthead_Staged_Revisions::reject( $input['revision_id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$revision = Masthead_Staged_Revisions::get_by_id( $input['revision_id'] );
		return array(
			'success'  => true,
			'revision' => Masthead_Staged_Revisions::format_for_response( $revision ),
		);
	}

	/**
	 * Callback: Publish staged revision.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_revision_publish( $input ) {
		if ( ! $this->settings->is_feature_enabled( 'staged_revisions' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is disabled.', 'masthead' ) );
		}

		$result = Masthead_Staged_Revisions::publish( $input['revision_id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'published' => true,
			'post_id'   => $result,
		);
	}

	/**
	 * Callback: Discard staged revision.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_revision_discard( $input ) {
		if ( ! $this->settings->is_feature_enabled( 'staged_revisions' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is disabled.', 'masthead' ) );
		}

		$result = Masthead_Staged_Revisions::discard( $input['revision_id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'deleted' => true );
	}

	/**
	 * Callback: Get checklist items.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_checklist_get( $input ) {
		if ( ! $this->settings->is_feature_enabled( 'publication_checklist' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Publication checklist feature is disabled.', 'masthead' ) );
		}

		$post_id = absint( $input['post_id'] ?? 0 );
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'permission_denied', __( 'Permission denied.', 'masthead' ) );
		}

		return Masthead_Publication_Checklist::get_instance()->get_checklist_items( $post_id );
	}

	/**
	 * Callback: Generate smart checklist.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_checklist_generate( $input ) {
		if ( ! $this->settings->is_feature_enabled( 'publication_checklist' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Publication checklist feature is disabled.', 'masthead' ) );
		}

		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'masthead' ) );
		}

		$items = Masthead_Publication_Checklist::get_instance()->get_checklist_items( $post_id );

		return array(
			'post_id' => $post_id,
			'items'   => $items,
			'summary' => $this->summarize_checklist_items( $items ),
		);
	}

	/**
	 * Callback: Validate publication checklist.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_checklist_validate( $input ) {
		if ( ! $this->settings->is_feature_enabled( 'publication_checklist' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Publication checklist feature is disabled.', 'masthead' ) );
		}

		$checklist = Masthead_Publication_Checklist::get_instance();
		$checked_items = array_map( 'absint', $input['checked_items'] );
		$result = $checklist->validate_checklist( $checked_items, absint( $input['post_id'] ) );

		if ( $result['valid'] ) {
			update_post_meta( $input['post_id'], '_masthead_checklist_bypassed', true );
		}

		return $result;
	}

	/**
	 * Summarize checklist item states.
	 *
	 * @param array $items Checklist items.
	 * @return array
	 */
	private function summarize_checklist_items( array $items ): array {
		$summary = array(
			'total'       => count( $items ),
			'required'    => 0,
			'passing'     => 0,
			'warnings'    => 0,
			'blocking'    => 0,
			'unavailable' => 0,
		);

		foreach ( $items as $item ) {
			if ( ! empty( $item['required'] ) ) {
				$summary['required']++;
			}

			$status = $item['status'] ?? 'manual';
			if ( 'pass' === $status ) {
				$summary['passing']++;
			} elseif ( 'warning' === $status ) {
				$summary['warnings']++;
			} elseif ( 'fail' === $status ) {
				$summary['blocking']++;
			} elseif ( 'unavailable' === $status ) {
				$summary['unavailable']++;
			}
		}

		return $summary;
	}

	/**
	 * Callback: Schedule staged revision.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_revision_schedule( $input ) {
		if ( ! $this->settings->is_feature_enabled( 'scheduled_publishing' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Scheduled publishing feature is disabled.', 'masthead' ) );
		}

		$result = Masthead_Scheduled_Publishing::schedule_staged_revision(
			$input['revision_id'],
			$input['publish_date']
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'scheduled'    => true,
			'revision_id'  => $input['revision_id'],
			'publish_date' => $input['publish_date'],
		);
	}

	/**
	 * Callback: Get revision timeline.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_timeline_get( $input ) {
		if ( ! $this->settings->is_feature_enabled( 'revision_timeline' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Revision timeline feature is disabled.', 'masthead' ) );
		}

		$post = get_post( $input['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'masthead' ) );
		}

		$args = array(
			'per_page'          => $input['per_page'] ?? 50,
			'include_autosaves' => $input['include_autosaves'] ?? false,
		);

		return Masthead_Revision_Timeline::get_timeline_data( $input['post_id'], $args );
	}

	/**
	 * Callback: Get revision diff.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_revision_diff( $input ) {
		if ( ! $this->settings->is_feature_enabled( 'revision_timeline' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Revision timeline feature is disabled.', 'masthead' ) );
		}

		$revision = get_post( $input['revision_id'] );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return new WP_Error( 'not_found', __( 'Revision not found.', 'masthead' ) );
		}

		// Determine what to compare against.
		if ( ! empty( $input['compare_to'] ) ) {
			$compare_post = get_post( $input['compare_to'] );
			if ( ! $compare_post ) {
				return new WP_Error( 'not_found', __( 'Comparison revision not found.', 'masthead' ) );
			}
		} else {
			$compare_post = $this->get_previous_revision( $revision );
			if ( ! $compare_post ) {
				$compare_post = get_post( $revision->post_parent );
			}
		}

		// Generate diff using word-level diffs if enabled.
		if ( $this->settings->is_feature_enabled( 'word_level_diffs' ) && class_exists( 'Masthead_Word_Level_Diffs' ) ) {
			$diff_data = Masthead_Word_Level_Diffs::generate_diff( $compare_post, $revision );
		} else {
			$diff_data = array(
				'revision_id' => $revision->ID,
				'compare_to'  => $compare_post->ID,
				'fields'      => array(
					'title'   => array(
						'from' => $compare_post->post_title,
						'to'   => $revision->post_title,
					),
					'content' => array(
						'from' => wp_strip_all_tags( $compare_post->post_content ),
						'to'   => wp_strip_all_tags( $revision->post_content ),
					),
					'excerpt' => array(
						'from' => wp_strip_all_tags( $compare_post->post_excerpt ),
						'to'   => wp_strip_all_tags( $revision->post_excerpt ),
					),
				),
			);
		}

		// Add media changes if enabled.
		if ( $this->settings->is_feature_enabled( 'media_change_tracking' ) && class_exists( 'Masthead_Media_Change_Tracking' ) ) {
			$diff_data['media_changes'] = Masthead_Media_Change_Tracking::get_media_changes(
				$compare_post->post_content,
				$revision->post_content
			);
		}

		return $diff_data;
	}

	/**
	 * Callback: Restore revision.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_revision_restore( $input ) {
		if ( ! $this->settings->is_feature_enabled( 'revision_timeline' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Revision timeline feature is disabled.', 'masthead' ) );
		}

		$revision = get_post( $input['revision_id'] );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return new WP_Error( 'not_found', __( 'Revision not found.', 'masthead' ) );
		}

		$restored = wp_restore_post_revision( $input['revision_id'] );

		if ( ! $restored ) {
			return new WP_Error( 'restore_failed', __( 'Failed to restore revision.', 'masthead' ) );
		}

		return array(
			'success' => true,
			'post_id' => $revision->post_parent,
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Get the previous revision for comparison.
	 *
	 * @param WP_Post $revision The current revision.
	 * @return WP_Post|null
	 */
	private function get_previous_revision( $revision ) {
		$revisions = wp_get_post_revisions(
			$revision->post_parent,
			array(
				'posts_per_page' => 1,
				'date_query'     => array(
					array(
						'before' => $revision->post_modified_gmt,
						'column' => 'post_modified_gmt',
					),
				),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		return ! empty( $revisions ) ? array_shift( $revisions ) : null;
	}

	/**
	 * Callback: Summarize revision changes via WP AI Client.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_revision_summarize( $input ) {
		if ( ! $this->settings->is_feature_enabled( 'revision_timeline' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Revision timeline feature is disabled.', 'masthead' ) );
		}

		$revision = get_post( $input['revision_id'] );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return new WP_Error( 'not_found', __( 'Revision not found.', 'masthead' ) );
		}

		// Check for cached summary first.
		$cached = get_metadata( 'post', $revision->ID, '_masthead_revision_summary', true );
		if ( $cached ) {
			return array(
				'summary'     => $cached,
				'revision_id' => $revision->ID,
				'cached'      => true,
			);
		}

		$parent = get_post( $revision->post_parent );
		if ( ! $parent ) {
			return new WP_Error( 'not_found', __( 'Parent post not found.', 'masthead' ) );
		}

		$compare = $this->get_previous_revision( $revision ) ?? $parent;

		$changes = array();
		if ( $revision->post_title !== $compare->post_title )     { $changes[] = 'title'; }
		if ( $revision->post_content !== $compare->post_content ) { $changes[] = 'body content'; }
		if ( $revision->post_excerpt !== $compare->post_excerpt ) { $changes[] = 'excerpt'; }

		if ( empty( $changes ) ) {
			return array(
				'summary'     => __( 'No changes detected between this revision and its predecessor.', 'masthead' ),
				'revision_id' => $revision->ID,
				'cached'      => false,
			);
		}

		$changed_fields = implode( ', ', $changes );

		// Use Masthead_AI wrapper (routes through WP AI Client).
		$ai = Masthead_AI::get_instance();
		$summary = $ai->summarize_revision(
			$changed_fields,
			$revision->post_title ?: $parent->post_title,
			$compare->post_content,
			$revision->post_content
		);

		if ( is_wp_error( $summary ) ) {
			// Fallback: plain description from diff fields.
			$summary = sprintf(
				/* translators: 1: changed fields list */
				__( 'Revision updates the following fields: %s.', 'masthead' ),
				$changed_fields
			);
		} else {
			// Cache AI-generated summary.
			update_metadata( 'post', $revision->ID, '_masthead_revision_summary', $summary );
		}

		return array(
			'summary'     => $summary,
			'revision_id' => $revision->ID,
			'cached'      => false,
		);
	}

	/**
	 * Callback: AI editorial review.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_content_review( $input ) {
		$post = get_post( $input['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'masthead' ) );
		}

		$ai = Masthead_AI::get_instance();
		if ( ! $ai->is_available() ) {
			return array(
				'issues'       => array(),
				'post_id'      => $post->ID,
				'ai_available' => false,
			);
		}

		$checks = $input['checks'] ?? array( 'grammar', 'style', 'tone' );
		$issues = $ai->review_content( $post->post_content, $post->post_title, $checks );

		if ( is_wp_error( $issues ) ) {
			return $issues;
		}

		// Store issue count for checklist integration.
		$error_count = count( array_filter( $issues, fn( $i ) => $i['severity'] === 'error' ) );
		update_post_meta( $post->ID, '_masthead_ai_review_date', current_time( 'mysql' ) );
		update_post_meta( $post->ID, '_masthead_ai_review_issues', $error_count );

		return array(
			'issues'       => $issues,
			'post_id'      => $post->ID,
			'ai_available' => true,
		);
	}

	/**
	 * Callback: Suggest headlines.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_headline_suggest( $input ) {
		$post = get_post( $input['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'masthead' ) );
		}

		$ai = Masthead_AI::get_instance();
		if ( ! $ai->is_available() ) {
			return array(
				'headlines'    => array(),
				'post_id'      => $post->ID,
				'ai_available' => false,
			);
		}

		$count     = min( max( (int) ( $input['count'] ?? 3 ), 1 ), 5 );
		$headlines = $ai->suggest_headlines( $post->post_content, $count );

		if ( is_wp_error( $headlines ) ) {
			return $headlines;
		}

		return array(
			'headlines'    => $headlines,
			'post_id'      => $post->ID,
			'ai_available' => true,
		);
	}

	/**
	 * Callback: AI status check.
	 *
	 * @param array $input Ability input (unused).
	 * @return array
	 */
	public function ability_ai_status( $input ) {
		return Masthead_AI::get_instance()->get_status();
	}

	/**
	 * Callback: Generate alt text for an image.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_generate_alt_text( $input ) {
		$ai = Masthead_AI::get_instance();

		if ( ! $ai->is_available() ) {
			return array(
				'alt_text'      => '',
				'attachment_id' => $input['attachment_id'],
				'applied'       => false,
				'ai_available'  => false,
			);
		}

		$post_context = $input['post_context'] ?? '';
		$alt_text = $ai->generate_alt_text( $input['attachment_id'], $post_context );

		if ( is_wp_error( $alt_text ) ) {
			return $alt_text;
		}

		$applied = false;
		if ( ! empty( $input['apply'] ) ) {
			update_post_meta( $input['attachment_id'], '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
			$applied = true;
		}

		return array(
			'alt_text'      => $alt_text,
			'attachment_id' => $input['attachment_id'],
			'applied'       => $applied,
			'ai_available'  => true,
		);
	}

	/**
	 * Callback: Scan post for images missing alt text.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_scan_missing_alt( $input ) {
		$ai = Masthead_AI::get_instance();
		$missing_ids = $ai->find_images_missing_alt( $input['post_id'] );

		$post = get_post( $input['post_id'] );
		$post_context = $post ? mb_substr( wp_strip_all_tags( $post->post_content ), 0, 500 ) : '';

		$images = array();
		foreach ( $missing_ids as $att_id ) {
			$item = array(
				'attachment_id' => $att_id,
				'filename'      => basename( get_attached_file( $att_id ) ),
				'generated_alt' => '',
				'applied'       => false,
			);

			if ( ! empty( $input['auto_generate'] ) && $ai->is_available() ) {
				$alt = $ai->generate_alt_text( $att_id, $post_context );
				if ( ! is_wp_error( $alt ) ) {
					$item['generated_alt'] = $alt;
					$item['applied'] = true;
					update_post_meta( $att_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
				}
			}

			$images[] = $item;
		}

		return array(
			'missing_count' => count( $missing_ids ),
			'images'        => $images,
			'post_id'       => $input['post_id'],
			'ai_available'  => $ai->is_available(),
		);
	}

	/**
	 * Callback: Analyze tone and readability.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_analyze_tone( $input ) {
		$post = get_post( $input['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'masthead' ) );
		}

		$ai = Masthead_AI::get_instance();
		if ( ! $ai->is_available() ) {
			return array(
				'tone'           => '',
				'reading_level'  => '',
				'grade_level'    => 0,
				'audience'       => '',
				'clarity_score'  => 0,
				'engagement_score' => 0,
				'suggestions'    => array(),
				'word_count'     => str_word_count( wp_strip_all_tags( $post->post_content ) ),
				'sentence_count' => 0,
				'paragraph_count' => 0,
				'avg_words_per_sentence' => 0,
				'post_id'        => $post->ID,
				'ai_available'   => false,
			);
		}

		$analysis = $ai->analyze_tone( $post->post_content, $post->post_title );

		if ( is_wp_error( $analysis ) ) {
			return $analysis;
		}

		$analysis['post_id']      = $post->ID;
		$analysis['ai_available'] = true;

		// Cache the analysis.
		update_post_meta( $post->ID, '_masthead_tone_analysis', $analysis );
		update_post_meta( $post->ID, '_masthead_tone_analysis_date', current_time( 'mysql' ) );

		return $analysis;
	}
}
