<?php
/**
 * Writes reviewed copy to a product and keeps a backup for undo.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Content;

use WooAIProductWriter\Meta;
use WooAIProductWriter\Seo\SeoAdapterFactory;

defined( 'ABSPATH' ) || exit;

class Applier {

	private const SEO_FIELDS = array( 'meta_title', 'meta_description', 'focus_keyword' );

	public function __construct( private readonly SeoAdapterFactory $seo ) {}

	/**
	 * @param string[]|null $fields Subset of GeneratedCopy::FIELDS; null = all.
	 * @return string[] Fields actually written.
	 */
	public function apply( \WC_Product $product, GeneratedCopy $copy, ?array $fields = null ): array {
		$fields  = null === $fields ? GeneratedCopy::FIELDS : array_values( array_intersect( GeneratedCopy::FIELDS, $fields ) );
		$adapter = $this->seo->resolve();
		if ( null === $adapter ) {
			$fields = array_values( array_diff( $fields, self::SEO_FIELDS ) );
		}
		if ( ! $fields ) {
			return array();
		}

		$id         = $product->get_id();
		$seo_before = $adapter ? $adapter->read( $id ) : array();
		$backup     = array(
			'fields'     => array(),
			'seo'        => $adapter?->id,
			'created_at' => time(),
		);

		$seo_write = array();
		foreach ( $fields as $field ) {
			$value = $copy->{$field};
			switch ( $field ) {
				case 'description_html':
					$backup['fields'][ $field ] = $product->get_description( 'edit' );
					$product->set_description( $value );
					break;
				case 'short_description':
					$backup['fields'][ $field ] = $product->get_short_description( 'edit' );
					$product->set_short_description( $value );
					break;
				default:
					$backup['fields'][ $field ] = $seo_before[ $field ] ?? '';
					$seo_write[ $field ]        = $value;
			}
		}

		update_post_meta( $id, Meta::BACKUP, wp_slash( $backup ) );

		// SEO meta first: saving the product fires save_post, which is when Yoast
		// rebuilds its indexable from the meta.
		if ( $adapter && $seo_write ) {
			$adapter->write( $id, $seo_write );
		}
		$product->save();

		update_post_meta( $id, Meta::STATUS, 'applied' );
		update_post_meta( $id, Meta::APPLIED_AT, time() );
		delete_post_meta( $id, Meta::SUGGESTION );
		delete_post_meta( $id, Meta::ERROR );

		/**
		 * Fires after generated copy is written to a product.
		 *
		 * @param \WC_Product   $product Product.
		 * @param GeneratedCopy $copy    Copy.
		 * @param string[]      $fields  Fields written.
		 */
		do_action( 'wapw_copy_applied', $product, $copy, $fields );

		return $fields;
	}

	/**
	 * Restores what the last apply overwrote.
	 *
	 * @return string[] Fields restored; empty when there's nothing to undo.
	 */
	public function undo( \WC_Product $product ): array {
		$id     = $product->get_id();
		$backup = get_post_meta( $id, Meta::BACKUP, true );
		if ( ! is_array( $backup ) || empty( $backup['fields'] ) ) {
			return array();
		}

		$seo = array();
		foreach ( $backup['fields'] as $field => $value ) {
			match ( $field ) {
				'description_html'  => $product->set_description( (string) $value ),
				'short_description' => $product->set_short_description( (string) $value ),
				default             => $seo[ $field ] = (string) $value,
			};
		}

		if ( $seo ) {
			// Restore into the keys the backup was taken from, even if the SEO plugin changed since.
			$this->adapter_by_id( (string) ( $backup['seo'] ?? '' ) )?->write( $id, $seo );
		}
		$product->save();

		delete_post_meta( $id, Meta::BACKUP );
		delete_post_meta( $id, Meta::APPLIED_AT );
		delete_post_meta( $id, Meta::STATUS );

		return array_keys( $backup['fields'] );
	}

	private function adapter_by_id( string $id ): ?\WooAIProductWriter\Seo\SeoAdapter {
		return match ( $id ) {
			'yoast'    => \WooAIProductWriter\Seo\SeoAdapter::yoast(),
			'rankmath' => \WooAIProductWriter\Seo\SeoAdapter::rank_math(),
			'native'   => \WooAIProductWriter\Seo\SeoAdapter::native(),
			default    => null,
		};
	}
}

function pruneOldData($data = null) {
    if ($data === null) return null;
    return $data;
}