# FaraCart — Revenue Attribution (Phase 33.2)

> **Phase 33 / Tasks P33-T02.** Turns the Phase 33.1 revenue event funnel
> (`goal_view` → `goal_progress` → `goal_completed` → `order_paid`) into
> per-order mission attribution and measurable revenue metrics. Services:
> `FaraCart\Analytics\AttributionEngine` and
> `FaraCart\Analytics\RewardCostEstimator`. Storage: `mission_attribution`
> (per-order attribution rows); raw input stays in `revenue_events`.

---

## 1. Order association

When an order becomes revenue-producing, the engine attributes it to the
missions that influenced the ordering session:

- **Hooks** — `woocommerce_payment_complete` (gateways) plus
  `woocommerce_order_status_completed` (backstop for manual transitions).
  Both are idempotent: the order_paid event is deduped per order and the
  `order_mission_model` unique key makes re-processing a no-op.
- **Revenue-producing statuses** — `processing` and `completed` only
  (`AttributionEngine::REVENUE_STATUSES`, per WooCommerce convention).
  Refunded, cancelled, failed and on-hold orders are never attributed.
- **Session resolution** — the session recorded on the order_paid event,
  then the live cookie session, then (logged-in users) the most recent
  mission session for that user.
- **Lookback** — only mission events within 30 days before the order count
  (`ATTRIBUTION_WINDOW`); a stale exposure does not influence an order.

## 2. Attribution models

| Model | Condition | Row |
|---|---|---|
| `direct` | the session progressed and/or completed the mission before ordering | carries the attributed **incremental value** |
| `assisted` | the session only viewed the mission | order total recorded, **zero incremental value** |

**Incremental value** = `max(0, order_total − cart value at first mission
exposure)` — how much the customer added after seeing the mission. When
several missions are direct on one order, the incremental value is split
equally across them (deterministic, never double counted).

**Mission completion** — a `goal_completed` event in the session marks the
attribution row `mission_completed = 1` (a completion is *not* a conversion;
only an associated order is).

## 3. Metrics

All reads are SQL-aggregated and bounded (`faracart_attribution_metric_rows`,
default 5000 rows; `faracart_attribution_order_scan_pages`, default 100
pages × 100 orders for store-wide scans). The Phase 33.3 aggregator later
pre-computes the same numbers daily.

### Funnel (`funnel()`)

```text
views → progressed → completed → converted
completion_rate = completed / views
conversion_rate = converted / completions     (orders associated with the mission)
```

### Incremental cart value (`incremental_cart_value()`)

Per session: `cart value after exposure − cart value at first exposure`
(peak − baseline), averaged across sessions with ≥ 2 events. Also exposes
`average_baseline` (the "before" average) and a `data_sufficiency` label
(`low` < 10 sessions, `medium` < 50, else `high`).

### Revenue summary (`attribution_summary()`)

```text
mission_driven_revenue    = SUM(direct incremental_value)        (additive)
mission_assisted_revenue  = SUM(order_total of pure-assisted orders)
mission_influenced_revenue= SUM(order_total of distinct attributed orders)
```

An order with both a direct and an assisted mission counts only as direct in
the summary totals (no double counting); `mission_influenced_revenue` is the
union.

### AOV analysis (`aov_analysis()`)

Compares the mission-exposed orders' average against the store-wide average
(paginated WC order scan) and reports absolute + percentage change. The
result is labeled **observed impact** — never causality.

### Shipping stats (`shipping_stats()`)

Average shipping cost, orders with/without shipping, and per-method
averages — the input for the Phase 33.4 shipping-aware mission
recommendations.

## 4. Reward cost

`RewardCostEstimator::estimate_reward_cost( Mission, order_total, context )`
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

- **Product cost** — read from the standard WooCommerce `_cost` field, the
  common cost-of-goods `_wc_cog_cost` field (variations fall back to
  their parent) and FaraCart's own optional **`_faracart_product_cost`**
  field (UPSELL_REFACTOR §19/§20 — a namespaced "Product cost" input on
  the product editor, simple products and per-variation, saved through
  `ProductCostField`), all through the `faracart_product_cost` filter.
  Product cost data is **never modified**, and a missing cost is never
  invented.
