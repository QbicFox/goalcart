# FaraCart — Analytics & Revenue Attribution

## Overview

FaraCart measures whether missions actually increase revenue through a multi-layered analytics system: event tracking, revenue attribution, smart recommendations, and upsell ranking.

## Event Tracking (Phase 16)

### Events

| Event | When | Source |
|---|---|---|
| `mission_impression` | Mission renders in widget | Client-reported |
| `mission_progress` | Progress percentage changes | Client-reported |
| `mission_completed` | Mission reaches 100% (no reward) | Client-reported |
| `reward_activated` | Mission with reward reaches 100% | Client-reported |
| `suggestion_impression` | Suggested product renders | Client-reported |
| `suggestion_clicked` | Suggestion link clicked | Client-reported |
| `suggested_product_added` | Suggestion product added to cart | **Server-side only** |

### Privacy

- Sessions are anonymous 32-char hex IDs (cookie-based)
- No raw IPs, emails, or personal data stored
- `user_id` captured only for logged-in users

### Server-side Attribution

`suggested_product_added` is attributed on `woocommerce_add_to_cart` only when the session saw a `suggestion_impression` for that product within 24 hours.

## Revenue Attribution (Phase 33.2)

### Order Association

When an order becomes revenue-producing (`processing` / `completed`), the engine attributes it to missions that influenced the ordering session.

**Lookback:** 30 days before the order.

### Attribution Models

| Model | Condition | Value |
|---|---|---|
| `direct` | Session progressed/completed mission before ordering | Carries incremental value |
| `assisted` | Session only viewed the mission | Zero incremental value |

**Incremental value** = `max(0, order_total − cart value at first mission exposure)`

### Metrics

| Metric | Formula |
|---|---|
| Completion rate | `completed / views` |
| Conversion rate | `converted / completions` |
| Mission-driven revenue | `SUM(direct incremental_value)` |
| Assisted revenue | `SUM(order_total of pure-assisted orders)` |
| Influenced revenue | `SUM(order_total of distinct attributed orders)` |
| AOV analysis | Mission-exposed vs store-wide average |

## Smart Mission Recommendation (Phase 33.4)

`MissionRecommendationEngine` recommends optimal mission thresholds using store order data:

### Inputs

- AOV, median order value, coefficient of variation
- Order distribution (AOV-relative buckets)
- Shipping costs
- Product margins (when cost data available)
- Current mission performance

### Scoring Components

| Component | Weight | Signal |
|---|---|---|
| Reachability | 30% | Share of orders within 30% below threshold |
| Distance | 25% | Stretch above median and AOV |
| Economics | 30% | Reward cost vs incremental margin |
| History | 15% | Store's own completion rate |

### Confidence Tiers

| Tier | Orders | Meaning |
|---|---|---|
| `basic` | 50–199 | Limited data |
| `reliable` | 200–999 | Moderate data |
| `high_confidence` | 1000+ | Good data |

## Smart Upsell Ranking (Phase 33.5)

`UpsellRanker` ranks products to help customers reach missions:

### Component Scores

| Component | Weight | Signal |
|---|---|---|
| Price gap | 25% | How well price fits remaining gap |
| Relevance | 25% | Mission eligibility, category overlap, WC source trust |
| Popularity | 15% | Units sold + average rating |
| Inventory | 10% | Stock level |
| Margin | 15% | Product margin (when cost data available) |
| Conversion | 10% | Historical upsell funnel performance |

### Candidate Sources

1. Mission's own products (manual)
2. Products historically recommended
3. Products in mission's categories
4. Cart items' upsells/cross-sells/related
5. Products sharing category/tag with cart
6. Best sellers

## Daily Aggregation (Phase 33.3)

`DailyAggregator` pre-computes daily metrics into `faracart_revenue_daily` so the dashboard reads aggregated data instead of scanning raw events.

- Runs on `daily` cron interval
- Processes day-by-day, bounded per tick
- Re-runs are idempotent (delete-then-insert per date)

## Caching

Revenue reads use generation-versioned transients:

- `faracart_revenue_cache_version` incremented on data changes
- Invalidated on: order payment/status, mission CRUD, product saves, aggregation
- TTL configurable via `faracart_revenue_cache_ttl`

## Cost Sources

Product cost is read from (in order):

1. `faracart_product_cost` filter
2. `_faracart_product_cost` (FaraCart's field)
3. `_cost` (WooCommerce standard)
4. `_wc_cog_cost` (common cost-of-goods)
5. Variation fallback to parent product

A stored cost of zero or negative = "no cost data" (never 100% margin).

## Profit Impact

```
estimated_profit = incremental_revenue × margin% − reward_cost − shipping_cost
```

Without margin data: `available: false` with reason. Revenue metrics still compute.

## REST Endpoints

| Endpoint | Description |
|---|---|
| `GET /analytics` | Phase 17 dashboard metrics |
| `GET /revenue/overview` | Sales Performance overview |
| `GET /revenue/missions` | Mission performance comparison |
| `GET /revenue/attribution` | Attribution dashboard |
| `GET /revenue/mission-recommendations` | Smart mission recommendations |
| `POST /revenue/mission-recommendations/apply` | Apply recommended threshold |
| `GET /revenue/cost-coverage` | Product cost coverage |
| `GET /revenue/upsells` | Upsell ranking |
| `GET /revenue/upsells/{product_id}` | Product score breakdown |
| `GET /upsell/analytics` | Upsell analytics table |
