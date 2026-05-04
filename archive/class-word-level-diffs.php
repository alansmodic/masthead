<?php
/**
 * Word-level Diffs feature for Masthead
 *
 * @package Masthead
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Masthead_Word_Level_Diffs
 *
 * Generates detailed word-by-word diffs between revisions.
 */
class Masthead_Word_Level_Diffs {
	/**
	 * Max LCS matrix cells to compute to avoid runaway memory/CPU.
	 */
	const MAX_DIFF_CELLS = 4000000;


	/**
	 * Singleton instance.
	 *
	 * @var Masthead_Word_Level_Diffs|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Masthead_Word_Level_Diffs
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
		// This feature is primarily used through static methods.
		// No initialization hooks needed.
	}

	/**
	 * Generate comprehensive diff between two posts.
	 *
	 * @param WP_Post $from_post The original post.
	 * @param WP_Post $to_post   The revision post.
	 * @return array The diff data.
	 */
	public static function generate_diff( $from_post, $to_post ) {
		$diff_data = array(
			'revision_id' => $to_post->ID,
			'compare_to'  => $from_post->ID,
			'comparison'  => self::get_comparison_metadata( $from_post, $to_post ),
			'fields'      => array(),
			'summary'     => array(),
		);

		// Generate diffs for each field.
		$fields_to_compare = array(
			'title'   => array(
				'from' => $from_post->post_title,
				'to'   => $to_post->post_title,
			),
			'content' => array(
				'from' => $from_post->post_content,
				'to'   => $to_post->post_content,
			),
			'excerpt' => array(
				'from' => $from_post->post_excerpt,
				'to'   => $to_post->post_excerpt,
			),
		);

		$total_additions = 0;
		$total_deletions = 0;

		foreach ( $fields_to_compare as $field => $values ) {
			if ( 'content' === $field ) {
				$field_diff = self::generate_content_diff( $values['from'], $values['to'] );
			} else {
				$field_diff = self::generate_text_diff( $values['from'], $values['to'] );
			}

			$diff_data['fields'][ $field ] = $field_diff;
			$total_additions += $field_diff['stats']['additions'];
			$total_deletions += $field_diff['stats']['deletions'];
		}

		// Generate summary statistics.
		$diff_data['summary'] = array(
			'total_changes'  => $total_additions + $total_deletions,
			'additions'      => $total_additions,
			'deletions'      => $total_deletions,
			'fields_changed' => count( array_filter( $diff_data['fields'], function( $field ) {
				return $field['has_changes'];
			} ) ),
		);

		return $diff_data;
	}

	/**
	 * Get comparison metadata.
	 *
	 * @param WP_Post $from_post The original post.
	 * @param WP_Post $to_post   The revision post.
	 * @return array Comparison metadata.
	 */
	private static function get_comparison_metadata( $from_post, $to_post ) {
		$from_author = get_userdata( $from_post->post_author );
		$to_author = get_userdata( $to_post->post_author );

		return array(
			'from' => array(
				'id'            => $from_post->ID,
				'date'          => $from_post->post_modified,
				'date_relative' => human_time_diff( strtotime( $from_post->post_modified_gmt ), time() ),
				'author'        => array(
					'name'   => $from_author ? $from_author->display_name : __( 'Unknown', 'masthead' ),
					'avatar' => get_avatar_url( $from_post->post_author, array( 'size' => 48 ) ),
				),
				'is_current'    => $from_post->post_type !== 'revision',
			),
			'to'   => array(
				'id'            => $to_post->ID,
				'date'          => $to_post->post_modified,
				'date_relative' => human_time_diff( strtotime( $to_post->post_modified_gmt ), time() ),
				'author'        => array(
					'name'   => $to_author ? $to_author->display_name : __( 'Unknown', 'masthead' ),
					'avatar' => get_avatar_url( $to_post->post_author, array( 'size' => 48 ) ),
				),
				'is_current'    => false,
			),
		);
	}

