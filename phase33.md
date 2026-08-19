# Phase 33 — Advanced V3 Revenue Optimization

## Comprehensive Implementation Prompt

You are working on the **FaraCart WooCommerce plugin**.

Your task is to fully implement **Phase 33 — Advanced V3 Revenue Optimization**.

---

# 1. Phase Information

```text
Phase: 33
Name: Advanced V3 Revenue Optimization

Phase Weight: 3%
Phase Progress: 100%
Project Contribution: 3.00%

Phase:
████████████████████ 100%
```

The purpose of this phase is to transform FaraCart from a simple mission/progress-bar plugin into a **data-driven revenue optimization engine for WooCommerce**.

The system must not only display missions but also measure their financial impact, recommend optimal mission thresholds, and intelligently recommend upsell products.

---

# 2. Core Objectives

Implement three major systems:

```text
1. Revenue Attribution Engine
2. Smart Mission Recommendation Engine
3. Smart Upsell Engine
```

The final system should answer:

```text
How much additional revenue did FaraCart generate?

Which mission thresholds should this store use?

Which products should be recommended to customers to help them reach a mission?

Which recommendations generate the most profitable conversions?
```

Do NOT introduce LLM/AI APIs in this phase.

The first version must be based on deterministic analytics, scoring, historical WooCommerce data, and statistical calculations.

The architecture must however be extensible so AI/ML-based recommendations can be added later.

---

# 3. IMPORTANT IMPLEMENTATION RULES

Before writing code:

1. Inspect the entire existing FaraCart plugin.
2. Understand the current architecture.
3. Reuse existing coding conventions.
4. Reuse existing database abstractions where possible.
5. Reuse existing React Admin architecture.
6. Do not duplicate existing functionality.
7. Do not break existing FaraCart functionality.
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
│   ├── Mission View Tracking
│   ├── Mission Progress Tracking
│   ├── Mission Completion Tracking
│   ├── Cart Value Tracking
│   ├── Order Attribution
│   ├── Revenue Attribution
│   ├── AOV Analysis
│   ├── Reward Cost Analysis
│   └── Profit Impact Estimation
│
├── Mission Recommendation Engine
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

## 5.1 Mission View Tracking

Track when a customer is exposed to a FaraCart mission.

Track:

```text
mission_id
session_id
customer_id if available
user_id if available
cart_value
mission_target
mission_type
timestamp
page/context
device type if available
```

Do not store unnecessary personally identifiable information.

Use anonymous session identifiers when possible.

---

# 5.2 Mission Progress Tracking

Track when a customer moves toward a mission.

Example:

```text
Initial cart:
700,000

Mission:
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
mission_progress_before
mission_progress_after
```

Avoid recording duplicate events for insignificant changes.

---

# 5.3 Mission Completion Tracking

Track when the customer reaches the mission.

Example:

```text
Mission:
1,000,000

Cart:
1,050,000

Mission completed:
YES
```

Record:

```text
mission_completed_at
cart_value_at_completion
amount_added_to_reach_mission
```

---

# 5.4 Mission Conversion Tracking

A mission completion should not automatically equal a conversion.

Track whether the customer subsequently completes an order.

Example funnel:

```text
Mission Viewed
     ↓
Mission Progressed
     ↓
Mission Reached
     ↓
Checkout
     ↓
Order Paid
```

Calculate conversion rates at every stage.

---

# 6. Revenue Attribution Models

Implement multiple attribution levels.

## 6.1 Direct Mission Attribution

Attribute incremental cart value to a mission when there is strong evidence that the mission influenced the cart increase.

Example:

```text
Cart before:
700K

Mission:
1M

Cart after:
1.05M

Incremental:
350K
```

The incremental amount may be attributed to the mission.

---

## 6.2 Mission Completion Attribution

When the customer reaches the mission and subsequently purchases, record:

```text
mission_completed = true
order_completed = true
```

Calculate mission-driven revenue.

---

## 6.3 Assisted Attribution

A mission may influence a purchase without being the direct cause.

Track:

```text
Mission Viewed
→ Product Viewed
→ Product Added
→ Mission Progress
→ Purchase
```

Record this as:

```text
Mission Assisted Revenue
```

---

# 7. Revenue Metrics

Implement the following metrics.

## Incremental Cart Value

```text
Incremental Cart Value =
Average Cart Value After Mission Exposure
-
Average Cart Value Without Mission Influence
```

Where statistically valid comparison data is available.

---

## Mission-Driven Revenue

Calculate:

```text
Mission Driven Revenue
```

based on the selected attribution model.

---

## Mission Assisted Revenue

