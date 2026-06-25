<?php
/**
 * Smart publication checklist items for Masthead.
 *
 * @package Masthead
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Masthead_Smart_Checklist
 *
 * Adds contextual, content-aware checks to the publication checklist.
 */
class Masthead_Smart_Checklist {

	/**
	 * Singleton instance.
	 *
	 * @var Masthead_Smart_Checklist|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Masthead_Smart_Checklist
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_filter( 'masthead_publication_checklist_items', array( $this, 'add_smart_items' ), 20, 2 );
	}

	/**
	 * Add smart checklist items for a post.
	 *
	 * @param array $items   Existing checklist items.
	 * @param int   $post_id Post ID.
	 * @return array
	 */
	public function add_smart_items( array $items, int $post_id = 0 ): array {
		if ( ! $post_id ) {
			return $items;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! Masthead::post_type_supports_editorial( $post->post_type ) ) {
			return $items;
		}

		return array_merge( $items, $this->evaluate_post( $post ) );
	}

	/**
	 * Evaluate smart checklist checks for a post.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return array
	 */
	public function evaluate_post( $post ): array {
		$post = get_post( $post );
		if ( ! $post ) {
			return array();
		}

		return array_values( array_filter( array(
			$this->check_title( $post ),
			$this->check_excerpt( $post ),
			$this->check_featured_image( $post ),
			$this->check_image_alt_text( $post ),
			$this->check_internal_links( $post ),
			$this->check_schedule( $post ),
		) ) );
	}

	/**
	 * Build a normalized smart item.
	 *
	 * @param string $id           Item ID.
	 * @param string $label        Item label.
	 * @param string $status       pass|warning|fail|unavailable|info.
	 * @param string $message      Human-readable message.
	 * @param bool   $required     Whether this blocks publishing.
	 * @param array  $action       Optional quick action descriptor.
	 * @return array
	 */
	private function item( string $id, string $label, string $status, string $message, bool $required = false, array $action = array() ): array {
		$item = array(
			'id'           => $id,
			'label'        => $label,
			'required'     => $required,
			'status'       => $status,
			'message'      => $message,
			'source'       => 'smart',
			'auto_checked' => 'pass' === $status,
		);

		if ( ! empty( $action ) ) {
			$item['action'] = $action;
		}

		return $item;
	}

	/**
	 * Check headline presence and length.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function check_title( WP_Post $post ): array {
		$title  = trim( wp_strip_all_tags( get_the_title( $post ) ) );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );

		if ( '' === $title ) {
			return $this->item(
				'masthead_title_present',
				__( 'Headline is present', 'masthead' ),
				'fail',
				__( 'Add a headline before publishing.', 'masthead' ),
				true
			);
		}

		if ( $length > 70 ) {
			return $this->item(
				'masthead_title_length',
				__( 'Headline length is in range', 'masthead' ),
				'warning',
				sprintf(
					/* translators: %d: headline character count */
					__( 'Headline is %d characters; consider tightening it below 70.', 'masthead' ),
					$length
				)
			);
		}

		return $this->item(
			'masthead_title_length',
			__( 'Headline length is in range', 'masthead' ),
			'pass',
			__( 'Headline length looks good.', 'masthead' )
		);
	}

	/**
	 * Check excerpt.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function check_excerpt( WP_Post $post ): array {
		if ( '' === trim( wp_strip_all_tags( $post->post_excerpt ) ) ) {
			return $this->item(
				'masthead_excerpt_present',
				__( 'Excerpt is present', 'masthead' ),
				'warning',
				__( 'Add an excerpt for previews, cards, and feeds.', 'masthead' )
			);
		}

		return $this->item(
			'masthead_excerpt_present',
			__( 'Excerpt is present', 'masthead' ),
			'pass',
			__( 'Excerpt is ready.', 'masthead' )
		);
	}

	/**
	 * Check featured image.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function check_featured_image( WP_Post $post ): array {
		if ( ! has_post_thumbnail( $post ) ) {
			return $this->item(
				'masthead_featured_image',
				__( 'Featured image is set', 'masthead' ),
				'warning',
				__( 'Set a featured image before publishing.', 'masthead' )
			);
		}

		return $this->item(
			'masthead_featured_image',
			__( 'Featured image is set', 'masthead' ),
			'pass',
			__( 'Featured image is ready.', 'masthead' )
		);
	}

	/**
	 * Check image alt text.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function check_image_alt_text( WP_Post $post ): array {
		$missing = Masthead_AI::get_instance()->find_images_missing_alt( (int) $post->ID );
		$count   = count( $missing );

		if ( $count > 0 ) {
			return $this->item(
				'masthead_image_alt_text',
				__( 'Images have alt text', 'masthead' ),
				'fail',
				sprintf(
					/* translators: %d: number of images missing alt text */
					_n( '%d image is missing alt text.', '%d images are missing alt text.', $count, 'masthead' ),
					$count
				),
				true,
				array(
					'type'  => 'ability',
					'name'  => 'masthead/generate-alt-text',
					'label' => __( 'Generate alt text', 'masthead' ),
				)
			);
		}

		return $this->item(
			'masthead_image_alt_text',
			__( 'Images have alt text', 'masthead' ),
			'pass',
			__( 'No missing image alt text found.', 'masthead' )
		);
	}

	/**
	 * Check internal links.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function check_internal_links( WP_Post $post ): array {
		$content = (string) $post->post_content;

		if ( ! preg_match_all( '/<a\s[^>]*href=[\'"]([^\'"]+)[\'"]/i', $content, $matches ) ) {
			return $this->item(
				'masthead_internal_links',
				__( 'Internal links are present', 'masthead' ),
				'warning',
				__( 'Add at least one internal link if the article should connect to related coverage.', 'masthead' )
			);
		}

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( $matches[1] as $href ) {
			$host = wp_parse_url( $href, PHP_URL_HOST );
			if ( empty( $host ) || ( $home_host && strtolower( $host ) === strtolower( $home_host ) ) ) {
				return $this->item(
					'masthead_internal_links',
					__( 'Internal links are present', 'masthead' ),
					'pass',
					__( 'Internal linking is present.', 'masthead' )
				);
			}
		}

		return $this->item(
			'masthead_internal_links',
			__( 'Internal links are present', 'masthead' ),
			'warning',
			__( 'Only external links found; consider adding an internal link.', 'masthead' )
		);
	}

	/**
	 * Check scheduled publish date sanity.
	 *
	 * @param WP_Post $post Post object.
	 * @return array|null
	 */
	private function check_schedule( WP_Post $post ) {
		if ( 'future' !== $post->post_status ) {
			return null;
		}

		$timestamp = strtotime( $post->post_date_gmt . ' GMT' );
		if ( $timestamp && $timestamp <= time() ) {
			return $this->item(
				'masthead_schedule_future',
				__( 'Scheduled date is in the future', 'masthead' ),
				'fail',
				__( 'Scheduled posts need a future publish date.', 'masthead' ),
				true
			);
		}

		return $this->item(
			'masthead_schedule_future',
			__( 'Scheduled date is in the future', 'masthead' ),
			'pass',
			__( 'Scheduled publish date is valid.', 'masthead' )
		);
	}
}