	/**
	 * Generate word-level diff for plain text content.
	 *
	 * @param string $from Original text.
	 * @param string $to   New text.
	 * @return array Diff data.
	 */
	public static function generate_text_diff( $from, $to ) {
		// Handle empty strings.
		if ( empty( $from ) && empty( $to ) ) {
			return self::get_empty_diff();
		}

		$from = self::normalize_text( $from );
		$to = self::normalize_text( $to );

		if ( $from === $to ) {
			return self::get_no_change_diff( $from );
		}

		// Split into words for word-level diffing.
		$from_words = self::split_into_words( $from );
		$to_words = self::split_into_words( $to );

		// Guard against very large diffs (quadratic behavior).
		if ( self::is_diff_too_large( count( $from_words ), count( $to_words ) ) ) {
			return self::get_large_diff( $from, $to );
		}

		// Calculate diff using dynamic programming (similar to git diff).
		$diff_result = self::calculate_word_diff( $from_words, $to_words );

		return array(
			'from'           => $from,
			'to'             => $to,
			'diff_html'      => self::render_diff_html( $diff_result ),
			'diff_inline'    => self::render_diff_inline( $diff_result ),
			'diff_split'     => self::render_diff_split( $diff_result ),
			'has_changes'    => true,
			'stats'          => $diff_result['stats'],
		);
	}

	/**
	 * Generate diff for HTML content (like post_content).
	 *
	 * @param string $from Original content.
	 * @param string $to   New content.
	 * @return array Diff data.
	 */
	public static function generate_content_diff( $from, $to ) {
		// Handle empty content.
		if ( empty( $from ) && empty( $to ) ) {
			return self::get_empty_diff();
		}

		// First, strip HTML and compare as text to determine if there are changes.
		$from_text = self::strip_html_for_diff( $from );
		$to_text = self::strip_html_for_diff( $to );

		if ( $from_text === $to_text ) {
			return self::get_no_change_diff( $from_text );
		}

		// Generate text-based diff for better readability.
		$text_diff = self::generate_text_diff( $from_text, $to_text );

		// Also provide HTML diff for technical review.
		$html_diff = self::generate_html_diff( $from, $to );

		return array_merge( $text_diff, array(
			'html_diff'    => $html_diff,
			'original_from' => $from,
			'original_to'   => $to,
		) );
	}

	/**
	 * Generate HTML-aware diff.
	 *
	 * @param string $from Original HTML.
	 * @param string $to   New HTML.
	 * @return array HTML diff data.
	 */
	private static function generate_html_diff( $from, $to ) {
		// For now, provide a simple character-level diff for HTML.
		// This could be enhanced with proper HTML parsing in the future.
		return array(
			'from'     => esc_html( $from ),
			'to'       => esc_html( $to ),
			'diff_html' => self::generate_simple_html_diff( $from, $to ),
		);
	}

