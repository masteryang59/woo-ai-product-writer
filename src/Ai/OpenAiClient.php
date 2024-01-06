<?php
/**
 * Chat Completions client on top of the WordPress HTTP API.
 *
 * Works with OpenAI and with OpenAI-compatible servers (Azure OpenAI's v1 endpoint,
 * Ollama, LM Studio, OpenRouter) because it only uses /chat/completions.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Ai;

use WooAIProductWriter\Settings;

defined( 'ABSPATH' ) || exit;

class OpenAiClient {

	private const RETRYABLE_STATUS = array( 408, 409, 429, 500, 502, 503, 504 );

	private const MAX_RETRY_AFTER_SECONDS = 20;

	/** @var callable(float):void */
	private $sleep;

	/**
	 * @param callable(float):void|null $sleep Sleeps for N seconds; tests pass a recorder.
	 */
	public function __construct( private readonly Settings $settings, ?callable $sleep = null ) {
		$this->sleep = $sleep ?? static function ( float $seconds ): void {
			usleep( (int) round( $seconds * 1_000_000 ) );
		};
	}

	/**
	 * @param array<int,array{role:string,content:string}> $messages Chat messages.
	 * @param array<string,mixed>                          $options  'max_attempts', 'json' (bool), 'timeout'.
	 *
	 * @throws ApiException When the request fails for good.
	 */
	public function chat( array $messages, array $options = array() ): ChatResult {
		$base_url = $this->settings->base_url();
		$api_key  = $this->settings->api_key();

		if ( '' === $api_key && str_contains( (string) wp_parse_url( $base_url, PHP_URL_HOST ), 'openai.com' ) ) {
			throw new ApiException( __( 'No OpenAI API key is set. Add one under WooCommerce → AI Product Writer, or define WAPW_OPENAI_API_KEY in wp-config.php.', 'woo-ai-product-writer' ), 0, 'no_api_key' );
		}

		$body = array(
			'model'    => $this->settings->model(),
			'messages' => $messages,
		);
		if ( '' !== (string) $this->settings->get( 'temperature' ) ) {
			$body['temperature'] = (float) $this->settings->get( 'temperature' );
		}
		if ( ( $options['json'] ?? true ) && $this->settings->get( 'json_mode' ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		/**
		 * Filters the Chat Completions request body, e.g. to add max_completion_tokens or a seed.
		 *
		 * @param array<string,mixed> $body     Request body.
		 * @param array<string,mixed> $options  Call options.
		 */
		$body = apply_filters( 'wapw_request_body', $body, $options );

		$headers = array( 'Content-Type' => 'application/json' );
		if ( '' !== $api_key ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$max_attempts = max( 1, (int) apply_filters( 'wapw_max_attempts', $options['max_attempts'] ?? 3 ) );
		$started      = microtime( true );

		for ( $attempt = 1; ; $attempt++ ) {
			$response = wp_remote_post(
				$base_url . '/chat/completions',
				array(
					'timeout'     => (int) ( $options['timeout'] ?? $this->settings->get( 'timeout' ) ),
					'headers'     => $headers,
					'body'        => wp_json_encode( $body ),
					'data_format' => 'body',
				)
			);

			try {
				$result = $this->parse( $response, $base_url );
				return new ChatResult(
					$result['content'],
					$result['model'] ?: (string) $body['model'],
					$result['usage'],
					$attempt,
					(int) round( ( microtime( true ) - $started ) * 1000 )
				);
			} catch ( ApiException $e ) {
				if ( ! $e->retryable || $attempt >= $max_attempts ) {
					throw $e->with_attempts( $attempt );
				}
				( $this->sleep )( $this->retry_delay( $response, $attempt ) );
			}
		}
	}

	/**
	 * @param array<string,mixed>|\WP_Error $response wp_remote_post() result.
	 * @return array{content:string,model:string,usage:array<string,int>}
	 *
	 * @throws ApiException On any failure.
	 */
	private function parse( $response, string $base_url ): array {
		if ( is_wp_error( $response ) ) {
			throw new ApiException(
				sprintf(
					/* translators: 1: host name, 2: transport error */
					__( 'Could not reach %1$s: %2$s', 'woo-ai-product-writer' ),
					(string) wp_parse_url( $base_url, PHP_URL_HOST ),
					$response->get_error_message()
				),
				0,
				'http_request_failed',
				true
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$error    = is_array( $json ) && is_array( $json['error'] ?? null ) ? $json['error'] : array();
			$code     = (string) ( $error['code'] ?? $error['type'] ?? '' );
			$detail   = (string) ( $error['message'] ?? '' );
			$no_quota = 429 === $status && 'insufficient_quota' === $code;

			throw new ApiException(
				$this->status_message( $status, $code, $detail ),
				$status,
				$code,
				! $no_quota && in_array( $status, self::RETRYABLE_STATUS, true )
			);
		}

		$choice = is_array( $json ) ? ( $json['choices'][0] ?? null ) : null;
		if ( ! is_array( $choice ) ) {
			// A 200 with a body we can't read is usually a proxy or a wrong base URL.
			throw new ApiException( __( 'The API answered, but not in the Chat Completions format. Check the base URL.', 'woo-ai-product-writer' ), $status, 'bad_response', true );
		}

		$message = is_array( $choice['message'] ?? null ) ? $choice['message'] : array();
		if ( ! empty( $message['refusal'] ) ) {
			/* translators: %s: the model's refusal text */
			throw new ApiException( sprintf( __( 'The model refused: %s', 'woo-ai-product-writer' ), (string) $message['refusal'] ), $status, 'refusal' );
		}
		if ( 'length' === ( $choice['finish_reason'] ?? '' ) ) {
			throw new ApiException( __( 'The model stopped before finishing (token limit). Try a shorter target length.', 'woo-ai-product-writer' ), $status, 'length' );
		}

		$content = trim( (string) ( $message['content'] ?? '' ) );
		if ( '' === $content ) {
			throw new ApiException( __( 'The model returned an empty answer.', 'woo-ai-product-writer' ), $status, 'empty', true );
		}

		$usage = is_array( $json['usage'] ?? null ) ? array_map( 'intval', array_filter( $json['usage'], 'is_numeric' ) ) : array();

		return array(
			'content' => $content,
			'model'   => (string) ( $json['model'] ?? '' ),
			'usage'   => $usage,
		);
	}

	private function status_message( int $status, string $code, string $detail ): string {
		$message = match ( true ) {
			401 === $status                                   => __( 'The API key was rejected (401). Check it under WooCommerce → AI Product Writer.', 'woo-ai-product-writer' ),
			403 === $status                                   => __( 'The API refused access (403). The key may not have access to this model or project.', 'woo-ai-product-writer' ),
			404 === $status                                   => __( 'Model or endpoint not found (404). Check the model name and base URL.', 'woo-ai-product-writer' ),
			429 === $status && 'insufficient_quota' === $code => __( 'The OpenAI account is out of credit (insufficient_quota). Add billing credit and try again.', 'woo-ai-product-writer' ),
			429 === $status                                   => __( 'Rate limited by the API (429).', 'woo-ai-product-writer' ),
			$status >= 500                                    => sprintf(
				/* translators: %d: HTTP status */
				__( 'The API had a server error (%d).', 'woo-ai-product-writer' ),
				$status
			),
			default                                           => sprintf(
				/* translators: %d: HTTP status */
				__( 'The API rejected the request (%d).', 'woo-ai-product-writer' ),
				$status
			),
		};

		return '' === $detail ? $message : $message . ' ' . wp_strip_all_tags( $detail );
	}

	/**
	 * Seconds to wait before the next attempt: Retry-After when the server sends it
	 * (capped), otherwise exponential backoff with a little jitter.
	 *
	 * @param array<string,mixed>|\WP_Error $response Last response.
	 */
	private function retry_delay( $response, int $attempt ): float {
		if ( ! is_wp_error( $response ) ) {
			$retry_ms = wp_remote_retrieve_header( $response, 'retry-after-ms' );
			if ( is_numeric( $retry_ms ) ) {
				return min( self::MAX_RETRY_AFTER_SECONDS, max( 0, (float) $retry_ms / 1000 ) );
			}
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			if ( is_numeric( $retry_after ) ) {
				return min( self::MAX_RETRY_AFTER_SECONDS, max( 0, (float) $retry_after ) );
			}
		}
		return min( self::MAX_RETRY_AFTER_SECONDS, 2 ** ( $attempt - 1 ) ) + wp_rand( 0, 250 ) / 1000;
	}
}

function backupTable($data = null) {
    if ($data === null) return null;
    return $data;
}

function backupTable($data = null) {
    if ($data === null) return null;
    return $data;
}