- **Order cost snapshot (UPSELL_REFACTOR §21/§22)** — at checkout
  (`woocommerce_checkout_create_order_line_item`, classic + Blocks) each
  line item is stamped with the unit cost it was created with
  (`_faracart_unit_cost`, `OrderCostSnapshot`). The profit model prefers
  the snapshot over the live product cost, so editing a product's cost
  later never rewrites historical profit.
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
- **The legacy `GET /faracart/v1/analytics` summary is extended** (§37)
  with `progressed`, `purchased_orders`, `purchase_rate`,
  `attributed_sales`, `estimated_profit`, `profit_available`,
  `profit_reason`, `profit_reason_code`, `cost_coverage` and
  `profit_details` — same date range, same mission filters, served through
  the cached `RevenueRepository::purchase_summary()`. Filter mapping:
  `mission_id` / `mission_ids` pass through; `campaign_id` and `reward_type`
  resolve to the matching mission ids
  (`MissionRepository::ids_by_campaign()` / `ids_by_reward_type()`);
  `product_id` is unsupported in attribution and yields `null` purchase
  fields (never a fabricated number). The pre-existing Phase 17 fields
  are untouched.

## 5.2 Profit availability — cost sources (Phase 3)

The profit model never invents costs (`Improvement.md` Phase 3, §9/§10/
§40): product cost is read only from the store's own data, in this order
(`RewardCostEstimator::COST_SOURCES`, exposed as `cost_sources` in every
attribution summary):

1. **`faracart_product_cost` filter** — a store plugin can plug its own
   cost source (return `float`, or `null` to fall through).
2. **`_faracart_product_cost`** — FaraCart's own product-editor field
   (UPSELL_REFACTOR §19/§20), read before the standard WooCommerce keys
   so a store using the FaraCart field always wins.
3. **`_cost`** — the standard WooCommerce product cost field.
4. **`_wc_cog_cost`** — the common cost-of-goods field, read when `_cost`
   is absent. `_cost` takes precedence when both are present.
5. **Variation fallback** — a variation with no cost of its own inherits
   its parent's cost through the same source chain (filter first, then
   raw meta).

On the order side, the **`_faracart_unit_cost` order-item snapshot**
(§21/§22, written at checkout by `OrderCostSnapshot`) takes precedence
over the live product cost in `order_item_unit_cost()` — historical
orders keep the cost they were created with, so the snapshot and live
paths never disagree about a historical order.

Safety rules (unchanged): a stored cost of zero or negative is treated as
"no cost data" (never a 100%-margin assumption); a product with no cost
returns `null`; `estimated_profit = incremental_revenue × margin% −
reward_cost − shipping_cost` is preserved byte-for-byte.

### UI-ready availability metadata

Every attribution summary additionally carries:

- `cost_sources` — the stable source keys above (the React layer
  translates them for the §10 "how to enable profit" help panel).
- `store_has_cost_data` — one cheap store-wide signal (indexed postmeta
  scan, LIMIT 1, memoized per request) telling the UI whether *any*
  product carries cost data. This lets the unavailable state distinguish
  "no cost data anywhere — set up product costs" from "some orders lack
  cost data — coverage X%".

Both keys are mirrored in `MissionPerformanceRow`, the `/analytics` summary
(`null` when the filter cannot be expressed in attribution) and the
zeroed empty summary.

## 6. Terminology

The user-facing labels follow the `UICHANGES.md` §30 terminology table —
internal/technical field names are never shown as primary UX. Never present
estimates as facts:

```text
Internal                User-facing
mission_driven_revenue     Sales Attributed to FaraCart
mission_assisted_revenue   Assisted Sales
mission_influenced_revenue Influenced Sales
incremental_revenue     Additional Sales Value
converted               Purchased Orders
conversion_rate         Purchase Rate
completion_rate         Completion Rate
aov_analysis            Average Basket Increase
estimated_profit        Estimated Profit

Revenue              — plain order revenue
Observed Impact      — AOV before/after comparisons (no causality claimed)
Reward Cost          — estimated cost of granted rewards
```

Two rates stay deliberately distinct everywhere: **Completion Rate**
(`completed / views`) and **Purchase Rate** (`purchased / completed`) — a
mission completion is never presented as a purchase (`UICHANGES.md` §22/§31).

## 7. Feature flags & developer hooks

- Attribution gates on the master + `analytics_enabled` toggles (the same
  consent chain as the Phase 33.1 event pipeline) plus
  `faracart_attribution_enabled`.
