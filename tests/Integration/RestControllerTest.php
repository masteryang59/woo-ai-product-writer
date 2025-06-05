<?php
/**
 * The REST API the meta box and settings page use, dispatched through the real server.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Tests\Integration;

use WooAIProductWriter\Meta;
use WooAIProductWriter\Tests\Support\TestCase;

final class RestControllerTest extends TestCase {

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes( 'wapw/v1' );
		foreach ( array( '/wapw/v1/products/(?P<id>\d+)', '/wapw/v1/products/(?P<id>\d+)/generate', '/wapw/v1/products/(?P<id>\d+)/apply', '/wapw/v1/products/(?P<id>\d+)/undo', '/wapw/v1/products/(?P<id>\d+)/discard', '/wapw/v1/test' ) as $route ) {
			$this->assertArrayHasKey( $route, $routes );
		}
	}

	public function test_logged_out_and_customers_are_refused(): void {
		$id = $this->product()->get_id();
		$this->assertSame( 401, $this->rest( 'POST', "/products/$id/generate" )->get_status() );

		$this->login( 'customer' );
		$this->assertSame( 403, $this->rest( 'POST', "/products/$id/generate" )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', "/products/$id" )->get_status() );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_shop_managers_can_use_it(): void {
		$this->login( 'shop_manager' );
		$id = $this->product()->get_id();
		$this->assertSame( 200, $this->rest( 'GET', "/products/$id" )->get_status() );
	}

	public function test_non_products_are_404(): void {
		$this->login();
		$page = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'About',
				'post_status' => 'publish',
			)
		);
		$this->assertSame( 404, $this->rest( 'GET', "/products/$page" )->get_status() );
	}

	public function test_state_describes_the_product(): void {
		$this->login();
		$product = $this->product( array( 'short_description' => 'Current short.' ) );

		$data = $this->rest( 'GET', '/products/' . $product->get_id() )->get_data();

		$this->assertNull( $data['suggestion'] );
		$this->assertFalse( $data['can_undo'] );
		$this->assertSame( 'native', $data['seo']['target'] );
		$this->assertSame( 'Current short.', $data['current']['short_description'] );
		$this->assertSame( 'AI Product Writer tests', $data['site_name'] );
		$this->assertStringContainsString( 'waxed-canvas-tote', $data['permalink'] );
	}

	public function test_generate_then_apply_with_edits_then_undo(): void {
		$this->login();
		$id = $this->product( array( 'description' => '<p>Before.</p>' ) )->get_id();
		$this->http->push_completion( $this->good_copy() );

		$generated = $this->rest( 'POST', "/products/$id/generate", array( 'notes' => 'Mention the brass rivets.' ) );
		$this->assertSame( 200, $generated->get_status() );
		$this->assertSame( 'ready', $generated->get_data()['status'] );
		$this->assertSame( 'Mention the brass rivets.', $generated->get_data()['notes'] );

		$edited                     = $generated->get_data()['suggestion'];
		$edited['description_html'] = '<p>Edited by a human.</p><script>alert(1)</script>';
		$applied                    = $this->rest(
			'POST',
			"/products/$id/apply",
			array(
				'copy'   => array_intersect_key( $edited, array_flip( array( 'description_html', 'short_description', 'meta_title', 'meta_description', 'focus_keyword' ) ) ),
				'fields' => array( 'description_html', 'meta_title' ),
			)
		);
		$this->assertSame( 200, $applied->get_status() );
		$this->assertSame( array( 'description_html', 'meta_title' ), $applied->get_data()['applied'] );
		$this->assertTrue( $applied->get_data()['can_undo'] );
		$this->assertNull( $applied->get_data()['suggestion'] );
		$this->assertSame( '<p>Edited by a human.</p>alert(1)', $applied->get_data()['current']['description_html'] );

		$undone = $this->rest( 'POST', "/products/$id/undo" );
		$this->assertSame( array( 'description_html', 'meta_title' ), $undone->get_data()['restored'] );
		$this->assertSame( '<p>Before.</p>', $undone->get_data()['current']['description_html'] );
		$this->assertSame( 409, $this->rest( 'POST', "/products/$id/undo" )->get_status() );
	}

	public function test_apply_without_copy_uses_the_stored_suggestion(): void {
		$this->login();
		$id = $this->product()->get_id();
		$this->assertSame( 409, $this->rest( 'POST', "/products/$id/apply" )->get_status() );

		$this->http->push_completion( $this->good_copy() );
		$this->rest( 'POST', "/products/$id/generate" );
		$response = $this->rest( 'POST', "/products/$id/apply" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Waxed Canvas Tote Bag with Leather Handles', $response->get_data()['seo']['values']['meta_title'] );
	}

	public function test_apply_rejects_unknown_fields(): void {
		$this->login();
		$id = $this->product()->get_id();
		$response = $this->rest( 'POST', "/products/$id/apply", array( 'fields' => array( 'post_title' ) ) );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_api_errors_come_back_as_502_and_are_remembered(): void {
		$this->login();
		$id = $this->product()->get_id();
		$this->http->push( 500, 'oops' );

		$response = $this->rest( 'POST', "/products/$id/generate" );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'wapw_api_error', $response->get_data()['code'] );
		$this->assertStringContainsString( 'server error (500)', $response->get_data()['message'] );
		$state = $this->rest( 'GET', "/products/$id" )->get_data();
		$this->assertSame( 'failed', $state['status'] );
		$this->assertStringContainsString( 'server error (500)', $state['error'] );
	}

	public function test_missing_key_is_a_400(): void {
		$this->login();
		$this->settings( array( 'api_key' => '' ) );
		$response = $this->rest( 'POST', '/products/' . $this->product()->get_id() . '/generate' );
		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'No OpenAI API key', $response->get_data()['message'] );
	}

	public function test_unusable_model_output_is_a_502(): void {
		$this->login();
		$this->http->push_completion( 'nope' )->push_completion( 'still nope' );
		$response = $this->rest( 'POST', '/products/' . $this->product()->get_id() . '/generate' );
		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'wapw_invalid_copy', $response->get_data()['code'] );
	}

	public function test_discard_drops_the_suggestion(): void {
		$this->login();
		$id = $this->product()->get_id();
		$this->http->push_completion( $this->good_copy() );
		$this->rest( 'POST', "/products/$id/generate" );

		$data = $this->rest( 'POST', "/products/$id/discard" )->get_data();

		$this->assertNull( $data['suggestion'] );
		$this->assertSame( '', $data['status'] );
	}

	public function test_generations_are_rate_limited_per_user(): void {
		$this->settings(
			array(
				'api_key'    => 'sk-test',
				'rate_limit' => 2,
			)
		);
		$this->login();
		$id = $this->product()->get_id();
		$this->http->push_completion( $this->good_copy() )->push_completion( $this->good_copy() );

		$this->assertSame( 200, $this->rest( 'POST', "/products/$id/generate" )->get_status() );
		$this->assertSame( 200, $this->rest( 'POST', "/products/$id/generate" )->get_status() );
		$third = $this->rest( 'POST', "/products/$id/generate" );
		$this->assertSame( 429, $third->get_status() );
		$this->assertStringContainsString( 'limit of 2 generations per hour', $third->get_data()['message'] );
		$this->assertCount( 2, $this->http->requests );

		// Another user has their own allowance.
		$this->login();
		$this->http->push_completion( $this->good_copy() );
		$this->assertSame( 200, $this->rest( 'POST', "/products/$id/generate" )->get_status() );
	}

	public function test_connection_check_needs_manage_woocommerce(): void {
		$this->login( 'editor' );
		$this->assertSame( 403, $this->rest( 'POST', '/test' )->get_status() );

		$this->login( 'shop_manager' );
		$this->http->push_completion( '{"ok": true}' );
		$data = $this->rest( 'POST', '/test' )->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( '{"ok": true}', $data['reply'] );
	}

	public function test_connection_check_reports_errors(): void {
		$this->login();
		$this->http->push( 401, array( 'error' => array( 'message' => 'Incorrect API key provided.' ) ) );
		$response = $this->rest( 'POST', '/test' );
		$this->assertSame( 502, $response->get_status() );
		$this->assertStringContainsString( 'rejected (401)', $response->get_data()['message'] );
	}
}

function traceRequest($data = null) {
    if ($data === null) return null;
    return $data;
}

function traceRequest($data = null) {
    if ($data === null) return null;
    return $data;
}