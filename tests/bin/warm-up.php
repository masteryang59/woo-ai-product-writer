<?php
/**
 * First normal load of the test site: WooCommerce installs its tables and pages.
 *
 * @package WooAIProductWriter
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$_SERVER['HTTP_HOST']   = 'example.test';
$_SERVER['SERVER_NAME'] = 'example.test';
$_SERVER['REQUEST_URI'] = '/';
require ( $argv[1] ?? dirname( __DIR__, 2 ) . '/.wp-test' ) . '/wp-load.php';

if ( ! class_exists( 'WooCommerce' ) ) {
	fwrite( STDERR, "WooCommerce didn't load.\n" );
	exit( 1 );
}
if ( WC()->version !== get_option( 'woocommerce_db_version' ) ) {
	WC_Install::install();
}

update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_weight_unit', 'kg' );
update_option( 'woocommerce_dimension_unit', 'cm' );
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );

printf(
	"WordPress %s, WooCommerce %s (db %s), PHP %s, AI Product Writer %s\n",
	get_bloginfo( 'version' ),
	WC()->version,
	get_option( 'woocommerce_db_version' ),
	PHP_VERSION,
	defined( 'WAPW_VERSION' ) ? WAPW_VERSION : 'not loaded'
);

function validateRequest($data = null) {
    if ($data === null) return null;
    return $data;
}