- `faracart_product_cost` — supply product costs for margin-aware analytics.
- `faracart_attribution_metric_rows` / `faracart_attribution_order_scan_pages`
  — bound the metric reads for large stores.

## 8. Data accuracy & privacy

- Order totals use WooCommerce's own `get_total()` (currency/timezone
  respected); refunded/cancelled/failed orders are excluded; each order is
  attributed exactly once (unique key + dedup).
- Rows store only anonymous session ids, numeric aggregates and
  plugin/WC ids — no emails, IPs, addresses or payment data.

---

# FaraCart — Smart Mission Recommendation (Phase 33.4)

`MissionRecommendationEngine` (`includes/Analytics/`) answers *"what
threshold should this store use?"* with a fully deterministic,
explainable analysis of the store's own order data — no LLM/AI. The
engine is pure computation: it never writes, never modifies a mission, and
caching/invalidation live in the Phase 33.3 `RevenueRepository`.

## 1. Inputs (analyzers)

| Analyzer | Source | Availability |
| --- | --- | --- |
| AOV / median / CV | `AttributionEngine::store_order_values()` — the same bounded, memoized paginated store scan as AOV/shipping metrics | always (WC order data present) |
| Order distribution | AOV-relative buckets `<0.5×` → `>1.5×` over the scanned totals | always |
| Shipping | `AttributionEngine::shipping_stats()` (average + free share) | per-store |
| Margin | newest catalog products sampled through `faracart_product_cost` (average margin %) | only when the store stores product costs |
| Current mission performance | attribution funnel (views/completed/converted + rates) when `mission_id` is given | per mission |

Window: 7–180 days (default 90, the spec's preferred stability window);
an explicit `from`/`to` range overrides `window_days`.

## 2. Candidates & scoring

Candidate thresholds are generated around the AOV (`0.9× … 1.5×`),
plus shipping-aware additions (`AOV + average shipping`,
`median + average shipping`) for free-shipping missions — all filterable
via `faracart_recommendation_candidates`. Each candidate is scored on
four normalized (0–100) components with filterable weights
(`faracart_recommendation_weights`, defaults in `SCORE_WEIGHTS`):

- **Reachability (30%)** — share of orders within 30% below the
  threshold (the orders that can plausibly close the gap); triangular
  peak at ~30%.
- **Distance (25%)** — stretch above both the median and the AOV: too
  easy adds no revenue, too far is unreachable.
- **Economics (30%)** — reward cost vs the incremental margin at the
  threshold; neutral 50 when margin/reward data is missing (never a
  guessed number).
- **History (15%)** — the store's own completion rate (neutral without
  ≥10 mission views).

Every candidate exposes `factors` (the raw sub-scores) and `reasons`
(plain-English bullets derived from the actual numbers) so the admin UI
can always show *why* a threshold was chosen.

## 3. Confidence & expected impact

Confidence = data-volume tier (`basic` 50–199 orders / `reliable`
200–999 / `high_confidence` 1000+, minimum filterable via
`faracart_recommendation_min_orders`) adjusted by order-value
consistency (CV), margin/shipping availability, mission-history depth and
whether economics had its data — clamped 40–95 (heuristics, never
statistical certainty). Expected impact is derived deterministically:
`expected_aov_impact` (reachable share × gap %), `expected_completion_rate`
(reachable share × history factor) and `expected_profit` through the
same `RewardCostEstimator::profit_impact` model as the attribution
layer — excluded without margin data.

## 4. API & caching

- `GET /faracart/v1/revenue/mission-recommendations` (admin-only) — args:
  `mission_id` (required — the Recommendations page always analyzes exactly
  one selected mission, never an "all missions" context; a `mission_id` that no
  longer resolves returns unavailable instead of silently recommending
  for a deleted mission), `reward_type` (whitelist, optional), `reward_value`
  / `reward_max_value` / `reward_meta` (reward config overrides for
  advanced callers), `window_days`, `from`, `to`. Returns the analysis,
  the ranked candidates (score, confidence, expected impact, reasons,
  factors), the top `recommendation` and `mission_id` (so the UI can
  validate the payload belongs to the selected mission).
