<?php
/**
 * Settings for Masthead
 *
 * Owns Masthead's native feature settings and cross-plugin integration toggles.
 *
 * @package Masthead
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Masthead_Settings {

	const OPTION_FEATURES      = 'masthead_features';
	const OPTION_CHECKLIST     = 'masthead_checklist_items';
	const OPTION_GENERAL       = 'masthead_general';
	const OPTION_INTEGRATIONS  = 'masthead_integrations';

	/**
	 * Masthead's own features (not owned by a suite plugin).
	 */
	private array $features = [
		'staged_revisions' => [
			'label'       => 'Staged Revisions',
			'description' => 'Save changes to published posts as staged drafts for review.',
			'default'     => true,
			'requires'    => [],
		],
		'publication_checklist' => [
			'label'       => 'Publication Checklist',
			'description' => 'Require a configurable checklist before publishing.',
			'default'     => true,
			'requires'    => [],
		],
		'scheduled_publishing' => [
			'label'       => 'Scheduled Publishing',
			'description' => 'Schedule staged revisions to go live at a specific date and time.',
			'default'     => true,
			'requires'    => [ 'staged_revisions' ],
		],
		'revision_timeline' => [
			'label'       => 'Revision Timeline',
			'description' => 'Review revision history and compare editorial changes.',
			'default'     => true,
			'requires'    => [],
		],
	];

	/**
	 * Cross-plugin integration settings.
	 * These only take effect when the relevant suite plugins are both active.
	 */
	private array $integrations = [

		'require_ai_review_before_publish' => [
			'label'       => 'Require AI review before publishing',
			'description' => 'Block publishing if an AI editorial review hasn\'t been run or has unresolved issues.',
			'default'     => false,
			'requires'    => [ 'wordpress-ai' ],
		],
		'ai_review_in_checklist' => [
			'label'       => 'Show AI review status in publication checklist',
			'description' => 'Add an AI review item to the publication checklist.',
			'default'     => true,
			'requires'    => [ 'wordpress-ai' ],
		],
		'auto_summarize_on_submission' => [
			'label'       => 'Auto-summarize revisions on submission',
			'description' => 'When a staged revision is submitted, generate an AI summary for reviewers.',
			'default'     => true,
			'requires'    => [ 'wordpress-ai', 'rewrites' ],
		],
	];

	private static ?self $instance = null;
	private ?array $feature_cache = null;
	private ?array $integration_cache = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_ajax_masthead_save_settings', [ $this, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_masthead_reset_features', [ $this, 'ajax_reset_features' ] );
	}

	public function register_settings(): void {
		register_setting( 'masthead_settings', self::OPTION_FEATURES );
		register_setting( 'masthead_settings', self::OPTION_CHECKLIST );
		register_setting( 'masthead_settings', self::OPTION_GENERAL );
		register_setting( 'masthead_settings', self::OPTION_INTEGRATIONS );
	}

	/**
	 * Set defaults on activation.
	 */
	public static function set_defaults(): void {
		$instance = self::get_instance();

		// Features.
		$existing = get_option( self::OPTION_FEATURES, [] );
		$defaults = [];
		foreach ( $instance->features as $key => $feature ) {
			if ( ! isset( $existing[ $key ] ) ) {
				$defaults[ $key ] = $feature['default'];
			}
		}
		if ( ! empty( $defaults ) ) {
			update_option( self::OPTION_FEATURES, array_merge( $existing, $defaults ) );
		}

		// Integrations.
		$existing_int = get_option( self::OPTION_INTEGRATIONS, [] );
		$default_int  = [];
		foreach ( $instance->integrations as $key => $setting ) {
			if ( ! isset( $existing_int[ $key ] ) ) {
				$default_int[ $key ] = $setting['default'];
			}
		}
		if ( ! empty( $default_int ) ) {
			update_option( self::OPTION_INTEGRATIONS, array_merge( $existing_int, $default_int ) );
		}

		// Default checklist items.
		if ( ! get_option( self::OPTION_CHECKLIST ) ) {
			update_option( self::OPTION_CHECKLIST, [
				[ 'label' => 'All changes have been reviewed', 'required' => true ],
				[ 'label' => 'Content has been proofread', 'required' => true ],
				[ 'label' => 'Links verified', 'required' => false ],
				[ 'label' => 'SEO metadata is complete', 'required' => false ],
			] );
		}

		// Default general options.
		if ( ! get_option( self::OPTION_GENERAL ) ) {
			update_option( self::OPTION_GENERAL, [
				'cleanup_old_revisions' => false,
				'cleanup_days'          => 30,
			] );
		}
	}

	/**
	 * Backward-compatible alias used by older tests and setup scripts.
	 */
	public static function set_default_features(): void {
		self::set_defaults();
	}

	// -------------------------------------------------------------------------
	// Features
	// -------------------------------------------------------------------------

	public function is_feature_enabled( string $key ): bool {
		$features = $this->get_enabled_features();
		return ! empty( $features[ $key ] );
	}

	public function get_enabled_features(): array {
		if ( null === $this->feature_cache ) {
			$stored = get_option( self::OPTION_FEATURES, [] );
			$this->feature_cache = [];
			foreach ( $this->features as $key => $feature ) {
				$this->feature_cache[ $key ] = $stored[ $key ] ?? $feature['default'];
			}
		}
		return $this->feature_cache;
	}

	public function get_available_features(): array {
		return $this->features;
	}

	public function check_feature_dependencies( string $key ): bool {
		if ( ! isset( $this->features[ $key ] ) ) {
			return false;
		}

		foreach ( $this->features[ $key ]['requires'] as $dependency ) {
			if ( ! $this->is_feature_enabled( $dependency ) ) {
				return false;
			}
		}

		return true;
	}

	public function get_dependent_features( string $key ): array {
		$dependents = [];

		foreach ( $this->features as $feature_key => $feature ) {
			if ( in_array( $key, $feature['requires'], true ) ) {
				$dependents[] = $feature_key;
			}
		}

		return $dependents;
	}

	public function enable_feature( string $key ): bool {
		return $this->set_feature_enabled( $key, true );
	}

	public function disable_feature( string $key ): bool {
		return $this->set_feature_enabled( $key, false );
	}

	private function set_feature_enabled( string $key, bool $enabled ): bool {
		if ( ! isset( $this->features[ $key ] ) ) {
			return false;
		}

		$features         = $this->get_enabled_features();
		$features[ $key ] = $enabled;
		$this->feature_cache = null;

		return update_option( self::OPTION_FEATURES, $features );
	}

	// -------------------------------------------------------------------------
	// Integrations
	// -------------------------------------------------------------------------

	public function get( string $key, mixed $default = null ): mixed {
		$integrations = $this->get_integrations();
		return $integrations[ $key ] ?? $default;
	}

	public function is_integration_enabled( string $key ): bool {
		$integrations = $this->get_integrations();

		return ! empty( $integrations[ $key ] ) && $this->check_integration_dependencies( $key );
	}

	public function get_integrations(): array {
		if ( null === $this->integration_cache ) {
			$stored = get_option( self::OPTION_INTEGRATIONS, [] );
			$this->integration_cache = [];
			foreach ( $this->integrations as $key => $setting ) {
				$this->integration_cache[ $key ] = $stored[ $key ] ?? $setting['default'];
			}
		}
		return $this->integration_cache;
	}

	public function get_available_integrations(): array {
		return $this->integrations;
	}

	public function check_integration_dependencies( string $key ): bool {
		if ( ! isset( $this->integrations[ $key ] ) ) {
			return false;
		}

		return Masthead_Module_Registry::get_instance()->requirements_met( $this->integrations[ $key ]['requires'] );
	}

	public function get_missing_integration_dependencies( string $key ): array {
		if ( ! isset( $this->integrations[ $key ] ) ) {
			return [];
		}

		return Masthead_Module_Registry::get_instance()->missing_requirements( $this->integrations[ $key ]['requires'] );
	}

	// -------------------------------------------------------------------------
	// Checklist
	// -------------------------------------------------------------------------

	public function get_checklist_items(): array {
		return get_option( self::OPTION_CHECKLIST, [] );
	}

	public function update_checklist_items( array $items ): bool {
		$sanitized = [];
		foreach ( $items as $item ) {
			if ( ! empty( $item['label'] ) ) {
				$sanitized[] = [
					'label'    => sanitize_text_field( $item['label'] ),
					'required' => ! empty( $item['required'] ),
				];
			}
		}
		return update_option( self::OPTION_CHECKLIST, $sanitized );
	}

	// -------------------------------------------------------------------------
	// General options
	// -------------------------------------------------------------------------

	public function get_option( string $key, mixed $default = null ): mixed {
		$options = get_option( self::OPTION_GENERAL, [] );
		return $options[ $key ] ?? $default;
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	public function ajax_save_settings(): void {
		check_ajax_referer( 'masthead_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		$section = sanitize_key( $_POST['section'] ?? '' );

		match ( $section ) {
			'features'     => $this->save_features(),
			'integrations' => $this->save_integrations(),
			'checklist'    => $this->save_checklist(),
			'general'      => $this->save_general(),
			default        => wp_send_json_error( [ 'message' => 'Invalid section.' ] ),
		};
	}

	public function ajax_reset_features(): void {
		check_ajax_referer( 'masthead_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		$defaults = [];
		foreach ( $this->features as $key => $feature ) {
			$defaults[ $key ] = $feature['default'];
		}

		update_option( self::OPTION_FEATURES, $defaults );
		$this->feature_cache = null;

		wp_send_json_success( [ 'message' => 'Features reset.', 'features' => $defaults ] );
	}

	private function save_features(): void {
		$input    = $_POST['features'] ?? [];
		$sanitized = [];
		foreach ( $this->features as $key => $feature ) {
			$sanitized[ $key ] = ! empty( $input[ $key ] );
		}
		update_option( self::OPTION_FEATURES, $sanitized );
		$this->feature_cache = null;
		wp_send_json_success( [ 'message' => 'Features saved.', 'features' => $sanitized ] );
	}

	private function save_integrations(): void {
		$input    = $_POST['integrations'] ?? [];
		$sanitized = [];
		foreach ( $this->integrations as $key => $setting ) {
			$sanitized[ $key ] = ! empty( $input[ $key ] ) && $this->check_integration_dependencies( $key );
		}
		update_option( self::OPTION_INTEGRATIONS, $sanitized );
		$this->integration_cache = null;
		wp_send_json_success( [ 'message' => 'Integration settings saved.' ] );
	}

	private function save_checklist(): void {
		$this->update_checklist_items( $_POST['items'] ?? [] );
		wp_send_json_success( [ 'message' => 'Checklist saved.' ] );
	}

	private function save_general(): void {
		$input = $_POST['options'] ?? [];
		$sanitized = [
			'cleanup_old_revisions' => ! empty( $input['cleanup_old_revisions'] ),
			'cleanup_days'          => absint( $input['cleanup_days'] ?? 30 ),
		];
		update_option( self::OPTION_GENERAL, $sanitized );
		wp_send_json_success( [ 'message' => 'General settings saved.' ] );
	}
}
