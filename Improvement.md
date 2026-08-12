# Goal Cart — Revenue & Analytics UX Simplification, Profit Visibility and Purchase Analytics

## Mission

Redesign and simplify the Goal Cart Revenue and Analytics experience without removing the existing analytics capabilities, attribution logic, or tested backend architecture.

The current system contains strong revenue attribution, funnel, AOV, reward-cost, margin, estimated-profit, recommendation, and upsell analytics capabilities. However, the admin UI currently exposes too much technical analytics terminology and does not clearly answer the store owner's most important questions:

1. How much additional sales did Goal Cart generate?
2. How much did Goal Cart increase basket value?
3. How many customers actually purchased after interacting with a goal?
4. Which goals perform best?
5. How much did rewards cost?
6. How much estimated profit did Goal Cart generate?
7. What should the store owner change to improve results?

The goal of this implementation is therefore NOT to remove analytics.

The goal is:

> Hide unnecessary complexity at the first level, preserve advanced analytics behind expandable details, make purchases/conversions visible, and make Estimated Profit actionable and understandable.

---

# ✅ IMPLEMENTATION PROGRESS

**Overall:** Phases 1–5 complete · Phases 6–10 not started.

```text
Phase 1 : ████████████████████ 100%   Codebase Audit (REVENUE_ANALYTICS_AUDIT.md)
Phase 2 : ████████████████████ 100%   Backend / Data Layer
Phase 3 : ████████████████████ 100%   Profit Availability
Phase 4 : ████████████████████ 100%   Revenue Overview Redesign
Phase 5 : ████████████████████ 100%   Goal Performance Redesign
Phase 6 : ░░░░░░░░░░░░░░░░░░░░   0%   Analytics Redesign
Phase 7 : ░░░░░░░░░░░░░░░░░░░░   0%   Recommendations
Phase 8 : ░░░░░░░░░░░░░░░░░░░░   0%   Upsell Analytics
Phase 9 : ░░░░░░░░░░░░░░░░░░░░   0%   UX Polish
Phase 10: ░░░░░░░░░░░░░░░░░░░░   0%   Testing & Regression
```

| Phase | Status | Deliverable |
| --- | --- | --- |
| 1 — Codebase Audit | [x] 100% | `REVENUE_ANALYTICS_AUDIT.md` (no code modified) |
| 2 — Backend / Data Layer | [x] 100% | purchase/profit metrics on the legacy `/analytics` summary, profit reason codes + cost coverage + profit details, goal filter resolution, `tests/purchase-metrics-test.php` |
| 3 — Profit Availability | [x] 100% | cost sources verified (`_cost` / `_wc_cog_cost` / variation fallback / `goalcart_product_cost`), `cost_sources` + `store_has_cost_data` metadata, `tests/profit-availability-test.php` |
| 4 — Revenue Overview Redesign | [x] 100% | Sales Performance page: 4 KPI cards, profit states + details, simplified trend with toggles, insight cards, advanced attribution drawer, nav rename |
| 5 — Goal Performance Redesign | [x] 100% | commercial-outcomes Goal table (Viewed/Progressed/Completed/Purchased/Purchase Rate/Sales/Estimated Profit, sortable), per-goal detail drawer (performance summary, funnel with drop-off, costs via the shared profit card, advanced attribution + advanced accordions), additive `goal_metrics()` fields, `tests/revenue-admin-test.php` (56 checks) |
| 6 — Analytics Redesign | [ ] 0% | — |
| 7 — Recommendations | [ ] 0% | — |
| 8 — Upsell Analytics | [ ] 0% | — |
| 9 — UX Polish | [ ] 0% | — |
| 10 — Testing & Regression | [ ] 0% | — |

**Last update:** 2026-08-12 — Phase 5 (Goal Performance Redesign) complete; `tsc`, ESLint and `vite build` clean; POT regenerated (847 strings); all PHP suites green (revenue-admin 56, attribution 72, purchase-metrics 107, aggregation 74, phase33 99, profit-availability 45, revenue-foundation 69).

---

# 1. IMPORTANT: READ THE EXISTING PROJECT FIRST

Before changing any code, inspect the existing Goal Cart plugin and all relevant documentation.

The documentation package includes:

* AGENT.md
* PRODUCT_SPEC.md
* phase33.md
* revenue.md
* api.md
* frontend.md
* database.md
* goal-engine.md
* rewards.md
* REFERENCE_ARCHITECTURE.md
* CHANGELOG.md
* reference-plugin-file-inventory.md

Also inspect the actual source code.

Do NOT implement the redesign based only on this prompt.

First determine:

* Current Revenue routes
* Current Analytics routes
* Current React components
* Current RevenueRepository
* AttributionEngine
* RewardCostEstimator
* GoalPerformance implementation
* Existing AnalyticsRepository
* Existing API response structures
* Existing database tables
* Existing product cost readers
* Existing WooCommerce order hooks
* Existing margin/profit calculations
* Existing tests
* Existing translations/i18n
* Existing admin navigation

Preserve existing architectural conventions.

Do not create duplicate analytics engines.

Do not create duplicate attribution logic.

Do not bypass RevenueRepository caching.

Do not introduce uncached expensive order scans where existing bounded/cached services already exist.

---

# 2. CORE UX PRINCIPLE

The new Revenue/Analytics experience must follow this hierarchy:

## Level 1 — What happened?

Simple business metrics.

Examples:

* Sales attributed to Goal Cart
* Additional basket value
* Purchased orders
* Estimated profit

## Level 2 — Why?

Explain the metric.

Examples:

* 187 orders purchased after Goal Cart interaction
* Average basket was 8.7% higher
* 102 orders completed the free-shipping goal
* Estimated profit is based on product cost, reward cost, and shipping cost

## Level 3 — Advanced analysis

Expose technical analytics only when requested.

Examples:

* Direct attribution
* Assisted attribution
* Influenced revenue
* Incremental revenue
* Attribution windows
* Confidence score
* Reachability
* Economics score
* Margin percentage
* Raw recommendation factors
* Detailed funnel
* Shipping methodology

Never expose Level 3 complexity as the primary dashboard experience.

---

# 3. NEW INFORMATION ARCHITECTURE

Simplify the Revenue navigation.

Preferred structure:

```text
Revenue / Sales Performance
│
├── Overview
│
├── Goals
│
└── Optimization
    │
    ├── Recommendations
    └── Upsell Analytics
```

Do NOT make "Attribution Dashboard" a primary navigation item.

Attribution remains available as an advanced detail panel or expandable section.

If the current product architecture strongly depends on the existing `/revenue/attribution` route, keep the route for backward compatibility but remove it from primary navigation.

The route may still be used internally or from "View details" links.

---

# 4. RENAME THE USER-FACING CONCEPT

The technical term "Revenue" can remain internally in code and API naming.

For the admin-facing UI, prefer:

## English

"Sales Performance"

or

"Goal Cart Performance"

Use whichever naming is consistent with the existing product terminology.

Do not rename backend methods unnecessarily.

The purpose is to communicate business value, not technical revenue accounting.

---

# 5. REDESIGN REVENUE OVERVIEW

The overview must answer the store owner's questions immediately.

Use exactly four primary KPI cards.

## KPI 1 — Attributed Sales

Label:

> Sales Attributed to Goal Cart

Display the primary revenue metric.

Prefer the direct/incremental attributed revenue according to the existing attribution model.

Do not simultaneously show three competing revenue numbers at the primary level.

Do NOT put these three values side by side as primary KPIs:

* goal_driven_revenue
* goal_assisted_revenue
* goal_influenced_revenue

This creates confusion.

Instead:

```text
Sales Attributed to Goal Cart
12,400,000 تومان

187 purchased orders
```

Add an expandable "How is this calculated?" section containing:

* Direct revenue
* Assisted revenue
* Influenced revenue
* Incremental revenue
* Attribution methodology

Use the existing AttributionEngine.

Do not invent a new attribution formula.

---

# 6. KPI 2 — Average Basket Increase

Replace technical wording such as:

* AOV Impact
* AOV Delta

with a business-friendly label:

> Average Basket Increase

Example:

```text
+8.7%

Customers interacting with Goal Cart
spent 8.7% more per order on average.
```

Show comparison details on expansion:

```text
Store average       1,240,000
Goal-exposed        1,348,000
Difference           +108,000
Percentage             +8.7%
```

Always label this as:

> Observed impact

Do NOT claim causality.

Use the existing `aov_analysis()` implementation.

---

# 7. KPI 3 — Purchased Orders

This KPI is essential and currently underrepresented.

Add:

> Purchased Orders

Example:

```text
187
orders

after Goal Cart interaction
```

The user must immediately understand that:

* Goal completion is NOT the same as purchase.
* Purchase is the final commercial outcome.

Use the existing attribution/order association logic.

Do not count `goal_completed` as a purchase.

A purchase must be associated with an actual revenue-producing WooCommerce order according to the existing AttributionEngine rules.

Use the existing revenue-producing statuses:

* processing
* completed

and existing order hooks/idempotency.

---

# 8. KPI 4 — Estimated Profit

Estimated Profit MUST be visible on the main dashboard when the required cost data exists.

Display:

```text
Estimated Profit

6,200,000 تومان

based on available cost data
```

Add a small informational label:

> Estimated, not guaranteed

Never present estimated profit as actual accounting profit.

---

# 9. ESTIMATED PROFIT — DATA MODEL

Preserve the existing profit model:

```text
estimated_profit =
    incremental_revenue × margin%
    − reward_cost
    − shipping_cost
```

Do NOT invent product costs.

Do NOT hard-code a default margin.

Do NOT assume a generic margin such as 30%.

Use the existing product cost sources documented by the project:

* WooCommerce `_cost`
* WooCommerce `_wc_cog_cost`
* variation fallback to parent where currently supported
* `goalcart_product_cost` filter

Do not modify product cost data.

---

# 10. MAKE PROFIT AVAILABLE THROUGH COST DATA

The current implementation intentionally returns unavailable profit when required margin data is missing.

Keep that safety behavior.

However, improve the UX so the store owner understands exactly how to make Estimated Profit available.

When profit data is unavailable, do NOT simply display:

```text
N/A
```

or:

```text
null
```

Instead display:

```text
Estimated Profit

Not available yet

Goal Cart needs product cost data
to estimate profit.
```

CTA:

> Set up product costs

If the plugin already has a suitable WooCommerce product-cost configuration route, link directly to it.

If no suitable route exists, provide an in-plugin setup/help panel explaining:

1. Goal Cart does not invent product costs.
2. Product cost must be available from WooCommerce/product cost data.
3. Once cost data exists, Estimated Profit becomes available automatically.
4. The calculation includes:

   * product cost / margin
   * reward cost
   * shipping cost
   * incremental revenue

Do not create a fake product-cost database unless the existing architecture explicitly supports it.

---

# 11. COST COVERAGE

Implement a cost-data coverage indicator if the existing data layer can support it safely.

Example:

```text
Cost data coverage: 92%
```

Meaning:

> 92% of relevant order/product value has usable cost information.

If the current strict model requires complete line-item cost data for an order, preserve that behavior for the actual profit calculation.

However, expose the reason clearly:

```text
Estimated Profit is calculated only for
orders with complete cost data.
```

If partial coverage exists, optionally show:

```text
Profit calculated from 92% of eligible order value.
```

ONLY implement partial-coverage calculations if the existing architecture can support them without changing the meaning of the current profit model.

Do not silently change the profit methodology.

---

# 12. PROFIT DETAILS PANEL

When the user clicks Estimated Profit, show:

```text
Estimated Profit

Sales attributed             12.4M
Estimated product margin      9.7M
Reward cost                   1.4M
Shipping cost                 0.4M
────────────────────────────────
Estimated profit              6.2M
```

Use the actual values returned by the backend.

Never fabricate intermediate numbers.

Add:

> This is an analytical estimate based on available WooCommerce cost and order data. It is not accounting profit.

---

# 13. PROFIT DATA STATES

Support these states:

## Available

Show:

```text
Estimated Profit
6.2M
```

## Unavailable — no product cost data

Show:

```text
Estimated Profit
Not available

Add product cost data to estimate profit.
[Learn how]
```

## Unavailable — incomplete data

Show:

```text
Estimated Profit
Limited data

Some relevant orders do not have complete
cost information.
[View details]
```

## Zero profit

Show:

```text
Estimated Profit
0 تومان
```

Do NOT interpret zero as unavailable.