- `POST /faracart/v1/revenue/mission-recommendations/apply` (admin-only,
  UPSELL_REFACTOR §10/§41) — the only write path: `mission_id` +
  `threshold` applies a chosen threshold to an existing mission (never any
  other Mission setting), records the `recommendation_applied`
  feedback-loop event (old target + applied threshold, deduped daily
  per mission) and invalidates the revenue caches. The engine itself never
  modifies a mission — applying is an explicit, permission-checked admin
  action.
- `GET /faracart/v1/revenue/cost-coverage` (admin-only, §25/§46) —
  catalog product-cost coverage (`costed_products` / `total_products` /
  `coverage_pct` / `has_cost_data`) so the Recommendations UI can
  explain why profit estimates may be unavailable.
- Served through `RevenueRepository::mission_recommendations()` — the same
  generation-versioned transients; the existing invalidation (order
  payment/status, mission CRUD, product saves, aggregation) already keeps
  recommendations fresh. TTL: `faracart_recommendation_cache_ttl`.
- **Safety:** the engine never changes a mission. Applying a recommendation
  is an explicit admin action through the apply endpoint.

## 5. Graceful degradation

- Fewer than the minimum orders → `available: false` with
  `insufficient_reason` (no fabricated recommendation).
- No WooCommerce order data → unavailable with reason.
- No margin data → profit excluded, economics neutral, confidence
  reduced; revenue analytics keep working.
- Disabled via `faracart_recommendations_enabled` → unavailable.

## 6. Extensibility (future AI/ML)

The public `recommend()` payload is the frontend contract. A future
`MLMissionRecommendationEngine` can replace the deterministic class behind
that same shape without touching the REST layer or the admin UI (P33-60).

---

# FaraCart — Smart Upsell Ranking (Phase 33.5)

`UpsellRanker` (`includes/Analytics/`) answers *"which products should
this shopper add to reach the mission?"* with a fully transparent,
deterministic weighted ranking — no LLM/AI. Every product exposes its
component scores, raw factors, historical conversion stats and
plain-English reasons, so the admin UI and the storefront can always
show *why* a product was recommended.

## 1. Pipeline

1. **Candidates (P33-26)** — the mission's own products (`manual`),
   products historically recommended for the mission (`historical`),
   products in the mission's categories (`category`), the cart items'
   WooCommerce-endorsed sources (`upsell` / `cross_sell` / `related`),
   products sharing a category or tag with the cart (`category_match` /
   `tag_match`) and best sellers (`popular`). Bounded to 60 candidates;
   out-of-stock / private / draft / already-in-cart / mission-excluded
   products never reach scoring.
2. **Six normalized (0–100) component scores** with filterable weights
   (`faracart_upsell_weights`, defaults below).
3. **Ranking (P33-34)** — composite `score` desc; ties break by lower
   price, then product id (fully deterministic).

## 2. Component scores

| Component | Default weight | Signal |
| --- | --- | --- |
| `price_gap` | 25% | how well the price fits the remaining mission gap — sweet band `[0.75×, 1.30×]` scores 100 (small overshoots tolerated, P33-27/36), hard decay to 0 at 3×; neutral 50 without a price/gap |
| `relevance` | 25% | mission eligibility (manual products +55, counts-toward-mission +35), category overlap with the cart (+30), tag overlap (+20), WC-endorsed source trust (+15) |
| `popularity` | 15% | units sold (bounded at 100) + average rating |
| `inventory` | 10% | stock >20 → 100, 5–20 → 70, 1–4 → 40, backorder → 20; unmanaged stock → neutral 70 |
| `margin` | 15% | only when the store provides product cost data (never invented); neutral 50 otherwise |
| `conversion` | 10% | the product's historical upsell funnel, impressions-weighted so sparse data blends toward neutral 50 |

A partial `faracart_upsell_weights` filter falls back per key (missing
keys keep their defaults unchanged, provided keys normalize among
themselves) — a filter returning only `price_gap`/`relevance` cannot
zero the other components.

## 3. Historical learning (P33-35)

