<?php
/**
 * Publication Checklist feature for Masthead
 *
 * @package Masthead
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Masthead_Publication_Checklist
 *
 * Handles publication checklist functionality - show checklist before publishing.
 */
class Masthead_Publication_Checklist {

	/**
	 * Singleton instance.
	 *
	 * @var Masthead_Publication_Checklist|null
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
	 * @return Masthead_Publication_Checklist
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

		// Only hook into publish flow if checklist is enabled.
		if ( $this->is_checklist_enabled() ) {
			add_action( 'transition_post_status', array( $this, 'handle_post_status_transition' ), 10, 3 );
			add_action( 'wp_ajax_masthead_bypass_checklist', array( $this, 'ajax_bypass_checklist' ) );
			add_action( 'wp_ajax_masthead_validate_checklist', array( $this, 'ajax_validate_checklist' ) );
			add_action( 'wp_ajax_editorial_io_bypass_checklist', array( $this, 'ajax_bypass_checklist' ) );
			add_action( 'wp_ajax_editorial_io_validate_checklist', array( $this, 'ajax_validate_checklist' ) );
			add_filter( 'wp_insert_post_data', array( $this, 'enforce_checklist_on_save' ), 10, 2 );
			add_filter( 'rest_pre_insert_post', array( $this, 'enforce_checklist_on_rest' ), 10, 2 );
			add_action( 'admin_notices', array( $this, 'maybe_show_checklist_notice' ) );
		}
	}

	/**
	 * Check if checklist is enabled.
	 *
	 * @return bool
	 */
	private function is_checklist_enabled() {
		return $this->settings->is_feature_enabled( 'publication_checklist' );
	}

	/**
	 * Get checklist items.
	 *
	 * @return array
	 */
	public function get_checklist_items( $post_id = 0 ) {
		$items = $this->settings->get_checklist_items();
		$items = apply_filters( 'masthead_publication_checklist_items', $items, (int) $post_id );

		return $this->normalize_checklist_items( $items );
	}

	/**
	 * Check if checklist should be shown for this post transition.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 * @return bool
	 */
	public function should_show_checklist( $new_status, $old_status, $post ) {
		// Only for supported post types.
		if ( ! Masthead::post_type_supports_editorial( $post->post_type ) ) {
			return false;
		}

		// Only when transitioning to publish.
		if ( 'publish' !== $new_status ) {
			return false;
		}

		// Don't show for new posts (draft to publish) unless specifically configured.
		$show_for_new = apply_filters( 'masthead_checklist_show_for_new_posts', false );
		if ( ! $show_for_new && 'draft' === $old_status ) {
			return false;
		}

		// Show when updating existing published posts.
		if ( 'publish' === $old_status ) {
			return true;
		}

		// Show when publishing from other statuses if configured.
		$show_for_statuses = apply_filters(
			'masthead_checklist_show_for_statuses',
			array( 'pending', 'future' )
		);

		return in_array( $old_status, $show_for_statuses, true );
	}

	/**
	 * Handle post status transitions.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public function handle_post_status_transition( $new_status, $old_status, $post ) {
		if ( ! $this->should_show_checklist( $new_status, $old_status, $post ) ) {
			return;
		}

		// Check if checklist has been bypassed for this post.
		$bypassed = $this->get_post_meta_compat( $post->ID, 'checklist_bypassed' );
		if ( $bypassed ) {
			// Clean up bypass flag.
			delete_post_meta( $post->ID, '_masthead_checklist_bypassed' );
			delete_post_meta( $post->ID, '_editorial_checklist_bypassed' );
			return;
		}

		// If we reach here without bypass, the checklist should have been shown
		// This is a fallback - normally the frontend handles this.
		$this->log_checklist_bypass( $post->ID, 'backend_fallback' );
	}

	/**
	 * Enforce checklist completion on non-REST saves.
	 *
	 * @param array $data    Sanitized post data.
	 * @param array $postarr Raw post data.
	 * @return array
	 */
	public function enforce_checklist_on_save( $data, $postarr ) {
		if ( empty( $postarr['ID'] ) ) {
			return $data;
		}

		$post_id = absint( $postarr['ID'] );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return $data;
		}

		// Skip autosaves, revisions, and non-publish transitions.
		if ( wp_is_post_autosave( $post_id ) || 'revision' === ( $postarr['post_type'] ?? '' ) ) {
			return $data;
		}

