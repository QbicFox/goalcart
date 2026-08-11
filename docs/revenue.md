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

---

# Goal Cart — Smart Goal Recommendation (Phase 33.4)

`GoalRecommendationEngine` (`includes/Analytics/`) answers *"what
threshold should this store use?"* with a fully deterministic,
explainable analysis of the store's own order data — no LLM/AI. The
engine is pure computation: it never writes, never modifies a goal, and
caching/invalidation live in the Phase 33.3 `RevenueRepository`.

## 1. Inputs (analyzers)

| Analyzer | Source | Availability |
| --- | --- | --- |
| AOV / median / CV | `AttributionEngine::store_order_values()` — the same bounded, memoized paginated store scan as AOV/shipping metrics | always (WC order data present) |
| Order distribution | AOV-relative buckets `<0.5×` → `>1.5×` over the scanned totals | always |
| Shipping | `AttributionEngine::shipping_stats()` (average + free share) | per-store |
| Margin | newest catalog products sampled through `goalcart_product_cost` (average margin %) | only when the store stores product costs |
| Current goal performance | attribution funnel (views/completed/converted + rates) when `goal_id` is given | per goal |

Window: 7–180 days (default 90, the spec's preferred stability window);
an explicit `from`/`to` range overrides `window_days`.

## 2. Candidates & scoring

Candidate thresholds are generated around the AOV (`0.9× … 1.5×`),
plus shipping-aware additions (`AOV + average shipping`,
`median + average shipping`) for free-shipping goals — all filterable
via `goalcart_recommendation_candidates`. Each candidate is scored on
four normalized (0–100) components with filterable weights
(`goalcart_recommendation_weights`, defaults in `SCORE_WEIGHTS`):

- **Reachability (30%)** — share of orders within 30% below the
  threshold (the orders that can plausibly close the gap); triangular
  peak at ~30%.
- **Distance (25%)** — stretch above both the median and the AOV: too
  easy adds no revenue, too far is unreachable.
- **Economics (30%)** — reward cost vs the incremental margin at the
  threshold; neutral 50 when margin/reward data is missing (never a
  guessed number).
- **History (15%)** — the store's own completion rate (neutral without
  ≥10 goal views).

Every candidate exposes `factors` (the raw sub-scores) and `reasons`
(plain-English bullets derived from the actual numbers) so the admin UI
can always show *why* a threshold was chosen.

## 3. Confidence & expected impact

Confidence = data-volume tier (`basic` 50–199 orders / `reliable`
200–999 / `high_confidence` 1000+, minimum filterable via
`goalcart_recommendation_min_orders`) adjusted by order-value
consistency (CV), margin/shipping availability, goal-history depth and
whether economics had its data — clamped 40–95 (heuristics, never
statistical certainty). Expected impact is derived deterministically:
`expected_aov_impact` (reachable share × gap %), `expected_completion_rate`
(reachable share × history factor) and `expected_profit` through the
same `RewardCostEstimator::profit_impact` model as the attribution
layer — excluded without margin data.

## 4. API & caching

- `GET /goalcart/v1/revenue/goal-recommendations` (admin-only) — args:
  `goal_id`, `reward_type` (whitelist), `reward_value` /
  `reward_max_value` / `reward_meta` (config for recommendations
  without an existing goal), `window_days`, `from`, `to`. Returns the
  analysis, the ranked candidates (score, confidence, expected impact,
  reasons, factors) and the top `recommendation`.
- Served through `RevenueRepository::goal_recommendations()` — the same
  generation-versioned transients; the existing invalidation (order
  payment/status, goal CRUD, product saves, aggregation) already keeps
  recommendations fresh. TTL: `goalcart_recommendation_cache_ttl`.
- **Safety:** the engine never changes a goal. Applying a recommendation
  is an explicit admin action through the existing GoalsController.

## 5. Graceful degradation

- Fewer than the minimum orders → `available: false` with
  `insufficient_reason` (no fabricated recommendation).
- No WooCommerce order data → unavailable with reason.
- No margin data → profit excluded, economics neutral, confidence
  reduced; revenue analytics keep working.
- Disabled via `goalcart_recommendations_enabled` → unavailable.

## 6. Extensibility (future AI/ML)

The public `recommend()` payload is the frontend contract. A future
`MLGoalRecommendationEngine` can replace the deterministic class behind
that same shape without touching the REST layer or the admin UI (P33-60).
