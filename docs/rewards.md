# Goal Cart — Reward Engine

> **Phase 5 / Tasks P05-T01–T03.** The reward layer that turns goal completion into
> real cart value. Rewards are fully decoupled from goal calculation: the GoalEngine
> (Phase 4) computes a `GoalResult` and the RewardEngine turns that result into a
> `RewardResult` using the goal's reward configuration, then applies it to the live
> WooCommerce cart through public hooks. This document explains the pipeline, the
> reward types, the safety guarantees, and how the WooCommerce integration works.

---

## 1. Objective (P05-T01)

Rewards are **decoupled from goal calculation**: the reward layer never evaluates a
goal and the goal engine never applies a reward. The two layers meet at the
`GoalResult` / `RewardResult` boundary, so rewards can be changed, stacked, and
safety-checked independently of the math.

The same engine serves the frontend widget, AJAX refreshes, the REST API, and
server-side jobs — the reward a shopper sees is always the reward the cart actually
grants.

## 2. Architecture (P05-T02)

```text
GoalResult           goal evaluation (Phase 4)
    ↓
Reward               typed accessors over the goal's reward columns + reward_meta
    ↓
RewardEngine         eligibility, stacking, and state resolution
    ↓
RewardApplicator     one stateless applicator per reward type
    ↓
RewardResult         consistent 5-state evaluation result (value object)
    ↓
WooCommerce hooks    fees / package rates / coupons / gifts on the live cart
```

| Class | Role |
|---|---|
| `Rewards\Reward` | Immutable reward value object derived from a Goal (type, value, max value, label, stacking, eligible/excluded products & categories, shipping zone/method filters, gift product & mode, coupon settings). |
| `Rewards\RewardResult` | The 5-state result object (`not_applicable`, `locked`, `available`, `applied`, `blocked`) + reasons, immutable. |
| `Rewards\RewardApplicator` | Interface (`supports()`, `evaluate()`, `apply()`); `evaluate()` is the pure, WooCommerce-independent step. |
| `Rewards\Applicators\*` | The five applicators: free shipping, percentage discount, fixed discount, free gift, coupon. |
| `Rewards\RewardApplicatorRegistry` | Maps reward type → applicator class, resolves lazily, filterable via `goalcart_reward_applicator_classes`. |
| `Rewards\RewardSafety` | Pure, testable safety rules: stacking, coupon existence, gift availability, deterministic generated coupon codes. |
| `Rewards\RewardEngine` | Facade: evaluates a GoalResult into a RewardResult and syncs rewards to the live WC cart (session-tracked, re-entrancy-guarded). |
| `Goals\GoalRepository` | Loads active goals (with campaign gating) once per request for the cart sync. |