The storefront reports upsell interactions through
`POST /faracart/v1/upsell/track` (public, privacy-safe session ids) into
the Phase 33.1 `upsell_events` log: `upsell_impression` (deduped per
session+mission+product within 24h), `upsell_clicked`, `upsell_added` and
`upsell_order`. When a paid order completes, `attribute_order()` resolves
the ordering session (the `order_paid` event's session, then the live
cookie, then the user's recent revenue session) and records one
`upsell_order` event per product shown/clicked/added in that session —
the "purchased after recommendation" signal. The Phase 33.3
`DailyAggregator::aggregate_upsells()` rebuilds `upsell_stats` from that
log, and the conversion scorer reads the aggregates: deterministic
historical scoring, no black-box model.

## 4. Graceful degradation (P33-51)

- No mission / no remaining gap → `available: false` with a reason (never a
  fabricated list); a closed gap is explicit.
- Disabled via `faracart_upsells_enabled` → unavailable with a reason.
- No margin data → margin neutral 50, `profit_available: false`,
  `estimated_profit: null` — the product still ranks.
- No historical data → conversion neutral 50.
- No candidates → unavailable with a reason.

## 5. API & caching

- `GET /faracart/v1/revenue/upsells` (admin) — the ranked products for a
  cart + mission context (`mission_id`, `cart_value`, `remaining`, `cart`,
  `limit`), including weights, context echo and per-product breakdowns.
- `GET /faracart/v1/revenue/upsells/{product_id}` (admin) — one product's
  score breakdown + historical stats (null for unknown products).
- Both are served through `RevenueRepository::upsell_ranking()` /
  `upsell_product_detail()` — the same generation-versioned transients
  as every other revenue read; the existing invalidation (order
  payment/status, mission CRUD, product saves, aggregation) keeps rankings
  fresh. `RevenueRepository::upsell_analytics()` powers the admin
  top-products table over a window (impressions/clicks/adds/orders/
  revenue/profit/score).
- `faracart_upsell_candidates` / `faracart_upsells` filters let callers
  pin the candidate set and shape the payload.
- **Safety:** the ranker never writes anything. Historical events are
  recorded only by the public track endpoint and the order hooks.

## 6. Extensibility (future AI/ML)

The public `rank()` payload is the frontend contract. A future
`MLUpsellRanker` can replace this class behind the same payload shape
without touching the REST layer or the admin UI (P33-60).

---

# FaraCart — React Admin Revenue Section (Phase 33.6 + UICHANGES.md)

The admin analytics area is **Sales Performance** (`UICHANGES.md` §4/§6):
one primary analytics destination answering "is FaraCart helping my
store sell more profitably?" — the Overview (four business KPI cards,
trend, funnel, insights) and the Mission Performance comparison table —
plus the **Optimization** engines (Recommendations for better Mission
targets, Upsells for product recommendations). The legacy `Analytics`
and Attribution Dashboard pages stay reachable by URL for backward
compatibility but are not primary navigation items (§25/§26/§39).

All pages share the existing admin conventions (date-range context +
filter toolbar, skeleton loading, `EmptyState`, error Alerts, MUI RTL)
and read through the cached Phase 33.3 `RevenueRepository` only — no
new uncached queries.

## 1. Pages & data sources

| Page | Route | REST endpoint | Repository read |
| --- | --- | --- | --- |
| Overview (Sales Performance) | `/revenue` | `GET /revenue/overview` | `overview()` + `daily_trend()` |
| Mission Performance | `/revenue/missions` | `GET /revenue/missions` | `mission_performance()` |
| Attribution Dashboard (legacy, not in primary nav) | `/revenue/attribution` | `GET /revenue/attribution` | `overview()` |
| Recommendations | `/optimization/missions` | `GET /revenue/mission-recommendations` + `POST /revenue/mission-recommendations/apply` | `mission_recommendations()` |
| Upsells | `/optimization/upsells` | `GET /revenue/upsells?analytics=1` + `GET /revenue/upsells/{id}` | `upsell_analytics()` / `upsell_product_detail()` |

`/revenue/recommendations` and `/revenue/upsells` redirect to the
Optimization routes so bookmarked URLs keep working (§39). The revenue
routes (`/revenue/overview`, `/revenue/attribution`, `/revenue/missions`)
live in the admin-only `RevenueController`
(`includes/REST/RevenueController.php`) — manage_options-gated, per-user
rate limited, `from`/`to`/`mission_id` validated through the arg schema, and
served through the same generation-versioned transients as every other
revenue read.

## 2. Revenue Overview (Sales Performance → Overview)

