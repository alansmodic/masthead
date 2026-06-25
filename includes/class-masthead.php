<?php
/**
 * Masthead core class
 *
 * Handles meta registration, asset enqueuing, and REST wiring.
 * Revision history, diffs, and media tracking are handled by Edit Ledger.
 *
 * @package Masthead
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Masthead {

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'register_meta' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	/**
	 * Register post meta for Masthead features.
	 */
	public function register_meta(): void {
		// Meta on revision posts (staged revisions).
		$revision_meta = [
			'_masthead_staged_revision'     => [ 'type' => 'boolean', 'default' => false ],
			'_masthead_staged_status'        => [ 'type' => 'string',  'default' => 'pending' ],
			'_masthead_staged_publish_date'  => [ 'type' => 'string',  'default' => '' ],
			'_masthead_staged_author'        => [ 'type' => 'integer', 'default' => 0 ],
			'_masthead_staged_notes'         => [ 'type' => 'string',  'default' => '' ],
			'_masthead_revision_summary'     => [ 'type' => 'string',  'default' => '' ], // Set by Edit Ledger integration.
		];

		foreach ( $revision_meta as $key => $args ) {
			register_post_meta( '', $key, [
				'type'          => $args['type'],
				'single'        => true,
				'default'       => $args['default'],
				'show_in_rest'  => true,
				'auth_callback' => fn( $allowed, $meta_key, $object_id ) => current_user_can( 'edit_post', $object_id ),
			] );
		}

		// Meta on parent posts.
		foreach ( get_post_types( [ 'public' => true ], 'names' ) as $post_type ) {
			register_post_meta( $post_type, '_masthead_has_staged_revision', [
				'type'          => 'integer',
				'single'        => true,
				'default'       => 0,
				'show_in_rest'  => true,
				'auth_callback' => fn( $allowed, $meta_key, $object_id ) => current_user_can( 'edit_post', $object_id ),
			] );
		}
	}

	/**
	 * Register REST routes.
	 */
	public function register_rest_routes(): void {
		( new Masthead_REST_Controller() )->register_routes();
	}

	/**
	 * Enqueue block editor assets.
	 */
	public function enqueue_editor_assets(): void {
		global $post;

		if ( ! $post || ! post_type_supports( $post->post_type, 'revisions' ) ) {
			return;
		}

		$allowed_statuses = [ 'publish', 'draft', 'auto-draft', 'pending', 'future' ];
		if ( ! in_array( $post->post_status, $allowed_statuses, true ) ) {
			return;
		}

		$editor_deps = [
			'wp-plugins',
			'wp-edit-post',
			'wp-editor',
			'wp-element',
			'wp-components',
			'wp-data',
			'wp-api-fetch',
			'wp-i18n',
		];

		// WP 7.0+: enqueue abilities API for auto-discovery of server-registered abilities.
		if ( wp_script_is( 'wp-core-abilities', 'registered' ) ) {
			$editor_deps[] = 'wp-core-abilities';
		}

		wp_enqueue_script(
			'masthead-editor',
			MASTHEAD_ASSETS_URL . 'js/editor.js',
			$editor_deps,
			MASTHEAD_VERSION,
			true
		);

		wp_enqueue_style( 'masthead-editor', MASTHEAD_ASSETS_URL . 'css/editor.css', [], MASTHEAD_VERSION );

		$settings = Masthead_Settings::get_instance();
		$checklist_config = array( 'enabled' => false );
		if ( $settings->is_feature_enabled( 'publication_checklist' ) && class_exists( 'Masthead_Publication_Checklist' ) ) {
			$checklist_config = Masthead_Publication_Checklist::get_instance()->get_frontend_config( $post->ID );
		}

		wp_localize_script( 'masthead-editor', 'mastheadData', [
			'postId'           => $post->ID,
			'stagedRevisionId' => (int) get_post_meta( $post->ID, '_masthead_has_staged_revision', true ),
			'restNonce'        => wp_create_nonce( 'wp_rest' ),
			'siteUrl'          => get_site_url(),
			'features'         => $settings->get_enabled_features(),
			'modules'          => Masthead_Module_Registry::get_instance()->get_all(),
			'config'           => [
				'checklist' => $checklist_config,
			],
			'strings'          => $this->editor_strings(),
		] );
	}

	/**
	 * Enqueue admin assets.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( strpos( $hook, 'masthead' ) === false ) {
			return;
		}

		wp_enqueue_style( 'masthead-admin', MASTHEAD_ASSETS_URL . 'css/admin.css', [], MASTHEAD_VERSION );
		wp_enqueue_script( 'masthead-admin', MASTHEAD_ASSETS_URL . 'js/admin.js', [ 'jquery', 'wp-api-fetch', 'jquery-ui-sortable' ], MASTHEAD_VERSION, true );

		wp_localize_script( 'masthead-admin', 'mastheadAdmin', [
			'nonce'   => wp_create_nonce( 'masthead_admin' ),
			'strings' => $this->admin_strings(),
		] );
	}

	private function editor_strings(): array {
		return [
			'pluginTitle'       => __( 'Masthead', 'masthead' ),
			'saveAsRewrite'     => __( 'Save as Rewrite', 'masthead' ),
			'publishNow'        => __( 'Publish Now', 'masthead' ),
			'schedulePublish'   => __( 'Schedule Publishing', 'masthead' ),
			'checklistTitle'    => __( 'Before You Publish', 'masthead' ),
			'confirmAndPublish' => __( 'Confirm & Publish', 'masthead' ),
			'requiredItems'     => __( 'Please check all required items before publishing.', 'masthead' ),
			'loading'           => __( 'Loading…', 'masthead' ),
			'error'             => __( 'An error occurred.', 'masthead' ),
		];
	}

	private function admin_strings(): array {
		return [
			'saved'          => __( 'Saved successfully', 'masthead' ),
			'saving'         => __( 'Saving…', 'masthead' ),
			'error'          => __( 'An error occurred', 'masthead' ),
			'confirmDiscard' => __( 'Discard this revision?', 'masthead' ),
			'confirmPublish' => __( 'Publish this revision now?', 'masthead' ),
		];
	}

	/**
	 * Get supported post types.
	 */
	public static function supported_post_types(): array {
		return apply_filters(
			'masthead_supported_post_types',
			array_filter(
				get_post_types( [ 'public' => true ], 'names' ),
				fn( $pt ) => post_type_supports( $pt, 'revisions' )
			)
		);
	}

	/**
	 * Check whether a post type supports Masthead editorial features.
	 */
	public static function post_type_supports_editorial( string $post_type ): bool {
		return in_array( $post_type, self::supported_post_types(), true );
	}
}