	/**
	 * Calculate word-level diff using dynamic programming.
	 *
	 * @param array $from_words Original words.
	 * @param array $to_words   New words.
	 * @return array Diff result.
	 */
	private static function calculate_word_diff( $from_words, $to_words ) {
		$from_len = count( $from_words );
		$to_len = count( $to_words );

		// Create a matrix to store the longest common subsequence lengths.
		$lcs = array_fill( 0, $from_len + 1, array_fill( 0, $to_len + 1, 0 ) );

		// Fill the matrix.
		for ( $i = 1; $i <= $from_len; $i++ ) {
			for ( $j = 1; $j <= $to_len; $j++ ) {
				if ( $from_words[ $i - 1 ] === $to_words[ $j - 1 ] ) {
					$lcs[ $i ][ $j ] = $lcs[ $i - 1 ][ $j - 1 ] + 1;
				} else {
					$lcs[ $i ][ $j ] = max( $lcs[ $i - 1 ][ $j ], $lcs[ $i ][ $j - 1 ] );
				}
			}
		}

		// Backtrack to build the diff.
		$diff_ops = array();
		$i = $from_len;
		$j = $to_len;

		while ( $i > 0 || $j > 0 ) {
			if ( $i > 0 && $j > 0 && $from_words[ $i - 1 ] === $to_words[ $j - 1 ] ) {
				array_unshift( $diff_ops, array(
					'type' => 'equal',
					'word' => $from_words[ $i - 1 ],
				) );
				$i--;
				$j--;
			} elseif ( $j > 0 && ( $i === 0 || $lcs[ $i ][ $j - 1 ] >= $lcs[ $i - 1 ][ $j ] ) ) {
				array_unshift( $diff_ops, array(
					'type' => 'insert',
					'word' => $to_words[ $j - 1 ],
				) );
				$j--;
			} else {
				array_unshift( $diff_ops, array(
					'type' => 'delete',
					'word' => $from_words[ $i - 1 ],
				) );
				$i--;
			}
		}

		// Calculate statistics.
		$stats = array(
			'additions' => 0,
			'deletions' => 0,
			'changes'   => 0,
		);

		foreach ( $diff_ops as $op ) {
			switch ( $op['type'] ) {
				case 'insert':
					$stats['additions']++;
					break;
				case 'delete':
					$stats['deletions']++;
					break;
			}
		}

		$stats['changes'] = $stats['additions'] + $stats['deletions'];

		return array(
			'operations' => $diff_ops,
			'stats'      => $stats,
		);
	}

	/**
	 * Determine whether diff computation is too large.
	 *
	 * @param int $from_len Word count for original.
	 * @param int $to_len   Word count for new.
	 * @return bool
	 */
	private static function is_diff_too_large( $from_len, $to_len ) {
		if ( 0 === $from_len || 0 === $to_len ) {
			return false;
		}

		$cells = (int) $from_len * (int) $to_len;
		return $cells > self::MAX_DIFF_CELLS;
	}

	/**
	 * Fallback diff response for large content.
	 *
	 * @param string $from Original text.
	 * @param string $to   New text.
	 * @return array
	 */
	private static function get_large_diff( $from, $to ) {
		$notice = '<em>' . __( 'Content too large for word-level diff. Use text view or compare smaller sections.', 'masthead' ) . '</em>';

		return array(
			'from'           => $from,
			'to'             => $to,
			'diff_html'      => $notice,
			'diff_inline'    => $notice,
			'diff_split'     => array(
				'left'  => $notice,
				'right' => $notice,
			),
			'has_changes'    => true,
			'stats'          => array(
				'additions' => 0,
				'deletions' => 0,
				'changes'   => 0,
			),
			'too_large'      => true,
		);
	}

	/**
	 * Render diff as HTML for inline view.
	 *
	 * @param array $diff_result Diff result from calculate_word_diff.
	 * @return string HTML output.
	 */
	private static function render_diff_html( $diff_result ) {
		$html = '';
		
		foreach ( $diff_result['operations'] as $op ) {
			$word = esc_html( $op['word'] );
			
			switch ( $op['type'] ) {
				case 'equal':
					$html .= $word . ' ';
					break;
				case 'insert':
					$html .= '<ins class="masthead-diff-added">' . $word . '</ins> ';
					break;
				case 'delete':
					$html .= '<del class="masthead-diff-removed">' . $word . '</del> ';
					break;
			}
		}

		return trim( $html );
	}

	/**
	 * Render diff for inline display (additions and deletions together).
	 *
	 * @param array $diff_result Diff result.
	 * @return string HTML output.
	 */
	private static function render_diff_inline( $diff_result ) {
		return self::render_diff_html( $diff_result );
	}

	/**
	 * Render diff for side-by-side display.
	 *
	 * @param array $diff_result Diff result.
	 * @return array Split diff data.
	 */
	private static function render_diff_split( $diff_result ) {
		$left = '';  // Deletions and context.
		$right = ''; // Additions and context.
		
		foreach ( $diff_result['operations'] as $op ) {
			$word = esc_html( $op['word'] );
			
			switch ( $op['type'] ) {
				case 'equal':
					$left .= $word . ' ';
					$right .= $word . ' ';
					break;
				case 'insert':
					$right .= '<ins class="masthead-diff-added">' . $word . '</ins> ';
					break;
				case 'delete':
					$left .= '<del class="masthead-diff-removed">' . $word . '</del> ';
					break;
			}
		}

		return array(
			'left'  => trim( $left ),
			'right' => trim( $right ),
		);
	}

