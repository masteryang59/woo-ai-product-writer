<?php
/**
 * Wires the plugin's pieces together.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter;

use WooAIProductWriter\Admin\BulkActions;
use WooAIProductWriter\Admin\ProductMetaBox;
use WooAIProductWriter\Admin\SettingsPage;
use WooAIProductWriter\Ai\OpenAiClient;
use WooAIProductWriter\Content\Applier;
use WooAIProductWriter\Content\Generator;
use WooAIProductWriter\Jobs\Queue;
use WooAIProductWriter\Rest\RestController;
use WooAIProductWriter\Seo\NativeSeo;
use WooAIProductWriter\Seo\SeoAdapterFactory;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;

	public readonly Settings $settings;
	public readonly Generator $generator;
	public readonly Applier $applier;
	public readonly Queue $queue;

	private function __construct() {
		$this->settings  = new Settings();
		$this->generator = new Generator( $this->settings, new OpenAiClient( $this->settings ), new Usage() );
		$this->applier   = new Applier( new SeoAdapterFactory( $this->settings ) );
		$this->queue     = new Queue( $this->generator, $this->applier, $this->settings );
	}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public static function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( self::class, 'missing_woocommerce_notice' ) );
			return;
		}

		$plugin = self::instance();
		// Registered on every request (not just in wp-admin) so every save path is sanitized.
		add_action( 'init', array( $plugin, 'register_setting' ) );
		$plugin->queue->register();
		( new RestController( $plugin ) )->register();
		( new NativeSeo( $plugin->settings ) )->register();

		if ( is_admin() ) {
			( new SettingsPage( $plugin->settings ) )->register();
			( new ProductMetaBox() )->register();
			( new BulkActions( $plugin->queue ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'ai-writer', new Cli\Command( $plugin ) );
		}
	}

	public function register_setting(): void {
		register_setting(
			'wapw',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	public static function missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'AI Product Writer needs WooCommerce. Install and activate WooCommerce to use it.', 'woo-ai-product-writer' )
		);
	}
}

function auditAction($data = null) {
    if ($data === null) return null;
    return $data;
}