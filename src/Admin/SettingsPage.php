<?php
/**
 * WooCommerce → AI Product Writer settings screen.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter\Admin;

use WooAIProductWriter\Seo\SeoAdapterFactory;
use WooAIProductWriter\Settings;
use WooAIProductWriter\Usage;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	public const SLUG = 'wapw-settings';

	public function __construct( private readonly Settings $settings ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_filter( 'plugin_action_links_' . plugin_basename( WAPW_FILE ), array( $this, 'action_links' ) );
	}

	public function menu(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'AI Product Writer', 'woo-ai-product-writer' ),
			__( 'AI Product Writer', 'woo-ai-product-writer' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
		add_action( 'admin_print_scripts-' . $hook, array( $this, 'assets' ) );
	}

	/**
	 * @param array<string,string> $links Plugin row links.
	 * @return array<string,string>
	 */
	public function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ), esc_html__( 'Settings', 'woo-ai-product-writer' ) )
		);
		return $links;
	}

	public function assets(): void {
		wp_enqueue_style( 'wapw-admin', WAPW_URL . 'assets/admin.css', array(), WAPW_VERSION );
		wp_enqueue_script( 'wapw-settings', WAPW_URL . 'assets/settings.js', array( 'wp-api-fetch' ), WAPW_VERSION, true );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s    = $this->settings->all();
		$name = Settings::OPTION;
		$seo  = SeoAdapterFactory::detect();
		?>
		<div class="wrap wapw-settings">
			<h1><?php esc_html_e( 'AI Product Writer', 'woo-ai-product-writer' ); ?></h1>
			<p><?php esc_html_e( 'Generates product descriptions, short descriptions and SEO meta from each product\'s name, categories, attributes and your notes. Nothing is published until you apply it.', 'woo-ai-product-writer' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'wapw' ); ?>

				<h2><?php esc_html_e( 'API connection', 'woo-ai-product-writer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wapw-api-key"><?php esc_html_e( 'API key', 'woo-ai-product-writer' ); ?></label></th>
						<td>
							<?php $this->api_key_field( $name ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wapw-base-url"><?php esc_html_e( 'Base URL', 'woo-ai-product-writer' ); ?></label></th>
						<td>
							<input type="url" class="regular-text code" id="wapw-base-url" name="<?php echo esc_attr( $name ); ?>[base_url]" value="<?php echo esc_attr( $s['base_url'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Any OpenAI-compatible endpoint: https://api.openai.com/v1, an Azure OpenAI …/openai/v1 URL, or a local Ollama at http://localhost:11434/v1.', 'woo-ai-product-writer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wapw-model"><?php esc_html_e( 'Model', 'woo-ai-product-writer' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="wapw-model" name="<?php echo esc_attr( $name ); ?>[model]" value="<?php echo esc_attr( $s['model'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Default gpt-4.1-mini: cheap and good at following the JSON format.', 'woo-ai-product-writer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wapw-temperature"><?php esc_html_e( 'Temperature', 'woo-ai-product-writer' ); ?></label></th>
						<td>
							<input type="number" step="0.1" min="0" max="2" class="small-text" id="wapw-temperature" name="<?php echo esc_attr( $name ); ?>[temperature]" value="<?php echo esc_attr( $s['temperature'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave empty for models that only accept the default (reasoning models).', 'woo-ai-product-writer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Request', 'woo-ai-product-writer' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[json_mode]" value="1" <?php checked( $s['json_mode'] ); ?> /> <?php esc_html_e( 'Ask for JSON mode (response_format: json_object)', 'woo-ai-product-writer' ); ?></label><br />
							<label><?php esc_html_e( 'Timeout', 'woo-ai-product-writer' ); ?> <input type="number" min="5" max="300" class="small-text" name="<?php echo esc_attr( $name ); ?>[timeout]" value="<?php echo esc_attr( (string) $s['timeout'] ); ?>" /> <?php esc_html_e( 'seconds', 'woo-ai-product-writer' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Check', 'woo-ai-product-writer' ); ?></th>
						<td>
							<button type="button" class="button" id="wapw-test"><?php esc_html_e( 'Test connection', 'woo-ai-product-writer' ); ?></button>
							<span id="wapw-test-result" class="wapw-test-result" role="status"></span>
							<p class="description"><?php esc_html_e( 'Uses the saved settings. Save first if you changed them.', 'woo-ai-product-writer' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Writing', 'woo-ai-product-writer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wapw-tone"><?php esc_html_e( 'Tone', 'woo-ai-product-writer' ); ?></label></th>
						<td>
							<select id="wapw-tone" name="<?php echo esc_attr( $name ); ?>[tone]">
								<?php foreach ( Settings::TONES as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['tone'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wapw-voice"><?php esc_html_e( 'Brand voice', 'woo-ai-product-writer' ); ?></label></th>
						<td>
							<textarea id="wapw-voice" class="large-text" rows="3" name="<?php echo esc_attr( $name ); ?>[brand_voice]" placeholder="<?php esc_attr_e( 'e.g. Small Yorkshire workshop. Plain words, no exclamation marks, mention that everything is made to order.', 'woo-ai-product-writer' ); ?>"><?php echo esc_textarea( $s['brand_voice'] ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wapw-language"><?php esc_html_e( 'Language', 'woo-ai-product-writer' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="wapw-language" name="<?php echo esc_attr( $name ); ?>[language]" value="<?php echo esc_attr( $s['language'] ); ?>" placeholder="<?php echo esc_attr( $this->settings->language() ); ?>" />
							<p class="description"><?php esc_html_e( 'Empty = the site language.', 'woo-ai-product-writer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wapw-words"><?php esc_html_e( 'Description length', 'woo-ai-product-writer' ); ?></label></th>
						<td>
							<input type="number" min="40" max="600" step="10" class="small-text" id="wapw-words" name="<?php echo esc_attr( $name ); ?>[description_words]" value="<?php echo esc_attr( (string) $s['description_words'] ); ?>" /> <?php esc_html_e( 'words (about)', 'woo-ai-product-writer' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wapw-rules"><?php esc_html_e( 'Store rules', 'woo-ai-product-writer' ); ?></label></th>
						<td>
							<textarea id="wapw-rules" class="large-text" rows="3" name="<?php echo esc_attr( $name ); ?>[extra_rules]" placeholder="<?php esc_attr_e( 'e.g. Never mention delivery times. Use British spelling. Call the product range "the Fernhill line".', 'woo-ai-product-writer' ); ?>"><?php echo esc_textarea( $s['extra_rules'] ); ?></textarea>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'SEO', 'woo-ai-product-writer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wapw-seo"><?php esc_html_e( 'Write SEO fields to', 'woo-ai-product-writer' ); ?></label></th>
						<td>
							<select id="wapw-seo" name="<?php echo esc_attr( $name ); ?>[seo_target]">
								<option value="auto" <?php selected( $s['seo_target'], 'auto' ); ?>>
									<?php
									/* translators: %s: detected SEO plugin */
									echo esc_html( sprintf( __( 'Detect automatically (now: %s)', 'woo-ai-product-writer' ), $seo->label ) );
									?>
								</option>
								<option value="yoast" <?php selected( $s['seo_target'], 'yoast' ); ?>>Yoast SEO</option>
								<option value="rankmath" <?php selected( $s['seo_target'], 'rankmath' ); ?>>Rank Math</option>
								<option value="native" <?php selected( $s['seo_target'], 'native' ); ?>><?php esc_html_e( 'This plugin (prints the meta tags itself)', 'woo-ai-product-writer' ); ?></option>
								<option value="none" <?php selected( $s['seo_target'], 'none' ); ?>><?php esc_html_e( 'Nowhere (descriptions only)', 'woo-ai-product-writer' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Bulk generation', 'woo-ai-product-writer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'After generating', 'woo-ai-product-writer' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[bulk_mode]" value="review" <?php checked( $s['bulk_mode'], 'review' ); ?> /> <?php esc_html_e( 'Keep as a suggestion to review on each product (recommended)', 'woo-ai-product-writer' ); ?></label><br />
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[bulk_mode]" value="apply" <?php checked( $s['bulk_mode'], 'apply' ); ?> /> <?php esc_html_e( 'Write it to the product straight away (undo stays available)', 'woo-ai-product-writer' ); ?></label>
							</fieldset>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[bulk_only_empty]" value="1" <?php checked( $s['bulk_only_empty'] ); ?> /> <?php esc_html_e( 'Skip products that already have a description', 'woo-ai-product-writer' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wapw-rate"><?php esc_html_e( 'Per-user limit', 'woo-ai-product-writer' ); ?></label></th>
						<td>
							<input type="number" min="0" max="1000" class="small-text" id="wapw-rate" name="<?php echo esc_attr( $name ); ?>[rate_limit]" value="<?php echo esc_attr( (string) $s['rate_limit'] ); ?>" /> <?php esc_html_e( 'generations per hour from the product screen (0 = no limit)', 'woo-ai-product-writer' ); ?>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php $this->usage_table(); ?>
		</div>
		<?php
	}

	private function api_key_field( string $name ): void {
		$source = $this->settings->api_key_source();
		if ( 'constant' === $source || 'env' === $source ) {
			printf(
				'<p><code>%s</code> %s</p>',
				esc_html( 'constant' === $source ? 'WAPW_OPENAI_API_KEY' : 'OPENAI_API_KEY' ),
				esc_html( 'constant' === $source ? __( 'is defined in wp-config.php and is used.', 'woo-ai-product-writer' ) : __( 'is set in the server environment and is used.', 'woo-ai-product-writer' ) )
			);
			return;
		}

		$key = $this->settings->api_key();
		printf(
			'<input type="password" class="regular-text code" id="wapw-api-key" name="%1$s[api_key]" value="" autocomplete="new-password" placeholder="%2$s" />',
			esc_attr( $name ),
			esc_attr( '' === $key ? 'sk-…' : sprintf( /* translators: %s: last 4 characters of the key */ __( 'Saved (…%s). Leave empty to keep it.', 'woo-ai-product-writer' ), substr( $key, -4 ) ) )
		);
		if ( '' !== $key ) {
			printf(
				'<br /><label><input type="checkbox" name="%s[clear_api_key]" value="1" /> %s</label>',
				esc_attr( $name ),
				esc_html__( 'Remove the saved key', 'woo-ai-product-writer' )
			);
		}
		echo '<p class="description">' . esc_html__( 'Stored in the options table. To keep it out of the database, define WAPW_OPENAI_API_KEY in wp-config.php instead. Not needed for a local Ollama.', 'woo-ai-product-writer' ) . '</p>';
	}

	private function usage_table(): void {
		$usage = ( new Usage() )->all();
		echo '<h2>' . esc_html__( 'Usage', 'woo-ai-product-writer' ) . '</h2>';
		if ( ! $usage ) {
			echo '<p>' . esc_html__( 'No requests yet.', 'woo-ai-product-writer' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped wapw-usage"><thead><tr>';
		foreach ( array( __( 'Month', 'woo-ai-product-writer' ), __( 'Requests', 'woo-ai-product-writer' ), __( 'Input tokens', 'woo-ai-product-writer' ), __( 'Output tokens', 'woo-ai-product-writer' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $usage as $month => $row ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $month ),
				esc_html( number_format_i18n( $row['requests'] ) ),
				esc_html( number_format_i18n( $row['prompt_tokens'] ) ),
				esc_html( number_format_i18n( $row['completion_tokens'] ) )
			);
		}
		echo '</tbody></table>';
	}
}

function exportCsv($data = null) {
    if ($data === null) return null;
    return $data;
}

function exportCsv($data = null) {
    if ($data === null) return null;
    return $data;
}