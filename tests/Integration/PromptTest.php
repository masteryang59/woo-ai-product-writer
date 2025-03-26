<?php
/**
 * ProductFacts and PromptBuilder against real WooCommerce products.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Tests\Integration;

use WooAIProductWriter\Content\ProductFacts;
use WooAIProductWriter\Content\PromptBuilder;
use WooAIProductWriter\Tests\Support\TestCase;

final class PromptTest extends TestCase {

	public function test_facts_include_what_a_store_fills_in(): void {
		$product = $this->product( array( 'sale_price' => '54' ) );
		wp_set_object_terms( $product->get_id(), array( 'rainy days', 'gifts' ), 'product_tag' );

		$facts = ProductFacts::from_product( wc_get_product( $product->get_id() ), 'Hand-waxed in Leeds.' );

		$this->assertSame( 'Waxed Canvas Tote', $facts['name'] );
		$this->assertSame( 'TOTE-WAX-01', $facts['sku'] );
		$this->assertSame( '54 USD (on sale, regular 68)', $facts['price'] );
		$this->assertStringStartsWith( 'Bags ', $facts['categories'][0] );
		$this->assertEqualsCanonicalizing( array( 'rainy days', 'gifts' ), $facts['tags'] );
		$this->assertSame(
			array(
				'Material' => 'Waxed cotton canvas, Leather handles',
				'Capacity' => '18 litres',
			),
			$facts['attributes']
		);
		$this->assertSame( '0.6 kg', $facts['weight'] );
		$this->assertSame( '40 × 12 × 35 cm', $facts['dimensions'] );
		$this->assertSame( 'Hand-waxed in Leeds.', $facts['merchant_notes'] );
	}

	public function test_hidden_attributes_and_uncategorized_are_left_out(): void {
		$product = $this->product( array( 'category_ids' => array( (int) get_option( 'default_product_cat' ) ) ) );
		$facts   = ProductFacts::from_product( $product );

		$this->assertArrayNotHasKey( 'Supplier code', $facts['attributes'] );
		$this->assertArrayNotHasKey( 'categories', $facts );
		$this->assertStringNotContainsString( 'SUP-99812', wp_json_encode( $facts ) );
	}

	public function test_taxonomy_attributes_use_term_names(): void {
		$attribute_id = wc_create_attribute(
			array(
				'name' => 'Colour',
				'slug' => 'colour-' . wp_generate_password( 4, false, false ),
			)
		);
		$taxonomy = wc_attribute_taxonomy_name_by_id( $attribute_id );
		// WooCommerce registers attribute taxonomies on init; do what it does for this one.
		register_taxonomy( $taxonomy, 'product' );
		$GLOBALS['wc_product_attributes'][ $taxonomy ] = wc_get_attribute( $attribute_id );
		wp_insert_term( 'Olive', $taxonomy );
		wp_insert_term( 'Navy', $taxonomy );

		$attribute = new \WC_Product_Attribute();
		$attribute->set_id( $attribute_id );
		$attribute->set_name( $taxonomy );
		$attribute->set_options( array( get_term_by( 'name', 'Olive', $taxonomy )->term_id, get_term_by( 'name', 'Navy', $taxonomy )->term_id ) );
		$attribute->set_visible( true );

		$product = $this->product( array( 'attributes' => array( $attribute ) ) );
		$product->set_attributes( array( $attribute ) );
		$product->save();

		$facts = ProductFacts::from_product( wc_get_product( $product->get_id() ) );
		unset( $GLOBALS['wc_product_attributes'][ $taxonomy ] );
		$this->assertSame( array( 'Colour' => 'Navy, Olive' ), $facts['attributes'] );
	}

	public function test_variable_products_get_a_price_range(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Merino Beanie' );
		$parent->set_status( 'publish' );
		$parent->save();
		foreach ( array( '24', '29' ) as $price ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_regular_price( $price );
			$variation->set_status( 'publish' );
			$variation->save();
		}
		\WC_Product_Variable::sync( $parent->get_id() );

		$facts = ProductFacts::from_product( wc_get_product( $parent->get_id() ) );
		$this->assertSame( '24–29 USD', $facts['price'] );
		$this->assertSame( 'variable', $facts['type'] );
	}

	public function test_existing_copy_is_passed_as_plain_text_and_capped(): void {
		$product = $this->product(
			array(
				'description'       => '<p>Old <strong>copy</strong> &amp; more.</p><ul><li>Point one</li><li>Point two</li></ul>' . str_repeat( '<p>Filler sentence here.</p>', 100 ),
				'short_description' => '<p>Short &amp; sweet.</p>',
			)
		);

		$facts = ProductFacts::from_product( $product );
		$this->assertStringStartsWith( "Old copy & more.\nPoint one\nPoint two\n", $facts['current_description'] );
		$this->assertLessThanOrEqual( 1201, mb_strlen( $facts['current_description'] ) );
		$this->assertStringEndsWith( '…', $facts['current_description'] );
		$this->assertSame( 'Short & sweet.', $facts['current_short_description'] );
	}

	public function test_facts_filter_can_add_custom_fields(): void {
		$this->filter(
			'wapw_product_facts',
			static fn ( array $facts, \WC_Product $product ) => $facts + array( 'care' => 'Re-wax once a year' ),
			10,
			2
		);
		$facts = ProductFacts::from_product( $this->product() );
		$this->assertSame( 'Re-wax once a year', $facts['care'] );
	}

	public function test_prompt_carries_settings_and_facts(): void {
		$settings = $this->settings(
			array(
				'tone'              => 'premium',
				'language'          => 'German',
				'description_words' => 220,
				'brand_voice'       => 'Quiet confidence, no exclamation marks.',
				'extra_rules'       => 'Never mention delivery times.',
			)
		);
		$facts    = ProductFacts::from_product( $this->product() );
		$messages = ( new PromptBuilder( $settings ) )->messages( $facts );

		$this->assertSame( array( 'system', 'user' ), array_column( $messages, 'role' ) );
		$system = $messages[0]['content'];
		$this->assertStringContainsString( 'Write in German.', $system );
		$this->assertStringContainsString( 'Premium and understated', $system );
		$this->assertStringContainsString( 'about 220 words', $system );
		$this->assertStringContainsString( 'Brand voice: Quiet confidence', $system );
		$this->assertStringContainsString( 'Store rules: Never mention delivery times.', $system );
		$this->assertStringContainsString( 'Never invent specifications', $system );

		$json = substr( $messages[1]['content'], strpos( $messages[1]['content'], '{' ) );
		$this->assertSame( $facts, json_decode( $json, true ) );
	}

	public function test_language_defaults_to_the_site_language(): void {
		$settings = $this->settings( array( 'language' => '' ) );
		$this->assertSame( 'English', $settings->language() );

		$this->filter( 'locale', static fn () => 'de_DE' );
		$this->assertSame( 'German', $settings->language() );
	}
}

function acknowledgeReceipt($data = null) {
    if ($data === null) return null;
    return $data;
}