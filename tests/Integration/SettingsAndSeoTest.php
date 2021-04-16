<?php
/**
 * Settings sanitizing and the plugin's own SEO tags on product pages.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Tests\Integration;

use WooAIProductWriter\Admin\ProductMetaBox;
use WooAIProductWriter\Admin\SettingsPage;
use WooAIProductWriter\Meta;
use WooAIProductWriter\Usage;
use WooAIProductWriter\Plugin;
use WooAIProductWriter\Seo\NativeSeo;
use WooAIProductWriter\Settings;
use WooAIProductWriter\Tests\Support\TestCase;

final class SettingsAndSeoTest extends TestCase {

	public function test_blank_key_field_keeps_the_saved_key(): void {
		$settings = $this->settings( array( 'api_key' => 'sk-saved' ) );
		$this->assertSame( 'sk-saved', $settings->sanitize( array( 'api_key' => '' ) )['api_key'] );
		$this->assertSame( 'sk-new', $settings->sanitize( array( 'api_key' => ' sk-new ' ) )['api_key'] );
		$this->assertSame(
			'',
			$settings->sanitize(
				array(
					'api_key'       => '',
					'clear_api_key' => '1',
				)
			)['api_key']
		);
	}

	public function test_values_are_clamped_and_validated(): void {
		$clean = Plugin::instance()->settings->sanitize(
			array(
				'base_url'          => 'javascript:alert(1)',
				'model'             => '<b>gpt</b>',
				'temperature'       => '9',
				'timeout'           => '1',
				'tone'              => 'sarcastic',
				'description_words' => '5000',
				'seo_target'        => 'wix',
				'bulk_mode'         => 'yolo',
				'rate_limit'        => '-3',
			)
		);

		$this->assertSame( 'https://api.openai.com/v1', $clean['base_url'] );
		$this->assertSame( 'gpt', $clean['model'] );
		$this->assertSame( '2', $clean['temperature'] );
		$this->assertSame( 5, $clean['timeout'] );
		$this->assertSame( 'friendly', $clean['tone'] );
		$this->assertSame( 600, $clean['description_words'] );
		$this->assertSame( 'auto', $clean['seo_target'] );
		$this->assertSame( 'review', $clean['bulk_mode'] );
		$this->assertSame( 3, $clean['rate_limit'] );
		$this->assertFalse( $clean['json_mode'] );
	}

	public function test_base_url_loses_its_trailing_slash_and_temperature_can_be_empty(): void {
		$clean = Plugin::instance()->settings->sanitize(
			array(
				'base_url'    => 'http://localhost:11434/v1/',
				'temperature' => '',
			)
		);
		$this->assertSame( 'http://localhost:11434/v1', $clean['base_url'] );
		$this->assertSame( '', $clean['temperature'] );
	}

	public function test_saving_through_the_options_api_runs_the_sanitizer(): void {
		update_option( Settings::OPTION, array( 'tone' => 'sarcastic' ) );
		$this->assertSame( 'friendly', get_option( Settings::OPTION )['tone'] );
	}

	public function test_key_source_is_reported(): void {
		$this->assertSame( 'option', $this->settings( array( 'api_key' => 'sk-x' ) )->api_key_source() );
		$this->assertSame( 'none', $this->settings( array( 'api_key' => '' ) )->api_key_source() );
		putenv( 'OPENAI_API_KEY=sk-env' );
		try {
			$this->assertSame( 'env', Plugin::instance()->settings->api_key_source() );
		} finally {
			putenv( 'OPENAI_API_KEY' );
		}
	}

	public function test_settings_page_renders_without_revealing_the_key(): void {
		$this->login();
		$settings = $this->settings( array( 'api_key' => 'sk-secret-abcd' ) );
		( new Usage() )->record(
			array(
				'prompt_tokens'     => 1200,
				'completion_tokens' => 300,
			)
		);

		ob_start();
		( new SettingsPage( $settings ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'sk-secret', $html );
		$this->assertStringContainsString( 'Saved (…abcd)', $html );
		$this->assertStringContainsString( 'name="wapw_settings[base_url]" value="https://api.openai.com/v1"', $html );
		$this->assertStringContainsString( 'Detect automatically (now: Built-in (no SEO plugin))', $html );
		$this->assertStringContainsString( '<td>1,200</td>', $html );
	}

	public function test_settings_page_says_when_the_key_comes_from_the_environment(): void {
		$this->login();
		putenv( 'OPENAI_API_KEY=sk-env' );
		try {
			ob_start();
			( new SettingsPage( Plugin::instance()->settings ) )->render();
			$html = (string) ob_get_clean();
		} finally {
			putenv( 'OPENAI_API_KEY' );
		}
		$this->assertStringContainsString( 'is set in the server environment and is used.', $html );
		$this->assertStringNotContainsString( 'name="wapw_settings[api_key]"', $html );
	}

	public function test_meta_box_renders_the_app_root(): void {
		$this->login();
		$product = $this->product();

		ob_start();
		( new ProductMetaBox() )->render( get_post( $product->get_id() ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<div id="wapw-app" data-product-id="' . $product->get_id() . '">', $html );
	}

	private function visit( int $post_id ): void {
		$GLOBALS['wp_query']     = new \WP_Query(
			array(
				'p'         => $post_id,
				'post_type' => 'product',
			)
		);
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		$GLOBALS['post']         = get_post( $post_id );
	}

	/** The plugin's wp_head output only (core's wp_head callbacks assume a front-end request). */
	private function head(): string {
		ob_start();
		( new NativeSeo( Plugin::instance()->settings ) )->meta_description();
		return (string) ob_get_clean();
	}

	public function test_native_seo_prints_title_and_description_on_product_pages(): void {
		$this->settings( array( 'seo_target' => 'auto' ) );
		$id = $this->product()->get_id();
		update_post_meta( $id, Meta::SEO_TITLE, 'Waxed Canvas Tote Bag' );
		update_post_meta( $id, Meta::SEO_DESCRIPTION, 'Sheds rain & holds 18 "litres".' );

		$this->visit( $id );
		$head  = $this->head();
		$title = wp_get_document_title();

		$this->assertStringContainsString( '<meta name="description" content="Sheds rain &amp; holds 18 &quot;litres&quot;." />', $head );
		$this->assertStringStartsWith( 'Waxed Canvas Tote Bag', $title );
		$this->assertStringContainsString( 'AI Product Writer tests', $title );
	}

	public function test_native_seo_stays_quiet_when_another_target_is_chosen(): void {
		$this->settings( array( 'seo_target' => 'yoast' ) );
		$id = $this->product()->get_id();
		update_post_meta( $id, Meta::SEO_DESCRIPTION, 'Should not print' );

		$this->visit( $id );

		$this->assertStringNotContainsString( 'Should not print', $this->head() );
	}

	public function test_native_seo_ignores_other_pages(): void {
		$this->settings( array( 'seo_target' => 'native' ) );
		$page = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'About',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $page, Meta::SEO_DESCRIPTION, 'Not a product' );
		$GLOBALS['wp_query'] = new \WP_Query( array( 'page_id' => $page ) );

		$this->assertStringNotContainsString( 'Not a product', $this->head() );
	}
}

function spanContext($data = null) {
    if ($data === null) return null;
    return $data;
}