`RewardEngine` is registered in the DI container (`Plugin::reward_engine()`); the
repository (`Plugin::goal_engine()`'s sibling) is `GoalRepository` via
`Plugin::container()->get( GoalRepository::class )`.

### Extension point

```php
add_filter( 'goalcart_reward_applicator_classes', function ( $classes ) {
    $classes['loyalty_points'] = My_Loyalty_Applicator::class;
    return $classes;
} );
```

## 3. Reward types (P05-T02)

| Type | Config | How it is granted |
|---|---|---|
| `free_shipping` | optional `shipping_zone_ids`, `shipping_method_ids` (`flat_rate` or `flat_rate:3`) | Rates in matching packages are zeroed via `woocommerce_package_rates`; with no restrictions every rate goes free. Store shipping settings are never altered. |
| `percent_discount` | `reward_value` (%), optional `reward_max_value` cap, eligible products/categories, excluded products | Negative cart fee (`woocommerce_cart_calculate_fees`), applied to the eligible after-discount base, never exceeding the base. |
| `fixed_discount` | `reward_value` (amount), eligible/excluded products & categories | Negative cart fee, clamped to the eligible value. |
| `free_gift` | `gift_product_id`, `gift_add_mode` (`automatic` \| `choose`) | Automatic: gift line added with `goalcart_gift` cart data, price zeroed during totals, removed when the goal becomes incomplete. Choose: the shopper picks one gift from the configured `gift_products` list through the storefront picker (`POST /goalcart/v1/gift`); the chosen product is added free, exactly one per goal. |
| `coupon` | `coupon_code` (existing) or `coupon_generate` (from `reward_value`, `max_value`, eligible/excluded rules) | Existing coupons are validated then applied; generated coupons are deterministic per goal (`GOALCART-…`), persisted, individual-use by default, and cleaned up on uninstall. |

Reward config lives in the goal's `reward_type` / `reward_value` /
`reward_max_value` columns plus the JSON `reward_meta` column (see
`docs/database.md` §1.1).

## 4. Reward Result contract (P05-T02)

Every evaluation produces exactly these states (see `RewardResult::to_array()`):

| State | Meaning |
|---|---|
| `not_applicable` | No reward applies (goal ineligible, no reward configured, unknown reward type) — carries a `reason`. |
| `locked` | Goal eligible but target not reached — reward stays locked. |
| `available` | Target reached; the reward may be granted. Computed `amount` and `meta` are attached when a `CartContext` is supplied. |
| `applied` | The reward has actually been applied to the live cart (reserved for analytics). |
| `blocked` | Target reached but a safety rule prevents granting — carries a `reason` (`stacking`, `invalid_coupon`, `gift_unavailable`, …). |

## 5. Reward safety (P05-T03)

| Guarantee | Mechanism |
|---|---|
| Duplicate rewards | A non-stacking reward may only be the first of its type per pass; `stacking='stack'` rewards combine explicitly. |
| Reward loops | Reconciliation is idempotent (it only touches coupons/gifts this engine applied) and `CartContext` subtracts the engine's own discount fees from the `total` basis — reward mutations can never re-trigger evaluation. |
| Stale rewards | Every totals pass re-evaluates from scratch and reconciles what is applied; coupons and automatic gifts granted by the engine are removed the moment a goal becomes incomplete — including without any cart change (schedule expiry, admin deactivation); discount fees drop out automatically. |
| Invalid coupon application | Codes are validated against WooCommerce before application; generated coupons are created through the public `WC_Coupon` API only. |
| Unintended stacking | `RewardSafety::stacking_allows()` blocks same-type duplicates; generated coupon rewards are `individual_use` unless `stacking='stack'`. |
| Excluded products | Discount bases and generated-coupon product/category rules exclude them; a fixed discount can never exceed the eligible value. |

## 6. WooCommerce integration

| Hook | Priority | Behavior |
|---|---|---|
| `woocommerce_before_calculate_totals` | 10 | `zero_gift_prices()` — automatic gift lines contribute 0 to every totals pass. |
| `woocommerce_before_calculate_totals` | 100 | `sync_cart()` — evaluate active goals, reconcile coupons/gifts. Runs at most once per totals pass (re-entrancy guard) and only mutates the cart when the shopper's fingerprint changed. |
| `woocommerce_cart_calculate_fees` | 20 | `apply_discount_fees()` — rebuild percentage/fixed discount fees from the per-request evaluation cache. |
| `woocommerce_package_rates` | 100 | `apply_free_shipping()` — stateless; when no free-shipping reward is active every rate passes through untouched. |

**Evaluation timing:** `sync_cart()` runs on `woocommerce_before_calculate_totals`,
which fires *after* `WC_Cart::reset_totals()` has zeroed the cart's aggregate
getters. `CartContext::from_cart()` therefore computes the money bases from the cart
**line items** (which always carry their values) and only falls back to the aggregate
getters when they are non-zero, so subtotal/discounted-subtotal goals stay honest on
the live cart. The grand `total` falls back to the after-discount line value while
totals are reset; the tax component is a Phase 6 refinement (shipping is excluded for
reward evaluation anyway).

The engine only touches what it granted: applied coupons are tracked per goal in the
session (`goalcart_applied_coupons`), automatic gifts in `goalcart_gift_goals`, and
session writes are skipped when the value did not change (no per-pass session
churn). The shopper's own coupons are never removed.

## 7. Edge cases

All verified by `tests/reward-test.php` (72 checks, run with `php tests/reward-test.php`):

| Edge case | Behavior |
|---|---|
| Goal without reward | `not_applicable` / `no_reward` |
| Inactive / out-of-schedule goal | `not_applicable` (goal-level ineligibility) |
| Target not reached | `locked` |
| Unknown reward type | `not_applicable` / `unknown_type` (registry never throws) |
| Duplicate non-stacking type | `blocked` / `stacking` |
| `stacking='stack'` same type | `available` |
| Percent cap / eligible products / categories / exclusions | Amount math respects all (pure, tested) |
| Fixed discount > eligible base | Clamped to the eligible value |
| No eligible items | 0 discount |
| Coupon: no config / nonexistent code | `blocked` / `invalid_coupon` |
| Coupon: generate mode | `available` |
| Gift: missing / unavailable product | `blocked` / `gift_unavailable` |
| Zero-target goal with reward | Unlocked → `available` |
| Free shipping rate filtering | Unrestricted zeroes all rates; method/instance-restricted zeroes only the configured instance; no active reward leaves rates untouched |
| WC hook wiring | The four integration hooks are live with their declared priorities after boot |
| `from_cart` money bases | Derived from line items, so they stay correct while WC has reset the aggregate totals (the state during `woocommerce_before_calculate_totals`) |

The WooCommerce-only *application* path (live cart mutations) is exercised in the
Phase 6 integration work; the engine's guards are designed so evaluation stays pure
and testable without a database.

## 8. Design decisions

| Decision | Rationale |
|---|---|
| Reward value object derived from the goal | MVP embeds one reward per goal (`docs/database.md`); a standalone rewards table can be extracted later without breaking this layer. |
| Registry + filter, not a hard-coded switch | New reward types ship as applicator classes without touching the engine. |
| Fees for discounts, not coupons | Fees are recalculated every totals pass, never persisted, and drop out automatically when a goal stops being available. |
| Session-tracked reversal | The engine knows exactly which coupons/gifts it granted and removes only those — stale rewards can never outlive an incomplete goal, even without a cart change. |
| Line-item money bases | WC zeroes the aggregate totals before `woocommerce_before_calculate_totals` fires, so evaluation uses the always-current line data instead. |
| Deterministic generated coupon codes | The same goal always maps to the same coupon (idempotent generation, cleaned up on uninstall). |
| Read-only tests | The test suite boots WordPress but never creates products, activates the plugin, or writes to the database. |
