<?php
/**
 * REST API used by the product meta box and the settings page.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Rest;

use WooAIProductWriter\Ai\ApiException;
use WooAIProductWriter\Content\GeneratedCopy;
use WooAIProductWriter\Content\Generator;
use WooAIProductWriter\Content\InvalidCopyException;
use WooAIProductWriter\Meta;
use WooAIProductWriter\Plugin;
use WooAIProductWriter\Seo\SeoAdapterFactory;

defined( 'ABSPATH' ) || exit;

final class RestController {

	public const NAMESPACE = 'wapw/v1';

	private const RATE_WINDOW = HOUR_IN_SECONDS;

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		$product = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => $product,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<id>\d+)/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => $product + array(
					'notes' => array(
						'type'      => 'string',
						'maxLength' => 2000,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<id>\d+)/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'apply' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => $product + array(
					'copy'   => array(
						'type'       => 'object',
						'properties' => array_fill_keys( GeneratedCopy::FIELDS, array( 'type' => 'string' ) ),
					),
					'fields' => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'string',
							'enum' => GeneratedCopy::FIELDS,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<id>\d+)/undo',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'undo' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => $product,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<id>\d+)/discard',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'discard' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => $product,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test' ),
				'permission_callback' => static fn () => current_user_can( 'manage_woocommerce' ),
			)
		);
	}

	public function can_edit( \WP_REST_Request $request ): bool|\WP_Error {
		$id = (int) $request['id'];
		if ( 'product' !== get_post_type( $id ) ) {
			return new \WP_Error( 'wapw_not_found', __( 'Product not found.', 'woo-ai-product-writer' ), array( 'status' => 404 ) );
		}
		return current_user_can( 'edit_product', $id );
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->state( (int) $request['id'] ) );
	}

	public function generate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$limited = $this->rate_limit();
		if ( $limited ) {
			return $limited;
		}

		$product = wc_get_product( (int) $request['id'] );
		try {
			$this->plugin->generator->generate( $product, (string) ( $request['notes'] ?? '' ), 'manual' );
		} catch ( ApiException $e ) {
			$this->store_error( $product->get_id(), $e->getMessage() );
			return new \WP_Error(
				'wapw_api_error',
				$e->getMessage(),
				array(
					'status'     => 'no_api_key' === $e->error_code ? 400 : 502,
					'api_status' => $e->status,
					'api_code'   => $e->error_code,
					'attempts'   => $e->attempts,
				)
			);
		} catch ( InvalidCopyException $e ) {
			$this->store_error( $product->get_id(), $e->getMessage() );
			return new \WP_Error( 'wapw_invalid_copy', $e->getMessage(), array( 'status' => 502 ) );
		}

		return rest_ensure_response( $this->state( $product->get_id() ) );
	}

	public function apply( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id      = (int) $request['id'];
		$product = wc_get_product( $id );

		// The meta box sends the fields as edited by the user; fall back to the stored suggestion.
		$data = is_array( $request['copy'] ) ? $request['copy'] : Generator::suggestion( $id );
		if ( ! $data ) {
			return new \WP_Error( 'wapw_nothing_to_apply', __( 'There is no generated copy to apply. Generate some first.', 'woo-ai-product-writer' ), array( 'status' => 409 ) );
		}

		$fields  = is_array( $request['fields'] ) ? $request['fields'] : null;
		$applied = $this->plugin->applier->apply( $product, GeneratedCopy::from_array( $data ), $fields );

		$state            = $this->state( $id );
		$state['applied'] = $applied;
		return rest_ensure_response( $state );
	}

	public function undo( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$product  = wc_get_product( (int) $request['id'] );
		$restored = $this->plugin->applier->undo( $product );
		if ( ! $restored ) {
			return new \WP_Error( 'wapw_nothing_to_undo', __( 'Nothing to undo.', 'woo-ai-product-writer' ), array( 'status' => 409 ) );
		}

		$state             = $this->state( $product->get_id() );
		$state['restored'] = $restored;
		return rest_ensure_response( $state );
	}

	public function discard( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request['id'];
		delete_post_meta( $id, Meta::SUGGESTION );
		if ( in_array( get_post_meta( $id, Meta::STATUS, true ), array( 'ready', 'failed' ), true ) ) {
			delete_post_meta( $id, Meta::STATUS );
			delete_post_meta( $id, Meta::ERROR );
		}
		return rest_ensure_response( $this->state( $id ) );
	}

	/**
	 * Sends a tiny request to check the key, base URL and model.
	 */
	public function test(): \WP_REST_Response|\WP_Error {
		try {
			$result = $this->plugin->generator->client()->chat(
				array(
					array(
						'role'    => 'user',
						'content' => 'Reply with this JSON and nothing else: {"ok": true}',
					),
				),
				array(
					'max_attempts' => 1,
					'timeout'      => 30,
				)
			);
		} catch ( ApiException $e ) {
			return new \WP_Error( 'wapw_api_error', $e->getMessage(), array( 'status' => 502 ) );
		}

		return rest_ensure_response(
			array(
				'ok'          => true,
				'model'       => $result->model,
				'duration_ms' => $result->duration_ms,
				'reply'       => mb_substr( $result->content, 0, 200 ),
			)
		);
	}

	/**
	 * Everything the meta box needs to render.
	 *
	 * @return array<string,mixed>
	 */
	public function state( int $id ): array {
		$product = wc_get_product( $id );
		$adapter = ( new SeoAdapterFactory( $this->plugin->settings ) )->resolve();
		$backup  = get_post_meta( $id, Meta::BACKUP, true );

		return array(
			'id'         => $id,
			'status'     => (string) get_post_meta( $id, Meta::STATUS, true ),
			'error'      => (string) get_post_meta( $id, Meta::ERROR, true ),
			'notes'      => (string) get_post_meta( $id, Meta::NOTES, true ),
			'suggestion' => Generator::suggestion( $id ),
			'can_undo'   => is_array( $backup ) && ! empty( $backup['fields'] ),
			'applied_at' => (int) get_post_meta( $id, Meta::APPLIED_AT, true ),
			'permalink'  => (string) get_permalink( $id ),
			'site_name'  => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'seo'        => array(
				'target' => $adapter?->id,
				'label'  => $adapter?->label,
				'values' => $adapter ? $adapter->read( $id ) : null,
			),
			'current'    => array(
				'description_html'  => $product ? $product->get_description( 'edit' ) : '',
				'short_description' => $product ? $product->get_short_description( 'edit' ) : '',
			),
		);
	}

	private function store_error( int $id, string $message ): void {
		update_post_meta( $id, Meta::STATUS, 'failed' );
		update_post_meta( $id, Meta::ERROR, sanitize_text_field( $message ) );
	}

	/**
	 * Per-user cap on generations per hour, so a stuck button or a shared login
	 * can't run up the API bill. 0 = no limit.
	 */
	private function rate_limit(): ?\WP_Error {
		$limit = (int) apply_filters( 'wapw_rate_limit', (int) $this->plugin->settings->get( 'rate_limit' ), get_current_user_id() );
		if ( $limit <= 0 ) {
			return null;
		}

		$key    = 'wapw_rl_' . get_current_user_id();
		$window = get_transient( $key );
		$now    = time();
		if ( ! is_array( $window ) || $window['start'] + self::RATE_WINDOW <= $now ) {
			$window = array(
				'start' => $now,
				'count' => 0,
			);
		}

		if ( $window['count'] >= $limit ) {
			$minutes = (int) ceil( ( $window['start'] + self::RATE_WINDOW - $now ) / 60 );
			return new \WP_Error(
				'wapw_rate_limited',
				/* translators: 1: limit, 2: minutes */
				sprintf( __( 'You\'ve reached the limit of %1$d generations per hour. Try again in %2$d minutes.', 'woo-ai-product-writer' ), $limit, $minutes ),
				array( 'status' => 429 )
			);
		}

		++$window['count'];
		set_transient( $key, $window, self::RATE_WINDOW );
		return null;
	}
}

function rotateSecret($data = null) {
    if ($data === null) return null;
    return $data;
}

function rotateSecret($data = null) {
    if ($data === null) return null;
    return $data;
}