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

## 5.1 Purchase & profit metadata (Phase 2)

The purchase-analysis data layer (`Improvement.md` Phase 2) exposes the
commercial-outcome metrics the redesigned admin UI needs, all derived from
the existing attribution reads — no new engine, no new order scans:

- **Purchased orders** — `summary.orders` / `funnel.converted` (distinct
  attributed orders). **Purchase rate** — `funnel.conversion_rate` =
  `converted / completed`, `null` when there is no completion denominator
  (the UI renders `—`, never `0%`).
- **Profit availability metadata** on every attribution summary:
  - `profit_reason_code` — stable machine-readable state (§39):
    `available` / `missing_product_cost` / `incomplete_product_cost`
    (`some orders lack cost data — profit still computed over the
    orders that have it`) / `insufficient_data` (no attributed orders).
  - `profit_details` — the §12 profit-panel building blocks:
    `incremental_revenue`, `margin_pct` (average), `reward_cost`,
    `shipping_cost`.
  - `cost_coverage` — `{ attributed_orders, orders_with_cost_data,
    coverage_pct, available }` over the direct (incremental) orders
    (§11). The strict profit model is unchanged — order margin still
    requires cost data on every line item; the counts only explain it.
- **The legacy `GET /goalcart/v1/analytics` summary is extended** (§37)
  with `progressed`, `purchased_orders`, `purchase_rate`,
  `attributed_sales`, `estimated_profit`, `profit_available`,
  `profit_reason`, `profit_reason_code`, `cost_coverage` and
  `profit_details` — same date range, same goal filters, served through
  the cached `RevenueRepository::purchase_summary()`. Filter mapping:
  `goal_id` / `goal_ids` pass through; `campaign_id` and `reward_type`
  resolve to the matching goal ids
  (`GoalRepository::ids_by_campaign()` / `ids_by_reward_type()`);
  `product_id` is unsupported in attribution and yields `null` purchase
  fields (never a fabricated number). The pre-existing Phase 17 fields
  are untouched.

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

---

# Goal Cart — Smart Upsell Ranking (Phase 33.5)

`UpsellRanker` (`includes/Analytics/`) answers *"which products should
this shopper add to reach the goal?"* with a fully transparent,
deterministic weighted ranking — no LLM/AI. Every product exposes its
component scores, raw factors, historical conversion stats and
plain-English reasons, so the admin UI and the storefront can always
show *why* a product was recommended.

## 1. Pipeline

1. **Candidates (P33-26)** — the goal's own products (`manual`),
   products historically recommended for the goal (`historical`),
   products in the goal's categories (`category`), the cart items'
   WooCommerce-endorsed sources (`upsell` / `cross_sell` / `related`),
   products sharing a category or tag with the cart (`category_match` /
   `tag_match`) and best sellers (`popular`). Bounded to 60 candidates;
   out-of-stock / private / draft / already-in-cart / goal-excluded
   products never reach scoring.
2. **Six normalized (0–100) component scores** with filterable weights
   (`goalcart_upsell_weights`, defaults below).
3. **Ranking (P33-34)** — composite `score` desc; ties break by lower
   price, then product id (fully deterministic).

## 2. Component scores

| Component | Default weight | Signal |
| --- | --- | --- |
| `price_gap` | 25% | how well the price fits the remaining goal gap — sweet band `[0.75×, 1.30×]` scores 100 (small overshoots tolerated, P33-27/36), hard decay to 0 at 3×; neutral 50 without a price/gap |
| `relevance` | 25% | goal eligibility (manual products +55, counts-toward-goal +35), category overlap with the cart (+30), tag overlap (+20), WC-endorsed source trust (+15) |
| `popularity` | 15% | units sold (bounded at 100) + average rating |
| `inventory` | 10% | stock >20 → 100, 5–20 → 70, 1–4 → 40, backorder → 20; unmanaged stock → neutral 70 |
| `margin` | 15% | only when the store provides product cost data (never invented); neutral 50 otherwise |
| `conversion` | 10% | the product's historical upsell funnel, impressions-weighted so sparse data blends toward neutral 50 |

A partial `goalcart_upsell_weights` filter falls back per key (missing
keys keep their defaults unchanged, provided keys normalize among
themselves) — a filter returning only `price_gap`/`relevance` cannot
zero the other components.

## 3. Historical learning (P33-35)

