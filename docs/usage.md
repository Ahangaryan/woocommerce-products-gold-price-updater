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

## Accessories & per-product overrides

The **Gold Pricing** box also supports optional extras and overrides, all
inherited from the parent by variations when left empty:

- **Accessory Weight (g)** — extra metal (stone setting, a second alloy)
  priced at the same effective gold price. It carries no making charge unless
  you tick **Include accessory weight in Making Charge**.
- **Stone / Accessory Fee** — a fixed shop-currency amount added to the final
  price (no labor, no VAT on it).
- **Seller Profit % / VAT %** — per-product overrides of the plugin defaults;
  empty = default. The preview endpoint resolves empty boxes exactly like a
  real sync.

## Set up products

1. Edit a product → **Gold Pricing** box → tick **Managed by Gold Price
   Updater**.
2. Set the WooCommerce **weight** (grams by default; the shop weight unit is
   honored) and the **Making Charge %** (empty = plugin default).
3. Optionally fill the accessory/stone fields and the per-product profit/VAT
   overrides (see above).
4. **Recalculate Preview** shows the full breakdown read-only, including
   accessory gold value and stone fee, computed with the current saved gold
   price. Saving the product persists only the flag, the making charge, and
   the accessory/override fields.
5. Variable products: set weight/making charge per variation for independent
   pricing; empty variation fields inherit the parent.

## Syncing

- **Sync Gold Price Now** validates → stores → queues all managed products
  and **starts processing inside the same request**, so the storefront
  usually shows the new prices before the redirect finishes. Larger catalogs
  finish in the background.
- If the fetched price equals the stored one, the sync finishes in
  milliseconds with **"Gold price unchanged — products already up to
  date"** — no product is touched.
- The dashboard progress bar polls the queue state live.
- Automatic syncs run on the **Update interval** setting (custom schedule,
  min 60s).

### Realtime updates

**Sync → Realtime updates** (on by default) adds a second, high-frequency
poller:

- It fetches the price every **Realtime poll interval** (default 60s, min
  15s). When the price changed, products update within seconds; when it did
  not change, the poll is nearly free (no product scan at all).
- The poller skips the max-change guard on purpose: a live market price is
  legitimate at any size. Validation/normalization/failure rules are
  identical to the regular sync — a failed poll never changes anything.
- For truly minute-level timing on low-traffic sites, also add a real cron
  (WP-Cron alone only fires on traffic):

  ```
  * * * * * wp cron event run --hook=gpu_realtime_cron --path=/path/to/site
  # or: * * * * * curl -s https://site.com/wp-cron.php?doing_wp_cron
  ```

  Even without it, every page view (frontend included) helps drain pending
  price updates, so changes spread within seconds of the next visitor.

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
| Sync says queued but progress stalls | WP-Cron isn't running — install the real cron above (the plugin also drains one batch per page view) |
| Prices didn't change though gold did | products not ticked as managed, missing weight, or the change was under 0.01 (rounding) |
| Sticky warning about price change | the max-change guard tripped — verify the API quote, then adjust the limit or use Force sync |
| Queue rows stuck | crashed worker rows auto-reclaim after 10 minutes; lock auto-releases after the lock timeout |

## Uninstall

Deleting the plugin (Plugins → Delete) drops its options, transients and the
three tables. Deactivating keeps all data.
