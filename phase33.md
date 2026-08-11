# Phase 33 — Advanced V3 Revenue Optimization

## Comprehensive Implementation Prompt

You are working on the **Goal Cart WooCommerce plugin**.

Your task is to fully implement **Phase 33 — Advanced V3 Revenue Optimization**.

---

# 1. Phase Information

```text
Phase: 33
Name: Advanced V3 Revenue Optimization

Phase Weight: 3%
Phase Progress: 37.5%
Project Contribution: 1.125%

Phase:
███████▒░░░░░░░░░░░░ 37.5%
```

The purpose of this phase is to transform Goal Cart from a simple goal/progress-bar plugin into a **data-driven revenue optimization engine for WooCommerce**.

The system must not only display goals but also measure their financial impact, recommend optimal goal thresholds, and intelligently recommend upsell products.

---

# 2. Core Objectives

Implement three major systems:

```text
1. Revenue Attribution Engine
2. Smart Goal Recommendation Engine
3. Smart Upsell Engine
```

The final system should answer:

```text
How much additional revenue did Goal Cart generate?

Which goal thresholds should this store use?

Which products should be recommended to customers to help them reach a goal?

Which recommendations generate the most profitable conversions?
```

Do NOT introduce LLM/AI APIs in this phase.

The first version must be based on deterministic analytics, scoring, historical WooCommerce data, and statistical calculations.

The architecture must however be extensible so AI/ML-based recommendations can be added later.

---

# 3. IMPORTANT IMPLEMENTATION RULES

Before writing code:

1. Inspect the entire existing Goal Cart plugin.
2. Understand the current architecture.
3. Reuse existing coding conventions.
4. Reuse existing database abstractions where possible.
5. Reuse existing React Admin architecture.
6. Do not duplicate existing functionality.
7. Do not break existing Goal Cart functionality.
8. Follow existing security conventions.
9. Follow WordPress and WooCommerce coding standards.
10. Make all analytics calculations timezone-aware.
11. Respect WooCommerce HPOS compatibility.
12. Do not assume that product margins are available.
13. Every recommendation must have a transparent explanation.
14. All analytics must be designed with performance in mind.
15. Avoid expensive queries on every frontend request.
16. Prefer aggregation and scheduled calculations for heavy analytics.

---

# 4. PHASE ARCHITECTURE

Create the following logical architecture:

```text
Revenue Optimization
│
├── Attribution Engine
│   ├── Event Tracking
│   ├── Goal View Tracking
│   ├── Goal Progress Tracking
│   ├── Goal Completion Tracking
│   ├── Cart Value Tracking
│   ├── Order Attribution
│   ├── Revenue Attribution
│   ├── AOV Analysis
│   ├── Reward Cost Analysis
│   └── Profit Impact Estimation
│
├── Goal Recommendation Engine
│   ├── AOV Analyzer
│   ├── Median Order Analyzer
│   ├── Order Distribution Analyzer
│   ├── Shipping Cost Analyzer
│   ├── Margin Analyzer
│   ├── Threshold Calculator
│   ├── Confidence Calculator
│   └── Recommendation Generator
│
└── Smart Upsell Engine
    ├── Candidate Product Collector
    ├── Price Gap Scorer
    ├── Relevance Scorer
    ├── Inventory Scorer
    ├── Popularity Scorer
    ├── Margin Scorer
    ├── Conversion Scorer
    ├── Composite Ranking Engine
    └── Historical Learning System
```

---

# 5. Revenue Attribution Engine

## 5.1 Goal View Tracking

Track when a customer is exposed to a Goal Cart goal.

Track:

```text
goal_id
session_id
customer_id if available
user_id if available
cart_value
goal_target
goal_type
timestamp
page/context
device type if available
```

Do not store unnecessary personally identifiable information.

Use anonymous session identifiers when possible.

---

# 5.2 Goal Progress Tracking

Track when a customer moves toward a goal.

Example:

```text
Initial cart:
700,000

Goal:
1,000,000

Customer adds:
200,000

New cart:
900,000
```

Record the progress event.

Calculate:

```text
previous_cart_value
new_cart_value
incremental_cart_value
goal_progress_before
goal_progress_after
```

Avoid recording duplicate events for insignificant changes.

---

# 5.3 Goal Completion Tracking

Track when the customer reaches the goal.

Example:

```text
Goal:
1,000,000

Cart:
1,050,000

Goal completed:
YES
```

Record:

```text
goal_completed_at
cart_value_at_completion
amount_added_to_reach_goal
```

---

# 5.4 Goal Conversion Tracking

A goal completion should not automatically equal a conversion.

Track whether the customer subsequently completes an order.

Example funnel:

```text
Goal Viewed
     ↓
Goal Progressed
     ↓
Goal Reached
     ↓
Checkout
     ↓
Order Paid
```

Calculate conversion rates at every stage.

---

# 6. Revenue Attribution Models

Implement multiple attribution levels.

## 6.1 Direct Goal Attribution

Attribute incremental cart value to a goal when there is strong evidence that the goal influenced the cart increase.

Example:

```text
Cart before:
700K

Goal:
1M

Cart after:
1.05M

Incremental:
350K
```

The incremental amount may be attributed to the goal.

---

## 6.2 Goal Completion Attribution

When the customer reaches the goal and subsequently purchases, record:

```text
goal_completed = true
order_completed = true
```

Calculate goal-driven revenue.

---

## 6.3 Assisted Attribution

A goal may influence a purchase without being the direct cause.

Track:

```text
Goal Viewed
→ Product Viewed
→ Product Added
→ Goal Progress
→ Purchase
```

Record this as:

```text
Goal Assisted Revenue
```

---

# 7. Revenue Metrics

Implement the following metrics.

## Incremental Cart Value

```text
Incremental Cart Value =
Average Cart Value After Goal Exposure
-
Average Cart Value Without Goal Influence
```

Where statistically valid comparison data is available.

---

## Goal-Driven Revenue

Calculate:

```text
Goal Driven Revenue
```

based on the selected attribution model.

---

## Goal Assisted Revenue

Calculate revenue from orders where Goal Cart played an assisting role.

---

## Goal Completion Rate

```text
Goal Completion Rate =
Completed Goals / Goal Views
```

---

## Goal Conversion Rate

```text
Goal Conversion Rate =
Orders Associated With Goal / Goal Completions
```

---

# 8. AOV Analysis

Calculate Average Order Value.

```text
AOV =
Total Order Revenue / Number of Orders
```

Compare:

```text
Before Goal Cart
After Goal Cart
```

Where possible, also compare:

```text
Goal-exposed orders
vs
Non-goal-exposed orders
```

Display:

```text
AOV Before
AOV After
Absolute Change
Percentage Change
```

Example:

```text
AOV Before:
780,000

AOV After:
940,000

Change:
+160,000

Percentage:
+20.5%
```

Do not claim causality when the available data does not support it.

Use labels such as:

```text
Observed Impact
Estimated Impact
Attributed Impact
```

instead of presenting uncertain numbers as facts.

---

# 9. Reward Cost Analysis

Goals may provide rewards such as:

* percentage discount
* fixed discount
* free shipping
* free gift
* coupon
* other supported rewards

Calculate the estimated cost of rewards.

Example:

```text
Goal Revenue:
38,000,000

Reward Cost:
4,800,000

Net Revenue Impact:
33,200,000
```

Support reward cost calculations based on the existing Goal Cart reward implementation.

---

# 10. Estimated Profit Impact

Implement estimated profit calculations.

Basic model:

```text
Estimated Profit Impact =
Incremental Revenue
-
Estimated Product Cost
-
Reward Cost
-
Additional Shipping Cost
-
Other measurable incremental costs
```

If product cost/margin information is unavailable:

```text
Profit Impact:
Unavailable
```

Do NOT invent margin data.

Allow the system to work with:

```text
Available margin data
```

and gracefully degrade to:

```text
Revenue-only analytics
```

---

# 11. Product Margin Support

Detect product margin/cost data when available.

Support common WooCommerce cost fields where practical.

Also provide an internal configuration mechanism if the plugin already has product margin functionality.

Calculate:

```text
Product Revenue
Product Cost
Estimated Margin
Margin Percentage
```

At minimum support:

```text
product_id
cost
selling_price
margin
margin_percentage
```

Do not modify WooCommerce product pricing/cost data.

---

# 12. Shipping Cost Analysis

Analyze shipping cost where available.

Track:

```text
average shipping cost
shipping cost by order value
shipping cost by shipping method
shipping cost by region where practical
```

Use this data in Smart Goal Recommendations.

Example:

```text
Average Order Value:
920K

Average Shipping Cost:
85K

Recommended Free Shipping Threshold:
1.25M
```

---

# 13. Revenue Optimization Dashboard

Create a React Admin page:

```text
Revenue Optimization
```

Top KPI cards:

```text
Goal Influenced Revenue
Incremental Cart Value
AOV Impact
Goal Conversion Rate
Reward Cost
Estimated Profit Impact
```

Example:

```text
Goal Influenced Revenue
+42.8M تومان

AOV Impact
+18.4%

Goal Revenue
67.2M تومان

Estimated Profit
+21.6M تومان
```

---

# 14. Goal Performance Dashboard

For every goal display:

```text
Goal Name

Views
Progressed
Reached
Converted
Conversion Rate

Average Cart Value
Incremental Cart Value

Attributed Revenue
Assisted Revenue

Reward Cost
Estimated Profit Impact
```

Add visual funnel:

```text
Views
 ↓
Progressed
 ↓
Reached
 ↓
Checkout
 ↓
Purchased
```

---

# 15. Smart Goal Recommendation Engine

Build a recommendation engine that analyzes store data and recommends goal thresholds.

The engine should answer:

```text
What threshold should this store use?
```

---

# 16. Smart Goal Inputs

Use the following inputs where available:

```text
AOV
Median Order Value
Order Distribution
Shipping Cost
Product Margins
Current Goal Performance
Goal Completion Rate
Goal Conversion Rate
Historical Order Data
```

