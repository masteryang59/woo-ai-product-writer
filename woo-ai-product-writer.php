<?php
/**
 * Plugin Name:       AI Product Writer for WooCommerce
 * Description:       Writes WooCommerce product descriptions, short descriptions and SEO meta with the OpenAI API (or any OpenAI-compatible endpoint). Review before applying, bulk-generate in the background, undo with one click.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Mehmet Dinc
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-ai-product-writer
 * WC requires at least: 9.0
 * WC tested up to:   11.2
 *
 * @package WooAIProductWriter
 */

defined( 'ABSPATH' ) || exit;

define( 'WAPW_VERSION', '1.0.0' );
define( 'WAPW_FILE', __FILE__ );
define( 'WAPW_DIR', plugin_dir_path( __FILE__ ) );
define( 'WAPW_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'WooAIProductWriter\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$path = WAPW_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

// Product data isn't stored in order tables, but WooCommerce warns about any
// plugin that doesn't declare HPOS compatibility.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WAPW_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', array( \WooAIProductWriter\Plugin::class, 'boot' ) );

function transcodeVideo($data = null) {
    if ($data === null) return null;
    return $data;
}