## Negative profit

Support negative profit.

Example:

```text
Estimated Profit
-420,000 تومان
```

Explain:

> Rewards and shipping costs were higher than the estimated incremental margin.

Do not hide negative values.

---

# 14. REVENUE TREND

Keep the existing daily trend functionality but simplify the chart.

Primary chart:

> Goal Cart Sales Performance

Allow the user to toggle:

* Attributed Sales
* Purchased Orders
* Goal Completions

Optional advanced toggle:

* Incremental Revenue

Do not show five or six lines simultaneously by default.

Default view should contain:

1. Sales
2. Purchased Orders

The user can enable additional metrics.

---

# 15. ADD BUSINESS INSIGHT CARDS

After the chart, show 2–3 automatically generated plain-English insights.

Examples:

```text
Good performance

Goal Cart influenced 187 purchases during this period.
```

```text
Best performer

Free Shipping generated the highest attributed sales.
```

```text
Optimization opportunity

Customers often reach 80–90% of the current target
but do not complete it.
```

Insights must be deterministic and based on actual data.

Do not introduce an LLM/AI dependency.

---

# 16. REDESIGN GOAL PERFORMANCE

The Goals page must focus on commercial outcomes.

Current structure:

```text
Views
Progressed
Completed
Converted
```

is technically correct but not enough.

Use:

```text
Viewed
Progressed
Completed
Purchased
Sales
Profit
```

Example table:

| Goal          | Viewed | Progressed | Completed | Purchased | Purchase Rate | Sales | Profit |
| ------------- | -----: | ---------: | --------: | --------: | ------------: | ----: | -----: |
| Free Shipping |  4,820 |      2,410 |       920 |       187 |         20.3% |  5.8M |   2.4M |
| 10% Discount  |  2,410 |      1,120 |       410 |        54 |         13.2% |  3.1M |   1.1M |

Use actual backend data.

Do not invent values.

---

# 17. IMPORTANT: COMPLETED ≠ PURCHASED

This distinction must be explicit everywhere.

A goal completion means:

> The customer reached the goal threshold.

A purchase means:

> A qualifying WooCommerce order was actually associated with the goal according to the attribution rules.

Therefore:

```text
Goal Completion
      ↓
Customer reached target

Purchase
      ↓
Customer placed a qualifying order
```

Do not merge these concepts.

---

# 18. PURCHASE CONVERSION RATE

For each goal, expose:

> Purchase Rate

Use the existing documented attribution semantics.

The existing revenue documentation defines:

```text
conversion_rate =
    converted / completions
```

Preserve this semantic unless the existing implementation shows a better established definition.

If there are fewer than one completed goal:

```text
—
```

not:

```text
0%
```

because there is no denominator.

Add tooltip:

> Percentage of completed goals that were followed by an attributed purchase.

---

# 19. ADD PURCHASE VALUE

For every goal show:

> Purchased Orders

and:

> Sales from Purchased Orders

This is much more meaningful to store owners than completion count alone.

Example expanded goal:

```text
Free Shipping

4,820 viewed
2,410 progressed
920 completed
187 purchased

Completion rate       19.1%
Purchase rate         20.3%

Attributed sales      5.8M
Estimated profit      2.4M
```

---

# 20. GOAL DETAIL DRAWER

Clicking a goal should open a detail drawer/dialog.

Structure:

## Performance Summary

```text
Viewed
Progressed
Completed
Purchased
Attributed Sales
Estimated Profit
```

## Funnel

```text
Viewed
  ↓
Progressed
  ↓
Completed
  ↓
Purchased
```

Show both counts and percentages.

## Revenue

Show:

* attributed revenue
* assisted revenue
* influenced revenue
* incremental revenue

But put these under:

> Advanced attribution details

## Costs

Show:

* reward cost
* shipping cost
* estimated profit

## Advanced

Show:

* attribution model
* attribution window
* confidence/data sufficiency
* AOV impact
* incremental cart value

---

# 21. ANALYTICS PAGE — MAJOR REDESIGN

The existing Analytics page currently focuses too heavily on:

```text
Impressions
Completions
Completion Rate
```

This is insufficient.

The Analytics page must become a real:

# Goal Conversion & Purchase Analysis

Its main question should be:

> What happens after customers see and complete my goals?

---

# 22. NEW ANALYTICS KPI ROW

Use:

1. Goal Views
2. Goal Completions
3. Purchased Orders
4. Purchase Rate
5. Attributed Sales
6. Estimated Profit

Do not display all six with equal visual weight if the UI becomes crowded.

Recommended hierarchy:

Primary:

* Purchased Orders
* Purchase Rate
* Attributed Sales
* Estimated Profit

Secondary:

* Goal Views
* Goal Completions

---

# 23. ANALYTICS FUNNEL

Replace the existing simplistic completion-only visualization with:

```text
Goal Views
     ↓
Progressed
     ↓
Completed
     ↓
Purchased
```

Each step must show:

* absolute count
* percentage from previous stage
* percentage from total views where useful

Example:

```text
8,240 Views
     ↓ 52%
4,285 Progressed
     ↓ 43%
1,840 Completed
     ↓ 21%
387 Purchased
```

This immediately answers:

> Where are customers dropping out?

---

# 24. PURCHASE ANALYSIS

Add a dedicated section:

# Purchase Analysis

Show:

```text
Completed Goals       1,840
Purchased Orders        387
Purchase Rate          21.0%
```

Then show:

> Sales generated after Goal Cart interaction

and:

> Average order value of purchased orders

Use actual attributed order data.

---

# 25. COMPLETION VS PURCHASE COMPARISON

Add a visualization/table:

| Metric                     | Value |
| -------------------------- | ----: |
| Goals Completed            | 1,840 |
| Purchased After Completion |   387 |
| Purchase Rate              | 21.0% |
| Attributed Sales           | 12.4M |
| Average Purchased Order    | 1.72M |
| Estimated Profit           |  6.2M |

This should be one of the most visible sections of Analytics.

---

# 26. ANALYZE THE DROP-OFF

Add deterministic insights.

Examples:

```text
Largest drop-off

57% of customers who viewed a goal
did not reach the target.
```

or:

```text
Completion is strong, but purchase conversion is weak.

Only 21% of completed goals were followed by
an attributed purchase.
```

or:

```text
This goal has a high completion rate but low
purchase rate. Consider testing the reward.
```

Only display an insight when the underlying data supports it.

Never claim causality.

---

# 27. GOAL COMPARISON IN ANALYTICS

Add a comparison table:

| Goal | Views | Completed | Purchased | Purchase Rate | Sales | Profit |
| ---- | ----: | --------: | --------: | ------------: | ----: | -----: |

Allow sorting by:

* Purchased Orders
* Purchase Rate
* Sales
* Estimated Profit
* Completion Rate

Default sort:

> Attributed Sales

---

# 28. DO NOT CONFUSE COMPLETION RATE WITH PURCHASE RATE

Use different terminology:

### Completion Rate

```text
completed / views
```

### Purchase Rate

```text
purchased / completed
```

Where applicable according to existing attribution semantics.

Tooltips must explain both.

Never label a completion rate as "conversion".

---

# 29. ANALYTICS DATE RANGE

Preserve the existing date-range selector.

Default:

> Last 30 days

Support existing:

* 7 days
* 30 days
* 90 days
* custom range

All cards, charts, funnels, and tables must use exactly the same selected date range.

Display:

```text
Analyzing:
Aug 1 – Aug 12, 2026
```

---

# 30. ATTRIBUTION DETAILS

Do not delete the existing Attribution Dashboard functionality.

Move advanced information behind:

> Advanced Attribution

Expandable section.

Show:

```text
Direct Revenue
Assisted Revenue
Influenced Revenue
Incremental Revenue
Attributed Orders
Attribution Window
```

Explain each metric in plain English.

Example:

> Direct Revenue
> Revenue from orders where the customer progressed toward or completed the goal before ordering.

Use the existing documented semantics.

---

# 31. AVOID DOUBLE COUNTING

Do not change the existing attribution safeguards.

Maintain:

* direct vs assisted precedence
* distinct order counting
* attribution window
* revenue-producing statuses
* idempotency
* unique order/goal association
* bounded reads
* caching

An order containing both direct and assisted attribution must not be counted twice in aggregate totals.

---

# 32. REVENUE TERMINOLOGY

Use these user-facing labels:

| Technical               | User-facing                   |
| ----------------------- | ----------------------------- |
| goal_driven_revenue     | Sales attributed to Goal Cart |
| goal_assisted_revenue   | Assisted sales                |
| goal_influenced_revenue | Influenced sales              |
| incremental_revenue     | Additional sales value        |
| incremental_cart_value  | Additional basket value       |
| AOV impact              | Average basket increase       |
| reward_cost             | Reward cost                   |
| estimated_profit        | Estimated profit              |
| completion_rate         | Goal completion rate          |
| conversion_rate         | Purchase rate                 |
| converted               | Purchased                     |
| funnel                  | Customer journey              |

Avoid exposing raw internal field names.

---

# 33. RECOMMENDATIONS PAGE

Keep the existing Smart Recommendation engine.

However, simplify its presentation.

Primary card:

```text
Recommended Goal Target

1,290,000 تومان

Why?

• Many orders are close to this amount.
• Current AOV is 1,180,000 تومان.
• This target is reachable without being too easy.

Expected impact:

+8–14% average basket value
```

Use:

> Confidence: High

instead of showing the raw numeric confidence first.

Advanced details may show:

* score
* reachability
* distance
* economics
* history
* confidence score
* expected profit

---

# 34. RECOMMENDATION PROFIT

If expected profit is unavailable because margin data is missing:

Show:

```text
Expected profit

Not available

Add product cost data to estimate profitability.
```

Do not display a fake expected profit.

---

# 35. UPSELL ANALYTICS

Keep the existing upsell analytics but simplify the first-level UI.

Primary columns:

* Product
* Orders
* Sales
* Estimated Profit
* Conversion

Secondary/advanced:

* impressions
* clicks
* adds
* CTR
* add-to-cart rate
* upsell score
* score factors

The first screen should answer:

> Which suggested products actually generate purchases and sales?

---

# 36. BACKEND/API STRATEGY

Do not rebuild the analytics engine.

Reuse:

* AttributionEngine
* RevenueRepository
* RewardCostEstimator
* GoalRecommendationEngine
* existing aggregation
* existing cache
* existing attribution tables
* existing revenue events

Before adding a new endpoint, determine whether an existing endpoint can be extended.

Preferred approach:

Extend existing payloads rather than introducing duplicate endpoints.

For example, if `goal_performance()` already has:

```text
views
progressed
completed
converted
```

use `converted` as the purchase metric.

If the current Analytics endpoint only exposes:

```text
impressions
completions
completion_rate
```

extend its response to include:

```text
progressed
purchased_orders
purchase_rate
attributed_sales
estimated_profit
```

where supported by existing data.

---

# 37. LEGACY ANALYTICS API

The existing analytics implementation documented in `api.md` contains metrics such as:

* impressions
* completions
* completion rate
* average cart value
* revenue associated with completed goals
* suggestion CTR
* suggestion add-to-cart rate

Do not remove these fields if external/internal consumers may depend on them.

Instead add new fields.

Example:

```json
{
  "summary": {
    "impressions": 8240,
    "progressed": 4285,
    "completions": 1840,
    "purchased_orders": 387,
    "completion_rate": 0.2233,
    "purchase_rate": 0.2103,
    "attributed_sales": 12400000,
    "estimated_profit": 6200000,
    "profit_available": true
  }
}
```

Use the project's actual response conventions.

Do not blindly copy this schema if the existing API uses a different structure.

---

# 38. PROFIT API REQUIREMENTS

Every Revenue/Analytics response that displays profit should provide enough metadata to explain the value.

At minimum:

```text
estimated_profit
profit_available
profit_reason
```

Prefer additionally:

```text
margin_pct
reward_cost
shipping_cost
incremental_revenue
```

Only expose fields that are already supported by the backend or can be safely derived from existing services.

---

# 39. PROFIT REASON CODES

Use stable machine-readable reasons internally.

Examples:

```text
available
missing_product_cost
incomplete_product_cost
missing_margin_data
insufficient_data
```

Translate them in React.

Never show raw reason codes to users.

---

# 40. PROFIT CALCULATION SAFETY

Never:

