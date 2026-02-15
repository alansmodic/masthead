<?php
/**
 * Publication Checklist feature for Editorial.io
 *
 * @package EditorialIO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Editorial_IO_Publication_Checklist
 *
 * Handles publication checklist functionality - show checklist before publishing.
 */
class Editorial_IO_Publication_Checklist {

	/**
	 * Singleton instance.
	 *
	 * @var Editorial_IO_Publication_Checklist|null
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
	 * @return Editorial_IO_Publication_Checklist
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

		// Only hook into publish flow if checklist is enabled.
		if ( $this->is_checklist_enabled() ) {
			add_action( 'transition_post_status', array( $this, 'handle_post_status_transition' ), 10, 3 );
			add_action( 'wp_ajax_editorial_io_bypass_checklist', array( $this, 'ajax_bypass_checklist' ) );
			add_action( 'wp_ajax_editorial_io_validate_checklist', array( $this, 'ajax_validate_checklist' ) );
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
	public function get_checklist_items() {
		return $this->settings->get_checklist_items();
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
		if ( ! Editorial_IO::post_type_supports_editorial( $post->post_type ) ) {
			return false;
		}

		// Only when transitioning to publish.
		if ( 'publish' !== $new_status ) {
			return false;
		}

		// Don't show for new posts (draft to publish) unless specifically configured.
		$show_for_new = apply_filters( 'editorial_io_checklist_show_for_new_posts', false );
		if ( ! $show_for_new && 'draft' === $old_status ) {
			return false;
		}

		// Show when updating existing published posts.
		if ( 'publish' === $old_status ) {
			return true;
		}

		// Show when publishing from other statuses if configured.
		$show_for_statuses = apply_filters(
			'editorial_io_checklist_show_for_statuses',
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
		$bypassed = get_post_meta( $post->ID, '_editorial_checklist_bypassed', true );
		if ( $bypassed ) {
			// Clean up bypass flag.
			delete_post_meta( $post->ID, '_editorial_checklist_bypassed' );
			return;
		}

		// If we reach here without bypass, the checklist should have been shown
		// This is a fallback - normally the frontend handles this.
		$this->log_checklist_bypass( $post->ID, 'backend_fallback' );
	}

	/**
	 * AJAX handler for bypassing checklist.
	 */
	public function ajax_bypass_checklist() {
		check_ajax_referer( 'editorial_io_checklist', 'nonce' );

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'editorial-io' ) ) );
		}

		$bypass_type = sanitize_key( $_POST['bypass_type'] ?? 'manual' );

		// Set bypass flag.
		update_post_meta( $post_id, '_editorial_checklist_bypassed', true );

		// Log the bypass.
		$this->log_checklist_bypass( $post_id, $bypass_type );

		wp_send_json_success( array(
			'message' => __( 'Checklist bypassed. You may now publish.', 'editorial-io' ),
		) );
	}

	/**
	 * AJAX handler for validating checklist completion.
	 */
	public function ajax_validate_checklist() {
		check_ajax_referer( 'editorial_io_checklist', 'nonce' );

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'editorial-io' ) ) );
		}

		$checked_items = $_POST['checked_items'] ?? array();
		$validation_result = $this->validate_checklist( $checked_items );

		if ( $validation_result['valid'] ) {
			// Set bypass flag (checklist was completed).
			update_post_meta( $post_id, '_editorial_checklist_bypassed', true );

			// Log checklist completion.
			$this->log_checklist_completion( $post_id, $checked_items );

			wp_send_json_success( array(
				'message'  => __( 'Checklist completed. You may now publish.', 'editorial-io' ),
				'validated' => true,
			) );
		} else {
			wp_send_json_error( array(
				'message'         => __( 'Please complete all required checklist items.', 'editorial-io' ),
				'missing_items'   => $validation_result['missing_required'],
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
	public function validate_checklist( $checked_items ) {
		$checklist_items = $this->get_checklist_items();
		$missing_required = array();

		foreach ( $checklist_items as $index => $item ) {
			if ( $item['required'] && ! in_array( $index, $checked_items, true ) ) {
				$missing_required[] = array(
					'index' => $index,
					'label' => $item['label'],
				);
			}
		}

		return array(
			'valid'             => empty( $missing_required ),
			'missing_required'  => $missing_required,
			'completed_items'   => count( $checked_items ),
			'required_items'    => count( array_filter( $checklist_items, function( $item ) {
				return $item['required'];
			} ) ),
		);
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
		do_action( 'editorial_io_checklist_bypassed', $log_entry );

		// Store in post meta for audit trail.
		$existing_log = get_post_meta( $post_id, '_editorial_checklist_log', true );
		if ( ! is_array( $existing_log ) ) {
			$existing_log = array();
		}
		$existing_log[] = $log_entry;

		// Keep only last 10 entries.
		$existing_log = array_slice( $existing_log, -10 );
		update_post_meta( $post_id, '_editorial_checklist_log', $existing_log );
	}

	/**
	 * Log checklist completion.
	 *
	 * @param int   $post_id       Post ID.
	 * @param array $checked_items Checked items.
	 */
	private function log_checklist_completion( $post_id, $checked_items ) {
		$checklist_items = $this->get_checklist_items();
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
		do_action( 'editorial_io_checklist_completed', $log_entry );

		// Store in post meta for audit trail.
		$existing_log = get_post_meta( $post_id, '_editorial_checklist_log', true );
		if ( ! is_array( $existing_log ) ) {
			$existing_log = array();
		}
		$existing_log[] = $log_entry;

		// Keep only last 10 entries.
		$existing_log = array_slice( $existing_log, -10 );
		update_post_meta( $post_id, '_editorial_checklist_log', $existing_log );
	}

	/**
	 * Get checklist completion history for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Log entries.
	 */
	public function get_checklist_history( $post_id ) {
		$log = get_post_meta( $post_id, '_editorial_checklist_log', true );
		return is_array( $log ) ? $log : array();
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
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_editorial_checklist_log'
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
	public function get_frontend_config() {
		if ( ! $this->is_checklist_enabled() ) {
			return array( 'enabled' => false );
		}

		return array(
			'enabled'        => true,
			'items'          => $this->get_checklist_items(),
			'nonce'          => wp_create_nonce( 'editorial_io_checklist' ),
			'ajax_url'       => admin_url( 'admin-ajax.php' ),
			'show_for_new'   => apply_filters( 'editorial_io_checklist_show_for_new_posts', false ),
			'show_for_statuses' => apply_filters(
				'editorial_io_checklist_show_for_statuses',
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
			return '<p>' . __( 'No checklist items configured.', 'editorial-io' ) . '</p>';
		}

		$output = '<div class="editorial-io-checklist-items">';
		
		foreach ( $items as $index => $item ) {
			$required_class = $item['required'] ? 'required' : 'optional';
			$required_label = $item['required'] ? __( '(Required)', 'editorial-io' ) : __( '(Optional)', 'editorial-io' );
			
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
			'editorial_io_user_can_bypass_checklist',
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
				'label'    => __( 'I have reviewed all changes', 'editorial-io' ),
				'required' => true,
			),
			array(
				'label'    => __( 'Content has been proofread for errors', 'editorial-io' ),
				'required' => true,
			),
			array(
				'label'    => __( 'Links have been verified', 'editorial-io' ),
				'required' => false,
			),
			array(
				'label'    => __( 'SEO meta data is complete', 'editorial-io' ),
				'required' => false,
			),
			array(
				'label'    => __( 'Images have appropriate alt text', 'editorial-io' ),
				'required' => false,
			),
		);
	}
}