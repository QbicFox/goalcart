# FaraCart — Revenue & Analytics UX Consolidation and Simplification

## Objective

Redesign and simplify the FaraCart admin Revenue, Analytics, Goal Performance, and Attribution experience.

The current FaraCart implementation already contains a strong revenue attribution, purchase analytics, AOV, profit, goal-performance, recommendation, and upsell analytics stack.

The problem is NOT lack of functionality.

The problem is **information duplication and excessive analytical fragmentation across multiple admin pages**.

Currently, several pages expose overlapping metrics:

* Revenue Overview
* Goal Performance
* Attribution Dashboard
* Analytics

Metrics such as:

* Goal Views
* Progressed
* Goal Completions
* Purchased Orders
* Purchase Rate
* Attributed Sales
* Assisted Revenue
* Influenced Revenue
* Incremental Revenue
* AOV
* Estimated Profit
* Reward Cost
* Shipping Cost
* Funnel metrics

are repeated across different pages with slightly different presentations.

This makes the plugin technically powerful but cognitively expensive for store owners.

The goal of this task is to transform the existing analytics experience into a **simple business-oriented Sales Performance system**.

The store owner should be able to understand the commercial performance of FaraCart within a few seconds.

---

# 1. IMPORTANT — READ THE PROJECT FIRST

Before modifying any code, inspect the complete FaraCart project.

Read at minimum:

* AGENT.md
* PRODUCT_SPEC.md
* phase33.md
* revenue.md
* Improvement.md
* REVENUE_ANALYTICS_AUDIT.md
* REFERENCE_ARCHITECTURE.md
* frontend.md
* api.md
* database.md
* CHANGELOG.md
* reference-plugin-file-inventory.md

Then inspect the actual implementation.

Inspect:

### Backend

* RevenueRepository
* RevenueController
* AttributionEngine
* RewardCostEstimator
* DailyAggregator
* AnalyticsRepository
* AnalyticsController
* GoalRepository
* Goal performance services
* Profit calculation services
* Product cost readers
* WooCommerce order attribution hooks

### Frontend

Inspect:

* AdminLayout
* navigation configuration
* DateRangeContext
* RevenueOverview.tsx
* GoalPerformance.tsx
* AttributionDashboard.tsx
* Analytics.tsx
* Recommendations.tsx
* UpsellAnalytics.tsx
* all KPI/card components
* FunnelVisual
* RevenueToolbar
* EmptyState
* formatting utilities
* API clients
* route configuration
* translation/i18n implementation

Do not implement the redesign from this prompt alone.

The existing implementation is the source of truth.

---

# 2. CORE PRODUCT PRINCIPLE

FaraCart is a revenue optimization product, not an analytics laboratory.

The primary admin question is:

> "Is FaraCart helping my store sell more profitably?"

The UI must answer this question first.

Advanced analytics must remain available, but must not dominate the primary experience.

Use a three-level information hierarchy.

---

# 3. INFORMATION HIERARCHY

## Level 1 — What happened?

Business outcomes.

Examples:

* Sales attributed to FaraCart
* Purchased Orders
* Average Basket Increase
* Estimated Profit

These are primary.

---

## Level 2 — Why?

Performance explanations.

Examples:

* Which goal performed best?
* Where do shoppers drop off?
* Which goal generated the most purchases?
* Which goal generated the most sales?
* Which goal generated the most profit?
* Is completion strong but purchase conversion weak?

These are secondary.

---

## Level 3 — Advanced analysis

Technical/analytical details.

Examples:

* Direct attribution
* Assisted attribution
* Influenced revenue
* Incremental revenue
* Attribution window
* Data sufficiency
* Confidence
* Incremental cart value
* Margin
* Reward cost
* Shipping cost
* Detailed attribution methodology

These must be progressively disclosed.

Never make Level 3 information the first thing a store owner sees.

---

# 4. NEW ADMIN INFORMATION ARCHITECTURE

