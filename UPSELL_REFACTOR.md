# Goal Cart — Goal Optimization & Smart Upsell Architecture Refactor

## Mission

Refactor the Goal Cart recommendation and upsell architecture so that the product has a clear and non-overlapping distinction between:

1. **Goal Optimization** — an admin-side system that recommends better Goal configurations and thresholds.
2. **Smart Upsells** — a customer-facing system that recommends products to help customers reach the currently active Goal.

The current implementation contains recommendation and upsell functionality, but the product terminology and information architecture can make these concepts appear similar.

The objective is NOT to remove functionality.

The objective is to create a clear product model:

```text
Goal Optimization
        ↓
Better Goal Configuration
        ↓
Active Goal
        ↓
Smart Upsells
        ↓
Higher Cart Value
        ↓
Goal Completion
        ↓
Purchase
        ↓
Analytics
        ↓
Future Optimization
```

The final system should make this distinction obvious to both developers and store administrators.

---

# 1. IMPORTANT — READ THE EXISTING PROJECT FIRST

Before changing any code, completely inspect:

- `AGENT.md`
- `PRODUCT_SPEC.md`
- `phase33.md`
- `revenue.md`
- `api.md`
- `frontend.md`
- `database.md`
- `goal-engine.md`
- `rewards.md`
- `REFERENCE_ARCHITECTURE.md`
- `CHANGELOG.md`
- `reference-plugin-file-inventory.md`

Also inspect the actual implementation.

Specifically locate and understand:

- `GoalRecommendationEngine`
- `UpsellRanker`
- recommendation services
- recommendation API endpoints
- upsell API endpoints
- goal engine
- goal configuration
- goal threshold calculation
- goal performance analytics
- upsell analytics
- historical learning
- attribution
- revenue analytics
- React routes
- React pages
- React components
- admin navigation
- storefront components
- database tables
- caches
- feature flags
- tests

Do not assume the documentation perfectly matches the current implementation.

The actual codebase is the source of truth for implementation details.

---

# 2. CORE PRODUCT DECISION

The final product must treat these as two different systems.

## Goal Optimization

Audience:

> Store administrator

Question:

> "What Goal configuration should I use?"

Examples:

- What threshold should I use?
- Is my current threshold too high?
- Is my free-shipping target reachable?
- Should I change the reward?
- What target could increase basket value?
- Which Goal configuration has the best economics?

Output:

```text
Recommended Goal Target

1,600,000 تومان

Current:
2,000,000 تومان

Recommended:
1,600,000 تومان

Reason:
A large percentage of customers already
purchase near this value.
```

---

## Smart Upsells

Audience:

> Customer

Question:

> "What should I buy to reach the current Goal?"

Example:

```text
Current cart:
1,350,000 تومان

Goal:
1,600,000 تومان

Remaining:
250,000 تومان

Recommended products:

Product A
280,000 تومان

Product B
320,000 تومان

Product C
390,000 تومان
```

Output:

> Product recommendations designed to help complete the active Goal while maximizing commercial value.

---

# 3. DO NOT MERGE THESE TWO ENGINES

Do NOT merge:

```text
GoalRecommendationEngine
```

and:

```text
UpsellRanker
```

They solve different problems.

Keep them as separate domain services.

The relationship should be:

```text
GoalRecommendationEngine
        ↓
Goal Configuration
        ↓
Active Goal
        ↓
UpsellRanker
        ↓
Product Recommendations
```

---

# 4. REMOVE THE PRODUCT-LEVEL CONFUSION

The current product may contain terminology such as:

- Smart Recommendations
- Goal Recommendations
- Upsells

This creates ambiguity.

The final product terminology should be:

## Admin

> **Goal Optimization**

Description:

> Improve your Goals using store performance data.

Inside this section:

> Smart Goal Recommendations

## Storefront

> **Smart Upsells**

Description:

> Products recommended to help customers reach their current Goal.

---

# 5. SMART RECOMMENDATIONS → GOAL OPTIMIZATION

Do not expose "Smart Recommendations" as a generic top-level product area if it refers to Goal threshold/configuration recommendations.

Rename the admin-facing concept to:

> **Goal Optimization**

If an internal code class currently uses:

```text
GoalRecommendationEngine
```

DO NOT rename it merely for cosmetic reasons unless there is a strong architectural reason.

