<?php
/**
 * Media Change Tracking feature for Masthead
 *
 * @package Masthead
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Masthead_Media_Change_Tracking
 *
 * Tracks and highlights changes to images, videos, and other media between revisions.
 */
class Masthead_Media_Change_Tracking {

	/**
	 * Singleton instance.
	 *
	 * @var Masthead_Media_Change_Tracking|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Masthead_Media_Change_Tracking
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
		// Media tracking is primarily used through static methods
	}

	/**
	 * Get media changes between two content versions.
	 *
	 * @param string $from_content The original content.
	 * @param string $to_content   The new content.
	 * @return array Media changes with 'added' and 'removed' arrays.
	 */
	public static function get_media_changes( $from_content, $to_content ) {
		$from_media = self::extract_media( $from_content );
		$to_media = self::extract_media( $to_content );

		$added = self::get_added_media( $from_media, $to_media );
		$removed = self::get_removed_media( $from_media, $to_media );

		return array(
			'added'   => $added,
			'removed' => $removed,
			'summary' => array(
				'added_count'   => count( $added ),
				'removed_count' => count( $removed ),
				'total_changes' => count( $added ) + count( $removed ),
			),
		);
	}

	/**
	 * Extract media elements from content.
	 *
	 * @param string $content The HTML content.
	 * @return array Array of media items.
	 */
	private static function extract_media( $content ) {
		$media = array();

		// Images
		$media = array_merge( $media, self::extract_images( $content ) );

		// Videos
		$media = array_merge( $media, self::extract_videos( $content ) );

		// Audio
		$media = array_merge( $media, self::extract_audio( $content ) );

		// YouTube
		$media = array_merge( $media, self::extract_youtube( $content ) );

		// Vimeo
		$media = array_merge( $media, self::extract_vimeo( $content ) );

		// Generic iframes/embeds
		$media = array_merge( $media, self::extract_iframes( $content ) );

		// WordPress galleries and blocks
		$media = array_merge( $media, self::extract_wp_blocks( $content ) );

		return $media;
	}

	/**
	 * Extract images from content.
	 *
	 * @param string $content The content.
	 * @return array
	 */
	private static function extract_images( $content ) {
		$images = array();

		if ( preg_match_all( '/<img[^>]*>/i', $content, $matches ) ) {
			foreach ( $matches[0] as $img ) {
				$src = '';
				$alt = '';
				$title = '';

				if ( preg_match( '/src=["\']([^"\']*)["\']/', $img, $src_match ) ) {
					$src = $src_match[1];
				}
				if ( preg_match( '/alt=["\']([^"\']*)["\']/', $img, $alt_match ) ) {
					$alt = $alt_match[1];
				}
				if ( preg_match( '/title=["\']([^"\']*)["\']/', $img, $title_match ) ) {
					$title = $title_match[1];
				}

				if ( ! empty( $src ) ) {
					$images[] = array(
						'type'      => 'image',
						'src'       => $src,
						'alt'       => $alt,
						'title'     => $title,
						'name'      => self::get_media_name( $src, $alt, $title ),
						'thumbnail' => self::get_thumbnail_url( $src ),
						'full_tag'  => $img,
					);
				}
			}
		}

		return $images;
	}

	/**
	 * Extract videos from content.
	 *
	 * @param string $content The content.
	 * @return array
	 */
	private static function extract_videos( $content ) {
		$videos = array();

		if ( preg_match_all( '/<video[^>]*>.*?<\/video>/is', $content, $matches ) ) {
			foreach ( $matches[0] as $video ) {
				$src = '';
				$poster = '';

				if ( preg_match( '/src=["\']([^"\']*)["\']/', $video, $src_match ) ) {
					$src = $src_match[1];
				} elseif ( preg_match( '/<source[^>]*src=["\']([^"\']*)["\']/', $video, $src_match ) ) {
					$src = $src_match[1];
				}

				if ( preg_match( '/poster=["\']([^"\']*)["\']/', $video, $poster_match ) ) {
					$poster = $poster_match[1];
				}

				if ( ! empty( $src ) ) {
					$videos[] = array(
						'type'      => 'video',
						'src'       => $src,
						'poster'    => $poster,
						'name'      => self::get_media_name( $src ),
						'thumbnail' => $poster ?: self::get_video_thumbnail( $src ),
						'full_tag'  => $video,
					);
				}
			}
		}

		return $videos;
	}