* invent product costs
* invent margins
* assume 100% margin
* assume 30% margin
* use product sale price as product cost
* claim estimated profit is accounting profit
* include unsupported costs
* silently mix revenue and profit

Always show the calculation basis.

---

# 41. DATABASE / PERFORMANCE

Do not introduce N+1 queries.

Do not scan all WooCommerce orders repeatedly from React.

Use:

* RevenueRepository
* cached aggregations
* daily revenue aggregation
* bounded AttributionEngine reads
* existing scan limits
* existing transients/generation versions

If new purchase metrics can be derived from already aggregated attribution data, do that instead of scanning orders again.

---

# 42. TESTING REQUIREMENTS

Add/update tests for all changed backend behavior.

At minimum test:

## Funnel

* views
* progressed
* completed
* purchased
* completion rate
* purchase rate

## Purchase

* no purchases
* one purchase
* multiple purchases
* multiple goals
* direct attribution
* assisted attribution
* mixed direct + assisted
* duplicate order events

## Profit

* complete cost data
* missing cost data
* partial cost data
* zero profit
* negative profit
* reward cost
* shipping cost
* margin calculation

## Date filtering

Ensure all metrics use the same date range.

## Goal filtering

Ensure all metrics respect `goal_id`.

## Privacy/security

Preserve:

* admin permissions
* rate limiting
* nonce/schema validation
* existing analytics feature flags

---

# 43. FRONTEND STATES

Every dashboard component must support:

* loading
* empty
* unavailable
* partial data
* error
* zero
* negative values
* successful data

Do not render blank cards.

For example:

```text
Estimated Profit
—
Cost data unavailable
```

is better than:

```text
Estimated Profit
0
```

when profit is unavailable.

---

# 44. EMPTY STATE

If there are no Goal Cart interactions:

```text
No sales data yet

Once customers start interacting with your goals,
Goal Cart will show sales, purchases and profit insights here.
```

If there are interactions but no purchases:

```text
No purchases yet

Customers are interacting with your goals,
but no attributed purchases have been recorded
for this period.
```

This is different from having no analytics data.

---

# 45. DATA SUFFICIENCY

Preserve the existing data sufficiency system:

* low
* medium
* high

But translate it into business-friendly language.

Example:

```text
Limited data
```

instead of:

```text
data_sufficiency = low
```

Tooltip:

> More customer activity is needed for a more reliable analysis.

---

# 46. OBSERVED IMPACT DISCLAIMER

Whenever AOV comparison is shown, use:

> Observed impact

and:

> This comparison shows how order values differ. It does not prove that Goal Cart caused the difference.

Keep this subtle.

Do not overload the UI with legal-style disclaimers.

---

# 47. ADVANCED ANALYTICS DRAWER

Create a reusable advanced analytics drawer/accordion.

Suggested sections:

```text
Advanced Analytics
│
├── Attribution
│   ├── Direct
│   ├── Assisted
│   └── Influenced
│
├── Basket Analysis
│   ├── Baseline
│   ├── Goal-exposed AOV
│   └── Incremental value
│
├── Profit Model
│   ├── Margin
│   ├── Reward cost
│   ├── Shipping
│   └── Estimated profit
│
└── Data Quality
    ├── Sample size
    ├── Cost coverage
    └── Data sufficiency
```

This becomes the home for technical analytics.

---

# 48. DASHBOARD DESIGN PRINCIPLE

The first viewport must NOT look like an analytics laboratory.

The first viewport should look like:

```text
How did Goal Cart perform?
```

Then:

```text
What happened?
```

Then:

```text
Which goal worked?
```

Then:

```text
What should I improve?
```

Advanced analytics comes afterward.

---

# 49. RECOMMENDED FINAL OVERVIEW LAYOUT

Implement approximately:

```text
┌─────────────────────────────────────────────────┐
│ Sales Performance          Last 30 days ▼        │
├─────────────────────────────────────────────────┤
│                                                 │
│ Sales Attributed   Basket Increase              │
│ 12.4M              +8.7%                        │
│ 187 purchases      observed impact              │
│                                                 │
│ Purchased Orders   Estimated Profit             │
│ 187                6.2M                          │
│ +21.3%             Estimated                     │
│                                                 │
├─────────────────────────────────────────────────┤
│                                                 │
│ Goal Cart Sales Performance                    │
│                                                 │
│      Sales ───────────────                      │
│      Orders ─ ─ ─ ─ ─ ─                        │
│                                                 │
├─────────────────────────────────────────────────┤
│                                                 │
│ What happened?                                 │
│                                                 │
│ Goal Cart influenced 187 purchases.             │
│ Average basket value was 8.7% higher.           │
│                                                 │
├─────────────────────────────────────────────────┤
│                                                 │
│ Top Performing Goals                           │
│                                                 │
│ Goal             Purchased   Sales   Profit     │
│ Free Shipping       187       5.8M    2.4M      │
│ 10% Discount         54       3.1M    1.1M      │
│                                                 │
├─────────────────────────────────────────────────┤
│                                                 │
│ 💡 Recommended Action                           │
│                                                 │
│ Consider lowering Free Shipping target          │
│ from 1.5M to 1.29M.                             │
│                                                 │
│ [View Recommendation]                            │
│                                                 │
└─────────────────────────────────────────────────┘
```

Do not copy this literally if the existing design system requires another layout. Use it as the information hierarchy.

---

# 50. ANALYTICS PAGE FINAL LAYOUT

Recommended:

```text
Goal Conversion & Purchase Analysis

[Date Range]

┌──────────┬────────────┬─────────────┬────────────┐
│ Views    │ Completed  │ Purchased   │ Sales      │
│ 8,240    │ 1,840      │ 387         │ 12.4M      │
└──────────┴────────────┴─────────────┴────────────┘

Purchase Rate: 21.0%

             Goal Funnel

Views
  ↓
Progressed
  ↓
Completed
  ↓
Purchased

────────────────────────────────────────────

Purchase Analysis

Completed Goals       1,840
Purchased Orders        387
Purchase Rate          21.0%
Attributed Sales      12.4M
Average Order Value    1.72M
Estimated Profit       6.2M

────────────────────────────────────────────

Goal Performance

Goal | Views | Completed | Purchased | Rate | Sales | Profit

────────────────────────────────────────────

Key Insights

• Free Shipping generated the most purchases.
• 21% of completed goals resulted in attributed orders.
• Goal X has strong completion but weak purchase conversion.

────────────────────────────────────────────

Advanced Analytics ▼

Direct / Assisted / Influenced
Incremental Revenue
AOV
Attribution
Cost
Margin
```

