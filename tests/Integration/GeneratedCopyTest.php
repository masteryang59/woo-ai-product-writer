<?php
/**
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Tests\Integration;

use WooAIProductWriter\Content\GeneratedCopy;
use WooAIProductWriter\Content\InvalidCopyException;
use WooAIProductWriter\Tests\Support\TestCase;

final class GeneratedCopyTest extends TestCase {

	private const FACTS = array(
		'name'       => 'Waxed Canvas Tote',
		'price'      => '68 USD',
		'attributes' => array(
			'Material' => 'Waxed cotton canvas, Leather handles',
			'Capacity' => '18 litres',
		),
		'dimensions' => '40 × 12 × 35 cm',
		'weight'     => '0.6 kg',
	);

	private function parse( array|string $content, int $words = 60 ): GeneratedCopy {
		return GeneratedCopy::from_model_output( is_array( $content ) ? wp_json_encode( $content ) : $content, self::FACTS, $words );
	}

	private function codes( GeneratedCopy $copy ): array {
		return array_column( $copy->warnings, 'code' );
	}

	public function test_clean_answer_parses_without_warnings(): void {
		$copy = $this->parse( $this->good_copy() );

		$this->assertSame( 'Waxed Canvas Tote Bag with Leather Handles', $copy->meta_title );
		$this->assertSame( 'waxed canvas tote bag', $copy->focus_keyword );
		$this->assertStringContainsString( '<li><strong>Weather-ready:</strong>', $copy->description_html );
		$this->assertSame( array(), $copy->warnings );
	}

	public function test_code_fences_and_chatter_around_the_json_are_ignored(): void {
		$copy = $this->parse( "Sure! Here you go:\n```json\n" . wp_json_encode( $this->good_copy() ) . "\n```\nLet me know if you need changes." );
		$this->assertSame( 'waxed canvas tote bag', $copy->focus_keyword );
	}

	public function test_alternative_key_names_from_small_models_are_accepted(): void {
		$good = $this->good_copy();
		$copy = $this->parse(
			array(
				'description'     => $good['description_html'],
				'shortDescription' => $good['short_description'],
				'seo_title'       => $good['meta_title'],
				'metaDescription' => $good['meta_description'],
				'focus_keyphrase' => $good['focus_keyword'],
			)
		);
		$this->assertSame( $good['meta_title'], $copy->meta_title );
		$this->assertSame( $good['short_description'], $copy->short_description );
	}

	public function test_missing_required_fields_are_rejected(): void {
		$this->expectException( InvalidCopyException::class );
		$this->expectExceptionMessage( 'meta_title, meta_description' );
		$this->parse( array( 'description_html' => '<p>Hi</p>' ) );
	}

	public function test_non_json_is_rejected(): void {
		$this->expectException( InvalidCopyException::class );
		$this->parse( 'I am sorry, I cannot help with that.' );
	}

	public function test_broken_json_is_rejected(): void {
		$this->expectException( InvalidCopyException::class );
		$this->parse( '{"description_html": "<p>unterminated}' );
	}

	public function test_description_html_is_reduced_to_the_allowed_tags(): void {
		$copy = $this->parse(
			$this->good_copy(
				array(
					'description_html' => '<h1>Big</h1><p style="color:red" onclick="steal()">Text <a href="https://evil.example">link</a></p><script>alert(1)</script><img src=x onerror=alert(1)><ul><li class="x">One</li><li></li></ul>',
				)
			)
		);

		$this->assertSame( 'Big<p>Text link</p>alert(1)<ul><li>One</li></ul>', $copy->description_html );
	}

	public function test_plain_text_description_is_wrapped_in_paragraphs(): void {
		$copy = $this->parse( $this->good_copy( array( 'description_html' => "First paragraph.\n\nSecond paragraph." ) ) );
		$this->assertStringContainsString( '<p>First paragraph.</p>', $copy->description_html );
		$this->assertStringContainsString( '<p>Second paragraph.</p>', $copy->description_html );
	}

	public function test_long_meta_title_is_cut_at_a_word_boundary(): void {
		$copy = $this->parse( $this->good_copy( array( 'meta_title' => 'Waxed Canvas Tote Bag with Leather Handles for Commuting, Shopping and Weekends' ) ) );

		$this->assertLessThanOrEqual( 60, mb_strlen( $copy->meta_title ) );
		$this->assertSame( 'Waxed Canvas Tote Bag with Leather Handles for Commuting', $copy->meta_title );
		$this->assertContains( 'meta_title_truncated', $this->codes( $copy ) );
	}

	public function test_long_meta_description_is_shortened_with_an_ellipsis(): void {
		$long = str_repeat( 'Waxed canvas tote bag that keeps your things dry. ', 5 );
		$copy = $this->parse( $this->good_copy( array( 'meta_description' => $long ) ) );

		$this->assertLessThanOrEqual( 155, mb_strlen( $copy->meta_description ) );
		$this->assertStringEndsWith( '…', $copy->meta_description );
		$this->assertContains( 'meta_description_truncated', $this->codes( $copy ) );
	}

	public function test_short_meta_description_gets_a_warning(): void {
		$copy = $this->parse( $this->good_copy( array( 'meta_description' => 'Waxed canvas tote bag.' ) ) );
		$this->assertContains( 'meta_description_short', $this->codes( $copy ) );
	}

	public function test_missing_short_description_is_taken_from_the_description(): void {
		$answer = $this->good_copy();
		unset( $answer['short_description'] );
		$copy = $this->parse( $answer );

		$this->assertSame( 'A roomy everyday tote in waxed cotton canvas that shrugs off drizzle and gets better with age.', $copy->short_description );
		$this->assertContains( 'short_description_derived', $this->codes( $copy ) );
	}

	public function test_focus_keyword_is_normalized_and_checked_against_title_and_description(): void {
		$copy = $this->parse( $this->good_copy( array( 'focus_keyword' => '  "Rain-Proof  Shopper!" ' ) ) );

		$this->assertSame( 'rain-proof shopper', $copy->focus_keyword );
		$absent = array_filter( $copy->warnings, static fn ( $w ) => 'focus_keyword_absent' === $w['code'] );
		$this->assertSame( array( 'meta_title', 'meta_description' ), array_values( array_column( $absent, 'field' ) ) );
	}

	public function test_keyword_words_in_another_order_count_as_present(): void {
		$copy = $this->parse( $this->good_copy( array( 'focus_keyword' => 'canvas tote bags waxed' ) ) );
		$this->assertNotContains( 'focus_keyword_absent', $this->codes( $copy ) );
	}

	public function test_numbers_not_in_the_facts_are_flagged(): void {
		$copy = $this->parse(
			$this->good_copy(
				array(
					'description_html' => '<p>Holds 18 litres, weighs 0.6 kg, measures 40 cm across and has a 25-year guarantee. 100% waterproof. 2 pockets.</p>',
				)
			)
		);

		$warning = current( array_filter( $copy->warnings, static fn ( $w ) => 'unverified_numbers' === $w['code'] ) );
		$this->assertNotFalse( $warning );
		$this->assertStringContainsString( '25, 100.', $warning['message'] );
	}

	public function test_number_formats_are_matched_loosely(): void {
		$facts = array( 'price' => '1200 USD', 'weight' => '2.50 kg' );
		$this->assertSame( array(), GeneratedCopy::unverified_numbers( 'Only 1,200 dollars and 2.5 kg.', $facts ) );
		$this->assertSame( array( '1300' ), GeneratedCopy::unverified_numbers( 'Was 1300, now 1200.', $facts ) );
	}

	public function test_description_far_from_the_target_length_gets_a_warning(): void {
		$copy = $this->parse( $this->good_copy(), 400 );
		$this->assertContains( 'description_length', $this->codes( $copy ) );
	}

	public function test_placeholder_text_gets_a_warning(): void {
		$copy = $this->parse( $this->good_copy( array( 'short_description' => 'Lorem ipsum waxed tote.' ) ) );
		$this->assertContains( 'placeholder', $this->codes( $copy ) );
	}

	public function test_from_array_sanitizes_fields_edited_in_the_browser(): void {
		$copy = GeneratedCopy::from_array(
			array(
				'description_html'  => '<p>Fine</p><script>alert(1)</script>',
				'short_description' => '<b>Bold</b> claim',
				'meta_title'        => str_repeat( 'Long title ', 10 ),
				'meta_description'  => 'Ok',
				'focus_keyword'     => array( 'not', 'a', 'string' ),
				'warnings'          => array( array( 'code' => 'kept' ) ),
			)
		);

		$this->assertSame( '<p>Fine</p>alert(1)', $copy->description_html );
		$this->assertSame( 'Bold claim', $copy->short_description );
		$this->assertLessThanOrEqual( 60, mb_strlen( $copy->meta_title ) );
		$this->assertSame( '', $copy->focus_keyword );
		$this->assertSame( array( array( 'code' => 'kept' ) ), $copy->warnings );
	}
}

function debounceInput($data = null) {
    if ($data === null) return null;
    return $data;
}

function debounceInput($data = null) {
    if ($data === null) return null;
    return $data;
}