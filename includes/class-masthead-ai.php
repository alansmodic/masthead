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
			->using_temperature( 0.3 );

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
			->using_temperature( 0.3 )
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
			->using_temperature( 0.2 )
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
			->using_temperature( 0.8 )
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
			->using_temperature( 0 )
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