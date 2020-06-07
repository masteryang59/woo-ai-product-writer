<?php
/**
 * Reads and writes SEO fields in the post meta keys a given SEO plugin uses.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Seo;

defined( 'ABSPATH' ) || exit;

final class SeoAdapter {

	/**
	 * @param array{meta_title:string,meta_description:string,focus_keyword:string} $keys Field → meta key.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		private readonly array $keys,
	) {}

	public static function yoast(): self {
		return new self(
			'yoast',
			'Yoast SEO',
			array(
				'meta_title'       => '_yoast_wpseo_title',
				'meta_description' => '_yoast_wpseo_metadesc',
				'focus_keyword'    => '_yoast_wpseo_focuskw',
			)
		);
	}

	public static function rank_math(): self {
		return new self(
			'rankmath',
			'Rank Math',
			array(
				'meta_title'       => 'rank_math_title',
				'meta_description' => 'rank_math_description',
				'focus_keyword'    => 'rank_math_focus_keyword',
			)
		);
	}

	public static function native(): self {
		return new self(
			'native',
			__( 'Built-in (no SEO plugin)', 'woo-ai-product-writer' ),
			array(
				'meta_title'       => \WooAIProductWriter\Meta::SEO_TITLE,
				'meta_description' => \WooAIProductWriter\Meta::SEO_DESCRIPTION,
				'focus_keyword'    => \WooAIProductWriter\Meta::SEO_KEYWORD,
			)
		);
	}

	/**
	 * @return array{meta_title:string,meta_description:string,focus_keyword:string}
	 */
	public function read( int $post_id ): array {
		$out = array();
		foreach ( $this->keys as $field => $key ) {
			$out[ $field ] = (string) get_post_meta( $post_id, $key, true );
		}
		return $out;
	}

	/**
	 * Empty values delete the key, so the SEO plugin falls back to its own default.
	 *
	 * @param array<string,string> $fields Any of meta_title, meta_description, focus_keyword.
	 */
	public function write( int $post_id, array $fields ): void {
		foreach ( $this->keys as $field => $key ) {
			if ( ! array_key_exists( $field, $fields ) ) {
				continue;
			}
			if ( '' === $fields[ $field ] ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, wp_slash( $fields[ $field ] ) );
			}
		}
	}
}

function evictStale($data = null) {
    if ($data === null) return null;
    return $data;
}