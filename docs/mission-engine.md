# FaraCart — Mission Engine

> **Phase 4 / Tasks P04-T01–T05.** The central calculation engine, built independently
> from any UI. The engine is authoritative in `includes/Missions/`; this document explains the
> pipeline, the mission types, the Mission Result contract, and how edge cases are handled.

---

## 1. Objective (P04-T01)

The mission engine computes, for every active mission and the current cart, an honest,
UI-independent **MissionResult**. It never renders anything, never touches the database, and
never reads request state — callers supply a `Mission` and a `CartContext`. The same engine
serves the front-end widget, AJAX refreshes, the REST API, and server-side jobs, so the
numbers a shopper sees are always the numbers the reward engine and analytics use.

## 2. Architecture (P04-T02)

```text
CartContext          immutable snapshot of the cart (value object)
    ↓
MissionEvaluator        one stateless evaluator per mission type (registry-resolved)
    ↓
MissionResult           consistent 8-field evaluation result (value object)
    ↓
ProgressCalculator   shared math: remaining / percentage / completed
```

| Class | Role |
|---|---|
| `Missions\Mission` | Immutable mission value object (type, target, basis, categories/products, exclusions, schedule, reward config). Built from a DB row or a config array; the engine never reads the DB. |
| `Missions\CartItem` | Immutable cart line (ids, quantity, line subtotal/total, weight, categories, virtual/downloadable flags). |
| `Missions\CartContext` | Immutable cart snapshot: totals + items, with computed accessors (amount by basis, quantity, distinct products, weight, category/product-restricted values). `CartContext::from_cart()` adapts a live `WC_Cart`; the constructor accepts plain data for tests/headless use. |
| `Missions\MissionEvaluator` | Interface (`supports()`, `evaluate()`). |
| `Missions\Evaluators\*` | The seven mission-type evaluators (below). |
| `Missions\MissionEvaluatorRegistry` | Maps mission type → evaluator class, resolves lazily. Filterable via `faracart_mission_evaluator_classes` so stores can add custom mission types. |
| `Missions\MissionEngine` | Facade: eligibility pre-checks (status, schedule, target validity, known type) then delegation to the evaluator. |
| `Missions\MissionResult` | The 8-field result object + factories (`ineligible()`, `build()`). |
| `Missions\ProgressCalculator` | Pure math shared by every evaluator. |

`MissionEngine` is registered in the DI container (`Plugin::mission_engine()`) and is stateless,
so it is safe to reuse across requests.

### Extension point

```php
add_filter( 'faracart_mission_evaluator_classes', function ( $classes ) {
    $classes['membership'] = My_Membership_Evaluator::class;
    return $classes;
} );
```

## 3. Mission types (P04-T03)

| Type | Current value | Calculation basis |
|---|---|---|
| `amount` | Cart money value | `subtotal` (default) · `total` (incl. tax + shipping) · `discounted_subtotal` (after coupons) |
| `quantity` | Total item quantity (decimal-aware) | — |
| `distinct_quantity` | Unique products/SKUs (variations distinct; quantity 0.5 still counts once) | — |
| `category` | Amount **or** quantity restricted to the mission's categories | `subtotal` (default) · `total` · `discounted_subtotal` · `quantity` |
| `product` | Quantity (default) **or** amount of the mission's products/variations | `quantity` (default) · `subtotal` · `total` · `discounted_subtotal` |
| `weight` | Σ quantity × unit weight (store unit) | — |
| `composite` | AND/OR combination of child missions, evaluated against the same cart | — |

Type-aware defaults: product missions default to `quantity`; everything else to `subtotal`.
Explicit `calculation_mode` always wins.

### Composite semantics

- **AND** — progress = weakest child (min percentage); completed only when *every* child
  completes; `current`/`target` are the sums of the children's values. An ineligible child
  keeps the mission incomplete.
- **OR** — progress = best child (max percentage); completed as soon as *any* child
  completes; `current`/`target` mirror the best child.

Children inherit the parent's status unless overridden; each child is a `Mission::from_array()`
payload evaluated through the registry, so children can themselves be composite. Children are
held to the same eligibility rules as top-level missions (status, schedule, target validity) via
`MissionEngine::eligibility_reason()`, so an inactive child genuinely blocks an AND mission instead of
being evaluated anyway.

