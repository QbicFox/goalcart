# REVENUE_ANALYTICS_AUDIT.md

> **Phase 1 — Codebase Audit** for the *Revenue & Analytics UX Simplification, Profit
> Visibility and Purchase Analytics* initiative (see `Improvement.md`).
>
> This document is a **read-only audit**. No code was modified. It maps the existing
> FaraCart revenue/analytics implementation to the requested UX, lists what is
> already reusable, what is missing, and what later phases must change.
>
> Audit date: 2026-08-12 · Scope: `includes/`, `admin-app/src/`, `tests/`, `docs/`,
> `languages/`.

---

## 1. Executive summary

FaraCart already contains a complete, tested revenue-attribution and analytics stack
(Phases 33.1–33.6 of `AGENT.md`): a bounded event pipeline, an attribution engine with
direct/assisted models, daily aggregation, a cached repository layer, admin-only REST
endpoints and five React pages. **The core data needed by the Improvement.md UX already
exists.** The initiative is therefore mostly a **presentation/navigation layer change**
over existing data, plus a small number of additive backend payload extensions.

Key findings:

| Finding | Status |
| --- | --- |
| Purchased-order counts (distinct attributed orders) | **Already computed** — `funnel.converted`, `summary.orders`, per-goal `converted`, daily `conversions` |
| Purchase rate (`converted / completed`) | **Already computed** — `conversion_rate` (same semantic the spec requires) |
| Attributed sales (direct/incremental) | **Already computed** — `goal_driven_revenue` / `incremental_revenue` |
| AOV impact with "observed, not causal" label | **Already computed** — `aov_analysis()` with `label: 'observed_impact'` |
| Estimated profit + availability + reason | **Already computed** — `profit_impact`, `profit_available`, `profit_reason` |
| Reward cost + shipping cost inputs | **Already computed** — `reward_cost`; shipping inside the profit model |
| Product cost sources (`_cost`, `_wc_cog_cost`, variation→parent, `goalcart_product_cost` filter) | **Already implemented** in `RewardCostEstimator` |
| Cost-coverage indicator | **Missing** — requires new (optional, safely derivable) backend work |
| Machine-readable profit reason codes | **Missing** — `profit_reason` is human-readable text only |
| `/analytics` legacy endpoint with purchase/profit fields | **Missing** — only the revenue endpoints expose purchase/profit data (§37 extension) |
| Deterministic plain-English insight cards | **Missing** — new frontend logic over existing data |
| Simplified navigation / renamed concepts / progressive disclosure | **Missing** — frontend-only |
| Goal detail drawer, advanced attribution drawer, funnel ending in "Purchased" | **Missing** — frontend-only (data exists) |
| In-plugin product-cost configuration route | **Missing** — cost data is read only from WooCommerce product meta + `goalcart_product_cost` filter (spec §10: link out / help panel, no fake DB) |

Bottom line: no new analytics engine, no new attribution logic, no new order scans are
required. Later phases extend existing payloads and rebuild the React presentation.

---

## 2. Current architecture

### 2.1 Backend data flow

```text
Storefront events                     Order lifecycle
  goal_view / goal_progress /          woocommerce_payment_complete
  goal_completed / order_paid /        woocommerce_order_status_completed
  upsell_*  (RevenueTracker)           → AttributionEngine::attribute_order()
        │                                          │
        ▼                                          ▼
  revenue_events (append-only log)     goal_attribution (per order+goal+model,
        │                                UNIQUE order_goal_model — exactly once)
        ▼                                          ▼
  DailyAggregator (daily cron)     AttributionEngine reads
  revenue_daily (per goal/day)     funnel / incremental_cart_value /
  upsell_stats (per product)       attribution_summary / aov_analysis /
        │                          shipping_stats / goal_metrics / daily_metrics
        ▼                                          │
  RevenueRepository (cached, generation-versioned transients) ◀── DailyAggregator + order/goal/product events invalidate
        │
        ▼
  REST: RevenueController (/revenue/*), AnalyticsController (/analytics),
        UpsellController (/revenue/upsells*), RecommendationsController
        (all admin-only: manage_options + per-user rate limit)
        │
        ▼
  React admin (admin-app/src/routes/*)
```

