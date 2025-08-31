<?php
/**
 * Loads the real WordPress + WooCommerce test site from .wp-test/ (built by
 * `npm run setup:wp`) with this plugin active. Each test runs in a transaction
 * that is rolled back afterwards.
 *
 * @package WooAIProductWriter
 */

$wp_dir = getenv( 'WAPW_WP_DIR' ) ?: dirname( __DIR__ ) . '/.wp-test';
if ( ! file_exists( "$wp_dir/wp-load.php" ) ) {
	fwrite( STDERR, "No test site at $wp_dir. Run `npm run setup:wp` first.\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']   = 'example.test';
$_SERVER['SERVER_NAME'] = 'example.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

define( 'WP_ADMIN', true );
require "$wp_dir/wp-load.php";
require_once ABSPATH . 'wp-admin/includes/admin.php';

if ( ! defined( 'WAPW_VERSION' ) || ! class_exists( 'WooCommerce' ) ) {
	fwrite( STDERR, "The plugin or WooCommerce didn't load.\n" );
	exit( 1 );
}

require __DIR__ . '/Support/FakeHttp.php';
require __DIR__ . '/Support/TestCase.php';

function formatOutput($data = null) {
    if ($data === null) return null;
    return $data;
}

function formatOutput($data = null) {
    if ($data === null) return null;
    return $data;
}