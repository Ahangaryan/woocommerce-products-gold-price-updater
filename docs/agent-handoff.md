# Gold Price Updater — Agent Handoff

© 2026 Hamid Ahangaryan. This file is the **entry point for any developer or AI
agent** continuing this plugin. Read it fully before changing anything.

## Project Purpose

A WooCommerce plugin that keeps gold product prices in step with the daily
gold price: it fetches a quote from a configurable provider, validates and
normalizes it to "per gram, target karat, site currency", applies a buffer,
guards against abnormal swings, then recalculates every managed product in
background batches. A wrong price on a gold shop costs real money, so the
design rule is: **the current gold price and all product prices only ever
change after every validation step has passed; any failure keeps the last
valid values untouched.**

## Current Architecture

```
API provider (any) 
   ↓  GPU_Api_Client (+ GPU_Api_Providers parsers)
normalize (unit/currency/karat)   GPU_Price_Normalizer  [PURE]
   ↓
validate + max-change guard       GPU_Sync
   ↓
save price                        GPU_Gold_Price_Service  (state owner)
   ↓
fill queue                        GPU_Queue (custom table, atomic claim)
   ↓  cron tick (GPU_Queue::CRON_HOOK, 1s rescheduling)
price per product                 GPU_Pricing_Engine  [PURE]
   ↓  update only if changed
WooCommerce                       GPU_Products (only writer of prices)

Read-only consumers: GPU_Meta preview (admin), GPU_Elementor tags (frontend),
dashboard cards. None of them compute prices themselves — they all call the
engine or the service.
```

`[PURE]` classes have **no WordPress dependency** (guarded by
`ABSPATH`/`GPU_TESTING`) and are covered by unit tests in
`tests/Unit/GoldPriceUpdater*.php` of the parent repo.

## Important Classes

| Class | File | Responsibility |
|---|---|---|
| `GPU_Plugin` | `includes/class-gpu-plugin.php` | lifecycle, cron schedules, module registration, migrations |
| `GPU_Settings` | `includes/class-gpu-settings.php` | all settings + runtime state + API status + sticky notices |
| `GPU_Api_Client` | `includes/class-gpu-api-client.php` | HTTP (auth methods, JSON path), sanitized summaries |
| `GPU_Api_Providers` | `includes/class-gpu-api-providers.php` | provider presets + per-provider response parsers |
| `GPU_Price_Normalizer` | `includes/class-gpu-price-normalizer.php` | unit → gram, currency, karat conversions [PURE] |
| `GPU_Pricing_Engine` | `includes/class-gpu-pricing-engine.php` | THE formula, BCMath, rounding [PURE] |
| `GPU_Gold_Price_Service` | `includes/class-gpu-gold-price-service.php` | mode resolution (api/manual/fallback), effective price, state |
| `GPU_Sync` | `includes/class-gpu-sync.php` | pipeline orchestration, guards, retries, notifications |
| `GPU_Queue` | `includes/class-gpu-queue.php` | batch queue table, atomic claim, tick loop |
| `GPU_Products` | `includes/class-gpu-products.php` | managed products, weights, making charge, price writing |
| `GPU_DB` | `includes/class-gpu-db.php` | schema, MySQL named lock, history helpers |
| `GPU_Logger` | `includes/class-gpu-logger.php` | buffered logging with secret redaction |
| `GPU_Admin` | `includes/class-gpu-admin.php` | dashboard/settings page, actions, progress AJAX |
| `GPU_Meta` | `includes/class-gpu-meta.php` | product meta box + preview endpoint |
| `GPU_Elementor` | `includes/class-gpu-elementor.php` | dynamic tag registration (graceful when absent) |
| `GPU_Updater` | `includes/class-gpu-updater.php` | GitHub releases updater (opt-in) |

## Important Hooks

| Hook | Type | Purpose |
|---|---|---|
| `gpu_sync_cron` | action | scheduled sync (interval = `update_interval` setting) |
| `gpu_queue_tick` | action | self-rescheduling queue worker |
| `gpu_api_request_headers` | filter | custom auth headers (never log the values) |
| `gpu_api_providers` | filter | register provider presets |
| `gpu_price_units` | filter | add/override price units (grams per unit) |
| `gpu_currency_factors` | filter | add currencies (factor relative to IRR) |
| `gpu_progress` / `gpu_preview_price` | AJAX | dashboard progress, product preview (nonced + cap-checked) |
| `admin_post_gpu_sync_now` / `gpu_force_sync` / `gpu_test_api` | admin-post | dashboard actions (nonce + `manage_woocommerce`) |

## Database Structure

Three custom tables (created by `GPU_DB::migrate()`, version gated by the
`gpu_db_version` option — never run on normal page loads):

- `wp_gpu_queue` — one row per product per sync: `sync_id, product_id,
  status(pending|processing|updated|skipped|error), claimed_at, finished_at`.
  Cleared after syncs (`clear_finished`); truncated on abort.