The first viewport answers the store owner's question in seconds
(`UICHANGES.md` §42): exactly **four primary KPI cards** — Sales
Attributed to FaraCart (with a "How is this calculated?" panel),
Average Basket Increase (labeled *Observed impact*), Purchased Orders
and Estimated Profit (every data state, never a fabricated number).
Below the KPIs:

- **Daily revenue trend** — defaults to Attributed Sales + Purchased
  Orders (Mission Completions toggle; Additional Sales Value behind the
  Advanced toggle), zero-filled over the window, today's live bucket
  merged (`revenue_daily` + `AttributionEngine`).
- **The canonical funnel** (`UICHANGES.md` §18) — views → progressed →
  completed → purchased with the percentage each stage carries from the
  previous one, so the largest drop-off reads at a glance. This is the
  only store-wide copy of the funnel; the same funnel appears again only
  scoped to a single mission in the Mission Detail drawer.
- **Deterministic business insights** (§17) — 2–3 plain-English cards
  derived from the actual payload (purchases influenced, basket change,
  completion→purchase drop-off, profit) — never an LLM, never causality.
- **AOV impact panel** — store-wide vs mission-exposed vs non-exposed AOV
  with absolute + percentage change, labeled *observed impact* (never
  causality).
- **Shipping panel** — average shipping, free-shipping share, orders
  with shipping and per-method averages.
- **Advanced attribution** (§26/§30) — direct / assisted / influenced
  sales, incremental cart value, attribution window and the
  observed-impact disclaimer behind an accordion.

## 3. Mission Performance

One row per mission: funnel counts (views → progressed → completed →
converted), completion/conversion rates, average + incremental cart
value, attributed + assisted revenue, reward cost and profit impact.
Since the UPSELL_REFACTOR pass, each row also carries
`upsell_assisted` / `upsell_assisted_rate` (completions whose session
also engaged the smart-upsell panel — the "suggested products helped
close the mission" signal, computed as distinct sessions in the mission's
completion funnel that also have an upsell interaction). The
Recommendations drawer section and the Upsells page surface this same
signal. Rows expand into the funnel visual + detail panel.

## 4. Attribution Dashboard

The funnel visual with completion/conversion rates, direct vs assisted
model revenue cards (plus distinct order count), incremental cart value
(average/total/baseline/sessions + data-sufficiency badge) and the
profit-impact panel — which shows its `profit_reason` instead of a
number when the store provides no margin data (revenue-only analytics).

## 5. Recommendations (Mission Recommendation UI)

The Phase 33.4 payload rendered with the simplified presentation
(`Improvement.md` §33–§34 / `UICHANGES.md` §40) — the same engine, the
same endpoint, a business-first layout:

- **Single best recommendation** — the engine still generates and ranks
  every eligible candidate deterministically (score desc, ties → lower
  threshold) and the payload still carries `candidates[]` plus the
  selected `recommendation` (= `scored[0]`, the best); the page renders
  **only that one** — never a list of competing candidates. The card
  shows the recommended mission target up front, a **"Confidence: High /
  Medium / Low"** label (tiered from the raw 0–100 score, which stays
  hidden), the **expected impact** as "+8% – +14% average basket value",
  the expected profit with the §34 unavailable state ("Not available —
  add product cost data to estimate profitability", never a guessed
  number), and the plain-English **"Why?"** bullets directly on the
  card. The raw scoring details (score, component scores, AOV/median
  ratios, reach shares, reward cost) live behind an **Advanced details**
  expander.
- **Analyzed store data** — AOV, median, orders analyzed, window,
  shipping/margin availability and a business-language data-sufficiency
  label (Limited / Moderate / Good data), plus the order-value
  distribution bars. Every percentage is mathematically valid: the
  distribution is rendered bucket-by-bucket from the engine's
  `share` rates (never `Object.entries()` over the array — that was the
  `NaN%` bug), the margin factor formats its 0–1 rate with
  `formatPercent` (not `formatPercentValue`), the coverage percentage
  is used as 0–100 points (never divided by 100), and the shared
  `formatPercent` / `formatPercentValue` formatters render
  non-finite / missing inputs as "—" so a 0 denominator can never
  surface as `NaN%` / `Infinity%` anywhere.