Optional inputs:

```text
Seasonality
Day-of-week patterns
Product category distribution
Existing discount behavior
```

Do not make optional inputs mandatory.

---

# 17. AOV Analyzer

Calculate:

```text
Average Order Value
```

for configurable historical windows:

```text
7 days
30 days
60 days
90 days
180 days
```

Prefer 30/90-day data for stable recommendations.

Allow configuration later.

---

# 18. Median Order Value Analyzer

Calculate:

```text
Median Order Value
```

because average order value can be distorted by unusually large orders.

Example:

```text
AOV:
920K

Median:
780K
```

Use both values when calculating recommendations.

---

# 19. Order Distribution Analyzer

Group orders into ranges.

Example:

```text
<500K        12%
500K–750K    28%
750K–1M      35%
1M–1.5M      18%
>1.5M         7%
```

Use distribution to identify potential thresholds.

The engine should avoid recommending a threshold that is too far beyond normal customer purchasing behavior.

---

# 20. Shipping-Aware Recommendations

If the goal is free shipping, consider:

```text
AOV
Median Order Value
Shipping Cost
Shipping Margin
Order Distribution
```

Example:

```text
AOV:
920K

Median:
780K

Shipping Cost:
85K

Recommended Threshold:
1.25M
```

The exact algorithm should be data-driven rather than hard-coded.

---

# 21. Margin-Aware Goal Recommendations

If margin data exists, incorporate it.

Example:

```text
Average Margin:
32%
```

Potential recommendations:

```text
Free Shipping:
1.29M

10% Discount:
1.79M

Free Gift:
1.49M
```

The engine must evaluate whether the reward remains economically viable.

---

# 22. Recommended Threshold Algorithm

Implement a deterministic scoring system.

Consider candidate thresholds around the current order-value distribution.

For example:

```text
0.90 × AOV
1.00 × AOV
1.10 × AOV
1.20 × AOV
1.30 × AOV
1.40 × AOV
1.50 × AOV
```

Do not blindly use these exact multipliers.

Use them as candidate generation inputs.

Score each candidate using:

```text
Expected Goal Reachability
Expected AOV Increase
Expected Conversion
Reward Cost
Shipping Cost
Estimated Profit
Historical Goal Performance
Order Distribution
Margin
```

Select the candidate with the best expected economic outcome.

---

# 23. Recommendation Confidence

Every recommendation must include a confidence score.

Example:

```text
Recommended Threshold:
1,290,000 تومان

Confidence:
87%

Expected AOV Impact:
+12% to +18%

Expected Goal Completion:
22% to 29%
```

Confidence should depend on:

```text
Data volume
Data consistency
Historical goal performance
Availability of margin/shipping data
Distribution stability
```

Do not present arbitrary confidence values.

---

# 24. Recommendation Explanation

Every recommendation must be explainable.

Example:

```text
Why this threshold?

• It is approximately 35% above the current median order value.
• 31% of existing orders are within reach of this threshold.
• Average shipping cost is 85K.
• Historical orders indicate customers frequently increase cart value
  when approaching this range.
• Estimated reward cost remains within the configured margin target.
```

The recommendation engine must expose the underlying factors to the React UI.

---

# 25. Smart Upsell Engine

Build a product-ranking engine that recommends products capable of helping customers reach their active Goal.

Example:

```text
Current Cart:
1,550K

Goal:
2,000K

Gap:
450K
```

The engine should identify suitable products.

---

# 26. Upsell Candidate Selection

Collect candidate products from:

```text
Related Products
Cross-sells
Frequently Bought Together
Same Category
Same Product Tags
Popular Products
Historical Cart Additions
Historical Goal Conversions
```

Exclude:

```text
Out of stock products
Private products
Draft products
Invalid products
Products excluded by store rules
Products already in cart
```

Respect existing WooCommerce visibility and catalog rules.

---

# 27. Price Gap Score

Calculate how well a product price fits the remaining gap.

Example:

```text
Gap:
450K

Product A:
490K

Product B:
1.8M
```

Product A should receive a much higher price-gap score.

The scoring should tolerate small overshoots.

Do not require exact price matching.

---

# 28. Relevance Score

Determine product relevance using:

```text
Product category
Product tags
Related products
Cross-sells
Purchase history
Cart contents
Historical co-purchase data
```

Example:

```text
Customer buys shoes

Shoe Cleaner:
High relevance

Kitchen Blender:
Low relevance
```

---

# 29. Inventory Score

Use inventory information.

Example:

```text
Stock > 20:
High score

Stock 5–20:
Medium score

Stock 1–4:
Low score

Out of stock:
Exclude
```

Optionally prioritize products with healthy inventory.

---

# 30. Popularity Score

Calculate product popularity using:

```text
Units sold
Orders containing product
Recent sales velocity
Product views where available
Add-to-cart rate where available
```

Recent performance should have higher weight than very old sales.

---

# 31. Margin Score

When margin information exists:

```text
Higher margin
→ higher score
```

Example:

```text
Product A:
Price 500K
Margin 15%

Product B:
Price 480K
Margin 45%
```

