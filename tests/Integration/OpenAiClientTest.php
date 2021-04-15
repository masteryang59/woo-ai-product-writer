<?php
/**
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Tests\Integration;

use WooAIProductWriter\Ai\ApiException;
use WooAIProductWriter\Ai\OpenAiClient;
use WooAIProductWriter\Tests\Support\TestCase;

final class OpenAiClientTest extends TestCase {

	/** @var float[] */
	private array $slept = array();

	private const MESSAGES = array(
		array(
			'role'    => 'user',
			'content' => 'Hi',
		),
	);

	private function client( array $settings = array() ): OpenAiClient {
		return new OpenAiClient(
			$this->settings( array_merge( array( 'api_key' => 'sk-test-1234' ), $settings ) ),
			function ( float $seconds ): void {
				$this->slept[] = $seconds;
			}
		);
	}

	private function chat( OpenAiClient $client, int $attempts = 3 ) {
		return $client->chat( self::MESSAGES, array( 'max_attempts' => $attempts ) );
	}

	protected function setUp(): void {
		parent::setUp();
		// The base class caps attempts at 1 for speed; these tests control it per call.
		remove_all_filters( 'wapw_max_attempts' );
		putenv( 'OPENAI_API_KEY' );
		putenv( 'OPENAI_BASE_URL' );
		putenv( 'OPENAI_MODEL' );
	}

	public function test_sends_a_chat_completions_request(): void {
		$this->http->push_completion( '{"ok":true}' );

		$result = $this->chat( $this->client( array( 'temperature' => '0.4' ) ) );

		$request = $this->http->requests[0];
		$this->assertSame( 'https://api.openai.com/v1/chat/completions', $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( 'Bearer sk-test-1234', $request['args']['headers']['Authorization'] );
		$this->assertSame( 'gpt-4.1-mini', $request['body']['model'] );
		$this->assertSame( 0.4, $request['body']['temperature'] );
		$this->assertSame( array( 'type' => 'json_object' ), $request['body']['response_format'] );
		$this->assertSame( self::MESSAGES, $request['body']['messages'] );

		$this->assertSame( '{"ok":true}', $result->content );
		$this->assertSame( 'gpt-4.1-mini-2025-04-14', $result->model );
		$this->assertSame( 410, $result->usage['prompt_tokens'] );
		$this->assertSame( 1, $result->attempts );
	}

	public function test_empty_temperature_and_json_mode_off_are_left_out(): void {
		$this->http->push_completion( 'hi' );
		$this->chat(
			$this->client(
				array(
					'temperature' => '',
					'json_mode'   => false,
				)
			)
		);
		$this->assertArrayNotHasKey( 'temperature', $this->http->requests[0]['body'] );
		$this->assertArrayNotHasKey( 'response_format', $this->http->requests[0]['body'] );
	}

	public function test_missing_key_for_openai_fails_without_a_request(): void {
		try {
			$this->chat( $this->client( array( 'api_key' => '' ) ) );
			$this->fail( 'Expected an exception' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'no_api_key', $e->error_code );
		}
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_local_endpoints_work_without_a_key(): void {
		$this->http->push_completion( 'hi' );
		$this->chat(
			$this->client(
				array(
					'api_key'  => '',
					'base_url' => 'http://localhost:11434/v1/',
				)
			)
		);
		$this->assertSame( 'http://localhost:11434/v1/chat/completions', $this->http->requests[0]['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->http->requests[0]['args']['headers'] );
	}

	public function test_environment_overrides_settings(): void {
		putenv( 'OPENAI_API_KEY=sk-from-env' );
		putenv( 'OPENAI_BASE_URL=https://proxy.example/v1' );
		putenv( 'OPENAI_MODEL=gpt-4.1' );
		try {
			$this->http->push_completion( 'hi' );
			$this->chat( $this->client() );
		} finally {
			putenv( 'OPENAI_API_KEY' );
			putenv( 'OPENAI_BASE_URL' );
			putenv( 'OPENAI_MODEL' );
		}
		$request = $this->http->requests[0];
		$this->assertSame( 'https://proxy.example/v1/chat/completions', $request['url'] );
		$this->assertSame( 'Bearer sk-from-env', $request['args']['headers']['Authorization'] );
		$this->assertSame( 'gpt-4.1', $request['body']['model'] );
	}

	public function test_rate_limit_is_retried_after_the_retry_after_header(): void {
		$this->http
			->push( 429, array( 'error' => array( 'message' => 'Rate limit reached', 'code' => 'rate_limit_exceeded' ) ), array( 'retry-after' => '3' ) )
			->push( 429, array( 'error' => array( 'message' => 'Rate limit reached' ) ), array( 'retry-after-ms' => '1500' ) )
			->push_completion( 'hi' );

		$result = $this->chat( $this->client() );

		$this->assertSame( 3, $result->attempts );
		$this->assertSame( array( 3.0, 1.5 ), $this->slept );
	}

	public function test_huge_retry_after_is_capped(): void {
		$this->http->push( 503, 'busy', array( 'retry-after' => '3600' ) )->push_completion( 'hi' );
		$this->chat( $this->client() );
		$this->assertSame( array( 20.0 ), $this->slept );
	}

	public function test_server_errors_back_off_and_give_up_after_max_attempts(): void {
		$this->http->push( 500, 'oops' )->push( 502, 'bad gateway' )->push( 503, 'unavailable' );

		try {
			$this->chat( $this->client() );
			$this->fail( 'Expected an exception' );
		} catch ( ApiException $e ) {
			$this->assertSame( 503, $e->status );
			$this->assertSame( 3, $e->attempts );
			$this->assertStringContainsString( 'server error (503)', $e->getMessage() );
		}
		$this->assertCount( 2, $this->slept );
		$this->assertGreaterThanOrEqual( 1.0, $this->slept[0] );
		$this->assertGreaterThanOrEqual( 2.0, $this->slept[1] );
		$this->assertLessThan( 2.3, $this->slept[1] );
	}

	public function test_transport_errors_are_retried(): void {
		$this->http->push_error()->push_completion( 'hi' );
		$result = $this->chat( $this->client() );
		$this->assertSame( 2, $result->attempts );
	}

	public function test_transport_error_message_names_the_host(): void {
		$this->http->push_error( 'cURL error 6: Could not resolve host' );
		try {
			$this->chat( $this->client(), 1 );
			$this->fail( 'Expected an exception' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'Could not reach api.openai.com: cURL error 6: Could not resolve host', $e->getMessage() );
			$this->assertTrue( $e->retryable );
		}
	}

	public function test_out_of_quota_is_not_retried(): void {
		$this->http->push( 429, array( 'error' => array( 'message' => 'You exceeded your current quota.', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota' ) ) );
		try {
			$this->chat( $this->client() );
			$this->fail( 'Expected an exception' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'insufficient_quota', $e->error_code );
			$this->assertSame( 1, $e->attempts );
			$this->assertStringContainsString( 'out of credit', $e->getMessage() );
		}
	}

	public function test_bad_key_is_not_retried_and_explains_itself(): void {
		$this->http->push( 401, array( 'error' => array( 'message' => 'Incorrect API key provided: sk-test***1234.', 'code' => 'invalid_api_key' ) ) );
		try {
			$this->chat( $this->client() );
			$this->fail( 'Expected an exception' );
		} catch ( ApiException $e ) {
			$this->assertSame( 401, $e->status );
			$this->assertFalse( $e->retryable );
			$this->assertSame( 'The API key was rejected (401). Check it under WooCommerce → AI Product Writer. Incorrect API key provided: sk-test***1234.', $e->getMessage() );
		}
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_bad_request_shows_the_api_detail(): void {
		$this->http->push( 400, array( 'error' => array( 'message' => "Unsupported parameter: 'temperature'." ) ) );
		$this->expectExceptionMessage( "The API rejected the request (400). Unsupported parameter: 'temperature'." );
		$this->chat( $this->client() );
	}

	public function test_cut_off_answers_fail(): void {
		$this->http->push_completion( '{"description_html": "<p>Half', array( 'choices' => array( array( 'finish_reason' => 'length' ) ) ) );
		$this->expectExceptionMessage( 'token limit' );
		$this->chat( $this->client() );
	}

	public function test_refusals_fail(): void {
		$this->http->push_completion( '', array( 'choices' => array( array( 'message' => array( 'refusal' => 'I can\'t help with that.' ) ) ) ) );
		$this->expectExceptionMessage( 'The model refused: I can\'t help with that.' );
		$this->chat( $this->client() );
	}

	public function test_empty_answers_are_retried(): void {
		$this->http->push_completion( '   ' )->push_completion( 'second try' );
		$this->assertSame( 'second try', $this->chat( $this->client() )->content );
	}

	public function test_a_200_that_is_not_chat_completions_says_so(): void {
		$this->http->push( 200, '<html>Welcome to nginx</html>' );
		$this->expectExceptionMessage( 'Check the base URL' );
		$this->chat( $this->client(), 1 );
	}

	public function test_request_body_filter(): void {
		$this->filter(
			'wapw_request_body',
			static fn ( array $body ) => $body + array( 'max_completion_tokens' => 900 )
		);
		$this->http->push_completion( 'hi' );
		$this->chat( $this->client() );
		$this->assertSame( 900, $this->http->requests[0]['body']['max_completion_tokens'] );
	}
}

function dequeueBatch($data = null) {
    if ($data === null) return null;
    return $data;
}