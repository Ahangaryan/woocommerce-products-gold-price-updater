# Architecture

## Layers

```
┌──────────────────────────────────────────────────────────┐
│ UI layer    GPU_Admin (dashboard/settings), GPU_Meta     │
│             (product box), GPU_Elementor (dynamic tags)  │
├──────────────────────────────────────────────────────────┤
│ Domain      GPU_Sync (orchestrator),                     │
│             GPU_Gold_Price_Service (price state owner),  │
│             GPU_Queue (background batches),              │
│             GPU_Products (the only price writer)         │
├──────────────────────────────────────────────────────────┤
│ Pure core   GPU_Pricing_Engine, GPU_Price_Normalizer     │
│             (no WordPress, unit-tested)                  │
├──────────────────────────────────────────────────────────┤
│ Infra       GPU_Api_Client/Providers, GPU_DB,            │
│             GPU_Logger, GPU_Settings, GPU_Updater        │
└──────────────────────────────────────────────────────────┘
```

Dependency rule: **downward only.** UI → domain → pure/infra. The pure core
never references WordPress functions (it is guarded by
`ABSPATH || GPU_TESTING` and loaded by PHPUnit directly). The API layer never
references WooCommerce. Elementor is optional at runtime.

## Data flow (sync)

```
cron / Sync Now
  → GPU_DB::acquire_lock()               (MySQL GET_LOCK, timeout-guarded)
  → GPU_DB::history_start()
  → resolve mode (api | manual | fallback)
  → GPU_Api_Client::fetch()              (retry ×n, no retry on 401/403)
  → GPU_Api_Providers::parse() / JSON path
  → GPU_Price_Normalizer::to_gram()      (unit → gram, currency, karat)
  → max-change guard                     (vs stored api_price; skippable via Force)
  → GPU_Gold_Price_Service::store_api_price()
  → GPU_Queue::fill()                    (parents + variations)
  → GPU_Queue::tick() ×N in background    (lock held per tick, atomic claims)
      → GPU_Products::update_product()
          → GPU_Pricing_Engine::price()
          → compare old vs new → write only if changed
  → history_finish() / logs flushed
```

## Concurrency model

- One **MySQL named lock** (`gpu_sync_lock`) serializes syncs + queue ticks.
  Self-releasing on session death; a transient timestamp marks acquisition
  time so a stale lock can be reclaimed after `lock_timeout`.
- Queue rows are claimed with a conditional `UPDATE ... WHERE status =
  'pending'` — only one worker can flip a row to `processing`.
- Price state changes are single-row option updates; the effective price is
  read once per batch.

## Extension points (filters)

| Filter | Signature | Use |
|---|---|---|
| `gpu_api_providers` | `array $providers` | add provider presets |
| `gpu_api_request_headers` | `array $headers, array $settings` | custom auth schemes |
| `gpu_price_units` | `array $units` (grams per unit) | new mass units |
| `gpu_currency_factors` | `array $factors` (relative to IRR) | new currencies |

Future business-rule changes (e.g. profit base) go through
`GPU_Pricing_Engine` only — the `profit_base` setting key already exists as
the seam for it (see docs/pricing-engine.md).
