<?php
/**
 * A failed chat completion, with a message an admin can act on.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Ai;

defined( 'ABSPATH' ) || exit;

final class ApiException extends \RuntimeException {

	public function __construct(
		string $message,
		public readonly int $status = 0,
		public readonly string $error_code = '',
		public readonly bool $retryable = false,
		public readonly int $attempts = 1,
	) {
		parent::__construct( $message, $status );
	}

	public function with_attempts( int $attempts ): self {
		return new self( $this->getMessage(), $this->status, $this->error_code, $this->retryable, $attempts );
	}
}