- `wp_gpu_logs` — `level, event, message, context(JSON), create_at`.
- `wp_gpu_sync_history` — one row per sync: source, gold price, buffer,
  effective price, totals, status, message.

Settings live in `wp_options` (see below); high-volume data never touches
`wp_options`.

## Options

| Option | Autoloaded | Contents |
|---|---|---|
| `gpu_settings` | yes | all configuration (defaults in `GPU_Settings::defaults()`) |
| `gpu_state` | **no** | runtime: `api_price`, `last_valid_price`, `last_sync_ok`, `last_sync_attempt`, `last_error`, `failure_count`, `queue_total` |
| `gpu_api_status` | no | last test/fetch result (status, message, price, http_code, checked_at) |
| `gpu_admin_notice` | no | sticky admin warnings (max-change guard trips) |
| `gpu_db_version` | no | schema version for migrations |
| transients `gpu_lock_taken_at`, `gpu_gh_release` | — | lock timestamp, GitHub release cache |

## Product Meta

| Meta | On | Meaning |
|---|---|---|
| `_gpu_managed` | parent product | `'yes'` → priced by the plugin. **The only opt-in flag; nothing else is ever touched.** |
| `_gpu_making_charge_pct` | product or variation | making charge % override (empty = parent, then `default_making_charge_pct` setting) |

Weight comes from the standard WooCommerce weight field (shop weight unit
converted to grams; variations fall back to their parent).

## Cron / Queue

- `gpu_sync_cron` runs every `update_interval` seconds (custom schedule
  `gpu_interval`, min 60). WP-Cron caveat: on low-traffic sites install a
  real cron hitting `wp-cron.php?doing_wp_cron` every minute — the plugin's
  schedule is interval-based and safe under real cron.
- The queue self-reschedules `gpu_queue_tick` 1s after every batch until the
  queue drains; each tick processes at most `batch_size` items and 20s of
  wall time, inside the sync lock.
- Stale `processing` rows (>10 min) are re-claimed automatically.

## API

Provider-agnostic: URL + auth method (`none|header|bearer|query|basic`) +
request method + JSON path + price unit + currency + timeout. Presets:
`custom`, `pie` (this repo's engine, `/api/v1/prices/gold_18k` with
`X-API-Key`), `tgju` (`data.0.p`, Rial). New providers: add a preset via the
`gpu_api_providers` filter and a parser branch in `GPU_Api_Providers::parse()`
— nothing else switches on provider names.

## Pricing Formula

The single source of truth is `GPU_Pricing_Engine` (BCMath string math):

```
effective_gold_price = gold_price + buffer
raw_gold_value       = weight_g × effective_gold_price
making_charge        = raw_gold_value × making_charge_pct / 100
seller_profit        = (raw_gold_value + making_charge) × seller_profit_pct / 100
vat                  = making_charge × vat_pct / 100        ← making charge ONLY
final_price          = raw + making + profit + vat          (+ optional rounding step)
```

Defaults: profit 7%, VAT 10% — both are **settings, never hard-code them**.
The spec's §64 example text (profit 2,047,500) contradicts its own formula;
the formula wins (see `tests/Unit/GoldPriceUpdaterPricingTest.php` for the
canonical numbers).

## Elementor Integration

`GPU_Elementor::register()` bails immediately when Elementor is absent — the
core never depends on it. Two tags (`GPU_Elem_Price`, `GPU_Elem_Updated`)
read exclusively from `GPU_Gold_Price_Service` / `GPU_Settings` state; they
compute nothing.

## GitHub Update System

Opt-in via the `github_repo` setting (`owner/name`). `GPU_Updater` caches
`releases/latest` for 12h, injects the release into the WordPress update
transient and the plugin-info popup. Releases must carry a version tag;
`GPU_VERSION` (plugin header + constant) is the single version constant.

## Known Issues

- WP-Cron depends on site traffic (see above for the real-cron recipe).
- Toman↔Rial conversion is deliberately NOT auto-guessed; both map to factor
  1.0 and the provider preset decides. Wire real factors via
  `gpu_currency_factors` if a provider quotes Toman.
- The dashboard is a single page (status + settings + history + logs); it
  will get long as features grow.

## TODO

- Optional Action Scheduler driver alongside the custom queue.
- Live price filter (render-time pricing) as an opt-in alternative to cron
  writes.
- Telegram/SMS notifications alongside email.
- Benchmark page for large catalogs (spec §65).

## Do Not Change

- **`GPU_Pricing_Engine` formula order and rounding semantics** — changing
  them changes every product price. Any change requires updating the unit
  tests AND the shop owner's sign-off.
- **The write-only-from-`GPU_Products` rule for product prices.**
- **The validate-before-store order in `GPU_Sync::run()`.**
- The `ABSPATH`/`GPU_TESTING` guards in the pure classes (tests rely on
  loading them without WordPress).
- Secret-redaction behavior in `GPU_Logger` (credential leakage risk).
