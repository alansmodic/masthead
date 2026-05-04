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
		'redline' => [
			'file'        => 'redline/redline.php',
			'label'       => 'Masthead: Redline',
			'description' => 'AI-powered editorial review with inline Notes on flagged blocks.',
			'repo'        => 'https://github.com/alansmodic/redline',
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
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( self::MODULES[ $module ]['file'] );
	}

	/**
	 * Check if a module is installed (but not necessarily active).
	 */
	public function is_installed( string $module ): bool {
		if ( ! isset( self::MODULES[ $module ] ) ) {
			return false;
		}
		return file_exists( WP_PLUGIN_DIR . '/' . self::MODULES[ $module ]['file'] );
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
}