	/**
	 * Extract audio from content.
	 *
	 * @param string $content The content.
	 * @return array
	 */
	private static function extract_audio( $content ) {
		$audio = array();

		if ( preg_match_all( '/<audio[^>]*>.*?<\/audio>/is', $content, $matches ) ) {
			foreach ( $matches[0] as $audio_tag ) {
				$src = '';

				if ( preg_match( '/src=["\']([^"\']*)["\']/', $audio_tag, $src_match ) ) {
					$src = $src_match[1];
				} elseif ( preg_match( '/<source[^>]*src=["\']([^"\']*)["\']/', $audio_tag, $src_match ) ) {
					$src = $src_match[1];
				}

				if ( ! empty( $src ) ) {
					$audio[] = array(
						'type'      => 'audio',
						'src'       => $src,
						'name'      => self::get_media_name( $src ),
						'thumbnail' => null,
						'full_tag'  => $audio_tag,
					);
				}
			}
		}

		return $audio;
	}

	/**
	 * Extract YouTube videos from content.
	 *
	 * @param string $content The content.
	 * @return array
	 */
	private static function extract_youtube( $content ) {
		$youtube = array();
		$pattern = '/(?:youtube\.com\/(?:embed\/|watch\?v=)|youtu\.be\/)([a-zA-Z0-9_-]+)/';

		if ( preg_match_all( $pattern, $content, $matches ) ) {
			foreach ( $matches[1] as $video_id ) {
				$youtube[] = array(
					'type'      => 'youtube',
					'src'       => 'https://www.youtube.com/embed/' . $video_id,
					'video_id'  => $video_id,
					'name'      => 'YouTube Video',
					'thumbnail' => 'https://img.youtube.com/vi/' . $video_id . '/hqdefault.jpg',
				);
			}
		}

		return $youtube;
	}

	/**
	 * Extract Vimeo videos from content.
	 *
	 * @param string $content The content.
	 * @return array
	 */
	private static function extract_vimeo( $content ) {
		$vimeo = array();

		if ( preg_match_all( '/vimeo\.com\/(?:video\/)?(\d+)/', $content, $matches ) ) {
			foreach ( $matches[1] as $video_id ) {
				$vimeo[] = array(
					'type'     => 'vimeo',
					'src'      => 'https://vimeo.com/' . $video_id,
					'video_id' => $video_id,
					'name'     => 'Vimeo Video (' . $video_id . ')',
					'thumbnail' => self::get_vimeo_thumbnail( $video_id ),
				);
			}
		}

		return $vimeo;
	}

	/**
	 * Extract iframe embeds from content.
	 *
	 * @param string $content The content.
	 * @return array
	 */
	private static function extract_iframes( $content ) {
		$iframes = array();

		if ( preg_match_all( '/<iframe[^>]*>.*?<\/iframe>/is', $content, $matches ) ) {
			foreach ( $matches[0] as $iframe ) {
				$src = '';

				if ( preg_match( '/src=["\']([^"\']*)["\']/', $iframe, $src_match ) ) {
					$src = $src_match[1];
				}

				if ( ! empty( $src ) ) {
					// Skip if we already captured this as YouTube or Vimeo
					if ( strpos( $src, 'youtube' ) !== false || strpos( $src, 'vimeo' ) !== false ) {
						continue;
					}

					$host = parse_url( $src, PHP_URL_HOST );
					$embed_type = self::identify_embed_type( $host );

					$iframes[] = array(
						'type'      => 'embed',
						'src'       => $src,
						'host'      => $host,
						'embed_type' => $embed_type,
						'name'      => self::get_embed_name( $embed_type, $host ),
						'thumbnail' => null,
						'full_tag'  => $iframe,
					);
				}
			}
		}

		return $iframes;
	}

	/**
	 * Extract WordPress blocks and galleries.
	 *
	 * @param string $content The content.
	 * @return array
	 */
	private static function extract_wp_blocks( $content ) {
		$blocks = array();

		// Gallery blocks
		if ( preg_match_all( '/<figure[^>]*class=["\'][^"\']*wp-block-gallery[^"\']*["\'][^>]*>.*?<\/figure>/is', $content, $matches ) ) {
			foreach ( $matches[0] as $index => $gallery ) {
				$image_count = substr_count( $gallery, '<img' );
				$blocks[] = array(
					'type'        => 'gallery',
					'src'         => '',
					'name'        => sprintf( __( 'Gallery (%d images)', 'masthead' ), $image_count ),
					'image_count' => $image_count,
					'thumbnail'   => null,
					'full_tag'    => $gallery,
				);
			}
		}

		// File download blocks
		if ( preg_match_all( '/<a[^>]*class=["\'][^"\']*wp-block-file[^"\']*["\'][^>]*>.*?<\/a>/is', $content, $matches ) ) {
			foreach ( $matches[0] as $file_link ) {
				$href = '';
				$filename = '';

				if ( preg_match( '/href=["\']([^"\']*)["\']/', $file_link, $href_match ) ) {
					$href = $href_match[1];
					$filename = basename( parse_url( $href, PHP_URL_PATH ) );
				}

				if ( ! empty( $href ) ) {
					$blocks[] = array(
						'type'      => 'file',
						'src'       => $href,
						'name'      => $filename ? 'File: ' . $filename : 'File Download',
						'thumbnail' => null,
						'full_tag'  => $file_link,
					);
				}
			}
		}

		return $blocks;
	}

