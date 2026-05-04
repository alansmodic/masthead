<?php
/**
 * Masthead GitHub Installer
 *
 * Downloads, installs, and activates Masthead suite plugins directly
 * from their GitHub releases. Uses WordPress core upgrader infrastructure.
 *
 * @package Masthead
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Masthead_GitHub_Installer {

	/**
	 * GitHub org/user for all suite plugins.
	 */
	const GITHUB_USER = 'alansmodic';

	/**
	 * Map module id → GitHub repo name.
	 */
	const REPOS = [
		'edit-ledger' => 'edit-ledger',
		'rewrites'    => 'rewrites',
		'redline'     => 'redline',
	];

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_masthead_install_module', [ $this, 'ajax_install' ] );
		add_action( 'wp_ajax_masthead_activate_module', [ $this, 'ajax_activate' ] );
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	public function ajax_install(): void {
		check_ajax_referer( 'masthead_admin', 'nonce' );

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to install plugins.', 'masthead' ) ] );
		}

		$module = sanitize_key( $_POST['module'] ?? '' );

		if ( ! isset( self::REPOS[ $module ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown module.', 'masthead' ) ] );
		}

		$result = $this->install( $module );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( [
			'message'    => sprintf( __( '%s installed successfully.', 'masthead' ), $module ),
			'plugin_file' => $result,
		] );
	}

	public function ajax_activate(): void {
		check_ajax_referer( 'masthead_admin', 'nonce' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to activate plugins.', 'masthead' ) ] );
		}

		$module = sanitize_key( $_POST['module'] ?? '' );

		if ( ! isset( self::REPOS[ $module ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown module.', 'masthead' ) ] );
		}

		$plugin_file = Masthead_Module_Registry::MODULES[ $module ]['file'] ?? '';

		if ( ! $plugin_file ) {
			wp_send_json_error( [ 'message' => __( 'Cannot determine plugin file.', 'masthead' ) ] );
		}

		$result = activate_plugin( $plugin_file );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( [ 'message' => sprintf( __( '%s activated.', 'masthead' ), $module ) ] );
	}

	// -------------------------------------------------------------------------
	// Core install logic
	// -------------------------------------------------------------------------

	/**
	 * Install a module from its latest GitHub release.
	 *
	 * @param string $module Module ID (e.g. 'rewrites').
	 * @return string|WP_Error Plugin file path on success, WP_Error on failure.
	 */
	public function install( string $module ): string|WP_Error {
		$repo = self::REPOS[ $module ] ?? null;

		if ( ! $repo ) {
			return new WP_Error( 'unknown_module', __( 'Unknown module.', 'masthead' ) );
		}

		// 1. Get latest release zip URL from GitHub API.
		$zip_url = $this->get_release_zip_url( $repo );

		if ( is_wp_error( $zip_url ) ) {
			return $zip_url;
		}

		// 2. Download the zip to a temp file.
		$tmp = download_url( $zip_url );

		if ( is_wp_error( $tmp ) ) {
			return new WP_Error( 'download_failed', sprintf(
				/* translators: %s: error message */
				__( 'Download failed: %s', 'masthead' ),
				$tmp->get_error_message()
			) );
		}

		// 3. Install via WordPress upgrader.
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		WP_Filesystem();

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		$result = $upgrader->install( $tmp );

		// Clean up temp file.
		@unlink( $tmp );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result ) {
			return new WP_Error( 'install_failed', $skin->get_error_messages() ?: __( 'Installation failed.', 'masthead' ) );
		}

		// 4. GitHub zips extract as `{repo}-{branch}/` — rename to the expected slug if needed.
		$plugin_file = $this->fix_plugin_folder( $module, $repo );

		if ( is_wp_error( $plugin_file ) ) {
			return $plugin_file;
		}

		return $plugin_file;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Get the latest release zip URL from the GitHub API.
	 */
	private function get_release_zip_url( string $repo ): string|WP_Error {
		$api_url  = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', self::GITHUB_USER, $repo );

		$response = wp_remote_get( $api_url, [
			'headers' => [
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'Masthead-Plugin/' . MASTHEAD_VERSION,
			],
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		// No release published yet — fall back to default branch zip.
		if ( 404 === $code ) {
			return $this->get_default_branch_zip( $repo );
		}

		if ( 200 !== $code ) {
			return new WP_Error( 'github_api_error', sprintf(
				__( 'GitHub API returned %d for %s.', 'masthead' ),
				$code,
				$repo
			) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Prefer a plugin zip asset, fall back to zipball.
		foreach ( $body['assets'] ?? [] as $asset ) {
			if ( str_ends_with( $asset['name'], '.zip' ) ) {
				return $asset['browser_download_url'];
			}
		}

		return $body['zipball_url'] ?? $this->get_default_branch_zip( $repo );
	}

	/**
	 * Get the zip URL for the repo's default branch.
	 */
	private function get_default_branch_zip( string $repo ): string {
		$response = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s/%s', self::GITHUB_USER, $repo ),
			[
				'headers'  => [ 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'Masthead-Plugin/' . MASTHEAD_VERSION ],
				'timeout'  => 10,
			]
		);

		$branch = 'main'; // safe default.

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data   = json_decode( wp_remote_retrieve_body( $response ), true );
			$branch = $data['default_branch'] ?? 'main';
		}

		return sprintf( 'https://github.com/%s/%s/archive/refs/heads/%s.zip', self::GITHUB_USER, $repo, $branch );
	}

	/**
	 * After installation, ensure the plugin folder matches the expected slug.
	 * GitHub zips extract as `{repo}-main/` but WordPress expects `{repo}/`.
	 */
	private function fix_plugin_folder( string $module, string $repo ): string|WP_Error {
		global $wp_filesystem;

		$plugins_dir    = WP_PLUGIN_DIR . '/';
		$expected_slug  = $repo;
		$expected_dir   = $plugins_dir . $expected_slug;
		$expected_file  = Masthead_Module_Registry::MODULES[ $module ]['file'];

		// Already in the right place.
		if ( is_dir( $expected_dir ) && file_exists( $plugins_dir . $expected_file ) ) {
			return $expected_file;
		}

		// Look for GitHub-style extracted folder: `{repo}-main`, `{repo}-master`, etc.
		$candidates = glob( $plugins_dir . $repo . '-*', GLOB_ONLYDIR );

		if ( empty( $candidates ) ) {
			return new WP_Error( 'folder_not_found', sprintf(
				__( 'Could not locate installed folder for %s.', 'masthead' ),
				$repo
			) );
		}

		$source = $candidates[0];

		// Rename to expected slug.
		if ( ! rename( $source, $expected_dir ) ) {
			return new WP_Error( 'rename_failed', sprintf(
				__( 'Could not rename %s to %s.', 'masthead' ),
				basename( $source ),
				$expected_slug
			) );
		}

		return $expected_file;
	}
}