		$new_status = $data['post_status'] ?? $post->post_status;
		if ( 'publish' !== $new_status ) {
			return $data;
		}

		if ( ! $this->should_show_checklist( $new_status, $post->post_status, $post ) ) {
			return $data;
		}

		$bypassed = $this->get_post_meta_compat( $post_id, 'checklist_bypassed' );
		if ( $bypassed ) {
			return $data;
		}

		// Block the publish by reverting status and surface a notice.
		$data['post_status'] = $post->post_status;

		add_filter( 'redirect_post_location', array( $this, 'add_checklist_notice_query_arg' ), 10, 2 );

		return $data;
	}

	/**
	 * Enforce checklist completion on REST saves.
	 *
	 * @param WP_Post         $prepared_post Prepared post object.
	 * @param WP_REST_Request $request       Request object.
	 * @return WP_Post|WP_Error
	 */
	public function enforce_checklist_on_rest( $prepared_post, $request ) {
		if ( ! ( $prepared_post instanceof WP_Post ) ) {
			return $prepared_post;
		}

		$post_id = absint( $request->get_param( 'id' ) );
		if ( ! $post_id && ! empty( $prepared_post->ID ) ) {
			$post_id = absint( $prepared_post->ID );
		}

		if ( ! $post_id ) {
			return $prepared_post;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $prepared_post;
		}

		$new_status = $prepared_post->post_status ?? $post->post_status;
		if ( 'publish' !== $new_status ) {
			return $prepared_post;
		}

		if ( ! $this->should_show_checklist( $new_status, $post->post_status, $post ) ) {
			return $prepared_post;
		}

		$bypassed = $this->get_post_meta_compat( $post_id, 'checklist_bypassed' );
		if ( $bypassed ) {
			return $prepared_post;
		}

		return new WP_Error(
			'masthead_checklist_required',
			__( 'Please complete the publication checklist before publishing.', 'masthead' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Add query arg for checklist enforcement notice.
	 *
	 * @param string $location Redirect location.
	 * @param int    $post_id  Post ID.
	 * @return string
	 */
	public function add_checklist_notice_query_arg( $location, $post_id ) {
		return add_query_arg( 'masthead_checklist_required', 1, $location );
	}

	/**
	 * Show admin notice when checklist blocks publishing.
	 */
	public function maybe_show_checklist_notice() {
		if ( empty( $_GET['masthead_checklist_required'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Publication checklist must be completed before publishing.', 'masthead' )
		);
	}

	/**
	 * AJAX handler for bypassing checklist.
	 */
	public function ajax_bypass_checklist() {
		check_ajax_referer( 'masthead_checklist', 'nonce' );

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'masthead' ) ) );
		}

		$bypass_type = sanitize_key( $_POST['bypass_type'] ?? 'manual' );

		// Set bypass flag.
		update_post_meta( $post_id, '_masthead_checklist_bypassed', true );

		// Log the bypass.
		$this->log_checklist_bypass( $post_id, $bypass_type );

		wp_send_json_success( array(
			'message' => __( 'Checklist bypassed. You may now publish.', 'masthead' ),
		) );
	}

	/**
	 * AJAX handler for validating checklist completion.
	 */
	public function ajax_validate_checklist() {
		check_ajax_referer( 'masthead_checklist', 'nonce' );

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'masthead' ) ) );
		}

		$checked_items = $_POST['checked_items'] ?? array();
		$validation_result = $this->validate_checklist( $checked_items, $post_id );

		if ( $validation_result['valid'] ) {
			// Set bypass flag (checklist was completed).
			update_post_meta( $post_id, '_masthead_checklist_bypassed', true );

			// Log checklist completion.
			$this->log_checklist_completion( $post_id, $checked_items );

			wp_send_json_success( array(
				'message'  => __( 'Checklist completed. You may now publish.', 'masthead' ),
				'validated' => true,
			) );
		} else {
			wp_send_json_error( array(
				'message'         => __( 'Please complete all required checklist items.', 'masthead' ),
				'missing_items'   => $validation_result['missing_required'],
				'blocked_items'   => $validation_result['blocked_required'],
				'validated'       => false,
			) );
		}
	}

	/**
	 * Validate checklist completion.
	 *
	 * @param array $checked_items Array of checked item indices.
	 * @return array Validation result.
	 */
	public function validate_checklist( $checked_items, $post_id = 0 ) {
		$checklist_items = $this->get_checklist_items( $post_id );
		$missing_required = array();
		$blocked_required = array();
		$checked_items = array_map( 'absint', (array) $checked_items );

		foreach ( $checklist_items as $index => $item ) {
			if ( empty( $item['required'] ) ) {
				continue;
			}

			if ( 'fail' === ( $item['status'] ?? '' ) || 'unavailable' === ( $item['status'] ?? '' ) ) {
				$blocked_required[] = array(
					'index'   => $index,
					'label'   => $item['label'],
					'status'  => $item['status'],
					'message' => $item['message'] ?? '',
				);
				continue;
			}

			if ( ! empty( $item['auto_checked'] ) || 'pass' === ( $item['status'] ?? '' ) ) {
				continue;
			}

			if ( ! in_array( $index, $checked_items, true ) ) {
				$missing_required[] = array(
					'index' => $index,
					'label' => $item['label'],
				);
			}
		}

		return array(
			'valid'             => empty( $missing_required ) && empty( $blocked_required ),
			'missing_required'  => $missing_required,
			'blocked_required'  => $blocked_required,
			'completed_items'   => count( $checked_items ),
			'required_items'    => count( array_filter( $checklist_items, function( $item ) {
				return $item['required'];
			} ) ),
		);
	}

	/**
	 * Normalize checklist items from settings and filters into one response shape.
	 *
	 * @param array $items Checklist items.
	 * @return array
	 */
	private function normalize_checklist_items( array $items ): array {
		$normalized = array();

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || empty( $item['label'] ) ) {
				continue;
			}

			$status = sanitize_key( $item['status'] ?? 'manual' );
			if ( 'complete' === $status ) {
				$status = 'pass';
			} elseif ( 'pending' === $status || 'skipped' === $status ) {
				$status = 'warning';
			}

			$normalized[] = array(
				'id'           => sanitize_key( $item['id'] ?? 'masthead_checklist_' . $index ),
				'label'        => sanitize_text_field( $item['label'] ),
				'required'     => ! empty( $item['required'] ),
				'status'       => $status,
				'message'      => isset( $item['message'] ) ? sanitize_text_field( $item['message'] ) : '',
				'source'       => sanitize_key( $item['source'] ?? ( isset( $item['status'] ) ? 'integration' : 'static' ) ),
				'auto_checked' => ! empty( $item['auto_checked'] ) || 'pass' === $status,
				'action'       => isset( $item['action'] ) && is_array( $item['action'] ) ? $item['action'] : null,
			);
		}

		return $normalized;
	}

	/**
	 * Log checklist bypass.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $bypass_type Type of bypass (manual, auto, backend_fallback).
	 */
	private function log_checklist_bypass( $post_id, $bypass_type ) {
		$log_entry = array(
			'action'      => 'checklist_bypassed',
			'post_id'     => $post_id,
			'user_id'     => get_current_user_id(),
			'bypass_type' => $bypass_type,
			'timestamp'   => current_time( 'mysql', true ),
		);

		/**
		 * Fired when checklist is bypassed.
		 *
		 * @param array $log_entry Log entry data.
		 */
		do_action( 'masthead_checklist_bypassed', $log_entry );

		// Store in post meta for audit trail.
		$existing_log = $this->get_post_meta_compat( $post_id, 'checklist_log' );
		if ( ! is_array( $existing_log ) ) {
			$existing_log = array();
		}
		$existing_log[] = $log_entry;

		// Keep only last 10 entries.
		$existing_log = array_slice( $existing_log, -10 );
		update_post_meta( $post_id, '_masthead_checklist_log', $existing_log );
	}

	/**
	 * Log checklist completion.
	 *
	 * @param int   $post_id       Post ID.
	 * @param array $checked_items Checked items.
	 */
	private function log_checklist_completion( $post_id, $checked_items ) {
		$checklist_items = $this->get_checklist_items( $post_id );
		$completed_items = array();

		foreach ( $checked_items as $index ) {
			if ( isset( $checklist_items[ $index ] ) ) {
				$completed_items[] = array(
					'index'    => $index,
					'label'    => $checklist_items[ $index ]['label'],
					'required' => $checklist_items[ $index ]['required'],
				);
			}
		}

		$log_entry = array(
			'action'           => 'checklist_completed',
			'post_id'          => $post_id,
			'user_id'          => get_current_user_id(),
			'completed_items'  => $completed_items,
			'total_items'      => count( $checklist_items ),
			'required_items'   => count( array_filter( $checklist_items, function( $item ) {
				return $item['required'];
			} ) ),
			'timestamp'        => current_time( 'mysql', true ),
		);

		/**
		 * Fired when checklist is completed.
		 *
		 * @param array $log_entry Log entry data.
		 */
		do_action( 'masthead_checklist_completed', $log_entry );

		// Store in post meta for audit trail.
		$existing_log = $this->get_post_meta_compat( $post_id, 'checklist_log' );
		if ( ! is_array( $existing_log ) ) {
			$existing_log = array();
		}
		$existing_log[] = $log_entry;

		// Keep only last 10 entries.
		$existing_log = array_slice( $existing_log, -10 );
		update_post_meta( $post_id, '_masthead_checklist_log', $existing_log );
	}

	/**
	 * Get checklist completion history for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Log entries.
	 */
	public function get_checklist_history( $post_id ) {
		$log = $this->get_post_meta_compat( $post_id, 'checklist_log' );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Read Masthead checklist meta, migrating old Editorial IO keys on access.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $suffix  Meta key suffix without the Masthead prefix.
	 * @return mixed
	 */
	private function get_post_meta_compat( $post_id, $suffix ) {
		$new_key = '_masthead_' . $suffix;
		$value   = get_post_meta( $post_id, $new_key, true );

		if ( '' !== $value && null !== $value ) {
			return $value;
		}

		$old_key = '_editorial_' . $suffix;
		$value   = get_post_meta( $post_id, $old_key, true );

		if ( '' !== $value && null !== $value ) {
			update_post_meta( $post_id, $new_key, $value );
		}

		return $value;
	}

	/**
	 * Get checklist statistics.
	 *
	 * @param array $args Query arguments.
	 * @return array Statistics.
	 */
	public function get_checklist_statistics( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'from_date' => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
			'to_date'   => gmdate( 'Y-m-d' ),
			'post_type' => 'post',
		);
		$args = wp_parse_args( $args, $defaults );

		// Get posts with checklist logs.
		$query = $wpdb->prepare(
			"SELECT p.ID, pm.meta_value as checklist_log
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key IN ('_masthead_checklist_log', '_editorial_checklist_log')
			 WHERE p.post_type = %s
			 AND p.post_date >= %s
			 AND p.post_date <= %s
			 AND p.post_status = 'publish'",
			$args['post_type'],
			$args['from_date'] . ' 00:00:00',
			$args['to_date'] . ' 23:59:59'
		);

		$results = $wpdb->get_results( $query );

		$stats = array(
			'total_posts'         => count( $results ),
			'checklist_completed' => 0,
			'checklist_bypassed'  => 0,
			'completion_rate'     => 0,
			'average_items_checked' => 0,
			'most_skipped_items'  => array(),
		);

		$all_items_data = array();
		$checklist_items = $this->get_checklist_items();

		foreach ( $results as $result ) {
			$log = maybe_unserialize( $result->checklist_log );
			if ( ! is_array( $log ) ) {
				continue;
			}

			// Find the most recent completion or bypass.
			$latest_entry = end( $log );
			if ( ! $latest_entry ) {
				continue;
			}

			if ( 'checklist_completed' === $latest_entry['action'] ) {
				$stats['checklist_completed']++;
				
				// Track which items were completed.
				$completed_items = $latest_entry['completed_items'] ?? array();
				foreach ( $checklist_items as $index => $item ) {
					$was_checked = false;
					foreach ( $completed_items as $completed_item ) {
						if ( $completed_item['index'] == $index ) {
							$was_checked = true;
							break;
						}
					}
					
					if ( ! isset( $all_items_data[ $index ] ) ) {
						$all_items_data[ $index ] = array(
							'label'    => $item['label'],
							'required' => $item['required'],
							'checked'  => 0,
							'total'    => 0,
						);
					}
					
					$all_items_data[ $index ]['total']++;
					if ( $was_checked ) {
						$all_items_data[ $index ]['checked']++;
					}
				}
			} elseif ( 'checklist_bypassed' === $latest_entry['action'] ) {
				$stats['checklist_bypassed']++;
			}
		}

		// Calculate rates and averages.
		if ( $stats['total_posts'] > 0 ) {
			$stats['completion_rate'] = round( ( $stats['checklist_completed'] / $stats['total_posts'] ) * 100, 1 );
		}

		// Find most skipped items.
		foreach ( $all_items_data as $index => $item_data ) {
			if ( $item_data['total'] > 0 ) {
				$skip_rate = ( ( $item_data['total'] - $item_data['checked'] ) / $item_data['total'] ) * 100;
				$stats['most_skipped_items'][] = array(
					'index'     => $index,
					'label'     => $item_data['label'],
					'required'  => $item_data['required'],
					'skip_rate' => round( $skip_rate, 1 ),
					'skipped'   => $item_data['total'] - $item_data['checked'],
					'total'     => $item_data['total'],
				);
			}
		}

		// Sort by skip rate (highest first).
		usort( $stats['most_skipped_items'], function( $a, $b ) {
			return $b['skip_rate'] <=> $a['skip_rate'];
		} );

		// Take top 5.
		$stats['most_skipped_items'] = array_slice( $stats['most_skipped_items'], 0, 5 );

		return $stats;
	}

	/**
	 * Get frontend configuration for JavaScript.
	 *
	 * @return array
	 */
	public function get_frontend_config( $post_id = 0 ) {
		if ( ! $this->is_checklist_enabled() ) {
			return array( 'enabled' => false );
		}

		return array(
			'enabled'        => true,
			'items'          => $this->get_checklist_items( $post_id ),
			'nonce'          => wp_create_nonce( 'masthead_checklist' ),
			'ajax_url'       => admin_url( 'admin-ajax.php' ),
			'show_for_new'   => apply_filters( 'masthead_checklist_show_for_new_posts', false ),
			'show_for_statuses' => apply_filters(
				'masthead_checklist_show_for_statuses',
				array( 'pending', 'future' )
			),
		);
	}

	/**
	 * Render checklist items for admin display.
	 *
	 * @param array $items Optional. Specific items to render.
	 * @return string HTML output.
	 */
	public function render_checklist_items( $items = null ) {
		if ( null === $items ) {
			$items = $this->get_checklist_items();
		}

		if ( empty( $items ) ) {
			return '<p>' . __( 'No checklist items configured.', 'masthead' ) . '</p>';
		}

		$output = '<div class="masthead-checklist-items">';
		
		foreach ( $items as $index => $item ) {
			$required_class = $item['required'] ? 'required' : 'optional';
			$required_label = $item['required'] ? __( '(Required)', 'masthead' ) : __( '(Optional)', 'masthead' );
			
			$output .= sprintf(
				'<div class="checklist-item %s">
					<label>
						<input type="checkbox" name="checklist_items[]" value="%d" %s />
						<span class="item-label">%s</span>
						<span class="item-type">%s</span>
					</label>
				</div>',
				esc_attr( $required_class ),
				esc_attr( $index ),
				$item['required'] ? 'required' : '',
				esc_html( $item['label'] ),
				esc_html( $required_label )
			);
		}
		
		$output .= '</div>';

		return $output;
	}

	/**
	 * Check if current user can bypass checklist.
	 *
	 * @return bool
	 */
	public function user_can_bypass_checklist() {
		/**
		 * Filter whether current user can bypass publication checklist.
		 *
		 * @param bool $can_bypass Default: editors and admins can bypass.
		 */
		return apply_filters(
			'masthead_user_can_bypass_checklist',
			current_user_can( 'edit_others_posts' )
		);
	}

	/**
	 * Get default checklist items.
	 *
	 * @return array
	 */
	public static function get_default_checklist_items() {
		return array(
			array(
				'label'    => __( 'I have reviewed all changes', 'masthead' ),
				'required' => true,
			),
			array(
				'label'    => __( 'Content has been proofread for errors', 'masthead' ),
				'required' => true,
			),
			array(
				'label'    => __( 'Links have been verified', 'masthead' ),
				'required' => false,
			),
			array(
				'label'    => __( 'SEO meta data is complete', 'masthead' ),
				'required' => false,
			),
			array(
				'label'    => __( 'Images have appropriate alt text', 'masthead' ),
				'required' => false,
			),
		);
	}
}
