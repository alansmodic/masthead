<?php
/**
 * Plugin Name: Masthead
 * Description: The WordPress editorial suite. Bundles Edit Ledger, Rewrites, and Redline into a unified workflow with a single settings screen and cross-plugin integrations.
 * Version: 1.1.0
 * Author: Alan Smodic
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Text Domain: masthead
 *
 * @package Masthead
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'MASTHEAD_VERSION', '1.1.0' );
define( 'MASTHEAD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MASTHEAD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MASTHEAD_INCLUDES_DIR', MASTHEAD_PLUGIN_DIR . 'includes/' );
define( 'MASTHEAD_ADMIN_DIR', MASTHEAD_PLUGIN_DIR . 'admin/' );
define( 'MASTHEAD_ASSETS_URL', MASTHEAD_PLUGIN_URL . 'assets/' );

// Core.
require_once MASTHEAD_INCLUDES_DIR . 'class-masthead.php';
require_once MASTHEAD_INCLUDES_DIR . 'class-masthead-settings.php';
require_once MASTHEAD_INCLUDES_DIR . 'class-masthead-module-registry.php';
require_once MASTHEAD_INCLUDES_DIR . 'class-masthead-rest-controller.php';
require_once MASTHEAD_INCLUDES_DIR . 'class-masthead-ai.php';
require_once MASTHEAD_INCLUDES_DIR . 'class-masthead-connector.php';
require_once MASTHEAD_INCLUDES_DIR . 'class-masthead-github-installer.php';
require_once MASTHEAD_INCLUDES_DIR . 'class-masthead-github-updater.php';
require_once MASTHEAD_ADMIN_DIR . 'class-masthead-admin.php';

// Cross-plugin integrations (loaded conditionally after plugins_loaded).
require_once MASTHEAD_INCLUDES_DIR . 'integrations/class-masthead-edit-ledger-rewrites.php';
require_once MASTHEAD_INCLUDES_DIR . 'integrations/class-masthead-ai-rewrites.php';

/**
 * Initialize Masthead.
 */
function masthead_init() {
	Masthead::get_instance();
	Masthead_Settings::get_instance();
	Masthead_Admin::get_instance();
	Masthead_AI::get_instance();
	Masthead_Connector::get_instance();
	Masthead_GitHub_Installer::get_instance();
	Masthead_GitHub_Updater::get_instance();

	// Load cross-plugin integrations only when both sides are active.
	$registry = Masthead_Module_Registry::get_instance();

	if ( $registry->is_active( 'edit-ledger' ) && $registry->is_active( 'rewrites' ) ) {
		Masthead_Edit_Ledger_Rewrites::get_instance();
	}

	// AI-powered editorial features via WP 7.0 AI Client.
	if ( function_exists( 'wp_ai_client_prompt' ) ) {
		Masthead_AI_Rewrites::get_instance();
	}
}
add_action( 'plugins_loaded', 'masthead_init' );

/**
 * Activation.
 */
function masthead_activate() {
	Masthead_Settings::set_defaults();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'masthead_activate' );

/**
 * Deactivation.
 */
function masthead_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'masthead_deactivate' );
