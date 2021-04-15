<?php
/**
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Tests\Integration;

use WooAIProductWriter\Content\Generator;
use WooAIProductWriter\Content\InvalidCopyException;
use WooAIProductWriter\Meta;
use WooAIProductWriter\Plugin;
use WooAIProductWriter\Usage;
use WooAIProductWriter\Tests\Support\TestCase;

final class GeneratorTest extends TestCase {

	private function generator(): Generator {
		return Plugin::instance()->generator;
	}

	public function test_generates_and_stores_a_suggestion(): void {
		$this->login();
		$product = $this->product();
		$this->http->push_completion( $this->good_copy() );

		$suggestion = $this->generator()->generate( $product, 'Hand-waxed in Leeds.' );

		$this->assertSame( 'Waxed Canvas Tote Bag with Leather Handles', $suggestion['meta_title'] );
		$this->assertSame( 'gpt-4.1-mini-2025-04-14', $suggestion['meta']['model'] );
		$this->assertSame( 'manual', $suggestion['meta']['source'] );
		$this->assertSame( 730, $suggestion['meta']['usage']['total_tokens'] );

		$this->assertSame( $suggestion, Generator::suggestion( $product->get_id() ) );
		$this->assertSame( 'ready', get_post_meta( $product->get_id(), Meta::STATUS, true ) );
		$this->assertSame( 'Hand-waxed in Leeds.', get_post_meta( $product->get_id(), Meta::NOTES, true ) );

		// Nothing is written to the product until it's applied.
		$this->assertSame( '', wc_get_product( $product->get_id() )->get_description() );

		$prompt = $this->http->requests[0]['body']['messages'][1]['content'];
		$this->assertStringContainsString( '"merchant_notes": "Hand-waxed in Leeds."', $prompt );
	}

	public function test_saved_notes_are_reused_when_none_are_given(): void {
		$product = $this->product();
		update_post_meta( $product->get_id(), Meta::NOTES, 'Ships flat.' );
		$this->http->push_completion( $this->good_copy() );

		$this->generator()->generate( $product, null, 'bulk' );

		$this->assertStringContainsString( 'Ships flat.', $this->http->requests[0]['body']['messages'][1]['content'] );
	}

	public function test_usage_is_counted_per_month(): void {
		$this->http->push_completion( $this->good_copy() )->push_completion( $this->good_copy() );
		$product = $this->product();

		$this->generator()->generate( $product );
		$this->generator()->generate( $product );

		$month = ( new Usage() )->all()[ gmdate( 'Y-m' ) ];
		$this->assertSame( 2, $month['requests'] );
		$this->assertSame( 820, $month['prompt_tokens'] );
		$this->assertSame( 640, $month['completion_tokens'] );
	}

	public function test_an_unusable_answer_gets_one_repair_round(): void {
		$this->http
			->push_completion( 'Here is a lovely description of the tote bag!' )
			->push_completion( $this->good_copy() );

		$suggestion = $this->generator()->generate( $this->product() );

		$this->assertSame( 'waxed canvas tote bag', $suggestion['focus_keyword'] );
		$this->assertSame( 2, $suggestion['meta']['attempts'] );
		$this->assertSame( 1460, $suggestion['meta']['usage']['total_tokens'] );

		$repair = $this->http->requests[1]['body']['messages'];
		$this->assertCount( 4, $repair );
		$this->assertSame( 'assistant', $repair[2]['role'] );
		$this->assertSame( 'Here is a lovely description of the tote bag!', $repair[2]['content'] );
		$this->assertStringContainsString( 'wasn\'t JSON', $repair[3]['content'] );
	}

	public function test_gives_up_after_the_repair_round(): void {
		$this->http
			->push_completion( array( 'description_html' => '<p>Only this</p>' ) )
			->push_completion( array( 'description_html' => '<p>Still only this</p>' ) );

		$this->expectException( InvalidCopyException::class );
		$this->generator()->generate( $this->product() );
	}

	public function test_generated_copy_filter_can_change_the_result(): void {
		$this->filter(
			'wapw_generated_copy',
			static fn ( $copy ) => \WooAIProductWriter\Content\GeneratedCopy::from_array( array( 'meta_title' => 'Filtered' ) + $copy->to_array() )
		);
		$this->http->push_completion( $this->good_copy() );

		$this->assertSame( 'Filtered', $this->generator()->generate( $this->product() )['meta_title'] );
	}
}

function queueMessage($data = null) {
    if ($data === null) return null;
    return $data;
}