Product B may receive a higher profitability score.

Do not prioritize margin so aggressively that relevance becomes meaningless.

---

# 32. Historical Conversion Score

Track recommendation performance.

For each recommended product:

```text
Impressions
Clicks
Add-to-cart
Orders
Revenue
Conversion Rate
```

Example:

```text
Product X

Impressions:
8,200

Clicks:
1,340

Adds:
420

Orders:
280

Conversion:
3.41%
```

Use this historical performance as a ranking signal.

---

# 33. Smart Upsell Composite Score

Implement a transparent weighted ranking model.

Initial default weights:

```text
Price Gap        25%
Relevance        25%
Popularity       15%
Inventory        10%
Margin           15%
Conversion       10%
```

Formula:

```text
Upsell Score =
Price Gap Score × 0.25
+
Relevance Score × 0.25
+
Popularity Score × 0.15
+
Inventory Score × 0.10
+
Margin Score × 0.15
+
Conversion Score × 0.10
```

Normalize every component to:

```text
0–100
```

Make the scoring architecture configurable so weights can be changed later.

---

# 34. Ranking Output

Return ranked products:

```text
Recommended Upsells

1. Product A
   Score: 84

2. Product B
   Score: 82

3. Product C
   Score: 65
```

Expose score breakdown:

```text
Price Gap:
95

Relevance:
80

Popularity:
72

Inventory:
90

Margin:
70

Conversion:
88

Final:
84
```

This is important for debugging and transparency.

---

# 35. Historical Learning

The system should learn from actual recommendation outcomes.

Track:

```text
Recommendation shown
Recommendation clicked
Recommendation added
Recommendation purchased
Goal completed after recommendation
Revenue generated
Profit generated
```

Use this historical data to improve future ranking.

Do NOT implement a black-box machine learning model in this phase.

Use deterministic historical scoring.

---

# 36. Context-Aware Upselling

Recommendations should be calculated dynamically based on:

```text
Current cart
Current cart value
Active goal
Remaining gap
Products already in cart
Store inventory
Historical behavior
```

Example:

```text
Cart:
1,550K

Goal:
2,000K

Gap:
450K
```

The recommendation engine should prefer products around:

```text
350K–600K
```

rather than completely unrelated high-priced products.

---

# 37. Frontend Integration

Integrate Smart Upsell with existing Goal Cart frontend components.

Possible UI:

```text
You're only 450K away from free shipping!

Add one of these:

[Product A]
490K
+ Add

[Product B]
420K
+ Add

[Product C]
530K
+ Add
```

Do not redesign existing Goal Cart UI unnecessarily.

Reuse existing components and styles where possible.

---

# 38. Admin React UI

Create the following sections:

```text
Revenue Optimization
│
├── Overview
├── Goal Performance
├── Revenue Attribution
├── Smart Recommendations
├── Goal Recommendations
└── Upsell Analytics
```

---

# 39. Overview Page

Display:

```text
Total Goal Revenue
Incremental Revenue
AOV Change
Goal Completion Rate
Goal Conversion Rate
Reward Cost
Estimated Profit Impact
```

Add date-range filtering.

Support:

```text
7 days
30 days
90 days
Custom
```

---

# 40. Goal Recommendations UI

Display:

```text
Recommended Goal
Confidence
Expected AOV Impact
Expected Completion Rate
Expected Profit Impact
Reasoning
```

Add:

```text
Apply Recommendation
Dismiss
View Details
```

Applying a recommendation must require explicit user action.

Never automatically modify a production goal.

---

# 41. Upsell Analytics UI

Display:

```text
Top Recommended Products

Product
Impressions
Clicks
Adds
Orders
Conversion
Revenue
Estimated Profit
Upsell Score
```

Also display:

```text
Top Performing Upsells
Lowest Performing Upsells
Highest Margin Upsells
Best Conversion Upsells
```

---

# 42. REST API

Create secure REST API endpoints following the existing plugin architecture.

Possible endpoints:

```text
GET /revenue/overview

GET /revenue/goals

GET /revenue/attribution

GET /revenue/recommendations

GET /revenue/goal-recommendations

GET /revenue/upsells

GET /revenue/upsells/{product_id}
```

Frontend endpoints must support:

```text
date range
goal filtering
pagination
sorting
search
```

Use proper WordPress REST API authentication and capability checks.

---

# 43. Database Design

Inspect the existing database architecture before creating tables.

If dedicated tables are required, consider:

```text
wp_goalcart_revenue_events
wp_goalcart_revenue_daily
wp_goalcart_goal_attribution
wp_goalcart_upsell_events
wp_goalcart_upsell_stats
```

Potential event fields:

```text
id
event_type
goal_id
product_id
order_id
session_id
user_id
cart_value
goal_target
incremental_value
timestamp
created_at
```

Use indexed columns for:

```text
goal_id
product_id
order_id
session_id
event_type
created_at
```

Do not create unnecessary indexes.

Use appropriate data types for monetary values.

---

# 44. Analytics Aggregation