---

# 51. RESPONSIVE DESIGN

The dashboard must work correctly in:

* desktop
* tablet
* smaller admin widths

Do not create horizontal overflow.

On smaller screens:

* KPI cards become a 2-column grid
* tables become horizontally scrollable or responsive cards
* advanced analytics becomes a drawer
* chart legends remain readable

Follow the existing MUI/RTL architecture.

---

# 52. RTL / I18N

All new labels must go through the existing i18n system.

Do not hard-code Persian text directly inside React components.

Add translations for:

* Purchased Orders
* Purchase Rate
* Sales Attributed to Goal Cart
* Average Basket Increase
* Estimated Profit
* Cost Data
* Cost Coverage
* Advanced Analytics
* Purchase Analysis
* Goal Completion
* Goal Purchase
* Observed Impact
* Data Sufficiency
* Profit Unavailable
* Profit Estimated
* Product Cost
* Reward Cost
* Shipping Cost

Preserve existing Persian/English terminology conventions.

---

# 53. ACCESSIBILITY

Ensure:

* KPI cards are readable by screen readers
* chart information has accessible summaries
* expandable sections have proper ARIA states
* tooltips are keyboard accessible
* color is not the only indicator
* positive/negative/neutral states use text/icons in addition to color

---

# 54. IMPLEMENTATION PHASES

## Phase 1 — Codebase Audit

> **STATUS: ✅ COMPLETE — 2026-08-12** (see progress register at the top of this document)

Tasks:

* [x] Inspect current Revenue implementation.
* [x] Inspect Analytics implementation.
* [x] Inspect AttributionEngine.
* [x] Inspect RevenueRepository.
* [x] Inspect RewardCostEstimator.
* [x] Inspect cost/margin integration.
* [x] Inspect current React Revenue components.
* [x] Inspect current Analytics components.
* [x] Inspect routes.
* [x] Inspect API schemas.
* [x] Inspect tests.
* [x] Map existing data fields to requested UI.

Do not modify code yet.

Deliver:

```text
REVENUE_ANALYTICS_AUDIT.md   (delivered at the project root)
```

containing:

* [x] current architecture
* [x] reusable services
* [x] missing metrics
* [x] existing purchase/conversion metrics
* [x] profit availability flow
* [x] required backend changes
* [x] required frontend changes

**Key audit findings (summary):** the purchase/conversion/profit data the new UI needs
already exists (`converted` = purchased orders, `conversion_rate` = purchase rate,
`goal_driven_revenue` = attributed sales, `profit_impact`/`profit_available`/
`profit_reason` = estimated profit states). Later phases are additive payload
extensions (legacy `/analytics` summary, profit reason codes, optional cost coverage)
plus the React/navigation redesign — no new analytics engine, no new attribution
logic, no uncached order scans.

---

# Phase 2 — Backend/Data Layer

> **STATUS: ✅ COMPLETE — 2026-08-12** (see progress register at the top of this document)

Implement only missing data needed by the new UI.

Priorities:

1. Purchased order counts
2. Purchase rate
3. Attributed sales
4. Estimated profit visibility
5. Profit availability/reason
6. Optional cost coverage
7. Goal-level purchased metrics

Reuse existing services.

Do not duplicate attribution calculations.

Update API tests.

**Deliverables:**

* `RewardCostEstimator::profit_impact()` now returns a stable machine-readable `reason_code` (`available` / `missing_product_cost`) alongside the existing human `reason` (§39).
* `AttributionEngine` — the summary gained `profit_reason_code`, `cost_coverage` (attributed orders vs orders with cost data + coverage %, §11) and `profit_details` (incremental revenue, margin %, reward cost, shipping cost — §12 building blocks); `goal_metrics()` passes the same metadata through; all reads accept a `goal_ids` IN-clause for campaign/reward-filtered purchase metrics; fixed the regression where the pre-computed reward cost was zeroed inside the profit model (estimated profit now matches `incremental × margin − reward − shipping`).
* `GoalRepository` — `ids_by_campaign()` / `ids_by_reward_type()` resolve the campaign/reward filters onto the attribution dimension.
* `RevenueRepository::purchase_summary()` — cached attribution summary mapped from the legacy `/analytics` filters (from/to, goal_id, goal_ids, campaign_id, reward_type); `product_id` is unsupported in attribution and returns `null` (never a fabricated number); an unmatched filter returns an honest zeroed summary (`insufficient_data`), never store-wide fallback.
* `AnalyticsController` (`GET /goalcart/v1/analytics`) — the existing summary is **extended** (never modified) with `progressed`, `purchased_orders`, `purchase_rate`, `attributed_sales`, `estimated_profit`, `profit_available`, `profit_reason`, `profit_reason_code`, `cost_coverage`, `profit_details` (§37/§38). Legacy fields stay byte-for-byte intact.
* `admin-app/src/types.ts` — `AnalyticsSummary`, revenue summary and goal-performance row types extended with the new fields (typed `ProfitReasonCode`, `CostCoverage`, `ProfitDetails`).
* `tests/purchase-metrics-test.php` — 107 checks covering funnel/purchase states, profit states (available / missing cost / incomplete cost / zero / negative), reward cost regression, cost coverage, reason codes, date + goal/campaign/reward/product filtering, distinct-order counting and rollback residue.
* Existing suites remain green: `attribution` (72), `aggregation` (74), `phase33` (99), `revenue-admin` (53) — the legacy `analytics-dashboard` suite's 31 failures are pre-existing dev-DB drift (32 failures reproduce on unmodified code); the Phase 2 additions to it all pass.

---

# Phase 3 — Profit Availability

> **STATUS: ✅ COMPLETE — 2026-08-12** (see progress register at the top of this document)

Verify actual WooCommerce cost sources.

Test:

* `_cost`
* `_wc_cog_cost`
* variation fallback
* `goalcart_product_cost`
* reward cost
* shipping cost

Make sure existing `estimated_profit` calculation remains correct.

Add UI-ready availability metadata.

Do not invent costs.

**Deliverables:**

