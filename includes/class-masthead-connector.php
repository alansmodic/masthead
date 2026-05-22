<?php
/**
 * Masthead Connector Registration
 *
 * Registers Masthead as a connector in the WP 7.0 Settings > Connectors screen.
 * This is NOT an AI provider — it's an editorial AI consumer that surfaces its
 * status and capabilities alongside the site's configured providers.
 *
 * @package Masthead
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Masthead_Connector {

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_connectors_init', array( $this, 'register_connector' ) );
	}

	/**
	 * Register the Masthead editorial connector.
	 *
	 * @param WP_Connector_Registry $registry The connector registry.
	 */
	public function register_connector( $registry ): void {
		$registry->register( 'masthead-editorial', array(
			'name'           => __( 'Masthead Editorial AI', 'masthead' ),
			'description'    => __( 'AI-powered editorial review, revision summaries, and headline suggestions for your newsroom workflow.', 'masthead' ),
			'logo_url'       => MASTHEAD_ASSETS_URL . 'images/masthead-connector-logo.svg',
			'type'           => 'editorial_ai',
			'authentication' => array(
				'method' => 'none',
			),
			'plugin'         => array(
				'file' => 'masthead/masthead.php',
			),
		) );
	}
}