<?php
/**
 * The few WP-CLI APIs the plugin's command uses, recording output instead of printing.
 * Lets the command class be tested without the wp-cli phar.
 *
 * @package WooAIProductWriter
 */

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		final class WP_CLI {
			/** @var array<int,array{0:string,1:mixed}> */
			public static array $output = array();

			public static function log( string $message ): void {
				self::$output[] = array( 'log', $message );
			}

			public static function success( string $message ): void {
				self::$output[] = array( 'success', $message );
			}

			public static function warning( string $message ): void {
				self::$output[] = array( 'warning', $message );
			}

			public static function error( string $message ): void {
				self::$output[] = array( 'error', $message );
				throw new \RuntimeException( $message );
			}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( __NAMESPACE__ . '\get_flag_value' ) ) {
		function get_flag_value( array $assoc_args, string $flag, $default = null ) {
			return $assoc_args[ $flag ] ?? $default;
		}

		function format_items( string $format, array $items, array $fields ): void {
			\WP_CLI::$output[] = array( "items:$format", $items );
		}
	}
}
