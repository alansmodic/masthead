<?php
/**
 * Masthead GitHub Updater
 *
 * Hooks into WordPress's update pipeline to detect new versions of
 * Masthead suite plugins on GitHub and offer in-place updates.
 *
 * @package Masthead
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Masthead_GitHub_Updater {

	const GITHUB_USER    = 'alansmodic';
	const CACHE_KEY      = 'masthead_github_versions';
	const CACHE_DURATION = HOUR_IN_SECONDS * 6; // Check GitHub every 6 hours.

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Inject into WordPress update check.
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_updates' ] );

		// Provide plugin info when user clicks "View version X.X.X details".
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 20, 3 );

		// After a successful update, clear our version cache.
		add_action( 'upgrader_process_complete', [ $this, 'clear_cache' ], 10, 2 );

		// AJAX: force refresh version cache.
		add_action( 'wp_ajax_masthead_check_updates', [ $this, 'ajax_check_updates' ] );
	}

	// -------------------------------------------------------------------------
	// WordPress update pipeline hooks
	// -------------------------------------------------------------------------

	/**
	 * Inject GitHub update data into WordPress's update transient.
	 */
	public function check_for_updates( object $transient ): object {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$registry = Masthead_Module_Registry::get_instance();
		$versions = $this->get_remote_versions();

		foreach ( Masthead_Module_Registry::MODULES as $id => $module ) {
			if ( empty( $module['file'] ) ) {
				continue;
			}

			if ( ! $registry->is_active( $id ) ) {
				continue;
			}

			$plugin_file     = $module['file'];
			$installed_data  = $this->get_installed_data( $plugin_file );
			$installed_ver   = $installed_data['Version'] ?? '0.0.0';
			$remote          = $versions[ $id ] ?? null;

			if ( ! $remote ) {
				continue;
			}

			if ( version_compare( $remote['version'], $installed_ver, '>' ) ) {
				$transient->response[ $plugin_file ] = (object) [
					'slug'        => dirname( $plugin_file ),
					'plugin'      => $plugin_file,
					'new_version' => $remote['version'],
					'url'         => $remote['html_url'],
					'package'     => $remote['zip_url'],
					'tested'      => $remote['tested'] ?? '',
					'requires'    => $remote['requires'] ?? '6.9',
				];
			} else {
				// No update — but tell WP we checked.
				$transient->no_update[ $plugin_file ] = (object) [
					'slug'        => dirname( $plugin_file ),
					'plugin'      => $plugin_file,
					'new_version' => $installed_ver,
					'url'         => $module['repo'] ?? '',
					'package'     => '',
				];
			}
		}

		return $transient;
	}

	/**
	 * Provide plugin info modal when user clicks "View version X details".
	 */
	public function plugin_info( mixed $result, string $action, object $args ): mixed {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		foreach ( Masthead_Module_Registry::MODULES as $id => $module ) {
			if ( empty( $module['file'] ) ) {
				continue;
			}

			$slug = dirname( $module['file'] );
			if ( $args->slug !== $slug ) {
				continue;
			}

			$versions = $this->get_remote_versions();
			$remote   = $versions[ $id ] ?? null;

			if ( ! $remote ) {
				return $result;
			}

			return (object) [
				'name'          => $module['label'],
				'slug'          => $slug,
				'version'       => $remote['version'],
				'author'        => 'Alan Smodic',
				'homepage'      => $module['repo'] ?? '',
				'download_link' => $remote['zip_url'],
				'sections'      => [
					'description' => $module['description'],
					'changelog'   => $remote['changelog'] ?? 'See GitHub releases for changelog.',
				],
				'last_updated'  => $remote['published_at'] ?? '',
				'requires'      => '6.9',
			];
		}

		return $result;
	}

	/**
	 * Clear version cache after any plugin upgrade.
	 */
	public function clear_cache( WP_Upgrader $upgrader, array $hook_extra ): void {
		if ( ( $hook_extra['type'] ?? '' ) === 'plugin' ) {
			delete_transient( self::CACHE_KEY );
		}
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	public function ajax_check_updates(): void {
		check_ajax_referer( 'masthead_admin', 'nonce' );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		delete_transient( self::CACHE_KEY );
		$versions = $this->get_remote_versions( force: true );

		$registry  = Masthead_Module_Registry::get_instance();
		$available = [];

		foreach ( Masthead_Module_Registry::MODULES as $id => $module ) {
			if ( empty( $module['file'] ) ) {
				continue;
			}

			if ( ! $registry->is_active( $id ) ) {
				continue;
			}

			$installed_ver = $this->get_installed_data( $module['file'] )['Version'] ?? '0.0.0';
			$remote_ver    = $versions[ $id ]['version'] ?? null;

			$available[ $id ] = [
				'installed' => $installed_ver,
				'remote'    => $remote_ver,
				'update'    => $remote_ver && version_compare( $remote_ver, $installed_ver, '>' ),
			];
		}

		wp_send_json_success( [ 'modules' => $available ] );
	}

	// -------------------------------------------------------------------------
	// Version fetching
	// -------------------------------------------------------------------------

	/**
	 * Get latest release versions from GitHub for all active modules.
	 * Cached for 6 hours to avoid hammering the API.
	 */
	public function get_remote_versions( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$versions = [];

		foreach ( Masthead_GitHub_Installer::REPOS as $id => $repo ) {
			$data = $this->fetch_release_data( $repo );
			if ( $data ) {
				$versions[ $id ] = $data;
			}
		}

		set_transient( self::CACHE_KEY, $versions, self::CACHE_DURATION );

		return $versions;
	}

	/**
	 * Fetch latest release data from GitHub for one repo.
	 */
	private function fetch_release_data( string $repo ): ?array {
		$api_url  = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			self::GITHUB_USER,
			$repo
		);

		$response = wp_remote_get( $api_url, [
			'headers' => [
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'Masthead-Plugin/' . MASTHEAD_VERSION,
			],
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['tag_name'] ) ) {
			return null;
		}

		// Normalise tag name: strip leading 'v' if present.
		$version = ltrim( $body['tag_name'], 'v' );

		// Prefer a zip asset; fall back to zipball.
		$zip_url = $body['zipball_url'];
		foreach ( $body['assets'] ?? [] as $asset ) {
			if ( str_ends_with( $asset['name'], '.zip' ) ) {
				$zip_url = $asset['browser_download_url'];
				break;
			}
		}

		return [
			'version'      => $version,
			'zip_url'      => $zip_url,
			'html_url'     => $body['html_url'],
			'published_at' => $body['published_at'] ?? '',
			'changelog'    => $body['body'] ?? '', // GitHub release notes as changelog.
		];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Get installed plugin header data.
	 */
	private function get_installed_data( string $plugin_file ): array {
		$path = WP_PLUGIN_DIR . '/' . $plugin_file;

		if ( ! file_exists( $path ) ) {
			return [];
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return get_plugin_data( $path, false, false );
	}

	/**
	 * Get update status for all active modules (used by dashboard).
	 */
	public function get_update_status(): array {
		$versions = $this->get_remote_versions();
		$registry = Masthead_Module_Registry::get_instance();
		$status   = [];

		foreach ( Masthead_Module_Registry::MODULES as $id => $module ) {
			if ( empty( $module['file'] ) ) {
				continue;
			}

			if ( ! $registry->is_active( $id ) ) {
				continue;
			}

			$installed = $this->get_installed_data( $module['file'] )['Version'] ?? '0.0.0';
			$remote    = $versions[ $id ]['version'] ?? null;

			$status[ $id ] = [
				'installed'  => $installed,
				'remote'     => $remote,
				'has_update' => $remote && version_compare( $remote, $installed, '>' ),
			];
		}

		return $status;
	}
}
