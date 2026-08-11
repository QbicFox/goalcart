# Goal Cart — Revenue Attribution (Phase 33.2)

> **Phase 33 / Tasks P33-T02.** Turns the Phase 33.1 revenue event funnel
> (`goal_view` → `goal_progress` → `goal_completed` → `order_paid`) into
> per-order goal attribution and measurable revenue metrics. Services:
> `GoalCart\Analytics\AttributionEngine` and
> `GoalCart\Analytics\RewardCostEstimator`. Storage: `goal_attribution`
> (per-order attribution rows); raw input stays in `revenue_events`.

---

## 1. Order association

When an order becomes revenue-producing, the engine attributes it to the
goals that influenced the ordering session:

- **Hooks** — `woocommerce_payment_complete` (gateways) plus
  `woocommerce_order_status_completed` (backstop for manual transitions).
  Both are idempotent: the order_paid event is deduped per order and the
  `order_goal_model` unique key makes re-processing a no-op.
- **Revenue-producing statuses** — `processing` and `completed` only
  (`AttributionEngine::REVENUE_STATUSES`, per WooCommerce convention).
  Refunded, cancelled, failed and on-hold orders are never attributed.
- **Session resolution** — the session recorded on the order_paid event,
  then the live cookie session, then (logged-in users) the most recent
  goal session for that user.
- **Lookback** — only goal events within 30 days before the order count
  (`ATTRIBUTION_WINDOW`); a stale exposure does not influence an order.

## 2. Attribution models

| Model | Condition | Row |
|---|---|---|
| `direct` | the session progressed and/or completed the goal before ordering | carries the attributed **incremental value** |
| `assisted` | the session only viewed the goal | order total recorded, **zero incremental value** |

**Incremental value** = `max(0, order_total − cart value at first goal
exposure)` — how much the customer added after seeing the goal. When
several goals are direct on one order, the incremental value is split
equally across them (deterministic, never double counted).

**Goal completion** — a `goal_completed` event in the session marks the
attribution row `goal_completed = 1` (a completion is *not* a conversion;
only an associated order is).

## 3. Metrics

All reads are SQL-aggregated and bounded (`goalcart_attribution_metric_rows`,
default 5000 rows; `goalcart_attribution_order_scan_pages`, default 100
pages × 100 orders for store-wide scans). The Phase 33.3 aggregator later
pre-computes the same numbers daily.

### Funnel (`funnel()`)

```text
views → progressed → completed → converted
completion_rate = completed / views
conversion_rate = converted / completions     (orders associated with the goal)
```

### Incremental cart value (`incremental_cart_value()`)

Per session: `cart value after exposure − cart value at first exposure`
(peak − baseline), averaged across sessions with ≥ 2 events. Also exposes
`average_baseline` (the "before" average) and a `data_sufficiency` label
(`low` < 10 sessions, `medium` < 50, else `high`).

### Revenue summary (`attribution_summary()`)

```text
goal_driven_revenue    = SUM(direct incremental_value)        (additive)
goal_assisted_revenue  = SUM(order_total of pure-assisted orders)
goal_influenced_revenue= SUM(order_total of distinct attributed orders)
```

An order with both a direct and an assisted goal counts only as direct in
the summary totals (no double counting); `goal_influenced_revenue` is the
union.

### AOV analysis (`aov_analysis()`)

Compares the goal-exposed orders' average against the store-wide average
(paginated WC order scan) and reports absolute + percentage change. The
result is labeled **observed impact** — never causality.

### Shipping stats (`shipping_stats()`)

Average shipping cost, orders with/without shipping, and per-method
averages — the input for the Phase 33.4 shipping-aware goal
recommendations.

## 4. Reward cost

`RewardCostEstimator::estimate_reward_cost( Goal, order_total, context )`
returns `{ estimated_cost, available, basis, type }`:

| Reward | Cost model | Needs store data? |
|---|---|---|
| percent discount | `min(order_total × %, reward_max_value)` | no |
| fixed discount | `reward_value` | no |
| coupon (generated) | percent/fixed per coupon settings, capped | no |
| free shipping | the order's shipping total | yes (order shipping) |
| free gift | the gift product's cost | yes (product cost) |

When the model needs data the store does not provide, `available` is
`false` with a human-readable `basis`/reason — the UI shows "unavailable"
instead of a guessed number.

## 5. Margin & profit impact

- **Product cost** — read from the standard WooCommerce `_cost` field and
  the common cost-of-goods `_wc_cog_cost` field (variations fall back to
  their parent), through the `goalcart_product_cost` filter. Product cost
  data is **never modified**, and a missing cost is never invented.
- **Order margin** — `order_margin_stats()` requires cost data on *every*
  line item; otherwise the order has no margin data (graceful).
- **Profit impact** — `estimated_profit = incremental_revenue × margin% −
  reward_cost − shipping_cost`. Without margin data the profit is reported
  `available: false` with the reason; all revenue metrics still compute
  (revenue-only analytics).

## 6. Terminology

Use these labels consistently (never present estimates as facts):

```text
Revenue              — plain order revenue
Attributed Revenue   — goal-driven (direct incremental) revenue
Assisted Revenue     — orders where a goal assisted but was not direct
Incremental Revenue  — cart value growth attributed to goal exposure
Observed Impact      — AOV before/after comparisons (no causality claimed)
Reward Cost          — estimated cost of granted rewards
Estimated Profit     — profit model; unavailable without margin data
```

## 7. Feature flags & developer hooks

- Attribution gates on the master + `analytics_enabled` toggles (the same
  consent chain as the Phase 33.1 event pipeline) plus
  `goalcart_attribution_enabled`.
- `goalcart_product_cost` — supply product costs for margin-aware analytics.
- `goalcart_attribution_metric_rows` / `goalcart_attribution_order_scan_pages`
  — bound the metric reads for large stores.

## 8. Data accuracy & privacy

- Order totals use WooCommerce's own `get_total()` (currency/timezone
  respected); refunded/cancelled/failed orders are excluded; each order is
  attributed exactly once (unique key + dedup).
- Rows store only anonymous session ids, numeric aggregates and
  plugin/WC ids — no emails, IPs, addresses or payment data.
