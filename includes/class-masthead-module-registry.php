<?php
/**
 * Masthead Module Registry
 *
 * Detects which Masthead suite plugins are installed and active.
 *
 * @package Masthead
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Masthead_Module_Registry {

	/**
	 * Known Masthead suite modules and their plugin file paths.
	 */
	const MODULES = [
		'edit-ledger' => [
			'file'        => 'edit-ledger/edit-ledger.php',
			'label'       => 'Masthead: Edit Ledger',
			'description' => 'Revision history, media change tracking, and AI summaries.',
			'repo'        => 'https://github.com/alansmodic/edit-ledger',
		],
		'rewrites' => [
			'file'        => 'rewrites/rewrites.php',
			'label'       => 'Masthead: Rewrites',
			'description' => 'Staged revisions, publication checklist, and scheduled publishing.',
			'repo'        => 'https://github.com/alansmodic/rewrites',
		],
		'wordpress-ai' => [
			'file'        => null,
			'label'       => 'WP AI Client',
			'description' => 'WordPress 7.0 built-in AI features. Configure providers at Settings → Connectors.',
			'repo'        => null,
			'builtin'     => true,
		],
		'editorial-calendar' => [
			'file'        => 'editorial-calendar/editorial-calendar.php',
			'label'       => 'Masthead: Editorial Calendar',
			'description' => 'Visual drag-and-drop publishing calendar. Unlocks richer views as you add more Masthead plugins.',
			'repo'        => 'https://github.com/alansmodic/editorial-calendar',
		],
	];

	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Check if a module is currently active.
	 */
	public function is_active( string $module ): bool {
		if ( ! isset( self::MODULES[ $module ] ) ) {
			return false;
		}

		$meta = self::MODULES[ $module ];

		// Built-in modules (e.g., WP AI Client) are active if their API exists.
		if ( ! empty( $meta['builtin'] ) ) {
			return $this->is_builtin_available( $module );
		}

		if ( ! $meta['file'] ) {
			return false;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( $meta['file'] );
	}

	/**
	 * Check whether a module is known to Masthead.
	 */
	public function exists( string $module ): bool {
		return isset( self::MODULES[ $module ] );
	}

	/**
	 * Check if a module is installed (but not necessarily active).
	 */
	public function is_installed( string $module ): bool {
		if ( ! isset( self::MODULES[ $module ] ) ) {
			return false;
		}

		$meta = self::MODULES[ $module ];

		// Built-in modules are always "installed" on WP 7.0+.
		if ( ! empty( $meta['builtin'] ) ) {
			return $this->is_builtin_available( $module );
		}

		if ( ! $meta['file'] ) {
			return false;
		}

		return file_exists( WP_PLUGIN_DIR . '/' . $meta['file'] );
	}

	/**
	 * Check whether a module can be installed by Masthead.
	 */
	public function is_installable( string $module ): bool {
		if ( ! isset( self::MODULES[ $module ] ) ) {
			return false;
		}

		$meta = self::MODULES[ $module ];

		return empty( $meta['builtin'] ) && ! empty( $meta['file'] ) && ! empty( $meta['repo'] );
	}

	/**
	 * Check whether a module can be activated by Masthead.
	 */
	public function is_activatable( string $module ): bool {
		return $this->is_installable( $module ) && $this->is_installed( $module ) && ! $this->is_active( $module );
	}

	/**
	 * Check whether all required modules are active.
	 */
	public function requirements_met( array $modules ): bool {
		foreach ( $modules as $module ) {
			if ( ! $this->is_active( $module ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return required modules that are not currently active.
	 */
	public function missing_requirements( array $modules ): array {
		$missing = [];

		foreach ( $modules as $module ) {
			if ( ! $this->is_active( $module ) ) {
				$missing[] = $module;
			}
		}

		return $missing;
	}

	/**
	 * Get all modules with their current status.
	 */
	public function get_all(): array {
		$result = [];
		foreach ( self::MODULES as $id => $meta ) {
			$active    = $this->is_active( $id );
			$installed = $this->is_installed( $id );

			$result[ $id ] = array_merge( $meta, [
				'active'      => $active,
				'installed'   => $installed,
				'installable' => $this->is_installable( $id ),
				'activatable' => $this->is_activatable( $id ),
				'status'      => $this->get_status( $id, $active, $installed ),
				'message'     => $this->get_status_message( $id, $active, $installed ),
			] );
		}
		return $result;
	}

	/**
	 * Get only active module IDs.
	 */
	public function active_modules(): array {
		return array_values( array_filter(
			array_keys( self::MODULES ),
			fn( $id ) => $this->is_active( $id )
		) );
	}

	/**
	 * Check if a built-in module's API is available.
	 */
	private function is_builtin_available( string $module ): bool {
		return match ( $module ) {
			'wordpress-ai' => function_exists( 'wp_ai_client_prompt' ),
			default        => false,
		};
	}

	/**
	 * Get a machine-readable module status.
	 */
	private function get_status( string $module, bool $active, bool $installed ): string {
		if ( $active ) {
			return 'active';
		}

		if ( ! empty( self::MODULES[ $module ]['builtin'] ) ) {
			return 'unavailable';
		}

		if ( $installed ) {
			return 'installed';
		}

		return 'missing';
	}

	/**
	 * Get a human-readable module status message.
	 */
	private function get_status_message( string $module, bool $active, bool $installed ): string {
		if ( $active ) {
			return __( 'Active and available.', 'masthead' );
		}

		if ( ! empty( self::MODULES[ $module ]['builtin'] ) ) {
			return __( 'Built-in API not available in this WordPress environment.', 'masthead' );
		}

		if ( $installed ) {
			return __( 'Installed but not active.', 'masthead' );
		}

		return __( 'Not installed.', 'masthead' );
	}
}
