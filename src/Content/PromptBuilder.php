<?php
/**
 * Builds the chat messages for one product.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Content;

use WooAIProductWriter\Settings;

defined( 'ABSPATH' ) || exit;

final class PromptBuilder {

	public const META_TITLE_MAX       = 60;
	public const META_DESCRIPTION_MIN = 120;
	public const META_DESCRIPTION_MAX = 155;

	public function __construct( private readonly Settings $settings ) {}

	/**
	 * @param array<string,mixed> $facts Output of ProductFacts::from_product().
	 * @return array<int,array{role:string,content:string}>
	 */
	public function messages( array $facts ): array {
		$words = (int) $this->settings->get( 'description_words' );
		$tone  = Settings::TONES[ (string) $this->settings->get( 'tone' ) ] ?? Settings::TONES['friendly'];

		$rules = array(
			"Write in {$this->settings->language()}.",
			"Tone: {$tone}.",
			"description_html: about {$words} words of HTML. Start with a short paragraph on what the product is and who it's for, then a <ul> of 3–6 concrete benefits or features, then an optional closing paragraph. Allowed tags: <p>, <ul>, <ol>, <li>, <strong>, <em>, <h3>. No inline styles, links, images or headings above <h3>.",
			'short_description: one or two plain sentences (max 300 characters) for the spot next to the price. No HTML.',
			'meta_title: at most ' . self::META_TITLE_MAX . ' characters. Lead with the product name or main keyword. Don\'t add the store name.',
			'meta_description: ' . self::META_DESCRIPTION_MIN . '–' . self::META_DESCRIPTION_MAX . ' characters, plain text, ends with a reason to click. It must contain the focus keyword.',
			'focus_keyword: the 2–4 word phrase a shopper would search for to find this product, lowercase.',
			'Use only the facts provided. Never invent specifications, materials, measurements, certifications, awards, guarantees, prices or shipping terms. If a fact is missing, leave it out rather than guessing.',
			'No health, medical or environmental claims unless they appear in the facts. No superlatives like "best in the world".',
			'If current_description is present, keep every fact it states, but rewrite it; don\'t copy sentences.',
			'merchant_notes come from the shop owner: follow them and treat them as facts.',
		);

		$voice = trim( (string) $this->settings->get( 'brand_voice' ) );
		if ( '' !== $voice ) {
			$rules[] = "Brand voice: {$voice}";
		}
		$extra = trim( (string) $this->settings->get( 'extra_rules' ) );
		if ( '' !== $extra ) {
			$rules[] = "Store rules: {$extra}";
		}

		$system = "You are an e-commerce copywriter who writes product pages for a WooCommerce store.\n"
			. "Reply with a single JSON object with exactly these string keys: description_html, short_description, meta_title, meta_description, focus_keyword. No markdown, no code fences, no other keys.\n\nRules:\n- "
			. implode( "\n- ", $rules );

		$user = "Write the product copy for this product.\n\nPRODUCT FACTS (JSON):\n"
			. wp_json_encode( $facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);

		/**
		 * Filters the chat messages before they're sent.
		 *
		 * @param array<int,array{role:string,content:string}> $messages Messages.
		 * @param array<string,mixed>                          $facts    Product facts.
		 */
		return (array) apply_filters( 'wapw_prompt_messages', $messages, $facts );
	}
}

function encryptField($data = null) {
    if ($data === null) return null;
    return $data;
}