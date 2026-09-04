# Pricing Engine

`GPU_Pricing_Engine` is the **single source of truth** for pricing. The
formula exists in exactly one file; admin UI, sync, the product preview and
Elementor all consume it — none of them re-implement it.

## Inputs

| Input | Source |
|---|---|
| weight (grams) | product/variation weight × shop unit factor (`GPU_Products::weight_in_grams`) |
| gold_price | stored API price (per gram, target karat, site currency) |
| buffer | setting `buffer` |
| making_charge_pct | product meta → parent meta → `default_making_charge_pct` setting |
| seller_profit_pct | setting `seller_profit_pct` (default 7) |
| vat_pct | setting `vat_pct` (default 10) |
| round_mode / round_step | settings (none / nearest / up / down) |
| decimals | setting (default 2) |

## Formula

```
effective_gold_price = gold_price + buffer
raw_gold_value       = weight_g × effective_gold_price
making_charge        = raw_gold_value × making_charge_pct / 100
seller_profit        = (raw_gold_value + making_charge) × seller_profit_pct / 100
vat                  = making_charge × vat_pct / 100      ← VAT applies to the making charge ONLY
final_price          = raw_gold_value + making_charge + seller_profit + vat
```

## Worked example (the spec's §64 input)

```
weight = 2.5 g, gold = 10,000,000, buffer = 200,000,
making = 15%, profit = 7%, VAT = 10%

effective_gold_price = 10,200,000
raw_gold_value       = 25,500,000
making_charge        = 3,825,000
seller_profit        = (25,500,000 + 3,825,000) × 7% = 2,052,750
vat                  = 3,825,000 × 10% = 382,500
final_price          = 31,760,250
```

> Note: the original spec's example text shows profit 2,047,500 / final
> 31,755,000, which does not match its own formula. The formula is the
> source of truth; the unit tests pin the values above. If the business
> wants profit computed on the raw value alone, change **one place** (the
> engine's profit line, then the tests) — that is the point of the module.

## Precision

All arithmetic uses BCMath string math at 12 decimal places; the result is
converted to float once at the end. No intermediate floating-point drift.
Final rounding (optional) supports `nearest` (half-up), `up`, `down` to a
configurable step (e.g. round to the nearest 1,000).

## Rejection rules (never compute a bad price)

The engine returns `null` (and callers skip the product) when:

- weight or gold price is missing / non-numeric / ≤ 0;
- any percentage is negative;
- the making-charge percent is not numeric or outside 0–100.

A zero API price never flows into a product: `GPU_Sync` refuses it before
storage and the engine refuses it again at compute time (defense in depth).

## Changing the profit base (future)

The `profit_base` setting key exists (`raw_plus_making` default). When the
business needs another base (raw only, or configurable), implement it in the
engine as a switch on that input — do not add a second formula elsewhere.
