=== Gold Price Updater for WooCommerce ===
Contributors: hamidahangaryan
Tags: woocommerce, gold, price, dynamic-pricing, elementor
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatic gold price sync for WooCommerce: fetch, validate, buffer, and recalculate every managed product with a dedicated pricing engine.

== Description ==

Gold Price Updater keeps a gold shop's prices in step with the market without ever writing an unvalidated price:

* **Provider-agnostic API client** — URL, auth method (header / bearer / query / basic), request method, JSON path, unit, currency, karat and timeout are all settings. Presets included for the Price Intelligence Engine and TGJU; add more via a filter.
* **Pricing engine (single source of truth)** — BCMath-based formula: raw gold value (incl. optional accessory weight) → making charge → seller profit → VAT on making charge only → final price + fixed stone fee. No floating-point drift, configurable rounding.
* **Safety first** — zero/null/negative prices are rejected; a configurable max-change guard blocks abnormal swings; the last valid price is kept on any failure; manual price mode and API→manual fallback are built in.
* **Batch/queue processing** — products are updated in background batches via a custom queue table with atomic claims; a MySQL named lock prevents concurrent syncs; crashed workers recover via stale-claim reclaim. Updates start inside the sync request itself and pending batches also drain on page views, so price changes reach the storefront in seconds.
* **Realtime polling** — an optional (on by default) high-frequency poller checks the gold price every 15–3600 seconds and reprices instantly on a change; when the price is unchanged, the poll costs nothing.
* **Variable products** — every variation is priced independently (its own weight and making charge, falling back to the parent's values).
* **Accessories & per-product overrides** — optional accessory weight (priced as metal), fixed stone fee, and per-product seller profit / VAT overrides on each product.
* **Sale-price behavior is a setting** — "update regular price only" (default) or "skip on-sale products".
* **Logs + sync history** — structured, secret-redacted logs and a per-sync history table, both auto-pruned.
* **Elementor dynamic tags** — gold price (effective/API/buffer/manual/karat/currency/source), per-product fields (weight, accessory weight, stone fee, percents with raw-number or computed money-value display), per-product price breakdown incl. the final price, and last-update time. Every tag has Before/After text and a Fallback. Optional: the core never depends on Elementor.
* **GitHub updater** — point it at a repo and WordPress offers releases as normal updates.

== Installation ==

1. Upload the `gold-price-updater` folder to `wp-content/plugins/` (or the zip via Plugins → Add New → Upload).
2. Activate. WooCommerce must be active.
3. Open **WooCommerce → Gold Price Updater**, pick a provider preset (or Custom), fill the API URL/credentials, and press **Test API Connection**.
4. Set the buffer, karat, unit, profit and VAT; save.
5. On each product, tick **Managed by Gold Price Updater**, set its weight and **Making Charge %**, then press **Recalculate Preview** to sanity-check.
6. Press **Sync Gold Price Now**; products update in the background.

== Frequently Asked Questions ==

= What happens when the API is down? =
Nothing is written. The last valid price stays active; the failure is logged and (optionally) emailed. In fallback mode the manual price is used for syncs until the API recovers.

= Which products are managed? =
Only products where you ticked "Managed by Gold Price Updater" (meta `_gpu_managed`). Nothing else is ever touched.

= Is VAT applied to the whole price? =
No — VAT is calculated on the making charge only, per the project's pricing rules.

== Changelog ==

= 1.2.0 =
* New: realtime gold-price polling — check the price every 15s+ and update products the moment it changes; unchanged prices skip all product work.
* Changed: product updates now start immediately inside the sync request and drain on page views too — no more waiting minutes after a price change.
* Changed: queue fill inserts in batches of 200 rows per query; Sync Now / Force Sync process the queue before redirecting.

= 1.1.0 =
* Every plugin field is now available as an Elementor dynamic tag (four tags).
* Percent fields have two display modes: the raw stored number, or its computed money value.
* New per-product tags: product fields (weight, accessory weight, stone fee, percents, raw gold value) and the full price breakdown incl. the final price.
* All tags gained Before / After text and a Fallback text (e.g. add «تومان» after any price).

= 1.0.4 =
* New: accessory weight (grams) per product — extra metal priced at the effective gold price, with an opt-in "include in making charge" flag.
* New: fixed stone / accessory fee added to the final price.
* New: per-product seller profit % and VAT % overrides (empty = plugin default).
* Product preview now sends and shows the accessory/stone/override values; product meta box fully translated (fa_IR).

= 1.0.2 =
* New redesigned admin dashboard UI.
* Fixed times showing server UTC instead of the site-local timezone (wp_date).
* Fixed HTTP 401 on Test API when the API key is empty; added hints for common HTTP errors.

= 1.0.0 =
* Initial release: API client + presets, pricing engine, queue/batch updates, locking, retries, max-change guard, manual override, logs, sync history, Elementor dynamic tags, GitHub updater, full documentation.