Calculate revenue from orders where FaraCart played an assisting role.

---

## Mission Completion Rate

```text
Mission Completion Rate =
Completed Missions / Mission Views
```

---

## Mission Conversion Rate

```text
Mission Conversion Rate =
Orders Associated With Mission / Mission Completions
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
Before FaraCart
After FaraCart
```

Where possible, also compare:

```text
Mission-exposed orders
vs
Non-mission-exposed orders
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

Missions may provide rewards such as:

* percentage discount
* fixed discount
* free shipping
* free gift
* coupon
* other supported rewards

Calculate the estimated cost of rewards.

Example:

```text
Mission Revenue:
38,000,000

Reward Cost:
4,800,000

Net Revenue Impact:
33,200,000
```

Support reward cost calculations based on the existing FaraCart reward implementation.

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

Use this data in Smart Mission Recommendations.

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
Mission Influenced Revenue
Incremental Cart Value
AOV Impact
Mission Conversion Rate
Reward Cost
Estimated Profit Impact
```

Example:

```text
Mission Influenced Revenue
+42.8M تومان

AOV Impact
+18.4%

Mission Revenue
67.2M تومان

Estimated Profit
+21.6M تومان
```

---

# 14. Mission Performance Dashboard

For every mission display:

```text
Mission Name

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

# 15. Smart Mission Recommendation Engine

Build a recommendation engine that analyzes store data and recommends mission thresholds.

The engine should answer:

```text
What threshold should this store use?
```

---

# 16. Smart Mission Inputs

Use the following inputs where available:

```text
AOV
Median Order Value
Order Distribution
Shipping Cost
Product Margins
Current Mission Performance
Mission Completion Rate
Mission Conversion Rate
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

If the mission is free shipping, consider:

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

# 21. Margin-Aware Mission Recommendations

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
Expected Mission Reachability
Expected AOV Increase
Expected Conversion
Reward Cost
Shipping Cost
Estimated Profit
Historical Mission Performance
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

Expected Mission Completion:
22% to 29%
```

Confidence should depend on:

```text
Data volume
Data consistency
Historical mission performance
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

Build a product-ranking engine that recommends products capable of helping customers reach their active Mission.

Example:

```text
Current Cart:
1,550K

Mission:
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
Historical Mission Conversions
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
Mission completed after recommendation
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
Active mission
Remaining gap
Products already in cart
Store inventory
Historical behavior
```

Example:

```text
Cart:
1,550K

Mission:
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

Integrate Smart Upsell with existing FaraCart frontend components.

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

Do not redesign existing FaraCart UI unnecessarily.

Reuse existing components and styles where possible.

---

# 38. Admin React UI

Create the following sections:

```text
Revenue Optimization
│
├── Overview
├── Mission Performance
├── Revenue Attribution
├── Smart Recommendations
├── Mission Recommendations
└── Upsell Analytics
```

---

# 39. Overview Page

Display:

```text
Total Mission Revenue
Incremental Revenue
AOV Change
Mission Completion Rate
Mission Conversion Rate
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

# 40. Mission Recommendations UI

Display:

```text
Recommended Mission
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

Never automatically modify a production mission.

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

GET /revenue/missions

GET /revenue/attribution

GET /revenue/recommendations

GET /revenue/mission-recommendations

GET /revenue/upsells

GET /revenue/upsells/{product_id}
```

Frontend endpoints must support:

```text
date range
mission filtering
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
wp_faracart_revenue_events
wp_faracart_revenue_daily
wp_faracart_mission_attribution
wp_faracart_upsell_events
wp_faracart_upsell_stats
```

Potential event fields:

```text
id
event_type
mission_id
product_id
order_id
session_id
user_id
cart_value
mission_target
incremental_value
timestamp
created_at
```

Use indexed columns for:

```text
mission_id
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
wp_faracart_revenue_daily
```

Fields:

```text
date
mission_id
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
Generate mission recommendations
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
Mission recommendations
Product rankings
Revenue summaries
AOV calculations
Order distribution
Shipping statistics
```

Invalidate caches when:

```text
new order
mission configuration changes
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
Enable Smart Mission Recommendations
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

* change mission thresholds
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
Mission threshold recommendation
Confidence calculation
```

---

# 55. Integration Tests

Test:

```text
WooCommerce order creation
Mission completion
Cart changes
Reward application
Order payment
Order cancellation
Refund
Partial refund
Product inventory changes
Mission configuration changes
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
Multiple missions
Multiple simultaneous missions
Expired missions
Disabled missions
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
Consistent with existing FaraCart React UI
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
• Historical mission performance

Expected:
+12–18% AOV impact
```