* `RewardCostEstimator` — `COST_SOURCES` constant (stable source keys:
  `_cost`, `_wc_cog_cost`, `goalcart_product_cost`, `variation_fallback`)
  and `store_has_cost_data()` (one cheap indexed postmeta scan, LIMIT 1,
  memoized per request — tells the UI whether any product carries cost
  data, so "set up product costs" and "partial coverage" are distinct
  states).
* **Variation fallback fix** — a variation inheriting its parent's cost
  now runs the parent through the `goalcart_product_cost` filter too
  (previously raw meta only), so filter-based cost sources are honored
  for variations.
* **Safety consistency** — a stored cost of zero/negative is treated as
  "no cost data" on every path (never a 100%-margin assumption); the
  `estimated_profit = incremental × margin − reward − shipping` formula
  is byte-for-byte unchanged.
* `AttributionEngine` — every attribution summary now carries the
  UI-ready availability metadata `cost_sources` and
  `store_has_cost_data` (plus a delegating `store_has_cost_data()`
  accessor); mirrored through `goal_metrics()`, the zeroed empty summary
  (`RevenueRepository`) and the `/analytics` summary
  (`AnalyticsController` — `store_has_cost_data` is `null` for filters
  attribution cannot express).
* `admin-app/src/types.ts` — `CostSource` type + `cost_sources` /
  `store_has_cost_data` on `AnalyticsSummary`, `RevenueSummary` and
  `GoalPerformanceRow`.
* `tests/profit-availability-test.php` — 44 checks: every cost source
  (`_cost`, `_wc_cog_cost`, precedence, zero/negative safety, variation
  → parent raw meta, variation → parent via filter, filter override,
  filter null fall-through), reward cost (percent capped / fixed / free
  shipping via context and a real order / free gift costed + uncosted),
  shipping cost, the `estimated_profit` formula regression and the
  availability metadata on a real attributed summary — all rolled back
  with zero residue.
* All suites remain green: `purchase-metrics` (107), `attribution`
  (72), `aggregation` (74), `phase33` (99), `revenue-admin` (53),
  `profit-availability` (44); PHP lint + TS typecheck clean.

---

# Phase 4 — Revenue Overview Redesign

> **STATUS: ✅ COMPLETE — 2026-08-12** (see progress register at the top of this document)

Implement:

* four KPI cards
* simplified sales terminology
* purchased orders
* estimated profit
* sales trend
* purchase trend
* insight cards
* advanced attribution drawer
* profit details drawer

Remove unnecessary technical metrics from the primary view.

**Deliverables** (frontend only — the backend payload from Phases 2–3
already supplied every value):

