<?php
/**
 * Revision Timeline feature for Masthead
 *
 * @package Masthead
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Masthead_Revision_Timeline
 *
 * Enhanced revision history with visual timeline and metadata.
 */
class Masthead_Revision_Timeline {

	/**
	 * Singleton instance.
	 *
	 * @var Masthead_Revision_Timeline|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Masthead_Revision_Timeline
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
		// Timeline functionality is primarily handled through REST API and JavaScript
	}

	/**
	 * Get enhanced revision data for timeline.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $args    Query arguments.
	 * @return array
	 */
	public static function get_timeline_data( $post_id, $args = array() ) {
		$defaults = array(
			'per_page'          => 50,
			'include_autosaves' => false,
		);
		$args = wp_parse_args( $args, $defaults );

		$revisions = wp_get_post_revisions( $post_id, array(
			'posts_per_page' => $args['per_page'],
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		if ( ! $args['include_autosaves'] ) {
			$revisions = array_filter( $revisions, function( $revision ) {
				return ! wp_is_post_autosave( $revision->ID );
			});
		}

		$timeline_data = array();
		$revision_array = array_values( $revisions );

		foreach ( $revision_array as $index => $revision ) {
			$previous = isset( $revision_array[ $index + 1 ] ) ? $revision_array[ $index + 1 ] : get_post( $post_id );
			$timeline_data[] = self::format_timeline_item( $revision, $previous );
		}

		return $timeline_data;
	}

	/**
	 * Format revision for timeline display.
	 *
	 * @param WP_Post $revision The revision.
	 * @param WP_Post $previous The previous revision for comparison.
	 * @return array
	 */
	private static function format_timeline_item( $revision, $previous ) {
		$author = get_userdata( $revision->post_author );
		$is_autosave = wp_is_post_autosave( $revision->ID );
		$is_staged = get_metadata( 'post', $revision->ID, '_masthead_staged_revision', true );

		// Determine changes
		$changes = array();
		if ( $revision->post_title !== $previous->post_title ) {
			$changes[] = 'title';
		}
		if ( $revision->post_content !== $previous->post_content ) {
			$changes[] = 'content';
		}
		if ( $revision->post_excerpt !== $previous->post_excerpt ) {
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
				'name'   => $author ? $author->display_name : __( 'Unknown', 'masthead' ),
				'avatar' => get_avatar_url( $revision->post_author, array( 'size' => 48 ) ),
			),
			'type'          => $is_staged ? 'staged' : ( $is_autosave ? 'autosave' : 'manual' ),
			'changes'       => $changes,
			'is_staged'     => $is_staged,
			'change_count'  => count( $changes ),
		);
	}

	/**
	 * REST: Get recent revisions across all posts.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function rest_get_recent_revisions( $request ) {
		global $wpdb;

		$per_page = $request->get_param( 'per_page' );
		$page = $request->get_param( 'page' );
		$author = $request->get_param( 'author' );
		$post_id = $request->get_param( 'post_id' );
		$after = $request->get_param( 'after' );
		$before = $request->get_param( 'before' );

		$where = array( "r.post_type = 'revision'" );
		$params = array();

		if ( $author ) {
			$where[] = 'r.post_author = %d';
			$params[] = $author;
		}

		if ( $post_id ) {
			$where[] = 'r.post_parent = %d';
			$params[] = $post_id;
		}

		if ( $after ) {
			$where[] = 'r.post_modified >= %s';
			$params[] = $after;
		}

		if ( $before ) {
			$where[] = 'r.post_modified <= %s';
			$params[] = $before;
		}

		$offset = ( $page - 1 ) * $per_page;
		$where_sql = implode( ' AND ', $where );

		$params[] = $per_page;
		$params[] = $offset;

		$query = $wpdb->prepare(
			"SELECT r.*, p.post_title as parent_title, p.post_type as parent_type
			 FROM {$wpdb->posts} r
			 INNER JOIN {$wpdb->posts} p ON r.post_parent = p.ID
			 WHERE {$where_sql}
			 ORDER BY r.post_modified DESC
			 LIMIT %d OFFSET %d",
			$params
		);

		$revisions = $wpdb->get_results( $query );

		$data = array();
		foreach ( $revisions as $revision ) {
			$author = get_userdata( $revision->post_author );
			$is_autosave = strpos( $revision->post_name, 'autosave' ) !== false;

			$data[] = array(
				'id'            => $revision->ID,
				'parent_id'     => $revision->post_parent,
				'parent_title'  => $revision->parent_title,
				'parent_type'   => $revision->parent_type,
				'date'          => $revision->post_modified,
				'date_relative' => human_time_diff( strtotime( $revision->post_modified_gmt ), time() ),
				'author'        => array(
					'id'     => $revision->post_author,
					'name'   => $author ? $author->display_name : __( 'Unknown', 'masthead' ),
					'avatar' => get_avatar_url( $revision->post_author, array( 'size' => 32 ) ),
				),
				'type'          => $is_autosave ? 'autosave' : 'manual',
				'edit_url'      => get_edit_post_link( $revision->post_parent, 'raw' ),
			);
		}

		return rest_ensure_response( $data );
	}

	/**
	 * REST: Restore a revision.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_restore_revision( $request ) {
		$revision_id = $request->get_param( 'revision_id' );
		$revision = get_post( $revision_id );

		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return new WP_Error( 'not_found', __( 'Revision not found.', 'masthead' ), array( 'status' => 404 ) );
		}

		$parent_id = $revision->post_parent;
		$restored = wp_restore_post_revision( $revision_id );

		if ( ! $restored ) {
			return new WP_Error( 'restore_failed', __( 'Failed to restore revision.', 'masthead' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array(
			'success'  => true,
			'message'  => __( 'Revision restored successfully.', 'masthead' ),
			'post_id'  => $parent_id,
			'edit_url' => get_edit_post_link( $parent_id, 'raw' ),
		) );
	}
}