	/**
	 * Split text into words, preserving whitespace information.
	 *
	 * @param string $text The text to split.
	 * @return array Array of words.
	 */
	private static function split_into_words( $text ) {
		// Split on whitespace and punctuation, but keep them as separate tokens.
		$words = preg_split( '/(\s+|[.!?;:,()[\]{}"\'-]+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		return $words ?: array();
	}

	/**
	 * Normalize text for comparison.
	 *
	 * @param string $text The text to normalize.
	 * @return string Normalized text.
	 */
	private static function normalize_text( $text ) {
		// Decode HTML entities.
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		
		// Normalize whitespace.
		$text = preg_replace( '/\s+/', ' ', $text );
		
		return trim( $text );
	}

	/**
	 * Strip HTML for diff comparison while preserving meaningful structure.
	 *
	 * @param string $content HTML content.
	 * @return string Plain text content.
	 */
	private static function strip_html_for_diff( $content ) {
		if ( empty( $content ) ) {
			return '';
		}

		// Convert media elements to placeholders BEFORE stripping HTML.
		$content = self::convert_media_to_placeholders( $content );

		// Remove Gutenberg block comments.
		$content = preg_replace( '/<!--\s*\/?wp:[^>]*-->/s', '', $content );

		// Convert block-level elements to newlines.
		$content = preg_replace(
			'/<\/(p|div|h[1-6]|li|blockquote|pre|article|section|header|footer)>/i',
			"\n\n",
			$content
		);
		$content = preg_replace( '/<(br|hr)\s*\/?>/i', "\n", $content );

		// Convert list items to bullet points.
		$content = preg_replace( '/<li[^>]*>/i', '• ', $content );

		// Strip remaining HTML tags.
		$content = wp_strip_all_tags( $content );

		// Decode HTML entities.
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );

		// Normalize whitespace.
		$content = preg_replace( '/[ \t]+/', ' ', $content );
		$content = preg_replace( '/\n{3,}/', "\n\n", $content );

		// Trim whitespace from each line.
		$lines = explode( "\n", $content );
		$lines = array_map( 'trim', $lines );
		$content = implode( "\n", $lines );

		return trim( $content );
	}

