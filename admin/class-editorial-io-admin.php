<?php
/**
 * Admin interface for Editorial.io
 *
 * @package EditorialIO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Editorial_IO_Admin
 *
 * Handles admin pages and interface for the Editorial.io plugin.
 */
class Editorial_IO_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var Editorial_IO_Admin|null
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
	 * @return Editorial_IO_Admin
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

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( EDITORIAL_IO_PLUGIN_DIR . 'editorial-io.php' ), array( $this, 'add_plugin_action_links' ) );
	}

	/**
	 * Add admin menu pages.
	 */
	public function add_admin_menu() {
		// Build menu title with pending count badge.
		$menu_title = __( 'Editorial.io', 'editorial-io' );
		if ( $this->settings->is_feature_enabled( 'staged_revisions' ) && class_exists( 'Editorial_IO_Staged_Revisions' ) ) {
			$pending = Editorial_IO_Staged_Revisions::get_all( array( 'status' => 'pending', 'per_page' => 1 ) );
			$pending_count = count( Editorial_IO_Staged_Revisions::get_all( array( 'status' => 'pending', 'per_page' => 99 ) ) );
			if ( $pending_count > 0 ) {
				$menu_title .= sprintf( ' <span class="awaiting-mod">%d</span>', $pending_count );
			}
		}

		// Main menu page.
		add_menu_page(
			__( 'Editorial.io', 'editorial-io' ),
			$menu_title,
			'edit_others_posts',
			'editorial-io',
			array( $this, 'render_dashboard_page' ),
			'dashicons-edit-large',
			25
		);

		// Dashboard (same as main page).
		add_submenu_page(
			'editorial-io',
			__( 'Dashboard', 'editorial-io' ),
			__( 'Dashboard', 'editorial-io' ),
			'edit_others_posts',
			'editorial-io',
			array( $this, 'render_dashboard_page' )
		);

		// Settings page.
		add_submenu_page(
			'editorial-io',
			__( 'Settings', 'editorial-io' ),
			__( 'Settings', 'editorial-io' ),
			'manage_options',
			'editorial-io-settings',
			array( $this, 'render_settings_page' )
		);

		// Staged Revisions page (if feature is enabled).
		if ( $this->settings->is_feature_enabled( 'staged_revisions' ) ) {
			add_submenu_page(
				'editorial-io',
				__( 'Staged Revisions', 'editorial-io' ),
				__( 'Staged Revisions', 'editorial-io' ),
				'edit_others_posts',
				'editorial-io-staged',
				array( $this, 'render_staged_revisions_page' )
			);
		}

		// Recent Activity page (if revision timeline is enabled).
		if ( $this->settings->is_feature_enabled( 'revision_timeline' ) ) {
			add_submenu_page(
				'editorial-io',
				__( 'Recent Activity', 'editorial-io' ),
				__( 'Recent Activity', 'editorial-io' ),
				'edit_others_posts',
				'editorial-io-activity',
				array( $this, 'render_activity_page' )
			);
		}
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Only load on Editorial.io admin pages.
		if ( strpos( $hook, 'editorial-io' ) === false ) {
			return;
		}

		// Enqueue admin CSS.
		wp_enqueue_style(
			'editorial-io-admin',
			EDITORIAL_IO_ASSETS_URL . 'css/admin.css',
			array(),
			EDITORIAL_IO_VERSION
		);

		// Enqueue admin JavaScript.
		wp_enqueue_script(
			'editorial-io-admin',
			EDITORIAL_IO_ASSETS_URL . 'js/admin.js',
			array( 'jquery', 'wp-api-fetch' ),
			EDITORIAL_IO_VERSION,
			true
		);

		// Enqueue additional scripts for specific pages.
		if ( $hook === 'editorial-io_page_editorial-io-settings' ) {
			wp_enqueue_script( 'jquery-ui-sortable' );
		}

		// Pass configuration to JavaScript.
		wp_localize_script(
			'editorial-io-admin',
			'editorialIOAdmin',
			array(
				'restUrl'    => rest_url( 'editorial/v1/' ),
				'nonce'      => wp_create_nonce( 'editorial_io_admin' ),
				'features'   => $this->settings->get_enabled_features(),
				'strings'    => $this->get_admin_strings(),
				'currentPage' => $this->get_current_admin_page( $hook ),
			)
		);
	}

	/**
	 * Show admin notices.
	 */
	public function show_admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'editorial-io' ) === false ) {
			return;
		}

		// Check for any dependency issues.
		$this->check_feature_dependencies();

		// Show welcome message for new installations.
		if ( get_transient( 'editorial_io_show_welcome' ) ) {
			delete_transient( 'editorial_io_show_welcome' );
			$this->show_welcome_notice();
		}
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array $links Existing action links.
	 * @return array Modified action links.
	 */
	public function add_plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=editorial-io-settings' ),
			__( 'Settings', 'editorial-io' )
		);

		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Add dashboard widget for staged revisions queue.
	 */
	public function add_dashboard_widget() {
		if ( ! $this->settings->is_feature_enabled( 'staged_revisions' ) || ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'editorial_io_queue',
			__( 'Editorial.io Queue', 'editorial-io' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render dashboard widget content.
	 */
	public function render_dashboard_widget() {
		if ( ! class_exists( 'Editorial_IO_Staged_Revisions' ) ) {
			echo '<p>' . esc_html__( 'Staged revisions feature is not loaded.', 'editorial-io' ) . '</p>';
			return;
		}

		$items = Editorial_IO_Staged_Revisions::get_recent( 5 );

		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'No staged revisions pending.', 'editorial-io' ) . '</p>';
			return;
		}

		$status_labels = array(
			'pending'   => __( 'Pending', 'editorial-io' ),
			'approved'  => __( 'Approved', 'editorial-io' ),
			'rejected'  => __( 'Rejected', 'editorial-io' ),
			'scheduled' => __( 'Scheduled', 'editorial-io' ),
		);

		echo '<ul>';
		foreach ( $items as $item ) {
			$author  = get_userdata( $item->staged_author_id );
			$name    = $author ? $author->display_name : __( 'Unknown', 'editorial-io' );
			$status  = $status_labels[ $item->staged_status ] ?? $item->staged_status;
			$edit_url = get_edit_post_link( $item->post_parent );

			printf(
				'<li><a href="%s"><strong>%s</strong></a> — %s <span class="editorial-io-status editorial-io-status-%s">%s</span><br><small>%s — %s</small></li>',
				esc_url( $edit_url ),
				esc_html( $item->revision_title ),
				esc_html( $name ),
				esc_attr( $item->staged_status ),
				esc_html( $status ),
				esc_html( self::format_admin_date( $item->post_modified ) ),
				esc_html( $item->notes )
			);
		}
		echo '</ul>';

		printf(
			'<p class="editorial-io-widget-footer"><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=editorial-io-staged' ) ),
			esc_html__( 'View all staged revisions →', 'editorial-io' )
		);
	}

	/**
	 * Render dashboard page.
	 */
	public function render_dashboard_page() {
		$enabled_features = $this->settings->get_enabled_features();
		$available_features = $this->settings->get_available_features();

		// Get recent activity data.
		$recent_activity = $this->get_recent_activity_data();

		include EDITORIAL_IO_ADMIN_DIR . 'views/dashboard.php';
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		$enabled_features = $this->settings->get_enabled_features();
		$available_features = $this->settings->get_available_features();
		$checklist_items = $this->settings->get_checklist_items();
		$general_options = get_option( Editorial_IO_Settings::OPTION_GENERAL, array() );

		include EDITORIAL_IO_ADMIN_DIR . 'views/settings.php';
	}

	/**
	 * Render staged revisions page.
	 */
	public function render_staged_revisions_page() {
		if ( ! $this->settings->is_feature_enabled( 'staged_revisions' ) ) {
			wp_die( __( 'Staged revisions feature is disabled.', 'editorial-io' ) );
		}

		include EDITORIAL_IO_ADMIN_DIR . 'views/staged-revisions.php';
	}

	/**
	 * Render activity page.
	 */
	public function render_activity_page() {
		if ( ! $this->settings->is_feature_enabled( 'revision_timeline' ) ) {
			wp_die( __( 'Revision timeline feature is disabled.', 'editorial-io' ) );
		}

		include EDITORIAL_IO_ADMIN_DIR . 'views/activity.php';
	}

	/**
	 * Get recent activity data for dashboard.
	 *
	 * @return array
	 */
	private function get_recent_activity_data() {
		$activity = array();

		// Get staged revisions if feature is enabled.
		if ( $this->settings->is_feature_enabled( 'staged_revisions' ) && class_exists( 'Editorial_IO_Staged_Revisions' ) ) {
			$staged = Editorial_IO_Staged_Revisions::get_recent( 5 );
			foreach ( $staged as $item ) {
				$activity[] = array(
					'type'        => 'staged_revision',
					'title'       => $item->post_title,
					'author'      => get_userdata( $item->staged_author_id ),
					'date'        => $item->post_modified,
					'post_id'     => $item->post_parent,
					'revision_id' => $item->revision_id,
					'status'      => $item->staged_status ?? 'pending',
				);
			}
		}

		// Get recent revisions if timeline feature is enabled.
		if ( $this->settings->is_feature_enabled( 'revision_timeline' ) ) {
			$recent_revisions = $this->get_recent_revisions_for_dashboard( 10 );
			foreach ( $recent_revisions as $revision ) {
				$activity[] = array(
					'type'        => 'revision',
					'title'       => $revision->parent_title,
					'author'      => get_userdata( $revision->post_author ),
					'date'        => $revision->post_modified,
					'post_id'     => $revision->post_parent,
					'revision_id' => $revision->ID,
					'changes'     => $this->determine_revision_changes( $revision ),
				);
			}
		}

		// Sort by date (most recent first).
		usort( $activity, function( $a, $b ) {
			return strtotime( $b['date'] ) - strtotime( $a['date'] );
		});

		return array_slice( $activity, 0, 10 );
	}

	/**
	 * Get recent revisions for dashboard.
	 *
	 * @param int $limit Number of revisions to fetch.
	 * @return array
	 */
	private function get_recent_revisions_for_dashboard( $limit ) {
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT r.*, p.post_title as parent_title, p.post_type as parent_type
			 FROM {$wpdb->posts} r
			 INNER JOIN {$wpdb->posts} p ON r.post_parent = p.ID
			 WHERE r.post_type = 'revision'
			 AND p.post_status = 'publish'
			 ORDER BY r.post_modified DESC
			 LIMIT %d",
			$limit
		);

		return $wpdb->get_results( $query );
	}

	/**
	 * Determine what changed in a revision.
	 *
	 * @param object $revision The revision data.
	 * @return array
	 */
	private function determine_revision_changes( $revision ) {
		$parent = get_post( $revision->post_parent );
		if ( ! $parent ) {
			return array();
		}

		$changes = array();
		if ( $revision->post_title !== $parent->post_title ) {
			$changes[] = 'title';
		}
		if ( $revision->post_content !== $parent->post_content ) {
			$changes[] = 'content';
		}
		if ( $revision->post_excerpt !== $parent->post_excerpt ) {
			$changes[] = 'excerpt';
		}

		return $changes;
	}

	/**
	 * Check feature dependencies and show notices if needed.
	 */
	private function check_feature_dependencies() {
		$enabled_features = $this->settings->get_enabled_features();
		$available_features = $this->settings->get_available_features();

		foreach ( $enabled_features as $key => $enabled ) {
			if ( ! $enabled || ! isset( $available_features[ $key ] ) ) {
				continue;
			}

			$feature = $available_features[ $key ];
			if ( ! empty( $feature['requires'] ) ) {
				foreach ( $feature['requires'] as $required ) {
					if ( empty( $enabled_features[ $required ] ) ) {
						$this->show_dependency_warning( $key, $required );
					}
				}
			}
		}
	}

	/**
	 * Show dependency warning notice.
	 *
	 * @param string $feature   The dependent feature.
	 * @param string $required  The required feature.
	 */
	private function show_dependency_warning( $feature, $required ) {
		$available_features = $this->settings->get_available_features();
		$feature_label = $available_features[ $feature ]['label'] ?? $feature;
		$required_label = $available_features[ $required ]['label'] ?? $required;

		printf(
			'<div class="notice notice-warning"><p><strong>%s:</strong> %s</p></div>',
			esc_html__( 'Editorial.io Warning', 'editorial-io' ),
			sprintf(
				/* translators: %1$s and %2$s are feature names */
				esc_html__( '%1$s requires %2$s to be enabled. Some functionality may not work correctly.', 'editorial-io' ),
				esc_html( $feature_label ),
				esc_html( $required_label )
			)
		);
	}

	/**
	 * Show welcome notice for new installations.
	 */
	private function show_welcome_notice() {
		printf(
			'<div class="notice notice-info is-dismissible"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Welcome to Editorial.io!', 'editorial-io' ),
			esc_html__( 'Your editorial workflow suite is ready.', 'editorial-io' ),
			esc_url( admin_url( 'admin.php?page=editorial-io-settings' ) ),
			esc_html__( 'Configure your features →', 'editorial-io' )
		);
	}

	/**
	 * Get localized strings for admin JavaScript.
	 *
	 * @return array
	 */
	private function get_admin_strings() {
		return array(
			'confirm'              => __( 'Are you sure?', 'editorial-io' ),
			'save'                 => __( 'Save', 'editorial-io' ),
			'saving'               => __( 'Saving...', 'editorial-io' ),
			'saved'                => __( 'Saved successfully', 'editorial-io' ),
			'error'                => __( 'An error occurred', 'editorial-io' ),
			'loading'              => __( 'Loading...', 'editorial-io' ),
			'noData'               => __( 'No data available', 'editorial-io' ),
			'confirmDelete'        => __( 'Are you sure you want to delete this item?', 'editorial-io' ),
			'confirmReset'         => __( 'Are you sure you want to reset all features to their default settings?', 'editorial-io' ),
			'featureEnabled'       => __( 'Feature enabled successfully', 'editorial-io' ),
			'featureDisabled'      => __( 'Feature disabled successfully', 'editorial-io' ),
			'dependencyWarning'    => __( 'This feature has dependencies that are not enabled', 'editorial-io' ),
			'checklistUpdated'     => __( 'Checklist updated successfully', 'editorial-io' ),
			'settingsUpdated'      => __( 'Settings updated successfully', 'editorial-io' ),
			'publishRevision'      => __( 'Publish this revision?', 'editorial-io' ),
			'publishingRevision'   => __( 'Publishing...', 'editorial-io' ),
			'revisionPublished'    => __( 'Revision published successfully', 'editorial-io' ),
			'approveRevision'      => __( 'Approve this revision?', 'editorial-io' ),
			'rejectRevision'       => __( 'Reject this revision?', 'editorial-io' ),
			'discardRevision'      => __( 'Discard this revision permanently?', 'editorial-io' ),
			'restoreRevision'      => __( 'Restore this revision? This will replace the current content.', 'editorial-io' ),
			'ago'                  => __( 'ago', 'editorial-io' ),
			'justNow'              => __( 'Just now', 'editorial-io' ),
		);
	}

	/**
	 * Get current admin page identifier.
	 *
	 * @param string $hook Current admin page hook.
	 * @return string
	 */
	private function get_current_admin_page( $hook ) {
		$pages = array(
			'toplevel_page_editorial-io'          => 'dashboard',
			'editorial-io_page_editorial-io-settings' => 'settings',
			'editorial-io_page_editorial-io-staged'   => 'staged',
			'editorial-io_page_editorial-io-activity' => 'activity',
		);

		return $pages[ $hook ] ?? 'unknown';
	}

	/**
	 * Get feature status summary for dashboard.
	 *
	 * @return array
	 */
	public function get_feature_status_summary() {
		$enabled_features = $this->settings->get_enabled_features();
		$available_features = $this->settings->get_available_features();

		$total_features = count( $available_features );
		$enabled_count = count( array_filter( $enabled_features ) );

		return array(
			'total'           => $total_features,
			'enabled'         => $enabled_count,
			'disabled'        => $total_features - $enabled_count,
			'enabled_list'    => array_keys( array_filter( $enabled_features ) ),
			'disabled_list'   => array_keys( array_filter( $enabled_features, function( $enabled ) {
				return ! $enabled;
			} ) ),
		);
	}

	/**
	 * Format date/time for admin display.
	 *
	 * @param string $date The date string.
	 * @return string
	 */
	public static function format_admin_date( $date ) {
		$timestamp = strtotime( $date );
		$now = time();
		$diff = $now - $timestamp;

		// Less than 1 hour.
		if ( $diff < HOUR_IN_SECONDS ) {
			return sprintf(
				/* translators: %s: number of minutes */
				_n( '%s minute ago', '%s minutes ago', round( $diff / MINUTE_IN_SECONDS ), 'editorial-io' ),
				round( $diff / MINUTE_IN_SECONDS )
			);
		}

		// Less than 1 day.
		if ( $diff < DAY_IN_SECONDS ) {
			return sprintf(
				/* translators: %s: number of hours */
				_n( '%s hour ago', '%s hours ago', round( $diff / HOUR_IN_SECONDS ), 'editorial-io' ),
				round( $diff / HOUR_IN_SECONDS )
			);
		}

		// More than 1 day - show actual date.
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}