A second, older analytics stack exists in parallel (Phase 16/17):
`Tracker` → `analytics_events` → `AnalyticsRepository` → `GET /goalcart/v1/analytics`
(impressions/completions/revenue-influenced/CTR/ATC). It counts **widget interactions**,
not attributed orders, and must be preserved for API compatibility (§37). The two stacks
are deliberately separate tables and must not be merged.

### 2.2 Database tables (`includes/Database/Schema.php`)

| Table | Purpose | Relevant columns |
| --- | --- | --- |
| `analytics_events` | Phase 16 widget-interaction log | event_type, goal_id, session_id, cart_value |
| `revenue_events` | Phase 33.1 raw revenue log | event_type, goal_id, order_id, session_id, cart_value, goal_target, incremental_value |
| `goal_attribution` | Phase 33.2 per-order attribution | order_id, goal_id, model (direct/assisted), order_total, incremental_value, goal_completed |
| `revenue_daily` | Phase 33.3 daily aggregates | report_date, goal_id, views, progressions, completions, conversions, revenue, incremental_revenue, reward_cost, estimated_profit |
| `upsell_events` | Phase 33.1 upsell interaction log | event_type, product_id, order_id, session_id |
| `upsell_stats` | Phase 33.3 per-product aggregates | product_id, impressions, clicks, adds, orders, revenue |

All queries are indexed, `$wpdb->prepare`-bound, bounded
(`goalcart_attribution_metric_rows` = 5000, `goalcart_attribution_order_scan_pages` =
100 × 100), and cached through generation-versioned transients
(`RevenueRepository::cached()`).

### 2.3 REST endpoints (admin-only unless noted)

| Endpoint | Controller | Payload |
| --- | --- | --- |
| `GET /goalcart/v1/revenue/overview` | `RevenueController` | summary + incremental_cart_value + aov + shipping + trend |
| `GET /goalcart/v1/revenue/attribution` | `RevenueController` | overview minus trend |
| `GET /goalcart/v1/revenue/goals` | `RevenueController` | `{ items: [per-goal metrics] }` |
| `GET /goalcart/v1/revenue/goal-recommendations` | `RecommendationsController` | recommendation engine payload |
| `GET /goalcart/v1/revenue/upsells` (+`?analytics=1`) | `UpsellController` | ranking / analytics rows |
| `GET /goalcart/v1/revenue/upsells/{product_id}` | `UpsellController` | product score breakdown |
| `GET /goalcart/v1/analytics` | `AnalyticsController` | legacy Phase 17 dashboard payload |

All revenue routes share the same arg schema: `from`/`to` (validated dates), `goal_id`
(≥ 0). Permissions: `manage_options` (filterable), per-user rate limited; public upsell
track endpoint is nonce-guarded + per-IP rate limited.

### 2.4 React admin (`admin-app/src/`)

| Route | Component | Data source |
| --- | --- | --- |
| `/revenue` | `routes/RevenueOverview.tsx` | `fetchRevenueOverview` |
| `/revenue/goals` | `routes/GoalPerformance.tsx` | `fetchGoalPerformance` |
| `/revenue/attribution` | `routes/AttributionDashboard.tsx` | `fetchRevenueAttribution` |
| `/revenue/recommendations` | `routes/Recommendations.tsx` | `fetchGoalRecommendations` |
| `/revenue/upsells` | `routes/UpsellAnalytics.tsx` | `fetchUpsellAnalytics` / `fetchUpsellProduct` |
| `/analytics` | `routes/Analytics.tsx` | `fetchAnalytics` |

Shared infra: `DateRangeContext` (+ `DateRangeFilter`), `RevenueToolbar` (date range +
goal selector), `FunnelVisual`, `PageContainer`, `EmptyState`, `KpiCard` patterns,
`lib/format.ts` (locale-aware currency/number/percent), MUI + Emotion RTL, `@wordpress/i18n`.

### 2.5 Existing i18n