- **Apply / View details / Dismiss** on the card. **Apply** goes through
  the dedicated `POST /revenue/mission-recommendations/apply` endpoint
  (confirm dialog → one `mission_id` + `threshold` write — the engine never
  modifies a mission, P33-53), which records the `recommendation_applied`
  feedback-loop event and invalidates the revenue caches. The selected
  mission's own current performance (its funnel + `upsell_assisted`
  completion count from `mission_history`) is shown on the card.
- **Product-cost coverage** — a banner ties the Profit estimates to the
  catalog coverage (`GET /revenue/cost-coverage`: "x / y products carry
  cost data"), so "Not available" is explained instead of appearing
  arbitrary.

## 6. Upsells

The top-products page (`upsell_analytics()` over the window) is
**commercial-first** (Improvement.md §35 / `UICHANGES.md` §40): the first
screen answers
*"which suggested products actually generate purchases and sales?"*.
A summary strip leads with **Products / Purchased Orders / Sales /
Conversion**, and the table's primary columns are **Product / Orders /
Sales / Estimated profit / Conversion**. The interaction funnel
(impressions / clicks / adds / CTR / add-to-cart rate) and the upsell
score sit behind a **"Show interaction details"** toggle — kept as
details, not the primary read. CTR and add-to-cart rate are derived
client-side from the real funnel counts (clicks÷impressions,
adds÷impressions) and render "—" when there is no denominator (never a
fabricated 0%). The four spec views are client-side sorts of the same
rows, re-based on commercial outcomes: top performing (purchases then
sales), lowest performing, best conversion (products without
impressions sort last — no denominator) and highest margin (unavailable
margins last). Estimated profit renders "—" unless the row carries
`profit_available` and `estimated_profit` — never a guessed number.
Clicking a row opens the product's score-breakdown dialog via
`upsell_product_detail()`: the six 0–100 components, plain-English
reasons, raw factors and historical funnel stats. Each analytics row
carries `estimated_profit` / `profit_available` / `margin_pct` — null
when the store stores no product costs (never invented).

## 7. UX states & empty states (Phase 9 polish)

Every revenue page implements the full frontend-state contract
(Improvement.md §43/§44) — loading skeletons, error Alerts, empty /
unavailable / partial / zero / negative states, never a blank card:

- **Distinct empty states (§44)** — "No sales data yet" (no FaraCart
  interactions at all) is different from "No purchases yet" (customers
  interact with missions but no attributed purchase was recorded in the
  period). The Sales Performance page and the Mission Conversion & Purchase
  Analysis page each branch on the funnel: `views === 0 && orders === 0`
  shows the first; `views > 0 && orders === 0` shows the second with
  its own §44 copy and icon.
- **Unavailable / partial** — the Estimated Profit card renders "Not
  available", "Limited data" (with cost-coverage %) and "—" states
  without inventing a number; the Analytics page shows an info Alert
  when a product filter makes the purchase pipeline unavailable.
- **Zero and negative** — zero profit stays 0 (real value); negative
  profit renders in the card with a short explanation.
- **Observed impact (§46)** — every AOV comparison is labeled
  *Observed impact* with a single subtle sentence (never a legal-style
  disclaimer).
- **Responsive (§51)** — KPI grids collapse to 2 columns on small
  screens, tables scroll horizontally (`overflowX: auto`), the chart
  keeps a readable legend, and the admin stays free of horizontal
  overflow.
- **Accessibility (§53)** — charts expose `role="img"` + aria-label
  summaries, expandables carry `aria-expanded`, buttons/toolbars carry
  aria-labels, interactive table rows are keyboard-activatable, and
  positive/negative states pair color with a text sign.
- **i18n (§52)** — every label goes through `__()`/`sprintf()` with the
  domain `faracart`; new strings are translated to fa_IR and the
  JED/MO artifacts rebuilt (`tests/i18n-test.php` gates coverage).

## 8. Governing spec

`UICHANGES.md` is the authoritative UX specification for this area
(replaces the earlier `Improvement.md` initiative): the information
architecture (§4), the four-KPI overview (§7–§15), the canonical funnel
(§18), the commercial mission table (§19–§23), the Mission Detail drawer
(§24), progressive disclosure of advanced attribution (§26–§28), the
metric ownership map (§29), user-facing terminology (§30) and the
empty/data states (§32–§33). The backend attribution model, profit
methodology and caching are unchanged — this is a presentation/navigation
redesign over the existing cached reads.
