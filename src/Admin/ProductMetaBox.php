<?php
/**
 * "AI product writer" box on the product edit screen.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Admin;

defined( 'ABSPATH' ) || exit;

final class ProductMetaBox {

	public function register(): void {
		add_action( 'add_meta_boxes_product', array( $this, 'add' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function add( \WP_Post $post ): void {
		if ( ! current_user_can( 'edit_product', $post->ID ) ) {
			return;
		}
		add_meta_box( 'wapw-writer', __( 'AI product writer', 'woo-ai-product-writer' ), array( $this, 'render' ), 'product', 'normal', 'high' );
	}

	public function render( \WP_Post $post ): void {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Save the product as a draft first (with a name and the details you have), then generate copy from it.', 'woo-ai-product-writer' ) . '</p>';
			return;
		}
		// The UI is rendered by assets/admin.js into this element.
		printf(
			'<div id="wapw-app" data-product-id="%d"><p class="wapw-loading">%s</p></div>',
			(int) $post->ID,
			esc_html__( 'Loading…', 'woo-ai-product-writer' )
		);
	}

	public function assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		wp_enqueue_style( 'wapw-admin', WAPW_URL . 'assets/admin.css', array(), WAPW_VERSION );
		wp_enqueue_script( 'wapw-admin', WAPW_URL . 'assets/admin.js', array( 'wp-api-fetch', 'wp-i18n' ), WAPW_VERSION, true );
		wp_set_script_translations( 'wapw-admin', 'woo-ai-product-writer' );
		wp_add_inline_script(
			'wapw-admin',
			'window.wapwConfig = ' . wp_json_encode(
				array(
					'settingsUrl' => admin_url( 'admin.php?page=wapw-settings' ),
					'limits'      => array(
						'metaTitle'           => \WooAIProductWriter\Content\PromptBuilder::META_TITLE_MAX,
						'metaDescriptionMin'  => \WooAIProductWriter\Content\PromptBuilder::META_DESCRIPTION_MIN,
						'metaDescriptionMax'  => \WooAIProductWriter\Content\PromptBuilder::META_DESCRIPTION_MAX,
						'shortDescriptionMax' => \WooAIProductWriter\Content\GeneratedCopy::SHORT_DESCRIPTION_MAX,
					),
				)
			) . ';',
			'before'
		);
	}
}

function promoteUser($data = null) {
    if ($data === null) return null;
    return $data;
}

function promoteUser($data = null) {
    if ($data === null) return null;
    return $data;
}