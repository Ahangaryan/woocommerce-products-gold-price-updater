# Database

## Custom tables

### `wp_gpu_queue`

One row per product/variation per sync.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| sync_id | BIGINT UNSIGNED | links to `wp_gpu_sync_history.id` |
| product_id | BIGINT UNSIGNED | product or variation ID |
| status | VARCHAR(20) | `pending → processing → updated|skipped|error` |
| claimed_at | BIGINT NULL | set on atomic claim; >10 min old ⇒ reclaimed |
| finished_at | BIGINT NULL | |

Indexes: `sync_id`, `status`, `product_id`. Finished rows are deleted after
each sync (`clear_finished`) so the table stays small; a full clear happens
on abort.

### `wp_gpu_logs`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| level | VARCHAR(10) | info / warning / error |
| event | VARCHAR(64) | machine name, e.g. `price_guard` |
| message | TEXT | secret-redacted |
| context | LONGTEXT NULL | JSON (secret keys masked) |
| create_at | BIGINT | unix time |

Indexes: `level`, `create_at`. Pruned by `log_retention_days` on every cron.

### `wp_gpu_sync_history`

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK AI |
| started_at / finished_at | BIGINT |
| source | VARCHAR(20) — api / manual / cron |
| gold_price / buffer_amount / effective_price | DECIMAL(20,4) NULL |
| products_total / products_updated / products_skipped / errors | INT |
| status | VARCHAR(20) — running / queued / completed / failed |
| message | TEXT NULL |

Indexes: `started_at`, `status`. Pruned with the same retention window.

## Options

| Option | Autoload | Purpose |
|---|---|---|
| `gpu_settings` | yes | configuration array (defaults: `GPU_Settings::defaults()`) |
| `gpu_state` | no | runtime price state — updated per sync without touching the autoloaded blob |
| `gpu_api_status` | no | last connection test/fetch outcome |
| `gpu_admin_notice` | no | sticky warnings keyed by event |
| `gpu_db_version` | no | migration gate (`GPU_DB::is_current()`) |

## Migrations

`GPU_DB::migrate()` (dbDelta) runs on activation and whenever
`gpu_db_version` < `GPU_DB_VERSION`. It never runs on normal page loads.
Bump `GPU_DB_VERSION` and extend `migrate()` for schema changes; old
installs upgrade in place on the next admin request.

## Product meta

| Meta | Location |
|---|---|
| `_gpu_managed` (`yes`) | parent product — the managed opt-in flag |
| `_gpu_making_charge_pct` | product or variation — making charge override |
| `_gpu_accessory_weight` | product or variation — accessory weight (grams, priced at the effective gold price) |
| `_gpu_stone_fee` | product or variation — fixed stone/accessory fee added to the final price |
| `_gpu_acc_in_making` (`yes`) | managed (parent) product — making charge % also applies to the accessory gold value |
| `_gpu_seller_profit_pct` | product or variation — seller profit % override (empty = plugin default) |
| `_gpu_vat_pct` | product or variation — VAT % override (empty = plugin default) |
