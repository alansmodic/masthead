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
	 * Get all modules with their current status.
	 */
	public function get_all(): array {
		$result = [];
		foreach ( self::MODULES as $id => $meta ) {
			$result[ $id ] = array_merge( $meta, [
				'active'    => $this->is_active( $id ),
				'installed' => $this->is_installed( $id ),
			] );
		}
		return $result;
	}

	/**
	 * Get only active module IDs.
	 */
	public function active_modules(): array {
		return array_keys( array_filter(
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
}
