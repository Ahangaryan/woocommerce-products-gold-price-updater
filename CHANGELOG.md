# Changelog

All notable changes to Gold Price Updater for WooCommerce are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning follows [Semantic Versioning](https://semver.org/).

## [1.0.1] — 2026-09-03

### Fixed
- **Fatal error on dashboard** (`Class "GPU_Api_Providers" not found`):
  `class-gpu-api-providers.php` was not required in the plugin file, causing
  the dashboard to crash on every load.
- **Retry sleep never firing** (`class-gpu-sync.php`): `$products` was used
  instead of `$attempts` in `fetch_with_retry()`, so the inter-request sleep
  between API retries was never executed.
- **Product meta box never saved**: `GPU_Meta::save()` was registered but never
  hooked to any WordPress action; adding the `woocommerce_process_product_meta`
  hook so managed-flag and making-charge % are actually persisted.
- **WooCommerce 11 compatibility**: Updated `WC tested up to: 11.0` header
  (was 10.9); added `woocommerce.php` compatibility file declaring support for
  High-Performance Order Storage.

## [1.0.0] — 2026-09-03

### Added
- Provider-agnostic API client (auth: header/bearer/query/basic, GET/POST,
  JSON path, timeout) with presets for Price Intelligence Engine and TGJU.
- Pure BCMath pricing engine: raw gold value → making charge → seller profit
  → VAT on making charge only → final price, with optional rounding
  (nearest/up/down to a step).
- Price normalizer: unit (gram/ounce/kg/mithqal/mesghal + filterable),
  currency and karat (source → target purity ratio) conversions.
- Price modes: API / Manual / API-with-fallback-to-manual; manual price
  override; buffer.
- Safety: zero/null/negative price rejection at two layers, max-change guard
  with sticky admin notice and Force sync bypass, last-valid-price retention,
  sale-price behavior setting (regular-only default, skip-on-sale option),
  never writing regular ≤ sale.
- Background queue (custom table, atomic claims, stale-claim reclaim,
  self-rescheduling ticks, batch size + runtime bounds) under a MySQL named
  lock with crash-safe timeout; WP-Cron sync schedule (60s–daily) with retry
  (no blind retries on 401/403) and admin email notification.
- Product integration: managed flag, per-product/variation making charge,
  weight normalization from the shop unit, variation-first pricing with
  parent fallback, read-only preview AJAX on the product editor.
- Admin dashboard: status cards, progress bar, Sync Now / Force / Test
  Connection actions, grouped settings, sync history, log viewer.
- Structured logging with secret redaction and retention pruning.
- Elementor dynamic tags (effective/API/buffer/manual/source price,
  last-update time) — gracefully skipped without Elementor.
- GitHub Releases updater (opt-in via repo setting).
- Unit tests (15 tests / 37 assertions) covering the spec example, VAT-on-
  making-charge-only rule, rounding modes, karat/unit conversion and
  rejection rules; full documentation incl. agent-handoff.md.
