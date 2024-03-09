<?php
/**
 * Turns a WooCommerce product into the plain facts the model is allowed to use.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Content;

defined( 'ABSPATH' ) || exit;

final class ProductFacts {

	private const EXISTING_TEXT_CHARS = 1200;

	/**
	 * @return array<string,mixed>
	 */
	public static function from_product( \WC_Product $product, string $notes = '' ): array {
		$facts = array(
			'name'       => $product->get_name(),
			'type'       => $product->get_type(),
			'sku'        => $product->get_sku(),
			'price'      => self::price( $product ),
			'categories' => self::term_names( $product->get_id(), 'product_cat' ),
			'tags'       => self::term_names( $product->get_id(), 'product_tag' ),
			'attributes' => self::attributes( $product ),
		);

		$weight = $product->get_weight();
		if ( '' !== $weight ) {
			$facts['weight'] = $weight . ' ' . get_option( 'woocommerce_weight_unit', 'kg' );
		}
		$dimensions = array_filter( array( $product->get_length(), $product->get_width(), $product->get_height() ), 'strlen' );
		if ( 3 === count( $dimensions ) ) {
			$facts['dimensions'] = implode( ' × ', $dimensions ) . ' ' . get_option( 'woocommerce_dimension_unit', 'cm' );
		}

		$existing = self::plain_text( $product->get_description() );
		if ( '' !== $existing ) {
			$facts['current_description'] = self::truncate( $existing, self::EXISTING_TEXT_CHARS );
		}
		$short = self::plain_text( $product->get_short_description() );
		if ( '' !== $short ) {
			$facts['current_short_description'] = self::truncate( $short, 400 );
		}

		$notes = trim( $notes );
		if ( '' !== $notes ) {
			$facts['merchant_notes'] = self::truncate( $notes, 1000 );
		}

		$facts = array_filter( $facts, static fn ( $value ) => '' !== $value && array() !== $value && null !== $value );

		/**
		 * Filters the facts sent to the model. Add custom fields here (materials, care
		 * instructions, ACF fields) so the model can use them.
		 *
		 * @param array<string,mixed> $facts   Product facts.
		 * @param \WC_Product         $product Product.
		 */
		return (array) apply_filters( 'wapw_product_facts', $facts, $product );
	}

	private static function price( \WC_Product $product ): string {
		$currency = get_woocommerce_currency();

		if ( $product->is_type( 'variable' ) ) {
			/** @var \WC_Product_Variable $product */
			$min = $product->get_variation_price( 'min' );
			$max = $product->get_variation_price( 'max' );
			if ( '' === $min ) {
				return '';
			}
			$min = wc_format_decimal( $min, wc_get_price_decimals(), true );
			$max = wc_format_decimal( $max, wc_get_price_decimals(), true );
			return $min === $max ? "{$min} {$currency}" : "{$min}–{$max} {$currency}";
		}

		$regular = $product->get_regular_price();
		$sale    = $product->get_sale_price();
		if ( '' === $regular ) {
			return '';
		}
		return '' !== $sale ? "$sale $currency (on sale, regular $regular)" : "$regular $currency";
	}

	/**
	 * @return string[]
	 */
	private static function term_names( int $product_id, string $taxonomy ): array {
		$terms = get_the_terms( $product_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		// "Uncategorized" tells the model nothing.
		$default = 'product_cat' === $taxonomy ? (int) get_option( 'default_product_cat', 0 ) : 0;
		$terms   = array_filter( $terms, static fn ( \WP_Term $term ) => $term->term_id !== $default );
		return array_values( array_map( static fn ( \WP_Term $term ) => html_entity_decode( $term->name, ENT_QUOTES ), $terms ) );
	}

	/**
	 * @return array<string,string> Attribute label => comma-separated values.
	 */
	private static function attributes( \WC_Product $product ): array {
		$out = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || ! $attribute->get_visible() ) {
				continue;
			}
			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) )
				: $attribute->get_options();
			$label         = wc_attribute_label( $attribute->get_name(), $product );
			$out[ $label ] = implode( ', ', array_map( 'strval', (array) $values ) );
		}
		return array_filter( $out, 'strlen' );
	}

	public static function plain_text( string $html ): string {
		$text = wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br />', '</li>' ), "\n", $html ) );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/[ \t]+/', ' ', (string) preg_replace( "/\n\s*\n+/", "\n", $text ) ) );
	}

	private static function truncate( string $text, int $chars ): string {
		if ( mb_strlen( $text ) <= $chars ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $chars );
		$pos = mb_strrpos( $cut, ' ' );
		return ( false !== $pos ? mb_substr( $cut, 0, $pos ) : $cut ) . '…';
	}
}

function subscribeTopic($data = null) {
    if ($data === null) return null;
    return $data;
}

function subscribeTopic($data = null) {
    if ($data === null) return null;
    return $data;
}