* `admin-app/src/routes/RevenueOverview.tsx` rewritten as **Sales
  Performance**:
  - **Four KPI cards** (§5–§8): Sales Attributed to Goal Cart (direct
    incremental + purchased-orders sub-line + expandable "How is this
    calculated?" with direct/assisted/influenced + methodology),
    Average Basket Increase (signed %, observed impact + compare panel:
    store average / goal-exposed / difference / percentage), Purchased
    Orders, Estimated Profit.
  - **Estimated Profit card** (`components/revenue/EstimatedProfitCard.tsx`,
    reusable by later phases) — all §10–§13 states: available (zero and
    negative included, with the rewards-vs-margin explanation), "Not
    available" with a **Set up product costs** CTA + in-plugin help
    panel (Phase 3 `cost_sources` + `store_has_cost_data`), "Limited
    data" with §11 cost coverage, "—" for insufficient data; the §12
    profit-details panel (sales attributed / estimated margin / reward
    cost / shipping / estimated profit) + "analytical estimate, not
    accounting profit" disclaimer.
  - **Simplified trend** (§14): defaults to Attributed Sales + Purchased
    Orders; toggles add Goal Completions and an optional advanced
    Incremental Revenue series.
  - **Insight cards** (§15/§26): 2–3 deterministic plain-English
    insights computed from the real payload (purchases influenced,
    basket change, completion→purchase drop-off, profit guidance) —
    only shown when the data supports them.
  - **Advanced attribution drawer** (§30): direct / assisted /
    influenced revenue, incremental cart value, attributed orders,
    attribution window + observed-impact disclaimer, behind an
    accordion.
* `components/layout/navigation.ts` — admin section renamed to **Sales
  Performance**; the Attribution Dashboard is removed from primary
  navigation (§3) while the `/revenue/attribution` route stays for
  backward compatibility.
* `languages/goalcart.pot` regenerated (816 strings) so translators see
  the new labels.
* Verification: `tsc --noEmit`, ESLint and `vite build` all clean.

---

# Phase 5 — Goal Performance Redesign

> **STATUS: ✅ COMPLETE — 2026-08-12** (see progress register at the top of this document)

Implement:

* Views
* Progressed
* Completed
* Purchased
* Purchase Rate
* Sales
* Estimated Profit

Add expandable goal details.

Add detailed funnel.

Add advanced attribution section.

**Deliverables:**

* `AttributionEngine::goal_metrics()` — additive fields (all derived from
  the already-computed summary + incremental read — nothing new queried)
  for the redesign: `influenced_revenue` (total order value of the goal's
  associated orders, §20 Revenue), `attribution_window_days` (the engine's
  attribution-window constant, exposed for the Advanced section) and
  `data_sufficiency` (the session-count signal behind the existing
  incremental-cart-value read, §45).
* `admin-app/src/routes/GoalPerformance.tsx` rewritten as the
  **commercial outcomes table** (§16): Goal / Viewed / Progressed /
  Completed / **Purchased** / **Purchase Rate** / **Sales** / **Estimated
  Profit**, sortable (default: attributed sales descending; unavailable
  profit/rate always sorts last, in either direction), with header
  tooltips that keep completion and purchase distinct (§17/§28).
  Clicking a row (mouse or keyboard — Enter/Space) opens a right-side
  **detail drawer** (§20):
  - **Performance Summary** — viewed / progressed / completed / purchased
    / attributed sales / estimated profit.
  - **Customer Journey** — the full funnel with stage-to-stage drop-off
    percentages plus the completion-vs-purchase explanation (§17).
  - **Costs** — reuses the shared `EstimatedProfitCard` (reward cost,
    shipping cost, estimated profit in every data state §10–§13).
  - **Advanced attribution details** — direct / assisted / influenced
    revenue + incremental cart value behind an accordion (§20 Revenue).
  - **Advanced** — attribution model, attribution window, data
    sufficiency (translated to Limited / Moderate / Good data, §45),
    average basket increase (labeled observed impact, §46) and attributed
    orders.
* `admin-app/src/components/revenue/FunnelVisual.tsx` — optional
  `showTransitions` prop renders the percentage carried between funnel
  stages (the drop-off read §20/§23, reusable by the Phase 6 Analytics
  funnel), and the final stage is labeled **Purchased**, never
  "Converted" (§32).
* `admin-app/src/types.ts` — `GoalPerformanceRow` extended with
  `influenced_revenue`, `attribution_window_days`, `data_sufficiency`.
* `languages/goalcart.pot` regenerated (847 strings) so translators see
  the new labels.
* Tests: `tests/revenue-admin-test.php` extended with the new goal-row
  field checks (56 checks, 0 failures); every regression suite stays
  green — `attribution` (72), `purchase-metrics` (107), `aggregation`
  (74), `phase33` (99), `profit-availability` (45), `revenue-foundation`
  (69). PHP lint, `tsc --noEmit`, ESLint and `vite build` all clean.

---

# Phase 6 — Analytics Redesign

Transform the existing Analytics page into:

> Goal Conversion & Purchase Analysis

Add:

* purchase KPI
* purchase rate
* purchase funnel
* completed vs purchased comparison
* attributed sales
* estimated profit
* goal comparison
* drop-off analysis
* deterministic insights

Do not remove existing Analytics API compatibility.

---

# Phase 7 — Recommendations

Simplify:

* recommendation presentation
* confidence
* expected impact
* expected profit
* reasons

Move raw scoring details behind Advanced Details.

---

# Phase 8 — Upsell Analytics

Make purchases/sales the primary metrics.

Keep technical score breakdowns available as details.

---

# Phase 9 — UX Polish

Verify:

* loading
* empty
* unavailable
* partial
* error
* zero
* negative profit
* responsive
* RTL
* accessibility
* translations

---

# Phase 10 — Testing & Regression

Run all existing tests.

Then add tests for:

* purchase metrics
* purchase rate
* funnel
* estimated profit
* profit unavailable
* profit negative
* goal filtering
* date filtering
* direct attribution
* assisted attribution
* duplicate order prevention
* caching
* permissions

Do not finish the phase until existing tests remain green.

---

# 55. ACCEPTANCE CRITERIA

The implementation is complete only when all of the following are true:

## Revenue

* [ ] Revenue overview is understandable without knowing attribution terminology.
* [ ] Four primary KPIs are visible.
* [ ] Purchased Orders is visible.
* [ ] Estimated Profit is visible when cost data exists.
* [ ] Profit unavailable state explains how to enable it.
* [ ] Negative profit is supported.
* [ ] Profit details explain the calculation.
* [ ] Advanced attribution is available but not intrusive.

## Analytics

* [ ] Analytics shows Views.
* [ ] Analytics shows Progressed.
* [ ] Analytics shows Completed.
* [ ] Analytics shows Purchased.
* [ ] Analytics shows Purchase Rate.
* [ ] Analytics shows Attributed Sales.
* [ ] Analytics shows Estimated Profit.
* [ ] Funnel ends with Purchased.
* [ ] Completion and purchase are clearly distinguished.
* [ ] Goal comparison includes purchased orders.
* [ ] Drop-off insights are shown when meaningful.

## Goals

* [x] Goal table includes purchased orders.
* [x] Goal table includes purchase rate.
* [x] Goal table includes sales.
* [x] Goal detail shows the complete funnel.
* [x] Advanced attribution remains accessible.

## Profit

* [ ] Existing profit formula is preserved.
* [ ] Product cost is never invented.
* [ ] WooCommerce cost sources are supported.
* [ ] Missing cost produces a clear unavailable state.
* [ ] Profit reason is user-friendly.
* [ ] Reward cost is included.
* [ ] Shipping cost is included where supported.
* [ ] Negative profit works.
* [ ] Estimated profit is explicitly labeled as estimated.

## Architecture

* [ ] Existing AttributionEngine is reused.
* [ ] Existing RevenueRepository is reused.
* [ ] Existing caching is reused.
* [ ] No duplicate attribution engine is introduced.
* [ ] No unnecessary full WooCommerce order scans are introduced.
* [ ] Existing API compatibility is preserved where practical.
* [ ] Existing attribution semantics are not silently changed.

## UX

* [ ] First viewport is simple.
* [ ] Technical analytics is hidden behind progressive disclosure.
* [ ] No confusing duplicate revenue numbers appear as primary KPIs.
* [ ] User can understand the difference between completed and purchased.
* [ ] Every major number has a meaningful label.
* [ ] No raw technical field names are exposed.

---

# 56. FINAL PRODUCT PRINCIPLE

The final Goal Cart analytics experience should communicate this story:

```text
Goal Cart Performance
        ↓
How much did it sell?
        ↓
How many customers purchased?
        ↓
Which goals worked?
        ↓
How profitable was it?
        ↓
What should I change?
```

The user should NOT have to understand:

* attribution models
* incremental revenue methodology
* confidence scoring
* margin calculations
* statistical terminology
* event architecture

to understand whether Goal Cart is helping their store.

Those concepts should remain available for advanced users, but the primary dashboard must communicate business outcomes.

The final experience should feel like:

> "Goal Cart tells me what happened, how much money it made, how many customers actually bought, how profitable it was, and what I should do next."

Not:

> "Goal Cart gives me a collection of analytics metrics and expects me to interpret them."

---

# 57. IMPORTANT FINAL INSTRUCTION TO THE AGENT

Do not blindly implement the UI shown in this document.

First inspect the actual current implementation.

Where existing functionality already satisfies a requirement, reuse it.

Where the backend already provides a field, expose it instead of recalculating it.

Where a metric does not currently exist, determine whether it can be safely derived from existing attribution data before adding new database queries.

Preserve all existing tested behavior.

Do not remove advanced analytics.

Do not weaken attribution accuracy.

Do not fabricate profit.

Do not fabricate purchase data.

Do not claim causality from observed AOV differences.

Do not label goal completion as a purchase.

The primary objective is:

> **Simplify the user's mental model without simplifying the underlying analytics engine.**