Simplify the primary navigation to:

```text
Dashboard

Goals

Sales Performance
  ├── Overview
  └── Goals

Optimization
  ├── Recommendations
  └── Upsells

Settings
```

Do not expose the following as primary navigation items:

```text
Analytics
Attribution
```

The existing routes may remain internally for backward compatibility if necessary.

Do NOT break existing URLs unless the current architecture explicitly permits it.

If necessary:

* keep `/analytics`
* keep `/revenue/attribution`

but redirect or internally map them to the new experience.

The primary navigation must not expose them.

---

# 5. REMOVE PAGE-LEVEL DUPLICATION

Do not simply redesign the existing pages independently.

First identify duplicated metrics and decide where each metric has a single canonical presentation.

Use this rule:

```text
One metric
→ One primary location
→ Secondary references only when contextually necessary
```

Examples:

### Purchased Orders

Primary location:

Sales Performance → Overview

Secondary:

Goal Performance table

Goal Detail drawer

Do NOT create a separate Purchased Orders KPI on multiple unrelated pages unless context requires it.

---

### Estimated Profit

Primary:

Sales Performance → Overview

Secondary:

Goal Performance

Goal Detail

Advanced Profit details

---

### Funnel

Primary:

Sales Performance → Overview

Secondary:

Goal Detail

Do NOT recreate the same funnel independently on multiple pages.

---

### Attribution

Primary:

Advanced section inside Sales Performance

Secondary:

Goal Detail → Advanced Attribution

Do NOT make Attribution Dashboard a primary page.

---

# 6. SALES PERFORMANCE — MAIN PAGE

Rename the user-facing concept to:

> Sales Performance

or:

> FaraCart Performance

Choose the option most consistent with the current product terminology.

Do NOT unnecessarily rename backend classes, APIs, repositories, database tables, or internal variables.

This is a UX terminology change, not a backend rewrite.

---

# 7. SALES PERFORMANCE — OVERVIEW

The Overview must immediately communicate commercial impact.

The first viewport should contain exactly four primary KPI cards.

## KPI 1 — Sales Attributed to FaraCart

Display:

```text
Sales Attributed to FaraCart

12,400,000 تومان

187 purchased orders
```

Use the existing attribution implementation.

Do NOT display these three metrics as competing primary KPIs:

* goal_driven_revenue
* goal_assisted_revenue
* goal_influenced_revenue

Instead expose them through:

> How is this calculated?

or:

> Advanced Attribution

Use the existing AttributionEngine.

Never create a new attribution formula.

---

# 8. KPI 2 — Purchased Orders

Display:

```text
Purchased Orders

187

after FaraCart interaction
```

The metric must represent real qualifying WooCommerce orders.

Do NOT interpret goal completion as purchase.

Use the existing AttributionEngine semantics and revenue-producing order statuses.

---

# 9. KPI 3 — Average Basket Increase

Use business-friendly terminology.

Preferred label:

> Average Basket Increase

Example:

```text
+8.7%

Observed impact
```

Supporting text:

```text
Goal-exposed customers spent more per order on average.
```

When expanded, show:

```text
Store average
Goal-exposed average
Absolute difference
Percentage difference
```

Always clearly label this as:

> Observed impact

Never claim causality unless the existing data model provides valid causal evidence.

Reuse the existing `aov_analysis()` implementation.

Do not create another AOV calculation.

---

# 10. KPI 4 — ESTIMATED PROFIT

Estimated Profit must be visible on the main Sales Performance page.

When available:

```text
Estimated Profit

6,200,000 تومان

Estimated based on available cost data
```

Do not present it as accounting profit.

Use wording such as:

> Estimated

or:

> Analytical estimate

---

# 11. PROFIT MODEL

Preserve the existing profit methodology.

Do NOT modify it merely for UI purposes.

The current model is conceptually:

```text
estimated_profit =
    incremental_revenue × margin%
    − reward_cost
    − shipping_cost
```

Use the existing implementation.

