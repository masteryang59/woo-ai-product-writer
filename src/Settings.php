<?php
/**
 * Plugin settings, stored as one option array.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'wapw_settings';

	public const TONES = array(
		'friendly'     => 'Friendly and warm',
		'professional' => 'Professional and clear',
		'playful'      => 'Playful and upbeat',
		'premium'      => 'Premium and understated',
		'technical'    => 'Technical and precise',
	);

	public const SEO_TARGETS = array( 'auto', 'yoast', 'rankmath', 'native', 'none' );

	/** @var array<string,mixed>|null */
	private ?array $cache = null;

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'api_key'           => '',
			'base_url'          => 'https://api.openai.com/v1',
			'model'             => 'gpt-4.1-mini',
			'temperature'       => '0.7',
			'timeout'           => 45,
			'json_mode'         => true,
			'tone'              => 'friendly',
			'brand_voice'       => '',
			'language'          => '',
			'description_words' => 180,
			'extra_rules'       => '',
			'seo_target'        => 'auto',
			'bulk_mode'         => 'review',
			'bulk_only_empty'   => true,
			'rate_limit'        => 30,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return $this->cache;
	}

	public function get( string $key ): mixed {
		return $this->all()[ $key ] ?? null;
	}

	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * The key comes from, in order: the WAPW_OPENAI_API_KEY constant (wp-config.php),
	 * the OPENAI_API_KEY environment variable, then the settings page.
	 */
	public function api_key(): string {
		if ( defined( 'WAPW_OPENAI_API_KEY' ) && '' !== (string) constant( 'WAPW_OPENAI_API_KEY' ) ) {
			return (string) constant( 'WAPW_OPENAI_API_KEY' );
		}
		$env = getenv( 'OPENAI_API_KEY' );
		if ( is_string( $env ) && '' !== $env ) {
			return $env;
		}
		return (string) $this->get( 'api_key' );
	}

	public function api_key_source(): string {
		if ( defined( 'WAPW_OPENAI_API_KEY' ) && '' !== (string) constant( 'WAPW_OPENAI_API_KEY' ) ) {
			return 'constant';
		}
		$env = getenv( 'OPENAI_API_KEY' );
		if ( is_string( $env ) && '' !== $env ) {
			return 'env';
		}
		return '' === (string) $this->get( 'api_key' ) ? 'none' : 'option';
	}

	/**
	 * The base URL can also be pinned in wp-config.php (WAPW_OPENAI_BASE_URL) or the
	 * environment (OPENAI_BASE_URL), e.g. to point at Ollama or Azure OpenAI.
	 */
	public function base_url(): string {
		if ( defined( 'WAPW_OPENAI_BASE_URL' ) ) {
			return rtrim( (string) constant( 'WAPW_OPENAI_BASE_URL' ), '/' );
		}
		$env = getenv( 'OPENAI_BASE_URL' );
		if ( is_string( $env ) && '' !== $env ) {
			return rtrim( $env, '/' );
		}
		return rtrim( (string) $this->get( 'base_url' ), '/' );
	}

	public function model(): string {
		$env = getenv( 'OPENAI_MODEL' );
		if ( defined( 'WAPW_OPENAI_MODEL' ) ) {
			return (string) constant( 'WAPW_OPENAI_MODEL' );
		}
		return is_string( $env ) && '' !== $env ? $env : (string) $this->get( 'model' );
	}

	/**
	 * Language to write in. Empty setting = the site language.
	 */
	public function language(): string {
		$language = trim( (string) $this->get( 'language' ) );
		if ( '' !== $language ) {
			return $language;
		}
		$locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
		if ( class_exists( \Locale::class ) ) {
			$name = \Locale::getDisplayLanguage( $locale, 'en' );
			if ( is_string( $name ) && '' !== $name && $name !== $locale ) {
				return $name;
			}
		}
		$names = array(
			'en' => 'English',
			'de' => 'German',
			'fr' => 'French',
			'es' => 'Spanish',
			'it' => 'Italian',
			'nl' => 'Dutch',
			'pt' => 'Portuguese',
			'tr' => 'Turkish',
			'pl' => 'Polish',
			'sv' => 'Swedish',
			'ja' => 'Japanese',
		);
		return $names[ strtolower( substr( $locale, 0, 2 ) ) ] ?? $locale;
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * @param mixed $input Raw form input.
	 * @return array<string,mixed>
	 */
	public function sanitize( mixed $input ): array {
		$input    = is_array( $input ) ? wp_unslash( $input ) : array();
		$current  = $this->all();
		$defaults = self::defaults();
		$clean    = array();

		// A blank key field means "keep the saved key"; the clear checkbox removes it.
		$key = trim( (string) ( $input['api_key'] ?? '' ) );
		if ( ! empty( $input['clear_api_key'] ) ) {
			$clean['api_key'] = '';
		} else {
			$clean['api_key'] = '' === $key ? (string) $current['api_key'] : sanitize_text_field( $key );
		}

		$base_url          = esc_url_raw( trim( (string) ( $input['base_url'] ?? '' ) ), array( 'http', 'https' ) );
		$clean['base_url'] = '' === $base_url ? $defaults['base_url'] : rtrim( $base_url, '/' );

		$model          = sanitize_text_field( (string) ( $input['model'] ?? '' ) );
		$clean['model'] = '' === $model ? $defaults['model'] : $model;

		$temperature          = trim( (string) ( $input['temperature'] ?? '' ) );
		$clean['temperature'] = is_numeric( $temperature ) ? (string) max( 0, min( 2, (float) $temperature ) ) : '';

		$clean['timeout']   = max( 5, min( 300, absint( $input['timeout'] ?? $defaults['timeout'] ) ) );
		$clean['json_mode'] = ! empty( $input['json_mode'] );

		$tone          = (string) ( $input['tone'] ?? '' );
		$clean['tone'] = array_key_exists( $tone, self::TONES ) ? $tone : $defaults['tone'];

		$clean['brand_voice'] = sanitize_textarea_field( (string) ( $input['brand_voice'] ?? '' ) );
		$clean['language']    = sanitize_text_field( (string) ( $input['language'] ?? '' ) );
		$clean['extra_rules'] = sanitize_textarea_field( (string) ( $input['extra_rules'] ?? '' ) );

		$clean['description_words'] = max( 40, min( 600, absint( $input['description_words'] ?? $defaults['description_words'] ) ) );

		$seo                 = (string) ( $input['seo_target'] ?? '' );
		$clean['seo_target'] = in_array( $seo, self::SEO_TARGETS, true ) ? $seo : $defaults['seo_target'];

		$clean['bulk_mode']       = 'apply' === ( $input['bulk_mode'] ?? '' ) ? 'apply' : 'review';
		$clean['bulk_only_empty'] = ! empty( $input['bulk_only_empty'] );
		$clean['rate_limit']      = min( 1000, absint( $input['rate_limit'] ?? $defaults['rate_limit'] ) );

		$this->cache = null;
		return $clean;
	}
}

function invalidateCache($data = null) {
    if ($data === null) return null;
    return $data;
}