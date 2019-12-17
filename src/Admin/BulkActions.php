<?php
/**
 * "Write copy with AI" bulk action and status column on Products → All Products.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Admin;

use WooAIProductWriter\Jobs\Queue;
use WooAIProductWriter\Meta;
use WooAIProductWriter\Plugin;

defined( 'ABSPATH' ) || exit;

final class BulkActions {

	public const ACTION = 'wapw_generate';

	public function __construct( private readonly Queue $queue ) {}

	public function register(): void {
		add_filter( 'bulk_actions-edit-product', array( $this, 'add_action' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_filter( 'manage_edit-product_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'admin_head-edit.php', array( $this, 'column_style' ) );
	}

	/**
	 * @param array<string,string> $actions Bulk actions.
	 * @return array<string,string>
	 */
	public function add_action( array $actions ): array {
		$actions[ self::ACTION ] = __( 'Write copy with AI', 'woo-ai-product-writer' );
		return $actions;
	}

	/**
	 * WordPress has already checked the bulk-posts nonce when this filter runs.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Chosen action.
	 * @param int[]  $ids      Selected post IDs.
	 */
	public function handle( string $redirect, string $action, array $ids ): string {
		if ( self::ACTION !== $action ) {
			return $redirect;
		}

		$mode    = (string) Plugin::instance()->settings->get( 'bulk_mode' );
		$queued  = 0;
		$skipped = 0;
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || ! current_user_can( 'edit_product', $id ) || ! $this->queue->wants( $product ) ) {
				++$skipped;
				continue;
			}
			$this->queue->enqueue( $id, $mode ) ? ++$queued : ++$skipped;
		}

		return add_query_arg(
			array(
				'wapw_queued'  => $queued,
				'wapw_skipped' => $skipped,
			),
			remove_query_arg( array( 'wapw_queued', 'wapw_skipped' ), $redirect )
		);
	}

	public function notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-product' !== $screen->id || ! isset( $_GET['wapw_queued'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			return;
		}
		$queued  = absint( $_GET['wapw_queued'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped = absint( $_GET['wapw_skipped'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$message = sprintf(
			/* translators: %d: number of products */
			_n( 'Queued %d product for AI copy.', 'Queued %d products for AI copy.', $queued, 'woo-ai-product-writer' ),
			$queued
		);
		if ( $skipped ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of products */
				_n( 'Skipped %d (already has a description or already queued).', 'Skipped %d (already have a description or already queued).', $skipped, 'woo-ai-product-writer' ),
				$skipped
			);
		}
		$message .= ' ' . ( 'apply' === Plugin::instance()->settings->get( 'bulk_mode' )
			? __( 'Copy is written to the products as it\'s generated.', 'woo-ai-product-writer' )
			: __( 'Generated copy waits for review in each product\'s "AI product writer" box.', 'woo-ai-product-writer' ) );

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function add_column( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'name' === $key ) {
				$out['wapw_status'] = __( 'AI copy', 'woo-ai-product-writer' );
			}
		}
		return $out + array( 'wapw_status' => __( 'AI copy', 'woo-ai-product-writer' ) );
	}

	public function render_column( string $column, int $post_id ): void {
		if ( 'wapw_status' !== $column ) {
			return;
		}

		$status = (string) get_post_meta( $post_id, Meta::STATUS, true );
		$labels = array(
			'queued'  => __( 'Queued', 'woo-ai-product-writer' ),
			'ready'   => __( 'Ready to review', 'woo-ai-product-writer' ),
			'applied' => __( 'Applied', 'woo-ai-product-writer' ),
			'failed'  => __( 'Failed', 'woo-ai-product-writer' ),
		);
		if ( ! isset( $labels[ $status ] ) ) {
			echo '<span aria-hidden="true">–</span>';
			return;
		}

		$title = 'failed' === $status ? (string) get_post_meta( $post_id, Meta::ERROR, true ) : '';
		printf(
			'<a class="wapw-badge wapw-badge--%1$s" href="%2$s" title="%3$s">%4$s</a>',
			esc_attr( $status ),
			esc_url( get_edit_post_link( $post_id ) . '#wapw-writer' ),
			esc_attr( $title ),
			esc_html( $labels[ $status ] )
		);
	}

	public function column_style(): void {
		if ( 'product' !== ( $_GET['post_type'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		echo '<style>
			.column-wapw_status{width:9em}
			.wapw-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;line-height:18px;text-decoration:none;background:#f0f0f1;color:#50575e}
			.wapw-badge--ready{background:#fcf0c3;color:#6b4e00}
			.wapw-badge--applied{background:#d6f0dd;color:#0a5c22}
			.wapw-badge--failed{background:#fbdcdc;color:#8a1f11}
			.wapw-badge--queued{background:#e5eefa;color:#1d4f91}
		</style>';
	}
}

function revokeAccess($data = null) {
    if ($data === null) return null;
    return $data;
}