Do NOT:

* invent product costs
* assume a default margin
* use a generic 30% margin
* fabricate missing cost values
* silently change historical profit methodology

Reuse the existing product cost sources:

* WooCommerce `_cost`
* WooCommerce `_wc_cog_cost`
* variation → parent fallback where already supported
* `goalcart_product_cost` filter

---

# 12. PROFIT STATES

The UI must distinguish:

## Available

```text
Estimated Profit
6,200,000 تومان
```

---

## No cost data

```text
Estimated Profit

Not available yet

FaraCart needs product cost data
to estimate profit.

[Learn how]
```

Explain that FaraCart never invents product costs.

If there is no suitable product-cost configuration route, provide an explanation/help panel rather than creating a fake cost system.

---

## Incomplete cost data

```text
Estimated Profit

Limited data

Some relevant orders do not have
complete cost information.

[View details]
```

Do not change the strict profit calculation model unless the existing backend explicitly supports it.

---

## Zero profit

Display:

```text
0 تومان
```

Do not confuse zero with unavailable.

---

## Negative profit

Support negative values.

Example:

```text
Estimated Profit

-420,000 تومان
```

Explain:

> Estimated rewards and shipping costs exceeded the estimated incremental margin.

Never hide or clamp negative profit.

---

# 13. PROFIT DETAILS

Clicking the Estimated Profit KPI must open a drawer/dialog/popover with details.

Example structure:

```text
Estimated Profit

Incremental sales
Estimated product margin
Reward cost
Shipping cost

----------------------

Estimated profit
```

Use only real backend values.

Never fabricate intermediate values.

Include:

> This is an analytical estimate based on available WooCommerce cost and order data. It is not accounting profit.

---

# 14. COST COVERAGE

If the existing backend can safely expose cost coverage, display it.

Example:

```text
Cost data coverage: 92%
```

Explain:

> Percentage of relevant attributed orders with usable cost information.

Preserve the existing strict profit semantics.

Do not silently turn partial cost coverage into a new profit methodology.

If cost coverage is unavailable from the backend, do not fake it.

---

# 15. SALES PERFORMANCE TREND

Keep the existing daily trend data.

Simplify the visualization.

Default chart:

> Sales Performance

Default visible metrics:

* Attributed Sales
* Purchased Orders

Optional toggles:

* Goal Completions
* Incremental Revenue

Do NOT display 5–6 lines by default.

The chart should answer:

> "Is FaraCart performance improving or declining?"

The date range must be shared with every other component on the page.

---

# 16. DATE RANGE

Preserve the existing date-range infrastructure.

Default:

> Last 30 days

Support existing ranges:

* 7 days
* 30 days
* 90 days
* Custom

All cards, charts, funnels, insights, and tables must use the same date range.

Never allow individual sections to silently use different ranges.

---

# 17. BUSINESS INSIGHTS

After the primary chart, add 2–3 deterministic business insight cards.

Examples:

```text
Good performance

FaraCart was associated with 187 purchases
during this period.
```

```text
Best performing goal

Free Shipping generated the highest
attributed sales.
```

```text
Optimization opportunity

Many shoppers reached 80–90% of the target
but did not complete it.
```

Only generate an insight when the underlying data supports it.

Do not use an LLM.

Do not invent conclusions.

Do not claim causality.

Use deterministic rules.

---

# 18. MAIN FUNNEL

Use one canonical funnel on Sales Performance Overview:

```text
Viewed
   ↓
Progressed
   ↓
Completed
   ↓
Purchased
```

Each stage must show:

* absolute count
* conversion from previous stage where meaningful

Example:

```text
8,240 Viewed
      ↓ 52%
4,285 Progressed
      ↓ 43%
1,840 Completed
      ↓ 21%
387 Purchased
```

The funnel should visually communicate where the largest drop-off happens.

Do not duplicate the exact same funnel elsewhere unless it is scoped to a specific Goal.

---

