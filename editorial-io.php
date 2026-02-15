<?php
/**
 * Plugin Name: Editorial.io
 * Description: Complete editorial workflow suite with staged revisions, publication checklist, visual revision timeline, word-level diffs, and media change tracking.
 * Version: 1.0.0
 * Author: Alan
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: editorial-io
 *
 * @package EditorialIO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'EDITORIAL_IO_VERSION', '1.0.0' );
define( 'EDITORIAL_IO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EDITORIAL_IO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EDITORIAL_IO_INCLUDES_DIR', EDITORIAL_IO_PLUGIN_DIR . 'includes/' );
define( 'EDITORIAL_IO_ADMIN_DIR', EDITORIAL_IO_PLUGIN_DIR . 'admin/' );
define( 'EDITORIAL_IO_ASSETS_URL', EDITORIAL_IO_PLUGIN_URL . 'assets/' );

// Include core files.
require_once EDITORIAL_IO_INCLUDES_DIR . 'class-editorial-io.php';
require_once EDITORIAL_IO_INCLUDES_DIR . 'class-editorial-io-settings.php';
require_once EDITORIAL_IO_INCLUDES_DIR . 'class-editorial-io-rest-controller.php';
require_once EDITORIAL_IO_INCLUDES_DIR . 'class-editorial-io-abilities.php';
require_once EDITORIAL_IO_ADMIN_DIR . 'class-editorial-io-admin.php';

// Feature modules.
require_once EDITORIAL_IO_INCLUDES_DIR . 'features/class-staged-revisions.php';
require_once EDITORIAL_IO_INCLUDES_DIR . 'features/class-publication-checklist.php';
require_once EDITORIAL_IO_INCLUDES_DIR . 'features/class-scheduled-publishing.php';
require_once EDITORIAL_IO_INCLUDES_DIR . 'features/class-revision-timeline.php';
require_once EDITORIAL_IO_INCLUDES_DIR . 'features/class-word-level-diffs.php';
require_once EDITORIAL_IO_INCLUDES_DIR . 'features/class-media-change-tracking.php';

/**
 * Initialize the plugin.
 */
function editorial_io_init() {
	// Initialize core classes.
	Editorial_IO::get_instance();
	Editorial_IO_Settings::get_instance();
	Editorial_IO_Admin::get_instance();

	// Initialize Abilities API integration if available (WP 6.9+).
	if ( function_exists( 'wp_register_ability' ) ) {
		Editorial_IO_Abilities::get_instance();
	}

	// Initialize feature modules based on settings.
	$settings = Editorial_IO_Settings::get_instance();

	if ( $settings->is_feature_enabled( 'staged_revisions' ) ) {
		Editorial_IO_Staged_Revisions::get_instance();
	}

	if ( $settings->is_feature_enabled( 'publication_checklist' ) ) {
		Editorial_IO_Publication_Checklist::get_instance();
	}

	if ( $settings->is_feature_enabled( 'scheduled_publishing' ) ) {
		Editorial_IO_Scheduled_Publishing::get_instance();
	}

	if ( $settings->is_feature_enabled( 'revision_timeline' ) ) {
		Editorial_IO_Revision_Timeline::get_instance();
	}

	if ( $settings->is_feature_enabled( 'word_level_diffs' ) ) {
		Editorial_IO_Word_Level_Diffs::get_instance();
	}

	if ( $settings->is_feature_enabled( 'media_change_tracking' ) ) {
		Editorial_IO_Media_Change_Tracking::get_instance();
	}
}
add_action( 'plugins_loaded', 'editorial_io_init' );

/**
 * Plugin activation hook.
 */
function editorial_io_activate() {
	// Set default feature states.
	Editorial_IO_Settings::set_default_features();

	// Schedule any cleanup cron if needed.
	if ( ! wp_next_scheduled( 'editorial_io_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'editorial_io_cleanup' );
	}

	// Flush rewrite rules to ensure REST API endpoints work.
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'editorial_io_activate' );

/**
 * Plugin deactivation hook.
 */
function editorial_io_deactivate() {
	// Clear any scheduled cron events.
	wp_clear_scheduled_hook( 'editorial_io_publish_staged' );
	wp_clear_scheduled_hook( 'editorial_io_cleanup' );

	// Flush rewrite rules.
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'editorial_io_deactivate' );