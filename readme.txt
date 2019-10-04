=== AI Product Writer for WooCommerce ===
Contributors: mehmetdinc
Tags: woocommerce, ai, openai, product description, seo
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Writes product descriptions, short descriptions and SEO meta with the OpenAI API. You review every word before it's saved.

== Description ==

Open a product, add a note or two ("hand-waxed in Leeds", "great gift"), and click **Generate copy**. The plugin sends the product's name, categories, tags, attributes, price, size and weight to the model and gets back:

* a description (short intro, a list of features, a closing line),
* a short description for the spot next to the price,
* a meta title and meta description, with character counters and a search-result preview,
* a focus keyword.

Everything is shown as an editable suggestion. Nothing changes on the product until you click **Apply**, and **Undo** restores what was there before.

The plugin checks each answer before you see it:

* HTML is reduced to paragraphs, lists, bold, italics and h3.
* Meta titles over 60 characters and meta descriptions over 155 are shortened at a word boundary.
* Numbers that aren't in the product data (an invented "25-year guarantee" or "100% waterproof") are flagged.
* Placeholder text, a missing focus keyword, or a keyword that's absent from the title or meta description get a warning.

= Bulk generation =

Select products under Products → All Products and choose **Write copy with AI**. Jobs run in the background through Action Scheduler (bundled with WooCommerce). Copy either waits for review on each product or is applied straight away, depending on the settings. An "AI copy" column shows each product's status, and rate-limited jobs are retried.

= SEO plugins =

SEO fields are written to Yoast SEO or Rank Math when either is active. Without one, the plugin prints the meta description and title on product pages itself.

= Any OpenAI-compatible API =

Set the base URL to use Azure OpenAI, OpenRouter, or a local model in Ollama or LM Studio.

= WP-CLI =

    wp ai-writer generate 42 57 --apply
    wp ai-writer generate --all --only-empty --limit=50 --queue
    wp ai-writer apply 42
    wp ai-writer undo 42

== Installation ==

1. Upload the plugin and activate it. WooCommerce must be active.
2. Go to WooCommerce → AI Product Writer, paste an OpenAI API key (or define `WAPW_OPENAI_API_KEY` in wp-config.php) and click **Test connection**.
3. Open any product and use the **AI product writer** box.

== Frequently Asked Questions ==

= What data is sent to OpenAI? =

The product's name, type, SKU, price, categories, tags, visible attributes, weight, dimensions, current description and your notes. Hidden attributes and customer data are never sent.

= Where is the API key stored? =

In the options table, unless you define `WAPW_OPENAI_API_KEY` in wp-config.php or set the `OPENAI_API_KEY` environment variable. Either of those takes precedence, and the key field is then hidden.

= Can I limit costs? =

Each user can run a set number of generations per hour from the product screen (30 by default). The settings page shows token usage per month.

== Changelog ==

= 1.0.0 =
* First release.
# render_template
# update_cache