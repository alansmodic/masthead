<?php
/**
 * Masthead Admin
 *
 * Suite settings screen, dashboard widget, and admin menu.
 *
 * @package Masthead
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Masthead_Admin {

	private static ?self $instance = null;
	private Masthead_Settings $settings;
	private Masthead_Module_Registry $registry;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = Masthead_Settings::get_instance();
		$this->registry = Masthead_Module_Registry::get_instance();

		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'wp_dashboard_setup', [ $this, 'add_dashboard_widget' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( MASTHEAD_PLUGIN_DIR . 'masthead.php' ), [ $this, 'plugin_action_links' ] );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'Masthead', 'masthead' ),
			__( 'Masthead', 'masthead' ),
			'edit_others_posts',
			'masthead',
			[ $this, 'render_dashboard' ],
			'dashicons-welcome-write-blog',
			25
		);

		add_submenu_page( 'masthead', __( 'Dashboard', 'masthead' ), __( 'Dashboard', 'masthead' ), 'edit_others_posts', 'masthead', [ $this, 'render_dashboard' ] );
		add_submenu_page( 'masthead', __( 'Settings', 'masthead' ), __( 'Settings', 'masthead' ), 'manage_options', 'masthead-settings', [ $this, 'render_settings' ] );

		if ( $this->settings->is_feature_enabled( 'staged_revisions' ) ) {
			$pending = $this->get_pending_count();
			$label   = $pending > 0
				? sprintf( '%s <span class="awaiting-mod">%d</span>', __( 'Staged Revisions', 'masthead' ), $pending )
				: __( 'Staged Revisions', 'masthead' );

			add_submenu_page( 'masthead', __( 'Staged Revisions', 'masthead' ), $label, 'edit_others_posts', 'masthead-staged', [ $this, 'render_staged' ] );
		}
	}

	public function render_dashboard(): void {
		$modules  = $this->registry->get_all();
		$features = $this->settings->get_enabled_features();
		include MASTHEAD_ADMIN_DIR . 'views/dashboard.php';
	}

	public function render_settings(): void {
		$modules          = $this->registry->get_all();
		$features         = $this->settings->get_enabled_features();
		$integrations     = $this->settings->get_integrations();
		$avail_features   = $this->settings->get_available_features();
		$avail_integ      = $this->settings->get_available_integrations();
		$checklist_items  = $this->settings->get_checklist_items();
		$settings_obj     = $this->settings;
		include MASTHEAD_ADMIN_DIR . 'views/settings.php';
	}

	public function render_staged(): void {
		if ( ! $this->settings->is_feature_enabled( 'staged_revisions' ) ) {
			wp_die( esc_html__( 'Staged revisions are disabled.', 'masthead' ) );
		}
		include MASTHEAD_ADMIN_DIR . 'views/staged-revisions.php';
	}

	public function add_dashboard_widget(): void {
		if ( ! $this->settings->is_feature_enabled( 'staged_revisions' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'masthead_queue', __( 'Masthead Queue', 'masthead' ), [ $this, 'render_dashboard_widget' ] );
	}

	public function render_dashboard_widget(): void {
		if ( ! class_exists( 'Masthead_Staged_Revisions' ) ) {
			echo '<p>' . esc_html__( 'Staged revisions are not loaded.', 'masthead' ) . '</p>';
			return;
		}

		$items = Masthead_Staged_Revisions::get_recent( 5 );

		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'No staged revisions pending.', 'masthead' ) . '</p>';
			return;
		}

		echo '<ul>';
		foreach ( $items as $item ) {
			$author = get_userdata( $item->staged_author_id );
			$name   = $author ? $author->display_name : __( 'Unknown', 'masthead' );
			printf(
				'<li><a href="%s"><strong>%s</strong></a> — %s <span class="masthead-status masthead-status-%s">%s</span></li>',
				esc_url( get_edit_post_link( $item->post_parent ) ),
				esc_html( $item->post_title ),
				esc_html( $name ),
				esc_attr( $item->staged_status ),
				esc_html( ucfirst( $item->staged_status ) )
			);
		}
		echo '</ul>';
		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=masthead-staged' ) ),
			esc_html__( 'View all →', 'masthead' )
		);
	}

	public function plugin_action_links( array $links ): array {
		array_unshift( $links, sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=masthead-settings' ),
			__( 'Settings', 'masthead' )
		) );
		return $links;
	}

	private function get_pending_count(): int {
		if ( ! class_exists( 'Masthead_Staged_Revisions' ) ) {
			return 0;
		}
		return count( Masthead_Staged_Revisions::get_all( [ 'status' => 'pending', 'per_page' => 99 ] ) );
	}
}
