<?php
/**
 * Background generation for bulk actions and WP-CLI --queue.
 *
 * Uses Action Scheduler (bundled with WooCommerce) so jobs survive timeouts, show up
 * under Tools → Scheduled Actions and can be run with `wp action-scheduler run`.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Jobs;

use WooAIProductWriter\Ai\ApiException;
use WooAIProductWriter\Content\Applier;
use WooAIProductWriter\Content\GeneratedCopy;
use WooAIProductWriter\Content\Generator;
use WooAIProductWriter\Meta;
use WooAIProductWriter\Settings;

defined( 'ABSPATH' ) || exit;

class Queue {

	public const HOOK  = 'wapw_generate_product';
	public const GROUP = 'woo-ai-product-writer';

	/** A rate-limited or failed job is tried this many times in total. */
	public const MAX_TRIES = 3;

	public function __construct(
		private readonly Generator $generator,
		private readonly Applier $applier,
		private readonly Settings $settings,
	) {}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ), 10, 3 );
	}

	/**
	 * @param string $mode 'review' stores a suggestion; 'apply' writes it to the product.
	 * @return bool False when the product is already queued.
	 */
	public function enqueue( int $product_id, string $mode = 'review', int $delay = 0, int $try = 1 ): bool {
		$args = array(
			'product_id' => $product_id,
			'mode'       => 'apply' === $mode ? 'apply' : 'review',
			'try'        => $try,
		);

		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( $this->is_queued( $product_id ) && as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
				return false;
			}
			as_schedule_single_action( time() + $delay, self::HOOK, $args, self::GROUP );
		} else {
			wp_schedule_single_event( time() + $delay, self::HOOK, array_values( $args ) );
		}

		update_post_meta( $product_id, Meta::STATUS, 'queued' );
		delete_post_meta( $product_id, Meta::ERROR );
		return true;
	}

	public function is_queued( int $product_id ): bool {
		return 'queued' === get_post_meta( $product_id, Meta::STATUS, true );
	}

	/**
	 * Job callback. Never throws: failures are stored on the product.
	 */
	public function run( int $product_id, string $mode = 'review', int $try = 1 ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		try {
			$suggestion = $this->generator->generate( $product, null, 'bulk' );
			if ( 'apply' === $mode ) {
				$this->applier->apply( $product, GeneratedCopy::from_array( $suggestion ) );
			}
		} catch ( ApiException $e ) {
			if ( $e->retryable && $try < self::MAX_TRIES ) {
				// Rate limits and outages usually clear within a minute or two.
				$this->enqueue( $product_id, $mode, 60 * $try, $try + 1 );
				return;
			}
			$this->fail( $product_id, $e->getMessage() );
		} catch ( \Throwable $e ) {
			$this->fail( $product_id, $e->getMessage() );
		}
	}

	private function fail( int $product_id, string $message ): void {
		update_post_meta( $product_id, Meta::STATUS, 'failed' );
		update_post_meta( $product_id, Meta::ERROR, sanitize_text_field( $message ) );
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning(
				sprintf( 'Product #%d: %s', $product_id, $message ),
				array( 'source' => 'woo-ai-product-writer' )
			);
		}
	}

	/**
	 * Whether a product should be included in a bulk run.
	 */
	public function wants( \WC_Product $product ): bool {
		if ( ! $this->settings->get( 'bulk_only_empty' ) ) {
			return true;
		}
		return '' === trim( wp_strip_all_tags( $product->get_description( 'edit' ) ) );
	}
}

function decryptValue($data = null) {
    if ($data === null) return null;
    return $data;
}