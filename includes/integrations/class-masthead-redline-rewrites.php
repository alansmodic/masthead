<?php
/**
 * Integration: Redline + Rewrites
 *
 * Optionally require a Redline content check to pass before
 * a staged revision can be published.
 *
 * @package Masthead
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Masthead_Redline_Rewrites {

	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function init(): void {
		// Gate publication on Redline check if setting is enabled.
		add_filter( 'rewrites_can_publish', [ $this, 'gate_on_redline' ], 10, 2 );

		// Surface Redline status in the Rewrites publication checklist.
		add_filter( 'rewrites_checklist_items', [ $this, 'add_redline_checklist_item' ], 10, 2 );
	}

	/**
	 * Block publishing if Redline check has open errors and setting requires it.
	 */
	public function gate_on_redline( bool $can_publish, int $post_id ): bool {
		$settings = Masthead_Settings::get_instance();
		if ( ! $settings->get( 'require_redline_before_publish' ) ) {
			return $can_publish;
		}

		$last_check  = get_post_meta( $post_id, '_redline_last_check', true );
		$open_errors = get_post_meta( $post_id, '_redline_open_errors', true );

		if ( ! $last_check || (int) $open_errors > 0 ) {
			return false;
		}

		return $can_publish;
	}

	/**
	 * Add Redline status as a checklist item in the Rewrites panel.
	 */
	public function add_redline_checklist_item( array $items, int $post_id ): array {
		$last_check  = get_post_meta( $post_id, '_redline_last_check', true );
		$open_errors = (int) get_post_meta( $post_id, '_redline_open_errors', true );

		if ( ! $last_check ) {
			$status  = 'pending';
			$label   = __( 'Redline check not run', 'masthead' );
		} elseif ( $open_errors > 0 ) {
			$status  = 'error';
			$label   = sprintf( _n( 'Redline: %d open issue', 'Redline: %d open issues', $open_errors, 'masthead' ), $open_errors );
		} else {
			$status  = 'pass';
			$label   = __( 'Redline: No issues found', 'masthead' );
		}

		$items[] = [
			'id'       => 'masthead_redline',
			'label'    => $label,
			'status'   => $status,
			'required' => Masthead_Settings::get_instance()->get( 'require_redline_before_publish' ),
		];

		return $items;
	}
}
