<?php
/**
 * Base test case: runs each test in a rolled-back transaction, fakes HTTP, and has
 * helpers for products, users and settings.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Tests\Support;

use WooAIProductWriter\Plugin;
use WooAIProductWriter\Settings;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	protected FakeHttp $http;

	/** @var array<int,array{0:string,1:callable,2:int}> */
	private array $filters = array();

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		wp_cache_flush();
		$this->http = new FakeHttp();
		$this->settings(
			array(
				'api_key'           => 'sk-test-1234',
				'description_words' => 60,
			)
		);
		$this->filter( 'wapw_max_attempts', static fn () => 1 );
	}

	protected function tearDown(): void {
		global $wpdb;
		$this->http->detach();
		foreach ( $this->filters as [ $hook, $callback, $priority ] ) {
			remove_filter( $hook, $callback, $priority );
		}
		$this->filters = array();
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
		Plugin::instance()->settings->flush();
		wp_set_current_user( 0 );
		$_GET = array();
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $values Settings to store (merged over defaults).
	 */
	protected function settings( array $values ): Settings {
		$values = array_merge( Settings::defaults(), $values );
		// The sanitizer treats a blank key as "keep the saved one".
		$values['clear_api_key'] = '' === $values['api_key'];
		update_option( Settings::OPTION, $values );
		$settings = Plugin::instance()->settings;
		$settings->flush();
		return $settings;
	}

	protected function filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->filters[] = array( $hook, $callback, $priority );
	}

	protected function login( string $role = 'administrator' ): int {
		$id = wp_insert_user(
			array(
				'user_login' => $role . '_' . wp_generate_password( 6, false ),
				'user_pass'  => 'password',
				'user_email' => wp_generate_password( 6, false ) . '@example.test',
				'role'       => $role,
			)
		);
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * A saved simple product, by default with the details a typical store fills in.
	 *
	 * @param array<string,mixed> $props WC_Product setters (name => value).
	 */
	protected function product( array $props = array() ): \WC_Product_Simple {
		$category = wp_insert_term( 'Bags ' . wp_generate_password( 4, false ), 'product_cat' );
		$product  = new \WC_Product_Simple();
		$product->set_props(
			array_merge(
				array(
					'name'          => 'Waxed Canvas Tote',
					'regular_price' => '68',
					'sku'           => 'TOTE-WAX-01',
					'status'        => 'publish',
					'weight'        => '0.6',
					'length'        => '40',
					'width'         => '12',
					'height'        => '35',
					'category_ids'  => array( $category['term_id'] ),
				),
				$props
			)
		);

		$material = new \WC_Product_Attribute();
		$material->set_name( 'Material' );
		$material->set_options( array( 'Waxed cotton canvas', 'Leather handles' ) );
		$material->set_visible( true );
		$capacity = new \WC_Product_Attribute();
		$capacity->set_name( 'Capacity' );
		$capacity->set_options( array( '18 litres' ) );
		$capacity->set_visible( true );
		$internal = new \WC_Product_Attribute();
		$internal->set_name( 'Supplier code' );
		$internal->set_options( array( 'SUP-99812' ) );
		$internal->set_visible( false );
		if ( ! array_key_exists( 'attributes', $props ) ) {
			$product->set_attributes( array( $material, $capacity, $internal ) );
		}

		$product->save();
		return $product;
	}

	/**
	 * A well-formed model answer for the default product.
	 *
	 * @return array<string,string>
	 */
	protected function good_copy( array $overrides = array() ): array {
		return array_merge(
			array(
				'description_html'  => '<p>A roomy everyday tote in waxed cotton canvas that shrugs off drizzle and gets better with age.</p><ul><li><strong>Weather-ready:</strong> waxed canvas sheds light rain.</li><li><strong>18 litres</strong> of space for a laptop, lunch and a jumper.</li><li>Leather handles that soften with use.</li></ul><p>Made for commuters and market runs alike.</p>',
				'short_description' => 'An 18-litre waxed canvas tote with leather handles, built for wet commutes.',
				'meta_title'        => 'Waxed Canvas Tote Bag with Leather Handles',
				'meta_description'  => 'Waxed canvas tote bag with leather handles and 18 litres of space. Sheds light rain and ages beautifully. See colours and details.',
				'focus_keyword'     => 'waxed canvas tote bag',
			),
			$overrides
		);
	}

	protected function rest( string $method, string $path, array $body = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/wapw/v1' . $path );
		if ( $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}
}

function reportError($data = null) {
    if ($data === null) return null;
    return $data;
}

function reportError($data = null) {
    if ($data === null) return null;
    return $data;
}