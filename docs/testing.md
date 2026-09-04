# Testing

## What is covered today

The plugin's **pure core** is unit-tested in the parent repo's PHPUnit suite
(no WordPress needed — the classes are loaded directly under the
`GPU_TESTING` guard):

`tests/Unit/GoldPriceUpdaterPricingTest.php` (in the repo root `tests/`)

- the spec §64 example, end to end (every line of the breakdown);
- VAT applies to the **making charge only** (zero making ⇒ zero VAT);
- fractional weights via exact string math;
- rounding modes nearest/up/down against the same input;
- rejection of negative / non-numeric / zero gold price / zero weight inputs
  (§72 — a bad price can never become a product price);
- the `price()` helper parity with `calculate()`.

`tests/Unit/GoldPriceUpdaterNormalizerTest.php`

- gram/kilogram/ounce → per-gram conversion;
- karat conversion **direction**: 24k quote → 18k basis scales down by
  purity(target)/purity(source); explicit up-scaling 18k → 24k;
- `auto` karat keeps the unit's own basis;
- rejection of zero/negative/non-numeric prices, unknown units and unknown
  currencies.

## Run

```bash
php vendor/bin/phpunit --filter GoldPriceUpdater   # just these tests
php vendor/bin/phpunit --testsuite unit            # whole unit suite
```

## How to extend

1. Keep domain math in the pure classes (`GPU_Pricing_Engine`,
   `GPU_Price_Normalizer`) so it stays testable without WordPress.
2. For WordPress-coupled logic, the pragmatic pattern used in this repo is a
   thin smoke/integration test with a WP function shim, or (preferred)
   extracting the decision into a pure helper and testing that.
3. Any change to the pricing formula MUST update
   `GoldPriceUpdaterPricingTest` in the same commit and get the shop owner's
   sign-off (see agent-handoff.md → Do Not Change).

## Failure scenarios worth adding next

- queue double-claim race (two `claim()` calls for one row);
- stale-lock reclaim after a simulated crash;
- sync retry scheduling on transport errors vs. immediate stop on 401/403;
- max-change guard boundary values (exactly at the limit);
- sale-mode `skip_on_sale` skipping and `regular_only` writing.

These need a small WP-shim harness (wpdb stub) and are the recommended next
step for an integration suite.