Do not calculate expensive metrics from raw events on every admin page request.

Implement aggregation where appropriate.

Possible aggregation:

```text
Hourly
Daily
Weekly
Monthly
```

At minimum support daily aggregates.

Example:

```text
wp_goalcart_revenue_daily
```

Fields:

```text
date
goal_id
views
progressions
completions
conversions
revenue
incremental_revenue
reward_cost
estimated_profit
```

---

# 45. Cron / Scheduled Processing

Use WordPress cron or the existing plugin scheduler where appropriate.

Tasks may include:

```text
Aggregate revenue events
Calculate daily metrics
Update product popularity
Update conversion scores
Generate goal recommendations
Clean expired anonymous session data
```

Heavy calculations should not block frontend requests.

---

# 46. Performance Requirements

The implementation must be optimized for WooCommerce stores with large datasets.

Consider:

```text
10,000+ orders
100,000+ orders
50,000+ products
```

Avoid:

```text
N+1 queries
Loading all products into memory
Loading all orders into memory
Calculating complex statistics on every request
Unbounded SQL queries
```

Use:

```text
pagination
aggregation
indexes
caching
transients/object cache where appropriate
scheduled processing
```

---

# 47. Caching

Cache:

```text
Goal recommendations
Product rankings
Revenue summaries
AOV calculations
Order distribution
Shipping statistics
```

Invalidate caches when:

```text
new order
goal configuration changes
product inventory changes where relevant
margin changes
date range expires
```

Do not cache data longer than appropriate.

---

# 48. Data Accuracy

All financial calculations must:

* Respect WooCommerce currency.
* Respect store timezone.
* Use WooCommerce order totals consistently.
* Handle refunds.
* Handle cancelled orders.
* Handle failed orders.
* Handle partially refunded orders where practical.
* Avoid counting the same order multiple times.
* Avoid duplicate tracking events.

Define clearly which order statuses are considered revenue-producing.

Use the existing WooCommerce conventions whenever possible.

---

# 49. Privacy

Do not unnecessarily store personal data.

Prefer:

```text
anonymous session ID
hashed/non-identifying identifiers where appropriate
```

Do not store:

```text
email
phone
full address
payment information
```

unless already required by the existing plugin architecture and explicitly necessary.

Provide data retention/cleanup mechanisms where appropriate.

---

# 50. Feature Flags

Implement feature flags or settings where appropriate:

```text
Enable Revenue Attribution
Enable Smart Goal Recommendations
Enable Smart Upsell
Enable Margin Analysis
Enable Shipping Analysis
```

Features that require unavailable data should gracefully degrade.

---

# 51. Graceful Degradation

Examples:

### No margin data

Display:

```text
Profit impact unavailable because product cost data is not available.
```

Still calculate revenue.

### No shipping cost

Do not fail.

Calculate recommendation without shipping data and reduce confidence.

### Insufficient historical data

Display:

```text
Not enough data for a reliable recommendation.
```

Do not generate fake recommendations.

### New store

Use:

```text
Basic AOV-based recommendations
```

with low confidence.

---

# 52. Minimum Data Requirements

Define thresholds for recommendations.

Example:

```text
<50 orders:
Insufficient data

50–200 orders:
Basic recommendation

200–1,000 orders:
Reliable recommendation

1,000+ orders:
High-confidence recommendation
```

These thresholds should be configurable.

Do not present them as statistical certainty; use them as product-level heuristics.

---

# 53. Recommendation Safety

Never automatically:

* change goal thresholds
* enable discounts
* change product prices
* enable free shipping
* change WooCommerce settings

Recommendations must always require explicit admin approval.

---

# 54. Testing Requirements

Create comprehensive tests.

## Unit Tests

Test:

```text
AOV calculation
Median calculation
Order distribution
Incremental value
Revenue attribution
Reward cost
Profit estimation
Price gap score
Relevance score
Inventory score
Popularity score
Margin score
Conversion score
Composite upsell score
Goal threshold recommendation
Confidence calculation
```

---

# 55. Integration Tests

Test:

```text
WooCommerce order creation
Goal completion
Cart changes
Reward application
Order payment
Order cancellation
Refund
Partial refund
Product inventory changes
Goal configuration changes
```

---

# 56. Edge Cases

Handle:

```text
Empty cart
Zero-value cart
Free products
Virtual products
Downloadable products
Products without cost
Products without inventory
Backorders
Variable products
Product variations
Coupons
Taxes
Shipping discounts
Free shipping
Refunded orders
Guest checkout
Logged-in customers
Multiple goals
Multiple simultaneous goals
Expired goals
Disabled goals
```

---

# 57. Admin UX Requirements

The admin UI must be:

```text
Clean
Fast
Readable
Responsive
RTL-compatible
WooCommerce-friendly
Consistent with existing Goal Cart React UI
```

Do not introduce a completely different design language.

Use existing components wherever possible.

---

# 58. Analytics Terminology

Use clear terminology.

Distinguish:

```text
Revenue
Attributed Revenue
Assisted Revenue
Incremental Revenue
Observed Revenue
Estimated Profit
Reward Cost
```

