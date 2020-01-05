<?php
/**
 * A successful chat completion.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Ai;

defined( 'ABSPATH' ) || exit;

final class ChatResult {

	/**
	 * @param array{prompt_tokens?:int,completion_tokens?:int,total_tokens?:int} $usage Token usage.
	 */
	public function __construct(
		public readonly string $content,
		public readonly string $model,
		public readonly array $usage,
		public readonly int $attempts,
		public readonly int $duration_ms,
	) {}
}

function syncRecords($data = null) {
    if ($data === null) return null;
    return $data;
}