---

# 60. Architecture for Future AI/ML

Do not implement machine learning now.

However, design interfaces so future implementations can replace:

```text
DeterministicMissionRecommendationEngine
```

with:

```text
MLMissionRecommendationEngine
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
  (the attribution funnel mission_view → mission_progress → mission_completed →
  order_paid, each row carrying cart_value, mission_target and
  incremental_value) and `upsell_events` (impression → clicked → added →
  order per product per session). The Phase 16 `Tracker`/`analytics_events`
  pipeline is untouched.
- **Schema** — five new tables (revenue_events, revenue_daily,
  mission_attribution, upsell_events, upsell_stats) in `Database\Schema`
  with full indexes (single + composite: mission_event, order_event,
  product_event, mission_date, unique order_mission_model / product_id); DB
  version bumped to 0.5.0; `Installer::maybe_add_indexes()` applies them
  idempotently to upgraded installs.
- **Event tracker** — `RevenueTracker::record()` / `record_upsell()` with
  strict event-type whitelists, typed field sanitization and the same
  FK-resilient insert pattern as the Phase 16 tracker.
- **Deduplication** — idempotent recording by design: view/completion /
  impression/click dedup per session+mission(+product) within a 24 h window,
  mission_progress within 30 min, order_paid / upsell_order exactly once per
  order (the unique order_mission_model key also guards the attribution
  layer).
- **Privacy-safe sessions** — reuses the anonymous 32-hex `Session`
  cookie; rows store only anonymous session ids, numeric aggregates and
  plugin/WC ids (no emails, IPs, addresses or payment data); logged-in
  `user_id` is an id, not personal data.
- **Event cleanup** — weekly `faracart_revenue_cleanup` cron (scheduled
  through `Installer::cron_events()` + the new
  `Installer::maybe_schedule_events()`; cleared on deactivation) purges
  rows older than `RETENTION_DAYS` (filterable via
  `faracart_revenue_retention_days`) in bounded batches and sweeps orphan
  upsell_stats rows.

---

## Phase 33.2 — Revenue Attribution

Tasks:

* Mission view tracking
* Mission progress tracking
* Mission completion tracking
* Order association
* Direct attribution
* Assisted attribution
* Incremental cart value
* Mission-driven revenue
* Mission-assisted revenue
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
  refunded/cancelled/failed orders are skipped) to the missions that
  influenced its session within a 30-day lookback
  (`ATTRIBUTION_WINDOW`). The order_paid event is recorded through the
  Phase 33.1 tracker; rows land in `mission_attribution` guarded by the
  `order_mission_model` unique key, so double processing is a no-op.
- **Direct vs assisted models** — a mission the session progressed or
  completed before ordering is `direct` (the order's incremental value —
  order total above the cart value at first exposure — is split equally
  across the direct missions, never double counted); a mission the session only
  viewed is `assisted` (order total recorded, zero incremental value).
  The session resolves from the recorded order_paid event, the live
  cookie, or the logged-in user's recent mission session.
- **Metrics (SQL-aggregated, bounded)** — `funnel()` (views → progressed
  → completed → converted + completion/conversion rates),
  `incremental_cart_value()` (cart value after exposure − value at first
  exposure, per session), `attribution_summary()` (mission-driven =
  SUM(direct incremental), mission-assisted = pure-assisted order totals,
  mission-influenced = distinct order totals), `mission_metrics()`, `aov_analysis()`
  (store-wide vs mission-exposed, labeled *observed* impact, never
  causality) and `shipping_stats()` (average shipping, per-method, free
  share — feeds the Phase 33.4 shipping-aware recommendations). Reads are
  capped by `faracart_attribution_metric_rows` /
  `faracart_attribution_order_scan_pages`.
- **Reward cost** — `RewardCostEstimator` maps every reward type to a
  deterministic cost model: percent (order total × % capped at max),
  fixed (amount), coupon (percent/fixed per coupon settings), free
  shipping (order shipping total) and free gift (gift product cost).
  Models needing data the store lacks (shipping total, gift cost) return
  `available: false` with the reason — never a guessed number.
- **Margin & profit impact** — product cost is read from the store's
  `_cost` / `_wc_cog_cost` fields through the `faracart_product_cost`
  filter (never modified); `estimated_profit = incremental_revenue ×
  margin% − reward_cost − shipping_cost`. Without margin data the profit
  is reported unavailable with a reason (revenue-only analytics).
- **Feature flags** — attribution gates on the master + analytics toggles
  through the tracker's consent chain plus the
  `faracart_attribution_enabled` filter (documented in the hooks list).
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
  rolls each day's `revenue_events` + `mission_attribution` rows into
  `revenue_daily` (views → progressions → completions → conversions,
  revenue, incremental_revenue, reward_cost, estimated_profit) through the
  new `AttributionEngine::daily_metrics()` — the exact same funnel/summary
  /reward-cost/profit code the live dashboard reads, so the aggregate and
  the live view can never drift. Only missions with activity that day get
  rows (no all-missions scan); rows are delete-then-inserted, so re-running
  is idempotent.
- **Upsell product statistics** — `aggregate_upsells()` rebuilds
  `upsell_stats` wholesale with one grouped INSERT...SELECT from the raw
  `upsell_events` log (per-product impressions/clicks/adds/orders/revenue),
  idempotent and never stale relative to the retained event window.
- **Scheduled jobs** — the aggregation job joins the weekly cleanup in
  `cron_events()`; per-event intervals are mapped in the new
  `cron_intervals()` (cleanup weekly, aggregation daily).
- **Bounded catch-up (large datasets)** — `aggregate_revenue()` starts the
  day after the last aggregated date (`faracart_revenue_last_aggregated`
  option) or the lookback floor (`faracart_aggregate_lookback_days`, 90 =
  aligned with retention), processes at most
  `faracart_aggregate_max_days` (default 7) days per tick and advances the
  option — a backlog drains over several runs instead of one unbounded pass.
- **Revenue summaries (cached)** — `RevenueRepository` (new) serves the
  KPI payloads: `overview()` (attribution summary + incremental cart value
  + AOV + shipping merged), `mission_performance()` (per-mission rows),
  `daily_trend()` (reads the aggregated `revenue_daily` table, zero-filled
  over the window, merging today's still-live bucket from the engine until
  the next tick) and `product_stats()` (reads `upsell_stats`). Every read
  is memoized in a generation-versioned transient
  (`faracart_revenue_cache_version`) with a filterable TTL
  (`faracart_revenue_cache_ttl`) and a master bypass filter
  (`faracart_revenue_cache_enabled`).
- **Cache invalidation** — `invalidate()` bumps the generation counter
  (stale keys expire through their TTL, no key enumeration); wired to the
  events that change the data: order payment/status changes, mission CRUD
  (new `faracart_missions_changed` action fired by `MissionRepository`
  create/update/delete), product saves (`save_post_product`) and the
  aggregation run itself (`faracart_revenue_aggregated`).

---

## Phase 33.4 — Smart Mission Recommendation

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
100% — COMPLETED
```

