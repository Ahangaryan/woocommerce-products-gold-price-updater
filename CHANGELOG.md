# Changelog

All notable changes to Gold Price Updater for WooCommerce are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning follows [Semantic Versioning](https://semver.org/).

## [1.3.0] — 2026-10-03

### Added
- **Configurable number separators** (dashboard → Pricing): a
  **Thousands Separator** (default `.`) and a **Decimal Separator**
  (default `,`) now control every number the plugin renders — dashboard
  cards, sync history, admin notices and all Elementor tags.
  - Empty Thousands Separator = no digit grouping at all (`10200000`).
  - Any single character works, including multi-byte ones (`٬`, space, `|`);
    letters/digits are rejected on save so a separator can never corrupt a
    price.
- New `GPU_Formatter` class: the single place that decides how numbers look
  (replaces direct `number_format_i18n()` calls throughout the plugin).

### Changed
- Default thousands separator is now `.` (was the WordPress locale
  separator, typically `,`). Existing installs pick the new defaults up
  automatically via settings-merge.
- Raw percent display (Product Fields tag, mode 1) is now emitted with a
  plain `.` decimal and no grouping — it always mirrors the exact value
  entered in the product editor (7.7 stays "7.7").

## [1.2.0] — 2026-09-28

### Added
- **Visible "Realtime Poller" status card on the dashboard**: shows whether
  the poller is enabled, a live "next poll in …" countdown, and the outcome
  of the last poll (price checked / failed + how many products were
  repriced) — driven by new `last_realtime_*` state keys written by
  `realtime_tick()`.
- **Realtime gold-price polling** (`realtime_enabled`, on by default): a
  dedicated high-frequency cron event (`gpu_realtime_cron`, custom schedule
  `gpu_realtime`, interval 15s–3600s, default 60s) re-runs the whole sync
  pipeline without the max-change guard, so product prices follow the live
  market within seconds of a change instead of waiting up to a full sync
  interval plus background batch time.

### Changed
- **Fast path on unchanged prices**: when a fetched price equals the stored
  one, the sync only records state + a `no_change` history row and never
  scans or touches products — this is what makes minute-level polling safe
  and cheap ("if the price didn't change, nothing happens").
- **Prices now apply in seconds, not minutes**: the sync pipeline
  front-loads the queue — as many products as the request budget allows are
  repriced inside the sync request itself; the remainder drains via
  self-rescheduling `gpu_queue_tick` events (now 5s apart) and a new
  request-level front-load that processes one batch per page view (frontend
  included) whenever cron lags.
- Queue fill uses one multi-row `INSERT` per 200 products instead of one
  query per product.
- The admin **Sync Now / Force Sync** handlers also front-load the queue, so
  manual updates feel instant on small/medium catalogs.
- Sync history now records a `no_change` status; new log events
  `sync_no_change` / `realtime_retry`.

### Fixed
- **Decimal percents are no longer rounded on display**: the Product Fields
  tag's raw-percent mode showed the stored number through the widget's
  money "Decimals" control (default 0), turning 7.7 into 8. The raw number
  is now always displayed exactly as stored (7.7, 8.25, …); the Decimals
  control keeps affecting money-value display only. The pricing engine has
  always calculated with the exact stored percent (BCMath) — this was a
  display bug, not a calculation bug.

### Notes
- The realtime poller deliberately bypasses the max-change guard (live
  market prices move legitimately); the regular `update_interval` sync keeps
  the guard. The fast path relies on the pricing engine being deterministic
  — documented as a "do not change" invariant in agent-handoff.md.

## [1.1.0] — 2026-09-28

### Added
- **Every plugin field is now an Elementor dynamic tag** — four tags, all
  sharing a new `GPU_Elem_Base`:
  - `GPU_Elem_Price` (existing, extended): effective/API/buffer/manual price,
    plus new **target karat** and **target currency** variants.
  - `GPU_Elem_Product_Fields` (new): product weight, accessory weight, stone
    fee, raw gold value, and the three percents (making charge / seller
    profit / VAT) with **two display modes** — the raw stored number, or the
    percent's computed money value (always consistent with the pricing
    engine, so displayed components add up to the final price).
  - `GPU_Elem_Product_Price` (new): the full per-product breakdown from the
    pricing engine, including the **final price** — computed live with the
    current effective gold price, read-only.
  - `GPU_Elem_Updated` (existing, moved onto the base).
- **Before / After / Fallback on every tag**: wrap any value with custom
  text (e.g. « … » تومان) and show a fallback string when the field is
  empty (before the first sync, missing weight, etc.). Elementor's own tag
  fallback takes precedence when set; the plugin's wrapper fills otherwise.
- Persian translations for all new tag UI strings.

## [1.0.4] — 2026-09-26

### Added
- **Accessory weight (extras)**: per-product/variation `_gpu_accessory_weight`
  meta (grams) is priced at the effective gold price and added to the raw gold
  value — stone setting, second alloys, etc. Priced as metal only by default;
  a per-product `_gpu_acc_in_making` flag extends the making charge % to the
  accessory gold value too.
- **Stone / accessory fee**: fixed `_gpu_stone_fee` meta added to the final
  price (shop currency) — carries no making charge and no VAT.
- **Per-product seller profit % and VAT % overrides** (`_gpu_seller_profit_pct`,
  `_gpu_vat_pct`): product → parent → plugin default, mirroring the making
  charge resolution chain.
- Persian translations for the whole product meta box via `GPU_Translations`.

### Fixed
- **Product preview missed the new fields**: `assets/preview.js` only sent the
  making charge, so the live breakdown ignored unsaved accessory weight, stone
  fee and profit/VAT overrides. It now posts every field and shows accessory
  gold value and stone fee in the result.
- Docs updated (pricing-engine.md formula + inputs, database.md meta table,
  agent-handoff.md formula/meta, usage.md product setup) — the pricing docs
  had drifted from the engine after the accessory feature.

## [1.0.3] — 2026-09-08

### Fixed
- **Elementor dynamic tags ignored their control values**: the "Date format"
  field on the "Gold Price Last Update" tag (and the "Show" / "Decimals"
  controls on the "Gold Price" tag) had no effect because `get_value()` read
  from the `$options` argument, which Elementor never populates when rendering
  tags. Values are now read from the tag settings via `$this->get_settings()`,
  the same API Elementor's own tags use.

## [1.0.2] — 2026-09-08

### Added
- New redesigned admin dashboard UI (cards, sections, badges, progress bar).

### Fixed
- **Times shown in server UTC instead of site-local time**: timestamps were
  rendered with `date_i18n()`, which treats the passed Unix timestamp as
  already-local and never applies the site timezone. Switched to `wp_date()`
  (Last Sync card, sync history, log timestamps, Elementor "Gold Price Last
  Update" tag) so times follow WordPress → Settings → General → Timezone.
- **HTTP 401 on Test API with empty API key**: the connection test now
  validates that an API key is set for header/bearer/basic auth before making
  the request, and common HTTP errors (401/403/404/429) now include a hint
  explaining the likely cause.

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