Never label estimated data as guaranteed profit.

---

# 59. Explainability

Every smart recommendation must expose:

```text
Recommendation
Score
Confidence
Main factors
Expected impact
Data period
```

Example:

```text
Recommended Threshold:
1,290,000 تومان

Confidence:
87%

Based on:
• 90-day AOV
• Median order value
• Order distribution
• Shipping cost
• Historical goal performance

Expected:
+12–18% AOV impact
```

---

# 60. Architecture for Future AI/ML

Do not implement machine learning now.

However, design interfaces so future implementations can replace:

```text
DeterministicGoalRecommendationEngine
```

with:

```text
MLGoalRecommendationEngine
```

and:

```text
DeterministicUpsellRanker
```

with:

```text
MLUpsellRanker
```

without changing frontend contracts.

Use interfaces/services where appropriate.

---

# 61. Implementation Phases

Break Phase 33 into the following implementation tasks.

## Phase 33.1 — Analytics Foundation

Tasks:

* Inspect existing analytics architecture
* Design event model
* Create required database tables
* Add indexes
* Implement event tracker
* Implement deduplication
* Implement privacy-safe session tracking
* Add event cleanup

Progress:

```text
100% — COMPLETED
```

Implementation notes (see `tests/revenue-foundation-test.php`, 66 checks):

- **Event model** — `RevenueTracker` owns two raw logs: `revenue_events`
  (the attribution funnel goal_view → goal_progress → goal_completed →
  order_paid, each row carrying cart_value, goal_target and
  incremental_value) and `upsell_events` (impression → clicked → added →
  order per product per session). The Phase 16 `Tracker`/`analytics_events`
  pipeline is untouched.
- **Schema** — five new tables (revenue_events, revenue_daily,
  goal_attribution, upsell_events, upsell_stats) in `Database\Schema`
  with full indexes (single + composite: goal_event, order_event,
  product_event, goal_date, unique order_goal_model / product_id); DB
  version bumped to 0.5.0; `Installer::maybe_add_indexes()` applies them
  idempotently to upgraded installs.
- **Event tracker** — `RevenueTracker::record()` / `record_upsell()` with
  strict event-type whitelists, typed field sanitization and the same
  FK-resilient insert pattern as the Phase 16 tracker.
- **Deduplication** — idempotent recording by design: view/completion /
  impression/click dedup per session+goal(+product) within a 24 h window,
  goal_progress within 30 min, order_paid / upsell_order exactly once per
  order (the unique order_goal_model key also guards the attribution
  layer).
- **Privacy-safe sessions** — reuses the anonymous 32-hex `Session`
  cookie; rows store only anonymous session ids, numeric aggregates and
  plugin/WC ids (no emails, IPs, addresses or payment data); logged-in
  `user_id` is an id, not personal data.
- **Event cleanup** — weekly `goalcart_revenue_cleanup` cron (scheduled
  through `Installer::cron_events()` + the new
  `Installer::maybe_schedule_events()`; cleared on deactivation) purges
  rows older than `RETENTION_DAYS` (filterable via
  `goalcart_revenue_retention_days`) in bounded batches and sweeps orphan
  upsell_stats rows.

---

## Phase 33.2 — Revenue Attribution

Tasks:

* Goal view tracking
* Goal progress tracking
* Goal completion tracking
* Order association
* Direct attribution
* Assisted attribution
* Incremental cart value
* Goal-driven revenue
* Goal-assisted revenue
* AOV analysis
* Reward cost
* Profit impact

Progress:

```text
100% — COMPLETED
```

Implementation notes (see `tests/attribution-test.php`, 71 checks):

- **Order association** — `AttributionEngine` hooks
  `woocommerce_payment_complete` plus `woocommerce_order_status_completed`
  (backstop for manual transitions; both idempotent) and attributes each
  revenue-producing order (statuses `processing`/`completed` only —
  refunded/cancelled/failed orders are skipped) to the goals that
  influenced its session within a 30-day lookback
  (`ATTRIBUTION_WINDOW`). The order_paid event is recorded through the
  Phase 33.1 tracker; rows land in `goal_attribution` guarded by the
  `order_goal_model` unique key, so double processing is a no-op.
- **Direct vs assisted models** — a goal the session progressed or
  completed before ordering is `direct` (the order's incremental value —
  order total above the cart value at first exposure — is split equally
  across the direct goals, never double counted); a goal the session only
  viewed is `assisted` (order total recorded, zero incremental value).
  The session resolves from the recorded order_paid event, the live
  cookie, or the logged-in user's recent goal session.
- **Metrics (SQL-aggregated, bounded)** — `funnel()` (views → progressed
  → completed → converted + completion/conversion rates),
  `incremental_cart_value()` (cart value after exposure − value at first
  exposure, per session), `attribution_summary()` (goal-driven =
  SUM(direct incremental), goal-assisted = pure-assisted order totals,
  goal-influenced = distinct order totals), `goal_metrics()`, `aov_analysis()`
  (store-wide vs goal-exposed, labeled *observed* impact, never
  causality) and `shipping_stats()` (average shipping, per-method, free
  share — feeds the Phase 33.4 shipping-aware recommendations). Reads are
  capped by `goalcart_attribution_metric_rows` /
  `goalcart_attribution_order_scan_pages`.
