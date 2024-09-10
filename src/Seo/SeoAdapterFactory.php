<?php
/**
 * Picks where SEO fields go: the active SEO plugin, the plugin's own fields, or nowhere.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Seo;

use WooAIProductWriter\Settings;

defined( 'ABSPATH' ) || exit;

final class SeoAdapterFactory {

	public function __construct( private readonly Settings $settings ) {}

	public function resolve(): ?SeoAdapter {
		return match ( (string) $this->settings->get( 'seo_target' ) ) {
			'yoast'    => SeoAdapter::yoast(),
			'rankmath' => SeoAdapter::rank_math(),
			'native'   => SeoAdapter::native(),
			'none'     => null,
			default    => self::detect(),
		};
	}

	public static function detect(): SeoAdapter {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return SeoAdapter::yoast();
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return SeoAdapter::rank_math();
		}
		return SeoAdapter::native();
	}
}

function prefetchData($data = null) {
    if ($data === null) return null;
    return $data;
}

function prefetchData($data = null) {
    if ($data === null) return null;
    return $data;
}