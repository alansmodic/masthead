<?php
/**
 * Staged Revisions feature for Editorial.io
 *
 * @package EditorialIO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Editorial_IO_Staged_Revisions
 *
 * Handles staged revisions functionality - save changes without immediately publishing.
 */
class Editorial_IO_Staged_Revisions {

	/**
	 * Singleton instance.
	 *
	 * @var Editorial_IO_Staged_Revisions|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Editorial_IO_Staged_Revisions
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
		add_action( 'init', array( $this, 'register_post_status' ) );
		add_action( 'editorial_io_cleanup', array( $this, 'cleanup_old_staged_revisions' ) );
		add_filter( 'wp_save_post_revision_post_has_changed', array( $this, 'protect_staged_revisions' ), 10, 3 );
	}

	/**
	 * Register custom post status for staged revisions.
	 */
	public function register_post_status() {
		register_post_status( 'staged', array(
			'label'                     => _x( 'Staged', 'post status', 'editorial-io' ),
			'public'                    => false,
			'internal'                  => true,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => false,
			'label_count'               => _n_noop(
				'Staged <span class="count">(%s)</span>',
				'Staged <span class="count">(%s)</span>',
				'editorial-io'
			),
		) );
	}

	/**
	 * Create a staged revision.
	 *
	 * @param int   $post_id   The parent post ID.
	 * @param array $post_data The post data.
	 * @param array $meta_data Optional meta data.
	 * @return int|WP_Error The revision ID or error.
	 */
	public static function create( $post_id, $post_data, $meta_data = array() ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'invalid_post', __( 'Post not found.', 'editorial-io' ) );
		}

		if ( ! Editorial_IO::post_type_supports_editorial( $post->post_type ) ) {
			return new WP_Error( 'unsupported_post_type', __( 'Post type does not support staged revisions.', 'editorial-io' ) );
		}

		// Check if there's already a staged revision — update it in place.
		$existing_staged = self::get( $post_id );
		if ( $existing_staged ) {
			$revision_id = $existing_staged->revision_id;

			$update_data = array(
				'ID'           => $revision_id,
				'post_title'   => isset( $post_data['title'] ) ? $post_data['title'] : $post->post_title,
				'post_content' => isset( $post_data['content'] ) ? $post_data['content'] : $post->post_content,
				'post_excerpt' => isset( $post_data['excerpt'] ) ? $post_data['excerpt'] : $post->post_excerpt,
			);

			$result = wp_update_post( $update_data );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Reset status to pending on update.
			update_metadata( 'post', $revision_id, '_editorial_staged_status', 'pending' );
			update_metadata( 'post', $revision_id, '_editorial_staged_author', get_current_user_id() );

			// Update notes if provided.
			if ( ! empty( $meta_data['notes'] ) ) {
				update_metadata( 'post', $revision_id, '_editorial_staged_notes', sanitize_textarea_field( $meta_data['notes'] ) );
			}

			/** This action is documented below. */
			do_action( 'editorial_io_staged_revision_created', $revision_id, $post_id, $post_data, $meta_data );

			return $revision_id;
		}

		// Create revision data.
		$revision_data = array(
			'post_parent'   => $post_id,
			'post_type'     => 'revision',
			'post_title'    => isset( $post_data['title'] ) ? $post_data['title'] : $post->post_title,
			'post_content'  => isset( $post_data['content'] ) ? $post_data['content'] : $post->post_content,
			'post_excerpt'  => isset( $post_data['excerpt'] ) ? $post_data['excerpt'] : $post->post_excerpt,
			'post_author'   => get_current_user_id(),
			'post_status'   => 'inherit',
			'post_name'     => $post_id . '-staged-' . time(),
		);

		$revision_id = wp_insert_post( $revision_data );
		if ( is_wp_error( $revision_id ) ) {
			return $revision_id;
		}

		// Add staged revision meta.
		update_metadata( 'post', $revision_id, '_editorial_staged_revision', true );
		update_metadata( 'post', $revision_id, '_editorial_staged_status', 'pending' );
		update_metadata( 'post', $revision_id, '_editorial_staged_author', get_current_user_id() );
		update_metadata( 'post', $revision_id, '_editorial_revision_type', 'staged' );

		// Add custom meta data.
		if ( ! empty( $meta_data['notes'] ) ) {
			update_metadata( 'post', $revision_id, '_editorial_staged_notes', sanitize_textarea_field( $meta_data['notes'] ) );
		}

		// Update parent post meta.
		update_post_meta( $post_id, '_editorial_has_staged_revision', $revision_id );

		/**
		 * Fired after a staged revision is created.
		 *
		 * @param int     $revision_id The revision ID.
		 * @param int     $post_id     The parent post ID.
		 * @param array   $post_data   The post data.
		 * @param array   $meta_data   The meta data.
		 */
		do_action( 'editorial_io_staged_revision_created', $revision_id, $post_id, $post_data, $meta_data );

		return $revision_id;
	}

	/**
	 * Get staged revision for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return object|null The revision data or null.
	 */
	public static function get( $post_id ) {
		$revision_id = get_post_meta( $post_id, '_editorial_has_staged_revision', true );
		if ( ! $revision_id ) {
			return null;
		}

		$revision = get_post( $revision_id );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			// Clean up orphaned meta.
			delete_post_meta( $post_id, '_editorial_has_staged_revision' );
			return null;
		}

		return self::format_revision_data( $revision );
	}

	/**
	 * Get staged revision by revision ID.
	 *
	 * @param int $revision_id The revision ID.
	 * @return object|null The revision data or null.
	 */
	public static function get_by_id( $revision_id ) {
		$revision = get_post( $revision_id );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return null;
		}

		if ( ! get_metadata( 'post', $revision_id, '_editorial_staged_revision', true ) ) {
			return null;
		}

		return self::format_revision_data( $revision );
	}

	/**
	 * Get all staged revisions.
	 *
	 * @param array $args Query arguments.
	 * @return array Staged revisions.
	 */
	public static function get_all( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'   => '',
			'per_page' => 20,
			'page'     => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$where_clauses = array(
			"r.post_type = 'revision'",
			"sr.meta_key = '_editorial_staged_revision'",
			"sr.meta_value = '1'",
		);
		$join_clauses = array(
			"INNER JOIN {$wpdb->postmeta} sr ON r.ID = sr.post_id",
			"INNER JOIN {$wpdb->posts} p ON r.post_parent = p.ID",
		);

		// Filter by status.
		if ( ! empty( $args['status'] ) ) {
			$join_clauses[] = "INNER JOIN {$wpdb->postmeta} sm ON r.ID = sm.post_id AND sm.meta_key = '_editorial_staged_status'";
			$where_clauses[] = $wpdb->prepare( "sm.meta_value = %s", $args['status'] );
		}

		$offset = ( $args['page'] - 1 ) * $args['per_page'];
		$limit = $args['per_page'];

		$query = "
			SELECT r.*, p.post_title as parent_title, p.post_type as parent_type
			FROM {$wpdb->posts} r
			" . implode( ' ', $join_clauses ) . "
			WHERE " . implode( ' AND ', $where_clauses ) . "
			ORDER BY r.post_modified DESC
			LIMIT {$limit} OFFSET {$offset}
		";

		$results = $wpdb->get_results( $query );
		$formatted = array();

		foreach ( $results as $revision ) {
			$formatted[] = self::format_revision_data( $revision );
		}

		return $formatted;
	}

	/**
	 * Get recent staged revisions.
	 *
	 * @param int $limit Number of revisions to fetch.
	 * @return array
	 */
	public static function get_recent( $limit = 10 ) {
		return self::get_all( array(
			'per_page' => $limit,
			'page'     => 1,
		) );
	}

	/**
	 * Publish a staged revision.
	 *
	 * @param int $revision_id The revision ID.
	 * @return int|WP_Error The post ID or error.
	 */
	public static function publish( $revision_id ) {
		$revision = self::get_by_id( $revision_id );
		if ( ! $revision ) {
			return new WP_Error( 'revision_not_found', __( 'Staged revision not found.', 'editorial-io' ) );
		}

		$post_id = $revision->post_parent;
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', __( 'Parent post not found.', 'editorial-io' ) );
		}

		// Restore the parent post from the staged revision using core's revision restore.
		$result = wp_restore_post_revision( $revision->revision_id );
		if ( ! $result || is_wp_error( $result ) ) {
			return is_wp_error( $result ) ? $result : new WP_Error( 'publish_failed', __( 'Failed to publish staged revision.', 'editorial-io' ) );
		}

		// Clean up staged revision.
		self::cleanup_staged_revision( $revision_id );

		/**
		 * Fired after a staged revision is published.
		 *
		 * @param int $post_id     The post ID.
		 * @param int $revision_id The revision ID.
		 */
		do_action( 'editorial_io_staged_revision_published', $post_id, $revision_id );

		return $post_id;
	}

	/**
	 * Approve a staged revision.
	 *
	 * @param int $revision_id The revision ID.
	 * @return bool|WP_Error Success or error.
	 */
	public static function approve( $revision_id ) {
		$revision = self::get_by_id( $revision_id );
		if ( ! $revision ) {
			return new WP_Error( 'revision_not_found', __( 'Staged revision not found.', 'editorial-io' ) );
		}

		update_metadata( 'post', $revision_id, '_editorial_staged_status', 'approved' );

		/**
		 * Fired after a staged revision is approved.
		 *
		 * @param int $revision_id The revision ID.
		 */
		do_action( 'editorial_io_staged_revision_approved', $revision_id );

		return true;
	}

	/**
	 * Reject a staged revision.
	 *
	 * @param int $revision_id The revision ID.
	 * @return bool|WP_Error Success or error.
	 */
	public static function reject( $revision_id ) {
		$revision = self::get_by_id( $revision_id );
		if ( ! $revision ) {
			return new WP_Error( 'revision_not_found', __( 'Staged revision not found.', 'editorial-io' ) );
		}

		update_metadata( 'post', $revision_id, '_editorial_staged_status', 'rejected' );

		// Clear any scheduled publishing for this revision.
		wp_clear_scheduled_hook( 'editorial_io_publish_staged', array( $revision_id ) );
		delete_metadata( 'post', $revision_id, '_editorial_staged_publish_date' );

		/**
		 * Fired after a staged revision is rejected.
		 *
		 * @param int $revision_id The revision ID.
		 */
		do_action( 'editorial_io_staged_revision_rejected', $revision_id );

		return true;
	}

	/**
	 * Discard a staged revision.
	 *
	 * @param int $revision_id The revision ID.
	 * @return bool|WP_Error Success or error.
	 */
	public static function discard( $revision_id ) {
		$revision = self::get_by_id( $revision_id );
		if ( ! $revision ) {
			return new WP_Error( 'revision_not_found', __( 'Staged revision not found.', 'editorial-io' ) );
		}

		$post_id = $revision->post_parent;

		// Delete the revision.
		$result = wp_delete_post( $revision_id, true );
		if ( ! $result ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete staged revision.', 'editorial-io' ) );
		}

		// Clean up parent post meta.
		delete_post_meta( $post_id, '_editorial_has_staged_revision' );

		/**
		 * Fired after a staged revision is discarded.
		 *
		 * @param int $post_id     The post ID.
		 * @param int $revision_id The revision ID.
		 */
		do_action( 'editorial_io_staged_revision_discarded', $post_id, $revision_id );

		return true;
	}

	/**
	 * Schedule a staged revision for publishing.
	 *
	 * @param int    $revision_id  The revision ID.
	 * @param string $publish_date The publish date (Y-m-d H:i:s format).
	 * @return bool|WP_Error Success or error.
	 */
	public static function schedule( $revision_id, $publish_date ) {
		$revision = self::get_by_id( $revision_id );
		if ( ! $revision ) {
			return new WP_Error( 'revision_not_found', __( 'Staged revision not found.', 'editorial-io' ) );
		}

		// Validate date.
		$timestamp = strtotime( $publish_date );
		if ( ! $timestamp || $timestamp <= time() ) {
			return new WP_Error( 'invalid_date', __( 'Invalid publish date. Date must be in the future.', 'editorial-io' ) );
		}

		// Update revision meta.
		update_metadata( 'post', $revision_id, '_editorial_staged_publish_date', $publish_date );
		update_metadata( 'post', $revision_id, '_editorial_staged_status', 'scheduled' );

		// Schedule the publishing event.
		wp_schedule_single_event( $timestamp, 'editorial_io_publish_staged', array( $revision_id ) );

		/**
		 * Fired after a staged revision is scheduled.
		 *
		 * @param int    $revision_id  The revision ID.
		 * @param string $publish_date The publish date.
		 */
		do_action( 'editorial_io_staged_revision_scheduled', $revision_id, $publish_date );

		return true;
	}

	/**
	 * Format revision data for API/display.
	 *
	 * @param object $revision The revision object.
	 * @return object The formatted revision data.
	 */
	private static function format_revision_data( $revision ) {
		$formatted = (object) array(
			'revision_id'        => $revision->ID,
			'post_parent'        => $revision->post_parent,
			'post_title'         => $revision->post_title,
			'post_content'       => $revision->post_content,
			'post_excerpt'       => $revision->post_excerpt,
			'post_type'          => isset( $revision->parent_type ) ? $revision->parent_type : get_post_type( $revision->post_parent ),
			'post_modified'      => $revision->post_modified,
			'revision_title'     => isset( $revision->parent_title ) ? $revision->parent_title : get_the_title( $revision->post_parent ),
			'staged_author_id'   => (int) get_metadata( 'post', $revision->ID, '_editorial_staged_author', true ),
			'staged_status'      => get_metadata( 'post', $revision->ID, '_editorial_staged_status', true ) ?: 'pending',
			'scheduled_date'     => get_metadata( 'post', $revision->ID, '_editorial_staged_publish_date', true ) ?: null,
			'notes'              => get_metadata( 'post', $revision->ID, '_editorial_staged_notes', true ) ?: '',
		);

		return $formatted;
	}

	/**
	 * Format staged revision for REST API response.
	 *
	 * @param object $revision The revision data.
	 * @return array The formatted response.
	 */
	public static function format_for_response( $revision ) {
		$author = get_userdata( $revision->staged_author_id );

		return array(
			'revision_id'    => (int) $revision->revision_id,
			'post_id'        => (int) $revision->post_parent,
			'post_title'     => $revision->revision_title,
			'post_type'      => $revision->post_type,
			'title'          => $revision->post_title,
			'content'        => $revision->post_content,
			'excerpt'        => $revision->post_excerpt,
			'author'         => array(
				'id'     => (int) $revision->staged_author_id,
				'name'   => $author ? $author->display_name : __( 'Unknown', 'editorial-io' ),
				'avatar' => get_avatar_url( $revision->staged_author_id, array( 'size' => 48 ) ),
			),
			'status'         => $revision->staged_status,
			'scheduled_date' => $revision->scheduled_date,
			'notes'          => $revision->notes,
			'modified'       => $revision->post_modified,
			'edit_url'       => get_edit_post_link( $revision->post_parent, 'raw' ),
			'view_url'       => get_permalink( $revision->post_parent ),
		);
	}

	/**
	 * Clean up a staged revision and its metadata.
	 *
	 * @param int $revision_id The revision ID.
	 */
	private static function cleanup_staged_revision( $revision_id ) {
		$revision = get_post( $revision_id );
		if ( $revision ) {
			delete_post_meta( $revision->post_parent, '_editorial_has_staged_revision' );
		}

		wp_delete_post( $revision_id, true );
	}

	/**
	 * Protect staged revisions from being overwritten.
	 *
	 * @param bool    $post_has_changed Whether the post has changed.
	 * @param WP_Post $last_revision    The last revision post object.
	 * @param WP_Post $post             The post object.
	 * @return bool
	 */
	public function protect_staged_revisions( $post_has_changed, $last_revision, $post ) {
		// If the last revision is a staged revision, ensure WordPress creates a new revision.
		if ( get_metadata( 'post', $last_revision->ID, '_editorial_staged_revision', true ) ) {
			return true;
		}

		return $post_has_changed;
	}

	/**
	 * Clean up old staged revisions (called by cron).
	 */
	public function cleanup_old_staged_revisions() {
		$settings = Editorial_IO_Settings::get_instance();
		if ( ! $settings->get_option( 'cleanup_old_revisions', false ) ) {
			return;
		}

		$cleanup_days = $settings->get_option( 'cleanup_days', 30 );
		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$cleanup_days} days" ) );

		global $wpdb;

		// Find old staged revisions that are not approved or scheduled.
		$old_revisions = $wpdb->get_results( $wpdb->prepare(
			"SELECT r.ID, r.post_parent
			 FROM {$wpdb->posts} r
			 INNER JOIN {$wpdb->postmeta} sr ON r.ID = sr.post_id AND sr.meta_key = '_editorial_staged_revision' AND sr.meta_value = '1'
			 LEFT JOIN {$wpdb->postmeta} sm ON r.ID = sm.post_id AND sm.meta_key = '_editorial_staged_status'
			 WHERE r.post_type = 'revision'
			 AND r.post_modified < %s
			 AND (sm.meta_value IS NULL OR sm.meta_value IN ('pending', 'rejected'))",
			$cutoff_date
		) );

		foreach ( $old_revisions as $revision ) {
			self::cleanup_staged_revision( $revision->ID );
		}

		if ( ! empty( $old_revisions ) ) {
			/* translators: %d: number of revisions */
			error_log( sprintf( __( 'Editorial.io: Cleaned up %d old staged revisions.', 'editorial-io' ), count( $old_revisions ) ) );
		}
	}

	// REST API methods (called by the main REST controller).

	/**
	 * REST: Create staged revision.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_create_staged_revision( $request ) {
		$post_id = $request->get_param( 'post_id' );

		$post_data = array(
			'title'   => $request->get_param( 'title' ),
			'content' => $request->get_param( 'content' ),
			'excerpt' => $request->get_param( 'excerpt' ),
		);

		$meta_data = array(
			'notes' => $request->get_param( 'notes' ),
		);

		$revision_id = self::create( $post_id, $post_data, $meta_data );

		if ( is_wp_error( $revision_id ) ) {
			return $revision_id;
		}

		$revision = self::get_by_id( $revision_id );
		return rest_ensure_response( self::format_for_response( $revision ) );
	}

	/**
	 * REST: Get staged revision.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_get_staged_revision( $request ) {
		$post_id = $request->get_param( 'post_id' );
		$revision = self::get( $post_id );

		if ( ! $revision ) {
			return new WP_Error( 'not_found', __( 'No staged revision found for this post.', 'editorial-io' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( self::format_for_response( $revision ) );
	}

	/**
	 * REST: Publish staged revision.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_publish_staged_revision( $request ) {
		$revision_id = $request->get_param( 'revision_id' );
		$result = self::publish( $revision_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array(
			'published' => true,
			'post_id'   => $result,
			'message'   => __( 'Staged revision published successfully.', 'editorial-io' ),
		) );
	}

	/**
	 * REST: Approve staged revision.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_approve_staged_revision( $request ) {
		$revision_id = $request->get_param( 'revision_id' );
		$result = self::approve( $revision_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$revision = self::get_by_id( $revision_id );
		return rest_ensure_response( self::format_for_response( $revision ) );
	}

	/**
	 * REST: Reject staged revision.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_reject_staged_revision( $request ) {
		$revision_id = $request->get_param( 'revision_id' );
		$result = self::reject( $revision_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$revision = self::get_by_id( $revision_id );
		return rest_ensure_response( self::format_for_response( $revision ) );
	}

	/**
	 * REST: Delete staged revision.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_delete_staged_revision( $request ) {
		$revision_id = $request->get_param( 'revision_id' );
		$result = self::discard( $revision_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array(
			'deleted' => true,
			'message' => __( 'Staged revision discarded.', 'editorial-io' ),
		) );
	}
}