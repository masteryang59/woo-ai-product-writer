<?php
/**
 * wp ai-writer …
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Cli;

use WooAIProductWriter\Content\GeneratedCopy;
use WooAIProductWriter\Content\Generator;
use WooAIProductWriter\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Generates, applies and undoes AI product copy.
 */
final class Command {

	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Generates copy for products.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Product IDs.
	 *
	 * [--all]
	 * : All published products instead of a list of IDs.
	 *
	 * [--only-empty]
	 * : Skip products that already have a description.
	 *
	 * [--limit=<n>]
	 * : With --all, stop after this many products.
	 *
	 * [--apply]
	 * : Write the copy to the product (undo stays available). Default: keep it as a suggestion.
	 *
	 * [--queue]
	 * : Queue the products for Action Scheduler instead of generating now.
	 *
	 * [--notes=<text>]
	 * : Notes for the writer (applied to every product in this run).
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-writer generate 42 57 --apply
	 *     wp ai-writer generate --all --only-empty --limit=20 --queue
	 *
	 * @param string[]             $args       IDs.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function generate( array $args, array $assoc_args ): void {
		$ids   = $this->ids( $args, $assoc_args );
		$apply = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'apply', false );
		$only  = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'only-empty', false );
		$notes = $assoc_args['notes'] ?? null;
		$rows  = array();

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				\WP_CLI::warning( "#$id is not a product." );
				continue;
			}
			if ( $only && '' !== trim( wp_strip_all_tags( $product->get_description( 'edit' ) ) ) ) {
				$rows[] = $this->row( $product, 'skipped (has description)' );
				continue;
			}

			if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'queue', false ) ) {
				$queued = $this->plugin->queue->enqueue( $id, $apply ? 'apply' : 'review' );
				$rows[] = $this->row( $product, $queued ? 'queued' : 'already queued' );
				continue;
			}

			try {
				$suggestion = $this->plugin->generator->generate( $product, $notes, 'cli' );
				$copy       = GeneratedCopy::from_array( $suggestion );
				if ( $apply ) {
					$this->plugin->applier->apply( $product, $copy );
				}
				$rows[] = $this->row( $product, $apply ? 'applied' : 'ready', $copy, $suggestion['meta'] ?? array() );
			} catch ( \Throwable $e ) {
				$rows[] = $this->row( $product, 'failed: ' . $e->getMessage() );
			}
		}

		$this->output( $rows, $assoc_args['format'] ?? 'table' );
	}

	/**
	 * Applies the stored suggestion to products.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Product IDs.
	 *
	 * @param string[] $args IDs.
	 */
	public function apply( array $args ): void {
		foreach ( array_map( 'intval', $args ) as $id ) {
			$product    = wc_get_product( $id );
			$suggestion = $product ? Generator::suggestion( $id ) : null;
			if ( ! $suggestion ) {
				\WP_CLI::warning( "#$id has no suggestion to apply." );
				continue;
			}
			$fields = $this->plugin->applier->apply( $product, GeneratedCopy::from_array( $suggestion ) );
			\WP_CLI::success( "#$id: applied " . implode( ', ', $fields ) . '.' );
		}
	}

	/**
	 * Restores what the last apply overwrote.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Product IDs.
	 *
	 * @param string[] $args IDs.
	 */
	public function undo( array $args ): void {
		foreach ( array_map( 'intval', $args ) as $id ) {
			$product  = wc_get_product( $id );
			$restored = $product ? $this->plugin->applier->undo( $product ) : array();
			if ( $restored ) {
				\WP_CLI::success( "#$id: restored " . implode( ', ', $restored ) . '.' );
			} else {
				\WP_CLI::warning( "#$id: nothing to undo." );
			}
		}
	}

	/**
	 * @param string[]             $args       IDs.
	 * @param array<string,string> $assoc_args Flags.
	 * @return int[]
	 */
	private function ids( array $args, array $assoc_args ): array {
		if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false ) ) {
			if ( ! $args ) {
				\WP_CLI::error( 'Pass product IDs or --all.' );
			}
			return array_map( 'intval', $args );
		}
		return wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => (int) ( $assoc_args['limit'] ?? -1 ),
				'return'  => 'ids',
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);
	}

	/**
	 * @param array<string,mixed> $meta Generation meta.
	 * @return array<string,string|int>
	 */
	private function row( \WC_Product $product, string $status, ?GeneratedCopy $copy = null, array $meta = array() ): array {
		$usage = $meta['usage'] ?? array();
		return array(
			'id'          => $product->get_id(),
			'name'        => $product->get_name(),
			'status'      => $status,
			'meta_title'  => $copy?->meta_title ?? '',
			'keyword'     => $copy?->focus_keyword ?? '',
			'tokens'      => (int) ( $usage['prompt_tokens'] ?? 0 ) + (int) ( $usage['completion_tokens'] ?? 0 ),
			'warnings'    => $copy ? count( $copy->warnings ) : 0,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Rows.
	 */
	private function output( array $rows, string $format ): void {
		if ( ! $rows ) {
			\WP_CLI::log( 'No products.' );
			return;
		}
		\WP_CLI\Utils\format_items( $format, $rows, array_keys( $rows[0] ) );
	}
}
