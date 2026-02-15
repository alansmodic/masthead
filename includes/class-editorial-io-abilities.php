<?php
/**
 * WordPress Abilities API integration for Editorial.io
 *
 * Registers editorial workflow actions as WordPress Abilities (WP 6.9+),
 * making them discoverable via REST, the command palette, and AI agents.
 *
 * @package EditorialIO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Editorial_IO_Abilities
 *
 * Registers Editorial.io abilities with the WordPress Abilities API.
 * Only registers abilities for currently enabled features.
 */
class Editorial_IO_Abilities {

	/**
	 * Singleton instance.
	 *
	 * @var Editorial_IO_Abilities|null
	 */
	private static $instance = null;

	/**
	 * Settings instance.
	 *
	 * @var Editorial_IO_Settings
	 */
	private $settings;

	/**
	 * Get singleton instance.
	 *
	 * @return Editorial_IO_Abilities
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
		$this->settings = Editorial_IO_Settings::get_instance();

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register the editorial-io ability category.
	 */
	public function register_category() {
		wp_register_ability_category( 'editorial-io', array(
			'label'       => __( 'Editorial.io', 'editorial-io' ),
			'description' => __( 'Editorial workflow abilities for content staging, review, and publishing.', 'editorial-io' ),
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
	}

	/**
	 * Register cross-cutting abilities (always available).
	 */
	private function register_cross_cutting_abilities() {
		wp_register_ability( 'editorial-io/features.list', array(
			'label'               => __( 'List Editorial Features', 'editorial-io' ),
			'description'         => __( 'Retrieve the status of all editorial workflow features.', 'editorial-io' ),
			'category'            => 'editorial-io',
			'callback'            => array( $this, 'ability_features_list' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => new \stdClass(),
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

		wp_register_ability( 'editorial-io/settings.update', array(
			'label'               => __( 'Update Editorial Settings', 'editorial-io' ),
			'description'         => __( 'Update Editorial.io plugin settings including feature toggles.', 'editorial-io' ),
			'category'            => 'editorial-io',
			'callback'            => array( $this, 'ability_settings_update' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'features' => array(
						'type'                 => 'object',
						'description'          => __( 'Feature toggle map. Keys are feature IDs, values are booleans.', 'editorial-io' ),
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

		wp_register_ability( 'editorial-io/revision.create', array(
			'label'               => __( 'Create Staged Revision', 'editorial-io' ),
			'description'         => __( 'Save changes to a published post as a staged revision without publishing immediately.', 'editorial-io' ),
			'category'            => 'editorial-io',
			'callback'            => array( $this, 'ability_revision_create' ),
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
						'description' => __( 'The parent post ID.', 'editorial-io' ),
					),
					'title'   => array(
						'type'        => 'string',
						'description' => __( 'Revised title.', 'editorial-io' ),
					),
					'content' => array(
						'type'        => 'string',
						'description' => __( 'Revised content.', 'editorial-io' ),
					),
					'excerpt' => array(
						'type'        => 'string',
						'description' => __( 'Revised excerpt.', 'editorial-io' ),
					),
					'notes'   => array(
						'type'        => 'string',
						'description' => __( 'Revision notes for editors.', 'editorial-io' ),
					),
				),
			),
			'output_schema'       => $staged_revision_schema,
			'meta'                => array( 'show_in_rest' => true ),
		) );

		wp_register_ability( 'editorial-io/revision.get', array(
			'label'               => __( 'Get Staged Revision', 'editorial-io' ),
			'description'         => __( 'Retrieve the staged revision for a specific post.', 'editorial-io' ),
			'category'            => 'editorial-io',
			'callback'            => array( $this, 'ability_revision_get' ),
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
						'description' => __( 'The post ID to get the staged revision for.', 'editorial-io' ),
					),
				),
			),
			'output_schema'       => $staged_revision_schema,
			'meta'                => array(
				'show_in_rest' => true,
				'readonly'     => true,
			),
		) );

		wp_register_ability( 'editorial-io/revision.approve', array(
			'label'               => __( 'Approve Staged Revision', 'editorial-io' ),
			'description'         => __( 'Approve a staged revision, marking it ready for publishing.', 'editorial-io' ),
			'category'            => 'editorial-io',
			'callback'            => array( $this, 'ability_revision_approve' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'revision_id' ),
				'properties' => array(
					'revision_id' => array(
						'type'        => 'integer',
						'description' => __( 'The staged revision ID to approve.', 'editorial-io' ),
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

		wp_register_ability( 'editorial-io/revision.reject', array(
			'label'               => __( 'Reject Staged Revision', 'editorial-io' ),
			'description'         => __( 'Reject a staged revision, sending it back for further changes.', 'editorial-io' ),
			'category'            => 'editorial-io',
			'callback'            => array( $this, 'ability_revision_reject' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'revision_id' ),
				'properties' => array(
					'revision_id' => array(
						'type'        => 'integer',
						'description' => __( 'The staged revision ID to reject.', 'editorial-io' ),
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

		wp_register_ability( 'editorial-io/revision.publish', array(
			'label'               => __( 'Publish Staged Revision', 'editorial-io' ),
			'description'         => __( 'Publish a staged revision, replacing the live post content immediately.', 'editorial-io' ),
			'category'            => 'editorial-io',
			'callback'            => array( $this, 'ability_revision_publish' ),
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
						'description' => __( 'The staged revision ID to publish.', 'editorial-io' ),
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

		wp_register_ability( 'editorial-io/revision.discard', array(
			'label'               => __( 'Discard Staged Revision', 'editorial-io' ),
			'description'         => __( 'Permanently delete a staged revision.', 'editorial-io' ),
			'category'            => 'editorial-io',
			'callback'            => array( $this, 'ability_revision_discard' ),
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
						'description' => __( 'The staged revision ID to discard.', 'editorial-io' ),
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
		wp_register_ability( 'editorial-io/checklist.get', array(
			'label'               => __( 'Get Checklist Items', 'editorial-io' ),
			'description'         => __( 'Retrieve the publication checklist items and their configuration.', 'editorial-io' ),
			'category'            => 'editorial-io',
			'callback'            => array( $this, 'ability_checklist_get' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => new \stdClass(),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'label'    => array( 'type' => 'string' ),
						'required' => array( 'type' => 'boolean' ),
					),
				),
			),
			'meta'                => array(
				'show_in_rest' => true,
				'readonly'     => true,
			),
		) );

		wp_register_ability( 'editorial-io/checklist.validate', array(
			'label'               => __( 'Validate Publication Checklist', 'editorial-io' ),
			'description'         => __( 'Validate that all required checklist items are checked before publishing.', 'editorial-io' ),
			'category'            => 'editorial-io',
			'callback'            => array( $this, 'ability_checklist_validate' ),
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
						'description' => __( 'The post ID being published.', 'editorial-io' ),
					),
					'checked_items' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => __( 'Array of checked item indices (0-based).', 'editorial-io' ),
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
					'completed_items'  => array( 'type' => 'integer' ),
					'required_items'   => array( 'type' => 'integer' ),
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );
	}

	/**
	 * Register scheduling abilities.
	 */
	private function register_scheduling_abilities() {
		wp_register_ability( 'editorial-io/revision.schedule', array(
			'label'               => __( 'Schedule Staged Revision', 'editorial-io' ),
			'description'         => __( 'Schedule a staged revision to be published at a specific future date and time.', 'editorial-io' ),
			'category'            => 'editorial-io',
			'callback'            => array( $this, 'ability_revision_schedule' ),
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
						'description' => __( 'The staged revision ID to schedule.', 'editorial-io' ),
					),
					'publish_date' => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Future date/time for publication (Y-m-d H:i:s format).', 'editorial-io' ),
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
	 * Register revision timeline abilities.
	 */
	private function register_timeline_abilities() {
		wp_register_ability( 'editorial-io/timeline.get', array(
			'label'               => __( 'Get Revision Timeline', 'editorial-io' ),
			'description'         => __( 'Retrieve the revision timeline for a post, including change metadata and author information.', 'editorial-io' ),
			'category'            => 'editorial-io',
			'callback'            => array( $this, 'ability_timeline_get' ),
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
						'description' => __( 'The post ID to get the timeline for.', 'editorial-io' ),
					),
					'per_page'          => array(
						'type'        => 'integer',
						'default'     => 50,
						'description' => __( 'Number of revisions to return.', 'editorial-io' ),
					),
					'include_autosaves' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Whether to include autosave revisions.', 'editorial-io' ),
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

		wp_register_ability( 'editorial-io/revision.diff', array(
			'label'               => __( 'Get Revision Diff', 'editorial-io' ),
			'description'         => __( 'Get a detailed diff between a revision and its predecessor, including word-level changes and media changes when enabled.', 'editorial-io' ),
			'category'            => 'editorial-io',
			'callback'            => array( $this, 'ability_revision_diff' ),
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
						'description' => __( 'The revision ID to generate a diff for.', 'editorial-io' ),
					),
					'compare_to'  => array(
						'type'        => 'integer',
						'description' => __( 'Revision ID to compare against. Defaults to the previous revision.', 'editorial-io' ),
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

		wp_register_ability( 'editorial-io/revision.restore', array(
			'label'               => __( 'Restore Revision', 'editorial-io' ),
			'description'         => __( 'Restore a post to a previous revision, replacing its current content.', 'editorial-io' ),
			'category'            => 'editorial-io',
			'callback'            => array( $this, 'ability_revision_restore' ),
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
						'description' => __( 'The revision ID to restore the post to.', 'editorial-io' ),
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
							__( 'Cannot enable %s because its dependencies are not met.', 'editorial-io' ),
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
								__( 'Disabled %1$s because it requires %2$s.', 'editorial-io' ),
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
			return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is disabled.', 'editorial-io' ) );
		}

		$post_data = array(
			'title'   => $input['title'] ?? null,
			'content' => $input['content'] ?? null,
			'excerpt' => $input['excerpt'] ?? null,
		);

		$meta_data = array(
			'notes' => $input['notes'] ?? '',
		);

		$revision_id = Editorial_IO_Staged_Revisions::create( $input['post_id'], $post_data, $meta_data );

		if ( is_wp_error( $revision_id ) ) {
			return $revision_id;
		}

		$revision = Editorial_IO_Staged_Revisions::get_by_id( $revision_id );
		return Editorial_IO_Staged_Revisions::format_for_response( $revision );
	}

	/**
	 * Callback: Get staged revision.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_revision_get( $input ) {
		if ( ! $this->settings->is_feature_enabled( 'staged_revisions' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is disabled.', 'editorial-io' ) );
		}

		$revision = Editorial_IO_Staged_Revisions::get( $input['post_id'] );

		if ( ! $revision ) {
			return new WP_Error( 'not_found', __( 'No staged revision found for this post.', 'editorial-io' ) );
		}

		return Editorial_IO_Staged_Revisions::format_for_response( $revision );
	}

	/**
	 * Callback: Approve staged revision.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_revision_approve( $input ) {
		if ( ! $this->settings->is_feature_enabled( 'staged_revisions' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is disabled.', 'editorial-io' ) );
		}

		$result = Editorial_IO_Staged_Revisions::approve( $input['revision_id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$revision = Editorial_IO_Staged_Revisions::get_by_id( $input['revision_id'] );
		return array(
			'success'  => true,
			'revision' => Editorial_IO_Staged_Revisions::format_for_response( $revision ),
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
			return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is disabled.', 'editorial-io' ) );
		}

		$result = Editorial_IO_Staged_Revisions::reject( $input['revision_id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$revision = Editorial_IO_Staged_Revisions::get_by_id( $input['revision_id'] );
		return array(
			'success'  => true,
			'revision' => Editorial_IO_Staged_Revisions::format_for_response( $revision ),
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
			return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is disabled.', 'editorial-io' ) );
		}

		$result = Editorial_IO_Staged_Revisions::publish( $input['revision_id'] );

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
			return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is disabled.', 'editorial-io' ) );
		}

		$result = Editorial_IO_Staged_Revisions::discard( $input['revision_id'] );

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
			return new WP_Error( 'feature_disabled', __( 'Publication checklist feature is disabled.', 'editorial-io' ) );
		}

		return $this->settings->get_checklist_items();
	}

	/**
	 * Callback: Validate publication checklist.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_checklist_validate( $input ) {
		if ( ! $this->settings->is_feature_enabled( 'publication_checklist' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Publication checklist feature is disabled.', 'editorial-io' ) );
		}

		$checklist = Editorial_IO_Publication_Checklist::get_instance();
		$checked_items = array_map( 'absint', $input['checked_items'] );
		$result = $checklist->validate_checklist( $checked_items );

		if ( $result['valid'] ) {
			update_post_meta( $input['post_id'], '_editorial_checklist_bypassed', true );
		}

		return $result;
	}

	/**
	 * Callback: Schedule staged revision.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_revision_schedule( $input ) {
		if ( ! $this->settings->is_feature_enabled( 'scheduled_publishing' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Scheduled publishing feature is disabled.', 'editorial-io' ) );
		}

		$result = Editorial_IO_Scheduled_Publishing::schedule_staged_revision(
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
			return new WP_Error( 'feature_disabled', __( 'Revision timeline feature is disabled.', 'editorial-io' ) );
		}

		$post = get_post( $input['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'editorial-io' ) );
		}

		$args = array(
			'per_page'          => $input['per_page'] ?? 50,
			'include_autosaves' => $input['include_autosaves'] ?? false,
		);

		return Editorial_IO_Revision_Timeline::get_timeline_data( $input['post_id'], $args );
	}

	/**
	 * Callback: Get revision diff.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_revision_diff( $input ) {
		if ( ! $this->settings->is_feature_enabled( 'revision_timeline' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Revision timeline feature is disabled.', 'editorial-io' ) );
		}

		$revision = get_post( $input['revision_id'] );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return new WP_Error( 'not_found', __( 'Revision not found.', 'editorial-io' ) );
		}

		// Determine what to compare against.
		if ( ! empty( $input['compare_to'] ) ) {
			$compare_post = get_post( $input['compare_to'] );
			if ( ! $compare_post ) {
				return new WP_Error( 'not_found', __( 'Comparison revision not found.', 'editorial-io' ) );
			}
		} else {
			$compare_post = $this->get_previous_revision( $revision );
			if ( ! $compare_post ) {
				$compare_post = get_post( $revision->post_parent );
			}
		}

		// Generate diff using word-level diffs if enabled.
		if ( $this->settings->is_feature_enabled( 'word_level_diffs' ) && class_exists( 'Editorial_IO_Word_Level_Diffs' ) ) {
			$diff_data = Editorial_IO_Word_Level_Diffs::generate_diff( $compare_post, $revision );
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
		if ( $this->settings->is_feature_enabled( 'media_change_tracking' ) && class_exists( 'Editorial_IO_Media_Change_Tracking' ) ) {
			$diff_data['media_changes'] = Editorial_IO_Media_Change_Tracking::get_media_changes(
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
			return new WP_Error( 'feature_disabled', __( 'Revision timeline feature is disabled.', 'editorial-io' ) );
		}

		$revision = get_post( $input['revision_id'] );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return new WP_Error( 'not_found', __( 'Revision not found.', 'editorial-io' ) );
		}

		$restored = wp_restore_post_revision( $input['revision_id'] );

		if ( ! $restored ) {
			return new WP_Error( 'restore_failed', __( 'Failed to restore revision.', 'editorial-io' ) );
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
}