## 4. Mission Result contract (P04-T04)

Every evaluation produces exactly these fields (see `MissionResult::to_array()`):

| Field | Meaning |
|---|---|
| `mission_id`, `mission_type` | Identity of the evaluated mission |
| `current` | Current value for the mission's basis |
| `target` | The mission threshold (clamped ≥ 0) |
| `remaining` | `target − current`, never negative |
| `percentage` | 0–100, capped at 100 |
| `completed` | `current >= target` |
| `reward_state` | `not_applicable` (ineligible) · `locked` (eligible, not reached) · `unlocked` (reached — Phase 5 may activate the reward) |
| `eligible` | Whether the mission applies to this cart/shopper at all |
| `reason` | Why not eligible: `mission_inactive`, `out_of_schedule`, `invalid_target`, `no_matching_items`, `unknown_type` |

## 5. Edge cases (P04-T05)

All verified by `tests/engine-test.php` (71 checks, run with `php tests/engine-test.php`):

| Edge case | Behavior |
|---|---|
| Empty cart | Eligible; `current = 0`, `remaining = target`, `percentage = 0` |
| Zero target | Trivially completed: `percentage = 100`, reward `unlocked` |
| Negative/invalid target | Ineligible, reason `invalid_target` |
| Sale prices | Computed from the price actually paid (`line_total`), not list price |
| Coupons | `discounted_subtotal` reflects coupons; `subtotal` does not |
| Taxes / shipping | Only `total` mode includes them |
| Virtual / downloadable | Counted normally (no implicit exclusion) |
| Variable products / variations | Product missions match `variation_id` or parent `product_id`; variations are distinct SKUs |
| Excluded products | Dropped from quantity, weight, distinct count, category/product values, and amount bases (grand total reduced by excluded lines' value) |
| Decimal quantities | Summed exactly (e.g. 1 + 2 + 0.5 = 3.5) |
| Refunds/returns | Not a cart-level concern; the engine reads the live cart. Order-lifecycle analytics handle post-purchase events (Phase 16) |
| Guest / logged-in users | Both evaluated identically; `CartContext` records `is_guest`/`user_id` for analytics and per-customer limits (later phases) |
| Inactive mission / out of schedule | Ineligible with a specific reason |
| Unknown mission type | Ineligible with reason `unknown_type` (registry never throws on evaluation) |

## 5.5 Mission Calculation settings (Phase 18, P18-T03)

`CartContext::from_cart()` honors five store-wide inclusion toggles (each
default preserves the pre-Phase-18 behavior documented in §5), applied by
`CartIntegration` when it builds the live-cart snapshot. Explicit caller
args always win over the settings:

| Setting | Effect | Default |
|---|---|---|
| `calculation_include_tax` | Line taxes fold into the subtotal / discounted-subtotal bases | `false` (taxes stay out) |
| `calculation_include_discount` | The discounted basis reflects coupons / line discounts | `true` (discounts count) |
| `calculation_include_shipping` | The `total` basis keeps the shipping line (legacy `exclude_shipping` arg still wins) | `true` (shipping stays in) |
| `calculation_include_sale` | Sale items are dropped from the snapshot when `false` (bases rebased onto the remaining lines) | `true` (sale items count) |
| `calculation_include_virtual` | Virtual / downloadable items are dropped when `false` | `true` (virtual items count) |

`CartItem` carries the line's `line_tax` so the include-tax folding is
exact. The store-wide default *basis* for money missions is separately
driven by the General `calculation_mode` setting via the
`faracart_default_calculation_mode` filter (`Mission::default_calculation_mode()`):
amount / category / composite missions follow the store mode; quantity-style
missions keep their type defaults.

## 6. Design decisions

| Decision | Rationale |
|---|---|
| UI-independent value objects | The engine is testable without WooCommerce and reusable by widget, REST, cron, and headless contexts |
| Registry + filter, not hard-coded switch | New mission types ship as evaluator classes without touching the engine |
| Type-aware default basis | Product missions count items by default; money missions measure subtotal — the least surprising reading of each mission type |
| `total` mode excludes shipping tax | Keeps the basis predictable; documented in `CartContext::amount()` |
| Zero target ≠ invalid | A store may legitimately define a 0-threshold mission (reward always applies); only negative targets are config errors |