The storefront reports upsell interactions through
`POST /goalcart/v1/upsell/track` (public, privacy-safe session ids) into
the Phase 33.1 `upsell_events` log: `upsell_impression` (deduped per
session+goal+product within 24h), `upsell_clicked`, `upsell_added` and
`upsell_order`. When a paid order completes, `attribute_order()` resolves
the ordering session (the `order_paid` event's session, then the live
cookie, then the user's recent revenue session) and records one
`upsell_order` event per product shown/clicked/added in that session —
the "purchased after recommendation" signal. The Phase 33.3
`DailyAggregator::aggregate_upsells()` rebuilds `upsell_stats` from that
log, and the conversion scorer reads the aggregates: deterministic
historical scoring, no black-box model.

## 4. Graceful degradation (P33-51)

- No goal / no remaining gap → `available: false` with a reason (never a
  fabricated list); a closed gap is explicit.
- Disabled via `goalcart_upsells_enabled` → unavailable with a reason.
- No margin data → margin neutral 50, `profit_available: false`,
  `estimated_profit: null` — the product still ranks.
- No historical data → conversion neutral 50.
- No candidates → unavailable with a reason.

## 5. API & caching

- `GET /goalcart/v1/revenue/upsells` (admin) — the ranked products for a
  cart + goal context (`goal_id`, `cart_value`, `remaining`, `cart`,
  `limit`), including weights, context echo and per-product breakdowns.
- `GET /goalcart/v1/revenue/upsells/{product_id}` (admin) — one product's
  score breakdown + historical stats (null for unknown products).
- Both are served through `RevenueRepository::upsell_ranking()` /
  `upsell_product_detail()` — the same generation-versioned transients
  as every other revenue read; the existing invalidation (order
  payment/status, goal CRUD, product saves, aggregation) keeps rankings
  fresh. `RevenueRepository::upsell_analytics()` powers the admin
  top-products table over a window (impressions/clicks/adds/orders/
  revenue/profit/score).
- `goalcart_upsell_candidates` / `goalcart_upsells` filters let callers
  pin the candidate set and shape the payload.
- **Safety:** the ranker never writes anything. Historical events are
  recorded only by the public track endpoint and the order hooks.

## 6. Extensibility (future AI/ML)

The public `rank()` payload is the frontend contract. A future
`MLUpsellRanker` can replace this class behind the same payload shape
without touching the REST layer or the admin UI (P33-60).

---

# Goal Cart — React Admin Revenue Section (Phase 33.6)

The Revenue Optimization admin section: five lazy-loaded React pages
under a new `Revenue` navigation group, all sharing the existing admin
conventions (date-range context + filter toolbar, skeleton loading,
`EmptyState`, error Alerts, MUI RTL). Data flows through the cached
Phase 33.3 `RevenueRepository` reads only — no new uncached queries.

## 1. Pages & data sources

| Page | Route | REST endpoint | Repository read |
| --- | --- | --- | --- |
| Revenue Overview | `/revenue` | `GET /revenue/overview` | `overview()` + `daily_trend()` |
| Goal Performance | `/revenue/goals` | `GET /revenue/goals` | `goal_performance()` |
| Attribution Dashboard | `/revenue/attribution` | `GET /revenue/attribution` | `overview()` |
| Smart Recommendations | `/revenue/recommendations` | `GET /revenue/goal-recommendations` | `goal_recommendations()` |
| Upsell Analytics | `/revenue/upsells` | `GET /revenue/upsells?analytics=1` + `GET /revenue/upsells/{id}` | `upsell_analytics()` / `upsell_product_detail()` |

The three new routes (`/revenue/overview`, `/revenue/attribution`,
`/revenue/goals`) live in the new admin-only `RevenueController`
(`includes/REST/RevenueController.php`) — manage_options-gated, per-user
rate limited, `from`/`to`/`goal_id` validated through the arg schema, and
served through the same generation-versioned transients as every other
revenue read.

## 2. Revenue Overview

KPIs (goal-influenced / goal-driven revenue, incremental cart value,
AOV impact %, goal conversion rate, reward cost, estimated profit) plus:

- **Daily revenue trend** — completions/conversions bars with revenue /
  incremental-revenue lines, zero-filled over the window, today's live
  bucket merged (`revenue_daily` + `AttributionEngine`).
- **AOV impact panel** — store-wide vs goal-exposed vs non-exposed AOV
  with absolute + percentage change, labeled *observed impact* (never
  causality).
- **Shipping panel** — average shipping, free-shipping share, orders
  with shipping and per-method averages.

## 3. Goal Performance

One row per goal: funnel counts (views → progressed → completed →
converted), completion/conversion rates, average + incremental cart
value, attributed + assisted revenue, reward cost and profit impact.
Rows expand into the funnel visual + detail panel.

## 4. Attribution Dashboard

The funnel visual with completion/conversion rates, direct vs assisted
model revenue cards (plus distinct order count), incremental cart value
(average/total/baseline/sessions + data-sufficiency badge) and the
profit-impact panel — which shows its `profit_reason` instead of a
number when the store provides no margin data (revenue-only analytics).

## 5. Smart Recommendations (Goal Recommendation UI)

The Phase 33.4 payload rendered end-to-end: analyzed store data (AOV,
median, coefficient of variation, order-distribution bars, shipping,
margin availability, confidence tier), the top recommendation card
(threshold, confidence, expected AOV impact range, expected completion
rate, expected profit, plain-English reasons) with **Apply / View
details / Dismiss**, and the ranked candidate list (score bar, confidence,
reachable share, reward cost, expandable reasons).

Applying a recommendation is always an explicit admin action — a
confirm dialog then `PUT /goals/{id}` updates the selected goal's
target. The engine never modifies a goal (P33-53).

## 6. Upsell Analytics

The top-products table (`upsell_analytics()` over the window):
impressions / clicks / adds / orders / conversion / revenue /
estimated profit / upsell score, with the four spec views (top
performing, lowest performing, best conversion, highest margin —
client-side sorts of the same rows). Clicking a row opens the product's
score-breakdown dialog via `upsell_product_detail()`: the six 0–100
components, plain-English reasons, raw factors and historical funnel
stats. Each analytics row now also carries `estimated_profit` /
`profit_available` / `margin_pct` — null when the store stores no
product costs (never invented).
