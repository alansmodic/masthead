<?php
/**
 * Scheduled Publishing feature for Editorial.io
 *
 * @package EditorialIO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Editorial_IO_Scheduled_Publishing
 *
 * Handles scheduling staged revisions for automatic publishing.
 */
class Editorial_IO_Scheduled_Publishing {

	/**
	 * Singleton instance.
	 *
	 * @var Editorial_IO_Scheduled_Publishing|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Editorial_IO_Scheduled_Publishing
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
		add_action( 'editorial_io_publish_staged', array( $this, 'publish_scheduled_revision' ) );
		add_action( 'editorial_io_cleanup', array( $this, 'cleanup_failed_schedules' ) );
	}

	/**
	 * Schedule a staged revision for publishing.
	 *
	 * @param int    $revision_id  The revision ID.
	 * @param string $publish_date The publish date (Y-m-d H:i:s format).
	 * @return bool|WP_Error Success or error.
	 */
	public static function schedule_staged_revision( $revision_id, $publish_date ) {
		if ( ! class_exists( 'Editorial_IO_Staged_Revisions' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Staged revisions feature is required.', 'editorial-io' ) );
		}

		return Editorial_IO_Staged_Revisions::schedule( $revision_id, $publish_date );
	}

	/**
	 * Publish a scheduled revision (called by cron).
	 *
	 * @param int $revision_id The revision ID.
	 */
	public function publish_scheduled_revision( $revision_id ) {
		if ( ! class_exists( 'Editorial_IO_Staged_Revisions' ) ) {
			error_log( 'Editorial.io: Cannot publish scheduled revision - Staged Revisions feature is disabled.' );
			return;
		}

		// Safety check: do not publish if revision has been rejected or discarded.
		$revision = Editorial_IO_Staged_Revisions::get_by_id( $revision_id );
		if ( ! $revision ) {
			error_log( 'Editorial.io: Scheduled revision ' . $revision_id . ' no longer exists, skipping.' );
			return;
		}

		$status = $revision->staged_status;
		if ( 'rejected' === $status ) {
			error_log( 'Editorial.io: Scheduled revision ' . $revision_id . ' was rejected, skipping publish.' );
			return;
		}

		$result = Editorial_IO_Staged_Revisions::publish( $revision_id );

		if ( is_wp_error( $result ) ) {
			error_log( 'Editorial.io: Failed to publish scheduled revision ' . $revision_id . ': ' . $result->get_error_message() );
		} else {
			/* translators: %1$d: revision ID, %2$d: post ID */
			error_log( sprintf( __( 'Editorial.io: Successfully published scheduled revision %1$d for post %2$d.', 'editorial-io' ), $revision_id, $result ) );
		}
	}

	/**
	 * Get scheduled revisions.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function get_scheduled_revisions( $args = array() ) {
		if ( ! class_exists( 'Editorial_IO_Staged_Revisions' ) ) {
			return array();
		}

		$args['status'] = 'scheduled';
		return Editorial_IO_Staged_Revisions::get_all( $args );
	}

	/**
	 * Clean up failed schedules.
	 */
	public function cleanup_failed_schedules() {
		// Remove scheduled events for revisions that no longer exist.
		global $wpdb;

		$scheduled_events = wp_get_scheduled_events();
		foreach ( $scheduled_events as $timestamp => $hooks ) {
			if ( isset( $hooks['editorial_io_publish_staged'] ) ) {
				foreach ( $hooks['editorial_io_publish_staged'] as $event ) {
					if ( isset( $event['args'][0] ) ) {
						$revision_id = $event['args'][0];
						$revision = get_post( $revision_id );
						
						if ( ! $revision || 'revision' !== $revision->post_type ) {
							wp_unschedule_event( $timestamp, 'editorial_io_publish_staged', array( $revision_id ) );
						}
					}
				}
			}
		}
	}

	/**
	 * REST: Schedule staged revision.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_schedule_staged_revision( $request ) {
		$revision_id = $request->get_param( 'revision_id' );
		$publish_date = $request->get_param( 'publish_date' );

		$result = self::schedule_staged_revision( $revision_id, $publish_date );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array(
			'scheduled'    => true,
			'revision_id'  => $revision_id,
			'publish_date' => $publish_date,
			'message'      => __( 'Revision scheduled for publishing.', 'editorial-io' ),
		) );
	}
}