- **Reward cost** — `RewardCostEstimator` maps every reward type to a
  deterministic cost model: percent (order total × % capped at max),
  fixed (amount), coupon (percent/fixed per coupon settings), free
  shipping (order shipping total) and free gift (gift product cost).
  Models needing data the store lacks (shipping total, gift cost) return
  `available: false` with the reason — never a guessed number.
- **Margin & profit impact** — product cost is read from the store's
  `_cost` / `_wc_cog_cost` fields through the `goalcart_product_cost`
  filter (never modified); `estimated_profit = incremental_revenue ×
  margin% − reward_cost − shipping_cost`. Without margin data the profit
  is reported unavailable with a reason (revenue-only analytics).
- **Feature flags** — attribution gates on the master + analytics toggles
  through the tracker's consent chain plus the
  `goalcart_attribution_enabled` filter (documented in the hooks list).
- **Graceful degradation** — no margin data → profit unavailable but all
  revenue metrics still compute; no WooCommerce order data → AOV/shipping
  comparisons mark `comparison_available: false`; refunded/zero-total
  orders are never attributed.

---

## Phase 33.3 — Aggregation & Performance

Tasks:

* Daily aggregation
* Scheduled jobs
* Revenue summaries
* Product statistics
* Caching
* Cache invalidation
* Large dataset optimization

Progress:

```text
100% — COMPLETED
```

Implementation notes (see `tests/aggregation-test.php`, 73 checks):

- **Daily aggregation** — `DailyAggregator` (new, registered on the
  `daily` cron interval through `Installer::cron_events()` +
  `cron_intervals()`, gated on the same revenue-tracking consent chain)
  rolls each day's `revenue_events` + `goal_attribution` rows into
  `revenue_daily` (views → progressions → completions → conversions,
  revenue, incremental_revenue, reward_cost, estimated_profit) through the
  new `AttributionEngine::daily_metrics()` — the exact same funnel/summary
  /reward-cost/profit code the live dashboard reads, so the aggregate and
  the live view can never drift. Only goals with activity that day get
  rows (no all-goals scan); rows are delete-then-inserted, so re-running
  is idempotent.
- **Upsell product statistics** — `aggregate_upsells()` rebuilds
  `upsell_stats` wholesale with one grouped INSERT...SELECT from the raw
  `upsell_events` log (per-product impressions/clicks/adds/orders/revenue),
  idempotent and never stale relative to the retained event window.
- **Scheduled jobs** — the aggregation job joins the weekly cleanup in
  `cron_events()`; per-event intervals are mapped in the new
  `cron_intervals()` (cleanup weekly, aggregation daily).
- **Bounded catch-up (large datasets)** — `aggregate_revenue()` starts the
  day after the last aggregated date (`goalcart_revenue_last_aggregated`
  option) or the lookback floor (`goalcart_aggregate_lookback_days`, 90 =
  aligned with retention), processes at most
  `goalcart_aggregate_max_days` (default 7) days per tick and advances the
  option — a backlog drains over several runs instead of one unbounded pass.
- **Revenue summaries (cached)** — `RevenueRepository` (new) serves the
  KPI payloads: `overview()` (attribution summary + incremental cart value
  + AOV + shipping merged), `goal_performance()` (per-goal rows),
  `daily_trend()` (reads the aggregated `revenue_daily` table, zero-filled
  over the window, merging today's still-live bucket from the engine until
  the next tick) and `product_stats()` (reads `upsell_stats`). Every read
  is memoized in a generation-versioned transient
  (`goalcart_revenue_cache_version`) with a filterable TTL
  (`goalcart_revenue_cache_ttl`) and a master bypass filter
  (`goalcart_revenue_cache_enabled`).
- **Cache invalidation** — `invalidate()` bumps the generation counter
  (stale keys expire through their TTL, no key enumeration); wired to the
  events that change the data: order payment/status changes, goal CRUD
  (new `goalcart_goals_changed` action fired by `GoalRepository`
  create/update/delete), product saves (`save_post_product`) and the
  aggregation run itself (`goalcart_revenue_aggregated`).

---

## Phase 33.4 — Smart Goal Recommendation

Tasks:

* AOV analyzer
* Median analyzer
* Distribution analyzer
* Shipping analyzer
* Margin analyzer
* Candidate threshold generation
* Threshold scoring
* Confidence calculation
* Recommendation explanation
* Recommendation API

Progress:

```text
0%
```

---

## Phase 33.5 — Smart Upsell

Tasks:

* Candidate product engine
* Price gap scorer
* Relevance scorer
* Inventory scorer
* Popularity scorer
* Margin scorer
* Conversion scorer
* Composite scoring
* Product ranking
* Historical performance tracking

Progress:

```text
0%
```

---

## Phase 33.6 — React Admin

Tasks:

* Revenue Overview
* Goal Performance
* Attribution Dashboard
* Smart Recommendations
* Goal Recommendation UI
* Upsell Analytics
* Charts
* Filters
* Date ranges
* Loading states
* Empty states
* Error states
* RTL support

Progress:

```text
0%
```

---

## Phase 33.7 — Frontend Upsell Integration

Tasks:

* Goal gap calculation
* Candidate request
* Product ranking
* Upsell component
* Add-to-cart integration
* Conversion tracking
* Mobile optimization
* Existing theme compatibility

Progress:

```text
0%
```

---

## Phase 33.8 — Testing & Optimization

Tasks:

* Unit tests
* Integration tests
* Edge-case tests
* HPOS tests
* Large dataset tests
* Performance tests
* Security audit
* Query optimization
* Cache validation
* Regression testing

Progress:

```text
0%
```

---

# 62. Progress Tracking

Maintain progress for:

```text
Phase 33
Phase 33.1
Phase 33.2
Phase 33.3
Phase 33.4
Phase 33.5
Phase 33.6
Phase 33.7
Phase 33.8
```

Every task must have:

```text
Status
Progress %
```

Allowed statuses:

```text
NOT_STARTED
IN_PROGRESS
BLOCKED
COMPLETED
```

Update progress only when implementation is actually completed.

---

# 63. Definition of Done

Phase 33 is complete only when all of the following are true:

### Revenue Attribution

* [ ] Goal views tracked
* [ ] Goal progress tracked
* [ ] Goal completions tracked
* [ ] Goal conversions tracked
* [ ] Goal-driven revenue calculated
* [ ] Goal-assisted revenue calculated
* [ ] Incremental cart value calculated
* [ ] AOV impact calculated
* [ ] Reward cost calculated
* [ ] Profit impact calculated when data is available

### Smart Goal Recommendation

* [ ] AOV analyzed
* [ ] Median order value analyzed
* [ ] Order distribution analyzed
* [ ] Shipping cost analyzed
* [ ] Margin analyzed when available
* [ ] Candidate thresholds generated
* [ ] Thresholds scored
* [ ] Best threshold recommended
* [ ] Confidence calculated
* [ ] Recommendation explanation available
* [ ] Admin approval required before applying

### Smart Upsell

* [ ] Candidate products collected
* [ ] Out-of-stock products excluded
* [ ] Price gap scored
* [ ] Relevance scored
* [ ] Inventory scored
* [ ] Popularity scored
* [ ] Margin scored
* [ ] Historical conversion scored
* [ ] Composite score calculated
* [ ] Products ranked
* [ ] Recommendation performance tracked

### Admin

* [ ] Revenue Overview implemented
* [ ] Goal Performance implemented
* [ ] Attribution dashboard implemented
* [ ] Smart Goal Recommendations implemented
* [ ] Upsell Analytics implemented
* [ ] Filters implemented
* [ ] Date ranges implemented
* [ ] RTL supported

### Technical

* [ ] HPOS compatible
* [ ] Secure REST APIs
* [ ] Permission checks
* [ ] SQL optimized
* [ ] Caching implemented
* [ ] Scheduled aggregation implemented
* [ ] Privacy-safe tracking
* [ ] No duplicate events
* [ ] Unit tests pass
* [ ] Integration tests pass
* [ ] Existing Goal Cart functionality remains intact

---

# 64. Final Expected Result

After Phase 33, Goal Cart should evolve from:

```text
A WooCommerce cart goal/progress plugin
```

into:

```text
A Revenue Optimization Engine
```

The final system should allow a store owner to see:

```text
How much revenue Goal Cart influenced
How much additional cart value it generated
How much AOV changed
How much rewards cost
How much estimated profit was generated
Which goal threshold should be used
Why that threshold is recommended
Which products should be used for upselling
Why each product was selected
How well each recommendation performs
```

The system should continuously collect data and improve its recommendations using historical store behavior.

---

# 65. Final Implementation Instruction

Do not implement this phase as a superficial collection of dashboard widgets.

Build the underlying:

```text
Tracking
Data Model
Analytics
Attribution
Aggregation
Recommendation
Ranking
Caching
API
Admin UI
Frontend Integration
Testing
```

as real production-ready functionality.

Before changing any file:

1. Inspect the existing Goal Cart architecture.
2. Identify reusable services/components.
3. Identify existing database tables.
4. Identify existing REST APIs.
5. Identify existing React Admin structure.
6. Identify existing frontend goal components.
7. Identify existing tracking mechanisms.
8. Produce an implementation plan based on the actual codebase.

Then implement the phase incrementally.

Do not rewrite unrelated parts of the plugin.

Do not introduce unnecessary dependencies.

Do not break backward compatibility.

At the end, provide a concise implementation report containing:

```text
Files Created
Files Modified
Database Changes
New APIs
New React Components
New Services
New Cron Jobs
Tests Added
Performance Considerations
Security Considerations
Completed Tasks
Remaining Tasks
Phase Progress
```

Final target:

```text
Phase 33 Progress: 100%
Project Contribution: 3.00%
```