- PHP/TS/JS strings all use `__( '…', 'goalcart' )`; POT generated by
  `bin/extract-pot.php`, JED/MO by `bin/build-i18n.php`; `fa_IR` translations committed
  (`languages/goalcart-fa_IR.po` + JED).
- `tests/i18n-test.php` scans sources for hard-coded Persian — all new UI strings must go
  through the same pipeline.

---

## 3. Reusable services (do NOT rebuild)

| Service | File | Reuse for |
| --- | --- | --- |
| `AttributionEngine` | `includes/Analytics/AttributionEngine.php` | All attribution/funnel/AOV/shipping/profit reads; `funnel()`, `attribution_summary()`, `incremental_cart_value()`, `aov_analysis()`, `shipping_stats()`, `goal_metrics()`, `daily_metrics()` |
| `RevenueRepository` | `includes/Analytics/RevenueRepository.php` | Cached read layer — overview / goal performance / daily trend / recommendations / upsell analytics. **Never bypass its caching.** |
| `RewardCostEstimator` | `includes/Analytics/RewardCostEstimator.php` | Reward cost models, product cost/margin reads, `profit_impact()` formula, shipping total |
| `DailyAggregator` | `includes/Analytics/DailyAggregator.php` | Pre-aggregated `revenue_daily` / `upsell_stats` |
| `AnalyticsRepository` | `includes/Analytics/AnalyticsRepository.php` | Legacy `/analytics` metrics (keep for compatibility) |
| `RevenueTracker` | `includes/Analytics/RevenueTracker.php` | Event constants + consent chain (do not duplicate event types) |
| `GoalRecommendationEngine` | `includes/Analytics/GoalRecommendationEngine.php` | Smart recommendations (§33/34) |
| `UpsellRanker` | `includes/Analytics/UpsellRanker.php` | Upsell ranking/analytics (§35) |

---

## 4. Existing purchase/conversion metrics (already available)

The spec's §18 definition `conversion_rate = converted / completions` **matches the
existing implementation exactly** (`AttributionEngine::funnel()`:
`conversion_rate = $completed > 0 ? converted / completed : null`). "Purchased" is the
existing `converted` (distinct attributed orders), and "goal completion ≠ purchase" is
already the engine's core rule (`goal_completed` flag ≠ an associated order).

