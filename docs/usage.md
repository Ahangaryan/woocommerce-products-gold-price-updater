# Usage Guide

## Installation

1. Copy `plugins/gold-price-updater` into `wp-content/plugins/` or upload it
   as a zip.
2. Activate — WooCommerce must be active. Three tables are created on
   activation.
3. Go to **WooCommerce → Gold Price Updater**.

## Configure the API

1. Pick a **Provider** preset (`custom`, `pie`, `tgju`) or keep Custom.
2. Fill **API URL**, credentials, auth method, request method, JSON path.
3. Choose the **Price unit** the API returns (`gram_18k`, `ounce`, ...) and
   the **API currency**; then the **Karat** and **Target currency** you price
   in. The normalizer converts to "per gram, target karat" automatically.
4. Press **Test API Connection** — you get Connected/Error plus the parsed
   price and HTTP code. Credentials never appear in logs (redacted) or
   non-debug summaries.

## Gold price settings

- **Price mode** — `API only`, `Manual only`, or `API with fallback to
  manual` (recommended). When the API fails in fallback mode, the manual
  price is used and the failure is logged as a warning.
- **Manual price** — the value used in manual/fallback situations.
- **Buffer** — added to the fetched price before any product math.
- **Max price change %** — if a new price deviates more than this from the
  stored one, the sync aborts, nothing is written, and a sticky admin notice
  appears. `0` disables the guard; **Force sync** bypasses it once.

## Pricing settings

- Default making charge %, seller profit % (7), VAT % (10, **making charge
  only**), rounding mode/step, decimals. Percentages are settings — the code
  never hard-codes tax or profit rates.

## Set up products

1. Edit a product → **Gold Pricing** box → tick **Managed by Gold Price
   Updater**.
2. Set the WooCommerce **weight** (grams by default; the shop weight unit is
   honored) and the **Making Charge %** (empty = plugin default).
3. **Recalculate Preview** shows the full breakdown read-only, computed with
   the current saved gold price. Saving the product persists only the flag
   and the making charge.
4. Variable products: set weight/making charge per variation for independent
   pricing; empty variation fields inherit the parent.

## Syncing

- **Sync Gold Price Now** validates → stores → queues all managed products
  (message shows the queue size; processing happens in background batches).
- The dashboard progress bar polls the queue state live.
- Automatic syncs run on the **Update interval** setting (custom schedule,
  min 60s). For reliable timing on low-traffic sites add a real cron:

  ```
  * * * * * wp cron event run --hook=gpu_sync_cron --path=/path/to/site
  # or: * * * * * curl -s https://site.com/wp-cron.php?doing_wp_cron
  ```

## Sale price behavior

`General → Sale price behavior`: **update regular price only** (default —
your WooCommerce sale prices keep working as manual discounts) or **skip
on-sale products** (they are not recalculated while any sale price is set).
The updater never writes a regular price at or below the sale price.

## Logs & history

- Logs: level, event, message, JSON context — secrets redacted. Retention
  configurable (Advanced), pruned each cron run.
- Sync history: every attempt with source, prices, queued counts, status.

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| "HTTP 401/403" repeatedly | wrong key/secret — not retried by design; fix credentials |
| "Normalization failed" | unit/currency/karat combination unsupported — check the unit list |
| Sync says queued but progress stalls | WP-Cron isn't running — install the real cron above |
| Prices didn't change though gold did | products not ticked as managed, missing weight, or the change was under 0.01 (rounding) |
| Sticky warning about price change | the max-change guard tripped — verify the API quote, then adjust the limit or use Force sync |
| Queue rows stuck | crashed worker rows auto-reclaim after 10 minutes; lock auto-releases after the lock timeout |

## Uninstall

Deleting the plugin (Plugins → Delete) drops its options, transients and the
three tables. Deactivating keeps all data.
