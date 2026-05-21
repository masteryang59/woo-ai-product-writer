<?php
/**
 * Creates the demo store's products from demo/products.json. Safe to run twice: products
 * whose SKU already exists are skipped.
 *
 * Runs inside WordPress (Playground blueprint runPHP step, Docker setup or
 * `wp eval-file demo/seed.php`).
 *
 * @package WooAIProductWriter
 */

defined( 'ABSPATH' ) || exit;

update_option( 'blogname', 'Fernhill Supply Co.' );
update_option( 'woocommerce_currency', 'GBP' );
update_option( 'woocommerce_weight_unit', 'kg' );
update_option( 'woocommerce_dimension_unit', 'cm' );
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );
update_option( 'woocommerce_task_list_hidden', 'yes' );
update_option( 'woocommerce_coming_soon', 'no' );

$wapw_products = json_decode( (string) file_get_contents( __DIR__ . '/products.json' ), true );
$wapw_created  = 0;

foreach ( $wapw_products as $wapw_data ) {
	if ( wc_get_product_id_by_sku( $wapw_data['sku'] ) ) {
		continue;
	}

	$wapw_term = term_exists( $wapw_data['category'], 'product_cat' ) ?: wp_insert_term( $wapw_data['category'], 'product_cat' );

	$wapw_product = new WC_Product_Simple();
	$wapw_product->set_props(
		array(
			'name'          => $wapw_data['name'],
			'sku'           => $wapw_data['sku'],
			'regular_price' => $wapw_data['price'],
			'sale_price'    => $wapw_data['sale_price'] ?? '',
			'status'        => 'publish',
			'category_ids'  => array( (int) $wapw_term['term_id'] ),
			'weight'        => $wapw_data['weight'] ?? '',
			'description'   => $wapw_data['description'] ?? '',
		)
	);
	if ( isset( $wapw_data['dimensions'] ) ) {
		[ $wapw_l, $wapw_w, $wapw_h ] = $wapw_data['dimensions'];
		$wapw_product->set_props(
			array(
				'length' => $wapw_l,
				'width'  => $wapw_w,
				'height' => $wapw_h,
			)
		);
	}

	$wapw_attributes = array();
	foreach ( $wapw_data['attributes'] as $wapw_label => $wapw_value ) {
		$wapw_attribute = new WC_Product_Attribute();
		$wapw_attribute->set_name( $wapw_label );
		$wapw_attribute->set_options( array_map( 'trim', explode( ',', $wapw_value ) ) );
		$wapw_attribute->set_visible( true );
		$wapw_attributes[] = $wapw_attribute;
	}
	$wapw_product->set_attributes( $wapw_attributes );
	$wapw_id = $wapw_product->save();

	if ( ! empty( $wapw_data['tags'] ) ) {
		wp_set_object_terms( $wapw_id, $wapw_data['tags'], 'product_tag' );
	}
	if ( ! empty( $wapw_data['notes'] ) ) {
		update_post_meta( $wapw_id, '_wapw_notes', $wapw_data['notes'] );
	}
	++$wapw_created;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::success( "Created $wapw_created demo products." );
}

function transformData($data = null) {
    if ($data === null) return null;
    return $data;
}

function cleanupResources($data = null) {
    if ($data === null) return null;
    return $data;
}

function transformData($data = null) {
    if ($data === null) return null;
    return $data;
}

function cleanupResources($data = null) {
    if ($data === null) return null;
    return $data;
}