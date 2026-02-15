<?php
/**
 * Unified REST API controller for Editorial.io
 *
 * @package EditorialIO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Editorial_IO_REST_Controller
 *
 * Unified REST API controller combining staged revisions and revision timeline functionality.
 */
class Editorial_IO_REST_Controller extends WP_REST_Controller {

	/**
	 * Settings instance.
	 *
	 * @var Editorial_IO_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'editorial/v1';
		$this->rest_base = '';
		$this->settings  = Editorial_IO_Settings::get_instance();
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// Staged Revisions routes (if feature is enabled).
		if ( $this->settings->is_feature_enabled( 'staged_revisions' ) ) {
			$this->register_staged_revision_routes();
		}

		// Revision Timeline routes (if feature is enabled).
		if ( $this->settings->is_feature_enabled( 'revision_timeline' ) ) {
			$this->register_revision_timeline_routes();
		}

		// General utility routes.
		$this->register_utility_routes();
	}

	/**
	 * Register staged revision routes.
	 */
	private function register_staged_revision_routes() {
		// GET /editorial/v1/staged - List all staged revisions.
		register_rest_route(
			$this->namespace,
			'/staged',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_staged_revisions' ),
					'permission_callback' => array( $this, 'get_staged_revisions_permissions_check' ),
					'args'                => array(
						'status'   => array(
							'type'              => 'string',
							'enum'              => array( 'pending', 'approved', 'rejected', 'scheduled' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 20,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// GET/POST /editorial/v1/posts/{post_id}/staged - Get or create staged revision for a post.
		register_rest_route(
			$this->namespace,
			'/posts/(?P<post_id>[\d]+)/staged',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_staged_revision' ),
					'permission_callback' => array( $this, 'staged_revision_permissions_check' ),
					'args'                => array(
						'post_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_staged_revision' ),
					'permission_callback' => array( $this, 'staged_revision_permissions_check' ),
					'args'                => $this->get_staged_revision_create_args(),
				),
			)
		);

		// POST /editorial/v1/staged/{revision_id}/publish - Publish a staged revision.
		register_rest_route(
			$this->namespace,
			'/staged/(?P<revision_id>[\d]+)/publish',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'publish_staged_revision' ),
					'permission_callback' => array( $this, 'publish_staged_revision_permissions_check' ),
					'args'                => array(
						'revision_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// POST /editorial/v1/staged/{revision_id}/schedule - Schedule a staged revision.
		register_rest_route(
			$this->namespace,
			'/staged/(?P<revision_id>[\d]+)/schedule',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'schedule_staged_revision' ),
					'permission_callback' => array( $this, 'publish_staged_revision_permissions_check' ),
					'args'                => array(
						'revision_id'  => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'publish_date' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// POST /editorial/v1/staged/{revision_id}/approve - Approve a staged revision.
		register_rest_route(
			$this->namespace,
			'/staged/(?P<revision_id>[\d]+)/approve',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'approve_staged_revision' ),
					'permission_callback' => array( $this, 'approve_staged_revision_permissions_check' ),
					'args'                => array(
						'revision_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// POST /editorial/v1/staged/{revision_id}/reject - Reject a staged revision.
		register_rest_route(
			$this->namespace,
			'/staged/(?P<revision_id>[\d]+)/reject',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reject_staged_revision' ),
					'permission_callback' => array( $this, 'approve_staged_revision_permissions_check' ),
					'args'                => array(
						'revision_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// DELETE /editorial/v1/staged/{revision_id} - Discard a staged revision.
		register_rest_route(
			$this->namespace,
			'/staged/(?P<revision_id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_staged_revision' ),
					'permission_callback' => array( $this, 'staged_revision_permissions_check' ),
					'args'                => array(
						'revision_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Register revision timeline routes.
	 */
	private function register_revision_timeline_routes() {
		// GET /editorial/v1/posts/{post_id}/revisions - List revisions for a post.
		register_rest_route(
			$this->namespace,
			'/posts/(?P<post_id>[\d]+)/revisions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post_revisions' ),
					'permission_callback' => array( $this, 'get_post_revisions_permissions_check' ),
					'args'                => array(
						'post_id'  => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'default'           => 50,
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
						'include_autosaves' => array(
							'default'           => false,
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);

		// GET /editorial/v1/revisions/{revision_id}/diff - Get diff for a revision.
		register_rest_route(
			$this->namespace,
			'/revisions/(?P<revision_id>[\d]+)/diff',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_revision_diff' ),
					'permission_callback' => array( $this, 'get_revision_permissions_check' ),
					'args'                => array(
						'revision_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'compare_to'  => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => 'Revision ID to compare to. Defaults to previous revision.',
						),
					),
				),
			)
		);

		// POST /editorial/v1/revisions/{revision_id}/restore - Restore a revision.
		register_rest_route(
			$this->namespace,
			'/revisions/(?P<revision_id>[\d]+)/restore',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'restore_revision' ),
					'permission_callback' => array( $this, 'restore_revision_permissions_check' ),
					'args'                => array(
						'revision_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// GET /editorial/v1/recent - Recent revisions across all posts (admin).
		register_rest_route(
			$this->namespace,
			'/recent',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_recent_revisions' ),
					'permission_callback' => array( $this, 'get_recent_revisions_permissions_check' ),
					'args'                => $this->get_recent_revisions_args(),
				),
			)
		);
	}

	/**
	 * Register utility routes.
	 */
	private function register_utility_routes() {
		// GET /editorial/v1/features - Get enabled features.
		register_rest_route(
			$this->namespace,
			'/features',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_features' ),
					'permission_callback' => array( $this, 'get_features_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Get staged revisions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_staged_revisions( $request ) {
		if ( ! class_exists( 'Editorial_IO_Staged_Revisions' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is disabled.', 'editorial-io' ), array( 'status' => 404 ) );
		}

		$args = array(
			'status'   => $request->get_param( 'status' ),
			'per_page' => $request->get_param( 'per_page' ),
			'page'     => $request->get_param( 'page' ),
		);

		$items = Editorial_IO_Staged_Revisions::get_all( $args );

		$data = array();
		foreach ( $items as $item ) {
			$data[] = $this->format_staged_revision_for_response( $item );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Get post revisions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_post_revisions( $request ) {
		$post_id         = $request->get_param( 'post_id' );
		$per_page        = $request->get_param( 'per_page' );
		$include_autosaves = $request->get_param( 'include_autosaves' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'editorial-io' ), array( 'status' => 404 ) );
		}

		// Get revisions.
		$revision_args = array(
			'posts_per_page' => $per_page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$revisions = wp_get_post_revisions( $post_id, $revision_args );

		// Filter out autosaves if not requested.
		if ( ! $include_autosaves ) {
			$revisions = array_filter( $revisions, function( $revision ) {
				return ! wp_is_post_autosave( $revision->ID );
			});
		}

		$data           = array();
		$revision_array = array_values( $revisions );

		foreach ( $revision_array as $index => $revision ) {
			// Get previous revision for comparison.
			$previous = isset( $revision_array[ $index + 1 ] )
				? $revision_array[ $index + 1 ]
				: $post;
			$data[]   = $this->format_revision_for_response( $revision, $previous );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Get revision diff.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_revision_diff( $request ) {
		$revision_id = $request->get_param( 'revision_id' );
		$compare_to  = $request->get_param( 'compare_to' );

		$revision = get_post( $revision_id );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return new WP_Error( 'not_found', __( 'Revision not found.', 'editorial-io' ), array( 'status' => 404 ) );
		}

		// Determine what to compare against.
		if ( $compare_to ) {
			$compare_post = get_post( $compare_to );
			if ( ! $compare_post ) {
				return new WP_Error( 'not_found', __( 'Comparison revision not found.', 'editorial-io' ), array( 'status' => 404 ) );
			}
		} else {
			$compare_post = $this->get_previous_revision( $revision );
			if ( ! $compare_post ) {
				$compare_post = get_post( $revision->post_parent );
			}
		}

		// Generate diff using the word-level diff feature if enabled.
		if ( $this->settings->is_feature_enabled( 'word_level_diffs' ) && class_exists( 'Editorial_IO_Word_Level_Diffs' ) ) {
			$diff_data = Editorial_IO_Word_Level_Diffs::generate_diff( $compare_post, $revision );
		} else {
			// Fallback to basic diff.
			$diff_data = $this->generate_basic_diff( $compare_post, $revision );
		}

		// Add media changes if feature is enabled.
		if ( $this->settings->is_feature_enabled( 'media_change_tracking' ) && class_exists( 'Editorial_IO_Media_Change_Tracking' ) ) {
			$diff_data['media_changes'] = Editorial_IO_Media_Change_Tracking::get_media_changes(
				$compare_post->post_content,
				$revision->post_content
			);
		}

		return rest_ensure_response( $diff_data );
	}

	/**
	 * Get enabled features.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_features( $request ) {
		$features = $this->settings->get_enabled_features();
		$available = $this->settings->get_available_features();

		$response = array();
		foreach ( $features as $key => $enabled ) {
			$response[ $key ] = array(
				'enabled' => $enabled,
				'label'   => $available[ $key ]['label'] ?? $key,
			);
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Get arguments for staged revision creation.
	 *
	 * @return array
	 */
	private function get_staged_revision_create_args() {
		return array(
			'post_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'title'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'content' => array(
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
			),
			'excerpt' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'notes'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
		);
	}

	/**
	 * Get arguments for recent revisions query.
	 *
	 * @return array
	 */
	private function get_recent_revisions_args() {
		return array(
			'per_page' => array(
				'default'           => 20,
				'type'              => 'integer',
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'page'     => array(
				'default'           => 1,
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'author'   => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'post_id'  => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'after'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'before'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Format staged revision for API response.
	 *
	 * @param object $revision The revision data.
	 * @return array
	 */
	private function format_staged_revision_for_response( $revision ) {
		return array(
			'revision_id'    => (int) $revision->revision_id,
			'post_id'        => (int) $revision->post_parent,
			'post_title'     => $revision->post_title,
			'post_type'      => $revision->post_type,
			'revision_title' => $revision->revision_title ?? $revision->post_title,
			'author'         => (int) $revision->staged_author_id,
			'author_name'    => $this->get_author_name( $revision->staged_author_id ),
			'status'         => $revision->staged_status ?? 'pending',
			'scheduled_date' => $revision->scheduled_date ?? null,
			'notes'          => $revision->notes ?? '',
			'modified'       => $revision->post_modified,
		);
	}

	/**
	 * Format revision for API response.
	 *
	 * @param WP_Post $revision  The revision post.
	 * @param WP_Post $compare   The post to compare against.
	 * @return array
	 */
	private function format_revision_for_response( $revision, $compare ) {
		$author      = get_userdata( $revision->post_author );
		$is_autosave = wp_is_post_autosave( $revision->ID );
		$is_staged   = get_metadata( 'post', $revision->ID, '_editorial_staged_revision', true );

		// Determine what changed.
		$changes = array();
		if ( $revision->post_title !== $compare->post_title ) {
			$changes[] = 'title';
		}
		if ( $revision->post_content !== $compare->post_content ) {
			$changes[] = 'content';
		}
		if ( $revision->post_excerpt !== $compare->post_excerpt ) {
			$changes[] = 'excerpt';
		}

		return array(
			'id'            => $revision->ID,
			'parent_id'     => $revision->post_parent,
			'title'         => $revision->post_title,
			'date'          => $revision->post_modified,
			'date_gmt'      => $revision->post_modified_gmt,
			'date_relative' => human_time_diff( strtotime( $revision->post_modified_gmt ), time() ),
			'author'        => array(
				'id'     => $revision->post_author,
				'name'   => $author ? $author->display_name : __( 'Unknown', 'editorial-io' ),
				'avatar' => get_avatar_url( $revision->post_author, array( 'size' => 48 ) ),
			),
			'type'          => $is_staged ? 'staged' : ( $is_autosave ? 'autosave' : 'manual' ),
			'changes'       => $changes,
			'is_staged'     => $is_staged,
		);
	}

	/**
	 * Get author display name.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function get_author_name( $user_id ) {
		$user = get_userdata( $user_id );
		return $user ? $user->display_name : __( 'Unknown', 'editorial-io' );
	}

	/**
	 * Generate basic diff (fallback when word-level diffs are disabled).
	 *
	 * @param WP_Post $from_post The original post.
	 * @param WP_Post $to_post   The revision post.
	 * @return array
	 */
	private function generate_basic_diff( $from_post, $to_post ) {
		$fields = array(
			'title'   => array(
				'from' => $from_post->post_title,
				'to'   => $to_post->post_title,
			),
			'content' => array(
				'from' => wp_strip_all_tags( $from_post->post_content ),
				'to'   => wp_strip_all_tags( $to_post->post_content ),
			),
			'excerpt' => array(
				'from' => wp_strip_all_tags( $from_post->post_excerpt ),
				'to'   => wp_strip_all_tags( $to_post->post_excerpt ),
			),
		);

		return array(
			'revision_id' => $to_post->ID,
			'compare_to'  => $from_post->ID,
			'fields'      => $fields,
		);
	}

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

	// Permission check methods.

	/**
	 * Check permissions for getting staged revisions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function get_staged_revisions_permissions_check( $request ) {
		return current_user_can( 'edit_others_posts' );
	}

	/**
	 * Check permissions for staged revision operations.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function staged_revision_permissions_check( $request ) {
		$post_id = $request->get_param( 'post_id' ) ?? null;
		$revision_id = $request->get_param( 'revision_id' ) ?? null;

		if ( $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		}

		if ( $revision_id ) {
			$revision = get_post( $revision_id );
			return $revision && current_user_can( 'edit_post', $revision->post_parent );
		}

		return false;
	}

	/**
	 * Check permissions for publishing staged revisions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function publish_staged_revision_permissions_check( $request ) {
		$revision_id = $request->get_param( 'revision_id' );
		$revision    = get_post( $revision_id );

		if ( ! $revision ) {
			return new WP_Error( 'not_found', __( 'Staged revision not found.', 'editorial-io' ), array( 'status' => 404 ) );
		}

		return current_user_can( 'publish_posts' ) && current_user_can( 'edit_post', $revision->post_parent );
	}

	/**
	 * Check permissions for approving staged revisions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function approve_staged_revision_permissions_check( $request ) {
		return current_user_can( 'edit_others_posts' );
	}

	/**
	 * Check permissions for getting post revisions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function get_post_revisions_permissions_check( $request ) {
		$post_id = $request->get_param( 'post_id' );
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Check permissions for getting revision details.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function get_revision_permissions_check( $request ) {
		$revision_id = $request->get_param( 'revision_id' );
		$revision    = get_post( $revision_id );

		if ( ! $revision ) {
			return new WP_Error( 'not_found', __( 'Revision not found.', 'editorial-io' ), array( 'status' => 404 ) );
		}

		return current_user_can( 'edit_post', $revision->post_parent );
	}

	/**
	 * Check permissions for restoring revisions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function restore_revision_permissions_check( $request ) {
		$revision_id = $request->get_param( 'revision_id' );
		$revision    = get_post( $revision_id );

		if ( ! $revision ) {
			return new WP_Error( 'not_found', __( 'Revision not found.', 'editorial-io' ), array( 'status' => 404 ) );
		}

		return current_user_can( 'edit_post', $revision->post_parent );
	}

	/**
	 * Check permissions for getting recent revisions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function get_recent_revisions_permissions_check( $request ) {
		return current_user_can( 'edit_others_posts' );
	}

	/**
	 * Check permissions for getting features.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function get_features_permissions_check( $request ) {
		return current_user_can( 'edit_posts' );
	}

	// Placeholder methods for feature-specific functionality.
	// These will delegate to the actual feature classes when available.

	/**
	 * Create staged revision (placeholder - delegates to feature class).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_staged_revision( $request ) {
		if ( class_exists( 'Editorial_IO_Staged_Revisions' ) ) {
			return Editorial_IO_Staged_Revisions::rest_create_staged_revision( $request );
		}
		return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is disabled.', 'editorial-io' ), array( 'status' => 404 ) );
	}

	/**
	 * Get staged revision (placeholder).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_staged_revision( $request ) {
		if ( class_exists( 'Editorial_IO_Staged_Revisions' ) ) {
			return Editorial_IO_Staged_Revisions::rest_get_staged_revision( $request );
		}
		return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is disabled.', 'editorial-io' ), array( 'status' => 404 ) );
	}

	/**
	 * Publish staged revision (placeholder).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function publish_staged_revision( $request ) {
		if ( class_exists( 'Editorial_IO_Staged_Revisions' ) ) {
			return Editorial_IO_Staged_Revisions::rest_publish_staged_revision( $request );
		}
		return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is disabled.', 'editorial-io' ), array( 'status' => 404 ) );
	}

	/**
	 * Schedule staged revision (placeholder).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function schedule_staged_revision( $request ) {
		if ( class_exists( 'Editorial_IO_Scheduled_Publishing' ) ) {
			return Editorial_IO_Scheduled_Publishing::rest_schedule_staged_revision( $request );
		}
		return new WP_Error( 'feature_disabled', __( 'Scheduled publishing feature is disabled.', 'editorial-io' ), array( 'status' => 404 ) );
	}

	/**
	 * Approve staged revision (placeholder).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_staged_revision( $request ) {
		if ( class_exists( 'Editorial_IO_Staged_Revisions' ) ) {
			return Editorial_IO_Staged_Revisions::rest_approve_staged_revision( $request );
		}
		return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is disabled.', 'editorial-io' ), array( 'status' => 404 ) );
	}

	/**
	 * Reject staged revision (placeholder).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reject_staged_revision( $request ) {
		if ( class_exists( 'Editorial_IO_Staged_Revisions' ) ) {
			return Editorial_IO_Staged_Revisions::rest_reject_staged_revision( $request );
		}
		return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is disabled.', 'editorial-io' ), array( 'status' => 404 ) );
	}

	/**
	 * Delete staged revision (placeholder).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_staged_revision( $request ) {
		if ( class_exists( 'Editorial_IO_Staged_Revisions' ) ) {
			return Editorial_IO_Staged_Revisions::rest_delete_staged_revision( $request );
		}
		return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is disabled.', 'editorial-io' ), array( 'status' => 404 ) );
	}

	/**
	 * Restore revision (placeholder).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_revision( $request ) {
		if ( class_exists( 'Editorial_IO_Revision_Timeline' ) ) {
			return Editorial_IO_Revision_Timeline::rest_restore_revision( $request );
		}
		return new WP_Error( 'feature_disabled', __( 'Revision timeline feature is disabled.', 'editorial-io' ), array( 'status' => 404 ) );
	}

	/**
	 * Get recent revisions (placeholder).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_recent_revisions( $request ) {
		if ( class_exists( 'Editorial_IO_Revision_Timeline' ) ) {
			return Editorial_IO_Revision_Timeline::rest_get_recent_revisions( $request );
		}
		return new WP_Error( 'feature_disabled', __( 'Revision timeline feature is disabled.', 'editorial-io' ), array( 'status' => 404 ) );
	}
}