| Requested metric (Improvement.md) | Existing field | Where |
| --- | --- | --- |
| Purchased Orders (store-wide) | `summary.orders` (= `funnel.converted`) | `RevenueRepository::overview()` → `summary` |
| Purchased Orders (trend) | `trend[].conversions` | `RevenueRepository::daily_trend()` |
| Purchased Orders (per goal) | `goal_metrics().converted` | `RevenueRepository::goal_performance()` |
| Purchase Rate | `conversion_rate` (null when 0 completions — the spec's "—" rule already holds) | `funnel()`, `goal_metrics()` |
| Completion Rate | `completion_rate` (completed/views) | `funnel()`, `goal_metrics()` |
| Sales attributed to FaraCart | `goal_driven_revenue` (direct incremental) | `attribution_summary()` |
| Assisted sales | `goal_assisted_revenue` | `attribution_summary()` |
| Influenced sales | `goal_influenced_revenue` | `attribution_summary()` |
| Incremental cart value | `incremental_cart_value.average` (+ baseline, sessions) | `incremental_cart_value()` |
| AOV impact | `aov_analysis()` (`absolute_change`, `percentage_change`, `overall_aov`, `exposed_aov`, `non_exposed_aov`, `label: 'observed_impact'`) | `aov_analysis()` |
| Reward cost | `summary.reward_cost` + `reward_cost_available` | `attribution_summary()` |
| Shipping stats | `shipping_stats()` | overview payload |
| Estimated profit | `summary.profit_impact` + `profit_available` + `profit_reason` | `attribution_summary()` |
| Per-goal sales/profit | `goal_metrics().attributed_revenue`, `assisted_revenue`, `reward_cost`, `profit_impact` | `goal_metrics()` |
| Data sufficiency | `incremental_cart_value.data_sufficiency` (low/medium/high) | `incremental_cart_value()` |
| Attribution window / model | `ATTRIBUTION_WINDOW` (30 days), `MODEL_DIRECT`/`MODEL_ASSISTED` | `AttributionEngine` constants |
| Expected profit (recommendations) | `expected_profit` + `expected_profit_available` | `GoalRecommendationEngine` payload |

---

## 5. Profit availability flow (as implemented today)

```text
WooCommerce product meta (_cost, _wc_cog_cost)  ─┐
variation → parent fallback                      ├─→ RewardCostEstimator::product_cost()
goalcart_product_cost filter                     ┘        (never invents a cost)
        │
        ▼
order_margin_stats(order)  — requires cost data on EVERY line item;
        │                    otherwise the WHOLE order has no margin data (strict)
        ▼
profit_impact():
   estimated_profit = incremental_revenue × margin_pct − reward_cost − shipping_cost
   margin_pct == null  →  { estimated_profit: null, available: false, reason: <text> }
   margin_pct != null  →  { estimated_profit: <number>, available: true, reason: null }
```

- **Available** — `profit_available: true`, `profit_impact: <number>` (zero and negative
  values are already possible; negative profit is never hidden).
- **Unavailable (no product cost data)** — `profit_available: false`, `profit_impact:
  null`, `profit_reason: "Product cost data is not available — profit impact
  unavailable (revenue-only analytics)."`.
- **Revenue-only fallback** — all revenue metrics still compute when profit is
  unavailable (graceful degradation, already tested).
- **Exposed via REST** — revenue endpoints carry `profit_impact`/`profit_available`/
  `profit_reason`; upsell analytics rows carry `estimated_profit`/`profit_available`/
  `margin_pct`; recommendations carry `expected_profit`/`expected_profit_available`.
  Public (non-admin) upsell payloads **redact** margin/profit fields.
- **UI today** — Revenue Overview shows `—` + `profit_reason`; Attribution Dashboard
  shows the reason text; Goal Performance shows an `n/a` chip; Upsell Analytics hides
  profit when unavailable.
- **No in-plugin cost-configuration route exists** — `Settings` has no cost/margin
  fields; product costs come only from WooCommerce product meta or the
  `goalcart_product_cost` filter. Spec §10's "Set up product costs" CTA therefore needs
  either a link to WooCommerce product editing or an in-plugin help panel — no fake
  product-cost database.

---

## 6. Missing metrics & required backend changes (for Phases 2–3)

All additive; existing fields/routes are never removed or renamed.

1. **Extend the legacy `GET /goalcart/v1/analytics` summary** (§37) with, derived from
   the existing `AttributionEngine` (goal-scoped by the same filters):
   - `progressed` (funnel `progressed`)
   - `purchased_orders` (= `funnel.converted`)
   - `purchase_rate` (= `conversion_rate`, null-safe)
   - `attributed_sales` (= `goal_driven_revenue`)
   - `estimated_profit` / `profit_available` / `profit_reason`
   All computed from already-aggregated data — no new order scans, no new engine.
2. **Machine-readable profit reason codes** (§39) — add a stable `profit_reason_code`
   alongside the human `profit_reason` (e.g. `available`, `missing_product_cost`,
   `incomplete_product_cost`, `missing_margin_data`, `insufficient_data`). The current
   code only emits one unavailable reason; a code field makes React states clean.
   Reason strings are already translatable via `__()`.
3. **Optional cost-coverage indicator** (§11) — expose `cost_coverage_pct` /
   `orders_with_margin / attributed_orders` only if derivable without changing the
   strict profit model. The engine already counts rows with/without margin inside
   `profit_impact_for_rows()`; surface that count. Keep the strict all-or-nothing
   profit calculation unchanged; coverage is informational.
4. **No new endpoints required for purchased orders / purchase rate / attributed
   sales / estimated profit** — all exist in the revenue payloads.

### Tests to add in Phase 2/10 (mirroring `attribution-test.php` style)

- Funnel: views/progressed/completed/purchased + completion & purchase rates
- Purchase: 0 / 1 / multiple purchases; multiple goals; direct; assisted; mixed
  direct+assisted; duplicate order events (idempotency)
- Profit: complete / missing / partial cost data; zero; negative; reward cost;
  shipping cost; margin math
- Date filtering (same range across metrics) and `goal_id` filtering
- Permissions/security unchanged (admin-only, rate limits, schema validation)

---

## 7. Required frontend changes (for Phases 4–9)

### 7.1 Navigation & naming (§3, §4)

- Rename the user-facing section from "Revenue" to **"Sales Performance"** (or "Goal
  Cart Performance") — keep `revenue/*` routes and API names (backward compatible).
- Restructure nav: Overview → Goals → Optimization (Recommendations + Upsell
  Analytics). **Remove "Attribution" from primary navigation**; keep
  `/revenue/attribution` for backward compatibility and link to it from an
  "Advanced Attribution" expandable section.

### 7.2 Revenue Overview (§5–§15, §49)

- Four primary KPI cards, in order:
  1. **Sales Attributed to FaraCart** — `goal_driven_revenue`, hint = `summary.orders`
     purchased orders. Expandable "How is this calculated?" showing direct / assisted /
     influenced / incremental revenue + attribution methodology (from the same summary
     payload).
  2. **Average Basket Increase** — `aov.percentage_change` (+ absolute change on
     expand), comparison rows (store avg / goal-exposed / difference), always labeled
     **"Observed impact"**.
  3. **Purchased Orders** — `summary.orders` / `funnel.converted`, hint "after Goal
     Cart interaction".
  4. **Estimated Profit** — `profit_impact` with the full data-state matrix (§13):
     available / unavailable (CTA + learn how) / limited / zero / negative.
- Trend chart: default two series — **Sales** (`revenue`) + **Purchased Orders**
  (`conversions`); toggles for Goal Completions (`completions`) and optional
  Incremental Revenue (`incremental_revenue`).
- 2–3 deterministic insight cards (§15) — derived client-side from the payload (best
  goal by sales, purchases count, completion→purchase gap). No LLM.
- Profit details drawer (§12) with sales / margin / reward cost / shipping /
  profit lines — all real backend values.
- Advanced attribution drawer (§30/§47) hosting the current Attribution Dashboard
  content (direct/assisted/influenced, basket analysis, profit model, data quality).

### 7.3 Goal Performance (§16–§20, §27)

- Columns: Goal | Viewed | Progressed | Completed | **Purchased** | **Purchase Rate**
  | **Sales** | **Profit** (keep completion rate as secondary/tooltip).
- Rename labels: Converted → Purchased, Conversion → Purchase Rate, Est. Profit →
  Estimated Profit (with availability states).
- Expand row → **detail drawer** (§20): Performance summary, funnel ending in
  Purchased, revenue section, costs section, and an "Advanced attribution details"
  subsection (assisted/influenced/incremental already in the row).

### 7.4 Analytics (§21–§29, §50)

- Title → **"Goal Conversion & Purchase Analysis"**.
- KPI row: primary = Purchased Orders, Purchase Rate, Attributed Sales, Estimated
  Profit; secondary = Goal Views, Goal Completions.
- Funnel ends in **Purchased** (Views → Progressed → Completed → Purchased) with
  stage-to-stage percentages.
- Purchase Analysis section + Completed-vs-Purchased comparison table (§25).
- Goal comparison table sortable by Purchased / Purchase Rate / Sales / Profit /
  Completion Rate, default sort = Attributed Sales.
- Deterministic drop-off insights (§26) when data supports them.
- Preserve the existing `/analytics` API compatibility; the extended summary fields
  (§6.1) feed the new KPIs.

### 7.5 Recommendations (§33–§34)

- Primary card: recommended target, plain-English "Why?" bullets (already in
  `reasons`), expected impact range, **"Confidence: High/Medium"** label instead of raw
  score; advanced details behind an expandable.
- Expected profit unavailable state: "Not available — add product cost data…" (already
  have `expected_profit_available`).

### 7.6 Upsell Analytics (§35)

- Primary columns: Product | Orders | Sales | Estimated Profit | Conversion; move
  impressions/clicks/adds/CTR/ATC/score behind an expandable/secondary view.
- Rows already carry `orders`, `revenue`, `estimated_profit`, `conversion_rate`,
  `margin_pct` — no backend change needed.

### 7.7 Shared UX requirements

- **Frontend states** (§43/§44): loading, empty ("No sales data yet" vs "No purchases
  yet" — distinct), unavailable, partial, error, zero, negative — no blank cards.
- **Terminology** (§32): apply the full technical→user-facing label table; never show
  raw field names.
- **i18n** (§52): add translations for all new labels (Purchased Orders, Purchase Rate,
  Sales Attributed to FaraCart, Average Basket Increase, Estimated Profit, Cost Data,
  Cost Coverage, Advanced Analytics, Purchase Analysis, Observed Impact, Data
  Sufficiency, profit states…), regenerate POT/JED, keep `fa_IR` in sync.
- **RTL / responsive / a11y** (§51/§53): 2-column KPI grid on small screens,
  scrollable tables, ARIA states for expandables, keyboard-accessible tooltips,
  text+icon (not color alone) for positive/negative/neutral.

---

## 8. Field-mapping table (existing data → requested UI)

| Improvement.md request | Existing source | Change needed |
| --- | --- | --- |
| Sales Attributed to FaraCart | `summary.goal_driven_revenue` | label only |
| Average Basket Increase | `aov.percentage_change` / `absolute_change` | label + "Observed impact" |
| Purchased Orders | `summary.orders` / `funnel.converted` / `trend[].conversions` / `goal.converted` | label ("Converted" → "Purchased") |
| Purchase Rate | `funnel.conversion_rate` / `goal.conversion_rate` | label ("Conversion" → "Purchase Rate"); tooltip |
| Estimated Profit | `summary.profit_impact` (+ `profit_available`/`profit_reason`) | states UI; reason codes (backend, optional) |
| Cost coverage | not exposed | new derived field (backend, optional) |
| Insight cards / drop-off insights | derived from payload | new frontend logic |
| Advanced attribution | existing Attribution Dashboard payload | move behind expandable |
| Goal detail drawer | `goal_metrics()` row (all fields present) | frontend drawer |
| Recommendations simplification | `expected_*` + `reasons` + `confidence` | presentation |
| Upsell simplification | `orders`/`revenue`/`estimated_profit`/`conversion_rate` per row | column grouping |

---

## 9. Architecture constraints to preserve (from Improvement.md §1, §31, §41)

- Reuse `AttributionEngine` / `RevenueRepository` / `RewardCostEstimator`; do not
  duplicate attribution or build a second analytics engine.
- Never bypass `RevenueRepository` caching; no uncached full-order scans; derive new
  metrics from already-aggregated attribution data.
- Preserve: direct-vs-assisted precedence, distinct-order counting, attribution
  window, revenue-producing statuses (`processing`, `completed`), idempotency
  (`order_goal_model`, `order_dedup`), bounded reads, per-user rate limits.
- Keep the exact profit formula; never invent costs/margins; label profit "estimated";
  never claim causality for AOV differences; never label completion as purchase.
- Do not remove legacy `/analytics` fields (external consumers may depend on them).

---

## 10. Phase readiness

| Improvement.md phase | Blocked by | Effort type |
| --- | --- | --- |
| Phase 2 — Backend/data layer | none (additive fields only) | small, additive |
| Phase 3 — Profit availability | none (cost sources already implemented; verify via tests) | verification + reason codes |
| Phase 4 — Revenue Overview | none | frontend |
| Phase 5 — Goal Performance | none | frontend |
| Phase 6 — Analytics redesign | Phase 2 (extended `/analytics` summary) | frontend + small backend |
| Phase 7 — Recommendations | none | frontend |
| Phase 8 — Upsell Analytics | none | frontend |
| Phase 9 — UX polish | Phases 4–8 | frontend + i18n |
| Phase 10 — Testing & regression | Phases 2–3 | tests |

---

*End of audit — no code was changed during Phase 1.*
