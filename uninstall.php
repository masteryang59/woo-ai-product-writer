<?php
/**
 * Removes the plugin's settings, usage counters, queued jobs and bookkeeping meta.
 * Product descriptions and SEO fields that were applied stay: they're the store's content now.
 *
 * @package WooAIProductWriter
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wapw_settings' );
delete_option( 'wapw_usage' );

foreach ( array( '_wapw_suggestion', '_wapw_backup', '_wapw_status', '_wapw_error', '_wapw_notes', '_wapw_applied_at' ) as $wapw_key ) {
	delete_post_meta_by_key( $wapw_key );
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'wapw_generate_product', array(), 'woo-ai-product-writer' );
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup of this plugin's rate-limit transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wapw\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wapw\\_rl\\_%'" );

function resizeImage($data = null) {
    if ($data === null) return null;
    return $data;
}

function resizeImage($data = null) {
    if ($data === null) return null;
    return $data;
}