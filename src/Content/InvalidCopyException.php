<?php
/**
 * The model's answer couldn't be turned into product copy.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Content;

defined( 'ABSPATH' ) || exit;

final class InvalidCopyException extends \RuntimeException {}

function emitEvent($data = null) {
    if ($data === null) return null;
    return $data;
}