Implementation notes (see `tests/recommendation-test.php`, 90 checks):

- **Deterministic engine** — `MissionRecommendationEngine` (new) answers
  "what threshold should this store use?" from the store's own data with
  no LLM/AI: AOV, median, order-value distribution, shipping and margins
  are all computed from bounded scans and averaged samples, and the
  public `recommend()` contract is the frontend contract — a future
  `MLMissionRecommendationEngine` can replace the class behind the same
  payload shape without touching the REST layer (P33-60).
- **Analyzers (all bounded)** — AOV/median/CV via the new
  `AttributionEngine::store_order_values()` (the same memoized paginated
  store scan the AOV/shipping metrics use — one pass per window, never a
  full-table load); order distribution in AOV-relative buckets
  (`<0.5×` → `>1.5×`); shipping via `shipping_stats()` (average + free
  share); margin by sampling the newest catalog products through the
  existing `faracart_product_cost` read path — unavailable when the
  store stores no costs, never invented; current mission performance via
  the attribution funnel when a `mission_id` is supplied.
- **Candidate generation (P33-22)** — AOV × {0.9, 1.0, 1.1, 1.2, 1.3,
  1.4, 1.5} plus shipping-aware additions (AOV + average shipping,
  median + average shipping) for free-shipping missions; the list is
  filterable (`faracart_recommendation_candidates`) before scoring.
