# FaraCart — Rewards

## Overview

Rewards are the benefits unlocked by completing a mission. The reward layer is fully decoupled from mission calculation — the MissionEngine computes a `MissionResult` and the RewardEngine turns that into a `RewardResult`, then applies it to the live WooCommerce cart.

## Reward Types

| Type | Config | How it's granted |
|---|---|---|
| `free_shipping` | Optional `shipping_zone_ids`, `shipping_method_ids` | Rates in matching packages zeroed via `woocommerce_package_rates` |
| `percent_discount` | `reward_value` (%), optional `reward_max_value` cap, eligible products/categories | Negative cart fee via `woocommerce_cart_calculate_fees` |
| `fixed_discount` | `reward_value` (amount), eligible/excluded products & categories | Negative cart fee, clamped to eligible value |
| `free_gift` | `gift_product_id`, `gift_add_mode` (`automatic` \| `choose`) | Automatic: gift line added with zero price. Choose: shopper picks from configured list |
| `coupon` | `coupon_code` (existing) or `coupon_generate` (from rules) | Existing validated then applied; generated deterministic per mission |

## Reward Safety

| Guarantee | Mechanism |
|---|---|
| Duplicate rewards | Non-stacking reward may only be first of its type per pass |
| Reward loops | Reconciliation is idempotent; engine's own fees subtracted from total basis |
| Stale rewards | Every totals pass re-evaluates; coupons/gifts removed when mission incomplete |
| Invalid coupon | Codes validated against WooCommerce; generated through `WC_Coupon` API |
| Unintended stacking | `RewardSafety::stacking_allows()` blocks same-type duplicates |
| Excluded products | Discount bases exclude them; fixed discount clamped to eligible value |

## Reward Result Contract

| State | Meaning |
|---|---|
| `not_applicable` | No reward applies |
| `locked` | Mission eligible but target not reached |
| `available` | Target reached; reward may be granted |
| `applied` | Reward applied to live cart |
| `blocked` | Target reached but safety rule prevents granting |

## Free Gift Modes

- **Automatic** — single configured gift added silently, non-removable
- **Choose** — shopper picks one gift from configured `gift_products` list via `POST /faracart/v1/gift`

Gift lines are stamped with `faracart_gift_mode` and `faracart_gift_mission` cart data. Quantity is locked to 1. Gifts are removed when the granting mission stops qualifying.

## WooCommerce Integration

| Hook | Priority | Behavior |
|---|---|---|
| `woocommerce_before_calculate_totals` | 10 | `zero_gift_prices()` — gift lines contribute 0 |
| `woocommerce_before_calculate_totals` | 100 | `sync_cart()` — evaluate missions, reconcile coupons/gifts |
| `woocommerce_cart_calculate_fees` | 20 | `apply_discount_fees()` — rebuild discount fees |
| `woocommerce_package_rates` | 100 | `apply_free_shipping()` — zero matching rates |

## Extension Point

```php
add_filter( 'faracart_reward_applicator_classes', function ( $classes ) {
    $classes['loyalty_points'] = My_Loyalty_Applicator::class;
    return $classes;
} );
```

## Design Decisions

| Decision | Rationale |
|---|---|
| Fees for discounts, not coupons | Recalculated every totals pass, drop out automatically |
| Session-tracked reversal | Engine knows exactly which coupons/gifts it granted |
| Line-item money bases | WC zeroes aggregates before `woocommerce_before_calculate_totals` |
| Deterministic coupon codes | Same mission always maps to same coupon |