Backend naming can remain `GoalRecommendationEngine` while the UI uses `Goal Optimization`.

---

# 6. GOAL OPTIMIZATION RESPONSIBILITIES

Goal Optimization should answer:

1. Is the current Goal reasonable?
2. Is the Goal too difficult?
3. Is the Goal too easy?
4. Is the reward economically reasonable?
5. What target should be tested?

Use existing:

- AOV
- median order value
- distribution
- reachability
- margin
- shipping economics
- historical completion
- historical revenue
- confidence

Do not invent new scoring logic if equivalent logic already exists.

---

# 7. PRESERVE THE EXISTING GOAL RECOMMENDATION ENGINE

The existing recommendation engine already evaluates factors such as:

- AOV
- order value distribution
- reachability
- completion rate
- margin
- shipping cost
- economics
- confidence

Preserve this functionality.

Do not simplify the backend engine merely because the frontend is being simplified.

The objective is:

> Simplify the presentation, not the intelligence.

---

# 8. GOAL OPTIMIZATION UI

Create a clear admin experience.

Recommended layout:

```text
Goal Optimization

Improve your Goals using real store data.

────────────────────────────────

Free Shipping

Current target
2,000,000 تومان

Recommended target
1,600,000 تومان

Why?
• Many customers are already close to this value.
• The target is more reachable.
• The expected economics are healthier.

Confidence
High

[Apply Recommendation]

[View Details]
```

---

# 9. RECOMMENDATION DETAIL

When the administrator clicks "View Details", show:

### Current Goal

- current threshold
- current reward
- current completion rate
- current purchase rate
- current attributed sales
- current estimated profit where available

### Recommended Goal

- recommended threshold
- expected reachability
- expected basket impact
- expected economics
- confidence

### Why?

Show human-readable reasons.

Use existing recommendation data.

Do not generate fake explanations.

---

# 10. APPLY RECOMMENDATION

If the existing system supports applying recommendations, preserve or implement:

```text
[Apply Recommendation]
```

Before applying, show the current and new target and require confirmation.

Use existing permission/security rules.

Do not modify unrelated Goal settings.

---

# 11. GOAL OPTIMIZATION SHOULD NOT RECOMMEND PRODUCTS

This is critical.

Goal Optimization should NOT say:

> Buy this product.

It may say:

> Set your Goal to 1.6M.

Product recommendations belong to Upsells.

Therefore:

```text
Goal Optimization
    → Goal / Threshold / Reward Strategy

Smart Upsell
    → Product Recommendation
```

---

# 12. SMART UPSELL RESPONSIBILITIES

Smart Upsell is customer-facing.

Its purpose is:

> Help customers reach the active Goal by recommending suitable products.

It should consider:

- current cart value
- remaining gap
- product price
- relevance
- popularity
- inventory
- margin
- historical conversion
- goal completion probability

Preserve the existing `UpsellRanker`.

---

# 13. PRESERVE EXISTING UPSELL SCORING

The existing UpsellRanker already considers factors such as:

- Price Gap
- Relevance
- Popularity
- Inventory
- Margin
- Conversion

Preserve these factors and their existing weighting unless there is a documented reason to change them.

Do not replace the existing ranking engine with a simpler product-price sort.

---

# 14. CUSTOMER-FACING UPSELL UX

The storefront should communicate:

```text
You're close!

Only 250,000 تومان
until Free Shipping.
```

Then show contextual product recommendations with Add to Cart actions.

Do not show generic unrelated product recommendations.

---

# 15. SMART UPSELL MUST UNDERSTAND THE GOAL

Every Upsell recommendation should be aware of:

- Goal
- Current Cart
- Remaining Gap
- Reward

The ranking system should prioritize products that make sense for the gap.

---

# 16. PRICE GAP LOGIC

Preserve the existing Price Gap scoring.

Products extremely far above the gap should generally receive a lower score unless other factors justify them.

Do not make price gap the only ranking factor.

---

# 17. MARGIN MUST REMAIN PART OF UPSELL

Because Goal Cart is moving toward Estimated Profit analytics, margin becomes even more important.

The Upsell engine should continue considering product margin where cost data is available.

Do not hard-code a margin.

Use actual Product Cost data when available.

---

# 18. PRODUCT COST INTEGRATION

Goal Cart should support a Product Cost field because WooCommerce Core does not provide a universal built-in product cost field.

