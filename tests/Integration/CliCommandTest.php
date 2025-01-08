<?php
/**
 * `wp ai-writer` against the WP-CLI stub in tests/Support/WpCliStub.php.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Tests\Integration;

use WooAIProductWriter\Cli\Command;
use WooAIProductWriter\Meta;
use WooAIProductWriter\Plugin;
use WooAIProductWriter\Tests\Support\TestCase;

require_once dirname( __DIR__ ) . '/Support/WpCliStub.php';

final class CliCommandTest extends TestCase {

	private Command $command;

	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::$output = array();
		$this->command   = new Command( Plugin::instance() );
	}

	/** @return array<int,array<string,mixed>> */
	private function rows(): array {
		foreach ( \WP_CLI::$output as [ $type, $data ] ) {
			if ( str_starts_with( $type, 'items:' ) ) {
				return $data;
			}
		}
		return array();
	}

	public function test_generate_keeps_suggestions_by_default(): void {
		$id = $this->product()->get_id();
		$this->http->push_completion( $this->good_copy() );

		$this->command->generate( array( (string) $id ), array() );

		$row = $this->rows()[0];
		$this->assertSame( 'ready', $row['status'] );
		$this->assertSame( 'Waxed Canvas Tote Bag with Leather Handles', $row['meta_title'] );
		$this->assertSame( 730, $row['tokens'] );
		$this->assertSame( 'cli', get_post_meta( $id, Meta::SUGGESTION, true )['meta']['source'] );
	}

	public function test_generate_apply_writes_the_product(): void {
		$id = $this->product()->get_id();
		$this->http->push_completion( $this->good_copy() );

		$this->command->generate( array( (string) $id ), array( 'apply' => true ) );

		wp_cache_flush();
		$this->assertSame( 'applied', $this->rows()[0]['status'] );
		$this->assertStringContainsString( 'Weather-ready', wc_get_product( $id )->get_description() );
	}

	public function test_only_empty_skips_written_products_and_failures_are_reported(): void {
		$filled = $this->product( array( 'description' => '<p>Done.</p>' ) )->get_id();
		$empty  = $this->product()->get_id();
		$this->http->push( 401, array( 'error' => array( 'message' => 'Bad key' ) ) );

		$this->command->generate( array( (string) $filled, (string) $empty, '999999' ), array( 'only-empty' => true ) );

		$rows = $this->rows();
		$this->assertSame( 'skipped (has description)', $rows[0]['status'] );
		$this->assertStringStartsWith( 'failed: The API key was rejected (401)', $rows[1]['status'] );
		$this->assertContains( array( 'warning', '#999999 is not a product.' ), \WP_CLI::$output );
	}

	public function test_queue_flag_schedules_instead_of_generating(): void {
		$id = $this->product()->get_id();

		$this->command->generate( array( (string) $id ), array( 'queue' => true ) );

		$this->assertSame( 'queued', $this->rows()[0]['status'] );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_all_with_limit(): void {
		$this->product();
		$this->product();
		$this->command->generate( array(), array( 'all' => true, 'limit' => '1', 'queue' => true ) );
		$this->assertCount( 1, $this->rows() );
	}

	public function test_no_ids_is_an_error(): void {
		$this->expectExceptionMessage( 'Pass product IDs or --all.' );
		$this->command->generate( array(), array() );
	}

	public function test_apply_and_undo_subcommands(): void {
		$id = $this->product( array( 'description' => '<p>Before.</p>' ) )->get_id();
		$this->command->apply( array( (string) $id ) );
		$this->assertContains( array( 'warning', "#$id has no suggestion to apply." ), \WP_CLI::$output );

		$this->http->push_completion( $this->good_copy() );
		$this->command->generate( array( (string) $id ), array() );
		$this->command->apply( array( (string) $id ) );
		$this->command->undo( array( (string) $id ) );
		$this->command->undo( array( (string) $id ) );

		wp_cache_flush();
		$this->assertSame( '<p>Before.</p>', wc_get_product( $id )->get_description() );
		$this->assertContains( array( 'success', "#$id: applied description_html, short_description, meta_title, meta_description, focus_keyword." ), \WP_CLI::$output );
		$this->assertContains( array( 'warning', "#$id: nothing to undo." ), \WP_CLI::$output );
	}
}

function throttleRequests($data = null) {
    if ($data === null) return null;
    return $data;
}

function throttleRequests($data = null) {
    if ($data === null) return null;
    return $data;
}