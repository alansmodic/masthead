<?php
/**
 * Masthead AI — WP 7.0 AI Client integration layer
 *
 * Wraps all AI interactions through wp_ai_client_prompt() so Masthead
 * never manages LLM connections directly.
 *
 * @package Masthead
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Masthead_AI {

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Check if AI text generation is available on this site.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		$builder = wp_ai_client_prompt( 'test' )
			;

		return $builder->is_supported_for_text_generation();
	}

	/**
	 * Summarize the editorial changes in a revision.
	 *
	 * @param string $changed_fields Comma-separated list of changed field names.
	 * @param string $post_title     The post title for context.
	 * @param string $old_content    Previous content (truncated for token budget).
	 * @param string $new_content    New content (truncated for token budget).
	 * @return string|WP_Error Summary text or error.
	 */
	public function summarize_revision( string $changed_fields, string $post_title, string $old_content = '', string $new_content = '' ): string|WP_Error {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'ai_unavailable', __( 'No AI provider is configured. Visit Settings > Connectors to add one.', 'masthead' ) );
		}

		$system = 'You are an editorial assistant for a WordPress newsroom. '
			. 'Your job is to summarize content revisions in 1-2 concise, factual sentences. '
			. 'Focus on what changed and why it matters editorially. '
			. 'Do not include revision IDs, timestamps, or technical metadata.';

		$prompt = sprintf(
			"Summarize the editorial changes.\nPost title: \"%s\"\nChanged fields: %s",
			$post_title,
			$changed_fields
		);

		if ( $old_content && $new_content ) {
			// Truncate to ~2000 chars each to stay within token budget.
			$old_excerpt = mb_substr( wp_strip_all_tags( $old_content ), 0, 2000 );
			$new_excerpt = mb_substr( wp_strip_all_tags( $new_content ), 0, 2000 );
			$prompt .= sprintf(
				"\n\nPrevious content (excerpt):\n%s\n\nNew content (excerpt):\n%s",
				$old_excerpt,
				$new_excerpt
			);
		}

		$result = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system )
			
			->using_max_tokens( 150 )
			->using_model_preference( 'claude-sonnet-4-6', 'gpt-4o', 'gemini-2.5-flash' )
			->generate_text();

		return $result;
	}

	/**
	 * Review content for editorial quality issues.
	 *
	 * @param string $content  The post content to review.
	 * @param string $title    The post title.
	 * @param array  $checks   Which checks to run (grammar, style, factual, tone).
	 * @return array|WP_Error  Array of issues or error.
	 */
	public function review_content( string $content, string $title, array $checks = array( 'grammar', 'style', 'tone' ) ): array|WP_Error {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'ai_unavailable', __( 'No AI provider is configured. Visit Settings > Connectors to add one.', 'masthead' ) );
		}

		$checks_str = implode( ', ', $checks );

		$system = 'You are a senior copy editor. Review the article for the specified issues. '
			. 'Return a JSON array of objects, each with "type" (grammar|style|factual|tone), '
			. '"severity" (error|warning|suggestion), "excerpt" (the problematic text), '
			. 'and "note" (your editorial comment). If no issues found, return an empty array.';

		$prompt = sprintf(
			"Review this article for: %s\n\nTitle: \"%s\"\n\nContent:\n%s",
			$checks_str,
			$title,
			mb_substr( wp_strip_all_tags( $content ), 0, 4000 )
		);

		$schema = array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'type'     => array( 'type' => 'string', 'enum' => array( 'grammar', 'style', 'factual', 'tone' ) ),
					'severity' => array( 'type' => 'string', 'enum' => array( 'error', 'warning', 'suggestion' ) ),
					'excerpt'  => array( 'type' => 'string' ),
					'note'     => array( 'type' => 'string' ),
				),
				'required' => array( 'type', 'severity', 'excerpt', 'note' ),
			),
		);

		$json = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system )
			
			->using_max_tokens( 1000 )
			->using_model_preference( 'claude-sonnet-4-6', 'gpt-4o', 'gemini-2.5-flash' )
			->as_json_response( $schema )
			->generate_text();

		if ( is_wp_error( $json ) ) {
			return $json;
		}

		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Generate a headline suggestion for content.
	 *
	 * @param string $content The post content.
	 * @param int    $count   Number of suggestions.
	 * @return array|WP_Error Array of headline strings or error.
	 */
	public function suggest_headlines( string $content, int $count = 3 ): array|WP_Error {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'ai_unavailable', __( 'No AI provider is configured. Visit Settings > Connectors to add one.', 'masthead' ) );
		}

		$system = 'You are a headline writer for a digital publication. '
			. 'Generate concise, engaging headlines. Return only a JSON array of strings.';

		$prompt = sprintf(
			"Generate %d headline options for this article:\n\n%s",
			$count,
			mb_substr( wp_strip_all_tags( $content ), 0, 3000 )
		);

		$schema = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);

		$json = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system )
			
			->using_max_tokens( 300 )
			->using_model_preference( 'claude-sonnet-4-6', 'gpt-4o', 'gemini-2.5-flash' )
			->as_json_response( $schema )
			->generate_text();

		if ( is_wp_error( $json ) ) {
			return $json;
		}

		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Generate alt text for an image attachment.
	 *
	 * Uses the WP AI Client's multimodal capabilities to describe the image.
	 * Falls back to context-based generation if image analysis isn't supported.
	 *
	 * @param int    $attachment_id The attachment post ID.
	 * @param string $post_context  Optional surrounding post context for relevance.
	 * @return string|WP_Error Generated alt text or error.
	 */
	public function generate_alt_text( int $attachment_id, string $post_context = '' ): string|WP_Error {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'ai_unavailable', __( 'No AI provider is configured. Visit Settings > Connectors to add one.', 'masthead' ) );
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'not_found', __( 'Attachment not found.', 'masthead' ) );
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error( 'not_image', __( 'Attachment is not an image.', 'masthead' ) );
		}

		$system = 'You are an accessibility specialist writing image alt text for a news website. '
			. 'Write a concise, descriptive alt text (1-2 sentences, max 125 characters preferred). '
			. 'Describe what is visually depicted. Do not start with "Image of" or "Photo of". '
			. 'Be specific and factual. Return only the alt text, no quotes or formatting.';

		// Build context from available metadata.
		$filename  = basename( get_attached_file( $attachment_id ) );
		$caption   = $attachment->post_excerpt;
		$title     = $attachment->post_title;

		$prompt = "Generate alt text for this image.\n";
		$prompt .= sprintf( "Filename: %s\n", $filename );

		if ( $caption ) {
			$prompt .= sprintf( "Caption: %s\n", $caption );
		}
		if ( $title && $title !== $filename ) {
			$prompt .= sprintf( "Title: %s\n", $title );
		}
		if ( $post_context ) {
			$prompt .= sprintf( "Article context: %s\n", mb_substr( $post_context, 0, 500 ) );
		}

		$result = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system )
			
			->using_max_tokens( 80 )
			->using_model_preference( 'claude-sonnet-4-6', 'gpt-4o', 'gemini-2.5-flash' )
			->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Clean up: remove surrounding quotes if present.
		$alt = trim( $result, " \t\n\r\0\x0B\"'" );

		return $alt;
	}

	/**
	 * Find images in a post that are missing alt text.
	 *
	 * @param int $post_id The post ID to scan.
	 * @return array Array of attachment IDs missing alt text.
	 */
	public function find_images_missing_alt( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$missing = array();

		// Check featured image.
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( $thumbnail_id ) {
			$alt = get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true );
			if ( empty( trim( $alt ) ) ) {
				$missing[] = $thumbnail_id;
			}
		}

		// Check inline images in content.
		if ( preg_match_all( '/wp-image-(\d+)/', $post->post_content, $matches ) ) {
			foreach ( array_unique( $matches[1] ) as $att_id ) {
				$att_id = (int) $att_id;
				if ( in_array( $att_id, $missing, true ) ) {
					continue;
				}
				$alt = get_post_meta( $att_id, '_wp_attachment_image_alt', true );
				if ( empty( trim( $alt ) ) ) {
					$missing[] = $att_id;
				}
			}
		}

		return $missing;
	}

	/**
	 * Analyze content tone and readability.
	 *
	 * @param string $content The post content.
	 * @param string $title   The post title.
	 * @return array|WP_Error Analysis result or error.
	 */
	public function analyze_tone( string $content, string $title ): array|WP_Error {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'ai_unavailable', __( 'No AI provider is configured. Visit Settings > Connectors to add one.', 'masthead' ) );
		}

		$system = 'You are an editorial analyst. Analyze the article\'s tone, readability, and audience fit. '
			. 'Return a JSON object with these fields: '
			. '"tone" (string: formal|informal|conversational|academic|journalistic|persuasive), '
			. '"reading_level" (string: elementary|middle_school|high_school|college|graduate), '
			. '"grade_level" (number: Flesch-Kincaid grade estimate, 1-18), '
			. '"audience" (string: brief description of target audience), '
			. '"clarity_score" (number: 1-10 where 10 is crystal clear), '
			. '"engagement_score" (number: 1-10 where 10 is highly engaging), '
			. '"suggestions" (array of strings: 2-4 actionable improvement suggestions). '
			. 'Be precise and constructive.';

		$stripped = mb_substr( wp_strip_all_tags( $content ), 0, 4000 );

		// Calculate basic stats locally (no AI needed).
		$word_count     = str_word_count( $stripped );
		$sentence_count = max( 1, preg_match_all( '/[.!?]+/', $stripped ) );
		$paragraph_count = max( 1, substr_count( $content, '</p>' ) );

		$prompt = sprintf(
			"Analyze this article's tone and readability.\n\nTitle: \"%s\"\nWord count: %d\nSentences: %d\nParagraphs: %d\n\nContent:\n%s",
			$title,
			$word_count,
			$sentence_count,
			$paragraph_count,
			$stripped
		);

		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'tone'             => array( 'type' => 'string' ),
				'reading_level'    => array( 'type' => 'string' ),
				'grade_level'      => array( 'type' => 'number' ),
				'audience'         => array( 'type' => 'string' ),
				'clarity_score'    => array( 'type' => 'number' ),
				'engagement_score' => array( 'type' => 'number' ),
				'suggestions'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required' => array( 'tone', 'reading_level', 'grade_level', 'audience', 'clarity_score', 'engagement_score', 'suggestions' ),
		);

		$json = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system )
			
			->using_max_tokens( 500 )
			->using_model_preference( 'claude-sonnet-4-6', 'gpt-4o', 'gemini-2.5-flash' )
			->as_json_response( $schema )
			->generate_text();

		if ( is_wp_error( $json ) ) {
			return $json;
		}

		$analysis = json_decode( $json, true );
		if ( ! is_array( $analysis ) ) {
			return new WP_Error( 'parse_error', __( 'Failed to parse tone analysis response.', 'masthead' ) );
		}

		// Merge local stats into the result.
		$analysis['word_count']      = $word_count;
		$analysis['sentence_count']  = $sentence_count;
		$analysis['paragraph_count'] = $paragraph_count;
		$analysis['avg_words_per_sentence'] = round( $word_count / $sentence_count, 1 );

		return $analysis;
	}

	/**
	 * Get the AI status for display in admin UI.
	 *
	 * @return array { available: bool, provider: string|null, message: string }
	 */
	public function get_status(): array {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return array(
				'available' => false,
				'provider'  => null,
				'message'   => __( 'WordPress 7.0+ required for AI features.', 'masthead' ),
			);
		}

		if ( ! $this->is_available() ) {
			return array(
				'available' => false,
				'provider'  => null,
				'message'   => __( 'No AI provider configured. Visit Settings → Connectors to add one.', 'masthead' ),
			);
		}

		// Do a quick probe to see which provider would handle requests.
		$result = wp_ai_client_prompt( 'Hello' )
			
			->using_max_tokens( 1 )
			->generate_text_result();

		$provider = null;
		if ( ! is_wp_error( $result ) && method_exists( $result, 'getProviderMetadata' ) ) {
			$meta = $result->getProviderMetadata();
			$provider = $meta['name'] ?? null;
		}

		return array(
			'available' => true,
			'provider'  => $provider,
			'message'   => $provider
				? sprintf( __( 'Connected via %s', 'masthead' ), $provider )
				: __( 'AI features available', 'masthead' ),
		);
	}
}