# 19. GOAL PERFORMANCE

The Goal Performance page must become a **commercial comparison page**, not another generic analytics dashboard.

Primary question:

> "Which of my goals actually performs best?"

Use a table similar to:

| Goal | Viewed | Completed | Purchased | Purchase Rate | Sales | Profit |
| ---- | -----: | --------: | --------: | ------------: | ----: | -----: |

Use real backend data.

Do not invent values.

---

# 20. GOAL TABLE METRIC PRIORITY

Primary metrics:

1. Purchased
2. Purchase Rate
3. Sales
4. Profit

Secondary:

5. Viewed
6. Completed

`Progressed` may be available through:

* column visibility
* row expansion
* Goal Detail

Do not make every funnel stage equally visually dominant.

---

# 21. GOAL TABLE SORTING

Support sorting by:

* Sales
* Purchased Orders
* Purchase Rate
* Estimated Profit
* Completion Rate

Default:

> Sales

This allows the store owner to immediately identify the highest-value Goal.

---

# 22. COMPLETION ≠ PURCHASE

This distinction must be visible throughout the UI.

Use:

### Goal Completed

> Customer reached the target.

### Purchased

> A qualifying WooCommerce order was associated with the goal.

Never use generic "conversion" terminology where it could confuse these concepts.

---

# 23. PURCHASE RATE

Use the existing documented semantics:

```text
purchase_rate =
    purchased / completed
```

Use the existing backend implementation.

If the denominator is zero:

```text
—
```

NOT:

```text
0%
```

because no meaningful rate can be calculated.

Tooltip:

> Percentage of completed goals followed by an attributed purchase.

Do not redefine the existing backend semantics.

---

# 24. GOAL DETAIL DRAWER

Clicking a Goal row should open a Goal Detail drawer/dialog.

Do NOT immediately navigate to another complex analytics page.

Structure:

## Performance Summary

```text
Viewed
Progressed
Completed
Purchased
Purchase Rate
```

---

## Revenue

```text
Sales Attributed to FaraCart
Incremental Sales
```

---

## Profit

```text
Estimated Profit
Reward Cost
Shipping Cost
```

---

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

Show counts and percentages.

---

## Advanced Attribution

Collapsed by default.

When expanded:

```text
Direct Revenue
Assisted Revenue
Influenced Revenue
Incremental Revenue
Attributed Orders
Attribution Window
Data Sufficiency
```

Explain every technical metric in plain language.

---

# 25. REMOVE ANALYTICS AS A PRIMARY PAGE

The existing Analytics page should no longer be a primary navigation destination.

Its useful functionality must be redistributed.

Map the existing Analytics functionality as follows:

```text
Current Analytics KPI data
→ Sales Performance Overview

Current Analytics funnel
→ Sales Performance Overview

Current Analytics goal comparison
→ Sales Performance → Goals

Current Analytics detailed goal analysis
→ Goal Detail Drawer

Advanced analytics
→ Goal Detail → Advanced Attribution
```

Do not simply delete existing functionality.

Move it to the appropriate context.

---

# 26. REMOVE ATTRIBUTION AS A PRIMARY PAGE

The existing Attribution Dashboard should no longer be a primary navigation item.

Keep the route/API if backward compatibility requires it.

Move the functionality into:

```text
Sales Performance
   ↓
Advanced Attribution
```

and:

```text
Goal Performance
   ↓
Goal Detail
      ↓
Advanced Attribution
```

The technical attribution model should only appear when the user asks for it.

---

# 27. ADVANCED ATTRIBUTION

Keep all existing attribution functionality.

Do not remove:

* direct attribution
* assisted attribution
* influenced revenue
* incremental revenue
* attribution window
* distinct order handling
* direct/assisted precedence
* revenue-producing order status rules
* idempotency
* caching
* bounded reads

The redesign is presentation/navigation only unless a minimal additive API field is genuinely required.

---

# 28. AVOID DOUBLE COUNTING

