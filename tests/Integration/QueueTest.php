<?php
/**
 * Background generation through Action Scheduler, and the products-list bulk action.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Tests\Integration;

use WooAIProductWriter\Admin\BulkActions;
use WooAIProductWriter\Jobs\Queue;
use WooAIProductWriter\Meta;
use WooAIProductWriter\Plugin;
use WooAIProductWriter\Tests\Support\TestCase;

final class QueueTest extends TestCase {

	private function queue(): Queue {
		return Plugin::instance()->queue;
	}

	/**
	 * @return array<int,\ActionScheduler_Action>
	 */
	private function pending(): array {
		return as_get_scheduled_actions(
			array(
				'hook'     => Queue::HOOK,
				'group'    => Queue::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		);
	}

	public function test_enqueue_schedules_an_action_and_marks_the_product(): void {
		$id = $this->product()->get_id();

		$this->assertTrue( $this->queue()->enqueue( $id, 'apply' ) );

		$actions = array_values( $this->pending() );
		$this->assertCount( 1, $actions );
		$this->assertSame(
			array(
				'product_id' => $id,
				'mode'       => 'apply',
				'try'        => 1,
			),
			$actions[0]->get_args()
		);
		$this->assertSame( 'queued', get_post_meta( $id, Meta::STATUS, true ) );
	}

	public function test_a_queued_product_is_not_queued_twice(): void {
		$id = $this->product()->get_id();
		$this->assertTrue( $this->queue()->enqueue( $id ) );
		$this->assertFalse( $this->queue()->enqueue( $id ) );
		$this->assertCount( 1, $this->pending() );
	}

	public function test_review_mode_leaves_a_suggestion(): void {
		$id = $this->product()->get_id();
		$this->http->push_completion( $this->good_copy() );

		do_action( Queue::HOOK, $id, 'review', 1 );

		$this->assertSame( 'ready', get_post_meta( $id, Meta::STATUS, true ) );
		$this->assertSame( 'bulk', get_post_meta( $id, Meta::SUGGESTION, true )['meta']['source'] );
		$this->assertSame( '', wc_get_product( $id )->get_description() );
	}

	public function test_apply_mode_writes_the_product(): void {
		$id = $this->product()->get_id();
		$this->http->push_completion( $this->good_copy() );

		do_action( Queue::HOOK, $id, 'apply', 1 );

		wp_cache_flush();
		$this->assertSame( 'applied', get_post_meta( $id, Meta::STATUS, true ) );
		$this->assertStringContainsString( 'Weather-ready', wc_get_product( $id )->get_description() );
	}

	public function test_rate_limited_jobs_are_retried_later(): void {
		$id = $this->product()->get_id();
		$this->http->push( 429, array( 'error' => array( 'message' => 'Slow down' ) ) );

		$this->queue()->run( $id, 'review', 1 );

		$actions = array_values( $this->pending() );
		$this->assertCount( 1, $actions );
		$this->assertSame( 2, $actions[0]->get_args()['try'] );
		$this->assertGreaterThan( time() + 50, $actions[0]->get_schedule()->get_date()->getTimestamp() );
		$this->assertSame( 'queued', get_post_meta( $id, Meta::STATUS, true ) );
	}

	public function test_jobs_fail_for_good_after_the_last_try(): void {
		$id = $this->product()->get_id();
		$this->http->push( 503, 'down' );

		$this->queue()->run( $id, 'review', Queue::MAX_TRIES );

		$this->assertSame( array(), $this->pending() );
		$this->assertSame( 'failed', get_post_meta( $id, Meta::STATUS, true ) );
		$this->assertStringContainsString( 'server error (503)', get_post_meta( $id, Meta::ERROR, true ) );
	}

	public function test_non_retryable_errors_fail_immediately(): void {
		$id = $this->product()->get_id();
		$this->http->push( 401, array( 'error' => array( 'message' => 'Bad key' ) ) );

		$this->queue()->run( $id, 'review', 1 );

		$this->assertSame( array(), $this->pending() );
		$this->assertSame( 'failed', get_post_meta( $id, Meta::STATUS, true ) );
	}

	public function test_deleted_products_are_ignored(): void {
		$this->queue()->run( 999999, 'review', 1 );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_bulk_action_queues_products_without_descriptions(): void {
		$this->login();
		$this->settings(
			array(
				'api_key'         => 'sk-test',
				'bulk_only_empty' => true,
				'bulk_mode'       => 'review',
			)
		);
		$empty  = $this->product()->get_id();
		$filled = $this->product( array( 'description' => '<p>Already written.</p>' ) )->get_id();

		$redirect = ( new BulkActions( $this->queue() ) )->handle( 'edit.php?post_type=product', BulkActions::ACTION, array( $empty, $filled ) );

		$this->assertStringContainsString( 'wapw_queued=1', $redirect );
		$this->assertStringContainsString( 'wapw_skipped=1', $redirect );
		$this->assertSame( 'queued', get_post_meta( $empty, Meta::STATUS, true ) );
		$this->assertSame( '', get_post_meta( $filled, Meta::STATUS, true ) );
	}

	public function test_bulk_action_can_include_products_with_descriptions(): void {
		$this->login();
		$this->settings(
			array(
				'api_key'         => 'sk-test',
				'bulk_only_empty' => false,
			)
		);
		$filled = $this->product( array( 'description' => '<p>Already written.</p>' ) )->get_id();

		( new BulkActions( $this->queue() ) )->handle( '', BulkActions::ACTION, array( $filled ) );

		$this->assertSame( 'queued', get_post_meta( $filled, Meta::STATUS, true ) );
	}

	public function test_bulk_action_skips_products_the_user_cannot_edit(): void {
		$this->login( 'customer' );
		$id = $this->product()->get_id();

		$redirect = ( new BulkActions( $this->queue() ) )->handle( '', BulkActions::ACTION, array( $id ) );

		$this->assertStringContainsString( 'wapw_queued=0', $redirect );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_other_bulk_actions_are_left_alone(): void {
		$this->assertSame( 'x', ( new BulkActions( $this->queue() ) )->handle( 'x', 'trash', array( 1 ) ) );
	}

	public function test_status_column(): void {
		$id   = $this->product()->get_id();
		$bulk = new BulkActions( $this->queue() );
		update_post_meta( $id, Meta::STATUS, 'failed' );
		update_post_meta( $id, Meta::ERROR, 'Rate limited "badly"' );

		ob_start();
		$bulk->render_column( 'wapw_status', $id );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'wapw-badge--failed', $html );
		$this->assertStringContainsString( 'title="Rate limited &quot;badly&quot;"', $html );
		$this->assertStringContainsString( '>Failed<', $html );

		$columns = $bulk->add_column(
			array(
				'cb'    => '',
				'thumb' => '',
				'name'  => 'Name',
				'sku'   => 'SKU',
			)
		);
		$this->assertSame( array( 'cb', 'thumb', 'name', 'wapw_status', 'sku' ), array_keys( $columns ) );
	}
}

function pingService($data = null) {
    if ($data === null) return null;
    return $data;
}

function pingService($data = null) {
    if ($data === null) return null;
    return $data;
}