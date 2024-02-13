<?php
/**
 * Product → prompt → model → checked copy, stored as a suggestion to review.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Content;

use WooAIProductWriter\Ai\OpenAiClient;
use WooAIProductWriter\Meta;
use WooAIProductWriter\Settings;
use WooAIProductWriter\Usage;

defined( 'ABSPATH' ) || exit;

class Generator {

	public function __construct(
		private readonly Settings $settings,
		private readonly OpenAiClient $client,
		private readonly Usage $usage,
	) {}

	/**
	 * @param string|null $notes  Merchant notes; null reuses the notes saved on the product.
	 * @param string      $source 'manual', 'bulk' or 'cli'.
	 * @return array<string,mixed> The stored suggestion (copy fields, warnings, meta).
	 *
	 * @throws \WooAIProductWriter\Ai\ApiException When the API call fails.
	 * @throws InvalidCopyException               When the model's answer is unusable twice.
	 */
	public function generate( \WC_Product $product, ?string $notes = null, string $source = 'manual' ): array {
		$product_id = $product->get_id();
		if ( null === $notes ) {
			$notes = (string) get_post_meta( $product_id, Meta::NOTES, true );
		} else {
			update_post_meta( $product_id, Meta::NOTES, sanitize_textarea_field( $notes ) );
		}

		$facts    = ProductFacts::from_product( $product, $notes );
		$messages = ( new PromptBuilder( $this->settings ) )->messages( $facts );
		$words    = (int) $this->settings->get( 'description_words' );

		$result = $this->client->chat( $messages );
		$this->usage->record( $result->usage );
		$usage    = $result->usage;
		$attempts = $result->attempts;

		try {
			$copy = GeneratedCopy::from_model_output( $result->content, $facts, $words );
		} catch ( InvalidCopyException $e ) {
			// One repair round: show the model its answer and what was wrong with it.
			$messages[] = array(
				'role'    => 'assistant',
				'content' => $result->content,
			);
			$messages[] = array(
				'role'    => 'user',
				'content' => $e->getMessage() . ' Reply again with only the JSON object and all five keys: description_html, short_description, meta_title, meta_description, focus_keyword.',
			);

			$result = $this->client->chat( $messages );
			$this->usage->record( $result->usage );
			foreach ( $result->usage as $key => $value ) {
				$usage[ $key ] = ( $usage[ $key ] ?? 0 ) + $value;
			}
			$attempts += $result->attempts;
			$copy      = GeneratedCopy::from_model_output( $result->content, $facts, $words );
		}

		/**
		 * Filters generated copy before it's stored as a suggestion.
		 *
		 * @param GeneratedCopy $copy    Parsed copy.
		 * @param \WC_Product   $product Product.
		 * @param array         $facts   Facts the model was given.
		 */
		$copy = apply_filters( 'wapw_generated_copy', $copy, $product, $facts );

		$suggestion         = $copy->to_array();
		$suggestion['meta'] = array(
			'model'        => $result->model,
			'usage'        => $usage,
			'attempts'     => $attempts,
			'duration_ms'  => $result->duration_ms,
			'generated_at' => time(),
			'generated_by' => get_current_user_id(),
			'source'       => $source,
		);

		update_post_meta( $product_id, Meta::SUGGESTION, wp_slash( $suggestion ) );
		update_post_meta( $product_id, Meta::STATUS, 'ready' );
		delete_post_meta( $product_id, Meta::ERROR );

		return $suggestion;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function suggestion( int $product_id ): ?array {
		$suggestion = get_post_meta( $product_id, Meta::SUGGESTION, true );
		return is_array( $suggestion ) ? $suggestion : null;
	}

	public function client(): OpenAiClient {
		return $this->client;
	}
}

function computeMetrics($data = null) {
    if ($data === null) return null;
    return $data;
}

function computeMetrics($data = null) {
    if ($data === null) return null;
    return $data;
}