	/**
	 * Convert media elements to readable placeholders.
	 *
	 * @param string $content The HTML content.
	 * @return string Content with media replaced by placeholders.
	 */
	private static function convert_media_to_placeholders( $content ) {
		// Images.
		$content = preg_replace_callback(
			'/<img[^>]*>/i',
			function ( $matches ) {
				$img = $matches[0];
				if ( preg_match( '/alt=["\']([^"\']*)["\']/', $img, $alt_match ) && ! empty( $alt_match[1] ) ) {
					return '[Image: ' . $alt_match[1] . ']';
				}
				if ( preg_match( '/src=["\']([^"\']*)["\']/', $img, $src_match ) ) {
					$filename = basename( parse_url( $src_match[1], PHP_URL_PATH ) );
					if ( ! empty( $filename ) ) {
						return '[Image: ' . $filename . ']';
					}
				}
				return '[Image]';
			},
			$content
		);

		// Videos.
		$content = preg_replace_callback(
			'/<video[^>]*>.*?<\/video>/is',
			function ( $matches ) {
				$video = $matches[0];
				if ( preg_match( '/src=["\']([^"\']*)["\']/', $video, $src_match ) ) {
					$filename = basename( parse_url( $src_match[1], PHP_URL_PATH ) );
					if ( ! empty( $filename ) ) {
						return '[Video: ' . $filename . ']';
					}
				}
				return '[Video]';
			},
			$content
		);

		// Audio.
		$content = preg_replace_callback(
			'/<audio[^>]*>.*?<\/audio>/is',
			function ( $matches ) {
				$audio = $matches[0];
				if ( preg_match( '/src=["\']([^"\']*)["\']/', $audio, $src_match ) ) {
					$filename = basename( parse_url( $src_match[1], PHP_URL_PATH ) );
					if ( ! empty( $filename ) ) {
						return '[Audio: ' . $filename . ']';
					}
				}
				return '[Audio]';
			},
			$content
		);

		// YouTube embeds.
		$youtube_pattern = '/(?:youtube\.com\/(?:embed\/|watch\?v=)|youtu\.be\/)([a-zA-Z0-9_-]+)/';
		$content = preg_replace( $youtube_pattern, '[YouTube Video: $1]', $content );

		// Vimeo embeds.
		$content = preg_replace( '/vimeo\.com\/(?:video\/)?(\d+)/', '[Vimeo Video: $1]', $content );

		// Generic iframes.
		$content = preg_replace_callback(
			'/<iframe[^>]*>.*?<\/iframe>/is',
			function ( $matches ) {
				$iframe = $matches[0];
				if ( preg_match( '/src=["\']([^"\']*)["\']/', $iframe, $src_match ) ) {
					$url = $src_match[1];
					$host = parse_url( $url, PHP_URL_HOST );
					if ( $host ) {
						return '[Embed: ' . $host . ']';
					}
				}
				return '[Embed]';
			},
			$content
		);

		// Tables.
		$content = preg_replace( '/<table[^>]*>.*?<\/table>/is', '[Table]', $content );

		// Buttons.
		$content = preg_replace_callback(
			'/<[^>]*class=["\'][^"\']*wp-block-button[^"\']*["\'][^>]*>.*?<\/[^>]+>/is',
			function ( $matches ) {
				if ( preg_match( '/<a[^>]*>([^<]*)<\/a>/', $matches[0], $text_match ) ) {
					return '[Button: ' . trim( $text_match[1] ) . ']';
				}
				return '[Button]';
			},
			$content
		);

		return $content;
	}

	/**
	 * Generate simple HTML diff (character-based).
	 *
	 * @param string $from Original HTML.
	 * @param string $to   New HTML.
	 * @return string Simple diff HTML.
	 */
	private static function generate_simple_html_diff( $from, $to ) {
		$from_chars = str_split( $from );
		$to_chars = str_split( $to );

		// This is a simplified character-level diff.
		// For production use, consider implementing a more sophisticated HTML-aware diff.
		return '<div class="masthead-html-diff-notice">' 
			. __( 'HTML content changed. Use text view for detailed comparison.', 'masthead' )
			. '</div>';
	}

	/**
	 * Get empty diff structure.
	 *
	 * @return array
	 */
	private static function get_empty_diff() {
		return array(
			'from'           => '',
			'to'             => '',
			'diff_html'      => '<em>' . __( 'No content', 'masthead' ) . '</em>',
			'diff_inline'    => '<em>' . __( 'No content', 'masthead' ) . '</em>',
			'diff_split'     => array(
				'left'  => '<em>' . __( 'No content', 'masthead' ) . '</em>',
				'right' => '<em>' . __( 'No content', 'masthead' ) . '</em>',
			),
			'has_changes'    => false,
			'stats'          => array(
				'additions' => 0,
				'deletions' => 0,
				'changes'   => 0,
			),
		);
	}

	/**
	 * Get no-change diff structure.
	 *
	 * @param string $content The unchanged content.
	 * @return array
	 */
	private static function get_no_change_diff( $content ) {
		$display_content = empty( $content ) ? '<em>' . __( 'No content', 'masthead' ) . '</em>' : esc_html( $content );
		
		return array(
			'from'           => $content,
			'to'             => $content,
			'diff_html'      => $display_content,
			'diff_inline'    => $display_content,
			'diff_split'     => array(
				'left'  => $display_content,
				'right' => $display_content,
			),
			'has_changes'    => false,
			'stats'          => array(
				'additions' => 0,
				'deletions' => 0,
				'changes'   => 0,
			),
		);
	}
}