	/**
	 * Get added media items.
	 *
	 * @param array $from_media Original media.
	 * @param array $to_media   New media.
	 * @return array
	 */
	private static function get_added_media( $from_media, $to_media ) {
		$added = array();

		foreach ( $to_media as $media ) {
			if ( ! self::media_exists_in_array( $media, $from_media ) ) {
				$added[] = $media;
			}
		}

		return $added;
	}

	/**
	 * Get removed media items.
	 *
	 * @param array $from_media Original media.
	 * @param array $to_media   New media.
	 * @return array
	 */
	private static function get_removed_media( $from_media, $to_media ) {
		$removed = array();

		foreach ( $from_media as $media ) {
			if ( ! self::media_exists_in_array( $media, $to_media ) ) {
				$removed[] = $media;
			}
		}

		return $removed;
	}

	/**
	 * Check if media exists in array.
	 *
	 * @param array $media       The media item to check.
	 * @param array $media_array The array to search in.
	 * @return bool
	 */
	private static function media_exists_in_array( $media, $media_array ) {
		foreach ( $media_array as $existing ) {
			if ( $media['src'] === $existing['src'] && $media['type'] === $existing['type'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get media name for display.
	 *
	 * @param string $src   The source URL.
	 * @param string $alt   Alt text (optional).
	 * @param string $title Title (optional).
	 * @return string
	 */
	private static function get_media_name( $src, $alt = '', $title = '' ) {
		if ( ! empty( $alt ) ) {
			return $alt;
		}
		if ( ! empty( $title ) ) {
			return $title;
		}

		$filename = basename( parse_url( $src, PHP_URL_PATH ) );
		return $filename ?: $src;
	}

	/**
	 * Get thumbnail URL for an image.
	 *
	 * @param string $src The image source URL.
	 * @return string
	 */
	private static function get_thumbnail_url( $src ) {
		// For WordPress uploads, try to get a thumbnail
		if ( strpos( $src, wp_upload_dir()['baseurl'] ) === 0 ) {
			$attachment_id = attachment_url_to_postid( $src );
			if ( $attachment_id ) {
				$thumbnail = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );
				if ( $thumbnail ) {
					return $thumbnail[0];
				}
			}
		}

		return $src;
	}

	/**
	 * Get video thumbnail (placeholder for now).
	 *
	 * @param string $src The video source URL.
	 * @return string|null
	 */
	private static function get_video_thumbnail( $src ) {
		// This could be enhanced to extract video thumbnails
		return null;
	}

	/**
	 * Get Vimeo thumbnail.
	 *
	 * @param string $video_id The Vimeo video ID.
	 * @return string|null
	 */
	private static function get_vimeo_thumbnail( $video_id ) {
		// This would require an API call to Vimeo
		// For now, return null - could be enhanced later
		return null;
	}

	/**
	 * Identify embed type from host.
	 *
	 * @param string $host The hostname.
	 * @return string
	 */
	private static function identify_embed_type( $host ) {
		$host = strtolower( $host );

		$embed_types = array(
			'twitter.com'      => 'twitter',
			'x.com'            => 'twitter',
			'instagram.com'    => 'instagram',
			'facebook.com'     => 'facebook',
			'spotify.com'      => 'spotify',
			'soundcloud.com'   => 'soundcloud',
			'codepen.io'       => 'codepen',
			'slideshare.net'   => 'slideshare',
			'scribd.com'       => 'scribd',
		);

		return $embed_types[ $host ] ?? 'generic';
	}

	/**
	 * Get embed name based on type.
	 *
	 * @param string $embed_type The embed type.
	 * @param string $host       The hostname.
	 * @return string
	 */
	private static function get_embed_name( $embed_type, $host ) {
		$names = array(
			'twitter'     => 'Twitter/X Embed',
			'instagram'   => 'Instagram Embed',
			'facebook'    => 'Facebook Embed',
			'spotify'     => 'Spotify Embed',
			'soundcloud'  => 'SoundCloud Embed',
			'codepen'     => 'CodePen Embed',
			'slideshare'  => 'SlideShare Embed',
			'scribd'      => 'Scribd Embed',
		);

		return $names[ $embed_type ] ?? 'Embed: ' . $host;
	}
}