Preserve all existing attribution safeguards.

An order that contains both direct and assisted attribution must not be counted twice in aggregate totals.

Do not modify attribution logic merely to simplify UI.

Use existing RevenueRepository and AttributionEngine outputs.

---

# 29. CANONICAL METRIC OWNERSHIP

Establish a clear frontend metric ownership map.

Example:

```text
Sales Attributed
→ Sales Performance Overview

Purchased Orders
→ Sales Performance Overview

Average Basket Increase
→ Sales Performance Overview

Estimated Profit
→ Sales Performance Overview

Funnel
→ Sales Performance Overview

Goal comparison
→ Sales Performance → Goals

Goal details
→ Goal Detail Drawer

Advanced Attribution
→ Goal Detail / Sales Performance advanced section

Recommendations
→ Optimization → Recommendations

Upsell analytics
→ Optimization → Upsells
```

Do not duplicate complete KPI blocks across pages.

---

# 30. USER-FACING TERMINOLOGY

Prefer simple business terminology.

Use:

| Internal                | User-facing                   |
| ----------------------- | ----------------------------- |
| goal_driven_revenue     | Sales Attributed to FaraCart |
| goal_assisted_revenue   | Assisted Sales                |
| goal_influenced_revenue | Influenced Sales              |
| incremental_revenue     | Additional Sales Value        |
| converted               | Purchased Orders              |
| conversion_rate         | Purchase Rate                 |
| completion_rate         | Completion Rate               |
| aov_analysis            | Average Basket Increase       |
| estimated_profit        | Estimated Profit              |

Do not expose technical database/API names.

---

# 31. ANALYTICS TERMINOLOGY RULE

Never confuse:

```text
Completion Rate
```

with:

```text
Purchase Rate
```

Completion:

```text
completed / views
```

Purchase:

```text
purchased / completed
```

Use tooltips to explain the difference.

---

# 32. EMPTY STATES

Every analytics component must distinguish:

### No data yet

```text
No sales data yet

FaraCart has not collected enough
data for this period.
```

### No purchases

```text
No purchases yet

Customers interacted with your goals,
but no attributed purchases were recorded.
```

### No completed goals

```text
No completed goals yet
```

Do not show all three as a generic "No data".

---

# 33. DATA STATES

Support:

* loading
* error
* unavailable
* partial
* zero
* negative
* no denominator

Do not convert `null` into `0`.

Do not convert unavailable profit into zero.

Do not hide negative profit.

Use existing frontend state conventions from Improvement.md.

---

# 34. RESPONSIVE DESIGN

The redesigned pages must work on:

* desktop
* tablet
* narrow admin widths

Requirements:

* KPI cards collapse gracefully
* tables become horizontally scrollable
* Goal Detail drawer works on mobile
* charts remain readable
* advanced sections collapse
* no horizontal page overflow

Preserve RTL support.

---

# 35. ACCESSIBILITY

Preserve and improve:

* keyboard navigation
* focus management
* accessible table rows
* aria-expanded for drawers/accordions
* accessible chart summaries
* readable contrast
* meaningful button labels

Do not rely on color alone to communicate:

* positive
* negative
* unavailable
* warning

---

# 36. I18N

All user-facing strings must use the existing i18n system.

Do not hard-code English or Persian.

Update translation catalogs where required.

Test:

* English
* Persian / fa_IR

Pay special attention to:

* currency formatting
* Persian digits
* RTL layout
* pluralization
* percentage formatting

---

# 37. PERFORMANCE

Do NOT introduce new expensive database queries for every UI component.

Reuse:

* RevenueRepository
* cached responses
* daily aggregates
* existing bounded queries
* existing API endpoints

If multiple cards use the same endpoint, fetch once and derive the values client-side where appropriate.

Avoid:

```text
KPI 1 → API request
KPI 2 → API request
KPI 3 → API request
KPI 4 → API request
```

Prefer:

```text
Sales Performance Overview
        ↓
one shared overview payload
        ↓
KPI cards
funnel
chart
insights
```

Do not create duplicate backend calculations.

---

# 38. API STRATEGY

Before changing APIs, inspect existing payloads.

Prefer reusing existing endpoints.

If the existing `/revenue/overview` payload already contains the required values, do not create a new endpoint.

If a small additive field is required:

* add it backward-compatibly
* update TypeScript types
* update tests
* update documentation

Do not create a second analytics engine.

---

# 39. ROUTING STRATEGY

Maintain backward compatibility where practical.

Recommended routes:

```text
/revenue
/revenue/goals
/revenue/recommendations
/revenue/upsells
```

Legacy routes may remain:

```text
/revenue/attribution
/analytics
```

but should not appear in primary navigation.

If safe, redirect legacy routes to the new relevant location.

Do not break existing bookmarks unnecessarily.

---

# 40. RECOMMENDATIONS AND UPSELLS

Do NOT merge Recommendations and Upsell Analytics into Sales Performance.

They answer a different question.

Sales Performance:

> What happened?

Recommendations:

> What should I change?

Upsells:

> Which products should I recommend?

Keep them under:

```text
Optimization
```

This preserves a clean conceptual distinction.

---

# 41. DO NOT MERGE GOAL MANAGEMENT WITH GOAL ANALYTICS

The existing:

```text
Goals
```

management area should remain focused on:

* create
* edit
* duplicate
* activate/deactivate
* configure reward
* configure target
* campaign association

Do not turn it into another analytics dashboard.

The Sales Performance → Goals section is specifically for performance analysis.

---

# 42. FINAL USER JOURNEY

The redesigned experience should support this journey:

```text
Store owner opens FaraCart
        ↓
Dashboard
        ↓
"How are my sales doing?"
        ↓
Sales Performance
        ↓
Sees:
- attributed sales
- purchased orders
- basket increase
- estimated profit
        ↓
Looks at trend
        ↓
Checks funnel
        ↓
Sees drop-off
        ↓
Opens Goals
        ↓
Identifies best/worst Goal
        ↓
Opens Goal Detail
        ↓
Understands:
- completion
- purchase
- revenue
- profit
        ↓
If needed:
Advanced Attribution
        ↓
Then goes to Optimization
        ↓
Recommendations / Upsells
```

This is the intended product flow.

---

# 43. WHAT NOT TO DO

Do NOT:

* add more KPI cards just because data exists
* duplicate the same metric on every page
* create another analytics engine
* create another attribution calculation
* create another profit calculation
* add LLM/AI dependency
* remove useful advanced analytics
* remove backward-compatible APIs unnecessarily
* break existing routes unnecessarily
* change attribution semantics
* change profit methodology
* invent product costs
* assume missing values are zero
* show technical terminology as primary UX
* show three competing revenue numbers as primary KPIs
* show the same funnel on three pages
* create a separate page for every analytical concept

---

# 44. IMPLEMENTATION PHASES

Implement this redesign in phases.

## Phase 1 — Audit

Before coding:

* inspect all relevant frontend routes
* inspect navigation
* inspect APIs
* inspect backend payloads
* identify duplicated metrics
* identify components that can be reused
* produce a short metric ownership map

Do not modify code yet.

---

## Phase 2 — Information Architecture

Implement:

* new Sales Performance navigation
* Overview / Goals structure
* hide Attribution from primary navigation
* hide Analytics from primary navigation
* preserve legacy routes

---

## Phase 3 — Sales Performance Overview

Implement:

* four primary KPI cards
* shared date range
* simplified trend chart
* canonical funnel
* deterministic insights
* profit states
* profit details drawer

---

## Phase 4 — Goal Performance

Implement:

* commercial Goal table
* sorting
* purchase metrics
* sales
* profit
* Goal Detail drawer
* Goal funnel
* advanced attribution accordion

---

## Phase 5 — Analytics Consolidation

Move useful Analytics functionality into:

* Sales Performance Overview
* Sales Performance Goals
* Goal Detail

Remove duplicated UI.

Do not remove underlying functionality.

---

## Phase 6 — Attribution Consolidation

Move advanced Attribution UI into:

* Sales Performance → Advanced Attribution
* Goal Detail → Advanced Attribution

Keep APIs and backend logic intact.

---

## Phase 7 — UX Polish

Implement:

* responsive layouts
* loading states
* empty states
* unavailable states
* partial states
* zero states
* negative values
* accessibility
* RTL
* i18n
* tooltips
* explanatory copy

---

## Phase 8 — Testing

Run:

* TypeScript typecheck
* ESLint
* frontend tests
* backend tests
* API tests
* i18n tests
* existing regression suite
* build

Verify:

* no duplicate analytics requests
* no broken legacy routes
* no attribution changes
* no profit calculation changes
* no database regression
* no WooCommerce compatibility regression

---

# 45. ACCEPTANCE CRITERIA

The redesign is complete only when:

### Navigation

* [ ] Analytics is no longer a primary navigation item
* [ ] Attribution is no longer a primary navigation item
* [ ] Sales Performance is the primary analytics destination
* [ ] Recommendations and Upsells remain under Optimization

### Sales Performance

* [ ] Exactly four primary KPI cards exist
* [ ] KPI cards focus on business outcomes
* [ ] Sales Attributed is primary
* [ ] Purchased Orders is primary
* [ ] Average Basket Increase is primary
* [ ] Estimated Profit is primary
* [ ] Trend chart is simplified
* [ ] Funnel exists
* [ ] Deterministic business insights exist

### Goals

* [ ] Goal Performance focuses on commercial outcomes
* [ ] Purchased Orders is visible
* [ ] Purchase Rate is visible
* [ ] Sales is visible
* [ ] Profit is visible
* [ ] Sorting is available
* [ ] Goal Detail drawer exists

### Advanced Analytics

* [ ] Advanced Attribution is progressively disclosed
* [ ] Direct/assisted/influenced metrics remain available
* [ ] Attribution methodology remains unchanged
* [ ] Advanced data does not dominate the main UI

### Profit

* [ ] Existing profit methodology remains unchanged
* [ ] No fake product costs
* [ ] No default margin
* [ ] Unavailable profit has an explanatory state
* [ ] Incomplete cost data is explained
* [ ] Zero profit is distinct from unavailable
* [ ] Negative profit is supported
* [ ] Profit details are available

### Data Integrity

* [ ] Goal completion is not treated as purchase
* [ ] Purchase Rate uses existing documented semantics
* [ ] No attribution double counting
* [ ] Existing revenue-producing order rules remain intact
* [ ] Existing caching remains intact
* [ ] Existing bounded queries remain intact

### UX

* [ ] Same metric is not unnecessarily repeated across pages
* [ ] First viewport answers "Is FaraCart helping my store?"
* [ ] Advanced information is progressively disclosed
* [ ] Empty states are meaningful
* [ ] Loading/error/unavailable states are handled
* [ ] Responsive design works
* [ ] RTL works
* [ ] i18n works
* [ ] Accessibility requirements are met

---

# 46. FINAL QUALITY TEST

After implementation, ask:

> If a store owner opens FaraCart for the first time, can they understand its sales impact within 5–10 seconds?

The first viewport should allow them to answer:

```text
How much did FaraCart sell?

How many orders did it influence?

Did basket value increase?

Was the impact profitable?
```

Then ask:

> Can they identify the best Goal within 30 seconds?

Then:

> Can they identify where customers drop off?

Then:

> Can they inspect advanced attribution if they need it?

If the answer to all four is yes, the redesign is successful.

The goal is not to show less data.

The goal is to show **the right data at the right time and in the right context**.

Do not sacrifice analytical power.

Reduce cognitive load.

Make FaraCart feel like a **sales performance tool**, not a collection of analytics dashboards.

