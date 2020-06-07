<?php
/**
 * Prints the plugin's own meta title and description on product pages when no SEO
 * plugin is handling them.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Seo;

use WooAIProductWriter\Meta;
use WooAIProductWriter\Settings;

defined( 'ABSPATH' ) || exit;

final class NativeSeo {

	public function __construct( private readonly Settings $settings ) {}

	public function register(): void {
		add_filter( 'document_title_parts', array( $this, 'title_parts' ) );
		add_action( 'wp_head', array( $this, 'meta_description' ), 1 );
	}

	private function active(): bool {
		return is_singular( 'product' )
			&& 'native' === ( new SeoAdapterFactory( $this->settings ) )->resolve()?->id;
	}

	/**
	 * @param array<string,string> $parts Title parts.
	 * @return array<string,string>
	 */
	public function title_parts( array $parts ): array {
		if ( $this->active() ) {
			$title = (string) get_post_meta( (int) get_queried_object_id(), Meta::SEO_TITLE, true );
			if ( '' !== $title ) {
				$parts['title'] = $title;
			}
		}
		return $parts;
	}

	public function meta_description(): void {
		if ( ! $this->active() ) {
			return;
		}
		$description = (string) get_post_meta( (int) get_queried_object_id(), Meta::SEO_DESCRIPTION, true );
		if ( '' !== $description ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
		}
	}
}
