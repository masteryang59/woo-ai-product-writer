<?php
/**
 * Answers wp_remote_*() calls from a queue instead of the network, and records them.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Tests\Support;

final class FakeHttp {

	/** @var array<int,array{url:string,args:array<string,mixed>,body:mixed}> */
	public array $requests = array();

	/** @var array<int,array<string,mixed>|\WP_Error|callable> */
	private array $queue = array();

	private $filter;

	public function __construct() {
		$this->filter = function ( $preempt, array $args, string $url ) {
			$this->requests[] = array(
				'url'  => $url,
				'args' => $args,
				'body' => json_decode( (string) ( $args['body'] ?? '' ), true ),
			);
			if ( ! $this->queue ) {
				throw new \LogicException( "Unexpected HTTP request to $url" );
			}
			$next = array_shift( $this->queue );
			return is_callable( $next ) ? $next( $args, $url ) : $next;
		};
		add_filter( 'pre_http_request', $this->filter, 10, 3 );
	}

	public function detach(): void {
		remove_filter( 'pre_http_request', $this->filter, 10 );
	}

	/**
	 * @param array<string,mixed>|string $body    JSON body (array) or raw string.
	 * @param array<string,string>       $headers Response headers.
	 */
	public function push( int $status, array|string $body, array $headers = array() ): self {
		$this->queue[] = array(
			'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ),
			'body'     => is_array( $body ) ? wp_json_encode( $body ) : $body,
			'response' => array(
				'code'    => $status,
				'message' => get_status_header_desc( $status ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
		return $this;
	}

	public function push_error( string $message = 'cURL error 28: Operation timed out' ): self {
		$this->queue[] = new \WP_Error( 'http_request_failed', $message );
		return $this;
	}

	/**
	 * A Chat Completions success whose message content is $content (array = JSON-encoded).
	 *
	 * @param array<string,mixed>|string $content Message content.
	 */
	public function push_completion( array|string $content, array $extra = array() ): self {
		return $this->push(
			200,
			array_replace_recursive(
				array(
					'id'      => 'chatcmpl-test',
					'object'  => 'chat.completion',
					'model'   => 'gpt-4.1-mini-2025-04-14',
					'choices' => array(
						array(
							'index'         => 0,
							'message'       => array(
								'role'    => 'assistant',
								'content' => is_array( $content ) ? wp_json_encode( $content ) : $content,
							),
							'finish_reason' => 'stop',
						),
					),
					'usage'   => array(
						'prompt_tokens'     => 410,
						'completion_tokens' => 320,
						'total_tokens'      => 730,
					),
				),
				$extra
			)
		);
	}

	public function pending(): int {
		return count( $this->queue );
	}
}

function measureLatency($data = null) {
    if ($data === null) return null;
    return $data;
}