Preferred architecture:

```text
WooCommerce Product
        ↓
Goal Cart Product Cost
        ↓
Order Created
        ↓
Cost Snapshot
        ↓
Revenue / Profit Analytics
```

The Product Cost is used by:

- Estimated Profit
- Upsell margin scoring
- Goal economics
- Recommendation economics

---

# 19. PRODUCT COST FIELD

Add:

> Product Cost

to WooCommerce product editing.

For variable products, support cost per variation.

Do not require the store owner to configure cost for every product before Goal Cart works.

Products without cost simply have limited profit/economics data.

---

# 20. PRODUCT COST STORAGE

Use a dedicated Goal Cart product meta field or the project's established product-data architecture.

Do not overload unrelated WooCommerce fields.

Use a clearly namespaced internal key, such as:

```text
_goalcart_product_cost
```

Use the project's actual naming conventions if an existing field already exists.

Do not create duplicate cost fields.

---

# 21. ORDER COST SNAPSHOT

This is extremely important.

When an order is created, preserve the Product Cost used at that time.

Do NOT calculate historical profit by reading the current product cost.

If product cost later changes, historical orders must continue using their original cost snapshot.

---

# 22. COST SNAPSHOT DATA

Prefer storing the snapshot at order-item level.

Conceptually:

```text
order_item_id
product_id
variation_id
quantity
unit_cost_snapshot
```

Use the project's existing database conventions.

Do not introduce unnecessary duplication if an existing order-item metadata strategy already exists.

---

# 23. ESTIMATED PROFIT

Estimated Profit should use the existing documented profit model.

Conceptually:

```text
Estimated Profit
=
Incremental / Attributed Margin
− Reward Cost
− Shipping Cost
```

Where product margin is based on:

```text
Revenue
− Product Cost
```

Do not invent costs.

Do not assume a default margin.

Do not use sale price as product cost.

Do not claim accounting accuracy.

Always label it:

> Estimated Profit

---

# 24. PROFIT AVAILABILITY

If product cost data exists, display Estimated Profit.

If it does not:

```text
Estimated Profit

Not available yet

Add product cost data to estimate profitability.
```

Provide a link/action:

> Manage Product Costs

---

# 25. COST COVERAGE

Add a useful indicator such as:

```text
Product Cost Coverage

842 / 1,000 products
84.2%
```

If possible, also calculate relevant order-value coverage.

Do not implement complex partial calculations unless the backend can guarantee correct semantics.

---

# 26. GOAL OPTIMIZATION + PROFIT

Goal Optimization should use profitability where data is available.

Only display expected profit impact if the underlying data supports it.

Otherwise clearly show that profit impact is unavailable because product cost data is incomplete.

Never estimate a margin arbitrarily.

---

# 27. UPSELL + PROFIT

Upsell ranking should consider margin where available.

This creates an important distinction:

### Goal Optimization

Optimizes:

> Goal configuration

### Upsell

Optimizes:

> Product selection

### Revenue Analytics

Measures:

> Business outcome

These three should form one coherent system.

---

# 28. THE COMPLETE PRODUCT LOOP

Implement and document the following conceptual loop:

```text
Goal Optimization
        ↓
Active Goal
        ↓
Smart Upsell
        ↓
Cart Increase
        ↓
Goal Completion
        ↓
Purchase
        ↓
Revenue & Analytics
        ↓
New Insights
        ↓
Goal Optimization
```

This feedback loop is a core product concept.

---

# 29. ANALYTICS MUST CONNECT BOTH SYSTEMS

Analytics should allow the store owner to understand:

### Goal performance

- views
- progressed
- completed
- purchased
- purchase rate
- attributed sales
- estimated profit

### Upsell performance

- impressions
- clicks
- adds
- purchases
- conversion
- revenue
- estimated profit

This allows the store owner to answer:

> Did Upsell actually help customers complete the Goal?

---

# 30. ADD UPSELL-ASSISTED GOAL COMPLETION

Where existing event/attribution data allows it, add:

> Upsell-assisted Goal Completions

Example:

```text
Goal Completions
920

Upsell-assisted
241

Upsell-assisted rate
26.2%
```

Do NOT fabricate this metric.

Only implement it if the existing event tracking provides reliable linkage.

---

# 31. UPSELL FUNNEL

Analytics should support:

```text
Upsell Impressions
        ↓
Clicks
        ↓
Add to Cart
        ↓
Goal Completion
        ↓
Purchase
```

This is much more useful than CTR alone.

---

# 32. UPSELL ANALYTICS UI

Rename the admin section:

> Upsell Performance

Primary metrics:

- Products shown
- Added to cart
- Goal completions assisted
- Purchased orders
- Sales
- Estimated profit

Secondary metrics:

- CTR
- Add-to-cart rate
- conversion
- ranking score
- score components

---

# 33. GOAL PERFORMANCE UI

Each Goal should show:

```text
Free Shipping

Views                 4,820
Progressed             2,410
Completed                920
Purchased                187
Purchase Rate           20.3%
Upsell-assisted          74
Sales                   5.8M
Estimated Profit        2.4M
```

Use actual backend data only.

---

# 34. GOAL DETAIL

Goal detail should contain:

## Goal Configuration

- reward
- threshold
- current status

## Goal Optimization

- current target
- recommended target
- recommendation reason
- confidence
- apply action

## Customer Funnel

- viewed
- progressed
- completed
- purchased

## Smart Upsells

- impressions
- clicks
- adds
- assisted completions
- purchases

## Revenue

- attributed sales
- incremental revenue
- estimated profit

## Advanced Analytics

- direct
- assisted
- influenced
- attribution window
- AOV
- economics

---

# 35. DO NOT SHOW TOO MUCH AT ONCE

Use progressive disclosure.

Primary view:

```text
Goal
Performance
Sales
Profit
```

Secondary:

```text
Optimization
Upsell Performance
```

Advanced:

```text
Attribution
Scoring
Economics
Data Sufficiency
```

Do not expose raw recommendation scores by default.

---

# 36. NAVIGATION RESTRUCTURE

Preferred admin navigation:

```text
Goal Cart

├── Dashboard
├── Goals
├── Sales Performance
│   ├── Overview
│   ├── Goal Performance
│   └── Analytics
└── Optimization
    ├── Goal Optimization
    └── Upsell Performance
```

If the current plugin has strong backward compatibility requirements, preserve existing routes but redirect or alias old routes.

For example:

```text
/recommendations
```

may redirect to:

```text
/optimization/goals
```

Do not break bookmarked URLs unnecessarily.

---

# 37. LEGACY TERMINOLOGY

Backend/internal terminology may remain unchanged where needed.

For example:

```text
GoalRecommendationEngine
UpsellRanker
goal_recommendations
upsell_analytics
```

Do not perform a large backend rename only to change UI terminology.

The goal is product clarity, not unnecessary refactoring.

---

# 38. ADMIN TERMINOLOGY

Use:

| Old / Technical | New User-Facing |
|---|---|
| Smart Recommendations | Goal Optimization |
| Goal Recommendations | Smart Goal Recommendations |
| Recommendation Engine | Goal Optimization Engine |
| Recommended Threshold | Recommended Goal Target |
| Upsell Analytics | Upsell Performance |
| Conversion | Purchase Rate where appropriate |
| Converted | Purchased |
| Goal Completion | Goal Completed |
| Influenced Revenue | Influenced Sales |
| Estimated Profit | Estimated Profit |

Use existing translations where appropriate.

---

# 39. GOAL OPTIMIZATION VS UPSELL TOOLTIP

Add a clear explanation in the admin UI.

For Goal Optimization:

> Goal Optimization helps you choose better Goal targets and reward configurations.

For Upsell Performance:

> Upsell Performance shows which products help customers reach Goals and generate additional sales.

This should eliminate conceptual confusion.

---

# 40. STORE OWNER EDUCATION

On first use, optionally show:

```text
How Goal Cart works

1. Goal Optimization
   Find better Goal settings.

2. Smart Upsells
   Help customers reach those Goals.

3. Analytics
   Measure purchases, sales and profit.
```

Do not show this on every page.

---

# 41. RECOMMENDATION FEEDBACK LOOP

If existing architecture supports it, track:

```text
Recommendation shown
        ↓
Recommendation viewed
        ↓
Recommendation applied
        ↓
Goal changed
        ↓
Goal performance
```

This allows future analysis of which recommendations actually improve performance.

Do not introduce a complex ML system.

---

# 42. UPSELL FEEDBACK LOOP

Preserve existing tracking:

```text
Upsell shown
        ↓
Clicked
        ↓
Added
        ↓
Goal completed
        ↓
Purchased
```

Ensure events are idempotent.

Do not duplicate events.

---

# 43. FUTURE LEARNING LOOP

Prepare the architecture for:

```text
Goal Configuration
        ↓
Customer Behavior
        ↓
Upsell Behavior
        ↓
Purchase
        ↓
Revenue
        ↓
Profit
        ↓
Recommendation Improvement
```

Do NOT implement machine learning in this phase.

Just ensure the data model does not prevent this future capability.

---

# 44. PERFORMANCE REQUIREMENTS

Do not introduce:

- N+1 product queries
- N+1 order queries
- repeated full order scans
- uncached recommendation calculations
- uncached upsell ranking calculations on every request

Reuse:

- existing caches
- RevenueRepository
- existing aggregation
- existing event tables
- existing historical learning
- existing ranking infrastructure

---

# 45. API REQUIREMENTS

Do not create duplicate APIs if existing endpoints can be extended.

Goal Optimization API should expose, where already supported:

```text
current_target
recommended_target
recommendation_score
confidence
reasons
economics
reachability
```

Upsell API should expose:

```text
goal_id
cart_value
remaining_gap
products
ranking
```

Upsell Analytics should expose:

```text
impressions
clicks
adds
goal_completions
purchases
sales
profit
```

Use the existing API conventions.

---

# 46. PRODUCT COST API

If Product Cost functionality is implemented, expose only what is necessary.

Possible fields:

```text
cost
cost_available
cost_coverage
```

Do not expose sensitive/internal implementation details unnecessarily.

---

# 47. SECURITY

Preserve existing:

- admin capabilities
- nonce verification
- schema validation
- authorization
- rate limiting
- feature flags

Product cost values must only be editable by authorized administrators.

Never expose product cost data to storefront customers.

---

# 48. STOREFRONT PRIVACY

The customer should NEVER see:

- product cost
- margin
- profit
- ranking score
- recommendation confidence
- internal attribution
- economics score

The storefront only sees:

- product
- price
- image
- availability
- relevant messaging

---

# 49. TESTING — GOAL OPTIMIZATION

Add/update tests for:

- recommended threshold
- current threshold
- recommendation confidence
- recommendation reasons
- reachability
- economics
- missing cost data
- applying recommendation
- invalid recommendation
- permission checks

---

# 50. TESTING — UPSELL

Test:

- gap calculation
- price gap ranking
- relevance
- popularity
- inventory
- margin
- conversion
- final ranking
- no suitable products
- out-of-stock products
- variation products
- duplicate recommendations
- goal-specific recommendations

---

# 51. TESTING — FEEDBACK LOOP

Test:

```text
Goal
↓
Upsell shown
↓
Product added
↓
Goal completed
↓
Purchase
```

Ensure analytics correctly associates events.

Test direct and assisted attribution where applicable.

---

# 52. TESTING — PRODUCT COST

Test:

### Product

- cost exists
- cost missing
- cost zero
- cost greater than selling price

### Variation

- variation-specific cost
- parent fallback if supported

### Order

- cost snapshot created
- snapshot remains unchanged after product cost changes

### Profit

- positive profit
- zero profit
- negative profit
- unavailable profit

---

# 53. MIGRATION

If the current plugin already has recommendation data:

Do not delete it.

If the current admin route is:

```text
/recommendations
```

preserve backward compatibility.

Migrate terminology at the UI level first.

Only perform database migrations if necessary.

Do not unnecessarily rename tables.

---

# 54. BACKWARD COMPATIBILITY

Existing Goals must continue to work.

Existing Upsells must continue to work.

Existing Recommendation Engine must continue to work.

Existing analytics must continue to work.

Existing API consumers should not break.

Existing caches should be invalidated correctly when Goal configuration changes.

---

# 55. FINAL ADMIN EXPERIENCE

The final product should feel like this:

```text
Goal Cart

Dashboard

Goals

Sales Performance

Optimization
   ├── Goal Optimization
   └── Upsell Performance
```

A store owner should understand immediately:

### Goal Optimization

> "Help me choose better Goals."

### Smart Upsells

> "Help my customers reach those Goals."

### Sales Performance

> "Show me whether any of this actually made money."

---

# 56. FINAL PRODUCT STORY

The complete Goal Cart story should be:

```text
1. Goal Optimization

"Set a better target."

        ↓

2. Goal

"Give customers something worth reaching."

        ↓

3. Smart Upsell

"Show customers what they can buy to get there."

        ↓

4. Goal Completion

"They reached the target."

        ↓

5. Purchase

"They actually bought."

        ↓

6. Sales & Profit

"Here's what Goal Cart generated."

        ↓

7. Analytics

"Here's what worked."

        ↓

8. Goal Optimization

"Now improve the Goal again."
```

This loop should become the central product philosophy of Goal Cart.

---

# 57. WHAT NOT TO DO

Do NOT:

- merge GoalRecommendationEngine and UpsellRanker
- remove Upsells
- remove Goal Recommendations
- create two separate product recommendation systems
- show product recommendations inside Goal Optimization
- show Goal threshold recommendations inside the storefront
- expose technical scoring to customers
- expose profit/cost to customers
- invent product margins
- calculate historical profit from current product cost
- duplicate attribution logic
- create unnecessary APIs
- duplicate database structures
- remove existing analytics
- break existing routes
- claim that observed AOV differences are causal
- call Goal Completion a Purchase
- call Completion Rate Purchase Rate

---

# 58. ACCEPTANCE CRITERIA

The implementation is complete when:

## Product Architecture

- [ ] Goal Optimization and Smart Upsells have clearly different responsibilities.
- [ ] GoalRecommendationEngine remains separate from UpsellRanker.
- [ ] Smart Recommendations and Goal Recommendations are unified conceptually.
- [ ] Goal Optimization is the admin-facing terminology.
- [ ] Smart Upsells remain customer-facing.

## Goal Optimization

- [ ] Store owner can see recommended Goal targets.
- [ ] Recommendation reasons are understandable.
- [ ] Confidence is visible.
- [ ] Existing recommendation intelligence is preserved.
- [ ] Recommendation can be applied where supported.
- [ ] Product recommendations are NOT shown as part of Goal Optimization.

## Smart Upsells

- [ ] Upsells are contextual to the active Goal.
- [ ] Remaining gap is considered.
- [ ] Existing ranking factors remain functional.
- [ ] Margin can influence ranking when cost data exists.
- [ ] Customer never sees internal economics.

## Product Cost

- [ ] Goal Cart can store Product Cost.
- [ ] Variations can have their own cost where supported.
- [ ] Order-item cost is snapshotted.
- [ ] Historical profit does not change when current product cost changes.
- [ ] Missing cost does not break Goal Cart.

## Profit

- [ ] Estimated Profit is displayed when enough data exists.
- [ ] Missing cost produces a clear unavailable state.
- [ ] Negative profit is supported.
- [ ] Product cost is never guessed.
- [ ] Estimated Profit is clearly labeled as estimated.

## Analytics

- [ ] Goal views are available.
- [ ] Goal completions are available.
- [ ] Purchased orders are available.
- [ ] Purchase rate is available.
- [ ] Upsell-assisted completions are available where reliable data exists.
- [ ] Sales are available.
- [ ] Estimated profit is available where possible.
- [ ] Upsell performance can be evaluated independently.

## UX

- [ ] Admin can understand Goal Optimization without technical knowledge.
- [ ] Admin can understand Upsell Performance without technical knowledge.
- [ ] Storefront customers only see relevant product recommendations.
- [ ] Technical analytics remains available through progressive disclosure.
- [ ] Existing functionality is not unnecessarily removed.

---

# 59. FINAL IMPLEMENTATION PRINCIPLE

The most important architectural rule is:

> **Goal Optimization decides what the Goal should be. Smart Upsell decides what the customer should buy to reach that Goal. Analytics decides whether the strategy actually worked.**

Do not blur these responsibilities.

The final Goal Cart product should not feel like it contains several unrelated recommendation systems.

It should feel like one intelligent optimization system:

```text
             GOAL CART
                 │
        ┌────────┴────────┐
        │                 │
        ▼                 ▼
Goal Optimization    Smart Upsell
        │                 │
        ▼                 ▼
 Better Goal          Better Product
        │                 │
        └────────┬────────┘
                 ▼
             More Sales
                 │
                 ▼
          More Profit
                 │
                 ▼
             Analytics
                 │
                 ▼
        Better Optimization
```

**Preserve the intelligence. Simplify the product. Make the distinction obvious.**
