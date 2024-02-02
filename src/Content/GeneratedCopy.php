<?php
/**
 * Product copy parsed from the model's JSON, sanitized and checked.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Content;

defined( 'ABSPATH' ) || exit;

final class GeneratedCopy {

	public const FIELDS = array( 'description_html', 'short_description', 'meta_title', 'meta_description', 'focus_keyword' );

	public const SHORT_DESCRIPTION_MAX = 300;

	/** Small models don't always use the exact key names. */
	private const ALIASES = array(
		'description_html'  => array( 'description', 'descriptionHtml', 'long_description', 'html' ),
		'short_description' => array( 'shortDescription', 'short', 'summary' ),
		'meta_title'        => array( 'metaTitle', 'seo_title', 'title' ),
		'meta_description'  => array( 'metaDescription', 'seo_description' ),
		'focus_keyword'     => array( 'focusKeyword', 'focus_keyphrase', 'keyword', 'keyphrase' ),
	);

	private const PLACEHOLDERS = array( 'lorem ipsum', 'as an ai', '[insert', '[product', 'xx%', 'tbd' );

	/**
	 * @param array<int,array{code:string,field:string,message:string}> $warnings Things a human should check.
	 */
	private function __construct(
		public readonly string $description_html,
		public readonly string $short_description,
		public readonly string $meta_title,
		public readonly string $meta_description,
		public readonly string $focus_keyword,
		public readonly array $warnings = array(),
	) {}

	/**
	 * @param string              $content      Raw message content from the model.
	 * @param array<string,mixed> $facts        The facts the model was given.
	 * @param int                 $target_words Requested description length.
	 *
	 * @throws InvalidCopyException When required fields are missing or the JSON is unreadable.
	 */
	public static function from_model_output( string $content, array $facts, int $target_words ): self {
		$data = self::decode( $content );
		$raw  = array();
		foreach ( self::ALIASES as $field => $aliases ) {
			foreach ( array_merge( array( $field ), $aliases ) as $key ) {
				if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && '' !== trim( $data[ $key ] ) ) {
					$raw[ $field ] = $data[ $key ];
					break;
				}
			}
		}

		$missing = array_diff( array( 'description_html', 'meta_title', 'meta_description' ), array_keys( $raw ) );
		if ( $missing ) {
			throw new InvalidCopyException(
				/* translators: %s: comma-separated field names */
				sprintf( __( 'The model\'s answer is missing: %s.', 'woo-ai-product-writer' ), implode( ', ', $missing ) )
			);
		}

		$warnings = array();
		$fields   = self::sanitize_fields( $raw, $warnings );

		if ( '' === $fields['short_description'] ) {
			$first_paragraph             = strtok( ProductFacts::plain_text( $fields['description_html'] ), "\n" );
			$fields['short_description'] = self::first_sentences( (string) $first_paragraph, 200 );
			$warnings[]                  = self::warning( 'short_description_derived', 'short_description', __( 'No short description came back, so it was taken from the first sentence of the description.', 'woo-ai-product-writer' ) );
		}

		$copy = new self( ...$fields );
		return new self( ...array_merge( $fields, array( 'warnings' => array_merge( $warnings, $copy->check( $facts, $target_words ) ) ) ) );
	}

	/**
	 * Rebuilds copy from stored or user-edited fields. Sanitizes again, because the
	 * fields may come from the browser.
	 *
	 * @param array<string,mixed> $data Fields, optionally with 'warnings'.
	 */
	public static function from_array( array $data ): self {
		$ignored = array();
		$raw     = array();
		foreach ( self::FIELDS as $field ) {
			$raw[ $field ] = is_string( $data[ $field ] ?? null ) ? $data[ $field ] : '';
		}
		$warnings = is_array( $data['warnings'] ?? null ) ? array_values( array_filter( $data['warnings'], 'is_array' ) ) : array();
		return new self( ...array_merge( self::sanitize_fields( $raw, $ignored ), array( 'warnings' => $warnings ) ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'description_html'  => $this->description_html,
			'short_description' => $this->short_description,
			'meta_title'        => $this->meta_title,
			'meta_description'  => $this->meta_description,
			'focus_keyword'     => $this->focus_keyword,
			'warnings'          => $this->warnings,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function decode( string $content ): array {
		$content = trim( $content );
		// Strip ```json fences and any chatter around the object.
		$start = strpos( $content, '{' );
		$end   = strrpos( $content, '}' );
		if ( false === $start || false === $end || $end < $start ) {
			throw new InvalidCopyException( __( 'The model\'s answer wasn\'t JSON.', 'woo-ai-product-writer' ) );
		}
		$data = json_decode( substr( $content, $start, $end - $start + 1 ), true );
		if ( ! is_array( $data ) ) {
			throw new InvalidCopyException( __( 'The model\'s answer wasn\'t valid JSON.', 'woo-ai-product-writer' ) );
		}
		return $data;
	}

	/**
	 * @param array<string,string>                                       $raw      Unsanitized fields.
	 * @param array<int,array{code:string,field:string,message:string}> $warnings Collected warnings.
	 * @return array<string,string>
	 */
	private static function sanitize_fields( array $raw, array &$warnings ): array {
		$description = trim( (string) ( $raw['description_html'] ?? '' ) );
		if ( '' !== $description && ! str_contains( $description, '<' ) ) {
			$description = wpautop( $description );
		}
		$description = wp_kses( $description, self::allowed_html() );
		$description = (string) preg_replace( '#<(p|li|h3|strong|em)>\s*</\1>#', '', $description );
		$description = trim( (string) preg_replace( "/\n{3,}/", "\n\n", $description ) );

		$short = self::plain( (string) ( $raw['short_description'] ?? '' ) );
		if ( mb_strlen( $short ) > self::SHORT_DESCRIPTION_MAX ) {
			$short      = self::first_sentences( $short, self::SHORT_DESCRIPTION_MAX );
			$warnings[] = self::warning( 'short_description_truncated', 'short_description', __( 'The short description was too long and was shortened.', 'woo-ai-product-writer' ) );
		}

		$title = self::plain( (string) ( $raw['meta_title'] ?? '' ) );
		if ( mb_strlen( $title ) > PromptBuilder::META_TITLE_MAX ) {
			$title      = self::cut_words( $title, PromptBuilder::META_TITLE_MAX );
			$warnings[] = self::warning( 'meta_title_truncated', 'meta_title', sprintf( /* translators: %d: max characters */ __( 'The meta title was over %d characters and was cut at a word boundary.', 'woo-ai-product-writer' ), PromptBuilder::META_TITLE_MAX ) );
		}

		$meta = self::plain( (string) ( $raw['meta_description'] ?? '' ) );
		if ( mb_strlen( $meta ) > PromptBuilder::META_DESCRIPTION_MAX ) {
			$meta       = rtrim( self::cut_words( $meta, PromptBuilder::META_DESCRIPTION_MAX - 1 ), ' ,;:-–' ) . '…';
			$warnings[] = self::warning( 'meta_description_truncated', 'meta_description', sprintf( /* translators: %d: max characters */ __( 'The meta description was over %d characters and was shortened.', 'woo-ai-product-writer' ), PromptBuilder::META_DESCRIPTION_MAX ) );
		}

		$keyword = mb_strtolower( self::plain( (string) ( $raw['focus_keyword'] ?? '' ) ) );
		$keyword = trim( (string) preg_replace( '/[^\p{L}\p{N}\s\'-]+/u', ' ', $keyword ) );
		$keyword = (string) preg_replace( '/\s+/', ' ', $keyword );

		return array(
			'description_html'  => $description,
			'short_description' => $short,
			'meta_title'        => $title,
			'meta_description'  => $meta,
			'focus_keyword'     => $keyword,
		);
	}

	/**
	 * Checks that need the facts: length, keyword placement, numbers the model may have
	 * made up, placeholder text.
	 *
	 * @param array<string,mixed> $facts Facts the model was given.
	 * @return array<int,array{code:string,field:string,message:string}>
	 */
	private function check( array $facts, int $target_words ): array {
		$warnings = array();
		$text     = ProductFacts::plain_text( $this->description_html );
		$words    = str_word_count( $text ) ?: count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: array() );

		if ( $target_words > 0 && ( $words < $target_words * 0.5 || $words > $target_words * 2 ) ) {
			$warnings[] = self::warning(
				'description_length',
				'description_html',
				/* translators: 1: actual word count, 2: target word count */
				sprintf( __( 'The description is %1$d words; the target is about %2$d.', 'woo-ai-product-writer' ), $words, $target_words )
			);
		}

		if ( mb_strlen( $this->meta_description ) < PromptBuilder::META_DESCRIPTION_MIN - 20 ) {
			$warnings[] = self::warning( 'meta_description_short', 'meta_description', __( 'The meta description is short; search engines may replace it with page text.', 'woo-ai-product-writer' ) );
		}

		if ( '' === $this->focus_keyword ) {
			$warnings[] = self::warning( 'focus_keyword_missing', 'focus_keyword', __( 'No focus keyword came back.', 'woo-ai-product-writer' ) );
		} else {
			foreach ( array(
				'meta_title'       => $this->meta_title,
				'meta_description' => $this->meta_description,
			) as $field => $value ) {
				if ( ! self::contains_keyword( $value, $this->focus_keyword ) ) {
					$warnings[] = self::warning(
						'focus_keyword_absent',
						$field,
						/* translators: %s: field name */
						sprintf( __( 'The focus keyword doesn\'t appear in the %s.', 'woo-ai-product-writer' ), str_replace( '_', ' ', $field ) )
					);
				}
			}
		}

		$unverified = self::unverified_numbers( $text . ' ' . $this->short_description . ' ' . $this->meta_description, $facts );
		if ( $unverified ) {
			$warnings[] = self::warning(
				'unverified_numbers',
				'description_html',
				/* translators: %s: list of numbers */
				sprintf( __( 'Mentions numbers that aren\'t in the product data: %s. Check them before publishing.', 'woo-ai-product-writer' ), implode( ', ', $unverified ) )
			);
		}

		$all = mb_strtolower( $text . ' ' . $this->short_description . ' ' . $this->meta_title . ' ' . $this->meta_description );
		foreach ( self::PLACEHOLDERS as $placeholder ) {
			if ( str_contains( $all, $placeholder ) ) {
				/* translators: %s: placeholder text */
				$warnings[] = self::warning( 'placeholder', 'description_html', sprintf( __( 'Contains placeholder text ("%s").', 'woo-ai-product-writer' ), $placeholder ) );
			}
		}

		return $warnings;
	}

	/**
	 * Numbers in the copy that appear nowhere in the facts. Catches invented capacities,
	 * percentages and warranty years, the most common way product copy goes wrong.
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @return string[]
	 */
	public static function unverified_numbers( string $copy, array $facts ): array {
		$known = array_flip( array_map( array( self::class, 'normalize_number' ), self::numbers_in( (string) wp_json_encode( $facts, JSON_UNESCAPED_UNICODE ) ) ) );

		$numbers = array();
		foreach ( array_unique( self::numbers_in( $copy ) ) as $number ) {
			$normalized = self::normalize_number( $number );
			// One-digit numbers are usually "2 pockets" or "1 of"; too noisy to flag.
			if ( strlen( $normalized ) < 2 || isset( $known[ $normalized ] ) ) {
				continue;
			}
			$numbers[] = $number;
		}
		return $numbers;
	}

	/**
	 * @return string[]
	 */
	private static function numbers_in( string $text ): array {
		// "1,200" is twelve hundred; "2,5" is a decimal comma.
		$text = (string) preg_replace( '/(\d),(\d{3})(?!\d)/', '$1$2', $text );
		preg_match_all( '/(?<![\p{L}\d])\d+(?:[.,]\d+)?/u', $text, $matches );
		return $matches[0];
	}

	private static function normalize_number( string $number ): string {
		$number = str_replace( ',', '.', $number );
		return str_contains( $number, '.' ) ? rtrim( rtrim( $number, '0' ), '.' ) : $number;
	}

	/**
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed_html(): array {
		$tags = array( 'p', 'ul', 'ol', 'li', 'strong', 'em', 'h3', 'br' );
		/**
		 * Filters the HTML tags allowed in generated descriptions.
		 *
		 * @param array<string,array<string,bool>> $allowed wp_kses() allowed-HTML array.
		 */
		return (array) apply_filters( 'wapw_allowed_description_html', array_fill_keys( $tags, array() ) );
	}

	private static function contains_keyword( string $text, string $keyword ): bool {
		$text = mb_strtolower( $text );
		if ( str_contains( $text, $keyword ) ) {
			return true;
		}
		// Accept the words in any order ("tote bag canvas" vs "canvas tote bag").
		foreach ( explode( ' ', $keyword ) as $word ) {
			if ( mb_strlen( $word ) > 2 && ! str_contains( $text, rtrim( $word, 's' ) ) ) {
				return false;
			}
		}
		return true;
	}

	private static function plain( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		// Models sometimes wrap a whole field in quotes; keep quotes that are part of the text.
		if ( preg_match( '/^(["“\'])(.*)(["”\'])$/u', $text, $m ) && ! preg_match( '/["“”]/u', $m[2] ) ) {
			$text = trim( $m[2] );
		}
		return $text;
	}

	private static function cut_words( string $text, int $max ): string {
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $max + 1 );
		$pos = mb_strrpos( $cut, ' ' );
		return rtrim( false !== $pos && $pos > $max / 2 ? mb_substr( $cut, 0, $pos ) : mb_substr( $text, 0, $max ), ' ,;:-–|' );
	}

	/**
	 * As many whole sentences as fit in $max characters, or a cut with an ellipsis
	 * when even the first sentence is too long.
	 */
	private static function first_sentences( string $text, int $max ): string {
		$text = trim( $text );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$out = '';
		foreach ( preg_split( '/(?<=[.!?])\s+/u', $text ) ?: array() as $sentence ) {
			$next = '' === $out ? $sentence : "$out $sentence";
			if ( mb_strlen( $next ) > $max ) {
				break;
			}
			$out = $next;
		}
		return '' !== $out ? $out : rtrim( self::cut_words( $text, $max - 1 ), ' ,;:' ) . '…';
	}

	/**
	 * @return array{code:string,field:string,message:string}
	 */
	private static function warning( string $code, string $field, string $message ): array {
		return array(
			'code'    => $code,
			'field'   => $field,
			'message' => $message,
		);
	}
}

function aggregateStats($data = null) {
    if ($data === null) return null;
    return $data;
}

function aggregateStats($data = null) {
    if ($data === null) return null;
    return $data;
}