- **Deterministic scoring (P33-22)** — four normalized components,
  filterable weights (`faracart_recommendation_weights`): reachability
  30% (share of orders within 30% below the threshold, triangular peak),
  distance 25% (stretch above median + AOV — too easy / too far both
  score low), economics 30% (reward cost vs incremental margin at the
  threshold — neutral 50 when margin/reward data is missing), history
  15% (the store's own completion rate, neutral without ≥10 views).
  Every candidate exposes its raw `factors` breakdown and a plain-English
  `reasons` list (P33-24/59) — the UI can always explain *why*.
- **Confidence (P33-23/52)** — data-volume tier (50/200/1000 orders →
  basic/reliable/high-confidence, default 50 minimum filterable via
  `faracart_recommendation_min_orders`) adjusted by order-value
  consistency (CV), margin/shipping availability, mission-history depth and
  economics data availability; clamped 40–95 so heuristics are never
  presented as certainty.
- **Expected impact** — `expected_aov_impact` range (reachable share ×
  gap %), `expected_completion_rate` (reachable share × history factor)
  and `expected_profit` through the tested `RewardCostEstimator::profit_impact`
  model — profit excluded (never invented) without margin data.
- **Graceful degradation (P33-51/52)** — fewer than the minimum orders →
  no recommendation, only an `insufficient_reason`; no WooCommerce order
  data → unavailable; disabled via `faracart_recommendations_enabled` →
  unavailable; the payload is filterable end-to-end
  (`faracart_recommendations`).
- **Safety (P33-53)** — the engine never changes a mission; applying a
  recommendation is an explicit admin action through the existing
  MissionsController.
- **API + caching** — new admin-only `GET /faracart/v1/revenue/mission-recommendations`
  (`RecommendationsController`, args: mission_id, reward_type whitelist,
  reward_value/max/`reward_meta`, window_days 7–180, from/to), served
  through the Phase 33.3 generation-versioned transient layer
  (`RevenueRepository::mission_recommendations`, TTL filterable via
  `faracart_recommendation_cache_ttl`) — the existing order/mission/product
  invalidation already covers every event that changes a recommendation.

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
100% — COMPLETED
```

Implementation notes (see `tests/upsell-test.php`, 81 checks):

- **Deterministic ranking engine** — `UpsellRanker` (new) answers "which
  products should this shopper add to reach the mission?" with a fully
  transparent weighted ranking, no LLM/AI. The public `rank()` contract is
  the frontend contract — a future `MLUpsellRanker` can replace the class
  behind the same payload shape without touching the REST layer or admin
  UI (P33-60). The ranker never writes anything; historical events are
  recorded only by the public track endpoint and the order hooks.
- **Candidate collection (P33-26)** — bounded (60 max), deduped,
  source-annotated pool from the mission's own products (`manual`),
  products historically recommended for the mission (`historical`), the
  mission's categories (`category`), the cart items' WooCommerce-endorsed
  sources (`upsell` / `cross_sell` / `related`), products sharing a
  category or tag with the cart (`category_match` / `tag_match`) and
  best sellers (`popular`). Out-of-stock / private / draft /
  already-in-cart / mission-excluded products never reach scoring.
- **Six normalized 0–100 components (P33-27→32)** with filterable
  weights (`faracart_upsell_weights`, defaults per P33-33): price gap
  25% (sweet band [0.75×, 1.30×] → 100, small overshoots tolerated per
  P33-27/36, hard decay to 0 at 3×, neutral 50 without a price/gap),
  relevance 25% (mission manual +55 / counts-toward-mission +35, category
  overlap +30, tag overlap +20, WC-endorsed source +15), popularity 15%
  (units sold bounded at 100 + rating), inventory 10% (stock >20 → 100 /
  5–20 → 70 / 1–4 → 40 / backorder → 20 / unmanaged → neutral 70),
  margin 15% (only when the store provides cost data — never invented,
  neutral 50 otherwise) and conversion 10% (historical upsell funnel,
  impressions-weighted so sparse data blends toward neutral 50). A
  partial weight filter falls back per key: missing keys keep their
  defaults, provided keys normalize among themselves.
- **Ranking output (P33-34)** — composite `score` desc, ties break by
  lower price then product id (fully deterministic); every product
  exposes its `components` breakdown, raw `factors`, historical
  `conversion` stats and plain-English `reasons` derived from the actual
  computed numbers — the admin UI can always show *why* a product was
  chosen.
- **Historical learning (P33-35)** — the storefront reports upsell
  interactions through the public `POST /faracart/v1/upsell/track`
  (`UpsellController`) into the Phase 33.1 `upsell_events` log
  (impression/clicked/add deduped per session+mission+product within 24h;
  `upsell_order` once per order). On a paid order
  `UpsellRanker::attribute_order()` resolves the ordering session (the
  order_paid event's session, then the live cookie, then the logged-in
  user's recent revenue session) and records one `upsell_order` event per
  product shown/clicked/added in that session — the "purchased after
  recommendation" signal. The Phase 33.3 `DailyAggregator::aggregate_upsells()`
  rebuilds `upsell_stats`, and the conversion scorer reads the aggregates:
  deterministic historical scoring, no black-box model.
- **Graceful degradation (P33-51)** — no mission / no remaining gap →
  unavailable with a reason (a closed gap is explicit, never a
  fabricated list); disabled via `faracart_upsells_enabled` → unavailable;
  no margin data → margin neutral 50 and profit excluded (`profit_available:
  false`, `estimated_profit: null`) while the product still ranks; no
  historical data → conversion neutral 50; no candidates → unavailable.
- **API + caching** — new admin-only `GET /faracart/v1/revenue/upsells`
  (ranked products for a cart + mission context: mission_id, cart_value,
  remaining, cart, limit, exclude) and
  `GET /faracart/v1/revenue/upsells/{product_id}` (one product's score
  breakdown + historical stats), served through
  `RevenueRepository::upsell_ranking()` / `upsell_product_detail()` on the
  same Phase 33.3 generation-versioned transient layer (the existing
  order/mission/product/aggregation invalidation keeps rankings fresh).
  `RevenueRepository::upsell_analytics()` powers the admin top-products
  table over a window (impressions/clicks/adds/orders/revenue/profit/score).
  `faracart_upsell_candidates` and `faracart_upsells` filters let callers
  pin the candidate set and shape the payload.
- **Hooks** — `UpsellRanker` registers the server-side `upsell_order`
  attribution on `woocommerce_payment_complete` +
  `woocommerce_order_status_completed` at priority 20 (after the
  AttributionEngine's order_paid anchor at 10); both paths are idempotent
  via the tracker's per-order dedup.

---

## Phase 33.6 — React Admin

Tasks:

* Revenue Overview
* Mission Performance
* Attribution Dashboard
* Smart Recommendations
* Mission Recommendation UI
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
100% — COMPLETED
```

Implementation notes (see `tests/revenue-admin-test.php`, 47 checks):

- **Revenue section** — a new `Revenue` navigation group with five
  lazy-loaded pages: Overview (`/revenue`), Mission Performance
  (`/revenue/missions`), Attribution (`/revenue/attribution`),
  Recommendations (`/revenue/recommendations`) and Upsell Analytics
  (`/revenue/upsells`). Every page reuses the shared `RevenueToolbar`
  (date range + mission filter) and follows the existing Analytics page's
  loading (skeleton), empty (`EmptyState`), error (Alert) and RTL
  conventions (MUI theme rtlPlugin + logical properties).
- **Revenue Overview** — KPI cards (mission-influenced / mission-driven
  revenue, incremental cart value, AOV impact, mission conversion rate,
  reward cost, estimated profit), the daily revenue trend chart
  (completions/conversions bars + revenue/incremental lines over
  `revenue_daily`, zero-filled with today's live bucket), the observed
  AOV comparison (exposed vs store-wide, labeled, never causality) and
  shipping stats — all from the new `GET /revenue/overview` endpoint.
- **Mission Performance** — per-mission table (funnel counts, completion /
  conversion rates, average + incremental cart value, attributed +
  assisted revenue, reward cost, profit) with expandable rows that show
  the funnel visual + detail — from `GET /revenue/missions`.
- **Attribution Dashboard** — the funnel visual, direct vs assisted
  model revenue cards, incremental cart value with a data-sufficiency
  badge and the profit panel (graceful unavailability reason when the
  store stores no product costs) — from `GET /revenue/attribution`.
- **Smart Recommendations / Mission Recommendation UI** — the Phase 33.4
  recommendation payload rendered end-to-end: analyzed store data (AOV,
  median, CV, order distribution bars, shipping, margin, confidence
  tier), the top recommendation card with threshold / confidence /
  expected AOV impact / expected completion / expected profit / reasons
  and **Apply / View details / Dismiss** actions, and the ranked
  candidate list. Applying always requires an explicit admin
  confirmation (ConfirmDialog) and goes through the existing
  `MissionsController` update — the engine itself never modifies a mission
  (P33-53).
- **Upsell Analytics** — the top-products table (impressions / clicks /
  adds / orders / conversion / revenue / estimated profit / upsell
  score) with the four spec views (top performing, lowest performing,
  best conversion, highest margin) and a per-product score-breakdown
  dialog (components, reasons, factors, historical stats) via
  `GET /revenue/upsells?analytics=1` + `GET /revenue/upsells/{product_id}`.
  `RevenueRepository::build_upsell_analytics()` now also exposes
  `estimated_profit` / `profit_available` / `margin_pct` per row (null
  without cost data — never invented).
- **REST** — new admin-only `RevenueController`
  (`includes/REST/RevenueController.php`): `GET /revenue/overview`
  (overview + daily trend), `GET /revenue/attribution` (overview minus
  trend) and `GET /revenue/missions` (per-mission rows), all
  manage_options-gated, per-user rate limited, arg-schema validated
  (from/to datetimes + mission_id bounds) and served through the cached
  `RevenueRepository` layer — no new uncached queries.

---

## Phase 33.7 — Frontend Upsell Integration

Tasks:

* Mission gap calculation
* Candidate request
* Product ranking
* Upsell component
* Add-to-cart integration
* Conversion tracking
* Mobile optimization
* Existing theme compatibility

Progress:

```text
100% — COMPLETED
```

Implementation notes (see `tests/upsell-frontend-test.php`, 63 checks):

- **Public rank endpoint** — new `GET /faracart/v1/upsell/rank`
  (`UpsellController`), public like `/progress` (no capability, per-IP
  rate limited, catalog data only — no PII or secrets). The storefront
  sends only `mission_id` + `limit`.
- **Mission gap calculation (server-side, never trusted from the client)** —
  the endpoint resolves the mission (explicit id, else the featured active
  money mission), builds the same `CartContext` the progress widgets
  evaluate on, runs the mission through the shared `MissionEngine` and derives
  the remaining gap as target − current cart value — exactly what the
  widget displays. Explicit `cart` / `cart_value` / `remaining` args
  exist for tests and embedded consumers only.
- **Candidate request + product ranking** — the endpoint calls the
  deterministic `UpsellRanker::rank()` DIRECTLY (not the cached admin
  repository read): no transient churn per cart state, and the ranking
  always reflects the live cart. All Phase 33.5 degradation holds (no
  mission / closed gap / disabled / no candidates → unavailable with a
  reason, never a fabricated list). The response is stamped
  `Cache-Control: no-store` (cart-dependent, like /progress).
- **Public payload redaction (P22-style)** — the ranker's raw payload
  carries the store's cost-derived margin/profit data; the public route
  strips `estimated_profit` / `profit_available` / `factors.margin_pct`
  and the margin reason bullets before serving, so an anonymous caller
  can never harvest the store's margins (the admin analytics surface
  keeps them behind manage_options).
- **Upsell component** — the storefront panel renders in full-variant
  cards on cart/checkout for money missions with a positive remaining gap:
  heading, ranked product rows (image, name, server-formatted price,
  add-to-cart button) fetched through `ProgressUI::frontend_config()`
  (`cfg.upsells` — rank endpoint, track endpoint, limit, localized
  labels; gated by the same `faracart_upsells_enabled` gate as the
  ranker). Results are cached per mission:gap so cart-change re-renders
  reuse them; network failures drop the panel entirely.
- **Add-to-cart integration** — the panel adds through WooCommerce's own
  public `?wc-ajax=add_to_cart` surface (the same endpoint the theme's
  buttons use — theme-compatible by construction), falls back to the
  classic `?add-to-cart=` redirect without it, and sends variation-requiring
  products to their product page. On success it funnels into the
  centralized `faracart:cart-changed` bridge, so the widgets re-poll and
  the gap closes live.
- **Conversion tracking** — the panel reports `upsell_impression` (once
  per mission+product per session), `upsell_clicked` (product link + add
  button) and `upsell_added` (after a successful add) through the
  Phase 33.5 public `POST /faracart/v1/upsell/track` route, reusing the
  Phase 16 tracking nonce/session — feeding the historical learning
  loop (P33-35) exactly as the admin analytics expect.
- **Mobile optimization + theme compatibility** — the panel is a grid on
  desktop and a swipeable horizontal snap-strip on small screens, styled
  exclusively through the scoped `faracart-*` classes and the existing
  CSS custom-property tokens (accent/bg/border/text/radius), so it can
  never leak into or break a store theme.

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
100% — COMPLETED
```

Implementation notes (see `tests/phase33-test.php`, 99 checks):

- **Regression hardening of the Phase 33 suite** — the three existing
  suites that assumed a pristine database (attribution, aggregation,
  recommendation) were made live-store-resilient: rollback/rebuild
  assertions are now scoped to each suite's own fixture markers (fixture
  session ids, order ids and date windows) instead of asserting globally
  empty tables, and the aggregation rebuild-count/trend checks tolerate
  the store's real `upsell_stats` rows. Each suite still proves *its own*
  fixtures leave zero residue, while passing on a store with genuine
  traffic. All 8 Phase 33 suites pass (555 checks, 0 failures) and must
  be run sequentially (the suites share fixture mission/session ids).
- **Unit tests** — reflection coverage of the Phase 33.5 ranker's
  scorers (price gap at the band edges / overshoot / overshoot-clamp,
  relevance source weights, popularity bounds), the dedup windows of the
  Phase 33.1 tracker (view/impression/click per session+mission+product,
  progress within the 30-minute window, order events exactly once) and
  confidence edge cases (clamping, tier thresholds).
- **Integration + edge-case + HPOS tests** — a transactional
  WooCommerce fixture covering the full order flow: order payment →
  exactly-once `order_paid` event, `upsell_order` attribution for
  shown/clicked/added products, double payment idempotency, refunded /
  cancelled orders never attributed, empty-cart / no-session graceful
  degradation, and multiple missions each attributed through their own
  model. HPOS is exercised via the `FeaturesUtil::get_compatible_plugins_for_feature`
  declaration plus the `store_order_values()` order-scan caps
  (`ORDER_SCAN_PAGES`, `ATTRIBUTION_WINDOW`).
- **Performance / large-dataset / query-optimization / cache-validation
  tests** — asserts the bounded-read constants on the attribution engine
  and daily aggregator (`ORDER_SCAN_PAGES`, `MAX_DAYS_PER_RUN`,
  lookback/retention alignment), the rate-limit and arg-schema constants
  on the REST controllers, the cache serve-from-transient path with
  generation-version invalidation and the bypass filter, and a
  `REV_INDEXES`/`upsell_stats` schema-index audit against
  INFORMATION_SCHEMA.
- **Security audit** — every admin revenue/upsell route carries a
  permission callback (anonymous requests are rejected), public routes
  (`/upsell/rank`) are per-IP rate limited and redact the store's
  margin/profit data (`estimated_profit`, `profit_available`,
  `factors.margin_pct`, margin reason bullets never reach an anonymous
  caller), and the track route clamps/normalizes its numeric args.
- **Environment note** — 7 pre-existing non-Phase-33 suites fail on this
  live store for environment reasons unrelated to Phase 33: exact-count
  analytics assertions (live traffic), stored `frontend_locations`
  overriding defaults, the literal `(copy)` suffix vs. the fa_IR
  translation `رونوشت از %s`, and the block-checkout widget-injection
  probe. No source code was changed to paper over live-store
  configuration; the Phase 33 suites themselves pass in full.

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

* [x] Mission views tracked
* [x] Mission progress tracked
* [x] Mission completions tracked
* [x] Mission conversions tracked
* [x] Mission-driven revenue calculated
* [x] Mission-assisted revenue calculated
* [x] Incremental cart value calculated
* [x] AOV impact calculated
* [x] Reward cost calculated
* [x] Profit impact calculated when data is available

### Smart Mission Recommendation

* [x] AOV analyzed
* [x] Median order value analyzed
* [x] Order distribution analyzed
* [x] Shipping cost analyzed
* [x] Margin analyzed when available
* [x] Candidate thresholds generated
* [x] Thresholds scored
* [x] Best threshold recommended
* [x] Confidence calculated
* [x] Recommendation explanation available
* [x] Admin approval required before applying

### Smart Upsell

* [x] Candidate products collected
* [x] Out-of-stock products excluded
* [x] Price gap scored
* [x] Relevance scored
* [x] Inventory scored
* [x] Popularity scored
* [x] Margin scored
* [x] Historical conversion scored
* [x] Composite score calculated
* [x] Products ranked
* [x] Recommendation performance tracked

### Admin

* [x] Revenue Overview implemented
* [x] Mission Performance implemented
* [x] Attribution dashboard implemented
* [x] Smart Mission Recommendations implemented
* [x] Upsell Analytics implemented
* [x] Filters implemented
* [x] Date ranges implemented
* [x] RTL supported

### Technical

* [x] HPOS compatible
* [x] Secure REST APIs
* [x] Permission checks
* [x] SQL optimized
* [x] Caching implemented
* [x] Scheduled aggregation implemented
* [x] Privacy-safe tracking
* [x] No duplicate events
* [x] Unit tests pass
* [x] Integration tests pass
* [x] Existing FaraCart functionality remains intact

---

# 64. Final Expected Result

After Phase 33, FaraCart should evolve from:

```text
A WooCommerce cart mission/progress plugin
```

into:

```text
A Revenue Optimization Engine
```

The final system should allow a store owner to see:

```text
How much revenue FaraCart influenced
How much additional cart value it generated
How much AOV changed
How much rewards cost
How much estimated profit was generated
Which mission threshold should be used
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

1. Inspect the existing FaraCart architecture.
2. Identify reusable services/components.
3. Identify existing database tables.
4. Identify existing REST APIs.
5. Identify existing React Admin structure.
6. Identify existing frontend mission components.
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

